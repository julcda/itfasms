<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizQuestion extends Model
{
    protected $table = 'classroom_quiz_questions';
    protected $fillable = ['quiz_id', 'type', 'question_text', 'points', 'sort_order', 'meta'];
    protected $casts = ['meta' => 'array'];

    public function quiz(): BelongsTo { return $this->belongsTo(Quiz::class, 'quiz_id'); }
    public function choices(): HasMany { return $this->hasMany(QuizChoice::class, 'question_id')->orderBy('sort_order'); }
}
