<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/grading_service.php';

require_login();

$connection = db();
$user       = current_user();

// Department Heads (role 'user'), the Principal ('admin'), and Super Admin.
if (!is_depthead_user($user) && !is_depthead_admin($user) && !is_super_admin($user)) {
    flash_set('error', 'Access denied. Department Head login required.');
    redirect_to(app_url(user_home_path($user)));
}

$sy                    = teacher_active_sy($connection);
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;
$ready                 = teacher_schema_ready($connection);

// ── POST: approve / return / save reviewer edits ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('grade_review.php');
    }
    $action   = (string) ($_POST['action'] ?? '');
    $classId  = to_int($_POST['class_id'] ?? 0);
    $periodId = to_int($_POST['period'] ?? 0);
    $back     = 'grade_review.php?class_id=' . $classId . '&period=' . $periodId;

    try {
        if (!$ready) {
            throw new RuntimeException('Run migrations/teacher_module.sql first.');
        }
        // AUTHORIZE FIRST — never trust the posted class_id.
        // publish/withdraw are PERIOD-scoped (no class): their authorization is
        // "may this user manage teachers/classes at all", and release_publish()
        // itself only ever touches rows where classes.user_id = this user, so a
        // head can never publish another department's results.
        if (in_array($action, ['publish', 'withdraw'], true)) {
            if (!teacher_can_manage($user)) {
                throw new RuntimeException('You are not allowed to publish grade slips.');
            }
        } else {
            require_review_rights($connection, $classId, $user);
        }

        if ($action === 'approve') {
            $n = review_approve_class($connection, $classId, $periodId, $user);
            flash_set('success', $n > 0
                ? $n . ' grade' . ($n === 1 ? '' : 's') . ' approved. The teacher can no longer edit them.'
                : 'Nothing to approve — no grades are awaiting review in this class.');
            redirect_to('grade_review.php?period=' . $periodId);
        } elseif ($action === 'return') {
            $n = review_return_class($connection, $classId, $periodId, (string) ($_POST['reason'] ?? ''), $user);
            flash_set('success', $n . ' grade' . ($n === 1 ? '' : 's') . ' returned to the teacher for correction.');
            redirect_to('grade_review.php?period=' . $periodId);
        } elseif ($action === 'publish') {
            $n = release_publish($connection, $periodId, $user, (string) ($_POST['note'] ?? ''));
            flash_set($n > 0 ? 'success' : 'error', $n > 0
                ? 'Grade slips published — ' . number_format($n) . ' student' . ($n === 1 ? '' : 's')
                  . ' in your department can now view their grade slip in the Student Portal.'
                : 'Nothing to publish yet: no grades in this period have been approved. Approve a class first.');
            redirect_to('grade_review.php?period=' . $periodId);
        } elseif ($action === 'withdraw') {
            release_withdraw($connection, $periodId, $user, (string) ($_POST['note'] ?? ''));
            flash_set('success', 'Grade slips withdrawn — students can no longer view them.');
            redirect_to('grade_review.php?period=' . $periodId);
        } elseif ($action === 'save_edits') {
            $saved = 0; $errors = [];
            foreach ((array) ($_POST['grade'] ?? []) as $sid => $raw) {
                $raw = trim((string) $raw);
                if ($raw !== '' && !is_numeric($raw)) { $errors[] = 'Invalid number for student #' . (int) $sid . '.'; continue; }
                try {
                    if (grade_save_reviewer($connection, $classId, (int) $sid, $periodId, $raw === '' ? null : (float) $raw, $user) === 'saved') { $saved++; }
                } catch (Throwable $e) { $errors[] = $e->getMessage(); }
            }
            flash_set($errors ? 'error' : 'success',
                $errors ? ('Saved ' . $saved . ', but: ' . implode(' ', array_slice($errors, 0, 2)))
                        : ($saved . ' grade' . ($saved === 1 ? '' : 's') . ' updated by you. The change is recorded in each student\'s history.'));
            redirect_to($back);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to($back);
    }
}

