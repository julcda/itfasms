@extends('teacher.layout')
@section('title', 'Discussion')

@section('content')
@php $tab = 'discussion'; @endphp
@include('teacher.partials.workspace_tabs')

<x-card class="mb-6">
    <h2 class="font-extrabold text-lg text-slate-900 dark:text-white mb-3">Start a discussion</h2>
    <form method="POST" action="{{ route('teacher.discussions.threads.store', $class->Class_id) }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <input type="text" name="title" required placeholder="Topic / question title" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm">
        <textarea name="body" rows="3" required placeholder="What would you like to discuss?" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm"></textarea>
        <div class="flex items-center gap-3">
            <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5">Post Discussion</button>
            <label class="text-sm text-slate-500 dark:text-slate-400 cursor-pointer">📷 Attach image <input type="file" name="image" accept="image/*" class="hidden"></label>
        </div>
    </form>
</x-card>

@include('classroom.partials.discussion_threads', ['isTeacher' => true])
@endsection
