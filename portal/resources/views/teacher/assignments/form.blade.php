@extends('teacher.layout')
@section('title', $assignment->exists ? 'Edit Assignment' : 'New Assignment')

@php
    $input = 'w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500';
    $label = 'block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5';
@endphp

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('teacher.dashboard')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('teacher.assignments.index', $class->Class_id)],
    ['label' => $assignment->exists ? 'Edit' : 'New Assignment', 'url' => null],
]" />
<x-page-header :eyebrow="'Assignment'" :title="$assignment->exists ? 'Edit Assignment' : 'New Assignment'" />

@if ($errors->any())
<div role="alert" class="mb-5 rounded-2xl border border-rose-300 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-800 dark:text-rose-300 px-5 py-4 text-sm">
    <ul class="list-disc list-inside space-y-0.5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<x-card>
    <form method="POST" action="{{ $assignment->exists ? route('teacher.assignments.update', $assignment->id) : route('teacher.assignments.store', $class->Class_id) }}"
          enctype="multipart/form-data" x-data="{ saving:false }" @submit="saving=true" class="space-y-4">
        @csrf
        @if ($assignment->exists) @method('PATCH') @endif

        <div>
            <label for="title" class="{{ $label }}">Title <span class="text-rose-500">*</span></label>
            <input id="title" type="text" name="title" required value="{{ old('title', $assignment->title) }}" class="{{ $input }}">
        </div>
        <div>
            <label for="description" class="{{ $label }}">Description</label>
            <textarea id="description" name="description" rows="2" class="{{ $input }}">{{ old('description', $assignment->description) }}</textarea>
        </div>
        <div>
            <label for="instructions" class="{{ $label }}">Instructions</label>
            <textarea id="instructions" name="instructions" rows="3" class="{{ $input }}">{{ old('instructions', $assignment->instructions) }}</textarea>
        </div>

        <div class="grid sm:grid-cols-3 gap-4">
            <div>
                <label for="due_at" class="{{ $label }}">Due date &amp; time</label>
                <input id="due_at" type="datetime-local" name="due_at" value="{{ old('due_at', $assignment->due_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
            </div>
            <div>
                <label for="max_score" class="{{ $label }}">Maximum score <span class="text-rose-500">*</span></label>
                <input id="max_score" type="number" name="max_score" min="1" max="1000" required value="{{ old('max_score', $assignment->max_score ?? 100) }}" class="{{ $input }}">
            </div>
            <div>
                <label for="submission_mode" class="{{ $label }}">Type</label>
                <select id="submission_mode" name="submission_mode" class="{{ $input }}">
                    <option value="individual" {{ old('submission_mode', $assignment->submission_mode) === 'individual' ? 'selected' : '' }}>Individual</option>
                    <option value="group" {{ old('submission_mode', $assignment->submission_mode) === 'group' ? 'selected' : '' }}>Group</option>
                </select>
            </div>
        </div>

        <div class="flex flex-wrap gap-5 pt-1">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" name="allow_late" value="1" class="accent-emerald-600" {{ old('allow_late', $assignment->allow_late ?? true) ? 'checked' : '' }}> Allow late submission</label>
            <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" name="require_file" value="1" class="accent-emerald-600" {{ old('require_file', $assignment->require_file ?? true) ? 'checked' : '' }}> File required</label>
            <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" name="require_text" value="1" class="accent-emerald-600" {{ old('require_text', $assignment->require_text ?? false) ? 'checked' : '' }}> Written answer required</label>
        </div>

        <div>
            <label class="{{ $label }}">Reference attachments <span class="text-slate-400 font-normal">(optional — PDF/DOCX/PPT/XLSX/images/ZIP)</span></label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.jpg,.jpeg,.png,.webp,.zip" class="block w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-emerald-50 dark:file:bg-emerald-500/15 file:text-emerald-700 dark:file:text-emerald-400 file:font-semibold">
            @if ($assignment->exists && $assignment->attachments->isNotEmpty())
            <div class="mt-3 space-y-1.5">
                @foreach ($assignment->attachments as $att)
                <div class="flex items-center gap-2 text-sm">
                    <a href="{{ \App\Support\Uploads::url($att->file_path) }}" target="_blank" class="text-emerald-700 dark:text-emerald-400 hover:underline">📎 {{ $att->file_name }}</a>
                    <form method="POST" action="{{ route('teacher.assignments.attachments.destroy', $att->id) }}" onsubmit="return confirm('Remove this attachment?')">@csrf @method('DELETE')<button class="text-rose-500 text-xs">✕</button></form>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div>
            <span class="{{ $label }}">Status</span>
            <div class="flex gap-3">
                <label class="flex items-center gap-2 rounded-xl border-2 px-4 py-2.5 cursor-pointer text-sm font-semibold {{ old('status', $assignment->status) === 'draft' ? 'border-slate-400 bg-slate-50 dark:bg-slate-700 dark:border-slate-500' : 'border-slate-200 dark:border-slate-700' }} text-slate-700 dark:text-slate-200"><input type="radio" name="status" value="draft" class="accent-emerald-600" {{ old('status', $assignment->status ?? 'draft') === 'draft' ? 'checked' : '' }}> Draft</label>
                <label class="flex items-center gap-2 rounded-xl border-2 px-4 py-2.5 cursor-pointer text-sm font-semibold {{ old('status', $assignment->status) === 'published' ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200' }}"><input type="radio" name="status" value="published" class="accent-emerald-600" {{ old('status', $assignment->status) === 'published' ? 'checked' : '' }}> Published</label>
            </div>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1.5">Publishing notifies every enrolled student.</p>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white text-sm font-bold px-6 py-2.5">
                <svg x-show="saving" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                {{ $assignment->exists ? 'Save Assignment' : 'Create Assignment' }}
            </button>
            @if ($assignment->exists)
            <a href="{{ route('teacher.assignments.submissions', $assignment->id) }}" class="text-sm font-bold text-emerald-700 dark:text-emerald-400 hover:underline">View submissions →</a>
            @endif
        </div>
    </form>

    @if ($assignment->exists)
    <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-700 flex gap-4">
        @if ($assignment->status === 'published')
        <form method="POST" action="{{ route('teacher.assignments.unpublish', $assignment->id) }}">@csrf<button class="text-xs font-bold text-amber-700 dark:text-amber-400 hover:underline">↩ Move to Draft</button></form>
        @else
        <form method="POST" action="{{ route('teacher.assignments.publish', $assignment->id) }}">@csrf<button class="text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:underline">✓ Publish now</button></form>
        @endif
        <form method="POST" action="{{ route('teacher.assignments.destroy', $assignment->id) }}" onsubmit="return confirm('Delete this assignment and all submissions? This cannot be undone.')">@csrf @method('DELETE')<button class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline">🗑 Delete</button></form>
    </div>
    @endif
</x-card>
@endsection
