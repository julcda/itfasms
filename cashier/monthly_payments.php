<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php-error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user)) {
    flash_set('error', 'Only Cashier users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

// ── Active school year ────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
try {
    $syStmt = $connection->prepare('SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1');
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow) $activeSchoolYearLabel = (string) ($syRow['School_year'] ?? '');
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── Mark overdue automatically ────────────────────────────────────────────────
$connection->query(
    "UPDATE monthly_payment SET status='Overdue'
     WHERE status='Unpaid' AND due_date < CURDATE()"
);

// ─────────────────────────────────────────────────────────────────────────────
// POST: record a monthly payment
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to('monthly_payments.php');
    }
    try {
        $mpId      = to_int($_POST['monthly_payment_id'] ?? 0);
        $accountId = to_int($_POST['account_id']        ?? 0);
        $amountPaid = (float) ($_POST['amount_paid'] ?? 0);
        $notes     = trim((string) ($_POST['notes'] ?? ''));

        if ($mpId <= 0 || $accountId <= 0 || $amountPaid <= 0) {
            throw new RuntimeException('Invalid payment details.');
        }

        // Fetch monthly_payment row
        $mpStmt = $connection->prepare(
            'SELECT mp.*, sa.student_id, sa.school_year AS sy
             FROM monthly_payment mp
             JOIN student_account sa ON sa.id = mp.student_account_id
             WHERE mp.id = ? AND mp.student_account_id = ? LIMIT 1'
        );
        $mpStmt->bind_param('ii', $mpId, $accountId);
        $mpStmt->execute();
        $mp = stmt_fetch_assoc($mpStmt);
        if (!$mp) throw new RuntimeException('Payment record not found.');
        if ($mp['status'] === 'Paid') throw new RuntimeException('This month is already fully paid.');

        $amountDue  = (float) $mp['amount_due'];
        $prevPaid   = (float) $mp['amount_paid'];
        $newPaid    = $prevPaid + $amountPaid;
        $newStatus  = $newPaid >= $amountDue ? 'Paid' : 'Partial';
        $cashierName = (string) ($user['full_name'] ?? 'Cashier');

        // Generate OR number (will back-fill after insert if new, or reuse)
        $orNumber = (string) ($mp['or_number'] ?? '');

        // UPDATE monthly_payment
        $updMp = $connection->prepare(
            'UPDATE monthly_payment
             SET amount_paid = ?, or_number = \'TEMP\', payment_date = NOW(),
                 cashier_name = ?, notes = ?, status = ?
             WHERE id = ?'
        );
        $updMp->bind_param('dsssi', $newPaid, $cashierName, $notes, $newStatus, $mpId);
        $updMp->execute();

        // Generate OR number using mp id
        $orNumber = 'ITFA-MP-' . date('Y') . '-' . str_pad((string) $mpId, 6, '0', STR_PAD_LEFT);
        $fixOr = $connection->prepare('UPDATE monthly_payment SET or_number = ? WHERE id = ?');
        $fixOr->bind_param('si', $orNumber, $mpId);
        $fixOr->execute();

        // Recalculate student_account totals
        $sumStmt = $connection->prepare('SELECT COALESCE(SUM(amount_paid),0) AS total_paid FROM monthly_payment WHERE student_account_id = ?');
        $sumStmt->bind_param('i', $accountId);
        $sumStmt->execute();
        $totalPaid = (float) (stmt_fetch_assoc($sumStmt)['total_paid'] ?? 0);

        $acctStmt = $connection->prepare('SELECT total_fee FROM student_account WHERE id = ? LIMIT 1');
        $acctStmt->bind_param('i', $accountId);
        $acctStmt->execute();
        $acct = stmt_fetch_assoc($acctStmt);
        $totalFee = (float) ($acct['total_fee'] ?? 0);
        $balance  = max(0.0, $totalFee - $totalPaid);
        $acctStatus = $balance <= 0 ? 'Fully Paid' : 'Active';

        $updAcct = $connection->prepare('UPDATE student_account SET total_paid=?, balance=?, status=?, updated_at=NOW() WHERE id=?');
        $updAcct->bind_param('ddsi', $totalPaid, $balance, $acctStatus, $accountId);
        $updAcct->execute();

        // Fetch student name for receipt
        $nameStmt = $connection->prepare(
            'SELECT COALESCE(CONCAT(p.surname,\', \',p.firstname), CONCAT(osp.surname,\', \',osp.firstname)) AS full_name,
                    gl.Gradelevel AS gradelevel_name,
                    IFNULL(sc.Section_name, sa.school_year) AS section_name,
                    sa.school_year
             FROM student_account sa
             LEFT JOIN preregistration p      ON sa.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp  ON (p.id IS NULL AND osp.student_id = sa.student_id)
             LEFT JOIN enrollment en ON en.id = sa.enrollment_id
             LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
             LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
             WHERE sa.id = ? LIMIT 1'
        );
        $nameStmt->bind_param('i', $accountId);
        $nameStmt->execute();
        $nameRow = stmt_fetch_assoc($nameStmt);

        // Session data for receipt
        $_SESSION['monthly_receipt_data'] = [
            'mp_id'        => $mpId,
            'account_id'   => $accountId,
            'or_number'    => $orNumber,
            'full_name'    => strtoupper(trim((string) ($nameRow['full_name'] ?? 'Student'))),
            'student_id'   => (string) $mp['student_id'],
            'grade'        => (string) ($nameRow['gradelevel_name'] ?? ''),
            'section'      => (string) ($nameRow['section_name'] ?? ''),
            'school_year'  => (string) ($mp['sy'] ?? $activeSchoolYearLabel),
            'month_label'  => (string) $mp['month_label'],
            'amount_due'   => $amountDue,
            'amount_paid'  => $amountPaid,
            'new_total_paid' => $newPaid,
            'payment_status' => $newStatus,
            'cashier_name' => $cashierName,
            'payment_date' => date('Y-m-d H:i:s'),
            'balance'      => $balance,
        ];
        redirect_to('monthly_receipt.php');

    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        $redirectId = to_int($_POST['account_id'] ?? 0);
        redirect_to('monthly_payments.php' . ($redirectId > 0 ? '?account_id=' . $redirectId : ''));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Detail mode: ?account_id=X
// ─────────────────────────────────────────────────────────────────────────────
$accountId  = to_int($_GET['account_id'] ?? 0);
$detailAcct = null;
$schedule   = [];

if ($accountId > 0) {
    $acStmt = $connection->prepare(
        'SELECT sa.*,
                COALESCE(CONCAT(p.surname,\', \',p.firstname), CONCAT(osp.surname,\', \',osp.firstname)) AS full_name,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name,
                en.Department
         FROM student_account sa
         LEFT JOIN enrollment en     ON en.id = sa.enrollment_id
         LEFT JOIN preregistration p  ON sa.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = sa.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE sa.id = ? LIMIT 1'
    );
    $acStmt->bind_param('i', $accountId);
    $acStmt->execute();
    $detailAcct = stmt_fetch_assoc($acStmt);

    if ($detailAcct) {
        $schStmt = $connection->prepare('SELECT * FROM monthly_payment WHERE student_account_id = ? ORDER BY payment_year, payment_month');
        $schStmt->bind_param('i', $accountId);
        $schStmt->execute();
        $schedule = stmt_fetch_all_assoc($schStmt);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// List mode: students with payment accounts
// ─────────────────────────────────────────────────────────────────────────────
$search   = trim((string) ($_GET['q']      ?? ''));
$syFilter = trim((string) ($_GET['sy']     ?? $activeSchoolYearLabel));
$statusFi = trim((string) ($_GET['status'] ?? ''));

$where  = ['sa.school_year = ?'];
$params = [$syFilter];
$types  = 's';

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where[] = '(p.surname LIKE ? OR p.firstname LIKE ? OR osp.surname LIKE ? OR osp.firstname LIKE ? OR sa.student_id LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
    $types  .= 'sssss';
}
if (in_array($statusFi, ['Active','Fully Paid','Overdue'], true)) {
    $where[] = 'sa.status = ?';
    $params[] = $statusFi;
    $types   .= 's';
}

$listStmt = $connection->prepare(
    'SELECT sa.id, sa.student_id, sa.school_year, sa.payment_method,
            sa.total_fee, sa.total_paid, sa.balance, sa.status AS account_status,
            sa.monthly_amount, sa.installment_months,
            COALESCE(CONCAT(p.surname,\', \',p.firstname), CONCAT(osp.surname,\', \',osp.firstname)) AS full_name,
            IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
            IFNULL(sc.Section_name, en.Department_section) AS section_name,
            (SELECT COUNT(*) FROM monthly_payment mp WHERE mp.student_account_id=sa.id AND mp.status IN (\'Unpaid\',\'Overdue\')) AS unpaid_months,
            (SELECT COUNT(*) FROM monthly_payment mp WHERE mp.student_account_id=sa.id AND mp.status=\'Overdue\')               AS overdue_months
     FROM student_account sa
     LEFT JOIN enrollment en     ON en.id = sa.enrollment_id
     LEFT JOIN preregistration p  ON sa.student_id = CAST(p.id AS CHAR)
     LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = sa.student_id)
     LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
     LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY overdue_months DESC, unpaid_months DESC, sa.id DESC'
);
bind_dynamic_params($listStmt, $types, $params);
$listStmt->execute();
$accountsList = stmt_fetch_all_assoc($listStmt);

// Summary stats
$statsStmt = $connection->prepare(
    'SELECT COUNT(*) AS total_accounts,
            SUM(IF(sa.status=\'Overdue\',1,0))    AS overdue_accounts,
            SUM(IF(sa.status=\'Fully Paid\',1,0)) AS paid_accounts,
            COALESCE(SUM(
              (SELECT COUNT(*) FROM monthly_payment mp WHERE mp.student_account_id=sa.id AND mp.status IN (\'Unpaid\',\'Overdue\'))
            ),0) AS total_unpaid_months
     FROM student_account sa WHERE sa.school_year = ?'
);
$statsStmt->bind_param('s', $activeSchoolYearLabel);
$statsStmt->execute();
$stats = stmt_fetch_assoc($statsStmt) ?? [];

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monthly Payments | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: { 50:'#f0f7f2', 600:'#166534', 700:'#0f4d28' }
                    },
                    boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium flex items-center gap-3
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if ($accountId > 0 && $detailAcct): ?>
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- DETAIL VIEW                                                        -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <?php
            $dName   = strtoupper(trim((string)($detailAcct['full_name']??'Student')));
            $dGrade  = (string)($detailAcct['gradelevel_name']??'');
            $dSec    = (string)($detailAcct['section_name']??'');
            $dDept   = (string)($detailAcct['Department']??'');
            $dSid    = (string)($detailAcct['student_id']??'');
            $dSy     = (string)($detailAcct['school_year']??'');
            $dMethod = (string)($detailAcct['payment_method']??'');
            $dTotal  = (float)($detailAcct['total_fee']??0);
            $dPaid   = (float)($detailAcct['total_paid']??0);
            $dBal    = (float)($detailAcct['balance']??0);
            $dStatus = (string)($detailAcct['status']??'Active');
            $statusColors = ['Active'=>'bg-green-100 text-green-700','Fully Paid'=>'bg-emerald-100 text-emerald-700','Overdue'=>'bg-rose-100 text-rose-700'];
        ?>
        <div class="mb-6 flex items-center gap-4">
            <a href="monthly_payments.php" class="text-slate-400 hover:text-slate-700 text-sm font-semibold flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Back to Accounts
            </a>
        </div>

        <!-- Student card -->
        <div class="rounded-2xl bg-green-700 text-white p-6 mb-6 shadow-lg">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs text-green-300 font-semibold uppercase tracking-widest">Student Account</p>
                    <p class="text-2xl font-extrabold mt-1"><?= h($dName) ?></p>
                    <p class="text-green-200 text-sm mt-0.5"><?= h($dDept) ?> · <?= h($dGrade) ?><?= $dSec ? ' · '.h($dSec) : '' ?></p>
                    <p class="text-green-300 font-mono text-xs mt-1"><?= h($dSid) ?> · S.Y. <?= h($dSy) ?></p>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <span class="rounded-full px-3 py-1 text-xs font-bold <?= $statusColors[$dStatus]??'bg-slate-200 text-slate-700' ?>">
                        <?= h($dStatus) ?>
                    </span>
                    <span class="text-xs text-green-300"><?= h($dMethod) ?> Plan</span>
                    <a href="soa_account.php?account_id=<?= $accountId ?>" target="_blank"
                       class="mt-1 flex items-center gap-1.5 bg-white/15 border border-white/20 hover:bg-white/25 text-white text-xs font-semibold rounded-lg px-3 py-1.5 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        View SOA
                    </a>
                </div>
            </div>
            <!-- Financial bar -->
            <div class="mt-5 grid grid-cols-3 gap-4 border-t border-white/15 pt-4">
                <div>
                    <p class="text-xs text-green-300">Total Due</p>
                    <p class="font-extrabold text-lg">₱<?= number_format($dTotal, 2) ?></p>
                </div>
                <div>
                    <p class="text-xs text-green-300">Total Paid</p>
                    <p class="font-extrabold text-lg text-emerald-300">₱<?= number_format($dPaid, 2) ?></p>
                </div>
                <div>
                    <p class="text-xs text-green-300">Balance</p>
                    <p class="font-extrabold text-lg <?= $dBal > 0 ? 'text-amber-300' : 'text-emerald-300' ?>">₱<?= number_format($dBal, 2) ?></p>
                </div>
            </div>
        </div>

        <!-- Payment schedule table -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <h2 class="font-bold text-lg">Monthly Payment Schedule</h2>
                <span class="text-xs text-slate-400"><?= count($schedule) ?> months</span>
            </div>
            <?php if (empty($schedule)): ?>
            <div class="py-12 text-center text-slate-400">
                <p class="font-semibold">Cash payment — no monthly schedule.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                            <th class="px-4 py-3 text-left w-8">#</th>
                            <th class="px-4 py-3 text-left">Month</th>
                            <th class="px-4 py-3 text-left">Due Date</th>
                            <th class="px-4 py-3 text-right">Amount Due</th>
                            <th class="px-4 py-3 text-right">Amount Paid</th>
                            <th class="px-4 py-3 text-left">OR Number</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($schedule as $i => $slot): ?>
                        <?php
                            $slStatus  = (string)($slot['status']??'Unpaid');
                            $slDue     = (float)($slot['amount_due']??0);
                            $slPaid    = (float)($slot['amount_paid']??0);
                            $slOrNum   = (string)($slot['or_number']??'');
                            $slDueDate = $slot['due_date'] ? date('M d, Y', strtotime($slot['due_date'])) : '—';
                            $slColors  = ['Unpaid'=>'bg-slate-100 text-slate-500','Partial'=>'bg-amber-100 text-amber-700','Paid'=>'bg-emerald-100 text-emerald-700','Overdue'=>'bg-rose-100 text-rose-700'];
                            $canPay    = in_array($slStatus, ['Unpaid','Partial','Overdue'], true);
                        ?>
                        <tr class="hover:bg-slate-50 <?= $slStatus==='Overdue' ? 'bg-rose-50/30' : '' ?>">
                            <td class="px-4 py-3 text-slate-400 text-xs"><?= $i+1 ?></td>
                            <td class="px-4 py-3 font-medium"><?= h((string)$slot['month_label']) ?></td>
                            <td class="px-4 py-3 text-sm text-slate-500"><?= $slDueDate ?></td>
                            <td class="px-4 py-3 text-right font-semibold text-green-700">₱<?= number_format($slDue, 2) ?></td>
                            <td class="px-4 py-3 text-right font-semibold <?= $slPaid>0?'text-emerald-600':'text-slate-300' ?>">
                                <?= $slPaid > 0 ? '₱'.number_format($slPaid,2) : '—' ?>
                            </td>
                            <td class="px-4 py-3 text-xs font-mono text-slate-500"><?= $slOrNum ? h($slOrNum) : '—' ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $slColors[$slStatus]??'bg-slate-100 text-slate-500' ?>">
                                    <?= h($slStatus) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($canPay): ?>
                                <button onclick="openPayModal(<?= (int)$slot['id'] ?>, <?= json_encode($slot['month_label']) ?>, <?= $slDue ?>, <?= $slPaid ?>)"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-green-700 text-white text-xs font-semibold px-3 py-1.5 hover:bg-green-800 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    Collect
                                </button>
                                <?php elseif ($slOrNum): ?>
                                <a href="monthly_receipt.php?mp_id=<?= (int)$slot['id'] ?>" target="_blank"
                                   class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 text-slate-600 text-xs font-semibold px-3 py-1.5 hover:bg-slate-200 transition-colors">
                                    Reprint
                                </a>
                                <?php else: ?>
                                <span class="text-xs text-slate-300">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment modal -->
        <div id="payModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
                <h3 class="font-extrabold text-lg mb-1">Collect Monthly Payment</h3>
                <p id="modalMonthLabel" class="text-slate-500 text-sm mb-5"></p>
                <form method="POST" action="monthly_payments.php">
                    <input type="hidden" name="csrf_token"        value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action"            value="record_payment">
                    <input type="hidden" name="account_id"        value="<?= $accountId ?>">
                    <input type="hidden" name="monthly_payment_id" id="modalMpId" value="">
                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Amount Due</label>
                        <p id="modalAmountDue" class="text-2xl font-extrabold text-green-700">₱0.00</p>
                        <p id="modalPrevPaid" class="text-xs text-slate-400 mt-0.5"></p>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Amount Collected <span class="text-rose-500">*</span></label>
                        <input type="number" name="amount_paid" id="modalAmountInput" step="0.01" min="0.01" required
                               class="w-full py-2.5 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                    <div class="mb-5">
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Notes (optional)</label>
                        <input type="text" name="notes" placeholder="e.g. partial payment, cash"
                               class="w-full py-2.5 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-green-700 text-white font-bold py-2.5 rounded-xl hover:bg-green-800 transition-colors">
                            Record Payment &amp; Print OR
                        </button>
                        <button type="button" onclick="closePayModal()"
                                class="px-4 py-2.5 border border-slate-200 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- LIST VIEW                                                          -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Monthly Payments</h1>
            <p class="text-slate-500 mt-2">Collect monthly installment payments from student accounts.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; <?= h((string) ($user['full_name'] ?? 'Cashier')) ?></p>
        </header>

        <!-- Stats -->
        <div class="grid gap-4 sm:grid-cols-4 mb-6">
            <?php
                $sc = [
                    ['Total Accounts',      $stats['total_accounts']??0,   'text-green-700', ''],
                    ['Overdue Accounts',    $stats['overdue_accounts']??0,  'text-rose-600',  ''],
                    ['Fully Paid',          $stats['paid_accounts']??0,     'text-emerald-600',''],
                    ['Unpaid Months',       $stats['total_unpaid_months']??0,'text-amber-600', ''],
                ];
            ?>
            <?php foreach ($sc as [$lbl,$val,$col,$_]): ?>
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
                <p class="text-xs text-slate-400 font-medium"><?= h($lbl) ?></p>
                <p class="text-3xl font-extrabold <?= $col ?> mt-2"><?= (int)$val ?></p>
                <p class="text-xs text-slate-400 mt-1">S.Y. <?= h($activeSchoolYearLabel) ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filters -->
        <form method="GET" class="flex flex-wrap gap-3 mb-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Search</label>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or Student ID…"
                       class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Status</label>
                <select name="status" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All</option>
                    <?php foreach (['Active','Overdue','Fully Paid'] as $s): ?>
                    <option value="<?= h($s) ?>" <?= $statusFi===$s?'selected':'' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="sy" value="<?= h($syFilter) ?>">
            <button type="submit" class="py-2 px-4 bg-green-700 text-white text-sm font-semibold rounded-xl hover:bg-green-800">Filter</button>
            <?php if ($search || $statusFi): ?>
            <a href="monthly_payments.php" class="py-2 px-4 border border-slate-200 bg-white text-sm font-semibold rounded-xl">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Accounts table -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <?php if (empty($accountsList)): ?>
            <div class="py-14 text-center text-slate-400">
                <p class="font-semibold">No student accounts found.</p>
                <p class="text-sm mt-1">
                    <a href="account_setup.php" class="text-green-700 underline">Set up accounts</a> first.
                </p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">Grade / Section</th>
                            <th class="px-4 py-3 text-left">Plan</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3 text-center">Unpaid / Overdue</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($accountsList as $row): ?>
                        <?php
                            $rName    = strtoupper(trim((string)($row['full_name']??'ID:'.$row['student_id'])));
                            $rStatus  = (string)($row['account_status']??'Active');
                            $rColors  = ['Active'=>'bg-green-100 text-green-700','Fully Paid'=>'bg-emerald-100 text-emerald-700','Overdue'=>'bg-rose-100 text-rose-700'];
                            $rOverdue = (int)($row['overdue_months']??0);
                            $rUnpaid  = (int)($row['unpaid_months']??0);
                        ?>
                        <tr class="hover:bg-slate-50 <?= $rOverdue>0?'bg-rose-50/40':'' ?>">
                            <td class="px-4 py-3">
                                <p class="font-semibold"><?= h($rName) ?></p>
                                <p class="text-xs text-slate-400 font-mono"><?= h((string)$row['student_id']) ?></p>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600">
                                <?= h((string)($row['gradelevel_name']??'')) ?>
                                <?php if (!empty($row['section_name'])): ?> · <?= h((string)$row['section_name']) ?><?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="font-medium"><?= h((string)($row['payment_method']??'')) ?></span><br>
                                <?php if ($row['payment_method']==='Installment'): ?>
                                <span class="text-slate-400">₱<?= number_format((float)($row['monthly_amount']??0),2) ?>/mo · <?= (int)($row['installment_months']??0) ?> mos</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right font-bold <?= (float)($row['balance']??0)>0?'text-amber-600':'text-emerald-600' ?>">
                                ₱<?= number_format((float)($row['balance']??0), 2) ?>
                            </td>
                            <td class="px-4 py-3 text-center text-xs">
                                <?php if ($rOverdue > 0): ?>
                                <span class="bg-rose-100 text-rose-700 font-bold rounded-full px-2 py-0.5"><?= $rOverdue ?> overdue</span>
                                <?php elseif ($rUnpaid > 0): ?>
                                <span class="bg-amber-100 text-amber-700 font-semibold rounded-full px-2 py-0.5"><?= $rUnpaid ?> unpaid</span>
                                <?php else: ?>
                                <span class="text-slate-300">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $rColors[$rStatus]??'bg-slate-100 text-slate-500' ?>">
                                    <?= h($rStatus) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="monthly_payments.php?account_id=<?= (int)$row['id'] ?>"
                                   class="inline-flex items-center gap-1.5 rounded-lg text-xs font-semibold px-3 py-1.5 transition-all
                                          <?= $rOverdue>0?'bg-rose-600 text-white hover:bg-rose-700':($rUnpaid>0?'bg-green-700 text-white hover:bg-green-800':'bg-slate-100 text-slate-600 hover:bg-slate-200') ?>">
                                    <?= $rOverdue>0?'Collect Overdue':($rUnpaid>0?'Collect Payment':'View') ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
</div>

<script>
function openPayModal(mpId, monthLabel, amountDue, prevPaid) {
    document.getElementById('modalMpId').value       = mpId;
    document.getElementById('modalMonthLabel').textContent = monthLabel;
    document.getElementById('modalAmountDue').textContent  = '₱' + amountDue.toLocaleString('en-PH',{minimumFractionDigits:2});
    document.getElementById('modalAmountInput').value      = (amountDue - prevPaid).toFixed(2);
    const prevEl = document.getElementById('modalPrevPaid');
    prevEl.textContent = prevPaid > 0 ? 'Previously paid: ₱' + prevPaid.toLocaleString('en-PH',{minimumFractionDigits:2}) : '';
    document.getElementById('payModal').classList.remove('hidden');
    document.getElementById('modalAmountInput').focus();
}
function closePayModal() {
    document.getElementById('payModal').classList.add('hidden');
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePayModal(); });
</script>
</body>
</html>
