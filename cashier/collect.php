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

$cashierName  = (string) ($user['full_name'] ?? 'Cashier');
$sy           = soa_active_school_year($connection);
$syLabel      = $sy['label'];
$syId         = $sy['id'];
$schemaReady  = soa_schema_ready($connection);

if ($schemaReady) {
    soa_mark_overdue($connection);
}

// ── POST: post a payment ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_payment') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('collect.php');
    }

    $assessmentId   = to_int($_POST['assessment_id'] ?? 0);
    $soaId          = to_int($_POST['soa_id'] ?? 0) ?: null;
    $method         = trim((string) ($_POST['method'] ?? 'Cash'));
    $referenceNo    = trim((string) ($_POST['reference_no'] ?? '')) ?: null;
    $amount         = (float) ($_POST['amount'] ?? 0);
    $scheduleIds    = array_map('intval', (array) ($_POST['schedule_ids'] ?? []));
    $idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? ''));

    if ($assessmentId <= 0) {
        flash_set('error', 'No student loaded.');
        redirect_to('collect.php');
    }
    if ($idempotencyKey === '') {
        $idempotencyKey = bin2hex(random_bytes(16));
    }

    try {
        $result = soa_post_payment(
            $connection,
            $assessmentId,
            $soaId,
            $method,
            $referenceNo,
            $amount,
            $scheduleIds,
            $user,
            $idempotencyKey
        );
        if ($result['duplicate']) {
            flash_set('success', 'This payment was already posted (OR ' . $result['or_number'] . ').');
        }
        redirect_to(app_url('cashier/or_print.php?payment_id=' . (int) $result['payment_id']));
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('collect.php?ref=' . urlencode((string) ($_POST['ref_echo'] ?? '')));
    }
}

// ── Lookup ────────────────────────────────────────────────────────────────────
$ref        = trim((string) ($_GET['ref'] ?? ''));
$loaded     = null;     // assessment meta
$schedule   = [];
$recentPays = [];
$preselect  = [];
$soaId      = 0;
$lookupMsg  = '';

if ($schemaReady && $ref !== '') {
    $resolved = soa_resolve_collection_ref($connection, $ref, $syId, $syLabel, $cashierName);
    if (!$resolved) {
        $lookupMsg = 'No SOA, OR, or student matched “' . $ref . '”.';
    } else {
        $assessmentId = $resolved['assessment_id'];
        $soaId        = (int) ($resolved['soa_id'] ?? 0);

        $aStmt = $connection->prepare(
            "SELECT sa.id, sa.student_id, sa.net_assessed, sa.total_discount, sa.total_paid, sa.balance,
                    sa.installment_count, sa.status,
                    en.Department, en.school_year,
                    COALESCE(
                        CONCAT(p.surname, ', ', p.firstname, ' ', IFNULL(p.middlename, '')),
                        CONCAT(osp.surname, ', ', osp.firstname, ' ', IFNULL(osp.middlename, ''))
                    ) AS full_name,
                    COALESCE(p.lrn, osp.lrn) AS lrn,
                    IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                    IFNULL(sc.Section_name, en.Department_section) AS section_name,
                    pb.description AS classification_desc
             FROM student_assessment sa
             JOIN enrollment en ON en.id = sa.enrollment_id
             LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
             LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
             LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
             LEFT JOIN payment_breakdown pb
                    ON pb.classification_id = en.Student_classification
                   AND pb.type = sa.student_type AND pb.status = 'Active'
             WHERE sa.id = ? LIMIT 1"
        );
        $aStmt->bind_param('i', $assessmentId);
        $aStmt->execute();
        $loaded = stmt_fetch_assoc($aStmt);

        if ($loaded) {
            $schedule = soa_get_schedule($connection, $assessmentId);

            // Map SOA's selected term_nos → schedule ids for preselection
            foreach ($resolved['preselect'] as $termNo) {
                if (isset($schedule[$termNo]) && $schedule[$termNo]['balance'] > 0) {
                    $preselect[] = $schedule[$termNo]['schedule_id'];
                }
            }

            $rpStmt = $connection->prepare(
                'SELECT pt.id, pt.method, pt.tendered, pt.paid_at, rm.or_number
                 FROM payment_transaction pt
                 LEFT JOIN receipt_master rm ON rm.payment_id = pt.id
                 WHERE pt.assessment_id = ? AND pt.status = \'Posted\'
                 ORDER BY pt.id DESC LIMIT 5'
            );
            $rpStmt->bind_param('i', $assessmentId);
            $rpStmt->execute();
            $recentPays = stmt_fetch_all_assoc($rpStmt);
        }
    }
}

