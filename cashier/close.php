<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/soa_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only Cashier or Super Admin users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$cashierName           = (string) ($user['full_name'] ?? 'Cashier');
$cashierId             = (int) ($user['id'] ?? 0);
$isAdmin               = is_super_admin($user);
$sy                    = soa_active_school_year($connection);
$syLabel               = $sy['label'];
$schemaReady           = soa_schema_ready($connection);
$activeSchoolYearLabel = $syLabel;

// ── POST: close a day ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('close.php');
    }
    $collectionId = to_int($_POST['collection_id'] ?? 0);
    $declaredCash = (float) ($_POST['declared_cash'] ?? 0);
    $notes        = trim((string) ($_POST['notes'] ?? ''));

    // A cashier may only close their own day; a super admin may close any.
    $ownChk = $connection->prepare('SELECT cashier_id FROM collection_summary WHERE id = ? LIMIT 1');
    $ownChk->bind_param('i', $collectionId);
    $ownChk->execute();
    $own = stmt_fetch_assoc($ownChk);
    if (!$own || (!$isAdmin && (int) $own['cashier_id'] !== $cashierId)) {
        flash_set('error', 'You can only close your own collection.');
        redirect_to('close.php');
    }

    try {
        $res = soa_close_collection($connection, $collectionId, $declaredCash, $notes, $user);
        $v   = $res['variance'];
        $vtxt = $v == 0.0 ? 'balanced' : ($v > 0 ? 'OVER by ₱' . number_format($v, 2) : 'SHORT by ₱' . number_format(abs($v), 2));
        flash_set('success', 'Day closed — ' . $vtxt . '. Expected ₱' . number_format($res['expected_cash'], 2)
            . ', counted ₱' . number_format($res['declared_cash'], 2) . '.');
        redirect_to('close.php?print=' . (int) $res['collection_id']);
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('close.php');
    }
}

// ── Z-report print mode ──────────────────────────────────────────────────────
$printId = to_int($_GET['print'] ?? 0);
if ($schemaReady && $printId > 0) {
    $cStmt = $connection->prepare('SELECT * FROM collection_summary WHERE id = ? LIMIT 1');
    $cStmt->bind_param('i', $printId);
    $cStmt->execute();
    $col = stmt_fetch_assoc($cStmt);
    if ($col && ($isAdmin || (int) $col['cashier_id'] === $cashierId)) {
        $pays = soa_collection_payments($connection, (string) $col['cashier_name'], (string) $col['business_date']);
        require __DIR__ . '/close_zreport.php';
        exit;
    }
}

// ── List collections ─────────────────────────────────────────────────────────
$filterDate = trim((string) ($_GET['date'] ?? ''));
$where  = $isAdmin ? '1=1' : 'cashier_id = ' . $cashierId;
if ($filterDate !== '') {
    $where .= " AND business_date = '" . $connection->real_escape_string($filterDate) . "'";
}
$collections = [];
if ($schemaReady) {
    $q = $connection->query(
        "SELECT * FROM collection_summary
         WHERE $where
         ORDER BY (status = 'Open') DESC, business_date DESC, cashier_name
         LIMIT 200"
    );
    if ($q) { while ($r = $q->fetch_assoc()) { $collections[] = $r; } }
}

// Payment detail for each listed day (keyed by collection id).
$details = [];
foreach ($collections as $c) {
    $details[(int) $c['id']] = soa_collection_payments($connection, (string) $c['cashier_name'], (string) $c['business_date']);
}

