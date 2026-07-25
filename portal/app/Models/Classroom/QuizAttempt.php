<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    protected $table = 'classroom_quiz_attempts';
    protected $fillable = ['quiz_id', 'student_id', 'attempt_number', 'started_at', 'submitted_at', 'score', 'is_auto_submitted', 'status'];
    protected $casts = ['started_at' => 'datetime', 'submitted_at' => 'datetime', 'is_auto_submitted' => 'bool'];

    public function quiz(): BelongsTo { return $this->belongsTo(Quiz::class, 'quiz_id'); }
    public function answers(): HasMany { return $this->hasMany(QuizAnswer::class, 'attempt_id'); }
}
