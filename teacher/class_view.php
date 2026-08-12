<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';

$teacher = require_teacher_login();

$db  = db();
$user = current_user();
$tid = (int) $teacher['Teacher_id'];
$sy  = teacher_active_sy($db);
$syLabel = $sy['label'];

$classId = to_int($_GET['class_id'] ?? $_POST['class_id'] ?? 0);

// ── AUTHORIZE FIRST — never trust the URL. ───────────────────────────────────
require_class_ownership($db, $classId, $tid);

$class = teacher_class_get($db, $classId);

// ── POST: save the grid / submit ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('class_view.php?class_id=' . $classId);
    }
    $postPeriod = to_int($_POST['period'] ?? 0);
    $action     = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_grades') {
            $res = grade_bulk_save($db, $classId, $postPeriod, (array) ($_POST['grade'] ?? []), $tid, $user);
            if ($res['errors']) {
                flash_set('error', 'Saved ' . $res['saved'] . ', but: ' . implode(' ', array_slice($res['errors'], 0, 3)));
            } else {
                flash_set('success', $res['saved'] . ' grade' . ($res['saved'] === 1 ? '' : 's') . ' saved'
                    . ($res['unchanged'] ? ' · ' . $res['unchanged'] . ' unchanged' : '') . '.');
            }
        } elseif ($action === 'submit') {
            $n = grade_submit_class($db, $classId, $postPeriod, $tid, $user);
            flash_set('success', $n . ' grade' . ($n === 1 ? '' : 's') . ' submitted for review.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('class_view.php?class_id=' . $classId . '&period=' . $postPeriod);
}

$periods  = gp_for_sy($db, (int) $class['School_year_id']);
$current  = gp_current($db, (int) $class['School_year_id']);
$periodId = to_int($_GET['period'] ?? 0) ?: (int) ($current['id'] ?? 0);
$period   = $periodId ? gp_get($db, $periodId) : null;
if ($period && (int) $period['school_year_id'] !== (int) $class['School_year_id']) {
    $period = $current; $periodId = (int) ($current['id'] ?? 0);
}

$roster    = grade_roster($db, $classId, $periodId);
$classOpen = (string) ($class['class_status'] ?? 'Open') === 'Open';
$canEdit   = $classOpen && gp_is_open($period);
$flash     = flash_get();
$csrf      = csrf_token();

$graded = 0;
$returnedNote = '';
$nApproved = 0; $nReturned = 0; $nSubmitted = 0;
foreach ($roster as $r) {
    if ($r['grade'] !== null) { $graded++; }
    $st = (string) ($r['grade_status'] ?? '');
    if ($st === 'Approved')  { $nApproved++; }
    if ($st === 'Submitted') { $nSubmitted++; }
    if ($st === 'Returned')  {
        $nReturned++;
        if ($returnedNote === '' && !empty($r['review_note'])) { $returnedNote = (string) $r['review_note']; }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h((string) ($class['Subject_name'] ?: 'Class')) ?> | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(5,150,105,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <a href="<?= h(app_url('teacher/classes.php')) ?>" class="text-sm text-slate-500 hover:underline">← My Classes</a>

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mt-3 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Class Roster</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1"><?= h((string) ($class['Subject_name'] ?: '—')) ?></h1>
                    <p class="text-slate-500 mt-1 text-sm">
                        <?= h((string) ($class['Gradelevel'] ?? '')) ?> — <?= h((string) ($class['Section_name'] ?? '')) ?>
                        · S.Y. <?= h((string) ($class['School_year'] ?? $syLabel)) ?>
                        <?php if ($class['Time']): ?> · <?= h((string) $class['Time']) ?><?php endif; ?>
                        <?php if ($class['room_name']): ?> · Room <?= h((string) $class['room_name']) ?><?php endif; ?>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">Progress</p>
                    <p class="text-2xl font-extrabold <?= $graded >= count($roster) && $roster ? 'text-emerald-600' : 'text-amber-600' ?>">
                        <?= $graded ?><span class="text-slate-300">/</span><?= count($roster) ?>
                    </p>
                </div>
            </div>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <!-- Period tabs -->
        <div class="bg-white rounded-3xl border border-emerald-100 shadow-panel p-5 mb-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Term</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($periods as $p): $on = (int) $p['id'] === $periodId; ?>
                <a href="class_view.php?class_id=<?= $classId ?>&period=<?= (int) $p['id'] ?>"
                   class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors <?= $on ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-400' ?>">
                    <?= h((string) $p['name']) ?>
                    <span class="ml-1 text-[10px] font-extrabold uppercase <?= $on ? 'text-emerald-100' : 'text-slate-400' ?>"><?= h((string) $p['status']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($nReturned > 0): ?>
        <div class="mb-5 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4">
            <p class="font-bold text-amber-900">⚠ The Department Head returned these grades for correction.</p>
            <?php if ($returnedNote !== ''): ?>
            <p class="text-sm text-amber-800 mt-1">Reason: “<?= h($returnedNote) ?>”</p>
            <?php endif; ?>
            <p class="text-xs text-amber-700 mt-1">Please correct them below and <b>Submit for Review</b> again.</p>
        </div>
        <?php endif; ?>

        <?php if ($nApproved > 0): ?>
        <div class="mb-5 rounded-2xl bg-emerald-50 border border-emerald-300 px-5 py-4 text-sm text-emerald-900">
            <b>✓ <?= $nApproved ?> grade<?= $nApproved === 1 ? '' : 's' ?> approved by the Department Head.</b>
            Approved grades can no longer be edited. Contact your Department Head if a correction is needed.
        </div>
        <?php elseif ($nSubmitted > 0): ?>
        <div class="mb-5 rounded-2xl bg-green-50 border border-green-300 px-5 py-4 text-sm text-green-900">
            <b><?= $nSubmitted ?> grade<?= $nSubmitted === 1 ? '' : 's' ?> submitted and awaiting Department Head review.</b>
            You can still edit them until they are approved or the period is locked.
        </div>
        <?php endif; ?>

        <?php if (!$canEdit): ?>
        <div class="mb-5 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4 text-sm text-amber-900">
            <b>View only.</b>
            <?php if (!$classOpen): ?>
                This class is closed.
            <?php elseif ($period): ?>
                <?= h((string) $period['name']) ?> is <?= h(strtolower((string) $period['status'])) ?> — contact the Registrar to reopen it.
            <?php else: ?>
                No grading period is available.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="class_view.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="class_id" value="<?= $classId ?>">
            <input type="hidden" name="period" value="<?= $periodId ?>">
            <input type="hidden" name="action" value="save_grades">

            <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel overflow-hidden">
                <?php if (!$roster): ?>
                <div class="p-10 text-center text-slate-400">
                    <p class="font-semibold">No students are enrolled in this class.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="text-left px-6 py-3 w-10">#</th>
                                <th class="text-left">LRN</th>
                                <th class="text-left">Student Name</th>
                                <th class="text-center">Status</th>
                                <th class="text-center w-32">Grade</th>
                                <th class="text-center">State</th>
                                <th class="text-right px-6">History</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php foreach ($roster as $i => $r):
                            $sid    = (int) $r['student_id'];
                            $val    = $r['grade'] !== null ? number_format((float) $r['grade'], 2, '.', '') : '';
                            // Approved and Locked are both out of the teacher's hands — grade_save()
                            // refuses them server-side, so the input must match that truth.
                            $locked = in_array((string) ($r['grade_status'] ?? ''), ['Locked', 'Approved'], true);
                            $name   = trim((string) $r['Lastname'] . ', ' . (string) $r['Firstname'] . ' ' . (string) ($r['Middlename'] ?? ''));
                        ?>
                            <tr class="hover:bg-emerald-50/30">
                                <td class="px-6 py-2 text-slate-400"><?= $i + 1 ?></td>
                                <td class="font-mono text-xs text-slate-500"><?= h((string) ($r['LRN_no'] ?: '—')) ?></td>
                                <td class="font-semibold"><?= h($name) ?></td>
                                <td class="text-center text-xs text-slate-500"><?= h((string) ($r['student_status'] == 1 ? 'Enrolled' : ($r['student_status'] ?: '—'))) ?></td>
                                <td class="text-center">
                                    <input type="number" step="0.01" min="0" max="100"
                                           name="grade[<?= $sid ?>]" value="<?= h($val) ?>"
                                           <?= $canEdit && !$locked ? '' : 'disabled' ?>
                                           placeholder="—"
                                           class="w-24 text-center rounded-lg border px-2 py-1.5 text-sm font-bold
                                                  <?= $canEdit && !$locked ? 'border-slate-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500' : 'border-slate-200 bg-slate-50 text-slate-400' ?>">
                                </td>
                                <td class="text-center">
                                    <?php if ($r['grade'] === null): ?>
                                        <span class="text-[10px] font-bold text-slate-400">—</span>
                                    <?php else: ?>
                                        <span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= grade_state_badge((string) $r['grade_status']) ?>">
                                            <?= h((string) $r['grade_status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right px-6">
                                    <a href="<?= h(app_url('teacher/grade_history.php?class_id=' . $classId . '&student_id=' . $sid)) ?>"
                                       class="text-xs font-bold text-emerald-700 hover:underline">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($canEdit): ?>
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50/60">
                    <p class="text-xs text-slate-500">
                        Enter 0–100 (decimals allowed, e.g. <b>85.75</b>). Leave a box empty to mark it <b>not yet graded</b>.
                    </p>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2.5">Save Grades</button>
                        <button type="submit" form="submitForm"
                                onclick="return confirm('Submit these grades for review? You can still edit until the Registrar locks the period.');"
                                class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Submit for Review</button>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </section>
        </form>

        <?php if ($canEdit): ?>
        <form method="POST" action="class_view.php" id="submitForm" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="class_id" value="<?= $classId ?>">
            <input type="hidden" name="period" value="<?= $periodId ?>">
            <input type="hidden" name="action" value="submit">
        </form>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
