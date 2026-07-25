<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

function normalize_department_for_enrollment(string $department): string
{
    $value = strtolower(trim($department));
    if (str_contains($value, 'senior')) {
        return 'Senior High';
    }

    if (str_contains($value, 'junior')) {
        return 'Junior High';
    }

    if (str_contains($value, 'elementary')) {
        return 'Elementary';
    }

    return 'Junior High';
}

function status_for_department(string $department): string
{
    return $department === 'Elementary' ? 'For Cashier Payment' : 'For Madrasah Enrollment';
}

$connection = db();
$user = current_user();

if (!is_enrollment_user($user)) {
    flash_set('error', 'Only Enrollment users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

// ── Auto-create houses table & enrollment.house_id column if missing ──────────
try {
    $connection->query(
        "CREATE TABLE IF NOT EXISTS `house` (
          `id`        int(11)      NOT NULL AUTO_INCREMENT,
          `housename` varchar(100) NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
} catch (Throwable) {}

try {
    $connection->query(
        "ALTER TABLE `enrollment`
         ADD COLUMN IF NOT EXISTS `house_id` int(11) DEFAULT NULL"
    );
} catch (Throwable) {
    // Fallback for servers that don't support IF NOT EXISTS on ADD COLUMN
    $chk = $connection->query(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'enrollment'
            AND COLUMN_NAME  = 'house_id'"
    );
    if ($chk && (int) ($chk->fetch_assoc()['c'] ?? 0) === 0) {
        $connection->query("ALTER TABLE `enrollment` ADD COLUMN `house_id` int(11) DEFAULT NULL");
    }
}


$activeTimelineYear = (int) date('Y');
$activeSchoolYearLabel = '';
try {
    $activeSyStmt = $connection->prepare(
        'SELECT School_year
         FROM schoolyear
         WHERE Status = 1
         ORDER BY School_year_id DESC
         LIMIT 1'
    );
    $activeSyStmt->execute();
    $activeSchoolYear = stmt_fetch_assoc($activeSyStmt);
    if ($activeSchoolYear && !empty($activeSchoolYear['School_year'])) {
        $activeSchoolYearLabel = (string) $activeSchoolYear['School_year'];
        $parts = explode('-', $activeSchoolYearLabel);
        $firstYear = isset($parts[0]) ? (int) trim($parts[0]) : 0;
        if ($firstYear > 0) {
            $activeTimelineYear = $firstYear;
        }
    }
} catch (Throwable) {
    $activeSchoolYearLabel = '';
}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = $activeTimelineYear . '-' . ($activeTimelineYear + 1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('index.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'process_madrasah') {
            $enrollmentId = to_int($_POST['enrollment_id'] ?? 0);
            $madrasahGradelevel = trim((string) ($_POST['madrasah_gradelevel'] ?? ''));
            $madrasahSection = trim((string) ($_POST['madrasah_section'] ?? ''));
            $madrasahAverage = (float) ($_POST['madrasah_average'] ?? 0);

            if ($enrollmentId <= 0) {
                throw new RuntimeException('Invalid enrollment record selected.');
            }

            if ($madrasahGradelevel === '') {
                throw new RuntimeException('Madrasah grade level is required.');
            }

            if ($madrasahSection === '') {
                throw new RuntimeException('Madrasah section is required.');
            }

            $madrasahAverage = max(0, min(100, $madrasahAverage));

            $recordCheckStmt = $connection->prepare(
                'SELECT id
                 FROM enrollment
                 WHERE id = ? AND school_year = ? AND Status = ?
                 LIMIT 1'
            );
            $madrasahStatus = 'For Madrasah Enrollment';
            $recordCheckStmt->bind_param('iss', $enrollmentId, $activeSchoolYearLabel, $madrasahStatus);
            $recordCheckStmt->execute();
            if (!stmt_fetch_assoc($recordCheckStmt)) {
                throw new RuntimeException('Selected record is not pending for madrasah enrollment.');
            }

            $nextStatus = 'For Cashier Payment';
            $updateMadrasahStmt = $connection->prepare(
                'UPDATE enrollment
                 SET Madrasah_gradelevel = ?,
                     Madrasah_section = ?,
                     Madrasah_average = ?,
                     Status = ?
                 WHERE id = ? AND school_year = ?'
            );
            $updateMadrasahStmt->bind_param(
                'ssdsis',
                $madrasahGradelevel,
                $madrasahSection,
                $madrasahAverage,
                $nextStatus,
                $enrollmentId,
                $activeSchoolYearLabel
            );
            $updateMadrasahStmt->execute();

            flash_set('success', 'Madrasah enrollment details saved. Status moved to For Cashier Payment.');
            redirect_to('index.php');
        }

        if ($action !== 'process_enrollment') {
            throw new RuntimeException('Unsupported enrollment action.');
        }

        $studentSource = trim((string) ($_POST['student_source'] ?? ''));
        $studentId = trim((string) ($_POST['student_id'] ?? ''));
        $schoolYear = trim((string) ($_POST['school_year'] ?? $activeSchoolYearLabel));
        $semester = trim((string) ($_POST['semester'] ?? 'N/A'));
        $department = normalize_department_for_enrollment((string) ($_POST['department'] ?? ''));
        $strand = trim((string) ($_POST['strand'] ?? 'N/A'));
        $departmentGradelevel = to_int($_POST['department_gradelevel'] ?? 0);
        $departmentSection = trim((string) ($_POST['department_section'] ?? ''));
        $madrasahGradelevel = trim((string) ($_POST['madrasah_gradelevel'] ?? 'N/A'));
        $madrasahSection = trim((string) ($_POST['madrasah_section'] ?? 'N/A'));
        $departmentAverage = (float) ($_POST['department_average'] ?? 0);
        $madrasahAverage = (float) ($_POST['madrasah_average'] ?? 0);
        $dateEnrolled = trim((string) ($_POST['date_enrolled'] ?? date('Y-m-d')));
        $studentClassification = to_int($_POST['student_classification'] ?? 0);
        $houseId = to_int($_POST['house_id'] ?? 0);
        if ($houseId <= 0) {
            $houseId = 0; // nullable
        }
        if ($department === 'Senior High') {
            $postedStatus = trim((string) ($_POST['status'] ?? ''));
            $status = ($postedStatus === 'For Cashier Payment') ? 'For Cashier Payment' : 'For Madrasah Enrollment';
        } else {
            $status = status_for_department($department);
        }

        // Document checklist (new students only)
        $docIdPic     = (($_POST['doc_id_pic']    ?? '') === '1') ? 'Yes' : 'No';
        $docGoodMoral = (($_POST['doc_good_moral'] ?? '') === '1') ? 'Yes' : 'No';
        $docCard      = (($_POST['doc_card']      ?? '') === '1') ? 'Yes' : 'No';
        $docPsa       = (($_POST['doc_psa']       ?? '') === '1') ? 'Yes' : 'No';

        if ($studentSource !== 'new' && $studentSource !== 'old') {
            throw new RuntimeException('Invalid student source.');
        }

        if ($studentId === '') {
            throw new RuntimeException('Student ID is required.');
        }

        if ($departmentGradelevel <= 0) {
            throw new RuntimeException('Department grade level is required.');
        }

        if ($departmentSection === '') {
            throw new RuntimeException('Department section is required.');
        }

        if ($studentClassification <= 0) {
            throw new RuntimeException('Student classification is required.');
        }

        if ($department !== 'Senior High') {
            $semester = 'N/A';
            $strand = 'N/A';
        }

        if ($madrasahGradelevel === '') {
            $madrasahGradelevel = 'N/A';
        }
        if ($madrasahSection === '') {
            $madrasahSection = 'N/A';
        }

        $departmentAverage = max(0, min(100, $departmentAverage));
        $madrasahAverage = max(0, min(100, $madrasahAverage));

        $duplicateStmt = $connection->prepare(
            'SELECT id FROM enrollment WHERE student_id = ? AND school_year = ? LIMIT 1'
        );
        $duplicateStmt->bind_param('ss', $studentId, $schoolYear);
        $duplicateStmt->execute();
        if (stmt_fetch_assoc($duplicateStmt)) {
            throw new RuntimeException('This student is already enrolled for the selected school year.');
        }

        $insertStmt = $connection->prepare(
            'INSERT INTO enrollment
             (student_id, school_year, Semester, Department, Strand, Department_gradelevel, Department_section,
              Madrasah_gradelevel, Madrasah_section, Department_average, Madrasah_average, Date_enrolled,
              Student_classification, house_id, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $houseIdParam = $houseId > 0 ? $houseId : null;
        $insertStmt->bind_param(
            'sssssisssddsiis',
            $studentId,
            $schoolYear,
            $semester,
            $department,
            $strand,
            $departmentGradelevel,
            $departmentSection,
            $madrasahGradelevel,
            $madrasahSection,
            $departmentAverage,
            $madrasahAverage,
            $dateEnrolled,
            $studentClassification,
            $houseIdParam,
            $status
        );
        $insertStmt->execute();

        if ($studentSource === 'new') {
            $newStudentNumericId = to_int($studentId);
            if ($newStudentNumericId > 0) {
                $latestExamStmt = $connection->prepare(
                    'SELECT exam_id FROM entranceexamination WHERE student_id = ? ORDER BY exam_id DESC LIMIT 1'
                );
                $latestExamStmt->bind_param('i', $newStudentNumericId);
                $latestExamStmt->execute();
                $latestExam = stmt_fetch_assoc($latestExamStmt);

                if ($latestExam && !empty($latestExam['exam_id'])) {
                    $examId = (int) $latestExam['exam_id'];
                    $enrolledRemarks = 'Enrolled';
                    $enrolledStatus = 'Enrolled';
                    $updateExamStmt = $connection->prepare(
                        'UPDATE entranceexamination
                         SET Status = ?, Remarks = ?, Date_Result = ?
                         WHERE exam_id = ?'
                    );
                    $updateExamStmt->bind_param('sssi', $enrolledStatus, $enrolledRemarks, $dateEnrolled, $examId);
                    $updateExamStmt->execute();
                }
            }

            // ── Save full profile to new_studentprofile ─────────────────────────
            $prFetchStmt = $connection->prepare(
                'SELECT * FROM preregistration WHERE id = ? LIMIT 1'
            );
            $prFetchStmt->bind_param('i', $newStudentNumericId);
            $prFetchStmt->execute();
            $prRow = stmt_fetch_assoc($prFetchStmt);

            if ($prRow) {
                $nspId           = (int)    ($prRow['id']              ?? 0);
                $nspStudenttype  = (string) ($prRow['studenttype']     ?? '');
                $nspDepartment   = (string) ($prRow['department']      ?? '');
                $nspLrn          = (string) ($prRow['lrn']             ?? '');
                $nspSurname      = (string) ($prRow['surname']         ?? '');
                $nspFirstname    = (string) ($prRow['firstname']       ?? '');
                $nspMiddlename   = (string) ($prRow['middlename']      ?? '');
                $nspBirthdate    = (string) ($prRow['birthdate']       ?? '');
                $nspBirthplace   = (string) ($prRow['birthplace']      ?? '');
                $nspSex          = (string) ($prRow['sex']             ?? '');
                $nspContact      = (string) ($prRow['contact']         ?? '');
                $nspEmail        = (string) ($prRow['email']           ?? '');
                $nspProvince     = (string) ($prRow['province']        ?? '');
                $nspMunicipality = (string) ($prRow['municipality']    ?? '');
                $nspBarangay     = (string) ($prRow['barangay']        ?? '');
                $nspPrevSchool   = (string) ($prRow['previous_school'] ?? '');
                $nspYearGrad     = (string) ($prRow['year_graduated']  ?? '');
                $nspFatherName   = (string) ($prRow['father_name']     ?? '');
                $nspFatherContact= (string) ($prRow['father_contact']  ?? '');
                $nspMotherName   = (string) ($prRow['mother_name']     ?? '');
                $nspMotherContact= (string) ($prRow['mother_contact']  ?? '');
                $nspParentAddr   = (string) ($prRow['parent_address']  ?? '');
                $nspSubmission   = (string) ($prRow['submission']      ?? '');

                $nspStmt = $connection->prepare(
                    'INSERT INTO new_studentprofile
                     (id, studenttype, department, lrn, surname, firstname, middlename,
                      birthdate, birthplace, sex, contact, email, province, municipality,
                      barangay, previous_school, year_graduated, father_name, father_contact,
                      mother_name, mother_contact, parent_address,
                      ID_pic, Good_moral, Card, PSA, submission)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                     ID_pic = VALUES(ID_pic), Good_moral = VALUES(Good_moral),
                     Card = VALUES(Card), PSA = VALUES(PSA)'
                );
                $nspStmt->bind_param(
                    'issssssssssssssssssssssssss',
                    $nspId, $nspStudenttype, $nspDepartment, $nspLrn, $nspSurname, $nspFirstname,
                    $nspMiddlename, $nspBirthdate, $nspBirthplace, $nspSex, $nspContact, $nspEmail,
                    $nspProvince, $nspMunicipality, $nspBarangay, $nspPrevSchool, $nspYearGrad,
                    $nspFatherName, $nspFatherContact, $nspMotherName, $nspMotherContact, $nspParentAddr,
                    $docIdPic, $docGoodMoral, $docCard, $docPsa, $nspSubmission
                );
                $nspStmt->execute();
            }
        }

        flash_set('success', 'Enrollment processed successfully.');
        redirect_to('index.php');
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
        redirect_to('index.php');
    }
}

$newSearch = trim((string) ($_GET['new_q'] ?? ''));
$oldSearch = trim((string) ($_GET['old_q'] ?? ''));

$newPage = max(1, to_int($_GET['new_page'] ?? 1));
$oldPage = max(1, to_int($_GET['old_page'] ?? 1));

$allowedMenus = ['dashboard', 'enrollment', 'madrasah'];
$currentMenu = strtolower(trim((string) ($_GET['menu'] ?? 'dashboard')));
if (!in_array($currentMenu, $allowedMenus, true)) {
    $currentMenu = 'dashboard';
}

$newPerPage = 10;
$oldPerPage = 10;

$semesterOptions = [];
$strandOptions = [];
$gradeLevels = [];
$sections = [];
$arabicGradeLevels = [];
$arabicSections = [];
$classificationOptions = [];
$houseOptions = [];
$sectionEnrollmentCounts = [];

try {
    $semesterResult = $connection->query('SELECT Semester FROM semester ORDER BY Semester_id ASC');
    if ($semesterResult) {
        while ($row = $semesterResult->fetch_assoc()) {
            $value = trim((string) ($row['Semester'] ?? ''));
            if ($value !== '') {
                $semesterOptions[] = $value;
            }
        }
    }
} catch (Throwable) {
    $semesterOptions = ['1st', '2nd', 'N/A'];
}

if (!$semesterOptions) {
    $semesterOptions = ['1st', '2nd', 'N/A'];
}

try {
    $strandResult = $connection->query('SELECT strand FROM strand ORDER BY strand_id ASC');
    if ($strandResult) {
        while ($row = $strandResult->fetch_assoc()) {
            $value = trim((string) ($row['strand'] ?? ''));
            if ($value !== '') {
                $strandOptions[] = $value;
            }
        }
    }
} catch (Throwable) {
    $strandOptions = ['N/A', 'ABM', 'HUMSS'];
}

if (!$strandOptions) {
    $strandOptions = ['N/A', 'ABM', 'HUMSS'];
}

try {
    $gradeResult = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id ASC');
    if ($gradeResult) {
        while ($row = $gradeResult->fetch_assoc()) {
            $gradeLevels[] = [
                'id' => (int) ($row['Gradelevel_id'] ?? 0),
                'name' => trim((string) ($row['Gradelevel'] ?? '')),
            ];
        }
    }
} catch (Throwable) {
    $gradeLevels = [];
}

try {
    $sectionResult = $connection->query('SELECT Section_id, Section_name, Gradelevel_id, Capacity FROM section ORDER BY Section_name ASC');
    if ($sectionResult) {
        while ($row = $sectionResult->fetch_assoc()) {
            $sections[] = [
                'id' => (int) ($row['Section_id'] ?? 0),
                'name' => trim((string) ($row['Section_name'] ?? '')),
                'gradelevel_id' => (int) ($row['Gradelevel_id'] ?? 0),
                'capacity' => (int) ($row['Capacity'] ?? 0),
            ];
        }
    }
} catch (Throwable) {
    $sections = [];
}

try {
    $sectionCountStmt = $connection->prepare(
        'SELECT Department_section, COUNT(*) AS total
         FROM enrollment
         WHERE school_year = ? AND Department_section <> ""
         GROUP BY Department_section'
    );
    $sectionCountStmt->bind_param('s', $activeSchoolYearLabel);
    $sectionCountStmt->execute();
    $sectionCountRows = stmt_fetch_all_assoc($sectionCountStmt);
    foreach ($sectionCountRows as $row) {
        $sectionEnrollmentCounts[(string) ($row['Department_section'] ?? '')] = (int) ($row['total'] ?? 0);
    }
} catch (Throwable) {
    $sectionEnrollmentCounts = [];
}

try {
    $arabicGradeResult = $connection->query(
        "SELECT id, Gradelevel_arabic
         FROM gradelevel_arabic
         WHERE status = 'ACTIVE'
         ORDER BY id ASC"
    );
    if ($arabicGradeResult) {
        while ($row = $arabicGradeResult->fetch_assoc()) {
            $arabicGradeLevels[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['Gradelevel_arabic'] ?? '')),
            ];
        }
    }
} catch (Throwable) {
    $arabicGradeLevels = [];
}

try {
    $arabicSectionResult = $connection->query(
        "SELECT id, Gradelevel_id, Section_arabic, Capacity
         FROM section_arabic
         WHERE status = 'ACTIVE'
         ORDER BY Section_arabic ASC"
    );
    if ($arabicSectionResult) {
        while ($row = $arabicSectionResult->fetch_assoc()) {
            $arabicSections[] = [
                'id' => (int) ($row['id'] ?? 0),
                'gradelevel_id' => (int) ($row['Gradelevel_id'] ?? 0),
                'section_arabic' => trim((string) ($row['Section_arabic'] ?? '')),
                'capacity' => (int) ($row['Capacity'] ?? 0),
            ];
        }
    }
} catch (Throwable) {
    $arabicSections = [];
}

try {
    $classificationResult = $connection->query('SELECT id_type, type FROM type ORDER BY id_type ASC');
    if ($classificationResult) {
        while ($row = $classificationResult->fetch_assoc()) {
            $idType = (int) ($row['id_type'] ?? 0);
            $typeLabel = trim((string) ($row['type'] ?? ''));
            if ($idType > 0 && $typeLabel !== '') {
                $classificationOptions[] = [
                    'id_type' => $idType,
                    'type' => $typeLabel,
                ];
            }
        }
    }
} catch (Throwable) {
    $classificationOptions = [];
}

if (!$classificationOptions) {
    $classificationOptions = [
        ['id_type' => 10, 'type' => 'REGULAR'],
    ];
}

try {
    $houseResult = $connection->query('SELECT id, housename FROM house ORDER BY housename ASC');
    if ($houseResult) {
        while ($row = $houseResult->fetch_assoc()) {
            $houseOptions[] = [
                'id'   => (int)    ($row['id']        ?? 0),
                'name' => trim((string) ($row['housename'] ?? '')),
            ];
        }
    }
} catch (Throwable) {
    $houseOptions = [];
}

$newWhere = [];
$newParams = [];
$newTypes = '';

$newWhere[] = 'YEAR(p.submission) = ?';
$newParams[] = $activeTimelineYear;
$newTypes .= 'i';
$newWhere[] = 'e.Status = ?';
$newParams[] = 'For Enrollment';
$newTypes .= 's';
$newWhere[] = 'e.exam_score IS NOT NULL';
$newWhere[] = 'NOT EXISTS (SELECT 1 FROM enrollment en WHERE en.student_id = CAST(p.id AS CHAR) AND en.school_year = ?)';
$newParams[] = $activeSchoolYearLabel;
$newTypes .= 's';

if ($newSearch !== '') {
    $newWhere[] = '(p.surname LIKE ? OR p.firstname LIKE ? OR p.lrn LIKE ?)';
    $searchLike = '%' . $newSearch . '%';
    $newParams[] = $searchLike;
    $newParams[] = $searchLike;
    $newParams[] = $searchLike;
    $newTypes .= 'sss';
}

$newCountSql = 'SELECT COUNT(*) AS total
                FROM preregistration p
                INNER JOIN (
                    SELECT ee.*
                    FROM entranceexamination ee
                    INNER JOIN (
                        SELECT student_id, MAX(exam_id) AS latest_exam_id
                        FROM entranceexamination
                        GROUP BY student_id
                    ) latest ON latest.latest_exam_id = ee.exam_id
                ) e ON e.student_id = p.id';

if ($newWhere) {
    $newCountSql .= ' WHERE ' . implode(' AND ', $newWhere);
}

$newCountStmt = $connection->prepare($newCountSql);
bind_dynamic_params($newCountStmt, $newTypes, $newParams);
$newCountStmt->execute();
$newCountRow = stmt_fetch_assoc($newCountStmt);
$totalNewStudents = to_int($newCountRow['total'] ?? 0);

$newTotalPages = max(1, (int) ceil($totalNewStudents / $newPerPage));
if ($newPage > $newTotalPages) {
    $newPage = $newTotalPages;
}
$newOffset = ($newPage - 1) * $newPerPage;

$newSql = 'SELECT p.id, p.studenttype, p.department, p.lrn, p.surname, p.firstname, p.middlename, p.contact,
                  e.exam_id, e.exam_score, e.Date_Result
           FROM preregistration p
           INNER JOIN (
               SELECT ee.*
               FROM entranceexamination ee
               INNER JOIN (
                   SELECT student_id, MAX(exam_id) AS latest_exam_id
                   FROM entranceexamination
                   GROUP BY student_id
               ) latest ON latest.latest_exam_id = ee.exam_id
           ) e ON e.student_id = p.id';

if ($newWhere) {
    $newSql .= ' WHERE ' . implode(' AND ', $newWhere);
}

$newSql .= ' ORDER BY e.Date_Result DESC, e.exam_id DESC LIMIT ? OFFSET ?';

$newStmt = $connection->prepare($newSql);
$newSelectParams = $newParams;
$newSelectParams[] = $newPerPage;
$newSelectParams[] = $newOffset;
bind_dynamic_params($newStmt, $newTypes . 'ii', $newSelectParams);
$newStmt->execute();
$newStudents = stmt_fetch_all_assoc($newStmt);

// ── old_studentprofile tab ────────────────────────────────────────────────────
$oldWhere = [];
$oldParams = [];
$oldTypes = '';

$oldWhere[] = 'NOT EXISTS (SELECT 1 FROM enrollment en WHERE en.student_id = o.student_id AND en.school_year = ?)';
$oldParams[] = $activeSchoolYearLabel;
$oldTypes .= 's';

if ($oldSearch !== '') {
    $oldWhere[] = '(o.surname LIKE ? OR o.firstname LIKE ? OR o.student_id LIKE ? OR o.lrn LIKE ?)';
    $searchLike = '%' . $oldSearch . '%';
    $oldParams[] = $searchLike;
    $oldParams[] = $searchLike;
    $oldParams[] = $searchLike;
    $oldParams[] = $searchLike;
    $oldTypes .= 'ssss';
}

$oldCountSql = 'SELECT COUNT(*) AS total
                FROM (
                    SELECT ops.id, ops.student_id, ops.studenttype, ops.department, ops.lrn, ops.surname, ops.firstname, ops.middlename, ops.contact, ops.submission
                    FROM old_studentprofile ops
                    INNER JOIN (
                        SELECT student_id, MAX(id) AS latest_id
                        FROM old_studentprofile
                        GROUP BY student_id
                    ) latest ON latest.latest_id = ops.id
                ) o';
if ($oldWhere) {
    $oldCountSql .= ' WHERE ' . implode(' AND ', $oldWhere);
}

$oldCountStmt = $connection->prepare($oldCountSql);
bind_dynamic_params($oldCountStmt, $oldTypes, $oldParams);
$oldCountStmt->execute();
$oldCountRow = stmt_fetch_assoc($oldCountStmt);
$totalOldStudents = to_int($oldCountRow['total'] ?? 0);

$oldTotalPages = max(1, (int) ceil($totalOldStudents / $oldPerPage));
if ($oldPage > $oldTotalPages) {
    $oldPage = $oldTotalPages;
}
$oldOffset = ($oldPage - 1) * $oldPerPage;

$oldSql = 'SELECT o.id, o.student_id, o.studenttype, o.department, o.lrn, o.surname, o.firstname, o.middlename, o.contact, o.submission
           FROM (
               SELECT ops.id, ops.student_id, ops.studenttype, ops.department, ops.lrn, ops.surname, ops.firstname, ops.middlename, ops.contact, ops.submission
               FROM old_studentprofile ops
               INNER JOIN (
                   SELECT student_id, MAX(id) AS latest_id
                   FROM old_studentprofile
                   GROUP BY student_id
               ) latest ON latest.latest_id = ops.id
           ) o';

if ($oldWhere) {
    $oldSql .= ' WHERE ' . implode(' AND ', $oldWhere);
}

$oldSql .= ' ORDER BY o.id DESC LIMIT ? OFFSET ?';

$oldStmt = $connection->prepare($oldSql);
$oldSelectParams = $oldParams;
$oldSelectParams[] = $oldPerPage;
$oldSelectParams[] = $oldOffset;
bind_dynamic_params($oldStmt, $oldTypes . 'ii', $oldSelectParams);
$oldStmt->execute();
$oldStudents = stmt_fetch_all_assoc($oldStmt);

// ── new_studentprofile tab ────────────────────────────────────────────────────
$nspSearch = trim((string) ($_GET['nsp_q'] ?? ''));
$nspPage   = max(1, to_int($_GET['nsp_page'] ?? 1));
$nspPerPage = 10;

$nspWhere  = [];
$nspParams = [];
$nspTypes  = '';

$nspWhere[]  = 'NOT EXISTS (SELECT 1 FROM enrollment en WHERE en.student_id = CAST(n.id AS CHAR) AND en.school_year = ?)';
$nspParams[] = $activeSchoolYearLabel;
$nspTypes   .= 's';

if ($nspSearch !== '') {
    $nspWhere[]  = '(n.surname LIKE ? OR n.firstname LIKE ? OR CAST(n.id AS CHAR) LIKE ? OR n.lrn LIKE ?)';
    $nspLike     = '%' . $nspSearch . '%';
    $nspParams[] = $nspLike;
    $nspParams[] = $nspLike;
    $nspParams[] = $nspLike;
    $nspParams[] = $nspLike;
    $nspTypes   .= 'ssss';
}

$nspCountSql = 'SELECT COUNT(*) AS total FROM new_studentprofile n';
if ($nspWhere) {
    $nspCountSql .= ' WHERE ' . implode(' AND ', $nspWhere);
}

$nspCountStmt = $connection->prepare($nspCountSql);
bind_dynamic_params($nspCountStmt, $nspTypes, $nspParams);
$nspCountStmt->execute();
$nspCountRow = stmt_fetch_assoc($nspCountStmt);
$totalNspStudents = to_int($nspCountRow['total'] ?? 0);

$nspTotalPages = max(1, (int) ceil($totalNspStudents / $nspPerPage));
if ($nspPage > $nspTotalPages) {
    $nspPage = $nspTotalPages;
}
$nspOffset = ($nspPage - 1) * $nspPerPage;

$nspSql = 'SELECT n.id, CAST(n.id AS CHAR) AS student_id, n.studenttype, n.department, n.lrn, n.surname, n.firstname, n.middlename, n.contact, n.submission
           FROM new_studentprofile n';
if ($nspWhere) {
    $nspSql .= ' WHERE ' . implode(' AND ', $nspWhere);
}
$nspSql .= ' ORDER BY n.id DESC LIMIT ? OFFSET ?';

$nspStmt = $connection->prepare($nspSql);
$nspSelectParams = $nspParams;
$nspSelectParams[] = $nspPerPage;
$nspSelectParams[] = $nspOffset;
bind_dynamic_params($nspStmt, $nspTypes . 'ii', $nspSelectParams);
$nspStmt->execute();
$nspStudents = stmt_fetch_all_assoc($nspStmt);

$newPaginationParams = [
    'menu' => $currentMenu,
    'new_q' => $newSearch,
    'old_q' => $oldSearch,
    'old_page' => $oldPage,
    'nsp_q' => $nspSearch,
    'nsp_page' => $nspPage,
];

$oldPaginationParams = [
    'menu' => $currentMenu,
    'new_q' => $newSearch,
    'old_q' => $oldSearch,
    'new_page' => $newPage,
    'nsp_q' => $nspSearch,
    'nsp_page' => $nspPage,
];

$nspPaginationParams = [
    'menu' => $currentMenu,
    'new_q' => $newSearch,
    'old_q' => $oldSearch,
    'new_page' => $newPage,
    'old_page' => $oldPage,
    'nsp_q' => $nspSearch,
];

$madrasahSearch  = trim((string) ($_GET['mad_q'] ?? ''));
$madrasahPage    = max(1, (int) ($_GET['mad_page'] ?? 1));
$madrasahPerPage = 25;
$madrasahOffset  = ($madrasahPage - 1) * $madrasahPerPage;
$madrasahTotalPages = 1;

$madrasahPending = [];
$madrasahPendingCount = 0;
try {
    $madrasahStatus = 'For Madrasah Enrollment';

    if ($madrasahSearch !== '') {
        $madrasahLike = '%' . $madrasahSearch . '%';
        $madrasahCountStmt = $connection->prepare(
            'SELECT COUNT(*) AS total
             FROM enrollment e
             LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
             LEFT JOIN (
                 SELECT ops.student_id, ops.surname, ops.firstname
                 FROM old_studentprofile ops
                 INNER JOIN (
                     SELECT student_id, MAX(id) AS latest_id
                     FROM old_studentprofile GROUP BY student_id
                 ) latest ON latest.latest_id = ops.id
             ) o ON o.student_id = e.student_id
             WHERE e.school_year = ? AND e.Status = ?
               AND (e.student_id LIKE ? OR p.surname LIKE ? OR p.firstname LIKE ?
                    OR o.surname LIKE ? OR o.firstname LIKE ?)'
        );
        $madrasahCountStmt->bind_param('sssssss', $activeSchoolYearLabel, $madrasahStatus,
            $madrasahLike, $madrasahLike, $madrasahLike, $madrasahLike, $madrasahLike);
    } else {
        $madrasahCountStmt = $connection->prepare(
            'SELECT COUNT(*) AS total FROM enrollment WHERE school_year = ? AND Status = ?'
        );
        $madrasahCountStmt->bind_param('ss', $activeSchoolYearLabel, $madrasahStatus);
    }
    $madrasahCountStmt->execute();
    $madrasahCountRow     = stmt_fetch_assoc($madrasahCountStmt);
    $madrasahPendingCount = to_int($madrasahCountRow['total'] ?? 0);
    $madrasahTotalPages   = max(1, (int) ceil($madrasahPendingCount / $madrasahPerPage));
    $madrasahPage         = min($madrasahPage, $madrasahTotalPages);
    $madrasahOffset       = ($madrasahPage - 1) * $madrasahPerPage;

    if ($madrasahSearch !== '') {
        $madrasahLike = '%' . $madrasahSearch . '%';
        $madrasahStmt = $connection->prepare(
            'SELECT e.id, e.student_id, e.Department, e.Department_gradelevel, e.Department_section,
                    e.Madrasah_gradelevel, e.Madrasah_section, e.Madrasah_average, e.Date_enrolled,
                    p.surname AS p_surname, p.firstname AS p_firstname,
                    o.surname AS o_surname, o.firstname AS o_firstname
             FROM enrollment e
             LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
             LEFT JOIN (
                 SELECT ops.student_id, ops.surname, ops.firstname
                 FROM old_studentprofile ops
                 INNER JOIN (
                     SELECT student_id, MAX(id) AS latest_id
                     FROM old_studentprofile GROUP BY student_id
                 ) latest ON latest.latest_id = ops.id
             ) o ON o.student_id = e.student_id
             WHERE e.school_year = ? AND e.Status = ?
               AND (e.student_id LIKE ? OR p.surname LIKE ? OR p.firstname LIKE ?
                    OR o.surname LIKE ? OR o.firstname LIKE ?)
             ORDER BY e.Date_enrolled DESC, e.id DESC
             LIMIT ? OFFSET ?'
        );
        $madrasahStmt->bind_param('sssssssii', $activeSchoolYearLabel, $madrasahStatus,
            $madrasahLike, $madrasahLike, $madrasahLike, $madrasahLike, $madrasahLike,
            $madrasahPerPage, $madrasahOffset);
    } else {
        $madrasahStmt = $connection->prepare(
            'SELECT e.id, e.student_id, e.Department, e.Department_gradelevel, e.Department_section,
                    e.Madrasah_gradelevel, e.Madrasah_section, e.Madrasah_average, e.Date_enrolled,
                    p.surname AS p_surname, p.firstname AS p_firstname,
                    o.surname AS o_surname, o.firstname AS o_firstname
             FROM enrollment e
             LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
             LEFT JOIN (
                 SELECT ops.student_id, ops.surname, ops.firstname
                 FROM old_studentprofile ops
                 INNER JOIN (
                     SELECT student_id, MAX(id) AS latest_id
                     FROM old_studentprofile GROUP BY student_id
                 ) latest ON latest.latest_id = ops.id
             ) o ON o.student_id = e.student_id
             WHERE e.school_year = ? AND e.Status = ?
             ORDER BY e.Date_enrolled DESC, e.id DESC
             LIMIT ? OFFSET ?'
        );
        $madrasahStmt->bind_param('ssii', $activeSchoolYearLabel, $madrasahStatus,
            $madrasahPerPage, $madrasahOffset);
    }
    $madrasahStmt->execute();
    $madrasahPending = stmt_fetch_all_assoc($madrasahStmt);
} catch (Throwable) {
    $madrasahPending = [];
    $madrasahPendingCount = 0;
}

$recentEnrollments = [];
try {
    $recentStmt = $connection->prepare(
        'SELECT e.id, e.student_id, e.Department, e.Strand, e.Semester, e.Department_gradelevel, e.Department_section,
                e.Date_enrolled, e.Status, p.surname AS p_surname, p.firstname AS p_firstname,
                o.surname AS o_surname, o.firstname AS o_firstname
         FROM enrollment e
         LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
         LEFT JOIN (
             SELECT ops.id, ops.student_id, ops.surname, ops.firstname
             FROM old_studentprofile ops
             INNER JOIN (
                 SELECT student_id, MAX(id) AS latest_id
                 FROM old_studentprofile
                 GROUP BY student_id
             ) latest ON latest.latest_id = ops.id
         ) o ON o.student_id = e.student_id
         WHERE e.school_year = ?
         ORDER BY e.Date_enrolled DESC, e.id DESC
         LIMIT 12'
    );
    $recentStmt->bind_param('s', $activeSchoolYearLabel);
    $recentStmt->execute();
    $recentEnrollments = stmt_fetch_all_assoc($recentStmt);
} catch (Throwable) {
    $recentEnrollments = [];
}

$todayCount = 0;
foreach ($recentEnrollments as $entry) {
    if ((string) ($entry['Date_enrolled'] ?? '') === date('Y-m-d')) {
        $todayCount++;
    }
}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enrollment Dashboard | ITFA Enrollment System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Manrope', 'ui-sans-serif', 'system-ui']
                    },
                    colors: {
                        sea: {
                            50: '#f0f7f2',
                            100: '#dcedde',
                            500: '#2e8b57',
                            600: '#166534',
                            700: '#0f4d28'
                        }
                    },
                    boxShadow: {
                        panel: '0 18px 40px -20px rgba(22,101,52,0.20)'
                    }
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
    <aside class="text-slate-100 p-6 lg:p-8" style="background:linear-gradient(180deg,#166534 0%,#0f4d28 100%)">
        <p class="text-xs uppercase tracking-[0.2em] text-green-300 font-semibold">ITFA System</p>
        <h1 class="text-xl font-extrabold mt-2">Enrollment Module</h1>

        <div class="mt-8 space-y-2">
            <a href="<?= h(app_url('dashboard/index.php')) ?>" class="block rounded-xl px-4 py-3 font-semibold text-slate-200 hover:bg-white/10">Dashboard</a>
            <a href="?<?= h(http_build_query(['menu' => 'dashboard'])) ?>" class="block rounded-xl px-4 py-3 font-semibold <?= $currentMenu === 'dashboard' ? 'bg-green-500/20 border border-green-300/30 text-green-100' : 'text-slate-200 hover:bg-white/10' ?>">Enrollment Home</a>
            <a href="?<?= h(http_build_query(['menu' => 'enrollment'])) ?>" class="block rounded-xl px-4 py-3 font-semibold <?= $currentMenu === 'enrollment' ? 'bg-green-500/20 border border-green-300/30 text-green-100' : 'text-slate-200 hover:bg-white/10' ?>">Enrollment Menu</a>
            <a href="?<?= h(http_build_query(['menu' => 'madrasah'])) ?>" class="block rounded-xl px-4 py-3 font-semibold <?= $currentMenu === 'madrasah' ? 'bg-green-500/20 border border-green-300/30 text-green-100' : 'text-slate-200 hover:bg-white/10' ?>">Madrasah Enrollment</a>
            <a href="<?= h(app_url('logout.php')) ?>" class="block rounded-xl px-4 py-3 font-semibold text-slate-200 hover:bg-white/10">Logout</a>
        </div>

        <div class="mt-10 rounded-2xl border border-white/10 bg-white/5 p-4">
            <p class="text-xs text-slate-300">Logged in as</p>
            <p class="font-semibold mt-1"><?= h((string) ($user['full_name'] ?? 'User')) ?></p>
            <p class="text-xs text-green-200 mt-1"><?= h((string) ($user['role'] ?? 'Staff')) ?></p>
            <p class="text-xs text-slate-300 mt-3">Active School Year</p>
            <p class="font-semibold mt-1"><?= h($activeSchoolYearLabel) ?></p>
        </div>
    </aside>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-sea-600 font-semibold">Enrollment</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">
                <?= $currentMenu === 'madrasah' ? 'Madrasah Enrollment' : ($currentMenu === 'enrollment' ? 'Enrollment Processing' : 'Enrollment Dashboard') ?>
            </h2>
            <p class="text-slate-500 mt-2">Process new qualifiers, manage madrasah steps, and track enrollment progress in one place.</p>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($currentMenu === 'dashboard'): ?>
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6 mb-6">
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-500">New Students For Enrollment</p>
                <p class="text-3xl font-extrabold mt-2 text-sea-600"><?= h((string) $totalNewStudents) ?></p>
            </article>
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-500">Old Profile DB Students</p>
                <p class="text-3xl font-extrabold mt-2 text-green-700"><?= h((string) $totalOldStudents) ?></p>
            </article>
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-500">New Profile DB Students</p>
                <p class="text-3xl font-extrabold mt-2 text-cyan-700"><?= h((string) $totalNspStudents) ?></p>
            </article>
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-500">Recent Enrollments</p>
                <p class="text-3xl font-extrabold mt-2 text-violet-700"><?= h((string) count($recentEnrollments)) ?></p>
            </article>
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-500">Processed Today</p>
                <p class="text-3xl font-extrabold mt-2 text-emerald-700"><?= h((string) $todayCount) ?></p>
            </article>
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-500">For Madrasah Enrollment</p>
                <p class="text-3xl font-extrabold mt-2 text-amber-700"><?= h((string) $madrasahPendingCount) ?></p>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($currentMenu === 'enrollment'): ?>
        <section class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
            <article class="bg-white border border-slate-200 rounded-3xl shadow-panel p-5 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold">New Students Ready For Enrollment</h2>
                    <span class="text-xs rounded-full bg-sea-50 text-sea-600 border border-sea-200 px-3 py-1 font-semibold">From Examination</span>
                </div>
                <form method="get" class="mb-4 grid grid-cols-1 sm:grid-cols-12 gap-2">
                    <input type="hidden" name="menu" value="enrollment">
                    <input type="hidden" name="old_q" value="<?= h($oldSearch) ?>">
                    <input type="hidden" name="old_page" value="<?= h((string) $oldPage) ?>">
                    <input type="hidden" name="nsp_q" value="<?= h($nspSearch) ?>">
                    <input type="hidden" name="nsp_page" value="<?= h((string) $nspPage) ?>">
                    <input type="hidden" name="new_page" value="1">
                    <div class="sm:col-span-8">
                        <input type="text" name="new_q" value="<?= h($newSearch) ?>" placeholder="Search new students by name or LRN"
                               class="w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
                    </div>
                    <button type="submit" class="sm:col-span-2 rounded-xl bg-sea-600 hover:bg-sea-700 text-white text-sm font-semibold px-3 py-2">Search</button>
                          <a href="?<?= h(http_build_query(['menu' => 'enrollment', 'old_q' => $oldSearch, 'old_page' => $oldPage])) ?>"
                       class="sm:col-span-2 rounded-xl border border-slate-300 bg-white text-sm font-semibold px-3 py-2 text-center hover:bg-slate-100">Clear</a>
                </form>
                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                        <tr>
                            <th class="px-3 py-3 text-left">Student</th>
                            <th class="px-3 py-3 text-left">Department</th>
                            <th class="px-3 py-3 text-left">Exam Score</th>
                            <th class="px-3 py-3 text-left">Action</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                        <?php if (!$newStudents): ?>
                            <tr>
                                <td colspan="4" class="px-3 py-8 text-center text-slate-500">No new students are pending enrollment.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($newStudents as $student): ?>
                            <?php
                            $fullName = trim(((string) $student['surname']) . ', ' . ((string) $student['firstname']) . ' ' . ((string) $student['middlename']));
                            $normalizedDepartment = normalize_department_for_enrollment((string) ($student['department'] ?? ''));
                            ?>
                            <tr>
                                <td class="px-3 py-3">
                                    <p class="font-semibold"><?= h($fullName) ?></p>
                                    <p class="text-xs text-slate-500">LRN: <?= h((string) $student['lrn']) ?> | ID: <?= h((string) $student['id']) ?></p>
                                </td>
                                <td class="px-3 py-3"><?= h($normalizedDepartment) ?></td>
                                <td class="px-3 py-3 font-semibold text-emerald-700"><?= h((string) $student['exam_score']) ?></td>
                                <td class="px-3 py-3">
                                    <button type="button"
                                            class="rounded-xl bg-sea-600 text-white px-3 py-2 text-xs font-semibold hover:bg-sea-700"
                                            onclick="openEnrollmentModal(this)"
                                            data-source="new"
                                            data-student-id="<?= h((string) $student['id']) ?>"
                                            data-student-name="<?= h($fullName) ?>"
                                            data-department="<?= h($normalizedDepartment) ?>"
                                            data-classification="">
                                        Process Enrollment
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between text-sm">
                    <p class="text-slate-500">Showing <?= h((string) count($newStudents)) ?> of <?= h((string) $totalNewStudents) ?> new students</p>
                    <div class="flex items-center gap-2">
                        <?php if ($newPage > 1): ?>
                            <a href="?<?= h(http_build_query(array_merge($newPaginationParams, ['new_page' => $newPage - 1]))) ?>"
                               class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-semibold hover:bg-slate-100">Previous</a>
                        <?php else: ?>
                            <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Previous</span>
                        <?php endif; ?>

                        <span class="text-xs text-slate-600 font-semibold">Page <?= h((string) $newPage) ?> / <?= h((string) $newTotalPages) ?></span>

                        <?php if ($newPage < $newTotalPages): ?>
                            <a href="?<?= h(http_build_query(array_merge($newPaginationParams, ['new_page' => $newPage + 1]))) ?>"
                               class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-semibold hover:bg-slate-100">Next</a>
                        <?php else: ?>
                            <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Next</span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>

            <article class="bg-white border border-slate-200 rounded-3xl shadow-panel p-5 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold">Old Students Available For Processing</h2>
                </div>

                <!-- Tab Buttons -->
                <div class="flex gap-2 mb-4 border-b border-slate-200">
                    <button type="button" id="tab-old-btn"
                            onclick="switchOldTab('old')"
                            class="tab-old-btn px-4 py-2 text-sm font-semibold rounded-t-lg border-b-2 border-green-600 text-green-700 bg-green-50">
                        Old Profile DB
                        <span class="ml-1 text-xs bg-green-100 text-green-700 rounded-full px-2 py-0.5"><?= h((string) $totalOldStudents) ?></span>
                    </button>
                    <button type="button" id="tab-nsp-btn"
                            onclick="switchOldTab('nsp')"
                            class="tab-nsp-btn px-4 py-2 text-sm font-semibold rounded-t-lg border-b-2 border-transparent text-slate-500 hover:text-green-600">
                        New Profile DB
                        <span class="ml-1 text-xs bg-slate-100 text-slate-600 rounded-full px-2 py-0.5"><?= h((string) $totalNspStudents) ?></span>
                    </button>
                </div>

                <!-- Tab: old_studentprofile -->
                <div id="tab-old-panel">
                    <form method="get" class="mb-4 grid grid-cols-1 sm:grid-cols-12 gap-2">
                        <input type="hidden" name="menu" value="enrollment">
                        <input type="hidden" name="new_q" value="<?= h($newSearch) ?>">
                        <input type="hidden" name="new_page" value="<?= h((string) $newPage) ?>">
                        <input type="hidden" name="old_page" value="1">
                        <input type="hidden" name="nsp_q" value="<?= h($nspSearch) ?>">
                        <input type="hidden" name="nsp_page" value="<?= h((string) $nspPage) ?>">
                        <input type="hidden" name="old_tab" value="old">
                        <div class="sm:col-span-8">
                            <input type="text" name="old_q" value="<?= h($oldSearch) ?>" placeholder="Search by name, Student ID, or LRN"
                                   class="w-full rounded-xl border-slate-300 focus:border-green-500 focus:ring-green-500">
                        </div>
                        <button type="submit" class="sm:col-span-2 rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-3 py-2">Search</button>
                        <a href="?<?= h(http_build_query(['menu' => 'enrollment', 'new_q' => $newSearch, 'new_page' => $newPage, 'nsp_q' => $nspSearch, 'nsp_page' => $nspPage, 'old_tab' => 'old'])) ?>"
                           class="sm:col-span-2 rounded-xl border border-slate-300 bg-white text-sm font-semibold px-3 py-2 text-center hover:bg-slate-100">Clear</a>
                    </form>
                    <div class="overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-3 py-3 text-left">Student</th>
                                <th class="px-3 py-3 text-left">Department</th>
                                <th class="px-3 py-3 text-left">Submission</th>
                                <th class="px-3 py-3 text-left">Action</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                            <?php if (!$oldStudents): ?>
                                <tr>
                                    <td colspan="4" class="px-3 py-8 text-center text-slate-500">No old student profiles are pending this school year.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($oldStudents as $student): ?>
                                <?php
                                $fullName = trim(((string) $student['surname']) . ', ' . ((string) $student['firstname']) . ' ' . ((string) $student['middlename']));
                                $normalizedDepartment = normalize_department_for_enrollment((string) ($student['department'] ?? ''));
                                ?>
                                <tr>
                                    <td class="px-3 py-3">
                                        <p class="font-semibold"><?= h($fullName) ?></p>
                                        <p class="text-xs text-slate-500">Student ID: <?= h((string) $student['student_id']) ?> | LRN: <?= h((string) $student['lrn']) ?></p>
                                    </td>
                                    <td class="px-3 py-3"><?= h($normalizedDepartment) ?></td>
                                    <td class="px-3 py-3"><?= h((string) ($student['submission'] ?? '-')) ?></td>
                                    <td class="px-3 py-3">
                                        <button type="button"
                                                class="rounded-xl bg-green-600 text-white px-3 py-2 text-xs font-semibold hover:bg-green-700"
                                                onclick="openEnrollmentModal(this)"
                                                data-source="old"
                                                data-student-id="<?= h((string) $student['student_id']) ?>"
                                                data-student-name="<?= h($fullName) ?>"
                                                data-department="<?= h($normalizedDepartment) ?>"
                                                data-classification="">
                                            Enroll Student
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between text-sm">
                        <p class="text-slate-500">Showing <?= h((string) count($oldStudents)) ?> of <?= h((string) $totalOldStudents) ?> old students</p>
                        <div class="flex items-center gap-2">
                            <?php if ($oldPage > 1): ?>
                                <a href="?<?= h(http_build_query(array_merge($oldPaginationParams, ['old_page' => $oldPage - 1, 'old_tab' => 'old']))) ?>"
                                   class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-semibold hover:bg-slate-100">Previous</a>
                            <?php else: ?>
                                <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Previous</span>
                            <?php endif; ?>
                            <span class="text-xs text-slate-600 font-semibold">Page <?= h((string) $oldPage) ?> / <?= h((string) $oldTotalPages) ?></span>
                            <?php if ($oldPage < $oldTotalPages): ?>
                                <a href="?<?= h(http_build_query(array_merge($oldPaginationParams, ['old_page' => $oldPage + 1, 'old_tab' => 'old']))) ?>"
                                   class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-semibold hover:bg-slate-100">Next</a>
                            <?php else: ?>
                                <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Next</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab: new_studentprofile -->
                <div id="tab-nsp-panel" class="hidden">
                    <form method="get" class="mb-4 grid grid-cols-1 sm:grid-cols-12 gap-2">
                        <input type="hidden" name="menu" value="enrollment">
                        <input type="hidden" name="new_q" value="<?= h($newSearch) ?>">
                        <input type="hidden" name="new_page" value="<?= h((string) $newPage) ?>">
                        <input type="hidden" name="old_q" value="<?= h($oldSearch) ?>">
                        <input type="hidden" name="old_page" value="<?= h((string) $oldPage) ?>">
                        <input type="hidden" name="nsp_page" value="1">
                        <input type="hidden" name="old_tab" value="nsp">
                        <div class="sm:col-span-8">
                            <input type="text" name="nsp_q" value="<?= h($nspSearch) ?>" placeholder="Search by name, ID, or LRN"
                                   class="w-full rounded-xl border-slate-300 focus:border-cyan-500 focus:ring-cyan-500">
                        </div>
                        <button type="submit" class="sm:col-span-2 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold px-3 py-2">Search</button>
                        <a href="?<?= h(http_build_query(['menu' => 'enrollment', 'new_q' => $newSearch, 'new_page' => $newPage, 'old_q' => $oldSearch, 'old_page' => $oldPage, 'old_tab' => 'nsp'])) ?>"
                           class="sm:col-span-2 rounded-xl border border-slate-300 bg-white text-sm font-semibold px-3 py-2 text-center hover:bg-slate-100">Clear</a>
                    </form>
                    <div class="overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-3 py-3 text-left">Student</th>
                                <th class="px-3 py-3 text-left">Department</th>
                                <th class="px-3 py-3 text-left">Submission</th>
                                <th class="px-3 py-3 text-left">Action</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                            <?php if (!$nspStudents): ?>
                                <tr>
                                    <td colspan="4" class="px-3 py-8 text-center text-slate-500">No new profile students are pending this school year.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($nspStudents as $student): ?>
                                <?php
                                $fullName = trim(((string) $student['surname']) . ', ' . ((string) $student['firstname']) . ' ' . ((string) $student['middlename']));
                                $normalizedDepartment = normalize_department_for_enrollment((string) ($student['department'] ?? ''));
                                ?>
                                <tr>
                                    <td class="px-3 py-3">
                                        <p class="font-semibold"><?= h($fullName) ?></p>
                                        <p class="text-xs text-slate-500">Student ID: <?= h((string) $student['student_id']) ?> | LRN: <?= h((string) $student['lrn']) ?></p>
                                    </td>
                                    <td class="px-3 py-3"><?= h($normalizedDepartment) ?></td>
                                    <td class="px-3 py-3"><?= h((string) ($student['submission'] ?? '-')) ?></td>
                                    <td class="px-3 py-3">
                                        <button type="button"
                                                class="rounded-xl bg-cyan-600 text-white px-3 py-2 text-xs font-semibold hover:bg-cyan-700"
                                                onclick="openEnrollmentModal(this)"
                                                data-source="old"
                                                data-student-id="<?= h((string) $student['student_id']) ?>"
                                                data-student-name="<?= h($fullName) ?>"
                                                data-department="<?= h($normalizedDepartment) ?>"
                                                data-classification="">
                                            Enroll Student
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between text-sm">
                        <p class="text-slate-500">Showing <?= h((string) count($nspStudents)) ?> of <?= h((string) $totalNspStudents) ?> new profile students</p>
                        <div class="flex items-center gap-2">
                            <?php if ($nspPage > 1): ?>
                                <a href="?<?= h(http_build_query(array_merge($nspPaginationParams, ['nsp_page' => $nspPage - 1, 'old_tab' => 'nsp']))) ?>"
                                   class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-semibold hover:bg-slate-100">Previous</a>
                            <?php else: ?>
                                <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Previous</span>
                            <?php endif; ?>
                            <span class="text-xs text-slate-600 font-semibold">Page <?= h((string) $nspPage) ?> / <?= h((string) $nspTotalPages) ?></span>
                            <?php if ($nspPage < $nspTotalPages): ?>
                                <a href="?<?= h(http_build_query(array_merge($nspPaginationParams, ['nsp_page' => $nspPage + 1, 'old_tab' => 'nsp']))) ?>"
                                   class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-semibold hover:bg-slate-100">Next</a>
                            <?php else: ?>
                                <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 font-semibold text-slate-400 cursor-not-allowed">Next</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <script>
                (function () {
                    var active = '<?= h((string) ($_GET['old_tab'] ?? 'old')) ?>';
                    switchOldTab(active);
                })();

                function switchOldTab(tab) {
                    var oldPanel = document.getElementById('tab-old-panel');
                    var nspPanel = document.getElementById('tab-nsp-panel');
                    var oldBtn   = document.getElementById('tab-old-btn');
                    var nspBtn   = document.getElementById('tab-nsp-btn');

                    if (tab === 'nsp') {
                        oldPanel.classList.add('hidden');
                        nspPanel.classList.remove('hidden');
                        oldBtn.classList.remove('border-green-600','text-green-700','bg-green-50');
                        oldBtn.classList.add('border-transparent','text-slate-500');
                        nspBtn.classList.remove('border-transparent','text-slate-500');
                        nspBtn.classList.add('border-cyan-600','text-cyan-700','bg-cyan-50');
                    } else {
                        nspPanel.classList.add('hidden');
                        oldPanel.classList.remove('hidden');
                        nspBtn.classList.remove('border-cyan-600','text-cyan-700','bg-cyan-50');
                        nspBtn.classList.add('border-transparent','text-slate-500');
                        oldBtn.classList.remove('border-transparent','text-slate-500');
                        oldBtn.classList.add('border-green-600','text-green-700','bg-green-50');
                    }
                }
                </script>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($currentMenu === 'madrasah'): ?>
        <?php
            $madPageUrl = function(int $p) use ($currentMenu, $madrasahSearch): string {
                return '?' . http_build_query(array_filter([
                    'menu'     => $currentMenu,
                    'mad_q'    => $madrasahSearch,
                    'mad_page' => $p,
                ], fn($v) => $v !== '' && $v !== null && $v !== 0));
            };
        ?>
        <section class="bg-white border border-slate-200 rounded-3xl shadow-panel p-5 sm:p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <div class="flex items-center gap-3">
                    <h2 class="text-lg font-bold">For Madrasah Enrollment Processing</h2>
                    <span class="text-xs rounded-full bg-amber-50 text-amber-700 border border-amber-200 px-3 py-1 font-semibold">Pending: <?= h((string) $madrasahPendingCount) ?></span>
                </div>
                <form method="get" action="" class="flex gap-2">
                    <input type="hidden" name="menu" value="madrasah">
                    <input type="text" name="mad_q" value="<?= h($madrasahSearch) ?>"
                           placeholder="Search student name or ID…"
                           class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 w-56">
                    <button type="submit" class="rounded-xl bg-amber-600 text-white px-4 py-1.5 text-sm font-semibold hover:bg-amber-700">Search</button>
                    <?php if ($madrasahSearch !== ''): ?>
                    <a href="?menu=madrasah" class="rounded-xl border border-slate-200 bg-white text-slate-600 px-3 py-1.5 text-sm font-medium hover:bg-slate-50">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                    <tr>
                        <th class="px-3 py-3 text-left">Student</th>
                        <th class="px-3 py-3 text-left">Department</th>
                        <th class="px-3 py-3 text-left">Dept Level/Section</th>
                        <th class="px-3 py-3 text-left">Madrasah Details</th>
                        <th class="px-3 py-3 text-left">Action</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    <?php if (!$madrasahPending): ?>
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-slate-500">No records are waiting for madrasah enrollment updates.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($madrasahPending as $entry): ?>
                        <?php
                        $displayName = trim((string) (($entry['p_surname'] ?: $entry['o_surname']) . ', ' . ($entry['p_firstname'] ?: $entry['o_firstname'])));
                        $displayName = $displayName !== ', ' ? $displayName : (string) $entry['student_id'];
                        ?>
                        <tr>
                            <td class="px-3 py-3">
                                <p class="font-semibold"><?= h($displayName) ?></p>
                                <p class="text-xs text-slate-500">Student ID: <?= h((string) $entry['student_id']) ?></p>
                            </td>
                            <td class="px-3 py-3"><?= h((string) $entry['Department']) ?></td>
                            <td class="px-3 py-3">G<?= h((string) $entry['Department_gradelevel']) ?> / Sec <?= h((string) $entry['Department_section']) ?></td>
                            <td class="px-3 py-3">
                                <p class="text-xs text-slate-600">Level: <?= h((string) ($entry['Madrasah_gradelevel'] ?? 'N/A')) ?></p>
                                <p class="text-xs text-slate-600">Section: <?= h((string) ($entry['Madrasah_section'] ?? 'N/A')) ?></p>
                                <p class="text-xs text-slate-600">Average: <?= h((string) ($entry['Madrasah_average'] ?? '0')) ?></p>
                            </td>
                            <td class="px-3 py-3">
                                <button type="button"
                                        class="rounded-xl bg-amber-600 text-white px-3 py-2 text-xs font-semibold hover:bg-amber-700"
                                        onclick="openMadrasahModal(this)"
                                        data-enrollment-id="<?= h((string) $entry['id']) ?>"
                                        data-student-id="<?= h((string) $entry['student_id']) ?>"
                                        data-student-name="<?= h($displayName) ?>"
                                        data-madrasah-gradelevel="<?= h((string) ($entry['Madrasah_gradelevel'] ?? 'N/A')) ?>"
                                        data-madrasah-section="<?= h((string) ($entry['Madrasah_section'] ?? 'N/A')) ?>"
                                        data-madrasah-average="<?= h((string) ($entry['Madrasah_average'] ?? '0')) ?>">
                                    Update Madrasah
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($madrasahTotalPages > 1): ?>
            <div class="flex items-center justify-between mt-4 gap-2 flex-wrap">
                <p class="text-xs text-slate-500">
                    Page <?= $madrasahPage ?> of <?= $madrasahTotalPages ?>
                    &middot; <?= $madrasahPendingCount ?> total record<?= $madrasahPendingCount !== 1 ? 's' : '' ?>
                </p>
                <div class="flex gap-1.5 flex-wrap">
                    <?php if ($madrasahPage > 1): ?>
                    <a href="<?= h($madPageUrl(1)) ?>" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">&laquo;</a>
                    <a href="<?= h($madPageUrl($madrasahPage - 1)) ?>" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">Prev</a>
                    <?php endif; ?>
                    <?php
                    $madStart = max(1, $madrasahPage - 2);
                    $madEnd   = min($madrasahTotalPages, $madrasahPage + 2);
                    for ($mp = $madStart; $mp <= $madEnd; $mp++):
                    ?>
                    <a href="<?= h($madPageUrl($mp)) ?>"
                       class="rounded-lg border px-2.5 py-1 text-xs font-medium
                              <?= $mp === $madrasahPage ? 'bg-amber-600 border-amber-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>">
                        <?= $mp ?>
                    </a>
                    <?php endfor; ?>
                    <?php if ($madrasahPage < $madrasahTotalPages): ?>
                    <a href="<?= h($madPageUrl($madrasahPage + 1)) ?>" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">Next</a>
                    <a href="<?= h($madPageUrl($madrasahTotalPages)) ?>" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">&raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($currentMenu === 'dashboard'): ?>
        <section class="bg-white border border-slate-200 rounded-3xl shadow-panel p-5 sm:p-6">
            <h2 class="text-lg font-bold mb-4">Recent Enrollment Transactions</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                    <tr>
                        <th class="px-3 py-3 text-left">Student</th>
                        <th class="px-3 py-3 text-left">Department</th>
                        <th class="px-3 py-3 text-left">Track</th>
                        <th class="px-3 py-3 text-left">Level/Section</th>
                        <th class="px-3 py-3 text-left">Date</th>
                        <th class="px-3 py-3 text-left">Status</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    <?php if (!$recentEnrollments): ?>
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-slate-500">No enrollment transactions yet for this school year.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($recentEnrollments as $entry): ?>
                        <?php
                        $displayName = trim((string) (($entry['p_surname'] ?: $entry['o_surname']) . ', ' . ($entry['p_firstname'] ?: $entry['o_firstname'])));
                        ?>
                        <tr>
                            <td class="px-3 py-3">
                                <p class="font-semibold"><?= h($displayName !== ', ' ? $displayName : (string) $entry['student_id']) ?></p>
                                <p class="text-xs text-slate-500">Student ID: <?= h((string) $entry['student_id']) ?></p>
                            </td>
                            <td class="px-3 py-3"><?= h((string) $entry['Department']) ?></td>
                            <td class="px-3 py-3"><?= h((string) $entry['Strand']) ?> / <?= h((string) $entry['Semester']) ?></td>
                            <td class="px-3 py-3">G<?= h((string) $entry['Department_gradelevel']) ?> / Sec <?= h((string) $entry['Department_section']) ?></td>
                            <td class="px-3 py-3"><?= h((string) $entry['Date_enrolled']) ?></td>
                            <td class="px-3 py-3"><span class="rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 px-3 py-1 text-xs font-semibold"><?= h((string) $entry['Status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </main>
</div>

<div id="madrasahModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/55 backdrop-blur-sm p-4" onclick="overlayClose(event, 'madrasahModal')">
    <div class="w-full max-w-2xl bg-white rounded-3xl border border-slate-200 shadow-2xl overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200 bg-gradient-to-r from-amber-50 via-orange-50 to-slate-50 flex items-start justify-between gap-4">
            <div class="space-y-1">
                <p class="text-xs uppercase tracking-[0.2em] text-amber-700 font-semibold">Madrasah Enrollment</p>
                <h3 class="text-xl font-extrabold">Complete Madrasah Details</h3>
                <p class="text-sm text-slate-600" id="madrasahStudentMeta">-</p>
            </div>
            <button type="button" class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-100" onclick="closeMadrasahModal()">Close</button>
        </div>

        <form method="post" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="process_madrasah">
            <input type="hidden" name="enrollment_id" id="madrasahEnrollmentId" value="">

            <div class="md:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">New Status After Save</p>
                <p class="mt-1 text-sm font-bold text-emerald-700">For Cashier Payment</p>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Madrasah Grade Level</label>
                <select name="madrasah_gradelevel" id="madrasahGradelevel" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-amber-500 focus:ring-amber-500"
                        onchange="filterArabicSections('madrasahGradelevel', 'madrasahSection')">
                    <option value="N/A">N/A</option>
                    <option value="">Select madrasah grade level</option>
                    <?php foreach ($arabicGradeLevels as $grade): ?>
                        <option value="<?= h((string) $grade['id']) ?>" data-grade-id="<?= h((string) $grade['id']) ?>">
                            <?= h((string) $grade['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Madrasah Section</label>
                <select name="madrasah_section" id="madrasahSection" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-amber-500 focus:ring-amber-500">
                    <option value="N/A">N/A</option>
                    <option value="">Select madrasah section</option>
                    <?php foreach ($arabicSections as $section): ?>
                        <option value="<?= h((string) $section['id']) ?>" data-gradelevel="<?= h((string) $section['gradelevel_id']) ?>">
                            <?= h((string) $section['section_arabic']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4 md:col-span-2">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Madrasah Average</label>
                <input type="number" name="madrasah_average" id="madrasahAverage" min="0" max="100" step="0.01" required
                       class="mt-1 w-full rounded-xl border-slate-300 focus:border-amber-500 focus:ring-amber-500">
            </div>

            <div class="md:col-span-2 flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeMadrasahModal()" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-700 text-white px-5 py-2.5 text-sm font-semibold">Save Madrasah Details</button>
            </div>
        </form>
    </div>
</div>

<div id="enrollmentModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/55 backdrop-blur-sm p-4" onclick="overlayClose(event, 'enrollmentModal')">
    <div class="w-full max-w-5xl bg-white rounded-3xl border border-slate-200 shadow-2xl overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200 bg-gradient-to-r from-green-50 via-purple-50 to-slate-50 flex items-start justify-between gap-4">
            <div class="space-y-2">
                <p class="text-xs uppercase tracking-[0.2em] text-sea-600 font-semibold">Enrollment Processing</p>
                <h3 class="text-xl sm:text-2xl font-extrabold">Finalize Student Enrollment</h3>
                <p class="text-sm text-slate-600" id="modalStudentMeta">-</p>
                <div class="flex flex-wrap gap-2 text-xs font-semibold">
                    <span id="modalSourceBadge" class="rounded-full border border-cyan-200 bg-cyan-50 text-cyan-700 px-3 py-1">Source: -</span>
                    <span id="modalStudentIdBadge" class="rounded-full border border-slate-200 bg-white text-slate-700 px-3 py-1">ID: -</span>
                    <span id="modalDepartmentBadge" class="rounded-full border border-green-200 bg-green-50 text-green-700 px-3 py-1">Department: -</span>
                    <span id="modalClassificationBadge" class="rounded-full border border-green-200 bg-green-50 text-green-700 px-3 py-1">Type: -</span>
                </div>
            </div>
            <button type="button" class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm font-semibold hover:bg-slate-100" onclick="closeEnrollmentModal()">Close</button>
        </div>

        <form method="post" class="p-6 grid grid-cols-1 lg:grid-cols-2 gap-4 max-h-[75vh] overflow-y-auto">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="process_enrollment">
            <input type="hidden" name="student_source" id="modalStudentSource" value="new">
            <input type="hidden" name="student_id" id="modalStudentId" value="">

            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">School Year</label>
                <input type="text" name="school_year" id="modalSchoolYear" value="<?= h($activeSchoolYearLabel) ?>" readonly
                       class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50">
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Date Enrolled</label>
                <input type="date" name="date_enrolled" id="modalDateEnrolled" value="<?= h(date('Y-m-d')) ?>" required
                       class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Department</label>
                <select name="department" id="modalDepartment" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
                    <option value="Elementary">Elementary</option>
                    <option value="Junior High">Junior High</option>
                    <option value="Senior High">Senior High</option>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Student Classification</label>
                <select name="student_classification" id="modalClassification" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
                    <?php foreach ($classificationOptions as $classification): ?>
                        <option value="<?= h((string) $classification['id_type']) ?>" data-type-label="<?= h((string) $classification['type']) ?>">
                            <?= h((string) $classification['type']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Semester</label>
                <select name="semester" id="modalSemester" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
                    <?php foreach ($semesterOptions as $semester): ?>
                        <option value="<?= h($semester) ?>"><?= h($semester) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cluster</label>
                <select name="strand" id="modalStrand" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
                    <?php foreach ($strandOptions as $strand): ?>
                        <option value="<?= h($strand) ?>"><?= h($strand) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Department Grade Level</label>
                <select name="department_gradelevel" id="modalGradelevel" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500" onchange="filterSections()">
                    <option value="">Select grade level</option>
                    <?php foreach ($gradeLevels as $grade): ?>
                        <option value="<?= h((string) $grade['id']) ?>"><?= h($grade['name'] !== '' ? $grade['name'] : ('Grade ' . $grade['id'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Department Section</label>
                <select name="department_section" id="modalSection" required
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500"
                        onchange="updateSectionCapacityMonitor()">
                    <option value="">Select section</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= h((string) $section['id']) ?>"
                                data-gradelevel="<?= h((string) $section['gradelevel_id']) ?>"
                                data-capacity="<?= h((string) $section['capacity']) ?>"
                                data-enrolled="<?= h((string) ($sectionEnrollmentCounts[(string) $section['id']] ?? 0)) ?>">
                            <?= h($section['name'] !== '' ? $section['name'] : ('Section ' . $section['id'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="sectionCapacityMonitor" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    Select a section to view capacity monitoring.
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Madrasah Grade Level</label>
                <select name="madrasah_gradelevel" id="modalArabicGradelevel"
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500"
                        onchange="filterArabicSections('modalArabicGradelevel', 'modalArabicSection')">
                    <option value="N/A">N/A</option>
                    <?php foreach ($arabicGradeLevels as $grade): ?>
                        <option value="<?= h((string) $grade['id']) ?>" data-grade-id="<?= h((string) $grade['id']) ?>">
                            <?= h((string) $grade['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Madrasah Section</label>
                <select name="madrasah_section" id="modalArabicSection"
                        class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
                    <option value="N/A">N/A</option>
                    <?php foreach ($arabicSections as $section): ?>
                        <option value="<?= h((string) $section['id']) ?>" data-gradelevel="<?= h((string) $section['gradelevel_id']) ?>">
                            <?= h((string) $section['section_arabic']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Department Average</label>
                <input type="number" name="department_average" min="0" max="100" step="0.01" value="85"
                       class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
            </div>

            <div class="rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Madrasah Average</label>
                <input type="number" name="madrasah_average" min="0" max="100" step="0.01" value="0"
                       class="mt-1 w-full rounded-xl border-slate-300 focus:border-sea-500 focus:ring-sea-500">
            </div>

            <div class="lg:col-span-2 rounded-2xl border border-green-200 bg-green-50/40 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-green-700">House / Organization</label>
                <p class="text-xs text-slate-500 mt-0.5 mb-2">Select the house this student belongs to. Leave blank if not applicable.</p>
                <select name="house_id" id="modalHouseId"
                        class="w-full rounded-xl border-slate-300 focus:border-green-500 focus:ring-green-500">
                    <option value="0">— No House Assigned —</option>
                    <?php foreach ($houseOptions as $house): ?>
                        <option value="<?= h((string) $house['id']) ?>"><?= h($house['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($houseOptions)): ?>
                    <p class="text-xs text-amber-600 mt-1">No houses found. Add houses to the <code>house</code> table to enable this selection.</p>
                <?php endif; ?>
            </div>

            <div class="lg:col-span-2 rounded-2xl border border-slate-200 p-4">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Enrollment Status</label>
                <input type="text" id="modalStatusDisplay" readonly
                       class="mt-1 w-full rounded-xl border-slate-300 bg-slate-50 text-slate-700 font-semibold">
                <input type="hidden" name="status" id="modalStatus" value="For Madrasah Enrollment">
                <p class="mt-1 text-xs text-slate-500">Status is assigned automatically based on department.</p>
            </div>

            <div id="documentsSection" class="lg:col-span-2 rounded-2xl border border-green-200 bg-green-50/40 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-green-700 mb-3">Required Documents Submitted</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" name="doc_id_pic" value="1" class="w-4 h-4 rounded text-green-600 border-slate-300 focus:ring-green-500">
                        <span class="text-sm font-medium text-slate-700">ID Picture</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" name="doc_good_moral" value="1" class="w-4 h-4 rounded text-green-600 border-slate-300 focus:ring-green-500">
                        <span class="text-sm font-medium text-slate-700">Good Moral</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" name="doc_card" value="1" class="w-4 h-4 rounded text-green-600 border-slate-300 focus:ring-green-500">
                        <span class="text-sm font-medium text-slate-700">Report Card</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" name="doc_psa" value="1" class="w-4 h-4 rounded text-green-600 border-slate-300 focus:ring-green-500">
                        <span class="text-sm font-medium text-slate-700">PSA</span>
                    </label>
                </div>
            </div>

            <div class="lg:col-span-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-1">
                <p id="modalStatusHint" class="text-xs text-slate-500">Review selected details before confirming.</p>
                <button type="button" onclick="closeEnrollmentModal()" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="button" onclick="handleEnrollmentSubmit()" class="rounded-xl bg-sea-600 hover:bg-sea-700 text-white px-5 py-2.5 text-sm font-semibold">Confirm Enrollment</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SHS Madrasah Confirmation Modal ─────────────────────────────────────── -->
<div id="shsMadrasahModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm">
        <div class="p-6 border-b border-slate-100">
            <div class="flex items-center gap-3 mb-1">
                <div class="w-10 h-10 rounded-2xl bg-green-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-extrabold text-slate-800">Madrasah Enrollment</h3>
                    <p class="text-xs text-slate-500">Senior High School</p>
                </div>
            </div>
        </div>
        <div class="p-6">
            <p class="text-sm text-slate-700 leading-relaxed mb-6">
                Is this SHS student availing the <strong class="text-green-700">2-day Madrasah</strong> program?
            </p>
            <div class="flex gap-3">
                <button type="button" onclick="confirmShsMadrasah(true)"
                        class="flex-1 rounded-xl bg-green-600 hover:bg-green-700 text-white px-4 py-2.5 text-sm font-semibold transition-colors">
                    Yes — For Madrasah Enrollment
                </button>
                <button type="button" onclick="confirmShsMadrasah(false)"
                        class="flex-1 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-4 py-2.5 text-sm font-semibold transition-colors">
                    No — For Cashier Payment
                </button>
            </div>
            <button type="button" onclick="closeShsMadrasahModal()"
                    class="mt-3 w-full text-center text-xs text-slate-400 hover:text-slate-600 transition-colors">
                ← Go back and review the form
            </button>
        </div>
    </div>
</div>

<script>
    function overlayClose(event, modalId) {
        if (event.target.id === modalId) {
            if (modalId === 'madrasahModal') {
                closeMadrasahModal();
                return;
            }

            closeEnrollmentModal();
        }
    }

    function openMadrasahModal(button) {
        const enrollmentId = button.getAttribute('data-enrollment-id') || '';
        const studentId = button.getAttribute('data-student-id') || '';
        const studentName = button.getAttribute('data-student-name') || 'Unknown Student';
        const gradelevel = button.getAttribute('data-madrasah-gradelevel') || 'N/A';
        const section = button.getAttribute('data-madrasah-section') || 'N/A';
        const average = button.getAttribute('data-madrasah-average') || '0';

        document.getElementById('madrasahEnrollmentId').value = enrollmentId;
        document.getElementById('madrasahStudentMeta').textContent = studentName + ' | Student ID: ' + studentId;
        document.getElementById('madrasahGradelevel').value = gradelevel;
        filterArabicSections('madrasahGradelevel', 'madrasahSection');
        document.getElementById('madrasahSection').value = section;
        document.getElementById('madrasahAverage').value = average;

        const modal = document.getElementById('madrasahModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeMadrasahModal() {
        const modal = document.getElementById('madrasahModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openEnrollmentModal(button) {
        const source = button.getAttribute('data-source') || 'new';
        const studentId = button.getAttribute('data-student-id') || '';
        const studentName = button.getAttribute('data-student-name') || 'Unknown Student';
        const department = button.getAttribute('data-department') || 'Junior High';
        const classification = button.getAttribute('data-classification') || '';

        document.getElementById('modalStudentSource').value = source;
        document.getElementById('modalStudentId').value = studentId;
        document.getElementById('modalStudentMeta').textContent = studentName + ' | Source: ' + source.toUpperCase() + ' | Student ID: ' + studentId;

        const deptSelect = document.getElementById('modalDepartment');
        deptSelect.value = department;

        const classificationSelect = document.getElementById('modalClassification');
        if (classification !== '' && [...classificationSelect.options].some(o => o.value === classification)) {
            classificationSelect.value = classification;
        } else {
            applyClassificationDefaults();
        }

        applyDepartmentDefaults();
        updateModalSummary();

        // Show documents checklist for new students only; reset checkboxes
        const docsSection = document.getElementById('documentsSection');
        if (docsSection) {
            docsSection.style.display = source === 'new' ? '' : 'none';
            docsSection.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
        }

        const modal = document.getElementById('enrollmentModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeEnrollmentModal() {
        const modal = document.getElementById('enrollmentModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function applyDepartmentDefaults() {
        const department = document.getElementById('modalDepartment').value;
        const semester = document.getElementById('modalSemester');
        const strand = document.getElementById('modalStrand');

        if (department === 'Senior High') {
            if ([...semester.options].some(o => o.value === '1st')) {
                semester.value = '1st';
            }
            if ([...strand.options].some(o => o.value === 'ABM')) {
                strand.value = 'ABM';
            }
        } else {
            if ([...semester.options].some(o => o.value === 'N/A')) {
                semester.value = 'N/A';
            }
            if ([...strand.options].some(o => o.value === 'N/A')) {
                strand.value = 'N/A';
            }
        }

        if (department === 'Elementary') {
            document.getElementById('modalArabicGradelevel').value = 'N/A';
            document.getElementById('modalArabicSection').value = 'N/A';
        }

        applyStatusDefaults();
        applyClassificationDefaults();
        filterArabicSections('modalArabicGradelevel', 'modalArabicSection');
        updateSectionCapacityMonitor();
        updateModalSummary();
    }

    function statusForDepartment(department) {
        return department === 'Elementary' ? 'For Cashier Payment' : 'For Madrasah Enrollment';
    }

    function applyStatusDefaults() {
        const department = document.getElementById('modalDepartment').value;
        const status = statusForDepartment(department);
        document.getElementById('modalStatus').value = status;
        document.getElementById('modalStatusDisplay').value = status;
    }

    function applyClassificationDefaults() {
        const department = document.getElementById('modalDepartment').value;
        const classificationSelect = document.getElementById('modalClassification');

        const targetLabel = department === 'Senior High'
            ? 'SHS REGULAR'
            : (department === 'Junior High' ? 'JHS REGULAR' : 'REGULAR');

        const targetOption = [...classificationSelect.options].find(option =>
            (option.getAttribute('data-type-label') || '').toUpperCase() === targetLabel
        );

        if (targetOption) {
            classificationSelect.value = targetOption.value;
        }
    }

    function updateModalSummary() {
        const source = document.getElementById('modalStudentSource').value || '-';
        const studentId = document.getElementById('modalStudentId').value || '-';
        const department = document.getElementById('modalDepartment').value || '-';
        const classificationSelect = document.getElementById('modalClassification');
        const selectedClassification = classificationSelect.selectedOptions[0];
        const typeLabel = selectedClassification
            ? (selectedClassification.getAttribute('data-type-label') || selectedClassification.textContent || '-')
            : '-';
        const dateEnrolled = document.getElementById('modalDateEnrolled').value || '-';
        const status = document.getElementById('modalStatus').value || '-';

        document.getElementById('modalSourceBadge').textContent = 'Source: ' + source.toUpperCase();
        document.getElementById('modalStudentIdBadge').textContent = 'ID: ' + studentId;
        document.getElementById('modalDepartmentBadge').textContent = 'Department: ' + department;
        document.getElementById('modalClassificationBadge').textContent = 'Type: ' + typeLabel;
        document.getElementById('modalStatusHint').textContent = 'Enrollment date ' + dateEnrolled + ' with status "' + status + '".';
    }

    function filterSections() {
        const gradelevel = document.getElementById('modalGradelevel').value;
        const sectionSelect = document.getElementById('modalSection');

        for (const option of sectionSelect.options) {
            if (!option.value) {
                option.hidden = false;
                continue;
            }

            option.hidden = gradelevel !== '' && option.getAttribute('data-gradelevel') !== gradelevel;
        }

        if (sectionSelect.selectedOptions.length > 0 && sectionSelect.selectedOptions[0].hidden) {
            sectionSelect.value = '';
        }

        updateSectionCapacityMonitor();
    }

    function updateSectionCapacityMonitor() {
        const sectionSelect = document.getElementById('modalSection');
        const monitor = document.getElementById('sectionCapacityMonitor');
        if (!sectionSelect || !monitor) {
            return;
        }

        const selectedOption = sectionSelect.selectedOptions[0];
        if (!selectedOption || !selectedOption.value) {
            monitor.className = 'mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600';
            monitor.textContent = 'Select a section to view capacity monitoring.';
            return;
        }

        const capacity = Number(selectedOption.getAttribute('data-capacity') || '0');
        const enrolled = Number(selectedOption.getAttribute('data-enrolled') || '0');
        const remaining = Math.max(0, capacity - enrolled);

        let stateClass = 'mt-3 rounded-xl border px-3 py-2 text-xs ';
        if (capacity > 0 && enrolled >= capacity) {
            stateClass += 'border-rose-200 bg-rose-50 text-rose-700';
        } else if (capacity > 0 && remaining <= 5) {
            stateClass += 'border-amber-200 bg-amber-50 text-amber-700';
        } else {
            stateClass += 'border-emerald-200 bg-emerald-50 text-emerald-700';
        }

        monitor.className = stateClass;
        if (capacity > 0) {
            monitor.textContent = 'Capacity monitor: ' + enrolled + ' enrolled out of ' + capacity + ' slots. Remaining: ' + remaining + '.';
            return;
        }

        monitor.textContent = 'Capacity monitor: ' + enrolled + ' students currently assigned. No section capacity set.';
    }

    function filterArabicSections(gradeSelectId, sectionSelectId) {
        const gradeSelect = document.getElementById(gradeSelectId);
        const sectionSelect = document.getElementById(sectionSelectId);
        if (!gradeSelect || !sectionSelect) {
            return;
        }

        const selectedGradeOption = gradeSelect.selectedOptions[0];
        const gradeId = selectedGradeOption ? (selectedGradeOption.getAttribute('data-grade-id') || '') : '';
        const selectedValue = gradeSelect.value;

        for (const option of sectionSelect.options) {
            if (!option.value || option.value === 'N/A') {
                option.hidden = false;
                continue;
            }

            if (selectedValue === 'N/A' || gradeId === '') {
                option.hidden = true;
                continue;
            }

            option.hidden = option.getAttribute('data-gradelevel') !== gradeId;
        }

        if (selectedValue === 'N/A') {
            sectionSelect.value = 'N/A';
            return;
        }

        if (sectionSelect.selectedOptions.length > 0 && sectionSelect.selectedOptions[0].hidden) {
            sectionSelect.value = '';
        }
    }

    document.getElementById('modalDepartment').addEventListener('change', applyDepartmentDefaults);
    document.getElementById('modalClassification').addEventListener('change', updateModalSummary);
    document.getElementById('modalDateEnrolled').addEventListener('change', updateModalSummary);
    document.getElementById('modalArabicGradelevel').addEventListener('change', () => filterArabicSections('modalArabicGradelevel', 'modalArabicSection'));
    document.getElementById('modalSection').addEventListener('change', updateSectionCapacityMonitor);

    filterArabicSections('modalArabicGradelevel', 'modalArabicSection');
    updateSectionCapacityMonitor();

    // ── SHS Madrasah confirmation ─────────────────────────────────────────────
    function handleEnrollmentSubmit() {
        const department = document.getElementById('modalDepartment').value;
        if (department === 'Senior High') {
            // Show the SHS Madrasah confirmation modal instead of submitting
            document.getElementById('shsMadrasahModal').classList.remove('hidden');
            document.getElementById('shsMadrasahModal').classList.add('flex');
        } else {
            document.querySelector('#enrollmentModal form').submit();
        }
    }

    function closeShsMadrasahModal() {
        document.getElementById('shsMadrasahModal').classList.add('hidden');
        document.getElementById('shsMadrasahModal').classList.remove('flex');
    }

    function confirmShsMadrasah(availing) {
        const status = availing ? 'For Madrasah Enrollment' : 'For Cashier Payment';
        document.getElementById('modalStatus').value = status;
        document.getElementById('modalStatusDisplay').value = status;
        updateModalSummary();
        closeShsMadrasahModal();
        document.querySelector('#enrollmentModal form').submit();
    }
</script>
</body>
</html>
