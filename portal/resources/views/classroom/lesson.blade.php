@extends('layouts.app')
@section('title', $lesson->title)

@section('content')
@php $uploadsUrl = rtrim(url(\App\Support\Uploads::url()), '/'); @endphp

<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => $lesson->title, 'url' => null],
]" />

<x-page-header accent="green" :eyebrow="$lesson->topic" :title="$lesson->title" :subtitle="$lesson->description">
    <x-slot:actions>
        <x-badge :color="$progress->status === 'completed' ? 'emerald' : 'amber'">
            {{ $progress->status === 'completed' ? '✔ Completed' : '⏳ In Progress' }}
        </x-badge>
    </x-slot:actions>
</x-page-header>

@if ($lesson->objectives || $lesson->learning_competency || $lesson->instructions)
<x-card class="mb-6 space-y-3 text-sm">
    @if ($lesson->learning_competency)<div><p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500 mb-1">Learning Competency</p><p class="text-slate-700 dark:text-slate-300">{{ $lesson->learning_competency }}</p></div>@endif
    @if ($lesson->objectives)<div><p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500 mb-1">Objectives</p><p class="text-slate-700 dark:text-slate-300">{{ $lesson->objectives }}</p></div>@endif
    @if ($lesson->instructions)<div><p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500 mb-1">Instructions</p><p class="text-slate-700 dark:text-slate-300 whitespace-pre-line">{{ $lesson->instructions }}</p></div>@endif
</x-card>
@endif

<div class="space-y-5">
@forelse ($lesson->resources as $r)
<x-card pad="p-5">
    <p class="font-bold text-sm mb-3 text-slate-800 dark:text-slate-100">{{ ['video_upload'=>'🎬','video_youtube'=>'🎬','video_vimeo'=>'🎬','video_gdrive'=>'🎬','document'=>'📄','image'=>'🖼️','link'=>'🔗'][$r->type] ?? '📎' }} {{ $r->title }}</p>

    @if ($r->isVideo() && $r->type === 'video_upload')
        <video controls class="w-full rounded-xl max-h-[480px] bg-black"><source src="{{ $uploadsUrl }}/{{ $r->file_path }}" type="{{ $r->mime_type }}"></video>
    @elseif ($r->isVideo())
        <div class="aspect-video rounded-xl overflow-hidden bg-black"><iframe src="{{ $r->embedUrl() }}" class="w-full h-full" title="{{ $r->title }}" frameborder="0" allowfullscreen loading="lazy"></iframe></div>
    @elseif ($r->type === 'image')
        <img src="{{ $uploadsUrl }}/{{ $r->file_path }}" alt="{{ $r->title }}" loading="lazy" class="w-full rounded-xl object-contain max-h-[480px] bg-slate-50 dark:bg-slate-900">
    @elseif ($r->type === 'document')
        @php $isPdf = str_ends_with(strtolower((string) $r->file_name), '.pdf'); @endphp
        @if ($isPdf)
        <iframe src="{{ $uploadsUrl }}/{{ $r->file_path }}" class="w-full h-[560px] rounded-xl border border-slate-200 dark:border-slate-700" title="{{ $r->title }}" loading="lazy"></iframe>
        @else
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 p-6 text-center">
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">{{ $r->file_name }}</p>
            <a href="https://docs.google.com/viewer?url={{ urlencode($uploadsUrl . '/' . $r->file_path) }}&embedded=true" target="_blank" rel="noopener"
               class="inline-block rounded-lg bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold px-4 py-2">Preview</a>
        </div>
        @endif
        <a href="{{ $uploadsUrl }}/{{ $r->file_path }}" download="{{ $r->file_name }}" class="inline-block mt-2 text-xs font-bold text-green-700 dark:text-green-400 hover:underline">⬇ Download {{ $r->file_name }}</a>
    @elseif ($r->type === 'link')
        <a href="{{ $r->url }}" target="_blank" rel="noopener" class="inline-block rounded-lg bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30 text-green-700 dark:text-green-300 text-sm font-semibold px-4 py-2 hover:bg-green-100 dark:hover:bg-green-500/20">🔗 Open link →</a>
    @endif
</x-card>
@empty
<x-empty-state icon="📎" title="No materials yet" message="This lesson doesn't have any materials attached." />
@endforelse
</div>

<div class="flex items-center justify-between mt-6 gap-3">
    <div class="w-24">
        @if ($prevId)<a href="{{ route('student.lessons.show', $prevId) }}" class="text-sm font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">← Previous</a>@endif
    </div>
    <form method="POST" action="{{ route('student.lessons.complete', $lesson->id) }}">
        @csrf
        <button class="rounded-xl {{ $progress->status === 'completed' ? 'bg-emerald-100 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-500/30' : 'bg-green-600 hover:bg-green-700 text-white' }} text-sm font-bold px-6 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">
            {{ $progress->status === 'completed' ? '✔ Completed' : 'Mark as Completed' }}
        </button>
    </form>
    <div class="w-24 text-right">
        @if ($nextId)<a href="{{ route('student.lessons.show', $nextId) }}" class="text-sm font-semibold text-green-700 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300">Next →</a>@endif
    </div>
</div>
@endsection
