<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user = current_user();

$activeSchoolYearLabel = '';
$activeTimelineYear = (int) date('Y');
try {
    $activeSyStmt = $connection->prepare(
        'SELECT School_year
         FROM schoolyear
         WHERE Status = 1
         ORDER BY School_year_id DESC
         LIMIT 1'
    );
    $activeSyStmt->execute();
    $activeSchoolYear = $activeSyStmt->get_result()->fetch_assoc();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('index.php');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_lrn') {
            $studentId = to_int($_POST['student_id'] ?? 0);
            $newLrn    = trim((string) ($_POST['new_lrn'] ?? ''));

            if ($studentId <= 0) {
                throw new RuntimeException('Invalid student record.');
            }
            if ($newLrn === '') {
                throw new RuntimeException('New LRN cannot be empty.');
            }
            if (!preg_match('/^[0-9]{1,12}$/', $newLrn)) {
                throw new RuntimeException('LRN must be numeric and up to 12 digits.');
            }

            // Make sure the new LRN is not already used by another preregistration record
            $dupChk = $connection->prepare(
                'SELECT id FROM preregistration WHERE lrn = ? AND id <> ? LIMIT 1'
            );
            $dupChk->bind_param('si', $newLrn, $studentId);
            $dupChk->execute();
            if ($dupChk->get_result()->fetch_assoc()) {
                throw new RuntimeException('That LRN is already assigned to another pre-registration record. Please verify the correct LRN.');
            }

            $updLrn = $connection->prepare('UPDATE preregistration SET lrn = ? WHERE id = ?');
            $updLrn->bind_param('si', $newLrn, $studentId);
            $updLrn->execute();

            flash_set('success', 'LRN updated successfully. The student can now be scheduled.');
            redirect_to('index.php');
        }

        if ($action === 'override_enrollment') {
            $studentId = to_int($_POST['student_id'] ?? 0);
            if ($studentId <= 0) {
                throw new RuntimeException('Invalid student record.');
            }

            // Verify the student exists in preregistration
            $chkStmt = $connection->prepare('SELECT id, lrn FROM preregistration WHERE id = ? LIMIT 1');
            $chkStmt->bind_param('i', $studentId);
            $chkStmt->execute();
            if (!$chkStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('Preregistration record not found.');
            }

            $today           = date('Y-m-d');
            $overrideRemarks = 'Overridden – Bypassed Entrance Exam';
            $statusForEnroll = 'For Enrollment';
            $paid            = 'Paid';

            $latestStmt = $connection->prepare(
                'SELECT exam_id FROM entranceexamination WHERE student_id = ? ORDER BY exam_id DESC LIMIT 1'
            );
            $latestStmt->bind_param('i', $studentId);
            $latestStmt->execute();
            $latest = $latestStmt->get_result()->fetch_assoc();

            if ($latest && isset($latest['exam_id'])) {
                $examId = (int) $latest['exam_id'];
                $updStmt = $connection->prepare(
                    'UPDATE entranceexamination
                     SET exam_date = ?, Payment_Status = ?, Status = ?, Remarks = ?, Date_Result = ?, exam_score = 0
                     WHERE exam_id = ?'
                );
                $updStmt->bind_param('sssssi', $today, $paid, $statusForEnroll, $overrideRemarks, $today, $examId);
                $updStmt->execute();
            } else {
                $insStmt = $connection->prepare(
                    'INSERT INTO entranceexamination
                     (student_id, exam_date, exam_score, Payment_Status, Date_Result, Remarks, Status)
                     VALUES (?, ?, 0, ?, ?, ?, ?)'
                );
                $insStmt->bind_param('isssss', $studentId, $today, $paid, $today, $overrideRemarks, $statusForEnroll);
                $insStmt->execute();
            }

            flash_set('success', 'Student has been overridden and marked For Enrollment successfully.');
            redirect_to('index.php');
        }

        if ($action === 'schedule_exam') {
            $studentId = to_int($_POST['student_id'] ?? 0);
            $examDate = trim((string) ($_POST['exam_date'] ?? ''));
            $paymentStatus = trim((string) ($_POST['payment_status'] ?? 'Unpaid'));
            $status = trim((string) ($_POST['status'] ?? 'Examination'));

            if ($studentId <= 0 || $examDate === '') {
                throw new RuntimeException('Student and exam date are required.');
            }

            $latestStmt = $connection->prepare(
                'SELECT exam_id FROM entranceexamination WHERE student_id = ? ORDER BY exam_id DESC LIMIT 1'
            );
            $latestStmt->bind_param('i', $studentId);
            $latestStmt->execute();
            $latest = $latestStmt->get_result()->fetch_assoc();

            if ($latest && isset($latest['exam_id'])) {
                $examId = (int) $latest['exam_id'];
                $updateStmt = $connection->prepare(
                    'UPDATE entranceexamination
                     SET exam_date = ?, Payment_Status = ?, Status = ?, Remarks = ?, Date_Result = ?
                     WHERE exam_id = ?'
                );
                $pendingRemarks = 'Pending Examination';
                $dateResult = $examDate;
                $updateStmt->bind_param('sssssi', $examDate, $paymentStatus, $status, $pendingRemarks, $dateResult, $examId);
                $updateStmt->execute();
            } else {
                $insertStmt = $connection->prepare(
                    'INSERT INTO entranceexamination
                     (student_id, exam_date, exam_score, Payment_Status, Date_Result, Remarks, Status)
                     VALUES (?, ?, NULL, ?, ?, ?, ?)'
                );
                $pendingRemarks = 'Pending Examination';
                $dateResult = $examDate;
                $insertStmt->bind_param('isssss', $studentId, $examDate, $paymentStatus, $dateResult, $pendingRemarks, $status);
                $insertStmt->execute();
            }

            flash_set('success', 'Entrance exam schedule saved successfully.');
            redirect_to('index.php');
        }

        if ($action === 'save_exam') {
            $examId = to_int($_POST['exam_id'] ?? 0);
            $studentId = to_int($_POST['student_id'] ?? 0);
            $examDate = trim((string) ($_POST['exam_date'] ?? date('Y-m-d')));
            $remarks = trim((string) ($_POST['remarks'] ?? 'Passed'));
            $status = trim((string) ($_POST['status'] ?? 'For Enrollment'));

            if ($examId <= 0 || $studentId <= 0) {
                throw new RuntimeException('A valid exam and student is required.');
            }

            $items = [];
            $totalScore = 0;
            for ($i = 1; $i <= 25; $i++) {
                $score = to_int($_POST['item' . $i] ?? 0);
                if ($score < 0 || $score > 4) {
                    throw new RuntimeException('Every item score must be between 0 and 4.');
                }
                $items[] = $score;
                $totalScore += $score;
            }

            $checkScoreStmt = $connection->prepare('SELECT exam_id FROM entranceexam_score WHERE exam_id = ? LIMIT 1');
            $checkScoreStmt->bind_param('i', $examId);
            $checkScoreStmt->execute();
            $scoreExists = (bool) $checkScoreStmt->get_result()->fetch_assoc();

            if ($scoreExists) {
                $updateScoreSql = 'UPDATE entranceexam_score SET '
                    . 'student_id=?, date_of_exam=?, '
                    . 'item1_score=?, item2_score=?, item3_score=?, item4_score=?, item5_score=?, '
                    . 'item6_score=?, item7_score=?, item8_score=?, item9_score=?, item10_score=?, '
                    . 'item11_score=?, item12_score=?, item13_score=?, item14_score=?, item15_score=?, '
                    . 'item16_score=?, item17_score=?, item18_score=?, item19_score=?, item20_score=?, '
                    . 'item21_score=?, item22_score=?, item23_score=?, item24_score=?, item25_score=? '
                    . 'WHERE exam_id=?';

                $updateScoreStmt = $connection->prepare($updateScoreSql);
                $types = 'is' . str_repeat('i', 25) . 'i';
                $params = array_merge([$studentId, $examDate], $items, [$examId]);
                bind_dynamic_params($updateScoreStmt, $types, $params);
                $updateScoreStmt->execute();
            } else {
                $insertScoreSql = 'INSERT INTO entranceexam_score '
                    . '(exam_id, student_id, date_of_exam, '
                    . 'item1_score, item2_score, item3_score, item4_score, item5_score, '
                    . 'item6_score, item7_score, item8_score, item9_score, item10_score, '
                    . 'item11_score, item12_score, item13_score, item14_score, item15_score, '
                    . 'item16_score, item17_score, item18_score, item19_score, item20_score, '
                    . 'item21_score, item22_score, item23_score, item24_score, item25_score) '
                    . 'VALUES (' . implode(', ', array_fill(0, 28, '?')) . ')';

                $insertScoreStmt = $connection->prepare($insertScoreSql);
                $types = 'iis' . str_repeat('i', 25);
                $params = array_merge([$examId, $studentId, $examDate], $items);
                bind_dynamic_params($insertScoreStmt, $types, $params);
                $insertScoreStmt->execute();
            }

            $resultDate = date('Y-m-d');
            $updateExamStmt = $connection->prepare(
                'UPDATE entranceexamination
                 SET exam_score = ?, Date_Result = ?, Remarks = ?, Status = ?
                 WHERE exam_id = ?'
            );
            $updateExamStmt->bind_param('isssi', $totalScore, $resultDate, $remarks, $status, $examId);
            $updateExamStmt->execute();

            flash_set('success', 'Entrance examination result has been recorded.');
            redirect_to('index.php');
        }
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
        redirect_to('index.php');
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$department = trim((string) ($_GET['department'] ?? ''));
$examStatus = trim((string) ($_GET['exam_status'] ?? ''));

