<?php

declare(strict_types=1);

/**
 * Certificates of Recognition (Academic Honors).
 *
 * Class Adviser issues (Draft) -> Department Head publishes -> visible to the
 * student. Certificates carry a QR code that resolves to a public verification
 * page, so a printed copy can be checked for authenticity.
 *
 * SNAPSHOT PRINCIPLE: name, section, average, adviser and period are COPIED onto
 * the certificate at issue time. A signed certificate must keep saying what it
 * said, even if the student later transfers section or a grade is corrected.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/soa_service.php';
require_once __DIR__ . '/grading_service.php';

/** Minimum general average that qualifies for the Academic Excellence Award. */
const CERT_ACADEMIC_MIN = 90.0;

/**
 * The award types a certificate can be. Each is its OWN certificate; a student
 * may receive any combination. The value is the default printed title (the
 * Special Award title is supplied per-issue).
 */
function cert_award_types(): array
{
    return [
        'Academic Excellence' => 'Academic Excellence Award',
        'Perfect Attendance'  => 'Perfect Attendance Award',
        'Special Award'       => '',
    ];
}

/** Does this average qualify for the Academic Excellence Award? */
function cert_is_honor(?float $avg): bool
{
    return $avg !== null && $avg >= CERT_ACADEMIC_MIN;
}

/**
 * The title to print for a stored certificate: the new award_title, falling
 * back to the legacy honor band, then the type. Keeps old certificates valid.
 */
