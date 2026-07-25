<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/certificate_service.php';

$teacher = require_teacher_login();

$db      = db();
$user    = current_user();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];
$syId    = (int) $sy['id'];
$ready   = cert_schema_ready($db);

$advisory = $ready ? cert_adviser_section($db, $tid, $syId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('certificates.php');
    }
    $periodId = to_int($_POST['period'] ?? 0);
    try {
        if (!$advisory) {
            throw new RuntimeException('You are not assigned as a class adviser.');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'issue') {
            $period  = $periodId ? gp_get($db, $periodId) : null;
            $levels  = (array) ($_POST['level'] ?? []);
            $roster  = cert_advisory_students($db, (int) $advisory['section_id'], $syId, $periodId);
            $byId    = [];
            foreach ($roster as $r) { $byId[(int) $r['student_id']] = $r; }

            $issued = 0; $errors = [];
            foreach ($levels as $sid => $lvl) {
                $lvl = trim((string) $lvl);
                if ($lvl === '') { continue; }              // not selected
                $sid = (int) $sid;
                if (!isset($byId[$sid])) {
                    $errors[] = 'Student #' . $sid . ' is not in your advisory class.';
                    continue;
                }
                $st = $byId[$sid];
                try {
                    cert_issue($db, [
                        'student_id'         => $sid,
                        'student_name'       => $st['full_name'],
                        'lrn'                => $st['LRN_no'],
                        'grade_level'        => $st['Gradelevel'] ?? ($advisory['Gradelevel'] ?? ''),
                        'section_name'       => $st['Section_name'] ?? ($advisory['Section_name'] ?? ''),
                        'school_year_id'     => $syId,
                        'school_year'        => $syLabel,
                        'grading_period_id'  => $periodId,
                        'period_name'        => $period['name'] ?? null,
                        'honor_level'        => $lvl,
                        'general_average'    => $st['average'],
                        'adviser_teacher_id' => $tid,
                        'adviser_name'       => teacher_display_name($teacher),
                    ], $user);
                    $issued++;
                } catch (Throwable $e) {
                    $errors[] = $st['full_name'] . ': ' . $e->getMessage();
                }
            }
            flash_set($errors ? 'error' : 'success',
                $issued . ' certificate' . ($issued === 1 ? '' : 's') . ' prepared'
                . ($errors ? ' · ' . implode(' ', array_slice($errors, 0, 2)) : '')
                . ($issued > 0 && !$errors ? ' — waiting for the Department Head to publish them.' : ''));

        } elseif ($action === 'delete') {
            cert_delete_draft($db, to_int($_POST['cert_id'] ?? 0), $user);
            flash_set('success', 'Draft certificate removed.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('certificates.php?period=' . $periodId);
}

$periods  = $ready ? gp_for_sy($db, $syId) : [];
$current  = $ready ? gp_current($db, $syId) : null;
$periodId = to_int($_GET['period'] ?? 0) ?: (int) ($current['id'] ?? 0);
$period   = $periodId ? gp_get($db, $periodId) : null;

$students = ($advisory && $periodId)
    ? cert_advisory_students($db, (int) $advisory['section_id'], $syId, $periodId)
    : [];

$eligible = 0;
foreach ($students as $s) { if ($s['suggested'] !== null) { $eligible++; } }

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificates | ITFA Teacher</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Class Adviser</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Certificates of Recognition</h1>
            <p class="text-slate-500 mt-2">Award academic honors to students in your advisory class. Certificates become visible to students once the Department Head publishes them.</p>
            <?php if ($advisory): ?>
            <p class="text-xs text-emerald-700 mt-2 font-bold">Advisory: <?= h((string) ($advisory['Gradelevel'] ?? '')) ?> — <?= h((string) ($advisory['Section_name'] ?? '')) ?> · S.Y. <?= h($syLabel) ?></p>
            <?php endif; ?>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$ready): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Certificate tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/certificates.sql</code> first.</p>
        </div>

        <?php elseif (!$advisory): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-10 text-center">
            <p class="text-5xl mb-3">🎓</p>
            <h2 class="font-extrabold text-lg text-amber-800">You are not a class adviser</h2>
            <p class="text-sm text-slate-600 mt-2 max-w-md mx-auto">
                Only a class adviser can issue Certificates of Recognition. Ask your Department Head
                to assign you an advisory section for S.Y. <?= h($syLabel) ?>.
            </p>
        </div>

        <?php else: ?>

        <!-- Period tabs -->
        <div class="bg-white rounded-3xl border border-emerald-100 shadow-panel p-5 mb-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Grading Period</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($periods as $p): $on = (int) $p['id'] === $periodId; ?>
                <a href="certificates.php?period=<?= (int) $p['id'] ?>"
                   class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors <?= $on ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-400' ?>">
                    <?= h((string) $p['name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Honor bands reference -->
        <div class="grid grid-cols-3 gap-4 mb-6">
            <?php foreach (cert_honor_bands() as $b): ?>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4 text-center">
                <p class="text-xs font-extrabold uppercase tracking-wider <?= str_contains($b['level'],'Highest') ? 'text-amber-700' : (str_contains($b['level'],'High') ? 'text-violet-700' : 'text-sky-700') ?>"><?= h($b['level']) ?></p>
                <p class="text-lg font-extrabold text-slate-800 mt-1"><?= number_format($b['min'], 0) ?>–<?= number_format($b['max'], 0) ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="POST" action="certificates.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="issue">
            <input type="hidden" name="period" value="<?= $periodId ?>">

            <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-slate-100">
                    <div>
                        <h2 class="font-extrabold text-lg">Advisory Class</h2>
                        <p class="text-xs text-slate-500"><?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?> · <b class="text-emerald-700"><?= $eligible ?></b> qualify for honors</p>
                    </div>
                    <p class="text-xs text-slate-400">Averages use approved grades only.</p>
                </div>

                <?php if (!$students): ?>
                <div class="p-10 text-center text-slate-400"><p class="font-semibold">No students found in your advisory section.</p></div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="text-left px-6 py-3 w-10">#</th>
                                <th class="text-left">Student</th>
                                <th class="text-center">Average</th>
                                <th class="text-left">Qualifies for</th>
                                <th class="text-left w-56">Award</th>
                                <th class="text-center">Certificate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php foreach ($students as $i => $s):
                            $sid  = (int) $s['student_id'];
                            $has  = $s['cert_id'] !== null;
                            $cs   = (string) ($s['cert_status'] ?? '');
                            $lock = $has && $cs === 'Published';
                        ?>
                            <tr class="hover:bg-emerald-50/30 <?= $s['suggested'] ? 'bg-amber-50/30' : '' ?>">
                                <td class="px-6 py-2.5 text-slate-400"><?= $i + 1 ?></td>
                                <td>
                                    <p class="font-semibold"><?= h((string) $s['full_name']) ?></p>
                                    <p class="text-xs text-slate-400 font-mono"><?= h((string) ($s['LRN_no'] ?: '—')) ?></p>
                                </td>
                                <td class="text-center">
                                    <?php if ($s['average'] === null): ?>
                                        <span class="text-xs text-slate-400">—</span>
                                    <?php else: ?>
                                        <span class="font-extrabold <?= $s['average'] >= 90 ? 'text-emerald-700' : 'text-slate-700' ?>"><?= number_format((float) $s['average'], 2) ?></span>
                                        <?php if (!$s['complete']): ?>
                                        <span class="block text-[9px] text-amber-600 font-bold"><?= (int) $s['graded_subjects'] ?>/<?= (int) $s['total_subjects'] ?> subjects</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['suggested']): ?>
                                    <span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= cert_level_badge((string) $s['suggested']) ?>"><?= h((string) $s['suggested']) ?></span>
                                    <?php else: ?>
                                    <span class="text-xs text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($lock): ?>
                                    <span class="text-xs text-slate-400 italic">published — locked</span>
                                    <?php else: ?>
                                    <select name="level[<?= $sid ?>]" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                        <option value="">— no award —</option>
                                        <?php foreach (cert_levels() as $lv): ?>
                                        <option value="<?= h($lv) ?>" <?= ($s['cert_level'] ?? $s['suggested']) === $lv ? 'selected' : '' ?>><?= h($lv) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($has): ?>
                                    <span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= cert_status_badge($cs) ?>"><?= h($cs) ?></span>
                                    <p class="text-[9px] text-slate-400 font-mono mt-0.5"><?= h((string) $s['certificate_no']) ?></p>
                                    <?php else: ?>
                                    <span class="text-xs text-slate-300">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50/60">
                    <p class="text-xs text-slate-500">
                        The suggested level is pre-selected from the average — you may change it. Leave “no award” for students who should not receive one.
                    </p>
                    <button type="submit" onclick="return confirm('Prepare certificates for the selected students?');"
                            class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5">
                        🎖 Prepare Certificates
                    </button>
                </div>
                <?php endif; ?>
            </section>
        </form>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