$where = [];
$params = [];
$types = '';

$where[] = 'YEAR(p.submission) = ?';
$params[] = $activeTimelineYear;
$types .= 'i';
$where[] = '(e.exam_id IS NULL OR e.exam_score IS NULL)';

if ($search !== '') {
    $where[] = '(p.surname LIKE ? OR p.firstname LIKE ? OR p.lrn LIKE ?)';
    $likeSearch = '%' . $search . '%';
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $types .= 'sss';
}

if ($department !== '') {
    $where[] = 'p.department = ?';
    $params[] = $department;
    $types .= 's';
}

if ($examStatus !== '') {
    if ($examStatus === 'Unscheduled') {
        $where[] = 'e.exam_id IS NULL';
    } elseif ($examStatus === 'Scheduled') {
        $where[] = 'e.exam_id IS NOT NULL AND (e.Remarks IS NULL OR e.Remarks LIKE ? OR e.Remarks = "")';
        $params[] = '%Pending%';
        $types .= 's';
    } else {
        $where[] = 'e.Status = ?';
        $params[] = $examStatus;
        $types .= 's';
    }
}

$sql = 'SELECT p.id, p.studenttype, p.department, p.lrn, p.surname, p.firstname, p.middlename, p.sex, p.contact, p.submission,
               e.exam_id, e.exam_date, e.exam_score, e.Payment_Status, e.Date_Result, e.Remarks, e.Status AS exam_status
        FROM preregistration p
        LEFT JOIN (
            SELECT ee.*
            FROM entranceexamination ee
            INNER JOIN (
                SELECT student_id, MAX(exam_id) AS latest_exam_id
                FROM entranceexamination
                GROUP BY student_id
            ) latest ON latest.latest_exam_id = ee.exam_id
        ) e ON e.student_id = p.id';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY p.id DESC';

