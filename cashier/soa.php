<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/soa_service.php';
require_once __DIR__ . '/../includes/back_account_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user)) {
    flash_set('error', 'Only Cashier users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$cashierName = (string) ($user['full_name'] ?? 'Cashier');
$sy          = soa_active_school_year($connection);
$syLabel     = $sy['label'];
$syId        = $sy['id'];
$genYear     = (int) date('Y');

// ── Guard: has the Phase 2 migration been applied? ───────────────────────────
$schemaReady = soa_schema_ready($connection);

// ── AJAX: already-generated months for a single student (to disable chips) ────
if (isset($_GET['generated_ref'])) {
    header('Content-Type: application/json');
    $out = ['terms' => []];
    $ref = trim((string) $_GET['generated_ref']);
    if ($ref !== '' && $schemaReady) {
        $ens = soa_resolve_scope($connection, 'Student', $ref, $syLabel);
        if ($ens) {
            $aStmt = $connection->prepare('SELECT id FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1');
            $aStmt->bind_param('ii', $ens[0]['id'], $syId);
            $aStmt->execute();
            if ($a = stmt_fetch_assoc($aStmt)) {
                $out['terms']   = soa_generated_terms($connection, (int) $a['id']);
                $out['matched'] = count($ens);
            }
        }
    }
    echo json_encode($out);
    exit;
}

// ── Resolve the enrollment set for a given scope ─────────────────────────────
/** @return array<int,array{id:int,student_id:string}> */
function soa_resolve_scope(mysqli $db, string $scope, string $ref, string $syLabel): array
{
    $where  = ['en.school_year = ?'];
    $params = [$syLabel];
    $types  = 's';

    switch ($scope) {
        case 'Section':
            $where[]  = 'en.Department_section = ?';
            $params[] = $ref;
            $types   .= 's';
            break;
        case 'Grade':
            $where[]  = 'en.Department_gradelevel = ?';
            $params[] = (int) $ref;
            $types   .= 'i';
            break;
        case 'Dept':
            $where[]  = 'en.Department = ?';
            $params[] = $ref;
            $types   .= 's';
            break;
        case 'Student':
            $like     = '%' . $ref . '%';
            $where[]  = '(en.student_id = ? OR p.lrn LIKE ? OR osp.lrn LIKE ?'
                      . ' OR p.surname LIKE ? OR p.firstname LIKE ?'
                      . ' OR osp.surname LIKE ? OR osp.firstname LIKE ?)';
            array_push($params, $ref, $like, $like, $like, $like, $like, $like);
            $types   .= 'sssssss';
            break;
        case 'School':
        default:
            // SY filter only
            break;
    }

    $sql = 'SELECT en.id, en.student_id,
                   COALESCE(CONCAT(p.surname, p.firstname), CONCAT(osp.surname, osp.firstname)) AS sortname
            FROM enrollment en
            LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
            LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY sortname';
    $stmt = $db->prepare($sql);
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();

    $out = [];
    foreach (stmt_fetch_all_assoc($stmt) as $r) {
        $out[] = ['id' => (int) $r['id'], 'student_id' => (string) $r['student_id']];
    }
    return $out;
}

// ── POST: generate SOA(s) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('soa.php');
    }
    if (!$schemaReady) {
        flash_set('error', 'SOA tables are not installed yet. Run migrations/phase2_soa_system.sql first.');
        redirect_to('soa.php');
    }

    $scope  = trim((string) ($_POST['scope'] ?? 'Student'));
    $layout = ($_POST['layout'] ?? '2up') === '1up' ? '1up' : '2up';
    $terms  = array_values(array_filter(array_map('intval', (array) ($_POST['terms'] ?? []))));

    // Pick the ref for the chosen scope
    $refMap = [
        'Student' => trim((string) ($_POST['q'] ?? '')),
        'Section' => trim((string) ($_POST['section_id'] ?? '')),
        'Grade'   => trim((string) ($_POST['grade_id'] ?? '')),
        'Dept'    => trim((string) ($_POST['dept'] ?? '')),
        'School'  => $syLabel,
    ];
    $scopeRef = $refMap[$scope] ?? '';

    if ($scope === 'Student' && $scopeRef === '') {
        flash_set('error', 'Enter a Student ID, LRN, or name to search.');
        redirect_to('soa.php');
    }
    if (in_array($scope, ['Section', 'Grade', 'Dept'], true) && $scopeRef === '') {
        flash_set('error', 'Please choose a ' . strtolower($scope) . ' to generate for.');
        redirect_to('soa.php');
    }

    $enrollments = soa_resolve_scope($connection, $scope, $scopeRef, $syLabel);
    if ($enrollments === []) {
        flash_set('error', 'No enrolled students matched the selected scope for S.Y. ' . $syLabel . '.');
        redirect_to('soa.php');
    }

    $batchId = count($enrollments) > 1 ? ('B' . date('ymdHis') . random_int(100, 999)) : null;
    $soaIds  = [];

    $skippedStudents = 0;
    $connection->begin_transaction();
    try {
        foreach ($enrollments as $en) {
            $assessmentId = soa_ensure_assessment($connection, $en['id'], $syId, $cashierName);
            if ($assessmentId <= 0) {
                continue; // no fee profile resolvable — skip
            }
            // Never re-generate a month that already has an SOA document.
            $generated = soa_generated_terms($connection, $assessmentId);
            $schedule  = soa_get_schedule($connection, $assessmentId);
            if ($terms !== []) {
                // Only bill selected months that (a) aren't already generated
                // and (b) actually exist in this student's payment schedule.
                // Enrollment-fee-only students have no schedule, so there is
                // nothing to bill — skip them instead of emitting a blank ₱0
                // slip (which also spawned endless duplicate docs on re-runs).
                $want = array_values(array_diff($terms, $generated));
                $want = array_values(array_filter($want, static fn($t) => isset($schedule[$t])));
            } else {
                // Default = unpaid months, minus any already generated.
                $unpaid = [];
                foreach ($schedule as $tno => $row) {
                    if ($row['balance'] > 0) { $unpaid[] = $tno; }
                }
                $want = array_values(array_diff($unpaid, $generated));
            }
            if ($want === []) {
                $skippedStudents++; // nothing billable for the selected month(s)
                continue;
            }
            $newSoaId = soa_create_document(
                $connection,
                $assessmentId,
                $en['student_id'],
                $syId,
                $genYear,
                $scope,
                $scopeRef,
                $want,
                $cashierName,
                $batchId
            );
            if ($newSoaId > 0) {
                $soaIds[] = $newSoaId;
            } else {
                $skippedStudents++; // defensive: nothing billable, no doc created
            }
        }
        $connection->commit();
    } catch (Throwable $e) {
        $connection->rollback();
        flash_set('error', 'Failed to generate SOA: ' . $e->getMessage());
        redirect_to('soa.php');
    }

    if ($soaIds === []) {
        flash_set('error', $skippedStudents > 0
            ? 'Nothing to generate — the selected month(s) were already generated for the student(s).'
            : 'No SOA could be generated (students have no resolvable fee profile).');
        redirect_to('soa.php');
    }

    // PRG: stash ids and redirect to the print view
    $_SESSION['soa_print_ids']    = $soaIds;
    $_SESSION['soa_print_layout'] = $layout;
    flash_set('success', count($soaIds) . ' SOA document(s) generated'
        . ($skippedStudents > 0 ? ' — ' . $skippedStudents . ' student(s) skipped (months already generated).' : '.'));
    redirect_to('soa.php?print=1');
}

