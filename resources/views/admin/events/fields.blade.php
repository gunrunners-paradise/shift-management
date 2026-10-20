@php
    // Each edit form shares field names, but only the failed form receives flashed input.
    $oldEventFormId = old('event_form_id');
    $usesOldEventInput = is_scalar($oldEventFormId) && (string) $oldEventFormId === (string) $eventFormId;
    $eventTitle = $usesOldEventInput ? old('title') : ($calendarEvent?->title ?? '');
    $eventDate = $usesOldEventInput ? old('event_date') : ($calendarEvent?->date->toDateString() ?? $today->toDateString());
    $eventKind = $usesOldEventInput ? old('kind') : ($calendarEvent?->kind ?? 'event');
    $eventDescription = $usesOldEventInput ? old('description') : ($calendarEvent?->description ?? '');

    if (! is_string($eventDescription)) {
        $eventDescription = '';
    }

    $fieldPrefix = 'event-'.$eventFormId;
@endphp

{{-- This identifies the displayed form only; the route identifies the record to update. --}}
<input type="hidden" name="event_form_id" value="{{ $eventFormId }}">

<x-field name="title" :id="$fieldPrefix.'-title'" label="שם האירוע או החג" :value="$eventTitle" :show-errors="$usesOldEventInput" maxlength="100" required />

<div class="field-row">
    <x-field name="event_date" :id="$fieldPrefix.'-date'" label="תאריך" type="date" :value="$eventDate" :show-errors="$usesOldEventInput" required />
    <x-field name="kind" :id="$fieldPrefix.'-kind'" label="סוג הודעה" :show-errors="$usesOldEventInput" required>
        <select id="{{ $fieldPrefix }}-kind" name="kind" @class(['form-control', 'is-invalid' => $usesOldEventInput && $errors->has('kind')]) required @if ($usesOldEventInput && $errors->has('kind')) aria-invalid="true" aria-describedby="{{ $fieldPrefix }}-kind-error" @endif>
            @foreach (\App\Models\CalendarEvent::KINDS as $kindValue => $kindLabel)
                <option value="{{ $kindValue }}" @selected($eventKind === $kindValue)>{{ $kindLabel }}</option>
            @endforeach
        </select>
    </x-field>
</div>

<x-field name="description" :id="$fieldPrefix.'-description'" label="תיאור (לא חובה)" :show-errors="$usesOldEventInput">
    <textarea id="{{ $fieldPrefix }}-description" name="description" @class(['form-control', 'is-invalid' => $usesOldEventInput && $errors->has('description')]) maxlength="500" rows="3" @if ($usesOldEventInput && $errors->has('description')) aria-invalid="true" aria-describedby="{{ $fieldPrefix }}-description-error" @endif>{{ $eventDescription }}</textarea>
</x-field>
