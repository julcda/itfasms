<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/other_payment_service.php';

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
$ready                 = op_schema_ready($connection);

// ── POST: collect / void ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to('other_payment.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!$ready) {
            throw new RuntimeException('Run migrations/other_payments.sql first.');
        }
        if ($action === 'collect') {
            // Optional student link by LRN / ID.
            $sid = null; $eid = null;
            $ref = trim((string) ($_POST['student_ref'] ?? ''));
            if ($ref !== '') {
                $st = $connection->prepare(
                    "SELECT en.id, en.student_id FROM enrollment en
                     LEFT JOIN preregistration p ON en.student_id=CAST(p.id AS CHAR)
                     LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id=en.student_id)
                     WHERE en.school_year = ? AND (en.student_id = ? OR p.lrn = ? OR osp.lrn = ?) LIMIT 1"
                );
                $st->bind_param('ssss', $syLabel, $ref, $ref, $ref);
                $st->execute();
                if ($row = stmt_fetch_assoc($st)) { $eid = (int) $row['id']; $sid = (string) $row['student_id']; }
            }
            $items = [];
            $names = (array) ($_POST['item_name'] ?? []);
            $qtys  = (array) ($_POST['item_qty'] ?? []);
            $amts  = (array) ($_POST['item_amount'] ?? []);
            foreach ($names as $i => $nm) {
                $items[] = ['name' => (string) $nm, 'quantity' => (int) ($qtys[$i] ?? 1), 'unit_amount' => (float) ($amts[$i] ?? 0)];
            }
            $res = op_create($connection, [
                'name'           => (string) ($_POST['payer_name'] ?? ''),
                'student_id'     => $sid,
                'enrollment_id'  => $eid,
                'payment_method' => (string) ($_POST['method'] ?? 'Cash'),
                'reference_no'   => (string) ($_POST['reference_no'] ?? ''),
                'items'          => $items,
            ], $user);
            flash_set('success', 'Payment recorded — OR ' . $res['or_number'] . ' (₱' . number_format($res['total'], 2) . ').');
            redirect_to(app_url('cashier/other_receipt.php?id=' . (int) $res['payment_id']));
        }
        if ($action === 'void') {
            op_void($connection, to_int($_POST['id'] ?? 0), $user);
            flash_set('success', 'Payment voided.');
            redirect_to('other_payment.php');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('other_payment.php');
    }
}

