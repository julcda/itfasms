<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();
if (!is_registrar_user($user)) {
    flash_set('error', 'Only Registrar users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

$enrollmentId = to_int($_GET['enrollment_id'] ?? 0);
if ($enrollmentId <= 0) {
    flash_set('error', 'Enrollment schedule not found.');
    redirect_to(app_url('registrar/index.php'));
}

$connection = db();

$studentInfoId = resolve_studentinfo_id_for_enrollment($connection, $enrollmentId, true);

$studentStmt = $connection->prepare(
    "SELECT
        en.id,
        en.student_id,
        en.school_year,
        en.Semester,
        en.Department,
        en.Strand,
        en.Department_gradelevel,
        en.Department_section,
        en.Madrasah_gradelevel,
        en.Madrasah_section,
        en.Date_enrolled,
        en.Status,
        COALESCE(
            CONCAT(p.surname, ', ', p.firstname, ' ', IFNULL(p.middlename, '')),
            CONCAT(osp.surname, ', ', osp.firstname, ' ', IFNULL(osp.middlename, ''))
        ) AS full_name,
        COALESCE(p.lrn, osp.lrn, en.student_id) AS lrn,
        COALESCE(p.contact, osp.contact, '') AS contact,
        IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
        IFNULL(sc.Section_name, en.Department_section) AS section_name,
        IFNULL(sy.Class_start, '') AS class_start_label,
        IFNULL(gla.Gradelevel_arabic, '') AS madrasah_grade_name,
        IFNULL(sa.Section_arabic, '') AS madrasah_section_name
     FROM enrollment en
     LEFT JOIN preregistration p ON en.student_id = CAST(p.id AS CHAR)
     LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
     LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
     LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = en.Department_section
     LEFT JOIN schoolyear sy ON sy.School_year = en.school_year
     LEFT JOIN gradelevel_arabic gla ON gla.id = en.Madrasah_gradelevel
     LEFT JOIN section_arabic sa ON sa.id = en.Madrasah_section
     WHERE en.id = ? AND en.Status = 'Officially Enrolled'
     LIMIT 1"
);
$studentStmt->bind_param('i', $enrollmentId);
$studentStmt->execute();
$student = stmt_fetch_assoc($studentStmt);

if (!$student) {
    flash_set('error', 'Officially enrolled student record not found.');
    redirect_to(app_url('registrar/index.php'));
}

$scheduleStmt = $connection->prepare(
    "SELECT
        c.Class_id,
        c.Time,
        sub.Subject_name,
        sub.subject_code,
        t.Fullname AS teacher_name,
        sem.Semester AS semester_name
     FROM student_classes sca
     INNER JOIN classes c ON c.Class_id = sca.class_id
     LEFT JOIN subject sub ON sub.Subject_id = c.Subject_id
     LEFT JOIN teacher t ON t.Teacher_id = c.Teacher_id
     LEFT JOIN semester sem ON sem.Semester_id = c.Semester_id
     WHERE sca.student_id = ?
     ORDER BY STR_TO_DATE(TRIM(SUBSTRING_INDEX(c.Time, '-', 1)), '%H:%i') ASC, sub.Subject_name ASC"
);
$studentId = (string) ($student['student_id'] ?? '');
$scheduleStmt->bind_param('i', $studentInfoId);
$scheduleStmt->execute();
$scheduleRows = stmt_fetch_all_assoc($scheduleStmt);

$studentName = trim((string) ($student['full_name'] ?? '')) ?: ('ID: ' . $studentId);
$classStartLabel = trim((string) ($student['class_start_label'] ?? '')) ?: 'To be announced';
$semesterLabel = trim((string) ($student['Semester'] ?? 'N/A')) ?: 'N/A';
$gradeLabel = trim((string) ($student['gradelevel_name'] ?? '-'));
$sectionLabel = trim((string) ($student['section_name'] ?? '-'));
$departmentLabel = trim((string) ($student['Department'] ?? '-'));
$schoolYearLabel = trim((string) ($student['school_year'] ?? ''));
$contactLabel = trim((string) ($student['contact'] ?? ''));
$dateEnrolled = trim((string) ($student['Date_enrolled'] ?? ''));
$formattedDateEnrolled = $dateEnrolled !== '' ? date('F d, Y', strtotime($dateEnrolled)) : '—';
$madrasahGradeLabel = trim((string) ($student['madrasah_grade_name'] ?? ''));
$madrasahSectionLabel = trim((string) ($student['madrasah_section_name'] ?? ''));
$isJuniorHigh = stripos($departmentLabel, 'junior') !== false;
$logoUrl = h(app_url('itfalogo.png'));
$backUrl = h(app_url('registrar/index.php'));

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Class Schedule | <?= h($studentName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #dbe2ea;
            color: #0f172a;
            min-height: 100vh;
            padding: 18px 12px 32px;
        }
        .toolbar {
            width: 100%;
            max-width: 794px;
            margin: 0 auto 12px;
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .toolbar-group { display: flex; gap: 10px; align-items: center; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            border-radius: 12px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 700;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .btn-back {
            background: #ffffff;
            color: #334155;
            border-color: #cbd5e1;
        }
        .btn-print {
            background: #1d4ed8;
            color: #ffffff;
        }
        .sheet {
            width: 100%;
            max-width: 794px;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 18px 40px -24px rgba(15, 23, 42, 0.25);
            border: 1px solid #cbd5e1;
        }
        .accent {
            height: 5px;
            background: #1d4ed8;
        }
        .content {
            padding: 14mm 12mm 12mm;
        }
        .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 2px solid #1e293b;
            padding-bottom: 10px;
            align-items: flex-start;
        }
        .brand {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .brand img {
            width: 58px;
            height: 58px;
            object-fit: contain;
        }
        .eyebrow {
            font-size: 10px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #334155;
            font-weight: 800;
        }
        .title {
            margin: 4px 0 2px;
            font-size: 24px;
            line-height: 1.15;
            color: #0f172a;
            font-weight: 800;
        }
        .subtitle {
            margin: 0;
            font-size: 12px;
            color: #475569;
        }
        .document-meta {
            min-width: 205px;
            text-align: right;
            font-size: 11px;
            line-height: 1.5;
            color: #334155;
        }
        .document-meta strong {
            color: #0f172a;
        }
        .info-section {
            margin-top: 12px;
        }
        .info-table,
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .section-title {
            margin: 14px 0 6px;
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }
        .info-table {
            border: 1px solid #334155;
            margin-bottom: 10px;
        }
        .info-table th,
        .info-table td {
            border: 1px solid #94a3b8;
            padding: 7px 9px;
            font-size: 12px;
            vertical-align: top;
        }
        .info-table th {
            width: 18%;
            background: #f8fafc;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #334155;
            text-align: left;
            font-weight: 800;
        }
        .info-table td {
            color: #0f172a;
        }
        .schedule-table {
            border: 1px solid #334155;
        }
        .schedule-table thead {
            background: #e2e8f0;
        }
        .schedule-table th,
        .schedule-table td {
            text-align: left;
            padding: 8px 9px;
            border: 1px solid #94a3b8;
            vertical-align: top;
        }
        .schedule-table th {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #0f172a;
            font-weight: 800;
        }
        .schedule-table td {
            font-size: 12px;
            color: #1f2937;
        }
        .mono {
            font-family: 'Inter', sans-serif;
            font-size: 11px;
            color: #475569;
        }
        .empty-state {
            border: 1px solid #94a3b8;
            padding: 14px;
            color: #64748b;
            text-align: center;
            font-size: 12px;
        }
        .footer-note {
            margin-top: 10px;
            font-size: 11px;
            color: #475569;
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
        }
        @media (max-width: 760px) {
            .header { flex-direction: column; }
            .document-meta { text-align: left; }
            .info-table,
            .schedule-table { table-layout: auto; }
            .content { padding: 18px 14px 20px; }
        }
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .toolbar {
                display: none;
            }
            .sheet {
                box-shadow: none;
                border: none;
                max-width: none;
            }
            .content {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div class="toolbar-group">
            <a class="btn btn-back" href="<?= $backUrl ?>">Back to Registrar</a>
        </div>
        <div class="toolbar-group">
            <button class="btn btn-print" type="button" onclick="window.print()">Print Class Schedule</button>
        </div>
    </div>

    <div class="sheet">
        <div class="accent"></div>
        <div class="content">
            <div class="header">
                <div class="brand">
                    <img src="<?= $logoUrl ?>" alt="ITFA logo">
                    <div>
                        <p class="eyebrow">Registrar Copy</p>
                        <h1 class="title">Student Class Schedule</h1>
                        <p class="subtitle">Official class schedule generated after enrollment confirmation.</p>
                    </div>
                </div>
                <div class="document-meta">
                    <div><strong>School Year:</strong> <?= h($schoolYearLabel) ?></div>
                    <div><strong>Semester:</strong> <?= h($semesterLabel) ?></div>
                    <div><strong>Class Starts:</strong> <?= h($classStartLabel) ?></div>
                    <div><strong>Confirmed On:</strong> <?= h($formattedDateEnrolled) ?></div>
                </div>
            </div>

            <div class="info-section">
                <h2 class="section-title">Student Information</h2>
                <table class="info-table">
                    <tbody>
                        <tr>
                            <th>Student Name</th>
                            <td><?= h($studentName) ?></td>
                            <th>LRN / Student ID</th>
                            <td><?= h((string) ($student['lrn'] ?? $studentId)) ?></td>
                        </tr>
                        <tr>
                            <th>Department</th>
                            <td><?= h($departmentLabel) ?></td>
                            <th>Grade / Section</th>
                            <td><?= h($gradeLabel) ?><?= $sectionLabel !== '' && $sectionLabel !== '-' ? ' - ' . h($sectionLabel) : '' ?></td>
                        </tr>
                        <?php if ($isJuniorHigh): ?>
                        <tr>
                            <th>Madrasah Grade</th>
                            <td><?= $madrasahGradeLabel !== '' ? h($madrasahGradeLabel) : '<span style="color:#94a3b8">—</span>' ?></td>
                            <th>Madrasah Section</th>
                            <td><?= $madrasahSectionLabel !== '' ? h($madrasahSectionLabel) : '<span style="color:#94a3b8">—</span>' ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Status</th>
                            <td>Officially Enrolled</td>
                            <th>Contact</th>
                            <td><?= h($contactLabel !== '' ? $contactLabel : 'No contact information on file') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2 class="section-title">Scheduled Subjects</h2>

            <?php if ($scheduleRows === []): ?>
                <div class="empty-state">
                    No class schedule was generated for this student yet. Please check the section schedule setup before printing again.
                </div>
            <?php else: ?>
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th style="width:28px;text-align:center">#</th>
                            <th style="width:110px">Time</th>
                            <th>Subject</th>
                            <th style="width:90px">Code</th>
                            <th>Teacher</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduleRows as $_pi => $schedule): ?>
                            <tr style="background:<?= $_pi % 2 === 0 ? '#ffffff' : '#f8fafc' ?>">
                                <td style="text-align:center;font-size:10px;font-weight:800;color:#2e8b57;padding:8px 4px"><?= $_pi + 1 ?></td>
                                <td style="font-weight:700;color:#1e293b;white-space:nowrap;font-size:12px">
                                    <?php
                                        $rawTime = trim((string)($schedule['Time'] ?? ''));
                                        $timeParts = explode('-', $rawTime, 2);
                                        $tStart = trim($timeParts[0] ?? $rawTime);
                                        $tEnd   = trim($timeParts[1] ?? '');
                                    ?>
                                    <?php if ($tEnd !== ''): ?>
                                        <span style="font-size:13px"><?= h($tStart) ?></span>
                                        <span style="font-size:10px;color:#94a3b8;display:block;font-weight:500">to <?= h($tEnd) ?></span>
                                    <?php else: ?>
                                        <?= h($rawTime) ?>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight:700;font-size:12px"><?= h((string)($schedule['Subject_name'] ?? '—')) ?></td>
                                <td class="mono"><?= h((string)($schedule['subject_code'] ?? '—')) ?></td>
                                <td style="font-size:12px;color:#334155"><?= h((string)($schedule['teacher_name'] ?? 'Unassigned')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="footer-note">
                This document is system-generated by the registrar module and is intended for student schedule reference and printing on A4 paper.
            </p>
        </div>
    </div>

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>