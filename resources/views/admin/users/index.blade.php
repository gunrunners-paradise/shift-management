@extends('layouts.app')

@section('title', 'ניהול המערכת')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/blocked-dates.css') }}">
    <link rel="stylesheet" href="{{ asset('css/events.css') }}">
    <link rel="stylesheet" href="{{ asset('css/receipts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/desks.css') }}">
@endpush

@section('content')
    <section class="page-title" aria-labelledby="page-heading">
        <h1 id="page-heading">ניהול מערכת הנוכחות</h1>
        <p>ניהול חשבונות וגישה למערכת, חסימת תאריכים, אירועים וחגים, עמדות עבודה ומעקב קבלות חודשי.</p>
    </section>

    <div class="quick-actions">
        <a class="button button--primary" href="#users">＋ משתמש חדש</a>
        <a class="button button--secondary" href="#account-list">רשימת משתמשים</a>
        <a class="button button--danger" href="#blocks">חסימת יום</a>
        <a class="button button--secondary" href="#events">אירועים וחגים</a>
        <a class="button button--secondary" href="#desks">ניהול עמדות</a>
        <a class="button button--secondary" href="#receipts">מעקב קבלות</a>
    </div>

    <div class="account-layout">
        <x-panel id="users" title="הוספת משתמשים" heading-id="create-user-heading" hint="שדות המסומנים בכוכבית הם חובה. החשבון החדש יהיה פעיל מיד לאחר השמירה.">
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf

                <div class="field-row">
                    <x-field name="first_name" label="שם פרטי" :value="old('first_name')" maxlength="60" autocomplete="off" required />
                    <x-field name="last_name" label="שם משפחה" :value="old('last_name')" maxlength="60" autocomplete="off" required />
                </div>

                <x-field name="username" label="שם משתמש" :value="old('username')" minlength="3" maxlength="60" autocomplete="off" dir="ltr" hint="3–60 תווים: אותיות באנגלית, ספרות, נקודה, מקף או קו תחתון. מתחיל באות או בספרה." required />

                <div class="field-row">
                    <x-field name="password" label="סיסמה" type="password" minlength="12" maxlength="72" autocomplete="new-password" hint="לפחות 12 תווים, עד 72 בתים. אות בעברית תופסת יותר מבית אחד. יש למסור את הסיסמה באופן פרטי." required />
                    <x-field name="password_confirmation" label="אימות סיסמה" type="password" minlength="12" maxlength="72" autocomplete="new-password" required />
                </div>

                <div class="field-row">
                    <x-field name="gender" label="מגדר" required>
                        <select id="gender" name="gender" @class(['form-control', 'is-invalid' => $errors->has('gender')]) required @if ($errors->has('gender')) aria-invalid="true" aria-describedby="gender-error" @endif>
                            <option value="other" @selected(old('gender', 'other') === 'other')>אחר / לא צוין</option>
                            <option value="female" @selected(old('gender') === 'female')>אישה</option>
                            <option value="male" @selected(old('gender') === 'male')>גבר</option>
                        </select>
                    </x-field>

                    <x-field name="role" label="תפקיד" required>
                        <select id="role" name="role" @class(['form-control', 'is-invalid' => $errors->has('role')]) required @if ($errors->has('role')) aria-invalid="true" aria-describedby="role-error" @endif>
                            <option value="worker" @selected(old('role', 'worker') === 'worker')>עובד/ת</option>
                            <option value="manager" @selected(old('role') === 'manager')>מנהל/ת</option>
                        </select>
                    </x-field>
                </div>

                <x-field name="birth_date" label="תאריך לידה" type="date" :value="old('birth_date')" :max="now()->toDateString()" />
                <x-button>שמירת משתמש</x-button>
            </form>
        </x-panel>

        <x-panel id="account-list" title="משתמשי המערכת" heading-id="account-list-heading" hint="השבתת חשבון חוסמת כניסה ושומרת את פרטיו. חשבון לא פעיל ניתן להפעיל מחדש או למחוק לצמיתות יחד עם ההיסטוריה שלו.">
            <p class="account-summary">{{ $users->total() }} משתמשים · {{ $activeManagerCount }} מנהלים פעילים</p>

            <ul class="account-list">
                @forelse ($users as $account)
                    <li class="account-card">
                        <div class="account-card-heading">
                            <div class="account-identity">
                                <strong>{{ $account->fullName() }}</strong>
                                <span class="account-username" dir="ltr">{{ $account->username }}</span>
                            </div>
                            <span @class(['status-chip', 'status-chip--inactive' => ! $account->is_active])>{{ $account->is_active ? 'פעיל' : 'לא פעיל' }}</span>
                        </div>
                        <p class="account-role">{{ $account->roleLabel() }}</p>

                        @if ($account->is_active)
                            @if ($account->isManager() && $activeManagerCount === 1)
                                <p class="account-protection-note">זהו המנהל הפעיל האחרון. לא ניתן להשבית את החשבון.</p>
                            @else
                                {{-- The required checkbox is also validated on the server, without relying on JS. --}}
                                <form class="deactivation-form" method="POST" action="{{ route('admin.users.deactivate', $account) }}">
                                    @csrf
                                    @method('PATCH')
                                    @if (! $account->isManager())
                                        <p id="deactivate-shift-note-{{ $account->id }}" class="account-protection-note">משמרות והזמנות עמדה עתידיות או שכבר התחילו וטרם הסתיימו יבוטלו מיד. רשומות שהסתיימו והיסטוריית הביטולים יישמרו.</p>
                                    @endif
                                    <label class="confirmation-label" for="confirm-deactivation-{{ $account->id }}">
                                        <input id="confirm-deactivation-{{ $account->id }}" type="checkbox" name="confirm_deactivation" value="1" required @if (! $account->isManager()) aria-describedby="deactivate-shift-note-{{ $account->id }}" @endif>
                                        <span>אני מאשר/ת את השבתת החשבון של {{ $account->fullName() }}</span>
                                    </label>
                                    <x-button variant="danger" class="button--small">השבתת חשבון</x-button>
                                </form>
                            @endif
                        @else
                            <form class="account-reactivation-form" method="POST" action="{{ route('admin.users.reactivate', $account) }}">
                                @csrf
                                @method('PATCH')
                                <p id="reactivate-note-{{ $account->id }}" class="account-protection-note">הכניסה תתאפשר עם שם המשתמש והסיסמה הקיימים. משמרות והזמנות עמדה שבוטלו לא ישוחזרו.</p>
                                <x-button variant="secondary" class="button--small" aria-describedby="reactivate-note-{{ $account->id }}">הפעלת חשבון מחדש <span class="screen-reader-only">של {{ $account->fullName() }}</span></x-button>
                            </form>
                            @php
                                $isFailedDeletionForm = is_scalar(old('deletion_form_id')) && (string) old('deletion_form_id') === (string) $account->id;
                            @endphp
                            <details class="account-deletion" @if ($isFailedDeletionForm) open @endif>
                                <summary>מחיקה לצמיתות <span class="screen-reader-only">של {{ $account->fullName() }}</span></summary>
                                <form class="account-deletion-form" method="POST" action="{{ route('admin.users.destroy', $account) }}">
                                    @csrf
                                    @method('DELETE')
                                    <p id="delete-account-warning-{{ $account->id }}" class="account-deletion-warning">הפעולה תמחק לצמיתות את החשבון, תמונת הפרופיל וכל המשמרות, הזמנות העמדות והקבלות שלו, כולל היסטוריה. לא ניתן לבטל פעולה זו.</p>
                                    <label class="confirmation-label" for="confirm-deletion-{{ $account->id }}">
                                        <input id="confirm-deletion-{{ $account->id }}" type="checkbox" name="confirm_deletion" value="1" aria-describedby="delete-account-warning-{{ $account->id }}" required>
                                        <span>אני מאשר/ת את מחיקת החשבון וכל ההיסטוריה שלו לצמיתות.</span>
                                    </label>
                                    <x-button variant="danger" class="button--small">מחיקת החשבון לצמיתות</x-button>
                                </form>
                            </details>
                        @endif
                    </li>
                @empty
                    <li class="empty-state">אין משתמשים להצגה.</li>
                @endforelse
            </ul>

            @if ($users->hasPages())
                <nav class="pagination" aria-label="דפדוף ברשימת המשתמשים">
                    @if ($users->onFirstPage())
                        <span class="pagination-disabled">הקודם</span>
                    @else
                        <a href="{{ $users->previousPageUrl() }}">הקודם</a>
                    @endif
                    <span>עמוד {{ $users->currentPage() }} מתוך {{ $users->lastPage() }}</span>
                    @if ($users->hasMorePages())
                        <a href="{{ $users->nextPageUrl() }}">הבא</a>
                    @else
                        <span class="pagination-disabled">הבא</span>
                    @endif
                </nav>
            @endif
        </x-panel>
    </div>

    @include('admin.blocks.panel')
    @include('admin.events.panel')
    @include('admin.desks.panel')
    @include('admin.receipts.panel')
@endsection
