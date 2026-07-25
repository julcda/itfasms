<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmissionFile extends Model
{
    protected $table = 'classroom_assignment_submission_files';
    protected $fillable = ['submission_id', 'file_path', 'file_name', 'file_size', 'mime_type'];

    public function submission(): BelongsTo { return $this->belongsTo(AssignmentSubmission::class, 'submission_id'); }
}
