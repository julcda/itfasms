<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use App\Models\Legacy\GradingPeriod;
use App\Models\Legacy\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    protected $table = 'classroom_lessons';

    protected $fillable = [
        'class_id', 'grading_period_id', 'week_number', 'title', 'description',
        'topic', 'learning_competency', 'objectives', 'instructions',
        'status', 'publish_at', 'due_at', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'publish_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'Class_id');
    }

    public function gradingPeriod(): BelongsTo
    {
        return $this->belongsTo(GradingPeriod::class, 'grading_period_id', 'id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class, 'lesson_id')->orderBy('sort_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'lesson_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
