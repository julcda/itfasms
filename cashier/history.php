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

// ── All school years (for dropdown) ─────────────────────────────────────────
$allSchoolYears = [];
try {
    $syListStmt = $connection->prepare(
        'SELECT School_year FROM schoolyear ORDER BY School_year_id DESC'
    );
    $syListStmt->execute();
    foreach (stmt_fetch_all_assoc($syListStmt) as $syListRow) {
        $allSchoolYears[] = (string) $syListRow['School_year'];
    }
} catch (Throwable) {}
if (empty($allSchoolYears)) {
    $allSchoolYears[] = $activeSchoolYearLabel;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search       = trim((string) ($_GET['q']      ?? ''));
$dateFrom     = trim((string) ($_GET['from']   ?? ''));
$dateTo       = trim((string) ($_GET['to']     ?? ''));
$methodFilter = trim((string) ($_GET['method'] ?? ''));
// Default school year to active; accept '' (All) when explicitly passed
$syFilter     = isset($_GET['sy']) ? trim((string) $_GET['sy']) : $activeSchoolYearLabel;
$page         = max(1, to_int($_GET['page'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

// ── Summary stats ─────────────────────────────────────────────────────────────
$stats = ['today_count' => 0, 'today_total' => 0.0, 'month_total' => 0.0, 'sy_total' => 0.0, 'grand_total' => 0.0, 'grand_count' => 0];
try {
    $stStmt = $connection->prepare(
        'SELECT
            SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN 1 ELSE 0 END)            AS today_count,
            IFNULL(SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN payment_amount ELSE 0 END), 0) AS today_total,
            IFNULL(SUM(CASE WHEN YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE()) THEN payment_amount ELSE 0 END), 0) AS month_total,
            IFNULL(SUM(CASE WHEN school_year = ? THEN payment_amount ELSE 0 END), 0)    AS sy_total,
            IFNULL(SUM(payment_amount), 0)                                              AS grand_total,
            COUNT(*)                                                                    AS grand_count
         FROM backaccount_payment_records'
    );
    $stStmt->bind_param('s', $activeSchoolYearLabel);
    $stStmt->execute();
    $stats = array_merge($stats, (array) stmt_fetch_assoc($stStmt));
} catch (Throwable) {}

// ── Build filtered query ──────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = '(bpr.name LIKE ? OR bpr.student_id LIKE ? OR bpr.or_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
    $types   .= 'sss';
}
if ($dateFrom !== '') {
    $where[]  = 'DATE(bpr.payment_date) >= ?';
    $params[] = $dateFrom;
    $types   .= 's';
}
if ($dateTo !== '') {
    $where[]  = 'DATE(bpr.payment_date) <= ?';
    $params[] = $dateTo;
    $types   .= 's';
}
if ($methodFilter !== '') {
    $where[]  = 'bpr.payment_method = ?';
    $params[] = $methodFilter;
    $types   .= 's';
}
if ($syFilter !== '') {
    $where[]  = 'bpr.school_year = ?';
    $params[] = $syFilter;
    $types   .= 's';
}

$whereClause = implode(' AND ', $where);

// Count total matching rows
$countSql  = "SELECT COUNT(*) AS cnt FROM backaccount_payment_records bpr WHERE $whereClause";
$countStmt = $connection->prepare($countSql);
if ($types !== '') {
    bind_dynamic_params($countStmt, $types, $params);
}
$countStmt->execute();
$totalRows  = (int) (stmt_fetch_assoc($countStmt)['cnt'] ?? 0);
$totalPages = (int) ceil($totalRows / $perPage);

