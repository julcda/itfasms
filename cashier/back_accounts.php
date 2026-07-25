<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/back_account_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only Cashier or Super Admin users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$cashierName           = (string) ($user['full_name'] ?? 'Cashier');
$sy                    = soa_active_school_year($connection);
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;
$ready                 = ba_schema_ready($connection);

// ── AJAX: student lookup for the "attach to student" picker ───────────────────
if ($ready && isset($_GET['student_search'])) {
    header('Content-Type: application/json');
    echo json_encode(ba_search_students($connection, (string) $_GET['student_search']));
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to('back_accounts.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!$ready) {
            throw new RuntimeException('Run migrations/student_back_accounts.sql first.');
        }

        if ($action === 'create') {
            $id = ba_create($connection, [
                'student_id'      => (string) ($_POST['student_id'] ?? ''),
                'student_name'    => (string) ($_POST['student_name'] ?? ''),
                'lrn'             => (string) ($_POST['lrn'] ?? ''),
                'school_year'     => (string) ($_POST['school_year'] ?? ''),
                'grade_section'   => (string) ($_POST['grade_section'] ?? ''),
                'original_amount' => (float) ($_POST['original_amount'] ?? 0),
                'remarks'         => (string) ($_POST['remarks'] ?? ''),
            ], $user);
            flash_set('success', 'Back account recorded for ' . (string) $_POST['student_name'] . '.');
            redirect_to('back_accounts.php?view=' . $id);
        }

        if ($action === 'update') {
            $id = to_int($_POST['id'] ?? 0);
            ba_update($connection, $id, [
                'original_amount' => (float) ($_POST['original_amount'] ?? 0),
                'school_year'     => (string) ($_POST['school_year'] ?? ''),
                'grade_section'   => (string) ($_POST['grade_section'] ?? ''),
                'remarks'         => (string) ($_POST['remarks'] ?? ''),
            ], $user);
            flash_set('success', 'Back account updated.');
            redirect_to('back_accounts.php?view=' . $id);
        }

        if ($action === 'collect') {
            $id  = to_int($_POST['id'] ?? 0);
            $res = ba_collect(
                $connection, $id,
                (float) ($_POST['amount'] ?? 0),
                (string) ($_POST['method'] ?? 'Cash'),
                (string) ($_POST['reference_no'] ?? ''),
                $user
            );
            flash_set('success', 'Payment collected — OR ' . $res['or_number'] . ' (₱' . number_format($res['amount'], 2) . '). Remaining balance: ₱' . number_format($res['balance'], 2) . '.');
            redirect_to(app_url('cashier/back_account_receipt.php?id=' . (int) $res['payment_id']));
        }

        if ($action === 'void_payment') {
            ba_void_payment($connection, to_int($_POST['payment_id'] ?? 0), (string) ($_POST['reason'] ?? 'Voided by cashier'), $user);
            flash_set('success', 'Payment voided and balance restored.');
            redirect_to('back_accounts.php?view=' . to_int($_POST['id'] ?? 0));
        }

        if ($action === 'cancel') {
            ba_cancel($connection, to_int($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? 'Cancelled by cashier'), $user);
            flash_set('success', 'Back account cancelled.');
            redirect_to('back_accounts.php');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('back_accounts.php' . (to_int($_POST['id'] ?? 0) ? '?view=' . to_int($_POST['id']) : ''));
    }
}

$q        = trim((string) ($_GET['q'] ?? ''));
$status   = (string) ($_GET['status'] ?? '');
$viewId   = to_int($_GET['view'] ?? 0);
$editId   = to_int($_GET['edit'] ?? 0);
$rows     = $ready ? ba_list($connection, $q, $status) : [];
$stats    = $ready ? ba_stats($connection) : ['total_rows' => 0, 'unpaid_rows' => 0, 'unpaid_total' => 0, 'collected_total' => 0];
$viewRow  = ($ready && $viewId) ? ba_get($connection, $viewId) : null;
$editRow  = ($ready && $editId) ? ba_get($connection, $editId) : null;
$payments = $viewRow ? ba_payments($connection, $viewId) : [];
$flash    = flash_get();
$csrf     = csrf_token();

