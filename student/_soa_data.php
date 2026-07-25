<?php
/**
 * Shared SOA data builder for the student portal.
 * Input:  $db (mysqli), $enrollmentId (int)
 * Output (defined in scope): $soa = [
 *   'assessment'      => row|null,
 *   'enrollFees'      => [['label','amount'], …],   // admission/activity/house/books-down
 *   'enrollFeesTotal' => float,
 *   'monthly'         => [component => amount],      // tuition/misc/improvement/books
 *   'monthlyTotal'    => float,
 *   'installmentCount'=> int,
 *   'installmentBase' => float,
 *   'netAssessed'     => float,
 *   'totalPaid'       => float,
 *   'balance'         => float,
 *   'payStatus'       => 'Fully Paid'|'Partially Paid'|'Unpaid'|'No Assessment',
 *   'payments'        => [['or_number','paid_at','method','amount','running'], …],
 * ]
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/soa_service.php';
require_once __DIR__ . '/../includes/promissory_service.php';
require_once __DIR__ . '/../includes/back_account_service.php';

$soa = [
    'assessment' => null, 'enrollFees' => [], 'enrollFeesTotal' => 0.0,
    'monthly' => [], 'monthlyTotal' => 0.0, 'installmentCount' => 0, 'installmentBase' => 0.0,
    'netAssessed' => 0.0, 'totalPaid' => 0.0, 'balance' => 0.0,
    'payStatus' => 'No Assessment', 'payments' => [],
    'officialSoaId' => 0,   // latest cashier-generated soa_master id (0 = none yet)
    'officialSoaPaid' => false, // that SOA's billed months are fully paid
    'promissoryNotes' => [], 'promissoryTotal' => 0.0,  // unpaid promissory arrangements
    'backAccounts' => [], 'backAccountTotal' => 0.0,    // unpaid prior-S.Y. balances
];

// Promissory notes are independent of an assessment existing — surface them always.
pn_mark_overdue($db);
$soa['promissoryNotes'] = pn_active_for_enrollment($db, $enrollmentId);
$soa['promissoryTotal'] = pn_unpaid_total($db, $enrollmentId);

// Back accounts likewise stand apart from this year's assessment — they are a
// prior-S.Y. debt shown as a warning, never folded into this year's balance.
$soa['backAccounts']     = ba_unpaid_for_enrollment($db, $enrollmentId);
$soa['backAccountTotal'] = ba_unpaid_total_for_enrollment($db, $enrollmentId);

$sy = student_active_sy($db);

// Assessment (active SY).
$aStmt = $db->prepare(
    'SELECT id, net_assessed, total_paid, balance, enrollment_fees_total, installment_base, installment_count, status
     FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1'
);
$aStmt->bind_param('ii', $enrollmentId, $sy['id']);
$aStmt->execute();
$assessment = stmt_fetch_assoc($aStmt);

if ($assessment) {
    $aid  = (int) $assessment['id'];
    $soa['assessment']       = $assessment;
    $soa['netAssessed']      = (float) $assessment['net_assessed'];
    $soa['totalPaid']        = (float) $assessment['total_paid'];
    $soa['balance']          = (float) $assessment['balance'];
    $soa['enrollFeesTotal']  = (float) $assessment['enrollment_fees_total'];
    $soa['installmentBase']  = (float) $assessment['installment_base'];
    $soa['installmentCount'] = (int) $assessment['installment_count'];

    // Enrollment-day fee lines (non-installment charges).
    $cRes = $db->query(
        "SELECT description, amount FROM assessment_charge
         WHERE assessment_id = $aid AND is_installment_base = 0 ORDER BY id"
    );
    if ($cRes) {
        while ($c = $cRes->fetch_assoc()) {
            $soa['enrollFees'][] = ['label' => (string) $c['description'], 'amount' => (float) $c['amount']];
        }
    }

    // Monthly component split (tuition / misc / improvement / books).
    $fees = soa_fetch_enrollment_fees($db, $enrollmentId);
    if ($fees) {
        $soa['monthly'] = soa_components_for(
            $db, (string) ($fees['Department'] ?? ''), (string) ($fees['gradelevel_name'] ?? ''),
            (string) ($fees['classification'] ?? ''), (string) ($fees['student_type'] ?? 'Old'),
            (float) ($fees['rate'] ?? 0), (bool) ($fees['waive_improvement'] ?? false),
            (bool) ($fees['waive_misc'] ?? false)
        );
        $soa['monthlyTotal'] = array_sum($soa['monthly']);
    }

    // Payment history (Posted only), oldest first, with running balance.
    $pRes = $db->query(
        "SELECT pt.amount, pt.method, pt.paid_at, COALESCE(rm.or_number,'—') AS or_number
         FROM payment_transaction pt
         LEFT JOIN receipt_master rm ON rm.payment_id = pt.id
         WHERE pt.assessment_id = $aid AND pt.status = 'Posted'
         ORDER BY pt.paid_at ASC, pt.id ASC"
    );
    $running = $soa['netAssessed'];
    if ($pRes) {
        while ($p = $pRes->fetch_assoc()) {
            $running = round($running - (float) $p['amount'], 2);
            $soa['payments'][] = [
                'or_number' => (string) $p['or_number'],
                'paid_at'   => (string) $p['paid_at'],
                'method'    => (string) $p['method'],
                'amount'    => (float) $p['amount'],
                'running'   => $running,
            ];
        }
    }

    $soa['payStatus'] = $soa['netAssessed'] <= 0
        ? 'No Assessment'
        : ($soa['balance'] <= 0 ? 'Fully Paid' : ($soa['totalPaid'] > 0 ? 'Partially Paid' : 'Unpaid'));

    // Has the cashier already generated an official SOA document for this student?
    $oRes = $db->query("SELECT id FROM soa_master WHERE assessment_id = $aid ORDER BY id DESC LIMIT 1");
    if ($oRes && ($o = $oRes->fetch_assoc())) {
        $soa['officialSoaId'] = (int) $o['id'];
        // Fully paid? Either the whole assessment is settled, or the SOA's billed
        // months have no remaining balance. A paid SOA is not offered for printing.
        $soaId = $soa['officialSoaId'];
        $rr = $db->query(
            "SELECT IFNULL(SUM(ps.balance),0) rem
             FROM soa_details sd JOIN payment_schedule ps ON ps.id = sd.schedule_id
             WHERE sd.soa_id = $soaId"
        );
        $rem = $rr ? (float) ($rr->fetch_assoc()['rem'] ?? 0) : 0.0;
        $soa['officialSoaPaid'] = ($soa['balance'] <= 0.009) || ($rem <= 0.009);
    }
}
