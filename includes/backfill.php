<?php

declare(strict_types=1);

/**
 * Enrollment-payment backfill (Milestone 6), as a reusable function so it can be
 * run from the CLI (migrations/backfill_enrollment_payments.php) OR from a
 * browser (cashier/run_backfill.php) on hosts without shell access.
 *
 * Imports backaccount_payment_records (legacy enrollment collections) into the
 * Phase-2 ledger: ensures an assessment, inserts a Posted payment_transaction
 * (+ receipt + ledger), and credits the assessment. Idempotent via
 * idempotency_key 'ba:<row id>'. The query only fetches NOT-yet-imported rows,
 * so it batches cleanly: call repeatedly with a $limit until remaining == 0.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/soa_service.php';

/** @return array{total:int,done:int,remaining:int,sy:array} */
function soa_backfill_status(mysqli $db): array
{
    $sy    = soa_active_school_year($db);
    $label = $db->real_escape_string((string) $sy['label']);
    $total = (int) ($db->query(
        "SELECT COUNT(*) c FROM backaccount_payment_records WHERE enrollment_id > 0 AND school_year = '$label'"
    )->fetch_assoc()['c'] ?? 0);
    $done = (int) ($db->query(
        "SELECT COUNT(*) c FROM backaccount_payment_records b
         WHERE b.enrollment_id > 0 AND b.school_year = '$label'
           AND EXISTS (SELECT 1 FROM payment_transaction pt WHERE pt.idempotency_key = CONCAT('ba:', b.id))"
    )->fetch_assoc()['c'] ?? 0);
    return ['total' => $total, 'done' => $done, 'remaining' => max(0, $total - $done), 'sy' => $sy];
}

/**
 * Process up to $limit not-yet-imported records (0 = all).
 *
 * @return array{imported:int,skipped:int,noAssess:int,errors:int,processed:int,messages:string[]}
 */
