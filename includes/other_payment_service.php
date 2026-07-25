<?php

declare(strict_types=1);

/**
 * Other / Miscellaneous cashier payments — ID, Sling, Certification, Good Moral,
 * Form 137, Entrance Exam, open/custom items, etc. Issues an itemized Official
 * Receipt. Built on the existing `payment_others` table (+ items + catalog).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/soa_service.php';

function op_schema_ready(mysqli $db): bool
{
    return soa_table_exists($db, 'other_payment_items') && soa_table_exists($db, 'other_fee_items');
}

/** Active catalog items (the counter pick-list). */
function op_catalog(mysqli $db): array
{
    $out = [];
    $r = $db->query('SELECT id, name, default_amount FROM other_fee_items WHERE active = 1 ORDER BY sort_order, name');
    if ($r) { while ($x = $r->fetch_assoc()) { $out[] = $x; } }
    return $out;
}

/** One receipt header (with payer/student display). */
function op_get(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM payment_others WHERE Payment_ID = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** Itemized lines for a receipt. */
function op_items(mysqli $db, int $id): array
{
    $stmt = $db->prepare('SELECT item_name, quantity, unit_amount, amount FROM other_payment_items WHERE payment_id = ? ORDER BY id');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/**
 * Record an other-payment and issue an OR.
 *
 * @param array $data ['name','student_id'?,'enrollment_id'?,'payment_method','reference_no'?,
 *                     'items'=>[['name','quantity','unit_amount'], …]]
 * @return array{payment_id:int,or_number:string,total:float}
 */
function op_create(mysqli $db, array $data, array $user): array
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Payer name is required.');
    }
    $method = in_array((string) ($data['payment_method'] ?? 'Cash'), ['Cash', 'GCash', 'Maya', 'Bank'], true)
        ? (string) $data['payment_method'] : 'Cash';
    $refNo  = trim((string) ($data['reference_no'] ?? '')) ?: null;
    $sid    = trim((string) ($data['student_id'] ?? '')) ?: null;
    $eid    = (int) ($data['enrollment_id'] ?? 0) ?: null;

    // Normalise line items.
    $items = [];
    $total = 0.0;
    foreach ((array) ($data['items'] ?? []) as $it) {
        $iname = trim((string) ($it['name'] ?? ''));
        $qty   = max(1, (int) ($it['quantity'] ?? 1));
        $unit  = round((float) ($it['unit_amount'] ?? 0), 2);
        if ($iname === '' || $unit <= 0) {
            continue;
        }
        $amt   = round($unit * $qty, 2);
        $items[] = ['name' => $iname, 'qty' => $qty, 'unit' => $unit, 'amount' => $amt];
        $total  += $amt;
    }
    $total = round($total, 2);
    if ($items === [] || $total <= 0) {
        throw new RuntimeException('Add at least one item with an amount greater than zero.');
    }

    $sy        = soa_active_school_year($db);
    $syId      = (int) $sy['id'];
    $syLabel   = (string) $sy['label'];
    $cashierId = (int) ($user['id'] ?? 0);
    $cashier   = (string) ($user['full_name'] ?? 'Cashier');
    $year      = (int) date('Y');
    $prefix    = soa_setting($db, 'OTHER_OR_PREFIX', 'OR');
    $purpose   = implode('; ', array_map(static fn($i) => $i['name'] . ($i['qty'] > 1 ? ' x' . $i['qty'] : ''), $items));
    $today     = date('Y-m-d');

    $db->begin_transaction();
    try {
        $orNo = soa_next_document_number($db, 'OTHER', $prefix, $year);

        $ins = $db->prepare(
            'INSERT INTO payment_others
                (Name, Purpose, Date, Amount, or_number, school_year, student_id, enrollment_id,
                 payment_method, reference_no, cashier_name, cashier_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Paid\')'
        );
        $ins->bind_param(
            'sssdsssssssi',
            $name, $purpose, $today, $total, $orNo, $syLabel, $sid, $eid,
            $method, $refNo, $cashier, $cashierId
        );
        $ins->execute();
        $paymentId = (int) $db->insert_id;

        $iStmt = $db->prepare('INSERT INTO other_payment_items (payment_id, item_name, quantity, unit_amount, amount) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $i) {
            $iStmt->bind_param('isidd', $paymentId, $i['name'], $i['qty'], $i['unit'], $i['amount']);
            $iStmt->execute();
        }

        // Fold into the day's collection (so end-of-day close includes it).
        $cash   = $method === 'Cash' ? $total : 0.0;
        $online = in_array($method, ['GCash', 'Maya', 'Bank'], true) ? $total : 0.0;
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
        $insC->bind_param('isiddd', $cashierId, $cashier, $syId, $cash, $online, $total);
        $insC->execute();

        soa_audit($db, $cashierId, $cashier, 'OTHER_PAYMENT', 'payment_others', (string) $paymentId, null,
            json_encode(['or_number' => $orNo, 'name' => $name, 'total' => $total, 'purpose' => $purpose]));

        $db->commit();
        return ['payment_id' => $paymentId, 'or_number' => $orNo, 'total' => $total];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Could not record payment: ' . $e->getMessage());
    }
}

/** Void an other-payment (reverses its day-collection contribution). */
function op_void(mysqli $db, int $id, array $user): void
{
    $pay = op_get($db, $id);
    if (!$pay) {
        throw new RuntimeException('Payment not found.');
    }
    if ((string) $pay['status'] === 'Void') {
        throw new RuntimeException('This payment is already void.');
    }
    $cashier = (string) ($user['full_name'] ?? 'Cashier');

    $db->begin_transaction();
    try {
        $upd = $db->prepare("UPDATE payment_others SET status='Void', voided_by=?, voided_at=NOW() WHERE Payment_ID=?");
        $upd->bind_param('si', $cashier, $id);
        $upd->execute();

        // Back out the day's collection for the original cashier/date (best effort).
        $amount = (float) $pay['Amount'];
        $method = (string) ($pay['payment_method'] ?? 'Cash');
        $bizDate = substr((string) ($pay['created_at'] ?? $pay['Date']), 0, 10);
        $who = (string) ($pay['cashier_name'] ?? '');
        if ($who !== '' && $bizDate !== '') {
            $cash   = $method === 'Cash' ? $amount : 0.0;
            $online = in_array($method, ['GCash', 'Maya', 'Bank'], true) ? $amount : 0.0;
            $updC = $db->prepare(
                'UPDATE collection_summary
                    SET txn_count = GREATEST(0, txn_count - 1),
                        total_cash = GREATEST(0, total_cash - ?),
                        total_online = GREATEST(0, total_online - ?),
                        total_collected = GREATEST(0, total_collected - ?)
                  WHERE cashier_name = ? AND business_date = ?'
            );
            $updC->bind_param('dddss', $cash, $online, $amount, $who, $bizDate);
            $updC->execute();
        }

        soa_audit($db, (int) ($user['id'] ?? 0), $cashier, 'VOID_OTHER_PAYMENT', 'payment_others', (string) $id, null,
            json_encode(['or_number' => $pay['or_number'], 'amount' => $amount]));

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Void failed: ' . $e->getMessage());
    }
}

/**
 * Recent other-payments for the active SY (that carry an OR — i.e. issued by
 * this module), newest first, with optional search / status filter.
 */
function op_list(mysqli $db, string $q = '', string $status = '', int $limit = 200): array
{
    $where  = ["po.or_number IS NOT NULL"];
    $types  = '';
    $params = [];
    if ($q !== '') {
        $where[] = "(po.or_number LIKE ? OR po.Name LIKE ? OR po.Purpose LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }
    if (in_array($status, ['Paid', 'Void'], true)) {
        $where[] = 'po.status = ?';
        $types  .= 's';
        $params[] = $status;
    }
    $sql = "SELECT Payment_ID, Name, Purpose, Amount, or_number, payment_method, status, cashier_name,
                   COALESCE(created_at, Date) AS when_paid
            FROM payment_others po
            WHERE " . implode(' AND ', $where) . "
            ORDER BY Payment_ID DESC LIMIT " . (int) $limit;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/** Today's other-payment totals (for the page summary). */
function op_today_totals(mysqli $db): array
{
    $r = $db->query("SELECT IFNULL(SUM(Amount),0) s, COUNT(*) c FROM payment_others WHERE status='Paid' AND or_number IS NOT NULL AND DATE(COALESCE(created_at, Date)) = CURDATE()");
    $row = $r ? $r->fetch_assoc() : ['s' => 0, 'c' => 0];
    return ['total' => (float) $row['s'], 'count' => (int) $row['c']];
}
