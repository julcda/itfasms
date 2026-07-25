<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php-error.log');
error_reporting(E_ALL);

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
try {
    $syStmt = $connection->prepare('SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1');
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow) $activeSchoolYearLabel = (string) ($syRow['School_year'] ?? '');
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── Ensure new fee columns exist (auto-migrate) ───────────────────────────────
try {
    $connection->query(
        "ALTER TABLE `payment_breakdown`
         ADD COLUMN IF NOT EXISTS `activity_fee`       decimal(10,2) NOT NULL DEFAULT '0.00',
         ADD COLUMN IF NOT EXISTS `house_registration` decimal(10,2) NOT NULL DEFAULT '0.00'"
    );
} catch (Throwable) {
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

// ── Generate 10-month schedule helper ─────────────────────────────────────────
function generate_schedule(string $schoolYear, int $months = 10): array
{
    $parts = explode('-', $schoolYear);
    $yr    = (int) ($parts[0] ?? (int) date('Y'));
    $mo    = 8;
    $rows  = [];
    for ($i = 0; $i < $months; $i++) {
        $ts     = mktime(0, 0, 0, $mo, 1, $yr);
        $rows[] = [
            'month_label'   => date('F Y', $ts),
            'payment_month' => $mo,
            'payment_year'  => $yr,
            'due_date'      => date('Y-m-t', $ts),
        ];
        $mo++;
        if ($mo > 12) { $mo = 1; $yr++; }
    }
    return $rows;
}

// ── POST: create account ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('account_setup.php');
    }

    if (trim((string) ($_POST['action'] ?? '')) === 'setup_account') {
        try {
            $enrollmentId      = to_int($_POST['enrollment_id'] ?? 0);
            $paymentMethod     = trim((string) ($_POST['payment_method'] ?? 'Installment'));
            $installmentMonths = min(12, max(1, to_int($_POST['installment_months'] ?? 10)));

            if ($enrollmentId <= 0) throw new RuntimeException('Invalid enrollment record.');
            if (!in_array($paymentMethod, ['Cash', 'Installment'], true)) $paymentMethod = 'Installment';

            // Guard: no duplicate account
            $chk = $connection->prepare('SELECT id FROM student_account WHERE enrollment_id = ? LIMIT 1');
            $chk->bind_param('i', $enrollmentId);
            $chk->execute();
            if (stmt_fetch_assoc($chk)) {
                throw new RuntimeException('An account already exists for this enrollment.');
            }

            // Fetch enrollment + fee breakdown
            $enStmt = $connection->prepare(
                'SELECT en.id, en.student_id, en.school_year,
                        COALESCE(p.surname, osp.surname) AS surname,
                        COALESCE(p.firstname, osp.firstname) AS firstname,
                        IFNULL(pb.tuition, 0)            AS fee_tuition,
                        IFNULL(pb.School_improvement, 0) AS fee_improvement,
                        IFNULL(pb.Enrollment, 0)         AS fee_enrollment,
                        IFNULL(pb.Cash, 0)               AS fee_cash,
                        IFNULL(pb.Installment, 0)        AS fee_installment
                 FROM enrollment en
                 LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
                 LEFT JOIN old_studentprofile osp  ON (p.id IS NULL AND osp.student_id = en.student_id)
                 LEFT JOIN payment_breakdown pb
                        ON pb.classification_id = en.Student_classification
                       AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
                       AND pb.status = \'Active\'
                 WHERE en.id = ? LIMIT 1'
            );
            $enStmt->bind_param('i', $enrollmentId);
            $enStmt->execute();
            $en = stmt_fetch_assoc($enStmt);
            if (!$en) throw new RuntimeException('Enrollment record not found.');

            $studentId      = (string) ($en['student_id'] ?? '');
            $syLabel        = (string) ($en['school_year'] ?? $activeSchoolYearLabel);
            $tuitionFee     = (float) ($en['fee_tuition']     ?? 0);
            $improvementFee = (float) ($en['fee_improvement'] ?? 0);
            $enrollmentFee  = (float) ($en['fee_enrollment']  ?? 0);
            $monthlyAmount  = $paymentMethod === 'Cash' ? 0.0 : (float) ($en['fee_installment'] ?? 0);
            $totalFee       = $paymentMethod === 'Cash'
                            ? (float) ($en['fee_cash'] ?? 0)
                            : $monthlyAmount * $installmentMonths;
            $balance        = $totalFee;

            // INSERT student_account (types: isssdddddid — 11 params)
            $insAcct = $connection->prepare(
                'INSERT INTO student_account
                 (enrollment_id, student_id, school_year, payment_method,
                  tuition_fee, school_improvement, enrollment_fee, total_fee,
                  monthly_amount, installment_months, total_paid, balance, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, \'Active\')'
            );
            $insAcct->bind_param(
                'isssdddddid',
                $enrollmentId, $studentId, $syLabel, $paymentMethod,
                $tuitionFee, $improvementFee, $enrollmentFee, $totalFee,
                $monthlyAmount, $installmentMonths, $balance
            );
            $insAcct->execute();
            $accountId = (int) $connection->insert_id;

            // INSERT monthly_payment rows (Installment only)
            if ($paymentMethod === 'Installment') {
                $schedule = generate_schedule($syLabel, $installmentMonths);
                $insMo    = $connection->prepare(
                    'INSERT INTO monthly_payment
                     (student_account_id, enrollment_id, student_id, school_year,
                      month_label, payment_month, payment_year, due_date, amount_due, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'Unpaid\')'
                );
                foreach ($schedule as $slot) {
                    $ml = $slot['month_label'];
                    $pm = $slot['payment_month'];
                    $py = $slot['payment_year'];
                    $dd = $slot['due_date'];
                    // types: iisssiisd (9 params)
                    $insMo->bind_param('iisssiisd', $accountId, $enrollmentId, $studentId, $syLabel, $ml, $pm, $py, $dd, $monthlyAmount);
                    $insMo->execute();
                }
            }

            $studentName = strtoupper(trim(($en['surname'] ?? '') . ', ' . ($en['firstname'] ?? '')));
            flash_set('success', "Account created for {$studentName}. Schedule generated ({$installmentMonths} months).");
            redirect_to('account_setup.php');

        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
            redirect_to('account_setup.php');
        }
    }
}

// ── GET: student for setup form ───────────────────────────────────────────────
$setupId  = to_int($_GET['setup'] ?? 0);
$setupRow = null;
if ($setupId > 0) {
    $sfStmt = $connection->prepare(
        'SELECT en.id, en.student_id, en.school_year, en.Department,
                COALESCE(p.surname, osp.surname) AS surname,
                COALESCE(p.firstname, osp.firstname) AS firstname,
                IF(p.id IS NOT NULL, \'New\', \'Old\') AS student_type,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name,
                pb.description AS classification_desc,
                IFNULL(pb.tuition, 0)            AS fee_tuition,
                IFNULL(pb.School_improvement, 0) AS fee_improvement,
                IFNULL(pb.Enrollment, 0)         AS fee_enrollment,
                IFNULL(pb.activity_fee, 0)       AS fee_activity,
                IFNULL(pb.house_registration, 0) AS fee_house_reg,
                IFNULL(pb.activity_fee, 0)       AS fee_activity,
                IFNULL(pb.house_registration, 0) AS fee_house_reg,
                IFNULL(pb.Cash, 0)               AS fee_cash,
                IFNULL(pb.Installment, 0)        AS fee_installment,
                sa.id AS existing_account_id
         FROM enrollment en
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp  ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN payment_breakdown pb
                ON pb.classification_id = en.Student_classification
               AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
               AND pb.status = \'Active\'
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         LEFT JOIN student_account sa ON sa.enrollment_id = en.id
         WHERE en.id = ? LIMIT 1'
    );
    $sfStmt->bind_param('i', $setupId);
    $sfStmt->execute();
    $setupRow = stmt_fetch_assoc($sfStmt);
}

// ── Eligible students list ────────────────────────────────────────────────────
$search   = trim((string) ($_GET['q']  ?? ''));
$syFilter = trim((string) ($_GET['sy'] ?? $activeSchoolYearLabel));

$where  = ['sa.id IS NULL', 'en.school_year = ?'];
$params = [$syFilter];
$types  = 's';

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where[] = '(p.surname LIKE ? OR p.firstname LIKE ? OR osp.surname LIKE ? OR osp.firstname LIKE ? OR en.student_id LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
    $types  .= 'sssss';
}

$listStmt = $connection->prepare(
    'SELECT en.id, en.student_id, en.Department, en.school_year, en.Status,
            COALESCE(CONCAT(p.surname, \', \', p.firstname), CONCAT(osp.surname, \', \', osp.firstname)) AS full_name,
            IF(p.id IS NOT NULL, \'New\', \'Old\') AS student_type,
            IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
            IFNULL(sc.Section_name, en.Department_section) AS section_name,
            pb.description AS classification_desc,
            IFNULL(pb.Cash, 0) AS fee_cash,
            IFNULL(pb.Installment, 0) AS fee_installment
     FROM enrollment en
     LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
     LEFT JOIN old_studentprofile osp  ON (p.id IS NULL AND osp.student_id = en.student_id)
     LEFT JOIN payment_breakdown pb
            ON pb.classification_id = en.Student_classification
           AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
           AND pb.status = \'Active\'
     LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
     LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
     LEFT JOIN student_account sa ON sa.enrollment_id = en.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY en.id DESC'
);
bind_dynamic_params($listStmt, $types, $params);
$listStmt->execute();
$eligibleStudents = stmt_fetch_all_assoc($listStmt);

$totalAccounts = 0;
try {
    $stSt = $connection->prepare('SELECT COUNT(*) AS cnt FROM student_account WHERE school_year = ?');
    $stSt->bind_param('s', $activeSchoolYearLabel);
    $stSt->execute();
    $totalAccounts = (int) (stmt_fetch_assoc($stSt)['cnt'] ?? 0);
} catch (Throwable) {}

$previewSchedule = $setupRow
    ? generate_schedule((string) ($setupRow['school_year'] ?? $activeSchoolYearLabel), 10)
    : [];

$flash = flash_get();

// Extract setup-form vars for JS
$sfInst = $setupRow ? (float) ($setupRow['fee_installment'] ?? 0) : 0.0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Setup | ITFA Cashier</title>
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

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium flex items-center gap-3
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Account Setup</h1>
            <p class="text-slate-500 mt-2">Create student financial accounts and generate monthly payment schedules.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; <?= h((string) ($user['full_name'] ?? 'Cashier')) ?></p>
        </header>

        <!-- Stats -->
        <div class="grid gap-4 sm:grid-cols-3 mb-6">
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
                <p class="text-xs text-slate-400 font-medium">Accounts Created</p>
                <p class="text-3xl font-extrabold text-green-700 mt-2"><?= $totalAccounts ?></p>
                <p class="text-xs text-slate-400 mt-1">S.Y. <?= h($activeSchoolYearLabel) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-amber-200 p-5 shadow-sm">
                <p class="text-xs text-amber-600 font-medium">Pending Setup</p>
                <p class="text-3xl font-extrabold text-amber-600 mt-2"><?= count($eligibleStudents) ?></p>
                <p class="text-xs text-slate-400 mt-1">students without an account</p>
            </div>
            <div class="rounded-2xl bg-green-700 p-5 shadow-sm text-white">
                <p class="text-xs text-green-300 font-medium">Schedule Starts</p>
                <p class="text-xl font-extrabold mt-2">August</p>
                <p class="text-xs text-green-300 mt-1">of school year's first year</p>
            </div>
        </div>

        <!-- ── Setup panel ──────────────────────────────────────────────────── -->
        <?php if ($setupId > 0 && $setupRow): ?>
        <?php
            $sfName     = strtoupper(trim(($setupRow['surname']??'') . ', ' . ($setupRow['firstname']??'')));
            $sfGrade    = trim((string)($setupRow['gradelevel_name']??''));
            $sfSec      = trim((string)($setupRow['section_name']??''));
            $sfDept     = (string)($setupRow['Department']??'');
            $sfClass    = (string)($setupRow['classification_desc']??'—');
            $sfType     = (string)($setupRow['student_type']??'Old');
            $sfCash     = (float)($setupRow['fee_cash']??0);
            $sfTuition  = (float)($setupRow['fee_tuition']??0);
            $sfImprov   = (float)($setupRow['fee_improvement']??0);
            $sfEnroll   = (float)($setupRow['fee_enrollment']??0);
            $sfActivity = (float)($setupRow['fee_activity']??0);
            $sfHouseReg = (float)($setupRow['fee_house_reg']??0);
            $sfEnrollDue = $sfEnroll + $sfActivity + $sfHouseReg;
            $alreadySet = !empty($setupRow['existing_account_id']);
        ?>
        <div class="bg-white rounded-2xl border border-green-600 shadow-lg mb-6 overflow-hidden">
            <div class="bg-green-700 px-6 py-4 flex items-start justify-between">
                <div>
                    <p class="text-xs text-green-300 font-semibold uppercase tracking-widest">Setting Up Account For</p>
                    <p class="text-white font-extrabold text-lg mt-0.5"><?= h($sfName) ?></p>
                    <p class="text-green-200 text-sm"><?= h($sfDept) ?> · <?= h($sfGrade) ?><?= $sfSec ? ' · ' . h($sfSec) : '' ?> · <?= h($sfClass) ?></p>
                </div>
                <a href="account_setup.php" class="text-green-200 hover:text-white text-lg leading-none mt-1">✕</a>
            </div>

            <?php if ($alreadySet): ?>
            <div class="p-6 bg-amber-50 border-t border-amber-200 text-amber-800 text-sm">
                ⚠ An account already exists for this student.
                <a href="monthly_payments.php" class="underline font-semibold ml-1">View monthly payments →</a>
            </div>
            <?php else: ?>
            <div class="p-6">
                <!-- Fee reference grid -->
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">Fee Breakdown (from Classification)</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
                    <?php foreach ([
                        ['Tuition Fee',        $sfTuition,   'slate'],
                        ['School Improvement',  $sfImprov,    'slate'],
                        ['Admission Fee',       $sfEnroll,    'blue'],
                        ['Activity Fees',       $sfActivity,  'green'],
                        ['House Registration',  $sfHouseReg,  'purple'],
                        ['Full Payment (Cash)', $sfCash,      'emerald'],
                    ] as [$lbl, $amt, $col]): ?>
                    <div class="rounded-xl bg-<?= $col ?>-50 border border-<?= $col ?>-200 p-3">
                        <p class="text-xs text-<?= $col ?>-600 font-medium"><?= h($lbl) ?></p>
                        <p class="font-extrabold text-<?= $col ?>-900 text-sm mt-1">₱<?= number_format((float)$amt, 2) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Enrollment due row -->
                <div class="rounded-xl bg-green-700 text-white p-3 mb-6 flex items-center justify-between">
                    <div>
                        <p class="text-xs text-green-300 font-semibold">Total Due at Enrollment</p>
                        <p class="text-xs text-green-200 mt-0.5">Admission + Activity + House Registration</p>
                    </div>
                    <p class="text-xl font-extrabold">₱<?= number_format($sfEnrollDue, 2) ?></p>
                </div>

                <form method="POST" action="account_setup.php">
                    <input type="hidden" name="csrf_token"   value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action"        value="setup_account">
                    <input type="hidden" name="enrollment_id" value="<?= $setupId ?>">

                    <div class="grid sm:grid-cols-2 gap-5 mb-6">
                        <!-- Payment Method -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-widest mb-2">Payment Method</label>
                            <div class="flex gap-3">
                                <label class="flex-1 flex items-center gap-3 border-2 border-green-600 bg-green-700/5 rounded-xl p-3 cursor-pointer">
                                    <input type="radio" name="payment_method" value="Installment" checked onchange="toggleMethod(this)">
                                    <div>
                                        <p class="font-semibold text-sm">Installment</p>
                                        <p class="text-xs text-slate-400">₱<?= number_format($sfInst, 2) ?>/month</p>
                                    </div>
                                </label>
                                <label class="flex-1 flex items-center gap-3 border-2 border-slate-200 rounded-xl p-3 cursor-pointer hover:border-slate-400">
                                    <input type="radio" name="payment_method" value="Cash" onchange="toggleMethod(this)">
                                    <div>
                                        <p class="font-semibold text-sm">Cash (Full)</p>
                                        <p class="text-xs text-slate-400">₱<?= number_format($sfCash, 2) ?></p>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <!-- Months -->
                        <div id="monthsField">
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-widest mb-2">Installment Months</label>
                            <select name="installment_months" id="installmentMonths" onchange="updatePreview()"
                                    class="w-full py-2.5 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                                <?php foreach ([6,7,8,9,10,11,12] as $n): ?>
                                <option value="<?= $n ?>" <?= $n===10?'selected':'' ?>><?= $n ?> months</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-slate-400 mt-1">Total: <strong id="totalLabel">₱<?= number_format($sfInst * 10, 2) ?></strong></p>
                        </div>
                    </div>

                    <!-- Schedule preview -->
                    <div id="schedulePreview">
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-2">Payment Schedule Preview</p>
                        <div class="rounded-xl border border-slate-200 overflow-hidden mb-6">
                            <table class="min-w-full text-xs">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-500 uppercase tracking-wide">
                                        <th class="px-3 py-2 text-left w-8">#</th>
                                        <th class="px-3 py-2 text-left">Month</th>
                                        <th class="px-3 py-2 text-left">Due Date</th>
                                        <th class="px-3 py-2 text-right">Amount Due</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100" id="scheduleBody">
                                    <?php foreach ($previewSchedule as $i => $slot): ?>
                                    <tr data-index="<?= $i ?>">
                                        <td class="px-3 py-2 text-slate-400"><?= $i+1 ?></td>
                                        <td class="px-3 py-2 font-medium"><?= h($slot['month_label']) ?></td>
                                        <td class="px-3 py-2 text-slate-400"><?= date('M d, Y', strtotime($slot['due_date'])) ?></td>
                                        <td class="px-3 py-2 text-right font-semibold text-green-700 amt-cell">₱<?= number_format($sfInst, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="bg-green-700 text-white font-bold px-6 py-2.5 rounded-xl hover:bg-green-800 transition-colors">
                            Create Account &amp; Schedule
                        </button>
                        <a href="account_setup.php" class="px-6 py-2.5 border border-slate-200 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Filter ──────────────────────────────────────────────────────── -->
        <form method="GET" class="flex flex-wrap gap-3 mb-4 items-end">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Search</label>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or Student ID…"
                       class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <input type="hidden" name="sy" value="<?= h($syFilter) ?>">
            <button type="submit" class="py-2 px-4 bg-green-700 text-white text-sm font-semibold rounded-xl hover:bg-green-800">Search</button>
            <?php if ($search !== ''): ?>
            <a href="account_setup.php" class="py-2 px-4 border border-slate-200 bg-white text-sm font-semibold rounded-xl">Clear</a>
            <?php endif; ?>
        </form>

        <!-- ── Students table ──────────────────────────────────────────────── -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <?php if (empty($eligibleStudents)): ?>
            <div class="flex flex-col items-center justify-center py-14 text-slate-400">
                <svg class="w-10 h-10 mb-3 opacity-40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="font-semibold">All students have accounts set up.</p>
                <p class="text-sm mt-1"><?= $search ? 'No matches for your search.' : 'No pending account setups.' ?></p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">Dept / Grade / Section</th>
                            <th class="px-4 py-3 text-left">Classification</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Cash / Monthly</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($eligibleStudents as $row): ?>
                        <?php
                            $rName  = strtoupper(trim((string)($row['full_name']??'ID:'.$row['student_id'])));
                            $rId    = (int)$row['id'];
                            $active = $setupId === $rId;
                        ?>
                        <tr class="hover:bg-slate-50 transition-colors <?= $active ? 'bg-green-50 border-l-4 border-l-[#1b3f7a]' : '' ?>">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-800"><?= h($rName) ?></p>
                                <p class="text-xs text-slate-400 font-mono"><?= h((string)$row['student_id']) ?></p>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600">
                                <span class="font-medium"><?= h((string)($row['Department']??'')) ?></span><br>
                                <?= h((string)($row['gradelevel_name']??'')) ?>
                                <?php if (!empty($row['section_name']) && $row['section_name'] !== '-'): ?>
                                · <?= h((string)$row['section_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold <?= ($row['student_type']??'Old')==='New' ? 'bg-emerald-100 text-emerald-700' : 'bg-green-100 text-green-700' ?>">
                                    <?= h((string)($row['student_type']??'Old')) ?>
                                </span>
                                <p class="text-xs text-slate-400 mt-0.5"><?= h((string)($row['classification_desc']??'—')) ?></p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold bg-slate-100 text-slate-600">
                                    <?= h((string)($row['Status']??'')) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-xs">
                                <p class="font-semibold text-green-700">₱<?= number_format((float)($row['fee_cash']??0), 2) ?></p>
                                <p class="text-slate-400">₱<?= number_format((float)($row['fee_installment']??0), 2) ?>/mo</p>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="account_setup.php?setup=<?= $rId ?><?= $search?'&q='.urlencode($search):'' ?>"
                                   class="inline-flex items-center gap-1.5 rounded-lg text-xs font-semibold px-3 py-1.5 transition-all
                                          <?= $active ? 'bg-green-700 text-white' : 'bg-slate-100 border border-slate-200 text-slate-700 hover:bg-green-700 hover:text-white hover:border-green-600' ?>">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    <?= $active ? 'Setting Up…' : 'Setup Account' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>
<script>
const sfInst = <?= json_encode($sfInst) ?>;
function toggleMethod(el) {
    const isInst = el.value === 'Installment';
    document.getElementById('monthsField').style.display     = isInst ? '' : 'none';
    document.getElementById('schedulePreview').style.display = isInst ? '' : 'none';
    updatePreview();
}
function updatePreview() {
    const months = parseInt(document.getElementById('installmentMonths')?.value || '10', 10);
    const total  = sfInst * months;
    const lbl    = document.getElementById('totalLabel');
    if (lbl) lbl.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.querySelectorAll('#scheduleBody tr').forEach(function(tr) {
        tr.style.display = parseInt(tr.dataset.index, 10) < months ? '' : 'none';
    });
}
</script>
</body>
</html>
