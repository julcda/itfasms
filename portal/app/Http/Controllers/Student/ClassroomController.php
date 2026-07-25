<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Classroom\Lesson;
use App\Models\Classroom\LessonProgress;
use App\Models\Legacy\SchoolClass;
use App\Models\Legacy\StudentClass;
use App\Services\Portal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function __construct(private Portal $portal) {}

    /** Resolve the masterlist student_id this session maps to, or bounce home. */
    private function studentInfoId(Request $request): int
    {
        [$profile] = $this->profile($request, $this->portal);
        $sy = $this->portal->activeSy();
        $id = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);
        if ($id <= 0) {
            throw new HttpResponseException(
                redirect()->route('dashboard')->with('error', 'Your class records could not be found. Please contact the Registrar.')
            );
        }
        return $id;
    }

    /**
     * AUTHORIZATION PRIMITIVE (student side) — mirrors the teacher's
     * ownedClass(): a class is only ever reachable if the student is on its
     * roster (student_classes), resolved from the SESSION, never the URL.
     */
    private function enrolledClass(int $studentInfoId, int $classId): SchoolClass
    {
        $onRoster = StudentClass::where('class_id', $classId)->where('student_id', $studentInfoId)->exists();
        if (!$onRoster) {
            throw new HttpResponseException(
                redirect()->route('student.classes.index')->with('error', 'You are not enrolled in that class.')
            );
        }
        return SchoolClass::with(['subject', 'section', 'teacher'])->findOrFail($classId);
    }

    public function index(Request $request)
    {
        $studentInfoId = $this->studentInfoId($request);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);

        $classIds = StudentClass::where('student_id', $studentInfoId)->pluck('class_id');
        $classes = SchoolClass::with(['subject', 'section', 'teacher'])
            ->whereIn('Class_id', $classIds)
            ->get()
            ->map(function (SchoolClass $c) {
                $c->setAttribute('published_lesson_count', Lesson::where('class_id', $c->Class_id)->where('status', 'published')->count());
                return $c;
            });

        return view('classroom.index', compact('profile', 'photoUrl', 'classes'));
    }

    public function show(Request $request, int $classId)
    {
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $studentInfoId = $this->studentInfoId($request);
        $class = $this->enrolledClass($studentInfoId, $classId);

        $lessons = Lesson::where('class_id', $classId)
            ->where('status', 'published')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $progress = LessonProgress::whereIn('lesson_id', $lessons->pluck('id'))
            ->where('student_id', $studentInfoId)
            ->get()->keyBy('lesson_id');

        $counts = [
            'assignments'   => \App\Models\Classroom\Assignment::where('class_id', $classId)->where('status', 'published')->count(),
            'quizzes'       => \App\Models\Classroom\Quiz::where('class_id', $classId)->where('status', 'published')->count(),
            'announcements' => \App\Models\Classroom\Announcement::where('class_id', $classId)->count(),
            'discussion'    => \App\Models\Classroom\DiscussionThread::where('class_id', $classId)->count(),
        ];

        return view('classroom.show', compact('profile', 'photoUrl', 'class', 'lessons', 'progress', 'counts'));
    }

    public function lesson(Request $request, int $lessonId)
    {
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $studentInfoId = $this->studentInfoId($request);

        $lesson = Lesson::where('id', $lessonId)->where('status', 'published')->firstOrFail();
        $class = $this->enrolledClass($studentInfoId, (int) $lesson->class_id); // authorizes

        $lesson->load('resources');

        $progress = LessonProgress::firstOrCreate(
            ['lesson_id' => $lesson->id, 'student_id' => $studentInfoId],
            ['status' => 'not_started']
        );
        if ($progress->status === 'not_started') {
            $progress->update(['status' => 'in_progress', 'last_viewed_at' => now()]);
        } else {
            $progress->update(['last_viewed_at' => now()]);
        }

        // Simple prev/next within the class's published lesson order.
        $siblings = Lesson::where('class_id', $class->Class_id)->where('status', 'published')
            ->orderBy('sort_order')->orderBy('id')->pluck('id')->values();
        $pos = $siblings->search($lesson->id);
        $prevId = $pos !== false && $pos > 0 ? $siblings[$pos - 1] : null;
        $nextId = $pos !== false && $pos < $siblings->count() - 1 ? $siblings[$pos + 1] : null;

        return view('classroom.lesson', compact('profile', 'photoUrl', 'class', 'lesson', 'progress', 'prevId', 'nextId'));
    }

    public function markComplete(Request $request, int $lessonId)
    {
        $studentInfoId = $this->studentInfoId($request);
        $lesson = Lesson::where('id', $lessonId)->where('status', 'published')->firstOrFail();
        $this->enrolledClass($studentInfoId, (int) $lesson->class_id); // authorizes

        LessonProgress::updateOrCreate(
            ['lesson_id' => $lesson->id, 'student_id' => $studentInfoId],
            ['status' => 'completed', 'progress_percent' => 100, 'completed_at' => now()]
        );

        return back()->with('success', 'Marked as completed.');
    }

    public function markViewed(Request $request, int $lessonId)
    {
        $studentInfoId = $this->studentInfoId($request);
        $lesson = Lesson::where('id', $lessonId)->where('status', 'published')->firstOrFail();
        $this->enrolledClass($studentInfoId, (int) $lesson->class_id);

        $pct = max(0, min(100, (int) $request->input('percent', 0)));
        $progress = LessonProgress::firstOrCreate(['lesson_id' => $lesson->id, 'student_id' => $studentInfoId]);
        if ($progress->status !== 'completed') {
            $progress->update([
                'status' => $pct >= 90 ? 'completed' : 'in_progress',
                'progress_percent' => max($progress->progress_percent, $pct),
                'last_viewed_at' => now(),
                'completed_at' => $pct >= 90 ? now() : null,
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
