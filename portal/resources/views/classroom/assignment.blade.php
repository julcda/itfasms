@extends('layouts.app')
@section('title', $assignment->title)

@section('content')
@php
    $st = $submission->status ?? 'none';
    $overdue = $assignment->due_at && $assignment->due_at->isPast();
    $locked = $st === 'graded';
    $canSubmit = !$locked && (!$overdue || $assignment->allow_late);
@endphp

<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => $assignment->title, 'url' => null],
]" />

<x-page-header accent="green" :eyebrow="'Assignment · ' . $assignment->max_score . ' points'" :title="$assignment->title" :subtitle="$assignment->description">
    <x-slot:actions>
        @switch($st)
            @case('graded') <x-badge color="emerald">✔ Graded</x-badge> @break
            @case('submitted') <x-badge color="green">Submitted</x-badge> @break
            @case('late') <x-badge color="amber">Submitted (late)</x-badge> @break
            @case('returned') <x-badge color="rose">Returned for revision</x-badge> @break
            @default <x-badge :color="$overdue ? 'rose' : 'slate'">{{ $overdue ? 'Past due' : 'Not submitted' }}</x-badge>
        @endswitch
    </x-slot:actions>
</x-page-header>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <x-card>
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm mb-4">
                <span class="text-slate-500 dark:text-slate-400">📅 {{ $assignment->due_at ? 'Due ' . $assignment->due_at->format('M j, Y · g:i A') : 'No due date' }}</span>
                <span class="text-slate-500 dark:text-slate-400">💯 {{ $assignment->max_score }} points</span>
                <span class="text-slate-500 dark:text-slate-400">{{ $assignment->allow_late ? '⏳ Late allowed' : '⛔ No late' }}</span>
            </div>
            @if ($assignment->instructions)
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500 mb-1">Instructions</p>
                <p class="text-slate-700 dark:text-slate-300 whitespace-pre-line leading-relaxed">{{ $assignment->instructions }}</p>
            </div>
            @endif
            @if ($assignment->attachments->isNotEmpty())
            <div class="mt-4">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500 mb-2">Reference files</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($assignment->attachments as $att)
                    <a href="{{ \App\Support\Uploads::url($att->file_path) }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 px-3 py-2">📎 {{ $att->file_name }}</a>
                    @endforeach
                </div>
            </div>
            @endif
        </x-card>

        {{-- Grade feedback --}}
        @if ($st === 'graded' || (($submission->teacher_comment ?? '') !== ''))
        <x-card class="border-emerald-200 dark:border-emerald-500/30">
            <div class="flex items-center justify-between">
                <h2 class="font-extrabold text-lg text-slate-900 dark:text-white">Your Grade</h2>
                @if ($st === 'graded')<p class="text-2xl font-extrabold text-emerald-700 dark:text-emerald-400 tabular-nums">{{ rtrim(rtrim(number_format((float)$submission->score,2),'0'),'.') }} <span class="text-slate-400 text-lg">/ {{ $assignment->max_score }}</span></p>@endif
            </div>
            @if (($submission->teacher_comment ?? '') !== '')
            <p class="text-sm text-slate-600 dark:text-slate-300 mt-2 bg-slate-50 dark:bg-slate-700/40 rounded-xl px-3 py-2"><b>Feedback:</b> {{ $submission->teacher_comment }}</p>
            @endif
        </x-card>
        @endif
    </div>

    {{-- Submission panel --}}
    <x-card class="h-fit">
        <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-1">Your Work</h2>
        @if ($submission && $submission->submitted_at)
        <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Submitted {{ $submission->submitted_at->format('M j, Y g:i A') }}</p>
        @endif

        @if ($submission && $submission->files->isNotEmpty())
        <div class="space-y-1.5 mb-4">
            @foreach ($submission->files as $f)
            <a href="{{ \App\Support\Uploads::url($f->file_path) }}" target="_blank" class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400 hover:underline">📎 {{ $f->file_name }}</a>
            @endforeach
        </div>
        @endif

        @if ($canSubmit)
        <form method="POST" action="{{ route('student.assignments.submit', $assignment->id) }}" enctype="multipart/form-data" class="space-y-3" x-data="{ busy:false }" @submit="busy=true">
            @csrf
            @if ($assignment->require_text || ($submission && $submission->text_answer))
            <div>
                <label for="text_answer" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Written answer @if($assignment->require_text)<span class="text-rose-500">*</span>@endif</label>
                <textarea id="text_answer" name="text_answer" rows="4" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">{{ $submission->text_answer ?? '' }}</textarea>
            </div>
            @endif
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Attach files @if($assignment->require_file && !($submission && $submission->files->isNotEmpty()))<span class="text-rose-500">*</span>@endif</label>
                <input type="file" name="files[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.jpg,.jpeg,.png,.webp,.zip" class="block w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-green-50 dark:file:bg-green-500/15 file:text-green-700 dark:file:text-green-400 file:font-semibold">
                <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">PDF, Office docs, images, or ZIP — up to 25MB each.</p>
            </div>
            <button type="submit" :disabled="busy" class="w-full rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-2.5 disabled:opacity-60">
                {{ $submission && in_array($st, ['submitted','late','returned']) ? 'Update Submission' : 'Turn In' }}
            </button>
        </form>
        @elseif ($locked)
        <p class="text-sm text-slate-500 dark:text-slate-400">This assignment has been graded and can no longer be edited.</p>
        @else
        <p class="text-sm text-rose-600 dark:text-rose-400">The deadline has passed and late submissions aren't allowed.</p>
        @endif
    </x-card>
</div>
@endsection
