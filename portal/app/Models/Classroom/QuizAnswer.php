<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    protected $table = 'classroom_quiz_answers';
    protected $fillable = ['attempt_id', 'question_id', 'answer_text', 'selected_choice_ids', 'is_correct', 'points_awarded', 'teacher_feedback'];
    protected $casts = ['selected_choice_ids' => 'array', 'is_correct' => 'bool'];

    public function attempt(): BelongsTo { return $this->belongsTo(QuizAttempt::class, 'attempt_id'); }
    public function question(): BelongsTo { return $this->belongsTo(QuizQuestion::class, 'question_id'); }
}
