@extends('teacher.layout')
@section('title', 'Quiz Results')

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('teacher.dashboard')],
    ['label' => 'Quizzes', 'url' => route('teacher.quizzes.index', $quiz->class_id)],
    ['label' => $quiz->title, 'url' => null],
]" />
<x-page-header :eyebrow="'Quiz results'" :title="$quiz->title"
               :subtitle="$attempts->count() . ' attempt(s) · ' . number_format($totalPoints,0) . ' total points'">
    <x-slot:actions><a href="{{ route('teacher.quizzes.edit', $quiz->id) }}" class="rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 text-sm font-bold px-4 py-2.5">Edit</a></x-slot:actions>
</x-page-header>

@if ($needsGrading)
<div class="mb-4 rounded-2xl bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/30 text-amber-800 dark:text-amber-300 text-sm px-4 py-3">✍ This quiz has essay questions — open an attempt to grade them.</div>
@endif

<x-card pad="p-0" class="overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700/50 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
            <tr><th class="text-left px-6 py-3">Student</th><th class="text-left">Score</th><th class="text-left">Status</th><th class="text-left">Submitted</th><th></th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
        @forelse ($attempts as $a)
            <tr>
                <td class="px-6 py-3 font-semibold text-slate-800 dark:text-slate-100">{{ $students->get($a->student_id)?->fullName() ?? 'Student #' . $a->student_id }} <span class="text-xs text-slate-400">· try {{ $a->attempt_number }}</span></td>
                <td class="font-bold text-emerald-700 dark:text-emerald-400 tabular-nums">{{ $a->score === null ? '—' : rtrim(rtrim(number_format((float)$a->score,2),'0'),'.') }} <span class="text-slate-400 font-normal">/ {{ number_format($totalPoints,0) }}</span></td>
                <td>@if ($a->status === 'graded')<x-badge color="emerald">Graded</x-badge>@else<x-badge color="amber">Needs grading</x-badge>@endif</td>
                <td class="text-slate-500 dark:text-slate-400 text-xs">{{ $a->submitted_at?->format('M j, g:i A') }}@if($a->is_auto_submitted) · auto @endif</td>
                <td class="pr-6 text-right"><a href="{{ route('teacher.quizzes.attempts.review', $a->id) }}" class="text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:underline">Review →</a></td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-6 py-10 text-center text-slate-400">No attempts yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>
@endsection
