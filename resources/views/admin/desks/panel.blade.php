<x-panel id="desks" class="managed-desks-panel" title="ניהול עמדות עבודה" heading-id="managed-desks-heading" hint="הוספה, שינוי שם והשבתת עמדות. שינוי שם שומר על מזהה העמדה ועל ההיסטוריה שלה. מנהלים אינם יכולים להזמין עמדות לעצמם.">
    <div class="managed-desks-layout">
        <div>
            <h3>הוספת עמדה</h3>
            <form method="POST" action="{{ route('admin.desks.store') }}">
                @csrf
                <input type="hidden" name="desk_form_id" value="0">
                <x-field name="name" id="new-desk-name" label="שם העמדה" :value="old('desk_form_id') === '0' || old('desk_form_id') === 0 ? old('name') : null" maxlength="60" :show-errors="old('desk_form_id') === '0' || old('desk_form_id') === 0" required />
                <x-button>הוספת עמדה</x-button>
            </form>
        </div>

        <div>
            <h3>עמדות המשרד</h3>
            <p class="hint">{{ $managedDesks->total() }} עמדות ברשימה, כולל עמדות שהושבתו. עמדות לא פעילות אינן ניתנות להזמנה.</p>
            <ul class="managed-desks-list">
                @forelse ($managedDesks as $managedDesk)
                    @php
                        $isFailedDeskForm = is_scalar(old('desk_form_id')) && (string) old('desk_form_id') === (string) $managedDesk->id;
                    @endphp
                    <li class="managed-desk-card">
                        <div class="managed-desk-heading">
                            <h3>{{ $managedDesk->name }}</h3>
                            <span @class(['status-chip', 'status-chip--inactive' => ! $managedDesk->is_active])>{{ $managedDesk->is_active ? 'פעילה' : 'לא פעילה' }}</span>
                        </div>
                        <p class="managed-desk-summary">מזהה עמדה: {{ $managedDesk->id }} · {{ $managedDesk->upcoming_reservations_count }} הזמנות עתידיות · {{ $managedDesk->in_progress_reservations_count }} הזמנות שמתבצעות כעת</p>

                        @if ($managedDesk->is_active)
                            <details class="managed-desk-action" @if ($isFailedDeskForm) open @endif>
                                <summary>שינוי שם העמדה <span class="screen-reader-only">{{ $managedDesk->name }}</span></summary>
                                <form method="POST" action="{{ route('admin.desks.update', $managedDesk) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="desk_form_id" value="{{ $managedDesk->id }}">
                                    <x-field name="name" :id="'desk-name-'.$managedDesk->id" label="שם העמדה" :value="$isFailedDeskForm ? old('name', $managedDesk->name) : $managedDesk->name" maxlength="60" :show-errors="$isFailedDeskForm" required />
                                    <x-button class="button--small">שמירת שם העמדה</x-button>
                                </form>
                            </details>

                            <details class="managed-desk-action managed-desk-action--danger">
                                <summary>השבתת העמדה <span class="screen-reader-only">{{ $managedDesk->name }}</span></summary>
                                <form class="managed-desk-deactivation" method="POST" action="{{ route('admin.desks.deactivate', $managedDesk) }}">
                                    @csrf
                                    @method('PATCH')
                                    <p id="desk-deactivation-warning-{{ $managedDesk->id }}" class="managed-desk-warning">כל ההזמנות העתידיות וההזמנות שכבר התחילו וטרם הסתיימו יבוטלו מיד. הזמנות שהסתיימו והיסטוריית הביטולים יישמרו. העמדה תפסיק להופיע ברשימת העמדות להזמנה.</p>
                                    <label class="confirmation-label" for="confirm-desk-deactivation-{{ $managedDesk->id }}">
                                        <input id="confirm-desk-deactivation-{{ $managedDesk->id }}" type="checkbox" name="confirm_deactivation" value="1" aria-describedby="desk-deactivation-warning-{{ $managedDesk->id }}" required>
                                        <span>אני מאשר/ת את השבתת {{ $managedDesk->name }} וביטול ההזמנות.</span>
                                    </label>
                                    <x-button variant="danger" class="button--small">השבתת העמדה</x-button>
                                </form>
                            </details>
                        @else
                            <p class="managed-desk-summary">העמדה הושבתה. הרשומות וההזמנות הקודמות נשמרות בהיסטוריה.</p>
                        @endif
                    </li>
                @empty
                    <li class="empty-state">אין עמדות להצגה.</li>
                @endforelse
            </ul>

            @if ($managedDesks->hasPages())
                <nav class="pagination" aria-label="דפדוף בעמדות">
                    @if ($managedDesks->onFirstPage())
                        <span class="pagination-disabled">הקודם</span>
                    @else
                        <a href="{{ $managedDesks->previousPageUrl() }}#desks">הקודם</a>
                    @endif
                    <span>עמוד {{ $managedDesks->currentPage() }} מתוך {{ $managedDesks->lastPage() }}</span>
                    @if ($managedDesks->hasMorePages())
                        <a href="{{ $managedDesks->nextPageUrl() }}#desks">הבא</a>
                    @else
                        <span class="pagination-disabled">הבא</span>
                    @endif
                </nav>
            @endif
        </div>
    </div>
</x-panel>
