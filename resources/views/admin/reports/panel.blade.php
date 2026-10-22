<x-panel id="reports" class="workday-report" title="דוח ימי עבודה — CSV" heading-id="workday-report-heading" hint="הדוח סופר ימי משמרת מתוכננים עד היום, כולל עובדים עם 0 ימים. אינו אישור נוכחות בפועל.">
    {{-- A normal form requires an explicit download request; opening the dashboard never generates a report. --}}
    <form id="workday-report-form" class="workday-report-form" method="POST" action="{{ route('admin.reports.workdays') }}">
        @csrf
        <x-field name="start_date" id="report-start-date" label="מתאריך (כולל)" type="date" :value="old('start_date', $today->startOfMonth()->toDateString())" :max="$today->toDateString()" dir="ltr" required />
        <x-field name="end_date" id="report-end-date" label="עד תאריך (כולל)" type="date" :value="old('end_date', $today->toDateString())" :max="$today->toDateString()" dir="ltr" required />
        <x-button>הורדת CSV</x-button>
    </form>
</x-panel>
