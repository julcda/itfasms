<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    protected $table = 'classroom_notifications';
    public $timestamps = false;
    protected $fillable = ['type', 'title', 'body', 'link', 'data', 'class_id', 'created_by', 'created_at'];
    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];

    public function reads(): HasMany
    {
        return $this->hasMany(NotificationRead::class, 'notification_id');
    }

    /**
     * Fan out one notification event to many recipients in a single insert.
     * @param array<int,array{role:string,id:int}> $recipients
     */
    public static function fanOut(array $attrs, array $recipients): self
    {
        $notification = self::create($attrs + ['created_at' => now()]);
        $rows = array_map(static fn (array $r) => [
            'notification_id' => $notification->id,
            'recipient_role'  => $r['role'],
            'recipient_id'    => $r['id'],
            'read_at'         => null,
            'created_at'      => now(),
        ], $recipients);
        if ($rows !== []) {
            NotificationRead::insert($rows);
        }
        return $notification;
    }
}
