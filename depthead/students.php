<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_super_admin($user)) {
    flash_set('error', 'Only Super Admin can access this module.');
    redirect_to(app_url('depthead/index.php'));
}

function allowed_student_record_view(string $view): string
{
    return in_array($view, ['new', 'old', 'enrollment'], true) ? $view : 'new';
}

function student_records_redirect_query(string $view, string $search, string $schoolYear): string
{
    $params = ['view' => allowed_student_record_view($view)];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($params['view'] === 'enrollment' && $schoolYear !== '') {
        $params['school_year'] = $schoolYear;
    }

    return 'students.php?' . http_build_query($params);
}

function selected_attr(string $expected, string $actual): string
{
    return $expected === $actual ? ' selected' : '';
}

function fetch_rows(mysqli $connection, string $sql, string $types = '', array $params = []): array
{
    $statement = $connection->prepare($sql);
    if (!$statement) {
        return [];
    }

    bind_dynamic_params($statement, $types, $params);
    $statement->execute();

    return stmt_fetch_all_assoc($statement);
}

function execute_statement(mysqli $connection, string $sql, string $types, array $params): void
{
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Unable to prepare the database statement.');
    }

    bind_dynamic_params($statement, $types, $params);
    $statement->execute();
}

$viewLabels = [
    'new' => 'New Student Profiles',
    'old' => 'Old Student Profiles',
    'enrollment' => 'Enrolled Students',
];

$activeSchoolYearLabel = '';
try {
    $syStmt = $connection->prepare(
        'SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow && !empty($syRow['School_year'])) {
        $activeSchoolYearLabel = (string) $syRow['School_year'];
    }
} catch (Throwable) {
}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . ((int) date('Y') + 1);
}