// ── Print mode: render SOA documents (fresh generation OR reprint by ids) ─────
// NOTE: the official-slip renderer below is mirrored in includes/soa_slip.php
// (soa_render_print_page), which the student portal uses to print the IDENTICAL
// document. Keep the two in sync if the slip layout changes.
$printMode = isset($_GET['print']);
if ($printMode) {
    $reqIds = trim((string) ($_GET['ids'] ?? ''));
    if ($reqIds !== '') {
        // Reprint: ids passed explicitly (from the management page)
        $printIds = array_values(array_filter(array_map('intval', explode(',', $reqIds)), static fn($v) => $v > 0));
        $layout   = (($_GET['layout'] ?? '2up') === '1up') ? '1up' : '2up';
    } else {
        // Fresh generation: ids stashed in session by the PRG redirect
        $printIds = array_map('intval', (array) ($_SESSION['soa_print_ids'] ?? []));
        $layout   = (($_SESSION['soa_print_layout'] ?? '2up') === '1up') ? '1up' : '2up';
        unset($_SESSION['soa_print_ids'], $_SESSION['soa_print_layout']);
    }
    if ($printIds === []) {
        flash_set('error', 'No SOA selected to print.');
        redirect_to('soa.php');
    }

    $idList = implode(',', array_filter($printIds, static fn($v) => $v > 0));
    $docs      = [];
    $details   = [];
    $fullSched = [];
    $charges   = [];
    $paid      = [];

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

        // Full installment schedule + upfront charge lines per assessment
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

            // Real enrollment-day payments from the existing cashier records,
            // aggregated per enrollment (admission/activity/books/house breakdown).
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

        // Monthly component split (Tuition / Misc / Improvement / Books) — derived
        // from the grade-tier schedule with the scholarship tuition discount applied.
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
            // Fallback for grades with no tier match
            $comp = soa_monthly_components(
                $connection, $dept, (string) ($doc['student_type'] ?? 'Old'),
                $monthly, (float) ($doc['pb_tuition'] ?? 0), (float) ($doc['pb_improvement'] ?? 0), $count
            );
        }
        $tuM = $comp['Tuition Fee'] ?? 0.0;
        $miM = $comp['Miscellaneous Fee'] ?? ($comp['Miscellaneous & Other'] ?? 0.0);
        $imM = $comp['School Improvement'] ?? 0.0;
        $bkM = $comp['Books / Materials'] ?? 0.0;

        // Paid-so-far (fractional months) from the installment ledger, and the
        // unpaid factor for the months selected on this SOA (paid months → 0).
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

        // Real enrollment-day payments (from the existing cashier records).
        $pd     = $paid[(int) ($doc['enrollment_id'] ?? 0)] ?? [];
        $pAdm   = (float) ($pd['p_adm']   ?? 0);
        $pAct   = (float) ($pd['p_act']   ?? 0);
        $pBk    = (float) ($pd['p_books'] ?? 0);
        $pHouse = (float) ($pd['p_house'] ?? 0);
        $pDate  = !empty($pd['p_date']) ? date('n/j/y', strtotime((string) $pd['p_date'])) : '';
        $pOr    = (string) ($pd['p_ors'] ?? '');
        $upD = static fn(float $p): string => $p > 0 ? $pDate : '';
        $upO = static fn(float $p): string => $p > 0 ? $pOr : '';

        // Assessed charges (official grade-tier fees)
        $reg    = (float) ($chg['admission'] ?? 0);
        $act    = (float) ($chg['activity'] ?? 0);
        $hou    = (float) ($chg['house'] ?? 0);
        $bkDown = $bkM * $count;   // book down-payment portion (paid at enrollment)

        // Build the official account-title rows.
        // [no, title, charges, paid, balance, breakdown, subitems, date, or]
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
            $bkPaid   = $pBk + $bkM * $monthsPaid;   // real down-payment + monthly portion
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

    // Pair docs into A4 sheets
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
            /* ── Official SOA slip ─────────────────────────────────────── */
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
            /* fee ledger table */
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
            <a class="btn btn-back" href="<?= h(app_url('cashier/soa.php')) ?>">← Back</a>
            <button class="btn btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        </div>
        <div class="sheets">
            <?php
            $total = count($docs);
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
    exit;
}

