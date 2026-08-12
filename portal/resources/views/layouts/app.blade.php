<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Student Portal') | ITFA</title>
    @include('partials.head')
</head>
<body class="app-bg min-h-screen font-sans text-slate-800 dark:text-slate-200">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-green-600 focus:text-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold">Skip to content</a>
<div x-data="{ nav: false }" class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    @include('partials.sidebar')

    {{-- Mobile drawer backdrop --}}
    <div x-show="nav" @click="nav = false" x-cloak class="fixed inset-0 z-30 bg-black/50 backdrop-blur-sm lg:hidden"></div>

    <main id="main" class="p-4 sm:p-6 lg:p-8 lg:pt-6">
        <div class="flex items-center justify-between gap-3 mb-5">
            <button @click="nav = true" class="lg:hidden w-10 h-10 rounded-xl glass border border-white/40 dark:border-slate-700 flex items-center justify-center" aria-label="Open menu">
                <svg class="w-5 h-5 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="flex items-center gap-2 ml-auto">
                <x-theme-toggle />
                @include('partials.notification_bell', ['indexUrl' => route('student.notifications.index'), 'readUrl' => route('student.notifications.read')])
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
