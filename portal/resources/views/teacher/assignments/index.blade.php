@extends('teacher.layout')
@section('title', 'Assignments')

@section('content')
@php $tab = 'assignments'; @endphp
@include('teacher.partials.workspace_tabs')

<div class="flex justify-end mb-4">
    <a href="{{ route('teacher.assignments.create', $class->Class_id) }}" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">+ New Assignment</a>
</div>

@forelse ($assignments as $a)
<a href="{{ route('teacher.assignments.submissions', $a->id) }}"
   class="lift block bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm rounded-2xl border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel p-5 mb-3 hover:shadow-lift">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <x-badge :color="$a->status === 'published' ? 'emerald' : 'slate'">{{ strtoupper($a->status) }}</x-badge>
                <p class="font-bold text-slate-800 dark:text-slate-100 truncate">{{ $a->title }}</p>
            </div>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                {{ $a->max_score }} pts · {{ $a->due_at ? 'Due ' . $a->due_at->format('M j, Y g:i A') : 'No due date' }}
                @if ($a->due_at && $a->due_at->isPast()) · <span class="text-rose-500">past due</span> @endif
            </p>
        </div>
        <div class="text-right shrink-0">
            <p class="text-2xl font-extrabold text-emerald-700 dark:text-emerald-400 tabular-nums">{{ $a->submitted_count }}<span class="text-slate-300 dark:text-slate-600 text-base">/{{ $rosterCount }}</span></p>
            <p class="text-[11px] text-slate-400 dark:text-slate-500 uppercase tracking-wide">turned in</p>
        </div>
    </div>
</a>
@empty
<x-empty-state icon="📝" title="No assignments yet" message="Create an assignment to collect work from your students.">
    <x-slot:action>
        <a href="{{ route('teacher.assignments.create', $class->Class_id) }}" class="inline-block rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">+ New Assignment</a>
    </x-slot:action>
</x-empty-state>
@endforelse
@endsection
