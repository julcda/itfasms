<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\GradeIntegration;
use App\Models\Classroom\Quiz;
use App\Models\Classroom\QuizAnswer;
use App\Models\Classroom\QuizAttempt;
use App\Models\Classroom\QuizQuestion;
use App\Models\Legacy\StudentInfo;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuizController extends TeacherController
{
    private function ownedQuiz(Request $request, int $id): Quiz
    {
        $quiz = Quiz::find($id);
        if (!$quiz) {
            throw new HttpResponseException(redirect()->route('teacher.dashboard')->with('error', 'Quiz not found.'));
        }
        $this->ownedClass($request, (int) $quiz->class_id);
        return $quiz;
    }

    public function index(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $quizzes = Quiz::where('class_id', $classId)->withCount(['questions', 'attempts' => fn ($q) => $q->where('status', '!=', 'in_progress')])->orderByDesc('id')->get();
        return view('teacher.quizzes.index', compact('class', 'quizzes'));
    }

    public function create(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        return view('teacher.quizzes.form', ['class' => $class, 'quiz' => new Quiz(['class_id' => $classId, 'max_attempts' => 1, 'auto_submit' => true, 'show_score_immediately' => true])]);
    }

    public function store(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $data = $this->validatedSettings($request);
        $data['class_id'] = $classId;
        $data['created_by'] = $this->teacher($request)->user_id;
        $quiz = Quiz::create($data);
        return redirect()->route('teacher.quizzes.edit', $quiz->id)->with('success', 'Quiz created — now add questions.');
    }

    public function edit(Request $request, int $id)
    {
        $quiz = $this->ownedQuiz($request, $id);
        $quiz->load(['questions' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'questions.choices']);
        return view('teacher.quizzes.form', ['class' => $quiz->schoolClass, 'quiz' => $quiz]);
    }

    public function update(Request $request, int $id)
    {
        $quiz = $this->ownedQuiz($request, $id);
        $was = $quiz->status;
        $quiz->update($this->validatedSettings($request));
        if ($was !== 'published' && $quiz->status === 'published') {
            $this->notifyClass((int) $quiz->class_id, 'quiz_published', 'New quiz: ' . $quiz->title, $quiz->description, route('student.quizzes.show', $quiz->id), ['quiz_id' => $quiz->id]);
        }
        return back()->with('success', 'Quiz saved.');
    }

    public function publish(Request $request, int $id)
    {
        $quiz = $this->ownedQuiz($request, $id);
        if ($quiz->questions()->count() === 0) {
            return back()->with('error', 'Add at least one question before publishing.');
        }
        $was = $quiz->status;
        $quiz->update(['status' => 'published']);
        if ($was !== 'published') {
            $this->notifyClass((int) $quiz->class_id, 'quiz_published', 'New quiz: ' . $quiz->title, $quiz->description, route('student.quizzes.show', $quiz->id), ['quiz_id' => $quiz->id]);
        }
        return back()->with('success', 'Quiz published.');
    }

    public function unpublish(Request $request, int $id)
    {
        $this->ownedQuiz($request, $id)->update(['status' => 'draft']);
        return back()->with('success', 'Quiz moved to draft.');
    }

    public function destroy(Request $request, int $id)
    {
        $quiz = $this->ownedQuiz($request, $id);
        $classId = (int) $quiz->class_id;
        GradeIntegration::where('source_type', 'quiz')->where('source_id', $quiz->id)->delete();
        $quiz->delete();
        return redirect()->route('teacher.quizzes.index', $classId)->with('success', 'Quiz deleted.');
    }

    public function storeQuestion(Request $request, int $id)
    {
        $quiz = $this->ownedQuiz($request, $id);
        $type = (string) $request->input('type');
        $request->validate([
            'type' => ['required', Rule::in(['mcq', 'multi_select', 'true_false', 'identification', 'short_answer', 'essay', 'matching', 'ordering', 'fill_blank'])],
            'question_text' => ['required', 'string'],
            'points' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ]);

        $meta = null;
        $choices = [];

        if (in_array($type, ['mcq', 'multi_select'], true)) {
            // Choices, one per line; a leading * marks a correct option.
            foreach (preg_split('/\r?\n/', (string) $request->input('choices', '')) as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $correct = str_starts_with($line, '*');
                $choices[] = ['choice_text' => trim(ltrim($line, '*')), 'is_correct' => $correct];
            }
            if ($choices === [] || !collect($choices)->contains('is_correct', true)) {
                return back()->with('error', 'Provide choices with at least one correct answer marked with *.');
            }
        } elseif ($type === 'true_false') {
            $correct = $request->input('tf_correct', 'true');
            $choices = [['choice_text' => 'True', 'is_correct' => $correct === 'true'], ['choice_text' => 'False', 'is_correct' => $correct === 'false']];
        } elseif (in_array($type, ['identification', 'short_answer', 'fill_blank'], true)) {
            $answers = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $request->input('answers', '')))));
            if ($answers === []) { return back()->with('error', 'Provide at least one accepted answer.'); }
            $meta = ['answers' => $answers];
        } elseif ($type === 'matching') {
            $pairs = [];
            foreach (preg_split('/\r?\n/', (string) $request->input('pairs', '')) as $line) {
                if (!str_contains($line, '=')) { continue; }
                [$l, $r] = array_map('trim', explode('=', $line, 2));
                if ($l !== '' && $r !== '') { $pairs[] = ['left' => $l, 'right' => $r]; }
            }
            if (count($pairs) < 2) { return back()->with('error', 'Provide at least two “left = right” pairs.'); }
            $meta = ['pairs' => $pairs];
        } elseif ($type === 'ordering') {
            $items = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $request->input('items', '')))));
            if (count($items) < 2) { return back()->with('error', 'Provide at least two items in the correct order.'); }
            $meta = ['items' => $items];
        }

        $question = $quiz->questions()->create([
            'type' => $type,
            'question_text' => (string) $request->input('question_text'),
            'points' => (float) $request->input('points'),
            'sort_order' => (int) ($quiz->questions()->max('sort_order') ?? 0) + 1,
            'meta' => $meta,
        ]);
        foreach ($choices as $i => $c) {
            $question->choices()->create($c + ['sort_order' => $i]);
        }
        return back()->with('success', 'Question added.');
    }

    public function destroyQuestion(Request $request, int $questionId)
    {
        $question = QuizQuestion::with('quiz')->findOrFail($questionId);
        $this->ownedClass($request, (int) $question->quiz->class_id);
        $question->delete();
        return back()->with('success', 'Question removed.');
    }

    public function results(Request $request, int $id)
    {
        $quiz = $this->ownedQuiz($request, $id);
        $attempts = QuizAttempt::where('quiz_id', $id)->where('status', '!=', 'in_progress')->orderByDesc('id')->get();
        $students = StudentInfo::whereIn('student_id', $attempts->pluck('student_id')->unique())->get()->keyBy('student_id');
        $totalPoints = (float) $quiz->questions()->sum('points');
        $needsGrading = $quiz->questions()->where('type', 'essay')->exists();
        return view('teacher.quizzes.results', compact('quiz', 'attempts', 'students', 'totalPoints', 'needsGrading'));
    }

    public function review(Request $request, int $attemptId)
    {
        $attempt = QuizAttempt::with(['answers'])->findOrFail($attemptId);
        $quiz = $this->ownedQuiz($request, (int) $attempt->quiz_id);
        $quiz->load(['questions' => fn ($q) => $q->orderBy('sort_order'), 'questions.choices']);
        $student = StudentInfo::find($attempt->student_id);
        $answers = $attempt->answers->keyBy('question_id');
        return view('teacher.quizzes.review', compact('quiz', 'attempt', 'student', 'answers'));
    }

    public function gradeAnswer(Request $request, int $answerId)
    {
        $answer = QuizAnswer::with('attempt')->findOrFail($answerId);
        $question = QuizQuestion::with('quiz')->findOrFail($answer->question_id);
        $this->ownedQuiz($request, (int) $question->quiz->id); // authorizes via the quiz's class

        $pts = (float) $request->validate(['points' => ['required', 'numeric', 'min:0', 'max:' . $question->points]])['points'];
        $answer->update([
            'points_awarded' => $pts,
            'is_correct' => $pts >= (float) $question->points,
            'teacher_feedback' => (string) $request->input('feedback', ''),
        ]);
        $this->recomputeAttempt($answer->attempt);
        return back()->with('success', 'Answer graded.');
    }

    private function recomputeAttempt(QuizAttempt $attempt): void
    {
        $quiz = Quiz::with('questions')->find($attempt->quiz_id);
        $answers = QuizAnswer::where('attempt_id', $attempt->id)->get();
        $anyPending = $answers->contains(fn ($a) => $a->points_awarded === null);
        $score = (float) $answers->sum(fn ($a) => (float) $a->points_awarded);
        $attempt->update(['score' => $score, 'status' => $anyPending ? 'submitted' : 'graded']);

        if (!$anyPending) {
            GradeIntegration::updateOrCreate(
                ['source_type' => 'quiz', 'source_id' => $quiz->id, 'student_id' => $attempt->student_id],
                ['class_id' => $quiz->class_id, 'grading_period_id' => 0, 'score' => $score, 'max_score' => (float) $quiz->questions->sum('points')]
            );
        }
    }

    private function validatedSettings(Request $request): array
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'passing_score' => ['nullable', 'numeric', 'min:0'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
        return $v + [
            'shuffle_questions' => $request->boolean('shuffle_questions'),
            'shuffle_choices' => $request->boolean('shuffle_choices'),
            'show_score_immediately' => $request->boolean('show_score_immediately'),
            'show_correct_answers' => $request->boolean('show_correct_answers'),
            'auto_submit' => $request->boolean('auto_submit'),
        ];
    }
}
