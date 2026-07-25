<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

/**
 * Resolve a lookup row id, preferring a specific label when present.
 */
function resolve_lookup_id(mysqli $connection, string $table, string $idColumn, string $labelColumn, ?string $preferredLabel = null): ?int
{
    $sql = "SELECT `$idColumn` AS lookup_id, `$labelColumn` AS lookup_label FROM `$table` ORDER BY `$idColumn` LIMIT 200";
    $result = $connection->query($sql);
    if (!$result) {
        return null;
    }

    $rows = $result->fetch_all(MYSQLI_ASSOC);
    if ($rows === []) {
        return null;
    }

    if ($preferredLabel !== null) {
        foreach ($rows as $row) {
            if (strcasecmp(trim((string) ($row['lookup_label'] ?? '')), $preferredLabel) === 0) {
                return (int) $row['lookup_id'];
            }
        }
    }

    return (int) $rows[0]['lookup_id'];
}

/**
 * Split a full name into first, middle and last name placeholders for teacher rows.
 */
function split_full_name(string $fullName): array
{
    $normalized = preg_replace('/\s+/', ' ', trim($fullName)) ?? '';
    if ($normalized === '') {
        return ['', '', ''];
    }

    $parts = explode(' ', $normalized);
    if (count($parts) === 1) {
        return [$parts[0], '', ''];
    }

    $firstName = array_shift($parts) ?: '';
    $lastName = array_pop($parts) ?: '';
    $middleName = implode(' ', $parts);

    return [$firstName, $middleName, $lastName];
}

$isSuperAdmin = is_super_admin($user);
$canManage = is_depthead_user($user) || is_depthead_admin($user) || $isSuperAdmin;
if (!$canManage) {
    flash_set('error', 'Access denied. Department Head login required.');
    redirect_to(app_url('login.php'));
}

