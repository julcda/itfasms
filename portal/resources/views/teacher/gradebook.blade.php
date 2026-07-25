@extends('teacher.layout')
@section('title', 'Gradebook')

@section('content')
@php $tab = 'grades'; @endphp
@include('teacher.partials.workspace_tabs')

<div class="mb-4 rounded-2xl bg-sky-50 dark:bg-sky-500/10 border border-sky-200 dark:border-sky-500/30 text-sky-800 dark:text-sky-300 text-sm px-4 py-3">
    ℹ These are <b>LMS activity scores</b> (assignments + quizzes), gathered automatically as you grade. They are a working record — importing them into the school's official grade sheet stays with the Registrar's encoding process.
</div>

@if ($columns->isEmpty())
    <x-empty-state icon="📊" title="Nothing to grade yet" message="Once you create assignments or quizzes and grade submissions, scores collect here automatically." />
@else
<x-card pad="p-0" class="overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700/50 text-xs text-slate-500 dark:text-slate-400">
                <tr>
                    <th class="text-left px-5 py-3 sticky left-0 bg-slate-50 dark:bg-slate-700/50 z-10 min-w-[200px]">Student</th>
                    @foreach ($columns as $col)
                    <th class="px-3 py-3 text-center whitespace-nowrap" title="{{ $col['label'] }}">
                        <span class="block text-[10px] uppercase tracking-wide text-{{ $col['kind'] === 'Quiz' ? 'violet' : 'emerald' }}-500">{{ $col['kind'] }}</span>
                        <span class="font-semibold text-slate-600 dark:text-slate-300">{{ \Illuminate\Support\Str::limit($col['label'], 16) }}</span>
                        <span class="block text-[10px] text-slate-400">/{{ rtrim(rtrim(number_format($col['max'],1),'0'),'.') }}</span>
                    </th>
                    @endforeach
                    <th class="px-4 py-3 text-center bg-emerald-50 dark:bg-emerald-500/10 font-bold text-emerald-700 dark:text-emerald-400">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                @foreach ($students as $s)
                @php
                    $row = $scores[$s->student_id] ?? [];
                    $earned = array_sum($row);
                    $pct = $totalMax > 0 ? round($earned / $totalMax * 100) : 0;
                @endphp
                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-700/30">
                    <td class="px-5 py-2.5 font-semibold text-slate-800 dark:text-slate-100 sticky left-0 bg-white dark:bg-slate-800/95 z-10">{{ $s->fullName() }}</td>
                    @foreach ($columns as $col)
                    @php $v = $row[$col['key']] ?? null; @endphp
                    <td class="px-3 py-2.5 text-center tabular-nums {{ $v === null ? 'text-slate-300 dark:text-slate-600' : 'text-slate-700 dark:text-slate-200' }}">
                        {{ $v === null ? '—' : rtrim(rtrim(number_format((float)$v,2),'0'),'.') }}
                    </td>
                    @endforeach
                    <td class="px-4 py-2.5 text-center bg-emerald-50/50 dark:bg-emerald-500/5">
                        <span class="font-extrabold text-slate-900 dark:text-white tabular-nums">{{ rtrim(rtrim(number_format($earned,2),'0'),'.') }}</span>
                        <span class="block text-[11px] font-bold {{ $pct >= 75 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-500' }}">{{ $pct }}%</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>
<p class="text-xs text-slate-400 dark:text-slate-500 mt-3">Total possible: {{ rtrim(rtrim(number_format($totalMax,2),'0'),'.') }} points across {{ $columns->count() }} activit{{ $columns->count() === 1 ? 'y' : 'ies' }}.</p>
@endif
@endsection
