@extends('layouts.app')
@section('title', 'Assignments')

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => 'Assignments', 'url' => null],
]" />
<x-page-header accent="green" eyebrow="{{ $class->subject->Subject_name ?? '' }}" title="Assignments" subtitle="Turn in your work and see your grades." />

@forelse ($assignments as $a)
@php $s = $mySubs->get($a->id); $st = $s->status ?? 'none'; $overdue = $a->due_at && $a->due_at->isPast(); @endphp
<a href="{{ route('student.assignments.show', $a->id) }}" class="lift block mb-3">
<x-card>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="font-bold text-slate-800 dark:text-slate-100">📝 {{ $a->title }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                {{ $a->max_score }} pts · {{ $a->due_at ? 'Due ' . $a->due_at->format('M j, g:i A') : 'No due date' }}
                @if ($overdue && $st === 'none') · <span class="text-rose-500">past due</span> @endif
            </p>
        </div>
        <div class="shrink-0">
            @switch($st)
                @case('graded') <span class="text-sm font-extrabold text-emerald-700 dark:text-emerald-400">{{ rtrim(rtrim(number_format((float)$s->score,2),'0'),'.') }}/{{ $a->max_score }}</span> @break
                @case('submitted') <x-badge color="green">Submitted</x-badge> @break
                @case('late') <x-badge color="amber">Late</x-badge> @break
                @case('returned') <x-badge color="rose">Returned</x-badge> @break
                @default <x-badge :color="$overdue ? 'rose' : 'slate'">{{ $overdue ? 'Missing' : 'To do' }}</x-badge>
            @endswitch
        </div>
    </div>
</x-card>
</a>
@empty
<x-empty-state icon="📝" title="No assignments yet" message="When your teacher posts an assignment, it'll appear here." />
@endforelse
@endsection
