<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';

$teacher = require_teacher_login();

$db      = db();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];

$schedule = teacher_schedule($db, $tid, $sy['id']);
$classes  = teacher_classes($db, $tid, $sy['id'], 0);
$flash    = flash_get();

// Group the normalized schedule by weekday.
$byDay = [];
foreach ($schedule as $s) { $byDay[(int) $s['day_of_week']][] = $s; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Schedule | ITFA Teacher</title>
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
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">My Schedule</h1>
            <p class="text-slate-500 mt-1 text-sm">S.Y. <?= h($syLabel) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$schedule): ?>
        <!-- Fall back to the legacy free-text time on `classes` until the Registrar
             enters structured schedule rows. -->
        <div class="mb-6 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4 text-sm text-amber-900">
            <b>No structured schedule has been entered yet.</b>
            Showing the time recorded on each class instead. Ask the Registrar to set day, time and room per class for a full weekly view.
        </div>

        <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel overflow-hidden">
            <?php if (!$classes): ?>
            <div class="p-10 text-center text-slate-400"><p class="font-semibold">No classes assigned.</p></div>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr><th class="text-left px-6 py-3">Subject</th><th class="text-left">Grade &amp; Section</th><th class="text-left">Time</th><th class="text-left px-6">Room</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($classes as $c): ?>
                    <tr class="hover:bg-emerald-50/30">
                        <td class="px-6 py-3 font-bold"><?= h((string) ($c['Subject_name'] ?: '—')) ?></td>
                        <td class="text-slate-600"><?= h((string) ($c['Gradelevel'] ?? '')) ?> — <?= h((string) ($c['Section_name'] ?? '')) ?></td>
                        <td class="text-slate-600"><?= h((string) ($c['Time'] ?: '—')) ?></td>
                        <td class="px-6 text-slate-600"><?= h((string) ($c['room_name'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>

        <?php else: ?>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <?php for ($d = 1; $d <= 6; $d++): $rows = $byDay[$d] ?? []; ?>
            <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel p-5">
                <h2 class="font-extrabold text-base mb-3 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full <?= $rows ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span>
                    <?= h(teacher_day_name($d)) ?>
                    <span class="ml-auto text-xs font-bold text-slate-400"><?= count($rows) ?></span>
                </h2>
                <?php if (!$rows): ?>
                <p class="text-xs text-slate-400">No classes.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($rows as $s): ?>
                    <a href="<?= h(app_url('teacher/class_view.php?class_id=' . (int) $s['Class_id'])) ?>"
                       class="block rounded-xl border border-slate-100 bg-slate-50 hover:bg-emerald-50 hover:border-emerald-200 px-3 py-2 transition-colors">
                        <p class="text-xs font-extrabold text-emerald-700">
                            <?= h(date('g:ia', strtotime((string) $s['start_time']))) ?>–<?= h(date('g:ia', strtotime((string) $s['end_time']))) ?>
                        </p>
                        <p class="text-sm font-bold truncate"><?= h((string) $s['Subject_name']) ?></p>
                        <p class="text-xs text-slate-500">
                            <?= h((string) ($s['Gradelevel'] ?? '')) ?> — <?= h((string) ($s['Section_name'] ?? '')) ?>
                            <?php if ($s['room_name']): ?> · <?= h((string) $s['room_name']) ?><?php endif; ?>
                        </p>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
