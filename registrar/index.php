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
if ($activeClassStartLabel === '') {
    $activeClassStartLabel = 'To be announced';
}

// ── Load lookup data for dropdowns ───────────────────────────────────────────
$gradeLevels = [];
try {
    $glResult  = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $glResult ? $glResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$sections = [];
try {
    $scResult = $connection->query('SELECT Section_id, Section_name, Gradelevel_id FROM section ORDER BY Section_name');
    $sections = $scResult ? $scResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$classifications = [];
try {
    $clResult = $connection->query(
        "SELECT DISTINCT classification_id, classification, description, type
         FROM payment_breakdown
         WHERE status = 'Active'
         ORDER BY classification_id, type"
    );
    $classifications = $clResult ? $clResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── POST: handle actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('index.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'confirm_enrollment') {
            $enrollmentId = to_int($_POST['enrollment_id'] ?? 0);
            if ($enrollmentId <= 0) {
                throw new RuntimeException('Invalid enrollment record.');
            }

            $studentInfoId = resolve_studentinfo_id_for_enrollment($connection, $enrollmentId, true);
            if ($studentInfoId <= 0) {
                throw new RuntimeException('Unable to prepare the student schedule record. Please check the student profile data first.');
            }

            // Fetch enrollment record to get student + section + semester + school year
            $checkStmt = $connection->prepare(
                "SELECT id, student_id, Department_section, Semester, school_year
                 FROM enrollment
                 WHERE id = ? AND Status = 'For Registrar Confirmation'"
            );
            $checkStmt->bind_param('i', $enrollmentId);
            $checkStmt->execute();
            $enrRow = stmt_fetch_assoc($checkStmt);
            if (!$enrRow) {
                throw new RuntimeException('Enrollment record not found or already processed.');
            }

            // Mark as officially enrolled
            $updStmt = $connection->prepare(
                "UPDATE enrollment SET Status = 'Officially Enrolled' WHERE id = ?"
            );
            $updStmt->bind_param('i', $enrollmentId);
            $updStmt->execute();

            // ── Auto-generate student_classes ─────────────────────────────
            // Resolve school year ID and semester ID from the enrollment strings
            $sectionIdInt   = to_int($enrRow['Department_section']);
            $semesterString = (string) ($enrRow['Semester'] ?? '');
            $schoolYearStr  = (string) ($enrRow['school_year'] ?? '');
            $studentId      = (string) ($enrRow['student_id'] ?? '');

            $syIdStmt = $connection->prepare(
                'SELECT School_year_id FROM schoolyear WHERE School_year = ? LIMIT 1'
            );
            $syIdStmt->bind_param('s', $schoolYearStr);
            $syIdStmt->execute();
            $syIdRow = stmt_fetch_assoc($syIdStmt);
            $resolvedSyId = (int) ($syIdRow['School_year_id'] ?? 0);

            $semIdStmt = $connection->prepare(
                'SELECT Semester_id FROM semester WHERE Semester = ? LIMIT 1'
            );
            $semIdStmt->bind_param('s', $semesterString);
            $semIdStmt->execute();
            $semIdRow = stmt_fetch_assoc($semIdStmt);
            $resolvedSemId = (int) ($semIdRow['Semester_id'] ?? 0);

            if ($resolvedSyId > 0 && $resolvedSemId > 0 && $sectionIdInt > 0 && $studentInfoId > 0) {
                // Fetch all active classes for the student's section + semester + school year
                $clsStmt = $connection->prepare(
                    'SELECT Class_id FROM classes
                     WHERE School_year_id = ? AND Semester_id = ? AND Section_id = ? AND Status = 1'
                );
                $clsStmt->bind_param('iii', $resolvedSyId, $resolvedSemId, $sectionIdInt);
                $clsStmt->execute();
                $classRows = stmt_fetch_all_assoc($clsStmt);

                foreach ($classRows as $clsRow) {
                    $classId = (int) $clsRow['Class_id'];

                    // Skip if already assigned (prevent duplicates)
                    $dupCheck = $connection->prepare(
                        'SELECT id FROM student_classes WHERE class_id = ? AND student_id = ? LIMIT 1'
                    );
                    $dupCheck->bind_param('ii', $classId, $studentInfoId);
                    $dupCheck->execute();
                    if (stmt_fetch_assoc($dupCheck)) {
                        continue;
                    }

                    $scInsert = $connection->prepare(
                        'INSERT INTO student_classes (class_id, student_id) VALUES (?, ?)'
                    );
                    $scInsert->bind_param('ii', $classId, $studentInfoId);
                    $scInsert->execute();
                }
            }
            // ─────────────────────────────────────────────────────────────

            flash_set('success', 'Enrollment confirmed. Student is now Officially Enrolled and the class schedule is ready to print.');
            redirect_to('class_schedule_print.php?enrollment_id=' . $enrollmentId);

        } elseif ($action === 'edit_enrollment') {
            $enrollmentId     = to_int($_POST['enrollment_id'] ?? 0);
            $department       = trim((string) ($_POST['department'] ?? ''));
            $gradeLevelId     = to_int($_POST['gradelevel_id'] ?? 0);
            $sectionId        = trim((string) ($_POST['section_id'] ?? ''));
            $classificationId = to_int($_POST['classification_id'] ?? 0);

            if ($enrollmentId <= 0) {
                throw new RuntimeException('Invalid enrollment record.');
            }

            $allowedDepts = ['Elementary', 'Junior High', 'Senior High'];
            if (!in_array($department, $allowedDepts, true)) {
                throw new RuntimeException('Invalid department.');
            }

            if ($gradeLevelId <= 0) {
                throw new RuntimeException('Please select a valid grade level.');
            }

            if ($classificationId <= 0) {
                throw new RuntimeException('Please select a valid classification.');
            }

            // Verify the enrollment record exists and is pending registrar confirmation
            $checkStmt = $connection->prepare(
                "SELECT id FROM enrollment WHERE id = ? AND Status = 'For Registrar Confirmation' LIMIT 1"
            );
            $checkStmt->bind_param('i', $enrollmentId);
            $checkStmt->execute();
            if (!stmt_fetch_assoc($checkStmt)) {
                throw new RuntimeException('Enrollment record not found or not editable at this stage.');
            }

            $sectionVal = $sectionId !== '' ? $sectionId : '';

            $updStmt = $connection->prepare(
                'UPDATE enrollment
                 SET Department = ?, Department_gradelevel = ?, Department_section = ?, Student_classification = ?
                 WHERE id = ?'
            );
            $updStmt->bind_param('siisi', $department, $gradeLevelId, $sectionVal, $classificationId, $enrollmentId);
            $updStmt->execute();

            flash_set('success', 'Enrollment information updated successfully.');
            redirect_to('index.php');

        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
        redirect_to('index.php');
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search     = trim((string) ($_GET['q'] ?? ''));
$deptFilter = trim((string) ($_GET['dept'] ?? ''));

$baseWhere  = ['en.school_year = ?'];
$baseParams = [$activeSchoolYearLabel];
$baseTypes  = 's';

if ($search !== '') {
    $baseWhere[]  = '(p.surname LIKE ? OR p.firstname LIKE ? OR p.lrn LIKE ?'
                  . ' OR osp.surname LIKE ? OR osp.firstname LIKE ? OR osp.lrn LIKE ?)';
    $like     = '%' . $search . '%';
    $baseParams   = array_merge($baseParams, [$like, $like, $like, $like, $like, $like]);
    $baseTypes   .= 'ssssss';
}

if ($deptFilter !== '') {
    $baseWhere[]  = 'en.Department = ?';
    $baseParams[] = $deptFilter;
    $baseTypes   .= 's';
}

$selectSql = 'SELECT
            en.id, en.student_id, en.school_year, en.Department, en.Strand,
            en.Department_gradelevel, en.Department_section, en.Semester,
            en.Student_classification, en.Date_enrolled, en.Status,
            COALESCE(
                CONCAT(p.surname, \', \', p.firstname, \' \', IFNULL(p.middlename, \'\')),
                CONCAT(osp.surname, \', \', osp.firstname, \' \', IFNULL(osp.middlename, \'\'))
            ) AS full_name,
            COALESCE(p.lrn, osp.lrn) AS lrn,
            COALESCE(p.contact, osp.contact) AS contact,
            IF(p.id IS NOT NULL, \'New\', \'Old\') AS student_type,
            pb.classification_id     AS pb_classification_id,
            pb.classification        AS classification_name,
            pb.description           AS classification_desc,
            IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
            IFNULL(sc.Section_name, en.Department_section) AS section_name,
            IFNULL(sy.Class_start, \'\') AS class_start_label
        FROM enrollment en
        LEFT JOIN preregistration p ON en.student_id = CAST(p.id AS CHAR)
             LEFT JOIN (
                 SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename, ops.lrn, ops.contact
                 FROM old_studentprofile ops
                 INNER JOIN (
                     SELECT student_id, MAX(id) AS latest_id
                     FROM old_studentprofile
                     GROUP BY student_id
                 ) latest ON latest.latest_id = ops.id
             ) osp ON (p.id IS NULL AND osp.student_id = en.student_id)
        LEFT JOIN payment_breakdown pb
               ON pb.classification_id = en.Student_classification
              AND pb.type = IF(p.id IS NOT NULL, \'New\', \'Old\')
              AND pb.status = \'Active\'
        LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
        LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = en.Department_section
        LEFT JOIN schoolyear sy ON sy.School_year = en.school_year';

$pendingWhere = array_merge(["en.Status = 'For Registrar Confirmation'"], $baseWhere);
$pendingParams = $baseParams;
$pendingTypes = $baseTypes;

$sql = $selectSql
    . ' WHERE ' . implode(' AND ', $pendingWhere)
    . ' ORDER BY en.id DESC';

$stmt = $connection->prepare($sql);
if ($pendingTypes !== '') {
    bind_dynamic_params($stmt, $pendingTypes, $pendingParams);
}
$stmt->execute();
$students = stmt_fetch_all_assoc($stmt);
$total    = count($students);

// ── Dashboard analytics ──────────────────────────────────────────────────────

// Total officially enrolled this S.Y.
$officiallyEnrolled = 0;
try {
    $oeStmt = $connection->prepare(
        "SELECT COUNT(*) AS cnt FROM enrollment WHERE Status = 'Officially Enrolled' AND school_year = ?"
    );
    $oeStmt->bind_param('s', $activeSchoolYearLabel);
    $oeStmt->execute();
    $officiallyEnrolled = (int) (stmt_fetch_assoc($oeStmt)['cnt'] ?? 0);
} catch (Throwable) {}

// Today's confirmations
$todayConfirmed = 0;
try {
    $tdStmt = $connection->prepare(
        "SELECT COUNT(*) AS cnt FROM enrollment WHERE Status = 'Officially Enrolled' AND school_year = ? AND Date_enrolled = CURDATE()"
    );
    $tdStmt->bind_param('s', $activeSchoolYearLabel);
    $tdStmt->execute();
    $todayConfirmed = (int) (stmt_fetch_assoc($tdStmt)['cnt'] ?? 0);
} catch (Throwable) {}

// Enrolled by department (Officially Enrolled, current S.Y.)
$enrolledByDept = ['Elementary' => 0, 'Junior High' => 0, 'Senior High' => 0];
try {
    $deptStmt = $connection->prepare(
        "SELECT Department, COUNT(*) AS cnt FROM enrollment
         WHERE Status = 'Officially Enrolled' AND school_year = ?
         GROUP BY Department"
    );
    $deptStmt->bind_param('s', $activeSchoolYearLabel);
    $deptStmt->execute();
    foreach (stmt_fetch_all_assoc($deptStmt) as $dr) {
        $enrolledByDept[(string) ($dr['Department'] ?? '')] = (int) $dr['cnt'];
    }
} catch (Throwable) {}

// New vs Returning (Officially Enrolled, current S.Y.)
$newStudentCount       = 0;
$returningStudentCount = 0;
try {
    $typeStmt = $connection->prepare(
        "SELECT IF(p.id IS NOT NULL, 'New', 'Returning') AS stype, COUNT(*) AS cnt
         FROM enrollment e
         LEFT JOIN preregistration p ON e.student_id = CAST(p.id AS CHAR)
         WHERE e.Status = 'Officially Enrolled' AND e.school_year = ?
         GROUP BY stype"
    );
    $typeStmt->bind_param('s', $activeSchoolYearLabel);
    $typeStmt->execute();
    foreach (stmt_fetch_all_assoc($typeStmt) as $tr) {
        if ($tr['stype'] === 'New') $newStudentCount = (int) $tr['cnt'];
        else $returningStudentCount = (int) $tr['cnt'];
    }
} catch (Throwable) {}

// Grade-level enrollment breakdown (Officially Enrolled, current S.Y.)
$gradeBreakdown = [];
try {
    $gbStmt = $connection->prepare(
        "SELECT e.Department,
                IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR)) AS grade,
                COUNT(*) AS cnt
         FROM enrollment e
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
         WHERE e.Status = 'Officially Enrolled' AND e.school_year = ?
         GROUP BY e.Department, e.Department_gradelevel
         ORDER BY e.Department, e.Department_gradelevel"
    );
    $gbStmt->bind_param('s', $activeSchoolYearLabel);
    $gbStmt->execute();
    $gradeBreakdown = stmt_fetch_all_assoc($gbStmt);
} catch (Throwable) {}

// Enrollment pipeline counts (all statuses, current S.Y.)
$pipelineCounts = [];
try {
    $plStmt = $connection->prepare(
        'SELECT Status, COUNT(*) AS cnt FROM enrollment WHERE school_year = ? GROUP BY Status'
    );
    $plStmt->bind_param('s', $activeSchoolYearLabel);
    $plStmt->execute();
    foreach (stmt_fetch_all_assoc($plStmt) as $pl) {
        $pipelineCounts[(string) $pl['Status']] = (int) $pl['cnt'];
    }
} catch (Throwable) {}

// Recent 8 confirmations
$recentConfirmed = [];
try {
    $rcStmt = $connection->prepare(
        "SELECT e.id, e.Department, e.Date_enrolled,
                IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR)) AS gradelevel_name,
                IFNULL(sc.Section_name, e.Department_section) AS section_name,
                COALESCE(
                    CONCAT(p.surname, ', ', p.firstname),
                    CONCAT(o.surname, ', ', o.firstname)
                ) AS full_name,
                IF(p.id IS NOT NULL, 'New', 'Returning') AS student_type
         FROM enrollment e
         LEFT JOIN preregistration p ON e.student_id = CAST(p.id AS CHAR)
         LEFT JOIN (
             SELECT ops.student_id, ops.surname, ops.firstname
             FROM old_studentprofile ops
             INNER JOIN (
                 SELECT student_id, MAX(id) AS latest_id FROM old_studentprofile GROUP BY student_id
             ) lx ON lx.latest_id = ops.id
         ) o ON (p.id IS NULL AND o.student_id = e.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
         LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = e.Department_section
         WHERE e.Status = 'Officially Enrolled' AND e.school_year = ?
         ORDER BY e.id DESC LIMIT 8"
    );
    $rcStmt->bind_param('s', $activeSchoolYearLabel);
    $rcStmt->execute();
    $recentConfirmed = stmt_fetch_all_assoc($rcStmt);
} catch (Throwable) {}

// Requirement submission counts (new students enrolled this S.Y., any status)
$reqCounts = ['total' => 0, 'psa' => 0, 'card' => 0, 'good_moral' => 0, 'id_pic' => 0];
try {
    $reqStmt = $connection->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN n.PSA        = 'Submitted' THEN 1 ELSE 0 END) AS psa,
                SUM(CASE WHEN n.Card       = 'Submitted' THEN 1 ELSE 0 END) AS card,
                SUM(CASE WHEN n.Good_moral = 'Submitted' THEN 1 ELSE 0 END) AS good_moral,
                SUM(CASE WHEN n.ID_pic     = 'Submitted' THEN 1 ELSE 0 END) AS id_pic
         FROM enrollment e
         INNER JOIN new_studentprofile n ON n.id = CAST(e.student_id AS SIGNED)
         WHERE e.school_year = ?"
    );
    $reqStmt->bind_param('s', $activeSchoolYearLabel);
    $reqStmt->execute();
    $reqRow = stmt_fetch_assoc($reqStmt);
    if ($reqRow) {
        $reqCounts['total']      = (int) ($reqRow['total']      ?? 0);
        $reqCounts['psa']        = (int) ($reqRow['psa']        ?? 0);
        $reqCounts['card']       = (int) ($reqRow['card']       ?? 0);
        $reqCounts['good_moral'] = (int) ($reqRow['good_moral'] ?? 0);
        $reqCounts['id_pic']     = (int) ($reqRow['id_pic']     ?? 0);
    }
} catch (Throwable) {}

$flash = flash_get();

// JSON-encode sections for JS dropdown dependency
$sectionsJson = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Dashboard helpers
$deptTotal    = array_sum($enrolledByDept);
$deptBarMax   = max(1, max($enrolledByDept));
$typeTotal    = $newStudentCount + $returningStudentCount;
$pipelineOrder = [
    'For Enrollment'             => ['color' => 'bg-slate-400',   'label' => 'For Enrollment'],
    'For Cashier Payment'        => ['color' => 'bg-amber-400',   'label' => 'For Cashier Payment'],
    'For Registrar Confirmation' => ['color' => 'bg-sky-500',     'label' => 'For Registrar Confirmation'],
    'For Madrasah Enrollment'    => ['color' => 'bg-violet-500',  'label' => 'For Madrasah Enrollment'],
    'Officially Enrolled'        => ['color' => 'bg-emerald-500', 'label' => 'Officially Enrolled'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrar | ITFA Enrollment System</title>
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
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8'
                        }
                    },
                    boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <!-- ── Sidebar ────────────────────────────────────────────── -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- ── Main content ───────────────────────────────────────── -->
    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Registrar</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Enrollment Confirmation</h2>
            <p class="text-slate-500 mt-2">Review pending records, confirm enrollment, and inspect each officially enrolled student's generated class schedule.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> · Class start: <?= h($activeClassStartLabel) ?></p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
            <article class="rounded-2xl bg-white border border-sky-200 p-5 shadow-panel flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-2xl bg-sky-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-sky-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="text-xs text-sky-600 font-semibold uppercase tracking-wide">Pending Confirmation</p>
                    <p class="text-3xl font-extrabold text-sky-800 mt-1"><?= h((string) $total) ?></p>
                    <p class="text-xs text-slate-400 mt-0.5">awaiting registrar sign-off</p>
                </div>
            </article>
            <article class="rounded-2xl bg-white border border-emerald-200 p-5 shadow-panel flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-2xl bg-emerald-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="text-xs text-emerald-600 font-semibold uppercase tracking-wide">Officially Enrolled</p>
                    <p class="text-3xl font-extrabold text-emerald-800 mt-1"><?= h((string) $officiallyEnrolled) ?></p>
                    <p class="text-xs text-slate-400 mt-0.5">confirmed this school year</p>
                </div>
            </article>
            <article class="rounded-2xl bg-white border border-green-200 p-5 shadow-panel flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-2xl bg-green-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </div>
                <div>
                    <p class="text-xs text-green-600 font-semibold uppercase tracking-wide">New Students</p>
                    <p class="text-3xl font-extrabold text-green-800 mt-1"><?= h((string) $newStudentCount) ?></p>
                    <p class="text-xs text-slate-400 mt-0.5"><?= h((string) $returningStudentCount) ?> returning enrolled</p>
                </div>
            </article>
            <article class="rounded-2xl bg-white border border-amber-200 p-5 shadow-panel flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-2xl bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <p class="text-xs text-amber-600 font-semibold uppercase tracking-wide">Confirmed Today</p>
                    <p class="text-3xl font-extrabold text-amber-800 mt-1"><?= h((string) $todayConfirmed) ?></p>
                    <p class="text-xs text-slate-400 mt-0.5"><?= h(date('F j, Y')) ?></p>
                </div>
            </article>
        </section>

        <!-- Search & Filter -->
        <form method="GET" action="" class="mb-4 flex flex-wrap gap-3">
            <input
                type="text"
                name="q"
                value="<?= h($search) ?>"
                placeholder="Search by name or LRN…"
                class="flex-1 min-w-[200px] rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
            >
            <select name="dept" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                <option value="">All Departments</option>
                <?php foreach (['Elementary', 'Junior High', 'Senior High'] as $dept): ?>
                    <option value="<?= h($dept) ?>" <?= $deptFilter === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700">Search</button>
            <?php if ($search !== '' || $deptFilter !== ''): ?>
                <a href="index.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Students Table -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-green-50 text-xs uppercase tracking-wide text-green-800">
                        <tr>
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">LRN / ID</th>
                            <th class="px-4 py-3 text-left">Department</th>
                            <th class="px-4 py-3 text-left">Grade / Section</th>
                            <th class="px-4 py-3 text-left">Classification</th>
                            <th class="px-4 py-3 text-left">Date Enrolled</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if ($students === []): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-slate-400">
                                    No students pending confirmation<?= ($search !== '' || $deptFilter !== '') ? ' for the current filter.' : ' for this school year.' ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($students as $row): ?>
                            <?php
                                $name       = trim((string) ($row['full_name'] ?? '')) ?: ('ID: ' . $row['student_id']);
                                $lrn        = trim((string) ($row['lrn'] ?? '-'));
                                $dept       = (string) ($row['Department'] ?? '-');
                                $grade      = trim((string) ($row['gradelevel_name'] ?? $row['Department_gradelevel'] ?? '-'));
                                $section    = trim((string) ($row['section_name'] ?? $row['Department_section'] ?? '-'));
                                $classTxt   = trim((string) ($row['classification_desc'] ?? 'N/A'));
                                $classType  = (string) ($row['classification_name'] ?? '');
                                $type       = (string) ($row['student_type'] ?? 'New');
                                $encId      = (int) $row['id'];
                                $dateEnrolled = (string) ($row['Date_enrolled'] ?? '');
                                $glId       = (int) ($row['Department_gradelevel'] ?? 0);
                                $secId      = (string) ($row['Department_section'] ?? '');
                                $classId    = (int) ($row['Student_classification'] ?? 0);
                            ?>
                            <tr class="hover:bg-green-50/40 transition-colors">
                                <td class="px-4 py-3 font-medium"><?= h($name) ?></td>
                                <td class="px-4 py-3 text-slate-500 font-mono text-xs"><?= h($lrn) ?></td>
                                <td class="px-4 py-3"><?= h($dept) ?></td>
                                <td class="px-4 py-3">
                                    <?= h($grade) ?>
                                    <?= $section !== '' && $section !== '-' ? '– ' . h($section) : '' ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $type === 'New' ? 'bg-emerald-100 text-emerald-700' : 'bg-green-100 text-green-700' ?>">
                                            <?= h($type) ?>
                                        </span>
                                        <span class="text-slate-500 text-xs"><?= h($classTxt ?: 'N/A') ?></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-500 text-xs">
                                    <?= $dateEnrolled ? h(date('M d, Y', strtotime($dateEnrolled))) : '—' ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <!-- Edit button -->
                                        <button
                                            type="button"
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                                'id'               => $encId,
                                                'name'             => $name,
                                                'department'       => $dept,
                                                'gradelevel_id'    => $glId,
                                                'section_id'       => $secId,
                                                'classification_id'=> $classId,
                                                'student_type'     => $type,
                                            ]), ENT_QUOTES) ?>)"
                                            class="rounded-xl border border-green-300 bg-green-50 text-green-700 px-3 py-1.5 text-xs font-semibold hover:bg-green-100 transition-colors">
                                            Edit
                                        </button>
                                        <!-- Confirm button -->
                                        <button
                                            type="button"
                                            onclick="openConfirmModal(<?= $encId ?>, <?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>)"
                                            class="rounded-xl bg-emerald-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700 transition-colors">
                                            Confirm
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ── DASHBOARD ──────────────────────────────────────── -->

        <!-- Enrollment Pipeline -->
        <section class="mt-6 rounded-3xl border border-slate-200 bg-white shadow-panel p-6">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold mb-4">Enrollment Pipeline — S.Y. <?= h($activeSchoolYearLabel) ?></p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <?php foreach ($pipelineOrder as $statusKey => $meta): ?>
                    <?php $cnt = $pipelineCounts[$statusKey] ?? 0; ?>
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="inline-block w-2.5 h-2.5 rounded-full <?= $meta['color'] ?>"></span>
                            <span class="text-xs font-semibold text-slate-600"><?= h($meta['label']) ?></span>
                        </div>
                        <p class="text-3xl font-extrabold text-slate-800"><?= $cnt ?></p>
                        <p class="text-xs text-slate-400 mt-0.5">students</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Department bars + New vs Returning -->
        <div class="mt-4 grid gap-4 lg:grid-cols-3">

            <!-- Department Breakdown -->
            <section class="lg:col-span-2 rounded-3xl border border-slate-200 bg-white shadow-panel p-6">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold mb-5">Enrolled by Department</p>
                <?php
                    $deptConfig = [
                        'Elementary'  => ['bar' => 'bg-green-500', 'light' => 'bg-green-50', 'text' => 'text-green-700'],
                        'Junior High' => ['bar' => 'bg-sky-500',    'light' => 'bg-sky-50',    'text' => 'text-sky-700'],
                        'Senior High' => ['bar' => 'bg-violet-500', 'light' => 'bg-violet-50', 'text' => 'text-violet-700'],
                    ];
                ?>
                <div class="space-y-4">
                    <?php foreach ($deptConfig as $deptName => $dc): ?>
                        <?php
                            $dCnt  = $enrolledByDept[$deptName] ?? 0;
                            $pct   = $deptBarMax > 0 ? round($dCnt / $deptBarMax * 100) : 0;
                            $share = $deptTotal > 0 ? round($dCnt / $deptTotal * 100) : 0;
                        ?>
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-sm font-semibold text-slate-700"><?= h($deptName) ?></span>
                                <span class="text-sm font-bold <?= $dc['text'] ?>"><?= $dCnt ?> <span class="text-xs font-normal text-slate-400">(<?= $share ?>%)</span></span>
                            </div>
                            <div class="w-full h-3 rounded-full <?= $dc['light'] ?>">
                                <div class="h-3 rounded-full <?= $dc['bar'] ?> transition-all" style="width:<?= $pct ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <p class="text-xs text-slate-400 pt-1">Total officially enrolled: <strong class="text-slate-600"><?= $deptTotal ?></strong></p>
                </div>
            </section>

            <!-- New vs Returning -->
            <section class="rounded-3xl border border-slate-200 bg-white shadow-panel p-6 flex flex-col">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold mb-5">New vs Returning</p>
                <?php
                    $newPct  = $typeTotal > 0 ? round($newStudentCount       / $typeTotal * 100) : 0;
                    $retPct  = $typeTotal > 0 ? round($returningStudentCount / $typeTotal * 100) : 0;
                    $circ    = 251.3;
                    $newDash = round($newPct / 100 * $circ, 1);
                ?>
                <div class="flex items-center justify-center flex-1 mb-4">
                    <svg viewBox="0 0 100 100" class="w-32 h-32 -rotate-90">
                        <circle cx="50" cy="50" r="40" fill="none" stroke="#dcedde" stroke-width="14"/>
                        <?php if ($typeTotal > 0): ?>
                        <circle cx="50" cy="50" r="40" fill="none" stroke="#2e8b57" stroke-width="14"
                                stroke-dasharray="<?= $newDash ?> <?= $circ - $newDash ?>"
                                stroke-dashoffset="0"/>
                        <?php endif; ?>
                    </svg>
                </div>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm text-slate-600">
                            <span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span> New
                        </span>
                        <span class="font-bold text-green-700"><?= $newStudentCount ?> <span class="text-xs font-normal text-slate-400">(<?= $newPct ?>%)</span></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm text-slate-600">
                            <span class="w-3 h-3 rounded-full bg-green-100 border border-green-300 inline-block"></span> Returning
                        </span>
                        <span class="font-bold text-slate-600"><?= $returningStudentCount ?> <span class="text-xs font-normal text-slate-400">(<?= $retPct ?>%)</span></span>
                    </div>
                </div>
            </section>
        </div>

        <!-- Grade-level breakdown + Requirements -->
        <div class="mt-4 grid gap-4 lg:grid-cols-2">

            <!-- Grade-level table -->
            <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100">
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold">Enrollment by Grade Level</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 text-left">Department</th>
                                <th class="px-5 py-3 text-left">Grade Level</th>
                                <th class="px-5 py-3 text-right">Enrolled</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($gradeBreakdown === []): ?>
                                <tr><td colspan="3" class="px-5 py-6 text-center text-slate-400">No data yet.</td></tr>
                            <?php else: ?>
                                <?php
                                    $gbMax = max(1, (int) max(array_column($gradeBreakdown, 'cnt')));
                                    $gbDeptColors = ['Elementary' => 'bg-green-400', 'Junior High' => 'bg-sky-400', 'Senior High' => 'bg-violet-400'];
                                ?>
                                <?php foreach ($gradeBreakdown as $gb): ?>
                                    <?php
                                        $gbDept  = (string) ($gb['Department'] ?? '');
                                        $gbGrade = (string) ($gb['grade'] ?? '—');
                                        $gbCnt   = (int) $gb['cnt'];
                                        $gbPct   = round($gbCnt / $gbMax * 100);
                                        $gbColor = $gbDeptColors[$gbDept] ?? 'bg-slate-400';
                                    ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-2.5 text-slate-500 text-xs"><?= h($gbDept) ?></td>
                                        <td class="px-5 py-2.5 font-semibold text-slate-800"><?= h($gbGrade) ?></td>
                                        <td class="px-5 py-2.5 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <div class="w-20 h-2 rounded-full bg-slate-100">
                                                    <div class="h-2 rounded-full <?= $gbColor ?>" style="width:<?= $gbPct ?>%"></div>
                                                </div>
                                                <span class="font-bold text-slate-800 w-6 text-right"><?= $gbCnt ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- New Student Requirements -->
            <section class="rounded-3xl border border-amber-200 bg-white shadow-panel overflow-hidden">
                <div class="px-6 py-4 border-b border-amber-100 bg-amber-50">
                    <p class="text-xs uppercase tracking-[0.2em] text-amber-700 font-semibold">Requirements — New Students</p>
                    <p class="text-xs text-amber-600 mt-1">Documents to collect upon enrollment confirmation</p>
                </div>
                <div class="p-5 grid gap-3">
                    <?php
                        $requirements = [
                            ['icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                             'title'     => 'PSA / NSO Birth Certificate',
                             'note'      => 'Original or authenticated copy',
                             'color'     => 'text-green-500 bg-green-50',
                             'bar_color' => 'bg-green-500',
                             'count_key' => 'psa'],
                            ['icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                             'title'     => 'Form 138 / Report Card',
                             'note'      => 'Most recent grade report (original + photocopy)',
                             'color'     => 'text-sky-500 bg-sky-50',
                             'bar_color' => 'bg-sky-500',
                             'count_key' => 'card'],
                            ['icon' => 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z',
                             'title'     => 'Certificate of Good Moral Character',
                             'note'      => 'Issued by previous school',
                             'color'     => 'text-emerald-500 bg-emerald-50',
                             'bar_color' => 'bg-emerald-500',
                             'count_key' => 'good_moral'],
                            ['icon' => 'M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z M15 13a3 3 0 11-6 0 3 3 0 016 0z',
                             'title'     => '2×2 ID Photo (3 pcs)',
                             'note'      => 'Recent, white background',
                             'color'     => 'text-violet-500 bg-violet-50',
                             'bar_color' => 'bg-violet-500',
                             'count_key' => 'id_pic'],
                            ['icon' => 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
                             'title'     => 'Medical / Health Certificate',
                             'note'      => 'Issued by a licensed physician',
                             'color'     => 'text-rose-500 bg-rose-50',
                             'bar_color' => null,
                             'count_key' => null],
                            ['icon' => 'M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2',
                             'title'     => "Parent / Guardian's Valid ID",
                             'note'      => 'Government-issued, photocopy',
                             'color'     => 'text-amber-500 bg-amber-50',
                             'bar_color' => null,
                             'count_key' => null],
                        ];
                    ?>
                    <?php foreach ($requirements as $req): ?>
                        <?php
                            $rqCount = isset($req['count_key']) ? ($reqCounts[$req['count_key']] ?? 0) : null;
                            $rqTotal = $reqCounts['total'];
                            $rqPct   = ($rqTotal > 0 && $rqCount !== null) ? min(100, (int) round($rqCount / $rqTotal * 100)) : 0;
                        ?>
                        <div class="flex items-start gap-3 rounded-2xl border border-slate-100 p-3">
                            <div class="flex-shrink-0 w-8 h-8 rounded-xl <?= $req['color'] ?> flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $req['icon'] ?>"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                    <p class="text-sm font-semibold text-slate-800"><?= h($req['title']) ?></p>
                                    <?php if ($rqCount !== null): ?>
                                        <span class="text-xs font-bold text-slate-700 whitespace-nowrap">
                                            <?= $rqCount ?><span class="font-normal text-slate-400"> / <?= $rqTotal ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5"><?= h($req['note']) ?></p>
                                <?php if ($rqCount !== null && $rqTotal > 0): ?>
                                    <div class="mt-1.5 h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full <?= h($req['bar_color']) ?>" style="width:<?= $rqPct ?>%"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- Recent Confirmations -->
        <section class="mt-4 rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold">Recently Confirmed</p>
                    <p class="text-sm text-slate-400 mt-0.5">Last 8 officially enrolled students this school year</p>
                </div>
                <a href="<?= h(app_url('registrar/reprint_schedule.php')) ?>" class="text-xs text-green-600 font-semibold hover:underline">View All →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 text-left">Student</th>
                            <th class="px-5 py-3 text-left">Department</th>
                            <th class="px-5 py-3 text-left">Grade / Section</th>
                            <th class="px-5 py-3 text-left">Date Confirmed</th>
                            <th class="px-5 py-3 text-center">Schedule</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if ($recentConfirmed === []): ?>
                            <tr><td colspan="5" class="px-5 py-8 text-center text-slate-400">No confirmed students yet this school year.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentConfirmed as $rc): ?>
                                <?php
                                    $rcName    = trim((string) ($rc['full_name'] ?? '—'));
                                    $rcDept    = (string) ($rc['Department'] ?? '—');
                                    $rcGrade   = trim((string) ($rc['gradelevel_name'] ?? '—'));
                                    $rcSection = trim((string) ($rc['section_name'] ?? ''));
                                    $rcType    = (string) ($rc['student_type'] ?? 'New');
                                    $rcDate    = (string) ($rc['Date_enrolled'] ?? '');
                                    $rcId      = (int) $rc['id'];
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3">
                                        <span class="font-semibold text-slate-800"><?= h($rcName) ?></span>
                                        <span class="ml-1.5 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold <?= $rcType === 'New' ? 'bg-emerald-100 text-emerald-700' : 'bg-green-100 text-green-700' ?>"><?= h($rcType) ?></span>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600"><?= h($rcDept) ?></td>
                                    <td class="px-5 py-3 text-slate-600">
                                        <?= h($rcGrade) ?><?= $rcSection !== '' && $rcSection !== '—' ? ' – ' . h($rcSection) : '' ?>
                                    </td>
                                    <td class="px-5 py-3 text-slate-500 text-xs"><?= $rcDate ? h(date('M j, Y', strtotime($rcDate))) : '—' ?></td>
                                    <td class="px-5 py-3 text-center">
                                        <a href="<?= h(app_url('registrar/class_schedule_print.php?enrollment_id=' . $rcId)) ?>"
                                           target="_blank"
                                           class="inline-flex items-center gap-1 rounded-xl bg-green-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-green-700 transition-colors">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                            </svg>
                                            Print
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────── -->
<div id="editModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between">
            <div>
                <h3 class="text-lg font-extrabold">Edit Enrollment Information</h3>
                <p id="editStudentName" class="text-sm text-slate-500 mt-1"></p>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="index.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_enrollment">
            <input type="hidden" name="enrollment_id" id="editEnrollmentId">
            <input type="hidden" name="student_type" id="editStudentType">

            <!-- Department -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Department</label>
                <select name="department" id="editDepartment" onchange="onDeptChange()"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="Elementary">Elementary</option>
                    <option value="Junior High">Junior High</option>
                    <option value="Senior High">Senior High</option>
                </select>
            </div>

            <!-- Grade Level -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Grade Level</label>
                <select name="gradelevel_id" id="editGradeLevel" onchange="onGradeLevelChange()"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select grade level —</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= h((string) $gl['Gradelevel_id']) ?>">
                            <?= h($gl['Gradelevel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Section -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Section <span class="text-slate-400 font-normal">(optional)</span></label>
                <select name="section_id" id="editSection"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select section —</option>
                </select>
            </div>

            <!-- Classification -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Classification</label>
                <select name="classification_id" id="editClassification"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select classification —</option>
                    <?php foreach ($classifications as $cl): ?>
                        <option value="<?= h((string) $cl['classification_id']) ?>"
                                data-type="<?= h($cl['type']) ?>">
                            <?= h($cl['classification']) ?> – <?= h($cl['description']) ?> (<?= h($cl['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                    Save Changes
                </button>
                <button type="button" onclick="closeEditModal()"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Confirm Modal ──────────────────────────────────────────────────────── -->
<div id="confirmModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-slate-100">
            <h3 class="text-lg font-extrabold">Confirm Enrollment</h3>
            <p class="text-sm text-slate-500 mt-1">You are about to officially enroll this student.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-slate-700 mb-4">
                Student: <strong id="confirmStudentName" class="text-slate-900"></strong>
            </p>
            <p class="text-sm text-slate-500 mb-6">
                This will mark the enrollment as <span class="font-semibold text-emerald-700">Officially Enrolled</span>.
                The student class schedule will open and print automatically after confirmation.
            </p>
            <form method="POST" action="index.php" class="flex gap-3" id="confirmForm" target="_blank" onsubmit="handleConfirmSubmit()">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="confirm_enrollment">
                <input type="hidden" name="enrollment_id" id="confirmEnrollmentId">
                <button type="submit"
                        class="flex-1 rounded-xl bg-emerald-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-emerald-700 transition-colors">
                    Yes, Confirm
                </button>
                <button type="button" onclick="closeConfirmModal()"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// ── Section data from PHP ─────────────────────────────────────────────────────
const allSections = <?= $sectionsJson ?>;

// ── Edit Modal ────────────────────────────────────────────────────────────────
function openEditModal(data) {
    document.getElementById('editEnrollmentId').value  = data.id;
    document.getElementById('editStudentName').textContent = data.name;
    document.getElementById('editStudentType').value   = data.student_type;

    // Set department
    const deptSel = document.getElementById('editDepartment');
    deptSel.value = data.department;

    // Set grade level
    const glSel = document.getElementById('editGradeLevel');
    glSel.value = data.gradelevel_id;

    // Populate sections for the selected grade level, then set current section
    populateSections(data.gradelevel_id, data.section_id);

    // Filter classifications by student type and set current
    filterClassifications(data.student_type, data.classification_id);

    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function onDeptChange() {
    // Reset grade level and section when dept changes
    document.getElementById('editGradeLevel').value = '';
    populateSections(null, null);
}

function onGradeLevelChange() {
    const glId = document.getElementById('editGradeLevel').value;
    populateSections(glId, null);
}

function populateSections(gradeLevelId, currentSectionId) {
    const sectionSel = document.getElementById('editSection');
    sectionSel.innerHTML = '<option value="">— Select section —</option>';
    if (!gradeLevelId) return;

    const filtered = allSections.filter(s => String(s.Gradelevel_id) === String(gradeLevelId));
    filtered.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.Section_id;
        opt.textContent = s.Section_name;
        if (currentSectionId !== null && String(s.Section_id) === String(currentSectionId)) {
            opt.selected = true;
        }
        sectionSel.appendChild(opt);
    });
}

function filterClassifications(studentType, currentClassId) {
    const clSel = document.getElementById('editClassification');
    const options = clSel.querySelectorAll('option[data-type]');
    options.forEach(opt => {
        const matches = opt.getAttribute('data-type') === studentType;
        opt.style.display = matches ? '' : 'none';
    });

    // Set selected value
    if (currentClassId) {
        // Try to find an option matching both id and type
        let found = false;
        options.forEach(opt => {
            if (opt.value === String(currentClassId) && opt.getAttribute('data-type') === studentType) {
                clSel.value = opt.value;
                found = true;
            }
        });
        if (!found) clSel.value = '';
    } else {
        clSel.value = '';
    }
}

// ── Confirm Modal ─────────────────────────────────────────────────────────────
function openConfirmModal(enrollmentId, studentName) {
    document.getElementById('confirmEnrollmentId').value     = enrollmentId;
    document.getElementById('confirmStudentName').textContent = studentName;
    document.getElementById('confirmModal').style.display    = 'flex';
}

function closeConfirmModal() {
    document.getElementById('confirmModal').style.display = 'none';
}

function handleConfirmSubmit() {
    closeConfirmModal();
    window.setTimeout(function () {
        window.location.reload();
    }, 700);

    return true;
}

// Close modals on backdrop click
['editModal', 'confirmModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function (e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});
</script>
</body>
</html>
