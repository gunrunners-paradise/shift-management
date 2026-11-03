@extends('layouts.guest')

@section('title', 'בקשת הצטרפות')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/signup.css') }}">
@endpush

@section('content')
    <section class="login-card signup-card" aria-labelledby="signup-heading">
        <h1 id="signup-heading">בקשת הצטרפות למערכת</h1>
        <p class="login-subtitle">בקשה לחשבון {{ $signupRole === 'manager' ? 'מנהל/ת' : 'עובד/ת' }}. ניתן יהיה להתחבר למערכת לאחר אישור הבקשה.</p>

        <x-messages />

        {{-- The route fixes the requested role; the applicant never submits a role field. --}}
        <form method="POST" action="{{ route('signup.store', ['signupRole' => $signupRole]) }}">
            @csrf
            <div class="field-row">
                <x-field name="first_name" label="שם פרטי" :value="old('first_name')" maxlength="60" autocomplete="given-name" required autofocus />
                <x-field name="last_name" label="שם משפחה" :value="old('last_name')" maxlength="60" autocomplete="family-name" required />
            </div>

            <x-field name="username" label="שם משתמש" :value="old('username')" minlength="3" maxlength="60" autocomplete="username" dir="ltr" hint="3–60 תווים: אותיות באנגלית, ספרות, נקודה, מקף או קו תחתון. מתחיל באות או בספרה." required />

            <x-field name="gender" label="מגדר" required>
                <select id="gender" name="gender" @class(['form-control', 'is-invalid' => $errors->has('gender')]) required @if ($errors->has('gender')) aria-invalid="true" aria-describedby="gender-error" @endif>
                    <option value="other" @selected(old('gender', 'other') === 'other')>אחר / לא צוין</option>
                    <option value="female" @selected(old('gender') === 'female')>אישה</option>
                    <option value="male" @selected(old('gender') === 'male')>גבר</option>
                </select>
            </x-field>

            <x-field name="birth_date" label="תאריך לידה (לא חובה)" type="date" :value="old('birth_date')" :max="now(config('app.timezone'))->toDateString()" autocomplete="bday" />
            <x-field name="password" label="סיסמה" type="password" minlength="12" maxlength="72" autocomplete="new-password" hint="הסיסמה חייבת להכיל לפחות 12 תווים. עד 72 בתים; אות בעברית תופסת יותר מבית אחד." required />
            <x-field name="password_confirmation" label="אימות סיסמה" type="password" minlength="12" maxlength="72" autocomplete="new-password" required />
            <x-button class="login-submit">שליחת בקשה</x-button>
        </form>
    </section>
@endsection
