<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_registrar_user($user)) {
    flash_set('error', 'Only Registrar users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

// ── Active school year ────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
$activeClassStartLabel = '';
try {
    $syStmt = $connection->prepare(
        'SELECT School_year, Class_start FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow && !empty($syRow['School_year'])) {
        $activeSchoolYearLabel = (string) $syRow['School_year'];
        $activeClassStartLabel = trim((string) ($syRow['Class_start'] ?? ''));
    }
} catch (Throwable) {}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── Active school year ────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
$activeClassStartLabel = '';
try {
    $syStmt = $connection->prepare(
        'SELECT School_year, Class_start FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow && !empty($syRow['School_year'])) {
        $activeSchoolYearLabel = (string) $syRow['School_year'];
        $activeClassStartLabel = trim((string) ($syRow['Class_start'] ?? ''));
    }
} catch (Throwable) {}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterDept    = trim((string) ($_GET['dept']    ?? ''));
$filterGrade   = to_int($_GET['grade']   ?? 0);
$filterSection = to_int($_GET['section'] ?? 0);
$filterSy      = trim((string) ($_GET['sy'] ?? $activeSchoolYearLabel));
$isPrint       = isset($_GET['print']);

// ── Lookup data ───────────────────────────────────────────────────────────────
$gradeLevels = [];
try {
    $glRes = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $glRes ? $glRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$sections = [];
try {
    $scRes = $connection->query('SELECT Section_id, Section_name, Gradelevel_id FROM section ORDER BY Section_name');
    $sections = $scRes ? $scRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$schoolYears = [];
try {
    $syAll = $connection->query('SELECT School_year FROM schoolyear ORDER BY School_year_id DESC');
    $schoolYears = $syAll ? $syAll->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── Build masterlist query ────────────────────────────────────────────────────
$where  = ["e.Status = 'Officially Enrolled'", 'e.school_year = ?'];
$params = [$filterSy];
$types  = 's';

if ($filterDept !== '') {
    $where[]  = 'e.Department = ?';
    $params[] = $filterDept;
    $types   .= 's';
}
if ($filterGrade > 0) {
    $where[]  = 'e.Department_gradelevel = ?';
    $params[] = $filterGrade;
    $types   .= 'i';
}
if ($filterSection > 0) {
    $where[]  = 'e.Department_section = ?';
    $params[] = (string) $filterSection;
    $types   .= 's';
}

$sql = '
    SELECT
        e.id              AS enrollment_id,
        e.student_id,
        e.Department,
        e.Department_gradelevel,
        e.Department_section,
        e.Student_classification,
        e.Date_enrolled,
        e.school_year,
        IFNULL(gl.Gradelevel, CONCAT("Grade ", e.Department_gradelevel)) AS gradelevel_name,
        IFNULL(sc.Section_name, e.Department_section)                    AS section_name,
        pb.description  AS classification_desc,
        pb.classification AS classification_name,
        pb.type         AS student_type,

        /* New student – preregistration */
        CONCAT_WS(" ",
            NULLIF(TRIM(p.surname),""),
            CONCAT(NULLIF(TRIM(p.firstname),""), IFNULL(CONCAT(" ", NULLIF(TRIM(p.middlename),"")),"")))
            AS new_full_name,
        p.lrn  AS new_lrn,
        p.sex  AS new_sex,
        p.contact AS new_contact,

        /* Old student – old_studentprofile */
        CONCAT_WS(" ",
            NULLIF(TRIM(o.surname),""),
            CONCAT(NULLIF(TRIM(o.firstname),""), IFNULL(CONCAT(" ", NULLIF(TRIM(o.middlename),"")),"")))
            AS old_full_name,
        o.lrn  AS old_lrn,
        o.sex  AS old_sex,
        o.contact AS old_contact

    FROM enrollment e
    LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
    LEFT JOIN (
        SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename, ops.lrn, ops.sex, ops.contact
        FROM old_studentprofile ops
        INNER JOIN (
            SELECT student_id, MAX(id) AS latest_id FROM old_studentprofile GROUP BY student_id
        ) latest ON latest.latest_id = ops.id
    ) o ON o.student_id = e.student_id
    LEFT JOIN gradelevel gl  ON gl.Gradelevel_id  = e.Department_gradelevel
    LEFT JOIN section sc     ON CAST(sc.Section_id AS CHAR) = e.Department_section
    LEFT JOIN payment_breakdown pb
           ON pb.classification_id = e.Student_classification
            AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
          AND pb.status = \'Active\'
';

$sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY e.Department, e.Department_gradelevel, sc.Section_name, new_full_name, old_full_name';

$masterStmt = $connection->prepare($sql);
bind_dynamic_params($masterStmt, $types, $params);
$masterStmt->execute();
$masterRows = stmt_fetch_all_assoc($masterStmt);

// ── Summary counts ────────────────────────────────────────────────────────────
$totalCount = count($masterRows);

// Group by department → grade → section for display
$grouped = [];
foreach ($masterRows as $row) {
    $dept    = (string) ($row['Department']    ?? 'Unknown');
    $grade   = (string) ($row['gradelevel_name'] ?? 'Unknown');
    $section = (string) ($row['section_name']  ?? 'Unknown');
    $grouped[$dept][$grade][$section][] = $row;
}

$flash = flash_get();

// JSON for JS-driven section filter
$sectionsJson = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masterlist | ITFA Registrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: {
                            50:  '#eff6ff',
                            300: '#93c5fd',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8'
                        }
                    },
                    boxShadow: { panel: '0 18px 40px -20px rgba(59,130,246,0.18)' }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .print-only { display: none; }
        @media print {
            @page { size: A4 portrait; margin: 10mm 14mm; }
            html, body { background: #fff !important; }
            body { font-family: Arial, sans-serif; font-size: 9pt; color: #000; margin: 0; padding: 0; }

            /* Hide all screen-only UI */
            aside, .no-print { display: none !important; }

            /* Collapse the sidebar grid so content is full-width */
            .min-h-screen { display: block !important; }
            main { padding: 0 !important; background: none !important; }

            /* Each section card = one page */
            .print-page {
                page-break-before: always;
                break-before: page;
                page-break-inside: avoid;
                break-inside: avoid;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }
            .print-page:first-of-type { page-break-before: auto; break-before: auto; }

            /* Show print-only letterhead, hide screen-only card header */
            .print-only         { display: block !important; }
            .print-screen-header { display: none !important; }
            .print-table-wrapper { overflow: visible !important; }
            .col-contact        { display: none !important; }

            /* Table */
            table { border-collapse: collapse; width: 100%; font-size: 8pt; }
            thead th {
                background: #111 !important;
                color: #fff !important;
                border: 1px solid #000;
                padding: 3pt 5pt;
                text-align: left;
                font-weight: bold;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            tbody td {
                border: 1px solid #888;
                padding: 2.5pt 5pt;
                vertical-align: middle;
            }
            tbody tr:nth-child(even) td {
                background: #f0f0f0 !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            /* Strip colored badges in print */
            .print-badge {
                background: none !important;
                border: none !important;
                color: inherit !important;
                padding: 0 !important;
                font-weight: 600;
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <!-- ── Sidebar ────────────────────────────────────────────── -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- ── Main content ───────────────────────────────────────── -->
    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(59,130,246,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-blue-100 shadow-panel p-6 mb-6 no-print">
            <p class="text-xs uppercase tracking-[0.2em] text-blue-700 font-semibold">Registrar</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Masterlist of Enrolled Students</h2>
            <p class="text-slate-500 mt-2">Officially enrolled students grouped by department, grade level, and section.</p>
            <p class="text-xs text-blue-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?></p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 no-print <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Filter bar -->
        <form method="GET" action="" class="mb-5 no-print bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-3 items-end shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                <select name="sy" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-blue-400 focus:border-blue-400">
                    <option value="<?= h($activeSchoolYearLabel) ?>" <?= $filterSy === $activeSchoolYearLabel ? 'selected' : '' ?>>
                        <?= h($activeSchoolYearLabel) ?> (Active)
                    </option>
                    <?php foreach ($schoolYears as $sy): ?>
                        <?php if ((string) $sy['School_year'] === $activeSchoolYearLabel) continue; ?>
                        <option value="<?= h((string) $sy['School_year']) ?>"
                                <?= $filterSy === (string) $sy['School_year'] ? 'selected' : '' ?>>
                            <?= h((string) $sy['School_year']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Department</label>
                <select name="dept" id="filterDept" onchange="updateGradeSectionFilters()"
                        class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-blue-400 focus:border-blue-400">
                    <option value="">All Departments</option>
                    <?php foreach (['Elementary', 'Junior High', 'Senior High'] as $dept): ?>
                        <option value="<?= h($dept) ?>" <?= $filterDept === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Grade Level</label>
                <select name="grade" id="filterGrade" onchange="updateSectionFilter()"
                        class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-blue-400 focus:border-blue-400">
                    <option value="0">All Grades</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= h((string) $gl['Gradelevel_id']) ?>"
                                data-grade-id="<?= h((string) $gl['Gradelevel_id']) ?>"
                                <?= $filterGrade === (int) $gl['Gradelevel_id'] ? 'selected' : '' ?>>
                            <?= h((string) $gl['Gradelevel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Section</label>
                <select name="section" id="filterSection"
                        class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-blue-400 focus:border-blue-400">
                    <option value="0">All Sections</option>
                    <?php foreach ($sections as $sc): ?>
                        <option value="<?= h((string) $sc['Section_id']) ?>"
                                data-gradelevel="<?= h((string) $sc['Gradelevel_id']) ?>"
                                <?= $filterSection === (int) $sc['Section_id'] ? 'selected' : '' ?>>
                            <?= h((string) $sc['Section_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"
                    class="rounded-xl bg-blue-600 text-white px-5 py-2 text-sm font-semibold hover:bg-blue-700 transition-colors">
                Generate
            </button>
            <?php if ($filterDept !== '' || $filterGrade > 0 || $filterSection > 0 || $filterSy !== $activeSchoolYearLabel): ?>
                <a href="masterlist.php"
                   class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    Clear
                </a>
            <?php endif; ?>
            <?php if ($masterRows !== []): ?>
                <a href="<?= h('masterlist.php?' . http_build_query(array_merge($_GET, ['print' => '1']))) ?>"
                   onclick="window.print(); return false;"
                   class="ml-auto rounded-xl bg-slate-800 text-white px-5 py-2 text-sm font-semibold hover:bg-slate-900 transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-6 0v4H9v-4h6z"/>
                    </svg>
                    Print Masterlist
                </a>
            <?php endif; ?>
        </form>

        <!-- Summary stat -->
        <?php if ($masterRows !== []): ?>
        <div class="mb-5 no-print flex flex-wrap gap-3">
            <div class="rounded-2xl bg-white border border-blue-200 px-5 py-3 shadow-sm">
                <p class="text-xs text-blue-700 font-semibold uppercase tracking-wide">Total Enrolled</p>
                <p class="text-3xl font-extrabold text-blue-800 mt-1"><?= h((string) $totalCount) ?></p>
                <p class="text-xs text-slate-400 mt-0.5">
                    S.Y. <?= h($filterSy) ?>
                    <?= $filterDept !== '' ? ' · ' . h($filterDept) : '' ?>
                </p>
            </div>
            <?php
            foreach ($grouped as $dept => $grades):
                $deptCount = 0;
                foreach ($grades as $sections) foreach ($sections as $rows) $deptCount += count($rows);
            ?>
            <div class="rounded-2xl bg-white border border-slate-200 px-5 py-3 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide"><?= h($dept) ?></p>
                <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= $deptCount ?></p>
                <p class="text-xs text-slate-400 mt-0.5">students</p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Print header is embedded per-section. Nothing to render here. -->
        <div hidden>
                </div>
            </div>
        </div>

        <!-- ── Masterlist content ─────────────────────────────── -->
        <?php if ($masterRows === []): ?>
            <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-12 text-center text-slate-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p class="font-semibold text-slate-500">No officially enrolled students found</p>
                <p class="text-sm mt-1">Try adjusting the filters or select a different school year.</p>
            </div>

        <?php else: ?>

            <?php foreach ($grouped as $dept => $grades): ?>

            <!-- Department heading -->
            <div class="no-print mt-6 mb-2 flex items-center gap-3">
                <div class="flex-1 border-t border-slate-300"></div>
                <span class="rounded-full border border-blue-300 bg-blue-50 text-blue-800 text-xs font-bold uppercase tracking-widest px-4 py-1">
                    <?= h($dept) ?>
                </span>
                <div class="flex-1 border-t border-slate-300"></div>
            </div>

                <?php foreach ($grades as $grade => $sectionGroups): ?>
                    <?php foreach ($sectionGroups as $sectionName => $rows): ?>

            <!-- Section card -->
            <section class="print-page print-card mb-5 rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">

                <!-- Per-section letterhead — visible on print only -->
                <div class="print-only" style="padding:0 0 0 0;">
                    <div style="text-align:center; padding-bottom:5pt; margin-bottom:5pt; border-bottom:2px solid #000;">
                        <p style="font-size:12pt; font-weight:800; margin:0; letter-spacing:0.01em; text-transform:uppercase;">Ibn Taimiyah Foundation Academy, Inc.</p>
                        <p style="font-size:7.5pt; margin:2pt 0 0;">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</p>
                    </div>
                    <div style="text-align:center; padding-bottom:5pt; margin-bottom:5pt; border-bottom:1px solid #999;">
                        <p style="font-size:9pt; font-weight:700; margin:0; letter-spacing:0.04em; text-transform:uppercase;">Masterlist of Officially Enrolled Students</p>
                        <p style="font-size:8pt; margin:3pt 0 0;">
                            School Year: <strong><?= h($filterSy) ?></strong>
                            &ensp;&bull;&ensp; <?= h($dept) ?>
                            &ensp;&bull;&ensp; <?= h($grade) ?>
                            &ensp;&bull;&ensp; Section: <strong><?= h($sectionName) ?></strong>
                            &ensp;&bull;&ensp; Total: <strong><?= count($rows) ?></strong> student<?= count($rows) !== 1 ? 's' : '' ?>
                        </p>
                    </div>
                </div>

                <!-- Card header — screen only -->
                <div class="print-screen-header px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-blue-50 to-slate-50 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-widest font-semibold">
                            <?= h($dept) ?> &middot; <?= h($grade) ?>
                        </p>
                        <h3 class="text-lg font-extrabold text-slate-900 mt-0.5">Section: <?= h($sectionName) ?></h3>
                    </div>
                    <span class="rounded-full bg-blue-100 text-blue-800 border border-blue-200 text-xs font-bold px-3 py-1">
                        <?= count($rows) ?> student<?= count($rows) !== 1 ? 's' : '' ?>
                    </span>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto print-table-wrapper">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-4 py-3 text-center w-8">#</th>
                                <th class="px-4 py-3 text-left">Student Name</th>
                                <th class="px-4 py-3 text-left">LRN</th>
                                <th class="px-4 py-3 text-left">Sex</th>
                                <th class="px-4 py-3 text-left col-contact">Contact</th>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-left">Classification</th>
                                <th class="px-4 py-3 text-left">Date Enrolled</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php $num = 1; foreach ($rows as $row):
                                $isNew    = ($row['new_full_name'] ?? '') !== '';
                                $name     = trim((string) ($isNew ? $row['new_full_name'] : ($row['old_full_name'] ?? '')));
                                $name     = $name !== '' ? $name : ('Student ID: ' . $row['student_id']);
                                $lrn      = (string) ($isNew ? $row['new_lrn'] : ($row['old_lrn'] ?? '-'));
                                $sex      = (string) ($isNew ? $row['new_sex'] : ($row['old_sex'] ?? '-'));
                                $contact  = (string) ($isNew ? $row['new_contact'] : ($row['old_contact'] ?? '-'));
                                $classTxt = trim((string) ($row['classification_desc'] ?? ($row['classification_name'] ?? 'Regular')));
                                $dateEnrolled = (string) ($row['Date_enrolled'] ?? '');
                            ?>
                            <tr class="hover:bg-blue-50/30 transition-colors">
                                <td class="px-4 py-2.5 text-center text-slate-400 text-xs font-semibold"><?= $num++ ?></td>
                                <td class="px-4 py-2.5 font-semibold"><?= h($name) ?></td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-500"><?= h($lrn ?: '—') ?></td>
                                <td class="px-4 py-2.5">
                                    <span class="print-badge rounded-full text-xs font-semibold px-2 py-0.5 <?= strtolower($sex) === 'female' ? 'bg-pink-100 text-pink-700' : 'bg-sky-100 text-sky-700' ?>">
                                        <?= h($sex ?: '—') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-500 col-contact"><?= h($contact ?: '—') ?></td>
                                <td class="px-4 py-2.5">
                                    <span class="print-badge rounded-full text-xs font-semibold px-2 py-0.5 <?= $isNew ? 'bg-emerald-100 text-emerald-700' : 'bg-green-100 text-green-700' ?>">
                                        <?= h($isNew ? 'New' : 'Old') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-600"><?= h($classTxt ?: '—') ?></td>
                                <td class="px-4 py-2.5 text-xs text-slate-500">
                                    <?= $dateEnrolled ? h(date('M d, Y', strtotime($dateEnrolled))) : '—' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Section footer -->
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex flex-wrap items-end justify-between gap-6">
                    <p class="text-xs text-slate-500">
                        Total enrolled in this section: <strong class="text-slate-700"><?= count($rows) ?></strong>
                    </p>
                    <div class="text-center">
                        <div class="border-t border-slate-600 pt-1 w-48">
                            <p class="text-xs font-semibold text-slate-700">Registrar's Signature</p>
                            <p class="text-xs text-slate-400">over printed name</p>
                        </div>
                    </div>
                </div>
            </section>

                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>

        <?php endif; ?>

    </main>
</div>

<script>
    const allSections = <?= $sectionsJson ?>;

    function updateGradeSectionFilters() {
        updateSectionFilter();
    }

    function updateSectionFilter() {
        const gradeId   = document.getElementById('filterGrade').value;
        const sectionSel = document.getElementById('filterSection');
        const currentSectionVal = sectionSel.value;

        for (const opt of sectionSel.options) {
            if (!opt.value || opt.value === '0') {
                opt.hidden = false;
                continue;
            }
            opt.hidden = gradeId !== '0' && opt.getAttribute('data-gradelevel') !== gradeId;
        }

        if (sectionSel.options[sectionSel.selectedIndex]?.hidden) {
            sectionSel.value = '0';
        }
    }

    // Run on load to hide sections that don't match the current grade
    updateSectionFilter();
</script>
</body>
</html>
