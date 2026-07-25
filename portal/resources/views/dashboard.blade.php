@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
@php
    $mid = trim((string) ($profile->middlename ?? ''));
    $fullName = trim($profile->firstname . ' ' . ($mid !== '' ? $mid[0] . '. ' : '') . $profile->surname);
    $cards = [
        ['Grade Level', $profile->grade_name, 'M12 14l9-5-9-5-9 5 9 5z', 'emerald'],
        ['Section', $profile->section_name, 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2', 'sky'],
        ['School Year', $profile->school_year, 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'violet'],
        ['Department', $profile->Department, 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3', 'gold'],
        ['Classification', $profile->classification_name ?: '—', 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z', 'rose'],
        ['Student Status', $profile->Status, 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'emerald'],
    ];
    $quick = [
        ['My Classes', route('student.classes.index'), 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253'],
        ['Grades', route('grades'), 'M12 14l9-5-9-5-9 5 9 5z'],
        ['Statement of Account', route('soa'), 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ];
@endphp

{{-- Hero --}}
<div class="relative overflow-hidden rounded-3xl p-6 sm:p-8 mb-6 text-white shadow-lift" style="background:linear-gradient(150deg,#0f5a30 0%,#0a3a1e 60%,#04240f 100%)">
    <div class="absolute -top-20 -right-10 w-72 h-72 rounded-full bg-emerald-400/20 blur-3xl"></div>
    <div class="absolute -bottom-24 right-1/3 w-72 h-72 rounded-full bg-gold-500/10 blur-3xl"></div>
    <div class="relative flex flex-col sm:flex-row sm:items-center gap-5">
        @if ($photoUrl)
        <img src="{{ $photoUrl }}" alt="" class="w-20 h-20 rounded-2xl object-cover ring-2 ring-white/30 shadow-lg">
        @else
        <div class="w-20 h-20 rounded-2xl bg-white/10 ring-2 ring-white/25 flex items-center justify-center text-2xl font-extrabold">
            {{ strtoupper(substr($profile->firstname,0,1) . substr($profile->surname,0,1)) }}
        </div>
        @endif
        <div class="flex-1 min-w-0">
            <p class="text-[11px] uppercase tracking-[0.2em] text-gold-300 font-bold">Welcome back</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold font-display mt-0.5 truncate">{{ $fullName }}</h1>
            <p class="text-emerald-100/70 mt-1 text-sm">LRN {{ $profile->lrn }} &nbsp;·&nbsp; {{ $profile->student_type }} Student</p>
        </div>
        <span class="self-start sm:self-center inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-bold bg-white/15 ring-1 ring-white/20">
            <span class="w-2 h-2 rounded-full bg-gold-300"></span>{{ $profile->Status }}
        </span>
    </div>
    <div class="relative flex flex-wrap gap-2 mt-6">
        @foreach ($quick as [$label, $url, $icon])
        <a href="{{ $url }}" class="inline-flex items-center gap-2 text-sm font-semibold bg-white/10 hover:bg-white/20 ring-1 ring-white/15 rounded-xl px-3.5 py-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
            {{ $label }}
        </a>
        @endforeach
    </div>
</div>

{{-- Info cards --}}
<section class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    @foreach ($cards as [$label, $value, $path, $c])
    <div class="lift bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm rounded-2xl border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel p-5 flex items-start gap-4">
        <div class="w-11 h-11 rounded-2xl bg-{{ $c }}-100 dark:bg-{{ $c }}-500/15 text-{{ $c }}-600 dark:text-{{ $c }}-400 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/></svg>
        </div>
        <div class="min-w-0">
            <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500 font-bold">{{ $label }}</p>
            <p class="font-bold text-slate-800 dark:text-slate-100 mt-0.5 truncate">{{ $value ?: '—' }}</p>
        </div>
    </div>
    @endforeach
</section>

{{-- Account summary --}}
<x-card>
    <div class="flex items-center justify-between mb-5">
        <h2 class="font-extrabold text-lg font-display text-slate-900 dark:text-white">Account Summary</h2>
        <a href="{{ route('soa') }}" class="text-sm font-semibold text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300 inline-flex items-center gap-1">View full SOA <span aria-hidden="true">→</span></a>
    </div>
    <div class="grid sm:grid-cols-4 gap-3">
        <div class="rounded-2xl bg-slate-50 dark:bg-slate-700/40 p-5">
            <p class="text-[11px] uppercase text-slate-400 dark:text-slate-500 font-bold">Total Assessment</p>
            <p class="text-2xl font-extrabold mt-1 text-slate-900 dark:text-white tabular-nums">₱{{ number_format($assessed, 2) }}</p>
        </div>
        <div class="rounded-2xl bg-emerald-50 dark:bg-emerald-500/10 p-5">
            <p class="text-[11px] uppercase text-emerald-600/80 dark:text-emerald-400/80 font-bold">Payments Made</p>
            <p class="text-2xl font-extrabold mt-1 text-emerald-700 dark:text-emerald-400 tabular-nums">₱{{ number_format($paid, 2) }}</p>
        </div>
        <div class="rounded-2xl {{ $balance > 0 ? 'bg-rose-50 dark:bg-rose-500/10' : 'bg-emerald-50 dark:bg-emerald-500/10' }} p-5">
            <p class="text-[11px] uppercase font-bold {{ $balance > 0 ? 'text-rose-600/80 dark:text-rose-400/80' : 'text-emerald-600/80 dark:text-emerald-400/80' }}">Remaining Balance</p>
            <p class="text-2xl font-extrabold mt-1 tabular-nums {{ $balance > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">₱{{ number_format(max(0,$balance), 2) }}</p>
        </div>
        <div class="rounded-2xl bg-{{ $statusColor }}-50 dark:bg-{{ $statusColor }}-500/10 p-5 flex flex-col justify-center">
            <p class="text-[11px] uppercase text-slate-400 dark:text-slate-500 font-bold">Payment Status</p>
            <p class="mt-1.5"><span class="inline-block px-3 py-1 rounded-full text-xs font-bold bg-{{ $statusColor }}-100 text-{{ $statusColor }}-800 border border-{{ $statusColor }}-300 dark:bg-{{ $statusColor }}-500/20 dark:text-{{ $statusColor }}-300 dark:border-{{ $statusColor }}-500/30">{{ $payStatus }}</span></p>
        </div>
    </div>
    @if ($balance < 0)
    <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-3">You have an advance/credit of ₱{{ number_format(abs($balance), 2) }} on your account.</p>
    @endif
</x-card>
@endsection
