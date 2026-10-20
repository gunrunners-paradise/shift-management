<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'התחברות') | מערכת נוכחות</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body class="guest-page">
    <a class="skip-link" href="#main-content">דילוג לתוכן</a>
    <header class="topbar">
        <div class="topbar-inner guest-topbar-inner">
            <strong class="brand">מערכת נוכחות</strong>
        </div>
    </header>

    <main id="main-content" class="guest-main">
        @yield('content')
    </main>
</body>
</html>
