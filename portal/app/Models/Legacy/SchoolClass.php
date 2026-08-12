<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Only real academic classes — excludes schedule-only subjects (HRG, recess,
     * breaks) flagged subject.is_academic = 0. Classes with no subject row are
     * kept (treated as academic) so a data gap never hides a real class.
     */
    public function scopeAcademic($query)
    {
        return $query->where(function ($q) {
            $q->whereDoesntHave('subject')
              ->orWhereHas('subject', fn ($s) => $s->where('is_academic', 1));
        });
    }

    /**
     * Only the CURRENT Term's classes (Senior High is term-based). A class whose
     * semester matches the current Term shows; year-long subjects (semester
     * "N/A"/none) always show. No-op if the current Term has no semester link.
     */
    public function scopeCurrentTerm($query)
    {
        $termSem = DB::table('grading_period')
            ->join('schoolyear', 'schoolyear.School_year_id', '=', 'grading_period.school_year_id')
            ->where('schoolyear.Status', 1)
            ->where('grading_period.is_current', 1)
            ->value('grading_period.semester_id');

        if ($termSem === null) {
            return $query;   // terms not linked to semesters yet — show everything
        }

        // Semesters that are tied to a Term (so we can tell term-scoped from year-long).
        $termSemesters = DB::table('grading_period')
            ->join('schoolyear', 'schoolyear.School_year_id', '=', 'grading_period.school_year_id')
            ->where('schoolyear.Status', 1)
            ->whereNotNull('grading_period.semester_id')
            ->pluck('grading_period.semester_id')->all();

        return $query->where(function ($q) use ($termSem, $termSemesters) {
            $q->where('Semester_id', $termSem)
              ->orWhereNull('Semester_id')
              ->orWhere('Semester_id', 0)
              ->orWhereNotIn('Semester_id', $termSemesters);
        });
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

    // ── Roster, derived LIVE from section membership ─────────────────────────
    // A class belongs to one section; its roster is every student enrolled in
    // that section for the class's school year. No student_classes maintenance.

    /** Base query for the students on this class's roster (StudentInfo rows). */
    public function rosterQuery()
    {
        return StudentInfo::where('Section', $this->Section_id)
            ->where('School_year_id', $this->School_year_id);
    }

    /** Collection of student_id on this class's roster. */
    public function rosterStudentIds()
    {
        return $this->rosterQuery()->pluck('student_id');
    }

    /** Full StudentInfo rows on this roster, name-sorted. */
    public function rosterStudents()
    {
        return $this->rosterQuery()->orderBy('Lastname')->orderBy('Firstname')->get();
    }

    /** Is a given masterlist student on this class's roster? */
    public function hasStudent(int $studentInfoId): bool
    {
        return $this->rosterQuery()->where('student_id', $studentInfoId)->exists();
    }

    /** Convenience: roster student_ids for a class id (loads the class). */
    public static function rosterIdsFor(int $classId)
    {
        $class = static::find($classId);

        return $class ? $class->rosterStudentIds() : collect();
    }

    public function isOpen(): bool
    {
        return (string) $this->class_status === 'Open';
    }
}
