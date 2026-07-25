@extends('layouts.app')
@section('title', 'Quizzes')

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => 'Quizzes', 'url' => null],
]" />
<x-page-header accent="green" eyebrow="{{ $class->subject->Subject_name ?? '' }}" title="Quizzes" subtitle="Take quizzes assigned by your teacher." />

@forelse ($quizzes as $q)
@php
    $mine = $myAttempts->get($q->id) ?? collect();
    $best = $mine->whereNotNull('score')->max('score');
    $used = $mine->count();
@endphp
<x-card class="mb-3">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <p class="font-bold text-slate-800 dark:text-slate-100">🧠 {{ $q->title }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                {{ $q->questions_count }} questions
                @if ($q->time_limit_minutes) · ⏱ {{ $q->time_limit_minutes }} min @endif
                · {{ $used }}/{{ $q->max_attempts }} attempts used
                @if ($q->due_at) · Due {{ $q->due_at->format('M j, g:i A') }} @endif
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if ($best !== null)<span class="text-sm font-bold text-emerald-700 dark:text-emerald-400">Best: {{ rtrim(rtrim(number_format((float)$best,2),'0'),'.') }}</span>@endif
            <a href="{{ route('student.quizzes.show', $q->id) }}" class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">{{ $used > 0 ? 'View' : 'Start' }}</a>
        </div>
    </div>
</x-card>
@empty
<x-empty-state icon="🧠" title="No quizzes yet" message="When your teacher publishes a quiz, it'll show up here." />
@endforelse
@endsection
