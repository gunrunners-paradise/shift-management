<?php

namespace App\Services;

use App\Models\BlockedDate;
use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class DeskReservationService
{
    public function __construct(private readonly CalendarDateLock $dateLock) {}

    /**
     * Book one local interval without requiring a shift on that date.
     * The worker and date locks prevent two daily bookings or overlapping desk
     * bookings, including when this date has never been booked or blocked.
     */
    public function book(User $worker, int $deskId, string $date, string $start, string $end): DeskReservation
    {
        $this->validateTimeFormat($start, $end);

        return $this->withWorkerDate($worker, $date, [$deskId], function () use ($worker, $deskId, $date, $start, $end): DeskReservation {
            $existing = DeskReservation::active()->where('worker_id', $worker->getKey())
                ->where('reservation_date', $date)->lockForUpdate()->first();
            $now = CarbonImmutable::now(config('app.timezone'));
            $this->validateBookingInterval($date, $start, $end, $now);

            if ($existing !== null) {
                throw ValidationException::withMessages(['reservation_date' => 'ניתן להחזיק הזמנת עמדה פעילה אחת בלבד בכל יום. אפשר לערוך או לבטל את ההזמנה הקיימת.']);
            }

            $this->ensureDeskIsAvailable($deskId, $date, $start, $end);

            return $this->createReservation($worker, $deskId, $date, $start, $end);
        });
    }

    /**
     * Replace an owned, not-yet-started interval while retaining its old row.
     * The date stays fixed; booking a different date is a separate reservation.
     * Lock old and new desk IDs numerically to make a desk change deterministic.
     */
    public function update(User $worker, DeskReservation $reservation, int $deskId, string $start, string $end): DeskReservation
    {
        $this->ensureOwnership($worker, $reservation);
        $this->validateTimeFormat($start, $end);
        $date = $reservation->reservation_date->toDateString();

        return $this->withWorkerDate($worker, $date, [$reservation->desk_id, $deskId], function () use ($worker, $reservation, $deskId, $date, $start, $end): DeskReservation {
            $current = DeskReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());
            $now = CarbonImmutable::now(config('app.timezone'));
            $this->ensureOwnership($worker, $current);
            $this->ensureCanChange($current, $now);
            $this->validateBookingInterval($date, $start, $end, $now);

            if ($current->desk_id === $deskId && $current->start_time === $start && $current->end_time === $end) {
                return $current;
            }

            $this->ensureDeskIsAvailable($deskId, $date, $start, $end, $current->getKey());
            $current->cancel($worker->getKey(), 'replaced', $now);

            return $this->createReservation($worker, $deskId, $date, $start, $end);
        });
    }

    /** Cancel only an owned interval that has not started; never delete it. */
    public function cancel(User $worker, DeskReservation $reservation): void
    {
        $this->ensureOwnership($worker, $reservation);
        $date = $reservation->reservation_date->toDateString();

        $this->withWorkerDate($worker, $date, [$reservation->desk_id], function () use ($worker, $reservation, $date): void {
            $current = DeskReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());
            $this->ensureOwnership($worker, $current);

            if ($current->cancelled_at !== null) {
                return;
            }

            // Read the clock after every lock: the request may have waited
            // across either the booking start or Israel's local midnight.
            $now = CarbonImmutable::now(config('app.timezone'));
            $this->validateWorkerDate($date, $now);
            $this->ensureCanChange($current, $now);
            $current->cancel($worker->getKey(), 'worker_cancelled', $now);
        });
    }

    /**
     * Cancel future and running bookings within account deactivation.
     * Caller owns ManagerTransaction and the worker row lock. That worker lock
     * prevents new dates appearing while this operation gathers serialization
     * keys. Completed intervals, including their exact end boundary, remain.
     */
    public function cancelForDeactivation(User $worker, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Desk cancellation requires the account transaction and worker lock.');
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $dates = DeskReservation::active()->where('worker_id', $worker->getKey())
            ->where('reservation_date', '>=', $today)->orderBy('reservation_date')
            ->distinct()->toBase()->pluck('reservation_date');

        foreach ($dates as $date) {
            $this->dateLock->acquire($date);
        }

        $deskIds = DeskReservation::active()->where('worker_id', $worker->getKey())
            ->where('reservation_date', '>=', $today)->orderBy('desk_id')
            ->distinct()->pluck('desk_id')->all();
        $this->lockDesks($deskIds, false);

        $reservations = DeskReservation::active()->where('worker_id', $worker->getKey())
            ->where('reservation_date', '>=', $today)->orderBy('reservation_date')->orderBy('id')
            ->lockForUpdate()->get();
        $now = CarbonImmutable::now(config('app.timezone'));

        foreach ($reservations as $reservation) {
            if ($reservation->endsAt()->greaterThan($now)) {
                $reservation->cancel($actor->getKey(), 'worker_deactivated', $now);
            }
        }
    }

    /**
     * Own account -> permanent date -> ascending desk locks until commit.
     * Every worker mutation, including cancellation, follows this order. The
     * desk lock also serializes against desk deletion on first-ever dates.
     * The callback locks reservation rows last, then checks the current clock.
     *
     * @template T
     *
     * @param  array<int, int>  $deskIds
     * @param  callable(): T  $operation
     * @return T
     */
    private function withWorkerDate(User $worker, string $date, array $deskIds, callable $operation): mixed
    {
        Validator::make(['reservation_date' => $date], ['reservation_date' => ['required', 'date_format:Y-m-d']])->validate();
        $this->validateWorkerDate($date, CarbonImmutable::now(config('app.timezone')));

        return DB::transaction(function () use ($worker, $date, $deskIds, $operation): mixed {
            $currentWorker = User::query()->lockForUpdate()->find($worker->getKey());

            if ($currentWorker === null || ! $currentWorker->is_active || $currentWorker->role !== 'worker') {
                throw new AuthorizationException('רק עובדים פעילים יכולים להזמין עמדות.');
            }

            $this->dateLock->acquire($date);
            $this->lockDesks($deskIds, true);

            if (BlockedDate::query()->where('date', $date)->exists()) {
                throw ValidationException::withMessages(['reservation_date' => 'התאריך חסום על ידי מנהל/ת ולא ניתן להזמין בו עמדות.']);
            }

            return $operation();
        }, 3);
    }

    /** Lock desk identities before any reservation rows, even on cancellation. */
    private function lockDesks(array $deskIds, bool $requireActive): void
    {
        $deskIds = array_values(array_unique($deskIds));
        sort($deskIds, SORT_NUMERIC);

        foreach ($deskIds as $deskId) {
            $desk = Desk::query()->lockForUpdate()->find($deskId);

            if ($desk === null || ($requireActive && ! $desk->is_active)) {
                throw ValidationException::withMessages(['desk_id' => 'העמדה שנבחרה אינה זמינה להזמנה.']);
            }
        }
    }

    private function ensureOwnership(User $worker, DeskReservation $reservation): void
    {
        if ($reservation->worker_id !== $worker->getKey()) {
            throw new AuthorizationException('ניתן לשנות רק את הזמנות העמדה שלך.');
        }
    }

    /** Changes cease exactly at start time, independently of the shift grid. */
    private function ensureCanChange(DeskReservation $reservation, CarbonImmutable $now): void
    {
        if ($reservation->cancelled_at !== null) {
            throw ValidationException::withMessages(['reservation' => 'ההזמנה כבר בוטלה. יש לבצע הזמנה חדשה.']);
        }

        if ($reservation->startsAt()->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages(['reservation' => 'ניתן לערוך או לבטל הזמנה רק לפני שעת ההתחלה שלה.']);
        }
    }

    private function validateTimeFormat(string $start, string $end): void
    {
        Validator::make(['start_time' => $start, 'end_time' => $end], [
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ])->validate();
    }

    /** Validate real Israel calendar days, before and after waiting for locks. */
    private function validateWorkerDate(string $date, CarbonImmutable $now): void
    {
        $calendarDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        $today = $now->startOfDay();
        $horizon = config('desks.booking_horizon_days');

        if ($calendarDate->lessThan($today) || $calendarDate->greaterThan($today->addDays($horizon))) {
            throw ValidationException::withMessages(['reservation_date' => "אפשר להזמין עמדות מהיום ועד {$horizon} ימים קדימה בלבד."]);
        }

        if (in_array($calendarDate->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], true)) {
            throw ValidationException::withMessages(['reservation_date' => 'ניתן להזמין עמדות בימים ראשון עד חמישי בלבד.']);
        }
    }

    /** Check office bounds, half-hour alignment and the strict future start. */
    private function validateBookingInterval(string $date, string $start, string $end, CarbonImmutable $now): void
    {
        $this->validateWorkerDate($date, $now);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        $startsAt = $day->setTimeFromTimeString($start);
        $endsAt = $day->setTimeFromTimeString($end);
        $step = config('desks.slot_minutes');
        $minimumDuration = config('desks.minimum_minutes');

        if ($startsAt->minute % $step !== 0 || $endsAt->minute % $step !== 0) {
            throw ValidationException::withMessages(['start_time' => 'שעות ההתחלה והסיום חייבות להיות בשעה עגולה או בחצי שעה.']);
        }

        $openingTime = config('desks.opening_time');
        $closingTime = config('desks.closing_time');

        if ($start < $openingTime || $end > $closingTime || $endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['end_time' => "ההזמנה חייבת להיות באותו יום בין {$openingTime} ל־{$closingTime}, עם סיום אחרי ההתחלה."]);
        }

        if ($startsAt->diffInMinutes($endsAt, false) < $minimumDuration) {
            throw ValidationException::withMessages(['end_time' => "משך ההזמנה חייב להיות לפחות {$minimumDuration} דקות."]);
        }

        if ($startsAt->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages(['start_time' => 'שעת ההתחלה חייבת להיות מאוחרת מהשעה הנוכחית.']);
        }
    }

    /** Strict inequalities allow adjacent reservations to touch at endpoints. */
    private function ensureDeskIsAvailable(int $deskId, string $date, string $start, string $end, ?int $exceptId = null): void
    {
        $overlaps = DeskReservation::active()->where('desk_id', $deskId)->where('reservation_date', $date)
            ->where('start_time', '<', $end)->where('end_time', '>', $start);

        if ($exceptId !== null) {
            $overlaps->whereKeyNot($exceptId);
        }

        if ($overlaps->lockForUpdate()->first() !== null) {
            throw ValidationException::withMessages(['desk_id' => 'העמדה תפוסה בחלק מהשעות שבחרת. יש לבחור שעות אחרות או עמדה אחרת.']);
        }
    }

    private function createReservation(User $worker, int $deskId, string $date, string $start, string $end): DeskReservation
    {
        return DeskReservation::create([
            'worker_id' => $worker->getKey(),
            'desk_id' => $deskId,
            'reservation_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }
}
