@extends('layouts.app')

@section('title', 'לוח משמרות')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/calendar.css') }}">
    <link rel="stylesheet" href="{{ asset('css/today.css') }}">
    <link rel="stylesheet" href="{{ asset('css/receipts.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/calendar.js') }}" defer></script>
    <script src="{{ asset('js/today.js') }}" defer></script>
@endpush

@section('content')
    <section class="calendar-week-heading" aria-labelledby="calendar-heading">
        <h1 id="calendar-heading">לוח המשמרות שלי</h1>
        <p>
            <time datetime="{{ $weekStart->toDateString() }}">{{ $weekStart->locale('he')->translatedFormat('j F Y') }}</time>
            <span aria-hidden="true">–</span>
            <span class="screen-reader-only">עד</span>
            <time datetime="{{ $weekEnd->toDateString() }}">{{ $weekEnd->locale('he')->translatedFormat('j F Y') }}</time>
        </p>
    </section>

    <ul class="calendar-legend" aria-label="מקרא מצבי המשמרות">
        <li><span class="calendar-swatch calendar-swatch--selected" aria-hidden="true"></span>משמרת נבחרה</li>
        <li><span class="calendar-swatch" aria-hidden="true"></span>בלי משמרת</li>
        <li><span class="calendar-swatch calendar-swatch--alternative" aria-hidden="true"></span>החלפת משמרת</li>
        <li><span class="calendar-swatch calendar-swatch--blocked" aria-hidden="true"></span>יום חסום</li>
    </ul>

    <nav class="calendar-navigation" aria-label="בחירת שבוע">
        <a class="button button--primary" href="{{ route('worker.home', ['week' => $previousWeek->toDateString()]) }}">❮ שבוע קודם</a>
        <a class="button button--primary" href="{{ route('worker.home', ['week' => $nextWeek->toDateString()]) }}">שבוע הבא ❯</a>
        <a class="button button--primary calendar-current-week" href="{{ route('worker.home') }}">השבוע הנוכחי</a>
    </nav>

    <p id="calendar-help" class="calendar-help">בחרו משמרת חממה אחת לכל יום. בחירה אחרת מחליפה את המשמרת הקיימת. ניתן לשנות מהיום ועד {{ config('shifts.booking_horizon_days') }} ימים קדימה, ולאורך כל היום הנוכחי.</p>

    <div class="calendar-scroll" role="region" aria-label="לוח משמרות שבועי" tabindex="0">
        <table class="shift-calendar" aria-describedby="calendar-help">
            <caption class="screen-reader-only">חמש אפשרויות משמרת בימים ראשון עד חמישי. הלוח מציג את הבחירות שלך בלבד.</caption>
            <colgroup>
                <col class="shift-label-column">
                <col span="5">
            </colgroup>
            <thead>
                <tr class="calendar-weekdays">
                    <th scope="col">משמרת</th>
                    @foreach ($days as $day)
                        <th scope="col">{{ $day['weekday'] }}</th>
                    @endforeach
                </tr>
                <tr class="calendar-dates">
                    <th scope="row">תאריך</th>
                    @foreach ($days as $day)
                        <th @class(['calendar-date', 'calendar-date--today' => $day['is_today']])>
                            @if ($day['is_today'])
                                <span class="calendar-today-label">היום</span>
                            @endif
                            <time datetime="{{ $day['date_key'] }}">{{ $day['date']->format('d/m') }}</time>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($periods as $periodCode => $period)
                    <tr>
                        <th scope="row" class="shift-row-heading" aria-label="{{ $period['label'] }}">
                            @if ($periodCode === 'full')
                                <span class="shift-full-label">{{ $period['label'] }}</span>
                            @endif
                            <span class="shift-period-time"><bdi dir="ltr">{{ $period['start'] }}–{{ $period['end'] }}</bdi></span>
                            <span class="shift-period-duration">{{ $period['duration_minutes'] / 60 }} שעות</span>
                        </th>
                        @foreach ($days as $day)
                            <td class="shift-cell">
                                <x-shift-slot :day="$day" :period="$period" :period-code="$periodCode" :week="$weekStart->toDateString()" />
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row">ביטול בחירה</th>
                    @foreach ($days as $day)
                        <td class="calendar-clear-cell">
                            @if ($day['editable'] && ! $day['blocked'])
                                <form id="clear-shift-{{ $day['date_key'] }}" method="POST" action="{{ route('worker.shifts.destroy', ['date' => $day['date_key']]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                                    <button class="calendar-clear-button" type="submit" aria-label="בלי משמרת ביום {{ $day['weekday'] }}, {{ $day['date']->format('d/m/Y') }}">בלי משמרת</button>
                                </form>
                            @elseif ($day['blocked'])
                                <span class="calendar-readonly-label">יום חסום</span>
                            @else
                                <span class="calendar-readonly-label">לקריאה בלבד</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="calendar-footer-note">הלוח מציג משמרות מתוכננות. תאריכי עבר ותאריכים שמעבר לטווח ההרשמה זמינים לקריאה בלבד.</p>

    {{-- Receipts come first below the calendar, before notices and team information. --}}
    @include('worker.receipts')
    @include('worker.today')

    <dialog id="shift-dialog" class="shift-dialog" aria-labelledby="shift-dialog-heading" aria-describedby="shift-dialog-description">
        <h2 id="shift-dialog-heading">בחירת משמרת</h2>
        <p id="shift-dialog-description" data-shift-description></p>
        <p class="shift-dialog-hint">ניתן לבחור משמרת אחת לכל יום. בחירה חדשה מחליפה את הקודמת.</p>
        <div class="shift-dialog-actions">
            <button class="shift-dialog-office" type="button" data-confirm-shift>משמרת חממה</button>
            <button class="button button--secondary" type="button" data-clear-shift>בלי משמרת</button>
            <button class="button button--secondary" type="button" data-dismiss-shift>ביטול</button>
        </div>
    </dialog>
@endsection
