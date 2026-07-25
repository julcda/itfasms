<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizChoice extends Model
{
    protected $table = 'classroom_quiz_choices';
    protected $fillable = ['question_id', 'choice_text', 'is_correct', 'sort_order', 'match_key'];
    protected $casts = ['is_correct' => 'bool'];

    public function question(): BelongsTo { return $this->belongsTo(QuizQuestion::class, 'question_id'); }
}
