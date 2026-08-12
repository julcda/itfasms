<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Classroom') | ITFA Teacher</title>
    @include('partials.head')
</head>
<body class="app-bg min-h-screen font-sans text-slate-800 dark:text-slate-200">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-emerald-600 focus:text-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold">Skip to content</a>
<div x-data="{ nav: false }" class="min-h-screen lg:grid lg:grid-cols-[264px_1fr]">

    {{-- Sidebar --}}
    <aside x-cloak :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed lg:static inset-y-0 left-0 z-40 w-[264px] text-slate-100 flex flex-col p-6 transition-transform duration-300 lg:m-3 lg:rounded-3xl overflow-hidden"
           style="background:linear-gradient(160deg,#0f5a30 0%,#0a3a1e 55%,#04240f 100%)" aria-label="Classroom navigation">
        {{-- subtle top glow --}}
        <div class="absolute -top-16 -right-10 w-48 h-48 rounded-full bg-emerald-400/20 blur-3xl pointer-events-none"></div>
        <div class="relative flex items-center gap-3 mb-9">
            <div class="w-10 h-10 rounded-2xl bg-white/10 ring-1 ring-white/15 flex items-center justify-center">
                <img src="/itfalogo.png" alt="" class="w-7 h-7 object-contain" onerror="this.style.display='none'">
            </div>
            <div>
                <p class="text-[10px] uppercase tracking-[0.2em] text-gold-300 font-bold">ITFA System</p>
                <p class="text-base font-extrabold leading-tight font-display">Classroom</p>
            </div>
            <button @click="nav = false" class="lg:hidden ml-auto -mr-1 w-9 h-9 rounded-lg text-emerald-50/80 hover:bg-white/10 flex items-center justify-center" aria-label="Close menu">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <nav class="relative space-y-1 flex-1">
            <a href="{{ route('teacher.dashboard') }}" @class([
                'group flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium transition-all',
                'bg-white/15 text-white font-semibold ring-1 ring-white/10 shadow-lg' => request()->routeIs('teacher.dashboard'),
                'text-emerald-50/70 hover:bg-white/10 hover:text-white' => !request()->routeIs('teacher.dashboard'),
            ])>
                <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                My Classes
            </a>
        </nav>

        <a href="{{ rtrim(config('portal.app_base_url'), '/') }}/teacher/index.php"
           class="relative mt-6 flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-semibold bg-white/10 hover:bg-white/20 ring-1 ring-white/10 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Teacher Dashboard
        </a>
    </aside>
    <div x-show="nav" @click="nav = false" x-cloak class="fixed inset-0 z-30 bg-black/50 backdrop-blur-sm lg:hidden"></div>

    <main id="main" class="p-4 sm:p-6 lg:p-8 lg:pt-6">
        <div class="flex items-center justify-between gap-3 mb-5">
            <button @click="nav = true" class="lg:hidden w-10 h-10 rounded-xl glass border border-white/40 dark:border-slate-700 flex items-center justify-center" aria-label="Open navigation">
                <svg class="w-5 h-5 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="flex items-center gap-2 ml-auto">
                <x-theme-toggle />
                @include('partials.notification_bell', ['indexUrl' => route('teacher.notifications.index'), 'readUrl' => route('teacher.notifications.read')])
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
