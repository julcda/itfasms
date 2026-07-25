<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/backfill.php';

require_login();

$connection = db();
$user       = current_user();

// Maintenance tool — Super Admin only.
if (!is_super_admin($user)) {
    flash_set('error', 'Only Super Admin can run the enrollment-payment backfill.');
    redirect_to(app_url('cashier/dashboard.php'));
}

$activeSchoolYearLabel = soa_active_school_year($connection)['label'];
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('run_backfill.php');
    }
    @set_time_limit(0); // best effort; ignored on hosts that disable it
    // Process a batch (re-runnable: only un-imported records are touched).
    $batch  = max(0, (int) ($_POST['batch'] ?? 1000));
    $result = soa_backfill_run($connection, $batch);
    flash_set('success', 'Backfill batch complete: ' . $result['imported'] . ' imported, '
        . $result['skipped'] . ' skipped, ' . $result['errors'] . ' error(s).');
}

$status = soa_backfill_status($connection);
$flash  = flash_get();
$csrf   = csrf_token();
$pct    = $status['total'] > 0 ? round($status['done'] / $status['total'] * 100) : 100;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Run Enrollment Backfill | ITFA Cashier</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Super Admin · Maintenance</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Enrollment Payment Backfill</h1>
            <p class="text-slate-500 mt-2">Imports the enrollment-day collections (from <code>backaccount_payment_records</code>) into the new payment ledger, so payment history and the dashboard reflect them. Safe to run repeatedly — already-imported payments are skipped.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6 max-w-2xl">
            <div class="grid sm:grid-cols-3 gap-4 mb-5">
                <div class="rounded-2xl border border-slate-100 p-4"><p class="text-xs uppercase text-slate-400 font-semibold">Total records</p><p class="text-2xl font-extrabold mt-1"><?= number_format($status['total']) ?></p></div>
                <div class="rounded-2xl border border-slate-100 p-4"><p class="text-xs uppercase text-slate-400 font-semibold">Imported</p><p class="text-2xl font-extrabold mt-1 text-emerald-600"><?= number_format($status['done']) ?></p></div>
                <div class="rounded-2xl border border-slate-100 p-4"><p class="text-xs uppercase text-slate-400 font-semibold">Remaining</p><p class="text-2xl font-extrabold mt-1 <?= $status['remaining']>0?'text-rose-600':'text-emerald-600' ?>"><?= number_format($status['remaining']) ?></p></div>
            </div>

            <div class="w-full bg-slate-100 rounded-full h-3 mb-2 overflow-hidden">
                <div class="bg-green-600 h-3" style="width: <?= $pct ?>%"></div>
            </div>
            <p class="text-xs text-slate-500 mb-5"><?= $pct ?>% imported</p>

            <?php if (!empty($result['messages'])): ?>
            <div class="rounded-xl bg-rose-50 border border-rose-200 p-3 mb-4 text-xs text-rose-700">
                <p class="font-bold mb-1">Errors (first 20):</p>
                <?php foreach ($result['messages'] as $m): ?><div><?= h($m) ?></div><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($status['remaining'] > 0): ?>
            <form method="POST" action="run_backfill.php" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Running… please wait';">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="batch" value="1000">
                <button class="w-full rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-3">
                    ▶ Run backfill (next <?= number_format(min(1000, $status['remaining'])) ?> records)
                </button>
                <p class="text-[11px] text-slate-400 mt-2 text-center">If it stops or times out, just click again — it continues where it left off.</p>
            </form>
            <?php else: ?>
            <div class="rounded-xl bg-emerald-50 border border-emerald-300 text-emerald-800 text-sm font-semibold px-4 py-4 text-center">
                ✓ All enrollment payments are imported. Payment history and the dashboard now reflect them.
            </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
