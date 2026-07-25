<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bridge model for the legacy `classes` table (a teacher+subject+section
 * offering for one school year). Named SchoolClass — `Class` is a reserved
 * word in PHP. Read/write both allowed: the native app owns this table, but
 * nothing here should ever change ownership/roster columns — Classroom only
 * ever attaches NEW rows (lessons, etc.) that reference Class_id.
 */
class SchoolClass extends Model
{
    protected $table = 'classes';
    protected $primaryKey = 'Class_id';
    public $timestamps = true;

    protected $fillable = [];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'Subject_id', 'Subject_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'Section_id', 'Section_id');
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'GradeLevel_id', 'Gradelevel_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherModel::class, 'Teacher_id', 'Teacher_id');
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class, 'class_id', 'Class_id');
    }

    public function isOpen(): bool
    {
        return (string) $this->class_status === 'Open';
    }
}
