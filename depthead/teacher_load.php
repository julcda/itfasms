<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_depthead_user($user) && !is_depthead_admin($user) && !is_super_admin($user)) {
    flash_set('error', 'Access denied. Department Head login required.');
    redirect_to(app_url('login.php'));
}

// ── Active school year ────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
$activeSchoolYearId    = 0;
try {
    $syStmt = $connection->prepare(
        'SELECT School_year_id, School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow) {
        $activeSchoolYearId    = (int)    $syRow['School_year_id'];
        $activeSchoolYearLabel = (string) $syRow['School_year'];
    }
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . ((int) date('Y') + 1);
}

// ── Teacher filter ───────────────────────────────────────────────────────────
$teacherId = to_int($_GET['teacher_id'] ?? 0);

// ── All teachers (for selector dropdown) ─────────────────────────────────────
$teachers = [];
try {
    $res = $connection->query('SELECT Teacher_id, Fullname, Designation FROM teacher ORDER BY Fullname ASC');
    $teachers = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── Selected teacher info ─────────────────────────────────────────────────────
$teacherInfo = null;
if ($teacherId > 0) {
    try {
        $tStmt = $connection->prepare('SELECT Teacher_id, Fullname, Designation FROM teacher WHERE Teacher_id = ? LIMIT 1');
        $tStmt->bind_param('i', $teacherId);
        $tStmt->execute();
        $teacherInfo = stmt_fetch_assoc($tStmt);
    } catch (Throwable) {}
}

// ── Teaching load: all classes assigned to this teacher for active SY ─────────
// Group by semester then section, ordered chronologically within each section
$loadRows = [];
if ($teacherId > 0 && $activeSchoolYearId > 0) {
    try {
        $loadStmt = $connection->prepare("
            SELECT
                c.Class_id,
                c.Time,
                c.Status,
                sub.Subject_name,
                sub.subject_code,
                gl.Gradelevel                     AS grade_name,
                sc.Section_name,
                sem.Semester                      AS semester_name,
                sem.Semester_id,
                st.strand                         AS strand_name,
                IFNULL(adv.Fullname, '')           AS adviser_name,
                sc.Section_id,
                gl.Gradelevel_id
            FROM classes c
            LEFT JOIN subject sub  ON sub.Subject_id    = c.Subject_id
            LEFT JOIN section sc   ON sc.Section_id     = c.Section_id
            LEFT JOIN gradelevel gl ON gl.Gradelevel_id  = sc.Gradelevel_id
            LEFT JOIN semester sem  ON sem.Semester_id   = c.Semester_id
            LEFT JOIN strand st    ON st.strand_id       = c.strand_id
            LEFT JOIN teacher adv  ON adv.Teacher_id     = sc.Adviser
            WHERE c.Teacher_id = ? AND c.School_year_id = ?
            ORDER BY sem.Semester_id ASC,
                     gl.Gradelevel_id ASC,
                     sc.Section_name ASC,
                     STR_TO_DATE(TRIM(SUBSTRING_INDEX(c.Time, '-', 1)), '%H:%i') ASC,
                     sub.Subject_name ASC
        ");
        $loadStmt->bind_param('ii', $teacherId, $activeSchoolYearId);
        $loadStmt->execute();
        $loadRows = stmt_fetch_all_assoc($loadStmt);
    } catch (Throwable) {}
}

// ── Aggregate stats ──────────────────────────────────────────────────────────
$totalSubjects   = count($loadRows);
$uniqueSections  = array_unique(array_column($loadRows, 'Section_id'));
$uniqueSemesters = array_unique(array_column($loadRows, 'Semester_id'));

// Group by semester for display
$bySemester = [];
foreach ($loadRows as $row) {
    $semKey = (string) ($row['Semester_id'] ?? '0');
    $bySemester[$semKey]['label']  = (string) ($row['semester_name'] ?? 'N/A');
    $bySemester[$semKey]['rows'][] = $row;
}

// ── Within each semester group by section ────────────────────────────────────
$bySection = []; // semKey => [ sectionKey => ['label'=>..., 'grade'=>..., 'adviser'=>..., 'rows'=>[...]] ]
foreach ($bySemester as $semKey => $semData) {
    foreach ($semData['rows'] as $row) {
        $secKey = (string) ($row['Section_id'] ?? '0');
        $bySection[$semKey][$secKey]['grade']   = (string) ($row['grade_name']   ?? '');
        $bySection[$semKey][$secKey]['label']   = (string) ($row['Section_name'] ?? '');
        $bySection[$semKey][$secKey]['adviser'] = (string) ($row['adviser_name'] ?? '');
        $bySection[$semKey][$secKey]['strand']  = (string) ($row['strand_name']  ?? '');
        $bySection[$semKey][$secKey]['rows'][]  = $row;
    }
}

$logoUrl  = h(app_url('itfalogo.png'));
$backUrl  = h(app_url('depthead/index.php'));
$printDate = date('F d, Y');
$printTime = date('h:i A');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teaching Load<?= $teacherInfo ? ' — ' . h((string)$teacherInfo['Fullname']) : '' ?> | ITFA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
        }

        /* ── Toolbar (screen only) ── */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #166534;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            gap: 10px;
            flex-wrap: wrap;
        }
        .toolbar-left { display: flex; align-items: center; gap: 10px; }
        .toolbar-right { display: flex; align-items: center; gap: 8px; }
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 16px; border-radius: 10px; font-size: 12px;
            font-weight: 700; cursor: pointer; border: none; text-decoration: none;
            transition: background 0.15s;
        }
        .btn-back    { background: rgba(255,255,255,0.1); color: #e2e8f0; }
        .btn-back:hover { background: rgba(255,255,255,0.18); }
        .btn-print   { background: #2e8b57; color: #fff; }
        .btn-print:hover { background: #166534; }
        .toolbar-title { color: #bfe0c8; font-size: 12px; font-weight: 600; }

        /* ── Teacher selector (screen only) ── */
        .selector-bar {
            max-width: 820px;
            margin: 20px auto 0;
            padding: 0 16px;
        }
        .selector-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }
        .selector-card label { display: block; font-size: 11px; font-weight: 700;
            text-transform: uppercase; color: #64748b; margin-bottom: 5px; }
        .selector-card select, .selector-card button {
            padding: 9px 14px; border-radius: 10px; font-family: inherit;
            font-size: 13px; border: 1px solid #cbd5e1; background: #f8fafc; cursor: pointer;
        }
        .selector-card button {
            background: #166534; color: #fff; border-color: #0f4d28; font-weight: 700;
        }
        .selector-card button:hover { background: #0f4d28; }

        /* ── Sheet ── */
        .sheet-wrap { max-width: 820px; margin: 20px auto 40px; padding: 0 16px; }
        .sheet {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 40px -12px rgba(22,101,52,0.18);
            overflow: hidden;
        }

        /* ── Accent bar ── */
        .accent { height: 6px; background: linear-gradient(90deg, #166534, #2e8b57 60%, #86c294); }

        /* ── Document content ── */
        .content { padding: 28px 32px 32px; }

        /* ── Header ── */
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 16px;
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand img { width: 52px; height: 52px; object-fit: contain; }
        .eyebrow { font-size: 10px; text-transform: uppercase; letter-spacing: 0.18em; color: #2e8b57; font-weight: 800; }
        .doc-title { font-size: 20px; font-weight: 800; color: #0f172a; margin: 2px 0; line-height: 1.2; }
        .doc-sub { font-size: 11px; color: #64748b; }
        .doc-meta { text-align: right; font-size: 11px; line-height: 1.7; color: #334155; }
        .doc-meta strong { color: #0f172a; }

        /* ── Teacher info banner ── */
        .teacher-banner {
            background: linear-gradient(135deg, #f0f7f2 0%, #dcedde 100%);
            border: 1px solid #bfe0c8;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .teacher-name { font-size: 18px; font-weight: 800; color: #0a3a1e; }
        .teacher-desig { font-size: 11px; color: #2e8b57; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; margin-top: 2px; }
        .stat-pills { display: flex; gap: 8px; flex-wrap: wrap; }
        .stat-pill {
            background: #fff;
            border: 1px solid #bfe0c8;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 700;
            color: #0f4d28;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .stat-pill span { color: #1e293b; font-size: 14px; }

        /* ── Section heading ── */
        .sem-heading {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #166534;
            background: #f0f7f2;
            border: 1px solid #bfe0c8;
            border-radius: 8px;
            padding: 7px 14px;
            margin: 18px 0 10px;
        }
        .section-heading {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin: 12px 0 6px 2px;
        }
        .section-heading .grade { font-size: 13px; font-weight: 800; color: #1e293b; }
        .section-heading .sec-name { font-size: 13px; font-weight: 700; color: #166534; }
        .section-heading .adviser-tag {
            font-size: 10px; color: #64748b; font-weight: 500;
            border-left: 2px solid #cbd5e1; padding-left: 8px; margin-left: 2px;
        }

        /* ── Schedule table ── */
        .load-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            table-layout: fixed;
        }
        .load-table thead tr { background: #f1f5f9; }
        .load-table th {
            padding: 7px 10px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #475569;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        .load-table td {
            padding: 7px 10px;
            font-size: 12px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .load-table tbody tr:nth-child(even) { background: #f8fafc; }
        .period-no {
            width: 32px;
            text-align: center;
            font-weight: 800;
            color: #2e8b57;
            font-size: 11px;
        }
        .time-col { width: 105px; }
        .time-start { font-weight: 700; font-size: 13px; color: #0f172a; }
        .time-end { font-size: 10px; color: #94a3b8; }
        .subject-col { font-weight: 700; color: #0f172a; }
        .code-col { font-family: 'Inter', monospace; font-size: 11px; color: #475569; width: 90px; }
        .status-active { color: #059669; font-weight: 700; font-size: 11px; }
        .status-inactive { color: #94a3b8; font-size: 11px; }

        /* ── Summary footer ── */
        .summary-box {
            margin-top: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            background: #f8fafc;
        }
        .summary-box-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 8px;
        }
        .summary-grid { display: flex; gap: 16px; flex-wrap: wrap; }
        .summary-item { font-size: 12px; color: #334155; }
        .summary-item strong { font-size: 16px; color: #166534; margin-right: 3px; }

        /* ── Footer note ── */
        .footer-note {
            margin-top: 18px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 10px;
            color: #94a3b8;
            text-align: center;
        }

        /* ── Empty state ── */
        .empty-block {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
            font-size: 13px;
        }
        .empty-block strong { display: block; font-size: 15px; color: #64748b; margin-bottom: 6px; }

        /* ── Print ── */
        @media print {
            body { background: #fff; }
            .toolbar, .selector-bar { display: none !important; }
            .sheet-wrap { margin: 0; padding: 0; max-width: none; }
            .sheet { box-shadow: none; border-radius: 0; }
            .content { padding: 20px 24px; }
            .sem-heading { break-inside: avoid; }
            .section-heading { break-inside: avoid; }
            .load-table { break-inside: auto; }
            .load-table tr { break-inside: avoid; }
        }
        @page { size: A4 portrait; margin: 10mm; }
    </style>
</head>
<body>

<!-- ── Toolbar ──────────────────────────────────────────────────────────────── -->
<div class="toolbar">
    <div class="toolbar-left">
        <a class="btn btn-back" href="<?= $backUrl ?>">← Back</a>
        <span class="toolbar-title">Department Head · Teaching Load Summary</span>
    </div>
    <?php if ($teacherInfo): ?>
    <div class="toolbar-right">
        <button class="btn btn-print" onclick="window.print()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-6 0v4H9v-4h6z"/>
            </svg>
            Print / Save PDF
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- ── Teacher Selector (screen only) ──────────────────────────────────────── -->
<div class="selector-bar">
    <form method="GET" class="selector-card">
        <div style="flex:1;min-width:220px">
            <label for="teacher_id">Select Teacher</label>
            <select name="teacher_id" id="teacher_id" style="width:100%">
                <option value="0">— Choose a teacher —</option>
                <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t['Teacher_id'] ?>" <?= (int)$t['Teacher_id'] === $teacherId ? 'selected' : '' ?>>
                    <?= h((string)$t['Fullname']) ?>
                    <?php if (!empty($t['Designation'])): ?>· <?= h((string)$t['Designation']) ?><?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">View Load</button>
        </div>
    </form>
</div>

<!-- ── Sheet ──────────────────────────────────────────────────────────────── -->
<div class="sheet-wrap">
<div class="sheet">
    <div class="accent"></div>
    <div class="content">

        <!-- Document header -->
        <div class="doc-header">
            <div class="brand">
                <img src="<?= $logoUrl ?>" alt="ITFA">
                <div>
                    <p class="eyebrow">Dept Head · Official Record</p>
                    <h1 class="doc-title">Teaching Load Summary</h1>
                    <p class="doc-sub">Class schedule and subject assignments per teacher · School Year <?= h($activeSchoolYearLabel) ?></p>
                </div>
            </div>
            <div class="doc-meta">
                <div><strong>School Year:</strong> <?= h($activeSchoolYearLabel) ?></div>
                <div><strong>Printed by:</strong> <?= h((string)($user['full_name'] ?? 'Dept Head')) ?></div>
                <div><strong>Date:</strong> <?= $printDate ?></div>
                <div><strong>Time:</strong> <?= $printTime ?></div>
            </div>
        </div>

        <?php if (!$teacherInfo): ?>
        <!-- No teacher selected -->
        <div class="empty-block">
            <strong>No teacher selected</strong>
            Use the dropdown above to choose a teacher and view their teaching load.
        </div>

        <?php elseif (empty($loadRows)): ?>
        <!-- Teacher selected but no load -->
        <div class="teacher-banner">
            <div>
                <div class="teacher-name"><?= h((string)$teacherInfo['Fullname']) ?></div>
                <div class="teacher-desig"><?= h((string)($teacherInfo['Designation'] ?? 'Teacher')) ?></div>
            </div>
        </div>
        <div class="empty-block">
            <strong>No classes assigned</strong>
            <?= h((string)$teacherInfo['Fullname']) ?> has no scheduled classes for S.Y. <?= h($activeSchoolYearLabel) ?>.
        </div>

        <?php else: ?>
        <!-- Teacher info banner -->
        <div class="teacher-banner">
            <div>
                <div class="teacher-name"><?= h((string)$teacherInfo['Fullname']) ?></div>
                <div class="teacher-desig"><?= h((string)($teacherInfo['Designation'] ?? 'Teacher')) ?></div>
            </div>
            <div class="stat-pills">
                <div class="stat-pill"><span><?= $totalSubjects ?></span>Subject<?= $totalSubjects !== 1 ? 's' : '' ?></div>
                <div class="stat-pill"><span><?= count($uniqueSections) ?></span>Section<?= count($uniqueSections) !== 1 ? 's' : '' ?></div>
                <div class="stat-pill"><span><?= count($uniqueSemesters) ?></span>Semester<?= count($uniqueSemesters) !== 1 ? 's' : '' ?></div>
            </div>
        </div>

        <!-- Schedule grouped by semester then section -->
        <?php foreach ($bySemester as $semKey => $semData): ?>
            <div class="sem-heading"><?= h($semData['label']) ?></div>

            <?php if (isset($bySection[$semKey])): ?>
                <?php foreach ($bySection[$semKey] as $secKey => $secData): ?>
                    <div class="section-heading">
                        <span class="grade"><?= h($secData['grade']) ?></span>
                        <span class="sec-name">— <?= h($secData['label']) ?></span>
                        <?php if (!empty($secData['strand'])): ?>
                            <span style="font-size:11px;color:#2e8b57;font-weight:600">[<?= h($secData['strand']) ?>]</span>
                        <?php endif; ?>
                        <?php if (!empty($secData['adviser'])): ?>
                            <span class="adviser-tag">Adviser: <?= h($secData['adviser']) ?></span>
                        <?php endif; ?>
                    </div>

                    <table class="load-table">
                        <thead>
                            <tr>
                                <th class="period-no">#</th>
                                <th class="time-col">Time</th>
                                <th>Subject</th>
                                <th class="code-col">Code</th>
                                <th style="width:65px;text-align:center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($secData['rows'] as $_pi => $row): ?>
                            <?php
                                $rawTime = trim((string)($row['Time'] ?? ''));
                                $tp      = explode('-', $rawTime, 2);
                                $tStart  = trim($tp[0] ?? $rawTime);
                                $tEnd    = trim($tp[1] ?? '');
                            ?>
                            <tr>
                                <td class="period-no"><?= $_pi + 1 ?></td>
                                <td class="time-col">
                                    <div class="time-start"><?= h($tStart) ?></div>
                                    <?php if ($tEnd !== ''): ?>
                                    <div class="time-end">to <?= h($tEnd) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="subject-col"><?= h((string)($row['Subject_name'] ?? '—')) ?></td>
                                <td class="code-col"><?= h((string)($row['subject_code'] ?? '—')) ?></td>
                                <td style="text-align:center">
                                    <?php if ((int)($row['Status'] ?? 0) === 1): ?>
                                        <span class="status-active">Active</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Summary box -->
        <div class="summary-box">
            <div class="summary-box-title">Load Summary — <?= h((string)$teacherInfo['Fullname']) ?></div>
            <div class="summary-grid">
                <div class="summary-item"><strong><?= $totalSubjects ?></strong> total subject<?= $totalSubjects !== 1 ? 's' : '' ?> assigned</div>
                <div class="summary-item"><strong><?= count($uniqueSections) ?></strong> section<?= count($uniqueSections) !== 1 ? 's' : '' ?> handled</div>
                <div class="summary-item"><strong><?= count($uniqueSemesters) ?></strong> semester<?= count($uniqueSemesters) !== 1 ? 's' : '' ?></div>
                <div class="summary-item">S.Y. <strong style="font-size:13px"><?= h($activeSchoolYearLabel) ?></strong></div>
            </div>
        </div>

        <?php endif; ?>

        <div class="footer-note">
            Teaching Load Summary · <?= h($activeSchoolYearLabel) ?> · Generated <?= $printDate ?> at <?= $printTime ?> by <?= h((string)($user['full_name'] ?? '')) ?> · ITFA Enrollment System
        </div>

    </div><!-- /content -->
</div><!-- /sheet -->
</div><!-- /sheet-wrap -->

</body>
</html>
