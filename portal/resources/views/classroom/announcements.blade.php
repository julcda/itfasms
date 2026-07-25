@extends('layouts.app')
@section('title', 'Announcements')

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => 'Announcements', 'url' => null],
]" />
<x-page-header accent="green" eyebrow="{{ $class->subject->Subject_name ?? '' }}" title="Announcements"
               :subtitle="'Updates from ' . ($class->teacher->displayName() ?? 'your teacher')" />

@forelse ($announcements as $a)
    <x-card class="mb-3">
        <div class="flex items-center gap-2 mb-1">
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-emerald-500 to-green-600 text-white flex items-center justify-center text-xs font-bold shrink-0" aria-hidden="true">📣</div>
            <div>
                @if ($a->title)<p class="font-extrabold text-slate-900 dark:text-white leading-tight">{{ $a->title }}</p>@endif
                <p class="text-[11px] text-slate-400 dark:text-slate-500">{{ $a->created_at->diffForHumans() }}</p>
            </div>
        </div>
        <p class="text-sm text-slate-700 dark:text-slate-300 mt-1 whitespace-pre-line leading-relaxed">{{ $a->body }}</p>
        @include('classroom.partials.announcement_attachments', ['a' => $a])
    </x-card>
@empty
    <x-empty-state icon="📣" title="No announcements yet" message="When your teacher posts an update, it'll appear here." />
@endforelse
@endsection
