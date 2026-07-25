@extends('teacher.layout')
@section('title', 'Class Analytics')

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('teacher.dashboard')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('teacher.classes.stream', $class->Class_id)],
    ['label' => 'Analytics', 'url' => null],
]" />
<x-page-header eyebrow="Insights" :title="'Class Analytics'"
               :subtitle="($class->subject->Subject_name ?? '') . ' · ' . $metrics['rosterCount'] . ' students'" />

{{-- Top metric tiles --}}
<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php
        $tiles = [
            ['Lessons', $metrics['lessonCount'], $metrics['lessonCompletionPct'].'% completed', 'emerald', 'M12 14l9-5-9-5-9 5 9 5z'],
            ['Assignments', $metrics['assignmentCount'], $metrics['submissionRate'].'% turned in', 'sky', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['Quiz attempts', $metrics['quizAttemptCount'], $metrics['quizAvgPct'].'% average', 'violet', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['Missing work', $metrics['missingSubs'], 'across assignments', 'rose', 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
        ];
    @endphp
    @foreach ($tiles as [$label, $value, $sub, $c, $icon])
    <x-card pad="p-5">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-2xl bg-{{ $c }}-100 dark:bg-{{ $c }}-500/15 text-{{ $c }}-600 dark:text-{{ $c }}-400 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
            </div>
            <div>
                <p class="text-3xl font-extrabold text-slate-900 dark:text-white tabular-nums">{{ $value }}</p>
            </div>
        </div>
        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500 mt-3">{{ $label }}</p>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $sub }}</p>
    </x-card>
    @endforeach
</div>

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Completion meters --}}
    <x-card class="lg:col-span-1">
        <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-4">Engagement</h2>
        @php
            $meters = [
                ['Lesson completion', $metrics['lessonCompletionPct'], 'emerald'],
                ['Assignment turn-in', $metrics['submissionRate'], 'sky'],
                ['Quiz average', $metrics['quizAvgPct'], 'violet'],
            ];
        @endphp
        <div class="space-y-4">
            @foreach ($meters as [$label, $pct, $c])
            <div>
                <div class="flex justify-between text-sm mb-1"><span class="text-slate-600 dark:text-slate-300">{{ $label }}</span><span class="font-bold text-slate-800 dark:text-slate-100">{{ $pct }}%</span></div>
                <div class="h-2.5 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden"><div class="h-full bg-{{ $c }}-500 rounded-full transition-all" style="width: {{ $pct }}%"></div></div>
            </div>
            @endforeach
        </div>
    </x-card>

    {{-- Leaderboard --}}
    <x-card class="lg:col-span-2">
        <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-4">Lesson progress by student</h2>
        @if ($leaderboard->isEmpty() || $metrics['lessonCount'] === 0)
        <p class="text-sm text-slate-400 dark:text-slate-500">Publish lessons to start tracking progress.</p>
        @else
        <div class="space-y-2.5 max-h-96 overflow-y-auto pr-1">
            @foreach ($leaderboard as $r)
            <div class="flex items-center gap-3">
                <span class="text-sm text-slate-700 dark:text-slate-200 w-40 truncate shrink-0">{{ $r['name'] }}</span>
                <div class="flex-1 h-2 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden">
                    <div class="h-full rounded-full {{ $r['pct'] >= 75 ? 'bg-emerald-500' : ($r['pct'] >= 40 ? 'bg-amber-500' : 'bg-rose-400') }}" style="width: {{ $r['pct'] }}%"></div>
                </div>
                <span class="text-xs font-bold text-slate-500 dark:text-slate-400 w-16 text-right tabular-nums">{{ $r['done'] }}/{{ $metrics['lessonCount'] }}</span>
            </div>
            @endforeach
        </div>
        @endif
    </x-card>
</div>
@endsection