$gradeLevels = [];
try {
    $glResult = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $glResult ? $glResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {
}

$sections = [];
try {
    $sectionResult = $connection->query('SELECT Section_id, Section_name, Gradelevel_id FROM section ORDER BY Section_name');
    $sections = $sectionResult ? $sectionResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {
}

$schoolYears = [];
try {
    $schoolYearResult = $connection->query('SELECT School_year FROM schoolyear ORDER BY School_year_id DESC');
    $schoolYears = $schoolYearResult ? $schoolYearResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {
}

$classifications = [];
try {
    $classificationResult = $connection->query(
        "SELECT DISTINCT classification_id, classification, description, type
         FROM payment_breakdown
         WHERE status = 'Active'
         ORDER BY classification_id, type"
    );
    $classifications = $classificationResult ? $classificationResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {
}

$enrollmentStatuses = [];
try {
    $statusResult = $connection->query('SELECT DISTINCT Status FROM enrollment WHERE Status <> "" ORDER BY Status');
    $enrollmentStatuses = $statusResult ? $statusResult->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {
}

$statusOptions = ['For Enrollment', 'For Registrar Confirmation', 'For Madrasah Enrollment', 'Officially Enrolled'];
foreach ($enrollmentStatuses as $statusRow) {
    $statusValue = trim((string) ($statusRow['Status'] ?? ''));
    if ($statusValue !== '' && !in_array($statusValue, $statusOptions, true)) {
        $statusOptions[] = $statusValue;
    }
}

$currentView = allowed_student_record_view((string) ($_GET['view'] ?? 'new'));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$schoolYearFilter = trim((string) ($_GET['school_year'] ?? $activeSchoolYearLabel));
if ($schoolYearFilter === '') {
    $schoolYearFilter = $activeSchoolYearLabel;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to(student_records_redirect_query($currentView, $searchQuery, $schoolYearFilter));
    }

    $action           = trim((string) ($_POST['action'] ?? ''));
    $returnView       = allowed_student_record_view((string) ($_POST['return_view'] ?? $currentView));
    $returnQuery      = trim((string) ($_POST['return_q'] ?? $searchQuery));
    $returnSchoolYear = trim((string) ($_POST['return_school_year'] ?? $schoolYearFilter));

    try {
        if ($action === 'edit_new_profile') {
            $profileId = to_int($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) {
                throw new RuntimeException('Invalid new student profile.');
            }

            $values = [
                trim((string) ($_POST['studenttype'] ?? '')),
                trim((string) ($_POST['department'] ?? '')),
                trim((string) ($_POST['lrn'] ?? '')),
                trim((string) ($_POST['surname'] ?? '')),
                trim((string) ($_POST['firstname'] ?? '')),
                trim((string) ($_POST['middlename'] ?? '')),
                trim((string) ($_POST['birthdate'] ?? '')),
                trim((string) ($_POST['birthplace'] ?? '')),
                trim((string) ($_POST['sex'] ?? '')),
                trim((string) ($_POST['contact'] ?? '')),
                trim((string) ($_POST['email'] ?? '')),
                trim((string) ($_POST['province'] ?? '')),
                trim((string) ($_POST['municipality'] ?? '')),
                trim((string) ($_POST['barangay'] ?? '')),
                trim((string) ($_POST['previous_school'] ?? '')),
                trim((string) ($_POST['year_graduated'] ?? '')),
                trim((string) ($_POST['father_name'] ?? '')),
                trim((string) ($_POST['father_contact'] ?? '')),
                trim((string) ($_POST['mother_name'] ?? '')),
                trim((string) ($_POST['mother_contact'] ?? '')),
                trim((string) ($_POST['parent_address'] ?? '')),
                trim((string) ($_POST['ID_pic'] ?? '')),
                trim((string) ($_POST['Good_moral'] ?? '')),
                trim((string) ($_POST['Card'] ?? '')),
                trim((string) ($_POST['PSA'] ?? '')),
                trim((string) ($_POST['submission'] ?? '')),
                $profileId,
            ];

            execute_statement(
                $connection,
                'UPDATE new_studentprofile
                 SET studenttype = ?, department = ?, lrn = ?, surname = ?, firstname = ?, middlename = ?,
                     birthdate = ?, birthplace = ?, sex = ?, contact = ?, email = ?, province = ?, municipality = ?,
                     barangay = ?, previous_school = ?, year_graduated = ?, father_name = ?, father_contact = ?,
                     mother_name = ?, mother_contact = ?, parent_address = ?, ID_pic = ?, Good_moral = ?,
                     Card = ?, PSA = ?, submission = ?
                 WHERE id = ?',
                str_repeat('s', count($values) - 1) . 'i',
                $values
            );

            flash_set('success', 'New student profile updated successfully.');
        } elseif ($action === 'delete_new_profile') {
            $profileId = to_int($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) {
                throw new RuntimeException('Invalid new student profile.');
            }

            execute_statement($connection, 'DELETE FROM new_studentprofile WHERE id = ?', 'i', [$profileId]);
            flash_set('success', 'New student profile deleted.');
        } elseif ($action === 'edit_old_profile') {
            $profileId = to_int($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) {
                throw new RuntimeException('Invalid old student profile.');
            }

            $values = [
                trim((string) ($_POST['student_id'] ?? '')),
                trim((string) ($_POST['studenttype'] ?? '')),
                trim((string) ($_POST['department'] ?? '')),
                trim((string) ($_POST['lrn'] ?? '')),
                trim((string) ($_POST['surname'] ?? '')),
                trim((string) ($_POST['firstname'] ?? '')),
                trim((string) ($_POST['middlename'] ?? '')),
                trim((string) ($_POST['birthdate'] ?? '')),
                trim((string) ($_POST['birthplace'] ?? '')),
                trim((string) ($_POST['sex'] ?? '')),
                trim((string) ($_POST['contact'] ?? '')),
                trim((string) ($_POST['email'] ?? '')),
                trim((string) ($_POST['province'] ?? '')),
                trim((string) ($_POST['municipality'] ?? '')),
                trim((string) ($_POST['barangay'] ?? '')),
                trim((string) ($_POST['previous_school'] ?? '')),
                trim((string) ($_POST['year_graduated'] ?? '')),
                trim((string) ($_POST['father_name'] ?? '')),
                trim((string) ($_POST['father_contact'] ?? '')),
                trim((string) ($_POST['mother_name'] ?? '')),
                trim((string) ($_POST['mother_contact'] ?? '')),
                trim((string) ($_POST['parent_address'] ?? '')),
                trim((string) ($_POST['ID_pic'] ?? '')),
                trim((string) ($_POST['Good_moral'] ?? '')),
                trim((string) ($_POST['Card'] ?? '')),
                trim((string) ($_POST['PSA'] ?? '')),
                trim((string) ($_POST['submission'] ?? '')),
                $profileId,
            ];

            execute_statement(
                $connection,
                'UPDATE old_studentprofile
                 SET student_id = ?, studenttype = ?, department = ?, lrn = ?, surname = ?, firstname = ?, middlename = ?,
                     birthdate = ?, birthplace = ?, sex = ?, contact = ?, email = ?, province = ?, municipality = ?,
                     barangay = ?, previous_school = ?, year_graduated = ?, father_name = ?, father_contact = ?,
                     mother_name = ?, mother_contact = ?, parent_address = ?, ID_pic = ?, Good_moral = ?,
                     Card = ?, PSA = ?, submission = ?
                 WHERE id = ?',
                str_repeat('s', count($values) - 1) . 'i',
                $values
            );

            flash_set('success', 'Old student profile updated successfully.');
        } elseif ($action === 'delete_old_profile') {
            $profileId = to_int($_POST['profile_id'] ?? 0);
            if ($profileId <= 0) {
                throw new RuntimeException('Invalid old student profile.');
            }

            execute_statement($connection, 'DELETE FROM old_studentprofile WHERE id = ?', 'i', [$profileId]);
            flash_set('success', 'Old student profile deleted.');
        } elseif ($action === 'edit_enrollment') {
            $enrollmentId = to_int($_POST['enrollment_id'] ?? 0);
            if ($enrollmentId <= 0) {
                throw new RuntimeException('Invalid enrollment record.');
            }

            $values = [
                trim((string) ($_POST['student_id'] ?? '')),
                trim((string) ($_POST['school_year'] ?? '')),
                trim((string) ($_POST['semester'] ?? '')),
                trim((string) ($_POST['department'] ?? '')),
                trim((string) ($_POST['strand'] ?? '')),
                trim((string) ($_POST['department_gradelevel'] ?? '0')),
                trim((string) ($_POST['department_section'] ?? '')),
                trim((string) ($_POST['madrasah_gradelevel'] ?? '')),
                trim((string) ($_POST['madrasah_section'] ?? '')),
                trim((string) ($_POST['department_average'] ?? '0')),
                trim((string) ($_POST['madrasah_average'] ?? '0')),
                trim((string) ($_POST['date_enrolled'] ?? '')),
                trim((string) ($_POST['student_classification'] ?? '0')),
                trim((string) ($_POST['status'] ?? '')),
                $enrollmentId,
            ];

            execute_statement(
                $connection,
                'UPDATE enrollment
                 SET student_id = ?, school_year = ?, Semester = ?, Department = ?, Strand = ?,
                     Department_gradelevel = ?, Department_section = ?, Madrasah_gradelevel = ?, Madrasah_section = ?,
                     Department_average = ?, Madrasah_average = ?, Date_enrolled = ?, Student_classification = ?, Status = ?
                 WHERE id = ?',
                str_repeat('s', count($values) - 1) . 'i',
                $values
            );

            flash_set('success', 'Enrollment record updated directly on the enrollment table.');
        } elseif ($action === 'delete_enrollment') {
            $enrollmentId = to_int($_POST['enrollment_id'] ?? 0);
            if ($enrollmentId <= 0) {
                throw new RuntimeException('Invalid enrollment record.');
            }

            execute_statement($connection, 'DELETE FROM enrollment WHERE id = ?', 'i', [$enrollmentId]);
            flash_set('success', 'Enrollment record deleted from the enrollment table.');
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
    }

    redirect_to(student_records_redirect_query($returnView, $returnQuery, $returnSchoolYear));
}

$records = [];

if ($currentView === 'new') {
    $sql = 'SELECT id, studenttype, department, lrn, surname, firstname, middlename, birthdate, birthplace, sex,
                   contact, email, province, municipality, barangay, previous_school, year_graduated,
                   father_name, father_contact, mother_name, mother_contact, parent_address,
                   ID_pic, Good_moral, Card, PSA, submission
            FROM new_studentprofile';
    $params = [];
    $types = '';

    if ($searchQuery !== '') {
        $like = '%' . $searchQuery . '%';
        $sql .= ' WHERE CAST(id AS CHAR) LIKE ? OR surname LIKE ? OR firstname LIKE ? OR middlename LIKE ? OR lrn LIKE ? OR email LIKE ? OR department LIKE ?';
        $params = [$like, $like, $like, $like, $like, $like, $like];
        $types = str_repeat('s', count($params));
    }

    $sql .= ' ORDER BY id DESC';
    $records = fetch_rows($connection, $sql, $types, $params);
} elseif ($currentView === 'old') {
    $sql = 'SELECT id, student_id, studenttype, department, lrn, surname, firstname, middlename, birthdate, birthplace, sex,
                   contact, email, province, municipality, barangay, previous_school, year_graduated,
                   father_name, father_contact, mother_name, mother_contact, parent_address,
                   ID_pic, Good_moral, Card, PSA, submission
            FROM old_studentprofile';
    $params = [];
    $types = '';

    if ($searchQuery !== '') {
        $like = '%' . $searchQuery . '%';
        $sql .= ' WHERE CAST(id AS CHAR) LIKE ? OR student_id LIKE ? OR surname LIKE ? OR firstname LIKE ? OR middlename LIKE ? OR lrn LIKE ? OR email LIKE ? OR department LIKE ?';
        $params = [$like, $like, $like, $like, $like, $like, $like, $like];
        $types = str_repeat('s', count($params));
    }

    $sql .= ' ORDER BY id DESC';
    $records = fetch_rows($connection, $sql, $types, $params);
} else {
    $sql = "SELECT e.id, e.student_id, e.school_year, e.Semester, e.Department, e.Strand,
                   e.Department_gradelevel, e.Department_section, e.Madrasah_gradelevel, e.Madrasah_section,
                   e.Department_average, e.Madrasah_average, e.Date_enrolled, e.Student_classification, e.Status,
                   COALESCE(
                       NULLIF(TRIM(CONCAT_WS(' ', p.surname, p.firstname, p.middlename)), ''),
                       NULLIF(TRIM(CONCAT_WS(' ', o.surname, o.firstname, o.middlename)), ''),
                       e.student_id
                   ) AS student_name,
                   IFNULL(gl.Gradelevel, CONCAT('Grade ', e.Department_gradelevel)) AS gradelevel_name,
                   IFNULL(sc.Section_name, e.Department_section) AS section_name,
                   pb.classification AS classification_name,
                   pb.description AS classification_desc,
                   COALESCE(p.lrn, o.lrn) AS profile_lrn,
                   COALESCE(p.contact, o.contact) AS profile_contact,
                   COALESCE(p.email, o.email) AS profile_email,
                   COALESCE(p.birthdate, o.birthdate) AS profile_birthdate,
                   COALESCE(p.sex, o.sex) AS profile_sex,
                   COALESCE(p.birthplace, o.birthplace) AS profile_birthplace,
                   COALESCE(p.province, o.province) AS profile_province,
                   COALESCE(p.municipality, o.municipality) AS profile_municipality,
                   COALESCE(p.barangay, o.barangay) AS profile_barangay,
                   COALESCE(p.father_name, o.father_name) AS profile_father_name,
                   COALESCE(p.father_contact, o.father_contact) AS profile_father_contact,
                   COALESCE(p.mother_name, o.mother_name) AS profile_mother_name,
                   COALESCE(p.mother_contact, o.mother_contact) AS profile_mother_contact,
                   COALESCE(p.parent_address, o.parent_address) AS profile_parent_address,
                   COALESCE(p.previous_school, o.previous_school) AS profile_previous_school,
                   COALESCE(p.year_graduated, o.year_graduated) AS profile_year_graduated
            FROM enrollment e
            LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
            LEFT JOIN (
                SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename,
                       ops.lrn, ops.contact, ops.email, ops.birthdate, ops.sex, ops.birthplace,
                       ops.province, ops.municipality, ops.barangay,
                       ops.father_name, ops.father_contact, ops.mother_name, ops.mother_contact,
                       ops.parent_address, ops.previous_school, ops.year_graduated
                FROM old_studentprofile ops
                INNER JOIN (
                    SELECT student_id, MAX(id) AS latest_id
                    FROM old_studentprofile
                    GROUP BY student_id
                ) latest ON latest.latest_id = ops.id
            ) o ON o.student_id = e.student_id
            LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
            LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = e.Department_section
            LEFT JOIN (
                SELECT classification_id,
                       MIN(classification) AS classification,
                       MIN(description)    AS description
                FROM payment_breakdown
                WHERE status = 'Active'
                GROUP BY classification_id
            ) pb ON pb.classification_id = e.Student_classification
            WHERE e.school_year = ?";
    $params = [$schoolYearFilter];
    $types = 's';

    if ($searchQuery !== '') {
        $like = '%' . $searchQuery . '%';
        $sql .= " AND (
            CAST(e.id AS CHAR) LIKE ? OR e.student_id LIKE ? OR e.Department LIKE ? OR e.Status LIKE ? OR e.Strand LIKE ?
            OR p.surname LIKE ? OR p.firstname LIKE ? OR p.middlename LIKE ?
            OR o.surname LIKE ? OR o.firstname LIKE ? OR o.middlename LIKE ?
        )";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        $types .= str_repeat('s', 11);
    }

    $sql .= ' ORDER BY e.Date_enrolled DESC, e.id DESC';
    $records = fetch_rows($connection, $sql, $types, $params);
}

$flash = flash_get();
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Records | Super Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: { 50: '#eff6ff', 300: '#93c5fd', 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8' }
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

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Super Admin</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Student Records Management</h2>
            <p class="text-slate-500 mt-2">Manage new student profiles, old student profiles, and enrolled students from one page. Enrollment actions here update the <span class="font-semibold">enrollment</span> table directly.</p>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="grid gap-4 lg:grid-cols-[1fr_auto] items-start mb-6">
            <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-5">
                <div class="flex flex-wrap gap-2 mb-4">
                    <?php foreach ($viewLabels as $viewKey => $label): ?>
                        <a href="<?= h(student_records_redirect_query($viewKey, $searchQuery, $viewKey === 'enrollment' ? $schoolYearFilter : $activeSchoolYearLabel)) ?>"
                           class="px-4 py-2 rounded-xl text-sm font-semibold transition-colors <?= $currentView === $viewKey ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                            <?= h($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <form method="GET" class="grid gap-3 md:grid-cols-[1fr_auto_auto] items-end">
                    <input type="hidden" name="view" value="<?= h($currentView) ?>">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Search</label>
                        <input type="text" name="q" value="<?= h($searchQuery) ?>"
                               placeholder="Search by name, ID, LRN, department..."
                               class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    </div>

                    <?php if ($currentView === 'enrollment'): ?>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">School Year</label>
                            <select name="school_year" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 bg-white">
                                <?php foreach ($schoolYears as $schoolYear): ?>
                                    <?php $schoolYearValue = (string) ($schoolYear['School_year'] ?? ''); ?>
                                    <option value="<?= h($schoolYearValue) ?>"<?= selected_attr($schoolYearValue, $schoolYearFilter) ?>><?= h($schoolYearValue) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>

                    <button type="submit" class="rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">Apply</button>
                </form>
            </div>

            <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-5 min-w-[220px]">
                <p class="text-sm text-slate-500">Current View</p>
                <p class="text-xl font-extrabold mt-1"><?= h($viewLabels[$currentView]) ?></p>
                <p class="text-sm text-slate-500 mt-3">Records loaded</p>
                <p class="text-3xl font-extrabold mt-1"><?= h((string) count($records)) ?></p>
                <?php if ($currentView === 'enrollment'): ?>
                    <p class="text-xs text-slate-400 mt-2">School year: <?= h($schoolYearFilter) ?></p>
                <?php endif; ?>
            </div>
        </section>

        <section class="rounded-3xl bg-white border border-slate-200 shadow-panel overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-extrabold"><?= h($viewLabels[$currentView]) ?></h3>
                    <p class="text-sm text-slate-500 mt-1">
                        <?php if ($currentView === 'enrollment'): ?>
                            Edit or remove rows from the enrollment table.
                        <?php else: ?>
                            Edit or remove student profile records.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 uppercase tracking-[0.18em] text-[11px]">
                    <tr>
                        <?php if ($currentView === 'new'): ?>
                            <th class="px-4 py-3 text-left">ID</th>
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">Department</th>
                            <th class="px-4 py-3 text-left">LRN</th>
                            <th class="px-4 py-3 text-left">Contact</th>
                            <th class="px-4 py-3 text-left">Submission</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        <?php elseif ($currentView === 'old'): ?>
                            <th class="px-4 py-3 text-left">ID</th>
                            <th class="px-4 py-3 text-left">Student ID</th>
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">Department</th>
                            <th class="px-4 py-3 text-left">LRN</th>
                            <th class="px-4 py-3 text-left">Submission</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        <?php else: ?>
                            <th class="px-4 py-3 text-left">ID</th>
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">School Year</th>
                            <th class="px-4 py-3 text-left">Department</th>
                            <th class="px-4 py-3 text-left">Grade / Section</th>
                            <th class="px-4 py-3 text-left">Classification</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php if ($records === []): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-slate-500">No records matched the current filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $row): ?>
                            <?php
                            $rowJson = h(json_encode($row, $jsonFlags));
                            $displayName = trim((string) (($row['surname'] ?? '') . ', ' . ($row['firstname'] ?? '') . ' ' . ($row['middlename'] ?? '')));
                            if ($currentView === 'enrollment') {
                                $displayName = (string) ($row['student_name'] ?? $row['student_id'] ?? '');
                            }
                            ?>
                            <tr class="hover:bg-green-50/40 transition-colors">
                                <?php if ($currentView === 'new'): ?>
                                    <td class="px-4 py-3 font-mono text-xs"><?= h((string) ($row['id'] ?? '')) ?></td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800"><?= h(trim($displayName, ', ')) ?></p>
                                        <p class="text-xs text-slate-500 mt-1"><?= h((string) ($row['studenttype'] ?? '')) ?></p>
                                    </td>
                                    <td class="px-4 py-3"><?= h((string) ($row['department'] ?? '')) ?></td>
                                    <td class="px-4 py-3 font-mono text-xs"><?= h((string) ($row['lrn'] ?? '')) ?></td>
                                    <td class="px-4 py-3"><?= h((string) ($row['contact'] ?? '')) ?></td>
                                    <td class="px-4 py-3"><?= h((string) ($row['submission'] ?? '')) ?></td>
                                <?php elseif ($currentView === 'old'): ?>
                                    <td class="px-4 py-3 font-mono text-xs"><?= h((string) ($row['id'] ?? '')) ?></td>
                                    <td class="px-4 py-3 font-mono text-xs"><?= h((string) ($row['student_id'] ?? '')) ?></td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800"><?= h(trim($displayName, ', ')) ?></p>
                                        <p class="text-xs text-slate-500 mt-1"><?= h((string) ($row['studenttype'] ?? '')) ?></p>
                                    </td>
                                    <td class="px-4 py-3"><?= h((string) ($row['department'] ?? '')) ?></td>
                                    <td class="px-4 py-3 font-mono text-xs"><?= h((string) ($row['lrn'] ?? '')) ?></td>
                                    <td class="px-4 py-3"><?= h((string) ($row['submission'] ?? '')) ?></td>
                                <?php else: ?>
                                    <td class="px-4 py-3 font-mono text-xs"><?= h((string) ($row['id'] ?? '')) ?></td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800"><?= h($displayName) ?></p>
                                        <p class="text-xs text-slate-500 mt-1 font-mono"><?= h((string) ($row['student_id'] ?? '')) ?></p>
                                    </td>
                                    <td class="px-4 py-3"><?= h((string) ($row['school_year'] ?? '')) ?></td>
                                    <td class="px-4 py-3">
                                        <p><?= h((string) ($row['Department'] ?? '')) ?></p>
                                        <p class="text-xs text-slate-500 mt-1"><?= h((string) ($row['Strand'] ?? '')) ?></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p><?= h((string) ($row['gradelevel_name'] ?? '')) ?></p>
                                        <p class="text-xs text-slate-500 mt-1"><?= h((string) ($row['section_name'] ?? '')) ?></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p><?= h((string) ($row['classification_name'] ?? '')) ?></p>
                                        <p class="text-xs text-slate-500 mt-1"><?= h((string) ($row['classification_desc'] ?? '')) ?></p>
                                    </td>
                                    <td class="px-4 py-3"><?= h((string) ($row['Status'] ?? '')) ?></td>
                                <?php endif; ?>

                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button"
                                                data-row='<?= $rowJson ?>'
                                                onclick="openViewModal(JSON.parse(this.dataset.row))"
                                                class="rounded-xl border border-emerald-300 bg-emerald-50 text-emerald-700 px-3 py-1.5 text-xs font-semibold hover:bg-emerald-100 transition-colors">
                                            View
                                        </button>
                                        <button type="button"
                                                data-edit='<?= $rowJson ?>'
                                                onclick="openEditModal(JSON.parse(this.dataset.edit))"
                                                class="rounded-xl border border-green-300 bg-green-50 text-green-700 px-3 py-1.5 text-xs font-semibold hover:bg-green-100 transition-colors">
                                            Edit
                                        </button>
                                        <button type="button"
                                                data-edit='<?= $rowJson ?>'
                                                data-label="<?= h($displayName !== '' ? $displayName : (string) ($row['student_id'] ?? $row['id'] ?? 'record')) ?>"
                                                onclick="openDeleteModal(JSON.parse(this.dataset.edit), this.dataset.label)"
                                                class="rounded-xl border border-rose-200 bg-rose-50 text-rose-600 px-3 py-1.5 text-xs font-semibold hover:bg-rose-100 transition-colors">
                                            Delete
                                        </button>
                                    </div>
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

<!-- ── View Modal ──────────────────────────────────────────────────────────── -->
<div id="viewModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
            <div>
                <h3 class="text-xl font-extrabold" id="viewModalName">Student</h3>
                <p class="text-sm text-slate-500 mt-1" id="viewModalMeta"></p>
            </div>
            <button type="button" onclick="closeViewModal()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="p-6 space-y-6" id="viewModalBody"></div>
    </div>
</div>

<?php if ($currentView === 'new'): ?>
<div id="editModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
            <div>
                <h3 class="text-lg font-extrabold">Edit New Student Profile</h3>
                <p class="text-sm text-slate-500 mt-1">Update the stored profile details for this new student record.</p>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="students.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_new_profile">
            <input type="hidden" name="profile_id" id="editProfileId">
            <input type="hidden" name="return_view" value="<?= h($currentView) ?>">
            <input type="hidden" name="return_q" value="<?= h($searchQuery) ?>">
            <input type="hidden" name="return_school_year" value="<?= h($schoolYearFilter) ?>">

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div><label class="block text-sm font-semibold mb-1">Student Type</label><input type="text" name="studenttype" id="editStudentType" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Department</label><input type="text" name="department" id="editDepartment" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">LRN</label><input type="text" name="lrn" id="editLrn" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Surname</label><input type="text" name="surname" id="editSurname" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Firstname</label><input type="text" name="firstname" id="editFirstname" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Middlename</label><input type="text" name="middlename" id="editMiddlename" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Birthdate</label><input type="text" name="birthdate" id="editBirthdate" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Birthplace</label><input type="text" name="birthplace" id="editBirthplace" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Sex</label><input type="text" name="sex" id="editSex" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Contact</label><input type="text" name="contact" id="editContact" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Email</label><input type="text" name="email" id="editEmail" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Province</label><input type="text" name="province" id="editProvince" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Municipality</label><input type="text" name="municipality" id="editMunicipality" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Barangay</label><input type="text" name="barangay" id="editBarangay" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Previous School</label><input type="text" name="previous_school" id="editPreviousSchool" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Year Graduated</label><input type="text" name="year_graduated" id="editYearGraduated" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Father Name</label><input type="text" name="father_name" id="editFatherName" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Father Contact</label><input type="text" name="father_contact" id="editFatherContact" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Mother Name</label><input type="text" name="mother_name" id="editMotherName" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Mother Contact</label><input type="text" name="mother_contact" id="editMotherContact" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div class="xl:col-span-2"><label class="block text-sm font-semibold mb-1">Parent Address</label><input type="text" name="parent_address" id="editParentAddress" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">ID Pic</label><input type="text" name="ID_pic" id="editIdPic" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Good Moral</label><input type="text" name="Good_moral" id="editGoodMoral" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Card</label><input type="text" name="Card" id="editCard" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">PSA</label><input type="text" name="PSA" id="editPsa" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Submission</label><input type="text" name="submission" id="editSubmission" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php elseif ($currentView === 'old'): ?>
<div id="editModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
            <div>
                <h3 class="text-lg font-extrabold">Edit Old Student Profile</h3>
                <p class="text-sm text-slate-500 mt-1">Update the stored profile details for this old student record.</p>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="students.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_old_profile">
            <input type="hidden" name="profile_id" id="editProfileId">
            <input type="hidden" name="return_view" value="<?= h($currentView) ?>">
            <input type="hidden" name="return_q" value="<?= h($searchQuery) ?>">
            <input type="hidden" name="return_school_year" value="<?= h($schoolYearFilter) ?>">

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div><label class="block text-sm font-semibold mb-1">Student ID</label><input type="text" name="student_id" id="editStudentId" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Student Type</label><input type="text" name="studenttype" id="editStudentType" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Department</label><input type="text" name="department" id="editDepartment" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">LRN</label><input type="text" name="lrn" id="editLrn" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Surname</label><input type="text" name="surname" id="editSurname" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Firstname</label><input type="text" name="firstname" id="editFirstname" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Middlename</label><input type="text" name="middlename" id="editMiddlename" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Birthdate</label><input type="text" name="birthdate" id="editBirthdate" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Birthplace</label><input type="text" name="birthplace" id="editBirthplace" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Sex</label><input type="text" name="sex" id="editSex" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Contact</label><input type="text" name="contact" id="editContact" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Email</label><input type="text" name="email" id="editEmail" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Province</label><input type="text" name="province" id="editProvince" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Municipality</label><input type="text" name="municipality" id="editMunicipality" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Barangay</label><input type="text" name="barangay" id="editBarangay" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Previous School</label><input type="text" name="previous_school" id="editPreviousSchool" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Year Graduated</label><input type="text" name="year_graduated" id="editYearGraduated" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Father Name</label><input type="text" name="father_name" id="editFatherName" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Father Contact</label><input type="text" name="father_contact" id="editFatherContact" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Mother Name</label><input type="text" name="mother_name" id="editMotherName" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Mother Contact</label><input type="text" name="mother_contact" id="editMotherContact" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div class="xl:col-span-2"><label class="block text-sm font-semibold mb-1">Parent Address</label><input type="text" name="parent_address" id="editParentAddress" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">ID Pic</label><input type="text" name="ID_pic" id="editIdPic" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Good Moral</label><input type="text" name="Good_moral" id="editGoodMoral" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Card</label><input type="text" name="Card" id="editCard" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">PSA</label><input type="text" name="PSA" id="editPsa" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Submission</label><input type="text" name="submission" id="editSubmission" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div id="editModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
            <div>
                <h3 class="text-lg font-extrabold">Edit Enrollment Record</h3>
                <p class="text-sm text-slate-500 mt-1">This form writes directly to the enrollment table.</p>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="students.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_enrollment">
            <input type="hidden" name="enrollment_id" id="editEnrollmentId">
            <input type="hidden" name="return_view" value="<?= h($currentView) ?>">
            <input type="hidden" name="return_q" value="<?= h($searchQuery) ?>">
            <input type="hidden" name="return_school_year" value="<?= h($schoolYearFilter) ?>">

            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="block text-sm font-semibold mb-1">Student ID</label><input type="text" name="student_id" id="editStudentId" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">School Year</label><select name="school_year" id="editSchoolYear" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm bg-white"><?php foreach ($schoolYears as $schoolYear): ?><?php $schoolYearValue = (string) ($schoolYear['School_year'] ?? ''); ?><option value="<?= h($schoolYearValue) ?>"><?= h($schoolYearValue) ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-semibold mb-1">Semester</label><select name="semester" id="editSemester" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm bg-white"><option value="N/A">N/A</option><option value="1st">1st</option><option value="2nd">2nd</option></select></div>
                <div><label class="block text-sm font-semibold mb-1">Department</label><select name="department" id="editDepartment" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm bg-white"><option value="Elementary">Elementary</option><option value="Junior High">Junior High</option><option value="Senior High">Senior High</option></select></div>
                <div><label class="block text-sm font-semibold mb-1">Strand</label><input type="text" name="strand" id="editStrand" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Department Grade Level</label><select name="department_gradelevel" id="editGradeLevel" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm bg-white"><?php foreach ($gradeLevels as $gradeLevel): ?><?php $gradeLevelId = (string) ($gradeLevel['Gradelevel_id'] ?? ''); ?><option value="<?= h($gradeLevelId) ?>"><?= h((string) ($gradeLevel['Gradelevel'] ?? '')) ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-semibold mb-1">Department Section</label><select name="department_section" id="editSection" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm bg-white"><?php foreach ($sections as $section): ?><?php $sectionId = (string) ($section['Section_id'] ?? ''); ?><option value="<?= h($sectionId) ?>"><?= h((string) ($section['Section_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-semibold mb-1">Student Classification</label><select name="student_classification" id="editClassification" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm bg-white"><?php foreach ($classifications as $classification): ?><?php $classificationId = (string) ($classification['classification_id'] ?? ''); ?><option value="<?= h($classificationId) ?>"><?= h((string) ($classification['classification'] ?? '')) ?> (<?= h((string) ($classification['type'] ?? '')) ?>)</option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-semibold mb-1">Date Enrolled</label><input type="date" name="date_enrolled" id="editDateEnrolled" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Department Average</label><input type="number" step="0.01" name="department_average" id="editDepartmentAverage" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Madrasah Grade Level</label><input type="text" name="madrasah_gradelevel" id="editMadrasahGradeLevel" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Madrasah Section</label><input type="text" name="madrasah_section" id="editMadrasahSection" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Madrasah Average</label><input type="number" step="0.01" name="madrasah_average" id="editMadrasahAverage" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-semibold mb-1">Status</label><select name="status" id="editStatus" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm bg-white"><?php foreach ($statusOptions as $statusOption): ?><option value="<?= h($statusOption) ?>"><?= h($statusOption) ?></option><?php endforeach; ?></select></div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="deleteModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-slate-100">
            <h3 class="text-lg font-extrabold text-rose-700">Delete Record</h3>
            <p class="text-sm text-slate-500 mt-1">This will permanently remove the selected record.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-slate-700 mb-6">
                Delete <strong id="deleteRecordLabel" class="text-slate-900"></strong>?
            </p>
            <form method="POST" action="students.php" class="flex gap-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" id="deleteAction" value="">
                <input type="hidden" name="return_view" value="<?= h($currentView) ?>">
                <input type="hidden" name="return_q" value="<?= h($searchQuery) ?>">
                <input type="hidden" name="return_school_year" value="<?= h($schoolYearFilter) ?>">
                <input type="hidden" name="profile_id" id="deleteProfileId">
                <input type="hidden" name="enrollment_id" id="deleteEnrollmentId">
                <button type="submit" class="flex-1 rounded-xl bg-rose-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-rose-700 transition-colors">Yes, Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">Cancel</button>
            </form>
        </div>
    </div>
</div>

<script>
const currentView = <?= json_encode($currentView) ?>;

function setValue(id, value) {
    const element = document.getElementById(id);
    if (!element) {
        return;
    }

    element.value = value ?? '';
}

function openEditModal(data) {
    if (currentView === 'new') {
        setValue('editProfileId', data.id);
        setValue('editStudentType', data.studenttype);
        setValue('editDepartment', data.department);
        setValue('editLrn', data.lrn);
        setValue('editSurname', data.surname);
        setValue('editFirstname', data.firstname);
        setValue('editMiddlename', data.middlename);
        setValue('editBirthdate', data.birthdate);
        setValue('editBirthplace', data.birthplace);
        setValue('editSex', data.sex);
        setValue('editContact', data.contact);
        setValue('editEmail', data.email);
        setValue('editProvince', data.province);
        setValue('editMunicipality', data.municipality);
        setValue('editBarangay', data.barangay);
        setValue('editPreviousSchool', data.previous_school);
        setValue('editYearGraduated', data.year_graduated);
        setValue('editFatherName', data.father_name);
        setValue('editFatherContact', data.father_contact);
        setValue('editMotherName', data.mother_name);
        setValue('editMotherContact', data.mother_contact);
        setValue('editParentAddress', data.parent_address);
        setValue('editIdPic', data.ID_pic);
        setValue('editGoodMoral', data.Good_moral);
        setValue('editCard', data.Card);
        setValue('editPsa', data.PSA);
        setValue('editSubmission', data.submission);
    } else if (currentView === 'old') {
        setValue('editProfileId', data.id);
        setValue('editStudentId', data.student_id);
        setValue('editStudentType', data.studenttype);
        setValue('editDepartment', data.department);
        setValue('editLrn', data.lrn);
        setValue('editSurname', data.surname);
        setValue('editFirstname', data.firstname);
        setValue('editMiddlename', data.middlename);
        setValue('editBirthdate', data.birthdate);
        setValue('editBirthplace', data.birthplace);
        setValue('editSex', data.sex);
        setValue('editContact', data.contact);
        setValue('editEmail', data.email);
        setValue('editProvince', data.province);
        setValue('editMunicipality', data.municipality);
        setValue('editBarangay', data.barangay);
        setValue('editPreviousSchool', data.previous_school);
        setValue('editYearGraduated', data.year_graduated);
        setValue('editFatherName', data.father_name);
        setValue('editFatherContact', data.father_contact);
        setValue('editMotherName', data.mother_name);
        setValue('editMotherContact', data.mother_contact);
        setValue('editParentAddress', data.parent_address);
        setValue('editIdPic', data.ID_pic);
        setValue('editGoodMoral', data.Good_moral);
        setValue('editCard', data.Card);
        setValue('editPsa', data.PSA);
        setValue('editSubmission', data.submission);
    } else {
        setValue('editEnrollmentId', data.id);
        setValue('editStudentId', data.student_id);
        setValue('editSchoolYear', data.school_year);
        setValue('editSemester', data.Semester);
        setValue('editDepartment', data.Department);
        setValue('editStrand', data.Strand);
        setValue('editGradeLevel', data.Department_gradelevel);
        setValue('editSection', data.Department_section);
        setValue('editMadrasahGradeLevel', data.Madrasah_gradelevel);
        setValue('editMadrasahSection', data.Madrasah_section);
        setValue('editDepartmentAverage', data.Department_average);
        setValue('editMadrasahAverage', data.Madrasah_average);
        setValue('editDateEnrolled', data.Date_enrolled);
        setValue('editClassification', data.Student_classification);
        setValue('editStatus', data.Status);
    }

    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function openDeleteModal(data, label) {
    document.getElementById('deleteRecordLabel').textContent = label || 'this record';
    setValue('deleteProfileId', '');
    setValue('deleteEnrollmentId', '');

    if (currentView === 'new') {
        setValue('deleteAction', 'delete_new_profile');
        setValue('deleteProfileId', data.id);
    } else if (currentView === 'old') {
        setValue('deleteAction', 'delete_old_profile');
        setValue('deleteProfileId', data.id);
    } else {
        setValue('deleteAction', 'delete_enrollment');
        setValue('deleteEnrollmentId', data.id);
    }

    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

['viewModal', 'editModal', 'deleteModal'].forEach(id => {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.addEventListener('click', function(event) {
        if (event.target === this) this.style.display = 'none';
    });
});

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

function openViewModal(data) {
    const v = currentView;

    // ── Resolve name & identifiers ──────────────────────────────────────────
    let fullName, studentId, lrn;
    if (v === 'enrollment') {
        fullName  = data.student_name  || data.student_id || '—';
        studentId = data.student_id    || '—';
        lrn       = data.profile_lrn   || '—';
    } else {
        fullName  = [data.surname, data.firstname, data.middlename].filter(Boolean).join(', ') || '—';
        studentId = data.student_id    || String(data.id) || '—';
        lrn       = data.lrn           || '—';
    }

    document.getElementById('viewModalName').textContent = fullName;
    document.getElementById('viewModalMeta').textContent =
        'Student ID: ' + studentId + '  ·  LRN: ' + lrn;

    // ── Helper builders ─────────────────────────────────────────────────────
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const field = (label, value) => {
        const val = value && String(value).trim() !== '' ? esc(value) : '<span class="text-slate-400">—</span>';
        return `<div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-0.5">${label}</p>
            <p class="text-sm font-medium text-slate-800">${val}</p>
        </div>`;
    };
    const badge = (label, value) => {
        const yes = value === 'Yes' || value === 'yes';
        const color = yes ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200';
        return `<div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">${label}</p>
            <span class="inline-flex items-center gap-1 text-xs font-semibold border rounded-full px-2.5 py-0.5 ${color}">${esc(value) || '—'}</span>
        </div>`;
    };
    const section = (title, color, fields) => `
        <div>
            <h4 class="text-xs font-extrabold uppercase tracking-widest ${color} mb-3 pb-1.5 border-b border-current/20">${title}</h4>
            <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2 xl:grid-cols-3">${fields.join('')}</div>
        </div>`;

    // ── Build sections ───────────────────────────────────────────────────────
    let html = '';

    if (v === 'enrollment') {
        html += section('Enrollment Details', 'text-green-600', [
            field('School Year',       data.school_year),
            field('Department',        data.Department),
            field('Semester',          data.Semester),
            field('Strand',            data.Strand && data.Strand !== 'N/A' ? data.Strand : null),
            field('Grade Level',       data.gradelevel_name),
            field('Section',           data.section_name),
            field('Classification',    data.classification_desc || data.classification_name),
            field('Date Enrolled',     data.Date_enrolled),
            field('Status',            data.Status),
            field('Dept. Average',     data.Department_average > 0 ? data.Department_average : null),
            field('Madrasah Grade',    data.Madrasah_gradelevel !== 'N/A' ? data.Madrasah_gradelevel : null),
            field('Madrasah Section',  data.Madrasah_section    !== 'N/A' ? data.Madrasah_section    : null),
            field('Madrasah Average',  data.Madrasah_average    > 0       ? data.Madrasah_average    : null),
        ]);
        html += section('Personal Information', 'text-sky-600', [
            field('Full Name',    fullName),
            field('Student ID',   studentId),
            field('LRN',          data.profile_lrn),
            field('Sex',          data.profile_sex),
            field('Birthdate',    data.profile_birthdate),
            field('Birthplace',   data.profile_birthplace),
            field('Contact',      data.profile_contact),
            field('Email',        data.profile_email),
        ]);
        html += section('Address', 'text-violet-600', [
            field('Province',     data.profile_province),
            field('Municipality', data.profile_municipality),
            field('Barangay',     data.profile_barangay),
        ]);
        html += section('Family Information', 'text-amber-600', [
            field("Father's Name",    data.profile_father_name),
            field("Father's Contact", data.profile_father_contact),
            field("Mother's Name",    data.profile_mother_name),
            field("Mother's Contact", data.profile_mother_contact),
            field("Parent's Address", data.profile_parent_address),
        ]);
        html += section('Academic Background', 'text-teal-600', [
            field('Previous School', data.profile_previous_school),
            field('Year Graduated',  data.profile_year_graduated),
        ]);
    } else {
        html += section('Personal Information', 'text-sky-600', [
            field('Full Name',    fullName),
            field('Student ID',   studentId),
            field('LRN',          data.lrn),
            field('Student Type', data.studenttype),
            field('Department',   data.department),
            field('Sex',          data.sex),
            field('Birthdate',    data.birthdate),
            field('Birthplace',   data.birthplace),
            field('Contact',      data.contact),
            field('Email',        data.email),
            field('Submission',   data.submission),
        ]);
        html += section('Address', 'text-violet-600', [
            field('Province',     data.province),
            field('Municipality', data.municipality),
            field('Barangay',     data.barangay),
        ]);
        html += section('Family Information', 'text-amber-600', [
            field("Father's Name",    data.father_name),
            field("Father's Contact", data.father_contact),
            field("Mother's Name",    data.mother_name),
            field("Mother's Contact", data.mother_contact),
            field("Parent's Address", data.parent_address),
        ]);
        html += section('Academic Background', 'text-teal-600', [
            field('Previous School', data.previous_school),
            field('Year Graduated',  data.year_graduated),
        ]);
        html += section('Documents Submitted', 'text-rose-600', [
            badge('ID Picture',  data.ID_pic),
            badge('Good Moral',  data.Good_moral),
            badge('Card',        data.Card),
            badge('PSA',         data.PSA),
        ]);
    }

    document.getElementById('viewModalBody').innerHTML = html;
    document.getElementById('viewModal').style.display = 'flex';
}
</script>
</body>
</html>