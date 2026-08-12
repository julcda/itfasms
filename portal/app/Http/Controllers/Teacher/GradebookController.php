<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\Assignment;
use App\Models\Classroom\GradeIntegration;
use App\Models\Classroom\Quiz;
use App\Models\Legacy\StudentClass;
use App\Models\Legacy\StudentInfo;
use Illuminate\Http\Request;

class GradebookController extends TeacherController
{
    public function index(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);

        // Columns = published assignments + quizzes.
        $assignments = Assignment::where('class_id', $classId)->orderBy('id')->get()
            ->map(fn ($a) => ['key' => 'assignment:' . $a->id, 'label' => $a->title, 'max' => (float) $a->max_score, 'kind' => 'Assignment']);
        $quizzes = Quiz::where('class_id', $classId)->withSum('questions', 'points')->orderBy('id')->get()
            ->map(fn ($q) => ['key' => 'quiz:' . $q->id, 'label' => $q->title, 'max' => (float) ($q->questions_sum_points ?? 0), 'kind' => 'Quiz']);
        $columns = $assignments->concat($quizzes)->values();

        // Roster.
        $roster = $class->rosterStudentIds()->filter()->values();
        $students = StudentInfo::whereIn('student_id', $roster)->get()
            ->sortBy(fn ($s) => $s->Lastname . $s->Firstname)->values();

        // Scores keyed [student_id][source_type:source_id].
        $scores = [];
        foreach (GradeIntegration::where('class_id', $classId)->get() as $g) {
            $scores[$g->student_id][$g->source_type . ':' . $g->source_id] = (float) $g->score;
        }

        $totalMax = (float) $columns->sum('max');

        return view('teacher.gradebook', compact('class', 'columns', 'students', 'scores', 'totalMax'));
    }
}
