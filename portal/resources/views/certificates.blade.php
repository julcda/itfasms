@extends('layouts.app')
@section('title', 'My Certificates')

@section('content')
<x-page-header accent="green" eyebrow="Student Portal" title="My Certificates"
               subtitle="Awards published by your Department Head. Each carries a QR/verification reference." />

@if (!$certs)
    <x-empty-state icon="🎓" title="No certificates yet"
                   message="Certificates of Recognition appear here once your class adviser awards one and your Department Head publishes it." />
@else
<div class="grid md:grid-cols-2 gap-5">
    @foreach ($certs as $c)
    @php
        $lvl = (string) $c->honor_level;
        $hi = str_contains($lvl, 'Highest');
        $h2 = !$hi && str_contains($lvl, 'High');
        $grad = $hi ? 'from-amber-500 to-orange-600' : ($h2 ? 'from-violet-500 to-purple-600' : 'from-sky-500 to-blue-600');
    @endphp
    <article class="bg-white dark:bg-slate-800/60 rounded-3xl border border-green-100 dark:border-slate-700 shadow-panel overflow-hidden">
        <div class="bg-gradient-to-br {{ $grad }} px-5 py-4 text-white">
            <p class="text-[10px] uppercase tracking-[0.2em] opacity-90">Certificate of Recognition</p>
            <p class="font-extrabold text-lg leading-tight mt-0.5">{{ $lvl }}</p>
        </div>
        <div class="p-5">
            <dl class="text-sm space-y-1.5">
                <div class="flex justify-between"><dt class="text-slate-400 dark:text-slate-500">Period</dt><dd class="font-semibold text-slate-700 dark:text-slate-200">{{ $c->period_name ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-400 dark:text-slate-500">School Year</dt><dd class="font-semibold text-slate-700 dark:text-slate-200">{{ $c->school_year }}</dd></div>
                @if ($c->general_average !== null)
                <div class="flex justify-between"><dt class="text-slate-400 dark:text-slate-500">General Average</dt><dd class="font-extrabold text-emerald-700 dark:text-emerald-400">{{ number_format((float) $c->general_average, 2) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-slate-400 dark:text-slate-500">Certificate No.</dt><dd class="font-mono text-xs text-slate-700 dark:text-slate-300">{{ $c->certificate_no }}</dd></div>
            </dl>
            <a href="{{ route('certificates.print', ['id' => $c->id]) }}" target="_blank"
               class="mt-4 block text-center rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">🖨 View &amp; Print Certificate</a>
        </div>
    </article>
    @endforeach
</div>
@endif
@endsection
