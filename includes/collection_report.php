<?php

declare(strict_types=1);

/**
 * Cashier collection report — unified list of all money collected in a date
 * range: SOA/tuition payments (payment_transaction + receipt_master) AND other
 * payments (payment_others). Used by cashier/report.php and report_print.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/soa_service.php';

/**
 * @return array<int,array{dt:string,name:string,particular:string,or_number:string,method:string,amount:float,kind:string}>
 */
function collection_report_rows(mysqli $db, string $from, string $to, string $type = 'all'): array
{
    // Guard dates (YYYY-MM-DD); default to today.
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-d');
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : date('Y-m-d');
    $rows = [];

    // ── Tuition / SOA collections ────────────────────────────────────────────
    if ($type === 'all' || $type === 'tuition') {
        $stmt = $db->prepare(
            "SELECT pt.paid_at AS dt, rm.or_number AS or_number, pt.method,
                    pt.amount,
                    COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname), 'Student') AS name
             FROM payment_transaction pt
             JOIN receipt_master rm      ON rm.payment_id = pt.id
             JOIN student_assessment sa  ON sa.id = pt.assessment_id
             JOIN enrollment en          ON en.id = sa.enrollment_id
             LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
             WHERE pt.status = 'Posted' AND DATE(pt.paid_at) BETWEEN ? AND ?
             ORDER BY pt.paid_at"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $r) {
            $rows[] = [
                'dt'         => (string) $r['dt'],
                'name'       => (string) $r['name'],
                'particular' => 'Tuition & School Fees',
                'or_number'  => (string) $r['or_number'],
                'method'     => (string) $r['method'],
                'amount'     => (float) $r['amount'],
                'kind'       => 'Tuition',
            ];
        }
    }

    // ── Other / miscellaneous collections ────────────────────────────────────
    if (($type === 'all' || $type === 'other') && soa_table_exists($db, 'other_payment_items')) {
        $stmt = $db->prepare(
            "SELECT COALESCE(created_at, Date) AS dt, or_number, payment_method AS method, Amount AS amount,
                    Name AS name, Purpose AS particular
             FROM payment_others
             WHERE status = 'Paid' AND or_number IS NOT NULL
               AND DATE(COALESCE(created_at, Date)) BETWEEN ? AND ?
             ORDER BY COALESCE(created_at, Date)"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $r) {
            $rows[] = [
                'dt'         => (string) $r['dt'],
                'name'       => (string) $r['name'],
                'particular' => (string) ($r['particular'] ?: 'Other Payment'),
                'or_number'  => (string) $r['or_number'],
                'method'     => (string) $r['method'],
                'amount'     => (float) $r['amount'],
                'kind'       => 'Other',
            ];
        }
    }

    // Chronological order across both sources.
    usort($rows, static fn($a, $b) => strcmp($a['dt'], $b['dt']));
    return $rows;
}

/** Sum of amounts. */
function collection_report_total(array $rows): float
{
    $t = 0.0;
    foreach ($rows as $r) { $t += (float) $r['amount']; }
    return round($t, 2);
}
