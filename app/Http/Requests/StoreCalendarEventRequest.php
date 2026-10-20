<?php

namespace App\Http\Requests;

use App\Models\CalendarEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    protected function prepareForValidation(): void
    {
        $event = $this->route('calendarEvent');

        // The dashboard has several inline forms with the same field names.
        // Flash old input only into the form chosen by the authoritative route,
        // rather than letting a submitted presentation ID target another form.
        $this->merge(['event_form_id' => $event instanceof CalendarEvent ? $event->getKey() : 0]);
    }

    /** Creation and editing accept the same single-date event fields. */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::in(array_keys(CalendarEvent::KINDS))],
            'event_form_id' => ['required', 'integer', 'min:0'],
            // Only a single date is approved. Do not silently accept a range.
            'start_date' => ['prohibited'],
            'end_date' => ['prohibited'],
            'date' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'user_id' => ['prohibited'],
            'worker_id' => ['prohibited'],
        ];
    }
}
