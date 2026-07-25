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

// ── Accept ?account_id or ?enrollment_id ─────────────────────────────────────
$accountId    = to_int($_GET['account_id']    ?? 0);
$enrollmentId = to_int($_GET['enrollment_id'] ?? 0);

// Resolve enrollment_id → account_id
if ($accountId <= 0 && $enrollmentId > 0) {
    $rStmt = $connection->prepare('SELECT id FROM student_account WHERE enrollment_id = ? LIMIT 1');
    $rStmt->bind_param('i', $enrollmentId);
    $rStmt->execute();
    $rRow = stmt_fetch_assoc($rStmt);
    if ($rRow) $accountId = (int) $rRow['id'];
}

if ($accountId <= 0) {
    flash_set('error', 'Invalid account.');
    redirect_to('monthly_payments.php');
}

// ── Fetch student account ─────────────────────────────────────────────────────
$acctStmt = $connection->prepare(
    'SELECT sa.*,
            COALESCE(CONCAT(p.surname,\', \',p.firstname), CONCAT(osp.surname,\', \',osp.firstname)) AS full_name,
            IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
            IFNULL(sc.Section_name, en.Department_section) AS section_name,
            en.Department,
            pb.description AS classification_desc,
            IF(p.id IS NOT NULL, \'New\', \'Old\') AS student_type
     FROM student_account sa
     LEFT JOIN enrollment en     ON en.id = sa.enrollment_id
     LEFT JOIN preregistration p  ON sa.student_id = CAST(p.id AS CHAR)
     LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = sa.student_id)
     LEFT JOIN payment_breakdown pb
            ON pb.classification_id = en.Student_classification
           AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
           AND pb.status = \'Active\'
     LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
     LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
     WHERE sa.id = ? LIMIT 1'
);
$acctStmt->bind_param('i', $accountId);
$acctStmt->execute();
$acct = stmt_fetch_assoc($acctStmt);

if (!$acct) {
    flash_set('error', 'Account not found.');
    redirect_to('monthly_payments.php');
}

// ── Fetch monthly schedule ────────────────────────────────────────────────────
$schedStmt = $connection->prepare(
    'SELECT * FROM monthly_payment WHERE student_account_id = ? ORDER BY payment_year, payment_month'
);
$schedStmt->bind_param('i', $accountId);
$schedStmt->execute();
$schedule = stmt_fetch_all_assoc($schedStmt);

// ── Derived values ────────────────────────────────────────────────────────────
$studentName    = strtoupper(trim((string) ($acct['full_name'] ?? 'Student')));
$studentId      = (string) ($acct['student_id'] ?? '');
$schoolYear     = (string) ($acct['school_year'] ?? '');
$department     = (string) ($acct['Department'] ?? '');
$gradeName      = (string) ($acct['gradelevel_name'] ?? '');
$sectionName    = (string) ($acct['section_name'] ?? '');
$classification = (string) ($acct['classification_desc'] ?? '—');
$studentType    = (string) ($acct['student_type'] ?? 'Old');
$paymentMethod  = (string) ($acct['payment_method'] ?? 'Installment');
$tuitionFee     = (float)  ($acct['tuition_fee'] ?? 0);
$improvFee      = (float)  ($acct['school_improvement'] ?? 0);
$enrollFee      = (float)  ($acct['enrollment_fee'] ?? 0);
$totalFee       = (float)  ($acct['total_fee'] ?? 0);
$totalPaid      = (float)  ($acct['total_paid'] ?? 0);
$balance        = (float)  ($acct['balance'] ?? 0);
$acctStatus     = (string) ($acct['status'] ?? 'Active');
$monthlyAmt     = (float)  ($acct['monthly_amount'] ?? 0);
$instMonths     = (int)    ($acct['installment_months'] ?? 0);
$generatedAt    = date('F j, Y');

$logoUrl = h(app_url('itfalogo.png'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement of Account — <?= h($studentName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        :root{--navy:#1b3f7a;--navy-light:#2a5298;--gold:#c9a227;}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;padding:24px 0;display:flex;flex-direction:column;align-items:center;min-height:100vh;}
        .soa{background:#fff;width:210mm;min-height:297mm;box-shadow:0 8px 32px rgba(0,0,0,.15);position:relative;overflow:hidden;}
        /* header */
        .hdr{background:var(--navy);color:#fff;padding:18px 24px 14px;display:flex;align-items:center;gap:14px;}
        .hdr-logo{width:54px;height:54px;object-fit:contain;filter:brightness(0) invert(1);}
        .hdr-school{font-family:'Playfair Display',serif;font-size:18px;font-weight:800;}
        .hdr-sub{font-size:10px;opacity:.75;margin-top:3px;letter-spacing:.3px;}
        .hdr-right{margin-left:auto;text-align:right;}
        .hdr-badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:8px 14px;display:inline-block;}
        .hdr-badge-label{font-size:8px;opacity:.7;letter-spacing:.8px;text-transform:uppercase;}
        .hdr-badge-title{font-size:16px;font-weight:800;margin-top:2px;letter-spacing:.5px;}
        /* divider banner */
        .soa-banner{background:var(--gold);color:#fff;text-align:center;padding:6px;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;}
        /* body */
        .soa-body{padding:20px 24px;}
        .section-head{font-size:9px;font-weight:700;color:var(--navy);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;padding-bottom:5px;border-bottom:2px solid var(--navy);}
        /* student info */
        .student-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px 16px;margin-bottom:18px;}
        .info-cell label{display:block;font-size:7.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;}
        .info-cell p{font-size:10px;font-weight:600;color:#1e293b;}
        .info-cell.wide{grid-column:1/-1;}
        /* fee summary */
        .fee-table{width:100%;border-collapse:collapse;margin-bottom:18px;}
        .fee-table th{background:#f8fafc;font-size:8px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;padding:7px 10px;border-bottom:1px solid #e2e8f0;text-align:left;}
        .fee-table th:last-child{text-align:right;}
        .fee-table td{font-size:9.5px;padding:6px 10px;border-bottom:1px solid #f1f5f9;}
        .fee-table td:last-child{text-align:right;font-weight:600;}
        .fee-table tr.subtotal td{background:#f8fafc;font-weight:700;font-size:10px;border-top:1.5px solid #cbd5e1;}
        /* ledger */
        .ledger-table{width:100%;border-collapse:collapse;margin-bottom:20px;}
        .ledger-table th{background:var(--navy);color:#fff;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:7px 8px;text-align:left;}
        .ledger-table th:last-child,.ledger-table th.num{text-align:right;}
        .ledger-table td{font-size:9px;padding:6px 8px;border-bottom:1px solid #f1f5f9;}
        .ledger-table td.num{text-align:right;}
        .ledger-table tr:nth-child(even) td{background:#f8fafc;}
        .badge{display:inline-block;border-radius:999px;padding:2px 8px;font-size:7.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
        .badge-unpaid{background:#f1f5f9;color:#475569;}
        .badge-paid{background:#dcfce7;color:#166534;}
        .badge-partial{background:#fef3c7;color:#92400e;}
        .badge-overdue{background:#fee2e2;color:#991b1b;}
        /* financial summary */
        .fin-box{background:var(--navy);color:#fff;border-radius:8px;padding:16px 20px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;}
        .fin-item-label{font-size:8px;opacity:.7;letter-spacing:.5px;text-transform:uppercase;}
        .fin-item-value{font-size:16px;font-weight:900;margin-top:3px;}
        .fin-item-value.green{color:#6ee7b7;}
        .fin-item-value.amber{color:#fcd34d;}
        /* signature */
        .sig-row{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-bottom:14px;}
        .sig-box{text-align:center;}
        .sig-line{border-top:1px solid #cbd5e1;padding-top:5px;margin-top:32px;}
        .sig-label{font-size:8.5px;color:#64748b;}
        /* footer */
        .soa-footer{background:#f8fafc;border-top:1px solid #e2e8f0;padding:8px 24px;display:flex;justify-content:space-between;align-items:center;}
        .soa-footer p{font-size:8px;color:#94a3b8;}
        /* print controls */
        .print-controls{margin-top:20px;display:flex;gap:10px;}
        .print-controls a,
        .print-controls button{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;border:none;}
        .btn-print{background:var(--navy);color:#fff;}
        .btn-back{background:#f1f5f9;color:#334155;}
        @media print{
            @page{size:A4 portrait;margin:0;}
            body{background:#fff;padding:0;}
            .soa{box-shadow:none;width:210mm;min-height:297mm;}
            .print-controls{display:none;}
        }
    </style>
</head>
<body>
<div class="soa">

    <!-- Header -->
    <div class="hdr">
        <img src="<?= $logoUrl ?>" alt="ITFA" class="hdr-logo">
        <div>
            <div class="hdr-school">ITFA School System</div>
            <div class="hdr-sub">Electronic Statement of Account</div>
        </div>
        <div class="hdr-right">
            <div class="hdr-badge">
                <div class="hdr-badge-label">School Year</div>
                <div class="hdr-badge-title">S.Y. <?= h($schoolYear) ?></div>
            </div>
        </div>
    </div>

    <div class="soa-banner">Student Statement of Account</div>

    <div class="soa-body">

        <!-- Student info -->
        <div class="section-head">Student Information</div>
        <div class="student-grid">
            <div class="info-cell wide">
                <label>Student Name</label>
                <p style="font-size:13px;font-weight:800;color:var(--navy)"><?= h($studentName) ?></p>
            </div>
            <div class="info-cell">
                <label>Student ID</label>
                <p><?= h($studentId) ?></p>
            </div>
            <div class="info-cell">
                <label>School Year</label>
                <p><?= h($schoolYear) ?></p>
            </div>
            <div class="info-cell">
                <label>Department</label>
                <p><?= h($department) ?></p>
            </div>
            <div class="info-cell">
                <label>Grade Level</label>
                <p><?= h($gradeName) ?></p>
            </div>
            <div class="info-cell">
                <label>Section</label>
                <p><?= h($sectionName ?: '—') ?></p>
            </div>
            <div class="info-cell">
                <label>Classification</label>
                <p><?= h($classification) ?></p>
            </div>
            <div class="info-cell">
                <label>Student Type</label>
                <p><?= h($studentType) ?></p>
            </div>
            <div class="info-cell">
                <label>Payment Method</label>
                <p><?= h($paymentMethod) ?></p>
            </div>
        </div>

        <!-- Fee summary -->
        <div class="section-head">Fee Summary</div>
        <table class="fee-table">
            <thead>
                <tr>
                    <th>Fee Description</th>
                    <th style="text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Tuition Fee</td>
                    <td>₱<?= number_format($tuitionFee, 2) ?></td>
                </tr>
                <tr>
                    <td>School Improvement Fee</td>
                    <td>₱<?= number_format($improvFee, 2) ?></td>
                </tr>
                <tr>
                    <td>Enrollment Fee</td>
                    <td>₱<?= number_format($enrollFee, 2) ?></td>
                </tr>
                <?php if ($paymentMethod === 'Installment'): ?>
                <tr>
                    <td style="color:#64748b;font-size:8.5px;">Monthly Rate: ₱<?= number_format($monthlyAmt, 2) ?> × <?= $instMonths ?> months</td>
                    <td style="color:#64748b;font-size:8.5px;text-align:right;"></td>
                </tr>
                <?php endif; ?>
                <tr class="subtotal">
                    <td><strong>Total Amount Due</strong></td>
                    <td><strong>₱<?= number_format($totalFee, 2) ?></strong></td>
                </tr>
            </tbody>
        </table>

        <!-- Monthly ledger (only for installment) -->
        <?php if (!empty($schedule)): ?>
        <div class="section-head">Monthly Payment Ledger</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width:24px">#</th>
                    <th>Month</th>
                    <th>Due Date</th>
                    <th class="num">Amount Due</th>
                    <th class="num">Amount Paid</th>
                    <th>OR Number</th>
                    <th>Payment Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedule as $i => $row): ?>
                <?php
                    $rs = (string) ($row['status'] ?? 'Unpaid');
                    $rdueDate = $row['due_date'] ? date('M d, Y', strtotime($row['due_date'])) : '—';
                    $rpayDate = $row['payment_date'] ? date('M d, Y', strtotime($row['payment_date'])) : '—';
                    $badgeCls = match($rs) { 'Paid'=>'badge-paid','Partial'=>'badge-partial','Overdue'=>'badge-overdue',default=>'badge-unpaid'};
                ?>
                <tr>
                    <td class="num"><?= $i+1 ?></td>
                    <td><?= h((string)$row['month_label']) ?></td>
                    <td><?= $rdueDate ?></td>
                    <td class="num">₱<?= number_format((float)$row['amount_due'], 2) ?></td>
                    <td class="num"><?= (float)$row['amount_paid']>0 ? '₱'.number_format((float)$row['amount_paid'],2) : '<span style="color:#cbd5e1">—</span>' ?></td>
                    <td style="font-size:8px;font-family:monospace;"><?= $row['or_number'] ? h((string)$row['or_number']) : '<span style="color:#cbd5e1">—</span>' ?></td>
                    <td><?= $rs==='Paid'||$rs==='Partial' ? $rpayDate : '<span style="color:#cbd5e1">—</span>' ?></td>
                    <td><span class="badge <?= $badgeCls ?>"><?= h($rs) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Financial summary -->
        <div class="fin-box">
            <div class="fin-item">
                <div class="fin-item-label">Total Due</div>
                <div class="fin-item-value">₱<?= number_format($totalFee, 2) ?></div>
            </div>
            <div class="fin-item">
                <div class="fin-item-label">Total Paid</div>
                <div class="fin-item-value green">₱<?= number_format($totalPaid, 2) ?></div>
            </div>
            <div class="fin-item">
                <div class="fin-item-label">Outstanding Balance</div>
                <div class="fin-item-value <?= $balance>0?'amber':'green' ?>">₱<?= number_format($balance, 2) ?></div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="sig-row">
            <div class="sig-box">
                <div class="sig-line">
                    <div class="sig-label">Received by (Student / Parent / Guardian)</div>
                </div>
            </div>
            <div class="sig-box">
                <div class="sig-line" style="border-color:var(--navy);">
                    <div class="sig-label" style="color:var(--navy);font-weight:700;">Cashier</div>
                    <div class="sig-label">ITFA School System</div>
                </div>
            </div>
        </div>

    </div><!-- /soa-body -->

    <!-- Footer -->
    <div class="soa-footer">
        <p>ITFA School System · <?= h($schoolYear) ?></p>
        <p>Generated: <?= h($generatedAt) ?> · Account #<?= $accountId ?></p>
        <p>This is a computer-generated document.</p>
    </div>
</div>

<div class="print-controls">
    <button class="btn-print" onclick="window.print()">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
        </svg>
        Print / Save PDF
    </button>
    <a href="monthly_payments.php?account_id=<?= $accountId ?>" class="btn-back">← Back to Account</a>
</div>
</body>
</html>
