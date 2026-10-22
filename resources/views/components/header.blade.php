<header class="topbar">
    <div class="topbar-inner">
        {{-- In RTL the first item is the rightmost: logo, hamburger, then title. --}}
        <div class="header-brand-group">
            <img class="brand-logo" src="{{ asset('images/enter_digital_studio.svg') }}" alt="לוגו Enter Digital Studio" width="49" height="49">

            <x-dropdown id="site-menu" label="תפריט האתר">
                <x-slot:trigger><span aria-hidden="true">☰</span></x-slot:trigger>
                <nav aria-label="דפי המערכת">
                    <p class="menu-caption">דפי המערכת</p>
                    @if (auth()->user()->isManager())
                        <a class="dropdown-link {{ request()->routeIs('admin.*') ? 'is-current' : '' }}" href="{{ route('admin.users.index') }}" @if (request()->routeIs('admin.*')) aria-current="page" @endif>ניהול המערכת</a>
                    @else
                        <a class="dropdown-link {{ request()->routeIs('worker.home') ? 'is-current' : '' }}" href="{{ route('worker.home') }}" @if (request()->routeIs('worker.home')) aria-current="page" @endif>לוח משמרות</a>
                        <a class="dropdown-link {{ request()->routeIs('worker.desks.*') ? 'is-current' : '' }}" href="{{ route('worker.desks.index') }}" @if (request()->routeIs('worker.desks.*')) aria-current="page" @endif>עמדות</a>
                    @endif
                </nav>
            </x-dropdown>

            <strong class="brand">מערכת נוכחות</strong>
        </div>

        <x-dropdown id="account-menu" :label="'תפריט חשבון: '.auth()->user()->fullName()" align="end" trigger-class="account-trigger">
            <x-slot:trigger>
                <img class="avatar" src="{{ auth()->user()->avatarUrl() }}" alt="" width="41" height="41">
                <span class="account-trigger-name">{{ auth()->user()->fullName() }}</span>
                <span class="account-chevron" aria-hidden="true">⌄</span>
            </x-slot:trigger>
            <p class="menu-caption account-caption">{{ auth()->user()->fullName() }}<br>{{ auth()->user()->roleLabel() }}</p>
            <a class="dropdown-link {{ request()->routeIs('profile.*') ? 'is-current' : '' }}" href="{{ route('profile.edit') }}" @if (request()->routeIs('profile.*')) aria-current="page" @endif>הפרופיל שלי</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="dropdown-link signout" type="submit">התנתקות</button>
            </form>
        </x-dropdown>
    </div>
</header>
