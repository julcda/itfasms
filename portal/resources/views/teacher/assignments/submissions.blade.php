@extends('teacher.layout')
@section('title', 'Submissions')

@section('content')
@php
    $graded = collect($rows)->filter(fn($r) => $r['submission'] && $r['submission']->status === 'graded')->count();
    $turnedIn = collect($rows)->filter(fn($r) => $r['submission'] && in_array($r['submission']->status, ['submitted','late','graded']))->count();
@endphp
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('teacher.dashboard')],
    ['label' => 'Assignments', 'url' => route('teacher.assignments.index', $assignment->class_id)],
    ['label' => $assignment->title, 'url' => null],
]" />
<x-page-header :eyebrow="'Assignment · ' . $assignment->max_score . ' pts'" :title="$assignment->title"
               :subtitle="$assignment->due_at ? 'Due ' . $assignment->due_at->format('M j, Y g:i A') : 'No due date'">
    <x-slot:actions>
        <a href="{{ route('teacher.assignments.edit', $assignment->id) }}" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 text-sm font-bold px-4 py-2.5">Edit</a>
    </x-slot:actions>
</x-page-header>

<div class="grid grid-cols-3 gap-3 mb-6">
    <x-card pad="p-4"><p class="text-[11px] uppercase text-slate-400 font-bold">Turned in</p><p class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $turnedIn }}<span class="text-slate-300 dark:text-slate-600 text-base">/{{ count($rows) }}</span></p></x-card>
    <x-card pad="p-4"><p class="text-[11px] uppercase text-slate-400 font-bold">Graded</p><p class="text-2xl font-extrabold text-emerald-700 dark:text-emerald-400">{{ $graded }}</p></x-card>
    <x-card pad="p-4"><p class="text-[11px] uppercase text-slate-400 font-bold">To grade</p><p class="text-2xl font-extrabold text-amber-600 dark:text-amber-400">{{ $turnedIn - $graded }}</p></x-card>
</div>

<div class="space-y-3">
@foreach ($rows as $r)
@php $s = $r['submission']; $st = $s->status ?? 'none'; @endphp
<x-card pad="p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <p class="font-bold text-slate-800 dark:text-slate-100">{{ $r['student']->fullName() }}</p>
                @switch($st)
                    @case('graded') <x-badge color="emerald">Graded</x-badge> @break
                    @case('submitted') <x-badge color="green">Submitted</x-badge> @break
                    @case('late') <x-badge color="amber">Late</x-badge> @break
                    @case('returned') <x-badge color="rose">Returned</x-badge> @break
                    @case('missing') <x-badge color="rose">Missing</x-badge> @break
                    @default <x-badge color="slate">Not submitted</x-badge>
                @endswitch
                @if ($s && $s->submitted_at)<span class="text-xs text-slate-400 dark:text-slate-500">{{ $s->submitted_at->format('M j, g:i A') }}</span>@endif
            </div>
            @if ($s)
                @if ($s->text_answer)<p class="text-sm text-slate-600 dark:text-slate-300 mt-2 whitespace-pre-line bg-slate-50 dark:bg-slate-700/40 rounded-xl px-3 py-2">{{ $s->text_answer }}</p>@endif
                @if ($s->files->isNotEmpty())
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach ($s->files as $f)
                    <a href="{{ \App\Support\Uploads::url($f->file_path) }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-lg bg-emerald-50 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 px-2.5 py-1.5">📎 {{ $f->file_name }} <span class="text-slate-400">{{ \App\Support\Uploads::humanSize($f->file_size) }}</span></a>
                    @endforeach
                </div>
                @endif
            @endif
        </div>

        {{-- Grade form --}}
        <form method="POST" action="{{ route('teacher.assignments.submissions.grade', $s->id ?? 0) }}" class="shrink-0 w-full sm:w-auto {{ $s ? '' : 'opacity-50 pointer-events-none' }}">
            @csrf
            <div class="flex items-center gap-2">
                <div class="relative">
                    <input type="number" name="score" step="0.01" min="0" max="{{ $assignment->max_score }}" value="{{ $s?->score }}" placeholder="—"
                           class="w-24 text-center rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 py-2 text-sm font-bold">
                    <span class="text-xs text-slate-400 ml-1">/ {{ $assignment->max_score }}</span>
                </div>
                <button name="action" value="grade" class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-2">Save</button>
            </div>
            <div class="flex items-center gap-3 mt-2 justify-end">
                <button name="action" value="return" class="text-xs font-semibold text-amber-700 dark:text-amber-400 hover:underline">Return</button>
                <button name="action" value="missing" class="text-xs font-semibold text-rose-600 dark:text-rose-400 hover:underline">Missing</button>
            </div>
            <input type="text" name="teacher_comment" value="{{ $s?->teacher_comment }}" placeholder="Feedback (optional)"
                   class="mt-2 w-full sm:w-64 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-1.5 text-xs">
        </form>
    </div>
</x-card>
@endforeach
</div>
@endsection
