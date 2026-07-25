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
$syId                  = (int) $sy['id'];
$syLabel               = $sy['label'];
$schemaReady           = soa_table_exists($connection, 'bank_deposits');
$activeSchoolYearLabel = $syLabel;

// ── POST: create / void ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('deposits.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!$schemaReady) {
            throw new RuntimeException('Run migrations/bank_deposits.sql first.');
        }

        if ($action === 'create') {
            $amount  = round((float) ($_POST['amount'] ?? 0), 2);
            $date    = trim((string) ($_POST['deposit_date'] ?? ''));
            $bank    = trim((string) ($_POST['bank_name'] ?? '')) ?: null;
            $account = trim((string) ($_POST['bank_account'] ?? '')) ?: null;
            $ref     = trim((string) ($_POST['reference_no'] ?? '')) ?: null;
            $pFrom   = trim((string) ($_POST['period_from'] ?? '')) ?: null;
            $pTo     = trim((string) ($_POST['period_to'] ?? '')) ?: null;
            $notes   = trim((string) ($_POST['notes'] ?? '')) ?: null;

            if ($amount <= 0) {
                throw new RuntimeException('Deposit amount must be greater than zero.');
            }
            if ($date === '') {
                throw new RuntimeException('Deposit date is required.');
            }

            $connection->begin_transaction();
            try {
                $depNo = soa_next_document_number($connection, 'DEP', 'DEP', (int) date('Y'));
                $ins = $connection->prepare(
                    'INSERT INTO bank_deposits
                        (deposit_no, deposit_date, amount, bank_name, bank_account, reference_no,
                         period_from, period_to, school_year_id, prepared_by, prepared_by_id, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->bind_param(
                    'ssdsssssiiss',
                    $depNo, $date, $amount, $bank, $account, $ref,
                    $pFrom, $pTo, $syId, $cashierName, $cashierId, $notes
                );
                $ins->execute();
                $newId = (int) $connection->insert_id;
                $connection->commit();
            } catch (Throwable $e) {
                $connection->rollback();
                throw $e;
            }
            flash_set('success', 'Deposit ' . $depNo . ' certified (₱' . number_format($amount, 2) . ').');
            redirect_to(app_url('cashier/deposit_certificate.php?id=' . $newId));
        }

        if ($action === 'void') {
            $id = to_int($_POST['id'] ?? 0);
            $row = null;
            $s = $connection->prepare('SELECT prepared_by_id, status FROM bank_deposits WHERE id = ? LIMIT 1');
            $s->bind_param('i', $id);
            $s->execute();
            $row = stmt_fetch_assoc($s);
            if (!$row) {
                throw new RuntimeException('Deposit not found.');
            }
            if (!$isAdmin && (int) $row['prepared_by_id'] !== $cashierId) {
                throw new RuntimeException('You can only void a deposit you prepared.');
            }
            if ((string) $row['status'] === 'Void') {
                throw new RuntimeException('This deposit is already void.');
            }
            $upd = $connection->prepare("UPDATE bank_deposits SET status='Void', voided_by=?, voided_at=NOW() WHERE id=?");
            $upd->bind_param('si', $cashierName, $id);
            $upd->execute();
            flash_set('success', 'Deposit voided.');
            redirect_to('deposits.php');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('deposits.php');
    }
}

// ── Reference figures: cash collected (to guide the deposit amount) ────────────
$cashWeek = 0.0; $cashMonth = 0.0; $totalDeposited = 0.0; $deposits = [];
if ($schemaReady) {
    // Cash collections this ISO week (Mon-today) and this calendar month.
    $r = $connection->query(
        "SELECT
            IFNULL(SUM(CASE WHEN YEARWEEK(pt.paid_at,3)=YEARWEEK(CURDATE(),3) THEN pt.amount END),0) AS cash_week,
            IFNULL(SUM(CASE WHEN YEAR(pt.paid_at)=YEAR(CURDATE()) AND MONTH(pt.paid_at)=MONTH(CURDATE()) THEN pt.amount END),0) AS cash_month
         FROM payment_transaction pt
         JOIN student_assessment sa ON sa.id = pt.assessment_id
         WHERE pt.status='Posted' AND pt.method='Cash' AND sa.school_year_id=" . $syId
    );
    if ($r && ($x = $r->fetch_assoc())) {
        $cashWeek  = (float) $x['cash_week'];
        $cashMonth = (float) $x['cash_month'];
    }
    $d = $connection->query("SELECT IFNULL(SUM(amount),0) s FROM bank_deposits WHERE status='Active' AND school_year_id=" . $syId);
    if ($d && ($y = $d->fetch_assoc())) {
        $totalDeposited = (float) $y['s'];
    }

    $q = $connection->query(
        "SELECT * FROM bank_deposits WHERE school_year_id=" . $syId . " ORDER BY deposit_date DESC, id DESC LIMIT 200"
    );
    if ($q) { while ($row = $q->fetch_assoc()) { $deposits[] = $row; } }
}

// Default the deposit date to the most recent Thursday (weekly deposit day).
$today = new DateTime('today');
$dow   = (int) $today->format('N'); // 1=Mon..7=Sun
$defaultDate = (clone $today)->modify('-' . (($dow - 4 + 7) % 7) . ' days')->format('Y-m-d');

$flash = flash_get();
$csrf  = csrf_token();

function _dep_badge(string $status): string
{
    return $status === 'Void'
        ? '<span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-slate-200 text-slate-500 border-slate-300">Void</span>'
        : '<span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-emerald-100 text-emerald-800 border-emerald-300">Active</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bank Deposits | ITFA Cashier</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier · Banking</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Bank Deposit Certification</h1>
            <p class="text-slate-500 mt-2">Certify the weekly cash deposit to the bank, then print the certificate. A full deposit history is kept below.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Deposit table not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/bank_deposits.sql</code> first.</p>
        </div>
        <?php else: ?>

        <!-- Reference figures -->
        <section class="grid sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Cash collected · this week</p>
                <p class="text-2xl font-extrabold mt-1">₱<?= number_format($cashWeek, 2) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Cash collected · this month</p>
                <p class="text-2xl font-extrabold mt-1">₱<?= number_format($cashMonth, 2) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Deposited (recorded) · S.Y.</p>
                <p class="text-2xl font-extrabold mt-1 text-green-700">₱<?= number_format($totalDeposited, 2) ?></p>
            </div>
        </section>

        <div class="grid lg:grid-cols-[420px_1fr] gap-6">
            <!-- Create deposit -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6 h-fit">
                <h2 class="font-extrabold text-lg mb-4">New Deposit</h2>
                <form method="POST" action="deposits.php" class="space-y-4"
                      onsubmit="return confirm('Certify this bank deposit?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Amount Deposited <span class="text-rose-500">*</span></label>
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400 text-lg">₱</span>
                            <input type="number" step="0.01" min="0" name="amount" required
                                   class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-bold focus:ring-2 focus:ring-green-400 focus:border-green-400">
                        </div>
                        <button type="button" onclick="document.querySelector('[name=amount]').value='<?= number_format($cashWeek,2,'.','') ?>'"
                                class="mt-1.5 text-xs text-green-600 font-semibold hover:underline">Use this week's cash (₱<?= number_format($cashWeek,2) ?>)</button>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Deposit Date <span class="text-rose-500">*</span></label>
                        <input type="date" name="deposit_date" value="<?= h($defaultDate) ?>" required
                               class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400">
                        <p class="text-[11px] text-slate-400 mt-1">Defaults to this week's Thursday.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Bank</label>
                            <input type="text" name="bank_name" placeholder="e.g. Landbank" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Account No.</label>
                            <input type="text" name="bank_account" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Deposit Slip / Reference No.</label>
                        <input type="text" name="reference_no" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Period from</label>
                            <input type="date" name="period_from" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Period to</label>
                            <input type="date" name="period_to" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Notes</label>
                        <input type="text" name="notes" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                    </div>

                    <button class="w-full rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-3">
                        Certify &amp; Print
                    </button>
                </form>
            </section>

            <!-- History -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-4">Deposit History</h2>
                <?php if (!$deposits): ?>
                <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-10 text-center">No deposits recorded yet.</div>
                <?php else: ?>
                <div class="overflow-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                                <th class="py-2 pr-3">Deposit No.</th>
                                <th class="py-2 pr-3">Date</th>
                                <th class="py-2 pr-3">Bank</th>
                                <th class="py-2 pr-3 text-right">Amount</th>
                                <th class="py-2 pr-3">Prepared by</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deposits as $d): $void = $d['status'] === 'Void'; ?>
                            <tr class="border-b border-slate-100 <?= $void ? 'opacity-50' : '' ?>">
                                <td class="py-2.5 pr-3 font-mono font-semibold text-slate-700"><?= h((string) $d['deposit_no']) ?></td>
                                <td class="py-2.5 pr-3"><?= h(date('M j, Y', strtotime((string) $d['deposit_date']))) ?></td>
                                <td class="py-2.5 pr-3 text-slate-600"><?= h((string) ($d['bank_name'] ?: '—')) ?></td>
                                <td class="py-2.5 pr-3 text-right font-bold">₱<?= number_format((float) $d['amount'], 2) ?></td>
                                <td class="py-2.5 pr-3 text-slate-500"><?= h((string) $d['prepared_by']) ?></td>
                                <td class="py-2.5 pr-3"><?= _dep_badge((string) $d['status']) ?></td>
                                <td class="py-2.5 pr-3 text-right whitespace-nowrap">
                                    <a href="<?= h(app_url('cashier/deposit_certificate.php?id=' . (int) $d['id'])) ?>" target="_blank"
                                       class="inline-block rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5">Certificate</a>
                                    <?php if (!$void && ($isAdmin || (int) $d['prepared_by_id'] === $cashierId)): ?>
                                    <form method="POST" action="deposits.php" class="inline" onsubmit="return confirm('Void this deposit certification?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="void">
                                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                        <button class="rounded-lg bg-white border border-slate-300 hover:bg-rose-50 text-rose-600 text-xs font-bold px-3 py-1.5">Void</button>
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
        </div>

        <?php endif; ?>
    </main>
</div>
</body>
</html>
