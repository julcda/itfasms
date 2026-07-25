<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Models\Classroom\GradeIntegration;
use App\Models\Classroom\Quiz;
use App\Models\Classroom\QuizAttempt;
use App\Support\QuizGrader;
use Illuminate\Http\Request;

class QuizController extends StudentLmsController
{
    private function visibleQuiz(Request $request, int $id): array
    {
        $sid = $this->studentInfoId($request);
        $quiz = Quiz::where('id', $id)->where('status', 'published')->firstOrFail();
        $class = $this->enrolledClass($sid, (int) $quiz->class_id);
        return [$quiz, $class, $sid];
    }

    public function classIndex(Request $request, int $classId)
    {
        $sid = $this->studentInfoId($request);
        $class = $this->enrolledClass($sid, $classId);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $quizzes = Quiz::where('class_id', $classId)->where('status', 'published')->withCount('questions')->orderByDesc('id')->get();
        $myAttempts = QuizAttempt::where('student_id', $sid)->whereIn('quiz_id', $quizzes->pluck('id'))->get()->groupBy('quiz_id');

        return view('classroom.quizzes', compact('class', 'quizzes', 'myAttempts', 'profile', 'photoUrl'));
    }

    public function show(Request $request, int $id)
    {
        [$quiz, $class, $sid] = $this->visibleQuiz($request, $id);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $attempts = QuizAttempt::where('quiz_id', $id)->where('student_id', $sid)->orderBy('attempt_number')->get();
        $totalPoints = (float) $quiz->questions()->sum('points');
        $questionCount = $quiz->questions()->count();

        return view('classroom.quiz_intro', compact('quiz', 'class', 'attempts', 'totalPoints', 'questionCount', 'profile', 'photoUrl'));
    }

    public function start(Request $request, int $id)
    {
        [$quiz, $class, $sid] = $this->visibleQuiz($request, $id);

        if ($quiz->available_from && now()->lessThan($quiz->available_from)) {
            return back()->with('error', 'This quiz is not open yet.');
        }
        if ($quiz->available_until && now()->greaterThan($quiz->available_until)) {
            return back()->with('error', 'This quiz has closed.');
        }
        $used = QuizAttempt::where('quiz_id', $id)->where('student_id', $sid)->count();
        // Resume an in-progress attempt if any.
        $inProgress = QuizAttempt::where('quiz_id', $id)->where('student_id', $sid)->where('status', 'in_progress')->first();
        if ($inProgress) {
            return redirect()->route('student.attempts.take', $inProgress->id);
        }
        if ($used >= $quiz->max_attempts) {
            return back()->with('error', 'You have used all your attempts for this quiz.');
        }

        $attempt = QuizAttempt::create([
            'quiz_id' => $id, 'student_id' => $sid, 'attempt_number' => $used + 1,
            'started_at' => now(), 'status' => 'in_progress',
        ]);
        return redirect()->route('student.attempts.take', $attempt->id);
    }

    public function take(Request $request, int $attemptId)
    {
        $sid = $this->studentInfoId($request);
        $attempt = QuizAttempt::where('id', $attemptId)->where('student_id', $sid)->firstOrFail();
        if ($attempt->status !== 'in_progress') {
            return redirect()->route('student.attempts.result', $attempt->id);
        }
        $quiz = Quiz::where('id', $attempt->quiz_id)->firstOrFail();
        $class = $this->enrolledClass($sid, (int) $quiz->class_id);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);

        $questions = $quiz->questions()->with('choices')->orderBy('sort_order')->orderBy('id')->get();
        if ($quiz->shuffle_questions) { $questions = $questions->shuffle(); }