function cert_display_title(array $cert): string
{
    $title = trim((string) ($cert['award_title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    $legacy = trim((string) ($cert['honor_level'] ?? ''));
    if ($legacy !== '') {
        return $legacy;
    }
    return (string) ($cert['type'] ?? 'Certificate');
}

function cert_schema_ready(mysqli $db): bool
{
    $r = $db->query("SHOW TABLES LIKE 'certificate'");
    return $r && $r->num_rows > 0;
}

/** The adviser's advisory class for a school year, or null. */
function cert_adviser_section(mysqli $db, int $teacherId, int $syId): ?array
{
    $stmt = $db->prepare(
        'SELECT a.section_id, a.gradelevel_id, sec.Section_name, gl.Gradelevel
         FROM advisory_class a
         LEFT JOIN section sec   ON sec.Section_id = a.section_id
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = a.gradelevel_id
         WHERE a.teacher_id = ? AND a.school_year_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $teacherId, $syId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * The adviser's advisory students with their general average for a period,
 * plus any certificate already issued.
 *
 * The average is computed from the SAME rule the grade slip uses: only
 * Approved/Locked grades count, so an adviser cannot award honors off
 * unreviewed marks.
 */
function cert_advisory_students(mysqli $db, int $sectionId, int $syId, int $periodId): array
{
    $stmt = $db->prepare(
        "SELECT si.student_id, si.LRN_no, si.Lastname, si.Firstname, si.Middlename,
                gl.Gradelevel, sec.Section_name,
                ROUND(AVG(CASE WHEN sg.status IN ('Approved','Locked') AND sg.grade IS NOT NULL
                               THEN sg.grade END), 2) AS average,
                COUNT(CASE WHEN sg.status IN ('Approved','Locked') AND sg.grade IS NOT NULL
                           THEN 1 END) AS graded_subjects,
                COUNT(DISTINCT sc.class_id) AS total_subjects
         FROM studentinfo si
         JOIN student_classes sc ON sc.student_id = si.student_id
         JOIN classes cl         ON cl.Class_id = sc.class_id AND cl.School_year_id = ?
         LEFT JOIN student_grade sg ON sg.class_id = cl.Class_id
                                   AND sg.student_id = si.student_id
                                   AND sg.grading_period_id = ?
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = si.Gradelevel
         LEFT JOIN section sec   ON sec.Section_id = si.Section
         WHERE si.Section = ? AND si.School_year_id = ?
         GROUP BY si.student_id
         -- MariaDB rejects an aggregate ALIAS in ORDER BY ('reference to group
         -- function'), so the expression is repeated here rather than aliased.
         ORDER BY AVG(CASE WHEN sg.status IN ('Approved','Locked') AND sg.grade IS NOT NULL
                           THEN sg.grade END) IS NULL,
                  AVG(CASE WHEN sg.status IN ('Approved','Locked') AND sg.grade IS NOT NULL
                           THEN sg.grade END) DESC,
                  si.Lastname"
    );
    $stmt->bind_param('iiii', $syId, $periodId, $sectionId, $syId);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);

    // Any award already on file for this section+period, keyed by student+type.
    $certs = cert_map_for_period($db, $sectionId, $syId, $periodId);

    foreach ($rows as &$r) {
        $avg = $r['average'] !== null ? (float) $r['average'] : null;
        $r['average']    = $avg;
        $r['is_honor']   = cert_is_honor($avg);
        $r['full_name']  = trim((string) $r['Lastname'] . ', ' . (string) $r['Firstname']
                         . ((string) ($r['Middlename'] ?? '') !== '' ? ' ' . mb_substr((string) $r['Middlename'], 0, 1) . '.' : ''));
        $r['complete']   = (int) $r['graded_subjects'] > 0
                        && (int) $r['graded_subjects'] === (int) $r['total_subjects'];
        // certs[type] => row|null for each award type
        $r['certs'] = [];
        foreach (array_keys(cert_award_types()) as $type) {
            $r['certs'][$type] = $certs[(int) $r['student_id']][$type] ?? null;
        }
    }
    return $rows;
}

/** student_id => [type => certificate row] for a section's period. */
function cert_map_for_period(mysqli $db, int $sectionId, int $syId, int $periodId): array
{
    $pid = $periodId ?: null;
    $stmt = $db->prepare(
        "SELECT c.id, c.student_id, c.type, c.award_title, c.honor_level, c.status, c.certificate_no
         FROM certificate c
         JOIN studentinfo si ON si.student_id = c.student_id
         WHERE si.Section = ? AND c.school_year_id = ? AND (c.grading_period_id <=> ?)"
    );
    $stmt->bind_param('iii', $sectionId, $syId, $pid);
    $stmt->execute();
    $map = [];
    foreach (stmt_fetch_all_assoc($stmt) as $c) {
        $map[(int) $c['student_id']][(string) $c['type']] = $c;
    }
    return $map;
}

/**
 * Issue (or re-issue) a certificate. Snapshots everything.
 * Re-issuing an existing Draft updates it; a Published one must be revoked first.
 */
function cert_issue(mysqli $db, array $d, array $user): int
{
    if (!cert_schema_ready($db)) {
        throw new RuntimeException('Run migrations/certificates.sql first.');
    }
    $studentId = (int) ($d['student_id'] ?? 0);
    $type      = (string) ($d['type'] ?? '');
    $syId      = (int) ($d['school_year_id'] ?? 0);
    $periodId  = (int) ($d['grading_period_id'] ?? 0) ?: null;

    if ($studentId <= 0 || $syId <= 0) {
        throw new RuntimeException('Invalid student or school year.');
    }
    $awardTypes = cert_award_types();
    if (!array_key_exists($type, $awardTypes)) {
        throw new RuntimeException('Choose a valid award type.');
    }

    // Resolve the printed award title. The Special Award title is free text.
    if ($type === 'Special Award') {
        $awardTitle = trim((string) ($d['award_title'] ?? ''));
        if ($awardTitle === '') {
            throw new RuntimeException('Enter the title of the Special Award.');
        }
    } else {
        $awardTitle = $awardTypes[$type];
    }
    // Only the Academic Excellence Award carries a general average.
    $avg = ($type === 'Academic Excellence' && isset($d['general_average']) && $d['general_average'] !== null)
        ? (float) $d['general_average'] : null;

    // Block silent overwrite of something already signed and published (per type).
    $ex = $db->prepare(
        "SELECT id, status FROM certificate
         WHERE student_id = ? AND school_year_id = ? AND type = ?
           AND (grading_period_id <=> ?) LIMIT 1"
    );
    $ex->bind_param('iisi', $studentId, $syId, $type, $periodId);
    $ex->execute();
    $existing = stmt_fetch_assoc($ex);
    if ($existing && (string) $existing['status'] === 'Published') {
        throw new RuntimeException('A published ' . $type . ' certificate already exists for this student and period. Revoke it first to change it.');
    }

    $name    = (string) ($d['student_name'] ?? '');
    $lrn     = (string) ($d['lrn'] ?? '') ?: null;
    $grade   = (string) ($d['grade_level'] ?? '') ?: null;
    $section = (string) ($d['section_name'] ?? '') ?: null;
    $syLabel = (string) ($d['school_year'] ?? '') ?: null;
    $pName   = (string) ($d['period_name'] ?? '') ?: null;
    $advId   = (int) ($d['adviser_teacher_id'] ?? 0) ?: null;
    $advName = (string) ($d['adviser_name'] ?? '') ?: null;
    $princ   = soa_setting($db, 'PRINCIPAL_NAME', 'MUJAHIDIN I. GARAY, LPT, MAEd');
    $issuedBy = (int) ($user['id'] ?? 0);

    $db->begin_transaction();
    try {
        if ($existing) {
            $stmt = $db->prepare(
                "UPDATE certificate
                    SET award_title = ?, honor_level = NULL, general_average = ?, student_name = ?, lrn = ?,
                        grade_level = ?, section_name = ?, school_year = ?, period_name = ?,
                        adviser_teacher_id = ?, adviser_name = ?, principal_name = ?,
                        status = 'Draft', issued_by = ?
                  WHERE id = ?"
            );
            $id = (int) $existing['id'];
            // title,avg,name,lrn,grade,section,syLabel,pName,advId,advName,princ,issuedBy,id
            $stmt->bind_param('sdssssssissii', $awardTitle, $avg, $name, $lrn, $grade, $section,
                $syLabel, $pName, $advId, $advName, $princ, $issuedBy, $id);
            $stmt->execute();
        } else {
            $year   = (int) date('Y');
            $certNo = soa_next_document_number($db, 'CERT', 'CR', $year);
            $token  = bin2hex(random_bytes(12));

            $stmt = $db->prepare(
                "INSERT INTO certificate
                    (certificate_no, verify_token, type, student_id, student_name, lrn,
                     grade_level, section_name, school_year_id, school_year,
                     grading_period_id, period_name, award_title, general_average,
                     adviser_teacher_id, adviser_name, principal_name, status, issued_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?)"
            );
            // certNo,token,type,studentId,name,lrn,grade,section,syId,syLabel,periodId,
            // pName,title,avg,advId,advName,princ,issuedBy  = 18 params
            $stmt->bind_param(
                'sssissssisissdissi',
                $certNo, $token, $type, $studentId, $name, $lrn, $grade, $section,
                $syId, $syLabel, $periodId, $pName, $awardTitle, $avg, $advId, $advName, $princ, $issuedBy
            );
            $stmt->execute();
            $id = (int) $db->insert_id;
        }

        soa_audit($db, $issuedBy, (string) ($user['full_name'] ?? 'Adviser'),
            'CERT_ISSUE', 'certificate', (string) $id, null,
            json_encode(['student' => $name, 'type' => $type, 'award' => $awardTitle, 'average' => $avg]));

        $db->commit();
        return $id;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** Publish every draft certificate for a period within the head's department. */
function cert_publish_period(mysqli $db, int $syId, ?int $periodId, array $user): int
{
    $uid  = (int) ($user['id'] ?? 0);
    $name = (string) ($user['full_name'] ?? 'Department Head');

    // Scope to the head's own classes — same rule as grade review.
    $sql = "UPDATE certificate c
            SET c.status = 'Published', c.published_by = ?, c.published_by_name = ?, c.published_at = NOW()
            WHERE c.status = 'Draft' AND c.school_year_id = ? AND (c.grading_period_id <=> ?)";
    if (!is_super_admin($user)) {
        $sql .= " AND EXISTS (SELECT 1 FROM student_classes sc
                              JOIN classes cl ON cl.Class_id = sc.class_id
                              WHERE sc.student_id = c.student_id AND cl.user_id = ?
                                AND cl.School_year_id = c.school_year_id)";
    }
    $stmt = $db->prepare($sql);
    if (is_super_admin($user)) {
        $stmt->bind_param('isii', $uid, $name, $syId, $periodId);
    } else {
        $stmt->bind_param('isiii', $uid, $name, $syId, $periodId, $uid);
    }
    $stmt->execute();
    $n = $db->affected_rows;

    if ($n > 0) {
        soa_audit($db, $uid, $name, 'CERT_PUBLISH', 'certificate', 'period:' . ($periodId ?? 'SY'), null,
            json_encode(['published' => $n]));
    }
    return $n;
}

/**
 * Publish (approve) every draft certificate for ONE section in a period.
 * Approval is done per section — the head signs off a whole class at once.
 */
function cert_publish_section(mysqli $db, int $sectionId, int $syId, ?int $periodId, array $user): int
{
    $uid  = (int) ($user['id'] ?? 0);
    $name = (string) ($user['full_name'] ?? 'Department Head');

    $sql = "UPDATE certificate c
            JOIN studentinfo si ON si.student_id = c.student_id
            SET c.status = 'Published', c.published_by = ?, c.published_by_name = ?, c.published_at = NOW()
            WHERE c.status = 'Draft' AND c.school_year_id = ? AND (c.grading_period_id <=> ?)
              AND si.Section = ?";
    if (!is_super_admin($user)) {
        $sql .= " AND EXISTS (SELECT 1 FROM student_classes sc
                              JOIN classes cl ON cl.Class_id = sc.class_id
                              WHERE sc.student_id = c.student_id AND cl.user_id = ?
                                AND cl.School_year_id = c.school_year_id)";
    }
    $stmt = $db->prepare($sql);
    if (is_super_admin($user)) {
        $stmt->bind_param('isiii', $uid, $name, $syId, $periodId, $sectionId);
    } else {
        $stmt->bind_param('isiiii', $uid, $name, $syId, $periodId, $sectionId, $uid);
    }
    $stmt->execute();
    $n = $db->affected_rows;

    if ($n > 0) {
        soa_audit($db, $uid, $name, 'CERT_PUBLISH', 'certificate', 'section:' . $sectionId, null,
            json_encode(['published' => $n, 'period' => $periodId]));
    }
    return $n;
}

/** Revoke one certificate. */
function cert_revoke(mysqli $db, int $certId, array $user, string $reason = ''): void
{
    $uid  = (int) ($user['id'] ?? 0);
    $stmt = $db->prepare("UPDATE certificate SET status='Revoked', revoked_by=?, revoked_at=NOW(), remarks=? WHERE id=?");
    $reason = trim($reason) ?: null;
    $stmt->bind_param('isi', $uid, $reason, $certId);
    $stmt->execute();

    soa_audit($db, $uid, (string) ($user['full_name'] ?? 'Staff'),
        'CERT_REVOKE', 'certificate', (string) $certId, null, json_encode(['reason' => $reason]));
}

/** Delete a draft (never a published one). */
function cert_delete_draft(mysqli $db, int $certId, array $user): void
{
    $stmt = $db->prepare("DELETE FROM certificate WHERE id = ? AND status = 'Draft'");
    $stmt->bind_param('i', $certId);
    $stmt->execute();
    if ($db->affected_rows === 0) {
        throw new RuntimeException('Only a draft certificate can be removed.');
    }
    soa_audit($db, (int) ($user['id'] ?? 0), (string) ($user['full_name'] ?? 'Staff'),
        'CERT_DELETE', 'certificate', (string) $certId, null, null);
}

function cert_get(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM certificate WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** Public verification lookup — requires BOTH the number and the token. */
function cert_verify(mysqli $db, string $certNo, string $token): ?array
{
    $certNo = trim($certNo);
    $token  = trim($token);
    if ($certNo === '' || $token === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM certificate WHERE certificate_no = ? LIMIT 1');
    $stmt->bind_param('s', $certNo);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    if (!$row) {
        return null;
    }
    // Constant-time compare — the token is a secret.
    if (!hash_equals((string) $row['verify_token'], $token)) {
        return null;
    }
    return $row;
}

/** Certificates a student may see: published only. */
function cert_for_student(mysqli $db, int $studentInfoId): array
{
    if ($studentInfoId <= 0 || !cert_schema_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT * FROM certificate
         WHERE student_id = ? AND status = 'Published'
         ORDER BY school_year_id DESC, id DESC"
    );
    $stmt->bind_param('i', $studentInfoId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** All certificates for the head's review screen. */
function cert_list(mysqli $db, int $syId, ?int $periodId, array $user, string $status = ''): array
{
    $sql = "SELECT c.*, t.Fullname AS adviser_full, si.Section AS section_id
            FROM certificate c
            LEFT JOIN teacher t     ON t.Teacher_id = c.adviser_teacher_id
            LEFT JOIN studentinfo si ON si.student_id = c.student_id
            WHERE c.school_year_id = ?";
    $types = 'i'; $params = [$syId];

    if ($periodId !== null) { $sql .= ' AND (c.grading_period_id <=> ?)'; $types .= 'i'; $params[] = $periodId; }
    if ($status !== '')     { $sql .= ' AND c.status = ?';                $types .= 's'; $params[] = $status; }

    if (!is_super_admin($user)) {
        $sql .= " AND EXISTS (SELECT 1 FROM student_classes sc
                              JOIN classes cl ON cl.Class_id = sc.class_id
                              WHERE sc.student_id = c.student_id AND cl.user_id = ?
                                AND cl.School_year_id = c.school_year_id)";
        $types .= 'i'; $params[] = (int) ($user['id'] ?? 0);
    }
    $sql .= " ORDER BY si.Section, c.student_name,
              FIELD(c.status,'Draft','Published','Revoked'), c.type";

    $stmt = $db->prepare($sql);
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Badge classes per award type (falls back for legacy honor bands). */
function cert_type_badge(string $type): string
{
    return match ($type) {
        'Academic Excellence' => 'bg-amber-100 text-amber-900 border-amber-400',
        'Perfect Attendance'  => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Special Award'       => 'bg-violet-100 text-violet-800 border-violet-300',
        // legacy honor bands
        'With Highest Honors' => 'bg-amber-100 text-amber-900 border-amber-400',
        'With High Honors'    => 'bg-violet-100 text-violet-800 border-violet-300',
        'With Honors'         => 'bg-sky-100 text-sky-800 border-sky-300',
        default               => 'bg-slate-100 text-slate-700 border-slate-300',
    };
}

/** BC alias — old callers referenced cert_level_badge(). */
function cert_level_badge(string $level): string
{
    return cert_type_badge($level);
}

function cert_status_badge(string $status): string
{
    return match ($status) {
        'Published' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Draft'     => 'bg-slate-100 text-slate-600 border-slate-300',
        'Revoked'   => 'bg-rose-100 text-rose-800 border-rose-300',
        default     => 'bg-slate-100 text-slate-600 border-slate-300',
    };
}

/** The absolute URL encoded into the QR. */
function cert_verify_url(array $cert): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . app_url('verify.php')
         . '?c=' . rawurlencode((string) $cert['certificate_no'])
         . '&k=' . rawurlencode((string) $cert['verify_token']);
}
