<x-panel id="blocks" class="blocked-dates-panel" title="חסימת ימי עבודה" heading-id="blocked-dates-heading" hint="חסימת היום או תאריך עתידי מונעת בחירת משמרות והזמנת עמדות ומבטלת משמרות והזמנות עמדה קיימות באותו תאריך. היסטוריית הביטולים נשמרת.">
    <div class="blocked-dates-layout">
        <form method="POST" action="{{ route('admin.blocks.store') }}" class="blocked-date-form">
            @csrf
            <x-field name="shift_date" label="תאריך לחסימה" type="date" :value="old('shift_date')" :min="$today->toDateString()" required />
            <x-field name="reason" label="סיבה (לא חובה)" :value="old('reason')" maxlength="150" />

            <label class="confirmation-label" for="confirm-block">
                <input id="confirm-block" type="checkbox" name="confirm_block" value="1" required @if ($errors->has('confirm_block')) aria-invalid="true" aria-describedby="confirm-block-error" @endif>
                <span>אני מאשר/ת את חסימת התאריך וביטול כל המשמרות והזמנות העמדה הקיימות בו.</span>
            </label>
            @error('confirm_block')
                <p id="confirm-block-error" class="field-error">{{ $message }}</p>
            @enderror

            <x-button variant="danger" class="blocked-date-submit">חסימת התאריך</x-button>
        </form>

        <div class="blocked-dates-list-column">
            <h3>תאריכים חסומים</h3>
            <p class="hint">הסרת חסימה מאפשרת הרשמה מחדש. משמרות והזמנות עמדה שבוטלו נשארות מבוטלות.</p>
            <ul class="blocked-dates-list">
                @forelse ($blockedDates as $blockedDate)
                    <li class="blocked-date-item">
                        <div class="blocked-date-heading">
                            <strong><time datetime="{{ $blockedDate->date->toDateString() }}">{{ $blockedDate->date->format('d/m/Y') }}</time></strong>
                            <span class="status-chip status-chip--inactive">יום חסום</span>
                        </div>
                        <p class="blocked-date-reason">{{ $blockedDate->reason ?: 'לא הוזנה סיבה.' }}</p>

                        @if ($blockedDate->date->greaterThanOrEqualTo($today))
                            <form class="unblock-date-form" method="POST" action="{{ route('admin.blocks.destroy', ['date' => $blockedDate->date->toDateString()]) }}">
                                @csrf
                                @method('DELETE')
                                <label class="confirmation-label" for="confirm-unblock-{{ $blockedDate->date->toDateString() }}">
                                    <input id="confirm-unblock-{{ $blockedDate->date->toDateString() }}" type="checkbox" name="confirm_unblock" value="1" required>
                                    <span>אני מאשר/ת את הסרת החסימה. המשמרות והזמנות העמדה שבוטלו לא ישוחזרו.</span>
                                </label>
                                <x-button variant="secondary" class="button--small">הסרת חסימה</x-button>
                            </form>
                        @else
                            <p class="blocked-date-history-note">תאריך עבר — לקריאה בלבד.</p>
                        @endif
                    </li>
                @empty
                    <li class="empty-state">אין תאריכים חסומים.</li>
                @endforelse
            </ul>

            @if ($blockedDates->hasPages())
                <nav class="pagination" aria-label="דפדוף בתאריכים החסומים">
                    @if ($blockedDates->onFirstPage())
                        <span class="pagination-disabled">הקודם</span>
                    @else
                        <a href="{{ $blockedDates->previousPageUrl() }}#blocks">הקודם</a>
                    @endif
                    <span>עמוד {{ $blockedDates->currentPage() }} מתוך {{ $blockedDates->lastPage() }}</span>
                    @if ($blockedDates->hasMorePages())
                        <a href="{{ $blockedDates->nextPageUrl() }}#blocks">הבא</a>
                    @else
                        <span class="pagination-disabled">הבא</span>
                    @endif
                </nav>
            @endif
        </div>
    </div>
</x-panel>
