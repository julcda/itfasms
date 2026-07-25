@extends('teacher.layout')
@section('title', $quiz->exists ? 'Edit Quiz' : 'New Quiz')

@php
    $input = 'w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500';
    $label = 'block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5';
    $typeLabels = ['mcq'=>'Multiple Choice','multi_select'=>'Multiple Select','true_false'=>'True / False','identification'=>'Identification','short_answer'=>'Short Answer','essay'=>'Essay','matching'=>'Matching','ordering'=>'Ordering','fill_blank'=>'Fill in the Blank'];
@endphp

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('teacher.dashboard')],
    ['label' => 'Quizzes', 'url' => route('teacher.quizzes.index', $class->Class_id)],
    ['label' => $quiz->exists ? $quiz->title : 'New Quiz', 'url' => null],
]" />
<x-page-header :eyebrow="'Quiz'" :title="$quiz->exists ? 'Edit Quiz' : 'New Quiz'" />

@if ($errors->any())
<div role="alert" class="mb-5 rounded-2xl border border-rose-300 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-800 dark:text-rose-300 px-5 py-4 text-sm"><ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

{{-- Settings --}}
<x-card class="mb-6">
    <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-4">Quiz Settings</h2>
    <form method="POST" action="{{ $quiz->exists ? route('teacher.quizzes.update', $quiz->id) : route('teacher.quizzes.store', $class->Class_id) }}" class="space-y-4">
        @csrf @if ($quiz->exists) @method('PATCH') @endif
        <div>
            <label for="title" class="{{ $label }}">Title <span class="text-rose-500">*</span></label>
            <input id="title" name="title" required value="{{ old('title', $quiz->title) }}" class="{{ $input }}">
        </div>
        <div>
            <label for="description" class="{{ $label }}">Description / instructions</label>
            <textarea id="description" name="description" rows="2" class="{{ $input }}">{{ old('description', $quiz->description) }}</textarea>
        </div>
        <div class="grid sm:grid-cols-4 gap-4">
            <div><label class="{{ $label }}">Time limit (min)</label><input type="number" name="time_limit_minutes" min="1" max="600" value="{{ old('time_limit_minutes', $quiz->time_limit_minutes) }}" placeholder="None" class="{{ $input }}"></div>
            <div><label class="{{ $label }}">Passing score</label><input type="number" name="passing_score" step="0.5" min="0" value="{{ old('passing_score', $quiz->passing_score) }}" class="{{ $input }}"></div>
            <div><label class="{{ $label }}">Attempts</label><input type="number" name="max_attempts" min="1" max="20" value="{{ old('max_attempts', $quiz->max_attempts ?? 1) }}" class="{{ $input }}"></div>
            <div><label class="{{ $label }}">Due date</label><input type="datetime-local" name="due_at" value="{{ old('due_at', $quiz->due_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}"></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="{{ $label }}">Available from</label><input type="datetime-local" name="available_from" value="{{ old('available_from', $quiz->available_from?->format('Y-m-d\TH:i')) }}" class="{{ $input }}"></div>
            <div><label class="{{ $label }}">Available until</label><input type="datetime-local" name="available_until" value="{{ old('available_until', $quiz->available_until?->format('Y-m-d\TH:i')) }}" class="{{ $input }}"></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-2 text-sm text-slate-700 dark:text-slate-300">
            @foreach (['shuffle_questions'=>'Shuffle questions','shuffle_choices'=>'Shuffle choices','show_score_immediately'=>'Show score immediately','show_correct_answers'=>'Show correct answers after','auto_submit'=>'Auto-submit when time is up'] as $f => $lbl)
            <label class="inline-flex items-center gap-2"><input type="checkbox" name="{{ $f }}" value="1" class="accent-emerald-600" {{ old($f, $quiz->$f ?? ($f==='auto_submit'||$f==='show_score_immediately')) ? 'checked' : '' }}> {{ $lbl }}</label>
            @endforeach
        </div>
        <div>
            <span class="{{ $label }}">Status</span>
            <div class="flex gap-3">
                <label class="flex items-center gap-2 rounded-xl border-2 px-4 py-2.5 cursor-pointer text-sm font-semibold {{ old('status', $quiz->status) === 'draft' ? 'border-slate-400 bg-slate-50 dark:bg-slate-700 dark:border-slate-500' : 'border-slate-200 dark:border-slate-700' }} text-slate-700 dark:text-slate-200"><input type="radio" name="status" value="draft" class="accent-emerald-600" {{ old('status', $quiz->status ?? 'draft') === 'draft' ? 'checked' : '' }}> Draft</label>
                <label class="flex items-center gap-2 rounded-xl border-2 px-4 py-2.5 cursor-pointer text-sm font-semibold {{ old('status', $quiz->status) === 'published' ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200' }}"><input type="radio" name="status" value="published" class="accent-emerald-600" {{ old('status', $quiz->status) === 'published' ? 'checked' : '' }}> Published</label>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5">{{ $quiz->exists ? 'Save Settings' : 'Create Quiz' }}</button>
            @if ($quiz->exists)
                <form method="POST" action="{{ route('teacher.quizzes.destroy', $quiz->id) }}" onsubmit="return confirm('Delete this quiz and all attempts?')" class="inline">@csrf @method('DELETE')<button class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline">🗑 Delete quiz</button></form>
                <a href="{{ route('teacher.quizzes.results', $quiz->id) }}" class="text-sm font-bold text-emerald-700 dark:text-emerald-400 hover:underline">Results →</a>
            @endif
        </div>
    </form>
