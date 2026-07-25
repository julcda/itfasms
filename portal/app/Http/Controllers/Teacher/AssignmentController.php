<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\Assignment;
use App\Models\Classroom\AssignmentAttachment;
use App\Models\Classroom\AssignmentSubmission;
use App\Models\Classroom\GradeIntegration;
use App\Models\Legacy\GradingPeriod;
use App\Models\Legacy\StudentClass;
use App\Models\Legacy\StudentInfo;
use App\Support\Uploads;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentController extends TeacherController
{
    private function ownedAssignment(Request $request, int $id): Assignment
    {
        $a = Assignment::find($id);
        if (!$a) {
            throw new HttpResponseException(redirect()->route('teacher.dashboard')->with('error', 'Assignment not found.'));
        }
        $this->ownedClass($request, (int) $a->class_id);
        return $a;
    }

    public function index(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $rosterCount = StudentClass::where('class_id', $classId)->count();
        $assignments = Assignment::where('class_id', $classId)
            ->withCount(['submissions as submitted_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late', 'graded'])])
            ->orderByDesc('id')->get();

        return view('teacher.assignments.index', compact('class', 'assignments', 'rosterCount'));
    }

    public function create(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        return view('teacher.assignments.form', ['class' => $class, 'assignment' => new Assignment(['class_id' => $classId, 'max_score' => 100, 'allow_late' => true, 'require_file' => true]), 'periods' => $this->periods($class)]);
    }

    public function store(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $data = $this->validated($request);
        $data['class_id'] = $classId;
        $data['created_by'] = $this->teacher($request)->user_id;

        $assignment = Assignment::create($data);
        $this->storeAttachments($request, $assignment);

        if ($assignment->status === 'published') {
            $this->notifyClass($classId, 'assignment_published', 'New assignment: ' . $assignment->title, $assignment->description, route('student.assignments.show', $assignment->id), ['assignment_id' => $assignment->id]);
        }
        return redirect()->route('teacher.assignments.edit', $assignment->id)->with('success', 'Assignment created.');
    }

    public function edit(Request $request, int $id)
    {
        $assignment = $this->ownedAssignment($request, $id);
        $assignment->load('attachments');
        return view('teacher.assignments.form', ['class' => $assignment->schoolClass, 'assignment' => $assignment, 'periods' => $this->periods($assignment->schoolClass)]);
    }

    public function update(Request $request, int $id)
    {
        $assignment = $this->ownedAssignment($request, $id);
        $was = $assignment->status;
        $assignment->update($this->validated($request));
        $this->storeAttachments($request, $assignment);

        if ($was !== 'published' && $assignment->status === 'published') {
            $this->notifyClass((int) $assignment->class_id, 'assignment_published', 'New assignment: ' . $assignment->title, $assignment->description, route('student.assignments.show', $assignment->id), ['assignment_id' => $assignment->id]);
        }
        return back()->with('success', 'Assignment saved.');
    }

    public function publish(Request $request, int $id)
    {
        $assignment = $this->ownedAssignment($request, $id);
        $was = $assignment->status;
        $assignment->update(['status' => 'published']);
        if ($was !== 'published') {
            $this->notifyClass((int) $assignment->class_id, 'assignment_published', 'New assignment: ' . $assignment->title, $assignment->description, route('student.assignments.show', $assignment->id), ['assignment_id' => $assignment->id]);
        }
        return back()->with('success', 'Assignment published.');
    }

    public function unpublish(Request $request, int $id)
    {
        $assignment = $this->ownedAssignment($request, $id);
        $assignment->update(['status' => 'draft']);
        return back()->with('success', 'Assignment moved to draft.');
    }

    public function destroy(Request $request, int $id)
    {
        $assignment = $this->ownedAssignment($request, $id);
        $classId = (int) $assignment->class_id;
        foreach ($assignment->attachments as $att) { Uploads::delete($att->file_path); }
        foreach ($assignment->submissions()->with('files')->get() as $sub) {
            foreach ($sub->files as $f) { Uploads::delete($f->file_path); }
        }
        GradeIntegration::where('source_type', 'assignment')->where('source_id', $assignment->id)->delete();
        $assignment->delete();
        return redirect()->route('teacher.assignments.index', $classId)->with('success', 'Assignment deleted.');
    }

    public function destroyAttachment(Request $request, int $attachmentId)
    {
        $att = AssignmentAttachment::findOrFail($attachmentId);
        $this->ownedAssignment($request, (int) $att->assignment_id);
        Uploads::delete($att->file_path);
        $att->delete();
        return back()->with('success', 'Attachment removed.');
    }

    /** Grading queue: every submission + who hasn't turned in. */
    public function submissions(Request $request, int $id)
    {
        $assignment = $this->ownedAssignment($request, $id);
        $roster = StudentClass::where('class_id', $assignment->class_id)->pluck('student_id')->filter()->values();
        $students = StudentInfo::whereIn('student_id', $roster)->get()->keyBy('student_id');
        $subs = AssignmentSubmission::with('files')->where('assignment_id', $id)->get()->keyBy('student_id');

        // Build a row per rostered student.
        $rows = $roster->map(fn ($sid) => [
            'student' => $students->get($sid),
            'submission' => $subs->get($sid),
        ])->filter(fn ($r) => $r['student'])->sortBy(fn ($r) => $r['student']->Lastname . $r['student']->Firstname)->values();

        return view('teacher.assignments.submissions', compact('assignment', 'rows'));
    }

    public function gradeSubmission(Request $request, int $submissionId)
    {
        $sub = AssignmentSubmission::findOrFail($submissionId);
        $assignment = $this->ownedAssignment($request, (int) $sub->assignment_id);

        $action = (string) $request->input('action', 'grade');
        if ($action === 'return') {
            $sub->update(['status' => 'returned', 'teacher_comment' => (string) $request->input('teacher_comment', ''), 'graded_by' => $this->teacher($request)->user_id, 'graded_at' => now()]);
            return back()->with('success', 'Returned to student for revision.');
        }
        if ($action === 'missing') {
            $sub->update(['status' => 'missing', 'score' => 0, 'graded_by' => $this->teacher($request)->user_id, 'graded_at' => now()]);
            $this->syncGrade($assignment, $sub);
            return back()->with('success', 'Marked as missing (0).');
        }

        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:' . $assignment->max_score],
            'teacher_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $sub->update([
            'score' => $data['score'],
            'teacher_comment' => $data['teacher_comment'] ?? null,
            'status' => 'graded',
            'graded_by' => $this->teacher($request)->user_id,
            'graded_at' => now(),
        ]);
        $this->syncGrade($assignment, $sub);
        $this->notifyStudent((int) $sub->student_id, 'assignment_graded', 'Assignment graded: ' . $assignment->title, 'You scored ' . rtrim(rtrim(number_format((float) $sub->score, 2), '0'), '.') . ' / ' . $assignment->max_score, route('student.assignments.show', $assignment->id), (int) $assignment->class_id);

        return back()->with('success', 'Graded.');
    }

    private function syncGrade(Assignment $assignment, AssignmentSubmission $sub): void
    {
        GradeIntegration::updateOrCreate(
            ['source_type' => 'assignment', 'source_id' => $assignment->id, 'student_id' => $sub->student_id],
            ['class_id' => $assignment->class_id, 'grading_period_id' => 0, 'score' => (float) $sub->score, 'max_score' => (float) $assignment->max_score]
        );
    }

    private function periods($class)
    {
        return GradingPeriod::where('school_year_id', $class->School_year_id)->orderBy('term_no')->get();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'max_score' => ['required', 'integer', 'min:1', 'max:1000'],
            'allow_late' => ['nullable', 'boolean'],
            'submission_mode' => ['required', Rule::in(['individual', 'group'])],
            'require_file' => ['nullable', 'boolean'],
            'require_text' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]) + [
            'allow_late' => $request->boolean('allow_late'),
            'require_file' => $request->boolean('require_file'),
            'require_text' => $request->boolean('require_text'),
        ];
    }

    private function storeAttachments(Request $request, Assignment $assignment): void
    {
        foreach ((array) $request->file('attachments', []) as $file) {
            if (!$file) { continue; }
            try {
                $meta = Uploads::store($file, 'classroom_assignments', array_merge(Uploads::DOC_EXT, Uploads::IMAGE_EXT, Uploads::ARCHIVE_EXT), 25 * 1024 * 1024);
                $assignment->attachments()->create($meta);
            } catch (\RuntimeException $e) {
                throw new HttpResponseException(back()->with('error', $e->getMessage()));
            }
        }
    }

    private function notifyStudent(int $studentId, string $type, string $title, ?string $body, string $link, int $classId): void
    {
        \App\Models\Classroom\Notification::fanOut(
            ['type' => $type, 'title' => $title, 'body' => $body, 'link' => $link, 'data' => ['class_id' => $classId], 'class_id' => $classId],
            [['role' => 'student', 'id' => $studentId]]
        );
    }
}
