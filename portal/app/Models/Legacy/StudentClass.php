<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bridge model for `student_classes` — roster membership (class_id + student_id). */
class StudentClass extends Model
{
    protected $table = 'student_classes';
    public $timestamps = false;
    protected $fillable = [];

    public function studentInfo(): BelongsTo
    {
        return $this->belongsTo(StudentInfo::class, 'student_id', 'student_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'Class_id');
    }
}