$idemKey = bin2hex(random_bytes(16));
$flash   = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collect Payment | ITFA Cashier</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Collect Payment</h1>
            <p class="text-slate-500 mt-2">Scan an SOA / OR barcode or search a student, then post payment against installments.</p>
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
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ SOA tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/phase2_soa_system.sql</code> first.</p>
        </div>
        <?php else: ?>

        <!-- Scan / search -->
        <form method="GET" action="collect.php" class="mb-6">
            <div class="flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-[260px]">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-600">🔍</span>
                    <input type="text" name="ref" value="<?= h($ref) ?>" autofocus
                           placeholder="Scan SOA / OR barcode, or type Student ID / LRN / Name…"
                           class="w-full rounded-2xl border-2 border-slate-200 pl-11 pr-4 py-3 text-sm font-medium focus:outline-none focus:border-green-500">
                </div>
                <button type="submit" class="rounded-2xl bg-green-700 hover:bg-green-800 text-white px-6 py-3 text-sm font-bold">Load</button>
                <?php if ($ref !== ''): ?>
                <a href="collect.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($lookupMsg !== ''): ?>
        <div class="rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 px-5 py-4 text-sm font-medium"><?= h($lookupMsg) ?></div>
        <?php endif; ?>

        <?php if ($loaded): ?>
        <?php
            $hasOutstanding = false;
            foreach ($schedule as $row) { if ($row['balance'] > 0) { $hasOutstanding = true; break; } }
        ?>
        <div class="grid lg:grid-cols-[1fr_360px] gap-6">

            <!-- Student + schedule -->
            <div>
                <!-- Student card -->
                <div class="rounded-3xl bg-green-700 text-white p-6 mb-5 shadow-lg">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs text-green-300 font-semibold uppercase tracking-widest">Student</p>
                            <p class="text-2xl font-extrabold mt-1"><?= h(strtoupper(trim((string) $loaded['full_name']))) ?></p>
                            <p class="text-green-200 text-sm mt-0.5">
                                <?= h((string) $loaded['Department']) ?> · <?= h(trim((string) $loaded['grade_name'])) ?>
                                <?= $loaded['section_name'] ? ' · ' . h((string) $loaded['section_name']) : '' ?>
                            </p>
                            <p class="text-green-300 font-mono text-xs mt-1">
                                <?= h((string) ($loaded['lrn'] ?: $loaded['student_id'])) ?> · S.Y. <?= h((string) $loaded['school_year']) ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-green-300">Outstanding Balance</p>
                            <p class="text-3xl font-extrabold <?= (float) $loaded['balance'] > 0 ? 'text-amber-300' : 'text-emerald-300' ?>">
                                ₱<?= number_format((float) $loaded['balance'], 2) ?>
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-3 gap-4 border-t border-white/15 pt-4">
                        <div><p class="text-xs text-green-300">Net Assessment</p><p class="font-extrabold">₱<?= number_format((float) $loaded['net_assessed'], 2) ?></p></div>
                        <div><p class="text-xs text-green-300">Total Paid</p><p class="font-extrabold text-emerald-300">₱<?= number_format((float) $loaded['total_paid'], 2) ?></p></div>
                        <div><p class="text-xs text-green-300">Discounts</p><p class="font-extrabold">₱<?= number_format((float) $loaded['total_discount'], 2) ?></p></div>
                    </div>
                </div>

                <!-- Schedule -->
                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="font-bold">Installment Schedule</h2>
                        <span class="text-xs text-slate-400"><?= count($schedule) ?> months · select to pay</span>
                    </div>
                    <?php if ($schedule === []): ?>
                    <div class="py-10 text-center text-slate-400 text-sm">No installment schedule for this student.</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                                    <th class="px-4 py-3 text-center w-10"></th>
                                    <th class="px-4 py-3 text-left">Month</th>
                                    <th class="px-4 py-3 text-left">Due Date</th>
                                    <th class="px-4 py-3 text-right">Due</th>
                                    <th class="px-4 py-3 text-right">Paid</th>
                                    <th class="px-4 py-3 text-right">Balance</th>
                                    <th class="px-4 py-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($schedule as $termNo => $row): ?>
                                <?php
                                    $sid     = (int) $row['schedule_id'];
                                    $bal     = (float) $row['balance'];
                                    $st      = (string) $row['status'];
                                    $canPay  = $bal > 0;
                                    $checked = in_array($sid, $preselect, true);
                                    $colors  = ['Paid'=>'bg-emerald-100 text-emerald-700','Partial'=>'bg-amber-100 text-amber-700','Overdue'=>'bg-rose-100 text-rose-700','Unpaid'=>'bg-slate-100 text-slate-500'];
                                ?>
                                <tr class="<?= $st==='Overdue' ? 'bg-rose-50/40' : '' ?> hover:bg-green-50/30">
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($canPay): ?>
                                        <input type="checkbox" form="payForm" name="schedule_ids[]" value="<?= $sid ?>"
                                               class="term-box w-4 h-4 accent-green-600"
                                               data-balance="<?= number_format($bal, 2, '.', '') ?>"
                                               onchange="recalc()" <?= $checked ? 'checked' : '' ?>>
                                        <?php else: ?>✓<?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 font-medium">M<?= (int) $termNo ?> · <?= h((string) $row['month_label']) ?></td>
                                    <td class="px-4 py-3 text-slate-500"><?= $row['due_date'] ? date('M d, Y', strtotime((string) $row['due_date'])) : '—' ?></td>
                                    <td class="px-4 py-3 text-right">₱<?= number_format((float) $row['amount_due'], 2) ?></td>
                                    <td class="px-4 py-3 text-right <?= (float)$row['amount_paid']>0?'text-emerald-600':'text-slate-300' ?>"><?= (float)$row['amount_paid']>0 ? '₱'.number_format((float)$row['amount_paid'],2) : '—' ?></td>
                                    <td class="px-4 py-3 text-right font-semibold <?= $bal>0?'text-amber-600':'text-emerald-600' ?>">₱<?= number_format($bal, 2) ?></td>
                                    <td class="px-4 py-3 text-center"><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $colors[$st] ?? 'bg-slate-100 text-slate-500' ?>"><?= h($st) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($recentPays): ?>
                <div class="mt-5 bg-white rounded-3xl border border-slate-100 shadow-panel p-5">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Recent Payments</h3>
                    <div class="space-y-2">
                        <?php foreach ($recentPays as $rp): ?>
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-mono text-xs text-green-700"><?= h((string) ($rp['or_number'] ?? '—')) ?></span>
                            <span class="text-slate-500"><?= h((string) $rp['method']) ?> · <?= date('M d, Y', strtotime((string) $rp['paid_at'])) ?></span>
                            <span class="font-semibold">₱<?= number_format((float) $rp['tendered'], 2) ?></span>
                            <a href="or_print.php?reprint=<?= (int) $rp['id'] ?>" target="_blank" class="text-xs text-green-600 hover:underline">reprint</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment form -->
            <div class="lg:sticky lg:top-6 h-fit">
                <form method="POST" action="collect.php" id="payForm" class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="post_payment">
                    <input type="hidden" name="assessment_id" value="<?= (int) $loaded['id'] ?>">
                    <input type="hidden" name="soa_id" value="<?= (int) $soaId ?>">
                    <input type="hidden" name="idempotency_key" value="<?= h($idemKey) ?>">
                    <input type="hidden" name="ref_echo" value="<?= h($ref) ?>">

                    <h2 class="font-extrabold text-lg mb-4">Post Payment</h2>

                    <div class="rounded-2xl bg-green-50 border border-green-200 p-4 mb-4 flex items-center justify-between">
                        <span class="text-xs font-semibold text-green-700 uppercase tracking-wide">Selected total</span>
                        <span class="text-xl font-extrabold text-green-800" id="selTotal">₱0.00</span>
                    </div>

                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Payment Method</label>
                    <select name="method" id="method" onchange="toggleRef()"
                            class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-green-400">
                        <?php foreach (['Cash','GCash','Maya','Bank','Voucher'] as $m): ?>
                        <option value="<?= h($m) ?>"><?= h($m) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div id="refWrap" style="display:none" class="mb-3">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Reference No.</label>
                        <input type="text" name="reference_no" placeholder="GCash / bank reference"
                               class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>

                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Amount Received (₱)</label>
                    <div class="relative mb-2">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-700 font-bold text-lg">₱</span>
                        <input type="number" name="amount" id="amount" min="0.01" step="0.01" required
                               class="w-full rounded-2xl border-2 border-slate-200 pl-10 pr-4 py-3 text-xl font-bold text-green-700 focus:outline-none focus:border-green-600">
                    </div>
                    <p class="text-xs text-slate-400 mb-4">Excess over selected months cascades to the next unpaid installment (advance).</p>

                    <button type="submit" <?= $hasOutstanding ? '' : 'disabled' ?>
                            class="w-full rounded-2xl bg-green-700 hover:bg-green-800 disabled:opacity-40 disabled:cursor-not-allowed text-white px-6 py-3 text-sm font-bold shadow-sm flex items-center justify-center gap-2"
                            onclick="setTimeout(() => { this.disabled = true; this.textContent = 'Posting…'; }, 0);">
                        ✓ Post Payment &amp; Print OR
                    </button>
                    <?php if (!$hasOutstanding): ?>
                    <p class="text-xs text-emerald-600 font-semibold text-center mt-2">Account fully settled 🎉</p>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <script>
        function recalc() {
            let sum = 0;
            document.querySelectorAll('.term-box:checked').forEach(b => sum += parseFloat(b.dataset.balance) || 0);
            document.getElementById('selTotal').textContent = '₱' + sum.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
            const amt = document.getElementById('amount');
            if (sum > 0) amt.value = sum.toFixed(2);
        }
        function toggleRef() {
            const m = document.getElementById('method').value;
            document.getElementById('refWrap').style.display = (m === 'Cash') ? 'none' : '';
        }
        recalc();
        </script>

        <?php elseif ($ref === ''): ?>
        <div class="rounded-3xl bg-white border border-slate-100 shadow-panel p-10 text-center text-slate-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
            <p class="font-semibold text-slate-500">Scan an SOA or OR barcode, or search a student to begin.</p>
        </div>
        <?php endif; ?>

        <?php endif; /* schemaReady */ ?>

    </main>
</div>
</body>
</html>
