<?php

declare(strict_types=1);

/**
 * Registrar "power" student-record management.
 *
 * Dropping a student removes their ACTIVE-S.Y. enrollment and every record tied
 * to it — SOA / ledger / payments, promissory notes, portal account, and the
 * student/class masterlist rows. It is destructive and irreversible, so callers
 * MUST preview first, confirm, and a full JSON snapshot is written to the audit
 * log before anything is deleted.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/soa_service.php';

/**
 * Resolve a student's identity + active-SY context from an enrollment id.
 * @return array<string,mixed>|null
 */
function registrar_resolve_student(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare(
        "SELECT en.id AS enrollment_id, en.student_id, en.school_year, en.Status,
                en.Department, en.Department_gradelevel, en.Department_section,
                COALESCE(CONCAT(p.surname,', ',p.firstname,' ',IFNULL(p.middlename,'')),
                         CONCAT(osp.surname,', ',osp.firstname,' ',IFNULL(osp.middlename,''))) AS full_name,
                COALESCE(p.surname, osp.surname) AS surname,
                COALESCE(p.lrn, osp.lrn) AS lrn,
                IF(p.id IS NOT NULL,'New','Old') AS profile_type,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name
         FROM enrollment en
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE en.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** Small helper — scalar count from a query. */
function _reg_count(mysqli $db, string $sql): int
{
    $r = $db->query($sql);
    return $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
}

/**
 * Everything that WILL be deleted for a student's active-SY enrollment, with
 * per-area counts (and financial totals so the registrar sees the stakes).
 *
 * @return array<string,mixed>
 */
function registrar_drop_preview(mysqli $db, int $enrollmentId): array
{
    $stu = registrar_resolve_student($db, $enrollmentId);
    if (!$stu) {
        return ['ok' => false];
    }
    $eid   = (int) $stu['enrollment_id'];
    $sid   = $db->real_escape_string((string) $stu['student_id']);
    $lrn   = trim((string) ($stu['lrn'] ?? ''));
    $lrnEsc = $db->real_escape_string($lrn);
    $syId  = (int) soa_active_school_year($db)['id'];

    // studentinfo ids for this student in the active SY (masterlist / class side).
    $siIds = [];
    if ($lrn !== '') {
        $r = $db->query("SELECT student_id FROM studentinfo WHERE LRN_no = '$lrnEsc' AND School_year_id = $syId");
        if ($r) { while ($x = $r->fetch_assoc()) { $siIds[] = (int) $x['student_id']; } }
    }
    $siList = $siIds ? implode(',', $siIds) : '0';

    $asmt  = _reg_count($db, "SELECT COUNT(*) c FROM student_assessment WHERE enrollment_id = $eid");
    $payRow = $db->query("SELECT COUNT(*) c, IFNULL(SUM(amount),0) s FROM payment_transaction pt JOIN student_assessment sa ON sa.id = pt.assessment_id WHERE sa.enrollment_id = $eid AND pt.status='Posted'")->fetch_assoc();
    $baRow  = $db->query("SELECT COUNT(*) c, IFNULL(SUM(payment_amount),0) s FROM backaccount_payment_records WHERE enrollment_id = $eid")->fetch_assoc();

    $counts = [
        'enrollment'            => 1,
        'student_assessment'    => $asmt,
        'soa_master'            => _reg_count($db, "SELECT COUNT(*) c FROM soa_master sm JOIN student_assessment sa ON sa.id=sm.assessment_id WHERE sa.enrollment_id=$eid"),
        'payment_transaction'   => (int) $payRow['c'],
        'receipt_master'        => _reg_count($db, "SELECT COUNT(*) c FROM receipt_master rm JOIN payment_transaction pt ON pt.id=rm.payment_id JOIN student_assessment sa ON sa.id=pt.assessment_id WHERE sa.enrollment_id=$eid"),
        'promissory_notes'      => _reg_count($db, "SELECT COUNT(*) c FROM promissory_notes WHERE enrollment_id=$eid"),
        'student_portal_account'=> _reg_count($db, "SELECT COUNT(*) c FROM student_portal_accounts WHERE enrollment_id=$eid"),
        // Grades hang off studentinfo (via classes), not off enrollment_id — see
        // TEACHER_MODULE_DESIGN.md. student_grade.student_id is RESTRICT, so these
        // rows MUST be counted here and deleted before studentinfo below.
        'student_grade'         => $siIds ? _reg_count($db, "SELECT COUNT(*) c FROM student_grade WHERE student_id IN ($siList)") : 0,
        'backaccount_payments'  => (int) $baRow['c'],
        'studentinfo'           => $lrn !== '' ? _reg_count($db, "SELECT COUNT(*) c FROM studentinfo WHERE LRN_no='$lrnEsc' AND School_year_id=$syId") : 0,
        'student_classes'       => $siIds ? _reg_count($db, "SELECT COUNT(*) c FROM student_classes WHERE student_id IN ($siList)") : 0,
        'class_tracking'        => $lrn !== '' ? _reg_count($db, "SELECT COUNT(*) c FROM student_classification_tracking WHERE student_id='$lrnEsc'") : 0,
    ];

    // Other enrollments (different SY) that share this profile — the profile is
    // only offered for deletion when this is the student's ONLY enrollment.
    $otherEnroll = _reg_count($db, "SELECT COUNT(*) c FROM enrollment WHERE student_id='$sid' AND id <> $eid");

    return [
        'ok'            => true,
        'student'       => $stu,
        'counts'        => $counts,
        'posted_amount' => (float) $payRow['s'],
        'backacc_amount'=> (float) $baRow['s'],
        'studentinfo_ids' => $siIds,
        'other_enrollments' => $otherEnroll,
    ];
}

/**
 * Permanently drop a student's active-SY enrollment and all linked records.
 * Writes a full audit snapshot BEFORE deleting. Runs in one transaction.
 *
 * @param array $opts ['delete_profile'=>bool, 'reason'=>string]
 * @return array{ok:bool,deleted:array,error?:string}
 */
function registrar_drop_student(mysqli $db, int $enrollmentId, array $user, array $opts = []): array
{
    $preview = registrar_drop_preview($db, $enrollmentId);
    if (!($preview['ok'] ?? false)) {
        throw new RuntimeException('Student / enrollment not found.');
    }
    $stu   = $preview['student'];
    $eid   = (int) $stu['enrollment_id'];
    $sid   = $db->real_escape_string((string) $stu['student_id']);
    $sidRaw = (string) $stu['student_id'];
    $lrn   = trim((string) ($stu['lrn'] ?? ''));
    $lrnEsc = $db->real_escape_string($lrn);
    $syId  = (int) soa_active_school_year($db)['id'];
    $siIds = $preview['studentinfo_ids'] ?? [];
    $siList = $siIds ? implode(',', array_map('intval', $siIds)) : '0';
    $deleteProfile = !empty($opts['delete_profile']) && (int) ($preview['other_enrollments'] ?? 1) === 0;
    $reason = trim((string) ($opts['reason'] ?? ''));

    // Forensic snapshot BEFORE deletion.
    $snapshot = json_encode([
        'enrollment_id' => $eid, 'student_id' => $sidRaw, 'lrn' => $lrn,
        'name' => $stu['full_name'], 'grade' => $stu['grade_name'], 'section' => $stu['section_name'],
        'school_year' => $stu['school_year'], 'reason' => $reason,
        'delete_profile' => $deleteProfile, 'profile_type' => $stu['profile_type'],
        'counts' => $preview['counts'], 'posted_amount' => $preview['posted_amount'],
    ], JSON_UNESCAPED_UNICODE);
    soa_audit($db, (int) ($user['id'] ?? 0), (string) ($user['full_name'] ?? 'Registrar'),
        'DROP_STUDENT', 'enrollment', (string) $eid, $snapshot, null);

    $deleted = [];
    $db->begin_transaction();
    try {
        // Assessment + payment ids for this enrollment.
        $asmtIds = [];
        $r = $db->query("SELECT id FROM student_assessment WHERE enrollment_id = $eid");
        if ($r) { while ($x = $r->fetch_assoc()) { $asmtIds[] = (int) $x['id']; } }
        $aList = $asmtIds ? implode(',', $asmtIds) : '0';

        // 1) payment_reversals (RESTRICT on payment) → then payments (cascade installments + receipts).
        $db->query("DELETE FROM payment_reversals WHERE payment_id IN (SELECT id FROM payment_transaction WHERE assessment_id IN ($aList))");
        $deleted['payment_transaction'] = 0;
        $q = $db->query("DELETE FROM payment_transaction WHERE assessment_id IN ($aList)");
        $deleted['payment_transaction'] = $db->affected_rows;

        // 2) assessments (cascade: assessment_charge, payment_adjustments, payment_schedule→soa_details,
        //    soa_master→soa_details, student_ledger).
        $db->query("DELETE FROM student_assessment WHERE enrollment_id = $eid");
        $deleted['student_assessment'] = $db->affected_rows;

        // 3) legacy financial tied by enrollment_id.
        $db->query("DELETE FROM backaccount_payment_records WHERE enrollment_id = $eid");
        $deleted['backaccount_payments'] = $db->affected_rows;
        $db->query("DELETE FROM monthly_payment WHERE enrollment_id = $eid");
        $db->query("DELETE FROM student_account WHERE enrollment_id = $eid");

        // 4) student / class masterlist (active SY, matched by LRN).
        if ($siIds) {
            // Grades first: student_grade.student_id -> studentinfo is RESTRICT (a grade
            // must never disappear as a silent side effect), so the delete below would
            // fail while any grade remains. student_grade_history cascades from here.
            $db->query("DELETE FROM student_grade WHERE student_id IN ($siList)");
            $deleted['student_grade'] = $db->affected_rows;

            $db->query("DELETE FROM student_classes WHERE student_id IN ($siList)");
            $deleted['student_classes'] = $db->affected_rows;
            $db->query("DELETE FROM studentinfo WHERE student_id IN ($siList)");
            $deleted['studentinfo'] = $db->affected_rows;
        }
        if ($lrn !== '') {
            $db->query("DELETE FROM student_classification_tracking WHERE student_id = '$lrnEsc'");
            $deleted['class_tracking'] = $db->affected_rows;
        }

        // 5) the enrollment itself (cascade: promissory_notes, student_portal_accounts).
        $db->query("DELETE FROM enrollment WHERE id = $eid");
        $deleted['enrollment'] = $db->affected_rows;

        // 6) optional profile — only when this was the student's ONLY enrollment.
        if ($deleteProfile) {
            if ((string) $stu['profile_type'] === 'New') {
                $db->query("DELETE FROM preregistration WHERE id = '$sid'");
            } else {
                $db->query("DELETE FROM old_studentprofile WHERE student_id = '$sid'");
            }
            $deleted['profile'] = $db->affected_rows;
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw new RuntimeException('Drop failed and was rolled back: ' . $e->getMessage());
    }

    return ['ok' => true, 'deleted' => $deleted];
}