// Fetch rows
$sql = "SELECT bpr.id, bpr.student_id, bpr.name, bpr.payment_amount,
               bpr.payment_date, bpr.or_number, bpr.payment_method,
               bpr.school_year, bpr.cashier_name,
               IFNULL(bpr.fee_admission, 0) AS fee_admission,
               IFNULL(bpr.fee_activity,  0) AS fee_activity,
               IFNULL(bpr.fee_books,     0) AS fee_books,
               IFNULL(bpr.fee_house_reg, 0) AS fee_house_reg,
               en.Department,
               IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
               IFNULL(sc.Section_name, en.Department_section) AS section_name
        FROM backaccount_payment_records bpr
        LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
        LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
        LEFT JOIN section sc   ON CAST(sc.Section_id AS CHAR) = en.Department_section
        WHERE $whereClause
        ORDER BY bpr.id DESC
        LIMIT ? OFFSET ?";

$dataStmt = $connection->prepare($sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes  = $types . 'ii';
bind_dynamic_params($dataStmt, $allTypes, $allParams);
$dataStmt->execute();
$rows = stmt_fetch_all_assoc($dataStmt);

// ── Running total of filtered results ─────────────────────────────────────────
$filteredTotal = 0.0;
$hasActiveFilter = ($search !== '' || $dateFrom !== '' || $dateTo !== '' || $methodFilter !== '' || $syFilter !== $activeSchoolYearLabel);
// Always compute filtered total (SY filter is always on)
$sumSql  = "SELECT IFNULL(SUM(bpr.payment_amount),0) AS s FROM backaccount_payment_records bpr WHERE $whereClause";
$sumStmt = $connection->prepare($sumSql);
if ($types !== '') {
    bind_dynamic_params($sumStmt, $types, $params);
}
$sumStmt->execute();
$filteredTotal = (float) (stmt_fetch_assoc($sumStmt)['s'] ?? 0);

// ── Edit payment POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_payment') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('history.php');
    }

    $editPassword = trim((string) ($_POST['edit_password'] ?? ''));
    if (!hash_equals('TAIMIYANS', $editPassword)) {
        flash_set('error', 'Incorrect authorization password.');
        redirect_to('history.php?' . http_build_query(array_filter(['q'=>$search,'from'=>$dateFrom,'to'=>$dateTo,'method'=>$methodFilter,'sy'=>$syFilter,'page'=>$page])));
    }

    try {
        $editId         = to_int($_POST['edit_id'] ?? 0);
        $editAdmission  = max(0.0, (float) ($_POST['edit_fee_admission'] ?? 0));
        $editActivity   = max(0.0, (float) ($_POST['edit_fee_activity']  ?? 0));
        $editBooks      = max(0.0, (float) ($_POST['edit_fee_books']     ?? 0));
        $editHouseReg   = max(0.0, (float) ($_POST['edit_fee_house_reg'] ?? 0));
        $editAmount     = round($editAdmission + $editActivity + $editBooks + $editHouseReg, 2);
        // Allow manual total override if no breakdown was entered
        if ($editAmount <= 0) {
            $editAmount = (float) ($_POST['edit_amount'] ?? 0);
        }
        $editDate    = trim((string) ($_POST['edit_date'] ?? ''));
        $editMethod  = trim((string) ($_POST['edit_method'] ?? 'Cash'));
        $editOr      = trim((string) ($_POST['edit_or'] ?? ''));
        $editCashier = trim((string) ($_POST['edit_cashier'] ?? ''));

        if ($editId <= 0) throw new RuntimeException('Invalid payment record.');
        if ($editAmount <= 0) throw new RuntimeException('Total amount must be greater than zero.');
        if ($editDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $editDate)) throw new RuntimeException('Invalid payment date.');
        if (!in_array($editMethod, ['Cash','Installment'], true)) throw new RuntimeException('Invalid payment method.');

        $upd = $connection->prepare(
            'UPDATE backaccount_payment_records
             SET payment_amount = ?, payment_date = ?, payment_method = ?, or_number = ?, cashier_name = ?,
                 fee_admission = ?, fee_activity = ?, fee_books = ?, fee_house_reg = ?
             WHERE id = ?'
        );
        $upd->bind_param('dssssddddi', $editAmount, $editDate, $editMethod, $editOr, $editCashier,
            $editAdmission, $editActivity, $editBooks, $editHouseReg, $editId);
        $upd->execute();

        flash_set('success', 'Payment record #' . $editId . ' has been updated successfully.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    redirect_to('history.php?' . http_build_query(array_filter(['q'=>$search,'from'=>$dateFrom,'to'=>$dateTo,'method'=>$methodFilter,'sy'=>$syFilter,'page'=>$page])));
}

