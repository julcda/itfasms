@extends('teacher.layout')
@section('title', 'Quizzes')

@section('content')
@php $tab = 'quizzes'; @endphp
@include('teacher.partials.workspace_tabs')

<div class="flex justify-end mb-4">
    <a href="{{ route('teacher.quizzes.create', $class->Class_id) }}" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">+ New Quiz</a>
</div>

@forelse ($quizzes as $q)
<x-card class="mb-3 lift">
    <div class="flex items-start justify-between gap-4">
        <a href="{{ route('teacher.quizzes.edit', $q->id) }}" class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <x-badge :color="$q->status === 'published' ? 'emerald' : 'slate'">{{ strtoupper($q->status) }}</x-badge>
                <p class="font-bold text-slate-800 dark:text-slate-100 truncate">{{ $q->title }}</p>
            </div>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                {{ $q->questions_count }} question{{ $q->questions_count === 1 ? '' : 's' }}
                @if ($q->time_limit_minutes) · ⏱ {{ $q->time_limit_minutes }} min @endif
                · {{ $q->max_attempts }} attempt{{ $q->max_attempts === 1 ? '' : 's' }}
                @if ($q->due_at) · Due {{ $q->due_at->format('M j') }} @endif
            </p>
        </a>
        <a href="{{ route('teacher.quizzes.results', $q->id) }}" class="text-right shrink-0">
            <p class="text-2xl font-extrabold text-emerald-700 dark:text-emerald-400">{{ $q->attempts_count }}</p>
            <p class="text-[11px] text-slate-400 dark:text-slate-500 uppercase">attempts →</p>
        </a>
    </div>
</x-card>
@empty
<x-empty-state icon="🧠" title="No quizzes yet" message="Build a quiz with multiple choice, true/false, identification, matching, ordering, essay and more.">
    <x-slot:action><a href="{{ route('teacher.quizzes.create', $class->Class_id) }}" class="inline-block rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">+ New Quiz</a></x-slot:action>
</x-empty-state>
@endforelse
@endsection
