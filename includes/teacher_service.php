<?php

declare(strict_types=1);

/**
 * Teacher Module — data/service layer.
 *
 * All teacher-scoped SQL lives here. Pages call these; pages contain no SQL.
 * Every function that returns class-scoped data takes $teacherId and filters on
 * classes.Teacher_id — the module has no way to express "all classes".
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
// soa_audit() lives here — teacher_set_status() writes to the shared audit log.
// Loading it explicitly rather than relying on the caller: a missing function is
// a fatal that a page-level catch(Throwable) would disguise as a form error.
require_once __DIR__ . '/soa_service.php';

function teacher_schema_ready(mysqli $db): bool
{
    $need = ['grading_period', 'student_grade', 'student_grade_history', 'advisory_class', 'class_schedule'];
    foreach ($need as $t) {
        $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
        if (!$r || $r->num_rows === 0) {
            return false;
        }
    }
    return true;
}

/** The active school year as ['id'=>int,'label'=>string]. */
function teacher_active_sy(mysqli $db): array
{
    $r   = $db->query('SELECT School_year_id, School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1');
    $row = $r ? $r->fetch_assoc() : null;
    return $row
        ? ['id' => (int) $row['School_year_id'], 'label' => (string) $row['School_year']]
        : ['id' => 0, 'label' => ''];
}

