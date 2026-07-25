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
try {
    $syStmt = $connection->prepare(
        'SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow && !empty($syRow['School_year'])) {
        $activeSchoolYearLabel = (string) $syRow['School_year'];
    }
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── All school years ─────────────────────────────────────────────────────────
$allSchoolYears = [];
try {
    $syListStmt = $connection->prepare(
        'SELECT School_year FROM schoolyear ORDER BY School_year_id DESC'
    );
    $syListStmt->execute();
    foreach (stmt_fetch_all_assoc($syListStmt) as $syRow2) {
        $allSchoolYears[] = (string) $syRow2['School_year'];
    }
} catch (Throwable) {}
if (empty($allSchoolYears)) {
    $allSchoolYears[] = $activeSchoolYearLabel;
}

// ── Filters ──────────────────────────────────────────────────────────────────
$syFilter     = isset($_GET['sy'])     ? trim((string) $_GET['sy'])     : $activeSchoolYearLabel;
$dateFrom     = isset($_GET['from'])   ? trim((string) $_GET['from'])   : '';
$dateTo       = isset($_GET['to'])     ? trim((string) $_GET['to'])     : '';
$methodFilter = isset($_GET['method']) ? trim((string) $_GET['method']) : '';
$deptFilter   = isset($_GET['dept'])   ? trim((string) $_GET['dept'])   : '';

// Build WHERE clause for all queries (scoped to payment records)
$baseWhere  = ['1=1'];
$baseParams = [];
$baseTypes  = '';

if ($syFilter !== '') {
    $baseWhere[]  = 'bpr.school_year = ?';
    $baseParams[] = $syFilter;
    $baseTypes   .= 's';
}
if ($dateFrom !== '') {
    $baseWhere[]  = 'DATE(bpr.payment_date) >= ?';
    $baseParams[] = $dateFrom;
    $baseTypes   .= 's';
}
if ($dateTo !== '') {
    $baseWhere[]  = 'DATE(bpr.payment_date) <= ?';
    $baseParams[] = $dateTo;
    $baseTypes   .= 's';
}
if ($methodFilter !== '') {
    $baseWhere[]  = 'bpr.payment_method = ?';
    $baseParams[] = $methodFilter;
    $baseTypes   .= 's';
}
if ($deptFilter !== '') {
    $baseWhere[]  = 'en.Department = ?';
    $baseParams[] = $deptFilter;
    $baseTypes   .= 's';
}

$wc = implode(' AND ', $baseWhere);

// ── Helper: run a summary query ───────────────────────────────────────────────
function run_sum_query(mysqli $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        bind_dynamic_params($stmt, $types, $params);
    }
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

// ─── 1. Grand totals by fee category ────────────────────────────────────────
$grandSql = "
    SELECT
        COUNT(bpr.id)                          AS total_transactions,
        SUM(bpr.payment_amount)                AS grand_total,
        SUM(bpr.fee_admission)                 AS total_admission,
        SUM(bpr.fee_activity)                  AS total_activity,
        SUM(bpr.fee_books)                     AS total_books,
        SUM(IFNULL(bpr.fee_house_reg, 0))      AS total_house_reg,
        SUM(CASE WHEN bpr.payment_method='Cash'        THEN bpr.payment_amount ELSE 0 END) AS cash_total,
        SUM(CASE WHEN bpr.payment_method='Installment' THEN bpr.payment_amount ELSE 0 END) AS inst_total,
        COUNT(CASE WHEN bpr.payment_method='Cash'        THEN 1 END) AS cash_count,
        COUNT(CASE WHEN bpr.payment_method='Installment' THEN 1 END) AS inst_count
    FROM backaccount_payment_records bpr
    LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
    WHERE $wc
";
$grandRows = run_sum_query($connection, $grandSql, $baseTypes, $baseParams);
$grand     = $grandRows[0] ?? [];

// ─── 2. Per-house breakdown ───────────────────────────────────────────────────
$houseSql = "
    SELECT
        IFNULL(h.housename, '— No House —')   AS house_name,
        COUNT(bpr.id)                          AS student_count,
        SUM(bpr.payment_amount)                AS house_total,
        SUM(bpr.fee_admission)                 AS h_admission,
        SUM(bpr.fee_activity)                  AS h_activity,
        SUM(bpr.fee_books)                     AS h_books,
        SUM(IFNULL(bpr.fee_house_reg, 0))      AS h_house_reg,
        SUM(CASE WHEN bpr.payment_method='Cash'        THEN bpr.payment_amount ELSE 0 END) AS h_cash,
        SUM(CASE WHEN bpr.payment_method='Installment' THEN bpr.payment_amount ELSE 0 END) AS h_inst
    FROM backaccount_payment_records bpr
    LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
    LEFT JOIN house h       ON h.id  = en.house_id
    WHERE $wc
    GROUP BY en.house_id, h.housename
    ORDER BY house_total DESC
";
$houseRows = run_sum_query($connection, $houseSql, $baseTypes, $baseParams);

// ─── 3. Per-department breakdown ─────────────────────────────────────────────
$deptSql = "
    SELECT
        IFNULL(en.Department, '— Unknown —')   AS dept_name,
        COUNT(bpr.id)                          AS d_count,
        SUM(bpr.payment_amount)                AS d_total,
        SUM(bpr.fee_admission)                 AS d_admission,
        SUM(bpr.fee_activity)                  AS d_activity,
        SUM(bpr.fee_books)                     AS d_books,
        SUM(IFNULL(bpr.fee_house_reg, 0))      AS d_house_reg
    FROM backaccount_payment_records bpr
    LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
    WHERE $wc
    GROUP BY en.Department
    ORDER BY d_total DESC
";
$deptRows = run_sum_query($connection, $deptSql, $baseTypes, $baseParams);

// ─── 4. Per-classification breakdown ────────────────────────────────────────
$classSql = "
    SELECT
        IFNULL(pb.description, '— Unknown —')  AS class_label,
        IFNULL(pb.classification, '')           AS class_code,
        IFNULL(pb.type, '')                     AS class_type,
        COUNT(bpr.id)                           AS c_count,
        SUM(bpr.payment_amount)                 AS c_total,
        SUM(bpr.fee_admission)                  AS c_admission,
        SUM(bpr.fee_activity)                   AS c_activity,
        SUM(bpr.fee_books)                      AS c_books,
        SUM(IFNULL(bpr.fee_house_reg, 0))       AS c_house_reg
    FROM backaccount_payment_records bpr
    LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
    LEFT JOIN payment_breakdown pb
           ON pb.classification_id = en.Student_classification
          AND pb.type = IF(
                (SELECT COUNT(*) FROM preregistration WHERE CAST(id AS CHAR) = en.student_id LIMIT 1) > 0,
                'New', 'Old'
              )
          AND pb.status = 'Active'
    WHERE $wc
    GROUP BY pb.description, pb.classification, pb.type
    ORDER BY c_total DESC
";
$classRows = run_sum_query($connection, $classSql, $baseTypes, $baseParams);

// ─── 5. Daily trend (last 30 days or within date range) ─────────────────────
$trendWhere  = $baseWhere;
$trendParams = $baseParams;
$trendTypes  = $baseTypes;
// If no date range set, default to last 30 days for trend
if ($dateFrom === '' && $dateTo === '') {
    $trendWhere[]  = 'bpr.payment_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)';
}
$trendWc  = implode(' AND ', $trendWhere);

$trendSql = "
    SELECT
        DATE(bpr.payment_date)             AS pay_day,
        COUNT(bpr.id)                      AS t_count,
        SUM(bpr.payment_amount)            AS t_total,
        SUM(bpr.fee_admission)             AS t_admission,
        SUM(bpr.fee_activity)              AS t_activity,
        SUM(bpr.fee_books)                 AS t_books,
        SUM(IFNULL(bpr.fee_house_reg, 0))  AS t_house_reg
    FROM backaccount_payment_records bpr
    LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
    WHERE $trendWc
    GROUP BY DATE(bpr.payment_date)
    ORDER BY pay_day ASC
";
$trendStmt = $connection->prepare($trendSql);
if ($trendTypes !== '') {
    bind_dynamic_params($trendStmt, $trendTypes, $trendParams);
}
$trendStmt->execute();
$trendRows = stmt_fetch_all_assoc($trendStmt);

// Build chart data arrays
$chartLabels     = [];
$chartTotal      = [];
$chartAdmission  = [];
$chartActivity   = [];
$chartBooks      = [];
$chartHouseReg   = [];
foreach ($trendRows as $tr) {
    $ts               = strtotime((string) $tr['pay_day']);
    $chartLabels[]    = date('M d', $ts);
    $chartTotal[]     = round((float) $tr['t_total'],      2);
    $chartAdmission[] = round((float) $tr['t_admission'],  2);
    $chartActivity[]  = round((float) $tr['t_activity'],   2);
    $chartBooks[]     = round((float) $tr['t_books'],      2);
    $chartHouseReg[]  = round((float) $tr['t_house_reg'],  2);
}

// Grand totals as scalars
$grandTotal      = (float) ($grand['grand_total']      ?? 0);
$grandAdmission  = (float) ($grand['total_admission']  ?? 0);
$grandActivity   = (float) ($grand['total_activity']   ?? 0);
$grandBooks      = (float) ($grand['total_books']      ?? 0);
$grandHouseReg   = (float) ($grand['total_house_reg']  ?? 0);
$grandCash       = (float) ($grand['cash_total']       ?? 0);
$grandInst       = (float) ($grand['inst_total']       ?? 0);
$grandCount      = (int)   ($grand['total_transactions'] ?? 0);
$cashCount       = (int)   ($grand['cash_count']       ?? 0);
$instCount       = (int)   ($grand['inst_count']       ?? 0);

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Consolidation | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
    <style>
        .card { @apply rounded-3xl bg-white border border-slate-100 shadow-panel; }
        .th { @apply px-4 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider; }
        .td { @apply px-4 py-3 text-sm; }
        .tr { @apply border-b border-slate-50 hover:bg-green-50/30 transition-colors; }
        .badge { @apply inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Payment Consolidation</h1>
            <p class="text-slate-500 mt-2">
                Detailed payment breakdown by category, house, department, and classification · S.Y. <?= h($syFilter ?: 'All') ?>
            </p>
            <p class="text-xs text-green-700 mt-2">
                <?= h((string) ($user['full_name'] ?? 'Cashier')) ?> &nbsp;·&nbsp; Generated: <?= date('F d, Y h:i A') ?>
            </p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="" class="bg-white rounded-2xl border border-slate-200 p-4 mb-6 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Filter Records</p>
            <div class="flex flex-wrap gap-3 items-end">
                <!-- School Year -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                    <select name="sy" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">All S.Y.</option>
                        <?php foreach ($allSchoolYears as $sy): ?>
                        <option value="<?= h($sy) ?>" <?= $syFilter === $sy ? 'selected' : '' ?>>
                            S.Y. <?= h($sy) ?><?= $sy === $activeSchoolYearLabel ? ' ✓' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Date From -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">From</label>
                    <input type="date" name="from" value="<?= h($dateFrom) ?>"
                           class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
                <!-- Date To -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">To</label>
                    <input type="date" name="to" value="<?= h($dateTo) ?>"
                           class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
                <!-- Method -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Method</label>
                    <select name="method" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">All Methods</option>
                        <option value="Cash"        <?= $methodFilter==='Cash'        ? 'selected':'' ?>>Cash</option>
                        <option value="Installment" <?= $methodFilter==='Installment' ? 'selected':'' ?>>Installment</option>
                    </select>
                </div>
                <!-- Department -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Department</label>
                    <select name="dept" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">All Departments</option>
                        <?php foreach (['Elementary','Junior High School','Senior High School'] as $_d): ?>
                        <option value="<?= h($_d) ?>" <?= $deptFilter === $_d ? 'selected':'' ?>><?= h($_d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit"
                            class="py-2 px-5 bg-green-700 text-white text-sm font-semibold rounded-xl hover:bg-green-800 transition-colors">
                        Apply
                    </button>
                    <a href="consolidation.php"
                       class="py-2 px-4 border border-slate-200 bg-white text-sm font-semibold text-slate-600 rounded-xl hover:bg-slate-50">
                        Reset
                    </a>
                </div>
            </div>
        </form>

        <!-- ── Grand Summary Cards ─────────────────────────────────────────── -->
        <section class="mb-8">
            <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-4">Grand Summary — Fee Breakdown</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">

                <!-- Total Collected -->
                <div class="xl:col-span-1 2xl:col-span-2 rounded-3xl bg-green-700 p-6 flex flex-col justify-between shadow-panel">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-green-200 uppercase tracking-wide">Grand Total</span>
                        <span class="badge bg-white/15 text-white"><?= $grandCount ?> txn</span>
                    </div>
                    <div class="mt-4">
                        <p class="text-4xl font-extrabold text-white">₱<?= number_format($grandTotal, 2) ?></p>
                        <p class="text-sm text-green-200 mt-1">All fees combined · <?= $grandCount ?> payment<?= $grandCount !== 1 ? 's' : '' ?></p>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <div class="rounded-xl bg-white/10 p-3">
                            <p class="text-xs text-green-200">Cash</p>
                            <p class="font-bold text-white">₱<?= number_format($grandCash, 2) ?></p>
                            <p class="text-[11px] text-green-300"><?= $cashCount ?> txn</p>
                        </div>
                        <div class="rounded-xl bg-white/10 p-3">
                            <p class="text-xs text-green-200">Installment</p>
                            <p class="font-bold text-white">₱<?= number_format($grandInst, 2) ?></p>
                            <p class="text-[11px] text-green-300"><?= $instCount ?> txn</p>
                        </div>
                    </div>
                </div>

                <!-- Admission Fee -->
                <div class="rounded-3xl bg-white border border-slate-100 shadow-panel p-5">
                    <div class="w-10 h-10 rounded-2xl bg-sky-50 flex items-center justify-center mb-3">
                        <svg class="w-5 h-5 text-sky-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Admission Fee</p>
                    <p class="text-2xl font-extrabold text-slate-900 mt-1">₱<?= number_format($grandAdmission, 2) ?></p>
                    <?php if ($grandTotal > 0): ?>
                    <div class="mt-3 h-1.5 rounded-full bg-slate-100">
                        <div class="h-1.5 rounded-full bg-sky-500"
                             style="width:<?= min(100, round($grandAdmission / $grandTotal * 100)) ?>%"></div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1"><?= round($grandAdmission / $grandTotal * 100) ?>% of total</p>
                    <?php endif; ?>
                </div>

                <!-- Activity Fee -->
                <div class="rounded-3xl bg-white border border-slate-100 shadow-panel p-5">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-50 flex items-center justify-center mb-3">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Activity Fee</p>
                    <p class="text-2xl font-extrabold text-slate-900 mt-1">₱<?= number_format($grandActivity, 2) ?></p>
                    <?php if ($grandTotal > 0): ?>
                    <div class="mt-3 h-1.5 rounded-full bg-slate-100">
                        <div class="h-1.5 rounded-full bg-emerald-500"
                             style="width:<?= min(100, round($grandActivity / $grandTotal * 100)) ?>%"></div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1"><?= round($grandActivity / $grandTotal * 100) ?>% of total</p>
                    <?php endif; ?>
                </div>

                <!-- Books -->
                <div class="rounded-3xl bg-white border border-slate-100 shadow-panel p-5">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 flex items-center justify-center mb-3">
                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Books / Materials</p>
                    <p class="text-2xl font-extrabold text-slate-900 mt-1">₱<?= number_format($grandBooks, 2) ?></p>
                    <?php if ($grandTotal > 0): ?>
                    <div class="mt-3 h-1.5 rounded-full bg-slate-100">
                        <div class="h-1.5 rounded-full bg-amber-500"
                             style="width:<?= min(100, round($grandBooks / $grandTotal * 100)) ?>%"></div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1"><?= round($grandBooks / $grandTotal * 100) ?>% of total</p>
                    <?php endif; ?>
                </div>

                <!-- House Registration -->
                <div class="rounded-3xl bg-white border border-slate-100 shadow-panel p-5">
                    <div class="w-10 h-10 rounded-2xl bg-violet-50 flex items-center justify-center mb-3">
                        <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">House Reg. Fee</p>
                    <p class="text-2xl font-extrabold text-slate-900 mt-1">₱<?= number_format($grandHouseReg, 2) ?></p>
                    <?php if ($grandTotal > 0): ?>
                    <div class="mt-3 h-1.5 rounded-full bg-slate-100">
                        <div class="h-1.5 rounded-full bg-violet-500"
                             style="width:<?= min(100, round($grandHouseReg / $grandTotal * 100)) ?>%"></div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1"><?= round($grandHouseReg / $grandTotal * 100) ?>% of total</p>
                    <?php endif; ?>
                </div>

            </div>
        </section>

        <!-- ── Chart ──────────────────────────────────────────────────────────── -->
        <?php if (!empty($chartLabels)): ?>
        <section class="mb-8">
            <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-4">
                Daily Collection Trend
                <span class="font-normal normal-case text-slate-300 ml-2">
                    <?= $dateFrom !== '' || $dateTo !== '' ? h($dateFrom) . ' – ' . h($dateTo) : 'Last 30 Days' ?>
                </span>
            </h2>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                <div class="h-72">
                    <canvas id="trendChart"></canvas>
                </div>
                <div class="mt-4 flex flex-wrap gap-4 text-xs">
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span>Total</span>
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-sky-400 inline-block"></span>Admission</span>
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-400 inline-block"></span>Activity</span>
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-amber-400 inline-block"></span>Books</span>
                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-violet-400 inline-block"></span>House Reg</span>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Two-column: House + Department ─────────────────────────────── -->
        <div class="grid gap-6 lg:grid-cols-2 mb-8">

            <!-- Per-House Breakdown -->
            <section>
                <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-4">By House / Organization</h2>
                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                    <?php if (empty($houseRows)): ?>
                    <div class="flex flex-col items-center justify-center py-12 text-slate-400 text-sm">
                        <svg class="w-8 h-8 mb-2 opacity-40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        No data
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100">
                                    <th class="th">House</th>
                                    <th class="th text-right">Students</th>
                                    <th class="th text-right">Admission</th>
                                    <th class="th text-right">Activity</th>
                                    <th class="th text-right">Books</th>
                                    <th class="th text-right">House Reg</th>
                                    <th class="th text-right font-bold text-slate-600">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php
                                $houseGrandTotal = array_sum(array_column($houseRows, 'house_total'));
                                foreach ($houseRows as $hrow):
                                    $pct = $houseGrandTotal > 0 ? round((float)$hrow['house_total'] / $houseGrandTotal * 100) : 0;
                                    $isNoHouse = ($hrow['house_name'] === '— No House —');
                                ?>
                                <tr class="tr">
                                    <td class="td">
                                        <div class="flex items-center gap-2">
                                            <div class="w-2.5 h-2.5 rounded-full flex-shrink-0
                                                <?= $isNoHouse ? 'bg-slate-300' : 'bg-violet-500' ?>"></div>
                                            <span class="font-semibold <?= $isNoHouse ? 'text-slate-400 italic' : 'text-slate-800' ?>">
                                                <?= h($hrow['house_name']) ?>
                                            </span>
                                        </div>
                                        <!-- Progress bar -->
                                        <div class="mt-1.5 h-1 rounded-full bg-slate-100 ml-4">
                                            <div class="h-1 rounded-full <?= $isNoHouse ? 'bg-slate-300' : 'bg-violet-500' ?>"
                                                 style="width:<?= $pct ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="td text-right tabular-nums">
                                        <span class="badge bg-slate-100 text-slate-600"><?= (int)$hrow['student_count'] ?></span>
                                    </td>
                                    <td class="td text-right tabular-nums text-sky-700">₱<?= number_format((float)$hrow['h_admission'], 2) ?></td>
                                    <td class="td text-right tabular-nums text-emerald-700">₱<?= number_format((float)$hrow['h_activity'], 2) ?></td>
                                    <td class="td text-right tabular-nums text-amber-700">₱<?= number_format((float)$hrow['h_books'], 2) ?></td>
                                    <td class="td text-right tabular-nums text-violet-700">₱<?= number_format((float)$hrow['h_house_reg'], 2) ?></td>
                                    <td class="td text-right tabular-nums font-bold text-green-700">₱<?= number_format((float)$hrow['house_total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-50 border-t-2 border-slate-200">
                                    <td class="px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Total</td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-700"><?= $grandCount ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-sky-700">₱<?= number_format($grandAdmission, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-emerald-700">₱<?= number_format($grandActivity, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-amber-700">₱<?= number_format($grandBooks, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-violet-700">₱<?= number_format($grandHouseReg, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-extrabold text-green-700">₱<?= number_format($grandTotal, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Per-Department Breakdown -->
            <section>
                <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-4">By Department</h2>
                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                    <?php if (empty($deptRows)): ?>
                    <div class="flex flex-col items-center justify-center py-12 text-slate-400 text-sm">No data</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100">
                                    <th class="th">Department</th>
                                    <th class="th text-right">Count</th>
                                    <th class="th text-right">Admission</th>
                                    <th class="th text-right">Activity</th>
                                    <th class="th text-right">Books</th>
                                    <th class="th text-right">House Reg</th>
                                    <th class="th text-right font-bold text-slate-600">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php
                                $deptGrandTotal = array_sum(array_column($deptRows, 'd_total'));
                                $deptColors = [
                                    'Elementary'         => 'bg-sky-500',
                                    'Junior High School' => 'bg-emerald-500',
                                    'Senior High School' => 'bg-amber-500',
                                ];
                                foreach ($deptRows as $drow):
                                    $dpct = $deptGrandTotal > 0 ? round((float)$drow['d_total'] / $deptGrandTotal * 100) : 0;
                                    $dcolor = $deptColors[$drow['dept_name']] ?? 'bg-slate-400';
                                ?>
                                <tr class="tr">
                                    <td class="td">
                                        <div class="flex items-center gap-2">
                                            <div class="w-2.5 h-2.5 rounded-full flex-shrink-0 <?= $dcolor ?>"></div>
                                            <span class="font-semibold text-slate-800"><?= h($drow['dept_name']) ?></span>
                                        </div>
                                        <div class="mt-1.5 h-1 rounded-full bg-slate-100 ml-4">
                                            <div class="h-1 rounded-full <?= $dcolor ?>" style="width:<?= $dpct ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="td text-right tabular-nums">
                                        <span class="badge bg-slate-100 text-slate-600"><?= (int)$drow['d_count'] ?></span>
                                    </td>
                                    <td class="td text-right tabular-nums text-sky-700">₱<?= number_format((float)$drow['d_admission'], 2) ?></td>
                                    <td class="td text-right tabular-nums text-emerald-700">₱<?= number_format((float)$drow['d_activity'], 2) ?></td>
                                    <td class="td text-right tabular-nums text-amber-700">₱<?= number_format((float)$drow['d_books'], 2) ?></td>
                                    <td class="td text-right tabular-nums text-violet-700">₱<?= number_format((float)$drow['d_house_reg'], 2) ?></td>
                                    <td class="td text-right tabular-nums font-bold text-green-700">₱<?= number_format((float)$drow['d_total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-50 border-t-2 border-slate-200">
                                    <td class="px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Total</td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-700"><?= $grandCount ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-sky-700">₱<?= number_format($grandAdmission, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-emerald-700">₱<?= number_format($grandActivity, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-amber-700">₱<?= number_format($grandBooks, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-violet-700">₱<?= number_format($grandHouseReg, 2) ?></td>
                                    <td class="px-4 py-3 text-right font-extrabold text-green-700">₱<?= number_format($grandTotal, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

        </div>

        <!-- ── Per-Classification Breakdown (full width) ───────────────────── -->
        <section class="mb-8">
            <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-4">By Student Classification</h2>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                <?php if (empty($classRows)): ?>
                <div class="flex flex-col items-center justify-center py-12 text-slate-400 text-sm">No data</div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="th">Classification</th>
                                <th class="th">Type</th>
                                <th class="th text-right">Students</th>
                                <th class="th text-right">Admission Fee</th>
                                <th class="th text-right">Activity Fee</th>
                                <th class="th text-right">Books / Mat.</th>
                                <th class="th text-right">House Reg.</th>
                                <th class="th text-right font-bold text-slate-600">Total Collected</th>
                                <th class="th text-right">Share</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php
                            $classGrandTotal = array_sum(array_column($classRows, 'c_total'));
                            foreach ($classRows as $crow):
                                $cpct = $classGrandTotal > 0 ? round((float)$crow['c_total'] / $classGrandTotal * 100) : 0;
                                $typeLabel = ($crow['class_type'] === 'New') ? 'New Student' : (($crow['class_type'] === 'Old') ? 'Old Student' : $crow['class_type']);
                                $typeBadge = ($crow['class_type'] === 'New')
                                    ? 'bg-sky-50 text-sky-700 border border-sky-200'
                                    : 'bg-amber-50 text-amber-700 border border-amber-200';
                            ?>
                            <tr class="tr">
                                <td class="td">
                                    <p class="font-semibold text-slate-800"><?= h($crow['class_label']) ?></p>
                                    <?php if ($crow['class_code'] !== ''): ?>
                                    <p class="text-[11px] text-slate-400 font-mono"><?= h($crow['class_code']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="td">
                                    <span class="badge <?= $typeBadge ?>"><?= h($typeLabel) ?></span>
                                </td>
                                <td class="td text-right tabular-nums">
                                    <span class="badge bg-slate-100 text-slate-600"><?= (int)$crow['c_count'] ?></span>
                                </td>
                                <td class="td text-right tabular-nums text-sky-700">₱<?= number_format((float)$crow['c_admission'], 2) ?></td>
                                <td class="td text-right tabular-nums text-emerald-700">₱<?= number_format((float)$crow['c_activity'], 2) ?></td>
                                <td class="td text-right tabular-nums text-amber-700">₱<?= number_format((float)$crow['c_books'], 2) ?></td>
                                <td class="td text-right tabular-nums text-violet-700">₱<?= number_format((float)$crow['c_house_reg'], 2) ?></td>
                                <td class="td text-right tabular-nums font-bold text-green-700">₱<?= number_format((float)$crow['c_total'], 2) ?></td>
                                <td class="td text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="w-16 h-1.5 rounded-full bg-slate-100">
                                            <div class="h-1.5 rounded-full bg-green-500" style="width:<?= $cpct ?>%"></div>
                                        </div>
                                        <span class="text-[11px] font-semibold text-slate-500 w-8 text-right"><?= $cpct ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 border-t-2 border-slate-200 font-bold">
                                <td class="px-4 py-3 text-xs uppercase tracking-wide text-slate-500" colspan="2">Grand Total</td>
                                <td class="px-4 py-3 text-right text-slate-700"><?= $grandCount ?></td>
                                <td class="px-4 py-3 text-right text-sky-700">₱<?= number_format($grandAdmission, 2) ?></td>
                                <td class="px-4 py-3 text-right text-emerald-700">₱<?= number_format($grandActivity, 2) ?></td>
                                <td class="px-4 py-3 text-right text-amber-700">₱<?= number_format($grandBooks, 2) ?></td>
                                <td class="px-4 py-3 text-right text-violet-700">₱<?= number_format($grandHouseReg, 2) ?></td>
                                <td class="px-4 py-3 text-right text-green-700 text-base">₱<?= number_format($grandTotal, 2) ?></td>
                                <td class="px-4 py-3 text-right text-slate-400">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Footer note -->
        <div class="text-center text-xs text-slate-400 pb-6">
            Report generated on <?= date('F d, Y \a\t h:i A') ?> by <?= h((string)($user['full_name'] ?? 'Cashier')) ?> ·
            <?= $syFilter ? 'S.Y. ' . h($syFilter) : 'All School Years' ?>
            <?= $dateFrom || $dateTo ? ' · ' . h($dateFrom ?: '...') . ' – ' . h($dateTo ?: 'now') : '' ?>
        </div>

    </main>
</div>

<?php if (!empty($chartLabels)): ?>
<script>
(function () {
    const labels     = <?= json_encode($chartLabels) ?>;
    const totals     = <?= json_encode($chartTotal) ?>;
    const admission  = <?= json_encode($chartAdmission) ?>;
    const activity   = <?= json_encode($chartActivity) ?>;
    const books      = <?= json_encode($chartBooks) ?>;
    const houseReg   = <?= json_encode($chartHouseReg) ?>;

    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Total',
                    data: totals,
                    backgroundColor: 'rgba(22,101,52,0.15)',
                    borderColor: 'rgba(22,101,52,0.8)',
                    borderWidth: 2,
                    type: 'line',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    yAxisID: 'y',
                    order: 0,
                },
                {
                    label: 'Admission',
                    data: admission,
                    backgroundColor: 'rgba(56,189,248,0.75)',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1,
                },
                {
                    label: 'Activity',
                    data: activity,
                    backgroundColor: 'rgba(52,211,153,0.75)',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1,
                },
                {
                    label: 'Books',
                    data: books,
                    backgroundColor: 'rgba(251,191,36,0.75)',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1,
                },
                {
                    label: 'House Reg',
                    data: houseReg,
                    backgroundColor: 'rgba(167,139,250,0.75)',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1,
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.dataset.label}: ₱${Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits:2})}`
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Manrope', size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: {
                        font: { family: 'Manrope', size: 11 },
                        callback: (v) => '₱' + Number(v).toLocaleString()
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

</body>
</html>
