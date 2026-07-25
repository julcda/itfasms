<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Legacy\SchoolClass;
use Illuminate\Http\Request;

class DashboardController extends TeacherController
{
    public function index(Request $request)
    {
        $teacher = $this->teacher($request);
        $sy = $this->activeSy();

        $classes = SchoolClass::with(['subject', 'section.gradeLevel'])
            ->where('Teacher_id', $teacher->Teacher_id)
            ->where('School_year_id', $sy->School_year_id)
            ->get()
            ->map(function (SchoolClass $c) {
                $c->setAttribute('student_count', $c->studentClasses()->count());
                $c->setAttribute('lesson_count', \App\Models\Classroom\Lesson::where('class_id', $c->Class_id)->count());
                return $c;
            })
            ->sortBy([
                fn ($c) => (int) ($c->section->gradeLevel->Gradelevel_id ?? 0),
                fn ($c) => (string) ($c->section->Section_name ?? ''),
            ])
            ->values();

        return view('teacher.dashboard', [
            'teacher' => $teacher,
            'sy'      => $sy,
            'classes' => $classes,
        ]);
    }
}
