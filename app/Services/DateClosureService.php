<?php

namespace App\Services;

use App\Models\BlockedDate;
use App\Models\DeskReservation;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DateClosureService
{
    public function __construct(
        private readonly ManagerTransaction $managerTransaction,
        private readonly CalendarDateLock $dateLock,
    ) {}

    /**
     * Close today or a future date and cancel shifts and desk bookings together.
     * The permanent date key serializes even first-ever selections/bookings.
     * ManagerTransaction also serializes desk/account deactivation, so closure
     * needs date and reservation locks without taking worker or desk locks.
     */
    public function block(User $manager, string $date, ?string $reason = null): void
    {
        $this->managerTransaction->run($manager, function () use ($manager, $date, $reason): void {
            $this->acquireEditableDate($date);

            if (BlockedDate::where('date', $date)->exists()) {
                return;
            }

            BlockedDate::create(['date' => $date, 'reason' => $reason, 'blocked_by' => $manager->getKey()]);
            $now = CarbonImmutable::now(config('app.timezone'));
            $shifts = Shift::active()->where('shift_date', $date)->orderBy('id')->lockForUpdate()->get();

            foreach ($shifts as $shift) {
                // Keep the reason on the canceled row even after reopening.
                $shift->cancel($manager->getKey(), 'date_blocked', $now, $reason);
            }

            $reservations = DeskReservation::active()->where('reservation_date', $date)
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($reservations as $reservation) {
                $reservation->cancel($manager->getKey(), 'date_blocked', $now, $reason);
            }
        });
    }

    /** Reopen without restoring any previously canceled selection. */
    public function unblock(User $manager, string $date): void
    {
        $this->managerTransaction->run($manager, function () use ($date): void {
            $this->acquireEditableDate($date);
            BlockedDate::where('date', $date)->delete();
            // Keep calendar_dates: the serialization key is independent of closure.
        });
    }

    /** Validate the local date and acquire its lock within ManagerTransaction. */
    private function acquireEditableDate(string $date): void
    {
        Validator::make(['shift_date' => $date], ['shift_date' => ['required', 'date_format:Y-m-d']])->validate();
        $this->rejectPastDate($date);
        $this->dateLock->acquire($date);
        $this->rejectPastDate($date);
    }

    /** Check before inserting a DATE key, and again after any midnight lock wait. */
    private function rejectPastDate(string $date): void
    {
        if ($date < CarbonImmutable::now(config('app.timezone'))->toDateString()) {
            throw ValidationException::withMessages(['shift_date' => 'ניתן לחסום או לפתוח תאריכים מהיום והלאה בלבד.']);
        }
    }
}
