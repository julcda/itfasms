<?php

declare(strict_types=1);

/**
 * Grading service — grading periods, rosters, encoding, locking, audit.
 *
 * SECURITY MODEL: every write goes through grade_save(), which re-checks
 * ownership, class status, period status and roster membership on the SERVER
 * before touching a row. Callers may not skip these gates; there is no
 * "trusted" path. The database backs this with UNIQUE + CHECK + FK RESTRICT.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/teacher_auth.php';

// ── Grading periods ─────────────────────────────────────────────────────────

/** All periods for a school year, ordered. */
function gp_for_sy(mysqli $db, int $syId): array
{
    $stmt = $db->prepare('SELECT * FROM grading_period WHERE school_year_id = ? ORDER BY term_no');
    $stmt->bind_param('i', $syId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** One period. */
function gp_get(mysqli $db, int $periodId): ?array
{
    $stmt = $db->prepare('SELECT * FROM grading_period WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $periodId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** The period the UI defaults to: the flagged current one, else the first Open. */
function gp_current(mysqli $db, int $syId): ?array
{
    $stmt = $db->prepare(
        "SELECT * FROM grading_period
         WHERE school_year_id = ?
         ORDER BY is_current DESC, (status = 'Open') DESC, term_no
         LIMIT 1"
    );
    $stmt->bind_param('i', $syId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** True when teachers may still encode into this period. */
function gp_is_open(?array $period): bool
{
    return $period !== null && (string) $period['status'] === 'Open';
}

function gp_status_badge(string $status): string
{
    return match ($status) {
        'Open'     => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Closed'   => 'bg-amber-100 text-amber-800 border-amber-300',
        'Locked'   => 'bg-rose-100 text-rose-800 border-rose-300',
        default    => 'bg-slate-100 text-slate-600 border-slate-300',
    };
}

/**
 * Registrar/Admin: change a period's status (Open|Closed|Locked|Upcoming).
 * Locking is what stops teachers editing; reopening is audited.
 */
function gp_set_status(mysqli $db, int $periodId, string $status, array $user): void
{
    $allowed = ['Upcoming', 'Open', 'Closed', 'Locked'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid grading period status.');
    }
    $period = gp_get($db, $periodId);
    if (!$period) {
        throw new RuntimeException('Grading period not found.');
    }

    $uid  = (int) ($user['id'] ?? 0);
    $name = (string) ($user['full_name'] ?? 'Staff');

    $db->begin_transaction();
    try {
        if ($status === 'Locked') {
            $stmt = $db->prepare("UPDATE grading_period SET status = ?, locked_by = ?, locked_at = NOW() WHERE id = ?");
            $stmt->bind_param('sii', $status, $uid, $periodId);
        } else {
            $stmt = $db->prepare("UPDATE grading_period SET status = ?, locked_by = NULL, locked_at = NULL WHERE id = ?");
            $stmt->bind_param('si', $status, $periodId);
        }
        $stmt->execute();

        // Lock/unlock the grades themselves so the state is readable per-row too.
        $rowStatus = $status === 'Locked' ? 'Locked' : 'Submitted';
        $u = $db->prepare(
            "UPDATE student_grade SET status = ?, locked_by = ?, locked_at = IF(? = 'Locked', NOW(), NULL)
             WHERE grading_period_id = ? AND status <> 'Draft'"
        );
        $u->bind_param('sisi', $rowStatus, $uid, $status, $periodId);
        $u->execute();

        grade_audit_period($db, $periodId, $status === 'Locked' ? 'Lock' : 'Unlock',
            (string) $period['status'], $status, $uid, $name);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** Make exactly one period current within its school year. */
function gp_set_current(mysqli $db, int $periodId): void
{
    $p = gp_get($db, $periodId);
    if (!$p) {
        throw new RuntimeException('Grading period not found.');
    }
    $syId = (int) $p['school_year_id'];

    $db->begin_transaction();
    try {
        $a = $db->prepare('UPDATE grading_period SET is_current = 0 WHERE school_year_id = ?');
        $a->bind_param('i', $syId);
        $a->execute();

        $b = $db->prepare('UPDATE grading_period SET is_current = 1 WHERE id = ?');
        $b->bind_param('i', $periodId);
        $b->execute();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

// ── Roster + grades ─────────────────────────────────────────────────────────

/**
 * The class roster with each student's grade for one period.
 *
 * Only officially enrolled students appear: membership is derived LIVE from
 * section (studentinfo.Section = the class's section), not the grade table —
 * so a class always shows exactly its section's students.
 */
function grade_roster(mysqli $db, int $classId, int $periodId): array
{
    $stmt = $db->prepare(
        "SELECT si.student_id, si.LRN_no, si.Lastname, si.Firstname, si.Middlename,
                si.Status AS student_status,
                sg.id AS grade_id, sg.grade, sg.remarks, sg.status AS grade_status,
                sg.updated_at, sg.review_note, sg.reviewed_at
         FROM classes c
         JOIN studentinfo si ON si.Section = c.Section_id AND si.School_year_id = c.School_year_id
         LEFT JOIN student_grade sg
                ON sg.class_id = c.Class_id
               AND sg.student_id = si.student_id
               AND sg.grading_period_id = ?
         WHERE c.Class_id = ?
         ORDER BY si.Lastname, si.Firstname"
    );
    $stmt->bind_param('ii', $periodId, $classId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Is this student actually on this class roster? */
function grade_student_in_class(mysqli $db, int $classId, int $studentId): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM classes c
         JOIN studentinfo si ON si.Section = c.Section_id AND si.School_year_id = c.School_year_id
         WHERE c.Class_id = ? AND si.student_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $classId, $studentId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt) !== null;
}

/**
 * Save ONE grade. The only write path.
 *
 * Gates, in order — authorization before validation, both before any write:
 *   a. the class belongs to this teacher
 *   b. the class is Open
 *   c. the grading period is Open
 *   d. the student is on THIS class roster
 *   e. the grade is NULL or 0..100
 *
 * Uses INSERT … ON DUPLICATE KEY UPDATE against the UNIQUE
 * (class_id, student_id, grading_period_id) key, so concurrent tabs cannot
 * create a duplicate — the database arbitrates, not a check-then-insert race.
 *
 * @param float|null $grade NULL clears the grade (not yet encoded ≠ zero)
 * @return string 'saved'|'unchanged'
 */
function grade_save(
    mysqli $db,
    int $classId,
    int $studentId,
    int $periodId,
    ?float $grade,
    int $teacherId,
    array $user,
    ?string $remarks = null
): string {
    // (a) authorization — from the session's teacher, never from input
    if (!teacher_owns_class($db, $classId, $teacherId)) {
        throw new RuntimeException('That class is not assigned to you.');
    }

    // (b) class open?
    $cls = teacher_class_get($db, $classId);
    if (!$cls) {
        throw new RuntimeException('Class not found.');
    }
    if ((string) ($cls['class_status'] ?? 'Open') !== 'Open') {
        throw new RuntimeException('This class is closed and can no longer be graded.');
    }

    // (c) period open?
    $period = gp_get($db, $periodId);
    if (!$period) {
        throw new RuntimeException('Grading period not found.');
    }
    if (!gp_is_open($period)) {
        throw new RuntimeException($period['name'] . ' is ' . strtolower((string) $period['status'])
            . ' — grades can no longer be edited. Contact the Registrar to reopen it.');
    }
    // The period must belong to the same school year as the class.
    if ((int) $period['school_year_id'] !== (int) $cls['School_year_id']) {
        throw new RuntimeException('That grading period does not belong to this class\'s school year.');
    }

    // (d) roster membership
    if (!grade_student_in_class($db, $classId, $studentId)) {
        throw new RuntimeException('That student is not enrolled in this class.');
    }

    // (f) the teacher's own edit window: a grade that has been Approved or Locked
    //     is out of their hands. Returned grades are editable again — that is the
    //     whole point of returning them.
    $existingRow = grade_get($db, $classId, $studentId, $periodId);
    $st          = (string) ($existingRow['status'] ?? 'Draft');
    if ($existingRow && !in_array($st, ['Draft', 'Returned'], true)) {
        throw new RuntimeException($st === 'Approved'
            ? 'These grades have been approved by the Department Head and can no longer be edited.'
            : 'These grades are locked and can no longer be edited.');
    }

    // Editing a returned grade puts it back into Draft — it must be resubmitted.
    return _grade_write($db, $classId, $studentId, $periodId, $grade, $remarks, $user, 'Draft', 'Teacher');
}

/**
 * The shared, validated write. Not a public entry point — callers must go
 * through grade_save() (teacher) or grade_save_reviewer() (dept head), which
 * apply their own authorization first.
 *
 * Uses INSERT … ON DUPLICATE KEY UPDATE against the UNIQUE
 * (class_id, student_id, grading_period_id) key, so concurrent tabs cannot
 * create a duplicate — the database arbitrates, not a check-then-insert race.
 */
function _grade_write(
    mysqli $db,
    int $classId,
    int $studentId,
    int $periodId,
    ?float $grade,
    ?string $remarks,
    array $user,
    string $newStatus,
    string $actorKind
): string {
    if ($grade !== null) {
        $grade = round($grade, 2);
        if ($grade < 0 || $grade > 100) {
            throw new RuntimeException('Grade must be between 0 and 100.');
        }
    }

    $existing = grade_get($db, $classId, $studentId, $periodId);
    $old      = $existing && $existing['grade'] !== null ? (float) $existing['grade'] : null;

    // Nothing to record: a blank box for a student who has no grade row yet.
    // The grid posts EVERY input on save, so without this a 51-student class
    // would create 51 rows and 51 "NULL -> NULL" audit entries the first time a
    // teacher saves a single grade. An absent row already means "not encoded".
    if (!$existing && $grade === null && ($remarks === null || $remarks === '')) {
        return 'unchanged';
    }

    if ($existing
        && $old === $grade
        && (string) ($existing['remarks'] ?? '') === (string) ($remarks ?? '')
        && (string) $existing['status'] === $newStatus) {
        return 'unchanged';
    }

    $uid  = (int) ($user['id'] ?? 0);
    $name = (string) ($user['full_name'] ?? $actorKind);

    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO student_grade
                (class_id, student_id, grading_period_id, grade, remarks, status, encoded_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                grade      = VALUES(grade),
                remarks    = VALUES(remarks),
                updated_by = VALUES(updated_by),
                status     = IF(status = 'Locked', status, VALUES(status))"
        );
        $stmt->bind_param('iiidssii', $classId, $studentId, $periodId, $grade, $remarks, $newStatus, $uid, $uid);
        $stmt->execute();

        $row = grade_get($db, $classId, $studentId, $periodId);
        $gid = (int) ($row['id'] ?? 0);

        grade_audit(
            $db, $gid, $classId, $studentId, $periodId,
            $existing ? 'Update' : 'Insert',
            $old, $grade, $uid, $name,
            $actorKind === 'Reviewer' ? 'Edited by reviewer' : null
        );

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    return 'saved';
}

/** One grade row. */
function grade_get(mysqli $db, int $classId, int $studentId, int $periodId): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM student_grade WHERE class_id = ? AND student_id = ? AND grading_period_id = ? LIMIT 1'
    );
    $stmt->bind_param('iii', $classId, $studentId, $periodId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * Save a whole grid in one transaction.
 *
 * @param array<int, string> $grades studentId => raw input ('' = clear)
 * @return array{saved:int, unchanged:int, errors:array<string>}
 */
function grade_bulk_save(mysqli $db, int $classId, int $periodId, array $grades, int $teacherId, array $user): array
{
    $saved = 0; $unchanged = 0; $errors = [];

    foreach ($grades as $studentId => $raw) {
        $studentId = (int) $studentId;
        $raw       = trim((string) $raw);
        $value     = $raw === '' ? null : (float) $raw;

        if ($raw !== '' && !is_numeric($raw)) {
            $errors[] = "Student #$studentId: '" . h($raw) . "' is not a number.";
            continue;
        }

        try {
            $res = grade_save($db, $classId, $studentId, $periodId, $value, $teacherId, $user);
            $res === 'saved' ? $saved++ : $unchanged++;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    return ['saved' => $saved, 'unchanged' => $unchanged, 'errors' => $errors];
}

/** Mark a class's grades for a period as Submitted (teacher attests completeness). */
function grade_submit_class(mysqli $db, int $classId, int $periodId, int $teacherId, array $user): int
{
    if (!teacher_owns_class($db, $classId, $teacherId)) {
        throw new RuntimeException('That class is not assigned to you.');
    }
    $period = gp_get($db, $periodId);
    if (!gp_is_open($period)) {
        throw new RuntimeException('This grading period is not open.');
    }

    $uid  = (int) ($user['id'] ?? 0);
    $name = (string) ($user['full_name'] ?? 'Teacher');

    $stmt = $db->prepare(
        "UPDATE student_grade SET status = 'Submitted', updated_by = ?
         WHERE class_id = ? AND grading_period_id = ? AND grade IS NOT NULL AND status = 'Draft'"
    );
    $stmt->bind_param('iii', $uid, $classId, $periodId);
    $stmt->execute();
    $n = $db->affected_rows;

    grade_audit_period($db, $periodId, 'Submit', 'Draft', 'Submitted', $uid, $name, $classId);

    return $n;
}

// ── Review workflow (Department Head) ───────────────────────────────────────

/**
 * REVIEWER AUTHORIZATION PRIMITIVE.
 *
 * A Department Head owns a class through `classes.user_id` — the same mechanism
 * depthead/index.php already uses to count "classes managed by this dept head".
 * user_account has no department column, so this IS the departmental boundary.
 * Super Admin (the Principal) sees everything.
 *
 * Fails closed.
 */
function review_can_manage_class(mysqli $db, int $classId, array $user): bool
{
    if ($classId <= 0) {
        return false;
    }
    if (is_super_admin($user)) {
        return true;
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM classes WHERE Class_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $classId, $uid);
    $stmt->execute();
    return stmt_fetch_assoc($stmt) !== null;
}

function require_review_rights(mysqli $db, int $classId, array $user): void
{
    if (!review_can_manage_class($db, $classId, $user)) {
        flash_set('error', 'That class is not in your department.');
        redirect_to(app_url('depthead/grade_review.php'));
    }
}

/**
 * The reviewer's queue: classes this dept head manages, with their submission
 * state for one grading period. Ordered so classes awaiting review come first.
 */
function review_queue(mysqli $db, array $user, int $syId, int $periodId): array
{
    $superAdmin = is_super_admin($user);
    $uid        = (int) ($user['id'] ?? 0);
    $termFilter = _term_class_filter($db, $syId, $periodId);   // SHS: only this Term's classes

    $sql =
        "SELECT c.Class_id, c.Time,
                s.Subject_name, sec.Section_name, gl.Gradelevel,
                t.Fullname AS teacher_name, t.Firstname AS t_first, t.Lastname AS t_last,
                (SELECT COUNT(*) FROM studentinfo si WHERE si.Section = c.Section_id AND si.School_year_id = c.School_year_id) AS student_count,
                SUM(sg.grade IS NOT NULL)              AS encoded,
                SUM(sg.status = 'Draft')               AS draft,
                SUM(sg.status = 'Submitted')           AS submitted,
                SUM(sg.status = 'Approved')            AS approved,
                SUM(sg.status = 'Returned')            AS returned,
                SUM(sg.status = 'Locked')              AS locked
         FROM classes c
         LEFT JOIN student_grade sg ON sg.class_id = c.Class_id AND sg.grading_period_id = ?
         LEFT JOIN subject    s   ON s.Subject_id    = c.Subject_id
         LEFT JOIN section    sec ON sec.Section_id  = c.Section_id
         LEFT JOIN gradelevel gl  ON gl.Gradelevel_id = c.GradeLevel_id
         LEFT JOIN teacher    t   ON t.Teacher_id    = c.Teacher_id
         WHERE c.School_year_id = ? AND COALESCE(s.is_academic, 1) = 1" . $termFilter . ($superAdmin ? '' : ' AND c.user_id = ?') .
        " GROUP BY c.Class_id
          ORDER BY submitted DESC, encoded DESC, gl.Gradelevel_id, sec.Section_name";

    $stmt = $db->prepare($sql);
    if ($superAdmin) {
        $stmt->bind_param('ii', $periodId, $syId);
    } else {
        $stmt->bind_param('iii', $periodId, $syId, $uid);
    }
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);

    foreach ($rows as &$r) {
        $r['teacher_display'] = trim((string) ($r['teacher_name'] ?: ($r['t_first'] . ' ' . $r['t_last']))) ?: '—';
        $sub = (int) $r['submitted'];
        $app = (int) $r['approved'];
        $ret = (int) $r['returned'];
        $enc = (int) $r['encoded'];
        $r['review_state'] = $sub > 0 ? 'Awaiting Review'
            : ($ret > 0 ? 'Returned'
            : (($app > 0 && $app >= $enc && $enc > 0) ? 'Approved'
            : ($enc > 0 ? 'In Progress' : 'Not Started')));
    }
    return $rows;
}

/** Counters for the reviewer dashboard header. */
function review_stats(mysqli $db, array $user, int $syId, int $periodId): array
{
    $out = ['awaiting' => 0, 'approved' => 0, 'returned' => 0, 'classes' => 0];
    foreach (review_queue($db, $user, $syId, $periodId) as $r) {
        $out['classes']++;
        if ($r['review_state'] === 'Awaiting Review') { $out['awaiting']++; }
        elseif ($r['review_state'] === 'Approved')    { $out['approved']++; }
        elseif ($r['review_state'] === 'Returned')    { $out['returned']++; }
    }
    return $out;
}

/**
 * Approve every submitted grade in a class for a period.
 * Approved grades leave the teacher's hands — grade_save() refuses them.
 */
function review_approve_class(mysqli $db, int $classId, int $periodId, array $user): int
{
    if (!review_can_manage_class($db, $classId, $user)) {
        throw new RuntimeException('That class is not in your department.');
    }
    $period = gp_get($db, $periodId);
    if (!$period) {
        throw new RuntimeException('Grading period not found.');
    }
    if ((string) $period['status'] === 'Locked') {
        throw new RuntimeException('This grading period is locked.');
    }

    $uid  = (int) ($user['id'] ?? 0);
    $name = (string) ($user['full_name'] ?? 'Department Head');

    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "UPDATE student_grade
                SET status = 'Approved', reviewed_by = ?, reviewed_at = NOW(), review_note = NULL
              WHERE class_id = ? AND grading_period_id = ? AND status = 'Submitted'"
        );
        $stmt->bind_param('iii', $uid, $classId, $periodId);
        $stmt->execute();
        $n = $db->affected_rows;

        if ($n > 0) {
            _review_audit($db, $classId, $periodId, 'Approve', $uid, $name, 'Approved by ' . $name);
        }
        $db->commit();
        return $n;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Send a class back to the teacher with a reason. Grades become editable again.
 * The reason is stored on the row so the teacher sees WHY, not just that it bounced.
 */
function review_return_class(mysqli $db, int $classId, int $periodId, string $reason, array $user): int
{
    if (!review_can_manage_class($db, $classId, $user)) {
        throw new RuntimeException('That class is not in your department.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Please give the teacher a reason for returning these grades.');
    }
    $period = gp_get($db, $periodId);
    if (!$period || (string) $period['status'] === 'Locked') {
        throw new RuntimeException('This grading period is locked.');
    }

    $uid  = (int) ($user['id'] ?? 0);
    $name = (string) ($user['full_name'] ?? 'Department Head');

    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "UPDATE student_grade
                SET status = 'Returned', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
              WHERE class_id = ? AND grading_period_id = ? AND status IN ('Submitted','Approved')"
        );
        $stmt->bind_param('isii', $uid, $reason, $classId, $periodId);
        $stmt->execute();
        $n = $db->affected_rows;

        if ($n > 0) {
            _review_audit($db, $classId, $periodId, 'Return', $uid, $name, 'Returned: ' . $reason);
        }
        $db->commit();
        return $n;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * A reviewer editing a grade directly. Authorization is class-management, not
 * class-teaching. The edit is attributed to the reviewer in the audit trail.
 * Reviewers may correct anything that is not Locked.
 */
function grade_save_reviewer(
    mysqli $db,
    int $classId,
    int $studentId,
    int $periodId,
    ?float $grade,
    array $user,
    ?string $remarks = null
): string {
    if (!review_can_manage_class($db, $classId, $user)) {
        throw new RuntimeException('That class is not in your department.');
    }
    $period = gp_get($db, $periodId);
    if (!$period) {
        throw new RuntimeException('Grading period not found.');
    }
    if ((string) $period['status'] === 'Locked') {
        throw new RuntimeException('This grading period is locked.');
    }
    $cls = teacher_class_get($db, $classId);
    if (!$cls || (int) $period['school_year_id'] !== (int) $cls['School_year_id']) {
        throw new RuntimeException('That grading period does not belong to this class\'s school year.');
    }
    if (!grade_student_in_class($db, $classId, $studentId)) {
        throw new RuntimeException('That student is not enrolled in this class.');
    }
    $existing = grade_get($db, $classId, $studentId, $periodId);
    if ($existing && (string) $existing['status'] === 'Locked') {
        throw new RuntimeException('That grade is locked.');
    }

    // A reviewer's edit stands as reviewed work, not as a new teacher draft.
    $keep = $existing ? (string) $existing['status'] : 'Submitted';
    $next = in_array($keep, ['Draft', 'Returned'], true) ? $keep : 'Submitted';

    return _grade_write($db, $classId, $studentId, $periodId, $grade, $remarks, $user, $next, 'Reviewer');
}

/** Audit a class-wide review action against every affected grade row. */
function _review_audit(mysqli $db, int $classId, int $periodId, string $action, int $uid, string $name, string $note): void
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
    $stmt = $db->prepare(
        "INSERT INTO student_grade_history
            (student_grade_id, class_id, student_id, grading_period_id, action,
             old_grade, new_grade, changed_by, changed_by_name, ip_address, note)
         SELECT sg.id, sg.class_id, sg.student_id, sg.grading_period_id, ?,
                sg.grade, sg.grade, ?, ?, ?, ?
         FROM student_grade sg
         WHERE sg.class_id = ? AND sg.grading_period_id = ?"
    );
    $stmt->bind_param('sisssii', $action, $uid, $name, $ip, $note, $classId, $periodId);
    $stmt->execute();
}

/** Tailwind badge for a grade row / class review state. */
function grade_state_badge(string $state): string
{
    return match ($state) {
        'Approved'        => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Awaiting Review',
        'Submitted'       => 'bg-green-100 text-green-800 border-green-300',
        'Returned'        => 'bg-amber-100 text-amber-800 border-amber-300',
        'Locked'          => 'bg-rose-100 text-rose-800 border-rose-300',
        'In Progress',
        'Draft'           => 'bg-slate-100 text-slate-600 border-slate-300',
        default           => 'bg-slate-100 text-slate-400 border-slate-200',
    };
}

// ── Grade slip release (Department Head publishes to students) ──────────────

/** Passing mark. DepEd standard. */
const GRADE_PASSING = 75.0;

function release_schema_ready(mysqli $db): bool
{
    $r = $db->query("SHOW TABLES LIKE 'grade_release'");
    return $r && $r->num_rows > 0;
}

/** The release row for one head + period, or null. */
function release_get(mysqli $db, int $periodId, int $ownerUserId): ?array
{
    if (!release_schema_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM grade_release WHERE grading_period_id = ? AND owner_user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $periodId, $ownerUserId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

function release_is_active(?array $row): bool
{
    return $row !== null && (string) $row['status'] === 'Released';
}

/**
 * Publish grade slips for this head's classes in this period.
 * Only Approved/Locked grades become visible (enforced in student_grade_slip).
 */
function release_publish(mysqli $db, int $periodId, array $user, string $note = ''): int
{
    if (!release_schema_ready($db)) {
        throw new RuntimeException('Run migrations/grade_release.sql first.');
    }
    $period = gp_get($db, $periodId);
    if (!$period) {
        throw new RuntimeException('Grading period not found.');
    }
    $owner = (int) ($user['id'] ?? 0);
    $name  = (string) ($user['full_name'] ?? 'Department Head');
    $syId  = (int) $period['school_year_id'];
    $note  = trim($note) ?: null;

    // How many students will actually see something? Publishing an empty period
    // is almost always a mistake, so tell the caller the number.
    $cnt = $db->prepare(
        "SELECT COUNT(DISTINCT sg.student_id) c
         FROM student_grade sg
         JOIN classes cl ON cl.Class_id = sg.class_id
         WHERE sg.grading_period_id = ? AND cl.user_id = ?
           AND sg.status IN ('Approved','Locked') AND sg.grade IS NOT NULL"
    );
    $cnt->bind_param('ii', $periodId, $owner);
    $cnt->execute();
    $students = (int) (stmt_fetch_assoc($cnt)['c'] ?? 0);

    $stmt = $db->prepare(
        "INSERT INTO grade_release
            (school_year_id, grading_period_id, owner_user_id, status, note,
             released_by, released_by_name, released_at)
         VALUES (?, ?, ?, 'Released', ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            status = 'Released', note = VALUES(note),
            released_by = VALUES(released_by), released_by_name = VALUES(released_by_name),
            released_at = NOW(), withdrawn_by = NULL, withdrawn_at = NULL"
    );
    $stmt->bind_param('iiisis', $syId, $periodId, $owner, $note, $owner, $name);
    $stmt->execute();

    soa_audit($db, $owner, $name, 'GRADE_RELEASE', 'grading_period', (string) $periodId, null,
        json_encode(['period' => $period['name'], 'students_visible' => $students]));

    return $students;
}

/** Withdraw a publication — slips disappear from the student portal again. */
function release_withdraw(mysqli $db, int $periodId, array $user, string $reason = ''): void
{
    $owner = (int) ($user['id'] ?? 0);
    $name  = (string) ($user['full_name'] ?? 'Department Head');
    $row   = release_get($db, $periodId, $owner);
    if (!$row) {
        throw new RuntimeException('These grade slips have not been published.');
    }
    $stmt = $db->prepare(
        "UPDATE grade_release SET status='Withdrawn', withdrawn_by=?, withdrawn_at=NOW(), note=?
         WHERE grading_period_id = ? AND owner_user_id = ?"
    );
    $reason = trim($reason) ?: null;
    $stmt->bind_param('isii', $owner, $reason, $periodId, $owner);
    $stmt->execute();

    soa_audit($db, $owner, $name, 'GRADE_WITHDRAW', 'grading_period', (string) $periodId,
        json_encode(['status' => 'Released']), json_encode(['status' => 'Withdrawn', 'reason' => $reason]));
}

/**
 * THE STUDENT-FACING QUERY — one grading period's slip for one student.
 *
 * Returns every subject the student is enrolled in for the period. A grade is
 * revealed only when its class owner has published AND the grade is Approved or
 * Locked; otherwise the row shows as pending. That keeps the subject list honest
 * (the student sees all their subjects) without leaking unreviewed marks.
 *
 * @return array{released:bool, rows:array, average:float|null, complete:bool,
 *               released_at:?string, subjects:int, graded:int}
 */
function student_grade_slip(mysqli $db, int $studentInfoId, int $periodId): array
{
    $out = ['released' => false, 'rows' => [], 'average' => null, 'complete' => false,
            'released_at' => null, 'subjects' => 0, 'graded' => 0];

    if ($studentInfoId <= 0 || $periodId <= 0 || !release_schema_ready($db)) {
        return $out;
    }

    $stmt = $db->prepare(
        "SELECT s.Subject_name, s.subject_code,
                t.Fullname AS teacher_name, t.Firstname AS t_first, t.Lastname AS t_last,
                sg.grade, sg.remarks, sg.status AS grade_status,
                r.status AS release_status, r.released_at
         FROM studentinfo si
         JOIN classes cl        ON cl.Section_id = si.Section AND cl.School_year_id = si.School_year_id
         LEFT JOIN subject s    ON s.Subject_id = cl.Subject_id
         LEFT JOIN teacher t    ON t.Teacher_id = cl.Teacher_id
         LEFT JOIN student_grade sg
                ON sg.class_id = cl.Class_id
               AND sg.student_id = si.student_id
               AND sg.grading_period_id = ?
         LEFT JOIN grade_release r
                ON r.grading_period_id = ?
               AND r.owner_user_id = cl.user_id
               AND r.status = 'Released'
         WHERE si.student_id = ?
         ORDER BY s.Subject_name"
    );
    $stmt->bind_param('iii', $periodId, $periodId, $studentInfoId);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);

    $sum = 0.0; $n = 0; $anyReleased = false; $releasedAt = null;

    foreach ($rows as $r) {
        $isReleased = (string) ($r['release_status'] ?? '') === 'Released';
        $isFinal    = in_array((string) ($r['grade_status'] ?? ''), ['Approved', 'Locked'], true);
        $visible    = $isReleased && $isFinal && $r['grade'] !== null;

        if ($isReleased) {
            $anyReleased = true;
            $releasedAt  = $releasedAt ?: ($r['released_at'] ?? null);
        }

        $grade = $visible ? (float) $r['grade'] : null;
        if ($grade !== null) {
            $sum += $grade; $n++;
        }

        $out['rows'][] = [
            'subject' => (string) ($r['Subject_name'] ?: '—'),
            'code'    => (string) ($r['subject_code'] ?? ''),
            'teacher' => trim((string) ($r['teacher_name'] ?: ($r['t_first'] . ' ' . $r['t_last']))) ?: '—',
            'grade'   => $grade,
            'remarks' => $grade === null
                ? null
                : ((string) ($r['remarks'] ?? '') ?: ($grade >= GRADE_PASSING ? 'Passed' : 'Failed')),
            'pending' => !$visible,
        ];
    }

    $out['subjects']    = count($rows);
    $out['graded']      = $n;
    $out['released']    = $anyReleased;
    $out['released_at'] = $releasedAt;
    $out['average']     = $n > 0 ? round($sum / $n, 2) : null;
    $out['complete']    = $n > 0 && $n === count($rows);

    return $out;
}

/** Periods with at least one published release — the student's period tabs. */
function student_released_periods(mysqli $db, int $syId): array
{
    if (!release_schema_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT DISTINCT gp.* FROM grading_period gp
         JOIN grade_release r ON r.grading_period_id = gp.id AND r.status = 'Released'
         WHERE gp.school_year_id = ? ORDER BY gp.term_no"
    );
    $stmt->bind_param('i', $syId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Descriptor for an average (DepEd-style bands). */
function grade_descriptor(?float $g): string
{
    if ($g === null)  { return '—'; }
    if ($g >= 90)     { return 'Outstanding'; }
    if ($g >= 85)     { return 'Very Satisfactory'; }
    if ($g >= 80)     { return 'Satisfactory'; }
    if ($g >= GRADE_PASSING) { return 'Fairly Satisfactory'; }
    return 'Did Not Meet Expectations';
}

// ── Audit ───────────────────────────────────────────────────────────────────

/** Write one grade-change audit row (requirement #6). */
function grade_audit(
    mysqli $db,
    int $gradeId,
    int $classId,
    int $studentId,
    int $periodId,
    string $action,
    ?float $oldGrade,
    ?float $newGrade,
    int $userId,
    string $userName,
    ?string $note = null
): void {
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;

    $stmt = $db->prepare(
        'INSERT INTO student_grade_history
            (student_grade_id, class_id, student_id, grading_period_id, action,
             old_grade, new_grade, changed_by, changed_by_name, ip_address, user_agent, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iiiisddissss',
        $gradeId, $classId, $studentId, $periodId, $action,
        $oldGrade, $newGrade, $userId, $userName, $ip, $ua, $note
    );
    $stmt->execute();
}

/** Audit a period-wide action (lock/unlock/submit) without a single grade row. */
function grade_audit_period(
    mysqli $db,
    int $periodId,
    string $action,
    string $from,
    string $to,
    int $userId,
    string $userName,
    int $classId = 0
): void {
    $ip   = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
    $note = "Grading period #$periodId: $from -> $to";

    // Attach to the affected grades so the trail is discoverable per student.
    $sql = "INSERT INTO student_grade_history
              (student_grade_id, class_id, student_id, grading_period_id, action,
               old_grade, new_grade, changed_by, changed_by_name, ip_address, note)
            SELECT sg.id, sg.class_id, sg.student_id, sg.grading_period_id, ?,
                   sg.grade, sg.grade, ?, ?, ?, ?
            FROM student_grade sg
            WHERE sg.grading_period_id = ?" . ($classId > 0 ? ' AND sg.class_id = ?' : '');

    $stmt = $db->prepare($sql);
    if ($classId > 0) {
        $stmt->bind_param('sisssii', $action, $userId, $userName, $ip, $note, $periodId, $classId);
    } else {
        $stmt->bind_param('sisssi', $action, $userId, $userName, $ip, $note, $periodId);
    }
    $stmt->execute();
}

/** Audit trail for one student's grade in one class+period. */
function grade_history(mysqli $db, int $classId, int $studentId, int $periodId = 0): array
{
    $sql = 'SELECT h.*, gp.name AS period_name
            FROM student_grade_history h
            LEFT JOIN grading_period gp ON gp.id = h.grading_period_id
            WHERE h.class_id = ? AND h.student_id = ?'
         . ($periodId > 0 ? ' AND h.grading_period_id = ?' : '')
         . ' ORDER BY h.changed_at DESC, h.id DESC LIMIT 200';

    $stmt = $db->prepare($sql);
    if ($periodId > 0) {
        $stmt->bind_param('iii', $classId, $studentId, $periodId);
    } else {
        $stmt->bind_param('ii', $classId, $studentId);
    }
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}
