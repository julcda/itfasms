<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';
require_once __DIR__ . '/../includes/message_service.php';

$teacher = require_teacher_login();

$db      = db();
$user    = current_user();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];
$ready   = teacher_schema_ready($db);

$period   = $ready ? gp_current($db, $sy['id']) : null;
$periodId = (int) ($period['id'] ?? 0);

$stats     = $ready ? teacher_stats($db, $tid, $sy['id'], $periodId) : ['class_count'=>0,'subject_count'=>0,'student_count'=>0,'graded_count'=>0];
$act       = $ready ? teacher_action_stats($db, $tid, $sy['id'], $periodId) : ['to_encode'=>0,'awaiting'=>0,'returned'=>0,'approved'=>0,'roster_total'=>0,'encoded'=>0,'percent'=>0];
$advisory  = $ready ? teacher_advisory($db, $tid, $sy['id']) : null;
$classes   = $ready ? teacher_classes($db, $tid, $sy['id'], $periodId, true) : [];  // grading cards — hide HRG/recess
$schedule  = $ready ? teacher_schedule($db, $tid, $sy['id']) : [];
$announce  = $ready ? teacher_announcements($db, 4) : [];
$unreadMsg = msg_unread_total($db, (int) $user['id']);
$today     = (int) date('N');   // 1=Mon … 7=Sun
$todayCls  = array_values(array_filter($schedule, static fn($s) => (int) $s['day_of_week'] === $today));
$flash     = flash_get();

