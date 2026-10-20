@extends('layouts.guest')

@section('title', 'התחברות')

@section('content')
    <section class="login-card" aria-labelledby="login-heading">
        <img class="login-logo" src="{{ asset('images/enter_digital_studio.svg') }}" alt="Enter Digital Studio" width="200" height="176">
        <h1 id="login-heading">התחברות למערכת</h1>
        <p class="login-subtitle">הזינו את פרטי המשתמש שלכם כדי להמשיך</p>

        <x-messages />

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <x-field name="username" label="שם משתמש" :value="old('username')" autocomplete="username" maxlength="60" dir="ltr" required autofocus />
            <x-field name="password" label="סיסמה" type="password" autocomplete="current-password" required />
            <x-button class="login-submit">התחברות</x-button>
        </form>
    </section>
@endsection
