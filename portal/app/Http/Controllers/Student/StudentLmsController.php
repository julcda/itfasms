<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Legacy\SchoolClass;
use App\Models\Legacy\StudentClass;
use App\Services\Portal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Base for every student-side Classroom module. Holds the shared authorization
 * primitives so each module controller can't drift: identity comes from the
 * SESSION (LRN → masterlist student_id), class access from roster membership.
 */
abstract class StudentLmsController extends Controller
{
    public function __construct(protected Portal $portal) {}

    /** The masterlist student_id this session maps to, or bounce home. */
    protected function studentInfoId(Request $request): int
    {
        [$profile] = $this->profile($request, $this->portal);
        $sy = $this->portal->activeSy();
        $id = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);
        if ($id <= 0) {
            throw new HttpResponseException(
                redirect()->route('dashboard')->with('error', 'Your class records could not be found. Please contact the Registrar.')
            );
        }
        return $id;
    }

    /** AUTHORIZATION PRIMITIVE — a class is reachable only if the student is on its roster. */
    protected function enrolledClass(int $studentInfoId, int $classId): SchoolClass
    {
        $onRoster = StudentClass::where('class_id', $classId)->where('student_id', $studentInfoId)->exists();
        if (!$onRoster) {
            throw new HttpResponseException(
                redirect()->route('student.classes.index')->with('error', 'You are not enrolled in that class.')
            );
        }
        return SchoolClass::with(['subject', 'section', 'teacher'])->findOrFail($classId);
    }
}
