<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/teacher_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!teacher_can_manage($user)) {
    flash_set('error', 'Access denied. Department Head login required.');
    redirect_to(app_url(user_home_path($user)));
}

$sy                    = teacher_active_sy($connection);
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;
$syId                  = (int) $sy['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('advisers.php');
    }
    try {
        $sectionId = to_int($_POST['section_id'] ?? 0);
        $teacherId = to_int($_POST['teacher_id'] ?? 0);
        $gradeId   = to_int($_POST['gradelevel_id'] ?? 0) ?: null;
        $uid       = (int) ($user['id'] ?? 0);

        if ($sectionId <= 0) {
            throw new RuntimeException('Invalid section.');
        }

        if ($teacherId <= 0) {
            $s = $connection->prepare('DELETE FROM advisory_class WHERE school_year_id = ? AND section_id = ?');
            $s->bind_param('ii', $syId, $sectionId);
            $s->execute();
            flash_set('success', 'Adviser removed from that section.');
        } else {
            // UNIQUE(school_year_id, section_id) makes this an upsert, so a section
            // can never end up with two advisers.
            $s = $connection->prepare(
                'INSERT INTO advisory_class (school_year_id, section_id, gradelevel_id, teacher_id, assigned_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id),
                                         gradelevel_id = VALUES(gradelevel_id),
                                         assigned_by = VALUES(assigned_by)'
            );
            $s->bind_param('iiiii', $syId, $sectionId, $gradeId, $teacherId, $uid);
            $s->execute();
            flash_set('success', 'Class adviser assigned. They can now issue Certificates of Recognition for that section.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('advisers.php');
}

// Sections that actually have classes this school year, scoped to this head.
$superAdmin = is_super_admin($user);
$sql = "SELECT sec.Section_id, sec.Section_name,
               MAX(cl.GradeLevel_id) AS gradelevel_id, gl.Gradelevel,
               COUNT(DISTINCT cl.Class_id) AS classes,
               (SELECT COUNT(*) FROM studentinfo si
                 WHERE si.Section = sec.Section_id AND si.School_year_id = ?) AS students,
               a.teacher_id, t.Fullname AS adviser_name, t.Designation
        FROM classes cl
        JOIN section sec ON sec.Section_id = cl.Section_id
        LEFT JOIN gradelevel gl ON gl.Gradelevel_id = cl.GradeLevel_id
        LEFT JOIN advisory_class a ON a.section_id = sec.Section_id AND a.school_year_id = ?
        LEFT JOIN teacher t ON t.Teacher_id = a.teacher_id
        WHERE cl.School_year_id = ?" . ($superAdmin ? '' : ' AND cl.user_id = ?') . "
        GROUP BY sec.Section_id
        ORDER BY gl.Gradelevel_id, sec.Section_name";
$stmt = $connection->prepare($sql);
if ($superAdmin) { $stmt->bind_param('iii', $syId, $syId, $syId); }
else { $uid = (int) $user['id']; $stmt->bind_param('iiii', $syId, $syId, $syId, $uid); }
$stmt->execute();
$sections = stmt_fetch_all_assoc($stmt);

$teachers = teacher_picker_options($connection, $syId);
$assigned = 0;
foreach ($sections as $s) { if ($s['teacher_id']) { $assigned++; } }

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class Advisers | ITFA</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Department Head · Faculty</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Class Advisers</h1>
            <p class="text-slate-500 mt-2">Assign one adviser per section. The adviser sees their advisory class on their dashboard and can issue <b>Certificates of Recognition</b> for honor students.</p>
            <p class="text-xs text-green-700 mt-2">S.Y. <?= h($syLabel) ?> · <?= $assigned ?> of <?= count($sections) ?> sections have an adviser</p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($assigned === 0): ?>
        <div class="mb-6 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4 text-sm text-amber-900">
            <b>No advisers are assigned yet.</b> Until a section has an adviser, no one can issue Certificates of Recognition for it.
        </div>
        <?php endif; ?>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
            <?php if (!$sections): ?>
            <div class="p-10 text-center text-slate-400"><p class="font-semibold">No sections with classes in S.Y. <?= h($syLabel) ?>.</p></div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3">Grade &amp; Section</th>
                            <th class="text-center">Students</th>
                            <th class="text-center">Classes</th>
                            <th class="text-left">Class Adviser</th>
                            <th class="text-right px-6 w-80">Assign</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($sections as $s): ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-6 py-3">
                                <p class="font-bold"><?= h((string) ($s['Gradelevel'] ?? '')) ?> — <?= h((string) $s['Section_name']) ?></p>
                            </td>
                            <td class="text-center"><?= number_format((int) $s['students']) ?></td>
                            <td class="text-center text-slate-500"><?= number_format((int) $s['classes']) ?></td>
                            <td>
                                <?php if ($s['teacher_id']): ?>
                                <p class="font-semibold text-emerald-700"><?= h((string) ($s['adviser_name'] ?: '—')) ?></p>
                                <p class="text-xs text-slate-400"><?= h((string) ($s['Designation'] ?: '')) ?></p>
                                <?php else: ?>
                                <span class="text-xs text-slate-400 italic">none assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-2">
                                <form method="POST" action="advisers.php" class="flex gap-2 justify-end">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="section_id" value="<?= (int) $s['Section_id'] ?>">
                                    <input type="hidden" name="gradelevel_id" value="<?= (int) ($s['gradelevel_id'] ?? 0) ?>">
                                    <select name="teacher_id" class="flex-1 min-w-0 rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                                        <option value="0">— none —</option>
                                        <?php foreach ($teachers as $t): ?>
                                        <option value="<?= (int) $t['Teacher_id'] ?>" <?= (int) $s['teacher_id'] === (int) $t['Teacher_id'] ? 'selected' : '' ?>>
                                            <?= h((string) $t['label']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
