@props(['rows' => 3])
{{-- Reusable loading placeholder for async-loaded content (Phase 2 lists). --}}
<div {{ $attributes->merge(['class' => 'space-y-3']) }} aria-hidden="true">
    @for ($i = 0; $i < (int) $rows; $i++)
    <div class="animate-pulse rounded-2xl border border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800/60 p-5">
        <div class="h-3.5 rounded bg-slate-200 dark:bg-slate-700 w-2/5 mb-3"></div>
        <div class="h-2.5 rounded bg-slate-100 dark:bg-slate-700/60 w-3/4 mb-2"></div>
        <div class="h-2.5 rounded bg-slate-100 dark:bg-slate-700/60 w-1/2"></div>
    </div>
    @endfor
</div>
