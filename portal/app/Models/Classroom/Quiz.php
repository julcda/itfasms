<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use App\Models\Legacy\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Schema-complete now; quiz engine UI ships in Phase 3. */
class Quiz extends Model
{
    protected $table = 'classroom_quizzes';
    protected $fillable = [
        'class_id', 'lesson_id', 'title', 'description', 'time_limit_minutes', 'passing_score',
        'shuffle_questions', 'shuffle_choices', 'max_attempts', 'show_score_immediately',
        'show_correct_answers', 'available_from', 'available_until', 'due_at', 'auto_submit',
        'status', 'created_by',
    ];
    protected $casts = [
        'available_from' => 'datetime', 'available_until' => 'datetime', 'due_at' => 'datetime',
        'shuffle_questions' => 'bool', 'shuffle_choices' => 'bool',
        'show_score_immediately' => 'bool', 'show_correct_answers' => 'bool', 'auto_submit' => 'bool',
    ];

    public function schoolClass(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id', 'Class_id'); }
    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class, 'lesson_id'); }
    public function questions(): HasMany { return $this->hasMany(QuizQuestion::class, 'quiz_id')->orderBy('sort_order'); }
    public function attempts(): HasMany { return $this->hasMany(QuizAttempt::class, 'quiz_id'); }
}
