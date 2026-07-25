@extends('teacher.layout')
@section('title', 'Announcements')

@section('content')
@php $tab = 'announcements'; @endphp
@include('teacher.partials.workspace_tabs')

<x-card class="mb-6">
    <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-3">Post an announcement</h2>
    <form method="POST" action="{{ route('teacher.announcements.store', $class->Class_id) }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <input type="text" name="title" placeholder="Title (optional)" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm">
        <textarea name="body" rows="3" required placeholder="Share something with the class…" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm"></textarea>
        <div class="grid sm:grid-cols-2 gap-3">
            <input type="url" name="link" placeholder="Attach a link (optional)" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm">
            <input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.jpg,.jpeg,.png,.webp" class="block w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-emerald-50 dark:file:bg-emerald-500/15 file:text-emerald-700 dark:file:text-emerald-400 file:font-semibold">
        </div>
        <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5">Post &amp; Notify Class</button>
    </form>
</x-card>

@forelse ($announcements as $a)
    <x-card class="mb-3">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                @if ($a->title)<p class="font-extrabold text-slate-900 dark:text-white">{{ $a->title }}</p>@endif
                <p class="text-sm text-slate-700 dark:text-slate-300 mt-1 whitespace-pre-line">{{ $a->body }}</p>
                @include('classroom.partials.announcement_attachments', ['a' => $a])
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-2">{{ $a->created_at->diffForHumans() }}</p>
            </div>
            <form method="POST" action="{{ route('teacher.announcements.destroy', $a->id) }}" onsubmit="return confirm('Delete this announcement?')">@csrf @method('DELETE')<button class="text-rose-500 hover:text-rose-700 text-sm" aria-label="Delete">✕</button></form>
        </div>
    </x-card>
@empty
    <x-empty-state icon="📣" title="No announcements yet" message="Post one above — students are notified immediately." />
@endforelse
@endsection
