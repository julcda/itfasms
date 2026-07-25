<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

class GradeLevel extends Model
{
    protected $table = 'gradelevel';
    protected $primaryKey = 'Gradelevel_id';
    public $timestamps = false;
    protected $fillable = [];
}
