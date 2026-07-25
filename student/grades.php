<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';

$account = require_student_login();
$db      = db();
$sess    = current_student();

$enrollmentId = (int) $sess['enrollment_id'];
$profile      = student_profile($db, $enrollmentId);
if (!$profile) {
    student_logout();
    redirect_to(app_url('student/login.php'));
}

$sy = student_active_sy($db);

// Map the portal login to the academic record that carries grades.
// false = never create a row on a read-only page.
$studentInfoId = resolve_studentinfo_id_for_enrollment($db, $enrollmentId, false);

$periods  = release_schema_ready($db) ? student_released_periods($db, $sy['id']) : [];
$periodId = to_int($_GET['p'] ?? 0);
if ($periodId > 0) {
    $ok = false;
    foreach ($periods as $p) { if ((int) $p['id'] === $periodId) { $ok = true; break; } }
    if (!$ok) { $periodId = 0; }        // never trust the URL
}
if ($periodId === 0 && $periods) {
    $periodId = (int) $periods[count($periods) - 1]['id'];   // newest released
}

$period = $periodId ? gp_get($db, $periodId) : null;
$slip   = ($studentInfoId > 0 && $periodId > 0) ? student_grade_slip($db, $studentInfoId, $periodId) : null;

$fullName = trim((string) $profile['firstname'] . ' '
          . ((string) ($profile['middlename'] ?? '') !== '' ? mb_substr((string) $profile['middlename'], 0, 1) . '. ' : '')
          . (string) $profile['surname']);
$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Grades | Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Student Portal</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">My Grades</h1>
            <p class="text-slate-500 mt-1 text-sm">S.Y. <?= h($sy['label']) ?> · <?= h($fullName) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$periods || !$slip || !$slip['released']): ?>
        <!-- Nothing published for this student yet -->
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-10 text-center">
            <p class="text-5xl mb-3">🔒</p>
            <h2 class="font-extrabold text-lg text-amber-800">Your grades are not yet released</h2>
            <p class="text-sm text-slate-600 mt-2 max-w-md mx-auto">
                Your grade slip becomes available here once your Department Head has reviewed and
                published the results for the grading period. Please check back later.
            </p>
        </div>

        <?php else: ?>

        <!-- Grading period tabs (released periods only) -->
        <?php if (count($periods) > 1): ?>
        <div class="bg-white rounded-3xl border border-green-100 shadow-panel p-5 mb-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Grading Period</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($periods as $p): $on = (int) $p['id'] === $periodId; ?>
                <a href="grades.php?p=<?= (int) $p['id'] ?>"
                   class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors <?= $on ? 'bg-green-700 border-green-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-green-400' ?>">
                    <?= h((string) $p['name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary tiles -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Grading Period</p>
                <p class="text-lg font-extrabold text-slate-800 mt-1"><?= h((string) ($period['name'] ?? '—')) ?></p>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Subjects</p>
                <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= (int) $slip['graded'] ?><span class="text-sm text-slate-300">/<?= (int) $slip['subjects'] ?></span></p>
            </div>
            <?php
            $avg = $slip['average'];
            $avgColor = $avg === null ? '#64748b' : ($avg >= 90 ? '#059669' : ($avg >= GRADE_PASSING ? '#166534' : '#e11d48'));
            ?>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">General Average</p>
                <p class="text-3xl font-extrabold mt-1" style="color:<?= $avgColor ?>"><?= $avg === null ? '—' : number_format($avg, 2) ?></p>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Remarks</p>
                <p class="text-sm font-extrabold text-slate-800 mt-2"><?= h(grade_descriptor($avg)) ?></p>
            </div>
        </div>

        <!-- The grade slip -->
        <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-slate-100">
                <div>
                    <h2 class="font-extrabold text-lg">Grade Slip</h2>
                    <?php if ($slip['released_at']): ?>
                    <p class="text-xs text-slate-400">Released <?= h(date('M j, Y', strtotime((string) $slip['released_at']))) ?></p>
                    <?php endif; ?>
                </div>
                <a href="<?= h(app_url('student/grade_slip.php?p=' . $periodId)) ?>" target="_blank"
                   class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">🖨 Print Grade Slip</a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3 w-10">#</th>
                            <th class="text-left">Subject</th>
                            <th class="text-left">Teacher</th>
                            <th class="text-center w-24">Grade</th>
                            <th class="text-left w-40">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($slip['rows'] as $i => $r): ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-6 py-3 text-slate-400"><?= $i + 1 ?></td>
                            <td class="font-semibold"><?= h($r['subject']) ?><?php if ($r['code']): ?><span class="block text-xs text-slate-400"><?= h($r['code']) ?></span><?php endif; ?></td>
                            <td class="text-slate-500 text-xs"><?= h($r['teacher']) ?></td>
                            <td class="text-center">
                                <?php if ($r['pending']): ?>
                                    <span class="text-xs text-slate-400">—</span>
                                <?php else: ?>
                                    <span class="text-base font-extrabold" style="color:<?= $r['grade'] >= GRADE_PASSING ? '#059669' : '#e11d48' ?>"><?= number_format((float) $r['grade'], 2) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['pending']): ?>
                                    <span class="text-[10px] font-bold rounded-full px-2 py-0.5 border bg-slate-100 text-slate-500 border-slate-300">Not yet released</span>
                                <?php else: ?>
                                    <span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= $r['grade'] >= GRADE_PASSING ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-rose-100 text-rose-800 border-rose-300' ?>"><?= h((string) $r['remarks']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-green-50/60">
                        <tr>
                            <td colspan="3" class="px-6 py-3 text-right font-extrabold text-slate-700">GENERAL AVERAGE</td>
                            <td class="text-center text-lg font-extrabold" style="color:<?= $avgColor ?>"><?= $avg === null ? '—' : number_format($avg, 2) ?></td>
                            <td class="font-bold text-slate-700"><?= h(grade_descriptor($avg)) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!$slip['complete']): ?>
            <div class="px-6 py-3 bg-amber-50 border-t border-amber-200 text-xs text-amber-800">
                ⓘ Some subjects are still being finalized. The general average shown is based on the
                <?= (int) $slip['graded'] ?> subject<?= $slip['graded'] === 1 ? '' : 's' ?> released so far.
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
