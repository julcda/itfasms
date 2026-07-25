<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementAttachment extends Model
{
    protected $table = 'classroom_announcement_attachments';
    protected $fillable = ['announcement_id', 'type', 'file_path', 'file_name', 'url'];

    public function announcement(): BelongsTo { return $this->belongsTo(Announcement::class, 'announcement_id'); }
}
