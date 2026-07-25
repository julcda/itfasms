<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user)) {
    redirect_to(app_url('dashboard/index.php'));
}

// ── Load data: session (fresh) or DB (reprint) ────────────────────────────────
$mpId  = to_int($_GET['mp_id'] ?? 0);
$isDuplicate = false;
$data  = null;

if ($mpId > 0) {
    // Reprint from DB
    $isDuplicate = true;
    $stmt = $connection->prepare(
        'SELECT mp.id, mp.or_number, mp.month_label, mp.amount_due, mp.amount_paid,
                mp.payment_date, mp.cashier_name, mp.notes, mp.status,
                sa.student_id, sa.school_year, sa.total_fee, sa.total_paid, sa.balance,
                sa.payment_method,
                COALESCE(CONCAT(p.surname,\', \',p.firstname), CONCAT(osp.surname,\', \',osp.firstname)) AS full_name,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade,
                IFNULL(sc.Section_name, en.Department_section) AS section_name
         FROM monthly_payment mp
         JOIN student_account sa ON sa.id = mp.student_account_id
         LEFT JOIN enrollment en     ON en.id = sa.enrollment_id
         LEFT JOIN preregistration p  ON sa.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = sa.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE mp.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $mpId);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    if ($row) {
        $data = [
            'mp_id'          => $mpId,
            'or_number'      => (string) $row['or_number'],
            'full_name'      => strtoupper(trim((string) ($row['full_name'] ?? 'Student'))),
            'student_id'     => (string) $row['student_id'],
            'grade'          => (string) ($row['grade'] ?? ''),
            'section'        => (string) ($row['section_name'] ?? ''),
            'school_year'    => (string) $row['school_year'],
            'month_label'    => (string) $row['month_label'],
            'amount_due'     => (float)  $row['amount_due'],
            'amount_paid'    => (float)  $row['amount_paid'],
            'new_total_paid' => (float)  ($row['total_paid'] ?? 0),
            'payment_status' => (string) $row['status'],
            'cashier_name'   => (string) ($row['cashier_name'] ?? ''),
            'payment_date'   => (string) ($row['payment_date'] ?? ''),
            'balance'        => (float)  ($row['balance'] ?? 0),
            'notes'          => (string) ($row['notes'] ?? ''),
        ];
    }
} elseif (isset($_SESSION['monthly_receipt_data'])) {
    $data = $_SESSION['monthly_receipt_data'];
    unset($_SESSION['monthly_receipt_data']);
}

if (!$data) {
    flash_set('error', 'Receipt data not found.');
    redirect_to('monthly_payments.php');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function mp_amount_in_words(float $amount): string
{
    $int  = (int) floor($amount);
    $dec  = (int) round(($amount - $int) * 100);
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
             'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
             'Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $convert = function(int $n) use ($ones, $tens, &$convert): string {
        if ($n < 20)  return $ones[$n];
        if ($n < 100) return $tens[(int)($n/10)] . ($n%10 ? ' ' . $ones[$n%10] : '');
        return $ones[(int)($n/100)] . ' Hundred' . ($n%100 ? ' ' . $convert($n%100) : '');
    };
    if ($int === 0 && $dec === 0) return 'Zero Pesos Only';
    $words = '';
    if ($int >= 1000000) { $words .= $convert((int)($int/1000000)) . ' Million '; $int %= 1000000; }
    if ($int >= 1000)    { $words .= $convert((int)($int/1000))    . ' Thousand '; $int %= 1000; }
    if ($int > 0)        { $words .= $convert($int) . ' '; }
    $words .= 'Pesos';
    if ($dec > 0)        $words .= ' and ' . $convert($dec) . '/100 Centavos';
    return trim($words) . ' Only';
}

$logoUrl = h(app_url('itfalogo.png'));
$orNum   = (string) ($data['or_number'] ?? '');
$paidAmt = (float)  ($data['amount_paid'] ?? 0);
$dueAmt  = (float)  ($data['amount_due']  ?? 0);
$balance = (float)  ($data['balance']     ?? 0);
$paidDate = !empty($data['payment_date']) ? date('F j, Y g:i A', strtotime($data['payment_date'])) : date('F j, Y g:i A');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monthly Receipt <?= h($orNum) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--navy:#1b3f7a;--navy-light:#2a5298;--gold:#c9a227;}
        body{font-family:'Inter',sans-serif;background:#f0f0f0;display:flex;flex-direction:column;align-items:center;padding:24px 0;min-height:100vh;}
        .receipt{background:#fff;width:148mm;min-height:210mm;position:relative;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.18);border-radius:4px;}
        /* duplicate stamp */
        <?php if ($isDuplicate): ?>
        .receipt::before{content:'DUPLICATE';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:68px;font-weight:900;color:rgba(211,47,47,.10);letter-spacing:6px;z-index:10;pointer-events:none;white-space:nowrap;}
        <?php endif; ?>
        /* header */
        .hdr{background:var(--navy);color:#fff;padding:12px 16px 10px;display:flex;align-items:center;gap:12px;}
        .hdr-logo{width:44px;height:44px;object-fit:contain;filter:brightness(0) invert(1);}
        .hdr-txt{flex:1;}
        .hdr-school{font-family:'Playfair Display',serif;font-size:12.5px;font-weight:800;line-height:1.2;letter-spacing:.5px;}
        .hdr-sub{font-size:8.5px;opacity:.8;margin-top:2px;letter-spacing:.3px;}
        .hdr-or{text-align:right;}
        .hdr-or-badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:6px;padding:4px 8px;display:inline-block;}
        .hdr-or-label{font-size:7.5px;opacity:.7;letter-spacing:.5px;text-transform:uppercase;}
        .hdr-or-num{font-size:11px;font-weight:700;letter-spacing:.5px;}
        /* receipt type banner */
        .type-banner{background:var(--gold);color:#fff;text-align:center;padding:5px 0;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;}
        /* barcode */
        .bc-wrap{background:#f8f9fa;padding:8px 12px 6px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;}
        .bc-svg{height:34px;}
        .bc-info{font-size:8.5px;color:#64748b;line-height:1.5;}
        /* body */
        .rc-body{padding:12px 16px;}
        .section-head{font-size:8px;font-weight:700;color:var(--navy);letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;padding-bottom:3px;border-bottom:1.5px solid var(--navy);}
        /* student info */
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;margin-bottom:12px;}
        .info-item label{display:block;font-size:7px;color:#94a3b8;letter-spacing:.5px;text-transform:uppercase;margin-bottom:1px;}
        .info-item p{font-size:9.5px;font-weight:600;color:#1e293b;}
        /* payment details */
        .pay-table{width:100%;border-collapse:collapse;margin-bottom:12px;}
        .pay-table th{font-size:7.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;padding:4px 6px;background:#f8fafc;text-align:left;}
        .pay-table th:last-child{text-align:right;}
        .pay-table td{font-size:9px;padding:5px 6px;border-top:1px solid #f1f5f9;}
        .pay-table td:last-child{text-align:right;font-weight:600;}
        .pay-table tr.total-row td{background:#f8fafc;font-weight:700;font-size:10px;border-top:1.5px solid #cbd5e1;}
        /* amount in words */
        .words-box{background:#f0f4ff;border:1px solid #c7d7f5;border-radius:6px;padding:7px 10px;margin-bottom:12px;}
        .words-label{font-size:7px;color:var(--navy);font-weight:700;letter-spacing:.5px;text-transform:uppercase;margin-bottom:2px;}
        .words-text{font-size:9px;color:#1e293b;font-style:italic;}
        /* balance summary */
        .bal-box{background:var(--navy);color:#fff;border-radius:6px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
        .bal-item{text-align:center;}
        .bal-item-label{font-size:7px;opacity:.7;letter-spacing:.5px;text-transform:uppercase;}
        .bal-item-value{font-size:11px;font-weight:800;margin-top:1px;}
        /* signature */
        .sig-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:10px;}
        .sig-box{text-align:center;}
        .sig-line{border-top:1px solid #cbd5e1;padding-top:4px;margin-top:22px;}
        .sig-label{font-size:7.5px;color:#64748b;}
        /* footer */
        .rc-footer{background:#f8fafc;border-top:1px solid #e2e8f0;padding:6px 16px;display:flex;justify-content:space-between;align-items:center;}
        .rc-footer p{font-size:7.5px;color:#94a3b8;}
        /* print controls */
        .print-controls{margin-top:18px;display:flex;gap:10px;}
        .print-controls a,
        .print-controls button{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;border:none;}
        .btn-print{background:var(--navy);color:#fff;}
        .btn-back{background:#f1f5f9;color:#334155;}
        @media print{
            @page{size:148mm 210mm;margin:0;}
            body{background:#fff;padding:0;}
            .receipt{box-shadow:none;border-radius:0;width:148mm;min-height:210mm;}
            .print-controls{display:none;}
        }
    </style>
</head>
<body>
<div class="receipt">
    <!-- Header -->
    <div class="hdr">
        <img src="<?= $logoUrl ?>" alt="ITFA" class="hdr-logo">
        <div class="hdr-txt">
            <div class="hdr-school">ITFA School System</div>
            <div class="hdr-sub">Official Monthly Payment Receipt</div>
        </div>
        <div class="hdr-or">
            <div class="hdr-or-badge">
                <div class="hdr-or-label">OR Number</div>
                <div class="hdr-or-num"><?= h($orNum) ?></div>
            </div>
        </div>
    </div>

    <!-- Type banner -->
    <div class="type-banner">Monthly Installment Payment<?= $isDuplicate ? ' — Duplicate' : '' ?></div>

    <!-- Barcode -->
    <div class="bc-wrap">
        <svg id="barcode" class="bc-svg"></svg>
        <div class="bc-info">
            <div><strong><?= h($orNum) ?></strong></div>
            <div><?= h($paidDate) ?></div>
            <div>Cashier: <?= h((string)($data['cashier_name']??'')) ?></div>
        </div>
    </div>

    <!-- Body -->
    <div class="rc-body">

        <!-- Student info -->
        <div class="section-head">Student Information</div>
        <div class="info-grid" style="margin-bottom:10px;">
            <div class="info-item" style="grid-column:1/-1;">
                <label>Name</label>
                <p><?= h((string)($data['full_name']??'')) ?></p>
            </div>
            <div class="info-item">
                <label>Student ID</label>
                <p><?= h((string)($data['student_id']??'')) ?></p>
            </div>
            <div class="info-item">
                <label>School Year</label>
                <p><?= h((string)($data['school_year']??'')) ?></p>
            </div>
            <?php if (!empty($data['grade'])): ?>
            <div class="info-item">
                <label>Grade Level</label>
                <p><?= h((string)$data['grade']) ?></p>
            </div>
            <?php endif; ?>
            <?php if (!empty($data['section'])): ?>
            <div class="info-item">
                <label>Section</label>
                <p><?= h((string)$data['section']) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment details -->
        <div class="section-head">Payment Details</div>
        <table class="pay-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Monthly Installment — <strong><?= h((string)($data['month_label']??'')) ?></strong></td>
                    <td>₱<?= number_format($dueAmt, 2) ?></td>
                </tr>
                <tr class="total-row">
                    <td>Amount Collected</td>
                    <td>₱<?= number_format($paidAmt, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Amount in words -->
        <div class="words-box">
            <div class="words-label">Amount in Words</div>
            <div class="words-text"><?= h(mp_amount_in_words($paidAmt)) ?></div>
        </div>

        <!-- Balance summary bar -->
        <div class="bal-box">
            <div class="bal-item">
                <div class="bal-item-label">Month Due</div>
                <div class="bal-item-value">₱<?= number_format($dueAmt, 2) ?></div>
            </div>
            <div class="bal-item">
                <div class="bal-item-label">Paid This Month</div>
                <div class="bal-item-value">₱<?= number_format($paidAmt, 2) ?></div>
            </div>
            <div class="bal-item">
                <div class="bal-item-label">Account Balance</div>
                <div class="bal-item-value">₱<?= number_format($balance, 2) ?></div>
            </div>
        </div>

        <?php if (!empty($data['notes'])): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;margin-bottom:10px;font-size:8.5px;color:#92400e;">
            <strong>Note:</strong> <?= h((string)$data['notes']) ?>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="sig-row">
            <div class="sig-box">
                <div class="sig-line">
                    <div class="sig-label">Received by (Student / Parent)</div>
                </div>
            </div>
            <div class="sig-box">
                <div class="sig-line" style="border-color:#1b3f7a;">
                    <div class="sig-label" style="color:var(--navy);font-weight:700;"><?= h((string)($data['cashier_name']??'Cashier')) ?></div>
                    <div class="sig-label">Cashier</div>
                </div>
            </div>
        </div>

    </div><!-- /rc-body -->

    <!-- Footer -->
    <div class="rc-footer">
        <p>S.Y. <?= h((string)($data['school_year']??'')) ?> · <?= h($orNum) ?></p>
        <p><?= $isDuplicate ? 'DUPLICATE COPY' : 'ORIGINAL COPY' ?></p>
        <p><?= h(date('M d, Y', strtotime($data['payment_date'] ?: 'now'))) ?></p>
    </div>
</div>

<div class="print-controls">
    <button class="btn-print" onclick="window.print()">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
        </svg>
        Print Receipt
    </button>
    <a href="monthly_payments.php" class="btn-back">← Back</a>
</div>

<script>
JsBarcode('#barcode', <?= json_encode($orNum) ?>, {
    format: 'CODE128', displayValue: false,
    lineColor: '#1b3f7a', width: 1.5, height: 34, margin: 0
});
window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