$flash = flash_get();
$csrf  = csrf_token();
$openCount = 0;
foreach ($collections as $c) { if ($c['status'] === 'Open') { $openCount++; } }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>End-of-Day Close | ITFA Cashier</title>
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

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier · Controls</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">End-of-Day Cash Close</h1>
            <p class="text-slate-500 mt-2">Count your drawer, declare the cash on hand, and lock the day. Over/short is computed against system-expected cash.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?> &nbsp;·&nbsp; <?= $isAdmin ? 'Viewing: all cashiers' : 'Viewing: your collections' ?></p>
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

        <form method="GET" action="close.php" class="mb-6 flex gap-2 items-end">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Filter date</label>
                <input type="date" name="date" value="<?= h($filterDate) ?>" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
            </div>
            <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-5 py-2.5">Filter</button>
            <?php if ($filterDate): ?><a href="close.php" class="text-sm text-slate-500 px-2 py-2.5">Clear</a><?php endif; ?>
            <span class="ml-auto text-sm text-slate-500"><?= $openCount ?> open · <?= count($collections) - $openCount ?> closed</span>
        </form>

        <?php if (!$collections): ?>
        <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-10 text-center text-slate-500">
            No collections<?= $filterDate ? ' for ' . h($filterDate) : '' ?> yet. Collections appear here once payments are posted.
        </div>
        <?php endif; ?>

        <div class="space-y-5">
        <?php foreach ($collections as $c):
            $cid       = (int) $c['id'];
            $isOpen    = $c['status'] === 'Open';
            $expCash   = (float) $c['total_cash'];
            $expOnline = (float) $c['total_online'];
            $expTotal  = (float) $c['total_collected'];
            $variance  = $c['variance'] !== null ? (float) $c['variance'] : null;
            $dayPays   = $details[$cid] ?? [];
        ?>
            <section class="rounded-3xl bg-white border <?= $isOpen ? 'border-amber-200' : 'border-emerald-200' ?> shadow-panel overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 <?= $isOpen ? 'bg-amber-50/50' : 'bg-emerald-50/50' ?>">
                    <div>
                        <p class="font-extrabold text-lg"><?= h(date('D, M j, Y', strtotime((string) $c['business_date']))) ?></p>
                        <p class="text-xs text-slate-500"><?= h((string) $c['cashier_name']) ?> · <?= (int) $c['txn_count'] ?> transactions</p>
                    </div>
                    <span class="inline-block px-3 py-1 rounded-full text-xs font-bold border
                        <?= $isOpen ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-emerald-100 text-emerald-800 border-emerald-300' ?>">
                        <?= $isOpen ? 'OPEN' : 'CLOSED' ?>
                    </span>
                </div>

                <div class="grid sm:grid-cols-3 gap-px bg-slate-100">
                    <div class="bg-white px-6 py-4"><p class="text-xs text-slate-400 uppercase tracking-wide">Cash</p><p class="text-xl font-extrabold mt-1">₱<?= number_format($expCash, 2) ?></p></div>
                    <div class="bg-white px-6 py-4"><p class="text-xs text-slate-400 uppercase tracking-wide">Online / Bank</p><p class="text-xl font-extrabold mt-1">₱<?= number_format($expOnline, 2) ?></p></div>
                    <div class="bg-white px-6 py-4"><p class="text-xs text-slate-400 uppercase tracking-wide">Total Collected</p><p class="text-xl font-extrabold mt-1 text-green-700">₱<?= number_format($expTotal, 2) ?></p></div>
                </div>

                <?php if ($dayPays): ?>
                <details class="px-6 py-3 border-t border-slate-100">
                    <summary class="text-sm font-semibold text-green-700 cursor-pointer"><?= count($dayPays) ?> payment line(s)</summary>
                    <div class="overflow-auto mt-3">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-xs uppercase text-slate-400 border-b border-slate-200">
                                <th class="py-1.5 pr-3">OR</th><th class="py-1.5 pr-3">Student</th><th class="py-1.5 pr-3">Method</th><th class="py-1.5 pr-3 text-right">Amount</th><th class="py-1.5 pr-3">Status</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($dayPays as $p): ?>
                                <tr class="border-b border-slate-50 <?= $p['status'] !== 'Posted' ? 'text-slate-400 line-through' : '' ?>">
                                    <td class="py-1.5 pr-3 text-slate-500"><?= h((string) ($p['or_number'] ?? '—')) ?></td>
                                    <td class="py-1.5 pr-3"><?= h((string) ($p['full_name'] ?? '—')) ?></td>
                                    <td class="py-1.5 pr-3"><?= h((string) $p['method']) ?></td>
                                    <td class="py-1.5 pr-3 text-right">₱<?= number_format((float) $p['amount'], 2) ?></td>
                                    <td class="py-1.5 pr-3 text-xs"><?= h((string) $p['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php endif; ?>

                <?php if ($isOpen): ?>
                <form method="POST" action="close.php" class="px-6 py-4 border-t border-slate-100 bg-slate-50/50"
                      onsubmit="return confirm('Close this day? Once closed it is locked.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="collection_id" value="<?= $cid ?>">
                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-1">Cash counted in drawer</label>
                            <div class="flex items-center gap-2">
                                <span class="text-slate-400">₱</span>
                                <input type="number" step="0.01" min="0" name="declared_cash" required
                                       data-expected="<?= $expCash ?>" oninput="_var(this)"
                                       class="w-40 rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-bold focus:ring-2 focus:ring-green-400">
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-500 mb-1">Expected cash</p>
                            <p class="text-sm font-bold py-2.5">₱<?= number_format($expCash, 2) ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-500 mb-1">Over / Short</p>
                            <p class="text-sm font-bold py-2.5 _variance" data-cid="<?= $cid ?>">—</p>
                        </div>
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-xs font-semibold text-slate-500 mb-1">Notes (optional)</label>
                            <input type="text" name="notes" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                        </div>
                        <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-3">Close & Print Z-Report</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/50 flex flex-wrap items-center gap-6">
                    <div><p class="text-xs text-slate-400 uppercase">Counted</p><p class="font-bold">₱<?= number_format((float) $c['declared_cash'], 2) ?></p></div>
                    <div><p class="text-xs text-slate-400 uppercase">Over / Short</p>
                        <p class="font-bold <?= $variance == 0.0 ? 'text-emerald-600' : ($variance > 0 ? 'text-green-600' : 'text-rose-600') ?>">
                            <?= $variance === null ? '—' : ($variance == 0.0 ? 'Balanced' : ($variance > 0 ? 'Over ₱' . number_format($variance, 2) : 'Short ₱' . number_format(abs($variance), 2))) ?>
                        </p>
                    </div>
                    <div><p class="text-xs text-slate-400 uppercase">Closed by</p><p class="font-bold"><?= h((string) ($c['closed_by'] ?? '—')) ?></p></div>
                    <div><p class="text-xs text-slate-400 uppercase">Closed at</p><p class="font-bold"><?= h((string) ($c['closed_at'] ?? '—')) ?></p></div>
                    <?php if ($c['notes']): ?><div class="flex-1"><p class="text-xs text-slate-400 uppercase">Notes</p><p class="text-sm italic"><?= h((string) $c['notes']) ?></p></div><?php endif; ?>
                    <a href="close.php?print=<?= $cid ?>" target="_blank" class="ml-auto rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Print Z-Report</a>
                </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </main>
</div>
<script>
function _fmt(n){ return '₱' + n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function _var(input){
    const exp = parseFloat(input.dataset.expected || '0');
    const dec = parseFloat(input.value || '0');
    const v   = Math.round((dec - exp) * 100) / 100;
    const cell = input.closest('section').querySelector('._variance');
    if (!cell) return;
    if (isNaN(dec)) { cell.textContent = '—'; cell.className = 'text-sm font-bold py-2.5 _variance'; return; }
    cell.textContent = v === 0 ? 'Balanced' : (v > 0 ? 'Over ' + _fmt(v) : 'Short ' + _fmt(Math.abs(v)));
    cell.className = 'text-sm font-bold py-2.5 _variance ' + (v === 0 ? 'text-emerald-600' : (v > 0 ? 'text-green-600' : 'text-rose-600'));
}
</script>
</body>
</html>
