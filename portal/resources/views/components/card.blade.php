@props(['pad' => 'p-6'])
<div {{ $attributes->merge(['class' => 'bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm rounded-3xl border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel ' . $pad]) }}>
    {{ $slot }}
</div>
