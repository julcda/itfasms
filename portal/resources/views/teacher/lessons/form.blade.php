@extends('teacher.layout')
@section('title', $lesson->exists ? 'Edit Lesson' : 'New Lesson')

@php
    $input = 'w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 placeholder:text-slate-400 dark:placeholder:text-slate-500';
    $label = 'block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5';
@endphp

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('teacher.dashboard')],
    ['label' => ($class->subject->Subject_name ?? 'Class'), 'url' => route('teacher.lessons.index', $class->Class_id)],
    ['label' => $lesson->exists ? 'Edit Lesson' : 'New Lesson', 'url' => null],
]" />

<x-page-header :eyebrow="($class->subject->Subject_name ?? '') . ' — ' . ($class->section->Section_name ?? '')"
               :title="$lesson->exists ? 'Edit Lesson' : 'New Lesson'" />

@if ($errors->any())
<div role="alert" class="mb-5 rounded-2xl border border-rose-300 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-800 dark:text-rose-300 px-5 py-4 text-sm">
    <p class="font-bold mb-1">Please fix the following:</p>
    <ul class="list-disc list-inside space-y-0.5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Lesson details --}}
    <x-card class="lg:col-span-2 h-fit">
        <form method="POST" action="{{ $lesson->exists ? route('teacher.lessons.update', $lesson->id) : route('teacher.lessons.store', $class->Class_id) }}"
              x-data="{ saving: false }" @submit="saving = true" class="space-y-4">
            @csrf
            @if ($lesson->exists) @method('PATCH') @endif

            <div>
                <label for="title" class="{{ $label }}">Lesson Title <span class="text-rose-500">*</span></label>
                <input id="title" type="text" name="title" required value="{{ old('title', $lesson->title) }}" class="{{ $input }}">
            </div>
            <div>
                <label for="description" class="{{ $label }}">Description</label>
                <textarea id="description" name="description" rows="2" class="{{ $input }}">{{ old('description', $lesson->description) }}</textarea>
            </div>

            <div class="grid sm:grid-cols-3 gap-4">
                <div>
                    <label for="grading_period_id" class="{{ $label }}">Quarter</label>
                    <select id="grading_period_id" name="grading_period_id" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach ($periods as $p)
                        <option value="{{ $p->id }}" {{ (int) old('grading_period_id', $lesson->grading_period_id) === $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="week_number" class="{{ $label }}">Week Number</label>
                    <input id="week_number" type="number" name="week_number" min="1" max="52" value="{{ old('week_number', $lesson->week_number) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="topic" class="{{ $label }}">Topic</label>
                    <input id="topic" type="text" name="topic" value="{{ old('topic', $lesson->topic) }}" class="{{ $input }}">
                </div>
            </div>

            <div>
                <label for="learning_competency" class="{{ $label }}">Learning Competency</label>
                <textarea id="learning_competency" name="learning_competency" rows="2" class="{{ $input }}">{{ old('learning_competency', $lesson->learning_competency) }}</textarea>
            </div>
            <div>
                <label for="objectives" class="{{ $label }}">Objectives</label>
                <textarea id="objectives" name="objectives" rows="2" class="{{ $input }}">{{ old('objectives', $lesson->objectives) }}</textarea>
            </div>
            <div>
                <label for="instructions" class="{{ $label }}">Instructions</label>
                <textarea id="instructions" name="instructions" rows="3" class="{{ $input }}">{{ old('instructions', $lesson->instructions) }}</textarea>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label for="due_at" class="{{ $label }}">Due Date <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input id="due_at" type="datetime-local" name="due_at" value="{{ old('due_at', $lesson->due_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="publish_at" class="{{ $label }}">Publish Date <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input id="publish_at" type="datetime-local" name="publish_at" value="{{ old('publish_at', $lesson->publish_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                </div>
            </div>

            <div>
                <span class="{{ $label }}">Status</span>
                <div class="flex gap-3">
                    <label class="flex items-center gap-2 rounded-xl border-2 px-4 py-2.5 cursor-pointer text-sm font-semibold {{ old('status', $lesson->status) === 'draft' ? 'border-slate-400 bg-slate-50 dark:bg-slate-700 dark:border-slate-500' : 'border-slate-200 dark:border-slate-700' }} text-slate-700 dark:text-slate-200">
                        <input type="radio" name="status" value="draft" class="accent-emerald-600" {{ old('status', $lesson->status) === 'draft' ? 'checked' : '' }}> Draft
                    </label>
                    <label class="flex items-center gap-2 rounded-xl border-2 px-4 py-2.5 cursor-pointer text-sm font-semibold {{ old('status', $lesson->status) === 'published' ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200' }}">
                        <input type="radio" name="status" value="published" class="accent-emerald-600" {{ old('status', $lesson->status) === 'published' ? 'checked' : '' }}> Published
                    </label>
                </div>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-1.5">Publishing notifies every enrolled student immediately.</p>
            </div>

            <button type="submit" :disabled="saving"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white text-sm font-bold px-6 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                <svg x-show="saving" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                <span x-text="saving ? 'Saving…' : '{{ $lesson->exists ? 'Save Lesson' : 'Create Lesson' }}'">{{ $lesson->exists ? 'Save Lesson' : 'Create Lesson' }}</span>
            </button>
        </form>

        @if ($lesson->exists)
        <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-700 flex gap-4">
            @if ($lesson->status === 'published')
            <form method="POST" action="{{ route('teacher.lessons.unpublish', $lesson->id) }}">@csrf
                <button class="text-xs font-bold text-amber-700 dark:text-amber-400 hover:underline">↩ Move back to Draft</button>
            </form>
            @else
            <form method="POST" action="{{ route('teacher.lessons.publish', $lesson->id) }}">@csrf
                <button class="text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:underline">✓ Publish now</button>
            </form>
            @endif
            <form method="POST" action="{{ route('teacher.lessons.destroy', $lesson->id) }}" onsubmit="return confirm('Delete this lesson and all its materials? This cannot be undone.');">
                @csrf @method('DELETE')
                <button class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline">🗑 Delete lesson</button>
            </form>
        </div>
        @endif
    </x-card>

    {{-- Materials manager --}}
    <x-card class="h-fit">
        <h2 class="font-extrabold text-lg mb-1 text-slate-900 dark:text-white">Learning Materials</h2>
        @if (!$lesson->exists)
        <p class="text-sm text-slate-400 dark:text-slate-500 mt-2">Save the lesson first, then add videos, documents, images, and links here.</p>
        @else
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Drag to reorder how students see them.</p>

        <div x-data="materialSorter({{ Illuminate\Support\Js::from($lesson->resources) }}, {{ Illuminate\Support\Js::from(route('teacher.resources.reorder', $lesson->id)) }})" class="space-y-2 mb-5">
            <template x-for="(r, i) in items" :key="r.id">
                <div draggable="true" @dragstart="drag(i)" @dragover.prevent @drop="drop(i)"
                     class="flex items-center gap-2 rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-2 bg-slate-50 dark:bg-slate-700/50 cursor-move">
                    <svg class="w-4 h-4 text-slate-300 dark:text-slate-500 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16"/></svg>
                    <span class="text-lg" aria-hidden="true" x-text="icon(r.type)"></span>
                    <span class="flex-1 min-w-0 text-sm font-semibold truncate text-slate-800 dark:text-slate-100" x-text="r.title"></span>
                    <form :action="{{ Illuminate\Support\Js::from(url('teacher/resources')) }} + '/' + r.id" method="POST" onsubmit="return confirm('Remove this material?');">
                        @csrf @method('DELETE')
                        <button class="text-rose-500 hover:text-rose-700 text-xs font-bold" aria-label="Remove material">✕</button>
                    </form>
                </div>
            </template>
            <template x-if="items.length === 0"><p class="text-sm text-slate-400 dark:text-slate-500 text-center py-4">No materials added yet.</p></template>
        </div>

        <div x-data="{ tab: 'video' }" class="border-t border-slate-100 dark:border-slate-700 pt-4">
            <div class="flex gap-1 mb-3 text-xs font-bold" role="tablist">
                @foreach (['video' => 'Video', 'document' => 'Document', 'image' => 'Image', 'link' => 'Link'] as $t => $lbl)
                <button type="button" @click="tab='{{ $t }}'" role="tab" :aria-selected="tab==='{{ $t }}'"
                        :class="tab==='{{ $t }}' ? 'bg-emerald-600 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300'" class="rounded-lg px-3 py-1.5">{{ $lbl }}</button>
                @endforeach
            </div>

            @php $minput = 'w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-2 text-xs'; $mbtn = 'w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2'; @endphp

            {{-- Video --}}
            <div x-show="tab==='video'" x-cloak x-data="{ vtype: 'video_youtube' }" class="space-y-2">
                <select x-model="vtype" class="{{ $minput }}" aria-label="Video source">
                    <option value="video_youtube">YouTube link</option>
                    <option value="video_vimeo">Vimeo link</option>
                    <option value="video_gdrive">Google Drive link</option>
                    <option value="video_upload">Upload MP4</option>
                </select>
                <form method="POST" action="{{ route('teacher.resources.store', $lesson->id) }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf<input type="hidden" name="type" :value="vtype">
                    <input type="text" name="title" required placeholder="Title (e.g. Intro Video)" class="{{ $minput }}">
                    <input x-show="vtype !== 'video_upload'" type="url" name="url" placeholder="Paste the video URL" class="{{ $minput }}">
                    <input x-show="vtype === 'video_upload'" type="file" name="file" accept="video/mp4" class="w-full text-xs text-slate-600 dark:text-slate-300">
                    <button class="{{ $mbtn }}">Add Video</button>
                </form>
            </div>
            {{-- Document --}}
            <div x-show="tab==='document'" x-cloak>
                <form method="POST" action="{{ route('teacher.resources.store', $lesson->id) }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf<input type="hidden" name="type" value="document">
                    <input type="text" name="title" required placeholder="Title" class="{{ $minput }}">
                    <input type="file" name="file" accept=".pdf,.docx,.ppt,.pptx,.xlsx,.txt" required class="w-full text-xs text-slate-600 dark:text-slate-300">
                    <p class="text-[10px] text-slate-400 dark:text-slate-500">PDF, DOCX, PPT, PPTX, XLSX, TXT — up to 25MB.</p>
                    <button class="{{ $mbtn }}">Add Document</button>
                </form>
            </div>
            {{-- Image --}}
            <div x-show="tab==='image'" x-cloak>
                <form method="POST" action="{{ route('teacher.resources.store', $lesson->id) }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf<input type="hidden" name="type" value="image">
                    <input type="text" name="title" required placeholder="Title" class="{{ $minput }}">
                    <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required class="w-full text-xs text-slate-600 dark:text-slate-300">
                    <p class="text-[10px] text-slate-400 dark:text-slate-500">JPG, PNG, WEBP — up to 10MB.</p>
                    <button class="{{ $mbtn }}">Add Image</button>
                </form>
            </div>
            {{-- Link --}}
            <div x-show="tab==='link'" x-cloak>
                <form method="POST" action="{{ route('teacher.resources.store', $lesson->id) }}" class="space-y-2">
                    @csrf<input type="hidden" name="type" value="link">
                    <input type="text" name="title" required placeholder="Title" class="{{ $minput }}">
                    <input type="url" name="url" required placeholder="https://…" class="{{ $minput }}">
                    <button class="{{ $mbtn }}">Add Link</button>
                </form>
            </div>
        </div>
        @endif
    </x-card>
</div>

<script>
function materialSorter(initial, reorderUrl) {
    return {
        items: initial,
        dragIndex: null,
        drag(i) { this.dragIndex = i; },
        drop(i) {
            if (this.dragIndex === null || this.dragIndex === i) return;
            const moved = this.items.splice(this.dragIndex, 1)[0];
            this.items.splice(i, 0, moved);
            this.dragIndex = null;
            fetch(reorderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ order: this.items.map(r => r.id) }),
            });
        },
        icon(type) { return { video_upload:'🎬', video_youtube:'🎬', video_vimeo:'🎬', video_gdrive:'🎬', document:'📄', image:'🖼️', link:'🔗' }[type] || '📎'; },
    };
}
</script>
@endsection
