{{-- A guest layout can render safe failures even when account lookup is unavailable. --}}
@extends('layouts.guest')

@section('title')@yield('error-title')@endsection

@section('content')
    <section class="panel error-card" aria-labelledby="error-heading">
        <p class="error-code">@yield('error-code')</p>
        <h1 id="error-heading">@yield('error-title')</h1>
        <p class="error-description">@yield('error-description')</p>
        <a class="button button--primary" href="{{ route('home') }}">חזרה לדף הבית</a>
    </section>
@endsection
