<x-panel id="events" class="events-panel" title="אירועים וחגים בלוח השנה" heading-id="events-heading" hint="הודעות ליום אחד, המוזנות ידנית. אירוע או חג אינם חוסמים הרשמה למשמרות או הזמנת עמדות. לסגירת המשרד יש לחסום את התאריך בנפרד.">
    <div class="events-layout">
        <div>
            <h3>הוספת אירוע או חג</h3>
            <form method="POST" action="{{ route('admin.events.store') }}">
                @csrf
                @include('admin.events.fields', ['calendarEvent' => null, 'eventFormId' => 0])
                <x-button>הוספת הודעה ללוח</x-button>
            </form>
        </div>

        <div class="events-list-column">
            <h3>הודעות בלוח</h3>
            <p class="hint">{{ $calendarEvents->total() }} הודעות. העובדים רואים רק הודעות לתאריך של היום.</p>
            <ul class="events-list">
                @forelse ($calendarEvents as $calendarEvent)
                    <li class="event-card">
                        <div class="event-card-heading">
                            <strong>{{ $calendarEvent->title }}</strong>
                            <span @class(['event-kind', 'event-kind--holiday' => $calendarEvent->kind === 'holiday'])>{{ $calendarEvent->kindLabel() }}</span>
                        </div>
                        <p class="event-date"><time datetime="{{ $calendarEvent->date->toDateString() }}">{{ $calendarEvent->date->format('d/m/Y') }}</time></p>
                        @if ($calendarEvent->description)
                            <p class="event-description">{{ $calendarEvent->description }}</p>
                        @endif

                        <details class="event-action" @if (is_scalar(old('event_form_id')) && (string) old('event_form_id') === (string) $calendarEvent->id) open @endif>
                            <summary>עריכת ההודעה <span class="screen-reader-only">{{ $calendarEvent->title }}</span></summary>
                            <form method="POST" action="{{ route('admin.events.update', $calendarEvent) }}">
                                @csrf
                                @method('PATCH')
                                @include('admin.events.fields', ['eventFormId' => $calendarEvent->id])
                                <x-button class="button--small">שמירת שינויים</x-button>
                            </form>
                        </details>

                        <details class="event-action event-action--danger">
                            <summary>הסרת ההודעה <span class="screen-reader-only">{{ $calendarEvent->title }}</span></summary>
                            <form class="event-delete-form" method="POST" action="{{ route('admin.events.destroy', $calendarEvent) }}">
                                @csrf
                                @method('DELETE')
                                <label class="confirmation-label" for="confirm-event-deletion-{{ $calendarEvent->id }}">
                                    <input id="confirm-event-deletion-{{ $calendarEvent->id }}" type="checkbox" name="confirm_event_deletion" value="1" required>
                                    <span>אני מאשר/ת את הסרת ההודעה ״{{ $calendarEvent->title }}״ מהלוח.</span>
                                </label>
                                <x-button variant="danger" class="button--small">הסרת הודעה</x-button>
                            </form>
                        </details>
                    </li>
                @empty
                    <li class="empty-state">אין אירועים או חגים בלוח.</li>
                @endforelse
            </ul>

            @if ($calendarEvents->hasPages())
                <nav class="pagination" aria-label="דפדוף באירועים ובחגים">
                    @if ($calendarEvents->onFirstPage())
                        <span class="pagination-disabled">הקודם</span>
                    @else
                        <a href="{{ $calendarEvents->previousPageUrl() }}#events">הקודם</a>
                    @endif
                    <span>עמוד {{ $calendarEvents->currentPage() }} מתוך {{ $calendarEvents->lastPage() }}</span>
                    @if ($calendarEvents->hasMorePages())
                        <a href="{{ $calendarEvents->nextPageUrl() }}#events">הבא</a>
                    @else
                        <span class="pagination-disabled">הבא</span>
                    @endif
                </nav>
            @endif
        </div>
    </div>
</x-panel>
