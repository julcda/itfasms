@extends('layouts.app')
@section('title', 'Discussion')

@section('content')
<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('student.classes.index')],
    ['label' => $class->subject->Subject_name ?? 'Class', 'url' => route('student.classes.show', $class->Class_id)],
    ['label' => 'Discussion', 'url' => null],
]" />
<x-page-header accent="green" eyebrow="{{ $class->subject->Subject_name ?? '' }}" title="Discussion Board"
               subtitle="Ask questions and share ideas with your class and teacher." />

<x-card class="mb-6">
    <form method="POST" action="{{ route('student.discussions.threads.store', $class->Class_id) }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <input type="text" name="title" required placeholder="Your question or topic" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm">
        <textarea name="body" rows="2" required placeholder="Add details…" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm"></textarea>
        <div class="flex items-center gap-3">
            <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-6 py-2.5">Post</button>
            <label class="text-sm text-slate-500 dark:text-slate-400 cursor-pointer">📷 Image <input type="file" name="image" accept="image/*" class="hidden"></label>
        </div>
    </form>
</x-card>

@include('classroom.partials.discussion_threads', ['isTeacher' => false])
@endsection
