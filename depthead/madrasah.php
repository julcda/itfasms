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

// ── School years (default latest with Madrasah-sectioned JHS students) ────────
$schoolYears = [];
try {
    $syRes = $connection->query('SELECT School_year FROM schoolyear ORDER BY School_year_id DESC');
    $schoolYears = $syRes ? array_column($syRes->fetch_all(MYSQLI_ASSOC), 'School_year') : [];
} catch (Throwable) {}

$defaultSy = '';
try {
    $defRes = $connection->query(
        "SELECT school_year FROM enrollment
         WHERE Department = 'Junior High' AND Madrasah_section NOT IN ('', 'N/A', '0') AND school_year <> ''
         ORDER BY school_year DESC LIMIT 1"
    );
    $defaultSy = (string) (($defRes ? $defRes->fetch_assoc() : null)['school_year'] ?? '');
} catch (Throwable) {}
if ($defaultSy === '') {
    $defaultSy = $schoolYears[0] ?? (date('Y') . '-' . (date('Y') + 1));
}
$activeSchoolYearLabel = $defaultSy; // for sidebar

$filterSy = trim((string) ($_GET['sy'] ?? $defaultSy));

// ── Madrasah sectioning for Junior High ───────────────────────────────────────
$sql = '
    SELECT
        e.id AS enrollment_id, e.student_id, e.Madrasah_gradelevel, e.Madrasah_section,
        ga.Gradelevel_arabic AS madrasah_grade, ga.level AS madrasah_level, ga.id AS ma_grade_id,
        sa.Section_arabic AS madrasah_section_name,
        IFNULL(gl.Gradelevel, CONCAT("Grade ", e.Department_gradelevel)) AS jhs_grade,
        CONCAT_WS(" ", NULLIF(TRIM(p.surname),""), NULLIF(TRIM(p.firstname),""), NULLIF(TRIM(p.middlename),"")) AS new_name,
        p.lrn AS new_lrn,
        CONCAT_WS(" ", NULLIF(TRIM(o.surname),""), NULLIF(TRIM(o.firstname),""), NULLIF(TRIM(o.middlename),"")) AS old_name,
        o.lrn AS old_lrn
    FROM enrollment e
    LEFT JOIN gradelevel_arabic ga ON ga.id = CAST(e.Madrasah_gradelevel AS UNSIGNED)
    LEFT JOIN section_arabic sa ON sa.id = CAST(e.Madrasah_section AS UNSIGNED)
    LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
    LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
    LEFT JOIN (
        SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename, ops.lrn
        FROM old_studentprofile ops
        INNER JOIN (SELECT student_id, MAX(id) AS latest_id FROM old_studentprofile GROUP BY student_id) lx
            ON lx.latest_id = ops.id
    ) o ON o.student_id = e.student_id
    WHERE e.Department = "Junior High"
      AND e.Status = "Officially Enrolled"
      AND e.school_year = ?
      AND e.Madrasah_section NOT IN ("", "N/A", "0")
      AND e.Madrasah_gradelevel NOT IN ("", "N/A", "0")
    ORDER BY ga.id, madrasah_section_name, new_name, old_name
';

$rows = [];
try {
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('s', $filterSy);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
} catch (Throwable) {
    $rows = [];
}

