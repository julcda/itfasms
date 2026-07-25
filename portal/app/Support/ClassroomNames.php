<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Legacy\StudentInfo;
use App\Models\Legacy\TeacherModel;

/**
 * Resolve display names for a mixed set of authors (teacher OR student) in one
 * batched pass — discussion threads/replies store authorship as a role+id pair
 * because there is no unified users table across the two legacy stores.
 */
class ClassroomNames
{
    /** @return array<string,string> keyed "teacher:{id}" / "student:{id}" */
    public static function resolve(iterable $threads): array
    {
        $teacherIds = [];
        $studentIds = [];
        foreach ($threads as $t) {
            self::collect($t->author_role, (int) $t->author_id, $teacherIds, $studentIds);
            foreach ($t->replies ?? [] as $r) {
                self::collect($r->author_role, (int) $r->author_id, $teacherIds, $studentIds);
            }
        }
        $names = [];
        if ($teacherIds) {
            foreach (TeacherModel::whereIn('Teacher_id', array_keys($teacherIds))->get() as $t) {
                $names['teacher:' . $t->Teacher_id] = $t->displayName();
            }
        }
        if ($studentIds) {
            foreach (StudentInfo::whereIn('student_id', array_keys($studentIds))->get() as $s) {
                $names['student:' . $s->student_id] = trim($s->Firstname . ' ' . $s->Lastname) ?: 'Student';
            }
        }
        return $names;
    }

    private static function collect(string $role, int $id, array &$t, array &$s): void
    {
        if ($role === 'teacher') { $t[$id] = 1; } else { $s[$id] = 1; }
    }
}
