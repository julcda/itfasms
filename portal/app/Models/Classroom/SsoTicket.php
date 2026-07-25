<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;

class SsoTicket extends Model
{
    protected $table = 'classroom_sso_tickets';
    public $timestamps = false;
    protected $fillable = ['ticket', 'teacher_id', 'redirect_path', 'expires_at', 'used_at', 'created_at'];
    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
}