/** Resolve the teacher behind a login. Returns null if the account is not a teacher. */
function teacher_by_user_id(mysqli $db, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT Teacher_id, Fullname, Firstname, Middlename, Lastname, Designation,
                employee_no, email, contact, status, user_id
         FROM teacher WHERE user_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * Display name. The atomic columns are the source of truth — `Fullname` is a
 * legacy derived column and the data is dirty (some rows have the licence
 * suffix parsed into Lastname), so prefer Fullname only when it is populated.
 */
function teacher_display_name(array $t): string
{
    $full = trim((string) ($t['Fullname'] ?? ''));
    if ($full !== '' && strtoupper($full) !== 'N/A') {
        return $full;
    }
    return trim(((string) ($t['Firstname'] ?? '')) . ' ' . ((string) ($t['Lastname'] ?? ''))) ?: 'Teacher';
}

/** The teacher's advisory class for a school year, or null. */
function teacher_advisory(mysqli $db, int $teacherId, int $syId): ?array
{
    $stmt = $db->prepare(
        'SELECT a.id, a.section_id, a.gradelevel_id,
                sec.Section_name, gl.Gradelevel,
                (SELECT COUNT(*) FROM studentinfo si
                  WHERE si.Section = a.section_id AND si.School_year_id = a.school_year_id) AS student_count
         FROM advisory_class a
         LEFT JOIN section sec  ON sec.Section_id = a.section_id
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = a.gradelevel_id
         WHERE a.teacher_id = ? AND a.school_year_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $teacherId, $syId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

// ── Advisee management (the adviser correcting their advisory students' info) ──
//
// An adviser owns exactly one section per school year (advisory_class UNIQUE on
// school_year_id + section_id). Their "advisees" are the studentinfo rows placed
// in that section for that S.Y. An adviser may correct a student's IDENTITY —
// name and LRN — because those are what get mis-typed on the masterlist and then
// flow onto rosters, grade slips and certificates. Placement fields (section,
// grade level, enrollment status) are deliberately NOT editable here: moving a
// student is an enrollment decision that belongs to the Registrar, and it would
// also move the student out of the adviser's own advisory.

/**
 * AUTHORIZATION PRIMITIVE for advisee edits — the mirror of teacher_owns_class().
 *
 * True only when $studentId is a studentinfo row sitting in the section this
 * teacher advises for $syId. Ownership is derived from advisory_class against the
 * Teacher_id resolved from the SESSION — never from a form field. Fails closed.
 */
function teacher_advises_student(mysqli $db, int $studentId, int $teacherId, int $syId): bool
{
    if ($studentId <= 0 || $teacherId <= 0 || $syId <= 0) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1
           FROM studentinfo si
           JOIN advisory_class a
             ON a.section_id = si.Section
            AND a.school_year_id = si.School_year_id
          WHERE si.student_id = ?
            AND a.teacher_id = ?
            AND a.school_year_id = ?
          LIMIT 1'
    );
    $stmt->bind_param('iii', $studentId, $teacherId, $syId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt) !== null;
}

/**
 * The adviser's advisees for a school year — the students in their advisory
 * section. Empty when the teacher is not an adviser this S.Y. Keyed on the
 * teacher's advisory_class row so the list can never leak another section.
 */
function teacher_advisees(mysqli $db, int $teacherId, int $syId, string $q = ''): array
{
    $sql =
        "SELECT si.student_id, si.LRN_no, si.Lastname, si.Firstname, si.Middlename, si.Status
         FROM advisory_class a
         JOIN studentinfo si
           ON si.Section = a.section_id AND si.School_year_id = a.school_year_id
         WHERE a.teacher_id = ? AND a.school_year_id = ?";
    $types  = 'ii';
    $params = [$teacherId, $syId];

    $q = trim($q);
    if ($q !== '') {
        $sql   .= " AND (si.Lastname LIKE ? OR si.Firstname LIKE ? OR si.Middlename LIKE ? OR CAST(si.LRN_no AS CHAR) LIKE ?)";
        $like   = '%' . $q . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY si.Lastname, si.Firstname";

    $stmt = $db->prepare($sql);
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** One advisee's editable identity row. Ownership is the CALLER's job. */
function teacher_advisee_get(mysqli $db, int $studentId): ?array
{
    $stmt = $db->prepare(
        'SELECT student_id, LRN_no, Lastname, Firstname, Middlename,
                Department, Gradelevel, Section, School_year_id, Status
         FROM studentinfo WHERE student_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * Resolve an advisee's full enrollment-side profile for a school year.
 *
 * The teacher module lives in the `studentinfo` masterlist, but the rich profile
 * (birthdate, sex, contact, address, parents, photo) lives in the enrollment
 * world: `preregistration` for New students, `old_studentprofile` for Old ones.
 * The bridge is the LRN, disambiguated THROUGH the active-S.Y. `enrollment` row —
 * the same New-then-Old precedence the cashier and student portal use, so an
 * adviser's correction lands on the exact record everyone else reads.
 *
 * Returns null when the masterlist row cannot be matched to a profile (≈2.7% of
 * rows have no LRN match) — the caller then offers name/LRN editing only.
 *
 * @return array<string,mixed>|null  includes `enrollment_id`, `src` (New|Old),
 *         `prereg_id`, `old_row_id`, and every profile field.
 */
function teacher_advisee_profile(mysqli $db, string $lrn, string $syLabel): ?array
{
    $lrn = trim($lrn);
    if ($lrn === '' || $syLabel === '') {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT e.id AS enrollment_id, e.student_id, e.Status AS enrollment_status,
                IF(p.id IS NOT NULL,'New','Old') AS src,
                p.id AS prereg_id, osp.id AS old_row_id,
                COALESCE(p.lrn, osp.lrn)                 AS lrn,
                COALESCE(p.surname, osp.surname)         AS surname,
                COALESCE(p.firstname, osp.firstname)     AS firstname,
                COALESCE(p.middlename, osp.middlename)   AS middlename,
                COALESCE(p.contact, osp.contact)         AS contact,
                COALESCE(p.email, osp.email)             AS email,
                COALESCE(p.sex, osp.sex)                 AS sex,
                COALESCE(p.birthdate, osp.birthdate)     AS birthdate,
                COALESCE(p.birthplace, osp.birthplace)   AS birthplace,
                COALESCE(p.province, osp.province)       AS province,
                COALESCE(p.municipality, osp.municipality) AS municipality,
                COALESCE(p.barangay, osp.barangay)       AS barangay,
                COALESCE(p.previous_school, osp.previous_school) AS previous_school,
                COALESCE(p.year_graduated, osp.year_graduated)   AS year_graduated,
                COALESCE(p.father_name, osp.father_name)     AS father_name,
                COALESCE(p.father_contact, osp.father_contact) AS father_contact,
                COALESCE(p.mother_name, osp.mother_name)     AS mother_name,
                COALESCE(p.mother_contact, osp.mother_contact) AS mother_contact,
                COALESCE(p.parent_address, osp.parent_address) AS parent_address,
                COALESCE(p.photo, '')                    AS photo
         FROM enrollment e
         LEFT JOIN preregistration p      ON e.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = e.student_id)
         WHERE e.school_year = ? AND COALESCE(p.lrn, osp.lrn) = ?
         ORDER BY e.id DESC LIMIT 1"
    );
    $stmt->bind_param('ss', $syLabel, $lrn);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * The advisee's photo URL, using the SAME resolution the student portal uses
 * (an adviser/portal upload at uploads/student_photos/{enrollment_id} wins, then
 * the legacy preregistration photo), so a photo uploaded here shows on the portal
 * too. Returns null when neither exists (caller shows an initials avatar).
 */
function teacher_advisee_photo_url(int $enrollmentId, string $legacyPhoto = ''): ?string
{
    if ($enrollmentId <= 0) {
        return null;
    }
    $root = dirname(__DIR__);
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $rel = 'uploads/student_photos/' . $enrollmentId . '.' . $ext;
        if (is_file($root . '/' . $rel)) {
            return app_url($rel) . '?v=' . filemtime($root . '/' . $rel);
        }
    }
    $legacy = trim($legacyPhoto);
    if ($legacy !== '') {
        foreach (['uploads/' . $legacy, 'admission/uploads/' . $legacy, $legacy] as $rel) {
            if (is_file($root . '/' . $rel)) {
                return app_url($rel);
            }
        }
    }
    return null;
}

/**
 * Store an adviser-uploaded advisee photo at uploads/student_photos/{enrollment_id}.{ext}
 * — the portal's own photo slot, so the picture is shared, not a teacher-only copy.
 * Mirrors the validation used for teacher photos.
 */
function teacher_store_advisee_photo(array $file, int $enrollmentId): void
{
    if ($enrollmentId <= 0) {
        throw new RuntimeException('This student has no linked enrollment record, so a photo cannot be attached here.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please choose an image to upload.');
    }
    if ((int) $file['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('Image must be 3 MB or smaller.');
    }
    $info    = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
    }
    $ext = $allowed[$info[2]];
    $dir = dirname(__DIR__) . '/uploads/student_photos';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $old) {
        $p = "$dir/$enrollmentId.$old";
        if (is_file($p)) { @unlink($p); }
    }
    if (!move_uploaded_file($file['tmp_name'], "$dir/$enrollmentId.$ext")) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
}

/** Remove an adviser/portal-uploaded advisee photo (the legacy prereg photo is left alone). */
function teacher_remove_advisee_photo(int $enrollmentId): void
{
    if ($enrollmentId <= 0) {
        return;
    }
    $dir = dirname(__DIR__) . '/uploads/student_photos';
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $e) {
        @unlink("$dir/$enrollmentId.$e");
    }
}

/**
 * Correct an advisee's identity + contact + address. THE ONLY write path for
 * advisee info. Writes the masterlist row (`studentinfo`, which the rosters and
 * grade slips read) AND — when the student is matched to a profile — the shared
 * enrollment profile the cashier and portal read, in one transaction so the two
 * worlds never disagree. Birthdate / sex / parents are shown but NOT written here.
 *
 * Gates, in order — authorization before validation, both before any write:
 *   a. the student is one of THIS teacher's advisees (session-derived)
 *   b. names present; whitespace normalized but case preserved
 *   c. LRN numeric and not already used by a different masterlist student
 *   d. email, if given, is well-formed
 */
function teacher_update_advisee(mysqli $db, int $studentId, int $teacherId, int $syId, string $syLabel, array $in, array $user): void
{
    // a. AUTHORIZE FIRST — never trust the posted student id.
    if (!teacher_advises_student($db, $studentId, $teacherId, $syId)) {
        throw new RuntimeException('That student is not one of your advisees.');
    }

    $before = teacher_advisee_get($db, $studentId);
    if (!$before) {
        throw new RuntimeException('Student not found.');
    }
    $profile = teacher_advisee_profile($db, (string) ($before['LRN_no'] ?? ''), $syLabel);

    // b. Names. Collapse internal whitespace and trim (this is what fixes the
    // "  YUSOP" leading-space dirt) but NEVER change case — "H.Salik" and "MAEd"
    // must survive a save untouched.
    $last  = preg_replace('/\s+/', ' ', trim((string) ($in['Lastname'] ?? '')));
    $first = preg_replace('/\s+/', ' ', trim((string) ($in['Firstname'] ?? '')));
    $mid   = preg_replace('/\s+/', ' ', trim((string) ($in['Middlename'] ?? '')));
    if ($last === '' || $first === '') {
        throw new RuntimeException('Last name and first name are required.');
    }
    $midInfo = $mid === '' ? null : $mid;   // studentinfo Middlename is nullable

    // c. LRN — numeric (bigint column), up to 20 digits, or blank. Guard against
    // typing a value that already belongs to a different masterlist student.
    $lrnRaw = trim((string) ($in['LRN_no'] ?? ''));
    $lrn    = null;
    if ($lrnRaw !== '') {
        if (!preg_match('/^\d{1,20}$/', $lrnRaw)) {
            throw new RuntimeException('LRN must be numbers only (up to 20 digits), or left blank.');
        }
        $lrn = $lrnRaw;
        $chk = $db->prepare('SELECT student_id FROM studentinfo WHERE LRN_no = ? AND student_id <> ? LIMIT 1');
        $chk->bind_param('si', $lrn, $studentId);
        $chk->execute();
        if (stmt_fetch_assoc($chk) !== null) {
            throw new RuntimeException('Another student already has that LRN — please re-check it.');
        }
    }

    // d. Contact / email / address (profile-only fields).
    $contact = trim((string) ($in['contact'] ?? ''));
    $email   = trim((string) ($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address, or leave it blank.');
    }
    $province = trim((string) ($in['province'] ?? ''));
    $munic    = trim((string) ($in['municipality'] ?? ''));
    $barangay = trim((string) ($in['barangay'] ?? ''));

    $db->begin_transaction();
    try {
        // The masterlist row — always present; keeps rosters/grade slips correct.
        $a = $db->prepare('UPDATE studentinfo SET Lastname = ?, Firstname = ?, Middlename = ?, LRN_no = ? WHERE student_id = ?');
        $a->bind_param('ssssi', $last, $first, $midInfo, $lrn, $studentId);
        $a->execute();

        // The shared enrollment profile — only when matched. Same columns exist on
        // both preregistration and old_studentprofile (NOT NULL on New → bind '').
        if ($profile) {
            $lrnStr = $lrn ?? '';
            $midStr = $mid;   // '' allowed on the profile tables
            $table  = ((string) $profile['src'] === 'New') ? 'preregistration' : 'old_studentprofile';
            $rowId  = ((string) $profile['src'] === 'New') ? (int) $profile['prereg_id'] : (int) $profile['old_row_id'];
            $b = $db->prepare(
                "UPDATE `$table`
                    SET surname = ?, firstname = ?, middlename = ?, lrn = ?,
                        contact = ?, email = ?, province = ?, municipality = ?, barangay = ?
                  WHERE id = ?"
            );
            $b->bind_param('sssssssssi', $last, $first, $midStr, $lrnStr, $contact, $email, $province, $munic, $barangay, $rowId);
            $b->execute();
        }

        soa_audit(
            $db,
            (int) ($user['id'] ?? 0),
            (string) ($user['full_name'] ?? 'Teacher'),
            'ADVISEE_UPDATE',
            'studentinfo',
            (string) $studentId,
            json_encode([
                'LRN_no' => $before['LRN_no'], 'Lastname' => $before['Lastname'],
                'Firstname' => $before['Firstname'], 'Middlename' => $before['Middlename'],
                'contact' => $profile['contact'] ?? null, 'email' => $profile['email'] ?? null,
                'province' => $profile['province'] ?? null, 'municipality' => $profile['municipality'] ?? null,
                'barangay' => $profile['barangay'] ?? null,
            ]),
            json_encode([
                'LRN_no' => $lrn, 'Lastname' => $last, 'Firstname' => $first, 'Middlename' => $midInfo,
                'contact' => $contact, 'email' => $email,
                'province' => $province, 'municipality' => $munic, 'barangay' => $barangay,
                'profile_written' => $profile ? $profile['src'] : 'none',
            ])
        );

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * The teacher's classes for a school year — THE source of the class list.
 * Includes the roster size and how many grades are already encoded for $periodId.
 */
function teacher_classes(mysqli $db, int $teacherId, int $syId, int $periodId = 0): array
{
    $stmt = $db->prepare(
        "SELECT c.Class_id, c.Time, c.class_status, c.Section_id, c.Subject_id, c.GradeLevel_id,
                c.Semester_id, c.room_id,
                s.Subject_name, s.subject_code,
                sec.Section_name,
                gl.Gradelevel,
                sem.Semester,
                st.strand,
                r.room AS room_name,
                (SELECT COUNT(*) FROM student_classes sc WHERE sc.class_id = c.Class_id) AS student_count,
                (SELECT COUNT(*) FROM student_grade sg
                  WHERE sg.class_id = c.Class_id AND sg.grading_period_id = ? AND sg.grade IS NOT NULL) AS graded_count
         FROM classes c
         LEFT JOIN subject    s   ON s.Subject_id    = c.Subject_id
         LEFT JOIN section    sec ON sec.Section_id  = c.Section_id
         LEFT JOIN gradelevel gl  ON gl.Gradelevel_id = c.GradeLevel_id
         LEFT JOIN semester   sem ON sem.Semester_id = c.Semester_id
         LEFT JOIN strand     st  ON st.strand_id    = c.strand_id
         LEFT JOIN room       r   ON r.room_id       = c.room_id
         WHERE c.Teacher_id = ? AND c.School_year_id = ?
         ORDER BY gl.Gradelevel_id, sec.Section_name, s.Subject_name"
    );
    $stmt->bind_param('iii', $periodId, $teacherId, $syId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** One class with its display context — already ownership-checked by the caller. */
function teacher_class_get(mysqli $db, int $classId): ?array
{
    $stmt = $db->prepare(
        "SELECT c.Class_id, c.Time, c.class_status, c.School_year_id, c.Teacher_id, c.room_id,
                s.Subject_name, s.subject_code,
                sec.Section_name, gl.Gradelevel, sem.Semester, r.room AS room_name,
                sy.School_year
         FROM classes c
         LEFT JOIN subject    s   ON s.Subject_id    = c.Subject_id
         LEFT JOIN section    sec ON sec.Section_id  = c.Section_id
         LEFT JOIN gradelevel gl  ON gl.Gradelevel_id = c.GradeLevel_id
         LEFT JOIN semester   sem ON sem.Semester_id = c.Semester_id
         LEFT JOIN room       r   ON r.room_id       = c.room_id
         LEFT JOIN schoolyear sy  ON sy.School_year_id = c.School_year_id
         WHERE c.Class_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * Weekly schedule. Prefers the normalized class_schedule rows; falls back to the
 * legacy free-text classes.Time when a class has not been scheduled properly yet.
 */
function teacher_schedule(mysqli $db, int $teacherId, int $syId): array
{
    $stmt = $db->prepare(
        "SELECT cs.day_of_week, cs.start_time, cs.end_time,
                c.Class_id, s.Subject_name, sec.Section_name, gl.Gradelevel,
                r.room AS room_name
         FROM class_schedule cs
         JOIN classes c ON c.Class_id = cs.class_id
         LEFT JOIN subject    s   ON s.Subject_id    = c.Subject_id
         LEFT JOIN section    sec ON sec.Section_id  = c.Section_id
         LEFT JOIN gradelevel gl  ON gl.Gradelevel_id = c.GradeLevel_id
         LEFT JOIN room       r   ON r.room_id       = cs.room_id
         WHERE c.Teacher_id = ? AND c.School_year_id = ?
         ORDER BY cs.day_of_week, cs.start_time"
    );
    $stmt->bind_param('ii', $teacherId, $syId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Headline numbers for the dashboard. */
function teacher_stats(mysqli $db, int $teacherId, int $syId, int $periodId): array
{
    $stmt = $db->prepare(
        "SELECT
            COUNT(DISTINCT c.Class_id) AS class_count,
            COUNT(DISTINCT c.Subject_id) AS subject_count,
            (SELECT COUNT(*) FROM student_classes sc
               JOIN classes c2 ON c2.Class_id = sc.class_id
              WHERE c2.Teacher_id = ? AND c2.School_year_id = ?) AS student_count,
            (SELECT COUNT(*) FROM student_grade sg
               JOIN classes c3 ON c3.Class_id = sg.class_id
              WHERE c3.Teacher_id = ? AND c3.School_year_id = ?
                AND sg.grading_period_id = ? AND sg.grade IS NOT NULL) AS graded_count
         FROM classes c
         WHERE c.Teacher_id = ? AND c.School_year_id = ?"
    );
    $stmt->bind_param('iiiiiii', $teacherId, $syId, $teacherId, $syId, $periodId, $teacherId, $syId);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt) ?? [];
    return [
        'class_count'   => (int) ($row['class_count'] ?? 0),
        'subject_count' => (int) ($row['subject_count'] ?? 0),
        'student_count' => (int) ($row['student_count'] ?? 0),
        'graded_count'  => (int) ($row['graded_count'] ?? 0),
    ];
}

/**
 * Actionable counts for the dashboard tiles — what the teacher must DO, not just
 * how many things exist. Each maps to one tile.
 */
function teacher_action_stats(mysqli $db, int $teacherId, int $syId, int $periodId): array
{
    $out = ['to_encode' => 0, 'awaiting' => 0, 'returned' => 0, 'approved' => 0, 'roster_total' => 0];
    if ($periodId <= 0) {
        return $out;
    }

    // Roster size across all this teacher's classes = the encoding denominator.
    $r = $db->prepare(
        'SELECT COUNT(*) c FROM student_classes sc
         JOIN classes cl ON cl.Class_id = sc.class_id
         WHERE cl.Teacher_id = ? AND cl.School_year_id = ?'
    );
    $r->bind_param('ii', $teacherId, $syId);
    $r->execute();
    $out['roster_total'] = (int) (stmt_fetch_assoc($r)['c'] ?? 0);

    $s = $db->prepare(
        "SELECT
            SUM(sg.grade IS NOT NULL)      AS encoded,
            SUM(sg.status = 'Submitted')   AS awaiting,
            SUM(sg.status = 'Returned')    AS returned,
            SUM(sg.status = 'Approved')    AS approved
         FROM student_grade sg
         JOIN classes cl ON cl.Class_id = sg.class_id
         WHERE cl.Teacher_id = ? AND cl.School_year_id = ? AND sg.grading_period_id = ?"
    );
    $s->bind_param('iii', $teacherId, $syId, $periodId);
    $s->execute();
    $row = stmt_fetch_assoc($s) ?? [];

    $encoded           = (int) ($row['encoded'] ?? 0);
    $out['awaiting']   = (int) ($row['awaiting'] ?? 0);
    $out['returned']   = (int) ($row['returned'] ?? 0);
    $out['approved']   = (int) ($row['approved'] ?? 0);
    $out['encoded']    = $encoded;
    $out['to_encode']  = max($out['roster_total'] - $encoded, 0);
    $out['percent']    = $out['roster_total'] > 0 ? (int) round($encoded / $out['roster_total'] * 100) : 0;

    return $out;
}

/** Announcements visible to teachers. */
function teacher_announcements(mysqli $db, int $limit = 5): array
{
    $stmt = $db->prepare(
        "SELECT id, title, content, username, created_at
         FROM announcements
         WHERE is_published = 1 AND audience IN ('All', 'Teacher')
         ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

// ── Teacher self-service profile (info + photo + signature) ─────────────────

/** Update a teacher's own editable info. */
function teacher_update_profile(mysqli $db, int $teacherId, string $email, string $contact, string $address): void
{
    $email = trim($email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
    }
    $contact = trim($contact) ?: null;
    $address = trim($address) ?: null;
    $email   = $email !== '' ? $email : null;

    $stmt = $db->prepare('UPDATE teacher SET email = ?, contact = ?, address = ? WHERE Teacher_id = ?');
    $stmt->bind_param('sssi', $email, $contact, $address, $teacherId);
    $stmt->execute();
}

/**
 * Resolve a teacher's uploaded image URL by kind ('photo' or 'signature').
 * Files live at uploads/teacher_{kind}s/{Teacher_id}.{ext}; returns null if none.
 * Mirrors student_photo_url() — no DB column needed.
 */
function teacher_image_url(int $teacherId, string $kind): ?string
{
    if ($teacherId <= 0) {
        return null;
    }
    $dir  = $kind === 'signature' ? 'teacher_signatures' : 'teacher_photos';
    $root = dirname(__DIR__);
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $rel = 'uploads/' . $dir . '/' . $teacherId . '.' . $ext;
        if (is_file($root . '/' . $rel)) {
            return app_url($rel) . '?v=' . filemtime($root . '/' . $rel);
        }
    }
    return null;
}

/** Absolute filesystem path a signature/photo would live at (for rendering by <img src>). */
function teacher_image_src(int $teacherId, string $kind): ?string
{
    // Same as URL but without cache-buster stripped — reuse the URL resolver.
    return teacher_image_url($teacherId, $kind);
}

/**
 * The class adviser (and their signature) for a given studentinfo row + S.Y.
 * Used by the Grade Slip: the adviser who signs is the student's SECTION adviser.
 *
 * @return array{teacher_id:int,name:string,signature:?string}|null
 */
function teacher_adviser_for_student(mysqli $db, int $studentInfoId, int $syId): ?array
{
    if ($studentInfoId <= 0 || $syId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT t.Teacher_id, t.Fullname, t.Firstname, t.Lastname
         FROM studentinfo si
         JOIN advisory_class a ON a.section_id = si.Section AND a.school_year_id = ?
         JOIN teacher t        ON t.Teacher_id = a.teacher_id
         WHERE si.student_id = ? LIMIT 1"
    );
    $stmt->bind_param('ii', $syId, $studentInfoId);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    if (!$row) {
        return null;
    }
    $tid  = (int) $row['Teacher_id'];
    $name = trim((string) ($row['Fullname'] ?: ($row['Firstname'] . ' ' . $row['Lastname'])));
    return [
        'teacher_id' => $tid,
        'name'       => $name,
        'signature'  => teacher_image_url($tid, 'signature'),
    ];
}

// ── Teacher administration (Department Heads / Principal / Super Admin) ─────

/** May this user administer teacher accounts? */
function teacher_can_manage(?array $user = null): bool
{
    $u = $user ?? current_user();
    return is_depthead_user($u) || is_depthead_admin($u) || is_super_admin($u);
}

/**
 * Teachers with their load and account state, for the management screen.
 * $status: '' = all, else Active|Inactive.
 */
function teacher_manage_list(mysqli $db, int $syId, string $q = '', string $status = ''): array
{
    $sql =
        "SELECT t.Teacher_id, t.Fullname, t.Firstname, t.Lastname, t.Designation,
                t.status, t.employee_no, t.user_id,
                u.username, u.status AS login_status, u.last_login, u.must_change_password,
                (SELECT COUNT(*) FROM classes c
                  WHERE c.Teacher_id = t.Teacher_id AND c.School_year_id = ?) AS active_classes,
                (SELECT COUNT(*) FROM advisory_class a
                  WHERE a.teacher_id = t.Teacher_id AND a.school_year_id = ?) AS advisory_count
         FROM teacher t
         LEFT JOIN user_account u ON u.user_id = t.user_id
         WHERE 1=1";
    $types  = 'ii';
    $params = [$syId, $syId];

    $q = trim($q);
    if ($q !== '') {
        $sql   .= " AND (t.Fullname LIKE ? OR t.Firstname LIKE ? OR t.Lastname LIKE ? OR u.username LIKE ?)";
        $like   = '%' . $q . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '') {
        $sql   .= ' AND t.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $sql .= " ORDER BY (t.status = 'Active') DESC, active_classes DESC, t.Lastname, t.Firstname";

    $stmt = $db->prepare($sql);
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/**
 * Activate / deactivate a teacher.
 *
 * Deactivating flips BOTH `teacher.status` and `user_account.status`, because
 * they answer different questions:
 *   - user_account.status blocks the LOGIN itself (authenticate_legacy_user)
 *   - teacher.status hides them from pickers and stops require_teacher_login()
 * Setting only one would leave a half-open door.
 *
 * Deactivating does NOT unassign their classes — existing classes keep showing
 * their name (report cards and past schedules must stay truthful). The
 * management screen flags any remaining load so it can be reassigned.
 */
function teacher_set_status(mysqli $db, int $teacherId, string $status, array $user): array
{
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        throw new RuntimeException('Invalid status.');
    }
    if (!teacher_can_manage($user)) {
        throw new RuntimeException('You are not allowed to manage teacher accounts.');
    }

    $stmt = $db->prepare('SELECT Teacher_id, Fullname, Firstname, Lastname, status, user_id FROM teacher WHERE Teacher_id = ? LIMIT 1');
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $t = stmt_fetch_assoc($stmt);
    if (!$t) {
        throw new RuntimeException('Teacher not found.');
    }
    if ((string) $t['status'] === $status) {
        return ['changed' => false, 'teacher' => $t, 'remaining_classes' => 0];
    }

    // A teacher must never deactivate themselves out of the system by accident.
    if ($status === 'Inactive' && (int) ($t['user_id'] ?? 0) === (int) ($user['id'] ?? -1)) {
        throw new RuntimeException('You cannot deactivate your own account.');
    }

    $sy   = teacher_active_sy($db);
    $syId = (int) $sy['id'];

    $cStmt = $db->prepare('SELECT COUNT(*) c FROM classes WHERE Teacher_id = ? AND School_year_id = ?');
    $cStmt->bind_param('ii', $teacherId, $syId);
    $cStmt->execute();
    $remaining = (int) (stmt_fetch_assoc($cStmt)['c'] ?? 0);

    $db->begin_transaction();
    try {
        $a = $db->prepare('UPDATE teacher SET status = ? WHERE Teacher_id = ?');
        $a->bind_param('si', $status, $teacherId);
        $a->execute();

        if ((int) ($t['user_id'] ?? 0) > 0) {
            $b = $db->prepare('UPDATE user_account SET status = ? WHERE user_id = ?');
            $uid = (int) $t['user_id'];
            $b->bind_param('si', $status, $uid);
            $b->execute();
        }

        soa_audit(
            $db,
            (int) ($user['id'] ?? 0),
            (string) ($user['full_name'] ?? 'Staff'),
            $status === 'Inactive' ? 'TEACHER_DEACTIVATE' : 'TEACHER_ACTIVATE',
            'teacher',
            (string) $teacherId,
            json_encode(['status' => $t['status']]),
            json_encode(['status' => $status, 'classes_still_assigned' => $remaining])
        );

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    return ['changed' => true, 'teacher' => $t, 'remaining_classes' => $status === 'Inactive' ? $remaining : 0];
}

/**
 * The option list for any "assign a teacher" dropdown.
 *
 * Returns Active teachers, PLUS any inactive teacher who is still assigned to a
 * class in this school year (flagged `is_inactive`).
 *
 * WHY the exception: the class edit forms populate a <select> from this list and
 * then setValue(existing teacher_id). If a still-assigned inactive teacher were
 * simply omitted, that setValue would find no option and saving the form would
 * SILENTLY REASSIGN the class to whoever sits first in the list. Keeping them —
 * clearly labelled — makes the data safe and tells the head to reassign.
 */
function teacher_picker_options(mysqli $db, int $syId): array
{
    $stmt = $db->prepare(
        "SELECT t.Teacher_id, t.Fullname, t.Firstname, t.Lastname, t.Designation, t.status,
                (t.status <> 'Active') AS is_inactive
         FROM teacher t
         WHERE t.status = 'Active'
            OR EXISTS (SELECT 1 FROM classes c
                        WHERE c.Teacher_id = t.Teacher_id AND c.School_year_id = ?)
         ORDER BY (t.status = 'Active') DESC, t.Fullname"
    );
    $stmt->bind_param('i', $syId);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);

    foreach ($rows as &$r) {
        $label = trim((string) ($r['Fullname'] ?: ($r['Firstname'] . ' ' . $r['Lastname'])));
        $r['label'] = ((int) $r['is_inactive'] === 1 ? '⚠ INACTIVE — ' : '') . ($label ?: 'Teacher #' . $r['Teacher_id']);
    }
    return $rows;
}

/** Day name for a 1..7 ISO weekday. */
function teacher_day_name(int $d): string
{
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][$d] ?? '—';
}
