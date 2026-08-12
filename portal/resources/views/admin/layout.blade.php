<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Maintenance') | ITFA Super Admin</title>
    @include('partials.head')
</head>
<body class="app-bg min-h-screen font-sans text-slate-800 dark:text-slate-200">
@php
    $admin = request()->attributes->get('admin', []);
    $adminNav = [
        ['admin.dashboard',  'Dashboard',        'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['admin.students',   'Student Accounts', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z'],
        ['admin.monitoring', 'Access Monitoring','M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'],
        ['admin.backups',    'Backups',          'M4 7v10c0 2 1.5 3 4 3h8c2.5 0 4-1 4-3V7M4 7c0-2 1.5-3 4-3h8c2.5 0 4 1 4 3M4 7c0 2 1.5 3 4 3h8c2.5 0 4-1 4-3M12 12v6m0 0l-2.5-2.5M12 18l2.5-2.5'],
        ['admin.maintenance','Maintenance',      'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    ];
    $adminName = trim($admin['name'] ?? 'Super Admin');
    $adminInit = strtoupper(substr($adminName, 0, 1) . (str_contains($adminName, ' ') ? substr(strrchr($adminName, ' '), 1, 1) : ''));
@endphp
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-gold-500 focus:text-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold">Skip to content</a>

<div x-data="{ nav: false }" class="min-h-screen lg:grid lg:grid-cols-[276px_1fr]">

    {{-- ── Sidebar (dark command-center) ──────────────────────────────── --}}
    <aside x-cloak :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed lg:static inset-y-0 left-0 z-40 w-[276px] text-slate-100 flex flex-col p-6 transition-transform duration-300 lg:m-3 lg:rounded-3xl overflow-hidden"
           style="background:linear-gradient(165deg,#0b1220 0%,#0f1a2b 55%,#0a1017 100%)" aria-label="Admin navigation">
        <div class="absolute -top-16 -right-12 w-52 h-52 rounded-full bg-gold-400/10 blur-3xl pointer-events-none"></div>

        <div class="relative flex items-center gap-3 mb-8">
            <div class="w-10 h-10 rounded-2xl bg-white/10 ring-1 ring-gold-400/30 flex items-center justify-center">
                <img src="/itfalogo.png" alt="" class="w-7 h-7 object-contain" onerror="this.style.display='none'">
            </div>
            <div>
                <p class="text-[10px] uppercase tracking-[0.22em] text-gold-300 font-bold">ITFA Portal</p>
                <p class="text-base font-extrabold leading-tight font-display">Super Admin</p>
            </div>
            <button @click="nav = false" class="lg:hidden ml-auto -mr-1 w-9 h-9 rounded-lg text-slate-300 hover:bg-white/10 flex items-center justify-center" aria-label="Close menu">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="relative mb-6 flex items-center gap-2 rounded-xl bg-gold-400/10 ring-1 ring-gold-400/25 px-3 py-2">
            <span class="text-[9px] font-black uppercase tracking-[0.2em] text-gold-300">● Maintenance Console</span>
        </div>

        <nav class="relative space-y-1 flex-1">
            @foreach ($adminNav as [$route, $label, $icon])
                @php $active = request()->routeIs($route); @endphp
                <a href="{{ route($route) }}"
                   class="group flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium transition-all {{ $active ? 'bg-gold-400/15 text-white font-semibold ring-1 ring-gold-400/25 shadow-lg' : 'text-slate-300/80 hover:bg-white/10 hover:text-white' }}">
                    <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                    <span>{{ $label }}</span>
                </a>
            @endforeach

            <form method="POST" action="{{ route('admin.logout') }}" class="pt-2">
                @csrf
                <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium text-slate-300/80 hover:bg-rose-500/15 hover:text-rose-200 transition-colors">
                    <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    Sign out
                </button>
            </form>
        </nav>

        <div class="relative rounded-2xl border border-white/10 bg-white/5 p-3.5 mt-6 flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-gold-400 to-gold-600 flex items-center justify-center text-sm font-extrabold text-white">{{ $adminInit ?: 'SA' }}</div>
            <div class="min-w-0">
                <p class="font-semibold text-sm truncate">{{ $adminName }}</p>
                <p class="text-xs text-gold-300 truncate">{{ $admin['role'] ?? 'Super Admin' }}</p>
            </div>
        </div>
    </aside>

    {{-- Mobile drawer backdrop --}}
    <div x-show="nav" @click="nav = false" x-cloak class="fixed inset-0 z-30 bg-black/50 backdrop-blur-sm lg:hidden"></div>

    <main id="main" class="p-4 sm:p-6 lg:p-8 lg:pt-6">
        <div class="flex items-center justify-between gap-3 mb-6">
            <button @click="nav = true" class="lg:hidden w-10 h-10 rounded-xl glass border border-white/40 dark:border-slate-700 flex items-center justify-center" aria-label="Open menu">
                <svg class="w-5 h-5 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="min-w-0">
                <h1 class="text-lg sm:text-2xl font-extrabold font-display truncate">@yield('heading', 'Maintenance')</h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 truncate">@yield('subheading', '')</p>
            </div>
            <div class="flex items-center gap-2 ml-auto">
                <x-theme-toggle />
            </div>
        </div>

        <div class="animate-enter">
            @yield('content')
        </div>
    </main>
</div>
<x-toasts />
</body>
</html>
