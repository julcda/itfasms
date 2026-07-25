@extends('layouts.app')
@section('title', 'Quiz Result')

@section('content')
@php
    $pending = $attempt->status !== 'graded';
    $pct = $totalPoints > 0 ? round(((float)$attempt->score / $totalPoints) * 100) : 0;
    $passed = $quiz->passing_score !== null ? ((float)$attempt->score >= (float)$quiz->passing_score) : null;
@endphp
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => $quiz->title, 'url' => route('student.quizzes.show', $quiz->id)],
    ['label' => 'Result', 'url' => null],
]" />

<div class="relative overflow-hidden rounded-3xl p-8 mb-6 text-white text-center shadow-lift" style="background:linear-gradient(150deg,#0f5a30 0%,#0a3a1e 60%,#04240f 100%)">
    <div class="absolute -top-16 -right-10 w-64 h-64 rounded-full bg-emerald-400/20 blur-3xl"></div>
    <p class="relative text-gold-300 text-sm font-bold uppercase tracking-widest">{{ $quiz->title }}</p>
    @if ($pending)
        <p class="relative text-5xl font-extrabold font-display mt-3">Submitted ✓</p>
        <p class="relative text-emerald-100/80 mt-2">Some answers need manual grading — your final score will appear once your teacher reviews them.</p>
    @else
        <p class="relative text-6xl font-extrabold font-display mt-3 tabular-nums">{{ rtrim(rtrim(number_format((float)$attempt->score,2),'0'),'.') }}<span class="text-2xl text-emerald-200/70"> / {{ number_format($totalPoints,0) }}</span></p>
        <p class="relative text-emerald-100/80 mt-1">{{ $pct }}%
            @if ($passed === true) · <span class="text-gold-300 font-bold">Passed</span>
            @elseif ($passed === false) · <span class="text-rose-300 font-bold">Did not pass</span> @endif
        </p>
    @endif
</div>

@if ($quiz->show_correct_answers && !$pending)
<h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-3">Answer review</h2>
@foreach ($quiz->questions as $i => $q)
@php $ans = $answers->get($q->id); $sel = (array)($ans->selected_choice_ids ?? []); @endphp
<x-card class="mb-3">
    <div class="flex items-center gap-2 mb-2">
        <span class="text-xs font-bold text-slate-400">Q{{ $i+1 }}</span>
        @if ($ans && $ans->is_correct === true)<x-badge color="emerald">Correct</x-badge>
        @elseif ($ans && $ans->is_correct === false)<x-badge color="rose">Incorrect</x-badge>
        @else<x-badge color="amber">Pending</x-badge>@endif
        <span class="text-xs text-slate-400">{{ $ans && $ans->points_awarded !== null ? rtrim(rtrim(number_format((float)$ans->points_awarded,1),'0'),'.') : '—' }}/{{ rtrim(rtrim(number_format($q->points,1),'0'),'.') }}</span>
    </div>
    <p class="font-semibold text-slate-800 dark:text-slate-100">{{ $q->question_text }}</p>
    @if (in_array($q->type, ['mcq','multi_select','true_false']))
        <div class="mt-1 text-sm">
            @foreach ($q->choices as $c)
            <div class="{{ $c->is_correct ? 'text-emerald-700 dark:text-emerald-400 font-semibold' : (in_array($c->id,$sel) ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400') }}">
                {{ in_array($c->id,$sel) ? '▸' : '·' }} {{ $c->choice_text }} {{ $c->is_correct ? '✓' : '' }}
            </div>
            @endforeach
        </div>
    @elseif ($q->type === 'essay')
        <p class="text-sm text-slate-600 dark:text-slate-300 mt-1 bg-slate-50 dark:bg-slate-700/40 rounded-xl px-3 py-2">{{ $ans->answer_text ?? '' }}</p>
        @if ($ans && $ans->teacher_feedback)<p class="text-xs text-emerald-700 dark:text-emerald-400 mt-1"><b>Feedback:</b> {{ $ans->teacher_feedback }}</p>@endif
    @else
        <p class="text-sm text-slate-600 dark:text-slate-300 mt-1">Your answer: <b>{{ $ans->answer_text ?? '(none)' }}</b></p>
    @endif
</x-card>
@endforeach
@endif

<div class="mt-4">
    <a href="{{ route('student.quizzes.show', $quiz->id) }}" class="text-sm font-bold text-green-700 dark:text-green-400 hover:underline">← Back to quiz</a>
</div>
@endsection
