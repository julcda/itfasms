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

// ── Active school year ────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
$activeTimelineYear    = (int) date('Y');
try {
    $syStmt = $connection->prepare(
        'SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow && !empty($syRow['School_year'])) {
        $activeSchoolYearLabel = (string) $syRow['School_year'];
        $parts                 = explode('-', $activeSchoolYearLabel);
        $firstYear             = isset($parts[0]) ? (int) trim($parts[0]) : 0;
        if ($firstYear > 0) {
            $activeTimelineYear = $firstYear;
        }
    }
} catch (Throwable) {
    // keep defaults
}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = $activeTimelineYear . '-' . ($activeTimelineYear + 1);
}

// ── POST: process payment ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('index.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action !== 'process_payment') {
            throw new RuntimeException('Invalid action.');
        }

        $enrollmentId  = to_int($_POST['enrollment_id'] ?? 0);
        $amountPaid    = (float) ($_POST['amount_paid'] ?? 0);
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Cash'));

        // Individual fee breakdown (editable by cashier in modal)
        $feeAdmission = (float) ($_POST['fee_admission'] ?? 0);
        $feeActivity  = (float) ($_POST['fee_activity']  ?? 0);
        $feeBooks     = (float) ($_POST['fee_books']     ?? 0);
        $feeHouseReg  = (float) ($_POST['fee_house_reg'] ?? 0);

        if ($enrollmentId <= 0) {
            throw new RuntimeException('Invalid enrollment record.');
        }

        if ($amountPaid <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $allowedMethods = ['Cash', 'Installment'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $paymentMethod = 'Cash';
        }

        // Fetch enrollment row – must be 'For Cashier Payment'
        $enrollStmt = $connection->prepare(
            'SELECT en.id, en.student_id, en.school_year, en.Department,
                    en.Department_gradelevel, en.Department_section, en.Status,
                    COALESCE(p.surname, osp.surname) AS surname,
                    COALESCE(p.firstname, osp.firstname) AS firstname,
                    IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
                    IFNULL(sc.Section_name, en.Department_section) AS section_name,
                    pb.description AS classification_desc,
                    pb.classification AS classification_name
             FROM enrollment en
             LEFT JOIN preregistration p ON en.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp
                    ON (p.id IS NULL AND osp.student_id = en.student_id)
             LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
             LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = en.Department_section
             LEFT JOIN payment_breakdown pb
                    ON pb.classification_id = en.Student_classification
                   AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
                   AND pb.status = \'Active\'
             WHERE en.id = ? AND en.Status = ?
             LIMIT 1'
        );
        $forCashier = 'For Cashier Payment';
        $enrollStmt->bind_param('is', $enrollmentId, $forCashier);
        $enrollStmt->execute();
        $enrollRow = stmt_fetch_assoc($enrollStmt);

        if (!$enrollRow) {
            throw new RuntimeException('Enrollment record not found or already processed.');
        }

        $studentId  = (string) ($enrollRow['student_id'] ?? '');
        $studentName = strtoupper(trim(
            ((string) ($enrollRow['surname'] ?? '')) . ', ' .
            ((string) ($enrollRow['firstname'] ?? ''))
        ));
        if ($studentName === ', ') {
            $studentName = $studentId;
        }

        // Record payment in backaccount_payment_records
        $tempOrNumber = 'ITFA-' . date('Y') . '-000000'; // placeholder; updated after insert_id is known
        $payStmt = $connection->prepare(
            'INSERT INTO backaccount_payment_records
             (student_id, name, payment_amount, or_number, payment_method, school_year, cashier_name, enrollment_id,
              fee_admission, fee_activity, fee_books, fee_house_reg)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $cashierFullName = (string) ($user['full_name'] ?? 'Cashier');
        $payStmt->bind_param(
            'ssdssssi' . 'dddd',
            $studentId, $studentName, $amountPaid,
            $tempOrNumber, $paymentMethod, $activeSchoolYearLabel, $cashierFullName, $enrollmentId,
            $feeAdmission, $feeActivity, $feeBooks, $feeHouseReg
        );
        $payStmt->execute();
        $paymentRecordId = $connection->insert_id;

        // Back-fill the real OR number now that we have the insert ID
        $orNumber = 'ITFA-' . date('Y') . '-' . str_pad((string) $paymentRecordId, 6, '0', STR_PAD_LEFT);
        $orStmt = $connection->prepare('UPDATE backaccount_payment_records SET or_number = ? WHERE id = ?');
        $orStmt->bind_param('si', $orNumber, $paymentRecordId);
        $orStmt->execute();

        // Move enrollment to registrar confirmation
        $updateStmt = $connection->prepare(
            'UPDATE enrollment SET Status = ? WHERE id = ? AND school_year = ?'
        );
        $nextStatus = 'For Registrar Confirmation';
        $updateStmt->bind_param('sis', $nextStatus, $enrollmentId, $activeSchoolYearLabel);
        $updateStmt->execute();

        // Store receipt data in session for display on receipt page
        $_SESSION['receipt_data'] = [
            'or_number'              => $orNumber,
            'payment_id'             => $paymentRecordId,
            'enrollment_id'          => $enrollmentId,
            'student_id'             => $studentId,
            'student_name'           => $studentName,
            'department'             => (string) ($enrollRow['Department'] ?? ''),
            'grade_level'            => trim((string) ($enrollRow['gradelevel_name'] ?? $enrollRow['Department_gradelevel'] ?? '')),
            'section'                => trim((string) ($enrollRow['section_name'] ?? $enrollRow['Department_section'] ?? '')),
            'classification'         => trim((string) ($enrollRow['classification_name'] ?? '')),
            'classification_desc'    => trim((string) ($enrollRow['classification_desc'] ?? '')),
            'school_year'            => $activeSchoolYearLabel,
            'amount_paid'            => $amountPaid,
            'payment_method'         => $paymentMethod,
            'payment_date'           => date('F d, Y'),
            'payment_time'           => date('h:i A'),
            'cashier_name'           => (string) ($user['full_name'] ?? 'Cashier'),
            'fee_admission'          => $feeAdmission,
            'fee_activity'           => $feeActivity,
            'fee_books'              => $feeBooks,
            'fee_house_reg'          => $feeHouseReg,
        ];

        redirect_to(app_url('cashier/receipt.php'));
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
        redirect_to('index.php');
    }
}

