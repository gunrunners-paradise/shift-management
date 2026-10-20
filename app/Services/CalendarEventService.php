<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\User;

class CalendarEventService
{
    public function __construct(private readonly ManagerTransaction $managerTransaction) {}

    /**
     * Persist a manually entered, validated event or holiday for one local date.
     * Announcements are informational: this workflow never blocks dates or
     * changes shifts. The shared manager lock rechecks authority before writing.
     */
    public function create(User $actor, array $attributes): CalendarEvent
    {
        return $this->managerTransaction->run($actor, function () use ($actor, $attributes): CalendarEvent {
            return CalendarEvent::create([
                ...$this->eventAttributes($attributes),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        });
    }

    /** Re-read under the transaction lock; retain the original creator's ID. */
    public function update(User $actor, CalendarEvent $event, array $attributes): CalendarEvent
    {
        return $this->managerTransaction->run($actor, function () use ($actor, $event, $attributes): CalendarEvent {
            $currentEvent = CalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $currentEvent->fill($this->eventAttributes($attributes));
            $currentEvent->updated_by = $actor->getKey();
            $currentEvent->save();

            return $currentEvent;
        });
    }

    /** Remove only this announcement, leaving independent office blocks intact. */
    public function delete(User $actor, CalendarEvent $event): void
    {
        $this->managerTransaction->run($actor, function () use ($event): void {
            CalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey())->delete();
        });
    }

    private function eventAttributes(array $attributes): array
    {
        return [
            'title' => $attributes['title'],
            'date' => $attributes['event_date'],
            'description' => $attributes['description'] ?? null,
            'kind' => $attributes['kind'],
        ];
    }
}
