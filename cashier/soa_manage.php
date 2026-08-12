<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/soa_service.php';

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
$schemaReady = soa_schema_ready($connection);

// ── All school years (dropdown) ───────────────────────────────────────────────
$allSY = [];
try {
    $r = $connection->query('SELECT School_year_id, School_year FROM schoolyear ORDER BY School_year_id DESC');
    if ($r) { while ($x = $r->fetch_assoc()) { $allSY[(int) $x['School_year_id']] = (string) $x['School_year']; } }
} catch (Throwable) {}

// ── Grade levels & sections (filter dropdowns) ────────────────────────────────
$allGrades = [];
try {
    $r = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel');
    if ($r) { while ($x = $r->fetch_assoc()) { $allGrades[(int) $x['Gradelevel_id']] = trim((string) $x['Gradelevel']); } }
} catch (Throwable) {}
$allSections = [];   // [ ['id'=>Section_id, 'name'=>Section_name, 'grade'=>Gradelevel_id], … ]
try {
    $r = $connection->query('SELECT Section_id, Section_name, Gradelevel_id FROM section ORDER BY Section_name');
    if ($r) { while ($x = $r->fetch_assoc()) { $allSections[] = ['id' => (string) $x['Section_id'], 'name' => (string) $x['Section_name'], 'grade' => (int) $x['Gradelevel_id']]; } }
} catch (Throwable) {}

// Month (term_no → month label), read from the real payment schedule.
$termLabels = [];
try {
    $r = $connection->query('SELECT DISTINCT term_no, month_label FROM payment_schedule WHERE term_no BETWEEN 1 AND 12 ORDER BY term_no');
    if ($r) { while ($x = $r->fetch_assoc()) { $termLabels[(int) $x['term_no']] = (string) $x['month_label']; } }
} catch (Throwable) {}
if ($termLabels === []) { for ($i = 1; $i <= 10; $i++) { $termLabels[$i] = ''; } }  // fallback

