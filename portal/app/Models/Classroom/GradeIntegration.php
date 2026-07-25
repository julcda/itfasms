<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;

/**
 * Staging layer only — never writes the native `student_grade` table directly.
 * See migration comment: the native gradebook has its own approval workflow
 * (encode -> submit -> Dept Head approve) that this must not bypass. Wiring
 * this into an "Import from LMS" action on the teacher's encode screen is a
 * Phase-2+ decision pending the school's grading-weight policy.
 */
class GradeIntegration extends Model
{
    protected $table = 'classroom_grade_integration';
    protected $fillable = [
        'class_id', 'student_id', 'grading_period_id', 'source_type', 'source_id',
        'score', 'max_score', 'weight', 'synced_to_student_grade', 'synced_at',
    ];
    protected $casts = ['synced_to_student_grade' => 'bool', 'synced_at' => 'datetime'];
}