$flash = flash_get();

function build_page_url(int $pg, string $q, string $from, string $to, string $method, string $sy): string
{
    $args = array_filter(['page' => $pg, 'q' => $q, 'from' => $from, 'to' => $to, 'method' => $method, 'sy' => $sy]);
    return 'history.php' . ($args ? '?' . http_build_query($args) : '');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment History | ITFA Cashier</title>
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
    <style>
        .stat-card { transition: transform 0.15s, box-shadow 0.15s; }
        .stat-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">

<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <!-- ── Sidebar ──────────────────────────────────────────────── -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- ── Main ─────────────────────────────────────────────────── -->
    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Payment History</h1>
            <p class="text-slate-500 mt-2">All collected enrollment payments · S.Y. <?= h($activeSchoolYearLabel) ?></p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; <?= h((string) ($user['full_name'] ?? 'Cashier')) ?></p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium flex items-center gap-3
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- ── Stat Cards ─────────────────────────────────────────── -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">

            <!-- Today's Collection -->
            <div class="stat-card rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-0.5 rounded-full"><?= (int)$stats['today_count'] ?> txn</span>
                </div>
                <p class="text-2xl font-extrabold text-slate-900 mt-3">₱<?= number_format((float)$stats['today_total'], 2) ?></p>
                <p class="text-xs text-slate-400 mt-1 font-medium">Today's Collection</p>
            </div>

            <!-- This Month -->
            <div class="stat-card rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-xl bg-violet-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <span class="text-xs font-semibold text-violet-600 bg-violet-50 px-2 py-0.5 rounded-full"><?= date('M Y') ?></span>
                </div>
                <p class="text-2xl font-extrabold text-slate-900 mt-3">₱<?= number_format((float)$stats['month_total'], 2) ?></p>
                <p class="text-xs text-slate-400 mt-1 font-medium">This Month's Collection</p>
            </div>

            <!-- This School Year -->
            <div class="stat-card rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                    </div>
                    <span class="text-xs font-semibold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">S.Y. <?= h($activeSchoolYearLabel) ?></span>
                </div>
                <p class="text-2xl font-extrabold text-slate-900 mt-3">₱<?= number_format((float)$stats['sy_total'], 2) ?></p>
                <p class="text-xs text-slate-400 mt-1 font-medium">School Year Collection</p>
            <div class="stat-card rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-xl bg-violet-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <span class="text-xs font-semibold text-violet-600 bg-violet-50 px-2 py-0.5 rounded-full"><?= date('M Y') ?></span>
                </div>
                <p class="text-2xl font-extrabold text-slate-900 mt-3">₱<?= number_format((float)$stats['month_total'], 2) ?></p>
                <p class="text-xs text-slate-400 mt-1 font-medium">This Month's Collection</p>
            </div>

            <!-- This School Year -->
            <div class="stat-card rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                    </div>
                    <span class="text-xs font-semibold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">S.Y. <?= h($activeSchoolYearLabel) ?></span>
                </div>
                <p class="text-2xl font-extrabold text-slate-900 mt-3">₱<?= number_format((float)$stats['sy_total'], 2) ?></p>
                <p class="text-xs text-slate-400 mt-1 font-medium">School Year Collection</p>
            </div>

            <!-- Grand Total -->
            <div class="stat-card rounded-2xl bg-green-700 border border-green-900 p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <span class="text-xs font-semibold text-green-200 bg-white/10 px-2 py-0.5 rounded-full"><?= (int)$stats['grand_count'] ?> records</span>
                </div>
                <p class="text-2xl font-extrabold text-white mt-3">₱<?= number_format((float)$stats['grand_total'], 2) ?></p>
                <p class="text-xs text-green-300 mt-1 font-medium">All-time Total Collection</p>
            </div>

        </div>

        <!-- ── Filters ──────────────────────────────────────────────── -->
        <form method="GET" action="" class="bg-white rounded-2xl border border-slate-200 p-4 mb-5 shadow-sm">
            <div class="flex flex-wrap gap-3 items-end">
                <!-- Search -->
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Search</label>
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>
                        <input type="text" name="q" value="<?= h($search) ?>"
                               placeholder="Name, Student ID, OR No…"
                               class="w-full pl-9 pr-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
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
                <!-- School Year -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                    <select name="sy" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">All S.Y.</option>
                        <?php foreach ($allSchoolYears as $sy): ?>
                        <option value="<?= h($sy) ?>" <?= $syFilter === $sy ? 'selected' : '' ?>>
                            S.Y. <?= h($sy) ?><?= $sy === $activeSchoolYearLabel ? ' (Active)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Method -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Method</label>
                    <select name="method" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="">All Methods</option>
                        <option value="Cash"        <?= $methodFilter==='Cash'        ? 'selected' : '' ?>>Cash</option>
                        <option value="Installment" <?= $methodFilter==='Installment' ? 'selected' : '' ?>>Installment</option>
                    </select>
                </div>
                <!-- Actions -->
                <div class="flex gap-2">
                    <button type="submit" class="py-2 px-4 bg-green-700 text-white text-sm font-semibold rounded-xl hover:bg-green-800 transition-colors">Filter</button>
                    <?php if ($hasActiveFilter): ?>
                    <a href="history.php" class="py-2 px-4 border border-slate-200 bg-white text-sm font-semibold text-slate-600 rounded-xl hover:bg-slate-50">Clear</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Always-visible summary bar -->
            <div class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-4 text-sm">
                <span class="text-slate-500">
                    <?php if ($syFilter !== ''): ?>
                        S.Y. <strong><?= h($syFilter) ?></strong> ·
                    <?php else: ?>
                        All school years ·
                    <?php endif; ?>
                    <?= $totalRows ?> record<?= $totalRows !== 1 ? 's' : '' ?>
                </span>
                <span class="font-bold text-green-700">Total collected: ₱<?= number_format($filteredTotal, 2) ?></span>
            </div>
        </form>

        <!-- ── Table ─────────────────────────────────────────────────── -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">

            <?php if ($rows === []): ?>
            <div class="flex flex-col items-center justify-center py-16 text-slate-400">
                <svg class="w-12 h-12 mb-3 opacity-40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <p class="font-semibold">No payment records found.</p>
                <p class="text-sm mt-1">Try adjusting the filters.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-100">
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">OR No.</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Student</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Dept / Level / Section</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Method</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Amount</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Date &amp; Time</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Cashier</th>
                            <th class="px-4 py-3.5 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($rows as $row): ?>
                        <?php
                            $orNo      = $row['or_number'] ?? ('ITFA-' . date('Y', strtotime($row['payment_date'])) . '-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT));
                            $dept      = (string) ($row['Department'] ?? '');
                            $grade     = trim((string) ($row['grade_name']   ?? ''));
                            $sec       = trim((string) ($row['section_name'] ?? ''));
                            $cashier   = (string) ($row['cashier_name'] ?? '—');
                            $method    = (string) ($row['payment_method'] ?? 'Cash');
                            $ts        = strtotime((string) $row['payment_date']);
                            $dateStr   = date('M d, Y', $ts);
                            $timeStr   = date('h:i A', $ts);
                            $reprintUrl = h(app_url('cashier/receipt.php') . '?reprint=' . (int)$row['id']);
                        ?>
                        <tr class="hover:bg-green-50/40 transition-colors group">
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded">
                                    <?= h($orNo) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-800"><?= h((string)$row['name']) ?></p>
                                <p class="text-xs text-slate-400 font-mono"><?= h((string)$row['student_id']) ?></p>
                            </td>
                            <td class="px-4 py-3 text-slate-600 text-xs">
                                <?php if ($dept !== ''): ?>
                                    <span class="font-medium text-slate-700"><?= h($dept) ?></span><br>
                                <?php endif; ?>
                                <?= h($grade) ?>
                                <?= $sec !== '' && $sec !== '-' ? ' · ' . h($sec) : '' ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="badge <?= $method === 'Cash' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-green-50 text-green-700 border border-green-200' ?>">
                                    <?= h($method) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-extrabold text-green-700 tabular-nums">
                                ₱<?= number_format((float)$row['payment_amount'], 2) ?>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                <p class="font-medium text-slate-700"><?= h($dateStr) ?></p>
                                <p><?= h($timeStr) ?></p>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500"><?= h($cashier) ?></td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <a href="<?= $reprintUrl ?>"
                                       target="_blank"
                                       class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 hover:bg-green-700 hover:text-white hover:border-green-600 text-xs font-semibold px-3 py-1.5 transition-all">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-6 0v4H9v-4h6z"/>
                                        </svg>
                                        Reprint
                                    </a>
                                    <button type="button"
                                            onclick="openPasswordGate(this)"
                                            data-id="<?= (int)$row['id'] ?>"
                                            data-name="<?= h((string)$row['name']) ?>"
                                            data-amount="<?= h(number_format((float)$row['payment_amount'],2,'.','')) ?>"
                                            data-date="<?= h(date('Y-m-d', $ts)) ?>"
                                            data-method="<?= h($method) ?>"
                                            data-or="<?= h($orNo) ?>"
                                            data-cashier="<?= h($cashier) ?>"
                                            data-fee-admission="<?= h(number_format((float)($row['fee_admission']??0),2,'.','')) ?>"
                                            data-fee-activity="<?= h(number_format((float)($row['fee_activity']??0),2,'.','')) ?>"
                                            data-fee-books="<?= h(number_format((float)($row['fee_books']??0),2,'.','')) ?>"
                                            data-fee-house-reg="<?= h(number_format((float)($row['fee_house_reg']??0),2,'.','')) ?>"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 hover:bg-amber-600 hover:text-white hover:border-amber-600 text-xs font-semibold px-3 py-1.5 transition-all">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        Edit
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <!-- Table footer with running total of visible page -->
                    <tfoot>
                        <tr class="bg-slate-50 border-t-2 border-slate-200">
                            <td colspan="4" class="px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                                Page <?= $page ?> of <?= max(1,$totalPages) ?> · <?= $totalRows ?> record<?= $totalRows!==1?'s':'' ?> total
                            </td>
                            <td class="px-4 py-3 text-right text-sm font-extrabold text-green-700" colspan="4">
                                <?php
                                $pageSum = array_sum(array_column($rows, 'payment_amount'));
                                ?>
                                Page subtotal: ₱<?= number_format($pageSum, 2) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 bg-white">
                <p class="text-xs text-slate-500">
                    Showing <?= (($page-1)*$perPage)+1 ?>–<?= min($page*$perPage,$totalRows) ?> of <?= $totalRows ?>
                </p>
                <div class="flex gap-1">
                    <?php if ($page > 1): ?>
                    <a href="<?= h(build_page_url($page-1,$search,$dateFrom,$dateTo,$methodFilter,$syFilter)) ?>"
                       class="px-3 py-1.5 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50">← Prev</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end   = min($totalPages, $page + 2);
                    for ($pg = $start; $pg <= $end; $pg++):
                    ?>
                    <a href="<?= h(build_page_url($pg,$search,$dateFrom,$dateTo,$methodFilter,$syFilter)) ?>"
                       class="px-3 py-1.5 text-xs font-semibold border rounded-lg <?= $pg===$page ? 'bg-green-700 text-white border-green-600' : 'border-slate-200 hover:bg-slate-50' ?>">
                        <?= $pg ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="<?= h(build_page_url($page+1,$search,$dateFrom,$dateTo,$methodFilter,$syFilter)) ?>"
                       class="px-3 py-1.5 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50">Next →</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div><!-- /table card -->

    </main>
</div>

<!-- ── Password Gate Modal ──────────────────────────────────────────────────── -->
<div id="passwordGateModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm p-7">
        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <div>
                <h3 class="font-extrabold text-slate-800">Authorization Required</h3>
                <p class="text-xs text-slate-500">Enter the edit password to continue</p>
            </div>
        </div>
        <div id="pwError" class="hidden mb-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium px-4 py-2"></div>
        <div class="mb-4">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Password</label>
            <input type="password" id="gatePasswordInput" placeholder="Enter password"
                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400">
        </div>
        <div class="flex gap-2">
            <button type="button" onclick="checkGatePassword()"
                    class="flex-1 rounded-xl bg-amber-600 text-white font-semibold py-2.5 text-sm hover:bg-amber-700 transition-colors">
                Unlock
            </button>
            <button type="button" onclick="closePasswordGate()"
                    class="rounded-xl border border-slate-200 bg-white text-slate-600 font-semibold py-2.5 px-4 text-sm hover:bg-slate-50 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ── Edit Payment Modal ───────────────────────────────────────────────────── -->
<div id="editPaymentModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="font-extrabold text-slate-800">Edit Payment</h3>
                    <p class="text-xs text-slate-500 mt-0.5" id="editStudentLabel"></p>
                </div>
                <button type="button" onclick="closeEditPaymentModal()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
            </div>
            <!-- In-modal search: jump to another payment without closing -->
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>
                <input type="text" id="editSearchInput" autocomplete="off"
                       placeholder="Search another payment by name, OR no., or ID…"
                       class="w-full rounded-xl border border-slate-200 pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                <div id="editSearchResults" class="hidden absolute z-20 mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
        </div>
        <form method="post" action="history.php<?= $page > 1 || $search || $syFilter ? '?' . http_build_query(array_filter(['q'=>$search,'from'=>$dateFrom,'to'=>$dateTo,'method'=>$methodFilter,'sy'=>$syFilter,'page'=>$page])) : '' ?>" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_payment">
            <input type="hidden" name="edit_password" id="editPasswordCarry">
            <input type="hidden" name="edit_id" id="editId">
            <input type="hidden" name="edit_amount" id="editAmountHidden">

            <!-- Fee Breakdown -->
            <div>
                <p class="text-xs font-extrabold uppercase tracking-widest text-green-600 mb-2">Fee Breakdown</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Admission Fee (₱)</label>
                        <input type="number" name="edit_fee_admission" id="editFeeAdmission" step="0.01" min="0" value="0"
                               oninput="recalcTotal()"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Activity Fee (₱)</label>
                        <input type="number" name="edit_fee_activity" id="editFeeActivity" step="0.01" min="0" value="0"
                               oninput="recalcTotal()"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Books Fee (₱)</label>
                        <input type="number" name="edit_fee_books" id="editFeeBooks" step="0.01" min="0" value="0"
                               oninput="recalcTotal()"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">House Reg. Fee (₱)</label>
                        <input type="number" name="edit_fee_house_reg" id="editFeeHouseReg" step="0.01" min="0" value="0"
                               oninput="recalcTotal()"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                </div>
                <div class="mt-3 rounded-xl bg-green-50 border border-green-200 px-4 py-2.5 flex items-center justify-between">
                    <span class="text-xs font-semibold text-green-700">Total Amount</span>
                    <span class="text-lg font-extrabold text-green-800" id="editTotalDisplay">₱0.00</span>
                </div>
                <p class="text-[10px] text-slate-400 mt-1">If all fees are 0, enter the total below manually.</p>
            </div>

            <!-- Manual total override -->
            <div id="manualTotalWrap">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Manual Total Amount (₱) <span class="text-slate-400 font-normal">&mdash; used only when all fees above are 0</span></label>
                <input type="number" name="edit_amount" id="editAmount" step="0.01" min="0.01"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>

            <div class="border-t border-slate-100 pt-4">
                <p class="text-xs font-extrabold uppercase tracking-widest text-slate-500 mb-2">Payment Details</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Payment Date</label>
                        <input type="date" name="edit_date" id="editDate" required
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Method</label>
                        <select name="edit_method" id="editMethod"
                                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                            <option value="Cash">Cash</option>
                            <option value="Installment">Installment</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">OR Number</label>
                        <input type="text" name="edit_or" id="editOr"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cashier Name</label>
                        <input type="text" name="edit_cashier" id="editCashier"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                </div>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 rounded-xl bg-green-700 text-white font-semibold py-2.5 text-sm hover:bg-green-800 transition-colors">
                    Save Changes
                </button>
                <button type="button" onclick="closeEditPaymentModal()"
                        class="rounded-xl border border-slate-200 bg-white text-slate-600 font-semibold py-2.5 px-4 text-sm hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const GATE_PASSWORD = 'TAIMIYANS';
    let _pendingData  = null;
    let _unlockedPass = '';

    window.openPasswordGate = function (btn) {
        _pendingData = {
            id:           btn.dataset.id,
            name:         btn.dataset.name,
            amount:       btn.dataset.amount,
            date:         btn.dataset.date,
            method:       btn.dataset.method,
            or:           btn.dataset.or,
            cashier:      btn.dataset.cashier,
            feeAdmission: btn.dataset.feeAdmission,
            feeActivity:  btn.dataset.feeActivity,
            feeBooks:     btn.dataset.feeBooks,
            feeHouseReg:  btn.dataset.feeHouseReg,
        };
        document.getElementById('gatePasswordInput').value = '';
        document.getElementById('pwError').classList.add('hidden');
        document.getElementById('passwordGateModal').style.display = 'flex';
        setTimeout(() => document.getElementById('gatePasswordInput').focus(), 80);
    };

    window.closePasswordGate = function () {
        document.getElementById('passwordGateModal').style.display = 'none';
        _pendingData = null;
    };

    window.checkGatePassword = function () {
        const entered = document.getElementById('gatePasswordInput').value;
        if (entered !== GATE_PASSWORD) {
            const err = document.getElementById('pwError');
            err.textContent = 'Incorrect password. Please try again.';
            err.classList.remove('hidden');
            document.getElementById('gatePasswordInput').value = '';
            document.getElementById('gatePasswordInput').focus();
            return;
        }
        _unlockedPass = entered;
        document.getElementById('passwordGateModal').style.display = 'none';
        openEditPaymentModal(_pendingData);
    };

    function openEditPaymentModal(data) {
        document.getElementById('editStudentLabel').textContent = data.name + '  ·  ID: ' + data.id;
        document.getElementById('editId').value            = data.id;
        document.getElementById('editFeeAdmission').value  = data.feeAdmission;
        document.getElementById('editFeeActivity').value   = data.feeActivity;
        document.getElementById('editFeeBooks').value      = data.feeBooks;
        document.getElementById('editFeeHouseReg').value   = data.feeHouseReg;
        document.getElementById('editAmount').value        = data.amount;
        document.getElementById('editDate').value          = data.date;
        document.getElementById('editMethod').value        = data.method;
        document.getElementById('editOr').value            = data.or;
        document.getElementById('editCashier').value       = data.cashier;
        document.getElementById('editPasswordCarry').value = _unlockedPass;
        recalcTotal();
        var _si = document.getElementById('editSearchInput');
        var _sr = document.getElementById('editSearchResults');
        if (_si) { _si.value = ''; }
        if (_sr) { _sr.classList.add('hidden'); _sr.innerHTML = ''; }
        document.getElementById('editPaymentModal').style.display = 'flex';
    }

    window.closeEditPaymentModal = function () {
        document.getElementById('editPaymentModal').style.display = 'none';
    };

    // Keyboard shortcut — Enter on password input
    document.getElementById('gatePasswordInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); window.checkGatePassword(); }
    });

    // Backdrop click closes modals
    ['passwordGateModal','editPaymentModal'].forEach(function (id) {
        document.getElementById(id).addEventListener('click', function (e) {
            if (e.target === this) this.style.display = 'none';
        });
    });

    window.recalcTotal = function () {
        const a = parseFloat(document.getElementById('editFeeAdmission').value) || 0;
        const b = parseFloat(document.getElementById('editFeeActivity').value)  || 0;
        const c = parseFloat(document.getElementById('editFeeBooks').value)     || 0;
        const d = parseFloat(document.getElementById('editFeeHouseReg').value)  || 0;
        const total = a + b + c + d;
        document.getElementById('editTotalDisplay').textContent = '₱' + total.toFixed(2);
        // Show/hide manual total input
        document.getElementById('manualTotalWrap').style.display = total > 0 ? 'none' : '';
    };

    // ── In-modal search: jump to another payment without closing ──────────────
    const _records = Array.from(document.querySelectorAll('button[data-fee-admission]')).map(function (b) {
        return {
            id: b.dataset.id, name: b.dataset.name, amount: b.dataset.amount, date: b.dataset.date,
            method: b.dataset.method, or: b.dataset.or, cashier: b.dataset.cashier,
            feeAdmission: b.dataset.feeAdmission, feeActivity: b.dataset.feeActivity,
            feeBooks: b.dataset.feeBooks, feeHouseReg: b.dataset.feeHouseReg,
        };
    });
    const _sInput   = document.getElementById('editSearchInput');
    const _sResults = document.getElementById('editSearchResults');

    function _renderResults(q) {
        if (q === '') { _sResults.classList.add('hidden'); _sResults.innerHTML = ''; return; }
        const m = _records.filter(function (r) {
            return (r.name || '').toLowerCase().includes(q)
                || (r.or || '').toLowerCase().includes(q)
                || (r.id || '').toLowerCase().includes(q);
        }).slice(0, 15);
        if (m.length === 0) {
            _sResults.innerHTML = '<div class="px-3 py-2 text-xs text-slate-400">No matches on this page. Use the main search above to load other records.</div>';
        } else {
            _sResults.innerHTML = m.map(function (r) {
                return '<button type="button" class="w-full text-left px-3 py-2 hover:bg-green-50 border-b border-slate-50 last:border-0" data-idx="' + _records.indexOf(r) + '">'
                    + '<span class="font-semibold text-sm text-slate-800">' + (r.name || '') + '</span>'
                    + '<span class="block text-[11px] text-slate-400 font-mono">' + (r.or || '') + ' · ID ' + (r.id || '') + ' · ₱' + (r.amount || '') + '</span>'
                    + '</button>';
            }).join('');
        }
        _sResults.classList.remove('hidden');
    }

    if (_sInput) {
        _sInput.addEventListener('input', function () { _renderResults(this.value.trim().toLowerCase()); });
        _sResults.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-idx]');
            if (!btn) return;
            const rec = _records[parseInt(btn.dataset.idx, 10)];
            if (rec) {
                openEditPaymentModal(rec);
                _sResults.classList.add('hidden');
                _sInput.value = '';
            }
        });
    }
}());
</script>

</body>
</html>
