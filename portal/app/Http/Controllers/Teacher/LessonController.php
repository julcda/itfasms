<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\Lesson;
use App\Models\Classroom\LessonResource;
use App\Models\Classroom\Notification;
use App\Models\Legacy\GradingPeriod;
use App\Models\Legacy\SchoolClass;
use App\Models\Legacy\StudentClass;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LessonController extends TeacherController
{
    public function index(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $lessons = Lesson::where('class_id', $classId)
            ->withCount('resources')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('teacher.workspace', [
            'tab' => 'lessons', 'class' => $class, 'lessons' => $lessons,
        ]);
    }

    /** The "Learning Materials" tab — a flat, browsable library across all lessons in the class. */
    public function materials(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $resources = LessonResource::with('lesson')
            ->whereHas('lesson', fn ($q) => $q->where('class_id', $classId))
            ->orderByDesc('id')
            ->get();

        return view('teacher.workspace', [
            'tab' => 'materials', 'class' => $class, 'resources' => $resources,
        ]);
    }

    public function create(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $periods = GradingPeriod::where('school_year_id', $class->School_year_id)->orderBy('term_no')->get();

        return view('teacher.lessons.form', [
            'class' => $class, 'lesson' => new Lesson(['class_id' => $classId]), 'periods' => $periods,
        ]);
    }

    public function store(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $data = $this->validated($request);

        $data['class_id']   = $classId;
        $data['created_by'] = $this->teacher($request)->user_id;
        $data['sort_order'] = (int) (Lesson::where('class_id', $classId)->max('sort_order') ?? 0) + 1;

        $lesson = Lesson::create($data);

        if ($lesson->status === 'published') {
            $this->notifyPublished($lesson, $classId);
        }

        return redirect()->route('teacher.lessons.edit', $lesson->id)->with('success', 'Lesson created. Now add learning materials below.');
    }

    public function edit(Request $request, int $lessonId)
    {
        $lesson = $this->ownedLesson($request, $lessonId);
        $class  = $lesson->schoolClass;
        $periods = GradingPeriod::where('school_year_id', $class->School_year_id)->orderBy('term_no')->get();
        $lesson->load('resources');

        return view('teacher.lessons.form', compact('class', 'lesson', 'periods'));
    }

    public function update(Request $request, int $lessonId)
    {
        $lesson = $this->ownedLesson($request, $lessonId);
        $wasPublished = $lesson->status === 'published';

        $lesson->update($this->validated($request));

        if (!$wasPublished && $lesson->status === 'published') {
            $this->notifyPublished($lesson, (int) $lesson->class_id);
        }

        return redirect()->route('teacher.lessons.edit', $lesson->id)->with('success', 'Lesson saved.');
    }

    public function publish(Request $request, int $lessonId)
    {
        $lesson = $this->ownedLesson($request, $lessonId);
        $wasPublished = $lesson->status === 'published';
        $lesson->update(['status' => 'published', 'publish_at' => $lesson->publish_at ?? now()]);
        if (!$wasPublished) {
            $this->notifyPublished($lesson, (int) $lesson->class_id);
        }
        return back()->with('success', 'Lesson published — students can now see it.');
    }

    public function unpublish(Request $request, int $lessonId)
    {
        $lesson = $this->ownedLesson($request, $lessonId);
        $lesson->update(['status' => 'draft']);
        return back()->with('success', 'Lesson moved back to draft — hidden from students.');
    }

    public function destroy(Request $request, int $lessonId)
    {
        $lesson = $this->ownedLesson($request, $lessonId);
        $classId = (int) $lesson->class_id;
        foreach ($lesson->resources as $resource) {
            $this->deleteResourceFile($resource);
        }
        $lesson->delete(); // cascades resources/progress rows via FK
        return redirect()->route('teacher.lessons.index', $classId)->with('success', 'Lesson deleted.');
    }

    public function reorder(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $ids = (array) $request->input('order', []);
        foreach ($ids as $i => $id) {
            Lesson::where('id', (int) $id)->where('class_id', $classId)->update(['sort_order' => $i]);
        }
        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string'],
            'grading_period_id'   => ['nullable', 'integer'],
            'week_number'         => ['nullable', 'integer', 'min:1', 'max:52'],
            'topic'               => ['nullable', 'string', 'max:255'],
            'learning_competency' => ['nullable', 'string'],
            'objectives'          => ['nullable', 'string'],
            'instructions'        => ['nullable', 'string'],
            'status'              => ['required', Rule::in(['draft', 'published'])],
            'publish_at'          => ['nullable', 'date'],
            'due_at'              => ['nullable', 'date'],
        ]);
    }

    private function deleteResourceFile(LessonResource $resource): void
    {
        if (!$resource->file_path) {
            return;
        }
        $full = rtrim((string) config('portal.lms_uploads_path'), '/\\') . '/' . $resource->file_path;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    /** Fan-out a "new lesson published" notification to every enrolled student. */
    private function notifyPublished(Lesson $lesson, int $classId): void
    {
        $studentIds = SchoolClass::rosterIdsFor($classId)->filter()->values();
        if ($studentIds->isEmpty()) {
            return;
        }
        Notification::fanOut([
            'type'     => 'lesson_published',
            'title'    => 'New lesson: ' . $lesson->title,
            'body'     => $lesson->description,
            'link'     => route('student.lessons.show', $lesson->id),
            'data'     => ['lesson_id' => $lesson->id, 'class_id' => $classId],
            'class_id' => $classId,
        ], $studentIds->map(fn ($id) => ['role' => 'student', 'id' => (int) $id])->all());
    }
}
