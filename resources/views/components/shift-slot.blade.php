@props(['day', 'period', 'periodCode', 'week'])

@php
    $isSelected = $day['selected_period'] === $periodCode;
    $isAvailable = $day['editable'] && ! $day['blocked'];
    $buttonText = 'בחירה';
    $statusDescription = 'ללא משמרת';

    if ($day['blocked']) {
        $buttonText = 'חסום';
        $statusDescription = 'יום חסום על ידי מנהל/ת';

        if ($day['block_reason']) {
            $statusDescription .= ': '.$day['block_reason'];
        }
    } elseif ($isSelected) {
        $buttonText = '✓ נבחרה';
        $statusDescription = 'המשמרת שנבחרה';
    } elseif ($day['selected_period']) {
        $buttonText = 'החלפה';
        $statusDescription = 'החלפת המשמרת שנבחרה';
    }

    if (! $day['blocked'] && ! $day['editable']) {
        $statusDescription .= '. לקריאה בלבד';

        if (! $isSelected) {
            $buttonText = 'לקריאה';
        }
    }
@endphp

{{-- Blade owns every cell and authoritative form value; JS only adds a dialog. --}}
<form
    class="shift-choice-form"
    method="POST"
    action="{{ route('worker.shifts.store') }}"
    data-shift-form
    data-date-label="{{ $day['weekday'] }}, {{ $day['date']->format('d/m/Y') }}"
    data-period-label="{{ $period['label'] }}"
    data-period-time="{{ $period['start'] }}–{{ $period['end'] }}"
    data-clear-form="clear-shift-{{ $day['date_key'] }}"
>
    @csrf
    <input type="hidden" name="shift_date" value="{{ $day['date_key'] }}">
    <input type="hidden" name="period" value="{{ $periodCode }}">
    <input type="hidden" name="week" value="{{ $week }}">
    <button
        @class([
            'shift-slot',
            'shift-slot--selected' => $isSelected && ! $day['blocked'],
            'shift-slot--alternative' => $isAvailable && $day['selected_period'] && ! $isSelected,
            'shift-slot--blocked' => $day['blocked'],
            'shift-slot--readonly' => ! $day['editable'] && ! $day['blocked'] && ! $isSelected,
        ])
        type="submit"
        data-date="{{ $day['date_key'] }}"
        data-period="{{ $periodCode }}"
        aria-label="{{ $day['weekday'] }}, {{ $day['date']->format('d/m/Y') }}, {{ $period['label'] }}: {{ $statusDescription }}"
        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
        title="{{ $statusDescription }}"
        @disabled(! $isAvailable)
    >{{ $buttonText }}</button>
</form>
