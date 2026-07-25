<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionLike extends Model
{
    protected $table = 'classroom_discussion_likes';
    protected $fillable = ['reply_id', 'author_role', 'author_id'];

    public function reply(): BelongsTo { return $this->belongsTo(DiscussionReply::class, 'reply_id'); }
}