// ── Hub UI data ───────────────────────────────────────────────────────────────
$sections = [];
$grades   = [];
try {
    $secRes = $connection->query(
        "SELECT sc.Section_id, sc.Section_name, gl.Gradelevel
         FROM section sc LEFT JOIN gradelevel gl ON gl.Gradelevel_id = sc.Gradelevel_id
         ORDER BY gl.Gradelevel, sc.Section_name"
    );
    if ($secRes) {
        while ($r = $secRes->fetch_assoc()) {
            $sections[] = $r;
        }
    }
    $glRes = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    if ($glRes) {
        while ($r = $glRes->fetch_assoc()) {
            $grades[] = $r;
        }
    }
} catch (Throwable) {}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement of Account | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: {
                fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' }
            } }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Statement of Account</h1>
            <p class="text-slate-500 mt-2">Generate individual, section, grade-level, or batch SOA — 2 students per A4 sheet.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6 mb-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ SOA tables not installed</h2>
            <p class="text-sm text-slate-600">Run the Phase 2 migration once before using this module:</p>
            <pre class="mt-3 bg-slate-900 text-slate-100 text-xs rounded-xl p-4 overflow-x-auto">mysql -u root enrollment_db &lt; migrations/phase2_soa_system.sql</pre>
        </div>
        <?php endif; ?>

        <form method="POST" action="soa.php" class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate">

            <!-- Scope -->
            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Scope</p>
            <div class="grid sm:grid-cols-5 gap-2 mb-5" id="scopeCards">
                <?php foreach ([
                    ['Student','Individual'],
                    ['Section','By Section'],
                    ['Grade','By Grade'],
                    ['Dept','By Department'],
                    ['School','Whole School'],
                ] as $i => [$val,$lbl]): ?>
                <label class="cursor-pointer rounded-2xl border-2 px-4 py-3 text-center text-sm font-semibold transition-colors
                              <?= $i===0 ? 'border-green-600 bg-green-50 text-green-700' : 'border-slate-200 text-slate-600 hover:border-slate-400' ?>"
                       data-scope-card>
                    <input type="radio" name="scope" value="<?= h($val) ?>" class="hidden" <?= $i===0?'checked':'' ?>
                           onchange="onScopeChange()">
                    <?= h($lbl) ?>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Scope-specific inputs -->
            <div class="grid sm:grid-cols-2 gap-4 mb-5">
                <div data-scope-field="Student">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Student ID / LRN / Name</label>
                    <input type="text" name="q" placeholder="Search a student…"
                           class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
                <div data-scope-field="Section" style="display:none">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Section</label>
                    <select name="section_id" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">— choose section —</option>
                        <?php foreach ($sections as $s): ?>
                        <option value="<?= (int)$s['Section_id'] ?>">
                            <?= h(trim((string)($s['Gradelevel'] ?? ''))) ?> — <?= h((string)$s['Section_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div data-scope-field="Grade" style="display:none">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Grade Level</label>
                    <select name="grade_id" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">— choose grade —</option>
                        <?php foreach ($grades as $g): ?>
                        <option value="<?= (int)$g['Gradelevel_id'] ?>"><?= h(trim((string)$g['Gradelevel'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div data-scope-field="Dept" style="display:none">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Department</label>
                    <select name="dept" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">— choose department —</option>
                        <?php foreach (['Elementary','Junior High','Senior High'] as $d): ?>
                        <option value="<?= h($d) ?>"><?= h($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div data-scope-field="School" style="display:none" class="flex items-end">
                    <p class="text-sm text-slate-500">Generates an SOA for <strong>every enrolled student</strong> in S.Y. <?= h($syLabel) ?>.</p>
                </div>
            </div>

            <!-- Months -->
            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Installment months to include</p>
            <div class="flex flex-wrap gap-2 mb-2" id="termChips">
                <?php for ($t=1; $t<=10; $t++): ?>
                <label class="cursor-pointer">
                    <input type="checkbox" name="terms[]" value="<?= $t ?>" class="hidden peer" data-term>
                    <span class="inline-block rounded-xl border-2 border-slate-200 px-3.5 py-1.5 text-sm font-semibold text-slate-600
                                 peer-checked:border-green-600 peer-checked:bg-green-50 peer-checked:text-green-700">
                        M<?= $t ?>
                    </span>
                </label>
                <?php endfor; ?>
            </div>
            <div class="flex flex-wrap gap-2 mb-5 text-xs">
                <button type="button" onclick="quickTerms([1])" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-3 py-1.5 font-semibold">Month 1</button>
                <button type="button" onclick="quickTerms([1,2])" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-3 py-1.5 font-semibold">M1+M2</button>
                <button type="button" onclick="quickTerms([1,2,3])" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-3 py-1.5 font-semibold">M1+M2+M3</button>
                <button type="button" onclick="quickTerms('all')" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-3 py-1.5 font-semibold">All months</button>
                <button type="button" onclick="quickTerms([])" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-3 py-1.5 font-semibold">Clear</button>
                <span class="text-slate-400 self-center">Leave empty = all unpaid months.</span>
            </div>

            <!-- Layout -->
            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Layout</p>
            <div class="flex gap-3 mb-6">
                <label class="flex items-center gap-2.5 rounded-xl border-2 border-green-600 bg-green-50 px-4 py-2.5 cursor-pointer text-sm font-semibold text-green-700">
                    <input type="radio" name="layout" value="2up" checked class="accent-green-600"> 2 students / A4 (paper-saver)
                </label>
                <label class="flex items-center gap-2.5 rounded-xl border-2 border-slate-200 px-4 py-2.5 cursor-pointer text-sm font-semibold text-slate-600">
                    <input type="radio" name="layout" value="1up" class="accent-green-600"> 1 student / page
                </label>
            </div>

            <button type="submit" <?= $schemaReady ? '' : 'disabled' ?>
                    class="rounded-xl bg-green-700 hover:bg-green-800 disabled:opacity-40 disabled:cursor-not-allowed text-white px-6 py-3 text-sm font-bold transition-colors shadow-sm">
                Generate &amp; Print SOA
            </button>
        </form>

    </main>
</div>

<script>
function onScopeChange() {
    const scope = document.querySelector('[name="scope"]:checked').value;
    document.querySelectorAll('[data-scope-field]').forEach(el => {
        el.style.display = el.dataset.scopeField === scope ? '' : 'none';
    });
    document.querySelectorAll('[data-scope-card]').forEach(card => {
        const checked = card.querySelector('input').checked;
        card.classList.toggle('border-green-600', checked);
        card.classList.toggle('bg-green-50', checked);
        card.classList.toggle('text-green-700', checked);
        card.classList.toggle('border-slate-200', !checked);
        card.classList.toggle('text-slate-600', !checked);
    });
    checkGenerated();
}
function quickTerms(sel) {
    document.querySelectorAll('[data-term]').forEach(b => {
        const t = parseInt(b.value, 10);
        b.checked = b.disabled ? false : (sel === 'all' ? true : (Array.isArray(sel) && sel.includes(t)));
    });
}
// Disable month chips already generated for the entered student (Student scope).
function setChipDisabled(box, disabled) {
    box.disabled = disabled;
    if (disabled) { box.checked = false; }
    const label = box.closest('label');
    if (label) {
        label.style.opacity = disabled ? '0.35' : '';
        label.style.pointerEvents = disabled ? 'none' : '';
        label.title = disabled ? 'Already generated' : '';
    }
}
let _genTimer;
function checkGenerated() {
    const scope = document.querySelector('[name="scope"]:checked').value;
    const q = document.querySelector('[data-scope-field="Student"] input[name="q"]');
    const boxes = document.querySelectorAll('[data-term]');
    if (scope !== 'Student' || !q || q.value.trim() === '') {
        boxes.forEach(b => setChipDisabled(b, false));
        return;
    }
    fetch('soa.php?generated_ref=' + encodeURIComponent(q.value.trim()))
        .then(r => r.json())
        .then(d => {
            const gen = (d.terms || []).map(Number);
            boxes.forEach(b => setChipDisabled(b, gen.includes(parseInt(b.value, 10))));
        })
        .catch(() => {});
}
(function () {
    const q = document.querySelector('[data-scope-field="Student"] input[name="q"]');
    if (q) {
        q.addEventListener('input', () => { clearTimeout(_genTimer); _genTimer = setTimeout(checkGenerated, 500); });
        q.addEventListener('change', checkGenerated);
    }
})();
</script>
</body>
</html>
