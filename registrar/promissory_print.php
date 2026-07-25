<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/promissory_service.php';

require_login();

$connection = db();
$user       = current_user();

// Registrar / Super Admin issue & reprint; Cashier may reprint when presented.
if (!is_registrar_user($user) && !is_super_admin($user) && !is_cashier_user($user)) {
    flash_set('error', 'You do not have access to Promissory Notes.');
    redirect_to(app_url('dashboard/index.php'));
}

$id = to_int($_GET['id'] ?? 0);
$pn = $id > 0 && pn_table_ready($connection) ? pn_get($connection, $id) : null;
if (!$pn) {
    flash_set('error', 'Promissory note not found.');
    redirect_to(app_url('registrar/promissory.php'));
}

$amount      = (float) $pn['promissory_amount'];
$amountWords = peso_in_words($amount);
$issued      = date('F j, Y', strtotime((string) $pn['date_issued']));
$promised    = date('F j, Y', strtotime((string) $pn['promised_payment_date']));
$status      = (string) $pn['status'];
$registrar   = (string) ($pn['created_by'] ?: $user['full_name'] ?? 'Registrar');
$fullName    = strtoupper(trim((string) $pn['full_name']));
$isClosed    = in_array($status, ['Paid', 'Cancelled'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Promissory Note — <?= h((string) $pn['promissory_no']) ?></title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4; margin: 0; }
        body { font-family: 'Times New Roman', Georgia, serif; color: #111; margin: 0; padding: 20px; background: #eef1f5; }
        .sheet { width: 210mm; height: 297mm; margin: 0 auto; background: #fff; padding: 16mm 20mm; box-shadow: 0 10px 40px rgba(0,0,0,.12); position: relative; overflow: hidden; display: flex; flex-direction: column; }
        .head { text-align: center; border-bottom: 3px double #166534; padding-bottom: 12px; position: relative; }
        .head img { width: 70px; height: 70px; object-fit: contain; position: absolute; left: 0; top: 0; }
        .head h1 { margin: 0; font-size: 21px; letter-spacing: .5px; color: #166534; font-weight: bold; }
        .head .sub { font-size: 12.5px; color: #444; margin-top: 3px; }
        .head .sub2 { font-size: 11px; color: #666; }
        .title { text-align: center; font-size: 19px; font-weight: bold; letter-spacing: 4px; margin: 28px 0 4px; text-decoration: underline; }
        .docno { text-align: center; font-size: 12px; color: #555; margin-bottom: 22px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 24px; font-size: 13px; margin-bottom: 18px; }
        .meta div { display: flex; justify-content: space-between; border-bottom: 1px dotted #cbd5e1; padding: 3px 0; }
        .meta .l { color: #64748b; } .meta .v { font-weight: 600; }
        .body { font-size: 14.5px; line-height: 2.0; text-align: justify; }
        .body p { margin: 0 0 12px; }
        .amt-box { margin: 18px 0; text-align: center; }
        .amt-words { font-style: italic; font-weight: bold; font-size: 15px; border-bottom: 1px solid #333; display: inline-block; padding: 0 18px 2px; }
        .amt-fig { font-size: 24px; font-weight: bold; color: #166534; margin-top: 6px; }
        .status-line { text-align: center; margin: 6px 0 4px; }
        .status { display: inline-block; padding: 3px 16px; border-radius: 999px; font-weight: bold; font-size: 12px; }
        .s-Pending { background: #fef3c7; color: #92400e; } .s-Paid { background: #d1fae5; color: #065f46; }
        .s-Overdue { background: #fee2e2; color: #991b1b; } .s-Cancelled { background: #e2e8f0; color: #475569; }
        .signs { display: flex; justify-content: space-between; gap: 40px; margin-top: 64px; }
        .sg { flex: 1; text-align: center; }
        .sg .line { border-top: 1.3px solid #111; padding-top: 5px; font-weight: bold; font-size: 14px; }
        .sg .role { font-size: 11.5px; color: #444; }
        .foot { margin-top: auto; display: flex; justify-content: space-between; font-size: 10.5px; color: #888; border-top: 1px solid #ddd; padding-top: 8px; }
        .watermark { position: absolute; top: 46%; left: 50%; transform: translate(-50%,-50%) rotate(-22deg); font-size: 100px; font-weight: bold; letter-spacing: 8px; pointer-events: none; }
        .wm-Paid { color: rgba(5,150,105,.14); } .wm-Cancelled { color: rgba(100,116,139,.16); }
        .toolbar { width: 210mm; margin: 0 auto 12px; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 9px 18px; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 13px; }
        .toolbar button { background: #166534; color: #fff; border: 0; } .toolbar a { background: #fff; border: 1px solid #cbd5e1; color: #334155; margin-left: 6px; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; margin: 0; } .toolbar { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="<?= h(app_url('registrar/promissory.php')) ?>">Back</a>
    </div>

    <div class="sheet">
        <?php if ($isClosed): ?><div class="watermark wm-<?= h($status) ?>"><?= h(strtoupper($status)) ?></div><?php endif; ?>

        <div class="head">
            <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA">
            <h1>IBN TAIMIYAH FOUNDATION ACADEMY, INC.</h1>
            <div class="sub">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>
            <div class="sub2">Office of the Registrar</div>
        </div>

        <div class="title">PROMISSORY NOTE</div>
        <div class="docno">No. <?= h((string) $pn['promissory_no']) ?> &nbsp;·&nbsp; S.Y. <?= h((string) ($pn['school_year'] ?? '')) ?></div>

        <div class="meta">
            <div><span class="l">Student Name</span><span class="v"><?= h($fullName) ?></span></div>
            <div><span class="l">Student ID / LRN</span><span class="v"><?= h((string) ($pn['lrn'] ?: $pn['student_id'])) ?></span></div>
            <div><span class="l">Grade &amp; Section</span><span class="v"><?= h((string) $pn['grade_name']) ?> · <?= h((string) $pn['section_name']) ?></span></div>
            <div><span class="l">Department</span><span class="v"><?= h((string) $pn['Department']) ?></span></div>
            <div><span class="l">SOA Reference</span><span class="v"><?= h((string) ($pn['soa_number'] ?: '—')) ?></span></div>
            <div><span class="l">SOA Amount Due</span><span class="v">₱<?= number_format((float) $pn['outstanding_balance'], 2) ?></span></div>
            <div><span class="l">Date Issued</span><span class="v"><?= h($issued) ?></span></div>
        </div>

        <div class="body">
            <p>I, the undersigned student / parent / guardian, hereby acknowledge an outstanding obligation to
                <b>IBN Taimiyah Foundation Academy, Inc.</b> and promise to pay the amount of</p>

            <div class="amt-box">
                <div class="amt-words"><?= h($amountWords) ?></div>
                <div class="amt-fig">₱<?= number_format($amount, 2) ?></div>
            </div>

            <p>on or before <b><?= h($promised) ?></b><?php if (!empty($pn['reason'])): ?>, for the following reason:
                <i><?= h((string) $pn['reason']) ?></i><?php endif; ?>.</p>

            <p>I understand that failure to settle this amount on the promised date will render this note
                <b>overdue</b>, and the said balance shall be carried over and reflected in my next Statement of Account.</p>
        </div>

        <div class="status-line">Status: <span class="status s-<?= h($status) ?>"><?= h($status) ?></span>
            <?php if ((int) $pn['cashier_verified'] === 1): ?>&nbsp; · &nbsp;<span style="color:#047857;font-weight:bold;">✓ Cashier Verified</span><?php endif; ?>
        </div>

        <div class="signs">
            <div class="sg"><div class="line">_______________________</div><div class="role">Student / Parent / Guardian Signature</div></div>
            <div class="sg"><div class="line"><?= h(strtoupper($registrar)) ?></div><div class="role">Registrar</div></div>
        </div>

        <div class="foot">
            <span>Generated: <?= h(date('M j, Y g:i A')) ?></span>
            <span>ITFA Promissory Note · <?= h((string) $pn['promissory_no']) ?></span>
        </div>
    </div>
</body>
</html>