// ── Ensure new fee columns exist (auto-migrate) ───────────────────────────────
try {
    $connection->query(
        "ALTER TABLE `payment_breakdown`
         ADD COLUMN IF NOT EXISTS `activity_fee`       decimal(10,2) NOT NULL DEFAULT '0.00',
         ADD COLUMN IF NOT EXISTS `house_registration` decimal(10,2) NOT NULL DEFAULT '0.00'"
    );
} catch (Throwable) {
    // Older MariaDB/MySQL without IF NOT EXISTS — try each individually
    foreach (['activity_fee', 'house_registration'] as $_col) {
        $chk = $connection->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'payment_breakdown'
                AND COLUMN_NAME  = '{$_col}'"
        );
        if ($chk && (int) ($chk->fetch_assoc()['c'] ?? 0) === 0) {
            $connection->query(
                "ALTER TABLE `payment_breakdown` ADD COLUMN `{$_col}` decimal(10,2) NOT NULL DEFAULT '0.00'"
            );
        }
    }
}

// ── Ensure fee breakdown columns exist on payment records (auto-migrate) ───────
try {
    $connection->query(
        "ALTER TABLE `backaccount_payment_records`
         ADD COLUMN IF NOT EXISTS `fee_admission`     decimal(10,2) NOT NULL DEFAULT '0.00',
         ADD COLUMN IF NOT EXISTS `fee_activity`      decimal(10,2) NOT NULL DEFAULT '0.00',
         ADD COLUMN IF NOT EXISTS `fee_books`         decimal(10,2) NOT NULL DEFAULT '0.00',
         ADD COLUMN IF NOT EXISTS `fee_house_reg`     decimal(10,2) NOT NULL DEFAULT '0.00'"
    );
} catch (Throwable) {
    foreach (['fee_admission', 'fee_activity', 'fee_books', 'fee_house_reg'] as $_fcol) {
        $chk = $connection->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'backaccount_payment_records'
                AND COLUMN_NAME  = '{$_fcol}'"
        );
        if ($chk && (int) ($chk->fetch_assoc()['c'] ?? 0) === 0) {
            $connection->query(
                "ALTER TABLE `backaccount_payment_records` ADD COLUMN `{$_fcol}` decimal(10,2) NOT NULL DEFAULT '0.00'"
            );
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search     = trim((string) ($_GET['q'] ?? ''));
$deptFilter = trim((string) ($_GET['dept'] ?? ''));

$where  = ['en.Status = ?', 'en.school_year = ?'];
$params = ['For Cashier Payment', $activeSchoolYearLabel];
$types  = 'ss';

if ($search !== '') {
    $where[]  = '(p.surname LIKE ? OR p.firstname LIKE ? OR p.lrn LIKE ?'
              . ' OR osp.surname LIKE ? OR osp.firstname LIKE ? OR osp.lrn LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    $types   .= 'ssssss';
}

if ($deptFilter !== '') {
    $where[]  = 'en.Department = ?';
    $params[] = $deptFilter;
    $types   .= 's';
}

$sql = 'SELECT
            en.id, en.student_id, en.school_year, en.Department, en.Strand,
            en.Department_gradelevel, en.Department_section,
            en.Madrasah_gradelevel, en.Madrasah_section,
            en.Student_classification, en.Date_enrolled, en.Status,
            COALESCE(
                CONCAT(p.surname, \', \', p.firstname, \' \', IFNULL(p.middlename, \'\')),
                CONCAT(osp.surname, \', \', osp.firstname, \' \', IFNULL(osp.middlename, \'\'))
            ) AS full_name,
            COALESCE(p.lrn, osp.lrn) AS lrn,
            COALESCE(p.contact, osp.contact) AS contact,
            IF(p.id IS NOT NULL, \'New\', \'Old\') AS student_type,
            pb.classification_id     AS pb_classification_id,
            pb.classification        AS classification_name,
            pb.description           AS classification_desc,
            pb.rate                  AS fee_rate,
            pb.Enrollment            AS fee_enrollment,
            IFNULL(pb.activity_fee, 0)       AS fee_activity,
            IFNULL(pb.house_registration, 0) AS fee_house_reg,
            pb.Cash                  AS fee_cash,
            pb.Installment           AS fee_installment,
            pb.tuition               AS fee_tuition,
            pb.School_improvement    AS fee_school_improvement,
            IFNULL(bk.Book_Cost, 0)  AS book_cost,
            IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
            IFNULL(sc.Section_name, en.Department_section) AS section_name
        FROM enrollment en
        LEFT JOIN preregistration p ON en.student_id = CAST(p.id AS CHAR)
        LEFT JOIN old_studentprofile osp
               ON (p.id IS NULL AND osp.student_id = en.student_id)
        LEFT JOIN payment_breakdown pb
               ON pb.classification_id = en.Student_classification
              AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
              AND pb.status = \'Active\'
        LEFT JOIN payment_book bk
               ON bk.Gradelevel = CAST(en.Department_gradelevel AS CHAR)
              AND en.Department = \'Elementary\'
        LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
        LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = en.Department_section'
    . ' WHERE ' . implode(' AND ', $where)
    . ' ORDER BY en.id DESC';

$stmt = $connection->prepare($sql);
if ($types !== '') {
    bind_dynamic_params($stmt, $types, $params);
}
$stmt->execute();
$students = stmt_fetch_all_assoc($stmt);
$total    = count($students);

// Stats: today's payments
$todayPayments = 0;
$todayTotal    = 0.0;
try {
    $todayStmt = $connection->prepare(
        'SELECT COUNT(*) AS cnt, IFNULL(SUM(payment_amount), 0) AS total
         FROM backaccount_payment_records
         WHERE DATE(payment_date) = CURDATE()'
    );
    $todayStmt->execute();
    $todayRow      = stmt_fetch_assoc($todayStmt);
    $todayPayments = (int) ($todayRow['cnt'] ?? 0);
    $todayTotal    = (float) ($todayRow['total'] ?? 0);
} catch (Throwable) {
    // keep defaults
}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Queue | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: { 50:'#f0f7f2', 600:'#166534', 700:'#0f4d28' }
                    },
                    boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <!-- ── Sidebar ────────────────────────────────────────────── -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- ── Main content ───────────────────────────────────────── -->
    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
                    <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Payment Queue</h2>
                    <p class="text-slate-500 mt-2">Process enrollment payments for students cleared by the Enrollment module.</p>
                    <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; <?= h((string) ($user['full_name'] ?? 'Cashier')) ?></p>
                </div>
                <div class="grid grid-cols-3 gap-3 sm:flex-shrink-0">
                    <div class="rounded-2xl bg-green-50 border border-green-200 px-4 py-3 text-center">
                        <p class="text-xs text-green-700">Pending</p>
                        <p class="text-2xl font-extrabold text-green-800 mt-0.5"><?= h((string) $total) ?></p>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-center">
                        <p class="text-xs text-emerald-700">Today</p>
                        <p class="text-2xl font-extrabold text-emerald-800 mt-0.5"><?= h((string) $todayPayments) ?></p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 border border-slate-200 px-4 py-3 text-center">
                        <p class="text-xs text-slate-500">Collected</p>
                        <p class="text-lg font-extrabold text-slate-700 mt-0.5">₱<?= number_format($todayTotal, 0) ?></p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
            <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium flex items-center gap-3
                <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Search & Filter -->
        <form method="GET" action="" class="mb-4 flex flex-wrap gap-3">
            <input
                type="text"
                name="q"
                value="<?= h($search) ?>"
                placeholder="Search by name or LRN…"
                class="flex-1 min-w-[200px] rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
            >
            <select name="dept" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                <option value="">All Departments</option>
                <?php foreach (['Elementary', 'Junior High', 'Senior High'] as $dept): ?>
                    <option value="<?= h($dept) ?>" <?= $deptFilter === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rounded-xl bg-green-700 hover:bg-green-800 text-white px-4 py-2.5 text-sm font-semibold transition-colors">Search</button>
            <?php if ($search !== '' || $deptFilter !== ''): ?>
                <a href="index.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Students Table -->
        <section class="rounded-3xl border border-slate-100 bg-white shadow-panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50/80 text-xs uppercase tracking-wider text-slate-400 font-semibold">
                            <th class="px-5 py-3.5 text-left">Student</th>
                            <th class="px-5 py-3.5 text-left">LRN / ID</th>
                            <th class="px-5 py-3.5 text-left">Department</th>
                            <th class="px-5 py-3.5 text-left">Grade / Section</th>
                            <th class="px-5 py-3.5 text-left">Classification</th>
                            <th class="px-5 py-3.5 text-right">Enrollment Fee</th>
                            <th class="px-5 py-3.5 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php if ($students === []): ?>
                            <tr>
                                <td colspan="7" class="px-5 py-16 text-center text-slate-400">
                                    <svg class="w-12 h-12 mx-auto mb-3 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                    </svg>
                                    <p class="font-semibold text-slate-500">No students pending payment<?= ($search !== '' || $deptFilter !== '') ? ' for the current filter.' : ' for this school year.' ?></p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($students as $row): ?>
                            <?php
                                $name      = trim((string) ($row['full_name'] ?? '')) ?: ('ID: ' . $row['student_id']);
                                $lrn       = trim((string) ($row['lrn'] ?? '-'));
                                $dept      = (string) ($row['Department'] ?? '-');
                                $grade     = trim((string) ($row['gradelevel_name'] ?? $row['Department_gradelevel'] ?? '-'));
                                $section   = trim((string) ($row['section_name'] ?? $row['Department_section'] ?? '-'));
                                $classificationName = trim((string) ($row['classification_name'] ?? ''));
                                $classTxt  = trim((string) ($row['classification_desc'] ?? 'N/A'));
                                $feeRate      = (float) ($row['fee_rate'] ?? 0);
                                $feeEnroll    = (float) ($row['fee_enrollment'] ?? 0);
                                $feeActivity  = (float) ($row['fee_activity'] ?? 0);
                                $feeHouseReg  = (float) ($row['fee_house_reg'] ?? 0);
                                $feeCash      = (float) ($row['fee_cash'] ?? 0);
                                $feeInst      = (float) ($row['fee_installment'] ?? 0);
                                $feeTuition   = (float) ($row['fee_tuition'] ?? 0);
                                $feeSchoolImp = (float) ($row['fee_school_improvement'] ?? 0);
                                $bookCost     = (float) ($row['book_cost'] ?? 0);
                                $type         = (string) ($row['student_type'] ?? 'New');
                                $encId        = (int) $row['id'];
                                $isElem       = $dept === 'Elementary';
                                $enrollmentDue = $feeEnroll + $feeActivity + $feeHouseReg + ($isElem ? $bookCost : 0);
                                $displayFee   = $enrollmentDue;
                            ?>
                            <tr class="hover:bg-green-50/40 transition-colors group">
                                <td class="px-5 py-3.5 font-semibold text-slate-800"><?= h($name) ?></td>
                                <td class="px-5 py-3.5 text-slate-400 font-mono text-xs"><?= h($lrn) ?></td>
                                <td class="px-5 py-3.5 text-slate-600"><?= h($dept) ?></td>
                                <td class="px-5 py-3.5 text-slate-600">
                                    <?= h($grade) ?>
                                    <?= $section !== '' && $section !== '-' ? '<span class="text-slate-400"> – </span>' . h($section) : '' ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $type === 'New' ? 'bg-emerald-100 text-emerald-700' : 'bg-green-100 text-green-700' ?>">
                                            <?= h($type) ?>
                                        </span>
                                        <span class="text-slate-500 text-xs"><?= h($classTxt ?: 'N/A') ?></span>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <?php if ($enrollmentDue > 0): ?>
                                        <p class="font-bold text-green-700 text-sm">₱<?= h(number_format($enrollmentDue, 2)) ?></p>
                                        <p class="text-xs text-slate-400 font-normal mt-0.5">Due at enrollment</p>
                                        <?php if ($isElem && $bookCost > 0): ?>
                                            <p class="text-xs text-slate-400 font-normal">incl. books ₱<?= h(number_format($bookCost, 2)) ?></p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400 font-normal">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <button
                                        type="button"
                                        data-enrollment-id="<?= $encId ?>"
                                        data-name="<?= h($name) ?>"
                                        data-dept="<?= h($dept) ?>"
                                        data-section="<?= h($grade) ?><?= $section !== '-' ? ' – ' . h($section) : '' ?>"
                                        data-classification="<?= h($classTxt ?: 'N/A') ?>"
                                        data-classification-name="<?= h($classificationName ?: $classTxt) ?>"
                                        data-type="<?= h($type) ?>"
                                        data-rate="<?= $feeRate ?>"
                                        data-fee-enroll="<?= $feeEnroll ?>"
                                        data-fee-activity="<?= $feeActivity ?>"
                                        data-fee-house-reg="<?= $feeHouseReg ?>"
                                        data-fee-cash="<?= $feeCash ?>"
                                        data-fee-inst="<?= $feeInst ?>"
                                        data-fee-tuition="<?= $feeTuition ?>"
                                        data-fee-school-imp="<?= $feeSchoolImp ?>"
                                        data-book-cost="<?= $bookCost ?>"
                                        data-is-elementary="<?= $isElem ? '1' : '0' ?>"
                                        data-enrollment-due="<?= $enrollmentDue ?>"
                                        onclick="openPaymentModal(this)"
                                        class="inline-flex items-center gap-1.5 rounded-xl bg-green-700 hover:bg-green-800 text-white px-3.5 py-1.5 text-xs font-semibold transition-colors opacity-80 group-hover:opacity-100">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                        </svg>
                                        Process Payment
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!empty($students)): ?>
                <div class="px-5 py-3 border-t border-slate-50 bg-slate-50/50 text-xs text-slate-400">
                    <?= count($students) ?> student<?= count($students) !== 1 ? 's' : '' ?> pending payment
                </div>
                <?php endif; ?>
            </div>
        </section>

    </main>
</div>

<!-- ── Payment Modal ──────────────────────────────────────────────────────── -->
<div id="paymentModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto flex flex-col">

        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white rounded-t-3xl z-10">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-green-700 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4.5 h-4.5 text-white w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-widest text-green-600 font-semibold">Cashier · Enrollment</p>
                    <h3 class="text-base font-extrabold leading-tight">Process Enrollment Payment</h3>
                </div>
            </div>
            <button type="button" onclick="closePaymentModal()"
                    class="w-8 h-8 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors text-xl leading-none">&times;</button>
        </div>

        <form method="POST" action="<?= h(app_url('cashier/index.php')) ?>" class="flex flex-col flex-1">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="process_payment">
            <input type="hidden" name="enrollment_id" id="modalEnrollmentId" value="">

            <div class="p-6 space-y-5 flex-1">

                <!-- Student Info Card -->
                <div class="rounded-2xl bg-green-700 text-white p-4">
                    <p class="text-xs font-semibold text-green-300 uppercase tracking-widest mb-1">Student</p>
                    <p class="font-extrabold text-xl leading-tight" id="modalStudentName">—</p>
                    <div class="flex flex-wrap gap-2 mt-2.5">
                        <span class="text-xs bg-white/15 border border-white/20 rounded-full px-3 py-0.5 font-medium" id="modalStudentDept">—</span>
                        <span class="text-xs bg-white/15 border border-white/20 rounded-full px-3 py-0.5" id="modalStudentSection">—</span>
                        <span class="text-xs bg-white/15 border border-white/20 rounded-full px-3 py-0.5" id="modalStudentType">—</span>
                    </div>
                    <p class="text-xs text-green-300 mt-2" id="modalClassification">—</p>
                </div>

                <!-- Enrollment Day Payment Breakdown -->
                <div class="rounded-2xl border-2 border-green-200 overflow-hidden">
                    <div class="bg-green-50 px-4 py-2.5 flex items-center justify-between border-b border-green-200">
                        <p class="text-xs font-bold text-green-900 uppercase tracking-wider">Enrollment Day Payment</p>
                        <span class="text-xs text-green-600 font-medium">Collect from student today</span>
                    </div>
                    <div class="p-4 space-y-3 bg-white">
                        <p class="text-xs text-slate-400 italic">Amounts are auto-filled from the fee schedule. You may adjust any field before confirming.</p>

                        <!-- Admission / Registration -->
                        <div class="flex items-center justify-between gap-3">
                            <label for="inputAdmission" class="text-sm text-slate-600 font-medium shrink-0">Admission / Registration Fee</label>
                            <div class="relative w-40">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold">₱</span>
                                <input type="number" id="inputAdmission" name="fee_admission"
                                       min="0" step="0.01" value="0"
                                       oninput="recalcTotal()"
                                       class="w-full rounded-xl border border-slate-200 pl-7 pr-3 py-1.5 text-right font-semibold text-slate-800 text-sm focus:outline-none focus:border-green-400 focus:ring-1 focus:ring-green-200">
                            </div>
                        </div>

                        <!-- Activity Fees -->
                        <div id="activityFeeGroup">
                            <div class="flex items-center justify-between gap-3">
                                <label for="inputActivity" class="text-sm text-slate-600 font-medium shrink-0">Activity Fees</label>
                                <div class="relative w-40">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold">₱</span>
                                    <input type="number" id="inputActivity" name="fee_activity"
                                           min="0" step="0.01" value="0"
                                           oninput="recalcTotal()"
                                           class="w-full rounded-xl border border-slate-200 pl-7 pr-3 py-1.5 text-right font-semibold text-slate-800 text-sm focus:outline-none focus:border-green-400 focus:ring-1 focus:ring-green-200">
                                </div>
                            </div>
                            <div id="activityFeeRows" class="mt-1.5 ml-1 pl-3 border-l-2 border-green-100 space-y-0.5 text-xs text-slate-400"></div>
                        </div>

                        <!-- Books (Elementary only) -->
                        <div id="bookFeeGroup" style="display:none">
                            <div class="flex items-center justify-between gap-3">
                                <label for="inputBooks" class="text-sm text-slate-600 font-medium shrink-0">Books (enrollment down-payment)</label>
                                <div class="relative w-40">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold">₱</span>
                                    <input type="number" id="inputBooks" name="fee_books"
                                           min="0" step="0.01" value="0"
                                           oninput="recalcTotal()"
                                           class="w-full rounded-xl border border-amber-200 pl-7 pr-3 py-1.5 text-right font-semibold text-amber-900 text-sm focus:outline-none focus:border-amber-400 focus:ring-1 focus:ring-amber-200">
                                </div>
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5 ml-1">Remaining balance spread over monthly installments</p>
                        </div>

                        <!-- House Registration -->
                        <div id="houseRegGroup" class="flex items-center justify-between gap-3">
                            <label for="inputHouseReg" class="text-sm text-slate-600 font-medium shrink-0">House Registration</label>
                            <div class="relative w-40">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold">₱</span>
                                <input type="number" id="inputHouseReg" name="fee_house_reg"
                                       min="0" step="0.01" value="0"
                                       oninput="recalcTotal()"
                                       class="w-full rounded-xl border border-slate-200 pl-7 pr-3 py-1.5 text-right font-semibold text-slate-800 text-sm focus:outline-none focus:border-green-400 focus:ring-1 focus:ring-green-200">
                            </div>
                        </div>

                        <!-- Total Due -->
                        <div class="border-t-2 border-green-600 pt-3 mt-1 flex items-center justify-between">
                            <span class="font-bold text-green-700 text-sm uppercase tracking-wide">Total Due Today</span>
                            <span class="text-2xl font-extrabold text-green-700" id="totalDueDisplay">₱0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Annual Payment Options (reference) -->
                <div class="rounded-2xl border border-slate-200 bg-slate-50 overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-200">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Annual Payment Plan Reference</p>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-center">
                            <p class="text-xs text-emerald-700 font-semibold">Full Payment (Cash)</p>
                            <p class="font-extrabold text-emerald-900 text-lg mt-1" id="feeCashDisplay">₱0.00</p>
                            <p class="text-xs text-emerald-600 mt-0.5">One-time annual fee</p>
                        </div>
                        <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-center">
                            <p class="text-xs text-amber-700 font-semibold">Monthly Installment</p>
                            <p class="font-extrabold text-amber-900 text-lg mt-1" id="feeInstDisplay">₱0.00</p>
                            <p class="text-xs text-amber-600 mt-0.5">per month × 10 months</p>
                        </div>
                    </div>
                </div>

                <!-- Payment Method -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Payment Method</label>
                    <div class="grid grid-cols-2 gap-3" id="methodCards">
                        <label class="flex items-center gap-3 border-2 border-green-600 bg-green-50 rounded-2xl p-3 cursor-pointer">
                            <input type="radio" name="payment_method" value="Cash" checked
                                   class="accent-green-600 w-4 h-4" onchange="prefillAmount()">
                            <div>
                                <p class="text-sm font-bold text-green-700">Cash (Full Payment)</p>
                                <p class="text-xs text-slate-500">Pay entire annual fee now</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 border-2 border-slate-200 rounded-2xl p-3 cursor-pointer hover:border-slate-400 transition-colors">
                            <input type="radio" name="payment_method" value="Installment"
                                   class="accent-green-600 w-4 h-4" onchange="prefillAmount()">
                            <div>
                                <p class="text-sm font-bold text-slate-700">Installment</p>
                                <p class="text-xs text-slate-500">Monthly payments over 10 months</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Amount Paid -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Amount Received (₱)</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-700 font-bold text-lg">₱</span>
                        <input type="number" name="amount_paid" id="modalAmountPaid"
                               min="0.01" step="0.01" required
                               class="w-full rounded-2xl border-2 border-slate-200 pl-10 pr-4 py-3 text-xl font-bold text-green-700 focus:outline-none focus:border-green-600 focus:ring-0 transition-colors"
                               placeholder="0.00">
                    </div>
                    <p class="text-xs text-slate-400 mt-1.5">Enter the exact amount received from the student. Adjust fee fields above if needed. A receipt will be generated.</p>
                </div>

            </div><!-- /p-6 -->

            <!-- Modal Footer -->
            <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-end gap-3 sticky bottom-0 bg-white rounded-b-3xl">
                <button type="button" onclick="closePaymentModal()"
                        class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="rounded-xl bg-green-700 hover:bg-green-800 text-white px-6 py-2.5 text-sm font-bold transition-colors shadow-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    Confirm &amp; Record Payment
                </button>
            </div>

        </form>
    </div>
</div>

<script>
let _feeCash = 0, _feeInst = 0, _isElem = false, _isJHS = false;

function fmt(n) {
    const v = parseFloat(n) || 0;
    return '₱' + v.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function getActivityItems(total) {
    if (total <= 0) return [];
    const items = [
        ['Athletic Fee', 200.00],
        ['TSC Fee (Student Council)', 120.00],
        ['School Publication Fee', 100.00],
    ];
    if (_isJHS) items.push(['Madrasah Registration (Tarbiyah)', 70.00]);
    return items;
}

function getInputVal(id) {
    return parseFloat(document.getElementById(id)?.value) || 0;
}

function recalcTotal() {
    const admission = getInputVal('inputAdmission');
    const activity  = getInputVal('inputActivity');
    const books     = _isElem ? getInputVal('inputBooks') : 0;
    const houseReg  = getInputVal('inputHouseReg');
    const total = admission + activity + books + houseReg;
    document.getElementById('totalDueDisplay').textContent = fmt(total);
    // Keep amount_paid in sync only if it matches the previous total
    const amtField = document.getElementById('modalAmountPaid');
    amtField.value = total > 0 ? total.toFixed(2) : '';
    // Update activity sub-breakdown when activity input changes
    const actRows = document.getElementById('activityFeeRows');
    if (actRows) {
        const items = getActivityItems(activity);
        actRows.innerHTML = items.map(([label, val]) =>
            `<div class="flex justify-between"><span>${label}</span><span>₱${val.toFixed(2)}</span></div>`
        ).join('');
    }
}

function openPaymentModal(btn) {
    const d = btn.dataset;
    const feeEnroll   = parseFloat(d.feeEnroll)   || 0;
    _feeCash          = parseFloat(d.feeCash)      || 0;
    _feeInst          = parseFloat(d.feeInst)      || 0;
    const bookCost    = parseFloat(d.bookCost)     || 0;
    _isElem           = d.isElementary === '1';
    const isSHS       = (d.dept || '').toLowerCase().includes('senior');
    _isJHS             = (d.dept || '').toLowerCase().includes('junior');

    // Use DB value if populated; fall back to schedule defaults when 0
    // JHS: 490 (includes Madrasah/Tarbiyah); Elementary & SHS: 420 (no Madrasah)
    const activityFee = parseFloat(d.feeActivity) || (_isJHS ? 490 : 420);
    const houseReg    = parseFloat(d.feeHouseReg) || 250;

    const rate               = parseFloat(d.rate) || 0;
    const classificationName = d.classificationName || d.classification || 'N/A';

    // Student info
    document.getElementById('modalEnrollmentId').value         = d.enrollmentId;
    document.getElementById('modalStudentName').textContent    = d.name;
    document.getElementById('modalStudentDept').textContent    = d.dept + ' Department';
    document.getElementById('modalStudentSection').textContent = d.section;
    document.getElementById('modalStudentType').textContent    = d.type + ' Student';

    const rateLabel = rate > 0 && rate < 1
        ? ` — ${Math.round(rate * 100)}% Discount`
        : (rate >= 1 ? ' — Full Scholarship' : '');
    document.getElementById('modalClassification').textContent =
        classificationName + rateLabel;

    // Pre-fill editable fee inputs from fee schedule / grade level
    document.getElementById('inputAdmission').value = feeEnroll  > 0 ? feeEnroll.toFixed(2)  : '0.00';
    document.getElementById('inputActivity').value  = activityFee > 0 ? activityFee.toFixed(2) : '0.00';
    document.getElementById('inputHouseReg').value  = houseReg    > 0 ? houseReg.toFixed(2)    : '0.00';

    // Activity sub-breakdown
    document.getElementById('activityFeeRows').innerHTML = getActivityItems(activityFee).map(([label, val]) =>
        `<div class="flex justify-between"><span>${label}</span><span>₱${val.toFixed(2)}</span></div>`
    ).join('');
    document.getElementById('activityFeeGroup').style.display = '';

    // Books (elementary only)
    const bookGroup = document.getElementById('bookFeeGroup');
    if (_isElem) {
        document.getElementById('inputBooks').value = bookCost > 0 ? bookCost.toFixed(2) : '0.00';
        bookGroup.style.display = '';
    } else {
        document.getElementById('inputBooks').value = '0.00';
        bookGroup.style.display = 'none';
    }

    // Annual payment reference
    document.getElementById('feeCashDisplay').textContent = fmt(_feeCash);
    document.getElementById('feeInstDisplay').textContent = fmt(_feeInst);

    // Compute initial total & pre-fill amount received
    recalcTotal();

    // Default method: Cash
    document.querySelectorAll('[name="payment_method"]').forEach(r => { r.checked = r.value === 'Cash'; });
    styleMethodCards();

    document.getElementById('paymentModal').style.display = 'flex';
    setTimeout(() => document.getElementById('modalAmountPaid').focus(), 80);
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function styleMethodCards() {
    document.querySelectorAll('#methodCards label').forEach(lbl => {
        const inp = lbl.querySelector('input');
        if (inp.checked) {
            lbl.classList.add('border-green-600', 'bg-green-50');
            lbl.classList.remove('border-slate-200');
        } else {
            lbl.classList.remove('border-green-600', 'bg-green-50');
            lbl.classList.add('border-slate-200');
        }
    });
}

function prefillAmount() {
    styleMethodCards();
    recalcTotal();
}

document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) closePaymentModal();
});
</script>
</body>
</html>
