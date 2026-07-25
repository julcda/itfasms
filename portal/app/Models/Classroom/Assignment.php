<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use App\Models\Legacy\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Schema-complete now; teacher/student UI ships in Phase 2. */
class Assignment extends Model
{
    protected $table = 'classroom_assignments';
    protected $fillable = [
        'class_id', 'lesson_id', 'title', 'description', 'instructions', 'due_at',
        'max_score', 'allow_late', 'submission_mode', 'require_file', 'require_text',
        'status', 'created_by',
    ];
    protected $casts = ['due_at' => 'datetime', 'allow_late' => 'bool', 'require_file' => 'bool', 'require_text' => 'bool'];

    public function schoolClass(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id', 'Class_id'); }
    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class, 'lesson_id'); }
    public function attachments(): HasMany { return $this->hasMany(AssignmentAttachment::class, 'assignment_id'); }
    public function submissions(): HasMany { return $this->hasMany(AssignmentSubmission::class, 'assignment_id'); }
}
