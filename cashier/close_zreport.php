<?php
/**
 * Z-Report print partial. Expects in scope: $col (collection_summary row),
 * $pays (array of payment rows), $syLabel. Included by close.php print mode.
 */
declare(strict_types=1);

$posted   = array_filter($pays, static fn($p) => $p['status'] === 'Posted');
$voided   = array_filter($pays, static fn($p) => $p['status'] !== 'Posted');
$byMethod = [];
foreach ($posted as $p) {
    $m = (string) $p['method'];
    $byMethod[$m] = ($byMethod[$m] ?? 0) + (float) $p['amount'];
}
$expCash  = (float) $col['total_cash'];
$declared = $col['declared_cash'] !== null ? (float) $col['declared_cash'] : null;
$variance = $col['variance'] !== null ? (float) $col['variance'] : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Z-Report · <?= h((string) $col['business_date']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; color: #111; margin: 0; padding: 24px; background: #f1f5f9; }
        .slip { width: 320px; margin: 0 auto; background: #fff; padding: 20px; border: 1px solid #cbd5e1; }
        h1 { font-size: 15px; text-align: center; margin: 0; letter-spacing: 1px; }
        .sub { text-align: center; font-size: 11px; color: #444; margin: 2px 0 10px; }
        .rule { border-top: 1px dashed #888; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; font-size: 12px; margin: 3px 0; }
        .row .l { color: #333; }
        .row .r { font-weight: bold; }
        .big { font-size: 14px; }
        .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #666; margin: 10px 0 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        td { padding: 2px 0; vertical-align: top; }
        td.amt { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .var-ok { color: #047857; } .var-over { color: #0f4d28; } .var-short { color: #be123c; }
        .foot { font-size: 10px; text-align: center; color: #666; margin-top: 14px; }
        @media print { body { background: #fff; padding: 0; } .slip { border: 0; } .noprint { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="noprint" style="width:320px;margin:0 auto 12px;text-align:center;">
        <button onclick="window.print()" style="padding:8px 18px;font:inherit;cursor:pointer;">🖨 Print</button>
        <a href="close.php" style="margin-left:8px;font-size:12px;">Back</a>
    </div>

    <div class="slip">
        <h1>ITFA CASHIER</h1>
        <div class="sub">END-OF-DAY Z-REPORT<br>S.Y. <?= h($syLabel) ?></div>

        <div class="row"><span class="l">Business date</span><span class="r"><?= h(date('M j, Y', strtotime((string) $col['business_date']))) ?></span></div>
        <div class="row"><span class="l">Cashier</span><span class="r"><?= h((string) $col['cashier_name']) ?></span></div>
        <div class="row"><span class="l">Status</span><span class="r"><?= h((string) $col['status']) ?></span></div>
        <?php if ($col['closed_at']): ?>
        <div class="row"><span class="l">Closed</span><span class="r"><?= h((string) $col['closed_at']) ?></span></div>
        <?php endif; ?>

        <div class="rule"></div>
        <div class="lbl">Collection summary</div>
        <div class="row"><span class="l">Transactions</span><span class="r"><?= count($posted) ?></span></div>
        <?php foreach ($byMethod as $m => $amt): ?>
        <div class="row"><span class="l"><?= h($m) ?></span><span class="r">₱<?= number_format((float) $amt, 2) ?></span></div>
        <?php endforeach; ?>
        <div class="rule"></div>
        <div class="row big"><span class="l">TOTAL COLLECTED</span><span class="r">₱<?= number_format((float) $col['total_collected'], 2) ?></span></div>

        <div class="rule"></div>
        <div class="lbl">Cash reconciliation</div>
        <div class="row"><span class="l">Expected cash</span><span class="r">₱<?= number_format($expCash, 2) ?></span></div>
        <div class="row"><span class="l">Counted cash</span><span class="r"><?= $declared === null ? '—' : '₱' . number_format($declared, 2) ?></span></div>
        <div class="row big">
            <span class="l">OVER / SHORT</span>
            <span class="r <?= $variance === null ? '' : ($variance == 0.0 ? 'var-ok' : ($variance > 0 ? 'var-over' : 'var-short')) ?>">
                <?= $variance === null ? '—' : ($variance == 0.0 ? '₱0.00' : ($variance > 0 ? '+₱' . number_format($variance, 2) : '-₱' . number_format(abs($variance), 2))) ?>
            </span>
        </div>

        <?php if ($posted): ?>
        <div class="rule"></div>
        <div class="lbl">Detail</div>
        <table>
            <?php foreach ($posted as $p): ?>
            <tr>
                <td><?= h((string) ($p['or_number'] ?? '—')) ?><br><span style="color:#777;"><?= h((string) ($p['full_name'] ?? '')) ?></span></td>
                <td class="amt">₱<?= number_format((float) $p['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <?php if ($voided): ?>
        <div class="rule"></div>
        <div class="lbl">Voided / refunded (excluded)</div>
        <table>
            <?php foreach ($voided as $p): ?>
            <tr>
                <td style="color:#999;"><?= h((string) ($p['or_number'] ?? '—')) ?> · <?= h((string) $p['status']) ?></td>
                <td class="amt" style="color:#999;">₱<?= number_format((float) $p['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <?php if ($col['notes']): ?>
        <div class="rule"></div>
        <div class="lbl">Notes</div>
        <div style="font-size:11px;"><?= h((string) $col['notes']) ?></div>
        <?php endif; ?>

        <div class="rule"></div>
        <div class="row" style="margin-top:24px;"><span class="l">Cashier sign</span><span class="r">_______________</span></div>
        <div class="row" style="margin-top:16px;"><span class="l">Verified by</span><span class="r">_______________</span></div>

        <div class="foot">Generated <?= h(date('M j, Y g:i A')) ?><br>This is a system-generated Z-Report.</div>
    </div>
</body>
</html>