function soa_backfill_run(mysqli $db, int $limit = 0): array
{
    $sy    = soa_active_school_year($db);
    $syId  = (int) $sy['id'];
    $label = $db->real_escape_string((string) $sy['label']);

    $sql = "SELECT id, enrollment_id, payment_amount, or_number, payment_method, cashier_name,
                   payment_date, fee_admission, fee_activity, fee_books, fee_house_reg
            FROM backaccount_payment_records b
            WHERE enrollment_id > 0 AND school_year = '$label'
              AND NOT EXISTS (SELECT 1 FROM payment_transaction pt WHERE pt.idempotency_key = CONCAT('ba:', b.id))
            ORDER BY id" . ($limit > 0 ? " LIMIT $limit" : '');
    $rows = $db->query($sql);

    $imported = 0; $skipped = 0; $noAssess = 0; $errors = 0; $n = 0; $messages = [];

    while ($rows && ($r = $rows->fetch_assoc())) {
        $n++;
        $rowId      = (int) $r['id'];
        $enrollment = (int) $r['enrollment_id'];
        $amount     = round((float) $r['payment_amount'], 2);
        $method     = in_array((string) $r['payment_method'], ['Cash', 'GCash', 'Maya', 'Bank', 'Voucher'], true) ? (string) $r['payment_method'] : 'Cash';
        $cashier    = trim((string) ($r['cashier_name'] ?? '')) ?: 'Enrollment Cashier';
        $paidAt     = (string) $r['payment_date'];
        $idemKey    = 'ba:' . $rowId;
        $origOr     = trim((string) ($r['or_number'] ?? ''));

        if ($amount <= 0) { $skipped++; continue; }

        $db->begin_transaction();
        try {
            $chk = $db->prepare('SELECT id FROM payment_transaction WHERE idempotency_key = ? LIMIT 1');
            $chk->bind_param('s', $idemKey);
            $chk->execute();
            if (stmt_fetch_assoc($chk)) { $db->commit(); $skipped++; continue; }

            $assessmentId = soa_ensure_assessment($db, $enrollment, $syId, 'backfill');
            if ($assessmentId <= 0) { $db->rollback(); $noAssess++; continue; }

            $aStmt = $db->prepare('SELECT student_id, net_assessed, total_paid FROM student_assessment WHERE id = ? FOR UPDATE');
            $aStmt->bind_param('i', $assessmentId);
            $aStmt->execute();
            $asmt = stmt_fetch_assoc($aStmt);
            $studentId = (string) ($asmt['student_id'] ?? '');
            $net       = (float) ($asmt['net_assessed'] ?? 0);
            $prevPaid  = (float) ($asmt['total_paid'] ?? 0);

            $zero = 0.00;
            $insP = $db->prepare(
                "INSERT INTO payment_transaction
                    (assessment_id, soa_id, method, reference_no, amount, tendered, change_amount,
                     status, received_by, idempotency_key, paid_at)
                 VALUES (?, NULL, ?, NULL, ?, ?, ?, 'Posted', ?, ?, ?)"
            );
            $insP->bind_param('isdddsss', $assessmentId, $method, $amount, $amount, $zero, $cashier, $idemKey, $paidAt);
            $insP->execute();
            $paymentId = (int) $db->insert_id;

            $orNumber = $origOr !== '' ? $origOr : ('LEG-' . $rowId);
            $exists = $db->prepare('SELECT id FROM receipt_master WHERE or_number = ? LIMIT 1');
            $exists->bind_param('s', $orNumber);
            $exists->execute();
            if (stmt_fetch_assoc($exists)) { $orNumber = 'LEG-' . $rowId; }
            $series = 'LEGACY'; $seq = 0;
            $insR = $db->prepare(
                'INSERT INTO receipt_master (payment_id, or_number, series, sequence, reprint_count, issued_by, issued_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)'
            );
            $insR->bind_param('ississ', $paymentId, $orNumber, $series, $seq, $cashier, $paidAt);
            $insR->execute();
            $receiptId = (int) $db->insert_id;

            $lines = [
                ['ADMISSION', 'admission', 'Admission / Registration Fee', round((float) $r['fee_admission'], 2)],
                ['ACTIVITY',  'activity',  'Activity Fees',                round((float) $r['fee_activity'], 2)],
                ['HOUSE_REG', 'house',     'House Registration',           round((float) $r['fee_house_reg'], 2)],
                ['BOOKS',     'books',     'Books',                        round((float) $r['fee_books'], 2)],
            ];
            $sumLines = 0.0;
            foreach ($lines as $l) { $sumLines += $l[3]; }
            $remainder = round($amount - $sumLines, 2);
            if ($remainder > 0) { $lines[] = ['INSTALLMENT', 'tuition', 'Tuition / Other', $remainder]; }

            $insD = $db->prepare(
                'INSERT INTO receipt_details (receipt_id, fee_item_id, category, description, amount)
                 VALUES (?, (SELECT id FROM fee_item WHERE code = ? LIMIT 1), ?, ?, ?)'
            );
            foreach ($lines as [$code, $cat, $desc, $amt]) {
                if ($amt <= 0) { continue; }
                $insD->bind_param('isssd', $receiptId, $code, $cat, $desc, $amt);
                $insD->execute();
            }

            $newPaid    = round($prevPaid + $amount, 2);
            $newBalance = round($net - $newPaid, 2);
            $newStatus  = $newBalance <= 0 ? 'Settled' : 'Active';
            $updA = $db->prepare('UPDATE student_assessment SET total_paid = ?, balance = ?, status = ?, updated_at = NOW() WHERE id = ?');
            $updA->bind_param('ddsi', $newPaid, $newBalance, $newStatus, $assessmentId);
            $updA->execute();

            soa_ledger_add($db, $assessmentId, $studentId, $syId, 'Payment', 'payment_transaction', $paymentId,
                'Enrollment payment (' . $method . ', backfilled)', 0.0, $amount, $newBalance, $cashier);
            soa_ledger_add($db, $assessmentId, $studentId, $syId, 'Receipt', 'receipt_master', $receiptId,
                'OR ' . $orNumber . ' (legacy)', 0.0, 0.0, $newBalance, $cashier);

            $db->commit();
            $imported++;
        } catch (Throwable $e) {
            $db->rollback();
            $errors++;
            if (count($messages) < 20) { $messages[] = "Row $rowId (enrollment $enrollment): " . $e->getMessage(); }
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'noAssess' => $noAssess, 'errors' => $errors, 'processed' => $n, 'messages' => $messages];
}
