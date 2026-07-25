<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();

if (!is_cashier_user($user)) {
    flash_set('error', 'Only Cashier users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

// Pull receipt data â€” either from session (fresh) or from DB (reprint)
$isDuplicate     = false;
$reprintId       = to_int($_GET['reprint'] ?? 0);

// Fee breakdown — only available from fresh session receipt
$feeAdmission    = 0.0;
$feeActivity     = 0.0;
$feeBooks        = 0.0;
$feeHouseReg     = 0.0;
$hasFeeBreakdown = false;

if ($reprintId > 0) {
    // â”€â”€ Reprint mode: load from DB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $isDuplicate = true;
    $connection  = db();

    $rpStmt = $connection->prepare(
        'SELECT bpr.id, bpr.student_id, bpr.name, bpr.payment_amount, bpr.payment_date,
                bpr.or_number, bpr.payment_method, bpr.school_year, bpr.cashier_name,
                bpr.enrollment_id,
                IFNULL(bpr.fee_admission,  0) AS fee_admission,
                IFNULL(bpr.fee_activity,   0) AS fee_activity,
                IFNULL(bpr.fee_books,      0) AS fee_books,
                IFNULL(bpr.fee_house_reg,  0) AS fee_house_reg,
                en.Department,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name,
                pb.description  AS classification_desc,
                pb.classification AS classification_name
         FROM backaccount_payment_records bpr
         LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc   ON CAST(sc.Section_id AS CHAR) = en.Department_section
         LEFT JOIN payment_breakdown pb
                ON pb.classification_id = en.Student_classification
               AND pb.status = \'Active\'
         WHERE bpr.id = ?
         LIMIT 1'
    );
    $rpStmt->bind_param('i', $reprintId);
    $rpStmt->execute();
    $rpRow = stmt_fetch_assoc($rpStmt);

    if (!$rpRow) {
        flash_set('error', 'Payment record not found.');
        redirect_to(app_url('cashier/history.php'));
    }

    $orNumber           = (string) ($rpRow['or_number']           ?? ('ITFA-' . date('Y') . '-' . str_pad((string) $reprintId, 6, '0', STR_PAD_LEFT)));
    $studentName        = (string) ($rpRow['name']                ?? '');
    $studentId          = (string) ($rpRow['student_id']          ?? '');
    $department         = (string) ($rpRow['Department']          ?? '');
    $gradeLevel         = trim((string) ($rpRow['gradelevel_name'] ?? ''));
    $section            = trim((string) ($rpRow['section_name']   ?? ''));
    $classificationName = (string) ($rpRow['classification_name'] ?? '');
    $classificationDesc = (string) ($rpRow['classification_desc'] ?? '');
    $schoolYear         = (string) ($rpRow['school_year']         ?? '');
    $amountPaid         = (float)  ($rpRow['payment_amount']      ?? 0);
    $paymentMethod      = (string) ($rpRow['payment_method']      ?? 'Cash');
    $ts                 = strtotime((string) ($rpRow['payment_date'] ?? 'now'));
    $paymentDate        = date('F d, Y', $ts ?: time());
    $paymentTime        = date('h:i A',  $ts ?: time());
    $cashierName        = (string) ($rpRow['cashier_name']        ?? 'Cashier');
    $feeAdmission       = (float)  ($rpRow['fee_admission']       ?? 0);
    $feeActivity        = (float)  ($rpRow['fee_activity']        ?? 0);
    $feeBooks           = (float)  ($rpRow['fee_books']           ?? 0);
    $feeHouseReg        = (float)  ($rpRow['fee_house_reg']       ?? 0);
    $hasFeeBreakdown    = ($feeAdmission + $feeActivity + $feeBooks + $feeHouseReg) > 0;

} else {
    // â”€â”€ Fresh receipt: load from session â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $receipt = $_SESSION['receipt_data'] ?? null;
    unset($_SESSION['receipt_data']);

    if (!$receipt) {
        flash_set('error', 'No receipt data found. Please process a payment first.');
        redirect_to(app_url('cashier/index.php'));
    }

    $orNumber           = (string) ($receipt['or_number']           ?? '');
    $studentName        = (string) ($receipt['student_name']        ?? '');
    $studentId          = (string) ($receipt['student_id']          ?? '');
    $department         = (string) ($receipt['department']          ?? '');
    $gradeLevel         = (string) ($receipt['grade_level']         ?? '');
    $section            = (string) ($receipt['section']             ?? '');
    $classificationName = (string) ($receipt['classification']      ?? '');
    $classificationDesc = (string) ($receipt['classification_desc'] ?? '');
    $schoolYear         = (string) ($receipt['school_year']         ?? '');
    $amountPaid         = (float)  ($receipt['amount_paid']         ?? 0);
    $paymentMethod      = (string) ($receipt['payment_method']      ?? 'Cash');
    $paymentDate        = (string) ($receipt['payment_date']        ?? date('F d, Y'));
    $paymentTime        = (string) ($receipt['payment_time']        ?? date('h:i A'));
    $cashierName        = (string) ($receipt['cashier_name']        ?? 'Cashier');
    $feeAdmission       = (float)  ($receipt['fee_admission']       ?? 0);
    $feeActivity        = (float)  ($receipt['fee_activity']        ?? 0);
    $feeBooks           = (float)  ($receipt['fee_books']           ?? 0);
    $feeHouseReg        = (float)  ($receipt['fee_house_reg']       ?? 0);
    $hasFeeBreakdown    = ($feeAdmission + $feeActivity + $feeBooks + $feeHouseReg) > 0;
}

// Classification label â€“ prefer description, fall back to name
$classTxt = $classificationDesc !== '' ? $classificationDesc
          : ($classificationName !== '' ? $classificationName : 'Regular');

// Detect Junior High School (Madrasah fee applies to JHS only)
$isJuniorHigh = stripos($department, 'junior') !== false;

// Activity fee sub-items
$activityItems = [];
if ($feeActivity > 0) {
    $activityItems = [
        ['Athletic Fee',              200.00],
        ['TSC Fee (Student Council)', 120.00],
        ['School Publication Fee',    100.00],
    ];
    if ($isJuniorHigh) {
        $activityItems[] = ['Madrasah Registration (Tarbiyah)', 70.00];
    }
}

$feeTotal = $feeAdmission + $feeActivity + $feeBooks + $feeHouseReg;
$logoUrl  = h(app_url('itfalogo.png'));

/**
 * Convert a numeric amount to English words (up to 999,999.99).
 */
function amount_in_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
             'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
             'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $intPart  = (int) floor($amount);
    $centPart = (int) round(($amount - $intPart) * 100);

    $toWords = static function (int $n) use ($ones, $tens): string {
        if ($n === 0) {
            return 'Zero';
        }
        $w = '';
        if ($n >= 100000) {
            $w .= $ones[(int) ($n / 100000)] . ' Hundred ';
            $n %= 100000;
        }
        if ($n >= 1000) {
            $t  = (int) ($n / 1000);
            $w .= ($t < 20 ? $ones[$t] : $tens[(int) ($t / 10)] . ($t % 10 ? ' ' . $ones[$t % 10] : '')) . ' Thousand ';
            $n %= 1000;
        }
        if ($n >= 100) {
            $w .= $ones[(int) ($n / 100)] . ' Hundred ';
            $n %= 100;
        }
        if ($n >= 20) {
            $w .= $tens[(int) ($n / 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '') . ' ';
        } elseif ($n > 0) {
            $w .= $ones[$n] . ' ';
        }
        return rtrim($w);
    };

    $result = $toWords($intPart);
    if ($centPart > 0) {
        $result .= ' and ' . $toWords($centPart) . '/100';
    }
    return $result;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Official Receipt &ndash; <?= h($orNumber) ?> | ITFA</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── Screen ─────────────────────────────────────────────────────── */
        body {
            font-family: 'Inter', sans-serif;
            background: #8e9aab;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 28px 16px 56px;
            min-height: 100vh;
        }

        .toolbar {
            display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
            width: 100%; max-width: 794px; margin-bottom: 20px;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 18px; border-radius: 10px;
            font-size: 12px; font-weight: 600; cursor: pointer;
            text-decoration: none; border: none; transition: background .15s;
        }
        .btn-back  { background:#fff; color:#334155; border:1px solid #cbd5e1; }
        .btn-back:hover  { background:#f1f5f9; }
        .btn-print { background:#1b3f7a; color:#fff; }
        .btn-print:hover { background:#15326a; }
        .dup-badge {
            margin-left: auto;
            display: inline-flex; align-items: center; gap: 5px;
            background: #fef2f2; border: 1.5px solid #fca5a5;
            color: #b91c1c; font-size: 9px; font-weight: 800;
            padding: 4px 12px; border-radius: 20px; letter-spacing: .1em; text-transform: uppercase;
        }

        /* ── Receipt shell ───────────────────────────────────────────────── */
        .receipt {
            background: #fff;
            width: 100%; max-width: 794px;
            border-radius: 12px;
            box-shadow: 0 24px 64px -16px rgba(0,0,0,.35);
            overflow: hidden; position: relative;
        }
        .dup-stamp {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%,-50%) rotate(-32deg);
            font-size: 72px; font-weight: 900;
            color: rgba(0,0,0,.06);
            white-space: nowrap; pointer-events: none; z-index: 10;
        }

        /* ── Accent bar ─────────────────────────────────────────────────── */
        .accent { height: 4px; background: #000; }

        /* ── Inner padding ──────────────────────────────────────────────── */
        .inner { padding: 12px 16px 14px; }

        /* ── Header ─────────────────────────────────────────────────────── */
        .hdr {
            display: flex; align-items: center; gap: 10px;
            padding-bottom: 9px; border-bottom: 2px solid #000;
        }
        .logo { width: 50px; height: 50px; object-fit: contain; flex-shrink: 0; }
        .hdr-mid { flex: 1; text-align: center; }
        .hdr-tag    { font-size: 7px; letter-spacing: .32em; text-transform: uppercase; color: #444; font-weight: 700; }
        .hdr-school { font-family: 'Playfair Display', serif; font-size: 11.5px; font-weight: 800; color: #000; line-height: 1.3; margin-top: 2px; }
        .hdr-addr   { font-size: 7px; color: #555; margin-top: 2px; }
        .hdr-sy     { font-size: 7.5px; color: #222; font-weight: 700; margin-top: 2px; }
        .or-pill {
            border: 1.5px solid #000;
            border-radius: 6px; padding: 7px 10px;
            text-align: center; min-width: 88px; flex-shrink: 0;
            background: #fff;
        }
        .or-lbl { font-size: 7px; letter-spacing: .2em; text-transform: uppercase; color: #444; font-weight: 700; }
        .or-num { font-size: 9.5px; font-weight: 800; margin-top: 2px; word-break: break-all; color: #000; }
        .or-dt  { font-size: 6.5px; color: #555; margin-top: 3px; line-height: 1.55; }

        /* ── Section label ──────────────────────────────────────────────── */
        .sec {
            font-size: 7px; text-transform: uppercase; letter-spacing: .24em;
            color: #555; font-weight: 700; margin: 9px 0 4px;
        }

        /* ── Info table (student / payment meta) ────────────────────────── */
        .tbl { width: 100%; border-collapse: collapse; }
        .tbl td { font-size: 9px; padding: 2px 0; vertical-align: top; line-height: 1.4; }
        .tbl td:first-child { color: #555; width: 42%; }
        .tbl td:last-child  { font-weight: 600; color: #000; text-align: right; }
        .tbl tr + tr td { border-top: 1px solid #ececec; }

        /* ── Separator ──────────────────────────────────────────────────── */
        .sep { border: none; border-top: 1px solid #ccc; margin: 8px 0; }

        /* ── Fee breakdown table ────────────────────────────────────────── */
        .fees { width: 100%; border-collapse: collapse; }
        .fees td { font-size: 9px; padding: 2px 0; vertical-align: top; line-height: 1.4; }
        .fees td:first-child { color: #333; }
        .fees td:last-child  { font-weight: 600; color: #000; text-align: right; white-space: nowrap; }
        .fees tr + tr td { border-top: 1px solid #ececec; }
        .fees tr.sub td { font-size: 7.5px; color: #666; padding: 1px 0 1px 14px; border-top: none !important; }
        .fees tr.sub td:last-child { color: #555; font-weight: 500; }
        .fees tr.total td {
            border-top: 2px solid #000 !important;
            padding-top: 4px; font-weight: 800;
            color: #000; font-size: 10px;
        }

        /* ── Amount block ───────────────────────────────────────────────── */
        .amt-block {
            display: flex; align-items: center; gap: 10px;
            background: #f5f5f5; border: 1.5px solid #000;
            border-radius: 6px; padding: 8px 12px; margin: 8px 0 6px;
        }
        .amt-fig  { font-size: 26px; font-weight: 800; color: #000; letter-spacing: -.5px; flex-shrink: 0; }
        .amt-right { flex: 1; }
        .amt-lbl  { font-size: 7px; text-transform: uppercase; letter-spacing: .2em; color: #444; font-weight: 700; }
        .amt-words { font-size: 7.5px; color: #555; font-style: italic; margin-top: 2px; line-height: 1.4; }
        .paid-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #000; color: #fff;
            font-size: 8px; font-weight: 700; padding: 3px 9px;
            border-radius: 20px; margin-top: 4px;
        }

        /* ── Payment meta row ───────────────────────────────────────────── */
        .pay-meta { display: flex; justify-content: space-between; font-size: 8px; color: #555; margin-bottom: 6px; }
        .pay-meta strong { color: #000; font-weight: 600; }

        /* ── Barcode ────────────────────────────────────────────────────── */
        .bc { text-align: center; margin: 4px 0 2px; }
        .bc svg { max-width: 100%; }
        .bc-num { font-size: 7px; color: #555; letter-spacing: .1em; margin-top: 2px; }

        /* ── Cut line ───────────────────────────────────────────────────── */
        .cut { display: flex; align-items: center; gap: 8px; margin: 6px 0 4px; }
        .cut::before, .cut::after { content:''; flex:1; border-top: 1px dashed #bbb; }
        .cut-txt { font-size: 7px; text-transform: uppercase; letter-spacing: .22em; color: #999; white-space: nowrap; }

        /* ── Signature ──────────────────────────────────────────────────── */
        .sig { text-align: center; }
        .sig-line { border-top: 1px solid #333; padding-top: 4px; margin: 22px auto 0; width: 170px; }
        .sig-name { font-size: 10px; font-weight: 700; color: #000; }
        .sig-role { font-size: 8px; color: #555; }

        /* ── Footer ─────────────────────────────────────────────────────── */
        .receipt-footer { text-align: center; font-size: 6px; color: #aaa; margin-top: 8px; }

        /* ══════════════════════════════════════════════════════════════════
           PRINT  —  half of A4 portrait  =  A5  =  148 mm × 210 mm
           Zero page margins; inner padding provides the safe zone.
           ══════════════════════════════════════════════════════════════════ */
        @media print {
            @page {
                size: 210mm 148.5mm;
                margin: 0mm;
            }
            html {
                width: 210mm; height: 148.5mm;
                margin: 0 !important; padding: 0 !important;
            }
            body {
                width: 210mm; height: 148.5mm;
                background: #fff !important;
                padding: 0 !important; margin: 0 !important;
                overflow: hidden;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .toolbar { display: none !important; }
            .receipt {
                position: fixed !important;
                top: 0 !important; left: 0 !important;
                width: 210mm !important; max-width: none !important;
                height: 148.5mm !important;
                border-radius: 0 !important; box-shadow: none !important;
                display: flex; flex-direction: column;
                overflow: hidden !important;
            }
            .accent { flex-shrink: 0; height: 3px !important; background: #000 !important; }
            .inner {
                padding: 4mm 6mm 3mm !important;
                flex: 1; overflow: hidden;
                display: flex; flex-direction: column;
            }
            .logo        { width: 40px !important; height: 40px !important; }
            .hdr         { padding-bottom: 6px !important; gap: 8px !important; }
            .hdr-school  { font-size: 10px !important; }
            .sec         { margin: 6px 0 3px !important; font-size: 6px !important; }
            .tbl td      { font-size: 8px !important; padding: 1.5px 0 !important; }
            .fees td     { font-size: 8px !important; padding: 1.5px 0 !important; }
            .fees tr.sub td { font-size: 7px !important; }
            .fees tr.total td { font-size: 9px !important; padding-top: 3px !important; }
            .sep         { margin: 5px 0 !important; }
            .amt-block   { margin: 5px 0 3px !important; padding: 5px 9px !important; gap: 8px !important; }
            .amt-fig     { font-size: 20px !important; }
            .amt-words   { font-size: 6.5px !important; }
            .pay-meta    { font-size: 6.5px !important; margin-bottom: 3px !important; }
            .bc          { margin: 2px 0 1px !important; }
            .cut         { margin: 3px 0 2px !important; }
            .sig-line    { margin-top: 14px !important; width: 150px !important; }
            .receipt-footer { margin-top: 4px !important; font-size: 5.5px !important; }
        }
    </style>
</head>
<body>

    <!-- Screen toolbar -->
    <div class="toolbar">
        <a href="<?= h(app_url('cashier/index.php')) ?>" class="btn btn-back">
            &larr; Back to Cashier
        </a>
        <button class="btn btn-print" onclick="window.print()">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-6 0v4H9v-4h6z"/>
            </svg>
            Print / Save PDF
        </button>
        <?php if ($isDuplicate): ?>
        <span class="dup-badge">&#9888; Duplicate Copy</span>
        <?php endif; ?>
    </div>

    <!-- ══ Receipt ═══════════════════════════════════════════════════════ -->
    <div class="receipt">
        <?php if ($isDuplicate): ?>
        <div class="dup-stamp">DUPLICATE</div>
        <?php endif; ?>

        <div class="accent"></div>

        <div class="inner">

            <!-- ── Header ─────────────────────────────────────────────── -->
            <div class="hdr">
                <img src="<?= $logoUrl ?>" alt="ITFA" class="logo">
                <div class="hdr-mid">
                    <div class="hdr-tag">Official Receipt</div>
                    <div class="hdr-school">IBN TAIMIYAH FOUNDATION ACADEMY, Inc.</div>
                    <div class="hdr-addr">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>
                    <div class="hdr-sy">School Year <?= h($schoolYear) ?></div>
                </div>
                <div class="or-pill">
                    <div class="or-lbl">OR No.</div>
                    <div class="or-num"><?= h($orNumber) ?></div>
                    <div class="or-dt"><?= h($paymentDate) ?><br><?= h($paymentTime) ?></div>
                </div>
            </div>

            <!-- ── Student Information ────────────────────────────────── -->
            <div class="sec">Student Information</div>
            <table class="tbl">
                <tr>
                    <td>Student Name</td>
                    <td><?= h($studentName) ?></td>
                </tr>
                <tr>
                    <td>Student ID</td>
                    <td style="font-family:monospace;font-size:8.5px"><?= h($studentId) ?></td>
                </tr>
                <tr>
                    <td>Department</td>
                    <td><?= h($department) ?></td>
                </tr>
                <tr>
                    <td>Grade / Section</td>
                    <td><?= h($gradeLevel) ?><?= ($section !== '' && $section !== '-') ? ' &ndash; ' . h($section) : '' ?></td>
                </tr>
                <tr>
                    <td>Classification</td>
                    <td><?= h($classTxt ?: 'Regular') ?></td>
                </tr>
            </table>

            <hr class="sep">

            <!-- ── Enrollment Day Fee Breakdown ──────────────────────── -->
            <?php if ($hasFeeBreakdown): ?>
            <div class="sec">Enrollment Day Fees</div>
            <table class="fees">
                <?php if ($feeAdmission > 0): ?>
                <tr>
                    <td>Admission / Registration Fee</td>
                    <td>&#8369;<?= number_format($feeAdmission, 2) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($feeActivity > 0): ?>
                <tr>
                    <td>Activity Fees</td>
                    <td>&#8369;<?= number_format($feeActivity, 2) ?></td>
                </tr>
                <?php foreach ($activityItems as [$label, $val]): ?>
                <tr class="sub">
                    <td>&bull; <?= h($label) ?></td>
                    <td>&#8369;<?= number_format($val, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($feeBooks > 0): ?>
                <tr>
                    <td>Books (enrollment down-payment)</td>
                    <td>&#8369;<?= number_format($feeBooks, 2) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($feeHouseReg > 0): ?>
                <tr>
                    <td>House Registration</td>
                    <td>&#8369;<?= number_format($feeHouseReg, 2) ?></td>
                </tr>
                <?php endif; ?>

                <tr class="total">
                    <td>Total Due at Enrollment</td>
                    <td>&#8369;<?= number_format($feeTotal, 2) ?></td>
                </tr>
            </table>
            <hr class="sep">
            <?php endif; ?>

            <!-- ── Amount Received ────────────────────────────────────── -->
            <div class="amt-block">
                <div class="amt-right">
                    <div class="amt-lbl">Amount Received</div>
                    <div class="amt-fig">&#8369;<?= h(number_format($amountPaid, 2)) ?></div>
                    <div class="amt-words"><?= h(amount_in_words($amountPaid)) ?> Pesos only</div>
                    <div class="paid-badge">
                        <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <?= h($paymentMethod) ?> &middot; PAID
                    </div>
                </div>
            </div>

            <!-- ── Payment Meta ───────────────────────────────────────── -->
            <div class="pay-meta">
                <span>Date: <strong><?= h($paymentDate) ?> &middot; <?= h($paymentTime) ?></strong></span>
                <span>Received by: <strong><?= h($cashierName) ?></strong></span>
            </div>

            <!-- ── Barcode ────────────────────────────────────────────── -->
            <div class="bc">
                <svg class="barcode"></svg>
                <div class="bc-num"><?= h($orNumber) ?></div>
            </div>

            <!-- ── Cut line ───────────────────────────────────────────── -->
            <div class="cut"><span class="cut-txt">Official Document &middot; ITFA</span></div>

            <!-- ── Signature ──────────────────────────────────────────── -->
            <div class="sig">
                <div class="sig-line">
                    <div class="sig-name"><?= h($cashierName) ?></div>
                    <div class="sig-role">Cashier / Treasurer</div>
                </div>
            </div>

            <div class="receipt-footer">
                &copy; <?= date('Y') ?> IBN TAIMIYAH FOUNDATION ACADEMY, Inc. &nbsp;&middot;&nbsp; All Rights Reserved
            </div>

        </div><!-- /inner -->
    </div><!-- /receipt -->

    <script>
        JsBarcode('.barcode', <?= json_encode($orNumber) ?>, {
            format:       'CODE128',
            lineColor:    '#000000',
            width:        1.4,
            height:       30,
            displayValue: false,
            margin:       2,
        });

        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 700);
        });
    </script>
</body>
</html>
