<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';

$teacher = require_teacher_login();

$db      = db();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];

$periods  = gp_for_sy($db, $sy['id']);
$current  = gp_current($db, $sy['id']);
$periodId = to_int($_GET['period'] ?? 0) ?: (int) ($current['id'] ?? 0);
$period   = $periodId ? gp_get($db, $periodId) : null;
if ($period && (int) $period['school_year_id'] !== $sy['id']) {
    $period   = $current;
    $periodId = (int) ($current['id'] ?? 0);
}

$classes = teacher_classes($db, $tid, $sy['id'], $periodId);
$flash   = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Classes | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(5,150,105,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Teacher · Teaching</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">My Classes</h1>
            <p class="text-slate-500 mt-1 text-sm">Only classes officially assigned to you appear here. Select a class to open its roster and encode grades.</p>
            <p class="text-xs text-emerald-700 mt-2">S.Y. <?= h($syLabel) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <!-- Period selector -->
        <div class="bg-white rounded-3xl border border-emerald-100 shadow-panel p-5 mb-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Grading Period</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($periods as $p): $on = (int) $p['id'] === $periodId; ?>
                <a href="classes.php?period=<?= (int) $p['id'] ?>"
                   class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors <?= $on ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-400' ?>">
                    <?= h((string) $p['name']) ?>
                    <span class="ml-1 text-[10px] font-extrabold uppercase <?= $on ? 'text-emerald-100' : 'text-slate-400' ?>"><?= h((string) $p['status']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if ($period && !gp_is_open($period)): ?>
            <p class="text-xs text-amber-700 mt-2 font-semibold">⚠ <?= h((string) $period['name']) ?> is <?= h(strtolower((string) $period['status'])) ?> — grades are view-only.</p>
            <?php endif; ?>
        </div>

        <?php if (!$classes): ?>
        <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-10 text-center text-slate-400">
            <p class="font-semibold">No classes are assigned to you for S.Y. <?= h($syLabel) ?>.</p>
            <p class="text-sm mt-1">Please contact the Registrar if this is unexpected.</p>
        </div>
        <?php else: ?>
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
            <?php foreach ($classes as $c):
                $sc   = (int) $c['student_count'];
                $gc   = (int) $c['graded_count'];
                $done = $sc > 0 && $gc >= $sc;
                $open = (string) ($c['class_status'] ?? 'Open') === 'Open';
            ?>
            <a href="<?= h(app_url('teacher/class_view.php?class_id=' . (int) $c['Class_id'] . '&period=' . $periodId)) ?>"
               class="block bg-white rounded-3xl border border-emerald-100 shadow-panel p-5 hover:border-emerald-400 hover:-translate-y-0.5 transition-all">
                <div class="flex items-start justify-between gap-2 mb-3">
                    <div class="min-w-0">
                        <p class="font-extrabold text-base leading-tight truncate"><?= h((string) ($c['Subject_name'] ?: '—')) ?></p>
                        <?php if ($c['subject_code']): ?><p class="text-xs text-slate-400 mt-0.5"><?= h((string) $c['subject_code']) ?></p><?php endif; ?>
                    </div>
                    <span class="shrink-0 text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= $open ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-300' ?>">
                        <?= $open ? 'OPEN' : 'CLOSED' ?>
                    </span>
                </div>

                <dl class="text-xs space-y-1 text-slate-600">
                    <div class="flex justify-between"><dt class="text-slate-400">Grade &amp; Section</dt><dd class="font-semibold"><?= h((string) ($c['Gradelevel'] ?? '—')) ?> — <?= h((string) ($c['Section_name'] ?? '—')) ?></dd></div>
                    <?php if ($c['Semester'] && $c['Semester'] !== 'N/A'): ?>
                    <div class="flex justify-between"><dt class="text-slate-400">Semester</dt><dd class="font-semibold"><?= h((string) $c['Semester']) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($c['strand'] && $c['strand'] !== 'N/A'): ?>
                    <div class="flex justify-between"><dt class="text-slate-400">Strand</dt><dd class="font-semibold"><?= h((string) $c['strand']) ?></dd></div>
                    <?php endif; ?>
                    <div class="flex justify-between"><dt class="text-slate-400">Schedule</dt><dd class="font-semibold"><?= h((string) ($c['Time'] ?: '—')) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-400">Room</dt><dd class="font-semibold"><?= h((string) ($c['room_name'] ?: '—')) ?></dd></div>
                </dl>

                <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100">
                    <span class="text-xs text-slate-500"><b class="text-slate-800"><?= number_format($sc) ?></b> student<?= $sc === 1 ? '' : 's' ?></span>
                    <span class="text-[11px] font-extrabold rounded-full px-2.5 py-0.5 border <?= $done ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : ($gc > 0 ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-slate-100 text-slate-500 border-slate-300') ?>">
                        <?= $done ? '✓ Complete' : $gc . '/' . $sc . ' graded' ?>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
