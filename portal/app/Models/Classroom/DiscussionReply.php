<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscussionReply extends Model
{
    protected $table = 'classroom_discussion_replies';
    protected $fillable = ['thread_id', 'body', 'image_path', 'author_role', 'author_id'];

    public function thread(): BelongsTo { return $this->belongsTo(DiscussionThread::class, 'thread_id'); }
    public function likes(): HasMany { return $this->hasMany(DiscussionLike::class, 'reply_id'); }
}
