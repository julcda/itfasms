<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

function schedule_redirect_query(int $sectionId, int $semesterId): string
{
    $params = [];
    if ($sectionId > 0) {
        $params['section_id'] = $sectionId;
    }
    if ($semesterId > 0) {
        $params['semester_id'] = $semesterId;
    }

    return $params === [] ? 'schedule.php' : 'schedule.php?' . http_build_query($params);
}

if (!is_registrar_user($user)) {
    flash_set('error', 'Only Registrar users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

// ── Active school year ────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
$activeSchoolYearId    = 0;
try {
    $syStmt = $connection->prepare(
        'SELECT School_year_id, School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = $syStmt->get_result()->fetch_assoc();
    if ($syRow) {
        $activeSchoolYearId    = (int) $syRow['School_year_id'];
        $activeSchoolYearLabel = (string) $syRow['School_year'];
    }
} catch (Throwable) {}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . ((int) date('Y') + 1);
}

// ── Load lookup data ──────────────────────────────────────────────────────────
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

$teachers = [];
try {
    // Active teachers only — plus any inactive teacher still assigned to a class
    // this S.Y., who must stay selectable or the edit form would silently
    // reassign their class. See teacher_picker_options().
    require_once __DIR__ . '/../includes/teacher_service.php';
    $teachers = teacher_picker_options($connection, $activeSchoolYearId);
} catch (Throwable) {}

$strands = [];
try {
    $res = $connection->query('SELECT strand_id, strand FROM strand ORDER BY strand_id');
    $strands = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$semesters = [];
try {
    $res = $connection->query('SELECT Semester_id, Semester FROM semester ORDER BY Semester_id');
    $semesters = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$subjects = [];
try {
    $res = $connection->query(
        'SELECT Subject_id, subject_code, Subject_name, gradelevel_id, strand_id, semester_id, Max_capacity
         FROM subject ORDER BY gradelevel_id, semester_id, Subject_name'
    );
    $subjects = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$userAccounts = [];
try {
    $res = $connection->query(
        'SELECT user_id, username, first_name, last_name FROM user_account ORDER BY first_name, last_name'
    );
    $userAccounts = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── POST: handle add / edit / delete ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('schedule.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $postedSectionId = to_int($_POST['section_id'] ?? 0);
    $postedSemesterId = to_int($_POST['semester_id'] ?? 0);

    try {
        if ($action === 'add_class') {
            $schoolYearId  = $activeSchoolYearId;
            $semesterId    = to_int($_POST['semester_id']    ?? 0);
            $strandId      = to_int($_POST['strand_id']      ?? 0);
            $gradeLevelId  = to_int($_POST['gradelevel_id']  ?? 0);
            $sectionId     = to_int($_POST['section_id']     ?? 0);
            $subjectId     = to_int($_POST['subject_id']     ?? 0);
            $teacherId     = to_int($_POST['teacher_id']     ?? 0);
            $managedUserId = to_int($_POST['managed_user_id'] ?? 0);
            $time          = trim((string) ($_POST['time']   ?? ''));

            if ($semesterId <= 0 || $strandId <= 0 || $gradeLevelId <= 0 || $sectionId <= 0 || $subjectId <= 0 || $teacherId <= 0 || $managedUserId <= 0) {
                throw new RuntimeException('All fields are required, including the Managed By user.');
            }

            if ($time === '') {
                throw new RuntimeException('Please enter a time slot.');
            }

            // Check for duplicate subject in the same section/semester/year
            $dupStmt = $connection->prepare(
                'SELECT Class_id FROM classes
                 WHERE School_year_id = ? AND Semester_id = ? AND Section_id = ? AND Subject_id = ?
                 LIMIT 1'
            );
            $dupStmt->bind_param('iiii', $schoolYearId, $semesterId, $sectionId, $subjectId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('This subject is already scheduled for that section and semester.');
            }

            $classId = next_table_id($connection, 'classes', 'Class_id');
            $status = 1;
            $insStmt = $connection->prepare(
                'INSERT INTO classes
                 (Class_id, School_year_id, Semester_id, strand_id, GradeLevel_id, Section_id, Subject_id, Teacher_id, Time, Status, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insStmt->bind_param(
                'iiiiiiiisii',
                $classId, $schoolYearId, $semesterId, $strandId, $gradeLevelId,
                $sectionId, $subjectId, $teacherId, $time, $status, $managedUserId
            );
            $insStmt->execute();

            flash_set('success', 'Class added to the schedule.');
            redirect_to(schedule_redirect_query($sectionId, $semesterId));

        } elseif ($action === 'edit_class') {
            $classIdInput  = $_POST['class_id'] ?? null;
            $classId       = to_int($classIdInput);
            $teacherId     = to_int($_POST['teacher_id']      ?? 0);
            $subjectId     = to_int($_POST['subject_id']      ?? 0);
            $managedUserId = to_int($_POST['managed_user_id'] ?? 0);
            $time          = trim((string) ($_POST['time']    ?? ''));
            $sectionId     = to_int($_POST['section_id']      ?? 0);
            $semesterId    = to_int($_POST['semester_id']     ?? 0);

            if (!is_int_input($classIdInput) || $classId < 0 || $teacherId <= 0 || $subjectId <= 0) {
                throw new RuntimeException('Invalid class record.');
            }
            if ($time === '') {
                throw new RuntimeException('Please enter a time slot.');
            }

            $dupStmt = $connection->prepare(
                'SELECT Class_id FROM classes
                 WHERE School_year_id = ? AND Semester_id = ? AND Section_id = ? AND Subject_id = ? AND Class_id <> ?
                 LIMIT 1'
            );
            $dupStmt->bind_param('iiiii', $activeSchoolYearId, $semesterId, $sectionId, $subjectId, $classId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('This subject is already scheduled for that section and semester.');
            }

            if ($managedUserId > 0) {
                $updStmt = $connection->prepare(
                    "UPDATE classes SET Subject_id = ?, Teacher_id = ?, Time = ?, user_id = ? WHERE Class_id = ?"
                );
                $updStmt->bind_param('iisii', $subjectId, $teacherId, $time, $managedUserId, $classId);
            } else {
                $updStmt = $connection->prepare(
                    "UPDATE classes SET Subject_id = ?, Teacher_id = ?, Time = ? WHERE Class_id = ?"
                );
                $updStmt->bind_param('iisi', $subjectId, $teacherId, $time, $classId);
            }
            $updStmt->execute();

            flash_set('success', 'Class updated successfully.');
            redirect_to(schedule_redirect_query($sectionId, $semesterId));

        } elseif ($action === 'delete_class') {
            $classIdInput = $_POST['class_id'] ?? null;
            $classId    = to_int($classIdInput);
            $sectionId  = to_int($_POST['section_id']  ?? 0);
            $semesterId = to_int($_POST['semester_id'] ?? 0);

            if (!is_int_input($classIdInput) || $classId < 0) {
                throw new RuntimeException('Invalid class record.');
            }

            $delStmt = $connection->prepare('DELETE FROM classes WHERE Class_id = ?');
            $delStmt->bind_param('i', $classId);
            $delStmt->execute();

            flash_set('success', 'Class removed from the schedule.');
            redirect_to(schedule_redirect_query($sectionId, $semesterId));

        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
        redirect_to(schedule_redirect_query($postedSectionId, $postedSemesterId));
    }
}

// ── Selected section / semester from GET ─────────────────────────────────────
$selectedSectionId  = to_int($_GET['section_id']  ?? 0);
$selectedSemesterId = to_int($_GET['semester_id'] ?? 3); // default N/A

// Find selected section info
$selectedSection = null;
foreach ($sections as $sec) {
    if ((int) $sec['Section_id'] === $selectedSectionId) {
        $selectedSection = $sec;
        break;
    }
}

// ── Load existing classes for selected section ────────────────────────────────
$classes = [];
if ($selectedSectionId > 0 && $activeSchoolYearId > 0) {
    try {
        $clStmt = $connection->prepare(
            'SELECT c.Class_id, c.Time, c.Status,
                    c.Subject_id, c.Teacher_id, c.Semester_id, c.strand_id,
                    c.GradeLevel_id, c.Section_id, c.user_id,
                    sub.Subject_name, sub.subject_code,
                    t.Fullname AS teacher_name,
                    sem.Semester AS semester_name,
                    st.strand AS strand_name,
                    ua.username AS managed_by_username,
                    CONCAT(IFNULL(ua.first_name,\'\'), \' \', IFNULL(ua.last_name,\'\')) AS managed_by_name
             FROM classes c
             LEFT JOIN subject sub ON sub.Subject_id = c.Subject_id
             LEFT JOIN teacher t ON t.Teacher_id = c.Teacher_id
             LEFT JOIN semester sem ON sem.Semester_id = c.Semester_id
             LEFT JOIN strand st ON st.strand_id = c.strand_id
             LEFT JOIN user_account ua ON ua.user_id = c.user_id
             WHERE c.School_year_id = ? AND c.Section_id = ? AND c.Semester_id = ?
             ORDER BY STR_TO_DATE(TRIM(SUBSTRING_INDEX(c.Time, \'-\', 1)), \'%H:%i\'), sub.Subject_name'
        );
        $clStmt->bind_param('iii', $activeSchoolYearId, $selectedSectionId, $selectedSemesterId);
        $clStmt->execute();
        $classes = $clStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable) {}
}

// Stats: total scheduled sections this year
$totalScheduledSections = 0;
try {
    $statStmt = $connection->prepare(
        'SELECT COUNT(DISTINCT Section_id) AS cnt FROM classes WHERE School_year_id = ?'
    );
    $statStmt->bind_param('i', $activeSchoolYearId);
    $statStmt->execute();
    $totalScheduledSections = (int) ($statStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
} catch (Throwable) {}

$totalClasses = 0;
try {
    $statStmt2 = $connection->prepare(
        'SELECT COUNT(*) AS cnt FROM classes WHERE School_year_id = ?'
    );
    $statStmt2->bind_param('i', $activeSchoolYearId);
    $statStmt2->execute();
    $totalClasses = (int) ($statStmt2->get_result()->fetch_assoc()['cnt'] ?? 0);
} catch (Throwable) {}

$flash = flash_get();

// JSON for JS-side filtering
$subjectsJson      = json_encode($subjects,      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$sectionsJson      = json_encode($sections,      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$teachersJson      = json_encode($teachers,      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$userAccountsJson  = json_encode($userAccounts,  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Index grade level names for quick lookup in JS
$gradeLevelMap = [];
foreach ($gradeLevels as $gl) {
    $gradeLevelMap[(int) $gl['Gradelevel_id']] = $gl['Gradelevel'];
}
$gradeLevelMapJson = json_encode($gradeLevelMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class Scheduling | ITFA Enrollment System</title>
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
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Class Scheduling</h2>
            <p class="text-slate-500 mt-2">Manage class schedules per section. Assign subjects, teachers, and time slots for the active school year.</p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <section class="grid gap-4 sm:grid-cols-3 mb-6">
            <article class="rounded-2xl bg-white border border-green-200 p-5 shadow-panel">
                <p class="text-sm text-green-700">School Year</p>
                <p class="text-xl font-extrabold mt-2 text-green-800"><?= h($activeSchoolYearLabel) ?></p>
                <p class="text-xs text-slate-400 mt-1">active school year</p>
            </article>
            <article class="rounded-2xl bg-white border border-green-200 p-5 shadow-panel">
                <p class="text-sm text-green-700">Sections with Schedule</p>
                <p class="text-3xl font-extrabold mt-2 text-green-800"><?= h((string) $totalScheduledSections) ?></p>
                <p class="text-xs text-slate-400 mt-1">sections with at least one class</p>
            </article>
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-600">Total Classes</p>
                <p class="text-3xl font-extrabold mt-2"><?= h((string) $totalClasses) ?></p>
                <p class="text-xs text-slate-400 mt-1">class entries this year</p>
            </article>
        </section>

        <!-- Section Selector + Semester -->
        <form method="GET" action="schedule.php" class="mb-6 bg-white rounded-2xl border border-slate-200 p-5 shadow-panel flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Department / Grade Level</label>
                <select id="filterGradeLevel" onchange="filterSections()"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Grade Levels</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= h((string) $gl['Gradelevel_id']) ?>">
                            <?= h($gl['Gradelevel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Section</label>
                <select name="section_id" id="filterSection"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select a section —</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= h((string) $sec['Section_id']) ?>"
                                data-grade="<?= h((string) $sec['Gradelevel_id']) ?>"
                                <?= $selectedSectionId === (int) $sec['Section_id'] ? 'selected' : '' ?>>
                            <?= h($sec['Section_name']) ?> (<?= h($sec['Gradelevel'] ?? '-') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Semester</label>
                <select name="semester_id"
                        class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <?php foreach ($semesters as $sem): ?>
                        <option value="<?= h((string) $sem['Semester_id']) ?>"
                                <?= $selectedSemesterId === (int) $sem['Semester_id'] ? 'selected' : '' ?>>
                            <?= h($sem['Semester']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"
                    class="rounded-xl bg-green-600 text-white px-5 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                View Schedule
            </button>
        </form>

        <?php if ($selectedSection): ?>
        <!-- Section header -->
        <div class="mb-4 flex items-center justify-between flex-wrap gap-3">
            <div>
                <h3 class="text-lg font-extrabold text-slate-800">
                    <?= h($selectedSection['Section_name']) ?>
                    <span class="text-slate-400 font-normal text-sm ml-2"><?= h($selectedSection['Gradelevel'] ?? '') ?></span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Capacity: <?= h((string) $selectedSection['Capacity']) ?> •
                    Semester: <?php
                        foreach ($semesters as $sem) {
                            if ((int)$sem['Semester_id'] === $selectedSemesterId) {
                                echo h($sem['Semester']);
                                break;
                            }
                        }
                    ?>
                </p>
            </div>
            <button type="button"
                    onclick="openAddModal()"
                    class="rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Add Class
            </button>
        </div>

        <!-- Schedule Table -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-green-50 text-xs uppercase tracking-wide text-green-800">
                        <tr>
                            <th class="px-3 py-3 text-center w-10">#</th>
                            <th class="px-4 py-3 text-left">Time</th>
                            <th class="px-4 py-3 text-left">Subject</th>
                            <th class="px-4 py-3 text-left">Code</th>
                            <th class="px-4 py-3 text-left">Teacher</th>
                            <th class="px-4 py-3 text-left">Cluster</th>
                            <th class="px-4 py-3 text-left">Managed By</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if ($classes === []): ?>
                            <tr>
                                <td colspan="9" class="px-4 py-10 text-center text-slate-400">
                                    No classes scheduled for this section and semester yet.
                                    <button type="button" onclick="openAddModal()" class="ml-2 text-green-600 font-semibold hover:underline">Add one now →</button>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($classes as $_ci => $cls): ?>
                            <tr class="hover:bg-green-50/40 transition-colors">
                                <td class="px-3 py-3 text-center text-xs font-extrabold text-green-400"><?= $_ci + 1 ?></td>
                                <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-700 whitespace-nowrap">
                                    <?= h($cls['Time'] ?? '—') ?>
                                </td>
                                <td class="px-4 py-3 font-medium"><?= h($cls['Subject_name'] ?? '—') ?></td>
                                <td class="px-4 py-3 text-xs text-slate-500 font-mono"><?= h($cls['subject_code'] ?? '—') ?></td>
                                <td class="px-4 py-3 text-slate-700"><?= h($cls['teacher_name'] ?? '—') ?></td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-slate-100 text-slate-600">
                                        <?= h($cls['strand_name'] ?? '—') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600">
                                    <?php
                                        $mbName = trim((string)($cls['managed_by_name'] ?? ''));
                                        $mbUser = (string)($cls['managed_by_username'] ?? '');
                                    ?>
                                    <?php if ($mbUser !== ''): ?>
                                        <span class="font-mono font-semibold"><?= h($mbUser) ?></span>
                                        <?php if ($mbName && $mbName !== ' '): ?>
                                            <span class="block text-slate-400"><?= h($mbName) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-300">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ((int)($cls['Status'] ?? 0) === 1): ?>
                                        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-700">Active</span>
                                    <?php else: ?>
                                        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold bg-slate-100 text-slate-500">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button"
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                                    'class_id'      => (int) $cls['Class_id'],
                                                    'subject_id'    => (int) $cls['Subject_id'],
                                                    'teacher_id'    => (int) $cls['Teacher_id'],
                                                    'time'          => $cls['Time'],
                                                    'semester_id'   => (int) $cls['Semester_id'],
                                                    'section_id'    => (int) $cls['Section_id'],
                                                    'gradelevel_id' => (int) $cls['GradeLevel_id'],
                                                    'strand_id'     => (int) $cls['strand_id'],
                                                    'user_id'       => (int) $cls['user_id'],
                                                ]), ENT_QUOTES) ?>)"
                                                class="rounded-xl border border-green-300 bg-green-50 text-green-700 px-3 py-1.5 text-xs font-semibold hover:bg-green-100 transition-colors">
                                            Edit
                                        </button>
                                        <button type="button"
                                                onclick="openDeleteModal(<?= (int) $cls['Class_id'] ?>, <?= htmlspecialchars(json_encode($cls['Subject_name'] ?? ''), ENT_QUOTES) ?>, <?= $selectedSectionId ?>, <?= $selectedSemesterId ?>)"
                                                class="rounded-xl border border-rose-200 bg-rose-50 text-rose-600 px-3 py-1.5 text-xs font-semibold hover:bg-rose-100 transition-colors">
                                            Remove
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($classes !== []): ?>
            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 text-xs text-slate-500">
                <?= count($classes) ?> class<?= count($classes) !== 1 ? 'es' : '' ?> scheduled
            </div>
            <?php endif; ?>
        </section>

        <?php else: ?>
        <!-- Prompt to select a section -->
        <div class="rounded-3xl border border-dashed border-slate-300 bg-white/60 p-12 text-center text-slate-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
            </svg>
            <p class="font-semibold text-slate-500">Select a section above to view or manage its class schedule.</p>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- ── Add Class Modal ────────────────────────────────────────────────────── -->
<div id="addModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
            <div>
                <h3 class="text-lg font-extrabold">Add Class to Schedule</h3>
                <p class="text-sm text-slate-500 mt-1">
                    Section: <strong><?= $selectedSection ? h($selectedSection['Section_name']) : '—' ?></strong>
                </p>
            </div>
            <button type="button" onclick="closeAddModal()" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="schedule.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token"  value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"      value="add_class">
            <input type="hidden" name="section_id"  value="<?= $selectedSectionId ?>">
            <input type="hidden" name="gradelevel_id" id="addGradeLevel" value="<?= $selectedSection ? h((string)$selectedSection['Gradelevel_id']) : '' ?>">

            <!-- Semester -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Semester</label>
                <select name="semester_id" id="addSemester" onchange="filterAddSubjects()" required
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select semester —</option>
                    <?php foreach ($semesters as $sem): ?>
                        <option value="<?= h((string) $sem['Semester_id']) ?>"
                                <?= $selectedSemesterId === (int) $sem['Semester_id'] ? 'selected' : '' ?>>
                            <?= h($sem['Semester']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Cluster -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Cluster</label>
                <select name="strand_id" id="addStrand" onchange="filterAddSubjects()" required
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select cluster —</option>
                    <?php foreach ($strands as $st): ?>
                        <option value="<?= h((string) $st['strand_id']) ?>"><?= h($st['strand']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Subject (filtered by grade level, strand, semester) -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                <div class="ss-wrap relative" id="addSubjectSS">
                    <input type="text" class="ss-input w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 bg-white"
                           placeholder="Type to search subject..." autocomplete="off" spellcheck="false">
                    <input type="hidden" name="subject_id" class="ss-hidden">
                    <div class="ss-dropdown absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl max-h-52 overflow-y-auto z-[200] hidden"></div>
                </div>
                <p class="text-xs text-slate-400 mt-1">Filtered by grade level &amp; semester — type to search.</p>
            </div>

            <!-- Teacher -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Teacher</label>
                <div class="ss-wrap relative" id="addTeacherSS">
                    <input type="text" class="ss-input w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 bg-white"
                           placeholder="Type to search teacher..." autocomplete="off" spellcheck="false">
                    <input type="hidden" name="teacher_id" class="ss-hidden">
                    <div class="ss-dropdown absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl max-h-52 overflow-y-auto z-[200] hidden"></div>
                </div>
            </div>

            <!-- Time -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Time Slot</label>
                <input type="text" name="time" placeholder="e.g. 7:30-8:30 (Tuesday)"
                       autocomplete="off"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                <p class="text-xs text-slate-400 mt-1">Enter as displayed on the schedule (e.g. 7:30 - 8:30).</p>
            </div>

            <!-- Managed By (user_account) -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Managed By <span class="text-slate-400 font-normal">(Department Head / User)</span></label>
                <select name="managed_user_id" required
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select user —</option>
                    <?php foreach ($userAccounts as $ua): ?>
                        <option value="<?= h((string) $ua['user_id']) ?>">
                            <?= h($ua['username']) ?> — <?= h(trim($ua['first_name'] . ' ' . $ua['last_name'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                    Add to Schedule
                </button>
                <button type="button" onclick="closeAddModal()"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Class Modal ───────────────────────────────────────────────────── -->
<div id="editModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
            <div>
                <h3 class="text-lg font-extrabold">Edit Class</h3>
                <p class="text-sm text-slate-500 mt-1">Update subject, teacher, or time slot.</p>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="schedule.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token"    value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"        value="edit_class">
            <input type="hidden" name="class_id"      id="editClassId">
            <input type="hidden" name="section_id"    id="editSectionId">
            <input type="hidden" name="semester_id"   id="editSemesterId">
            <input type="hidden" name="gradelevel_id" id="editGradeLevel">
            <input type="hidden" name="strand_id"     id="editStrandId">

            <!-- Subject (all for this grade level) -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                <div class="ss-wrap relative" id="editSubjectSS">
                    <input type="text" class="ss-input w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 bg-white"
                           placeholder="Type to search subject..." autocomplete="off" spellcheck="false">
                    <input type="hidden" name="subject_id" class="ss-hidden">
                    <div class="ss-dropdown absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl max-h-52 overflow-y-auto z-[200] hidden"></div>
                </div>
            </div>

            <!-- Teacher -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Teacher</label>
                <div class="ss-wrap relative" id="editTeacherSS">
                    <input type="text" class="ss-input w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 bg-white"
                           placeholder="Type to search teacher..." autocomplete="off" spellcheck="false">
                    <input type="hidden" name="teacher_id" class="ss-hidden">
                    <div class="ss-dropdown absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl max-h-52 overflow-y-auto z-[200] hidden"></div>
                </div>
            </div>

            <!-- Time -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Time Slot</label>
                <input type="text" name="time" id="editTime" placeholder="e.g. 7:30-8:30 (Tuesday)"
                       autocomplete="off"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>

            <!-- Managed By -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Managed By</label>
                <select name="managed_user_id" id="editManagedUser"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select user —</option>
                    <?php foreach ($userAccounts as $ua): ?>
                        <option value="<?= h((string) $ua['user_id']) ?>">
                            <?= h($ua['username']) ?> — <?= h(trim($ua['first_name'] . ' ' . $ua['last_name'])) ?>
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

<!-- ── Delete Confirm Modal ───────────────────────────────────────────────── -->
<div id="deleteModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-slate-100">
            <h3 class="text-lg font-extrabold text-rose-700">Remove Class</h3>
            <p class="text-sm text-slate-500 mt-1">This will remove the class from the schedule.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-slate-700 mb-6">
                Remove <strong id="deleteSubjectName" class="text-slate-900"></strong> from the schedule?
            </p>
            <form method="POST" action="schedule.php" class="flex gap-3">
                <input type="hidden" name="csrf_token"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action"       value="delete_class">
                <input type="hidden" name="class_id"     id="deleteClassId">
                <input type="hidden" name="section_id"   id="deleteSectionId">
                <input type="hidden" name="semester_id"  id="deleteSemesterId">
                <button type="submit"
                        class="flex-1 rounded-xl bg-rose-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-rose-700 transition-colors">
                    Yes, Remove
                </button>
                <button type="button" onclick="closeDeleteModal()"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const allSubjects    = <?= $subjectsJson ?>;
const allSections    = <?= $sectionsJson ?>;
const allTeachers    = <?= $teachersJson ?>;
const gradeLevelMap  = <?= $gradeLevelMapJson ?>;

// ── Searchable Select ─────────────────────────────────────────────────────────
class SearchableSelect {
    constructor(wrapperId, options = []) {
        this.wrapper  = document.getElementById(wrapperId);
        this.input    = this.wrapper.querySelector('.ss-input');
        this.hidden   = this.wrapper.querySelector('.ss-hidden');
        this.dropdown = this.wrapper.querySelector('.ss-dropdown');
        this._options = options;
        this._bind();
    }
    _bind() {
        this.input.addEventListener('focus', () => { this._render(); this._open(); });
        this.input.addEventListener('input', () => { this._render(); this._open(); });
        document.addEventListener('mousedown', (e) => {
            if (!this.wrapper.contains(e.target)) this._close();
        });
    }
    _render() {
        const q        = this.input.value.trim().toLowerCase();
        const filtered = q ? this._options.filter(o => o.label.toLowerCase().includes(q)) : this._options;
        this.dropdown.innerHTML = '';
        if (filtered.length === 0) {
            this.dropdown.innerHTML = '<div class="px-3 py-2.5 text-sm text-slate-400 italic">No results found</div>';
            return;
        }
        filtered.forEach(o => {
            const d = document.createElement('div');
            d.className   = 'px-3 py-2.5 text-sm cursor-pointer hover:bg-green-50 transition-colors';
            d.textContent = o.label;
            d.addEventListener('mousedown', (e) => { e.preventDefault(); this._select(o); });
            this.dropdown.appendChild(d);
        });
    }
    _select(o) { this.input.value = o.label; this.hidden.value = o.value; this._close(); }
    _open()  { this.dropdown.classList.remove('hidden'); }
    _close() { this.dropdown.classList.add('hidden'); }
    setOptions(options) { this._options = options; this.input.value = ''; this.hidden.value = ''; }
    setValue(val) {
        const o = this._options.find(x => String(x.value) === String(val));
        if (o) { this.input.value = o.label; this.hidden.value = o.value; }
        else   { this.input.value = ''; this.hidden.value = ''; }
    }
    getValue() { return this.hidden.value; }
    clear()    { this.input.value = ''; this.hidden.value = ''; }
}

// ── Build option arrays ───────────────────────────────────────────────────────
function subjectLabel(s) {
    const code = (s.subject_code && s.subject_code.toLowerCase() !== 'n/a') ? ` (${s.subject_code})` : '';
    return s.Subject_name + code;
}
function teacherLabel(t) {
    // Inactive teachers only appear here when they still hold a class this S.Y.
    // (removing them would make the edit form silently reassign the class).
    // Flag them so the registrar knows to reassign.
    const flag = Number(t.is_inactive) === 1 ? '⚠ INACTIVE — ' : '';
    return flag + t.Fullname + (t.Designation ? ' — ' + t.Designation : '');
}
const teacherOptions = allTeachers.map(t => ({ value: t.Teacher_id, label: teacherLabel(t) }));

// Instances
let addSubjectSS, addTeacherSS, editSubjectSS, editTeacherSS;

function buildAddSubjectOptions() {
    return allSubjects.map(s => ({ value: s.Subject_id, label: subjectLabel(s) }));
}

function filterAddSubjects() {
    if (addSubjectSS) addSubjectSS.setOptions(buildAddSubjectOptions());
}

// ── Section filter by grade level ─────────────────────────────────────────────
function filterSections() {
    const glId    = document.getElementById('filterGradeLevel').value;
    const secSel  = document.getElementById('filterSection');
    secSel.querySelectorAll('option[data-grade]').forEach(opt => {
        opt.style.display = (!glId || opt.getAttribute('data-grade') === glId) ? '' : 'none';
    });
    const selected = secSel.querySelector('option[selected]');
    if (selected && selected.style.display === 'none') secSel.value = '';
}

// ── Add Modal ─────────────────────────────────────────────────────────────────
function openAddModal() {
    filterAddSubjects();
    if (addTeacherSS) addTeacherSS.setOptions(teacherOptions);
    document.getElementById('addModal').style.display = 'flex';
}
function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
}

// ── Edit Modal ────────────────────────────────────────────────────────────────
function openEditModal(data) {
    document.getElementById('editClassId').value    = data.class_id;
    document.getElementById('editSectionId').value  = data.section_id;
    document.getElementById('editSemesterId').value = data.semester_id;
    document.getElementById('editGradeLevel').value = data.gradelevel_id;
    document.getElementById('editStrandId').value   = data.strand_id;
    document.getElementById('editTime').value       = data.time;
    document.getElementById('editManagedUser').value = data.user_id ?? '';

    const subjectOpts = allSubjects.map(s => ({ value: s.Subject_id, label: subjectLabel(s) }));

    if (editSubjectSS) { editSubjectSS.setOptions(subjectOpts); editSubjectSS.setValue(data.subject_id); }
    if (editTeacherSS) { editTeacherSS.setOptions(teacherOptions); editTeacherSS.setValue(data.teacher_id); }

    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// ── Delete Modal ──────────────────────────────────────────────────────────────
function openDeleteModal(classId, subjectName, sectionId, semesterId) {
    document.getElementById('deleteClassId').value    = classId;
    document.getElementById('deleteSectionId').value  = sectionId;
    document.getElementById('deleteSemesterId').value = semesterId;
    document.getElementById('deleteSubjectName').textContent = subjectName;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

['addModal', 'editModal', 'deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});

window.addEventListener('DOMContentLoaded', function() {
    console.log('[Schedule] allSubjects loaded:', allSubjects.length, '| addGradeLevel:', document.getElementById('addGradeLevel').value);
    addSubjectSS  = new SearchableSelect('addSubjectSS',  buildAddSubjectOptions());
    addTeacherSS  = new SearchableSelect('addTeacherSS',  teacherOptions);
    editSubjectSS = new SearchableSelect('editSubjectSS', []);
    editTeacherSS = new SearchableSelect('editTeacherSS', teacherOptions);
    filterSections();
});
</script>
</body>
</html>
