<?php

declare(strict_types=1);

/**
 * Student Back Accounts — prior-school-year balances carried by a student.
 *
 * A back account is keyed to the student (student_id), NOT to a single
 * enrollment, so it follows the student across school years. The SOA looks it
 * up through enrollment.student_id and shows an unpaid balance as a WARNING —
 * it is never folded into the SOA's total due (see soa_slip.php).
 *
 * Collections issue an Official Receipt off the 'BACK' document series and fold
 * into the day's collection_summary, so end-of-day close includes them.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/soa_service.php';

function ba_schema_ready(mysqli $db): bool
{
    return soa_table_exists($db, 'student_back_accounts') && soa_table_exists($db, 'back_account_payments');
}

/** One back account row, or null. */
function ba_get(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM student_back_accounts WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** Every back account for a student (newest first). */
function ba_for_student(mysqli $db, string $studentId): array
{
    $stmt = $db->prepare('SELECT * FROM student_back_accounts WHERE student_id = ? ORDER BY school_year DESC, id DESC');
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Unpaid/Partial back accounts with a real balance — what the SOA warns about. */
function ba_unpaid_for_student(mysqli $db, string $studentId): array
{
    $stmt = $db->prepare(
        "SELECT * FROM student_back_accounts
         WHERE student_id = ? AND status IN ('Unpaid','Partial') AND balance > 0.009
         ORDER BY school_year ASC, id ASC"
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

function ba_unpaid_total_for_student(mysqli $db, string $studentId): float
{
    $stmt = $db->prepare(
        "SELECT IFNULL(SUM(balance),0) t FROM student_back_accounts
         WHERE student_id = ? AND status IN ('Unpaid','Partial') AND balance > 0.009"
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    return (float) (stmt_fetch_assoc($stmt)['t'] ?? 0);
}

/**
 * SOA hook: unpaid back accounts for the student behind an enrollment.
 * Resolves enrollment.student_id, then looks the student up.
 */
function ba_unpaid_for_enrollment(mysqli $db, int $enrollmentId): array
{
    if ($enrollmentId <= 0 || !ba_schema_ready($db)) {
        return [];
    }
    $stmt = $db->prepare('SELECT student_id FROM enrollment WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $sid = (string) (stmt_fetch_assoc($stmt)['student_id'] ?? '');
    return $sid === '' ? [] : ba_unpaid_for_student($db, $sid);
}

function ba_unpaid_total_for_enrollment(mysqli $db, int $enrollmentId): float
{
    $t = 0.0;
    foreach (ba_unpaid_for_enrollment($db, $enrollmentId) as $r) {
        $t += (float) $r['balance'];
    }
    return round($t, 2);
}

/**
 * Search back accounts for the cashier list.
 * $status: '' = any, else Unpaid|Partial|Paid|Cancelled.
 */
function ba_list(mysqli $db, string $q = '', string $status = '', int $limit = 100): array
{
    $sql    = 'SELECT * FROM student_back_accounts WHERE 1=1';
    $types  = '';
    $params = [];

    $q = trim($q);
    if ($q !== '') {
        $sql   .= ' AND (student_name LIKE ? OR lrn LIKE ? OR student_id LIKE ?)';
        $like   = '%' . $q . '%';
        $types .= 'sss';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($status !== '') {
        $sql   .= ' AND status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $sql   .= ' ORDER BY (status IN (\'Unpaid\',\'Partial\')) DESC, balance DESC, id DESC LIMIT ?';
    $types .= 'i';
    $params[] = max(1, $limit);

    $stmt = $db->prepare($sql);
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Headline counters for the page. */
function ba_stats(mysqli $db): array
{
    $r = $db->query(
        "SELECT
            COUNT(*) AS total_rows,
            SUM(status IN ('Unpaid','Partial')) AS unpaid_rows,
            IFNULL(SUM(CASE WHEN status IN ('Unpaid','Partial') THEN balance ELSE 0 END),0) AS unpaid_total,
            IFNULL(SUM(amount_paid),0) AS collected_total
         FROM student_back_accounts"
    );
    $row = $r ? $r->fetch_assoc() : null;
    return [
        'total_rows'      => (int) ($row['total_rows'] ?? 0),
        'unpaid_rows'     => (int) ($row['unpaid_rows'] ?? 0),
        'unpaid_total'    => (float) ($row['unpaid_total'] ?? 0),
        'collected_total' => (float) ($row['collected_total'] ?? 0),
    ];
}

/**
 * Find students to attach a back account to, by LRN or name.
 *
 * Searches the two profile tables DIRECTLY and unions them, rather than
 * scanning `enrollment` and joining out to the profiles. The old join-based
 * form cost ~4.8s per keystroke: `ON e.student_id = CAST(p.id AS CHAR)` makes
 * the join unindexable, so MySQL fell back to three full scans in nested
 * block-nested-loop joins (~38 billion row combinations).
 *
 * Only students that actually appear in `enrollment.student_id` are returned —
 * that column is the key a back account links on, so a student we could not
 * link to would produce a back account the SOA can never surface.
 *
 * Needs migrations/student_search_indexes.sql for the supporting indexes.
 */
function ba_search_students(mysqli $db, string $q, int $limit = 20): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $like   = '%' . $q . '%';
    $prefix = $q . '%';

    $stmt = $db->prepare(
        "SELECT x.student_id, x.lrn, x.surname, x.firstname,
                (SELECT MAX(e.school_year) FROM enrollment e WHERE e.student_id = x.student_id) AS latest_sy
         FROM (
                SELECT CAST(p.id AS CHAR) AS student_id, p.lrn, p.surname, p.firstname
                  FROM preregistration p
                 WHERE p.lrn LIKE ? OR p.surname LIKE ? OR p.firstname LIKE ?
                    OR CONCAT_WS(' ', p.surname, p.firstname) LIKE ?
                UNION
                SELECT o.student_id, o.lrn, o.surname, o.firstname
                  FROM old_studentprofile o
                 WHERE o.lrn LIKE ? OR o.surname LIKE ? OR o.firstname LIKE ?
                    OR CONCAT_WS(' ', o.surname, o.firstname) LIKE ?
              ) x
         WHERE EXISTS (SELECT 1 FROM enrollment e2 WHERE e2.student_id = x.student_id)
         ORDER BY (x.lrn LIKE ? OR x.surname LIKE ?) DESC, x.surname, x.firstname
         LIMIT ?"
    );
    $stmt->bind_param(
        'ssssssssssi',
        $like, $like, $like, $like,
        $like, $like, $like, $like,
        $prefix, $prefix,
        $limit
    );
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    foreach ($rows as &$r) {
        $r['display_name'] = trim(trim((string) $r['surname']) . ', ' . trim((string) $r['firstname']), ', ');
    }
    return $rows;
}

/** Recompute amount_paid/balance/status from the payment trail. Never touches 'Cancelled'. */
function ba_recompute(mysqli $db, int $id): void
{
    $row = ba_get($db, $id);
    if (!$row || $row['status'] === 'Cancelled') {
        return;
    }
    $pStmt = $db->prepare("SELECT IFNULL(SUM(amount),0) t FROM back_account_payments WHERE back_account_id = ? AND status = 'Paid'");
    $pStmt->bind_param('i', $id);
    $pStmt->execute();
    $paid = round((float) (stmt_fetch_assoc($pStmt)['t'] ?? 0), 2);

    $orig    = round((float) $row['original_amount'], 2);
    $balance = round(max($orig - $paid, 0), 2);
    $status  = $balance <= 0.009 ? 'Paid' : ($paid > 0.009 ? 'Partial' : 'Unpaid');

    $u = $db->prepare('UPDATE student_back_accounts SET amount_paid = ?, balance = ?, status = ? WHERE id = ?');
    $u->bind_param('ddsi', $paid, $balance, $status, $id);
    $u->execute();
}

/** Create a back account for a student. */
function ba_create(mysqli $db, array $d, array $user): int
{
    $studentId = trim((string) ($d['student_id'] ?? ''));
    $name      = trim((string) ($d['student_name'] ?? ''));
    $sy        = trim((string) ($d['school_year'] ?? ''));
    $amount    = round((float) ($d['original_amount'] ?? 0), 2);

    if ($studentId === '' || $name === '') {
        throw new RuntimeException('Select a student first.');
    }
    if ($sy === '') {
        throw new RuntimeException('School year is required.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Amount must be greater than zero.');
    }

    $lrn     = trim((string) ($d['lrn'] ?? '')) ?: null;
    $gs      = trim((string) ($d['grade_section'] ?? '')) ?: null;
    $remarks = trim((string) ($d['remarks'] ?? '')) ?: null;
    $by      = (string) ($user['full_name'] ?? 'Cashier');

    $stmt = $db->prepare(
        "INSERT INTO student_back_accounts
            (student_id, lrn, student_name, school_year, grade_section,
             original_amount, amount_paid, balance, status, remarks, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'Unpaid', ?, ?)"
    );
    $stmt->bind_param('sssssddss', $studentId, $lrn, $name, $sy, $gs, $amount, $amount, $remarks, $by);
    $stmt->execute();
    $id = (int) $db->insert_id;

    soa_audit($db, (int) ($user['id'] ?? 0), $by, 'BACKACCOUNT_CREATE', 'student_back_accounts', (string) $id, null,
        json_encode(['student_id' => $studentId, 'name' => $name, 'sy' => $sy, 'amount' => $amount]));

    return $id;
}

/** Edit the amount / SY / remarks of an existing back account. */
function ba_update(mysqli $db, int $id, array $d, array $user): void
{
    $row = ba_get($db, $id);
    if (!$row) {
        throw new RuntimeException('Back account not found.');
    }
    if ($row['status'] === 'Cancelled') {
        throw new RuntimeException('This back account is cancelled and can no longer be edited.');
    }

    $amount = round((float) ($d['original_amount'] ?? 0), 2);
    $paid   = round((float) $row['amount_paid'], 2);
    if ($amount <= 0) {
        throw new RuntimeException('Amount must be greater than zero.');
    }
    if ($amount < $paid) {
        throw new RuntimeException('Amount cannot be lower than the ₱' . number_format($paid, 2) . ' already paid on this account.');
    }

    $sy      = trim((string) ($d['school_year'] ?? $row['school_year']));
    $gs      = trim((string) ($d['grade_section'] ?? '')) ?: null;
    $remarks = trim((string) ($d['remarks'] ?? '')) ?: null;

    $stmt = $db->prepare('UPDATE student_back_accounts SET original_amount = ?, school_year = ?, grade_section = ?, remarks = ? WHERE id = ?');
    $stmt->bind_param('dsssi', $amount, $sy, $gs, $remarks, $id);
    $stmt->execute();

    ba_recompute($db, $id);

    soa_audit($db, (int) ($user['id'] ?? 0), (string) ($user['full_name'] ?? 'Cashier'),
        'BACKACCOUNT_UPDATE', 'student_back_accounts', (string) $id,
        json_encode(['original_amount' => (float) $row['original_amount'], 'sy' => $row['school_year']]),
        json_encode(['original_amount' => $amount, 'sy' => $sy]));
}

/** Cancel a back account (e.g. entered in error / written off). Keeps the record. */
function ba_cancel(mysqli $db, int $id, string $reason, array $user): void
{
    $row = ba_get($db, $id);
    if (!$row) {
        throw new RuntimeException('Back account not found.');
    }
    if ((float) $row['amount_paid'] > 0.009) {
        throw new RuntimeException('This account already has payments — void the payments first before cancelling.');
    }
    $remarks = trim(($row['remarks'] ? $row['remarks'] . ' | ' : '') . 'CANCELLED: ' . $reason);
    $stmt = $db->prepare("UPDATE student_back_accounts SET status = 'Cancelled', balance = 0, remarks = ? WHERE id = ?");
    $stmt->bind_param('si', $remarks, $id);
    $stmt->execute();

    soa_audit($db, (int) ($user['id'] ?? 0), (string) ($user['full_name'] ?? 'Cashier'),
        'BACKACCOUNT_CANCEL', 'student_back_accounts', (string) $id,
        json_encode(['status' => $row['status'], 'balance' => (float) $row['balance']]),
        json_encode(['status' => 'Cancelled', 'reason' => $reason]));
}

/**
 * Collect a (full or partial) payment against a back account and issue an OR.
 *
 * @return array{payment_id:int, or_number:string, amount:float, balance:float}
 */
function ba_collect(mysqli $db, int $id, float $amount, string $method, string $refNo, array $user): array
{
    $row = ba_get($db, $id);
    if (!$row) {
        throw new RuntimeException('Back account not found.');
    }
    if ($row['status'] === 'Cancelled') {
        throw new RuntimeException('This back account is cancelled.');
    }
    if ($row['status'] === 'Paid' || (float) $row['balance'] <= 0.009) {
        throw new RuntimeException('This back account is already fully paid.');
    }

    $amount  = round($amount, 2);
    $balance = round((float) $row['balance'], 2);
    if ($amount <= 0) {
        throw new RuntimeException('Enter a payment amount greater than zero.');
    }
    if ($amount > $balance + 0.009) {
        throw new RuntimeException('Payment (₱' . number_format($amount, 2) . ') exceeds the remaining balance of ₱' . number_format($balance, 2) . '.');
    }

    $method = in_array($method, ['Cash', 'GCash', 'Maya', 'Bank', 'Check'], true) ? $method : 'Cash';
    $refNo  = trim($refNo) ?: null;

    $sy        = soa_active_school_year($db);
    $syId      = (int) $sy['id'];
    $cashierId = (int) ($user['id'] ?? 0);
    $cashier   = (string) ($user['full_name'] ?? 'Cashier');
    $year      = (int) date('Y');
    $prefix    = soa_setting($db, 'BACK_OR_PREFIX', 'OR');

    $db->begin_transaction();
    try {
        $orNo = soa_next_document_number($db, 'BACK', $prefix, $year);

        $ins = $db->prepare(
            "INSERT INTO back_account_payments
                (back_account_id, or_number, amount, payment_method, reference_no, cashier_name, cashier_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Paid')"
        );
        $ins->bind_param('isdsssi', $id, $orNo, $amount, $method, $refNo, $cashier, $cashierId);
        $ins->execute();
        $paymentId = (int) $db->insert_id;

        ba_recompute($db, $id);

        // Fold into the day's collection so end-of-day close includes it.
        $cash   = $method === 'Cash' ? $amount : 0.0;
        $online = in_array($method, ['GCash', 'Maya', 'Bank'], true) ? $amount : 0.0;
        $insC = $db->prepare(
            'INSERT INTO collection_summary
                (cashier_id, cashier_name, business_date, school_year_id, txn_count, total_cash, total_online, total_collected, status)
             VALUES (?, ?, CURDATE(), ?, 1, ?, ?, ?, \'Open\')
             ON DUPLICATE KEY UPDATE
                txn_count       = txn_count + 1,
                total_cash      = total_cash + VALUES(total_cash),
                total_online    = total_online + VALUES(total_online),
                total_collected = total_collected + VALUES(total_collected)'
        );
        $insC->bind_param('isiddd', $cashierId, $cashier, $syId, $cash, $online, $amount);
        $insC->execute();

        soa_audit($db, $cashierId, $cashier, 'BACKACCOUNT_PAYMENT', 'back_account_payments', (string) $paymentId, null,
            json_encode(['or_number' => $orNo, 'back_account_id' => $id, 'amount' => $amount, 'method' => $method]));

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    $after = ba_get($db, $id);
    return [
        'payment_id' => $paymentId,
        'or_number'  => $orNo,
        'amount'     => $amount,
        'balance'    => (float) ($after['balance'] ?? 0),
    ];
}

/** Void a back-account payment and restore the balance. */
function ba_void_payment(mysqli $db, int $paymentId, string $reason, array $user): void
{
    $stmt = $db->prepare('SELECT * FROM back_account_payments WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $pay = stmt_fetch_assoc($stmt);
    if (!$pay) {
        throw new RuntimeException('Payment not found.');
    }
    if ($pay['status'] === 'Voided') {
        throw new RuntimeException('This payment is already voided.');
    }

    $by     = (string) ($user['full_name'] ?? 'Cashier');
    $amount = (float) $pay['amount'];
    $baId   = (int) $pay['back_account_id'];

    $db->begin_transaction();
    try {
        $u = $db->prepare("UPDATE back_account_payments SET status = 'Voided', voided_by = ?, voided_at = NOW() WHERE id = ?");
        $u->bind_param('si', $by, $paymentId);
        $u->execute();

        ba_recompute($db, $baId);

        // Reverse it out of today's collection.
        $method = (string) $pay['payment_method'];
        $cash   = $method === 'Cash' ? -$amount : 0.0;
        $online = in_array($method, ['GCash', 'Maya', 'Bank'], true) ? -$amount : 0.0;
        $neg    = -$amount;
        $sy     = soa_active_school_year($db);
        $syId   = (int) $sy['id'];
        $cid    = (int) ($user['id'] ?? 0);
        $insC = $db->prepare(
            'INSERT INTO collection_summary
                (cashier_id, cashier_name, business_date, school_year_id, txn_count, total_cash, total_online, total_collected, status)
             VALUES (?, ?, CURDATE(), ?, 0, ?, ?, ?, \'Open\')
             ON DUPLICATE KEY UPDATE
                total_cash      = total_cash + VALUES(total_cash),
                total_online    = total_online + VALUES(total_online),
                total_collected = total_collected + VALUES(total_collected)'
        );
        $insC->bind_param('isiddd', $cid, $by, $syId, $cash, $online, $neg);
        $insC->execute();

        soa_audit($db, $cid, $by, 'BACKACCOUNT_VOID', 'back_account_payments', (string) $paymentId,
            json_encode(['status' => 'Paid', 'amount' => $amount]),
            json_encode(['status' => 'Voided', 'reason' => $reason]));

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** Payment trail for one back account. */
function ba_payments(mysqli $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM back_account_payments WHERE back_account_id = ? ORDER BY paid_at DESC, id DESC');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** One payment (for the receipt page). */
function ba_payment_get(mysqli $db, int $paymentId): ?array
{
    $stmt = $db->prepare(
        'SELECT bp.*, b.student_name, b.lrn, b.student_id, b.school_year AS debt_sy, b.balance AS current_balance, b.original_amount
         FROM back_account_payments bp
         JOIN student_back_accounts b ON b.id = bp.back_account_id
         WHERE bp.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** Tailwind badge classes for a status. */
function ba_status_badge(string $status): string
{
    return match ($status) {
        'Paid'      => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Partial'   => 'bg-amber-100 text-amber-800 border-amber-300',
        'Cancelled' => 'bg-slate-100 text-slate-600 border-slate-300',
        default     => 'bg-rose-100 text-rose-800 border-rose-300',
    };
}
