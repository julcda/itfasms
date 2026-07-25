@extends('layouts.app')
@section('title', 'My Classes')

@section('content')
<x-page-header accent="emerald" eyebrow="Classroom · LMS" title="My Classes"
               subtitle="Lessons, materials, and activities from your teachers appear here as soon as they're published." />

@if ($classes->isEmpty())
    <x-empty-state icon="🏫" title="No classes yet" message="You're not enrolled in any classes for this school year. Check back once enrollment is finalized." />
@else
@php $grads = ['from-emerald-500 to-green-600','from-sky-500 to-blue-600','from-violet-500 to-purple-600','from-amber-500 to-orange-600','from-rose-500 to-pink-600','from-teal-500 to-cyan-600']; @endphp
<div x-data="{ q: '' }">
    <div class="relative max-w-md mb-6">
        <svg class="w-4 h-4 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
        <input type="text" x-model="q" placeholder="Search your classes…" aria-label="Search classes"
               class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-800/60 backdrop-blur-sm text-slate-800 dark:text-slate-100 pl-11 pr-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
        @foreach ($classes as $i => $c)
        @php $g = $grads[$i % count($grads)]; $initial = strtoupper(substr($c->subject->Subject_name ?? 'C', 0, 1)); @endphp
        <a href="{{ route('student.classes.show', $c->Class_id) }}"
           x-show="q === '' || {{ Illuminate\Support\Js::from(strtolower(($c->subject->Subject_name ?? '') . ' ' . ($c->teacher?->displayName() ?? ''))) }}.includes(q.toLowerCase())"
           class="lift group block rounded-3xl overflow-hidden bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel hover:shadow-lift focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
            <div class="relative h-24 bg-gradient-to-br {{ $g }} p-5 flex items-end">
                <div class="absolute inset-0 opacity-20" style="background-image:radial-gradient(#fff 1px,transparent 1px);background-size:16px 16px"></div>
                <div class="absolute top-4 right-4 w-11 h-11 rounded-2xl bg-white/25 backdrop-blur flex items-center justify-center text-white text-lg font-extrabold">{{ $initial }}</div>
                <p class="relative text-white font-extrabold text-lg font-display leading-tight drop-shadow-sm pr-14">{{ $c->subject->Subject_name ?? '—' }}</p>
            </div>
            <div class="p-5">
                <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    {{ $c->teacher->displayName() ?? '—' }}
                </div>
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-100 dark:border-slate-700">
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ $c->section->Section_name ?? '' }}</span>
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 dark:text-emerald-400">
                        {{ $c->published_lesson_count }} lesson{{ $c->published_lesson_count === 1 ? '' : 's' }}
                        <span class="opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all" aria-hidden="true">→</span>
                    </span>
                </div>
            </div>
        </a>
        @endforeach
    </div>
</div>
@endif
@endsection