$catalog = $ready ? op_catalog($connection) : [];
$q       = trim((string) ($_GET['q'] ?? ''));
$status  = (string) ($_GET['status'] ?? '');
$rows    = $ready ? op_list($connection, $q, $status) : [];
$today   = $ready ? op_today_totals($connection) : ['total' => 0, 'count' => 0];
$flash   = flash_get();
$csrf    = csrf_token();
$catalogJs = [];
foreach ($catalog as $c) { $catalogJs[$c['name']] = (float) $c['default_amount']; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Other Payments | ITFA Cashier</title>
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
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Other Payments</h1>
            <p class="text-slate-500 mt-2">Collect &amp; issue receipts for ID, sling, certifications, forms, exams — or any open/custom item.</p>
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
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Other-payments tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/other_payments.sql</code> first.</p>
        </div>
        <?php else: ?>

        <div class="grid lg:grid-cols-[1fr_360px] gap-6">
            <!-- Collect -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-4">New Payment</h2>
                <form method="POST" action="other_payment.php" onsubmit="return opSubmit();">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="collect">

                    <div class="grid sm:grid-cols-2 gap-3 mb-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Payer Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="payer_name" required placeholder="Surname, First name" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Student LRN / ID <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" name="student_ref" placeholder="link to a student" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        </div>
                    </div>

                    <label class="block text-sm font-semibold text-slate-700 mb-1">Items</label>
                    <datalist id="catalogList">
                        <?php foreach ($catalog as $c): ?><option value="<?= h((string) $c['name']) ?>"></option><?php endforeach; ?>
                    </datalist>
                    <div id="itemRows" class="space-y-2"></div>
                    <button type="button" onclick="opAddRow()" class="mt-2 text-sm text-green-600 font-semibold hover:underline">+ Add item</button>

                    <div class="mt-4 flex items-center justify-between rounded-2xl bg-green-50 px-4 py-3">
                        <span class="font-bold text-green-800">TOTAL</span>
                        <span id="grandTotal" class="text-2xl font-extrabold text-green-800">₱0.00</span>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-3 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Method</label>
                            <select name="method" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                                <option>Cash</option><option>GCash</option><option>Maya</option><option>Bank</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Reference No. <span class="text-slate-400 font-normal">(if online)</span></label>
                            <input type="text" name="reference_no" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                        </div>
                    </div>

                    <button class="w-full mt-5 rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-3">Record &amp; Print Receipt</button>
                </form>
            </section>

            <!-- Today -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6 h-fit">
                <h2 class="font-extrabold text-lg mb-3">Today</h2>
                <div class="rounded-2xl border border-slate-100 p-5 text-center">
                    <p class="text-xs uppercase text-slate-400 font-semibold">Other collections today</p>
                    <p class="text-3xl font-extrabold mt-1 text-emerald-600">₱<?= number_format($today['total'], 2) ?></p>
                    <p class="text-xs text-slate-400 mt-1"><?= (int) $today['count'] ?> receipt(s)</p>
                </div>
                <p class="text-[11px] text-slate-400 mt-3">These are included in your End-of-Day cash close.</p>
            </section>
        </div>

        <!-- History -->
        <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6 mt-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h2 class="font-extrabold text-lg">Receipt History</h2>
                <form method="GET" action="other_payment.php" class="flex gap-2">
                    <input type="text" name="q" value="<?= h($q) ?>" placeholder="OR, name, item…" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <select name="status" class="rounded-xl border border-slate-300 px-2 py-2 text-sm">
                        <option value="">All</option>
                        <option value="Paid" <?= $status==='Paid'?'selected':'' ?>>Paid</option>
                        <option value="Void" <?= $status==='Void'?'selected':'' ?>>Void</option>
                    </select>
                    <button class="rounded-xl bg-green-600 text-white text-sm font-semibold px-4">Go</button>
                </form>
            </div>
            <?php if (!$rows): ?>
            <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-8 text-center">No receipts yet.</div>
            <?php else: ?>
            <div class="overflow-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                        <th class="py-2 pr-3">OR No.</th><th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Payer</th><th class="py-2 pr-3">Items</th><th class="py-2 pr-3 text-right">Amount</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3 text-right">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): $void = $r['status'] === 'Void'; ?>
                        <tr class="border-b border-slate-100 <?= $void ? 'opacity-50' : '' ?>">
                            <td class="py-2.5 pr-3 font-mono font-semibold"><?= h((string) $r['or_number']) ?></td>
                            <td class="py-2.5 pr-3 text-slate-500 whitespace-nowrap"><?= h(date('M j, Y', strtotime((string) $r['when_paid']))) ?></td>
                            <td class="py-2.5 pr-3"><?= h((string) $r['Name']) ?></td>
                            <td class="py-2.5 pr-3 text-slate-600 text-xs max-w-[220px] truncate" title="<?= h((string) $r['Purpose']) ?>"><?= h((string) $r['Purpose']) ?></td>
                            <td class="py-2.5 pr-3 text-right font-bold">₱<?= number_format((float) $r['Amount'], 2) ?></td>
                            <td class="py-2.5 pr-3"><?= $void ? '<span class="text-xs font-semibold text-slate-400">Void</span>' : '<span class="text-xs font-semibold text-emerald-600">Paid</span>' ?></td>
                            <td class="py-2.5 pr-3 text-right whitespace-nowrap">
                                <a href="<?= h(app_url('cashier/other_receipt.php?id=' . (int) $r['Payment_ID'])) ?>" target="_blank" class="inline-block rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5">Receipt</a>
                                <?php if (!$void): ?>
                                <form method="POST" action="other_payment.php" class="inline" onsubmit="return confirm('Void this receipt?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int) $r['Payment_ID'] ?>">
                                    <button class="rounded-lg bg-white border border-rose-300 hover:bg-rose-50 text-rose-600 text-xs font-bold px-3 py-1.5">Void</button>
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

<script>
const CATALOG = <?= json_encode($catalogJs) ?>;
let rowIdx = 0;
function opAddRow(name = '', amt = '') {
    const i = rowIdx++;
    const div = document.createElement('div');
    div.className = 'flex gap-2 items-center';
    div.innerHTML =
        '<input list="catalogList" name="item_name[]" placeholder="Item (or type custom)" value="' + name + '" oninput="opFill(this)" class="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">' +
        '<input type="number" name="item_qty[]" value="1" min="1" oninput="opTotal()" class="w-16 rounded-xl border border-slate-300 px-2 py-2 text-sm text-center" title="Qty">' +
        '<span class="text-slate-400">₱</span>' +
        '<input type="number" name="item_amount[]" step="0.01" min="0" value="' + amt + '" placeholder="0.00" oninput="opTotal()" class="w-28 rounded-xl border border-slate-300 px-2 py-2 text-sm font-semibold" title="Unit price">' +
        '<button type="button" onclick="this.parentNode.remove();opTotal();" class="text-rose-500 font-bold px-1">✕</button>';
    document.getElementById('itemRows').appendChild(div);
    opTotal();
}
function opFill(inp) {
    const amtInput = inp.parentNode.querySelector('[name="item_amount[]"]');
    if (CATALOG.hasOwnProperty(inp.value) && (!amtInput.value || parseFloat(amtInput.value) === 0) && CATALOG[inp.value] > 0) {
        amtInput.value = CATALOG[inp.value].toFixed(2);
    }
    opTotal();
}
function opTotal() {
    let t = 0;
    document.querySelectorAll('#itemRows > div').forEach(function (row) {
        const q = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
        const a = parseFloat(row.querySelector('[name="item_amount[]"]').value) || 0;
        t += q * a;
    });
    document.getElementById('grandTotal').textContent = '₱' + t.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function opSubmit() {
    let t = 0;
    document.querySelectorAll('#itemRows > div').forEach(function (row) {
        const q = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
        const a = parseFloat(row.querySelector('[name="item_amount[]"]').value) || 0;
        t += q * a;
    });
    if (t <= 0) { alert('Add at least one item with an amount.'); return false; }
    return true;
}
opAddRow(); // start with one row
</script>
</body>
</html>
