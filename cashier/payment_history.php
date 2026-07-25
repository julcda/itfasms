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
$sy                    = soa_active_school_year($connection);
$syId                  = (int) $sy['id'];
$syLabel               = $sy['label'];
$schemaReady           = soa_schema_ready($connection);
$activeSchoolYearLabel = $syLabel;

// ── POST: void a payment ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('payment_history.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $return = 'payment_history.php' . (($_POST['q'] ?? '') !== '' ? '?q=' . urlencode((string) $_POST['q']) : '');

    try {
        if ($action === 'void') {
            $paymentId = to_int($_POST['payment_id'] ?? 0);
            $reason    = trim((string) ($_POST['reason'] ?? '')) ?: 'Voided from Payment History';
            // Cashier voids directly: file the reversal request and approve it in one step.
            $rid = soa_request_reversal($connection, $paymentId, 'Void', $reason, $user);
            $res = soa_approve_reversal($connection, $rid, $user);
            flash_set('success', 'Payment #' . $res['payment_id'] . ' voided (₱' . number_format($res['amount'], 2)
                . '). The student balance was restored.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to($return);
}

// ── Filters + list ────────────────────────────────────────────────────────────
$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$rows   = [];
$totPosted = 0.0; $cntPosted = 0; $cntVoided = 0;

if ($schemaReady) {
    $where  = ['sa.school_year_id = ?'];
    $types  = 'i';
    $params = [$syId];

    if ($q !== '') {
        $where[] = "(rm.or_number LIKE ? OR COALESCE(p.lrn,osp.lrn) LIKE ?
                     OR COALESCE(CONCAT(p.surname,' ',p.firstname),CONCAT(osp.surname,' ',osp.firstname)) LIKE ?)";
        $like = '%' . $q . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }
    if (in_array($status, ['Posted', 'Voided', 'Refunded'], true)) {
        $where[] = 'pt.status = ?';
        $types  .= 's';
        $params[] = $status;
    }

    $sql =
        "SELECT pt.id AS payment_id, pt.amount, pt.method, pt.reference_no, pt.status, pt.paid_at, pt.received_by,
                rm.or_number, rm.reprint_count,
                COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname)) AS full_name,
                COALESCE(p.lrn, osp.lrn) AS lrn,
                en.Department,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name
         FROM payment_transaction pt
         LEFT JOIN receipt_master rm      ON rm.payment_id = pt.id
         LEFT JOIN student_assessment sa  ON sa.id = pt.assessment_id
         LEFT JOIN enrollment en          ON en.id = sa.enrollment_id
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE " . implode(' AND ', $where) . "
         ORDER BY pt.id DESC
         LIMIT 300";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);

    // Summary for the active SY (independent of filters).
    $sumStmt = $connection->prepare(
        "SELECT
            IFNULL(SUM(CASE WHEN pt.status='Posted' THEN pt.amount END),0) AS posted_total,
            COUNT(CASE WHEN pt.status='Posted' THEN 1 END) AS posted_cnt,
            COUNT(CASE WHEN pt.status IN ('Voided','Refunded') THEN 1 END) AS voided_cnt
         FROM payment_transaction pt
         JOIN student_assessment sa ON sa.id = pt.assessment_id
         WHERE sa.school_year_id = ?"
    );
    $sumStmt->bind_param('i', $syId);
    $sumStmt->execute();
    if ($s = stmt_fetch_assoc($sumStmt)) {
        $totPosted = (float) $s['posted_total'];
        $cntPosted = (int) $s['posted_cnt'];
        $cntVoided = (int) $s['voided_cnt'];
    }
}

$flash = flash_get();
$csrf  = csrf_token();

