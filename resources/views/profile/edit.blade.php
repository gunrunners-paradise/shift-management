@extends('layouts.app')

@section('title', 'הפרופיל שלי')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/profile.css') }}">
@endpush

@section('content')
    <div class="profile-page">
        <section class="page-title" aria-labelledby="profile-heading">
            <h1 id="profile-heading">הפרופיל שלי</h1>
            <p>עדכון הפרטים האישיים, תמונת הפרופיל והסיסמה שלך.</p>
        </section>

        <div class="profile-layout">
            <aside class="panel profile-identity" aria-labelledby="profile-identity-heading">
                <img class="profile-identity-photo" src="{{ $user->avatarUrl() }}" alt="" width="126" height="126">
                <h2 id="profile-identity-heading">{{ $user->fullName() }}</h2>
                <span class="profile-role">{{ $user->roleLabel() }}</span>

                <dl class="profile-summary">
                    <div>
                        <dt>שם משתמש</dt>
                        <dd><bdi dir="ltr">{{ $user->username }}</bdi></dd>
                    </div>
                    <div>
                        <dt>תפקיד</dt>
                        <dd>{{ $user->roleLabel() }}</dd>
                    </div>
                    <div>
                        <dt>תאריך לידה</dt>
                        <dd>{{ $user->birth_date?->format('d/m/Y') ?? 'לא הוגדר' }}</dd>
                    </div>
                </dl>
            </aside>

            <div class="profile-content">
                <x-panel title="פרטים אישיים" heading-id="profile-details-heading">
                    @if ($user->isManager())
                        <p class="hint">עדכון הפרטים האישיים ותמונת הפרופיל של החשבון שלך.</p>
                    @else
                        <p class="hint">השם ותמונת הפרופיל שלך יופיעו גם ברשימת המשמרות המתוכננות של הצוות.</p>
                    @endif

                    <form id="profile-details-form" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PATCH')

                        <div class="field-row">
                            <x-field name="first_name" label="שם פרטי" :value="old('first_name', $user->first_name)" maxlength="60" autocomplete="given-name" required />
                            <x-field name="last_name" label="שם משפחה" :value="old('last_name', $user->last_name)" maxlength="60" autocomplete="family-name" required />
                        </div>

                        <div class="field-row">
                            <div class="field">
                                <label for="profile-username">שם משתמש</label>
                                {{-- Read-only identity is displayed but never submitted as an editable field. --}}
                                <input id="profile-username" class="form-control profile-readonly" type="text" value="{{ $user->username }}" dir="ltr" readonly aria-describedby="profile-username-hint">
                                <span id="profile-username-hint" class="field-hint">שם המשתמש קבוע ואינו ניתן לשינוי כאן.</span>
                            </div>

                            <x-field name="gender" label="מגדר" required>
                                <select id="gender" name="gender" @class(['form-control', 'is-invalid' => $errors->has('gender')]) required @if ($errors->has('gender')) aria-invalid="true" aria-describedby="gender-error" @endif>
                                    <option value="other" @selected(old('gender', $user->gender) === 'other')>אחר / לא צוין</option>
                                    <option value="female" @selected(old('gender', $user->gender) === 'female')>אישה</option>
                                    <option value="male" @selected(old('gender', $user->gender) === 'male')>גבר</option>
                                </select>
                            </x-field>
                        </div>

                        <div class="field-row">
                            <x-field name="birth_date" label="תאריך לידה" type="date" :value="old('birth_date', $user->birth_date?->toDateString())" :max="$today->toDateString()" autocomplete="bday" />
                            <x-field name="photo" label="תמונת פרופיל" type="file" accept="image/jpeg,image/png,image/webp" hint="JPEG, PNG או WebP, עד 2 MB ועד 2048×2048 פיקסלים." />
                        </div>

                        <div class="profile-form-actions">
                            <x-button>שמירת פרטים</x-button>
                            <a class="button button--secondary" href="{{ route('profile.edit') }}">ביטול שינויים</a>
                        </div>
                    </form>
                </x-panel>

                <x-panel title="שינוי סיסמה" heading-id="profile-password-heading">
                    <p class="hint">יש להזין את הסיסמה הנוכחית כדי לאשר את השינוי. מומלץ לבחור סיסמה ייחודית.</p>

                    <form id="profile-password-form" method="POST" action="{{ route('profile.password.update') }}">
                        @csrf
                        @method('PUT')
                        <x-field name="current_password" label="סיסמה נוכחית" type="password" maxlength="72" autocomplete="current-password" required />

                        <div class="field-row">
                            <x-field name="password" label="סיסמה חדשה" type="password" minlength="12" maxlength="72" autocomplete="new-password" hint="לפחות 12 תווים, עד 72 בתים. אות בעברית תופסת יותר מבית אחד." required />
                            <x-field name="password_confirmation" label="אימות סיסמה חדשה" type="password" minlength="12" maxlength="72" autocomplete="new-password" required />
                        </div>

                        <x-button>עדכון סיסמה</x-button>
                    </form>
                </x-panel>
            </div>
        </div>
    </div>
@endsection
