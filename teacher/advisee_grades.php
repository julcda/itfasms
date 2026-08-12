<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';

$teacher = require_teacher_login();

$db      = db();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syId    = (int) $sy['id'];
$syLabel = $sy['label'];

$advisory = teacher_advisory($db, $tid, $syId);

// ── Grading periods + selection ───────────────────────────────────────────────
$periods  = $advisory ? gp_for_sy($db, $syId) : [];
$periodId = to_int($_GET['period'] ?? 0);
if ($periodId <= 0 && $periods) {
    // default to the current period if flagged, else the first
    foreach ($periods as $p) {
        if ((int) ($p['is_current'] ?? 0) === 1) { $periodId = (int) $p['id']; break; }
    }
    if ($periodId <= 0) { $periodId = (int) $periods[0]['id']; }
}

$search   = trim((string) ($_GET['q'] ?? ''));
$advisees = $advisory ? teacher_advisees($db, $tid, $syId, $search) : [];
$matrix   = $advisory ? teacher_advisory_grades($db, (int) $advisory['section_id'], $syId, $periodId) : ['columns' => [], 'grades' => []];
$columns  = $matrix['columns'];
$gradeMap = $matrix['grades'];
$visible  = ADVISORY_VISIBLE_GRADE_STATUSES;

/** Resolve a visible numeric grade for a cell, or null if not yet submitted. */
$cellGrade = function (int $sid, int $classId) use ($gradeMap, $visible): ?float {
    $c = $gradeMap[$sid][$classId] ?? null;
    if ($c && $c['grade'] !== null && in_array($c['status'], $visible, true)) {
        return (float) $c['grade'];
    }
    return null;
};

$flash = flash_get();
$_page = 'advisee_grades.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advisee Grades | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(5,150,105,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Teacher · Advisory</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Advisee Grades</h1>
                    <p class="text-slate-500 mt-1 text-sm">Monitor your advisees&rsquo; grades across all subjects. A grade appears once the subject teacher has <b>submitted</b> it.</p>
                    <p class="text-xs text-emerald-700 mt-2">
                        <?php if ($advisory): ?>
                        Advisory: <b><?= h((string) ($advisory['Gradelevel'] ?? '')) ?> — <?= h((string) ($advisory['Section_name'] ?? '')) ?></b> · S.Y. <?= h($syLabel) ?>
                        <?php else: ?>
                        S.Y. <?= h($syLabel) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="<?= h(app_url('teacher/advisees.php')) ?>" class="rounded-xl bg-white border border-slate-200 text-slate-700 text-sm font-bold px-4 py-2.5 hover:bg-slate-50">← Advisee Info</a>
            </div>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$advisory): ?>
        <div class="bg-white rounded-3xl border border-amber-200 shadow-panel p-10 text-center">
            <p class="font-semibold text-slate-500">You are not assigned as a class adviser for S.Y. <?= h($syLabel) ?>.</p>
            <p class="text-sm mt-1">Advisee grade monitoring is only available to class advisers. Please contact your Department Head if this is unexpected.</p>
        </div>
        <?php else: ?>

        <!-- Controls -->
        <form method="GET" action="advisee_grades.php" class="bg-white rounded-2xl border border-slate-200 p-4 mb-5 shadow-sm flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Term</label>
                <select name="period" onchange="this.form.submit()" class="py-2 px-3 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <?php foreach ($periods as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $periodId === (int) $p['id'] ? 'selected' : '' ?>><?= h((string) ($p['name'] ?? ('Period ' . $p['term_no']))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Search advisee</label>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or LRN…" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400">
            </div>
            <div class="flex gap-2">
                <button class="py-2 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-xl">Apply</button>
                <a href="advisee_grades.php" class="py-2 px-4 border border-slate-200 bg-white text-sm font-semibold text-slate-600 rounded-xl">Clear</a>
            </div>
            <div class="ml-auto text-xs text-slate-500 self-center">
                <span class="inline-block w-3 h-3 rounded-sm bg-emerald-100 border border-emerald-300 align-middle"></span> passed &nbsp;
                <span class="inline-block w-3 h-3 rounded-sm bg-rose-100 border border-rose-300 align-middle"></span> below 75 &nbsp;
                <span class="text-slate-400">—</span> not yet submitted
            </div>
        </form>

        <?php if (!$advisees): ?>
        <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-10 text-center text-slate-400">
            <p class="font-semibold"><?= $search !== '' ? 'No advisees matched “' . h($search) . '”.' : 'No students are enrolled in your advisory section yet.' ?></p>
        </div>
        <?php else: ?>
        <!-- Grade matrix -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                            <th class="sticky left-0 z-10 bg-slate-50 text-left px-4 py-3 border-b border-slate-200 min-w-[220px]">Student</th>
                            <?php foreach ($columns as $col): ?>
                            <?php
                                $code = trim((string) $col['subject_code']);
                                $head = ($code !== '' && strtoupper($code) !== 'N/A')
                                      ? $code
                                      : mb_strimwidth((string) $col['Subject_name'], 0, 16, '…');
                            ?>
                            <th class="px-2 py-3 border-b border-slate-200 text-center whitespace-nowrap" title="<?= h((string) $col['Subject_name']) ?>">
                                <?= h($head) ?>
                            </th>
                            <?php endforeach; ?>
                            <th class="px-3 py-3 border-b border-slate-200 text-center bg-emerald-50 text-emerald-700">Avg</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($advisees as $i => $s): ?>
                        <?php
                            $sid = (int) $s['student_id'];
                            $name = trim($s['Lastname'] . ', ' . $s['Firstname'] . ' ' . (string) $s['Middlename']);
                            $vals = [];
                        ?>
                        <tr class="hover:bg-emerald-50/30">
                            <td class="sticky left-0 z-10 bg-white px-4 py-2.5 font-semibold text-slate-800 border-r border-slate-100">
                                <span class="text-slate-400 text-xs mr-1"><?= $i + 1 ?>.</span> <?= h($name) ?>
                                <span class="block text-[10px] text-slate-400 font-normal">LRN <?= h((string) $s['LRN_no']) ?></span>
                            </td>
                            <?php foreach ($columns as $col): ?>
                                <?php
                                    $g = $cellGrade($sid, (int) $col['Class_id']);
                                    if ($g !== null) { $vals[] = $g; }
                                    $cls = $g === null ? 'text-slate-300'
                                         : ($g < 75 ? 'bg-rose-50 text-rose-700 font-bold' : 'bg-emerald-50 text-emerald-700 font-bold');
                                ?>
                                <td class="px-2 py-2.5 text-center <?= $cls ?>"><?= $g === null ? '—' : rtrim(rtrim(number_format($g, 2), '0'), '.') ?></td>
                            <?php endforeach; ?>
                            <?php $avg = $vals ? array_sum($vals) / count($vals) : null; ?>
                            <td class="px-3 py-2.5 text-center font-extrabold bg-emerald-50/60 <?= $avg === null ? 'text-slate-300' : ($avg < 75 ? 'text-rose-700' : 'text-emerald-700') ?>">
                                <?= $avg === null ? '—' : number_format($avg, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3 border-t border-slate-100 text-xs text-slate-500 flex flex-wrap gap-4">
                <span><b><?= count($advisees) ?></b> advisee<?= count($advisees) !== 1 ? 's' : '' ?></span>
                <span><b><?= count($columns) ?></b> subject<?= count($columns) !== 1 ? 's' : '' ?></span>
                <span>“Avg” = average of submitted grades so far (not the official general average).</span>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