$periods  = $ready ? gp_for_sy($connection, $sy['id']) : [];
$current  = $ready ? gp_current($connection, $sy['id']) : null;
$periodId = to_int($_GET['period'] ?? 0) ?: (int) ($current['id'] ?? 0);
$period   = $periodId ? gp_get($connection, $periodId) : null;
$classId  = to_int($_GET['class_id'] ?? 0);

$detail = null; $roster = [];
if ($ready && $classId > 0) {
    require_review_rights($connection, $classId, $user);
    $detail = teacher_class_get($connection, $classId);
    $roster = grade_roster($connection, $classId, $periodId);
}

$queue = ($ready && !$classId) ? review_queue($connection, $user, $sy['id'], $periodId) : [];
$stats = ($ready && !$classId) ? review_stats($connection, $user, $sy['id'], $periodId) : null;
$flash = flash_get();
$csrf  = csrf_token();
$periodLocked = $period && (string) $period['status'] === 'Locked';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grade Review | ITFA Department Head</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <?php if ($classId): ?><a href="grade_review.php?period=<?= $periodId ?>" class="text-sm text-slate-500 hover:underline">← Review queue</a><?php endif; ?>

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 <?= $classId ? 'mt-3' : '' ?> mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Department Head · Academics</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">
                <?= $classId ? h((string) ($detail['Subject_name'] ?: 'Class')) : 'Grade Review' ?>
            </h1>
            <?php if ($classId): ?>
            <p class="text-slate-500 mt-1 text-sm">
                <?= h((string) ($detail['Gradelevel'] ?? '')) ?> — <?= h((string) ($detail['Section_name'] ?? '')) ?>
                · S.Y. <?= h((string) ($detail['School_year'] ?? $syLabel)) ?>
            </p>
            <?php else: ?>
            <p class="text-slate-500 mt-2">Review grades submitted by teachers in your department. Approve them, or return them with a reason for correction.</p>
            <p class="text-xs text-green-700 mt-2">
                S.Y. <?= h($syLabel) ?>
                <?= is_super_admin($user) ? ' · viewing ALL departments (Super Admin)' : ' · your department only' ?>
            </p>
            <?php endif; ?>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$ready): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Teacher module tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/teacher_module.sql</code> then <code>migrations/grade_review.sql</code>.</p>
        </div>
        <?php else: ?>

        <!-- Period selector -->
        <div class="bg-white rounded-3xl border border-green-100 shadow-panel p-5 mb-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Grading Period</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($periods as $p): $on = (int) $p['id'] === $periodId; ?>
                <a href="grade_review.php?period=<?= (int) $p['id'] ?><?= $classId ? '&class_id=' . $classId : '' ?>"
                   class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors <?= $on ? 'bg-green-700 border-green-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-green-400' ?>">
                    <?= h((string) $p['name']) ?>
                    <span class="ml-1 text-[10px] font-extrabold uppercase <?= $on ? 'text-green-200' : 'text-slate-400' ?>"><?= h((string) $p['status']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if ($periodLocked): ?>
            <p class="text-xs text-rose-700 mt-2 font-semibold">⚠ This period is locked by the Registrar — review actions are disabled.</p>
            <?php endif; ?>
        </div>

        <?php if (!$classId): ?>
        <!-- ══ QUEUE ══ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="rounded-2xl bg-white border border-green-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-green-600 font-bold">Awaiting Review</p>
                <p class="text-2xl font-extrabold text-green-700 mt-1"><?= number_format($stats['awaiting']) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-emerald-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-emerald-600 font-bold">Approved</p>
                <p class="text-2xl font-extrabold text-emerald-700 mt-1"><?= number_format($stats['approved']) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-amber-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-amber-600 font-bold">Returned</p>
                <p class="text-2xl font-extrabold text-amber-700 mt-1"><?= number_format($stats['returned']) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">My Classes</p>
                <p class="text-2xl font-extrabold text-slate-700 mt-1"><?= number_format($stats['classes']) ?></p>
            </div>
        </div>

        <?php
        // ── Publish grade slips to students ──
        $rel       = $period ? release_get($connection, $periodId, (int) $user['id']) : null;
        $published = release_is_active($rel);
        ?>
        <?php if ($period): ?>
        <section class="rounded-3xl shadow-panel overflow-hidden mb-6 <?= $published ? 'bg-emerald-50 border border-emerald-300' : 'bg-white border border-green-100' ?>">
            <div class="p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex gap-3">
                        <span class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 <?= $published ? 'bg-emerald-600' : 'bg-green-100' ?>">
                            <svg class="w-6 h-6 <?= $published ? 'text-white' : 'text-green-600' ?>" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </span>
                        <div>
                            <h2 class="font-extrabold text-lg <?= $published ? 'text-emerald-900' : '' ?>">
                                <?= $published ? '✓ Grade slips are published' : 'Publish grade slips to students' ?>
                            </h2>
                            <p class="text-sm mt-0.5 <?= $published ? 'text-emerald-800' : 'text-slate-500' ?>">
                                <?php if ($published): ?>
                                    Students in your department can view their <?= h((string) $period['name']) ?> grade slip in the Student Portal.
                                    <?php if ($rel['released_at']): ?>
                                    <span class="block text-xs mt-0.5">Published <?= h(date('M j, Y g:ia', strtotime((string) $rel['released_at']))) ?> by <?= h((string) ($rel['released_by_name'] ?: '—')) ?>.</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Once published, students can view and print their own <?= h((string) $period['name']) ?> grade slip.
                                    <span class="block text-xs text-slate-400 mt-0.5">Only <b>approved</b> grades are shown — work in progress is never revealed.</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <form method="POST" action="grade_review.php" class="shrink-0"
                          onsubmit="return confirm('<?= $published
                              ? 'Withdraw the published grade slips? Students will no longer be able to view them.'
                              : 'Publish grade slips for ' . h(addslashes((string) $period['name'])) . '?\n\nEvery student in your department with approved grades will be able to view and print their slip.' ?>');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="<?= $published ? 'withdraw' : 'publish' ?>">
                        <input type="hidden" name="period" value="<?= $periodId ?>">
                        <button class="rounded-xl text-white text-sm font-bold px-6 py-3 <?= $published ? 'bg-slate-600 hover:bg-slate-700' : 'bg-emerald-600 hover:bg-emerald-700' ?>">
                            <?= $published ? 'Withdraw' : '📤 Publish Grade Slips' ?>
                        </button>
                    </form>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
            <?php if (!$queue): ?>
            <div class="p-10 text-center text-slate-400">
                <p class="font-semibold">No classes are assigned to your department for S.Y. <?= h($syLabel) ?>.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3">Subject</th>
                            <th class="text-left">Grade &amp; Section</th>
                            <th class="text-left">Teacher</th>
                            <th class="text-center">Encoded</th>
                            <th class="text-center">State</th>
                            <th class="text-right px-6">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($queue as $q):
                        $enc = (int) $q['encoded']; $tot = (int) $q['student_count']; ?>
                        <tr class="hover:bg-green-50/30 <?= $q['review_state'] === 'Awaiting Review' ? 'bg-green-50/20' : '' ?>">
                            <td class="px-6 py-3 font-bold"><?= h((string) ($q['Subject_name'] ?: '—')) ?></td>
                            <td class="text-slate-600"><?= h((string) ($q['Gradelevel'] ?? '')) ?> — <?= h((string) ($q['Section_name'] ?? '')) ?></td>
                            <td class="text-slate-600"><?= h((string) $q['teacher_display']) ?></td>
                            <td class="text-center"><?= $enc ?><span class="text-slate-300">/</span><?= $tot ?></td>
                            <td class="text-center">
                                <span class="text-[10px] font-extrabold rounded-full px-2.5 py-0.5 border <?= grade_state_badge((string) $q['review_state']) ?>"><?= h((string) $q['review_state']) ?></span>
                            </td>
                            <td class="text-right px-6">
                                <a href="grade_review.php?class_id=<?= (int) $q['Class_id'] ?>&period=<?= $periodId ?>"
                                   class="font-bold text-green-700 hover:underline"><?= $q['review_state'] === 'Awaiting Review' ? 'Review' : 'Open' ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <?php else: ?>
        <!-- ══ CLASS DETAIL ══ -->
        <?php
        $sub = 0; $app = 0; $ret = 0;
        foreach ($roster as $r) {
            $st = (string) ($r['grade_status'] ?? '');
            if ($st === 'Submitted') { $sub++; } elseif ($st === 'Approved') { $app++; } elseif ($st === 'Returned') { $ret++; }
        }
        $note = '';
        foreach ($roster as $r) { if (!empty($r['review_note'])) { $note = (string) $r['review_note']; break; } }
        ?>

        <?php if ($ret > 0 && $note): ?>
        <div class="mb-5 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4 text-sm text-amber-900">
            <b>Returned to the teacher.</b> Reason given: “<?= h($note) ?>”
        </div>
        <?php endif; ?>

        <form method="POST" action="grade_review.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="class_id" value="<?= $classId ?>">
            <input type="hidden" name="period" value="<?= $periodId ?>">
            <input type="hidden" name="action" value="save_edits">

            <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-slate-100">
                    <p class="text-sm text-slate-500">
                        <b class="text-green-700"><?= $sub ?></b> awaiting review ·
                        <b class="text-emerald-700"><?= $app ?></b> approved ·
                        <b class="text-amber-700"><?= $ret ?></b> returned
                    </p>
                    <p class="text-xs text-slate-400">You may correct a grade directly — it is logged as your change.</p>
                </div>

                <?php if (!$roster): ?>
                <div class="p-10 text-center text-slate-400"><p class="font-semibold">No students enrolled in this class.</p></div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="text-left px-6 py-3 w-10">#</th>
                                <th class="text-left">LRN</th>
                                <th class="text-left">Student Name</th>
                                <th class="text-center w-32">Grade</th>
                                <th class="text-center">State</th>
                                <th class="text-right px-6">History</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php foreach ($roster as $i => $r):
                            $sid  = (int) $r['student_id'];
                            $val  = $r['grade'] !== null ? number_format((float) $r['grade'], 2, '.', '') : '';
                            $st   = (string) ($r['grade_status'] ?? '');
                            $lock = $st === 'Locked' || $periodLocked;
                            $name = trim((string) $r['Lastname'] . ', ' . (string) $r['Firstname'] . ' ' . (string) ($r['Middlename'] ?? ''));
                        ?>
                            <tr class="hover:bg-green-50/30">
                                <td class="px-6 py-2 text-slate-400"><?= $i + 1 ?></td>
                                <td class="font-mono text-xs text-slate-500"><?= h((string) ($r['LRN_no'] ?: '—')) ?></td>
                                <td class="font-semibold"><?= h($name) ?></td>
                                <td class="text-center">
                                    <input type="number" step="0.01" min="0" max="100" name="grade[<?= $sid ?>]" value="<?= h($val) ?>"
                                           <?= $lock ? 'disabled' : '' ?> placeholder="—"
                                           class="w-24 text-center rounded-lg border px-2 py-1.5 text-sm font-bold <?= $lock ? 'border-slate-200 bg-slate-50 text-slate-400' : 'border-slate-300 focus:ring-2 focus:ring-green-500 focus:border-green-500' ?>">
                                </td>
                                <td class="text-center">
                                    <?php if ($st === ''): ?><span class="text-[10px] text-slate-400 font-bold">—</span>
                                    <?php else: ?>
                                    <span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= grade_state_badge($st) ?>"><?= h($st) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right px-6">
                                    <a href="<?= h(app_url('depthead/grade_review.php?class_id=' . $classId . '&period=' . $periodId)) ?>#s<?= $sid ?>"
                                       onclick="toggleHist(<?= $sid ?>);return false;" class="text-xs font-bold text-green-700 hover:underline">View</a>
                                </td>
                            </tr>
                            <tr id="h<?= $sid ?>" class="hidden bg-slate-50/70">
                                <td colspan="6" class="px-6 py-3">
                                    <?php $hist = grade_history($connection, $classId, $sid, $periodId); ?>
                                    <?php if (!$hist): ?>
                                    <p class="text-xs text-slate-400">No changes recorded.</p>
                                    <?php else: ?>
                                    <table class="w-full text-xs">
                                        <thead class="text-slate-400"><tr><th class="text-left py-1">When</th><th class="text-left">Action</th><th class="text-center">Old</th><th class="text-center">New</th><th class="text-left">By</th></tr></thead>
                                        <tbody>
                                        <?php foreach (array_slice($hist, 0, 6) as $hh): ?>
                                            <tr class="border-t border-slate-200">
                                                <td class="py-1"><?= h(date('M j, g:ia', strtotime((string) $hh['changed_at']))) ?></td>
                                                <td><span class="font-bold"><?= h((string) $hh['action']) ?></span></td>
                                                <td class="text-center text-slate-500"><?= $hh['old_grade'] === null ? '—' : number_format((float) $hh['old_grade'], 2) ?></td>
                                                <td class="text-center font-bold"><?= $hh['new_grade'] === null ? '—' : number_format((float) $hh['new_grade'], 2) ?></td>
                                                <td><?= h((string) ($hh['changed_by_name'] ?: '—')) ?><?= $hh['note'] ? ' · ' . h((string) $hh['note']) : '' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!$periodLocked): ?>
                <div class="flex flex-wrap items-center justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/60">
                    <button type="submit" class="rounded-xl bg-slate-700 hover:bg-slate-800 text-white text-sm font-bold px-5 py-2.5">Save My Edits</button>
                    <button type="button" onclick="document.getElementById('retBox').classList.toggle('hidden')"
                            class="rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold px-5 py-2.5">Return to Teacher</button>
                    <button type="submit" form="approveForm"
                            onclick="return confirm('Approve <?= $sub ?> submitted grade(s)? The teacher will no longer be able to edit them.');"
                            class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">✓ Approve Class</button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </section>
        </form>

        <?php if (!$periodLocked): ?>
        <!-- Return with a reason -->
        <div id="retBox" class="hidden mt-4 bg-white rounded-3xl border border-amber-300 shadow-panel p-6">
            <h3 class="font-extrabold mb-2">Return these grades to the teacher</h3>
            <p class="text-xs text-slate-500 mb-3">The grades become editable again and the teacher sees your reason. This is recorded in every affected student's history.</p>
            <form method="POST" action="grade_review.php" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="class_id" value="<?= $classId ?>">
                <input type="hidden" name="period" value="<?= $periodId ?>">
                <input type="hidden" name="action" value="return">
                <div class="flex-1 min-w-[260px]">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Reason <span class="text-rose-600">*</span></label>
                    <input name="reason" required maxlength="255" placeholder="e.g. Missing grades for 3 students; please complete and resubmit."
                           class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                </div>
                <button class="rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold px-5 py-2.5">Return</button>
            </form>
        </div>

        <form method="POST" action="grade_review.php" id="approveForm" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="class_id" value="<?= $classId ?>">
            <input type="hidden" name="period" value="<?= $periodId ?>">
            <input type="hidden" name="action" value="approve">
        </form>
        <?php endif; ?>

        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script>
function toggleHist(sid) { document.getElementById('h' + sid).classList.toggle('hidden'); }
</script>
</body>
</html>
