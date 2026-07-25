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

$cashierName = (string) ($user['full_name'] ?? 'Cashier');
$sy          = soa_active_school_year($connection);
$syLabel     = $sy['label'];
$syId        = $sy['id'];
$schemaReady = soa_schema_ready($connection);

$kpi = [
    'today_total' => 0.0, 'today_count' => 0, 'month_total' => 0.0,
    'sy_total' => 0.0, 'sy_count' => 0,
    'receivables' => 0.0, 'overdue_accts' => 0, 'partial_accts' => 0,
    'total_accounts' => 0,
];
$byGrade  = [];
$projected = [];
$overdueList = [];
$trendLabels = [];
$trendData   = [];

if ($schemaReady) {
    soa_mark_overdue($connection);

    // Collection KPIs (scoped to active SY assessments)
    $s = $connection->prepare(
        "SELECT
            IFNULL(SUM(CASE WHEN DATE(pt.paid_at)=CURDATE() THEN pt.amount END),0) AS today_total,
            COUNT(CASE WHEN DATE(pt.paid_at)=CURDATE() THEN 1 END)                 AS today_count,
            IFNULL(SUM(CASE WHEN YEAR(pt.paid_at)=YEAR(CURDATE()) AND MONTH(pt.paid_at)=MONTH(CURDATE()) THEN pt.amount END),0) AS month_total,
            IFNULL(SUM(pt.amount),0) AS sy_total,
            COUNT(*)                 AS sy_count
         FROM payment_transaction pt
         JOIN student_assessment sa ON sa.id = pt.assessment_id
         WHERE pt.status='Posted' AND sa.school_year_id = ?"
    );
    $s->bind_param('i', $syId);
    $s->execute();
    $kpi = array_merge($kpi, (array) stmt_fetch_assoc($s));

    // Receivables + overdue/partial accounts
    $s = $connection->prepare(
        "SELECT
            IFNULL(SUM(ps.balance),0) AS receivables,
            COUNT(DISTINCT CASE WHEN ps.status='Overdue' THEN ps.assessment_id END) AS overdue_accts,
            COUNT(DISTINCT CASE WHEN ps.status='Partial' THEN ps.assessment_id END) AS partial_accts
         FROM payment_schedule ps
         JOIN student_assessment sa ON sa.id = ps.assessment_id
         WHERE sa.school_year_id = ? AND ps.balance > 0"
    );
    $s->bind_param('i', $syId);
    $s->execute();
    $kpi = array_merge($kpi, (array) stmt_fetch_assoc($s));

    $s = $connection->prepare('SELECT COUNT(*) AS c FROM student_assessment WHERE school_year_id = ?');
    $s->bind_param('i', $syId);
    $s->execute();
    $kpi['total_accounts'] = (int) (stmt_fetch_assoc($s)['c'] ?? 0);

    // Collection by grade
    $s = $connection->prepare(
        "SELECT IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade,
                COUNT(pt.id) AS cnt, IFNULL(SUM(pt.amount),0) AS total
         FROM payment_transaction pt
         JOIN student_assessment sa ON sa.id = pt.assessment_id
         JOIN enrollment en ON en.id = sa.enrollment_id
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         WHERE pt.status='Posted' AND sa.school_year_id = ?
         GROUP BY grade ORDER BY total DESC"
    );
    $s->bind_param('i', $syId);
    $s->execute();
    $byGrade = stmt_fetch_all_assoc($s);

    // Projected collections per month (upcoming receivables)
    $s = $connection->prepare(
        "SELECT ps.month_label, MIN(ps.due_date) AS dd, IFNULL(SUM(ps.balance),0) AS due
         FROM payment_schedule ps
         JOIN student_assessment sa ON sa.id = ps.assessment_id
         WHERE sa.school_year_id = ? AND ps.balance > 0
         GROUP BY ps.month_label ORDER BY dd"
    );
    $s->bind_param('i', $syId);
    $s->execute();
    $projected = stmt_fetch_all_assoc($s);

    // Overdue students
    $s = $connection->prepare(
        "SELECT sa.id, COUNT(*) AS overdue_months, IFNULL(SUM(ps.balance),0) AS bal,
                COALESCE(
                    CONCAT(p.surname, ', ', p.firstname),
                    CONCAT(osp.surname, ', ', osp.firstname)
                ) AS full_name,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade
         FROM payment_schedule ps
         JOIN student_assessment sa ON sa.id = ps.assessment_id
         JOIN enrollment en ON en.id = sa.enrollment_id
         LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         WHERE sa.school_year_id = ? AND ps.status='Overdue'
         GROUP BY sa.id ORDER BY overdue_months DESC, bal DESC LIMIT 8"
    );
    $s->bind_param('i', $syId);
    $s->execute();
    $overdueList = stmt_fetch_all_assoc($s);

    // 30-day trend
    $s = $connection->prepare(
        "SELECT DATE(pt.paid_at) AS d, IFNULL(SUM(pt.amount),0) AS total
         FROM payment_transaction pt
         JOIN student_assessment sa ON sa.id = pt.assessment_id
         WHERE pt.status='Posted' AND sa.school_year_id = ?
           AND pt.paid_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
         GROUP BY DATE(pt.paid_at) ORDER BY d"
    );
    $s->bind_param('i', $syId);
    $s->execute();
    foreach (stmt_fetch_all_assoc($s) as $row) {
        $trendLabels[] = date('M d', strtotime((string) $row['d']));
        $trendData[]   = round((float) $row['total'], 2);
    }
}

