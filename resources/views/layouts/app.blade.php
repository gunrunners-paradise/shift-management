<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'מערכת נוכחות') | מערכת נוכחות</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('styles')
    <script src="{{ asset('js/app.js') }}" defer></script>
    @stack('scripts')
</head>
<body>
    <a class="skip-link" href="#main-content">דילוג לתוכן</a>
    <x-header />

    <main id="main-content" class="app-main">
        <x-messages />
        @yield('content')
    </main>
</body>
</html>