// ── POST: delete ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('soa_manage.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $back   = 'soa_manage.php' . (!empty($_POST['qs']) ? '?' . (string) $_POST['qs'] : '');

    try {
        $deleted = 0;
        if ($action === 'delete') {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))), static fn($v) => $v > 0));
            if ($ids === []) {
                throw new RuntimeException('No SOA selected.');
            }
            $inList  = implode(',', $ids);
            $connection->query("DELETE FROM soa_master WHERE id IN ($inList)");
            $deleted = $connection->affected_rows;
        } elseif ($action === 'delete_batch') {
            $batch = trim((string) ($_POST['batch_id'] ?? ''));
            if ($batch === '') {
                throw new RuntimeException('No batch specified.');
            }
            $stmt = $connection->prepare('DELETE FROM soa_master WHERE batch_id = ?');
            $stmt->bind_param('s', $batch);
            $stmt->execute();
            $deleted = $connection->affected_rows;
        } else {
            throw new RuntimeException('Unknown action.');
        }

        // Audit
        try {
            $actorId = (int) ($user['id'] ?? 0);
            $after   = json_encode(['action' => $action, 'deleted' => $deleted]);
            $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $a = 'DELETE_SOA'; $ent = 'soa_master'; $eid = '';
            $log = $connection->prepare(
                'INSERT INTO financial_audit_logs (actor_id, actor_name, action, entity, entity_id, after_json, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $log->bind_param('issssss', $actorId, $cashierName, $a, $ent, $eid, $after, $ip);
            $log->execute();
        } catch (Throwable) {}

        flash_set('success', $deleted . ' SOA document(s) cleared.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to($back);
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim((string) ($_GET['q'] ?? ''));
$syFilter = isset($_GET['sy']) ? (int) $_GET['sy'] : $syId;   // 0 = all
$gradeFilter   = (int) ($_GET['grade'] ?? 0);                 // 0 = all
$sectionFilter = trim((string) ($_GET['section'] ?? ''));    // '' = all (Section_id)
$monthFilter   = (int) ($_GET['month'] ?? 0);                // 0 = all; else term_no (M1..M10)
$batch    = trim((string) ($_GET['batch'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo   = trim((string) ($_GET['to'] ?? ''));
$layout   = (($_GET['layout'] ?? '2up') === '1up') ? '1up' : '2up';
$page     = max(1, to_int($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
$types  = '';
if ($syFilter > 0)  { $where[] = 'sa.school_year_id = ?'; $params[] = $syFilter; $types .= 'i'; }
if ($gradeFilter > 0)   { $where[] = 'en.Department_gradelevel = ?'; $params[] = $gradeFilter;   $types .= 'i'; }
if ($sectionFilter !== '') { $where[] = 'en.Department_section = ?'; $params[] = $sectionFilter; $types .= 's'; }
if ($monthFilter > 0) { $where[] = 'EXISTS (SELECT 1 FROM soa_details sd WHERE sd.soa_id = sm.id AND sd.term_no = ?)'; $params[] = $monthFilter; $types .= 'i'; }
if ($batch !== '')  { $where[] = 'sm.batch_id = ?';       $params[] = $batch;    $types .= 's'; }
if ($dateFrom !== '') { $where[] = 'DATE(sm.generated_at) >= ?'; $params[] = $dateFrom; $types .= 's'; }
if ($dateTo !== '')   { $where[] = 'DATE(sm.generated_at) <= ?'; $params[] = $dateTo;   $types .= 's'; }
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(sm.soa_number LIKE ? OR sa.student_id LIKE ? OR p.surname LIKE ? OR p.firstname LIKE ? OR osp.surname LIKE ? OR osp.firstname LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}
$wc = implode(' AND ', $where);

$joins = "FROM soa_master sm
          JOIN student_assessment sa ON sa.id = sm.assessment_id
          JOIN enrollment en ON en.id = sa.enrollment_id
          LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
          LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
          LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
          LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section";

$rows = [];
$totalRows = 0;
$batches = [];
if ($schemaReady) {
    // Count
    $cStmt = $connection->prepare("SELECT COUNT(*) AS c $joins WHERE $wc");
    if ($types !== '') { bind_dynamic_params($cStmt, $types, $params); }
    $cStmt->execute();
    $totalRows = (int) (stmt_fetch_assoc($cStmt)['c'] ?? 0);

    // Rows
    $sql = "SELECT sm.id, sm.soa_number, sm.scope, sm.scope_ref, sm.total_due, sm.batch_id,
                   sm.generated_by, sm.generated_at, sm.selected_terms_json,
                   en.Department, sa.student_id,
                   COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname)) AS full_name,
                   IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                   IFNULL(sc.Section_name, en.Department_section) AS section_name
            $joins WHERE $wc ORDER BY sm.id DESC LIMIT ? OFFSET ?";
    $dStmt = $connection->prepare($sql);
    bind_dynamic_params($dStmt, $types . 'ii', array_merge($params, [$perPage, $offset]));
    $dStmt->execute();
    $rows = stmt_fetch_all_assoc($dStmt);

    // Batches for dropdown / quick actions
    $bStmt = $connection->prepare(
        "SELECT sm.batch_id, COUNT(*) AS n, MIN(sm.generated_at) AS first_at, MAX(sm.scope) AS scope
         FROM soa_master sm JOIN student_assessment sa ON sa.id = sm.assessment_id
         WHERE sm.batch_id IS NOT NULL" . ($syFilter > 0 ? ' AND sa.school_year_id = ?' : '') . "
         GROUP BY sm.batch_id ORDER BY first_at DESC LIMIT 50"
    );
    if ($syFilter > 0) { $bStmt->bind_param('i', $syFilter); }
    $bStmt->execute();
    $batches = stmt_fetch_all_assoc($bStmt);
}

// All SOA ids in the currently-filtered batch (so "reprint whole batch" covers every page)
$batchAllIds = [];
if ($schemaReady && $batch !== '') {
    $bidStmt = $connection->prepare('SELECT id FROM soa_master WHERE batch_id = ? ORDER BY id');
    $bidStmt->bind_param('s', $batch);
    $bidStmt->execute();
    foreach (stmt_fetch_all_assoc($bidStmt) as $x) { $batchAllIds[] = (int) $x['id']; }
}
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Current querystring (for redirects / pagination)
$qsArr = array_filter(['q'=>$search, 'sy'=>$syFilter ?: '0', 'grade'=>$gradeFilter ?: '', 'section'=>$sectionFilter, 'month'=>$monthFilter ?: '', 'batch'=>$batch, 'from'=>$dateFrom, 'to'=>$dateTo, 'layout'=>$layout], static fn($v) => $v !== '' && $v !== null);
$qs    = http_build_query($qsArr);
$flash = flash_get();

function sm_page_url(array $base, int $pg): string
{
    $base['page'] = $pg;
    return 'soa_manage.php?' . http_build_query(array_filter($base, static fn($v) => $v !== '' && $v !== null));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage SOA | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
            boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' }
        } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Manage SOA</h1>
                    <p class="text-slate-500 mt-2">Browse, reprint, or clear generated Statements of Account.</p>
                </div>
                <a href="soa.php" class="rounded-2xl bg-green-700 hover:bg-green-800 text-white px-5 py-2.5 text-sm font-bold text-center">+ Generate SOA</a>
            </div>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ SOA tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/phase2_soa_system.sql</code> first.</p>
        </div>
        <?php else: ?>

        <!-- Filters -->
        <form method="GET" action="soa_manage.php" class="bg-white rounded-2xl border border-slate-200 p-4 mb-5 shadow-sm">
            <div class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Search</label>
                    <input type="text" name="q" value="<?= h($search) ?>" placeholder="SOA No., student name, or ID…"
                           class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                    <select name="sy" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="0">All</option>
                        <?php foreach ($allSY as $id => $lbl): ?>
                        <option value="<?= $id ?>" <?= $syFilter === $id ? 'selected' : '' ?>>S.Y. <?= h($lbl) ?><?= $id === $syId ? ' (active)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Grade Level</label>
                    <select name="grade" id="gradeSel" onchange="filterSections()" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400 max-w-[170px]">
                        <option value="0">All grades</option>
                        <?php foreach ($allGrades as $id => $lbl): ?>
                        <option value="<?= $id ?>" <?= $gradeFilter === $id ? 'selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Section</label>
                    <select name="section" id="sectionSel" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400 max-w-[190px]">
                        <option value="" data-grade="0">All sections</option>
                        <?php foreach ($allSections as $sct): ?>
                        <option value="<?= h($sct['id']) ?>" data-grade="<?= (int) $sct['grade'] ?>"
                                <?= $sectionFilter === $sct['id'] ? 'selected' : '' ?>>
                            <?= h($sct['name']) ?><?= isset($allGrades[$sct['grade']]) ? ' · ' . h($allGrades[$sct['grade']]) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Month</label>
                    <select name="month" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400 max-w-[170px]">
                        <option value="0">All months</option>
                        <?php foreach ($termLabels as $tno => $mlabel): ?>
                        <option value="<?= (int) $tno ?>" <?= $monthFilter === (int) $tno ? 'selected' : '' ?>>M<?= (int) $tno ?><?= $mlabel !== '' ? ' · ' . h($mlabel) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Batch</label>
                    <select name="batch" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400 max-w-[220px]">
                        <option value="">All batches</option>
                        <?php foreach ($batches as $b): ?>
                        <option value="<?= h((string) $b['batch_id']) ?>" <?= $batch === $b['batch_id'] ? 'selected' : '' ?>>
                            <?= h(date('M d, h:iA', strtotime((string) $b['first_at']))) ?> · <?= h((string) $b['scope']) ?> · <?= (int) $b['n'] ?> SOA
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">From</label>
                    <input type="date" name="from" value="<?= h($dateFrom) ?>" class="py-2 px-3 text-sm border border-slate-200 rounded-xl">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">To</label>
                    <input type="date" name="to" value="<?= h($dateTo) ?>" class="py-2 px-3 text-sm border border-slate-200 rounded-xl">
                </div>
                <div class="flex gap-2">
                    <button class="py-2 px-4 bg-green-700 text-white text-sm font-semibold rounded-xl hover:bg-green-800">Filter</button>
                    <a href="soa_manage.php" class="py-2 px-4 border border-slate-200 bg-white text-sm font-semibold text-slate-600 rounded-xl">Clear</a>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-4 text-sm">
                <span class="text-slate-500"><strong><?= $totalRows ?></strong> SOA<?= $totalRows !== 1 ? 's' : '' ?> match</span>
                <?php if ($batch !== ''): ?>
                <button type="button" onclick="reprintBatch()" class="text-green-700 font-semibold hover:underline">↻ Reprint whole batch</button>
                <button type="button" onclick="deleteBatch()" class="text-rose-600 font-semibold hover:underline">🗑 Delete whole batch</button>
                <?php endif; ?>
                <label class="ml-auto flex items-center gap-2 text-xs text-slate-500">Reprint layout:
                    <select id="layoutSel" class="border border-slate-200 rounded-lg px-2 py-1 text-xs">
                        <option value="2up" <?= $layout==='2up'?'selected':'' ?>>2 per A4</option>
                        <option value="1up" <?= $layout==='1up'?'selected':'' ?>>1 per page</option>
                    </select>
                </label>
            </div>
        </form>

        <!-- Bulk action bar -->
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <button type="button" onclick="reprintSelected()" class="rounded-xl bg-green-700 hover:bg-green-800 text-white px-4 py-2 text-sm font-semibold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Reprint Selected
            </button>
            <button type="button" onclick="deleteSelected()" class="rounded-xl bg-white border border-rose-300 text-rose-600 hover:bg-rose-50 px-4 py-2 text-sm font-semibold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete Selected
            </button>
            <span id="selCount" class="text-xs text-slate-400">0 selected</span>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
            <?php if ($rows === []): ?>
            <div class="py-16 text-center text-slate-400">
                <p class="font-semibold">No SOA documents found.</p>
                <p class="text-sm mt-1"><a href="soa.php" class="text-green-700 underline">Generate some</a> to get started.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase tracking-wider">
                            <th class="px-4 py-3 text-center w-10"><input type="checkbox" id="checkAll" onclick="toggleAll(this)" class="w-4 h-4 accent-green-600"></th>
                            <th class="px-4 py-3 text-left">SOA No.</th>
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">Grade / Section</th>
                            <th class="px-4 py-3 text-left">Months</th>
                            <th class="px-4 py-3 text-right">Total Due</th>
                            <th class="px-4 py-3 text-left">Generated</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($rows as $r): ?>
                        <?php
                            $id    = (int) $r['id'];
                            $terms = json_decode((string) ($r['selected_terms_json'] ?? '[]'), true) ?: [];
                            $mLbl  = $terms ? ('M' . implode(', M', array_map('intval', $terms))) : '—';
                        ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-4 py-3 text-center"><input type="checkbox" class="rowChk w-4 h-4 accent-green-600" value="<?= $id ?>" onclick="updateCount()"></td>
                            <td class="px-4 py-3"><span class="font-mono text-xs font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded"><?= h((string) $r['soa_number']) ?></span></td>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-800"><?= h(strtoupper(trim((string) ($r['full_name'] ?? ('ID ' . $r['student_id']))))) ?></p>
                                <p class="text-xs text-slate-400 font-mono"><?= h((string) $r['student_id']) ?></p>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600"><?= h(trim((string) $r['grade_name'])) ?><?= $r['section_name'] ? ' · ' . h((string) $r['section_name']) : '' ?></td>
                            <td class="px-4 py-3 text-xs text-slate-500"><?= h($mLbl) ?></td>
                            <td class="px-4 py-3 text-right font-bold text-green-700">₱<?= number_format((float) $r['total_due'], 2) ?></td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                <?= h(date('M d, Y h:iA', strtotime((string) $r['generated_at']))) ?><br>
                                <span class="text-slate-400"><?= h((string) $r['generated_by']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <button type="button" onclick="reprint([<?= $id ?>])" class="rounded-lg bg-slate-100 border border-slate-200 text-slate-700 hover:bg-green-700 hover:text-white hover:border-green-600 text-xs font-semibold px-3 py-1.5 transition-all">Reprint</button>
                                    <button type="button" onclick="del([<?= $id ?>])" class="rounded-lg bg-rose-50 border border-rose-200 text-rose-600 hover:bg-rose-600 hover:text-white hover:border-rose-600 text-xs font-semibold px-3 py-1.5 transition-all">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                <p class="text-xs text-slate-500">Showing <?= $offset+1 ?>–<?= min($page*$perPage, $totalRows) ?> of <?= $totalRows ?></p>
                <div class="flex gap-1">
                    <?php
                    $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
                    if ($page > 1) echo '<a href="'.h(sm_page_url($qsArr,$page-1)).'" class="px-3 py-1.5 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50">←</a>';
                    for ($pg=$start;$pg<=$end;$pg++) {
                        echo '<a href="'.h(sm_page_url($qsArr,$pg)).'" class="px-3 py-1.5 text-xs font-semibold border rounded-lg '.($pg===$page?'bg-green-700 text-white border-green-600':'border-slate-200 hover:bg-slate-50').'">'.$pg.'</a>';
                    }
                    if ($page < $totalPages) echo '<a href="'.h(sm_page_url($qsArr,$page+1)).'" class="px-3 py-1.5 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50">→</a>';
                    ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- hidden delete form -->
        <form id="delForm" method="POST" action="soa_manage.php" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="ids" id="delIds">
            <input type="hidden" name="qs" value="<?= h($qs) ?>">
        </form>
        <form id="delBatchForm" method="POST" action="soa_manage.php" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_batch">
            <input type="hidden" name="batch_id" value="<?= h($batch) ?>">
            <input type="hidden" name="qs" value="<?= h($qs) ?>">
        </form>

        <?php endif; /* schemaReady */ ?>
    </main>
</div>

<script>
const appBase = <?= json_encode(app_url('cashier/soa.php')) ?>;
const batchAllIds = <?= json_encode($batchAllIds ?? []) ?>;
function selectedIds() {
    return Array.from(document.querySelectorAll('.rowChk:checked')).map(c => c.value);
}
function updateCount() {
    document.getElementById('selCount').textContent = selectedIds().length + ' selected';
}
function toggleAll(box) {
    document.querySelectorAll('.rowChk').forEach(c => c.checked = box.checked);
    updateCount();
}
function layout() { return document.getElementById('layoutSel') ? document.getElementById('layoutSel').value : '2up'; }
function reprint(ids) {
    if (!ids.length) { alert('Select at least one SOA to reprint.'); return; }
    window.open(appBase + '?print=1&layout=' + layout() + '&ids=' + ids.join(','), '_blank');
}
function reprintSelected() { reprint(selectedIds()); }
function del(ids) {
    if (!ids.length) { alert('Select at least one SOA to delete.'); return; }
    if (!confirm('Clear ' + ids.length + ' SOA document(s)? This cannot be undone (assessments & payments are kept).')) return;
    document.getElementById('delIds').value = ids.join(',');
    document.getElementById('delForm').submit();
}
function deleteSelected() { del(selectedIds()); }
function reprintBatch() {
    // Reprint EVERY SOA in the filtered batch, across all pages.
    const ids = batchAllIds.length ? batchAllIds : Array.from(document.querySelectorAll('.rowChk')).map(c => c.value);
    if (!ids.length) { alert('No SOAs in this batch.'); return; }
    reprint(ids);
}
function deleteBatch() {
    if (!confirm('Delete the ENTIRE batch (all its SOAs, across all pages)? Assessments & payments are kept.')) return;
    document.getElementById('delBatchForm').submit();
}
// Show only the sections that belong to the chosen grade (keep "All sections").
function filterSections() {
    const grade = document.getElementById('gradeSel').value;
    const sel   = document.getElementById('sectionSel');
    let currentStillVisible = false;
    Array.from(sel.options).forEach(opt => {
        const g = opt.getAttribute('data-grade');
        const show = (grade === '0') || (g === '0') || (g === grade);
        opt.hidden = !show;
        if (opt.selected && !show) { currentStillVisible = false; }
        else if (opt.selected) { currentStillVisible = true; }
    });
    // If the selected section no longer belongs to the grade, reset to "All".
    if (!currentStillVisible) { sel.value = ''; }
}
filterSections();  // apply on load (preserves grade/section from the querystring)
</script>
</body>
</html>
