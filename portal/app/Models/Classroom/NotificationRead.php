<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRead extends Model
{
    protected $table = 'classroom_notification_reads';
    public $timestamps = false;
    protected $fillable = ['notification_id', 'recipient_role', 'recipient_id', 'read_at', 'created_at'];
    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }
}
