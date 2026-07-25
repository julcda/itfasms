<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/promissory_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_registrar_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only the Registrar or Super Admin can access Promissory Notes.');
    redirect_to(app_url('dashboard/index.php'));
}

$sy                    = soa_active_school_year($connection);
$syId                  = (int) $sy['id'];
$activeSchoolYearLabel = $sy['label'];
$ready                 = pn_table_ready($connection);

if ($ready) {
    pn_mark_overdue($connection);
}

// ── POST: cancel / mark paid (registrar actions) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to('promissory.php');
    }
    try {
        $id     = to_int($_POST['promissory_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'cancel') {
            pn_set_status($connection, $id, 'Cancelled', $user);
            flash_set('success', 'Promissory note cancelled.');
        } elseif ($action === 'paid') {
            pn_set_status($connection, $id, 'Paid', $user);
            flash_set('success', 'Promissory note marked as Paid.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('promissory.php' . (($_POST['q'] ?? '') !== '' ? '?q=' . urlencode((string) $_POST['q']) : ''));
}

// ── Filters + list ────────────────────────────────────────────────────────────
$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$from   = trim((string) ($_GET['from'] ?? ''));
$to     = trim((string) ($_GET['to'] ?? ''));
$rows   = [];
$stats  = ['active' => 0, 'overdue' => 0, 'paid' => 0, 'amount' => 0.0, 'overdue_amount' => 0.0];

if ($ready) {
    $stats = pn_dashboard_stats($connection, $syId);

    $where  = ['pn.school_year_id = ?'];
    $types  = 'i';
    $params = [$syId];
    if ($q !== '') {
        $where[] = "(pn.promissory_no LIKE ? OR pn.student_id = ? OR COALESCE(p.lrn,osp.lrn) LIKE ?
                     OR COALESCE(CONCAT(p.surname,' ',p.firstname),CONCAT(osp.surname,' ',osp.firstname)) LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'ssss';
        array_push($params, $like, $q, $like, $like);
    }
    if (in_array($status, ['Pending', 'Paid', 'Overdue', 'Cancelled'], true)) {
        $where[] = 'pn.status = ?';
        $types  .= 's';
        $params[] = $status;
    }
    if ($from !== '') { $where[] = 'pn.date_issued >= ?'; $types .= 's'; $params[] = $from; }
    if ($to !== '')   { $where[] = 'pn.date_issued <= ?'; $types .= 's'; $params[] = $to; }

    $sql =
        "SELECT pn.promissory_id, pn.promissory_no, pn.promissory_amount, pn.outstanding_balance,
                pn.date_issued, pn.promised_payment_date, pn.status, pn.cashier_verified, pn.reason,
                COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname)) AS full_name,
                COALESCE(p.lrn, osp.lrn) AS lrn,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name
         FROM promissory_notes pn
         JOIN enrollment en ON en.id = pn.enrollment_id
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE " . implode(' AND ', $where) . "
         ORDER BY pn.promissory_id DESC LIMIT 300";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
}

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Promissory Notes | ITFA Registrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Registrar</p>
                <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Promissory Notes</h1>
                <p class="text-slate-500 mt-2">Deferred-payment arrangements for students who cannot settle their full SOA.</p>
                <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?></p>
            </div>
            <a href="<?= h(app_url('registrar/promissory_new.php')) ?>" class="rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-bold px-5 py-3">+ New Promissory Note</a>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (!$ready): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Promissory table not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/promissory_notes.sql</code> first.</p>
        </div>
        <?php else: ?>

        <!-- Dashboard -->
        <section class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Active notes</p>
                <p class="text-2xl font-extrabold mt-1 text-amber-600"><?= number_format($stats['active']) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Overdue notes</p>
                <p class="text-2xl font-extrabold mt-1 text-rose-600"><?= number_format($stats['overdue']) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Under arrangement</p>
                <p class="text-2xl font-extrabold mt-1 text-green-700">₱<?= number_format($stats['amount'], 2) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Overdue amount</p>
                <p class="text-2xl font-extrabold mt-1 text-rose-600">₱<?= number_format($stats['overdue_amount'], 2) ?></p>
            </div>
        </section>

        <!-- Filters -->
        <form method="GET" action="promissory.php" class="mb-5 flex flex-wrap gap-3 items-end">
            <div class="relative flex-1 min-w-[240px]">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-600">🔍</span>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="PN number, student name, LRN or ID…"
                       class="w-full rounded-xl border border-slate-300 pl-11 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
            </div>
            <select name="status" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <option value="">All statuses</option>
                <?php foreach (['Pending','Paid','Overdue','Cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
            <div><label class="block text-[10px] uppercase text-slate-400 font-semibold">From</label><input type="date" name="from" value="<?= h($from) ?>" class="rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="block text-[10px] uppercase text-slate-400 font-semibold">To</label><input type="date" name="to" value="<?= h($to) ?>" class="rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
            <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-5 py-2.5">Filter</button>
            <?php if ($q||$status||$from||$to): ?><a href="promissory.php" class="text-sm text-slate-500 px-2 py-2.5">Clear</a><?php endif; ?>
        </form>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
            <?php if (!$rows): ?>
            <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-10 text-center">No promissory notes found.</div>
            <?php else: ?>
            <div class="overflow-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                            <th class="py-2 pr-3">PN No.</th>
                            <th class="py-2 pr-3">Student</th>
                            <th class="py-2 pr-3">Grade / Section</th>
                            <th class="py-2 pr-3 text-right">Amount</th>
                            <th class="py-2 pr-3">Issued</th>
                            <th class="py-2 pr-3">Promised</th>
                            <th class="py-2 pr-3">Status</th>
                            <th class="py-2 pr-3">Cashier</th>
                            <th class="py-2 pr-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): $st = (string) $r['status']; $closed = in_array($st, ['Paid','Cancelled'], true); ?>
                        <tr class="border-b border-slate-100 <?= $closed ? 'opacity-60' : '' ?>">
                            <td class="py-2.5 pr-3 font-mono font-semibold text-slate-700"><?= h((string) $r['promissory_no']) ?></td>
                            <td class="py-2.5 pr-3">
                                <span class="font-semibold"><?= h((string) ($r['full_name'] ?: '—')) ?></span>
                                <span class="block text-[11px] text-slate-400 font-mono"><?= h((string) ($r['lrn'] ?: '')) ?></span>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-600 text-xs"><?= h(trim((string) $r['grade_name'])) ?><?= $r['section_name'] ? ' · ' . h((string) $r['section_name']) : '' ?></td>
                            <td class="py-2.5 pr-3 text-right font-bold">₱<?= number_format((float) $r['promissory_amount'], 2) ?></td>
                            <td class="py-2.5 pr-3 text-slate-500 whitespace-nowrap"><?= h(date('M j, Y', strtotime((string) $r['date_issued']))) ?></td>
                            <td class="py-2.5 pr-3 whitespace-nowrap <?= $st==='Overdue'?'text-rose-600 font-semibold':'text-slate-500' ?>"><?= h(date('M j, Y', strtotime((string) $r['promised_payment_date']))) ?></td>
                            <td class="py-2.5 pr-3"><?= pn_status_badge($st) ?></td>
                            <td class="py-2.5 pr-3"><?= ((int) $r['cashier_verified'] === 1) ? '<span class="text-emerald-600 text-xs font-semibold">✓ Verified</span>' : '<span class="text-slate-400 text-xs">—</span>' ?></td>
                            <td class="py-2.5 pr-3 text-right whitespace-nowrap">
                                <a href="<?= h(app_url('registrar/promissory_print.php?id=' . (int) $r['promissory_id'])) ?>" target="_blank"
                                   class="inline-block rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5">Print</a>
                                <?php if (!$closed): ?>
                                <form method="POST" action="promissory.php" class="inline" onsubmit="return confirm('Mark this note as PAID?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="paid">
                                    <input type="hidden" name="promissory_id" value="<?= (int) $r['promissory_id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
                                    <button class="rounded-lg bg-white border border-emerald-300 hover:bg-emerald-50 text-emerald-700 text-xs font-bold px-3 py-1.5">Paid</button>
                                </form>
                                <form method="POST" action="promissory.php" class="inline" onsubmit="return confirm('Cancel this promissory note?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="promissory_id" value="<?= (int) $r['promissory_id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
                                    <button class="rounded-lg bg-white border border-slate-300 hover:bg-rose-50 text-rose-600 text-xs font-bold px-3 py-1.5">Cancel</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>
    </main>
</div>
</body>
</html>
