<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentAttachment extends Model
{
    protected $table = 'classroom_assignment_attachments';
    protected $fillable = ['assignment_id', 'file_path', 'file_name', 'file_size', 'mime_type'];

    public function assignment(): BelongsTo { return $this->belongsTo(Assignment::class, 'assignment_id'); }
}
