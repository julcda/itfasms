<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentSubmission extends Model
{
    protected $table = 'classroom_assignment_submissions';
    protected $fillable = [
        'assignment_id', 'student_id', 'text_answer', 'submitted_at', 'status',
        'score', 'teacher_comment', 'graded_by', 'graded_at',
    ];
    protected $casts = ['submitted_at' => 'datetime', 'graded_at' => 'datetime'];

    public function assignment(): BelongsTo { return $this->belongsTo(Assignment::class, 'assignment_id'); }
    public function files(): HasMany { return $this->hasMany(AssignmentSubmissionFile::class, 'submission_id'); }
}
