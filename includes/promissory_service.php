<?php

declare(strict_types=1);

/**
 * Promissory Note service — Registrar-issued deferred payment arrangements,
 * integrated with the SOA/ledger and the Cashier module.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/soa_service.php';

function pn_table_ready(mysqli $db): bool
{
    return soa_table_exists($db, 'promissory_notes');
}

/** Flip Pending notes to Overdue once their promised date has passed. Call on page load. */
function pn_mark_overdue(mysqli $db): void
{
    if (!pn_table_ready($db)) {
        return;
    }
    $db->query("UPDATE promissory_notes SET status='Overdue'
                WHERE status='Pending' AND promised_payment_date < CURDATE()");
}

/** Audit helper (reuses financial_audit_logs via soa_audit). */
function pn_audit(mysqli $db, array $user, string $action, string $entityId, ?string $after): void
{
    soa_audit(
        $db,
        (int) ($user['id'] ?? 0),
        (string) ($user['full_name'] ?? ''),
        $action,
        'promissory_notes',
        $entityId,
        null,
        $after
    );
}

/** One note by internal id (joined with student display info). */
function pn_get(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare(_pn_select_sql('pn.promissory_id = ?'));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** One note by its display number (PN-2026-000001). */
function pn_get_by_no(mysqli $db, string $no): ?array
{
    $stmt = $db->prepare(_pn_select_sql('pn.promissory_no = ?'));
    $stmt->bind_param('s', $no);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** Shared SELECT with student display fields. */
function _pn_select_sql(string $where): string
{
    return "SELECT pn.*,
                   COALESCE(CONCAT(p.surname,', ',p.firstname,' ',IFNULL(p.middlename,'')),
                            CONCAT(osp.surname,', ',osp.firstname,' ',IFNULL(osp.middlename,''))) AS full_name,
                   COALESCE(p.lrn, osp.lrn) AS lrn,
                   en.Department,
                   IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                   IFNULL(sc.Section_name, en.Department_section) AS section_name,
                   en.school_year,
                   sm.soa_number
            FROM promissory_notes pn
            JOIN enrollment en          ON en.id = pn.enrollment_id
            LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
            LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
            LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
            LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
            LEFT JOIN soa_master sm ON sm.id = pn.soa_id
            WHERE $where
            LIMIT 1";
}

/**
 * Unpaid (Pending/Overdue) notes for a student's enrollment, newest first.
 * @return array<int,array<string,mixed>>
 */
function pn_active_for_enrollment(mysqli $db, int $enrollmentId): array
{
    if (!pn_table_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT promissory_id, promissory_no, promissory_amount, outstanding_balance,
                date_issued, promised_payment_date, reason, status, cashier_verified
         FROM promissory_notes
         WHERE enrollment_id = ? AND status IN ('Pending','Overdue')
         ORDER BY promissory_id DESC"
    );
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Total unpaid promissory amount for an enrollment. */
function pn_unpaid_total(mysqli $db, int $enrollmentId): float
{
    if (!pn_table_ready($db)) {
        return 0.0;
    }
    $stmt = $db->prepare(
        "SELECT IFNULL(SUM(promissory_amount),0) s FROM promissory_notes
         WHERE enrollment_id = ? AND status IN ('Pending','Overdue')"
    );
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    return (float) (stmt_fetch_assoc($stmt)['s'] ?? 0);
}

/**
 * Create a promissory note. CALLER passes validated data.
 * @return array{promissory_id:int,promissory_no:string}
 */
function pn_create(mysqli $db, array $data, array $user): array
{
    $enrollmentId = (int) $data['enrollment_id'];
    $studentId    = (string) $data['student_id'];
    $syId         = (int) $data['school_year_id'];
    $soaId        = isset($data['soa_id']) && $data['soa_id'] ? (int) $data['soa_id'] : null;
    $outstanding  = round((float) $data['outstanding_balance'], 2);
    $amount       = round((float) $data['promissory_amount'], 2);
    $issued       = (string) $data['date_issued'];
    $promised     = (string) $data['promised_payment_date'];
    $reason       = trim((string) ($data['reason'] ?? ''));
    $createdBy    = (string) ($user['full_name'] ?? 'Registrar');

    if ($amount <= 0) {
        throw new RuntimeException('Promissory amount must be greater than zero.');
    }
    if ($outstanding > 0 && $amount > $outstanding + 0.01) {
        throw new RuntimeException('Promissory amount cannot exceed the current SOA amount due.');
    }
    if ($promised < $issued) {
        throw new RuntimeException('Promised payment date cannot be before the date issued.');
    }

    $db->begin_transaction();
    try {
        $pnNo = soa_next_document_number($db, 'PN', 'PN', (int) date('Y'));
        $ins = $db->prepare(
            'INSERT INTO promissory_notes
                (promissory_no, enrollment_id, student_id, school_year_id, soa_id,
                 outstanding_balance, promissory_amount, date_issued, promised_payment_date,
                 reason, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', ?)'
        );
        $ins->bind_param(
            'sisiiddssss',
            $pnNo, $enrollmentId, $studentId, $syId, $soaId,
            $outstanding, $amount, $issued, $promised, $reason, $createdBy
        );
        $ins->execute();
        $id = (int) $db->insert_id;
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Could not create promissory note: ' . $e->getMessage());
    }

    pn_audit($db, $user, 'CREATE_PROMISSORY', (string) $id, json_encode([
        'promissory_no' => $pnNo, 'enrollment_id' => $enrollmentId, 'amount' => $amount,
        'promised' => $promised, 'reason' => $reason,
    ]));

    return ['promissory_id' => $id, 'promissory_no' => $pnNo];
}

/** Cashier verifies a note as an approved deferred arrangement. */
function pn_verify(mysqli $db, int $id, array $user): array
{
    $pn = pn_get($db, $id);
    if (!$pn) {
        throw new RuntimeException('Promissory note not found.');
    }
    if ((string) $pn['status'] === 'Cancelled') {
        throw new RuntimeException('This promissory note is cancelled.');
    }
    if ((int) $pn['cashier_verified'] === 1) {
        throw new RuntimeException('This note is already verified.');
    }
    $name = (string) ($user['full_name'] ?? 'Cashier');
    $upd = $db->prepare("UPDATE promissory_notes SET cashier_verified=1, cashier_verified_by=?, cashier_verified_date=NOW() WHERE promissory_id=?");
    $upd->bind_param('si', $name, $id);
    $upd->execute();

    pn_audit($db, $user, 'VERIFY_PROMISSORY', (string) $id, json_encode(['promissory_no' => $pn['promissory_no']]));
    return pn_get($db, $id) ?? [];
}

/** Change status (Paid | Cancelled | Pending). */
function pn_set_status(mysqli $db, int $id, string $status, array $user): void
{
    $allowed = ['Pending', 'Paid', 'Overdue', 'Cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid status.');
    }
    $pn = pn_get($db, $id);
    if (!$pn) {
        throw new RuntimeException('Promissory note not found.');
    }
    $upd = $db->prepare('UPDATE promissory_notes SET status=? WHERE promissory_id=?');
    $upd->bind_param('si', $status, $id);
    $upd->execute();

    pn_audit($db, $user, 'STATUS_PROMISSORY', (string) $id, json_encode([
        'promissory_no' => $pn['promissory_no'], 'from' => $pn['status'], 'to' => $status,
    ]));
}

/** Dashboard counters for the active SY. @return array{active:int,overdue:int,paid:int,amount:float,overdue_amount:float} */
function pn_dashboard_stats(mysqli $db, int $syId): array
{
    $row = $db->query(
        "SELECT
            COUNT(CASE WHEN status IN ('Pending','Overdue') THEN 1 END) AS active,
            COUNT(CASE WHEN status='Overdue' THEN 1 END)                AS overdue,
            COUNT(CASE WHEN status='Paid' THEN 1 END)                   AS paid,
            IFNULL(SUM(CASE WHEN status IN ('Pending','Overdue') THEN promissory_amount END),0) AS amount,
            IFNULL(SUM(CASE WHEN status='Overdue' THEN promissory_amount END),0) AS overdue_amount
         FROM promissory_notes WHERE school_year_id = " . (int) $syId
    )->fetch_assoc();
    return [
        'active'         => (int) ($row['active'] ?? 0),
        'overdue'        => (int) ($row['overdue'] ?? 0),
        'paid'           => (int) ($row['paid'] ?? 0),
        'amount'         => (float) ($row['amount'] ?? 0),
        'overdue_amount' => (float) ($row['overdue_amount'] ?? 0),
    ];
}

/** Badge HTML for a status. */
function pn_status_badge(string $status): string
{
    $map = [
        'Pending'   => 'bg-amber-100 text-amber-800 border-amber-300',
        'Paid'      => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Overdue'   => 'bg-rose-100 text-rose-800 border-rose-300',
        'Cancelled' => 'bg-slate-200 text-slate-500 border-slate-300',
    ];
    $cls = $map[$status] ?? 'bg-slate-100 text-slate-600 border-slate-300';
    return '<span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold border ' . $cls . '">' . htmlspecialchars($status, ENT_QUOTES) . '</span>';
}