$subjects = [];
foreach ($classes as $c) {
    $n = (string) ($c['Subject_name'] ?? '');
    if ($n !== '') { $subjects[$n] = true; }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | ITFA Teacher</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Teacher</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Welcome, <?= h(teacher_display_name($teacher)) ?></h1>
            <p class="text-slate-500 mt-1 text-sm">
                <?= h((string) ($teacher['Designation'] ?: 'Faculty')) ?>
                <?php if ($teacher['employee_no']): ?> · Employee #<?= h((string) $teacher['employee_no']) ?><?php endif; ?>
            </p>
            <div class="flex flex-wrap items-center gap-2 mt-3 text-xs">
                <span class="rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 font-bold px-3 py-1">S.Y. <?= h($syLabel) ?></span>
                <?php if ($period): ?>
                <span class="rounded-full border font-bold px-3 py-1 <?= gp_status_badge((string) $period['status']) ?>">
                    <?= h((string) $period['name']) ?> · <?= h((string) $period['status']) ?>
                </span>
                <?php endif; ?>
            </div>
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

        <?php if (!$period): ?>
        <div class="mb-6 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4 text-sm text-amber-900">
            <b>No grading period is configured for S.Y. <?= h($syLabel) ?>.</b> Please contact the Registrar.
        </div>
        <?php elseif (!gp_is_open($period)): ?>
        <div class="mb-6 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4 text-sm text-amber-900">
            <b><?= h((string) $period['name']) ?> is <?= h(strtolower((string) $period['status'])) ?>.</b>
            You can view grades but not edit them. Contact the Registrar to reopen the period.
        </div>
        <?php endif; ?>

        <!-- ══ ACTION TILES — what needs doing, most urgent first ══
             Status colours (rose/amber/emerald) are reserved for state and always
             ship with an icon + word, never colour alone. -->
        <?php
        $tiles = [];
        if ($act['returned'] > 0) {
            $tiles[] = ['label'=>'Returned to you','value'=>$act['returned'],'sub'=>'needs correction',
                        'hex'=>'#e11d48','ring'=>'ring-rose-200','bg'=>'bg-rose-50','txt'=>'text-rose-700',
                        'href'=>app_url('teacher/classes.php'),
                        'icon'=>'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z'];
        }
        if ($act['to_encode'] > 0) {
            $tiles[] = ['label'=>'Grades to encode','value'=>$act['to_encode'],'sub'=>'students still blank',
                        'hex'=>'#f59e0b','ring'=>'ring-amber-200','bg'=>'bg-amber-50','txt'=>'text-amber-700',
                        'href'=>app_url('teacher/classes.php'),
                        'icon'=>'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'];
        }
        if ($act['awaiting'] > 0) {
            $tiles[] = ['label'=>'Awaiting review','value'=>$act['awaiting'],'sub'=>'with the Dept Head',
                        'hex'=>'#166534','ring'=>'ring-green-200','bg'=>'bg-green-50','txt'=>'text-green-700',
                        'href'=>app_url('teacher/classes.php'),
                        'icon'=>'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'];
        }
        if ($act['approved'] > 0) {
            $tiles[] = ['label'=>'Approved','value'=>$act['approved'],'sub'=>'signed off',
                        'hex'=>'#059669','ring'=>'ring-emerald-200','bg'=>'bg-emerald-50','txt'=>'text-emerald-700',
                        'href'=>app_url('teacher/classes.php'),
                        'icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'];
        }
        if ($unreadMsg > 0) {
            $tiles[] = ['label'=>'Unread messages','value'=>$unreadMsg,'sub'=>'from colleagues',
                        'hex'=>'#7c3aed','ring'=>'ring-violet-200','bg'=>'bg-violet-50','txt'=>'text-violet-700',
                        'href'=>app_url('teacher/messages.php'),
                        'icon'=>'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'];
        }
        ?>
        <?php if ($tiles): ?>
        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">Needs your attention</p>
        <div class="grid grid-cols-2 lg:grid-cols-<?= min(count($tiles), 4) ?> gap-4 mb-6">
            <?php foreach ($tiles as $t): ?>
            <a href="<?= h($t['href']) ?>" class="group rounded-2xl <?= $t['bg'] ?> ring-1 <?= $t['ring'] ?> p-4 hover:-translate-y-0.5 hover:shadow-lg transition-all">
                <div class="flex items-start justify-between">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:<?= $t['hex'] ?>1a">
                        <svg class="w-5 h-5" style="color:<?= $t['hex'] ?>" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $t['icon'] ?>"/></svg>
                    </span>
                    <span class="text-3xl font-extrabold leading-none <?= $t['txt'] ?>"><?= number_format($t['value']) ?></span>
                </div>
                <p class="text-sm font-bold text-slate-700 mt-3"><?= h($t['label']) ?></p>
                <p class="text-xs text-slate-500"><?= h($t['sub']) ?></p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="rounded-2xl bg-emerald-50 ring-1 ring-emerald-200 px-5 py-4 mb-6 flex items-center gap-3">
            <span class="w-9 h-9 rounded-xl bg-emerald-600/10 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <p class="text-sm font-bold text-emerald-800">All caught up — nothing needs your attention right now.</p>
        </div>
        <?php endif; ?>

        <!-- ══ Context tiles + grading meter ══ -->
        <div class="grid lg:grid-cols-[1fr_1fr_1fr_1.4fr] gap-4 mb-6">
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:#1665341a">
                        <svg class="w-4 h-4" style="color:#166534" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </span>
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Classes</p>
                </div>
                <p class="text-3xl font-extrabold text-slate-800 mt-2"><?= number_format($stats['class_count']) ?></p>
                <p class="text-xs text-slate-400"><?= number_format(count($subjects)) ?> subject<?= count($subjects) === 1 ? '' : 's' ?></p>
            </div>

            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:#0284c71a">
                        <svg class="w-4 h-4" style="color:#0284c7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a3 3 0 00-5.356-1.857M17 20H7m10 0v-1c0-.656-.126-1.283-.356-1.857M7 20H2v-1a3 3 0 015.356-1.857M7 20v-1c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </span>
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Students</p>
                </div>
                <p class="text-3xl font-extrabold text-slate-800 mt-2"><?= number_format($stats['student_count']) ?></p>
                <p class="text-xs text-slate-400">across all classes</p>
            </div>

            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:#0598691a">
                        <svg class="w-4 h-4" style="color:#059669" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </span>
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Today</p>
                </div>
                <p class="text-3xl font-extrabold text-slate-800 mt-2"><?= count($todayCls) ?></p>
                <p class="text-xs text-slate-400"><?= h(teacher_day_name($today)) ?>'s classes</p>
            </div>

            <!-- Term progress: a ratio against a limit → meter, not a chart -->
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">
                        Term progress<?= $period ? ' · ' . h((string) $period['code']) : '' ?>
                    </p>
                    <span class="text-xs font-extrabold <?= $act['percent'] >= 100 ? 'text-emerald-700' : 'text-slate-600' ?>"><?= (int) $act['percent'] ?>%</span>
                </div>
                <p class="text-3xl font-extrabold text-slate-800 mt-2">
                    <?= number_format((int) ($act['encoded'] ?? 0)) ?><span class="text-lg text-slate-300 font-bold">/<?= number_format((int) $act['roster_total']) ?></span>
                </p>
                <div class="mt-3 h-2.5 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full transition-all" style="width:<?= max(2, (int) $act['percent']) ?>%;background:<?= $act['percent'] >= 100 ? '#059669' : '#166534' ?>"></div>
                </div>
                <p class="text-xs text-slate-400 mt-1.5">
                    <?= $act['percent'] >= 100 ? 'All students graded 🎉' : number_format($act['to_encode']) . ' still to encode' ?>
                </p>
            </div>
        </div>

        <div class="grid lg:grid-cols-[1fr_340px] gap-6">
            <div class="space-y-6">
                <!-- Advisory -->
                <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-3">Advisory Class</h2>
                    <?php if ($advisory): ?>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-xl bg-emerald-600 text-white font-extrabold px-4 py-2">
                            <?= h((string) ($advisory['Gradelevel'] ?? '')) ?> — <?= h((string) ($advisory['Section_name'] ?? '')) ?>
                        </span>
                        <span class="text-sm text-slate-500"><?= number_format((int) $advisory['student_count']) ?> student<?= (int) $advisory['student_count'] === 1 ? '' : 's' ?></span>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-slate-400">You are not assigned as an adviser this school year.</p>
                    <?php endif; ?>
                </section>

                <!-- Teaching load -->
                <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                        <h2 class="font-extrabold text-lg">Teaching Load</h2>
                        <a href="<?= h(app_url('teacher/classes.php')) ?>" class="text-sm font-bold text-emerald-700 hover:underline">View all →</a>
                    </div>
                    <?php if (!$classes): ?>
                    <div class="p-8 text-center text-slate-400">
                        <p class="font-semibold">No classes assigned to you for S.Y. <?= h($syLabel) ?>.</p>
                        <p class="text-sm mt-1">Please contact the Registrar if this is unexpected.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                <tr><th class="text-left px-6 py-3">Subject</th><th class="text-left">Grade &amp; Section</th><th class="text-center">Students</th><th class="text-center">Graded</th><th></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach (array_slice($classes, 0, 6) as $c):
                                $sc = (int) $c['student_count']; $gc = (int) $c['graded_count']; ?>
                                <tr class="hover:bg-emerald-50/30">
                                    <td class="px-6 py-3">
                                        <p class="font-bold"><?= h((string) ($c['Subject_name'] ?: '—')) ?></p>
                                        <?php if ($c['subject_code']): ?><p class="text-xs text-slate-400"><?= h((string) $c['subject_code']) ?></p><?php endif; ?>
                                    </td>
                                    <td class="text-slate-600"><?= h((string) ($c['Gradelevel'] ?? '')) ?> — <?= h((string) ($c['Section_name'] ?? '')) ?></td>
                                    <td class="text-center"><?= number_format($sc) ?></td>
                                    <td class="text-center">
                                        <span class="text-xs font-bold rounded-full px-2 py-0.5 border <?= $sc > 0 && $gc >= $sc ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : ($gc > 0 ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-slate-100 text-slate-500 border-slate-300') ?>">
                                            <?= $gc ?>/<?= $sc ?>
                                        </span>
                                    </td>
                                    <td class="text-right px-6">
                                        <a href="<?= h(app_url('teacher/class_view.php?class_id=' . (int) $c['Class_id'])) ?>" class="font-bold text-emerald-700 hover:underline">Grades</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="space-y-6">
                <!-- Schedule -->
                <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="font-extrabold text-lg">Class Schedule</h2>
                        <a href="<?= h(app_url('teacher/schedule.php')) ?>" class="text-xs font-bold text-emerald-700 hover:underline">Full →</a>
                    </div>
                    <?php if (!$schedule): ?>
                    <p class="text-sm text-slate-400">No structured schedule has been set yet.</p>
                    <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($schedule, 0, 6) as $s): ?>
                        <div class="flex items-center gap-3 text-xs bg-slate-50 rounded-lg px-3 py-2 border border-slate-100">
                            <span class="font-bold text-emerald-700 w-10"><?= h(substr(teacher_day_name((int) $s['day_of_week']), 0, 3)) ?></span>
                            <span class="text-slate-500 w-24"><?= h(date('g:ia', strtotime((string) $s['start_time']))) ?>–<?= h(date('g:ia', strtotime((string) $s['end_time']))) ?></span>
                            <span class="font-semibold flex-1 truncate"><?= h((string) $s['Subject_name']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>

                <?php
                /* Announcements — a bulletin board, not a list.
                   Content is stored raw and MAY contain hostile markup: this table
                   already holds injected iframe and CSS-expression payloads posted
                   by "Anonymous*" users. Always strip_tags() then h(); never render
                   it raw. (Server-side comment on purpose — do not leak this note
                   into the page source.) */
                ?>
                <section class="rounded-3xl overflow-hidden shadow-panel border border-green-100 bg-white">
                    <div class="px-5 py-4 flex items-center gap-3" style="background:linear-gradient(135deg,#166534 0%,#7c3aed 100%)">
                        <span class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                        </span>
                        <div>
                            <h2 class="font-extrabold text-white leading-tight">Announcements</h2>
                            <p class="text-[11px] text-green-100">School bulletin</p>
                        </div>
                        <?php if ($announce): ?>
                        <span class="ml-auto text-[10px] font-extrabold bg-white/25 text-white rounded-full px-2.5 py-1"><?= count($announce) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$announce): ?>
                    <div class="p-6 text-center">
                        <p class="text-3xl mb-1">📭</p>
                        <p class="text-sm font-bold text-slate-500">No announcements yet</p>
                        <p class="text-xs text-slate-400 mt-0.5">School news will appear here.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($announce as $i => $a):
                            $accents = ['#166534', '#059669', '#f59e0b', '#0284c7'];
                            $accent  = $accents[$i % count($accents)];
                            $clean   = trim(preg_replace('/\s+/', ' ', strip_tags((string) $a['content'])) ?? '');
                            $isNew   = (time() - strtotime((string) $a['created_at'])) < 604800;   // 7 days
                        ?>
                        <article class="p-4 hover:bg-slate-50/70 transition-colors">
                            <div class="flex gap-3">
                                <span class="w-1.5 rounded-full shrink-0" style="background:<?= $accent ?>"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start gap-2">
                                        <p class="font-bold text-sm text-slate-800 leading-snug flex-1">
                                            <?= h((string) ($a['title'] ?: 'School Announcement')) ?>
                                        </p>
                                        <?php if ($isNew): ?>
                                        <span class="shrink-0 text-[9px] font-extrabold text-white rounded-full px-2 py-0.5" style="background:#e11d48">NEW</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-slate-600 mt-1 leading-relaxed">
                                        <?= h(mb_strimwidth($clean, 0, 150, '…')) ?>
                                    </p>
                                    <div class="flex items-center gap-2 mt-2 text-[11px] text-slate-400">
                                        <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-600 font-extrabold text-[8px] flex items-center justify-center">
                                            <?= h(strtoupper(mb_substr((string) $a['username'], 0, 2))) ?>
                                        </span>
                                        <span class="truncate"><?= h((string) $a['username']) ?></span>
                                        <span>·</span>
                                        <span class="shrink-0"><?= h(msg_when((string) $a['created_at'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>

                <!-- Quick link to the staff room -->
                <a href="<?= h(app_url('teacher/messages.php')) ?>"
                   class="block rounded-3xl p-5 shadow-panel hover:-translate-y-0.5 transition-all"
                   style="background:linear-gradient(135deg,#059669 0%,#0284c7 100%)">
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="font-extrabold text-white leading-tight">Staff Room</p>
                            <p class="text-[11px] text-emerald-50">
                                <?= $unreadMsg > 0 ? $unreadMsg . ' unread message' . ($unreadMsg === 1 ? '' : 's') : 'Message a colleague' ?>
                            </p>
                        </div>
                        <?php if ($unreadMsg > 0): ?>
                        <span class="ml-auto text-xs font-extrabold bg-white text-emerald-700 rounded-full px-2.5 py-1"><?= $unreadMsg ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        </div>

        <?php endif; ?>
    </main>
</div>
</body>
</html>
