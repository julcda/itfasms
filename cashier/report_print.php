<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/collection_report.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only Cashier or Super Admin users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to   = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$type = (string) ($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'tuition', 'other'], true)) { $type = 'all'; }

$rows  = collection_report_rows($connection, $from, $to, $type);
$total = collection_report_total($rows);
$words = peso_in_words($total);
$typeLabel = ['all' => 'All Collections', 'tuition' => 'Tuition & School Fees', 'other' => 'Other Payments'][$type];
$cashierName = (string) ($user['full_name'] ?? 'Cashier');
$cashierSig  = soa_setting($connection, 'SOA_CASHIER_SIGNATORY', $cashierName);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Collection Report — <?= h($from) ?> to <?= h($to) ?></title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4 portrait; margin: 12mm 10mm; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #111; margin: 0; background: #f1f5f9; }
        .sheet { width: 190mm; margin: 0 auto; background: #fff; padding: 8mm 9mm; }
        .head { text-align: center; border-bottom: 2px solid #166534; padding-bottom: 8px; position: relative; }
        .head img { width: 52px; height: 52px; object-fit: contain; position: absolute; left: 0; top: 0; }
        .head h1 { font-size: 16px; color: #166534; font-weight: bold; }
        .head .sub { font-size: 9.5px; color: #555; }
        .title { text-align: center; font-size: 14px; font-weight: bold; letter-spacing: 2px; margin: 12px 0 2px; }
        .range { text-align: center; font-size: 11px; color: #444; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3.5px 5px; font-size: 10px; border: 0.5px solid #cbd5e1; }
        thead th { background: #166534; color: #fff; text-align: left; font-size: 9px; text-transform: uppercase; }
        th.r, td.r { text-align: right; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        /* Total lives in <tbody> (not <tfoot>) so it prints once on the last page
           instead of repeating at the foot of every page. */
        tbody tr.total-row td { font-weight: 800; font-size: 12px; background: #f0f7f2; color: #166534; }
        tbody tr.total-row td { page-break-inside: avoid; }
        .words { font-style: italic; font-size: 10px; margin-top: 6px; }
        .sign { margin-top: 34px; display: flex; justify-content: flex-end; }
        .sign .by { text-align: center; min-width: 220px; }
        .sign img { height: 26px; object-fit: contain; display: block; margin: 0 auto -2px; }
        .sign .ln { border-top: 1px solid #111; padding-top: 3px; font-weight: bold; font-size: 12px; }
        .sign .role { font-size: 10px; color: #555; }
        .foot { margin-top: 16px; font-size: 8.5px; color: #94a3b8; text-align: center; }
        .toolbar { width: 190mm; margin: 8px auto; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 15px; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 12px; }
        .toolbar button { background: #166534; color: #fff; border: 0; } .toolbar a { background: #fff; border: 1px solid #cbd5e1; color: #334155; margin-left: 6px; }
        @media print { body { background: #fff; } .sheet { width: auto; padding: 0; } .toolbar { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="<?= h(app_url('cashier/report.php?from='.urlencode($from).'&to='.urlencode($to).'&type='.urlencode($type))) ?>">Back</a>
    </div>

    <div class="sheet">
        <div class="head">
            <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA">
            <h1>IBN TAIMIYAH FOUNDATION ACADEMY, INC.</h1>
            <div class="sub">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte · Office of the Cashier</div>
        </div>

        <div class="title">COLLECTION REPORT</div>
        <div class="range"><?= h($typeLabel) ?> &nbsp;·&nbsp; <?= h(date('F j, Y', strtotime($from))) ?> to <?= h(date('F j, Y', strtotime($to))) ?></div>

        <table>
            <thead>
                <tr>
                    <th class="r" style="width:6%;">No.</th>
                    <th style="width:13%;">Date</th>
                    <th style="width:24%;">Received From</th>
                    <th style="width:24%;">Payment Particular</th>
                    <th style="width:17%;">Receipt No.</th>
                    <th class="r" style="width:16%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:16px;">No collections in this range.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td class="r"><?= $i + 1 ?></td>
                    <td><?= h(date('m/d/Y', strtotime($r['dt']))) ?></td>
                    <td><?= h($r['name']) ?></td>
                    <td><?= h($r['particular']) ?></td>
                    <td><?= h($r['or_number']) ?></td>
                    <td class="r">₱<?= number_format($r['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row"><td colspan="5" class="r">TOTAL</td><td class="r">₱<?= number_format($total, 2) ?></td></tr>
            </tbody>
        </table>
        <div class="words">Total amount: <?= h($words) ?></div>

        <div class="sign">
            <div class="by">
                <?php if (is_file(dirname(__DIR__) . '/BAJUNAID GARAY.png')): ?><img src="<?= h(app_url('BAJUNAID%20GARAY.png')) ?>" alt=""><?php endif; ?>
                <div class="ln"><?= h(strtoupper((string) $cashierSig)) ?></div>
                <div class="role">Cashier</div>
            </div>
        </div>

        <div class="foot">Generated <?= h(date('M j, Y g:i A')) ?> by <?= h($cashierName) ?> · <?= number_format(count($rows)) ?> receipt(s)</div>
    </div>
</body>
</html>
