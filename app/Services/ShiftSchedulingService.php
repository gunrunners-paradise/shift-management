<?php

namespace App\Services;

use App\Models\BlockedDate;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class ShiftSchedulingService
{
    public function __construct(private readonly CalendarDateLock $dateLock) {}

    /**
     * Select one configured period for the authenticated worker and local date.
     * Replacement cancels the old row before creating the new active record.
     * The same period is idempotent; overlapping periods have no capacity limit.
     */
    public function select(User $worker, string $date, string $period): Shift
    {
        if (! array_key_exists($period, config('shifts.periods'))) {
            throw ValidationException::withMessages(['period' => 'יש לבחור אחת מחמש המשמרות המותרות.']);
        }

        return $this->withWorkerDate($worker, $date, function (?Shift $existing, CarbonImmutable $now) use ($worker, $date, $period): Shift {
            if ($existing?->period === $period) {
                return $existing;
            }

            $existing?->cancel($worker->getKey(), 'replaced', $now);

            return Shift::create([
                'worker_id' => $worker->getKey(),
                'shift_date' => $date,
                'period' => $period,
            ]);
        });
    }

    /** Clear the active selection without deleting its cancellation history. */
    public function clear(User $worker, string $date): void
    {
        $this->withWorkerDate($worker, $date, function (?Shift $existing, CarbonImmutable $now) use ($worker): void {
            $existing?->cancel($worker->getKey(), 'worker_cancelled', $now);
        });
    }

    /**
     * Cancel future and currently running shifts during account deactivation.
     * Call within ManagerTransaction, after locking the worker's user row.
     * Completed shifts today and all earlier dates retain their scheduled state.
     */
    public function cancelForDeactivation(User $worker, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Deactivation cancellation requires the account transaction and worker lock.');
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->toDateString();

        // Read dates without locking shift rows first. Every workflow must lock
        // date -> shift, otherwise a simultaneous closure could invert the order.
        // The already-held worker lock prevents new selections on another date.
        $dates = Shift::active()->where('worker_id', $worker->getKey())
            ->where('shift_date', '>=', $today)->orderBy('shift_date')
            ->distinct()->toBase()->pluck('shift_date');

        foreach ($dates as $date) {
            $this->dateLock->acquire($date);
        }

        // A wait may cross the shift end or midnight. Decide whether work is
        // completed using the clock after every required date lock is held.
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->toDateString();

        $shifts = Shift::active()->where('worker_id', $worker->getKey())
            ->where('shift_date', '>=', $today)->orderBy('shift_date')->lockForUpdate()->get();

        foreach ($shifts as $shift) {
            if ($shift->shift_date->toDateString() === $today) {
                $period = config('shifts.periods.'.$shift->period);

                if ($period === null) {
                    throw new LogicException('Unknown stored period requires a reviewed history migration.');
                }

                $end = $shift->shift_date->setTimeFromTimeString($period['end']);

                // At the exact end boundary the planned shift is completed.
                // Start times never restrict a worker's own same-day editing.
                if ($end->lessThanOrEqualTo($now)) {
                    continue;
                }
            }

            $shift->cancel($actor->getKey(), 'worker_deactivated', $now);
        }
    }

    /**
     * Own a worker/date mutation until commit, including authorization and time.
     * User -> date -> shift is the common lock order with account deactivation.
     * Checking the active account again under its row lock closes the race with
     * deactivation after middleware ran. All date checks use Israel calendar days.
     *
     * @template T
     *
     * @param  callable(?Shift, CarbonImmutable): T  $operation
     * @return T
     */
    private function withWorkerDate(User $worker, string $date, callable $operation): mixed
    {
        Validator::make(['shift_date' => $date], ['shift_date' => ['required', 'date_format:Y-m-d']])->validate();
        $calendarDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        // Reject out-of-window dates before inserting a MariaDB DATE key. Repeat
        // inside the lock because a valid request can wait across midnight.
        $this->validateWorkerDate($calendarDate, CarbonImmutable::now(config('app.timezone')));

        return DB::transaction(function () use ($worker, $date, $calendarDate, $operation): mixed {
            $currentWorker = User::query()->lockForUpdate()->find($worker->getKey());

            // An older request must not resume writing after reactivation has
            // revoked its login, even though this account is active again.
            if ($currentWorker === null || ! $currentWorker->is_active || $currentWorker->role !== 'worker'
                || $currentWorker->session_version !== (int) $worker->session_version) {
                throw new AuthorizationException('רק עובדים פעילים יכולים לשנות משמרות.');
            }

            $this->dateLock->acquire($date);
            // Read the clock after waiting for locks: midnight may have passed.
            $now = CarbonImmutable::now(config('app.timezone'));
            $this->validateWorkerDate($calendarDate, $now);

            if (BlockedDate::where('date', $date)->exists()) {
                throw ValidationException::withMessages(['shift_date' => 'התאריך חסום על ידי מנהל/ת ולא ניתן לשנות בו משמרות.']);
            }

            $existing = Shift::active()->where('worker_id', $currentWorker->getKey())
                ->where('shift_date', $date)->lockForUpdate()->first();

            return $operation($existing, $now);
        }, 3);
    }

    /** Validate local calendar days, independently of any period's start time. */
    private function validateWorkerDate(CarbonImmutable $date, CarbonImmutable $now): void
    {
        $today = $now->startOfDay();
        $horizon = config('shifts.booking_horizon_days');

        if ($date->isBefore($today) || $date->isAfter($today->addDays($horizon))) {
            throw ValidationException::withMessages(['shift_date' => "אפשר לשנות משמרות מהיום ועד {$horizon} ימים קדימה בלבד."]);
        }

        if (in_array($date->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], true)) {
            throw ValidationException::withMessages(['shift_date' => 'ניתן לבחור משמרות בימים ראשון עד חמישי בלבד.']);
        }
    }
}
