<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\Assignment;
use App\Models\Classroom\AssignmentSubmission;
use App\Models\Classroom\Lesson;
use App\Models\Classroom\LessonProgress;
use App\Models\Classroom\Quiz;
use App\Models\Classroom\QuizAttempt;
use App\Models\Legacy\StudentClass;
use App\Models\Legacy\StudentInfo;
use Illuminate\Http\Request;

class AnalyticsController extends TeacherController
{
    public function index(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);

        $roster = $class->rosterStudentIds()->filter()->unique()->values();
        $rosterCount = $roster->count();

        // Lessons
        $lessonIds = Lesson::where('class_id', $classId)->where('status', 'published')->pluck('id');
        $lessonCount = $lessonIds->count();
        $completedProg = LessonProgress::whereIn('lesson_id', $lessonIds)->where('status', 'completed')->count();
        $lessonCompletionPct = ($lessonCount * $rosterCount) > 0 ? round($completedProg / ($lessonCount * $rosterCount) * 100) : 0;

        // Assignments
        $assignmentIds = Assignment::where('class_id', $classId)->where('status', 'published')->pluck('id');
        $assignmentCount = $assignmentIds->count();
        $submissions = AssignmentSubmission::whereIn('assignment_id', $assignmentIds)->whereIn('status', ['submitted', 'late', 'graded'])->count();
        $expectedSubs = $assignmentCount * $rosterCount;
        $missingSubs = max(0, $expectedSubs - $submissions);

        // Quizzes
        $quizIds = Quiz::where('class_id', $classId)->where('status', 'published')->pluck('id');
        $quizCount = $quizIds->count();
        $quizAttempts = QuizAttempt::whereIn('quiz_id', $quizIds)->where('status', '!=', 'in_progress')->get();

        // Per-student progress leaderboard (lesson completion %).
        $students = StudentInfo::whereIn('student_id', $roster)->get();
        $perStudentDone = LessonProgress::whereIn('lesson_id', $lessonIds)->where('status', 'completed')
            ->get()->groupBy('student_id')->map->count();
        $leaderboard = $students->map(fn ($s) => [
            'name' => $s->fullName(),
            'done' => (int) ($perStudentDone[$s->student_id] ?? 0),
            'pct'  => $lessonCount > 0 ? round(((int) ($perStudentDone[$s->student_id] ?? 0)) / $lessonCount * 100) : 0,
        ])->sortByDesc('done')->values();

        $metrics = [
            'rosterCount' => $rosterCount,
            'lessonCount' => $lessonCount,
            'lessonCompletionPct' => $lessonCompletionPct,
            'assignmentCount' => $assignmentCount,
            'submissions' => $submissions,
            'missingSubs' => $missingSubs,
            'submissionRate' => $expectedSubs > 0 ? round($submissions / $expectedSubs * 100) : 0,
            'quizCount' => $quizCount,
            'quizAttemptCount' => $quizAttempts->count(),
            'quizAvgPct' => $this->quizAvgPct($quizIds, $quizAttempts),
        ];

        return view('teacher.analytics', compact('class', 'metrics', 'leaderboard'));
    }

    private function quizAvgPct($quizIds, $attempts): int
    {
        if ($attempts->isEmpty()) {
            return 0;
        }
        $maxByQuiz = Quiz::whereIn('id', $quizIds)->withSum('questions', 'points')->get()
            ->mapWithKeys(fn ($q) => [$q->id => (float) ($q->questions_sum_points ?? 0)]);
        $pcts = [];
        foreach ($attempts as $a) {
            $max = (float) ($maxByQuiz[$a->quiz_id] ?? 0);
            if ($max > 0 && $a->score !== null) {
                $pcts[] = (float) $a->score / $max * 100;
            }
        }
        return $pcts === [] ? 0 : (int) round(array_sum($pcts) / count($pcts));
    }
}
