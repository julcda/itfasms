<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

class SchoolYear extends Model
{
    protected $table = 'schoolyear';
    protected $primaryKey = 'School_year_id';
    public $timestamps = false;
    protected $fillable = [];
}