function _pay_badge(string $status): string
{
    $map = [
        'Posted'   => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'Voided'   => 'bg-slate-200 text-slate-500 border-slate-300',
        'Refunded' => 'bg-amber-100 text-amber-800 border-amber-300',
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
    <title>Payment History | ITFA Cashier</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier · Collections</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Payment History</h1>
            <p class="text-slate-500 mt-2">Payments collected through the cashier. Reprint the Official Receipt or void a payment (restores the student's balance).</p>
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

        <!-- Summary -->
        <section class="grid sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Posted collections · S.Y.</p>
                <p class="text-2xl font-extrabold mt-1 text-emerald-600">₱<?= number_format($totPosted, 2) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Posted payments</p>
                <p class="text-2xl font-extrabold mt-1"><?= number_format($cntPosted) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5">
                <p class="text-xs uppercase text-slate-400 font-semibold">Voided / Refunded</p>
                <p class="text-2xl font-extrabold mt-1 text-slate-500"><?= number_format($cntVoided) ?></p>
            </div>
        </section>

        <!-- Search / filter -->
        <form method="GET" action="payment_history.php" class="mb-5 flex flex-wrap gap-3 items-end">
            <div class="relative flex-1 min-w-[260px]">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-600">🔍</span>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by OR number, student name, or LRN…"
                       class="w-full rounded-xl border border-slate-300 pl-11 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400">
            </div>
            <select name="status" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <option value="">All statuses</option>
                <?php foreach (['Posted','Voided','Refunded'] as $st): ?>
                <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
            <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-5 py-2.5">Search</button>
            <?php if ($q !== '' || $status !== ''): ?><a href="payment_history.php" class="text-sm text-slate-500 px-2 py-2.5">Clear</a><?php endif; ?>
        </form>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
            <?php if (!$rows): ?>
            <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-10 text-center">No payments found.</div>
            <?php else: ?>
            <p class="text-xs text-slate-400 mb-3">Showing <?= count($rows) ?> most recent payment(s)<?= count($rows) === 300 ? ' (capped at 300 — refine your search)' : '' ?>.</p>
            <div class="overflow-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                            <th class="py-2 pr-3">OR No.</th>
                            <th class="py-2 pr-3">Date</th>
                            <th class="py-2 pr-3">Student</th>
                            <th class="py-2 pr-3">Grade / Section</th>
                            <th class="py-2 pr-3">Method</th>
                            <th class="py-2 pr-3 text-right">Amount</th>
                            <th class="py-2 pr-3">Status</th>
                            <th class="py-2 pr-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): $voided = $r['status'] !== 'Posted'; ?>
                        <tr class="border-b border-slate-100 <?= $voided ? 'opacity-60' : '' ?>">
                            <td class="py-2.5 pr-3 font-mono font-semibold text-slate-700">
                                <?= h((string) ($r['or_number'] ?: '—')) ?>
                                <?php if ((int) $r['reprint_count'] > 0): ?><span class="text-[10px] text-amber-600">×<?= (int) $r['reprint_count'] ?></span><?php endif; ?>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-500 whitespace-nowrap"><?= h(date('M j, Y g:ia', strtotime((string) $r['paid_at']))) ?></td>
                            <td class="py-2.5 pr-3">
                                <span class="font-semibold"><?= h((string) ($r['full_name'] ?: '—')) ?></span>
                                <span class="block text-[11px] text-slate-400 font-mono"><?= h((string) ($r['lrn'] ?: '')) ?></span>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-600 text-xs"><?= h(trim((string) $r['grade_name'])) ?><?= $r['section_name'] ? ' · ' . h((string) $r['section_name']) : '' ?></td>
                            <td class="py-2.5 pr-3"><?= h((string) $r['method']) ?><?= $r['reference_no'] ? '<span class="block text-[10px] text-slate-400">' . h((string) $r['reference_no']) . '</span>' : '' ?></td>
                            <td class="py-2.5 pr-3 text-right font-bold">₱<?= number_format((float) $r['amount'], 2) ?></td>
                            <td class="py-2.5 pr-3"><?= _pay_badge((string) $r['status']) ?></td>
                            <td class="py-2.5 pr-3 text-right whitespace-nowrap">
                                <a href="<?= h(app_url('cashier/or_print.php?reprint=' . (int) $r['payment_id'])) ?>" target="_blank"
                                   class="inline-block rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5">Reprint OR</a>
                                <?php if (!$voided): ?>
                                <form method="POST" action="payment_history.php" class="inline"
                                      onsubmit="this.querySelector('[name=reason]').value = prompt('Reason for voiding this payment:') || ''; if(!this.querySelector('[name=reason]').value) return false; return confirm('Void this payment? The student balance will be restored.');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="void">
                                    <input type="hidden" name="payment_id" value="<?= (int) $r['payment_id'] ?>">
                                    <input type="hidden" name="reason" value="">
                                    <input type="hidden" name="q" value="<?= h($q) ?>">
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
</body>
</html>
