<?php

declare(strict_types=1);

/**
 * Shared renderer for the OFFICIAL Statement-of-Account slip (exact replica of
 * the school form). Used by both the cashier hub (cashier/soa.php) and the
 * student portal (student/soa_print.php) so a student prints the identical
 * document a cashier would generate.
 *
 * @param int[]  $printIds  soa_master ids to render
 * @param string $layout    '2up' (2 slips / A4, paper-saver) or '1up'
 * @param string $backUrl   optional "← Back" target shown in the screen toolbar
 */

require_once __DIR__ . '/soa_service.php';
require_once __DIR__ . '/promissory_service.php';
require_once __DIR__ . '/back_account_service.php';

function soa_render_print_page(mysqli $connection, array $printIds, string $layout = '2up', string $backUrl = ''): void
{
    $layout = $layout === '1up' ? '1up' : '2up';
    $printIds = array_values(array_filter(array_map('intval', $printIds), static fn($v) => $v > 0));
    $idList = implode(',', $printIds);

    $docs = []; $details = []; $fullSched = []; $charges = []; $paid = [];

    if ($idList !== '') {
        $sql = "SELECT sm.id AS soa_id, sm.soa_number, sm.total_due, sm.generated_at, sm.barcode_ref,
                       sa.id AS assessment_id, sa.enrollment_id, sa.student_type, sa.installment_base,
                       sa.net_assessed, sa.total_assessed, sa.total_discount, sa.total_paid, sa.balance,
                       sa.installment_count, sa.student_id,
                       IFNULL(pb.tuition, 0)            AS pb_tuition,
                       IFNULL(pb.School_improvement, 0) AS pb_improvement,
                       en.Department, en.school_year, en.waive_school_improvement AS waive_improvement,
                       en.waive_miscellaneous AS waive_misc,
                       COALESCE(
                           CONCAT(p.surname, ', ', p.firstname, ' ', IFNULL(p.middlename, '')),
                           CONCAT(osp.surname, ', ', osp.firstname, ' ', IFNULL(osp.middlename, ''))
                       ) AS full_name,
                       COALESCE(p.lrn, osp.lrn) AS lrn,
                       IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                       IFNULL(sc.Section_name, en.Department_section) AS section_name,
                       pb.description AS classification_desc,
                       pb.classification AS classification_code,
                       IFNULL(pb.rate, 0) AS pb_rate
                FROM soa_master sm
                JOIN student_assessment sa ON sa.id = sm.assessment_id
                JOIN enrollment en         ON en.id = sa.enrollment_id
                LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
                LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
                LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
                LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
                LEFT JOIN payment_breakdown pb
                       ON pb.classification_id = en.Student_classification
                      AND pb.type = sa.student_type
                      AND pb.status = 'Active'
                WHERE sm.id IN ($idList)
                ORDER BY full_name";
        $res = $connection->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $docs[] = $row;
            }
        }
        $dRes = $connection->query(
            "SELECT soa_id, term_no, month_label, amount_due, amount_paid_snapshot, amount_selected
             FROM soa_details WHERE soa_id IN ($idList) ORDER BY soa_id, term_no"
        );
        if ($dRes) {
            while ($d = $dRes->fetch_assoc()) {
                $details[(int) $d['soa_id']][] = $d;
            }
        }

        $aIds  = array_filter(array_unique(array_map(static fn($d) => (int) $d['assessment_id'], $docs)));
        $aList = implode(',', $aIds);
        if ($aList !== '') {
            $fsRes = $connection->query(
                "SELECT assessment_id, term_no, month_label, due_date, amount_due, amount_paid, balance, status
                 FROM payment_schedule WHERE assessment_id IN ($aList) ORDER BY assessment_id, term_no"
            );
            if ($fsRes) {
                while ($s = $fsRes->fetch_assoc()) {
                    $fullSched[(int) $s['assessment_id']][] = $s;
                }
            }
            $cRes = $connection->query(
                "SELECT ac.assessment_id, IFNULL(fi.category,'other') AS category, ac.amount
                 FROM assessment_charge ac LEFT JOIN fee_item fi ON fi.id = ac.fee_item_id
                 WHERE ac.assessment_id IN ($aList)"
            );
            if ($cRes) {
                while ($c = $cRes->fetch_assoc()) {
                    $charges[(int) $c['assessment_id']][(string) $c['category']] = (float) $c['amount'];
                }
            }

            $enIds = array_filter(array_unique(array_map(static fn($d) => (int) $d['enrollment_id'], $docs)));
            $enList = implode(',', $enIds);
            if ($enList !== '') {
                $pRes = $connection->query(
                    "SELECT enrollment_id,
                            SUM(fee_admission)  AS p_adm,
                            SUM(fee_activity)   AS p_act,
                            SUM(fee_books)      AS p_books,
                            SUM(fee_house_reg)  AS p_house,
                            SUM(payment_amount) AS p_total,
                            MAX(payment_date)   AS p_date,
                            GROUP_CONCAT(DISTINCT or_number ORDER BY id SEPARATOR ', ') AS p_ors
                     FROM backaccount_payment_records
                     WHERE enrollment_id IN ($enList)
                     GROUP BY enrollment_id"
                );
                if ($pRes) {
                    while ($p = $pRes->fetch_assoc()) {
                        $paid[(int) $p['enrollment_id']] = $p;
                    }
                }
            }
        }
    }

    $bookkeeper = soa_setting($connection, 'SOA_BOOKKEEPER', 'Bookkeeper');
    $cashierSig = soa_setting($connection, 'SOA_CASHIER_SIGNATORY', 'Cashier');
    // Signature images (project root). Shown above the name only if the file exists.
    $rootDir = dirname(__DIR__);
    $bookSig = is_file($rootDir . '/Pahima Tahir.png')   ? app_url('Pahima%20Tahir.png')   : '';
    $cashSig = is_file($rootDir . '/BAJUNAID GARAY.png') ? app_url('BAJUNAID%20GARAY.png') : '';

    /** Render one half-page SOA slip — exact replica of the official school SOA. */
    $renderHalf = static function (?array $doc) use ($details, $fullSched, $charges, $paid, $connection, $bookkeeper, $cashierSig, $bookSig, $cashSig): string {
        if ($doc === null) {
            return '<div class="half half-empty"></div>';
        }
        $logo      = h(app_url('itfalogo.png'));
        $name      = strtoupper(trim((string) ($doc['full_name'] ?? 'Student')));
        $soaNo     = (string) $doc['soa_number'];
        $aid       = (int) $doc['assessment_id'];
        $schedRows = $fullSched[$aid] ?? [];
        $chg       = $charges[$aid] ?? [];
        $selected  = array_map(static fn($d) => (int) $d['term_no'], $details[(int) $doc['soa_id']] ?? []);
        $dept      = (string) ($doc['Department'] ?? '');
        $grade     = trim((string) ($doc['grade_name'] ?? ''));
        $type      = strtoupper((string) ($doc['student_type'] ?? 'Old'));
        $classDesc = strtoupper(trim((string) ($doc['classification_desc'] ?? '')));
        $count     = max(1, (int) $doc['installment_count']);
        $isKinder  = stripos($grade, 'kinder') !== false || stripos($grade, 'tahder') !== false;

        $monthly = round(((float) $doc['installment_base']) / $count, 2);
        $comp    = soa_components_for(
            $connection, $dept, $grade,
            (string) ($doc['classification_code'] ?? ''),
            (string) ($doc['student_type'] ?? 'Old'),
            (float) ($doc['pb_rate'] ?? 0),
            (bool) ($doc['waive_improvement'] ?? false),
            (bool) ($doc['waive_misc'] ?? false)
        );
        if ($comp === []) {
            $comp = soa_monthly_components(
                $connection, $dept, (string) ($doc['student_type'] ?? 'Old'),
                $monthly, (float) ($doc['pb_tuition'] ?? 0), (float) ($doc['pb_improvement'] ?? 0), $count
            );
        }
        $tuM = $comp['Tuition Fee'] ?? 0.0;
        $miM = $comp['Miscellaneous Fee'] ?? ($comp['Miscellaneous & Other'] ?? 0.0);
        $imM = $comp['School Improvement'] ?? 0.0;
        $bkM = $comp['Books / Materials'] ?? 0.0;

        $collected = 0.0; $schedByTerm = [];
        foreach ($schedRows as $sr) { $collected += (float) $sr['amount_paid']; $schedByTerm[(int) $sr['term_no']] = $sr; }
        $monthsPaid = $monthly > 0 ? $collected / $monthly : 0.0;
        $selFactor = 0.0; $selMonths = [];
        foreach ($selected as $t) {
            $sr = $schedByTerm[$t] ?? null; if (!$sr) { continue; }
            $due = (float) $sr['amount_due']; $bal = (float) $sr['balance'];
            $selFactor += $due > 0 ? $bal / $due : 0.0;
            $selMonths[] = (string) $sr['month_label'];
        }
        $monthLabel = $selMonths === [] ? '—'
            : (count($selMonths) === 1 ? $selMonths[0] : ($selMonths[0] . ' – ' . end($selMonths)));

        $pd     = $paid[(int) ($doc['enrollment_id'] ?? 0)] ?? [];
        $pAdm   = (float) ($pd['p_adm']   ?? 0);
        $pAct   = (float) ($pd['p_act']   ?? 0);
        $pBk    = (float) ($pd['p_books'] ?? 0);
        $pHouse = (float) ($pd['p_house'] ?? 0);
        $pDate  = !empty($pd['p_date']) ? date('n/j/y', strtotime((string) $pd['p_date'])) : '';
        $pOr    = (string) ($pd['p_ors'] ?? '');
        $upD = static fn(float $p): string => $p > 0 ? $pDate : '';
        $upO = static fn(float $p): string => $p > 0 ? $pOr : '';

        $reg    = (float) ($chg['admission'] ?? 0);
        $act    = (float) ($chg['activity'] ?? 0);
        $hou    = (float) ($chg['house'] ?? 0);
        $bkDown = $bkM * $count;

        $rows = [];
        $rows[] = [1, 'Registration Fee', $reg, $pAdm, $reg - $pAdm, 0.0, [
            ['A. Forms', 0], ['B. ID Card', 0], ['C. Handbook', 0], ['D. Test Paper', 0],
        ], $upD($pAdm), $upO($pAdm)];
        $miAnnual = $miM * $count;
        $rows[] = [2, 'Miscellaneous Fees', $miAnnual, $miM * $monthsPaid, $miAnnual - $miM * $monthsPaid, $miM * $selFactor, [
            ['A. Laboratory fee', 0], ['B. Electric fee', 0], ['C. Library fee', 0],
            ['D. Internet/ICT fee', 0], ['E. Facilities Maintenance fee', 0], ['F. Medical/clinic fee', 0],
        ], '', ''];
        $imAnnual = $imM * $count;
        $rows[] = [3, 'School Improvement Fee', $imAnnual, $imM * $monthsPaid, $imAnnual - $imM * $monthsPaid, $imM * $selFactor, [], '', ''];
        $tuAnnual = $tuM * $count;
        $rows[] = [4, 'Tuition Fee', $tuAnnual, $tuM * $monthsPaid, $tuAnnual - $tuM * $monthsPaid, $tuM * $selFactor, [], '', ''];
        $rows[] = [5, 'Activity Fee', $act, $pAct, $act - $pAct, 0.0, [], $upD($pAct), $upO($pAct)];
        $rows[] = [6, 'House Reg. Fee', $hou, $pHouse, $hou - $pHouse, 0.0, [], $upD($pHouse), $upO($pHouse)];
        if ($isKinder) {
            $rows[] = [7, 'Graduation Fee', 0.0, 0.0, 0.0, 0.0, [], '', ''];
        } else {
            $bkAnnual = $bkDown + $bkM * $count;
            $bkPaid   = $pBk + $bkM * $monthsPaid;
            $rows[] = [7, 'Books Fee', $bkAnnual, $bkPaid, $bkAnnual - $bkPaid, $bkM * $selFactor, [], $upD($pBk), $upO($pBk)];
        }

        $tCharge = $tPaid = $tBal = $tBrk = 0.0;
        foreach ($rows as $r) { $tCharge += $r[2]; $tPaid += $r[3]; $tBal += $r[4]; $tBrk += $r[5]; }

        $peso = static fn(float $v): string => number_format($v, 2);

        ob_start(); ?>
        <div class="half slip">
            <div class="s-hdr">
                <img src="<?= $logo ?>" class="s-logo" alt="ITFA">
                <div class="s-school">IBN TAIMIYAH FOUNDATION ACADEMY, INC.</div>
                <div class="s-addr">Crossing Simuay, Sultan Kudarat, Maguindanao</div>
                <div class="s-doc">OFFICIAL STATEMENT OF ACCOUNTS · S.Y. <?= h((string) $doc['school_year']) ?></div>
                <div class="s-month">For the month of: <strong><?= h(strtoupper($monthLabel)) ?></strong></div>
                <div class="s-class"><?= h(trim($classDesc . ' ' . $type)) ?></div>
            </div>

            <div class="s-info">
                <div><span class="il">Name:</span> <strong><?= h($name) ?></strong></div>
                <div>
                    <span class="il">Yr &amp; Sec.:</span> <?= h($grade) ?><?= $doc['section_name'] ? ' — ' . h((string) $doc['section_name']) : '' ?>
                    <span class="s-no">SOA <?= h($soaNo) ?></span>
                </div>
            </div>

            <table class="s-tbl">
                <colgroup>
                    <col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"><col class="c6"><col class="c7">
                </colgroup>
                <thead>
                    <tr>
                        <th class="r">Charges</th><th class="r">Amount Paid</th><th class="r">Balance</th>
                        <th>Date</th><th>OR No.</th><th class="al">Account Title</th><th class="r">Breakdown</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as [$no, $title, $c, $p, $b, $brk, $subs, $date, $or]): ?>
                    <tr class="main">
                        <td class="r"><?= $peso($c) ?></td>
                        <td class="r"><?= $peso($p) ?></td>
                        <td class="r"><?= $peso($b) ?></td>
                        <td class="dt"><?= h($date) ?></td>
                        <td class="orn"><?= h($or) ?></td>
                        <td class="al"><?= $no ?>. <?= h($title) ?></td>
                        <td class="r b"><?= $peso($brk) ?></td>
                    </tr>
                    <?php foreach ($subs as [$slbl, $sval]): ?>
                    <tr class="sub">
                        <td class="r"><?= $sval ? number_format($sval, 2) : '0' ?></td>
                        <td></td><td class="r">0</td><td></td><td></td>
                        <td class="al"><?= h($slbl) ?></td><td class="r">0</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="r"><?= $peso($tCharge) ?></td>
                        <td class="r"><?= $peso($tPaid) ?></td>
                        <td class="r"><?= $peso($tBal) ?></td>
                        <td colspan="2" class="ttl">Total Amount to be Paid for this month</td>
                        <td class="al"></td>
                        <td class="r grand"><?= '₱' . number_format($tBrk, 2) ?></td>
                    </tr>
                </tfoot>
            </table>

            <div class="s-note">NOTE: Always present this slip upon paying your accounts.</div>
            <?php
            $pnActive = pn_active_for_enrollment($connection, (int) ($doc['enrollment_id'] ?? 0));
            if ($pnActive):
                $pnSum = 0.0; $pnLabels = [];
                foreach ($pnActive as $pnx) {
                    $pnSum += (float) $pnx['promissory_amount'];
                    $pnLabels[] = $pnx['promissory_no'] . ' (due ' . date('n/j/y', strtotime((string) $pnx['promised_payment_date'])) . ')';
                }
            ?>
            <div style="font-size:7px;font-weight:800;color:#b91c1c;border:0.6px solid #b91c1c;padding:1px 3px;margin-top:2px;">
                ⚠ UNPAID PROMISSORY NOTE: ₱<?= number_format($pnSum, 2) ?> — <?= h(implode(', ', $pnLabels)) ?>
            </div>
            <?php endif; ?>
            <?php
            // Prior-school-year balance carried by this student. Shown as a warning
            // only — deliberately NOT added into this month's total above.
            $baUnpaid = ba_unpaid_for_enrollment($connection, (int) ($doc['enrollment_id'] ?? 0));
            if ($baUnpaid):
                $baSum = 0.0; $baLabels = [];
                foreach ($baUnpaid as $bax) {
                    $baSum += (float) $bax['balance'];
                    $baLabels[] = 'S.Y. ' . (string) $bax['school_year'];
                }
            ?>
            <div style="font-size:7px;font-weight:800;color:#b91c1c;border:0.6px solid #b91c1c;padding:1px 3px;margin-top:2px;">
                ⚠ UNPAID BACK ACCOUNT: ₱<?= number_format($baSum, 2) ?> — <?= h(implode(', ', array_unique($baLabels))) ?>
                <span style="font-weight:600;">(not included in the total above &mdash; please settle at the Cashier)</span>
            </div>
            <?php endif; ?>
            <div class="s-warn">&ldquo;N O&nbsp;&nbsp;P E R M I T&nbsp;&nbsp;N O&nbsp;&nbsp;E X A M&rdquo;</div>

            <div class="s-sign">
                <div class="sg">
                    <?php if ($bookSig): ?><img class="sg-img" src="<?= h($bookSig) ?>" alt=""><?php endif; ?>
                    <div class="sg-name"><?= h($bookkeeper) ?></div>
                    <div class="sg-role">Bookkeeper</div>
                </div>
                <svg class="soa-barcode" data-code="<?= h($soaNo) ?>"></svg>
                <div class="sg">
                    <?php if ($cashSig): ?><img class="sg-img" src="<?= h($cashSig) ?>" alt=""><?php endif; ?>
                    <div class="sg-name"><?= h($cashierSig) ?></div>
                    <div class="sg-role">Cashier</div>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    };

    $perSheet = $layout === '1up' ? 1 : 2;
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Statement of Account — Print</title>
        <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
            body{font-family:'Inter',sans-serif;background:#8e9aab;color:#111;}
            .toolbar{position:sticky;top:0;display:flex;gap:10px;align-items:center;padding:12px 18px;background:#166534;color:#fff;z-index:50;}
            .toolbar .sp{flex:1;font-size:13px;font-weight:600;}
            .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;border:none;}
            .btn-print{background:#fff;color:#166534;}
            .btn-back{background:rgba(255,255,255,.15);color:#fff;}
            .sheets{padding:18px;display:flex;flex-direction:column;align-items:center;gap:18px;}
            .sheet{background:#fff;width:210mm;height:297mm;box-shadow:0 10px 30px rgba(0,0,0,.25);
                   display:flex;flex-direction:column;overflow:hidden;}
            .half{flex:1;padding:6mm 9mm;display:flex;flex-direction:column;min-height:0;overflow:hidden;}
            .half-empty{background:repeating-linear-gradient(45deg,#fafafa,#fafafa 12px,#f3f3f3 12px,#f3f3f3 24px);}
            .cut{height:0;border-top:1.5px dashed #b9b9b9;position:relative;margin:0 8mm;}
            .cut::after{content:'✂  cut here';position:absolute;left:50%;top:-8px;transform:translateX(-50%);
                        background:#fff;padding:0 8px;font-size:8px;letter-spacing:2px;color:#999;}
            .slip{font-size:8px;color:#000;}
            .s-hdr{text-align:center;position:relative;padding-bottom:4px;border-bottom:1.5px solid #000;}
            .s-logo{width:38px;height:38px;object-fit:contain;position:absolute;left:0;top:0;}
            .s-school{font-size:12px;font-weight:800;letter-spacing:.3px;}
            .s-addr{font-size:7.5px;}
            .s-doc{font-size:8px;font-weight:700;margin-top:2px;}
            .s-month{font-size:8px;margin-top:1px;}
            .s-class{font-size:8.5px;font-weight:800;letter-spacing:1px;margin-top:1px;text-transform:uppercase;}
            .s-info{font-size:8.5px;margin:4px 0 3px;line-height:1.6;}
            .s-info .il{display:inline-block;min-width:54px;}
            .s-info .s-no{float:right;font-size:7px;color:#555;font-weight:700;letter-spacing:.5px;}
            .s-tbl{width:100%;border-collapse:collapse;table-layout:fixed;}
            .s-tbl th,.s-tbl td{border:0.6px solid #000;padding:1px 3px;font-size:7.5px;line-height:1.25;
                                overflow:hidden;white-space:nowrap;}
            .s-tbl th{background:#e8eef6;font-weight:700;font-size:6.8px;text-align:center;}
            .s-tbl th.al,.s-tbl td.al{text-align:left;white-space:normal;}
            .s-tbl th.r,.s-tbl td.r{text-align:right;}
            .s-tbl col.c1{width:12%;} .s-tbl col.c2{width:12%;} .s-tbl col.c3{width:11%;}
            .s-tbl col.c4{width:9%;} .s-tbl col.c5{width:15%;} .s-tbl col.c6{width:29%;} .s-tbl col.c7{width:12%;}
            .s-tbl td.dt{font-size:6.2px;text-align:center;color:#444;}
            .s-tbl td.orn{font-size:5.8px;text-align:center;color:#444;letter-spacing:-.2px;}
            .s-tbl tr.main td{font-weight:600;}
            .s-tbl tr.main td.b{color:#1b3f7a;font-weight:800;}
            .s-tbl tr.sub td{font-size:6.5px;color:#666;font-weight:400;}
            .s-tbl tr.sub td.al{padding-left:12px;}
            .s-tbl tfoot td{font-weight:800;background:#f1f5f9;font-size:7.5px;}
            .s-tbl tfoot td.ttl{text-align:center;font-style:italic;font-size:7px;}
            .s-tbl tfoot td.grand{color:#1b3f7a;font-size:9px;}
            .s-note{font-size:7px;margin-top:3px;}
            .s-warn{font-size:9px;font-weight:800;text-align:center;letter-spacing:1px;margin:2px 0;}
            .s-sign{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;margin-top:auto;padding-top:10px;}
            .s-sign .sg{text-align:center;flex:1;}
            .s-sign .sg-img{display:block;height:26px;object-fit:contain;margin:0 auto -1px;}
            .s-sign .sg-name{font-size:8.5px;font-weight:700;border-top:1px solid #000;padding-top:2px;}
            .s-sign .sg-role{font-size:7.5px;}
            .soa-barcode{height:30px;max-width:30%;align-self:flex-end;}
            @media print{
                @page{size:210mm 297mm;margin:0;}
                body{background:#fff;}
                .toolbar{display:none !important;}
                .sheets{padding:0;gap:0;}
                .sheet{box-shadow:none;width:210mm;height:297mm;page-break-after:always;}
                .sheet:last-child{page-break-after:auto;}
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <span class="sp"><?= count($docs) ?> SOA · <?= $perSheet === 2 ? '2 per A4 (paper-saver)' : '1 per page' ?></span>
            <?php if ($backUrl !== ''): ?><a class="btn btn-back" href="<?= h($backUrl) ?>">← Back</a><?php endif; ?>
            <button class="btn btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        </div>
        <div class="sheets">
            <?php
            $total = count($docs);
            if ($total === 0) {
                echo '<p style="color:#fff;padding:40px;">No statement available to print.</p>';
            }
            for ($i = 0; $i < $total; $i += $perSheet) {
                echo '<div class="sheet">';
                echo $renderHalf($docs[$i] ?? null);
                if ($perSheet === 2) {
                    echo '<div class="cut"></div>';
                    echo $renderHalf($docs[$i + 1] ?? null);
                }
                echo '</div>';
            }
            ?>
        </div>
        <script>
            document.querySelectorAll('.soa-barcode').forEach(function (el) {
                try {
                    JsBarcode(el, el.dataset.code, {
                        format: 'CODE128', lineColor: '#000', width: 1.2, height: 30,
                        displayValue: true, fontSize: 8, margin: 0,
                    });
                } catch (e) { /* ignore */ }
            });
        </script>
    </body>
    </html>
    <?php
}
