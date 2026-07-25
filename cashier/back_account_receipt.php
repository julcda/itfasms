<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/back_account_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only Cashier or Super Admin users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$id  = to_int($_GET['id'] ?? 0);
$pay = $id > 0 && ba_schema_ready($connection) ? ba_payment_get($connection, $id) : null;
if (!$pay) {
    flash_set('error', 'Receipt not found.');
    redirect_to(app_url('cashier/back_accounts.php'));
}

$amount      = (float) $pay['amount'];
$amountWords = peso_in_words($amount);
$when        = date('F j, Y g:i A', strtotime((string) $pay['paid_at']));
$isVoid      = $pay['status'] === 'Voided';
$cashierSig  = soa_setting($connection, 'SOA_CASHIER_SIGNATORY', (string) ($pay['cashier_name'] ?: 'Cashier'));
$sigImg      = is_file(dirname(__DIR__) . '/BAJUNAID GARAY.png') ? app_url('BAJUNAID%20GARAY.png') : '';
$remaining   = (float) $pay['current_balance'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt — <?= h((string) $pay['or_number']) ?></title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A5 portrait; margin: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #111; margin: 0; padding: 16px; background: #eef1f5; }
        .slip { width: 148mm; min-height: 200mm; margin: 0 auto; background: #fff; padding: 12mm 12mm; box-shadow: 0 10px 40px rgba(0,0,0,.12); position: relative; display: flex; flex-direction: column; }
        .head { text-align: center; border-bottom: 2px solid #166534; padding-bottom: 8px; position: relative; }
        .head img { width: 46px; height: 46px; object-fit: contain; position: absolute; left: 0; top: 0; }
        .head h1 { font-size: 15px; color: #166534; font-weight: bold; }
        .head .sub { font-size: 9px; color: #555; }
        .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 3px; margin: 12px 0 2px; }
        .subtitle { text-align: center; font-size: 9.5px; color: #b91c1c; font-weight: bold; letter-spacing: 1px; margin-bottom: 4px; }
        .orline { display: flex; justify-content: space-between; font-size: 11px; margin: 8px 0; }
        .orline .or { font-weight: bold; color: #166534; font-family: 'Consolas', monospace; }
        .meta { font-size: 11.5px; line-height: 1.7; margin: 6px 0 10px; }
        .meta .l { color: #64748b; display: inline-block; min-width: 76px; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        th, td { padding: 5px 6px; font-size: 11px; }
        thead th { background: #166534; color: #fff; text-align: left; }
        thead th.r, tbody td.r { text-align: right; }
        tbody td { border-bottom: 1px solid #e5e7eb; }
        tfoot td { font-weight: 800; font-size: 13px; padding-top: 8px; }
        .words { font-style: italic; font-size: 10.5px; color: #444; margin-top: 4px; }
        .bal { margin-top: 10px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 8px; font-size: 11px; display: flex; justify-content: space-between; }
        .bal.settled { border-color: #059669; color: #065f46; background: #ecfdf5; }
        .bal.due { border-color: #b91c1c; color: #991b1b; background: #fef2f2; }
        .sign { margin-top: auto; padding-top: 30px; text-align: right; }
        .sign img { height: 26px; object-fit: contain; display:block; margin: 0 0 -2px auto; }
        .sign .ln { border-top: 1px solid #111; display: inline-block; padding-top: 3px; font-weight: bold; font-size: 12px; min-width: 180px; text-align:center; }
        .sign .role { font-size: 10px; color: #555; text-align:center; }
        .foot { margin-top: 14px; text-align: center; font-size: 8.5px; color: #94a3b8; border-top: 1px solid #eee; padding-top: 6px; }
        .void { position: absolute; top: 44%; left: 50%; transform: translate(-50%,-50%) rotate(-20deg); font-size: 72px; font-weight: bold; color: rgba(220,38,38,.16); letter-spacing: 6px; }
        .toolbar { width: 148mm; margin: 0 auto 10px; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 15px; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 12px; }
        .toolbar button { background: #166534; color: #fff; border: 0; } .toolbar a { background: #fff; border: 1px solid #cbd5e1; color: #334155; margin-left: 6px; }
        @media print { body { background: #fff; padding: 0; } .slip { box-shadow: none; } .toolbar { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="<?= h(app_url('cashier/back_accounts.php?view=' . (int) $pay['back_account_id'])) ?>">Back</a>
    </div>

    <div class="slip">
        <?php if ($isVoid): ?><div class="void">VOID</div><?php endif; ?>
        <div class="head">
            <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA">
            <h1>IBN TAIMIYAH FOUNDATION ACADEMY, INC.</h1>
            <div class="sub">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte · Office of the Cashier</div>
        </div>

        <div class="title">OFFICIAL RECEIPT</div>
        <div class="subtitle">BACK ACCOUNT PAYMENT</div>
        <div class="orline">
            <span>No. <span class="or"><?= h((string) $pay['or_number']) ?></span></span>
            <span><?= h(date('M j, Y', strtotime((string) $pay['paid_at']))) ?></span>
        </div>

        <div class="meta">
            <div><span class="l">Received from:</span> <strong><?= h((string) $pay['student_name']) ?></strong></div>
            <div><span class="l">LRN:</span> <?= h((string) ($pay['lrn'] ?: '—')) ?></div>
            <div><span class="l">Payment:</span> <?= h((string) $pay['payment_method']) ?><?php if ($pay['reference_no']): ?> · Ref <?= h((string) $pay['reference_no']) ?><?php endif; ?></div>
        </div>

        <table>
            <thead><tr><th>Particular</th><th class="r">Amount</th></tr></thead>
            <tbody>
                <tr>
                    <td>Back Account — S.Y. <?= h((string) $pay['debt_sy']) ?></td>
                    <td class="r">₱<?= number_format($amount, 2) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr><td class="r">TOTAL</td><td class="r">₱<?= number_format($amount, 2) ?></td></tr>
            </tfoot>
        </table>
        <div class="words"><?= h($amountWords) ?></div>

        <?php if (!$isVoid): ?>
        <div class="bal <?= $remaining <= 0.009 ? 'settled' : 'due' ?>">
            <span><strong><?= $remaining <= 0.009 ? 'FULLY SETTLED' : 'Remaining back-account balance' ?></strong></span>
            <span><strong>₱<?= number_format(max($remaining, 0), 2) ?></strong></span>
        </div>
        <?php endif; ?>

        <div class="sign">
            <?php if ($sigImg && !$isVoid): ?><img src="<?= h($sigImg) ?>" alt=""><?php endif; ?>
            <div class="ln"><?= h(strtoupper((string) $cashierSig)) ?></div>
            <div class="role">Cashier</div>
        </div>

        <div class="foot">This is a system-generated Official Receipt · <?= h($when) ?><?= $isVoid ? ' · VOIDED' : '' ?></div>
    </div>
</body>
</html>
