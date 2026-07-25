<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Classroom\Lesson;
use App\Models\Classroom\Notification;
use App\Models\Legacy\SchoolClass;
use App\Models\Legacy\SchoolYear;
use App\Models\Legacy\StudentClass;
use App\Models\Legacy\TeacherModel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

abstract class TeacherController extends Controller
{
    protected function teacher(Request $request): TeacherModel
    {
        return $request->attributes->get('teacher');
    }

    protected function activeSy(): SchoolYear
    {
        return SchoolYear::where('Status', 1)->orderByDesc('School_year_id')->firstOrFail();
    }

    /**
     * AUTHORIZATION PRIMITIVE — mirrors the native teacher_owns_class(): every
     * class-scoped page/action calls this BEFORE reading or writing anything.
     * Ownership is derived from classes.Teacher_id against the teacher resolved
     * from the SESSION — never from a route/form value. Fails closed.
     */
    protected function ownedClass(Request $request, int $classId): SchoolClass
    {
        $class = SchoolClass::where('Class_id', $classId)
            ->where('Teacher_id', $this->teacher($request)->Teacher_id)
            ->first();

        if (!$class) {
            throw new HttpResponseException(
                redirect()->route('teacher.dashboard')->with('error', 'That class is not assigned to you.')
            );
        }

        return $class;
    }

    /** Same primitive, one hop further: a lesson is owned via its class. */
    protected function ownedLesson(Request $request, int $lessonId): Lesson
    {
        $lesson = Lesson::find($lessonId);
        if (!$lesson) {
            throw new HttpResponseException(
                redirect()->route('teacher.dashboard')->with('error', 'Lesson not found.')
            );
        }
        $this->ownedClass($request, (int) $lesson->class_id); // throws if not owned
        return $lesson;
    }

    /** Fan a notification out to every enrolled student of a class. */
    protected function notifyClass(int $classId, string $type, string $title, ?string $body, string $link, array $data = []): void
    {
        $studentIds = StudentClass::where('class_id', $classId)->pluck('student_id')->filter()->unique()->values();
        if ($studentIds->isEmpty()) {
            return;
        }
        Notification::fanOut([
            'type' => $type, 'title' => $title, 'body' => $body, 'link' => $link,
            'data' => $data + ['class_id' => $classId], 'class_id' => $classId,
        ], $studentIds->map(fn ($id) => ['role' => 'student', 'id' => (int) $id])->all());
    }
}
