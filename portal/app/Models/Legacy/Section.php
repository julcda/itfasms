<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Section extends Model
{
    protected $table = 'section';
    protected $primaryKey = 'Section_id';
    public $timestamps = false;
    protected $fillable = [];

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'Gradelevel_id', 'Gradelevel_id');
    }
}
