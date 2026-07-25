@props(['eyebrow' => null, 'title' => '', 'subtitle' => null, 'accent' => 'emerald'])
<header class="relative overflow-hidden glass rounded-3xl border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel p-6 sm:p-7 mb-6">
    {{-- decorative accent blob --}}
    <div class="absolute -top-14 -right-10 w-44 h-44 rounded-full bg-{{ $accent }}-400/15 blur-3xl pointer-events-none" aria-hidden="true"></div>
    <div class="relative flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="inline-flex items-center gap-1.5 text-[11px] uppercase tracking-[0.18em] text-{{ $accent }}-700 dark:text-{{ $accent }}-400 font-bold">
                    <span class="w-1.5 h-1.5 rounded-full bg-{{ $accent }}-500"></span>{{ $eyebrow }}
                </p>
            @endif
            <h1 class="text-2xl sm:text-[2rem] leading-tight font-extrabold font-display mt-1.5 text-slate-900 dark:text-white text-balance">{{ $title }}</h1>
            @if ($subtitle)
                <p class="text-slate-500 dark:text-slate-400 mt-1.5 text-sm max-w-2xl">{{ $subtitle }}</p>
            @endif
        </div>
        @if (isset($actions))
            <div class="flex items-center gap-2 shrink-0">{{ $actions }}</div>
        @endif
    </div>
</header>
