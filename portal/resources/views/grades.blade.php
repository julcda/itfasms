@extends('layouts.app')
@section('title', 'My Grades')

@section('content')
@php
    use App\Services\Portal;
    $mid = trim((string) ($profile->middlename ?? ''));
    $fullName = trim($profile->firstname . ' ' . ($mid !== '' ? mb_substr($mid,0,1) . '. ' : '') . $profile->surname);
    $avg = $slip['average'] ?? null;
    $avgColor = $avg === null ? '#64748b' : ($avg >= 90 ? '#059669' : ($avg >= Portal::GRADE_PASSING ? '#166534' : '#e11d48'));
@endphp

<x-page-header accent="green" eyebrow="Student Portal" title="My Grades" :subtitle="'S.Y. ' . $sy['label'] . ' · ' . $fullName" />

@if (!$periods || !$slip || !$slip['released'])
    <x-empty-state icon="🔒" title="Your grades are not yet released"
                   message="Your grade slip becomes available here once your Department Head has reviewed and published the results for the grading period. Please check back later." />
@else

@if (count($periods) > 1)
<x-card pad="p-5" class="mb-6">
    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">Grading Period</p>
    <div class="flex flex-wrap gap-2">
        @foreach ($periods as $p)
        <a href="{{ route('grades', ['p' => $p->id]) }}"
           class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 {{ (int)$p->id === $periodId ? 'bg-green-700 border-green-700 text-white' : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:border-green-400' }}">
            {{ $p->name }}
        </a>
        @endforeach
    </div>
</x-card>
@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php $tile = 'rounded-2xl bg-white dark:bg-slate-800/60 ring-1 ring-slate-200 dark:ring-slate-700 shadow-panel p-4'; @endphp
    <div class="{{ $tile }}">
        <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">Grading Period</p>
        <p class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ $period->name ?? '—' }}</p>
    </div>
    <div class="{{ $tile }}">
        <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">Subjects</p>
        <p class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ $slip['graded'] }}<span class="text-sm text-slate-300 dark:text-slate-600">/{{ $slip['subjects'] }}</span></p>
    </div>
    <div class="{{ $tile }}">
        <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">General Average</p>
        <p class="text-3xl font-extrabold mt-1" style="color:{{ $avgColor }}">{{ $avg === null ? '—' : number_format($avg, 2) }}</p>
    </div>
    <div class="{{ $tile }}">
        <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">Remarks</p>
        <p class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mt-2">{{ $portal->gradeDescriptor($avg) }}</p>
    </div>
</div>

<x-card pad="p-0" class="overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-slate-100 dark:border-slate-700">
        <div>
            <h2 class="font-extrabold text-lg text-slate-900 dark:text-white">Grade Slip</h2>
            @if ($slip['released_at'])
            <p class="text-xs text-slate-400 dark:text-slate-500">Released {{ \Carbon\Carbon::parse($slip['released_at'])->format('M j, Y') }}</p>
            @endif
        </div>
        <a href="{{ route('grades.slip', ['p' => $periodId]) }}" target="_blank"
           class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">🖨 Print Grade Slip</a>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700/50 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                <tr>
                    <th scope="col" class="text-left px-6 py-3 w-10">#</th><th scope="col" class="text-left">Subject</th>
                    <th scope="col" class="text-left">Teacher</th><th scope="col" class="text-center w-24">Grade</th><th scope="col" class="text-left w-40">Remarks</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
            @foreach ($slip['rows'] as $i => $r)
                <tr class="hover:bg-green-50/30 dark:hover:bg-slate-700/40">
                    <td class="px-6 py-3 text-slate-400 dark:text-slate-500">{{ $i + 1 }}</td>
                    <td class="font-semibold text-slate-800 dark:text-slate-100">{{ $r['subject'] }}@if ($r['code'])<span class="block text-xs text-slate-400 dark:text-slate-500">{{ $r['code'] }}</span>@endif</td>
                    <td class="text-slate-500 dark:text-slate-400 text-xs">{{ $r['teacher'] }}</td>
                    <td class="text-center">
                        @if ($r['pending'])
                            <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                        @else
                            <span class="text-base font-extrabold" style="color:{{ $r['grade'] >= Portal::GRADE_PASSING ? '#059669' : '#e11d48' }}">{{ number_format((float) $r['grade'], 2) }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($r['pending'])
                            <x-badge color="slate">Not yet released</x-badge>
                        @else
                            <x-badge :color="$r['grade'] >= Portal::GRADE_PASSING ? 'emerald' : 'rose'">{{ $r['remarks'] }}</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="bg-green-50/60 dark:bg-green-500/10">
                <tr>
                    <td colspan="3" class="px-6 py-3 text-right font-extrabold text-slate-700 dark:text-slate-200">GENERAL AVERAGE</td>
                    <td class="text-center text-lg font-extrabold" style="color:{{ $avgColor }}">{{ $avg === null ? '—' : number_format($avg, 2) }}</td>
                    <td class="font-bold text-slate-700 dark:text-slate-200">{{ $portal->gradeDescriptor($avg) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if (!$slip['complete'])
    <div class="px-6 py-3 bg-amber-50 dark:bg-amber-500/10 border-t border-amber-200 dark:border-amber-500/30 text-xs text-amber-800 dark:text-amber-300">
        ⓘ Some subjects are still being finalized. The general average shown is based on the {{ $slip['graded'] }} subject{{ $slip['graded'] === 1 ? '' : 's' }} released so far.
    </div>
    @endif
</x-card>
@endif
@endsection
