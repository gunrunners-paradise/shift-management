@extends('layouts.app')

@section('title', 'עמדות עבודה')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/desks.css') }}">
@endpush

@section('content')
    @php
        $openingTime = config('desks.opening_time');
        $closingTime = config('desks.closing_time');
        $latestStartTime = $selectedDate->setTimeFromTimeString($closingTime)->subMinutes(config('desks.minimum_minutes'))->format('H:i');
        $earliestEndTime = $selectedDate->setTimeFromTimeString($openingTime)->addMinutes(config('desks.minimum_minutes'))->format('H:i');
        $timeStepSeconds = config('desks.slot_minutes') * 60;
    @endphp
    <section class="page-title" aria-labelledby="page-heading">
        <h1 id="page-heading">הזמנת עמדת עבודה</h1>
        <p>בחרו תאריך ועמדה, בדקו את השעות התפוסות והזמינו זמן לעבודה במשרד. אפשר להזמין עמדה גם בלי לבחור משמרת.</p>
    </section>

    <section class="panel desk-date-panel" aria-label="בחירת יום עבודה">
        <form class="desk-date-form" method="GET" action="{{ route('worker.desks.index') }}">
            <x-field name="date" id="desk-date" label="יום עבודה" type="date" :value="$selectedDate->toDateString()" required />
            <x-button variant="secondary">הצגת היום</x-button>
        </form>
        <nav class="desk-date-navigation" aria-label="מעבר בין ימים">
            <a class="button button--secondary button--small" href="{{ route('worker.desks.index', ['date' => $selectedDate->subDay()->toDateString()]) }}" rel="prev">יום קודם</a>
            <a class="button button--secondary button--small" href="{{ route('worker.desks.index', ['date' => $today->toDateString()]) }}">היום</a>
            <a class="button button--secondary button--small" href="{{ route('worker.desks.index', ['date' => $selectedDate->addDay()->toDateString()]) }}" rel="next">יום הבא</a>
        </nav>
        <p class="desk-day-summary"><time datetime="{{ $selectedDate->toDateString() }}">{{ $selectedDate->translatedFormat('l, j F Y') }}</time></p>
    </section>

    @if ($dateRestriction)
        <p id="desk-date-restriction" class="desk-date-notice" role="status">{{ $dateRestriction }}</p>
    @endif

    <div class="desk-content-layout">
        <x-panel heading-id="desks-heading">
            <div class="desk-section-heading">
                <div>
                    <h2 id="desks-heading">בחרו עמדה</h2>
                    <p class="hint">{{ $canBookDate ? 'בחרו עמדה כדי לראות את השעות התפוסות. זמן פנוי בין הזמנות ניתן להזמנה.' : 'העמדות אינן זמינות לבחירה או להזמנה ביום זה.' }}</p>
                </div>
                <span class="desk-count">{{ $desks->count() }} עמדות פעילות</span>
            </div>

            <ul class="desk-legend" aria-label="מקרא עמדות">
                @if ($canBookDate)
                    <li><span class="desk-swatch" aria-hidden="true"></span>ללא הזמנות</li>
                    <li><span class="desk-swatch desk-swatch--occupied" aria-hidden="true"></span>יש שעות תפוסות</li>
                    <li><span class="desk-swatch desk-swatch--selected" aria-hidden="true"></span>עמדה נבחרה</li>
                @else
                    <li><span class="desk-swatch desk-swatch--disabled" aria-hidden="true"></span>העמדות אינן זמינות ביום זה</li>
                @endif
            </ul>

            <div id="desks-grid" class="desk-grid">
                @forelse ($desks as $desk)
                    {{-- Native disabled controls match the server's date restriction without JavaScript. --}}
                    @if ($canBookDate)
                        <a @class(['desk-card', 'is-selected' => $selectedDesk?->is($desk)]) href="{{ route('worker.desks.index', ['date' => $selectedDate->toDateString(), 'desk_id' => $desk->id]) }}#desk-booking-heading" @if ($selectedDesk?->is($desk)) aria-current="true" @endif>
                    @else
                        <button class="desk-card is-disabled" type="button" disabled aria-describedby="desk-date-restriction">
                    @endif
                        <span class="desk-card-heading">
                            <strong class="desk-card-title">{{ $desk->name }}</strong>
                            <span class="desk-symbol" aria-hidden="true">🖥</span>
                        </span>
                        @if ($canBookDate)
                            <span @class(['desk-availability', 'desk-availability--occupied' => $desk->day_reservations->isNotEmpty()])>{{ $desk->day_reservations->isEmpty() ? 'ללא הזמנות ביום זה' : 'יש שעות תפוסות' }}</span>
                        @else
                            <span class="desk-availability desk-availability--disabled">לא ניתן להזמין ביום זה</span>
                        @endif
                        <span class="desk-card-detail">
                            @if ($desk->day_reservations->isEmpty())
                                אין שעות שמורות ביום הנבחר.
                            @else
                                שעות תפוסות:
                                @foreach ($desk->day_reservations as $busyReservation)
                                    <bdi dir="ltr">{{ $busyReservation->start_time }}–{{ $busyReservation->end_time }}</bdi>@if (! $loop->last) · @endif
                                @endforeach
                            @endif
                        </span>
                        @if ($canBookDate)
                            <span class="desk-card-choice">{{ $selectedDesk?->is($desk) ? '✓ עמדה נבחרה' : 'בחירת העמדה' }}</span>
                        @else
                            <span class="desk-card-choice">לא ניתן לבחור עמדה</span>
                        @endif
                    @if ($canBookDate)
                        </a>
                    @else
                        </button>
                    @endif
                @empty
                    <p class="empty-state">אין כרגע עמדות פעילות להזמנה.</p>
                @endforelse
            </div>
        </x-panel>

        <aside class="panel desk-booking-panel" aria-labelledby="desk-booking-heading">
            <div class="desk-booking-heading">
                <h2 id="desk-booking-heading" tabindex="-1">{{ $activeReservation ? 'עריכת ההזמנה שלי' : 'פרטי ההזמנה' }}</h2>
                @if ($selectedDesk && $canBookDate)
                    <span class="desk-selected-label">{{ $selectedDesk->name }}</span>
                @endif
            </div>
            <p class="desk-booking-date"><time datetime="{{ $selectedDate->toDateString() }}">{{ $selectedDate->format('d/m/Y') }}</time> · שעות המשרד: <bdi dir="ltr">{{ $openingTime }}–{{ $closingTime }}</bdi></p>

            @if ($selectedDesk && $canBookDate && (! $activeReservation || $canEditReservation))
                @php
                    // Reuse failed input only for the date this form actually changes.
                    $hasOldBookingInput = old('reservation_date') === $selectedDate->toDateString();
                    $startTimeValue = $hasOldBookingInput ? old('start_time', $defaultStartTime) : $defaultStartTime;
                    $endTimeValue = $hasOldBookingInput ? old('end_time', $defaultEndTime) : $defaultEndTime;
                @endphp
                <form id="desk-booking-form" method="POST" action="{{ $activeReservation ? route('worker.desks.update', $activeReservation) : route('worker.desks.store') }}">
                    @csrf
                    @if ($activeReservation)
                        @method('PATCH')
                    @endif
                    <input type="hidden" name="reservation_date" value="{{ $selectedDate->toDateString() }}">
                    <input type="hidden" name="desk_id" value="{{ $selectedDesk->id }}">
                    <div class="desk-time-row">
                        <x-field name="start_time" id="desk-start-time" label="משעה" type="time" :value="$startTimeValue" :min="$openingTime" :max="$latestStartTime" :step="$timeStepSeconds" required />
                        <x-field name="end_time" id="desk-end-time" label="עד שעה" type="time" :value="$endTimeValue" :min="$earliestEndTime" :max="$closingTime" :step="$timeStepSeconds" required />
                    </div>
                    <x-button class="desk-booking-submit">{{ $activeReservation ? 'שמירת השינויים' : 'הזמנת העמדה' }}</x-button>
                </form>
                @if ($activeReservation)
                    <p class="desk-booking-note">השמירה תחליף את פרטי ההזמנה הפעילה לאותו יום. ההזמנה הקודמת נשמרת בהיסטוריה.</p>
                @endif
            @elseif ($activeReservation && ! $canEditReservation)
                <p class="empty-state">ההזמנה כבר התחילה או הסתיימה. לא ניתן לערוך או לבטל אותה.</p>
            @elseif (! $selectedDesk)
                <p class="empty-state">אין עמדה זמינה לבחירה.</p>
            @else
                <p class="empty-state">התאריך מוצג לקריאה בלבד. לא ניתן להזמין עמדה ביום זה.</p>
            @endif

            <p class="desk-booking-note">הזמנה אחת בלבד לכל יום, למשך שעה לפחות ובחצאי שעות. ניתן להזמין מהיום ועד <time datetime="{{ $lastBookableDate->toDateString() }}">{{ $lastBookableDate->format('d/m/Y') }}</time>. היום יש לבחור שעת התחלה עתידית. עריכה וביטול אפשריים רק לפני שעת ההתחלה.</p>
            <p class="desk-booking-note">הזמינות המוצגת נכונה למועד פתיחת הדף. בעת השמירה תיבדק שוב הזמינות.</p>

            @if ($selectedDesk)
                <h3 class="desk-busy-heading">שעות תפוסות בעמדה זו</h3>
                <ul class="desk-busy-list">
                    @forelse ($selectedDesk->day_reservations as $busyReservation)
                        <li class="desk-busy-entry">
                            <strong><bdi dir="ltr">{{ $busyReservation->start_time }}–{{ $busyReservation->end_time }}</bdi></strong>
                            <span>{{ $busyReservation->worker_id === auth()->id() ? 'ההזמנה שלי' : 'שעות שמורות' }}</span>
                        </li>
                    @empty
                        <li class="empty-state">אין שעות תפוסות בעמדה זו ביום הנבחר.</li>
                    @endforelse
                </ul>
            @endif
        </aside>
    </div>

    <x-panel id="my-desk-reservations" class="desk-own-panel" title="ההזמנות שלי ביום הנבחר" heading-id="my-desk-reservations-heading" hint="הזמנות שבוטלו נשמרות כאן בהיסטוריה ואינן תופסות עמדה.">
        <ul class="desk-own-list">
            @forelse ($ownReservations as $reservation)
                <li class="desk-own-card">
                    <h3>{{ $reservation->desk->name }}</h3>
                    <p class="desk-own-time"><time datetime="{{ $reservation->reservation_date->toDateString() }}">{{ $reservation->reservation_date->format('d/m/Y') }}</time> · <bdi dir="ltr">{{ $reservation->start_time }}–{{ $reservation->end_time }}</bdi></p>
                    @if ($reservation->cancelled_at)
                        <p class="desk-own-status">ההזמנה בוטלה. הרשומה נשמרת בהיסטוריה.</p>
                    @else
                        <p class="desk-own-status">ההזמנה נשמרה.</p>
                        @if ($canEditReservation && $activeReservation?->is($reservation))
                            <a class="button button--secondary button--small" href="{{ route('worker.desks.index', ['date' => $selectedDate->toDateString(), 'desk_id' => $reservation->desk_id]) }}#desk-booking-heading">עריכת ההזמנה</a>
                            {{-- Native details and a required checkbox keep confirmation usable without JS. --}}
                            <details class="desk-cancel-action">
                                <summary>ביטול ההזמנה</summary>
                                <form class="desk-cancel-form" method="POST" action="{{ route('worker.desks.destroy', $reservation) }}">
                                    @csrf
                                    @method('DELETE')
                                    <label class="confirmation-label" for="confirm-reservation-cancellation-{{ $reservation->id }}">
                                        <input id="confirm-reservation-cancellation-{{ $reservation->id }}" type="checkbox" name="confirm_cancellation" value="1" required>
                                        <span>אני מאשר/ת את ביטול ההזמנה עבור {{ $reservation->desk->name }} ביום הנבחר.</span>
                                    </label>
                                    <x-button variant="danger" class="button--small">אישור ביטול ההזמנה</x-button>
                                </form>
                            </details>
                        @else
                            <p class="desk-own-status">ההזמנה התחילה או הסתיימה. עריכה וביטול אינם זמינים.</p>
                        @endif
                    @endif
                </li>
            @empty
                <li class="empty-state">אין לך הזמנות ביום הנבחר.</li>
            @endforelse
        </ul>
    </x-panel>
@endsection