</x-card>

@if ($quiz->exists)
{{-- Existing questions --}}
<x-card class="mb-6">
    <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-3">Questions ({{ $quiz->questions->count() }}) · {{ number_format($quiz->questions->sum('points'), 0) }} pts</h2>
    @forelse ($quiz->questions as $i => $q)
    <div class="flex items-start gap-3 py-3 border-b border-slate-100 dark:border-slate-700 last:border-0">
        <span class="text-xs font-bold text-slate-400 mt-0.5">{{ $i + 1 }}</span>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <x-badge color="emerald">{{ $typeLabels[$q->type] ?? $q->type }}</x-badge>
                <span class="text-xs text-slate-400">{{ rtrim(rtrim(number_format($q->points,1),'0'),'.') }} pts</span>
            </div>
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 mt-1">{{ $q->question_text }}</p>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                @if (in_array($q->type, ['mcq','multi_select','true_false']))
                    @foreach ($q->choices as $c)<span class="mr-3">{{ $c->is_correct ? '✓' : '·' }} {{ $c->choice_text }}</span>@endforeach
                @elseif (in_array($q->type, ['identification','short_answer','fill_blank']))
                    Accepts: {{ implode(' / ', $q->meta['answers'] ?? []) }}
                @elseif ($q->type === 'matching')
                    @foreach ($q->meta['pairs'] ?? [] as $p)<span class="mr-3">{{ $p['left'] }} → {{ $p['right'] }}</span>@endforeach
                @elseif ($q->type === 'ordering')
                    Order: {{ implode(' → ', $q->meta['items'] ?? []) }}
                @else
                    Manually graded
                @endif
            </div>
        </div>
        <form method="POST" action="{{ route('teacher.quizzes.questions.destroy', $q->id) }}" onsubmit="return confirm('Remove this question?')">@csrf @method('DELETE')<button class="text-rose-500 text-sm">✕</button></form>
    </div>
    @empty
    <p class="text-sm text-slate-400 dark:text-slate-500 py-4 text-center">No questions yet — add your first below.</p>
    @endforelse
</x-card>

{{-- Add question --}}
<x-card x-data="{ type: 'mcq' }">
    <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-4">Add a question</h2>
    <form method="POST" action="{{ route('teacher.quizzes.questions.store', $quiz->id) }}" class="space-y-4">
        @csrf
        <div class="grid sm:grid-cols-[1fr_120px] gap-4">
            <div>
                <label class="{{ $label }}">Question type</label>
                <select name="type" x-model="type" class="{{ $input }}">
                    @foreach ($typeLabels as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                </select>
            </div>
            <div><label class="{{ $label }}">Points</label><input type="number" name="points" step="0.5" min="0.5" value="1" class="{{ $input }}"></div>
        </div>
        <div>
            <label class="{{ $label }}">Question <span class="text-rose-500">*</span></label>
            <textarea name="question_text" rows="2" required class="{{ $input }}"></textarea>
        </div>

        <div x-show="type==='mcq' || type==='multi_select'" x-cloak>
            <label class="{{ $label }}">Choices — one per line, mark correct answer(s) with a leading <b>*</b></label>
            <textarea name="choices" rows="4" placeholder="*Correct answer&#10;Wrong option&#10;Wrong option" class="{{ $input }} font-mono"></textarea>
        </div>
        <div x-show="type==='true_false'" x-cloak>
            <label class="{{ $label }}">Correct answer</label>
            <div class="flex gap-3">
                <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="tf_correct" value="true" checked class="accent-emerald-600"> True</label>
                <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="tf_correct" value="false" class="accent-emerald-600"> False</label>
            </div>
        </div>
        <div x-show="type==='identification' || type==='short_answer' || type==='fill_blank'" x-cloak>
            <label class="{{ $label }}">Accepted answers — one per line (any match is correct)</label>
            <textarea name="answers" rows="3" placeholder="Answer&#10;Alternative spelling" class="{{ $input }} font-mono"></textarea>
        </div>
        <div x-show="type==='matching'" x-cloak>
            <label class="{{ $label }}">Pairs — one per line as <b>left = right</b></label>
            <textarea name="pairs" rows="4" placeholder="Manila = Philippines&#10;Tokyo = Japan" class="{{ $input }} font-mono"></textarea>
        </div>
        <div x-show="type==='ordering'" x-cloak>
            <label class="{{ $label }}">Items — one per line, in the <b>correct order</b></label>
            <textarea name="items" rows="4" placeholder="First step&#10;Second step&#10;Third step" class="{{ $input }} font-mono"></textarea>
        </div>
        <div x-show="type==='essay'" x-cloak>
            <p class="text-sm text-slate-500 dark:text-slate-400">Essay answers are graded manually after submission.</p>
        </div>

        <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5">+ Add Question</button>
    </form>
</x-card>
@else
<x-card><p class="text-sm text-slate-500 dark:text-slate-400">Save the quiz settings first, then add questions.</p></x-card>
@endif
@endsection
