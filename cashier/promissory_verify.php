<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/promissory_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only Cashier or Super Admin users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$cashierName           = (string) ($user['full_name'] ?? 'Cashier');
$sy                    = soa_active_school_year($connection);
$activeSchoolYearLabel = $sy['label'];
$ready                 = pn_table_ready($connection);

if ($ready) {
    pn_mark_overdue($connection);
}

// ── POST: verify / mark paid ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to('promissory_verify.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $id     = to_int($_POST['promissory_id'] ?? 0);
    $ref    = (string) ($_POST['ref'] ?? '');
    try {
        if ($action === 'verify') {
            pn_verify($connection, $id, $user);
            flash_set('success', 'Promissory note verified and recorded as an approved deferred arrangement.');
        } elseif ($action === 'paid') {
            pn_set_status($connection, $id, 'Paid', $user);
            flash_set('success', 'Promissory note marked as Paid.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('promissory_verify.php' . ($ref !== '' ? '?ref=' . urlencode($ref) : ''));
}

// ── Lookup ────────────────────────────────────────────────────────────────────
$ref = trim((string) ($_GET['ref'] ?? ''));
$pn  = null; $lookupMsg = '';
if ($ready && $ref !== '') {
    // Accept either the PN number or the numeric id.
    $pn = pn_get_by_no($connection, $ref);
    if (!$pn && ctype_digit($ref)) {
        $pn = pn_get($connection, (int) $ref);
    }
    if (!$pn) {
        $lookupMsg = 'No promissory note matched “' . $ref . '”.';
    }
}

// Recent notes awaiting verification.
$pending = [];
if ($ready) {
    $res = $connection->query(
        "SELECT pn.promissory_id, pn.promissory_no, pn.promissory_amount, pn.promised_payment_date, pn.status,
                COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname)) AS full_name
         FROM promissory_notes pn
         JOIN enrollment en ON en.id = pn.enrollment_id
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         WHERE pn.cashier_verified = 0 AND pn.status IN ('Pending','Overdue')
         ORDER BY pn.promissory_id DESC LIMIT 15"
    );
    if ($res) { while ($r = $res->fetch_assoc()) { $pending[] = $r; } }
}

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Promissory Note | ITFA Cashier</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier · Promissory</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Verify Promissory Note</h1>
            <p class="text-slate-500 mt-2">Scan or enter the Promissory Note ID presented by the student to validate and record the deferred-payment arrangement.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?></p>
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

        <div class="grid lg:grid-cols-2 gap-6">
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <form method="GET" action="promissory_verify.php" class="mb-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Promissory Note ID</label>
                    <div class="flex gap-2">
                        <input type="text" name="ref" value="<?= h($ref) ?>" autofocus placeholder="Scan or type, e.g. PN-2026-000001"
                               class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-5">Find</button>
                    </div>
                </form>

                <?php if ($lookupMsg): ?>
                <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3"><?= h($lookupMsg) ?></div>
                <?php endif; ?>

                <?php if ($pn): ?>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm space-y-1.5">
                    <div class="flex justify-between"><span class="text-slate-500">PN No.</span><span class="font-mono font-bold"><?= h((string) $pn['promissory_no']) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Student</span><span class="font-semibold"><?= h((string) $pn['full_name']) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Grade / Section</span><span><?= h((string) $pn['grade_name']) ?> · <?= h((string) $pn['section_name']) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Outstanding</span><span>₱<?= number_format((float) $pn['outstanding_balance'], 2) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Promissory Amount</span><span class="font-bold">₱<?= number_format((float) $pn['promissory_amount'], 2) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Promised Date</span><span><?= h(date('M j, Y', strtotime((string) $pn['promised_payment_date']))) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Status</span><span><?= pn_status_badge((string) $pn['status']) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Cashier Verified</span><span><?= ((int) $pn['cashier_verified']===1) ? '<b class="text-emerald-600">✓ '.h((string) $pn['cashier_verified_by']).'</b>' : '<span class="text-slate-400">Not yet</span>' ?></span></div>
                </div>

                <div class="flex gap-2 mt-4">
                    <?php if ((string) $pn['status'] === 'Cancelled'): ?>
                    <p class="text-sm text-slate-500">This note is cancelled.</p>
                    <?php else: ?>
                        <?php if ((int) $pn['cashier_verified'] === 0): ?>
                        <form method="POST" action="promissory_verify.php" class="flex-1" onsubmit="return confirm('Verify this promissory note as an approved deferred arrangement?');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="verify">
                            <input type="hidden" name="promissory_id" value="<?= (int) $pn['promissory_id'] ?>"><input type="hidden" name="ref" value="<?= h($ref) ?>">
                            <button class="w-full rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold py-2.5">✓ Verify Note</button>
                        </form>
                        <?php endif; ?>
                        <?php if ((string) $pn['status'] !== 'Paid'): ?>
                        <form method="POST" action="promissory_verify.php" class="flex-1" onsubmit="return confirm('Mark this promissory note as PAID?');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="paid">
                            <input type="hidden" name="promissory_id" value="<?= (int) $pn['promissory_id'] ?>"><input type="hidden" name="ref" value="<?= h($ref) ?>">
                            <button class="w-full rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-2.5">Mark Paid</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <a href="<?= h(app_url('registrar/promissory_print.php?id=' . (int) $pn['promissory_id'])) ?>" target="_blank" class="rounded-xl bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-bold px-4 py-2.5">Print</a>
                </div>
                <?php endif; ?>
            </section>

            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-3">Awaiting Verification <span class="text-amber-600">(<?= count($pending) ?>)</span></h2>
                <?php if (!$pending): ?>
                <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-8 text-center">No unverified notes.</div>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($pending as $r): ?>
                    <a href="promissory_verify.php?ref=<?= h(urlencode((string) $r['promissory_no'])) ?>" class="flex items-center justify-between rounded-xl border border-slate-100 hover:bg-green-50/40 px-4 py-2.5 text-sm">
                        <div>
                            <span class="font-mono font-semibold"><?= h((string) $r['promissory_no']) ?></span>
                            <span class="block text-[11px] text-slate-500"><?= h((string) ($r['full_name'] ?: '—')) ?> · ₱<?= number_format((float) $r['promissory_amount'], 2) ?></span>
                        </div>
                        <?= pn_status_badge((string) $r['status']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>

        <?php endif; ?>
    </main>
</div>
</body>
</html>
