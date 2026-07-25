<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use App\Models\Legacy\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Schema-complete now; discussion board UI ships in Phase 4. */
class DiscussionThread extends Model
{
    protected $table = 'classroom_discussion_threads';
    protected $fillable = ['class_id', 'lesson_id', 'title', 'body', 'image_path', 'author_role', 'author_id', 'is_pinned'];
    protected $casts = ['is_pinned' => 'bool'];

    public function schoolClass(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id', 'Class_id'); }
    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class, 'lesson_id'); }
    public function replies(): HasMany { return $this->hasMany(DiscussionReply::class, 'thread_id'); }
}
