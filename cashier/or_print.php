<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user)) {
    flash_set('error', 'Only Cashier users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$paymentId   = to_int($_GET['payment_id'] ?? 0);
$reprintId   = to_int($_GET['reprint'] ?? 0);
$isDuplicate = $reprintId > 0;
if ($isDuplicate) {
    $paymentId = $reprintId;
}
if ($paymentId <= 0) {
    flash_set('error', 'No receipt specified.');
    redirect_to(app_url('cashier/collect.php'));
}

// Increment reprint counter for duplicates
if ($isDuplicate) {
    $up = $connection->prepare('UPDATE receipt_master SET reprint_count = reprint_count + 1 WHERE payment_id = ?');
    $up->bind_param('i', $paymentId);
    $up->execute();
}

$stmt = $connection->prepare(
    "SELECT pt.id AS payment_id, pt.method, pt.reference_no, pt.amount, pt.tendered, pt.change_amount,
            pt.paid_at, pt.status,
            rm.or_number, rm.reprint_count, rm.issued_by,
            sa.student_id, sa.net_assessed, sa.total_paid, sa.balance,
            en.Department, en.school_year,
            COALESCE(
                CONCAT(p.surname, ', ', p.firstname, ' ', IFNULL(p.middlename, '')),
                CONCAT(osp.surname, ', ', osp.firstname, ' ', IFNULL(osp.middlename, ''))
            ) AS full_name,
            COALESCE(p.lrn, osp.lrn) AS lrn,
            IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
            IFNULL(sc.Section_name, en.Department_section) AS section_name
     FROM payment_transaction pt
     JOIN receipt_master rm     ON rm.payment_id = pt.id
     JOIN student_assessment sa ON sa.id = pt.assessment_id
     JOIN enrollment en         ON en.id = sa.enrollment_id
     LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
     LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
     LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
     LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
     WHERE pt.id = ? LIMIT 1"
);
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$r = stmt_fetch_assoc($stmt);
if (!$r) {
    flash_set('error', 'Receipt not found.');
    redirect_to(app_url('cashier/collect.php'));
}

// Receipt detail lines
$dStmt = $connection->prepare('SELECT category, description, amount FROM receipt_details WHERE receipt_id = (SELECT id FROM receipt_master WHERE payment_id = ?) ORDER BY id');
$dStmt->bind_param('i', $paymentId);
$dStmt->execute();
$details = stmt_fetch_all_assoc($dStmt);

// Allocated installment terms
$tStmt = $connection->prepare(
    'SELECT ps.term_no, ps.month_label, pi.amount
     FROM payment_installments pi JOIN payment_schedule ps ON ps.id = pi.schedule_id
     WHERE pi.payment_id = ? ORDER BY ps.term_no'
);
$tStmt->bind_param('i', $paymentId);
$tStmt->execute();
$terms = stmt_fetch_all_assoc($tStmt);

$orNumber   = (string) $r['or_number'];
$name       = strtoupper(trim((string) ($r['full_name'] ?? 'Student')));
$amount     = (float) $r['tendered'];
$ts         = strtotime((string) $r['paid_at']) ?: time();
$logoUrl    = h(app_url('itfalogo.png'));

function or_amount_in_words(float $amount): string
{
    $ones = ['', 'One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen',
             'Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['', '', 'Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $int  = (int) floor($amount);
    $cent = (int) round(($amount - $int) * 100);
    $w = static function (int $n) use (&$w, $ones, $tens): string {
        if ($n === 0) return 'Zero';
        $s = '';
        if ($n >= 1000) { $s .= $w((int)($n/1000)) . ' Thousand '; $n %= 1000; }
        if ($n >= 100)  { $s .= $ones[(int)($n/100)] . ' Hundred '; $n %= 100; }
        if ($n >= 20)   { $s .= $tens[(int)($n/10)] . ($n%10 ? ' ' . $ones[$n%10] : '') . ' '; }
        elseif ($n > 0) { $s .= $ones[$n] . ' '; }
        return trim($s);
    };
    $res = $w($int);
    if ($cent > 0) $res .= ' and ' . $w($cent) . '/100';
    return $res;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Official Receipt — <?= h($orNumber) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Inter',sans-serif;background:#8e9aab;display:flex;flex-direction:column;align-items:center;padding:28px 16px 56px;min-height:100vh;}
        .toolbar{display:flex;gap:10px;width:100%;max-width:794px;margin-bottom:18px;}
        .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;border:none;}
        .btn-back{background:#fff;color:#334155;border:1px solid #cbd5e1;}
        .btn-print{background:#1b3f7a;color:#fff;}
        .dup-badge{margin-left:auto;background:#fef2f2;border:1.5px solid #fca5a5;color:#b91c1c;font-size:9px;font-weight:800;padding:4px 12px;border-radius:20px;letter-spacing:.1em;text-transform:uppercase;}
        .rcpt{background:#fff;width:100%;max-width:794px;border-radius:12px;box-shadow:0 24px 64px -16px rgba(0,0,0,.35);overflow:hidden;position:relative;}
        .dup-stamp{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:72px;font-weight:900;color:rgba(0,0,0,.06);pointer-events:none;}
        .accent{height:4px;background:#1b3f7a;}
        .inner{padding:14px 18px 16px;}
        .hdr{display:flex;align-items:center;gap:10px;border-bottom:2px solid #000;padding-bottom:9px;}
        .logo{width:48px;height:48px;object-fit:contain;}
        .hdr-mid{flex:1;text-align:center;}
        .hdr-tag{font-size:7px;letter-spacing:.3em;text-transform:uppercase;color:#444;font-weight:700;}
        .hdr-school{font-family:'Playfair Display',serif;font-size:12px;font-weight:800;margin-top:2px;}
        .hdr-addr{font-size:7px;color:#555;margin-top:1px;}
        .hdr-sy{font-size:7.5px;font-weight:700;margin-top:2px;}
        .or-pill{border:1.5px solid #000;border-radius:6px;padding:7px 10px;text-align:center;min-width:96px;}
        .or-lbl{font-size:7px;letter-spacing:.2em;text-transform:uppercase;color:#444;font-weight:700;}
        .or-num{font-size:9.5px;font-weight:800;margin-top:2px;word-break:break-all;}
        .or-dt{font-size:6.5px;color:#555;margin-top:3px;line-height:1.5;}
        .sec{font-size:7px;text-transform:uppercase;letter-spacing:.24em;color:#555;font-weight:700;margin:9px 0 4px;}
        .tbl{width:100%;border-collapse:collapse;}
        .tbl td{font-size:9px;padding:2px 0;line-height:1.4;}
        .tbl td:first-child{color:#555;width:42%;}
        .tbl td:last-child{font-weight:600;text-align:right;}
        .fees{width:100%;border-collapse:collapse;}
        .fees td{font-size:9px;padding:2px 0;}
        .fees td:last-child{text-align:right;font-weight:600;white-space:nowrap;}
        .fees tr+tr td{border-top:1px solid #ececec;}
        .fees tr.total td{border-top:2px solid #000;padding-top:4px;font-weight:800;font-size:10px;}
        .sep{border:none;border-top:1px solid #ccc;margin:8px 0;}
        .amt-block{display:flex;gap:10px;background:#f5f5f5;border:1.5px solid #000;border-radius:6px;padding:8px 12px;margin:8px 0 6px;}
        .amt-lbl{font-size:7px;text-transform:uppercase;letter-spacing:.2em;color:#444;font-weight:700;}
        .amt-fig{font-size:24px;font-weight:800;}
        .amt-words{font-size:7.5px;color:#555;font-style:italic;margin-top:2px;}
        .paid-badge{display:inline-flex;align-items:center;gap:5px;background:#000;color:#fff;font-size:8px;font-weight:700;padding:3px 9px;border-radius:20px;margin-top:4px;}
        .pay-meta{display:flex;justify-content:space-between;font-size:8px;color:#555;margin-bottom:6px;}
        .pay-meta strong{color:#000;}
        .bc{text-align:center;margin:4px 0 2px;}
        .sig{text-align:center;}
        .sig-line{border-top:1px solid #333;padding-top:4px;margin:20px auto 0;width:170px;}
        .sig-name{font-size:10px;font-weight:700;}
        .sig-role{font-size:8px;color:#555;}
        .ftr{text-align:center;font-size:6px;color:#aaa;margin-top:8px;}
        @media print{
            @page{size:210mm 148.5mm;margin:0;}
            body{background:#fff;padding:0;}
            .toolbar{display:none !important;}
            .rcpt{position:fixed;top:0;left:0;width:210mm;height:148.5mm;max-width:none;border-radius:0;box-shadow:none;}
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a class="btn btn-back" href="<?= h(app_url('cashier/collect.php')) ?>">← Back to Collection</a>
        <button class="btn btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        <?php if ($isDuplicate): ?><span class="dup-badge">⚠ Duplicate Copy</span><?php endif; ?>
    </div>

    <div class="rcpt">
        <?php if ($isDuplicate): ?><div class="dup-stamp">DUPLICATE</div><?php endif; ?>
        <div class="accent"></div>
        <div class="inner">
            <div class="hdr">
                <img src="<?= $logoUrl ?>" class="logo" alt="ITFA">
                <div class="hdr-mid">
                    <div class="hdr-tag">Official Receipt</div>
                    <div class="hdr-school">IBN TAIMIYAH FOUNDATION ACADEMY, Inc.</div>
                    <div class="hdr-addr">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>
                    <div class="hdr-sy">School Year <?= h((string) $r['school_year']) ?></div>
                </div>
                <div class="or-pill">
                    <div class="or-lbl">OR No.</div>
                    <div class="or-num"><?= h($orNumber) ?></div>
                    <div class="or-dt"><?= date('F d, Y', $ts) ?><br><?= date('h:i A', $ts) ?></div>
                </div>
            </div>

            <div class="sec">Student Information</div>
            <table class="tbl">
                <tr><td>Student Name</td><td><?= h($name) ?></td></tr>
                <tr><td>LRN / ID</td><td style="font-family:monospace"><?= h((string) ($r['lrn'] ?: $r['student_id'])) ?></td></tr>
                <tr><td>Department</td><td><?= h((string) $r['Department']) ?></td></tr>
                <tr><td>Grade / Section</td><td><?= h(trim((string) $r['grade_name'])) ?><?= $r['section_name'] ? ' — ' . h((string) $r['section_name']) : '' ?></td></tr>
            </table>

            <hr class="sep">

            <div class="sec">Payment Breakdown</div>
            <table class="fees">
                <?php foreach ($details as $d): ?>
                <tr>
                    <td><?= h((string) $d['description']) ?></td>
                    <td>&#8369;<?= number_format((float) $d['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($terms): ?>
                <tr><td colspan="2" style="font-size:7px;color:#777;padding-top:3px;">
                    Applied to:
                    <?php foreach ($terms as $i => $t): ?><?= $i ? ', ' : '' ?><?= h((string) $t['month_label']) ?> (&#8369;<?= number_format((float) $t['amount'], 0) ?>)<?php endforeach; ?>
                </td></tr>
                <?php endif; ?>
                <tr class="total"><td>Total Paid</td><td>&#8369;<?= number_format($amount, 2) ?></td></tr>
            </table>

            <div class="amt-block">
                <div>
                    <div class="amt-lbl">Amount Received</div>
                    <div class="amt-fig">&#8369;<?= number_format($amount, 2) ?></div>
                    <div class="amt-words"><?= h(or_amount_in_words($amount)) ?> Pesos only</div>
                    <div class="paid-badge">✓ <?= h((string) $r['method']) ?> · PAID</div>
                </div>
                <div style="margin-left:auto;text-align:right;font-size:8px;color:#555;align-self:flex-end;">
                    <div>Remaining balance</div>
                    <div style="font-size:13px;font-weight:800;color:#000;">&#8369;<?= number_format((float) $r['balance'], 2) ?></div>
                </div>
            </div>

            <div class="pay-meta">
                <span>Date: <strong><?= date('F d, Y h:i A', $ts) ?></strong></span>
                <span>Received by: <strong><?= h((string) $r['issued_by']) ?></strong></span>
            </div>

            <div class="bc"><svg id="bc"></svg></div>

            <div class="sig">
                <div class="sig-line">
                    <div class="sig-name"><?= h((string) $r['issued_by']) ?></div>
                    <div class="sig-role">Cashier / Treasurer</div>
                </div>
            </div>
            <div class="ftr">&copy; <?= date('Y') ?> IBN TAIMIYAH FOUNDATION ACADEMY, Inc. · Computer-generated official receipt</div>
        </div>
    </div>

    <script>
        try { JsBarcode('#bc', <?= json_encode($orNumber) ?>, {format:'CODE128',lineColor:'#000',width:1.4,height:30,displayValue:true,fontSize:9,margin:2}); } catch(e){}
        window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 600); });
    </script>
</body>
</html>