$projectedNextMonth = 0.0;
if ($projected) {
    foreach ($projected as $pj) {
        if (strtotime((string) $pj['dd']) >= strtotime('first day of next month')) {
            $projectedNextMonth = (float) $pj['due'];
            break;
        }
    }
    if ($projectedNextMonth === 0.0) {
        $projectedNextMonth = (float) ($projected[0]['due'] ?? 0);
    }
}
$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Dashboard | ITFA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Financial Dashboard</h1>
            <p class="text-slate-500 mt-2">Live collections, receivables, and projections from the SOA ledger.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?> &nbsp;·&nbsp; <?= h($cashierName) ?> &nbsp;·&nbsp; <?= date('F d, Y') ?></p>
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

        <!-- KPI row -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-5">
            <div class="rounded-2xl bg-green-700 text-white p-5 shadow-panel">
                <p class="text-xs text-green-200 font-semibold uppercase tracking-wide">Today's Collection</p>
                <p class="text-3xl font-extrabold mt-2">₱<?= number_format((float) $kpi['today_total'], 2) ?></p>
                <p class="text-xs text-green-300 mt-1"><?= (int) $kpi['today_count'] ?> transaction(s)</p>
            </div>
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-xs text-slate-400 font-semibold uppercase tracking-wide">This Month</p>
                <p class="text-3xl font-extrabold text-slate-900 mt-2">₱<?= number_format((float) $kpi['month_total'], 2) ?></p>
                <p class="text-xs text-slate-400 mt-1"><?= date('F Y') ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-xs text-slate-400 font-semibold uppercase tracking-wide">School-Year Collection</p>
                <p class="text-3xl font-extrabold text-slate-900 mt-2">₱<?= number_format((float) $kpi['sy_total'], 2) ?></p>
                <p class="text-xs text-slate-400 mt-1"><?= (int) $kpi['sy_count'] ?> payments · S.Y. <?= h($syLabel) ?></p>
            </div>
            <div class="rounded-2xl bg-amber-500 text-white p-5 shadow-panel">
                <p class="text-xs text-amber-100 font-semibold uppercase tracking-wide">Outstanding Receivables</p>
                <p class="text-3xl font-extrabold mt-2">₱<?= number_format((float) $kpi['receivables'], 2) ?></p>
                <p class="text-xs text-amber-100 mt-1">installment balances</p>
            </div>
        </div>

        <!-- Secondary KPIs -->
        <div class="grid gap-4 sm:grid-cols-4 mb-6">
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm">
                <p class="text-xs text-slate-400 font-medium">Assessed Accounts</p>
                <p class="text-2xl font-extrabold text-green-700 mt-1"><?= (int) $kpi['total_accounts'] ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-rose-200 p-5 shadow-sm">
                <p class="text-xs text-rose-500 font-medium">Overdue Accounts</p>
                <p class="text-2xl font-extrabold text-rose-600 mt-1"><?= (int) $kpi['overdue_accts'] ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-amber-200 p-5 shadow-sm">
                <p class="text-xs text-amber-600 font-medium">Partial Accounts</p>
                <p class="text-2xl font-extrabold text-amber-600 mt-1"><?= (int) $kpi['partial_accts'] ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-emerald-200 p-5 shadow-sm">
                <p class="text-xs text-emerald-600 font-medium">Projected Next Month</p>
                <p class="text-2xl font-extrabold text-emerald-700 mt-1">₱<?= number_format($projectedNextMonth, 0) ?></p>
            </div>
        </div>

        <!-- Trend -->
        <section class="mb-6">
            <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-3">Collection Trend — Last 30 Days</h2>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                <?php if ($trendLabels === []): ?>
                <p class="text-center text-slate-400 text-sm py-10">No payments recorded yet. Collections will appear here.</p>
                <?php else: ?>
                <div class="h-64"><canvas id="trendChart"></canvas></div>
                <?php endif; ?>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2 mb-6">
            <!-- By grade -->
            <section>
                <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-3">Collection by Grade Level</h2>
                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                    <?php if ($byGrade === []): ?>
                    <p class="text-center text-slate-400 text-sm py-10">No data.</p>
                    <?php else: ?>
                    <?php $gTotal = array_sum(array_column($byGrade, 'total')); ?>
                    <table class="min-w-full text-sm">
                        <thead><tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase tracking-wide">
                            <th class="px-4 py-3 text-left">Grade</th><th class="px-4 py-3 text-right">Txn</th><th class="px-4 py-3 text-right">Collected</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($byGrade as $g): $pct = $gTotal>0 ? round((float)$g['total']/$gTotal*100) : 0; ?>
                            <tr class="hover:bg-green-50/30">
                                <td class="px-4 py-3">
                                    <span class="font-semibold text-slate-700"><?= h(trim((string) $g['grade'])) ?></span>
                                    <div class="mt-1 h-1 rounded-full bg-slate-100"><div class="h-1 rounded-full bg-green-500" style="width:<?= $pct ?>%"></div></div>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-500"><?= (int) $g['cnt'] ?></td>
                                <td class="px-4 py-3 text-right font-bold text-green-700">₱<?= number_format((float) $g['total'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Projected by month -->
            <section>
                <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-3">Projected Monthly Collections</h2>
                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                    <?php if ($projected === []): ?>
                    <p class="text-center text-slate-400 text-sm py-10">No upcoming receivables.</p>
                    <?php else: ?>
                    <table class="min-w-full text-sm">
                        <thead><tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase tracking-wide">
                            <th class="px-4 py-3 text-left">Month</th><th class="px-4 py-3 text-left">Due Date</th><th class="px-4 py-3 text-right">Expected</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($projected as $pj): ?>
                            <tr class="hover:bg-green-50/30">
                                <td class="px-4 py-3 font-medium"><?= h((string) $pj['month_label']) ?></td>
                                <td class="px-4 py-3 text-slate-500"><?= $pj['dd'] ? date('M d, Y', strtotime((string) $pj['dd'])) : '—' ?></td>
                                <td class="px-4 py-3 text-right font-bold text-emerald-700">₱<?= number_format((float) $pj['due'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <!-- Overdue students -->
        <section class="mb-6">
            <h2 class="text-sm font-bold uppercase tracking-widest text-slate-400 mb-3">Students with Overdue Accounts</h2>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                <?php if ($overdueList === []): ?>
                <p class="text-center text-emerald-600 text-sm py-10 font-semibold">No overdue accounts 🎉</p>
                <?php else: ?>
                <table class="min-w-full text-sm">
                    <thead><tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase tracking-wide">
                        <th class="px-4 py-3 text-left">Student</th><th class="px-4 py-3 text-left">Grade</th>
                        <th class="px-4 py-3 text-center">Overdue Months</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3 text-center">Action</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($overdueList as $o): ?>
                        <tr class="hover:bg-rose-50/30">
                            <td class="px-4 py-3 font-semibold text-slate-700"><?= h(strtoupper(trim((string) ($o['full_name'] ?? '—')))) ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= h(trim((string) $o['grade'])) ?></td>
                            <td class="px-4 py-3 text-center"><span class="rounded-full bg-rose-100 text-rose-700 font-bold px-2.5 py-0.5 text-xs"><?= (int) $o['overdue_months'] ?></span></td>
                            <td class="px-4 py-3 text-right font-bold text-rose-600">₱<?= number_format((float) $o['bal'], 2) ?></td>
                            <td class="px-4 py-3 text-center">
                                <a href="collect.php?ref=<?= urlencode((string) ($o['full_name'] ?? '')) ?>" class="text-xs font-semibold text-green-600 hover:underline">Collect →</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>

        <?php endif; /* schemaReady */ ?>
    </main>
</div>

<?php if ($trendLabels !== []): ?>
<script>
new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trendLabels) ?>,
        datasets: [{
            label: 'Collected',
            data: <?= json_encode($trendData) ?>,
            backgroundColor: 'rgba(22,101,52,0.12)',
            borderColor: 'rgba(22,101,52,0.9)',
            borderWidth: 2, fill: true, tension: 0.35, pointRadius: 3,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: {
            label: (c) => ' ₱' + Number(c.raw).toLocaleString('en-PH', {minimumFractionDigits:2})
        } } },
        scales: { x: { grid: { display:false } },
                  y: { beginAtZero:true, ticks: { callback:(v)=>'₱'+Number(v).toLocaleString() } } }
    }
});
</script>
<?php endif; ?>
</body>
</html>
