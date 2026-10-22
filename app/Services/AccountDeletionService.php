<?php

namespace App\Services;

use App\Models\DeskReservation;
use App\Models\MonthlyReceipt;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountDeletionService
{
    public function __construct(
        private readonly ManagerTransaction $managerTransaction,
        private readonly CalendarDateLock $dateLock,
        private readonly AvatarStorage $avatars,
    ) {}

    /**
     * Permanently remove an inactive account and all of its personal history.
     * The manager gate rechecks the actor and serializes other manager writes.
     * The fresh target row lock prevents a concurrent profile or worker request
     * from adding personal records while their dates are gathered and deleted.
     * Shared office records and other workers' history remain, with this user's
     * actor references cleared instead of removing their original actions.
     */
    public function delete(User $actor, User $target): void
    {
        $this->managerTransaction->run($actor, function () use ($target): void {
            $account = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($account->is_active) {
                throw ValidationException::withMessages([
                    'account' => 'ניתן למחוק לצמיתות רק חשבון שאינו פעיל. יש להשבית אותו תחילה.',
                ]);
            }

            // Follow worker -> dates -> work records, the same lock order as
            // booking and cancellation. Keep the permanent date keys: other
            // workers and future bookings still use them for serialization.
            $this->lockHistoryDates($account);

            Shift::query()->where('worker_id', $account->getKey())->delete();
            DeskReservation::query()->where('worker_id', $account->getKey())->delete();
            MonthlyReceipt::query()->where('worker_id', $account->getKey())->delete();
            DB::table('sessions')->where('user_id', $account->getKey())->delete();

            $this->clearActorReferences($account);

            $photoPath = $account->photo_path;
            $account->delete();

            // A transaction can still roll back after the DELETE. Remove its
            // private photo only after the outermost commit has succeeded.
            DB::afterCommit(function () use ($photoPath): void {
                $this->deleteAvatarAfterCommit($photoPath);
            });
        });
    }

    /** Gather every owned date, including completed and canceled history. */
    private function lockHistoryDates(User $account): void
    {
        $shiftDates = DB::table('shifts')->where('worker_id', $account->getKey())
            ->distinct()->pluck('shift_date')->all();
        $reservationDates = DB::table('desk_reservations')->where('worker_id', $account->getKey())
            ->distinct()->pluck('reservation_date')->all();
        $dates = array_values(array_unique(array_merge($shiftDates, $reservationDates)));
        sort($dates, SORT_STRING);

        foreach ($dates as $date) {
            $this->dateLock->acquire($date);
        }
    }

    /**
     * Retain office state and other accounts' records without a deleted actor.
     * These explicit updates keep foreign-key enforcement enabled throughout;
     * no deletion can silently cascade into a shared block, event or desk.
     */
    private function clearActorReferences(User $account): void
    {
        $accountId = $account->getKey();

        DB::table('blocked_dates')->where('blocked_by', $accountId)->update(['blocked_by' => null]);
        DB::table('calendar_events')->where('created_by', $accountId)->update(['created_by' => null]);
        DB::table('calendar_events')->where('updated_by', $accountId)->update(['updated_by' => null]);
        DB::table('shifts')->where('cancelled_by', $accountId)->update(['cancelled_by' => null]);
        DB::table('desk_reservations')->where('cancelled_by', $accountId)->update(['cancelled_by' => null]);
        DB::table('desks')->where('deactivated_by', $accountId)->update(['deactivated_by' => null]);
    }

    /** Filesystem cleanup must not report a committed deletion as a failure. */
    private function deleteAvatarAfterCommit(?string $photoPath): void
    {
        try {
            $this->avatars->delete($photoPath);
        } catch (Throwable) {
            try {
                // Never log the account's personal details, filename or path.
                Log::warning('A deleted account profile image could not be removed. Check private storage permissions.');
            } catch (Throwable) {
                // Even unavailable logging cannot undo the committed deletion.
            }
        }
    }
}
