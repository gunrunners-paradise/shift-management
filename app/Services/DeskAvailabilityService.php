<?php

namespace App\Services;

use App\Models\BlockedDate;
use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DeskAvailabilityService
{
    /**
     * Read the selected date for the worker's server-rendered desk page.
     * Availability is advisory: another request may book after this read.
     * The reservation service always validates again under transaction locks.
     * Other workers' names and private profile fields are never loaded here.
     */
    public function forDate(User $worker, string $date, ?int $deskId): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $selectedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        $desks = Desk::where('is_active', true)->orderBy('id')->get();
        $dayReservations = DeskReservation::active()->where('reservation_date', $date)
            ->orderBy('start_time')->orderBy('id')
            ->get(['id', 'desk_id', 'worker_id', 'reservation_date', 'start_time', 'end_time'])
            ->groupBy('desk_id');

        foreach ($desks as $desk) {
            $desk->setRelation('day_reservations', $dayReservations->get($desk->getKey(), collect()));
        }

        $reservationHistory = DeskReservation::with('desk')->where('worker_id', $worker->getKey())
            ->where('reservation_date', $date)->orderByDesc('id')->get();
        $ownReservations = collect();
        $activeReservation = null;

        foreach ($reservationHistory as $reservation) {
            // Eager loading uses a second query. A manager may delete the desk
            // and its reservations between these reads; omit that stale row
            // before Blade dereferences its desk or offers booking actions.
            if ($reservation->desk === null) {
                continue;
            }

            $ownReservations->push($reservation);

            if ($activeReservation === null && $reservation->cancelled_at === null) {
                $activeReservation = $reservation;
            }
        }

        // Redisplay a failed choice only on its own date. A GET desk selection
        // otherwise takes precedence over the existing reservation's desk.
        if (old('reservation_date') === $date && filter_var(old('desk_id'), FILTER_VALIDATE_INT) !== false) {
            $deskId = (int) old('desk_id');
        }

        $deskId ??= $activeReservation?->desk_id;
        $selectedDesk = $desks->firstWhere('id', $deskId) ?? $desks->first();
        $dateRestriction = $this->dateRestriction($selectedDate, $now);
        $canEditReservation = $activeReservation !== null && $dateRestriction === null
            && $activeReservation->startsAt()->isAfter($now);
        $timeOptions = $this->timeOptions($selectedDate);
        $defaultStartTime = $this->defaultStartTime($selectedDate, $now, $timeOptions);
        $defaultEndTime = $selectedDate->setTimeFromTimeString($defaultStartTime)
            ->addMinutes(config('desks.minimum_minutes'))->format('H:i');

        return [
            'selectedDate' => $selectedDate,
            'today' => $now->startOfDay(),
            'lastBookableDate' => $now->startOfDay()->addDays(config('desks.booking_horizon_days')),
            'desks' => $desks,
            'selectedDesk' => $selectedDesk,
            'ownReservations' => $ownReservations,
            'activeReservation' => $activeReservation,
            'canBookDate' => $dateRestriction === null,
            'dateRestriction' => $dateRestriction,
            'canEditReservation' => $canEditReservation,
            'defaultStartTime' => $activeReservation?->start_time ?? $defaultStartTime,
            'defaultEndTime' => $activeReservation?->end_time ?? $defaultEndTime,
        ];
    }

    /** Display why a date is read-only; mutation rules remain authoritative in the booking service. */
    private function dateRestriction(CarbonImmutable $date, CarbonImmutable $now): ?string
    {
        $today = $now->startOfDay();

        if ($date->isBefore($today) || $date->isAfter($today->addDays(config('desks.booking_horizon_days')))) {
            return 'ניתן להזמין עמדות מהיום ועד '.config('desks.booking_horizon_days').' ימים קדימה. תאריך זה זמין לצפייה בלבד.';
        }

        if (in_array($date->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], true)) {
            return 'הזמנת עמדות זמינה בימים ראשון עד חמישי בלבד.';
        }

        if (BlockedDate::where('date', $date->toDateString())->exists()) {
            return 'התאריך חסום על ידי מנהל/ת. לא ניתן להזמין בו עמדה.';
        }

        $latestStart = $date->setTimeFromTimeString(config('desks.closing_time'))
            ->subMinutes(config('desks.minimum_minutes'));

        if (! $latestStart->isAfter($now)) {
            return 'לא נותר היום טווח הזמנה של שעה לפחות שמתחיל אחרי השעה הנוכחית.';
        }

        return null;
    }

    /** Use the same configured half-hour grid for both native time selectors. */
    private function timeOptions(CarbonImmutable $date): array
    {
        $time = $date->setTimeFromTimeString(config('desks.opening_time'));
        $closing = $date->setTimeFromTimeString(config('desks.closing_time'));
        $options = [];

        while ($time->lessThanOrEqualTo($closing)) {
            $options[] = $time->format('H:i');
            $time = $time->addMinutes(config('desks.slot_minutes'));
        }

        return $options;
    }

    /** Suggest the next future grid time; closed dates keep harmless read-only defaults. */
    private function defaultStartTime(CarbonImmutable $date, CarbonImmutable $now, array $options): string
    {
        $closing = $date->setTimeFromTimeString(config('desks.closing_time'));

        foreach ($options as $option) {
            $start = $date->setTimeFromTimeString($option);

            if ($start->isAfter($now) && $start->addMinutes(config('desks.minimum_minutes'))->lessThanOrEqualTo($closing)) {
                return $option;
            }
        }

        return config('desks.opening_time');
    }
}
