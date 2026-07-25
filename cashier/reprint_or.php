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

// ── All school years (for filter dropdown) ───────────────────────────────────
$allSchoolYears = [];
try {
    $syListStmt = $connection->prepare(
        'SELECT School_year FROM schoolyear ORDER BY School_year_id DESC'
    );
    $syListStmt->execute();
    foreach (stmt_fetch_all_assoc($syListStmt) as $r) {
        $allSchoolYears[] = (string) $r['School_year'];
    }
} catch (Throwable) {}
if (empty($allSchoolYears)) {
    $allSchoolYears[] = $activeSchoolYearLabel;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim((string) ($_GET['q']    ?? ''));
$syFilter = isset($_GET['sy']) ? trim((string) $_GET['sy']) : $activeSchoolYearLabel;
$page     = max(1, to_int($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = '(bpr.name LIKE ? OR bpr.student_id LIKE ? OR bpr.or_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
    $types   .= 'sss';
}
if ($syFilter !== '') {
    $where[]  = 'bpr.school_year = ?';
    $params[] = $syFilter;
    $types   .= 's';
}

$whereClause = implode(' AND ', $where);

// Count
$countStmt = $connection->prepare(
    "SELECT COUNT(*) AS cnt FROM backaccount_payment_records bpr WHERE $whereClause"
);
if ($types !== '') {
    bind_dynamic_params($countStmt, $types, $params);
}
$countStmt->execute();
$totalRows  = (int) (stmt_fetch_assoc($countStmt)['cnt'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }

// Fetch rows
$dataStmt = $connection->prepare(
    "SELECT bpr.id, bpr.student_id, bpr.name, bpr.payment_amount,
            bpr.payment_date, bpr.or_number, bpr.payment_method,
            bpr.school_year, bpr.cashier_name,
            en.Department,
            IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
            IFNULL(sc.Section_name, en.Department_section) AS section_name
     FROM backaccount_payment_records bpr
     LEFT JOIN enrollment en ON en.id = bpr.enrollment_id
     LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
     LEFT JOIN section sc   ON CAST(sc.Section_id AS CHAR) = en.Department_section
     WHERE $whereClause
     ORDER BY bpr.id DESC
     LIMIT ? OFFSET ?"
);
$allParams = array_merge($params, [$perPage, $offset]);
bind_dynamic_params($dataStmt, $types . 'ii', $allParams);
$dataStmt->execute();
$rows = stmt_fetch_all_assoc($dataStmt);

$flash = flash_get();

function reprint_page_url(int $pg, string $q, string $sy): string
{
    $args = array_filter(['page' => $pg, 'q' => $q, 'sy' => $sy], fn($v) => $v !== '' && $v !== 0);
    return 'reprint_or.php' . ($args ? '?' . http_build_query($args) : '');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reprint Official Receipt | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: { brand: { 50:'#f0f7f2', 600:'#166534', 700:'#0f4d28' } },
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
<div class="min-h-screen lg:grid lg:grid-cols-[260px_1fr]">

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8">

        <!-- Page header -->
        <div class="mb-6">
            <p class="text-xs uppercase tracking-widest text-green-500 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Reprint Official Receipt</h1>
            <p class="text-slate-500 mt-1 text-sm">Search for a processed payment and reprint the OR.</p>
        </div>

        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 text-sm
                <?= $flash['type'] === 'success'
                    ? 'bg-emerald-50 border-emerald-300 text-emerald-800'
                    : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="reprint_or.php"
              class="bg-white rounded-2xl border border-slate-200 shadow-panel p-5 mb-6 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1 uppercase tracking-wide">Search</label>
                <input type="text" name="q" value="<?= h($search) ?>"
                       placeholder="Name, Student ID, or OR No."
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div class="min-w-[160px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1 uppercase tracking-wide">School Year</label>
                <select name="sy" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Years</option>
                    <?php foreach ($allSchoolYears as $sy): ?>
                        <option value="<?= h($sy) ?>" <?= $syFilter === $sy ? 'selected' : '' ?>><?= h($sy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"
                    class="rounded-xl bg-green-600 hover:bg-green-700 text-white px-5 py-2.5 text-sm font-semibold transition-colors">
                Search
            </button>
            <a href="reprint_or.php"
               class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 px-5 py-2.5 text-sm font-semibold transition-colors">
                Clear
            </a>
        </form>

        <!-- Results table -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-panel overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-bold text-base">Payment Records</h2>
                <span class="text-sm text-slate-500"><?= h((string) $totalRows) ?> record<?= $totalRows !== 1 ? 's' : '' ?> found</span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 uppercase text-xs tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">OR Number</th>
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">Department / Section</th>
                            <th class="px-4 py-3 text-left">Amount</th>
                            <th class="px-4 py-3 text-left">Method</th>
                            <th class="px-4 py-3 text-left">Date Paid</th>
                            <th class="px-4 py-3 text-left">Cashier</th>
                            <th class="px-4 py-3 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-slate-400 font-medium">
                                    No payment records found<?= $search !== '' ? ' for "' . h($search) . '"' : '' ?>.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $orNo       = (string) ($row['or_number'] ?? '—');
                            $name       = (string) ($row['name'] ?? '—');
                            $studentId  = (string) ($row['student_id'] ?? '');
                            $dept       = (string) ($row['Department'] ?? '—');
                            $grade      = (string) ($row['grade_name'] ?? '');
                            $section    = (string) ($row['section_name'] ?? '');
                            $amount     = (float)  ($row['payment_amount'] ?? 0);
                            $method     = (string) ($row['payment_method'] ?? 'Cash');
                            $paidAt     = (string) ($row['payment_date'] ?? '');
                            $cashier    = (string) ($row['cashier_name'] ?? '');
                            $recordId   = (int)    ($row['id'] ?? 0);
                            $paidFmt    = $paidAt !== '' ? date('M d, Y g:i A', strtotime($paidAt)) : '—';
                            $deptLine   = trim($dept . ($grade !== '' ? ' · ' . $grade : '') . ($section !== '' ? ' – ' . $section : ''));
                            ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs font-semibold text-green-700 bg-green-50 border border-green-200 rounded-lg px-2 py-1">
                                        <?= h($orNo) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold"><?= h($name) ?></p>
                                    <p class="text-xs text-slate-400">ID: <?= h($studentId) ?></p>
                                </td>
                                <td class="px-4 py-3 text-slate-600"><?= h($deptLine ?: '—') ?></td>
                                <td class="px-4 py-3 font-bold text-emerald-700">
                                    ₱<?= h(number_format($amount, 2)) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs rounded-full px-2 py-0.5 font-semibold
                                        <?= $method === 'Cash'
                                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                            : 'bg-sky-50 text-sky-700 border border-sky-200' ?>">
                                        <?= h($method) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600"><?= h($paidFmt) ?></td>
                                <td class="px-4 py-3 text-xs text-slate-500"><?= h($cashier) ?></td>
                                <td class="px-4 py-3">
                                    <a href="<?= h(app_url('cashier/receipt.php') . '?reprint=' . $recordId) ?>"
                                       target="_blank"
                                       class="inline-flex items-center gap-1.5 rounded-xl bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 text-xs font-semibold transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                        </svg>
                                        Reprint OR
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between flex-wrap gap-3 text-sm">
                <p class="text-slate-500">
                    Page <?= h((string) $page) ?> of <?= h((string) $totalPages) ?>
                    &nbsp;·&nbsp; <?= h((string) $totalRows) ?> total records
                </p>
                <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?= h(reprint_page_url($page - 1, $search, $syFilter)) ?>"
                           class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 font-semibold hover:bg-slate-50">Previous</a>
                    <?php else: ?>
                        <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= h(reprint_page_url($page + 1, $search, $syFilter)) ?>"
                           class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 font-semibold hover:bg-slate-50">Next</a>
                    <?php else: ?>
                        <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Next</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>
</body>
</html>
