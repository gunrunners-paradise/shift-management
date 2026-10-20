<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class TodayInformationService
{
    /**
     * Read today's notices, celebrations and planned staffing for Blade and JSON.
     * The viewed calendar week is deliberately not an input: these panels always
     * describe the actual business date in Israel. Return only public team fields,
     * never an entire User model, birthday year, username or private storage path.
     */
    public function forToday(): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));

        return [
            'date' => $today->toDateString(),
            'display_date' => $today->format('d/m/Y'),
            'announcements' => $this->announcements($today),
            'birthdays' => $this->birthdays($today),
            'coworkers' => $this->coworkers($today),
        ];
    }

    /** Notices are informational; this read never closes a date or cancels shifts. */
    private function announcements(CarbonImmutable $today): array
    {
        $events = CalendarEvent::where('date', $today->toDateString())
            ->orderBy('id')->get(['title', 'description', 'kind']);
        $announcements = [];

        foreach ($events as $event) {
            $announcements[] = [
                'title' => $event->title,
                'description' => $event->description,
                'kind' => $event->kind,
            ];
        }

        return $announcements;
    }

    /** February 29 birthdays are celebrated on February 28 in non-leap years. */
    private function birthdays(CarbonImmutable $today): array
    {
        $workers = User::where('role', 'worker')->where('is_active', true)
            ->where(function (Builder $query) use ($today): void {
                $query->where(function (Builder $exactDate) use ($today): void {
                    $exactDate->whereMonth('birth_date', $today->month)
                        ->whereDay('birth_date', $today->day);
                });

                if (! $today->isLeapYear() && $today->month === 2 && $today->day === 28) {
                    $query->orWhere(function (Builder $leapBirthday): void {
                        $leapBirthday->whereMonth('birth_date', 2)->whereDay('birth_date', 29);
                    });
                }
            })
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'photo_path']);

        $people = [];

        foreach ($workers as $worker) {
            $people[] = $this->person($worker);
        }

        return $people;
    }

    /** One joined query reads non-canceled selections belonging to active workers. */
    private function coworkers(CarbonImmutable $today): array
    {
        $periods = config('shifts.periods');
        $workers = User::query()->join('shifts', 'shifts.worker_id', '=', 'users.id')
            ->where('users.role', 'worker')->where('users.is_active', true)
            ->where('shifts.shift_date', $today->toDateString())
            ->whereNull('shifts.cancelled_at')->whereIn('shifts.period', array_keys($periods))
            ->orderBy('users.first_name')->orderBy('users.last_name')->orderBy('users.id')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.photo_path', 'shifts.period']);

        $people = [];

        foreach ($workers as $worker) {
            $period = $periods[$worker->period];
            $people[] = [
                ...$this->person($worker),
                'period_label' => $period['label'],
                'start' => $period['start'],
                'end' => $period['end'],
            ];
        }

        return $people;
    }

    private function person(User $worker): array
    {
        return ['name' => $worker->fullName(), 'avatar_url' => $worker->avatarUrl()];
    }
}
