@php
    $tabs = [
        'stream'       => ['Stream', route('teacher.classes.stream', $class->Class_id)],
        'lessons'      => ['Lessons', route('teacher.lessons.index', $class->Class_id)],
        'materials'    => ['Materials', route('teacher.materials.index', $class->Class_id)],
        'assignments'  => ['Assignments', route('teacher.assignments.index', $class->Class_id)],
        'quizzes'      => ['Quizzes', route('teacher.quizzes.index', $class->Class_id)],
        'discussion'   => ['Discussion', route('teacher.discussions.index', $class->Class_id)],
        'students'     => ['Students', route('teacher.classes.students', $class->Class_id)],
        'grades'       => ['Grades', route('teacher.gradebook.index', $class->Class_id)],
        'announcements'=> ['Announcements', route('teacher.announcements.index', $class->Class_id)],
    ];
    $clsName = $class->subject->Subject_name ?? 'Class';
@endphp

<x-breadcrumbs :items="[
    ['label' => 'My Classes', 'url' => route('teacher.dashboard')],
    ['label' => $clsName . ' · ' . ($class->section->Section_name ?? ''), 'url' => null],
]" />

<x-page-header :eyebrow="'Class Workspace'" :title="$clsName"
               :subtitle="($class->gradeLevel->Gradelevel ?? '') . ' — ' . ($class->section->Section_name ?? '')" />

<div class="bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm rounded-2xl border border-white/60 dark:border-slate-700/60 ring-1 ring-slate-900/[0.03] dark:ring-white/[0.04] shadow-panel p-2 mb-6 overflow-x-auto">
    <div class="flex gap-1 min-w-max" role="tablist">
        @foreach ($tabs as $key => [$label, $url])
        <a href="{{ $url }}" role="tab" @if($tab === $key) aria-selected="true" aria-current="page" @endif
           @class([
                'rounded-xl px-4 py-2 text-sm font-bold whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                'bg-emerald-600 text-white shadow-sm' => $tab === $key,
                'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700' => $tab !== $key,
           ])>{{ $label }}</a>
        @endforeach
    </div>
</div>
