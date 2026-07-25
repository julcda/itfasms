@extends('teacher.layout')
@section('title', 'Review Attempt')

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'Quizzes', 'url' => route('teacher.quizzes.index', $quiz->class_id)],
    ['label' => $quiz->title, 'url' => route('teacher.quizzes.results', $quiz->id)],
    ['label' => 'Review', 'url' => null],
]" />
<x-page-header :eyebrow="'Attempt review'" :title="$student?->fullName() ?? 'Attempt'"
               :subtitle="'Score: ' . ($attempt->score === null ? '—' : rtrim(rtrim(number_format((float)$attempt->score,2),'0'),'.'))" />

@foreach ($quiz->questions as $i => $q)
@php $ans = $answers->get($q->id); $selected = (array)($ans->selected_choice_ids ?? []); @endphp
<x-card class="mb-4">
    <div class="flex items-center gap-2 mb-2">
        <span class="text-xs font-bold text-slate-400">Q{{ $i+1 }}</span>
        <x-badge color="slate">{{ str_replace('_',' ',$q->type) }}</x-badge>
        <span class="text-xs text-slate-400">{{ rtrim(rtrim(number_format($q->points,1),'0'),'.') }} pts</span>
        @if ($ans && $ans->is_correct === true)<x-badge color="emerald">Correct</x-badge>
        @elseif ($ans && $ans->is_correct === false)<x-badge color="rose">Incorrect</x-badge>
        @elseif ($q->type === 'essay')<x-badge color="amber">Needs grading</x-badge>@endif
    </div>
    <p class="font-semibold text-slate-800 dark:text-slate-100">{{ $q->question_text }}</p>

    <div class="mt-2 text-sm">
        @if (in_array($q->type, ['mcq','multi_select','true_false']))
            @foreach ($q->choices as $c)
            <div class="{{ in_array($c->id, $selected) ? 'font-bold' : '' }} {{ $c->is_correct ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-400' }}">
                {{ in_array($c->id, $selected) ? '▸' : '·' }} {{ $c->choice_text }} {{ $c->is_correct ? '✓' : '' }}
            </div>
            @endforeach
        @elseif ($q->type === 'essay')
            <p class="text-slate-700 dark:text-slate-300 whitespace-pre-line bg-slate-50 dark:bg-slate-700/40 rounded-xl px-3 py-2">{{ $ans->answer_text ?? '(no answer)' }}</p>
            <form method="POST" action="{{ route('teacher.quizzes.answers.grade', $ans->id ?? 0) }}" class="flex flex-wrap items-end gap-2 mt-3">
                @csrf
                <div><label class="text-xs font-semibold text-slate-500">Points</label><input type="number" name="points" step="0.5" min="0" max="{{ $q->points }}" value="{{ $ans->points_awarded }}" class="w-24 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-1.5 text-sm"> <span class="text-xs text-slate-400">/ {{ $q->points }}</span></div>
                <input type="text" name="feedback" value="{{ $ans->teacher_feedback }}" placeholder="Feedback" class="flex-1 min-w-[180px] rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-1.5 text-sm">
                <button class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2">Save</button>
            </form>
        @else
            <p class="text-slate-700 dark:text-slate-300">Answer: <b>{{ $ans->answer_text ?? '(none)' }}</b></p>
        @endif
    </div>
</x-card>
@endforeach
@endsection
