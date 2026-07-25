@props(['color' => 'slate'])
@php
    $map = [
        'emerald' => 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-300 dark:border-emerald-500/30',
        'amber'   => 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-500/15 dark:text-amber-300 dark:border-amber-500/30',
        'rose'    => 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-500/15 dark:text-rose-300 dark:border-rose-500/30',
        'slate'   => 'bg-slate-100 text-slate-600 border-slate-300 dark:bg-slate-700 dark:text-slate-300 dark:border-slate-600',
        'green'   => 'bg-green-100 text-green-700 border-green-200 dark:bg-green-500/15 dark:text-green-300 dark:border-green-500/30',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-block text-[10px] font-extrabold rounded-full px-2 py-0.5 border ' . ($map[$color] ?? $map['slate'])]) }}>
    {{ $slot }}
</span>