$stmt = $connection->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$applicants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$forEnrollmentStudents = [];
try {
    $forEnrollmentSql = 'SELECT p.id, p.lrn, p.surname, p.firstname, p.middlename, p.department, p.contact,
                                e.exam_id, e.exam_date, e.exam_score, e.Date_Result, e.Remarks, e.Status
                         FROM preregistration p
                         INNER JOIN (
                             SELECT ee.*
                             FROM entranceexamination ee
                             INNER JOIN (
                                 SELECT student_id, MAX(exam_id) AS latest_exam_id
                                 FROM entranceexamination
                                 GROUP BY student_id
                             ) latest ON latest.latest_exam_id = ee.exam_id
                         ) e ON e.student_id = p.id
                         WHERE YEAR(p.submission) = ?
                           AND e.Status = ?
                           AND e.exam_score IS NOT NULL
                         ORDER BY e.Date_Result DESC, e.exam_id DESC';
    $forEnrollmentStmt = $connection->prepare($forEnrollmentSql);
    $forEnrollmentStatus = 'For Enrollment';
    $forEnrollmentStmt->bind_param('is', $activeTimelineYear, $forEnrollmentStatus);
    $forEnrollmentStmt->execute();
    $forEnrollmentStudents = $forEnrollmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable) {
    $forEnrollmentStudents = [];
}

$counts = [
    'applicants' => count($applicants),
    'scheduled' => 0,
    'for_enrollment' => count($forEnrollmentStudents),
    'processed' => 0,
];

$scheduledStudents = [];

foreach ($applicants as $applicant) {
    if (!empty($applicant['exam_id'])) {
        $counts['scheduled']++;
        $scheduledStudents[] = $applicant;
    }

    $statusValue = (string) ($applicant['exam_status'] ?? '');
    if ($statusValue === 'Processed') {
        $counts['processed']++;
    }
}

$departments = ['Elementary', 'Junior High School', 'Senior High School'];

// Header summary counts
$totalApplicantsAll = 0;
try {
    $totalStmt = $connection->prepare(
        'SELECT COUNT(*) AS total FROM preregistration WHERE YEAR(submission) = ?'
    );
    $totalStmt->bind_param('i', $activeTimelineYear);
    $totalStmt->execute();
    $totalRow = stmt_fetch_assoc($totalStmt);
    $totalApplicantsAll = (int) ($totalRow['total'] ?? 0);
} catch (Throwable) {}

