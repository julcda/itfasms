<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_depthead_admin($user) && !is_super_admin($user)) {
    flash_set('error', 'Access denied. Admin login required.');
    redirect_to(app_url('login.php'));
}

// ── School years ──────────────────────────────────────────────────────────────
$schoolYears = [];
try {
    $syRes = $connection->query('SELECT School_year_id, School_year, Status FROM schoolyear ORDER BY School_year_id DESC');
    $schoolYears = $syRes ? $syRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// Default to the school year that has the most class entries; fall back to active SY
$defaultSyId = 0;
try {
    $defRes = $connection->query('SELECT School_year_id, COUNT(*) c FROM classes GROUP BY School_year_id ORDER BY c DESC LIMIT 1');
    $defaultSyId = (int) (($defRes ? $defRes->fetch_assoc() : null)['School_year_id'] ?? 0);
} catch (Throwable) {}
if ($defaultSyId <= 0) {
    foreach ($schoolYears as $sy) {
        if ((int) $sy['Status'] === 1) { $defaultSyId = (int) $sy['School_year_id']; break; }
    }
}
if ($defaultSyId <= 0 && $schoolYears !== []) {
    $defaultSyId = (int) $schoolYears[0]['School_year_id'];
}

$filterSyId = to_int($_GET['sy_id'] ?? $defaultSyId);

$activeSchoolYearLabel = '';
foreach ($schoolYears as $sy) {
    if ((int) $sy['School_year_id'] === $filterSyId) {
        $activeSchoolYearLabel = (string) $sy['School_year'];
        break;
    }
}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── Teacher load aggregates ───────────────────────────────────────────────────
$rows = [];
try {
    $stmt = $connection->prepare(
        'SELECT t.Teacher_id, t.Fullname, t.Designation,
                COUNT(c.Class_id)               AS subjects,
                COUNT(DISTINCT c.Section_id)    AS sections,
                COUNT(DISTINCT c.Semester_id)   AS semesters
         FROM teacher t
         LEFT JOIN classes c ON c.Teacher_id = t.Teacher_id AND c.School_year_id = ?
         GROUP BY t.Teacher_id, t.Fullname, t.Designation
         ORDER BY subjects DESC, t.Fullname ASC'
    );
    $stmt->bind_param('i', $filterSyId);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
} catch (Throwable) {
    $rows = [];
}

// ── Advisory assignments (section.Adviser) ────────────────────────────────────
$advisory = [];
try {
    $advRes = $connection->query('SELECT Adviser, COUNT(*) c FROM section WHERE Adviser > 0 GROUP BY Adviser');
    foreach ($advRes ? $advRes->fetch_all(MYSQLI_ASSOC) : [] as $a) {
        $advisory[(int) $a['Adviser']] = (int) $a['c'];
    }
} catch (Throwable) {}

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalTeachers   = count($rows);
$withLoad        = 0;
$totalAssignments = 0;
foreach ($rows as $r) {
    $totalAssignments += (int) $r['subjects'];
    if ((int) $r['subjects'] > 0) {
        $withLoad++;
    }
}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teaching Loads Summary | ITFA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
            boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.22)' }
        } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        @media print {
            @page { size: A4 portrait; margin: 10mm 12mm; }
            html, body { background: #fff !important; }
            aside, .no-print { display: none !important; }
            .min-h-screen { display: block !important; }
            main { padding: 0 !important; background: none !important; }
            section { box-shadow: none !important; border: 1px solid #999 !important; }
            table { border-collapse: collapse; width: 100%; font-size: 8.5pt; }
            thead th { background: #166534 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody td { border: 1px solid #888; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6 no-print">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Admin Report</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Teaching Loads Summary</h2>
            <p class="text-slate-500 mt-2">Every teacher with their total subjects, sections, semesters, and advisory assignments. Click a teacher to view the full load sheet.</p>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 no-print rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="" class="mb-5 no-print bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-3 items-end shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                <select name="sy_id" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= h((string) $sy['School_year_id']) ?>" <?= $filterSyId === (int) $sy['School_year_id'] ? 'selected' : '' ?>>
                            <?= h((string) $sy['School_year']) ?><?= (int) $sy['Status'] === 1 ? ' (Active)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rounded-xl bg-green-600 text-white px-5 py-2 text-sm font-semibold hover:bg-green-700 transition-colors">View</button>
            <button type="button" onclick="window.print()" class="ml-auto rounded-xl bg-slate-800 text-white px-5 py-2 text-sm font-semibold hover:bg-slate-900 transition-colors">Print</button>
        </form>

        <div class="mb-5 no-print flex flex-wrap gap-3">
            <div class="rounded-2xl bg-white border border-green-200 px-5 py-3 shadow-sm">
                <p class="text-xs text-green-700 font-semibold uppercase tracking-wide">Teachers</p>
                <p class="text-3xl font-extrabold text-green-800 mt-1"><?= h((string) $totalTeachers) ?></p>
                <p class="text-xs text-slate-400 mt-0.5"><?= $withLoad ?> with load · S.Y. <?= h($activeSchoolYearLabel) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-slate-200 px-5 py-3 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide">Total Subject Assignments</p>
                <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= h((string) $totalAssignments) ?></p>
                <p class="text-xs text-slate-400 mt-0.5">class entries this year</p>
            </div>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-green-50 to-slate-50">
                <h3 class="text-lg font-extrabold text-slate-900">Teachers &amp; Loads — S.Y. <?= h($activeSchoolYearLabel) ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-center w-8">#</th>
                            <th class="px-4 py-3 text-left">Teacher</th>
                            <th class="px-4 py-3 text-left">Designation</th>
                            <th class="px-4 py-3 text-center">Subjects</th>
                            <th class="px-4 py-3 text-center">Sections</th>
                            <th class="px-4 py-3 text-center">Semesters</th>
                            <th class="px-4 py-3 text-center">Advisory</th>
                            <th class="px-4 py-3 text-center no-print">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if ($rows === []): ?>
                            <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">No teachers found.</td></tr>
                        <?php endif; ?>
                        <?php $num = 1; foreach ($rows as $row):
                            $subjects = (int) $row['subjects'];
                            $adv      = (int) ($advisory[(int) $row['Teacher_id']] ?? 0);
                        ?>
                        <tr class="hover:bg-green-50/30 transition-colors <?= $subjects === 0 ? 'text-slate-400' : '' ?>">
                            <td class="px-4 py-2.5 text-center text-slate-400 text-xs font-semibold"><?= $num++ ?></td>
                            <td class="px-4 py-2.5 font-semibold <?= $subjects === 0 ? 'text-slate-500' : 'text-slate-900' ?>"><?= h((string) $row['Fullname']) ?></td>
                            <td class="px-4 py-2.5 text-xs text-slate-500"><?= h((string) ($row['Designation'] ?? '') ?: '—') ?></td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-block min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold <?= $subjects > 0 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400' ?>"><?= $subjects ?></span>
                            </td>
                            <td class="px-4 py-2.5 text-center text-slate-700"><?= (int) $row['sections'] ?></td>
                            <td class="px-4 py-2.5 text-center text-slate-700"><?= (int) $row['semesters'] ?></td>
                            <td class="px-4 py-2.5 text-center">
                                <?php if ($adv > 0): ?>
                                    <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-700"><?= $adv ?></span>
                                <?php else: ?>
                                    <span class="text-slate-300">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2.5 text-center no-print">
                                <a href="<?= h(app_url('depthead/teacher_load.php') . '?teacher_id=' . (int) $row['Teacher_id']) ?>"
                                   class="rounded-xl border border-slate-200 bg-white text-slate-700 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 transition-colors">
                                    View Load
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>
</body>
</html>
