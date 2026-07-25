<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use App\Models\Legacy\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Schema-complete now; composer UI ships in Phase 4. */
class Announcement extends Model
{
    protected $table = 'classroom_announcements';
    protected $fillable = ['class_id', 'title', 'body', 'created_by'];

    public function schoolClass(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id', 'Class_id'); }
    public function attachments(): HasMany { return $this->hasMany(AnnouncementAttachment::class, 'announcement_id'); }
}
