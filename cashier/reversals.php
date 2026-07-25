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

$cashierName            = (string) ($user['full_name'] ?? 'Cashier');
$isApprover             = is_super_admin($user);
$sy                     = soa_active_school_year($connection);
$syLabel                = $sy['label'];
$schemaReady            = soa_schema_ready($connection);
$activeSchoolYearLabel  = $syLabel; // sidebar expects this

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('reversals.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'request') {
            $paymentId = to_int($_POST['payment_id'] ?? 0);
            $type      = (string) ($_POST['type'] ?? 'Void');
            $reason    = (string) ($_POST['reason'] ?? '');
            $id = soa_request_reversal($connection, $paymentId, $type, $reason, $user);
            flash_set('success', strtoupper($type) . ' request #' . $id . ' filed and is awaiting Super Admin approval.');
            redirect_to('reversals.php');
        }

        if ($action === 'approve') {
            if (!$isApprover) {
                throw new RuntimeException('Only a Super Admin can approve a reversal.');
            }
            $reversalId = to_int($_POST['reversal_id'] ?? 0);
            $res = soa_approve_reversal($connection, $reversalId, $user);
            flash_set('success', $res['type'] . ' approved — payment #' . $res['payment_id']
                . ' reversed (₱' . number_format($res['amount'], 2) . '). New balance ₱' . number_format($res['new_balance'], 2) . '.');
            redirect_to('reversals.php');
        }

        if ($action === 'reject') {
            if (!$isApprover) {
                throw new RuntimeException('Only a Super Admin can reject a reversal.');
            }
            $reversalId = to_int($_POST['reversal_id'] ?? 0);
            $note       = (string) ($_POST['note'] ?? '');
            soa_reject_reversal($connection, $reversalId, $user, $note);
            flash_set('success', 'Request #' . $reversalId . ' rejected.');
            redirect_to('reversals.php');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('reversals.php' . ($action === 'request' ? '?ref=' . urlencode((string) ($_POST['ref_echo'] ?? '')) : ''));
    }
}

// ── Lookup a payment by OR number (for the request form) ─────────────────────
$ref     = trim((string) ($_GET['ref'] ?? ''));
$payment = null;
$lookupMsg = '';

if ($schemaReady && $ref !== '') {
    $stmt = $connection->prepare(
        "SELECT pt.id AS payment_id, pt.amount, pt.method, pt.status, pt.paid_at, pt.received_by,
                rm.or_number, sa.id AS assessment_id, sa.balance, sa.net_assessed,
                en.Department, en.school_year,
                COALESCE(
                    CONCAT(p.surname, ', ', p.firstname, ' ', IFNULL(p.middlename, '')),
                    CONCAT(osp.surname, ', ', osp.firstname, ' ', IFNULL(osp.middlename, ''))
                ) AS full_name,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name
         FROM receipt_master rm
         JOIN payment_transaction pt ON pt.id = rm.payment_id
         JOIN student_assessment sa  ON sa.id = pt.assessment_id
         JOIN enrollment en          ON en.id = sa.enrollment_id
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         WHERE rm.or_number = ? LIMIT 1"
    );
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $payment = stmt_fetch_assoc($stmt);
    if (!$payment) {
        $lookupMsg = 'No receipt matched OR “' . $ref . '”.';
    }
}

// Any open request already on this payment?
$existingReq = null;
if ($payment) {
    $eStmt = $connection->prepare("SELECT id, type, status FROM payment_reversals WHERE payment_id = ? AND status IN ('Pending','Approved') ORDER BY id DESC LIMIT 1");
    $eStmt->bind_param('i', $payment['payment_id']);
    $eStmt->execute();
    $existingReq = stmt_fetch_assoc($eStmt);
}

// ── Request lists ────────────────────────────────────────────────────────────
$pending = [];
$history = [];
if ($schemaReady) {
    $q = $connection->query(
        "SELECT pr.id, pr.payment_id, pr.type, pr.amount, pr.reason, pr.requested_by, pr.approved_by,
                pr.status, pr.created_at, rm.or_number, pt.method, pt.status AS pay_status,
                COALESCE(
                    CONCAT(p.surname, ', ', p.firstname),
                    CONCAT(osp.surname, ', ', osp.firstname)
                ) AS full_name
         FROM payment_reversals pr
         LEFT JOIN payment_transaction pt ON pt.id = pr.payment_id
         LEFT JOIN receipt_master rm      ON rm.payment_id = pr.payment_id
         LEFT JOIN student_assessment sa  ON sa.id = pt.assessment_id
         LEFT JOIN enrollment en          ON en.id = sa.enrollment_id
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         ORDER BY (pr.status = 'Pending') DESC, pr.id DESC
         LIMIT 200"
    );
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            if ($r['status'] === 'Pending') { $pending[] = $r; }
            else { $history[] = $r; }
        }
    }
}