// School-year choices for the input form (newest first, plus any already used).
// The student_back_accounts query MUST be guarded by $ready: mysqli runs in
// exception mode, so querying a table that does not exist yet (migration not run)
// throws an uncaught exception -> HTTP 500 before the "tables not installed"
// notice can render.
$syOptions = [];
$syRes = $connection->query('SELECT School_year FROM schoolyear ORDER BY School_year_id DESC');
if ($syRes) { while ($r = $syRes->fetch_assoc()) { $syOptions[] = (string) $r['School_year']; } }
if ($ready) {
    $syExtra = $connection->query('SELECT DISTINCT school_year FROM student_back_accounts ORDER BY school_year DESC');
    if ($syExtra) { while ($r = $syExtra->fetch_assoc()) { $syOptions[] = (string) $r['school_year']; } }
}
$syOptions = array_values(array_unique(array_filter($syOptions)));
rsort($syOptions);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Back Accounts | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier · Collections</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Student Back Accounts</h1>
            <p class="text-slate-500 mt-2">Record prior-school-year balances against a student, collect payments, and issue receipts. Unpaid back accounts appear as a warning on the student's SOA.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (!$ready): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Back-account tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/student_back_accounts.sql</code> first.</p>
        </div>
        <?php else: ?>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="rounded-2xl bg-white border border-rose-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-rose-600 font-bold">Outstanding</p>
                <p class="text-2xl font-extrabold text-rose-700 mt-1">₱<?= number_format($stats['unpaid_total'], 2) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-amber-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-amber-600 font-bold">Unpaid Accounts</p>
                <p class="text-2xl font-extrabold text-amber-700 mt-1"><?= number_format($stats['unpaid_rows']) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-emerald-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-emerald-600 font-bold">Collected</p>
                <p class="text-2xl font-extrabold text-emerald-700 mt-1">₱<?= number_format($stats['collected_total'], 2) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-green-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-green-600 font-bold">Total Records</p>
                <p class="text-2xl font-extrabold text-green-700 mt-1"><?= number_format($stats['total_rows']) ?></p>
            </div>
        </div>

        <!-- ── Add a back account ─────────────────────────────────────────── -->
        <details class="bg-white rounded-3xl border border-green-100 shadow-panel mb-6" <?= $editRow ? '' : 'open' ?>>
            <summary class="cursor-pointer select-none px-6 py-4 font-extrabold text-lg">➕ Record a Back Account</summary>
            <div class="px-6 pb-6">
                <form method="POST" action="back_accounts.php" id="createForm">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="student_id"   id="f_sid">
                    <input type="hidden" name="student_name" id="f_sname">
                    <input type="hidden" name="lrn"          id="f_lrn">

                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Student <span class="text-rose-600">*</span></label>
                    <div class="relative">
                        <input type="text" id="stuSearch" autocomplete="off" placeholder="Type an LRN or name, then pick from the list…"
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <div id="stuResults" class="hidden absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg"></div>
                    </div>
                    <div id="stuPicked" class="hidden mt-2 rounded-xl bg-green-50 border border-green-200 px-4 py-2.5 text-sm">
                        <span class="font-bold" id="stuPickedName"></span>
                        <span class="text-slate-500" id="stuPickedMeta"></span>
                        <button type="button" onclick="clearPick()" class="ml-2 text-green-700 font-bold hover:underline">change</button>
                    </div>

                    <div class="grid sm:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">S.Y. of the debt <span class="text-rose-600">*</span></label>
                            <input list="syList" name="school_year" required placeholder="e.g. 2023-2024"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            <datalist id="syList">
                                <?php foreach ($syOptions as $o): ?><option value="<?= h($o) ?>"><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Amount (₱) <span class="text-rose-600">*</span></label>
                            <input type="number" name="original_amount" step="0.01" min="0.01" required placeholder="0.00"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade &amp; Section <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" name="grade_section" placeholder="e.g. SEVEN OMAR"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Remarks <span class="text-slate-400 font-normal">(optional)</span></label>
                        <input type="text" name="remarks" maxlength="255" placeholder="e.g. unpaid tuition balance carried from S.Y. 2023-2024"
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>
                    <button class="mt-4 rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Save Back Account</button>
                </form>
            </div>
        </details>

        <!-- ── Edit panel ─────────────────────────────────────────────────── -->
        <?php if ($editRow): ?>
        <section class="bg-white rounded-3xl border border-amber-200 shadow-panel p-6 mb-6">
            <h2 class="font-extrabold text-lg mb-1">✏️ Edit Back Account</h2>
            <p class="text-sm text-slate-500 mb-4"><?= h((string) $editRow['student_name']) ?> · already paid ₱<?= number_format((float) $editRow['amount_paid'], 2) ?></p>
            <form method="POST" action="back_accounts.php" class="grid sm:grid-cols-4 gap-4 items-end">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">S.Y.</label>
                    <input name="school_year" value="<?= h((string) $editRow['school_year']) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Amount (₱)</label>
                    <input type="number" step="0.01" min="0.01" name="original_amount" value="<?= number_format((float) $editRow['original_amount'], 2, '.', '') ?>" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade &amp; Section</label>
                    <input name="grade_section" value="<?= h((string) ($editRow['grade_section'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                </div>
                <div class="flex gap-2">
                    <button class="rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold px-5 py-2.5">Save</button>
                    <a href="back_accounts.php" class="rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-sm font-bold px-5 py-2.5">Cancel</a>
                </div>
                <div class="sm:col-span-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Remarks</label>
                    <input name="remarks" maxlength="255" value="<?= h((string) ($editRow['remarks'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                </div>
            </form>
        </section>
        <?php endif; ?>

        <!-- ── Selected account: collect + history ────────────────────────── -->
        <?php if ($viewRow): ?>
        <section class="bg-white rounded-3xl border border-green-200 shadow-panel p-6 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-extrabold text-lg"><?= h((string) $viewRow['student_name']) ?></h2>
                    <p class="text-sm text-slate-500">
                        LRN <?= h((string) ($viewRow['lrn'] ?: '—')) ?> ·
                        Student ID <?= h((string) $viewRow['student_id']) ?> ·
                        S.Y. <?= h((string) $viewRow['school_year']) ?>
                        <?= $viewRow['grade_section'] ? ' · ' . h((string) $viewRow['grade_section']) : '' ?>
                    </p>
                    <?php if ($viewRow['remarks']): ?>
                    <p class="text-xs text-slate-400 mt-1"><?= h((string) $viewRow['remarks']) ?></p>
                    <?php endif; ?>
                </div>
                <span class="inline-flex rounded-full border px-3 py-1 text-xs font-bold <?= ba_status_badge((string) $viewRow['status']) ?>"><?= h((string) $viewRow['status']) ?></span>
            </div>

            <div class="grid grid-cols-3 gap-4 mb-5">
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <p class="text-xs text-slate-500 font-semibold">Original</p>
                    <p class="text-lg font-extrabold">₱<?= number_format((float) $viewRow['original_amount'], 2) ?></p>
                </div>
                <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3">
                    <p class="text-xs text-emerald-600 font-semibold">Paid</p>
                    <p class="text-lg font-extrabold text-emerald-700">₱<?= number_format((float) $viewRow['amount_paid'], 2) ?></p>
                </div>
                <div class="rounded-xl bg-rose-50 border border-rose-200 p-3">
                    <p class="text-xs text-rose-600 font-semibold">Balance</p>
                    <p class="text-lg font-extrabold text-rose-700">₱<?= number_format((float) $viewRow['balance'], 2) ?></p>
                </div>
            </div>

            <?php if (in_array($viewRow['status'], ['Unpaid', 'Partial'], true)): ?>
            <form method="POST" action="back_accounts.php" class="grid sm:grid-cols-4 gap-4 items-end border-t border-slate-100 pt-5"
                  onsubmit="return confirm('Collect this payment and issue an Official Receipt?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="collect">
                <input type="hidden" name="id" value="<?= (int) $viewRow['id'] ?>">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Amount to collect (₱)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" max="<?= number_format((float) $viewRow['balance'], 2, '.', '') ?>"
                           value="<?= number_format((float) $viewRow['balance'], 2, '.', '') ?>" required
                           class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-slate-400 mt-1">Partial payments are allowed.</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Method</label>
                    <select name="method" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                        <?php foreach (['Cash','GCash','Maya','Bank','Check'] as $m): ?>
                        <option value="<?= $m ?>"><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Reference # <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input name="reference_no" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                </div>
                <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">Collect &amp; Print OR</button>
            </form>
            <?php endif; ?>

            <!-- Payment history -->
            <h3 class="font-extrabold mt-6 mb-2 text-sm uppercase tracking-wider text-slate-500">Payment History</h3>
            <?php if (!$payments): ?>
            <p class="text-sm text-slate-400">No payments recorded yet.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wider text-slate-500 border-b border-slate-200">
                        <tr><th class="text-left py-2">Date</th><th class="text-left">OR #</th><th class="text-left">Method</th><th class="text-right">Amount</th><th class="text-left pl-3">Status</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($payments as $p): $void = $p['status'] === 'Voided'; ?>
                        <tr class="<?= $void ? 'text-slate-400 line-through' : '' ?>">
                            <td class="py-2"><?= h(date('M j, Y g:ia', strtotime((string) $p['paid_at']))) ?></td>
                            <td class="font-mono text-xs"><?= h((string) $p['or_number']) ?></td>
                            <td><?= h((string) $p['payment_method']) ?><?= $p['reference_no'] ? ' · ' . h((string) $p['reference_no']) : '' ?></td>
                            <td class="text-right font-bold">₱<?= number_format((float) $p['amount'], 2) ?></td>
                            <td class="pl-3"><?= $void ? 'Voided' : 'Paid' ?></td>
                            <td class="text-right">
                                <?php if (!$void): ?>
                                <a href="<?= h(app_url('cashier/back_account_receipt.php?id=' . (int) $p['id'])) ?>" target="_blank" class="text-green-700 font-bold hover:underline">Reprint</a>
                                <form method="POST" action="back_accounts.php" class="inline" onsubmit="return confirm('Void this payment? The balance will be restored.');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="void_payment">
                                    <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="id" value="<?= (int) $viewRow['id'] ?>">
                                    <input type="hidden" name="reason" value="Voided by cashier">
                                    <button class="ml-2 text-rose-600 font-bold hover:underline">Void</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="mt-4"><a href="back_accounts.php" class="text-sm text-slate-500 hover:underline">← Back to list</a></div>
        </section>
        <?php endif; ?>

        <!-- ── List ───────────────────────────────────────────────────────── -->
        <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
            <form method="GET" action="back_accounts.php" class="flex flex-wrap gap-3 items-end p-6 border-b border-slate-100">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Search</label>
                    <input name="q" value="<?= h($q) ?>" placeholder="Name, LRN, or student ID…"
                           class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Status</label>
                    <select name="status" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                        <option value="">All</option>
                        <?php foreach (['Unpaid','Partial','Paid','Cancelled'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Filter</button>
                <?php if ($q !== '' || $status !== ''): ?>
                <a href="back_accounts.php" class="rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-sm font-bold px-5 py-2.5">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (!$rows): ?>
            <div class="p-10 text-center text-slate-400">
                <p class="font-semibold">No back accounts found.</p>
                <p class="text-sm mt-1"><?= $q !== '' || $status !== '' ? 'Try a different search or filter.' : 'Record one using the form above.' ?></p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3">Student</th>
                            <th class="text-left">S.Y.</th>
                            <th class="text-right">Original</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Balance</th>
                            <th class="text-left pl-4">Status</th>
                            <th class="text-right px-6">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $r): ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-6 py-3">
                                <p class="font-bold"><?= h((string) $r['student_name']) ?></p>
                                <p class="text-xs text-slate-400">LRN <?= h((string) ($r['lrn'] ?: '—')) ?></p>
                            </td>
                            <td class="text-slate-600"><?= h((string) $r['school_year']) ?></td>
                            <td class="text-right">₱<?= number_format((float) $r['original_amount'], 2) ?></td>
                            <td class="text-right text-emerald-700">₱<?= number_format((float) $r['amount_paid'], 2) ?></td>
                            <td class="text-right font-extrabold <?= (float) $r['balance'] > 0.009 ? 'text-rose-700' : 'text-slate-400' ?>">₱<?= number_format((float) $r['balance'], 2) ?></td>
                            <td class="pl-4"><span class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-bold <?= ba_status_badge((string) $r['status']) ?>"><?= h((string) $r['status']) ?></span></td>
                            <td class="text-right px-6 whitespace-nowrap">
                                <a href="back_accounts.php?view=<?= (int) $r['id'] ?>" class="text-green-700 font-bold hover:underline"><?= in_array($r['status'], ['Unpaid','Partial'], true) ? 'Collect' : 'View' ?></a>
                                <?php if ($r['status'] !== 'Cancelled'): ?>
                                <a href="back_accounts.php?edit=<?= (int) $r['id'] ?>" class="ml-3 text-amber-700 font-bold hover:underline">Edit</a>
                                <?php endif; ?>
                                <?php if ((float) $r['amount_paid'] <= 0.009 && $r['status'] !== 'Cancelled'): ?>
                                <form method="POST" action="back_accounts.php" class="inline" onsubmit="return confirm('Cancel this back account? It will no longer appear on the student\'s SOA.');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="reason" value="Cancelled by cashier">
                                    <button class="ml-3 text-rose-600 font-bold hover:underline">Cancel</button>
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

        <?php endif; /* ready */ ?>
    </main>
</div>

<script>
// ── Student picker ───────────────────────────────────────────────────────────
const stuSearch  = document.getElementById('stuSearch');
const stuResults = document.getElementById('stuResults');
let stuTimer = null;
let stuSeq   = 0;    // guards against a slow earlier response overwriting a newer one
let stuList  = [];   // current results, for keyboard navigation
let stuIdx   = -1;   // highlighted row

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

function panel(html) { stuResults.innerHTML = html; stuResults.classList.remove('hidden'); }
function hidePanel()  { stuResults.classList.add('hidden'); stuIdx = -1; }

function clearPick() {
    document.getElementById('f_sid').value   = '';
    document.getElementById('f_sname').value = '';
    document.getElementById('f_lrn').value   = '';
    document.getElementById('stuPicked').classList.add('hidden');
    stuSearch.classList.remove('hidden');
    stuSearch.value = '';
    stuSearch.focus();
}

function pick(sid, name, lrn, sy) {
    document.getElementById('f_sid').value   = sid;
    document.getElementById('f_sname').value = name;
    document.getElementById('f_lrn').value   = lrn || '';
    document.getElementById('stuPickedName').textContent = name;
    document.getElementById('stuPickedMeta').textContent = ' · LRN ' + (lrn || '—') + ' · last enrolled ' + (sy || '—');
    document.getElementById('stuPicked').classList.remove('hidden');
    hidePanel();
    stuSearch.classList.add('hidden');
}

function pickIdx(i) {
    const s = stuList[i];
    if (s) { pick(s.student_id, s.display_name, s.lrn || '', s.latest_sy || ''); }
}

function render() {
    if (!stuList.length) {
        panel('<p class="px-4 py-3 text-sm text-slate-400">No student found. Try a surname or the full LRN.</p>');
        return;
    }
    panel(stuList.map((s, i) => `
        <button type="button" data-i="${i}"
                class="stu-row block w-full text-left px-4 py-2.5 text-sm border-b border-slate-100 ${i === stuIdx ? 'bg-green-100' : 'hover:bg-green-50'}">
            <span class="font-bold">${esc(s.display_name)}</span>
            <span class="text-slate-400"> · LRN ${esc(s.lrn || '—')} · ${esc(s.latest_sy || '')}</span>
        </button>`).join(''));
    stuResults.querySelectorAll('.stu-row').forEach(b =>
        b.addEventListener('click', () => pickIdx(parseInt(b.dataset.i, 10))));
}

function move(d) {
    if (!stuList.length) { return; }
    stuIdx = (stuIdx + d + stuList.length) % stuList.length;
    render();
    stuResults.querySelector(`[data-i="${stuIdx}"]`)?.scrollIntoView({ block: 'nearest' });
}

if (stuSearch) {
    stuSearch.addEventListener('input', () => {
        clearTimeout(stuTimer);
        const q = stuSearch.value.trim();
        stuIdx = -1;
        if (q.length < 2) {
            stuList = [];
            panel('<p class="px-4 py-3 text-sm text-slate-400">Keep typing — at least 2 characters.</p>');
            return;
        }
        panel('<p class="px-4 py-3 text-sm text-slate-400">Searching…</p>');
        stuTimer = setTimeout(async () => {
            const mySeq = ++stuSeq;
            try {
                const res  = await fetch('back_accounts.php?student_search=' + encodeURIComponent(q));
                const list = await res.json();
                if (mySeq !== stuSeq) { return; }   // a newer keystroke already won
                stuList = Array.isArray(list) ? list : [];
                render();
            } catch (e) {
                if (mySeq !== stuSeq) { return; }
                panel('<p class="px-4 py-3 text-sm text-rose-500">Search failed. Please try again.</p>');
            }
        }, 200);
    });

    stuSearch.addEventListener('keydown', e => {
        if (stuResults.classList.contains('hidden')) { return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter' && stuIdx >= 0) { e.preventDefault(); pickIdx(stuIdx); }
        else if (e.key === 'Escape') { hidePanel(); }
    });

    document.addEventListener('click', e => {
        if (!stuResults.contains(e.target) && e.target !== stuSearch) { hidePanel(); }
    });
}

document.getElementById('createForm')?.addEventListener('submit', e => {
    if (!document.getElementById('f_sid').value) {
        e.preventDefault();
        alert('Please search for and select a student first.');
    }
});
</script>
</body>
</html>
