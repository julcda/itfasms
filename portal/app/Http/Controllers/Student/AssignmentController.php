<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Models\Classroom\Assignment;
use App\Models\Classroom\AssignmentSubmission;
use App\Support\Uploads;
use Illuminate\Http\Request;

class AssignmentController extends StudentLmsController
{
    public function classIndex(Request $request, int $classId)
    {
        $sid = $this->studentInfoId($request);
        $class = $this->enrolledClass($sid, $classId);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);

        $assignments = Assignment::where('class_id', $classId)->where('status', 'published')->orderByDesc('id')->get();
        $mySubs = AssignmentSubmission::where('student_id', $sid)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');

        return view('classroom.assignments', compact('class', 'assignments', 'mySubs', 'profile', 'photoUrl'));
    }

    private function visibleAssignment(Request $request, int $id): array
    {
        $sid = $this->studentInfoId($request);
        $assignment = Assignment::where('id', $id)->where('status', 'published')->firstOrFail();
        $class = $this->enrolledClass($sid, (int) $assignment->class_id);
        return [$assignment, $class, $sid];
    }

    public function show(Request $request, int $id)
    {
        [$assignment, $class, $sid] = $this->visibleAssignment($request, $id);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $assignment->load('attachments');
        $submission = AssignmentSubmission::with('files')->where('assignment_id', $id)->where('student_id', $sid)->first();

        return view('classroom.assignment', compact('assignment', 'class', 'submission', 'profile', 'photoUrl'));
    }

    public function submit(Request $request, int $id)
    {
        [$assignment, $class, $sid] = $this->visibleAssignment($request, $id);

        $existing = AssignmentSubmission::where('assignment_id', $id)->where('student_id', $sid)->first();
        if ($existing && $existing->status === 'graded') {
            return back()->with('error', 'This assignment has already been graded and can no longer be changed.');
        }

        $overdue = $assignment->due_at && now()->greaterThan($assignment->due_at);
        if ($overdue && !$assignment->allow_late) {
            return back()->with('error', 'The deadline has passed and late submissions are not allowed.');
        }

        $text = trim((string) $request->input('text_answer', ''));
        $files = array_filter((array) $request->file('files', []));

        if ($assignment->require_text && $text === '') {
            return back()->with('error', 'A written answer is required for this assignment.');
        }
        $hasExistingFiles = $existing && $existing->files()->exists();
        if ($assignment->require_file && $files === [] && !$hasExistingFiles) {
            return back()->with('error', 'A file upload is required for this assignment.');
        }

        $sub = AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $id, 'student_id' => $sid],
            [
                'text_answer' => $text !== '' ? $text : null,
                'submitted_at' => now(),
                'status' => $overdue ? 'late' : 'submitted',
            ]
        );

        foreach ($files as $file) {
            if (!$file) { continue; }
            try {
                $meta = Uploads::store($file, 'classroom_submissions', Uploads::SUBMIT_EXT, 25 * 1024 * 1024);
                $sub->files()->create($meta);
            } catch (\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        // Notify the teacher.
        \App\Models\Classroom\Notification::fanOut(
            ['type' => 'assignment_submitted', 'title' => 'Submission: ' . $assignment->title, 'body' => 'A student turned in their work.', 'link' => route('teacher.assignments.submissions', $assignment->id), 'data' => ['class_id' => $assignment->class_id], 'class_id' => $assignment->class_id],
            [['role' => 'teacher', 'id' => (int) $class->Teacher_id]]
        );

        return back()->with('success', $overdue ? 'Submitted (marked late).' : 'Submitted successfully.');
    }
}
