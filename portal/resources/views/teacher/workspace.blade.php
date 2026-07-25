@extends('teacher.layout')
@section('title', ($class->subject->Subject_name ?? 'Class') . ' Workspace')

@section('content')
@include('teacher.partials.workspace_tabs')

@if ($tab === 'stream')
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        @php
            $quick = [
                ['New Lesson', route('teacher.lessons.create', $class->Class_id), '📘', 'emerald'],
                ['New Assignment', route('teacher.assignments.create', $class->Class_id), '📝', 'sky'],
                ['New Quiz', route('teacher.quizzes.create', $class->Class_id), '🧠', 'violet'],
                ['View Analytics', route('teacher.analytics.index', $class->Class_id), '📊', 'gold'],
            ];
        @endphp
        @foreach ($quick as [$label, $url, $icon, $c])
        <a href="{{ $url }}" class="lift bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm rounded-2xl border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel p-4 flex items-center gap-3 hover:shadow-lift">
            <span class="w-10 h-10 rounded-xl bg-{{ $c }}-100 dark:bg-{{ $c }}-500/15 flex items-center justify-center text-lg shrink-0">{{ $icon }}</span>
            <span class="font-bold text-sm text-slate-800 dark:text-slate-100">{{ $label }}</span>
        </a>
        @endforeach
    </div>
    <x-card>
        <h2 class="font-extrabold text-lg mb-4 text-slate-900 dark:text-white">Recent Activity</h2>
        @forelse ($recent as $lesson)
        <a href="{{ route('teacher.lessons.edit', $lesson->id) }}" class="block px-4 py-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/40 border-b border-slate-50 dark:border-slate-700/50 last:border-0">
            <p class="font-semibold text-sm text-slate-800 dark:text-slate-100">📘 {{ $lesson->title }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Published {{ $lesson->publish_at?->diffForHumans() ?? $lesson->created_at->diffForHumans() }}</p>
        </a>
        @empty
        <x-empty-state icon="🗂️" title="Nothing published yet" message="Create your first lesson to start building this class." class="border-0 shadow-none bg-transparent dark:bg-transparent p-6">
            <x-slot:action>
                <a href="{{ route('teacher.lessons.create', $class->Class_id) }}" class="inline-block rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">+ Create your first lesson</a>
            </x-slot:action>
        </x-empty-state>
        @endforelse
    </x-card>

@elseif ($tab === 'lessons')
    <div x-data="{ q: '' }">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="relative flex-1 min-w-[220px] max-w-md">
                <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                <input type="text" x-model="q" placeholder="Search lessons…" aria-label="Search lessons"
                       class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 pl-10 pr-4 py-2.5 text-sm text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <a href="{{ route('teacher.lessons.create', $class->Class_id) }}" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">+ New Lesson</a>
        </div>

        <x-card pad="p-0" class="overflow-hidden">
            @forelse ($lessons as $lesson)
            <a href="{{ route('teacher.lessons.edit', $lesson->id) }}"
               x-show="q === '' || {{ Illuminate\Support\Js::from(strtolower($lesson->title . ' ' . $lesson->topic)) }}.includes(q.toLowerCase())"
               class="flex items-center gap-4 px-6 py-4 border-b border-slate-50 dark:border-slate-700/50 last:border-0 hover:bg-emerald-50/30 dark:hover:bg-slate-700/40">
                <x-badge :color="$lesson->status === 'published' ? 'emerald' : 'slate'">{{ strtoupper($lesson->status) }}</x-badge>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm truncate text-slate-800 dark:text-slate-100">{{ $lesson->title }}</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">{{ $lesson->topic }} · {{ $lesson->resources_count }} material{{ $lesson->resources_count === 1 ? '' : 's' }}</p>
                </div>
                <svg class="w-4 h-4 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
            @empty
            <x-empty-state icon="📘" title="No lessons yet" message="Click “New Lesson” to add your first one." class="border-0 shadow-none" />
            @endforelse
        </x-card>
    </div>

@elseif ($tab === 'materials')
    <div x-data="{ q: '' }">
        <div class="relative max-w-md mb-4">
            <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
            <input type="text" x-model="q" placeholder="Search materials…" aria-label="Search materials"
                   class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 pl-10 pr-4 py-2.5 text-sm text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
        </div>
        <x-card pad="p-0" class="overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700"><p class="text-sm text-slate-500 dark:text-slate-400">Every file/link across all lessons, newest first.</p></div>
            @forelse ($resources as $r)
            <a href="{{ route('teacher.lessons.edit', $r->lesson_id) }}"
               x-show="q === '' || {{ Illuminate\Support\Js::from(strtolower($r->title . ' ' . ($r->lesson->title ?? ''))) }}.includes(q.toLowerCase())"
               class="flex items-center gap-4 px-6 py-3 border-b border-slate-50 dark:border-slate-700/50 last:border-0 hover:bg-emerald-50/30 dark:hover:bg-slate-700/40">
                <span class="text-xl" aria-hidden="true">{{ ['video_upload'=>'🎬','video_youtube'=>'🎬','video_vimeo'=>'🎬','video_gdrive'=>'🎬','document'=>'📄','image'=>'🖼️','link'=>'🔗'][$r->type] ?? '📎' }}</span>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm truncate text-slate-800 dark:text-slate-100">{{ $r->title }}</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">from “{{ $r->lesson->title ?? '—' }}”</p>
                </div>
            </a>
            @empty
            <x-empty-state icon="🗃️" title="No materials yet" message="Add videos, documents, images, or links from inside a lesson." class="border-0 shadow-none" />
            @endforelse
        </x-card>
    </div>

@elseif ($tab === 'students')
    <div x-data="{ q: '' }">
        <div class="relative max-w-md mb-4">
            <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
            <input type="text" x-model="q" placeholder="Search students…" aria-label="Search students"
                   class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 pl-10 pr-4 py-2.5 text-sm text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
        </div>
        <x-card pad="p-0" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-700/50 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <tr><th scope="col" class="text-left px-6 py-3 w-10">#</th><th scope="col" class="text-left">LRN</th><th scope="col" class="text-left">Student Name</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                    @forelse ($roster as $i => $s)
                        <tr x-show="q === '' || {{ Illuminate\Support\Js::from(strtolower($s->fullName())) }}.includes(q.toLowerCase())">
                            <td class="px-6 py-2.5 text-slate-400 dark:text-slate-500">{{ $i + 1 }}</td>
                            <td class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $s->LRN_no ?: '—' }}</td>
                            <td class="font-semibold text-slate-800 dark:text-slate-100">{{ $s->fullName() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-10 text-center text-slate-400 dark:text-slate-500">No students enrolled in this class yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

@endif
@endsection
