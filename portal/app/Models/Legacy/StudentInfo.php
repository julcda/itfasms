<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

/** Bridge model for the `studentinfo` masterlist — what rosters key off. */
class StudentInfo extends Model
{
    protected $table = 'studentinfo';
    protected $primaryKey = 'student_id';
    public $timestamps = false;
    protected $fillable = [];

    public function fullName(): string
    {
        return trim($this->Lastname . ', ' . $this->Firstname . ' ' . (string) $this->Middlename);
    }
}
