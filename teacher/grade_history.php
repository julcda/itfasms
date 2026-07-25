<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';

$teacher = require_teacher_login();

$db      = db();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];

$classId   = to_int($_GET['class_id'] ?? 0);
$studentId = to_int($_GET['student_id'] ?? 0);

// AUTHORIZE FIRST — the audit trail is class-scoped data.
require_class_ownership($db, $classId, $tid);

$class = teacher_class_get($db, $classId);

$sStmt = $db->prepare('SELECT student_id, LRN_no, Lastname, Firstname, Middlename FROM studentinfo WHERE student_id = ? LIMIT 1');
$sStmt->bind_param('i', $studentId);
$sStmt->execute();
$student = stmt_fetch_assoc($sStmt);

if (!$student || !grade_student_in_class($db, $classId, $studentId)) {
    flash_set('error', 'That student is not enrolled in this class.');
    redirect_to(app_url('teacher/class_view.php?class_id=' . $classId));
}

$history = grade_history($db, $classId, $studentId);
$name    = trim((string) $student['Lastname'] . ', ' . (string) $student['Firstname'] . ' ' . (string) ($student['Middlename'] ?? ''));
$flash   = flash_get();

$actionBadge = static fn(string $a): string => match ($a) {
    'Insert' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
    'Update' => 'bg-amber-100 text-amber-800 border-amber-300',
    'Lock'   => 'bg-rose-100 text-rose-800 border-rose-300',
    'Unlock' => 'bg-green-100 text-green-800 border-green-300',
    'Submit' => 'bg-sky-100 text-sky-800 border-sky-300',
    default  => 'bg-slate-100 text-slate-600 border-slate-300',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grade History | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(5,150,105,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <a href="<?= h(app_url('teacher/class_view.php?class_id=' . $classId)) ?>" class="text-sm text-slate-500 hover:underline">← Back to roster</a>

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mt-3 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Audit Trail</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1"><?= h($name) ?></h1>
            <p class="text-slate-500 mt-1 text-sm">
                LRN <?= h((string) ($student['LRN_no'] ?: '—')) ?> ·
                <?= h((string) ($class['Subject_name'] ?? '')) ?> ·
                <?= h((string) ($class['Gradelevel'] ?? '')) ?> — <?= h((string) ($class['Section_name'] ?? '')) ?>
            </p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel overflow-hidden">
            <?php if (!$history): ?>
            <div class="p-10 text-center text-slate-400">
                <p class="font-semibold">No grade activity recorded yet.</p>
                <p class="text-sm mt-1">Every change to this student's grade will be listed here.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3">When</th>
                            <th class="text-left">Period</th>
                            <th class="text-center">Action</th>
                            <th class="text-center">Previous</th>
                            <th class="text-center">New</th>
                            <th class="text-left">By</th>
                            <th class="text-left px-6">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($history as $h): ?>
                        <tr class="hover:bg-emerald-50/30">
                            <td class="px-6 py-2.5 whitespace-nowrap"><?= h(date('M j, Y g:ia', strtotime((string) $h['changed_at']))) ?></td>
                            <td class="text-slate-600"><?= h((string) ($h['period_name'] ?? '—')) ?></td>
                            <td class="text-center">
                                <span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= $actionBadge((string) $h['action']) ?>"><?= h((string) $h['action']) ?></span>
                            </td>
                            <td class="text-center text-slate-500"><?= $h['old_grade'] === null ? '—' : number_format((float) $h['old_grade'], 2) ?></td>
                            <td class="text-center font-bold"><?= $h['new_grade'] === null ? '—' : number_format((float) $h['new_grade'], 2) ?></td>
                            <td class="text-slate-600"><?= h((string) ($h['changed_by_name'] ?: '—')) ?></td>
                            <td class="px-6 font-mono text-xs text-slate-400"><?= h((string) ($h['ip_address'] ?: '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3 bg-slate-50/60 border-t border-slate-100">
                <p class="text-xs text-slate-500">Showing <?= count($history) ?> entr<?= count($history) === 1 ? 'y' : 'ies' ?>. Records are permanent and cannot be edited or removed.</p>
            </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