$tab         = $_GET['tab'] ?? 'semester';
$allowedTabs = ['semester', 'gradelevel', 'strand', 'section', 'subject', 'teacher', 'class'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'semester';
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to(app_url("depthead/manage.php?tab=$tab"));
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        switch ($action) {

            // ── Semester ──────────────────────────────────────────────────────
            case 'add_semester':
                $n = trim($_POST['semester_name'] ?? '');
                if ($n !== '') {
                    $st = $connection->prepare('INSERT INTO semester (Semester) VALUES (?)');
                    $st->bind_param('s', $n);
                    $st->execute();
                    flash_set('success', 'Semester added.');
                }
                break;

            case 'edit_semester':
                $id = to_int($_POST['semester_id'] ?? 0);
                $n  = trim($_POST['semester_name'] ?? '');
                if ($id > 0 && $n !== '') {
                    $st = $connection->prepare('UPDATE semester SET Semester = ? WHERE Semester_id = ?');
                    $st->bind_param('si', $n, $id);
                    $st->execute();
                    flash_set('success', 'Semester updated.');
                }
                break;

            case 'delete_semester':
                $id = to_int($_POST['semester_id'] ?? 0);
                if ($id > 0) {
                    $st = $connection->prepare('DELETE FROM semester WHERE Semester_id = ?');
                    $st->bind_param('i', $id);
                    $st->execute();
                    flash_set('success', 'Semester deleted.');
                }
                break;

            // ── Grade Level ───────────────────────────────────────────────────
            case 'add_gradelevel':
                $n = trim($_POST['gradelevel_name'] ?? '');
                if ($n !== '') {
                    $st = $connection->prepare('INSERT INTO gradelevel (Gradelevel) VALUES (?)');
                    $st->bind_param('s', $n);
                    $st->execute();
                    flash_set('success', 'Grade level added.');
                }
                break;

            case 'edit_gradelevel':
                $id = to_int($_POST['gradelevel_id'] ?? 0);
                $n  = trim($_POST['gradelevel_name'] ?? '');
                if ($id > 0 && $n !== '') {
                    $st = $connection->prepare('UPDATE gradelevel SET Gradelevel = ? WHERE Gradelevel_id = ?');
                    $st->bind_param('si', $n, $id);
                    $st->execute();
                    flash_set('success', 'Grade level updated.');
                }
                break;

            case 'delete_gradelevel':
                $id = to_int($_POST['gradelevel_id'] ?? 0);
                if ($id > 0) {
                    $st = $connection->prepare('DELETE FROM gradelevel WHERE Gradelevel_id = ?');
                    $st->bind_param('i', $id);
                    $st->execute();
                    flash_set('success', 'Grade level deleted.');
                }
                break;

            // ── Strand ────────────────────────────────────────────────────────
            case 'add_strand':
                $n = trim($_POST['strand_name'] ?? '');
                if ($n !== '') {
                    $st = $connection->prepare('INSERT INTO strand (strand) VALUES (?)');
                    $st->bind_param('s', $n);
                    $st->execute();
                    flash_set('success', 'Cluster added.');
                }
                break;

            case 'edit_strand':
                $id = to_int($_POST['strand_id'] ?? 0);
                $n  = trim($_POST['strand_name'] ?? '');
                if ($id > 0 && $n !== '') {
                    $st = $connection->prepare('UPDATE strand SET strand = ? WHERE strand_id = ?');
                    $st->bind_param('si', $n, $id);
                    $st->execute();
                    flash_set('success', 'Cluster updated.');
                }
                break;

            case 'delete_strand':
                $id = to_int($_POST['strand_id'] ?? 0);
                if ($id > 0) {
                    $st = $connection->prepare('DELETE FROM strand WHERE strand_id = ?');
                    $st->bind_param('i', $id);
                    $st->execute();
                    flash_set('success', 'Cluster deleted.');
                }
                break;

            // ── Section ───────────────────────────────────────────────────────
            case 'add_section':
                $n    = trim($_POST['section_name'] ?? '');
                $glId = to_int($_POST['gradelevel_id'] ?? 0);
                $cap  = to_int($_POST['capacity'] ?? 0);
                if ($n !== '' && $glId > 0) {
                    $adviserId = 0;
                    $st = $connection->prepare('INSERT INTO section (Section_name, Gradelevel_id, Capacity, Adviser) VALUES (?, ?, ?, ?)');
                    $st->bind_param('siii', $n, $glId, $cap, $adviserId);
                    $st->execute();
                    flash_set('success', 'Section added.');
                }
                break;

            case 'edit_section':
                $id   = to_int($_POST['section_id'] ?? 0);
                $n    = trim($_POST['section_name'] ?? '');
                $glId = to_int($_POST['gradelevel_id'] ?? 0);
                $cap  = to_int($_POST['capacity'] ?? 0);
                if ($id > 0 && $n !== '' && $glId > 0) {
                    $st = $connection->prepare('UPDATE section SET Section_name = ?, Gradelevel_id = ?, Capacity = ? WHERE Section_id = ?');
                    $st->bind_param('siii', $n, $glId, $cap, $id);
                    $st->execute();
                    flash_set('success', 'Section updated.');
                }
                break;

            case 'delete_section':
                $id = to_int($_POST['section_id'] ?? 0);
                if ($id > 0) {
                    $st = $connection->prepare('DELETE FROM section WHERE Section_id = ?');
                    $st->bind_param('i', $id);
                    $st->execute();
                    flash_set('success', 'Section deleted.');
                }
                break;

            // ── Subject ───────────────────────────────────────────────────────
            case 'add_subject':
                $code  = trim($_POST['subject_code'] ?? '');
                $n     = trim($_POST['subject_name'] ?? '');
                $glId  = to_int($_POST['gradelevel_id'] ?? 0);
                $stId  = to_int($_POST['strand_id'] ?? 0);
                $semId = to_int($_POST['semester_id'] ?? 0);
                $cap   = to_int($_POST['max_capacity'] ?? 0);
                if ($stId <= 0) {
                    $stId = resolve_lookup_id($connection, 'strand', 'strand_id', 'strand', 'N/A') ?? 0;
                }
                if ($semId <= 0) {
                    $semId = resolve_lookup_id($connection, 'semester', 'Semester_id', 'Semester', 'N/A') ?? 0;
                }
                if ($n !== '' && $glId > 0 && $stId > 0 && $semId > 0) {
                    $st = $connection->prepare(
                        'INSERT INTO subject (subject_code, Subject_name, gradelevel_id, strand_id, semester_id, Max_capacity)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $st->bind_param('ssiiii', $code, $n, $glId, $stId, $semId, $cap);
                    $st->execute();
                    flash_set('success', 'Subject added.');
                } else {
                    flash_set('error', 'Subject requires a grade level, cluster, and semester.');
                }
                break;

            case 'edit_subject':
                $id    = to_int($_POST['subject_id'] ?? 0);
                $code  = trim($_POST['subject_code'] ?? '');
                $n     = trim($_POST['subject_name'] ?? '');
                $glId  = to_int($_POST['gradelevel_id'] ?? 0);
                $stId  = to_int($_POST['strand_id'] ?? 0);
                $semId = to_int($_POST['semester_id'] ?? 0);
                $cap   = to_int($_POST['max_capacity'] ?? 0);
                if ($stId <= 0) {
                    $stId = resolve_lookup_id($connection, 'strand', 'strand_id', 'strand', 'N/A') ?? 0;
                }
                if ($semId <= 0) {
                    $semId = resolve_lookup_id($connection, 'semester', 'Semester_id', 'Semester', 'N/A') ?? 0;
                }
                if ($id > 0 && $n !== '' && $glId > 0 && $stId > 0 && $semId > 0) {
                    $st = $connection->prepare(
                        'UPDATE subject SET subject_code = ?, Subject_name = ?, gradelevel_id = ?,
                         strand_id = ?, semester_id = ?, Max_capacity = ? WHERE Subject_id = ?'
                    );
                    $st->bind_param('ssiiiii', $code, $n, $glId, $stId, $semId, $cap, $id);
                    $st->execute();
                    flash_set('success', 'Subject updated.');
                } else {
                    flash_set('error', 'Subject requires a grade level, cluster, and semester.');
                }
                break;

            case 'delete_subject':
                $id = to_int($_POST['subject_id'] ?? 0);
                if ($id > 0) {
                    $st = $connection->prepare('DELETE FROM subject WHERE Subject_id = ?');
                    $st->bind_param('i', $id);
                    $st->execute();
                    flash_set('success', 'Subject deleted.');
                }
                break;

            // ── Teacher ───────────────────────────────────────────────────────
            case 'add_teacher':
                $fn  = trim($_POST['fullname'] ?? '');
                $des = trim($_POST['designation'] ?? '');
                if ($fn !== '') {
                    [$firstName, $middleName, $lastName] = split_full_name($fn);
                    $password = '';
                    $signature = '';
                    $st = $connection->prepare(
                        'INSERT INTO teacher (Fullname, Firstname, Lastname, Middlename, Designation, Password, Signature)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->bind_param('sssssss', $fn, $firstName, $lastName, $middleName, $des, $password, $signature);
                    $st->execute();
                    flash_set('success', 'Teacher added.');
                }
                break;

            case 'edit_teacher':
                $id  = to_int($_POST['teacher_id'] ?? 0);
                $fn  = trim($_POST['fullname'] ?? '');
                $des = trim($_POST['designation'] ?? '');
                if ($id > 0 && $fn !== '') {
                    [$firstName, $middleName, $lastName] = split_full_name($fn);
                    $st = $connection->prepare(
                        'UPDATE teacher SET Fullname = ?, Firstname = ?, Lastname = ?, Middlename = ?, Designation = ? WHERE Teacher_id = ?'
                    );
                    $st->bind_param('sssssi', $fn, $firstName, $lastName, $middleName, $des, $id);
                    $st->execute();
                    flash_set('success', 'Teacher updated.');
                }
                break;

            case 'delete_teacher':
                $id = to_int($_POST['teacher_id'] ?? 0);
                if ($id > 0) {
                    $st = $connection->prepare('DELETE FROM teacher WHERE Teacher_id = ?');
                    $st->bind_param('i', $id);
                    $st->execute();
                    flash_set('success', 'Teacher deleted.');
                }
                break;

            // ── Class Schedule ─────────────────────────────────────────────────
            case 'add_class':
                if (!$canManage) { throw new RuntimeException('Access denied.'); }
                $classId = next_table_id($connection, 'classes', 'Class_id');
                $syId   = to_int($_POST['school_year_id'] ?? 0);
                $semId  = to_int($_POST['semester_id']    ?? 0);
                $stId   = to_int($_POST['strand_id']      ?? 0);
                $glId   = to_int($_POST['gradelevel_id']  ?? 0);
                $secId  = to_int($_POST['section_id']     ?? 0);
                $subId  = to_int($_POST['subject_id']     ?? 0);
                $tId    = to_int($_POST['teacher_id']     ?? 0);
                $time   = trim((string) ($_POST['time']   ?? ''));
                $cstat  = to_int($_POST['class_status']   ?? 1);
                $uid    = (int) ($user['id'] ?? 0);
                if ($glId > 0 && $subId > 0) {
                    $st = $connection->prepare(
                        'INSERT INTO classes (Class_id, School_year_id, Semester_id, strand_id, GradeLevel_id,
                         Section_id, Subject_id, Teacher_id, Time, Status, user_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->bind_param('iiiiiiiisii', $classId, $syId, $semId, $stId, $glId, $secId, $subId, $tId, $time, $cstat, $uid);
                    $st->execute();
                    flash_set('success', 'Class schedule added.');
                } else {
                    flash_set('error', 'Grade level and subject are required.');
                }
                break;

            case 'edit_class':
                if (!$canManage) { throw new RuntimeException('Access denied.'); }
                $classIdInput = $_POST['class_id'] ?? null;
                $id    = to_int($classIdInput);
                $syId  = to_int($_POST['school_year_id']  ?? 0);
                $semId = to_int($_POST['semester_id']     ?? 0);
                $stId  = to_int($_POST['strand_id']       ?? 0);
                $glId  = to_int($_POST['gradelevel_id']   ?? 0);
                $secId = to_int($_POST['section_id']      ?? 0);
                $subId = to_int($_POST['subject_id']      ?? 0);
                $tId   = to_int($_POST['teacher_id']      ?? 0);
                $time  = trim((string) ($_POST['time']    ?? ''));
                $cstat = to_int($_POST['class_status']    ?? 1);
                if (is_int_input($classIdInput) && $id >= 0 && $glId > 0 && $subId > 0) {
                    $st = $connection->prepare(
                        'UPDATE classes SET School_year_id=?, Semester_id=?, strand_id=?,
                         GradeLevel_id=?, Section_id=?, Subject_id=?, Teacher_id=?, Time=?, Status=?
                         WHERE Class_id=?'
                    );
                    $st->bind_param('iiiiiiisii', $syId, $semId, $stId, $glId, $secId, $subId, $tId, $time, $cstat, $id);
                    $st->execute();
                    flash_set('success', 'Class schedule updated.');
                } else {
                    flash_set('error', 'Grade level and subject are required.');
                }
                break;

            case 'delete_class':
                if (!$canManage) { throw new RuntimeException('Access denied.'); }
                $classIdInput = $_POST['class_id'] ?? null;
                $id = to_int($classIdInput);
                if (is_int_input($classIdInput) && $id >= 0) {
                    $st = $connection->prepare('DELETE FROM classes WHERE Class_id = ?');
                    $st->bind_param('i', $id);
                    $st->execute();
                    flash_set('success', 'Class schedule deleted.');
                }
                break;
        }
    } catch (Throwable $exception) {
        $actionType = strtok($action, '_') ?: '';
        if ($actionType === 'delete') {
            flash_set('error', 'Operation failed. The record may be referenced by other data.');
        } else {
            flash_set('error', 'Operation failed. Please check the required fields and database references.');
        }
    }

    redirect_to(app_url("depthead/manage.php?tab=$tab"));
}

// ── Load data ─────────────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
try {
    $sy = $connection->query('SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1');
    $activeSchoolYearLabel = $sy ? (string) ($sy->fetch_assoc()['School_year'] ?? '') : '';
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . ((int) date('Y') + 1);
}

// Always load FK lookups
$gradeLevels = [];
$strands     = [];
$semesters   = [];
try {
    $r = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $r = $connection->query('SELECT strand_id, strand FROM strand ORDER BY strand');
    $strands     = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $r = $connection->query('SELECT Semester_id, Semester FROM semester ORDER BY Semester_id');
    $semesters   = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// Management FK lookups (for class schedule tab)
$schoolYears = [];
$teachers    = [];
$subjects    = [];
$sections    = [];
if ($canManage) {
    try {
        $r = $connection->query('SELECT School_year_id, School_year FROM schoolyear ORDER BY School_year_id DESC');
        $schoolYears = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        // Assignment picker: Active teachers, plus any inactive one still holding
        // a class this S.Y. (removing them would silently reassign that class).
        require_once __DIR__ . '/../includes/teacher_service.php';
        $_sy      = teacher_active_sy($connection);
        $teachers = teacher_picker_options($connection, (int) $_sy['id']);
        $r = $connection->query('SELECT Subject_id, subject_code, Subject_name FROM subject ORDER BY Subject_name');
        $subjects    = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $r = $connection->query(
            'SELECT s.Section_id, s.Section_name, g.Gradelevel
             FROM section s LEFT JOIN gradelevel g ON g.Gradelevel_id = s.Gradelevel_id
             ORDER BY g.Gradelevel_id, s.Section_name'
        );
        $sections = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable) {}
}

// Pre-load class records when on class tab
$classRecords = [];
if ($canManage && $tab === 'class') {
    try {
        $r = $connection->query(
            'SELECT c.Class_id, c.School_year_id, c.Semester_id, c.strand_id, c.GradeLevel_id,
                    c.Section_id, c.Subject_id, c.Teacher_id, c.Time, c.Status,
                    sy.School_year, sem.Semester, st.strand,
                    g.Gradelevel, sec.Section_name,
                    sub.Subject_name, sub.subject_code, t.Fullname AS Teacher_name
             FROM classes c
             LEFT JOIN schoolyear sy  ON sy.School_year_id = c.School_year_id
             LEFT JOIN semester sem   ON sem.Semester_id   = c.Semester_id
             LEFT JOIN strand st      ON st.strand_id      = c.strand_id
             LEFT JOIN gradelevel g   ON g.Gradelevel_id   = c.GradeLevel_id
             LEFT JOIN section sec    ON sec.Section_id    = c.Section_id
             LEFT JOIN subject sub    ON sub.Subject_id    = c.Subject_id
             LEFT JOIN teacher t      ON t.Teacher_id      = c.Teacher_id
             ORDER BY sy.School_year_id DESC, g.Gradelevel_id, sec.Section_name'
        );
        $classRecords = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable) {}
}

// Tab-specific records
$records = [];
try {
    $records = match($tab) {
        'semester'   => ($r = $connection->query('SELECT Semester_id, Semester FROM semester ORDER BY Semester_id'))
                        ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'gradelevel' => ($r = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id'))
                        ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'strand'     => ($r = $connection->query('SELECT strand_id, strand FROM strand ORDER BY strand'))
                        ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'section'    => ($r = $connection->query(
                            'SELECT s.Section_id, s.Section_name, s.Gradelevel_id, s.Capacity, g.Gradelevel
                             FROM section s LEFT JOIN gradelevel g ON g.Gradelevel_id = s.Gradelevel_id
                             ORDER BY g.Gradelevel_id, s.Section_name'))
                        ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'subject'    => ($r = $connection->query(
                            'SELECT sub.Subject_id, sub.subject_code, sub.Subject_name,
                                    sub.gradelevel_id, sub.strand_id, sub.semester_id, sub.Max_capacity,
                                    g.Gradelevel, st.strand, sem.Semester
                             FROM subject sub
                             LEFT JOIN gradelevel g   ON g.Gradelevel_id = sub.gradelevel_id
                             LEFT JOIN strand st       ON st.strand_id    = sub.strand_id
                             LEFT JOIN semester sem    ON sem.Semester_id = sub.semester_id
                             ORDER BY g.Gradelevel_id, sub.Subject_name'))
                        ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'teacher'    => ($r = $connection->query('SELECT Teacher_id, Fullname, Designation FROM teacher ORDER BY Fullname'))
                        ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'class'      => $classRecords,
        default      => [],
    };
} catch (Throwable) {}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Management | Dept Head Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: { 50:'#f0f7f2', 100:'#dcedde', 300:'#86c294', 400:'#2e8b57', 500:'#2e8b57', 600:'#166534', 700:'#0f4d28' }
                    },
                    boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' },
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">

<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <!-- Sidebar -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- Main -->
    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Administration</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">System Management</h1>
                    <p class="text-slate-500 mt-2">Manage semesters, grade levels, clusters, sections, subjects, teachers, and class schedules.</p>
                    <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; Logged in as <?= h((string) ($user['full_name'] ?? '')) ?></p>
                </div>
                <?php
                $tabCounts = [
                    'semester'   => count($tab === 'semester'   ? $records : []),
                    'gradelevel' => count($tab === 'gradelevel' ? $records : []),
                    'section'    => count($tab === 'section'    ? $records : []),
                    'subject'    => count($tab === 'subject'    ? $records : []),
                    'teacher'    => count($tab === 'teacher'    ? $records : []),
                ];
                ?>
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-3">
                    <?php
                    $stats = [
                        ['label'=>'Subjects', 'tab'=>'subject', 'color'=>'blue'],
                        ['label'=>'Teachers', 'tab'=>'teacher', 'color'=>'green'],
                        ['label'=>'Sections', 'tab'=>'section', 'color'=>'violet'],
                    ];
                    foreach ($stats as $s):
                        $cnt = 0;
                        try {
                            $tbl = ['subject'=>'subject','teacher'=>'teacher','section'=>'section'][$s['tab']];
                            $r = $connection->query("SELECT COUNT(*) AS c FROM `$tbl`");
                            $cnt = (int) ($r ? $r->fetch_assoc()['c'] : 0);
                        } catch (Throwable) {}
                    ?>
                    <div class="rounded-2xl bg-<?= $s['color'] ?>-50 border border-<?= $s['color'] ?>-200 px-4 py-3">
                        <p class="text-xs text-<?= $s['color'] ?>-700"><?= $s['label'] ?></p>
                        <p class="text-xl font-extrabold text-<?= $s['color'] ?>-800 mt-0.5"><?= $cnt ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium flex items-center gap-3
            <?= $flash['type'] === 'success'
                ? 'bg-emerald-50 border-emerald-300 text-emerald-800'
                : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?php if ($flash['type'] === 'success'): ?>
            <svg class="w-5 h-5 flex-shrink-0 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <?php else: ?>
            <svg class="w-5 h-5 flex-shrink-0 text-rose-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
            <?php endif; ?>
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-panel border border-slate-100 overflow-hidden">

            <!-- Tab bar + Add button -->
            <div class="px-6 pt-5 pb-0 border-b border-slate-100">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex gap-1 bg-slate-100 rounded-2xl p-1 flex-wrap">
                        <?php
                        $tabLabels = [
                            'semester'   => 'Semester',
                            'gradelevel' => 'Grade Level',
                            'strand'     => 'Cluster',
                            'section'    => 'Section',
                            'subject'    => 'Subject',
                            'teacher'    => 'Teacher',
                            'class'      => 'Class Schedule',
                        ];
                        foreach ($tabLabels as $key => $label):
                            $active = $tab === $key;
                        ?>
                        <a href="manage.php?tab=<?= $key ?>"
                           class="px-4 py-1.5 rounded-xl text-sm font-semibold transition-colors whitespace-nowrap
                                  <?= $active ? 'bg-white text-green-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
                            <?= $label ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="openAddModal()"
                            class="flex items-center gap-2 bg-green-700 hover:bg-green-800 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors shadow-sm ml-3 whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        Add <?= h($tabLabels[$tab]) ?>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
            <?php if (empty($records)): ?>
                <div class="flex flex-col items-center justify-center py-20 text-slate-400">
                    <svg class="w-14 h-14 mb-4 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                    </svg>
                    <p class="font-semibold text-slate-500 text-base">No <?= h(strtolower($tabLabels[$tab])) ?>s yet</p>
                    <p class="text-sm mt-1">Click <strong>Add <?= h($tabLabels[$tab]) ?></strong> to create the first one.</p>
                </div>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50/80 text-xs uppercase tracking-wider text-slate-400 font-semibold">
                    <?php if ($tab === 'semester'): ?>
                        <th class="px-6 py-3.5 text-left w-16">#</th>
                        <th class="px-6 py-3.5 text-left">Semester Name</th>
                    <?php elseif ($tab === 'gradelevel'): ?>
                        <th class="px-6 py-3.5 text-left w-16">#</th>
                        <th class="px-6 py-3.5 text-left">Grade Level</th>
                    <?php elseif ($tab === 'strand'): ?>
                        <th class="px-6 py-3.5 text-left w-16">#</th>
                        <th class="px-6 py-3.5 text-left">Cluster</th>
                    <?php elseif ($tab === 'section'): ?>
                        <th class="px-6 py-3.5 text-left w-16">#</th>
                        <th class="px-6 py-3.5 text-left">Section Name</th>
                        <th class="px-6 py-3.5 text-left">Grade Level</th>
                        <th class="px-6 py-3.5 text-left">Capacity</th>
                    <?php elseif ($tab === 'subject'): ?>
                        <th class="px-6 py-3.5 text-left w-16">#</th>
                        <th class="px-6 py-3.5 text-left">Code</th>
                        <th class="px-6 py-3.5 text-left">Subject Name</th>
                        <th class="px-6 py-3.5 text-left">Grade Level</th>
                        <th class="px-6 py-3.5 text-left">Cluster</th>
                        <th class="px-6 py-3.5 text-left">Semester</th>
                        <th class="px-6 py-3.5 text-left">Max</th>
                    <?php elseif ($tab === 'teacher'): ?>
                        <th class="px-6 py-3.5 text-left w-16">#</th>
                        <th class="px-6 py-3.5 text-left">Full Name</th>
                        <th class="px-6 py-3.5 text-left">Designation</th>
                    <?php elseif ($tab === 'class'): ?>
                        <th class="px-6 py-3.5 text-left w-12">#</th>
                        <th class="px-6 py-3.5 text-left">School Year</th>
                        <th class="px-6 py-3.5 text-left">Grade Level</th>
                        <th class="px-6 py-3.5 text-left">Section</th>
                        <th class="px-6 py-3.5 text-left">Subject</th>
                        <th class="px-6 py-3.5 text-left">Teacher</th>
                        <th class="px-6 py-3.5 text-left">Time</th>
                        <th class="px-6 py-3.5 text-left">Status</th>
                    <?php endif; ?>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                <?php foreach ($records as $i => $row): ?>
                    <tr class="hover:bg-green-50/40 transition-colors group">
                    <?php if ($tab === 'semester'): ?>
                        <td class="px-6 py-3.5 text-xs text-slate-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-6 py-3.5">
                            <span class="inline-flex items-center gap-2 font-semibold">
                                <span class="w-2 h-2 rounded-full bg-green-400 flex-shrink-0"></span>
                                <?= h($row['Semester']) ?>
                            </span>
                        </td>
                    <?php elseif ($tab === 'gradelevel'): ?>
                        <td class="px-6 py-3.5 text-xs text-slate-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-6 py-3.5 font-semibold"><?= h($row['Gradelevel']) ?></td>
                    <?php elseif ($tab === 'strand'): ?>
                        <td class="px-6 py-3.5 text-xs text-slate-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-6 py-3.5">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 border border-green-100">
                                <?= h($row['strand']) ?>
                            </span>
                        </td>
                    <?php elseif ($tab === 'section'): ?>
                        <td class="px-6 py-3.5 text-xs text-slate-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-6 py-3.5 font-semibold"><?= h($row['Section_name']) ?></td>
                        <td class="px-6 py-3.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-green-50 text-green-700">
                                <?= h($row['Gradelevel'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-6 py-3.5 text-slate-500"><?= h((string) $row['Capacity']) ?> students</td>
                    <?php elseif ($tab === 'subject'): ?>
                        <td class="px-6 py-3.5 text-xs text-slate-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-6 py-3.5">
                            <span class="font-mono text-xs text-slate-500 bg-slate-100 px-2 py-0.5 rounded-lg"><?= h($row['subject_code'] ?? '—') ?></span>
                        </td>
                        <td class="px-6 py-3.5 font-semibold text-slate-800"><?= h($row['Subject_name']) ?></td>
                        <td class="px-6 py-3.5 text-xs text-slate-500"><?= h($row['Gradelevel'] ?? '—') ?></td>
                        <td class="px-6 py-3.5 text-xs text-slate-500"><?= h($row['strand'] ?? '—') ?></td>
                        <td class="px-6 py-3.5 text-xs text-slate-500"><?= h($row['Semester'] ?? '—') ?></td>
                        <td class="px-6 py-3.5 text-xs text-slate-500"><?= h((string) ($row['Max_capacity'] ?? '—')) ?></td>
                    <?php elseif ($tab === 'teacher'): ?>
                        <td class="px-6 py-3.5 text-xs text-slate-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-green-100 text-green-700 flex items-center justify-center text-xs font-extrabold flex-shrink-0">
                                    <?= strtoupper(mb_substr($row['Fullname'], 0, 1)) ?>
                                </div>
                                <span class="font-semibold"><?= h($row['Fullname']) ?></span>
                            </div>
                        </td>
                        <td class="px-6 py-3.5 text-slate-500"><?= h($row['Designation'] ?? '—') ?></td>
                    <?php elseif ($tab === 'class'): ?>
                        <td class="px-6 py-3.5 text-xs text-slate-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-6 py-3.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-green-50 text-green-700">
                                <?= h($row['School_year'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-6 py-3.5 text-xs text-slate-600"><?= h($row['Gradelevel'] ?? '—') ?></td>
                        <td class="px-6 py-3.5 font-semibold text-slate-800"><?= h($row['Section_name'] ?? '—') ?></td>
                        <td class="px-6 py-3.5">
                            <div class="text-sm font-semibold text-slate-800"><?= h($row['Subject_name'] ?? '—') ?></div>
                            <?php if (!empty($row['subject_code'])): ?>
                            <div class="font-mono text-xs text-slate-400"><?= h($row['subject_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3.5 text-sm text-slate-600"><?= h($row['Teacher_name'] ?? '—') ?></td>
                        <td class="px-6 py-3.5 text-xs text-slate-500"><?= h($row['Time'] ?? '—') ?></td>
                        <td class="px-6 py-3.5">
                            <?php if ((int)($row['Status'] ?? 1) === 1): ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-100">Active</span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-500 border border-slate-200">Inactive</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                        <td class="px-6 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-60 group-hover:opacity-100 transition-opacity">
                                <button data-edit='<?= h(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                                        onclick="openEditModal(JSON.parse(this.dataset.edit))"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-green-700 bg-green-50 hover:bg-green-100 border border-green-100 transition-colors" title="Edit">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
                                    </svg>
                                    Edit
                                </button>
                                <button data-edit='<?= h(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>'
                                        onclick="openDeleteModal(JSON.parse(this.dataset.edit))"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-100 transition-colors" title="Delete">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                                    </svg>
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="px-6 py-3 border-t border-slate-50 bg-slate-50/50 text-xs text-slate-400">
                <?= count($records) ?> record<?= count($records) !== 1 ? 's' : '' ?> total
            </div>
            <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<!-- ── Modals ─────────────────────────────────────────────────────────────── -->

<!-- Add Modal -->
<div id="addModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white rounded-t-3xl z-10">
            <div>
                <p class="text-xs uppercase tracking-widest text-green-600 font-semibold">Management</p>
                <h3 class="text-lg font-extrabold mt-0.5">Add <?= h($tabLabels[$tab]) ?></h3>
            </div>
            <button onclick="closeAddModal()" class="w-8 h-8 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="manage.php?tab=<?= h($tab) ?>" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"     value="add_<?= h($tab) ?>">

            <?php if ($tab === 'semester'): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Semester Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="semester_name" required placeholder="e.g. 1st Semester"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'gradelevel'): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="gradelevel_name" required placeholder="e.g. Grade 7"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'strand'): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Cluster Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="strand_name" required placeholder="e.g. STEM"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'section'): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Section Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="section_name" required placeholder="e.g. Narra"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level <span class="text-rose-400">*</span></label>
                    <select name="gradelevel_id" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="">— Select grade level —</option>
                        <?php foreach ($gradeLevels as $gl): ?>
                            <option value="<?= h((string) $gl['Gradelevel_id']) ?>"><?= h($gl['Gradelevel']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Capacity</label>
                    <input type="number" name="capacity" min="0" value="40"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'subject'): ?>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Subject Code</label>
                        <input type="text" name="subject_code" placeholder="e.g. MATH01"
                               class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Max Capacity</label>
                        <input type="number" name="max_capacity" min="0" value="40"
                               class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Subject Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="subject_name" required placeholder="e.g. Mathematics"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level <span class="text-rose-400">*</span></label>
                    <select name="gradelevel_id" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="">— Select grade level —</option>
                        <?php foreach ($gradeLevels as $gl): ?>
                            <option value="<?= h((string) $gl['Gradelevel_id']) ?>"><?= h($gl['Gradelevel']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Cluster <span class="text-rose-400">*</span></label>
                        <select name="strand_id" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="">— Select cluster —</option>
                            <?php foreach ($strands as $st): ?>
                                <option value="<?= h((string) $st['strand_id']) ?>"><?= h($st['strand']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Semester <span class="text-rose-400">*</span></label>
                        <select name="semester_id" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="">— Select semester —</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= h((string) $sem['Semester_id']) ?>"><?= h($sem['Semester']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

            <?php elseif ($tab === 'teacher'): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Full Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="fullname" required placeholder="e.g. Juan dela Cruz"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Designation <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="text" name="designation" placeholder="e.g. Science Teacher"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'class'): ?>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">School Year</label>
                        <select name="school_year_id" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($schoolYears as $sy): ?>
                                <option value="<?= h((string) $sy['School_year_id']) ?>"><?= h($sy['School_year']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Semester</label>
                        <select name="semester_id" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= h((string) $sem['Semester_id']) ?>"><?= h($sem['Semester']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level <span class="text-rose-400">*</span></label>
                        <select name="gradelevel_id" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="">— Select —</option>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= h((string) $gl['Gradelevel_id']) ?>"><?= h($gl['Gradelevel']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Section</label>
                        <select name="section_id" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= h((string) $sec['Section_id']) ?>"><?= h(($sec['Gradelevel'] ? $sec['Gradelevel'].' - ' : '').$sec['Section_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Subject <span class="text-rose-400">*</span></label>
                    <select name="subject_id" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="">— Select subject —</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= h((string) $sub['Subject_id']) ?>"><?= h(($sub['subject_code'] ? '['.$sub['subject_code'].'] ' : '').$sub['Subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Teacher</label>
                    <select name="teacher_id" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="0">— Select teacher —</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= h((string) $t['Teacher_id']) ?>"><?= h($t['Fullname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Cluster</label>
                        <select name="strand_id" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($strands as $st): ?>
                                <option value="<?= h((string) $st['strand_id']) ?>"><?= h($st['strand']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Status</label>
                        <select name="class_status" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Time / Schedule</label>
                    <input type="text" name="time" autocomplete="off" placeholder="e.g. 7:30-8:30 (Tuesday)"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
            <?php endif; ?>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <button type="button" onclick="closeAddModal()"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-semibold transition-colors shadow-sm">
                    Save <?= h($tabLabels[$tab]) ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white rounded-t-3xl z-10">
            <div>
                <p class="text-xs uppercase tracking-widest text-green-600 font-semibold">Management</p>
                <h3 class="text-lg font-extrabold mt-0.5">Edit <?= h($tabLabels[$tab]) ?></h3>
            </div>
            <button onclick="closeEditModal()" class="w-8 h-8 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="manage.php?tab=<?= h($tab) ?>" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"     value="edit_<?= h($tab) ?>">

            <?php if ($tab === 'semester'): ?>
                <input type="hidden" name="semester_id" id="editId">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Semester Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="semester_name" id="editField1" required
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'gradelevel'): ?>
                <input type="hidden" name="gradelevel_id" id="editId">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="gradelevel_name" id="editField1" required
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'strand'): ?>
                <input type="hidden" name="strand_id" id="editId">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Cluster Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="strand_name" id="editField1" required
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'section'): ?>
                <input type="hidden" name="section_id" id="editId">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Section Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="section_name" id="editField1" required
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level <span class="text-rose-400">*</span></label>
                    <select name="gradelevel_id" id="editGradeLevel" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="">— Select —</option>
                        <?php foreach ($gradeLevels as $gl): ?>
                            <option value="<?= h((string) $gl['Gradelevel_id']) ?>"><?= h($gl['Gradelevel']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Capacity</label>
                    <input type="number" name="capacity" id="editCapacity" min="0"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'subject'): ?>
                <input type="hidden" name="subject_id" id="editId">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Subject Code</label>
                        <input type="text" name="subject_code" id="editSubjectCode"
                               class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Max Capacity</label>
                        <input type="number" name="max_capacity" id="editMaxCap" min="0"
                               class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Subject Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="subject_name" id="editField1" required
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level <span class="text-rose-400">*</span></label>
                    <select name="gradelevel_id" id="editGradeLevel" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="">— Select grade level —</option>
                        <?php foreach ($gradeLevels as $gl): ?>
                            <option value="<?= h((string) $gl['Gradelevel_id']) ?>"><?= h($gl['Gradelevel']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Cluster <span class="text-rose-400">*</span></label>
                        <select name="strand_id" id="editStrand" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="">— Select cluster —</option>
                            <?php foreach ($strands as $st): ?>
                                <option value="<?= h((string) $st['strand_id']) ?>"><?= h($st['strand']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Semester <span class="text-rose-400">*</span></label>
                        <select name="semester_id" id="editSemester" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="">— Select semester —</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= h((string) $sem['Semester_id']) ?>"><?= h($sem['Semester']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

            <?php elseif ($tab === 'teacher'): ?>
                <input type="hidden" name="teacher_id" id="editId">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Full Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="fullname" id="editField1" required
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Designation</label>
                    <input type="text" name="designation" id="editField2"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>

            <?php elseif ($tab === 'class'): ?>
                <input type="hidden" name="class_id" id="editId">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">School Year</label>
                        <select name="school_year_id" id="editSchoolYear" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($schoolYears as $sy): ?>
                                <option value="<?= h((string) $sy['School_year_id']) ?>"><?= h($sy['School_year']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Semester</label>
                        <select name="semester_id" id="editSemester" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= h((string) $sem['Semester_id']) ?>"><?= h($sem['Semester']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Grade Level <span class="text-rose-400">*</span></label>
                        <select name="gradelevel_id" id="editGradeLevel" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="">— Select —</option>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= h((string) $gl['Gradelevel_id']) ?>"><?= h($gl['Gradelevel']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Section</label>
                        <select name="section_id" id="editSection" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= h((string) $sec['Section_id']) ?>"><?= h(($sec['Gradelevel'] ? $sec['Gradelevel'].' - ' : '').$sec['Section_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Subject <span class="text-rose-400">*</span></label>
                    <select name="subject_id" id="editSubject" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="">— Select subject —</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= h((string) $sub['Subject_id']) ?>"><?= h(($sub['subject_code'] ? '['.$sub['subject_code'].'] ' : '').$sub['Subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Teacher</label>
                    <select name="teacher_id" id="editTeacher" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                        <option value="0">— Select teacher —</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= h((string) $t['Teacher_id']) ?>"><?= h($t['Fullname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Cluster</label>
                        <select name="strand_id" id="editStrand" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="0">— Select —</option>
                            <?php foreach ($strands as $st): ?>
                                <option value="<?= h((string) $st['strand_id']) ?>"><?= h($st['strand']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Status</label>
                        <select name="class_status" id="editClassStatus" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent bg-white">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Time / Schedule</label>
                    <input type="text" name="time" id="editTime" autocomplete="off" placeholder="e.g. 7:30-8:30 (Tuesday)"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent">
                </div>
            <?php endif; ?>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="px-5 py-2.5 rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-semibold transition-colors shadow-sm">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm p-8 text-center">
        <div class="w-16 h-16 rounded-2xl bg-rose-100 flex items-center justify-center mx-auto mb-5">
            <svg class="w-8 h-8 text-rose-500" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
            </svg>
        </div>
        <h3 class="text-xl font-extrabold mb-1">Delete <?= h($tabLabels[$tab]) ?>?</h3>
        <p class="text-slate-500 text-sm mb-1">You are about to delete:</p>
        <p class="font-extrabold text-slate-800 text-base mb-4 px-4" id="deleteLabel">—</p>
        <p class="text-xs text-rose-500 mb-6 bg-rose-50 border border-rose-100 rounded-xl px-4 py-2.5">
            This cannot be undone. Other records that reference this item may be affected.
        </p>
        <form method="POST" action="manage.php?tab=<?= h($tab) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"     value="delete_<?= h($tab) ?>">
            <input type="hidden" name="<?= h($tab) ?>_id" id="deleteId">
            <div class="flex gap-3">
                <button type="button" onclick="closeDeleteModal()"
                        class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold transition-colors shadow-sm">
                    Yes, Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const TAB = '<?= h($tab) ?>';

function openAddModal()  { document.getElementById('addModal').style.display = 'flex'; }
function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }

function openEditModal(data) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };

    if (TAB === 'semester') {
        set('editId',     data.Semester_id);
        set('editField1', data.Semester);
    } else if (TAB === 'gradelevel') {
        set('editId',     data.Gradelevel_id);
        set('editField1', data.Gradelevel);
    } else if (TAB === 'strand') {
        set('editId',     data.strand_id);
        set('editField1', data.strand);
    } else if (TAB === 'section') {
        set('editId',         data.Section_id);
        set('editField1',     data.Section_name);
        set('editGradeLevel', data.Gradelevel_id);
        set('editCapacity',   data.Capacity);
    } else if (TAB === 'subject') {
        set('editId',          data.Subject_id);
        set('editSubjectCode', data.subject_code);
        set('editField1',      data.Subject_name);
        set('editMaxCap',      data.Max_capacity);
        set('editGradeLevel',  data.gradelevel_id);
        set('editStrand',      data.strand_id);
        set('editSemester',    data.semester_id);
    } else if (TAB === 'teacher') {
        set('editId',     data.Teacher_id);
        set('editField1', data.Fullname);
        set('editField2', data.Designation);
    } else if (TAB === 'class') {
        set('editId',          data.Class_id);
        set('editSchoolYear',  data.School_year_id);
        set('editSemester',    data.Semester_id);
        set('editGradeLevel',  data.GradeLevel_id);
        set('editSection',     data.Section_id);
        set('editSubject',     data.Subject_id);
        set('editTeacher',     data.Teacher_id);
        set('editStrand',      data.strand_id);
        set('editTime',        data.Time);
        set('editClassStatus', data.Status);
    }

    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }

function openDeleteModal(data) {
    let id    = '';
    let label = '';

    if (TAB === 'semester')   { id = data.Semester_id;   label = data.Semester; }
    if (TAB === 'gradelevel') { id = data.Gradelevel_id; label = data.Gradelevel; }
    if (TAB === 'strand')     { id = data.strand_id;     label = data.strand; }
    if (TAB === 'section')    { id = data.Section_id;    label = data.Section_name; }
    if (TAB === 'subject')    { id = data.Subject_id;    label = data.Subject_name; }
    if (TAB === 'teacher')    { id = data.Teacher_id;    label = data.Fullname; }
    if (TAB === 'class')      { id = data.Class_id;      label = (data.Subject_name || '') + (data.Section_name ? ' — ' + data.Section_name : ''); }

    document.getElementById('deleteId').value          = id;
    document.getElementById('deleteLabel').textContent = label;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }

// Close on backdrop click
['addModal', 'editModal', 'deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

</body>
</html>
