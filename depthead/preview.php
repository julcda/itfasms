<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user = current_user();

if (!is_depthead_user($user) && !is_depthead_admin($user)) {
    flash_set('error', 'Access denied. Department Head login required.');
    redirect_to(app_url('login.php'));
}

$activeSchoolYearLabel = '';
$activeSchoolYearId = 0;
try {
    $syStmt = $connection->prepare(
        'SELECT School_year_id, School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow) {
        $activeSchoolYearId = (int) $syRow['School_year_id'];
        $activeSchoolYearLabel = (string) $syRow['School_year'];
    }
} catch (Throwable) {}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . ((int) date('Y') + 1);
}

$sections = [];
try {
    $res = $connection->query(
        'SELECT s.Section_id, s.Section_name, s.Gradelevel_id, s.Capacity,
                g.Gradelevel
         FROM section s
         LEFT JOIN gradelevel g ON g.Gradelevel_id = s.Gradelevel_id
         ORDER BY g.Gradelevel_id, s.Section_name'
    );
    $sections = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$gradeLevels = [];
try {
    $res = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$semesters = [];
try {
    $res = $connection->query('SELECT Semester_id, Semester FROM semester ORDER BY Semester_id');
    $semesters = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$selectedSectionId = to_int($_GET['section_id'] ?? 0);
$selectedSemesterId = to_int($_GET['semester_id'] ?? 3);
$selectedClassId = to_int($_GET['class_id'] ?? 0);

$selectedSection = null;
foreach ($sections as $section) {
    if ((int) $section['Section_id'] === $selectedSectionId) {
        $selectedSection = $section;
        break;
    }
}

$classes = [];
if ($selectedSectionId > 0 && $activeSchoolYearId > 0) {
    try {
        $classStmt = $connection->prepare(
            'SELECT c.Class_id, c.Time, c.Status, c.Teacher_id,
                    sub.Subject_name, sub.subject_code,
                    t.Fullname AS teacher_name,
                    sem.Semester AS semester_name,
                    st.strand AS strand_name
             FROM classes c
             LEFT JOIN subject sub ON sub.Subject_id = c.Subject_id
             LEFT JOIN teacher t ON t.Teacher_id = c.Teacher_id
             LEFT JOIN semester sem ON sem.Semester_id = c.Semester_id
             LEFT JOIN strand st ON st.strand_id = c.strand_id
             WHERE c.School_year_id = ? AND c.Section_id = ? AND c.Semester_id = ?
             ORDER BY STR_TO_DATE(TRIM(SUBSTRING_INDEX(c.Time, \'-\', 1)), \'%H:%i\') ASC, sub.Subject_name ASC'
        );
        $classStmt->bind_param('iii', $activeSchoolYearId, $selectedSectionId, $selectedSemesterId);
        $classStmt->execute();
        $classes = stmt_fetch_all_assoc($classStmt);
    } catch (Throwable) {}
}

$selectedSemesterLabel = 'N/A';
foreach ($semesters as $semester) {
    if ((int) $semester['Semester_id'] === $selectedSemesterId) {
        $selectedSemesterLabel = (string) $semester['Semester'];
        break;
    }
}

$selectedClass = null;
foreach ($classes as $class) {
    if ((int) ($class['Class_id'] ?? 0) === $selectedClassId) {
        $selectedClass = $class;
        break;
    }
}

$printRows = $selectedClass ? [$selectedClass] : $classes;
$isSingleClassPreview = $selectedClass !== null;

$flash = flash_get();
$sectionsJson = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$logoUrl = h(app_url('itfalogo.png'));
$manageUrl = app_url('depthead/index.php') . '?section_id=' . $selectedSectionId . '&semester_id=' . $selectedSemesterId;
$previewBaseUrl = app_url('depthead/preview.php') . '?section_id=' . $selectedSectionId . '&semester_id=' . $selectedSemesterId;

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schedule Preview | Dept Head Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: { 50:'#f0f7f2', 300:'#86c294', 400:'#2e8b57', 500:'#2e8b57', 600:'#166534', 700:'#0f4d28' }
                    },
                    boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' },
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        .print-toolbar {
            width: 100%;
            max-width: 794px;
            margin: 0 auto 12px;
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .print-toolbar-group { display: flex; gap: 10px; align-items: center; }
        .print-sheet {
            width: 100%;
            max-width: 794px;
            margin: 24px auto 0;
            background: #ffffff;
            box-shadow: 0 18px 40px -24px rgba(15, 23, 42, 0.25);
            border: 1px solid #cbd5e1;
        }
        .print-accent {
            height: 5px;
            background: #166534;
        }
        .print-content {
            padding: 14mm 12mm 12mm;
        }
        .print-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 2px solid #1e293b;
            padding-bottom: 10px;
            align-items: flex-start;
        }
        .print-brand {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .print-brand img {
            width: 58px;
            height: 58px;
            object-fit: contain;
        }
        .print-eyebrow {
            font-size: 10px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #334155;
            font-weight: 800;
        }
        .print-title {
            margin: 4px 0 2px;
            font-size: 24px;
            line-height: 1.15;
            color: #0f172a;
            font-weight: 800;
        }
        .print-subtitle {
            margin: 0;
            font-size: 12px;
            color: #475569;
        }
        .print-meta {
            min-width: 205px;
            text-align: right;
            font-size: 11px;
            line-height: 1.5;
            color: #334155;
        }
        .print-meta strong {
            color: #0f172a;
        }
        .print-section-title {
            margin: 14px 0 6px;
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }
        .print-info-table,
        .print-schedule-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .print-info-table {
            border: 1px solid #334155;
            margin-bottom: 10px;
        }
        .print-info-table th,
        .print-info-table td,
        .print-schedule-table th,
        .print-schedule-table td {
            border: 1px solid #94a3b8;
            padding: 8px 9px;
            vertical-align: top;
        }
        .print-info-table th {
            width: 18%;
            background: #f8fafc;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #334155;
            text-align: left;
            font-weight: 800;
        }
        .print-info-table td {
            font-size: 12px;
            color: #0f172a;
        }
        .print-schedule-table {
            border: 1px solid #334155;
        }
        .print-schedule-table thead {
            background: #e2e8f0;
        }
        .print-schedule-table th {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #0f172a;
            text-align: left;
            font-weight: 800;
        }
        .print-schedule-table td {
            font-size: 12px;
            color: #1f2937;
        }
        .print-note {
            margin-top: 10px;
            font-size: 11px;
            color: #475569;
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
        }
        .screen-only { display: block; }
        @media (max-width: 760px) {
            .print-header { flex-direction: column; }
            .print-meta { text-align: left; }
            .print-info-table,
            .print-schedule-table { table-layout: auto; }
            .print-content { padding: 18px 14px 20px; }
        }
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        @media print {
            body {
                background: #ffffff !important;
                padding: 0;
            }
            aside,
            .screen-only,
            .print-toolbar {
                display: none !important;
            }
            .print-sheet {
                margin: 0;
                box-shadow: none;
                border: none;
                max-width: none;
            }
            .print-content {
                padding: 0;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">
        <div class="screen-only">
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Department Head</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Class Schedule Preview</h2>
            <p class="text-slate-500 mt-2">Preview the section schedule exactly as it has been created before sharing it with the registrar or printing it.</p>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="preview.php" class="mb-6 bg-white rounded-2xl border border-slate-200 p-5 shadow-panel flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Grade Level</label>
                <select id="filterGradeLevel" onchange="filterSections()"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Grade Levels</option>
                    <?php foreach ($gradeLevels as $gradeLevel): ?>
                        <option value="<?= h((string) $gradeLevel['Gradelevel_id']) ?>">
                            <?= h($gradeLevel['Gradelevel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Section</label>
                <select name="section_id" id="filterSection"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select a section —</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= h((string) $section['Section_id']) ?>"
                                data-grade="<?= h((string) $section['Gradelevel_id']) ?>"
                                <?= $selectedSectionId === (int) $section['Section_id'] ? 'selected' : '' ?>>
                            <?= h($section['Section_name']) ?> (<?= h($section['Gradelevel'] ?? '-') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Semester</label>
                <select name="semester_id"
                        class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <?php foreach ($semesters as $semester): ?>
                        <option value="<?= h((string) $semester['Semester_id']) ?>"
                                <?= $selectedSemesterId === (int) $semester['Semester_id'] ? 'selected' : '' ?>>
                            <?= h($semester['Semester']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"
                    class="rounded-xl bg-green-600 text-white px-5 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                Preview Schedule
            </button>
        </form>
        </div>

        <?php if ($selectedSection): ?>
            <div class="screen-only">
            <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-900"><?= h($selectedSection['Section_name']) ?></h3>
                        <p class="text-sm text-slate-500 mt-1">
                            <?= h($selectedSection['Gradelevel'] ?? '-') ?> · Capacity: <?= h((string) ($selectedSection['Capacity'] ?? 0)) ?> · School Year: <?= h($activeSchoolYearLabel) ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="<?= h($manageUrl) ?>"
                           class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                            Manage This Schedule
                        </a>
                        <?php if ($isSingleClassPreview): ?>
                            <a href="<?= h($previewBaseUrl) ?>"
                               class="rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-semibold text-green-700 hover:bg-green-100 transition-colors">
                                Back to Full Schedule
                            </a>
                        <?php endif; ?>
                        <button type="button"
                                onclick="window.print()"
                                class="rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                            Print Preview
                        </button>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 text-sm text-slate-600">
                    Semester:
                    <span class="font-semibold text-slate-900">
                        <?= h($selectedSemesterLabel) ?>
                    </span>
                    <?php if ($isSingleClassPreview): ?>
                        <span class="ml-3 inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-700">
                            Single class block preview
                        </span>
                    <?php endif; ?>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-green-50 text-xs uppercase tracking-wide text-green-800">
                            <tr>
                                <th class="px-4 py-3 text-left">Time</th>
                                <th class="px-4 py-3 text-left">Subject</th>
                                <th class="px-4 py-3 text-left">Code</th>
                                <th class="px-4 py-3 text-left">Teacher</th>
                                <th class="px-4 py-3 text-left">Cluster</th>
                                <th class="px-4 py-3 text-center">Status</th>
                                <th class="px-4 py-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php if ($classes === []): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center text-slate-400">
                                        No classes scheduled for this section and semester yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($classes as $class): ?>
                                <tr class="hover:bg-green-50/30 transition-colors">
                                    <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-700 whitespace-nowrap"><?= h((string) ($class['Time'] ?? '—')) ?></td>
                                    <td class="px-4 py-3 font-medium text-slate-900"><?= h((string) ($class['Subject_name'] ?? '—')) ?></td>
                                    <td class="px-4 py-3 text-xs text-slate-500 font-mono"><?= h((string) ($class['subject_code'] ?? '—')) ?></td>
                                    <td class="px-4 py-3 text-slate-700">
                                        <?php
                                            $rowTeacherId   = (int)($class['Teacher_id'] ?? 0);
                                            $rowTeacherName = (string)($class['teacher_name'] ?? '—');
                                        ?>
                                        <?= h($rowTeacherName) ?>
                                        <?php if ($rowTeacherId > 0): ?>
                                        <a href="<?= h(app_url('depthead/teacher_load.php') . '?teacher_id=' . $rowTeacherId) ?>"
                                           title="View teaching load of <?= h($rowTeacherName) ?>"
                                           class="ml-1 inline-flex items-center text-green-400 hover:text-green-700"
                                           target="_blank">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                            </svg>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600"><?= h((string) ($class['strand_name'] ?? '—')) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ((int) ($class['Status'] ?? 0) === 1): ?>
                                            <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-700">Active</span>
                                        <?php else: ?>
                                            <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold bg-slate-100 text-slate-500">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex items-center justify-center gap-2 flex-wrap">
                                            <a href="<?= h($previewBaseUrl . '&class_id=' . (int) $class['Class_id']) ?>"
                                               class="rounded-xl border border-green-200 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700 hover:bg-green-100 transition-colors">
                                                Preview Block
                                            </a>
                                            <a href="<?= h($previewBaseUrl . '&class_id=' . (int) $class['Class_id']) ?>"
                                               onclick="event.preventDefault(); window.location.href = this.href; setTimeout(() => window.print(), 150);"
                                               class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                                                Export / Print
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 text-xs text-slate-500">
                    <?= count($classes) ?> class<?= count($classes) !== 1 ? 'es' : '' ?> in preview
                </div>
            </section>
            </div>

            <div class="print-toolbar screen-only">
                <div class="print-toolbar-group text-sm text-slate-500">
                    <?= $isSingleClassPreview ? 'Formal A4 print sheet for one class block.' : 'Formal A4 print sheet for the full section schedule.' ?>
                </div>
                <div class="print-toolbar-group">
                    <button type="button"
                            onclick="window.print()"
                            class="rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                        Print A4 Sheet
                    </button>
                </div>
            </div>

            <section class="print-sheet">
                <div class="print-accent"></div>
                <div class="print-content">
                    <div class="print-header">
                        <div class="print-brand">
                            <img src="<?= $logoUrl ?>" alt="ITFA logo">
                            <div>
                                <p class="print-eyebrow">Department Head Copy</p>
                                <h1 class="print-title">Class Schedule Preview</h1>
                                <p class="print-subtitle">
                                    <?= $isSingleClassPreview ? 'Single class block ready for review and printing.' : 'Formal section schedule preview ready for review and printing.' ?>
                                </p>
                            </div>
                        </div>
                        <div class="print-meta">
                            <div><strong>School Year:</strong> <?= h($activeSchoolYearLabel) ?></div>
                            <div><strong>Semester:</strong> <?= h($selectedSemesterLabel) ?></div>
                            <div><strong>Section:</strong> <?= h((string) ($selectedSection['Section_name'] ?? '-')) ?></div>
                            <div><strong>Grade Level:</strong> <?= h((string) ($selectedSection['Gradelevel'] ?? '-')) ?></div>
                        </div>
                    </div>

                    <h2 class="print-section-title">Schedule Information</h2>
                    <table class="print-info-table">
                        <tbody>
                            <tr>
                                <th>Section</th>
                                <td><?= h((string) ($selectedSection['Section_name'] ?? '-')) ?></td>
                                <th>Grade Level</th>
                                <td><?= h((string) ($selectedSection['Gradelevel'] ?? '-')) ?></td>
                            </tr>
                            <tr>
                                <th>Semester</th>
                                <td><?= h($selectedSemesterLabel) ?></td>
                                <th>Capacity</th>
                                <td><?= h((string) ($selectedSection['Capacity'] ?? 0)) ?></td>
                            </tr>
                            <tr>
                                <th>Preview Mode</th>
                                <td><?= $isSingleClassPreview ? 'Single class block' : 'Full section schedule' ?></td>
                                <th>Generated On</th>
                                <td><?= h(date('F d, Y')) ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <h2 class="print-section-title"><?= $isSingleClassPreview ? 'Selected Class Block' : 'Scheduled Classes' ?></h2>
                    <?php if ($printRows === []): ?>
                        <div class="border border-slate-400 px-4 py-5 text-center text-sm text-slate-500">
                            No classes are available for printing in this schedule selection.
                        </div>
                    <?php else: ?>
                        <table class="print-schedule-table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Code</th>
                                    <th>Time</th>
                                    <th>Teacher</th>
                                    <th>Cluster</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($printRows as $class): ?>
                                    <tr>
                                        <td><strong><?= h((string) ($class['Subject_name'] ?? '—')) ?></strong></td>
                                        <td><?= h((string) ($class['subject_code'] ?? '—')) ?></td>
                                        <td><?= h((string) ($class['Time'] ?? '—')) ?></td>
                                        <td><?= h((string) ($class['teacher_name'] ?? 'Unassigned')) ?></td>
                                        <td><?= h((string) ($class['strand_name'] ?? '—')) ?></td>
                                        <td><?= (int) ($class['Status'] ?? 0) === 1 ? 'Active' : 'Inactive' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <p class="print-note">
                        This department-head preview sheet is formatted for A4 printing and may be used to review either the full section schedule or one selected class block.
                    </p>
                </div>
            </section>
        <?php else: ?>
            <div class="screen-only rounded-3xl border border-dashed border-slate-300 bg-white/60 p-12 text-center text-slate-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/>
                </svg>
                <p class="font-semibold text-slate-500">Select a grade level, section, and semester above to preview its class schedule.</p>
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
const allSections = <?= $sectionsJson ?>;

function filterSections() {
    const gradeLevelId = document.getElementById('filterGradeLevel').value;
    const sectionSelect = document.getElementById('filterSection');
    sectionSelect.querySelectorAll('option[data-grade]').forEach(option => {
        option.style.display = (!gradeLevelId || option.getAttribute('data-grade') === gradeLevelId) ? '' : 'none';
    });
}

window.addEventListener('DOMContentLoaded', filterSections);
</script>
</body>
</html>