$flash = flash_get();
$csrf  = csrf_token();

function _rev_badge(string $status): string
{
    $map = [
        'Pending'  => 'bg-amber-100 text-amber-800 border-amber-300',
        'Approved' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Rejected' => 'bg-slate-200 text-slate-600 border-slate-300',
    ];
    $cls = $map[$status] ?? 'bg-slate-100 text-slate-600 border-slate-300';
    return '<span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold border ' . $cls . '">' . h($status) . '</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Void / Refund | ITFA Cashier</title>
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
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Void / Refund</h1>
            <p class="text-slate-500 mt-2">A cashier files a request; a Super Admin approves it. Approval reverses the payment, restores the student's balance, and backs out the day's collection.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?> &nbsp;·&nbsp; <?= $isApprover ? 'Role: Super Admin (approver)' : 'Role: Cashier (requester)' ?></p>
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

        <div class="grid lg:grid-cols-2 gap-6">

            <!-- ── File a request ─────────────────────────────────────────── -->
            <section class="rounded-3xl bg-white border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-1">File a request</h2>
                <p class="text-sm text-slate-500 mb-4">Look up the receipt (OR number) you need to void or refund.</p>

                <form method="GET" action="reversals.php" class="mb-4">
                    <div class="flex gap-2">
                        <input type="text" name="ref" value="<?= h($ref) ?>" autofocus
                               placeholder="Scan or type OR number…"
                               class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400">
                        <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-5">Find</button>
                    </div>
                </form>

                <?php if ($lookupMsg): ?>
                <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3"><?= h($lookupMsg) ?></div>
                <?php endif; ?>

                <?php if ($payment): ?>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 mb-4 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">OR Number</span><span class="font-semibold"><?= h((string) $payment['or_number']) ?></span></div>
                    <div class="flex justify-between mt-1"><span class="text-slate-500">Student</span><span class="font-semibold"><?= h((string) $payment['full_name']) ?></span></div>
                    <div class="flex justify-between mt-1"><span class="text-slate-500">Grade / Dept</span><span><?= h((string) $payment['grade_name']) ?> · <?= h((string) $payment['Department']) ?></span></div>
                    <div class="flex justify-between mt-1"><span class="text-slate-500">Paid</span><span class="font-semibold">₱<?= number_format((float) $payment['amount'], 2) ?> <span class="text-slate-400 font-normal">(<?= h((string) $payment['method']) ?>)</span></span></div>
                    <div class="flex justify-between mt-1"><span class="text-slate-500">Posted</span><span><?= h((string) $payment['paid_at']) ?> · <?= h((string) $payment['received_by']) ?></span></div>
                    <div class="flex justify-between mt-1"><span class="text-slate-500">Payment status</span><span class="font-semibold"><?= h((string) $payment['status']) ?></span></div>
                </div>

                    <?php if ($payment['status'] !== 'Posted'): ?>
                    <div class="rounded-xl bg-slate-100 border border-slate-300 text-slate-600 text-sm px-4 py-3">
                        This payment is already <strong><?= h(strtolower((string) $payment['status'])) ?></strong> and cannot be reversed.
                    </div>
                    <?php elseif ($existingReq): ?>
                    <div class="rounded-xl bg-amber-50 border border-amber-300 text-amber-800 text-sm px-4 py-3">
                        A <strong><?= h(strtolower((string) $existingReq['status'])) ?></strong> <?= h((string) $existingReq['type']) ?> request (#<?= (int) $existingReq['id'] ?>) already exists for this payment.
                    </div>
                    <?php else: ?>
                    <form method="POST" action="reversals.php" onsubmit="return confirm('File this request for Super Admin approval?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="request">
                        <input type="hidden" name="payment_id" value="<?= (int) $payment['payment_id'] ?>">
                        <input type="hidden" name="ref_echo" value="<?= h($ref) ?>">

                        <label class="block text-sm font-semibold mb-1">Type</label>
                        <div class="flex gap-3 mb-3">
                            <label class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm cursor-pointer has-[:checked]:border-rose-500 has-[:checked]:bg-rose-50">
                                <input type="radio" name="type" value="Void" checked class="mr-2">Void <span class="text-slate-400">(entry error — no cash returned)</span>
                            </label>
                            <label class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm cursor-pointer has-[:checked]:border-rose-500 has-[:checked]:bg-rose-50">
                                <input type="radio" name="type" value="Refund" class="mr-2">Refund <span class="text-slate-400">(cash returned)</span>
                            </label>
                        </div>

                        <label class="block text-sm font-semibold mb-1">Reason <span class="text-rose-500">*</span></label>
                        <textarea name="reason" required rows="3" placeholder="Why is this being voided / refunded?"
                                  class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400 mb-4"></textarea>

                        <button class="w-full rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold py-3">
                            File request for approval
                        </button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <!-- ── Pending approvals ──────────────────────────────────────── -->
            <section class="rounded-3xl bg-white border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-1">Pending requests <span class="text-amber-600">(<?= count($pending) ?>)</span></h2>
                <p class="text-sm text-slate-500 mb-4"><?= $isApprover ? 'Approve to reverse the payment, or reject.' : 'Awaiting Super Admin approval.' ?></p>

                <?php if (!$pending): ?>
                <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-6 text-center">No pending requests.</div>
                <?php else: ?>
                <div class="space-y-3 max-h-[560px] overflow-auto pr-1">
                    <?php foreach ($pending as $r): ?>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-bold text-sm"><?= h((string) ($r['type'])) ?> · ₱<?= number_format((float) $r['amount'], 2) ?></p>
                                <p class="text-xs text-slate-600 mt-0.5"><?= h((string) ($r['full_name'] ?? '—')) ?> · OR <?= h((string) ($r['or_number'] ?? '—')) ?></p>
                                <p class="text-xs text-slate-500 mt-1">Requested by <?= h((string) $r['requested_by']) ?> · <?= h((string) $r['created_at']) ?></p>
                                <p class="text-sm text-slate-700 mt-2 italic">“<?= h((string) $r['reason']) ?>”</p>
                            </div>
                            <?= _rev_badge((string) $r['status']) ?>
                        </div>
                        <?php if ($isApprover): ?>
                        <div class="flex gap-2 mt-3">
                            <form method="POST" action="reversals.php" class="flex-1"
                                  onsubmit="return confirm('Approve this <?= h((string) $r['type']) ?>? This reverses the payment and restores the student balance.');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="reversal_id" value="<?= (int) $r['id'] ?>">
                                <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2">Approve</button>
                            </form>
                            <form method="POST" action="reversals.php" class="flex-1"
                                  onsubmit="this.querySelector('[name=note]').value = prompt('Reason for rejection (optional):') || ''; return confirm('Reject this request?');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="reversal_id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="note" value="">
                                <button class="w-full rounded-lg bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-bold py-2">Reject</button>
                            </form>
                        </div>
                        <?php else: ?>
                        <p class="text-xs text-amber-700 mt-3 font-semibold">⏳ Waiting for Super Admin</p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- ── History ─────────────────────────────────────────────────────── -->
        <section class="rounded-3xl bg-white border border-green-100 shadow-panel p-6 mt-6">
            <h2 class="font-extrabold text-lg mb-4">Reversal history</h2>
            <?php if (!$history): ?>
            <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-6 text-center">No approved or rejected reversals yet.</div>
            <?php else: ?>
            <div class="overflow-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                            <th class="py-2 pr-3">#</th>
                            <th class="py-2 pr-3">Type</th>
                            <th class="py-2 pr-3">Student</th>
                            <th class="py-2 pr-3">OR</th>
                            <th class="py-2 pr-3 text-right">Amount</th>
                            <th class="py-2 pr-3">Reason</th>
                            <th class="py-2 pr-3">Requested by</th>
                            <th class="py-2 pr-3">Approved by</th>
                            <th class="py-2 pr-3">Status</th>
                            <th class="py-2 pr-3">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $r): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pr-3 text-slate-400"><?= (int) $r['id'] ?></td>
                            <td class="py-2 pr-3 font-semibold"><?= h((string) $r['type']) ?></td>
                            <td class="py-2 pr-3"><?= h((string) ($r['full_name'] ?? '—')) ?></td>
                            <td class="py-2 pr-3 text-slate-500"><?= h((string) ($r['or_number'] ?? '—')) ?></td>
                            <td class="py-2 pr-3 text-right">₱<?= number_format((float) $r['amount'], 2) ?></td>
                            <td class="py-2 pr-3 text-slate-600 max-w-[220px] truncate" title="<?= h((string) $r['reason']) ?>"><?= h((string) $r['reason']) ?></td>
                            <td class="py-2 pr-3 text-slate-500"><?= h((string) $r['requested_by']) ?></td>
                            <td class="py-2 pr-3 text-slate-500"><?= h((string) ($r['approved_by'] ?? '—')) ?></td>
                            <td class="py-2 pr-3"><?= _rev_badge((string) $r['status']) ?></td>
                            <td class="py-2 pr-3 text-slate-400"><?= h((string) $r['created_at']) ?></td>
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
