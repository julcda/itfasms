@extends('layouts.app')
@section('title', $class->subject->Subject_name ?? 'Class')

@section('content')
@php
    $done = $progress->filter(fn ($p) => $p->status === 'completed')->count();
    $total = $lessons->count();
    $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
@endphp

<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => null],
]" />

<x-page-header accent="green" :eyebrow="$class->section->Section_name ?? ''"
               :title="$class->subject->Subject_name ?? '—'" :subtitle="'Taught by ' . ($class->teacher->displayName() ?? '—')" />

{{-- Module nav --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    @php
        $nav = [
            ['Assignments', route('student.classes.assignments', $class->Class_id), '📝', 'sky', $counts['assignments'] ?? 0],
            ['Quizzes', route('student.classes.quizzes', $class->Class_id), '🧠', 'violet', $counts['quizzes'] ?? 0],
            ['Announcements', route('student.classes.announcements', $class->Class_id), '📣', 'gold', $counts['announcements'] ?? 0],
            ['Discussion', route('student.classes.discussion', $class->Class_id), '💬', 'emerald', $counts['discussion'] ?? 0],
        ];
    @endphp
    @foreach ($nav as [$label, $url, $icon, $c, $n])
    <a href="{{ $url }}" class="lift bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm rounded-2xl border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel p-4 hover:shadow-lift focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">
        <div class="flex items-center justify-between">
            <span class="w-10 h-10 rounded-xl bg-{{ $c }}-100 dark:bg-{{ $c }}-500/15 flex items-center justify-center text-lg">{{ $icon }}</span>
            @if ($n > 0)<span class="text-xs font-extrabold text-{{ $c }}-600 dark:text-{{ $c }}-400 tabular-nums">{{ $n }}</span>@endif
        </div>
        <p class="font-bold text-sm text-slate-800 dark:text-slate-100 mt-2">{{ $label }}</p>
    </a>
    @endforeach
</div>

@if ($total > 0)
<x-card class="mb-6" pad="p-5">
    <div class="flex items-center justify-between mb-2">
        <p class="text-sm font-bold text-slate-700 dark:text-slate-200">Your progress</p>
        <p class="text-sm font-extrabold text-green-700 dark:text-green-400">{{ $done }}/{{ $total }} completed</p>
    </div>
    <div class="h-2.5 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="Lesson completion">
        <div class="h-full bg-green-500 rounded-full transition-all" style="width: {{ $pct }}%"></div>
    </div>
</x-card>
@endif

<x-card pad="p-0" class="overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700"><h2 class="font-extrabold text-lg text-slate-900 dark:text-white">Lessons</h2></div>
    @forelse ($lessons as $lesson)
    @php $p = $progress->get($lesson->id); $status = $p->status ?? 'not_started'; @endphp
    <a href="{{ route('student.lessons.show', $lesson->id) }}" class="flex items-center gap-4 px-6 py-4 border-b border-slate-50 dark:border-slate-700/50 last:border-0 hover:bg-green-50/30 dark:hover:bg-slate-700/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">
        <span class="text-xl w-6 text-center" aria-hidden="true">@if ($status === 'completed') ✔ @elseif ($status === 'in_progress') ⏳ @else ⬜ @endif</span>
        <div class="flex-1 min-w-0">
            <p class="font-semibold text-sm truncate text-slate-800 dark:text-slate-100">{{ $lesson->title }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500">{{ $lesson->topic }}</p>
        </div>
        <x-badge :color="$status === 'completed' ? 'emerald' : ($status === 'in_progress' ? 'amber' : 'slate')">
            {{ $status === 'completed' ? 'Completed' : ($status === 'in_progress' ? 'In Progress' : 'Not Started') }}
        </x-badge>
    </a>
    @empty
    <x-empty-state icon="📘" title="No lessons yet" message="Your teacher hasn't published any lessons for this class — check back soon." class="border-0 shadow-none" />
    @endforelse
</x-card>
@endsection
