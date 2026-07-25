@props(['icon' => '📭', 'title' => 'Nothing here yet', 'message' => null])
<div {{ $attributes->merge(['class' => 'rounded-3xl bg-white/70 dark:bg-slate-800/40 backdrop-blur-sm border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel p-12 sm:p-16 text-center']) }}>
    <div class="relative mx-auto w-20 h-20 mb-5">
        <div class="absolute inset-0 rounded-3xl bg-gradient-to-br from-emerald-400/20 to-gold-400/20 blur-xl" aria-hidden="true"></div>
        <div class="relative w-20 h-20 rounded-3xl bg-white dark:bg-slate-800 ring-1 ring-slate-900/5 dark:ring-white/10 shadow-sm flex items-center justify-center text-4xl" aria-hidden="true">{{ $icon }}</div>
    </div>
    <h2 class="font-extrabold text-lg text-slate-800 dark:text-slate-100">{{ $title }}</h2>
    @if ($message)
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 max-w-md mx-auto leading-relaxed">{{ $message }}</p>
    @endif
    @if (isset($action))
        <div class="mt-6">{{ $action }}</div>
    @endif
</div>
