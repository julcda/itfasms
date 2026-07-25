@extends('layouts.app')
@section('title', $quiz->title)

@section('content')
@php $used = $attempts->count(); $canStart = $used < $quiz->max_attempts; @endphp
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => $quiz->title, 'url' => null],
]" />
<x-page-header accent="green" eyebrow="Quiz" :title="$quiz->title" :subtitle="$quiz->description" />

<div class="grid lg:grid-cols-3 gap-6">
    <x-card class="lg:col-span-2">
        <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-4">Before you begin</h2>
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
            <div class="rounded-xl bg-slate-50 dark:bg-slate-700/40 px-4 py-3"><p class="text-slate-400 text-xs uppercase font-bold">Questions</p><p class="font-bold text-slate-800 dark:text-slate-100">{{ $questionCount }}</p></div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-700/40 px-4 py-3"><p class="text-slate-400 text-xs uppercase font-bold">Total points</p><p class="font-bold text-slate-800 dark:text-slate-100">{{ number_format($totalPoints,0) }}</p></div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-700/40 px-4 py-3"><p class="text-slate-400 text-xs uppercase font-bold">Time limit</p><p class="font-bold text-slate-800 dark:text-slate-100">{{ $quiz->time_limit_minutes ? $quiz->time_limit_minutes . ' minutes' : 'None' }}</p></div>
            <div class="rounded-xl bg-slate-50 dark:bg-slate-700/40 px-4 py-3"><p class="text-slate-400 text-xs uppercase font-bold">Attempts</p><p class="font-bold text-slate-800 dark:text-slate-100">{{ $used }} / {{ $quiz->max_attempts }} used</p></div>
        </div>
        @if ($quiz->time_limit_minutes)<p class="text-xs text-amber-600 dark:text-amber-400 mt-3">⏱ Once you start, the timer runs continuously. The quiz auto-submits when time is up.</p>@endif

        <div class="mt-6">
            @if ($canStart)
            <form method="POST" action="{{ route('student.quizzes.start', $quiz->id) }}">@csrf
                <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-6 py-3">{{ $used > 0 ? 'Start attempt ' . ($used+1) : 'Start Quiz' }}</button>
            </form>
            @else
            <p class="text-sm text-rose-600 dark:text-rose-400">You have used all {{ $quiz->max_attempts }} attempts.</p>
            @endif
        </div>
    </x-card>

    <x-card class="h-fit">
        <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-3">Your attempts</h2>
        @forelse ($attempts as $a)
        <a href="{{ route('student.attempts.result', $a->id) }}" class="flex items-center justify-between py-2 border-b border-slate-100 dark:border-slate-700 last:border-0">
            <span class="text-sm text-slate-600 dark:text-slate-300">Attempt {{ $a->attempt_number }}</span>
            <span class="text-sm font-bold {{ $a->status === 'graded' ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-600' }}">{{ $a->status === 'in_progress' ? 'In progress' : (rtrim(rtrim(number_format((float)$a->score,2),'0'),'.') . ' / ' . number_format($totalPoints,0)) }}</span>
        </a>
        @empty
        <p class="text-sm text-slate-400 dark:text-slate-500">No attempts yet.</p>
        @endforelse
    </x-card>
</div>
@endsection
