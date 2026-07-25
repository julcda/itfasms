<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/soa_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only Cashier or Super Admin users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$id = to_int($_GET['id'] ?? 0);
$dep = null;
if ($id > 0 && soa_table_exists($connection, 'bank_deposits')) {
    $s = $connection->prepare(
        'SELECT bd.*, sy.School_year AS sy_label
         FROM bank_deposits bd
         LEFT JOIN schoolyear sy ON sy.School_year_id = bd.school_year_id
         WHERE bd.id = ? LIMIT 1'
    );
    $s->bind_param('i', $id);
    $s->execute();
    $dep = stmt_fetch_assoc($s);
}

if (!$dep) {
    flash_set('error', 'Deposit not found.');
    redirect_to(app_url('cashier/deposits.php'));
}

// peso_in_words() is provided by includes/functions.php (shared).

$amount     = (float) $dep['amount'];
$amountWords = peso_in_words($amount);
$depDate    = date('F j, Y', strtotime((string) $dep['deposit_date']));
$isVoid     = $dep['status'] === 'Void';
$cashierSig = soa_setting($connection, 'DEPOSIT_CASHIER_SIGNATORY', 'BAJUNAID GARAY');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Deposit Certification — <?= h((string) $dep['deposit_no']) ?></title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4; margin: 0; }
        body { font-family: 'Times New Roman', Georgia, serif; color: #111; margin: 0; padding: 20px; background: #eef1f5; }
        /* One A4 page: 210mm x 297mm, with internal padding. overflow hidden keeps it to a single sheet. */
        .sheet { width: 210mm; height: 297mm; margin: 0 auto; background: #fff; padding: 16mm 20mm; box-shadow: 0 10px 40px rgba(0,0,0,.12); position: relative; overflow: hidden; display: flex; flex-direction: column; }
        .head { text-align: center; border-bottom: 3px double #166534; padding-bottom: 12px; position: relative; }
        .head img { width: 70px; height: 70px; object-fit: contain; position: absolute; left: 0; top: 0; }
        .head h1 { margin: 0; font-size: 21px; letter-spacing: .5px; color: #166534; font-weight: bold; }
        .head .sub { font-size: 12.5px; color: #444; margin-top: 3px; }
        .head .sub2 { font-size: 11px; color: #666; }
        .title { text-align: center; font-size: 19px; font-weight: bold; letter-spacing: 4px; margin: 30px 0 5px; text-decoration: underline; }
        .docno { text-align: center; font-size: 12px; color: #555; margin-bottom: 26px; }
        .body { font-size: 15px; line-height: 2.0; text-align: justify; }
        .body p { margin: 0 0 12px; }
        .amt-box { margin: 22px 0; text-align: center; }
        .amt-words { font-style: italic; font-weight: bold; font-size: 15.5px; border-bottom: 1px solid #333; display: inline-block; padding: 0 18px 2px; }
        .amt-fig { font-size: 26px; font-weight: bold; color: #166534; margin-top: 8px; letter-spacing: .5px; }
        .sign { margin-top: 56px; }
        .sign .by { display: inline-block; text-align: center; min-width: 290px; }
        .sign .line { border-top: 1.4px solid #111; padding-top: 5px; font-weight: bold; font-size: 16px; }
        .sign .role { font-size: 12.5px; color: #444; }
        .foot { margin-top: auto; display: flex; justify-content: space-between;
                font-size: 10.5px; color: #888; border-top: 1px solid #ddd; padding-top: 8px; }
        .void-stamp { position: absolute; top: 45%; left: 50%; transform: translate(-50%, -50%) rotate(-22deg);
                      font-size: 110px; font-weight: bold; color: rgba(220,38,38,.16); letter-spacing: 8px; pointer-events: none; }
        .toolbar { width: 210mm; margin: 0 auto 12px; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 9px 18px; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 13px; }
        .toolbar button { background: #166534; color: #fff; border: 0; }
        .toolbar a { background: #fff; border: 1px solid #cbd5e1; color: #334155; margin-left: 6px; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; margin: 0; page-break-after: avoid; } .toolbar { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="<?= h(app_url('cashier/deposits.php')) ?>">Back</a>
    </div>

    <div class="sheet">
        <?php if ($isVoid): ?><div class="void-stamp">VOID</div><?php endif; ?>

        <div class="head">
            <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA">
            <h1>IBN TAIMIYAH FOUNDATION ACADEMY, INC.</h1>
            <div class="sub">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>
            <div class="sub2">Office of the Cashier</div>
        </div>

        <div class="title">DEPOSIT CERTIFICATION</div>
        <div class="docno">No. <?= h((string) $dep['deposit_no']) ?> &nbsp;·&nbsp; S.Y. <?= h((string) ($dep['sy_label'] ?? '')) ?></div>

        <div class="body">
            <p>TO WHOM IT MAY CONCERN:</p>

            <p>This is to certify that the amount of</p>

            <div class="amt-box">
                <div class="amt-words"><?= h($amountWords) ?></div>
                <div class="amt-fig">₱<?= number_format($amount, 2) ?></div>
            </div>

            <p>representing collections from school fees, was deposited to
                <b><?= h((string) ($dep['bank_name'] ?: '___________________')) ?></b><?php if ($dep['bank_account']): ?>
                (Account No. <?= h((string) $dep['bank_account']) ?>)<?php endif; ?>
                on <b><?= h($depDate) ?></b><?php if ($dep['reference_no']): ?>
                under Deposit Slip / Reference No. <b><?= h((string) $dep['reference_no']) ?></b><?php endif; ?>.</p>

            <?php if ($dep['period_from'] || $dep['period_to']): ?>
            <p>The said amount covers collections for the period
                <b><?= h($dep['period_from'] ? date('M j, Y', strtotime((string) $dep['period_from'])) : '—') ?></b>
                to <b><?= h($dep['period_to'] ? date('M j, Y', strtotime((string) $dep['period_to'])) : '—') ?></b>.</p>
            <?php endif; ?>

            <?php if ($dep['notes']): ?>
            <p><i><?= h((string) $dep['notes']) ?></i></p>
            <?php endif; ?>

            <p>Issued this <?= h(date('jS \d\a\y \o\f F, Y', strtotime((string) $dep['created_at']))) ?> at IBN Taimiyah Foundation Academy, Inc.</p>
        </div>

        <div class="sign">
            <div class="by">
                <div class="line"><?= h(strtoupper((string) $cashierSig)) ?></div>
                <div class="role">Cashier</div>
            </div>
        </div>

        <div class="foot">
            <span>Deposit No.: <?= h((string) $dep['deposit_no']) ?></span>
            <span>This is a system-generated certification.</span>
        </div>
    </div>
</body>
</html>