$pipelineCounts = ['Unscheduled' => 0, 'Scheduled' => 0, 'For Enrollment' => 0, 'Processed' => 0];
try {
    $pipeSql = 'SELECT
                    SUM(CASE WHEN e.exam_id IS NULL THEN 1 ELSE 0 END) AS unscheduled,
                    SUM(CASE WHEN e.exam_id IS NOT NULL AND (e.Remarks LIKE ? OR e.Remarks IS NULL OR e.Remarks = \'\') THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN e.Status = ? THEN 1 ELSE 0 END) AS for_enrollment,
                    SUM(CASE WHEN e.Status = ? THEN 1 ELSE 0 END) AS processed
                FROM preregistration p
                LEFT JOIN (
                    SELECT ee.* FROM entranceexamination ee
                    INNER JOIN (SELECT student_id, MAX(exam_id) AS lid FROM entranceexamination GROUP BY student_id) lx
                        ON lx.lid = ee.exam_id
                ) e ON e.student_id = p.id
                WHERE YEAR(p.submission) = ?';
    $pipeStmt = $connection->prepare($pipeSql);
    $pendingLike = '%Pending%';
    $feStatus = 'For Enrollment';
    $procStatus = 'Processed';
    $pipeStmt->bind_param('sssi', $pendingLike, $feStatus, $procStatus, $activeTimelineYear);
    $pipeStmt->execute();
    $pipeRow = stmt_fetch_assoc($pipeStmt);
    if ($pipeRow) {
        $pipelineCounts['Unscheduled']   = (int) ($pipeRow['unscheduled']    ?? 0);
        $pipelineCounts['Scheduled']     = (int) ($pipeRow['scheduled']      ?? 0);
        $pipelineCounts['For Enrollment']= (int) ($pipeRow['for_enrollment'] ?? 0);
        $pipelineCounts['Processed']     = (int) ($pipeRow['processed']      ?? 0);
    }
} catch (Throwable) {}

$flash = flash_get();

// ── Duplicate LRN detection ───────────────────────────────────────────────────
// Map lrn => [student_id, ...] so we can warn when two pre-reg records share an LRN
$lrnIdMap       = [];  // lrn => [p.id, ...]
$lrnScheduledId = [];  // lrn => p.id of the entry that already has a scheduled exam
foreach ($applicants as $_a) {
    $_lrn = trim((string) ($_a['lrn'] ?? ''));
    if ($_lrn === '') continue;
    $lrnIdMap[$_lrn][] = (int) $_a['id'];
    if (!empty($_a['exam_id'])) {
        $lrnScheduledId[$_lrn] = (int) $_a['id'];
    }
}


?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admission Module</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Manrope', 'ui-sans-serif', 'system-ui']
                    },
                    colors: {
                        brand: {
                            50: '#f0f7f2',
                            100: '#dcedde',
                            500: '#2e8b57',
                            600: '#166534',
                            700: '#0f4d28',
                            900: '#0a3a1e'
                        }
                    },
                    boxShadow: {
                        panel: '0 20px 45px -20px rgba(79, 70, 229, 0.25)'
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen font-sans">
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.16),_rgba(241,245,249,0.85)_40%,_rgba(241,245,249,1)_75%)]">
    <header class="max-w-7xl mx-auto px-4 pt-8 pb-4 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-white/90 backdrop-blur p-6 sm:p-8 shadow-panel border border-green-100">
            <div class="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
                <div>
                    <p class="text-xs tracking-[0.2em] uppercase text-brand-700 font-semibold">ITFA Enrollment Platform</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Admission &amp; Entrance Examination</h1>
                    <p class="text-slate-500 mt-1.5 text-sm max-w-lg">Manage preregistered applicants, schedule entrance exams, record scores, and track the admission pipeline.</p>
                    <div class="flex flex-wrap gap-3 mt-3">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 border border-green-200 px-3 py-1 text-xs font-semibold text-green-700">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            S.Y. <?= h($activeSchoolYearLabel !== '' ? $activeSchoolYearLabel : (string) $activeTimelineYear) ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <?= h((string) ($user['full_name'] ?? 'User')) ?> · <?= h((string) ($user['role'] ?? 'Staff')) ?>
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 shrink-0">
                    <a href="<?= h(app_url('dashboard/index.php')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Dashboard</a>
                    <a href="<?= h(app_url('admission/index.php')) ?>" class="rounded-xl bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Admission</a>
                    <a href="<?= h(app_url('examination/index.php')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Examination</a>
                    <a href="<?= h(app_url('enrollment/index.php')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Enrollment</a>
                    <a href="<?= h(app_url('logout.php')) ?>" class="rounded-xl border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100">Logout</a>
                </div>
            </div>

            <!-- KPI strip -->
            <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-2xl bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="text-xs text-slate-500 font-medium">Total Applicants</p>
                    <p class="text-2xl font-extrabold text-slate-800 mt-0.5"><?= $totalApplicantsAll ?></p>
                    <p class="text-xs text-slate-400 mt-0.5">Submission year <?= $activeTimelineYear ?></p>
                </div>
                <button type="button" onclick="openScheduledModal()" class="rounded-2xl bg-sky-50 border border-sky-200 px-4 py-3 text-left hover:bg-sky-100 transition-colors group">
                    <p class="text-xs text-sky-700 font-medium">Scheduled</p>
                    <p class="text-2xl font-extrabold text-sky-800 mt-0.5"><?= $pipelineCounts['Scheduled'] ?></p>
                    <p class="text-xs text-sky-500 mt-0.5 group-hover:underline">View list →</p>
                </button>
                <button type="button" onclick="openForEnrollmentModal()" class="rounded-2xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-left hover:bg-emerald-100 transition-colors group">
                    <p class="text-xs text-emerald-700 font-medium">For Enrollment</p>
                    <p class="text-2xl font-extrabold text-emerald-800 mt-0.5"><?= $counts['for_enrollment'] ?></p>
                    <p class="text-xs text-emerald-500 mt-0.5 group-hover:underline">View list →</p>
                </button>
                <div class="rounded-2xl bg-green-50 border border-green-200 px-4 py-3">
                    <p class="text-xs text-green-700 font-medium">Processed</p>
                    <p class="text-2xl font-extrabold text-green-800 mt-0.5"><?= $pipelineCounts['Processed'] ?></p>
                    <p class="text-xs text-green-400 mt-0.5">Completed admissions</p>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 pb-10 sm:px-6 lg:px-8">
        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- ── Applicants Table ───────────────────────────────────────────────── -->
        <section class="mt-4 bg-white border border-slate-200 rounded-3xl shadow-panel p-5 sm:p-6">
            <form method="get" class="grid grid-cols-1 lg:grid-cols-12 gap-3 mb-6">
                <div class="lg:col-span-5">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Search</label>
                    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Surname, first name, or LRN"
                           class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div class="lg:col-span-3">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Department</label>
                    <select name="department" class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= h($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Exam Status</label>
                    <select name="exam_status" class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Any Status</option>
                        <option value="Unscheduled" <?= $examStatus === 'Unscheduled' ? 'selected' : '' ?>>Unscheduled</option>
                        <option value="Scheduled" <?= $examStatus === 'Scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        <option value="Examination" <?= $examStatus === 'Examination' ? 'selected' : '' ?>>Examination</option>
                        <option value="For Enrollment" <?= $examStatus === 'For Enrollment' ? 'selected' : '' ?>>For Enrollment</option>
                        <option value="Processed" <?= $examStatus === 'Processed' ? 'selected' : '' ?>>Processed</option>
                    </select>
                </div>
                <div class="lg:col-span-2 flex items-end gap-2">
                    <button type="submit" class="w-full rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5">Filter</button>
                    <a href="index.php" class="rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Reset</a>
                </div>
            </form>

            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                    <tr>
                        <th class="px-3 py-3 text-left">Applicant</th>
                        <th class="px-3 py-3 text-left">Department</th>
                        <th class="px-3 py-3 text-left">Contact</th>
                        <th class="px-3 py-3 text-left">Exam Schedule</th>
                        <th class="px-3 py-3 text-left">Result</th>
                        <th class="px-3 py-3 text-left">Actions</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    <?php if (!$applicants): ?>
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-slate-500">No applicants found for your filters.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($applicants as $row): ?>
                        <?php
                        $fullName = trim(($row['surname'] ?? '') . ', ' . ($row['firstname'] ?? '') . ' ' . ($row['middlename'] ?? ''));
                        $examLabel = $row['exam_date'] ? date('M d, Y', strtotime((string) $row['exam_date'])) : 'Not scheduled';
                        $statusLabel = (string) ($row['exam_status'] ?: 'Unscheduled');
                        $remarksLabel = (string) ($row['Remarks'] ?: 'Pending');

                        // ── Duplicate-LRN enforcement ────────────────────────
                        $_rowLrn           = trim((string) ($row['lrn'] ?? ''));
                        $isDuplicateLrn    = $_rowLrn !== '' && isset($lrnIdMap[$_rowLrn]) && count($lrnIdMap[$_rowLrn]) > 1;
                        $lrnLockedToOther  = $isDuplicateLrn
                                             && isset($lrnScheduledId[$_rowLrn])
                                             && $lrnScheduledId[$_rowLrn] !== (int) $row['id'];
                        ?>
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-3 py-3 align-top">
                                <p class="font-semibold text-slate-900"><?= h($fullName) ?></p>
                                <p class="text-xs text-slate-500">LRN: <?= h((string) $row['lrn']) ?> | ID: <?= h((string) $row['id']) ?></p>
                                <p class="text-xs text-slate-500">Type: <?= h((string) $row['studenttype']) ?></p>
                                <?php if ($isDuplicateLrn): ?>
                                    <span class="inline-flex items-center gap-1 mt-1 rounded-full bg-amber-100 border border-amber-300 text-amber-800 text-xs font-semibold px-2 py-0.5">
                                        ⚠ Duplicate LRN
                                    </span>
                                <?php endif; ?>
                                <?php if ($lrnLockedToOther): ?>
                                    <p class="text-xs text-rose-600 font-medium mt-1">
                                        Locked — another record with this LRN is already scheduled (ID <?= h((string) $lrnScheduledId[$_rowLrn]) ?>).
                                    </p>
                                    <button type="button"
                                            class="mt-1 inline-flex items-center gap-1 rounded-lg border border-green-300 bg-green-50 text-green-700 text-xs font-semibold px-2.5 py-1 hover:bg-green-100"
                                            onclick='openEditLrnModal(<?= json_encode([
                                                'student_id' => (int) $row['id'],
                                                'name'       => $fullName,
                                                'current_lrn'=> (string) ($row['lrn'] ?? '')
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                        ✏ Edit LRN
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700"><?= h((string) $row['department']) ?></span>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <p><?= h((string) $row['contact']) ?></p>
                                <p class="text-xs text-slate-500"><?= h((string) $row['sex']) ?></p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <p class="font-medium"><?= h($examLabel) ?></p>
                                <p class="text-xs text-slate-500">Payment: <?= h((string) ($row['Payment_Status'] ?: 'Unpaid')) ?></p>
                                <p class="text-xs text-slate-500">Status: <?= h($statusLabel) ?></p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <p class="font-medium"><?= h($remarksLabel) ?></p>
                                <p class="text-xs text-slate-500">Score: <?= h((string) ($row['exam_score'] ?? '-')) ?></p>
                                <p class="text-xs text-slate-500">Result Date: <?= h((string) ($row['Date_Result'] ?? '-')) ?></p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="flex flex-wrap gap-2">
                                    <?php if ($lrnLockedToOther): ?>
                                        <span class="rounded-lg border border-slate-200 bg-slate-100 text-slate-400 text-xs font-semibold px-3 py-2 cursor-not-allowed"
                                              title="This LRN is already scheduled under preregistration ID <?= h((string) $lrnScheduledId[$_rowLrn]) ?>">
                                            🔒 Locked — update LRN to unlock
                                        </span>
                                    <?php else: ?>
                                        <button type="button"
                                                class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold px-3 py-2"
                                                onclick='openScheduleModal(<?= json_encode([
                                                    'student_id' => (int) $row['id'],
                                                    'name' => $fullName,
                                                    'exam_date' => (string) ($row['exam_date'] ?? ''),
                                                    'payment_status' => (string) ($row['Payment_Status'] ?: 'Unpaid'),
                                                    'status' => (string) ($row['exam_status'] ?: 'Examination')
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                            Schedule
                                        </button>
                                    <?php endif; ?>

                                    <button type="button"
                                            class="rounded-lg border border-emerald-300 bg-emerald-50 text-emerald-700 text-xs font-semibold px-3 py-2 <?= empty($row['exam_id']) ? 'opacity-40 cursor-not-allowed' : 'hover:bg-emerald-100' ?>"
                                            <?= empty($row['exam_id']) ? 'disabled' : '' ?>
                                            onclick='openExamModal(<?= json_encode([
                                                'exam_id' => (int) ($row['exam_id'] ?? 0),
                                                'student_id' => (int) $row['id'],
                                                'name' => $fullName,
                                                'exam_date' => (string) ($row['exam_date'] ?: date('Y-m-d')),
                                                'status' => (string) ($row['exam_status'] ?: 'For Enrollment'),
                                                'remarks' => (string) ($row['Remarks'] ?: 'Passed')
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                        Process Exam
                                    </button>

                                    <button type="button"
                                            class="rounded-lg border border-amber-300 bg-amber-50 text-amber-700 text-xs font-semibold px-3 py-2 hover:bg-amber-100"
                                            onclick='openOverrideModal(<?= json_encode([
                                                'student_id' => (int) $row['id'],
                                                'name' => $fullName,
                                                'dept' => (string) ($row['department'] ?? '')
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                            title="Bypass entrance exam and directly mark this student For Enrollment">
                                        Override
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<div id="forEnrollmentModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/50 backdrop-blur-sm p-4">
    <div class="max-w-5xl mx-auto mt-8 mb-10 bg-white rounded-2xl border border-slate-200 shadow-panel">
        <div class="p-6 border-b border-slate-200 flex items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-emerald-600">Qualified Students</p>
                <h2 class="text-lg font-bold">For Enrollment List</h2>
                <p class="text-xs text-slate-500 mt-1">Active Submission Year: <?= h((string) $activeTimelineYear) ?></p>
            </div>
            <button type="button" class="text-slate-500 hover:text-slate-700" onclick="closeForEnrollmentModal()">Close</button>
        </div>

        <div class="p-6">
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                    <tr>
                        <th class="px-3 py-3 text-left">Student</th>
                        <th class="px-3 py-3 text-left">Department</th>
                        <th class="px-3 py-3 text-left">Exam Date</th>
                        <th class="px-3 py-3 text-left">Score</th>
                        <th class="px-3 py-3 text-left">Remarks</th>
                        <th class="px-3 py-3 text-left">Result Date</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    <?php if (!$forEnrollmentStudents): ?>
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-slate-500">No students are currently tagged For Enrollment.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($forEnrollmentStudents as $student): ?>
                        <?php $studentName = trim(($student['surname'] ?? '') . ', ' . ($student['firstname'] ?? '') . ' ' . ($student['middlename'] ?? '')); ?>
                        <tr>
                            <td class="px-3 py-3 align-top">
                                <p class="font-semibold text-slate-900"><?= h($studentName) ?></p>
                                <p class="text-xs text-slate-500">LRN: <?= h((string) ($student['lrn'] ?? '-')) ?> | ID: <?= h((string) ($student['id'] ?? '-')) ?></p>
                            </td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['department'] ?? '-')) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['exam_date'] ?? '-')) ?></td>
                            <td class="px-3 py-3 align-top font-semibold text-emerald-700"><?= h((string) ($student['exam_score'] ?? '-')) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['Remarks'] ?? '-')) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['Date_Result'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="scheduledModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/50 backdrop-blur-sm p-4">
    <div class="max-w-5xl mx-auto mt-8 mb-10 bg-white rounded-2xl border border-slate-200 shadow-panel">
        <div class="p-6 border-b border-slate-200 flex items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-cyan-600">Exam Scheduling</p>
                <h2 class="text-lg font-bold">Scheduled Students</h2>
                <p class="text-xs text-slate-500 mt-1">Active Submission Year: <?= h((string) $activeTimelineYear) ?></p>
            </div>
            <button type="button" class="text-slate-500 hover:text-slate-700" onclick="closeScheduledModal()">Close</button>
        </div>

        <div class="p-6">
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-xs tracking-wide">
                    <tr>
                        <th class="px-3 py-3 text-left">Student</th>
                        <th class="px-3 py-3 text-left">Department</th>
                        <th class="px-3 py-3 text-left">Exam Date</th>
                        <th class="px-3 py-3 text-left">Payment</th>
                        <th class="px-3 py-3 text-left">Status</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    <?php if (!$scheduledStudents): ?>
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-slate-500">No students are currently scheduled.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($scheduledStudents as $student): ?>
                        <?php $studentName = trim(($student['surname'] ?? '') . ', ' . ($student['firstname'] ?? '') . ' ' . ($student['middlename'] ?? '')); ?>
                        <tr>
                            <td class="px-3 py-3 align-top">
                                <p class="font-semibold text-slate-900"><?= h($studentName) ?></p>
                                <p class="text-xs text-slate-500">LRN: <?= h((string) ($student['lrn'] ?? '-')) ?> | ID: <?= h((string) ($student['id'] ?? '-')) ?></p>
                            </td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['department'] ?? '-')) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['exam_date'] ?? '-')) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['Payment_Status'] ?? '-')) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($student['exam_status'] ?? 'Examination')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="editLrnModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-sm p-4">
    <div class="max-w-md mx-auto mt-24 bg-white rounded-2xl border border-green-200 shadow-panel">
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_lrn">
            <input type="hidden" name="student_id" id="editLrn_student_id">

            <div>
                <p class="text-xs uppercase tracking-wide text-green-600 font-semibold">Correct LRN</p>
                <h2 class="text-lg font-bold mt-0.5" id="editLrn_name">Applicant</h2>
            </div>

            <div>
                <label class="text-sm font-semibold text-slate-700">Current LRN</label>
                <p class="mt-1 text-sm font-mono text-slate-500" id="editLrn_current"></p>
            </div>

            <div>
                <label for="editLrn_new" class="text-sm font-semibold text-slate-700">New LRN <span class="text-rose-500">*</span></label>
                <input type="text" id="editLrn_new" name="new_lrn"
                       maxlength="12" pattern="[0-9]{1,12}" required
                       placeholder="Enter correct LRN (up to 12 digits)"
                       class="mt-1 w-full rounded-xl border-slate-300 focus:border-green-500 focus:ring-green-500 font-mono">
                <p class="text-xs text-slate-400 mt-1">Must be numeric, up to 12 digits, and not already used by another applicant.</p>
            </div>

            <div class="flex justify-end gap-2 pt-1">
                <button type="button" onclick="closeEditLrnModal()" class="rounded-xl border border-slate-300 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 hover:bg-green-700 px-5 py-2 font-bold text-white">Save LRN</button>
            </div>
        </form>
    </div>