        // Per-question presentation payloads (shuffled choices / matching rights / ordering items).
        $view = $questions->map(function ($q) use ($quiz) {
            $choices = $q->choices;
            if ($quiz->shuffle_choices && in_array($q->type, ['mcq', 'multi_select', 'true_false'], true)) { $choices = $choices->shuffle(); }
            $rights = collect(($q->meta['pairs'] ?? []))->pluck('right')->shuffle()->values();
            $items = collect(($q->meta['items'] ?? []))->shuffle()->values(); // [ [origIndex, text], ... ] keep orig index
            $itemsIndexed = collect($q->meta['items'] ?? [])->map(fn ($t, $i) => ['i' => $i, 'text' => $t])->shuffle()->values();
            return ['q' => $q, 'choices' => $choices, 'rights' => $rights, 'items' => $itemsIndexed];
        });

        $deadline = $quiz->time_limit_minutes ? $attempt->started_at->copy()->addMinutes($quiz->time_limit_minutes) : null;
        return view('classroom.quiz_take', compact('quiz', 'class', 'attempt', 'view', 'deadline', 'profile', 'photoUrl'));
    }

    public function submit(Request $request, int $attemptId)
    {
        $sid = $this->studentInfoId($request);
        $attempt = QuizAttempt::where('id', $attemptId)->where('student_id', $sid)->firstOrFail();
        if ($attempt->status !== 'in_progress') {
            return redirect()->route('student.attempts.result', $attempt->id);
        }
        $quiz = Quiz::with(['questions.choices'])->findOrFail($attempt->quiz_id);
        $this->enrolledClass($sid, (int) $quiz->class_id);

        $auto = $request->boolean('auto_submitted');
        $score = 0.0; $anyPending = false;

        foreach ($quiz->questions as $q) {
            $g = QuizGrader::grade($q, $request);
            $attempt->answers()->updateOrCreate(
                ['question_id' => $q->id],
                ['answer_text' => $g['answer_text'], 'selected_choice_ids' => $g['selected_choice_ids'], 'is_correct' => $g['is_correct'], 'points_awarded' => $g['points_awarded']]
            );
            if ($g['points_awarded'] === null) { $anyPending = true; } else { $score += (float) $g['points_awarded']; }
        }

        $attempt->update([
            'submitted_at' => now(), 'score' => $score,
            'is_auto_submitted' => $auto, 'status' => $anyPending ? 'submitted' : 'graded',
        ]);

        if (!$anyPending) {
            GradeIntegration::updateOrCreate(
                ['source_type' => 'quiz', 'source_id' => $quiz->id, 'student_id' => $sid],
                ['class_id' => $quiz->class_id, 'grading_period_id' => 0, 'score' => $score, 'max_score' => (float) $quiz->questions->sum('points')]
            );
        }

        // Notify teacher.
        \App\Models\Classroom\Notification::fanOut(
            ['type' => 'quiz_submitted', 'title' => 'Quiz submitted: ' . $quiz->title, 'body' => $anyPending ? 'Has essay answers to grade.' : 'Auto-graded.', 'link' => route('teacher.quizzes.results', $quiz->id), 'data' => ['class_id' => $quiz->class_id], 'class_id' => $quiz->class_id],
            [['role' => 'teacher', 'id' => (int) $quiz->schoolClass->Teacher_id]]
        );

        return redirect()->route('student.attempts.result', $attempt->id);
    }

    public function result(Request $request, int $attemptId)
    {
        $sid = $this->studentInfoId($request);
        $attempt = QuizAttempt::where('id', $attemptId)->where('student_id', $sid)->firstOrFail();
        $quiz = Quiz::with(['questions' => fn ($q) => $q->orderBy('sort_order'), 'questions.choices'])->findOrFail($attempt->quiz_id);
        $class = $this->enrolledClass($sid, (int) $quiz->class_id);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $answers = $attempt->answers()->get()->keyBy('question_id');
        $totalPoints = (float) $quiz->questions->sum('points');

        return view('classroom.quiz_result', compact('quiz', 'class', 'attempt', 'answers', 'totalPoints', 'profile', 'photoUrl'));
    }
}
