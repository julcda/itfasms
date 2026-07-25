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
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    @include('partials.sidebar')

    <main id="main" class="p-4 sm:p-6 lg:p-8 lg:pt-6">
        <div class="flex justify-end items-center gap-2 mb-5">
            <x-theme-toggle />
            @include('partials.notification_bell', ['indexUrl' => route('student.notifications.index'), 'readUrl' => route('student.notifications.read')])
        </div>

        <div class="animate-enter">
            @yield('content')
        </div>
    </main>
</div>
<x-toasts />
</body>
</html>