</div>

<div id="overrideModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-sm p-4">
    <div class="max-w-md mx-auto mt-20 bg-white rounded-2xl border border-amber-200 shadow-panel">
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="override_enrollment">
            <input type="hidden" name="student_id" id="override_student_id">

            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0 text-amber-600 text-lg font-bold">!</div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-amber-600 font-semibold">Override – Bypass Entrance Exam</p>
                    <h2 class="text-lg font-bold mt-0.5" id="override_student_name">Applicant</h2>
                    <p class="text-xs text-slate-500 mt-0.5" id="override_student_dept"></p>
                </div>
            </div>

            <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
                <p class="font-semibold mb-1">This will:</p>
                <ul class="list-disc list-inside space-y-1 text-xs">
                    <li>Skip the entrance examination entirely for this student.</li>
                    <li>Mark the student as <strong>For Enrollment</strong> immediately.</li>
                    <li>Record today's date as the result date.</li>
                    <li>Set remarks to <em>"Overridden – Bypassed Entrance Exam"</em>.</li>
                </ul>
            </div>

            <p class="text-sm text-slate-600">Are you sure you want to proceed? This action cannot be automatically undone without manual database correction.</p>

            <div class="flex justify-end gap-2 pt-1">
                <button type="button" onclick="closeOverrideModal()" class="rounded-xl border border-slate-300 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-amber-500 hover:bg-amber-600 px-5 py-2 font-bold text-white">Confirm Override</button>
            </div>
        </form>
    </div>
