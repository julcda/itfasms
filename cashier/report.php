<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/collection_report.php';

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

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to   = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$type = (string) ($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'tuition', 'other'], true)) { $type = 'all'; }

$rows  = collection_report_rows($connection, $from, $to, $type);
$total = collection_report_total($rows);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collection Report | ITFA Cashier</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier · Reports</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Collection Report</h1>
            <p class="text-slate-500 mt-2">All collections within a date range — tuition &amp; other payments — with the total.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?></p>
        </header>

        <!-- Filters -->
        <form method="GET" action="report.php" class="mb-5 bg-white rounded-3xl border border-green-100 shadow-panel p-5 flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Start date</label>
                <input type="date" name="from" value="<?= h($from) ?>" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">End date</label>
                <input type="date" name="to" value="<?= h($to) ?>" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Type</label>
                <select name="type" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    <option value="all"     <?= $type==='all'?'selected':'' ?>>All collections</option>
                    <option value="tuition" <?= $type==='tuition'?'selected':'' ?>>Tuition &amp; School Fees</option>
                    <option value="other"   <?= $type==='other'?'selected':'' ?>>Other payments</option>
                </select>
            </div>
            <button class="rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-bold px-6 py-2.5">Generate</button>
            <a href="<?= h(app_url('cashier/report_print.php?from='.urlencode($from).'&to='.urlencode($to).'&type='.urlencode($type))) ?>" target="_blank"
               class="rounded-xl bg-white border border-green-300 text-green-700 hover:bg-green-50 text-sm font-bold px-5 py-2.5">🖨 Print Report</a>
        </form>

        <!-- Summary -->
        <section class="grid sm:grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Receipts in range</p>
                <p class="text-2xl font-extrabold mt-1"><?= number_format(count($rows)) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Total collected</p>
                <p class="text-2xl font-extrabold mt-1 text-emerald-600">₱<?= number_format($total, 2) ?></p>
            </div>
        </section>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
            <p class="text-sm text-slate-500 mb-3"><?= h(date('M j, Y', strtotime($from))) ?> &ndash; <?= h(date('M j, Y', strtotime($to))) ?></p>
            <?php if (!$rows): ?>
            <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-10 text-center">No collections in this range.</div>
            <?php else: ?>
            <div class="overflow-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                            <th class="py-2 pr-3 text-right w-12">No.</th>
                            <th class="py-2 pr-3">Date</th>
                            <th class="py-2 pr-3">Received From</th>
                            <th class="py-2 pr-3">Payment Particular</th>
                            <th class="py-2 pr-3">Receipt No.</th>
                            <th class="py-2 pr-3">Method</th>
                            <th class="py-2 pr-3 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pr-3 text-right text-slate-400"><?= $i + 1 ?></td>
                            <td class="py-2 pr-3 text-slate-500 whitespace-nowrap"><?= h(date('M j, Y', strtotime($r['dt']))) ?></td>
                            <td class="py-2 pr-3"><?= h($r['name']) ?></td>
                            <td class="py-2 pr-3 text-slate-600"><?= h($r['particular']) ?></td>
                            <td class="py-2 pr-3 font-mono text-slate-700"><?= h($r['or_number']) ?></td>
                            <td class="py-2 pr-3 text-slate-500"><?= h($r['method']) ?></td>
                            <td class="py-2 pr-3 text-right font-semibold">₱<?= number_format($r['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-green-50">
                            <td colspan="6" class="py-3 pr-3 text-right font-extrabold text-green-800">TOTAL</td>
                            <td class="py-3 pr-3 text-right font-extrabold text-green-800 text-lg">₱<?= number_format($total, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
