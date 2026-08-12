@php
    $navItems = [
        ['dashboard',    'Dashboard',            'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['soa',          'Statement of Account', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['account',      'Account',              'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
        ['certificates', 'Certificates',         'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['grades',       'Grades',               'M12 14l9-5-9-5-9 5 9 5z'],
    ];
    $sbName     = trim(($profile->firstname ?? '') . ' ' . ($profile->surname ?? '')) ?: 'Student';
    $sbInitials = strtoupper(substr($profile->firstname ?? 'S', 0, 1) . substr($profile->surname ?? '', 0, 1));
@endphp
<aside x-cloak :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed lg:static inset-y-0 left-0 z-40 w-[280px] text-slate-100 flex flex-col p-6 transition-transform duration-300 lg:m-3 lg:rounded-3xl overflow-hidden"
       style="background:linear-gradient(160deg,#0f5a30 0%,#0a3a1e 55%,#04240f 100%)" aria-label="Portal navigation">
    <div class="absolute -top-16 -right-12 w-52 h-52 rounded-full bg-emerald-400/20 blur-3xl pointer-events-none"></div>

    <div class="relative flex items-center gap-3 mb-9">
        <div class="w-10 h-10 rounded-2xl bg-white/10 ring-1 ring-white/15 flex items-center justify-center">
            <img src="/itfalogo.png" alt="" class="w-7 h-7 object-contain" onerror="this.style.display='none'">
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-[0.2em] text-gold-300 font-bold">ITFA System</p>
            <p class="text-base font-extrabold leading-tight font-display">Student Portal</p>
        </div>
        <button @click="nav = false" class="lg:hidden ml-auto -mr-1 w-9 h-9 rounded-lg text-emerald-50/80 hover:bg-white/10 flex items-center justify-center" aria-label="Close menu">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <nav class="relative space-y-1 flex-1">
        @php $classroomActive = request()->routeIs('student.classes.*') || request()->routeIs('student.lessons.*'); @endphp
        <a href="{{ route('student.classes.index') }}"
           class="group flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium transition-all {{ $classroomActive ? 'bg-white/15 text-white font-semibold ring-1 ring-white/10 shadow-lg' : 'text-emerald-50/70 hover:bg-white/10 hover:text-white' }}">
            <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            <span>My Classes</span>
            <span class="ml-auto text-[9px] uppercase tracking-wide bg-gold-400/25 text-gold-100 px-1.5 py-0.5 rounded font-bold">LMS</span>
        </a>
        @foreach ($navItems as [$route, $label, $icon])
            @php $active = request()->routeIs($route); @endphp
            <a href="{{ route($route) }}"
               class="group flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium transition-all {{ $active ? 'bg-white/15 text-white font-semibold ring-1 ring-white/10 shadow-lg' : 'text-emerald-50/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                <span>{{ $label }}</span>
            </a>
        @endforeach

        <form method="POST" action="{{ route('logout') }}" class="pt-2">
            @csrf
            <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium text-emerald-50/70 hover:bg-white/10 hover:text-white transition-colors">
                <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Logout
            </button>
        </form>
    </nav>

    <div class="relative rounded-2xl border border-white/10 bg-white/5 p-3.5 mt-6 flex items-center gap-3">
        @if (!empty($photoUrl))
        <img src="{{ $photoUrl }}" alt="" class="w-11 h-11 rounded-xl object-cover border border-white/20">
        @else
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-gold-400 to-gold-600 flex items-center justify-center text-sm font-extrabold text-white">{{ $sbInitials }}</div>
        @endif
        <div class="min-w-0">
            <p class="font-semibold text-sm truncate">{{ $sbName }}</p>
            <p class="text-xs text-gold-300 truncate">LRN: {{ $profile->lrn ?? '' }}</p>
        </div>
    </div>
</aside>
