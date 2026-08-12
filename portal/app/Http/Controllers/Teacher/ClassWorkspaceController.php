<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\Lesson;
use App\Models\Legacy\StudentClass;
use Illuminate\Http\Request;

class ClassWorkspaceController extends TeacherController
{
    /** The Stream tab — recent activity (published lessons, newest first). */
    public function stream(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);

        $recent = Lesson::where('class_id', $classId)
            ->where('status', 'published')
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('teacher.workspace', [
            'tab' => 'stream', 'class' => $class, 'recent' => $recent,
        ]);
    }

    /** The Students tab — roster from the existing masterlist, read-only here. */
    public function students(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);

        $roster = $class->rosterStudents();

        return view('teacher.workspace', [
            'tab' => 'students', 'class' => $class, 'roster' => $roster,
        ]);
    }

    /** Assignments/Quizzes/Grades/Announcements — real UI ships in later phases. */
    public function comingSoon(Request $request, int $classId, string $tab)
    {
        $class = $this->ownedClass($request, $classId);
        return view('teacher.workspace', ['tab' => $tab, 'class' => $class]);
    }
}
