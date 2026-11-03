@php
    $signupManagementErrors = $errors->getBag('signupManagement');
    $pendingWorkerCount = (int) $signupCounts->get('worker', 0);
    $pendingManagerCount = (int) $signupCounts->get('manager', 0);
    $pendingSignupCount = $pendingWorkerCount + $pendingManagerCount;
@endphp

<x-panel id="signup-requests" class="signup-management-panel" title="בקשות הצטרפות" heading-id="signup-requests-heading" hint="פתיחת ההרשמה מאפשרת לשלוח בקשות חדשות. חשבון נוצר רק לאחר אישור מנהל או מנהלת.">
    @if ($signupManagementErrors->any())
        <div class="message message--error" role="alert" tabindex="-1" data-error-summary>
            <p><strong>לא ניתן להשלים את הפעולה:</strong></p>
            <ul>
                @foreach ($signupManagementErrors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="signup-settings" aria-label="פתיחה וסגירה של ההרשמה">
        @foreach (['worker' => 'הרשמת עובדים', 'manager' => 'הרשמת מנהלים'] as $role => $label)
            @php
                $isSignupEnabled = (bool) ($signupSettings->get($role)?->is_enabled ?? false);
            @endphp
            <form class="signup-setting" method="POST" action="{{ route('admin.signups.settings.update', ['signupRole' => $role]) }}">
                @csrf
                @method('PATCH')
                <div class="signup-setting-description">
                    <h3 id="signup-setting-heading-{{ $role }}">{{ $label }}</h3>
                    <span @class(['status-chip', 'status-chip--inactive' => ! $isSignupEnabled])>{{ $isSignupEnabled ? 'ההרשמה פתוחה' : 'ההרשמה סגורה' }}</span>
                </div>
                <input type="hidden" name="is_enabled" value="{{ $isSignupEnabled ? '0' : '1' }}">
                <x-button variant="secondary" class="button--small" aria-describedby="signup-setting-heading-{{ $role }}">{{ $isSignupEnabled ? 'סגירת ההרשמה' : 'פתיחת ההרשמה' }} <span class="screen-reader-only">— {{ $label }}</span></x-button>
            </form>
        @endforeach
    </div>

    <p class="hint signup-setting-note">סגירת ההרשמה חוסמת בקשות חדשות גם מטפסים שכבר נפתחו. בקשות שכבר נשלחו נשארות לבדיקה.</p>
    <p class="account-summary" id="signup-pending-summary">{{ $pendingSignupCount }} בקשות ממתינות לאישור · {{ $pendingWorkerCount }} לעובדים · {{ $pendingManagerCount }} למנהלים</p>

    {{-- Both bulk actions review a server-selected snapshot across every list page. --}}
    <div class="signup-bulk-actions" aria-label="פעולות על כל בקשות ההצטרפות">
        <form method="POST" action="{{ route('admin.signups.review') }}">
            @csrf
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="scope" value="all">
            <x-button aria-describedby="signup-bulk-note" :disabled="$pendingSignupCount === 0">אישור כל הבקשות</x-button>
        </form>
        <form method="POST" action="{{ route('admin.signups.review') }}">
            @csrf
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="scope" value="all">
            <x-button variant="danger" aria-describedby="signup-bulk-note" :disabled="$pendingSignupCount === 0">מחיקת כל הבקשות</x-button>
        </form>
    </div>
    <p class="hint" id="signup-bulk-note">הפעולה חלה על כל העמודים. לפני ביצועה יוצג מסך אישור עם מספר בקשות העובדים והמנהלים.</p>

    <ul class="signup-request-list">
        @forelse ($signupRequests as $signupRequest)
            <li class="signup-request-card">
                <div class="signup-request-heading">
                    <div class="account-identity">
                        <strong>{{ $signupRequest->fullName() }}</strong>
                        <span class="account-username" dir="ltr">{{ $signupRequest->username }}</span>
                    </div>
                    <span @class(['signup-role-chip', 'signup-role-chip--manager' => $signupRequest->role === 'manager'])>{{ $signupRequest->roleLabel() }}</span>
                </div>
                <p class="signup-request-date">נשלחה בתאריך <time datetime="{{ $signupRequest->created_at->toIso8601String() }}">{{ $signupRequest->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</time></p>

                @if ($signupRequest->review_error)
                    <p class="signup-review-error">{{ $signupRequest->review_error }}</p>
                @endif

                <div class="signup-request-actions">
                    <form method="POST" action="{{ route('admin.signups.review') }}">
                        @csrf
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="scope" value="single">
                        <input type="hidden" name="request_id" value="{{ $signupRequest->id }}">
                        <x-button variant="secondary" class="button--small">אישור ויצירת חשבון <span class="screen-reader-only">של {{ $signupRequest->fullName() }}</span></x-button>
                    </form>
                    <form method="POST" action="{{ route('admin.signups.review') }}">
                        @csrf
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="scope" value="single">
                        <input type="hidden" name="request_id" value="{{ $signupRequest->id }}">
                        <x-button variant="danger" class="button--small">מחיקת בקשה <span class="screen-reader-only">של {{ $signupRequest->fullName() }}</span></x-button>
                    </form>
                </div>

                <details class="signup-request-details">
                    <summary>פרטים נוספים <span class="screen-reader-only">על {{ $signupRequest->fullName() }}</span></summary>
                    <dl>
                        <div><dt>מגדר</dt><dd>{{ match ($signupRequest->gender) { 'female' => 'אישה', 'male' => 'גבר', default => 'אחר / לא צוין' } }}</dd></div>
                        <div><dt>תאריך לידה</dt><dd>{{ $signupRequest->birth_date?->format('d/m/Y') ?? 'לא הוגדר' }}</dd></div>
                    </dl>
                </details>
            </li>
        @empty
            <li class="empty-state">אין בקשות הממתינות לאישור.</li>
        @endforelse
    </ul>

    @if ($signupRequests->hasPages())
        <nav class="pagination" aria-label="דפדוף בבקשות ההצטרפות">
            @if ($signupRequests->onFirstPage())
                <span class="pagination-disabled">הקודם</span>
            @else
                <a href="{{ $signupRequests->previousPageUrl() }}#signup-requests">הקודם</a>
            @endif
            <span>עמוד {{ $signupRequests->currentPage() }} מתוך {{ $signupRequests->lastPage() }}</span>
            @if ($signupRequests->hasMorePages())
                <a href="{{ $signupRequests->nextPageUrl() }}#signup-requests">הבא</a>
            @else
                <span class="pagination-disabled">הבא</span>
            @endif
        </nav>
    @endif
</x-panel>
