<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    protected $table = 'classroom_lesson_progress';

    protected $fillable = [
        'lesson_id', 'student_id', 'status', 'progress_percent',
        'last_viewed_at', 'completed_at',
    ];

    protected $casts = [
        'last_viewed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}
