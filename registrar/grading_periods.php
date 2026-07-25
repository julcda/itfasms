<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/grading_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_registrar_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only Registrar or Super Admin users can manage grading periods.');
    redirect_to(app_url('dashboard/index.php'));
}

$sy                    = teacher_active_sy($connection);
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;
$ready                 = teacher_schema_ready($connection);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('grading_periods.php');
    }
    try {
        if (!$ready) {
            throw new RuntimeException('Run migrations/teacher_module.sql first.');
        }
        $action   = (string) ($_POST['action'] ?? '');
        $periodId = to_int($_POST['period_id'] ?? 0);

        if ($action === 'set_status') {
            gp_set_status($connection, $periodId, (string) ($_POST['status'] ?? ''), $user);
            $p = gp_get($connection, $periodId);
            flash_set('success', $p['name'] . ' is now ' . strtolower((string) $p['status']) . '.');
        } elseif ($action === 'set_current') {
            gp_set_current($connection, $periodId);
            $p = gp_get($connection, $periodId);
            flash_set('success', $p['name'] . ' is now the current grading period.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('grading_periods.php');
}

$syId    = to_int($_GET['sy'] ?? 0) ?: $sy['id'];
$periods = $ready ? gp_for_sy($connection, $syId) : [];

$years = [];
$yr = $connection->query('SELECT School_year_id, School_year, Status FROM schoolyear ORDER BY School_year_id DESC');
if ($yr) { while ($r = $yr->fetch_assoc()) { $years[] = $r; } }

// Encoding progress per period.
$progress = [];
if ($ready && $periods) {
    $ids = implode(',', array_map(static fn($p) => (int) $p['id'], $periods));
    $pr  = $connection->query(
        "SELECT grading_period_id, COUNT(*) total, SUM(grade IS NOT NULL) encoded,
                SUM(status='Submitted') submitted, SUM(status='Locked') locked
         FROM student_grade WHERE grading_period_id IN ($ids) GROUP BY grading_period_id"
    );
    if ($pr) { while ($r = $pr->fetch_assoc()) { $progress[(int) $r['grading_period_id']] = $r; } }
}

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grading Periods | ITFA Registrar</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Registrar · Academics</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Grading Periods</h1>
            <p class="text-slate-500 mt-2">Control when teachers may encode grades. <b>Open</b> lets them edit; <b>Locked</b> freezes the period. Locking and reopening are recorded in each student's grade history.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$ready): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Teacher module tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/teacher_module.sql</code> first.</p>
        </div>
        <?php else: ?>

        <!-- School year switcher -->
        <div class="bg-white rounded-3xl border border-green-100 shadow-panel p-5 mb-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">School Year</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($years as $y): $on = (int) $y['School_year_id'] === $syId; ?>
                <a href="grading_periods.php?sy=<?= (int) $y['School_year_id'] ?>"
                   class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors <?= $on ? 'bg-green-700 border-green-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-green-400' ?>">
                    <?= h((string) $y['School_year']) ?>
                    <?php if ((int) $y['Status'] === 1): ?><span class="ml-1 text-[10px] <?= $on ? 'text-green-200' : 'text-emerald-600' ?>">ACTIVE</span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="space-y-4">
            <?php foreach ($periods as $p):
                $pid  = (int) $p['id'];
                $pr   = $progress[$pid] ?? ['total'=>0,'encoded'=>0,'submitted'=>0,'locked'=>0];
                $tot  = (int) $pr['total']; $enc = (int) $pr['encoded'];
                $pct  = $tot > 0 ? (int) round($enc / $tot * 100) : 0;
                $cur  = (int) $p['is_current'] === 1;
            ?>
            <section class="bg-white rounded-3xl border <?= $cur ? 'border-green-300' : 'border-green-100' ?> shadow-panel p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="font-extrabold text-lg"><?= h((string) $p['name']) ?></h2>
                            <span class="text-xs font-bold rounded-full border px-2.5 py-0.5 <?= gp_status_badge((string) $p['status']) ?>"><?= h((string) $p['status']) ?></span>
                            <?php if ($cur): ?>
                            <span class="text-[10px] font-extrabold rounded-full border border-green-300 bg-green-100 text-green-800 px-2 py-0.5">CURRENT</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">
                            Term <?= (int) $p['term_no'] ?> · <?= h((string) $p['code']) ?>
                            <?php if ($p['locked_at']): ?> · locked <?= h(date('M j, Y g:ia', strtotime((string) $p['locked_at']))) ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">Encoded</p>
                        <p class="text-xl font-extrabold <?= $pct >= 100 ? 'text-emerald-600' : 'text-slate-700' ?>"><?= number_format($enc) ?><span class="text-slate-300">/</span><?= number_format($tot) ?></p>
                    </div>
                </div>

                <?php if ($tot > 0): ?>
                <div class="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full <?= $pct >= 100 ? 'bg-emerald-500' : 'bg-green-500' ?>" style="width:<?= $pct ?>%"></div>
                </div>
                <p class="text-xs text-slate-400 mt-1"><?= $pct ?>% encoded · <?= number_format((int) $pr['submitted']) ?> submitted · <?= number_format((int) $pr['locked']) ?> locked</p>
                <?php endif; ?>

                <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-slate-100">
                    <?php foreach (['Open' => 'emerald', 'Closed' => 'amber', 'Locked' => 'rose'] as $st => $col):
                        if ((string) $p['status'] === $st) { continue; } ?>
                    <form method="POST" action="grading_periods.php" class="inline"
                          onsubmit="return confirm('<?= $st === 'Locked' ? 'Lock this period? Teachers will no longer be able to edit these grades.' : ($st === 'Open' ? 'Open this period? Teachers will be able to edit grades again.' : 'Close this period?') ?>');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="period_id" value="<?= $pid ?>">
                        <input type="hidden" name="status" value="<?= $st ?>">
                        <button class="rounded-xl bg-<?= $col ?>-600 hover:bg-<?= $col ?>-700 text-white text-xs font-bold px-4 py-2">
                            <?= $st === 'Open' ? ((string) $p['status'] === 'Locked' ? 'Reopen' : 'Open') : $st ?>
                        </button>
                    </form>
                    <?php endforeach; ?>

                    <?php if (!$cur): ?>
                    <form method="POST" action="grading_periods.php" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="set_current">
                        <input type="hidden" name="period_id" value="<?= $pid ?>">
                        <button class="rounded-xl bg-slate-100 border border-slate-200 hover:bg-slate-200 text-slate-700 text-xs font-bold px-4 py-2">Make Current</button>
                    </form>
                    <?php endif; ?>
                </div>
            </section>
            <?php endforeach; ?>

            <?php if (!$periods): ?>
            <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-10 text-center text-slate-400">
                <p class="font-semibold">No grading periods defined for this school year.</p>
            </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 rounded-2xl bg-green-50 border border-green-200 px-5 py-4 text-xs text-green-900">
            <b>Adding a 4th grading period?</b> Periods are data, not code — insert a row with <code>term_no = 4</code> for the school year.
            S.Y. 2023-2024 already runs four. No schema change is needed.
        </div>

        <?php endif; ?>
    </main>
</div>
</body>
</html>