</div>

<div id="scheduleModal" class="fixed inset-0 z-40 hidden bg-slate-900/50 backdrop-blur-sm p-4">
    <div class="max-w-lg mx-auto mt-10 bg-white rounded-2xl border border-slate-200 shadow-panel">
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="schedule_exam">
            <input type="hidden" name="student_id" id="schedule_student_id">

            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Schedule Entrance Exam</p>
                    <h2 class="text-lg font-bold" id="schedule_student_name">Applicant</h2>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-700" onclick="closeScheduleModal()">Close</button>
            </div>

            <div>
                <label class="text-sm font-semibold text-slate-700">Exam Date</label>
                <input type="date" name="exam_date" id="schedule_exam_date" required
                       class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-sm font-semibold text-slate-700">Payment Status</label>
                    <select name="payment_status" id="schedule_payment_status"
                            class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                        <option value="Unpaid">Unpaid</option>
                        <option value="Paid">Paid</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Workflow Status</label>
                    <select name="status" id="schedule_status"
                            class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                        <option value="Examination">Examination</option>
                        <option value="For Enrollment">For Enrollment</option>
                        <option value="Processed">Processed</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeScheduleModal()" class="rounded-xl border border-slate-300 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-brand-600 px-4 py-2 font-semibold text-white hover:bg-brand-700">Save Schedule</button>
            </div>
        </form>
    </div>
