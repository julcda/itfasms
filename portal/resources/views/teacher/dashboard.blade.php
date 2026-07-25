@extends('teacher.layout')
@section('title', 'My Classes')

@section('content')
<x-page-header eyebrow="Classroom · {{ $sy->School_year }}" title="Welcome back, {{ $teacher->displayName() }}"
               subtitle="Only classes officially assigned to you appear here. Select a class to create lessons, materials, quizzes and more." />

@if ($classes->isEmpty())
    <x-empty-state icon="🏫" title="No classes assigned"
                   message="You have no classes for S.Y. {{ $sy->School_year }}. If this looks wrong, contact the Registrar." />
@else
@php $grads = ['from-emerald-500 to-green-600','from-sky-500 to-blue-600','from-violet-500 to-purple-600','from-amber-500 to-orange-600','from-rose-500 to-pink-600','from-teal-500 to-cyan-600']; @endphp
<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
    @foreach ($classes as $i => $c)
    @php $g = $grads[$i % count($grads)]; @endphp
    <a href="{{ route('teacher.lessons.index', $c->Class_id) }}"
       class="lift group block rounded-3xl overflow-hidden bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel hover:shadow-lift focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
        <div class="relative h-24 bg-gradient-to-br {{ $g }} p-5 flex items-end justify-between">
            <div class="absolute inset-0 opacity-20" style="background-image:radial-gradient(#fff 1px,transparent 1px);background-size:16px 16px"></div>
            <p class="relative text-white font-extrabold text-lg font-display leading-tight drop-shadow-sm">{{ $c->gradeLevel->Gradelevel ?? '—' }} — {{ $c->section->Section_name ?? '—' }}</p>
            <span class="relative shrink-0 text-[10px] font-extrabold rounded-full px-2.5 py-1 bg-white/25 backdrop-blur text-white">{{ $c->isOpen() ? 'OPEN' : 'CLOSED' }}</span>
        </div>
        <div class="p-5">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $c->subject->Subject_name ?? '—' }}</p>
            <div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-100 dark:border-slate-700 text-xs">
                <span class="text-slate-500 dark:text-slate-400 inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
                    <b class="text-slate-800 dark:text-slate-200">{{ $c->student_count }}</b> student{{ $c->student_count === 1 ? '' : 's' }}
                </span>
                <span class="inline-flex items-center gap-1.5 font-bold text-emerald-700 dark:text-emerald-400">
                    <b>{{ $c->lesson_count }}</b> lesson{{ $c->lesson_count === 1 ? '' : 's' }}
                    <span class="opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all" aria-hidden="true">→</span>
                </span>
            </div>
        </div>
    </a>
    @endforeach
</div>
@endif
@endsection
