<x-panel id="desks" class="managed-desks-panel" title="ניהול עמדות עבודה" heading-id="managed-desks-heading" hint="הוספה, שינוי שם ומחיקת עמדות. מחיקת עמדה מוחקת גם את כל ההזמנות וההיסטוריה שלה. מנהלים אינם יכולים להזמין עמדות לעצמם.">
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
            <p class="hint">{{ $managedDesks->total() }} עמדות ברשימה. עמדות לא פעילות אינן ניתנות להזמנה.</p>
            <ul class="managed-desks-list">
                @forelse ($managedDesks as $managedDesk)
                    @php
                        $isFailedDeskForm = is_scalar(old('desk_form_id')) && (string) old('desk_form_id') === (string) $managedDesk->id;
                        // Several inline forms share this page; reopen only the action that failed.
                        $isFailedDeskRename = $isFailedDeskForm && $errors->has('name');
                        $isFailedDeskDeletion = $isFailedDeskForm && $errors->has('confirm_deletion');
                    @endphp
                    <li class="managed-desk-card">
                        <div class="managed-desk-heading">
                            <h3>{{ $managedDesk->name }}</h3>
                            <span @class(['status-chip', 'status-chip--inactive' => ! $managedDesk->is_active])>{{ $managedDesk->is_active ? 'פעילה' : 'לא פעילה' }}</span>
                        </div>
                        <p class="managed-desk-summary">מזהה עמדה: {{ $managedDesk->id }} · {{ $managedDesk->upcoming_reservations_count }} הזמנות עתידיות · {{ $managedDesk->in_progress_reservations_count }} הזמנות שמתבצעות כעת</p>

                        @if ($managedDesk->is_active)
                            <details class="managed-desk-action" @if ($isFailedDeskRename) open @endif>
                                <summary>שינוי שם העמדה <span class="screen-reader-only">{{ $managedDesk->name }}</span></summary>
                                <form method="POST" action="{{ route('admin.desks.update', $managedDesk) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="desk_form_id" value="{{ $managedDesk->id }}">
                                    <x-field name="name" :id="'desk-name-'.$managedDesk->id" label="שם העמדה" :value="$isFailedDeskRename ? old('name', $managedDesk->name) : $managedDesk->name" maxlength="60" :show-errors="$isFailedDeskRename" required />
                                    <x-button class="button--small">שמירת שם העמדה</x-button>
                                </form>
                            </details>
                        @else
                            <p class="managed-desk-summary">העמדה אינה פעילה ואינה זמינה להזמנה.</p>
                        @endif

                        <details class="managed-desk-action managed-desk-action--danger" @if ($isFailedDeskDeletion) open @endif>
                            <summary>מחיקת העמדה <span class="screen-reader-only">{{ $managedDesk->name }}</span></summary>
                            <form class="managed-desk-deletion" method="POST" action="{{ route('admin.desks.destroy', $managedDesk) }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="desk_form_id" value="{{ $managedDesk->id }}">
                                <p id="desk-deletion-warning-{{ $managedDesk->id }}" class="managed-desk-warning">העמדה וכל הזמנותיה יימחקו, כולל הזמנות עתידיות, הזמנות שמתבצעות כעת, הזמנות עבר והזמנות שבוטלו. לא ניתן לבטל את המחיקה.</p>
                                <label class="confirmation-label" for="confirm-desk-deletion-{{ $managedDesk->id }}">
                                    <input id="confirm-desk-deletion-{{ $managedDesk->id }}" type="checkbox" name="confirm_deletion" value="1" aria-describedby="desk-deletion-warning-{{ $managedDesk->id }}{{ $isFailedDeskDeletion ? ' desk-deletion-error-'.$managedDesk->id : '' }}" required @if ($isFailedDeskDeletion) aria-invalid="true" @endif>
                                    <span>אני מאשר/ת את מחיקת {{ $managedDesk->name }} וכל ההזמנות וההיסטוריה שלה.</span>
                                </label>
                                @if ($isFailedDeskDeletion)
                                    <span id="desk-deletion-error-{{ $managedDesk->id }}" class="field-error">{{ $errors->first('confirm_deletion') }}</span>
                                @endif
                                <x-button variant="danger" class="button--small">מחיקת העמדה</x-button>
                            </form>
                        </details>
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