</div>

<div id="examModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/50 backdrop-blur-sm p-4">
    <div class="max-w-4xl mx-auto mt-6 mb-10 bg-white rounded-2xl border border-slate-200 shadow-panel">
        <form method="post" class="p-6">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_exam">
            <input type="hidden" name="exam_id" id="exam_exam_id">
            <input type="hidden" name="student_id" id="exam_student_id">

            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Actual Examination</p>
                    <h2 class="text-lg font-bold" id="exam_student_name">Applicant</h2>
                </div>
                <button type="button" class="text-slate-500 hover:text-slate-700" onclick="closeExamModal()">Close</button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="text-sm font-semibold text-slate-700">Date of Exam</label>
                    <input type="date" name="exam_date" id="exam_date" required
                           class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Remarks</label>
                    <select name="remarks" id="exam_remarks" class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                        <option value="Passed">Passed</option>
                        <option value="Failed">Failed</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Next Status</label>
                    <select name="status" id="exam_status" class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                        <option value="For Enrollment">For Enrollment</option>
                        <option value="Processed">Processed</option>
                        <option value="Examination">Examination</option>
                    </select>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 p-4 bg-slate-50">
                <p class="text-sm font-semibold mb-3">Item Scores (0 to 4 each)</p>
                <div class="grid grid-cols-2 sm:grid-cols-5 lg:grid-cols-10 gap-2">
                    <?php for ($i = 1; $i <= 25; $i++): ?>
                        <label class="text-xs text-slate-600">
                            Item <?= $i ?>
                            <input type="number" min="0" max="4" value="0" name="item<?= $i ?>"
                                   class="mt-1 w-full rounded-lg border-slate-300 focus:border-brand-500 focus:ring-brand-500">
                        </label>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-5">
                <button type="button" onclick="closeExamModal()" class="rounded-xl border border-slate-300 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Save Exam Result</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditLrnModal(payload) {
        document.getElementById('editLrn_student_id').value     = payload.student_id || '';
        document.getElementById('editLrn_name').textContent     = payload.name || 'Applicant';
        document.getElementById('editLrn_current').textContent  = payload.current_lrn || '—';
        document.getElementById('editLrn_new').value            = '';
        document.getElementById('editLrnModal').classList.remove('hidden');
        setTimeout(() => document.getElementById('editLrn_new').focus(), 60);
    }

    function closeEditLrnModal() {
        document.getElementById('editLrnModal').classList.add('hidden');
    }

    function openOverrideModal(payload) {
        document.getElementById('override_student_id').value       = payload.student_id || '';
        document.getElementById('override_student_name').textContent = payload.name || 'Applicant';
        document.getElementById('override_student_dept').textContent = payload.dept || '';
        document.getElementById('overrideModal').classList.remove('hidden');
    }

    function closeOverrideModal() {
        document.getElementById('overrideModal').classList.add('hidden');
    }

    function openScheduledModal() {
        document.getElementById('scheduledModal').classList.remove('hidden');
    }

    function closeScheduledModal() {
        document.getElementById('scheduledModal').classList.add('hidden');
    }

    function openForEnrollmentModal() {
        document.getElementById('forEnrollmentModal').classList.remove('hidden');
    }

    function closeForEnrollmentModal() {
        document.getElementById('forEnrollmentModal').classList.add('hidden');
    }

    function openScheduleModal(payload) {
        document.getElementById('schedule_student_id').value = payload.student_id || '';
        document.getElementById('schedule_student_name').textContent = payload.name || 'Applicant';
        document.getElementById('schedule_exam_date').value = payload.exam_date || '';
        document.getElementById('schedule_payment_status').value = payload.payment_status || 'Unpaid';
        document.getElementById('schedule_status').value = payload.status || 'Examination';
        document.getElementById('scheduleModal').classList.remove('hidden');
    }

    function closeScheduleModal() {
        document.getElementById('scheduleModal').classList.add('hidden');
    }

    function openExamModal(payload) {
        document.getElementById('exam_exam_id').value = payload.exam_id || '';
        document.getElementById('exam_student_id').value = payload.student_id || '';
        document.getElementById('exam_student_name').textContent = payload.name || 'Applicant';
        document.getElementById('exam_date').value = payload.exam_date || '';
        document.getElementById('exam_remarks').value = payload.remarks || 'Passed';
        document.getElementById('exam_status').value = payload.status || 'For Enrollment';
        document.getElementById('examModal').classList.remove('hidden');
    }

    function closeExamModal() {
        document.getElementById('examModal').classList.add('hidden');
    }
</script>
</body>
</html>
