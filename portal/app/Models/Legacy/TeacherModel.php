<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bridge model for the legacy `teacher` table. Named TeacherModel to avoid
 * any ambiguity with Laravel's own auth concepts.
 */
class TeacherModel extends Model
{
    protected $table = 'teacher';
    protected $primaryKey = 'Teacher_id';
    public $timestamps = true;
    protected $fillable = [];

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'Teacher_id', 'Teacher_id');
    }

    public function displayName(): string
    {
        $full = trim((string) $this->Fullname);
        if ($full !== '' && strtoupper($full) !== 'N/A') {
            return $full;
        }
        return trim($this->Firstname . ' ' . $this->Lastname) ?: 'Teacher';
    }
}