// ── Group by Madrasah grade -> Madrasah section ───────────────────────────────
$grouped = [];
foreach ($rows as $row) {
    $grade   = (string) ($row['madrasah_grade'] ?? ('Grade ' . ($row['Madrasah_gradelevel'] ?? '?')));
    $section = (string) ($row['madrasah_section_name'] ?? ('Section ' . ($row['Madrasah_section'] ?? '?')));
    $grouped[$grade][$section][] = $row;
}
$totalCount = count($rows);

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Madrasah Sections (JHS) | ITFA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
            boxShadow: { panel: '0 18px 40px -20px rgba(16,185,129,0.20)' }
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
            .print-page { page-break-inside: avoid; box-shadow: none !important; border: 1px solid #999 !important; }
            table { border-collapse: collapse; width: 100%; font-size: 8.5pt; }
            thead th { background: #064e3b !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody td { border: 1px solid #888; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mb-6 no-print">
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Admin Report</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Madrasah Sectioning — Junior High</h2>
            <p class="text-slate-500 mt-2">Junior High students grouped by their Madrasah grade level and Madrasah section.</p>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 no-print rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="" class="mb-5 no-print bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-3 items-end shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                <select name="sy" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-emerald-400 focus:border-emerald-400">
                    <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= h((string) $sy) ?>" <?= $filterSy === (string) $sy ? 'selected' : '' ?>><?= h((string) $sy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rounded-xl bg-emerald-600 text-white px-5 py-2 text-sm font-semibold hover:bg-emerald-700 transition-colors">View</button>
            <?php if ($rows !== []): ?>
            <button type="button" onclick="window.print()" class="ml-auto rounded-xl bg-slate-800 text-white px-5 py-2 text-sm font-semibold hover:bg-slate-900 transition-colors">Print</button>
            <?php endif; ?>
        </form>

        <?php if ($rows !== []): ?>
        <div class="mb-5 no-print rounded-2xl bg-white border border-emerald-200 px-5 py-3 shadow-sm inline-block">
            <p class="text-xs text-emerald-700 font-semibold uppercase tracking-wide">Total JHS Madrasah Students</p>
            <p class="text-3xl font-extrabold text-emerald-800 mt-1"><?= h((string) $totalCount) ?></p>
            <p class="text-xs text-slate-400 mt-0.5">S.Y. <?= h($filterSy) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($rows === []): ?>
            <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-12 text-center text-slate-400">
                <p class="font-semibold text-slate-500">No Madrasah sectioning found for Junior High</p>
                <p class="text-sm mt-1">Try a different school year. Only officially enrolled JHS students with a Madrasah section are shown.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $grade => $sectionGroups): ?>
            <div class="mt-6 mb-2 flex items-center gap-3 no-print">
                <div class="flex-1 border-t border-slate-300"></div>
                <span class="rounded-full border border-emerald-300 bg-emerald-50 text-emerald-800 text-xs font-bold uppercase tracking-widest px-4 py-1"><?= h($grade) ?></span>
                <div class="flex-1 border-t border-slate-300"></div>
            </div>
                <?php foreach ($sectionGroups as $sectionName => $members): ?>
                <section class="print-page mb-5 rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-emerald-50 to-slate-50 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-xs text-slate-500 uppercase tracking-widest font-semibold"><?= h($grade) ?> · Madrasah · S.Y. <?= h($filterSy) ?></p>
                            <h3 class="text-lg font-extrabold text-slate-900 mt-0.5">Section: <?= h($sectionName) ?></h3>
                        </div>
                        <span class="rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 text-xs font-bold px-3 py-1"><?= count($members) ?> student<?= count($members) !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                                <tr>
                                    <th class="px-4 py-2.5 text-center w-8">#</th>
                                    <th class="px-4 py-2.5 text-left">Student Name</th>
                                    <th class="px-4 py-2.5 text-left">LRN</th>
                                    <th class="px-4 py-2.5 text-left">JHS Grade</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php $num = 1; foreach ($members as $row):
                                    $name = trim((string) ($row['new_name'] ?? '')) ?: trim((string) ($row['old_name'] ?? ''));
                                    $name = $name !== '' ? $name : ('Student ID: ' . (string) $row['student_id']);
                                    $lrn  = (string) ($row['new_lrn'] ?? '') ?: (string) ($row['old_lrn'] ?? '');
                                ?>
                                <tr class="hover:bg-emerald-50/30 transition-colors">
                                    <td class="px-4 py-2 text-center text-slate-400 text-xs font-semibold"><?= $num++ ?></td>
                                    <td class="px-4 py-2 font-semibold"><?= h($name) ?></td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500"><?= h($lrn ?: '—') ?></td>
                                    <td class="px-4 py-2 text-xs text-slate-500"><?= h((string) ($row['jhs_grade'] ?? '—')) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
