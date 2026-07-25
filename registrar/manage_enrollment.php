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
try {
    $syStmt = $connection->prepare(
        'SELECT School_year, Class_start FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow && !empty($syRow['School_year'])) {
        $activeSchoolYearLabel = (string) $syRow['School_year'];
    }
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── All available school years for filter ─────────────────────────────────────
$allSchoolYears = [];
try {
    $syAllStmt = $connection->prepare(
        'SELECT DISTINCT school_year FROM enrollment ORDER BY school_year DESC'
    );
    $syAllStmt->execute();
    $allSchoolYears = array_column(stmt_fetch_all_assoc($syAllStmt), 'school_year');
} catch (Throwable) {}

// ── POST: handle actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('manage_enrollment.php');
    }

    $action       = trim((string) ($_POST['action'] ?? ''));
    $enrollmentId = to_int($_POST['enrollment_id'] ?? 0);

    if ($enrollmentId <= 0) {
        flash_set('error', 'Invalid enrollment record.');
        redirect_to('manage_enrollment.php');
    }

    try {
        if ($action === 'delete_enrollment') {
            // ── Fetch the enrollment record first ─────────────────────────
            $fetchStmt = $connection->prepare(
                'SELECT en.id, en.student_id, en.school_year, en.Status,
                        COALESCE(
                            CONCAT(p.surname, \', \', p.firstname),
                            CONCAT(osp.surname, \', \', osp.firstname)
                        ) AS full_name,
                        COALESCE(p.lrn, osp.lrn) AS lrn
                 FROM enrollment en
                 LEFT JOIN preregistration p ON en.student_id = CAST(p.id AS CHAR)
                 LEFT JOIN (
                     SELECT ops.student_id, ops.surname, ops.firstname, ops.lrn
                     FROM old_studentprofile ops
                     INNER JOIN (
                         SELECT student_id, MAX(id) AS latest_id
                         FROM old_studentprofile GROUP BY student_id
                     ) lx ON lx.latest_id = ops.id
                 ) osp ON (p.id IS NULL AND osp.student_id = en.student_id)
                 WHERE en.id = ? LIMIT 1'
            );
            $fetchStmt->bind_param('i', $enrollmentId);
            $fetchStmt->execute();
            $enrRow = stmt_fetch_assoc($fetchStmt);

            if (!$enrRow) {
                throw new RuntimeException('Enrollment record not found.');
            }

            $schoolYear = (string) ($enrRow['school_year'] ?? '');
            $lrn        = (string) ($enrRow['lrn'] ?? '');

            // ── 1. Resolve studentinfo_id (false = don't create) ──────────
            $studentInfoId = resolve_studentinfo_id_for_enrollment($connection, $enrollmentId, false);

            // ── 2. Delete monthly_payment rows ────────────────────────────
            $mp = $connection->prepare(
                'DELETE mp FROM monthly_payment mp
                 INNER JOIN student_account sa ON sa.id = mp.student_account_id
                 WHERE sa.enrollment_id = ?'
            );
            $mp->bind_param('i', $enrollmentId);
            $mp->execute();

            // ── 3. Delete student_account ─────────────────────────────────
            $sa = $connection->prepare('DELETE FROM student_account WHERE enrollment_id = ?');
            $sa->bind_param('i', $enrollmentId);
            $sa->execute();

            // ── 4. Delete backaccount_payment_records ──────────────────────
            $bpr = $connection->prepare('DELETE FROM backaccount_payment_records WHERE enrollment_id = ?');
            $bpr->bind_param('i', $enrollmentId);
            $bpr->execute();

            // ── 5. Delete student_classes (via studentinfo_id + school year) ──
            if ($studentInfoId > 0 && $schoolYear !== '') {
                $sc = $connection->prepare(
                    'DELETE sca FROM student_classes sca
                     INNER JOIN classes c ON c.Class_id = sca.class_id
                     INNER JOIN schoolyear sy ON sy.School_year_id = c.School_year_id
                     WHERE sca.student_id = ? AND sy.School_year = ?'
                );
                $sc->bind_param('is', $studentInfoId, $schoolYear);
                $sc->execute();

                // Also remove the studentinfo row for this school year
                $siDel = $connection->prepare(
                    'DELETE si FROM studentinfo si
                     INNER JOIN schoolyear sy ON sy.School_year_id = si.School_year_id
                     WHERE si.student_id = ? AND sy.School_year = ?'
                );
                $siDel->bind_param('is', $studentInfoId, $schoolYear);
                $siDel->execute();
            }

            // ── 6. Delete the enrollment record itself ────────────────────
            $del = $connection->prepare('DELETE FROM enrollment WHERE id = ?');
            $del->bind_param('i', $enrollmentId);
            $del->execute();

            $studentName = (string) ($enrRow['full_name'] ?? "ID #{$enrollmentId}");
            flash_set('success', "Enrollment record for {$studentName} (S.Y. {$schoolYear}) has been permanently deleted along with all linked payment and schedule data.");
            redirect_to('manage_enrollment.php?' . http_build_query([
                'sy'     => $schoolYear,
                'status' => '',
                'dept'   => '',
                'q'      => '',
            ]));

        } elseif ($action === 'reset_status') {
            $newStatus = trim((string) ($_POST['new_status'] ?? ''));
            $allowed   = [
                'For Cashier Payment',
                'For Registrar Confirmation',
            ];
            if (!in_array($newStatus, $allowed, true)) {
                throw new RuntimeException('Invalid status transition.');
            }

            // When reverting to For Cashier Payment, remove class assignments and studentinfo
            if ($newStatus === 'For Cashier Payment') {
                $schoolYear    = '';
                $studentInfoId = 0;

                $syStmt2 = $connection->prepare('SELECT school_year FROM enrollment WHERE id = ? LIMIT 1');
                $syStmt2->bind_param('i', $enrollmentId);
                $syStmt2->execute();
                $syRow2     = stmt_fetch_assoc($syStmt2);
                $schoolYear = (string) ($syRow2['school_year'] ?? '');

                $studentInfoId = resolve_studentinfo_id_for_enrollment($connection, $enrollmentId, false);

                if ($studentInfoId > 0 && $schoolYear !== '') {
                    $sc2 = $connection->prepare(
                        'DELETE sca FROM student_classes sca
                         INNER JOIN classes c ON c.Class_id = sca.class_id
                         INNER JOIN schoolyear sy ON sy.School_year_id = c.School_year_id
                         WHERE sca.student_id = ? AND sy.School_year = ?'
                    );
                    $sc2->bind_param('is', $studentInfoId, $schoolYear);
                    $sc2->execute();

                    $siDel2 = $connection->prepare(
                        'DELETE si FROM studentinfo si
                         INNER JOIN schoolyear sy ON sy.School_year_id = si.School_year_id
                         WHERE si.student_id = ? AND sy.School_year = ?'
                    );
                    $siDel2->bind_param('is', $studentInfoId, $schoolYear);
                    $siDel2->execute();
                }

                // Remove payment records too (reverting to before cashier)
                $mp2 = $connection->prepare(
                    'DELETE mp FROM monthly_payment mp
                     INNER JOIN student_account sa ON sa.id = mp.student_account_id
                     WHERE sa.enrollment_id = ?'
                );
                $mp2->bind_param('i', $enrollmentId);
                $mp2->execute();

                $sa2 = $connection->prepare('DELETE FROM student_account WHERE enrollment_id = ?');
                $sa2->bind_param('i', $enrollmentId);
                $sa2->execute();

                $bpr2 = $connection->prepare('DELETE FROM backaccount_payment_records WHERE enrollment_id = ?');
                $bpr2->bind_param('i', $enrollmentId);
                $bpr2->execute();
            }

            // When reverting to For Registrar Confirmation, only remove class assignments
            if ($newStatus === 'For Registrar Confirmation') {
                $schoolYear2   = '';
                $studentInfoId2 = 0;

                $syStmt3 = $connection->prepare('SELECT school_year FROM enrollment WHERE id = ? LIMIT 1');
                $syStmt3->bind_param('i', $enrollmentId);
                $syStmt3->execute();
                $syRow3      = stmt_fetch_assoc($syStmt3);
                $schoolYear2 = (string) ($syRow3['school_year'] ?? '');

                $studentInfoId2 = resolve_studentinfo_id_for_enrollment($connection, $enrollmentId, false);

                if ($studentInfoId2 > 0 && $schoolYear2 !== '') {
                    $sc3 = $connection->prepare(
                        'DELETE sca FROM student_classes sca
                         INNER JOIN classes c ON c.Class_id = sca.class_id
                         INNER JOIN schoolyear sy ON sy.School_year_id = c.School_year_id
                         WHERE sca.student_id = ? AND sy.School_year = ?'
                    );
                    $sc3->bind_param('is', $studentInfoId2, $schoolYear2);
                    $sc3->execute();

                    $siDel3 = $connection->prepare(
                        'DELETE si FROM studentinfo si
                         INNER JOIN schoolyear sy ON sy.School_year_id = si.School_year_id
                         WHERE si.student_id = ? AND sy.School_year = ?'
                    );
                    $siDel3->bind_param('is', $studentInfoId2, $schoolYear2);
                    $siDel3->execute();
                }
            }

            $upd = $connection->prepare('UPDATE enrollment SET Status = ? WHERE id = ?');
            $upd->bind_param('si', $newStatus, $enrollmentId);
            $upd->execute();

            flash_set('success', "Enrollment status reset to \"{$newStatus}\" successfully.");
            redirect_to('manage_enrollment.php');

        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
        redirect_to('manage_enrollment.php');
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterSY     = trim((string) ($_GET['sy']     ?? $activeSchoolYearLabel));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterDept   = trim((string) ($_GET['dept']   ?? ''));
$search       = trim((string) ($_GET['q']      ?? ''));

$where  = [];
$params = [];
$types  = '';

if ($filterSY !== '') {
    $where[]  = 'en.school_year = ?';
    $params[] = $filterSY;
    $types   .= 's';
}
if ($filterStatus !== '') {
    $where[]  = 'en.Status = ?';
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($filterDept !== '') {
    $where[]  = 'en.Department = ?';
    $params[] = $filterDept;
    $types   .= 's';
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(p.surname LIKE ? OR p.firstname LIKE ? OR p.lrn LIKE ?'
              . ' OR osp.surname LIKE ? OR osp.firstname LIKE ? OR osp.lrn LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    $types   .= 'ssssss';
}

$whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$listSql = "SELECT
        en.id, en.student_id, en.school_year, en.Department, en.Strand,
        en.Department_gradelevel, en.Department_section, en.Semester,
        en.Student_classification, en.Date_enrolled, en.Status,
        en.house_id,
        COALESCE(
            CONCAT(p.surname, ', ', p.firstname, ' ', IFNULL(p.middlename, '')),
            CONCAT(osp.surname, ', ', osp.firstname, ' ', IFNULL(osp.middlename, ''))
        ) AS full_name,
        COALESCE(p.lrn, osp.lrn) AS lrn,
        IF(p.id IS NOT NULL, 'New', 'Old') AS student_type,
        IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
        IFNULL(sc.Section_name, en.Department_section) AS section_name,
        h.housename AS house_name,
        (SELECT COUNT(*) FROM backaccount_payment_records bpr WHERE bpr.enrollment_id = en.id) AS payment_count,
        (SELECT IFNULL(SUM(bpr2.payment_amount),0) FROM backaccount_payment_records bpr2 WHERE bpr2.enrollment_id = en.id) AS total_paid,
        (SELECT COUNT(*) FROM student_account sa WHERE sa.enrollment_id = en.id) AS has_account,
        (SELECT COUNT(*) FROM monthly_payment mp INNER JOIN student_account sa2 ON sa2.id = mp.student_account_id WHERE sa2.enrollment_id = en.id) AS monthly_count
    FROM enrollment en
    LEFT JOIN preregistration p ON en.student_id = CAST(p.id AS CHAR)
    LEFT JOIN (
        SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename, ops.lrn
        FROM old_studentprofile ops
        INNER JOIN (
            SELECT student_id, MAX(id) AS latest_id
            FROM old_studentprofile GROUP BY student_id
        ) lx ON lx.latest_id = ops.id
    ) osp ON (p.id IS NULL AND osp.student_id = en.student_id)
    LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
    LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = en.Department_section
    LEFT JOIN house h ON h.id = en.house_id
    {$whereClause}
    ORDER BY en.id DESC
    LIMIT 300";

$listStmt = $connection->prepare($listSql);
if ($types !== '') {
    bind_dynamic_params($listStmt, $types, $params);
}
$listStmt->execute();
$records = stmt_fetch_all_assoc($listStmt);

// ── Counts per status for current school year ─────────────────────────────────
$statusCounts = [];
try {
    $scStmt = $connection->prepare(
        'SELECT Status, COUNT(*) AS cnt FROM enrollment WHERE school_year = ? GROUP BY Status'
    );
    $scStmt->bind_param('s', $filterSY);
    $scStmt->execute();
    foreach (stmt_fetch_all_assoc($scStmt) as $row) {
        $statusCounts[(string) $row['Status']] = (int) $row['cnt'];
    }
} catch (Throwable) {}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enrollment Management | Registrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
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

    <!-- ── Sidebar ─────────────────────────────────────────────────────── -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- ── Main content ────────────────────────────────────────────────── -->
    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-rose-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-rose-600 font-semibold">Registrar · Management</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Enrollment Record Management</h2>
            <p class="text-slate-500 mt-2">Search, review, reset status, or permanently delete enrollment records along with all linked payments, accounts, and class assignments.</p>
            <p class="text-xs text-rose-600 font-semibold mt-2">⚠ Deletions are permanent and cannot be undone.</p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Status summary pills -->
        <section class="flex flex-wrap gap-3 mb-6">
            <?php
            $pillData = [
                'For Cashier Payment'          => ['bg-amber-100',  'text-amber-800',  'border-amber-300'],
                'For Registrar Confirmation'    => ['bg-sky-100',    'text-sky-800',    'border-sky-300'],
                'Officially Enrolled'           => ['bg-emerald-100','text-emerald-800','border-emerald-300'],
            ];
            foreach ($pillData as $st => [$bg, $tx, $br]):
                $cnt = $statusCounts[$st] ?? 0;
            ?>
            <a href="?<?= h(http_build_query(['sy' => $filterSY, 'status' => $st, 'dept' => $filterDept, 'q' => $search])) ?>"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-full border text-sm font-semibold <?= $bg ?> <?= $tx ?> <?= $br ?>
                      <?= $filterStatus === $st ? 'ring-2 ring-offset-1 ring-current' : '' ?>">
                <?= h($st) ?>
                <span class="bg-white/60 rounded-full px-2 py-0.5 text-xs font-bold"><?= $cnt ?></span>
            </a>
            <?php endforeach; ?>
            <?php if ($filterStatus !== ''): ?>
            <a href="?<?= h(http_build_query(['sy' => $filterSY, 'status' => '', 'dept' => $filterDept, 'q' => $search])) ?>"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-full border text-sm font-medium bg-slate-100 text-slate-600 border-slate-300">
                × Clear Status Filter
            </a>
            <?php endif; ?>
        </section>

        <!-- Filters -->
        <form method="GET" action="" class="bg-white/80 backdrop-blur border border-slate-200 rounded-2xl p-4 mb-6 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[180px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                <select name="sy" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All School Years</option>
                    <?php foreach ($allSchoolYears as $sy): ?>
                        <option value="<?= h($sy) ?>" <?= $filterSY === $sy ? 'selected' : '' ?>><?= h($sy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Status</label>
                <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Statuses</option>
                    <option value="For Cashier Payment"       <?= $filterStatus === 'For Cashier Payment'       ? 'selected' : '' ?>>For Cashier Payment</option>
                    <option value="For Registrar Confirmation"<?= $filterStatus === 'For Registrar Confirmation'? 'selected' : '' ?>>For Registrar Confirmation</option>
                    <option value="Officially Enrolled"       <?= $filterStatus === 'Officially Enrolled'       ? 'selected' : '' ?>>Officially Enrolled</option>
                </select>
            </div>
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Department</label>
                <select name="dept" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Departments</option>
                    <?php foreach (['Elementary', 'Junior High', 'Senior High'] as $d): ?>
                        <option value="<?= h($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Search</label>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or LRN…"
                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-green-600 text-white text-sm font-semibold hover:bg-green-700">Filter</button>
                <a href="manage_enrollment.php" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-600 text-sm font-semibold hover:bg-slate-200">Reset</a>
            </div>
        </form>

        <!-- Table -->
        <div class="bg-white/90 backdrop-blur rounded-3xl border border-slate-200 shadow-panel overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <p class="font-bold text-slate-700">
                    <?= count($records) ?> record<?= count($records) !== 1 ? 's' : '' ?> found
                    <?= $filterSY !== '' ? '· S.Y. ' . h($filterSY) : '' ?>
                </p>
                <p class="text-xs text-slate-400">Showing up to 300 records. Use filters to narrow results.</p>
            </div>

            <?php if ($records === []): ?>
                <div class="p-12 text-center text-slate-400">
                    <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    No records found. Try adjusting your filters.
                </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">#</th>
                            <th class="px-4 py-3 text-left font-semibold">Student</th>
                            <th class="px-4 py-3 text-left font-semibold">LRN</th>
                            <th class="px-4 py-3 text-left font-semibold">S.Y.</th>
                            <th class="px-4 py-3 text-left font-semibold">Dept / Grade / Section</th>
                            <th class="px-4 py-3 text-left font-semibold">Status</th>
                            <th class="px-4 py-3 text-left font-semibold">Payments</th>
                            <th class="px-4 py-3 text-left font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($records as $i => $rec):
                        $status = (string) ($rec['Status'] ?? '');
                        $statusBadge = match($status) {
                            'For Cashier Payment'          => 'bg-amber-100 text-amber-700 border-amber-200',
                            'For Registrar Confirmation'    => 'bg-sky-100 text-sky-700 border-sky-200',
                            'Officially Enrolled'           => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                            default                         => 'bg-slate-100 text-slate-600 border-slate-200',
                        };
                        $payCount    = (int) ($rec['payment_count'] ?? 0);
                        $totalPaid   = (float) ($rec['total_paid'] ?? 0);
                        $hasAccount  = (int) ($rec['has_account'] ?? 0) > 0;
                        $monthlyCount= (int) ($rec['monthly_count'] ?? 0);
                    ?>
                    <tr class="<?= $i % 2 === 0 ? 'bg-white' : 'bg-slate-50/50' ?> hover:bg-green-50/40 transition-colors">
                        <td class="px-4 py-3 text-slate-400 font-mono text-xs"><?= h((string) $rec['id']) ?></td>
                        <td class="px-4 py-3">
                            <p class="font-semibold text-slate-800"><?= h((string) ($rec['full_name'] ?? '—')) ?></p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                <span class="<?= (string)($rec['student_type']??'') === 'New' ? 'text-green-500' : 'text-slate-500' ?> font-semibold"><?= h((string) ($rec['student_type'] ?? '')) ?></span>
                                <?php if (!empty($rec['house_name'])): ?>
                                    · <span class="text-purple-600">🏠 <?= h((string) $rec['house_name']) ?></span>
                                <?php endif; ?>
                            </p>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600"><?= h((string) ($rec['lrn'] ?? '—')) ?></td>
                        <td class="px-4 py-3 text-xs font-medium text-slate-600"><?= h((string) ($rec['school_year'] ?? '')) ?></td>
                        <td class="px-4 py-3">
                            <p class="text-slate-700 font-medium"><?= h((string) ($rec['Department'] ?? '')) ?></p>
                            <p class="text-xs text-slate-500"><?= h((string) ($rec['gradelevel_name'] ?? '')) ?> · <?= h((string) ($rec['section_name'] ?? '')) ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= $statusBadge ?>">
                                <?= h($status) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600">
                            <?php if ($payCount > 0): ?>
                                <p class="font-semibold text-emerald-700">₱<?= number_format($totalPaid, 2) ?></p>
                                <p class="text-slate-400"><?= $payCount ?> OR<?= $payCount > 1 ? 's' : '' ?>
                                    <?= $hasAccount ? ' · Account' : '' ?>
                                    <?= $monthlyCount > 0 ? " · {$monthlyCount} monthly" : '' ?>
                                </p>
                            <?php else: ?>
                                <span class="text-slate-300">No payments</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 flex-wrap">
                                <?php if ($status === 'Officially Enrolled'): ?>
                                    <!-- Reset to For Registrar Confirmation -->
                                    <button type="button"
                                        onclick="openResetModal(<?= (int)$rec['id'] ?>, <?= h(json_encode((string)($rec['full_name']??''))) ?>, 'For Registrar Confirmation')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sky-100 text-sky-700 border border-sky-200 text-xs font-semibold hover:bg-sky-200 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                        </svg>
                                        Revert to Registrar
                                    </button>
                                <?php endif; ?>
                                <?php if ($status === 'For Registrar Confirmation'): ?>
                                    <!-- Reset to For Cashier Payment -->
                                    <button type="button"
                                        onclick="openResetModal(<?= (int)$rec['id'] ?>, <?= h(json_encode((string)($rec['full_name']??''))) ?>, 'For Cashier Payment')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-100 text-amber-700 border border-amber-200 text-xs font-semibold hover:bg-amber-200 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                        </svg>
                                        Revert to Cashier
                                    </button>
                                <?php endif; ?>
                                <!-- Delete -->
                                <button type="button"
                                    onclick="openDeleteModal(<?= (int)$rec['id'] ?>, <?= h(json_encode((string)($rec['full_name']??''))) ?>, <?= h(json_encode((string)($rec['school_year']??''))) ?>, <?= (int)$payCount ?>, <?= (float)$totalPaid ?>, <?= $hasAccount ? 'true' : 'false' ?>, <?= (int)$monthlyCount ?>)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold hover:bg-rose-200 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ── Delete Confirmation Modal ──────────────────────────────────────────── -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-7">
        <div class="flex items-start gap-4 mb-5">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-rose-100 flex items-center justify-center">
                <svg class="w-6 h-6 text-rose-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-slate-800">Permanently Delete Enrollment</h3>
                <p class="text-sm text-slate-500 mt-1">This action cannot be undone.</p>
            </div>
        </div>

        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4 mb-5">
            <p class="font-bold text-rose-800" id="del_studentName">Student Name</p>
            <p class="text-xs text-rose-600 mt-1" id="del_schoolYear"></p>
            <ul class="mt-3 space-y-1 text-xs text-rose-700" id="del_impactList"></ul>
        </div>

        <p class="text-sm text-slate-600 mb-2">Type <strong>DELETE</strong> to confirm:</p>
        <input type="text" id="deleteConfirmInput" placeholder="Type DELETE here"
            class="w-full rounded-xl border border-rose-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-rose-400 mb-4"
            oninput="checkDeleteConfirm()">

        <form id="deleteForm" method="POST" action="manage_enrollment.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_enrollment">
            <input type="hidden" name="enrollment_id" id="del_enrollmentId" value="">
            <div class="flex gap-3">
                <button type="button" onclick="closeDeleteModal()"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-sm font-semibold hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" id="deleteSubmitBtn" disabled
                    class="flex-1 px-4 py-2.5 rounded-xl bg-rose-600 text-white text-sm font-semibold
                           hover:bg-rose-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                    Delete Permanently
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Reset Status Modal ──────────────────────────────────────────────────── -->
<div id="resetModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-7">
        <div class="flex items-start gap-4 mb-5">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-slate-800">Reset Enrollment Status</h3>
                <p class="text-sm text-slate-500 mt-1" id="reset_subtitle">Revert enrollment to a previous step.</p>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5">
            <p class="font-bold text-amber-800" id="reset_studentName"></p>
            <p class="text-sm text-amber-700 mt-2" id="reset_desc"></p>
        </div>

        <form id="resetForm" method="POST" action="manage_enrollment.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset_status">
            <input type="hidden" name="enrollment_id" id="reset_enrollmentId" value="">
            <input type="hidden" name="new_status" id="reset_newStatus" value="">
            <div class="flex gap-3">
                <button type="button" onclick="closeResetModal()"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-sm font-semibold hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-amber-500 text-white text-sm font-semibold hover:bg-amber-600 transition-colors">
                    Confirm Reset
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Delete modal ─────────────────────────────────────────────────────────────
function openDeleteModal(id, name, sy, payCount, totalPaid, hasAccount, monthlyCount) {
    document.getElementById('del_enrollmentId').value  = id;
    document.getElementById('del_studentName').textContent = name;
    document.getElementById('del_schoolYear').textContent  = 'Enrollment ID #' + id + ' · S.Y. ' + sy;

    const list = document.getElementById('del_impactList');
    list.innerHTML = '';
    const add = (text) => {
        const li = document.createElement('li');
        li.textContent = '⚠ ' + text;
        list.appendChild(li);
    };
    add('Enrollment record will be permanently removed');
    if (payCount > 0) add(payCount + ' payment record(s) · ₱' + totalPaid.toLocaleString('en-PH', {minimumFractionDigits:2}) + ' total');
    if (hasAccount)   add('Student account (installment plan) will be removed');
    if (monthlyCount > 0) add(monthlyCount + ' monthly payment schedule row(s) will be removed');
    add('Class schedule assignments (student_classes) will be removed');
    add('Studentinfo record for this school year will be removed');

    document.getElementById('deleteConfirmInput').value = '';
    document.getElementById('deleteSubmitBtn').disabled = true;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
}
function checkDeleteConfirm() {
    const val = document.getElementById('deleteConfirmInput').value.trim().toUpperCase();
    document.getElementById('deleteSubmitBtn').disabled = (val !== 'DELETE');
}

// ── Reset modal ───────────────────────────────────────────────────────────────
function openResetModal(id, name, newStatus) {
    document.getElementById('reset_enrollmentId').value = id;
    document.getElementById('reset_newStatus').value    = newStatus;
    document.getElementById('reset_studentName').textContent = name;

    const descriptions = {
        'For Registrar Confirmation': 'This will remove all class schedule assignments (student_classes and studentinfo entry) and revert the enrollment to "For Registrar Confirmation". Payment records are kept.',
        'For Cashier Payment':        'This will remove class assignments, studentinfo entry, all payment records, student account, and monthly payment rows. The enrollment will revert to "For Cashier Payment".',
    };
    document.getElementById('reset_desc').textContent     = descriptions[newStatus] || '';
    document.getElementById('reset_subtitle').textContent = 'Reset to: ' + newStatus;

    document.getElementById('resetModal').classList.remove('hidden');
    document.getElementById('resetModal').classList.add('flex');
}
function closeResetModal() {
    document.getElementById('resetModal').classList.add('hidden');
    document.getElementById('resetModal').classList.remove('flex');
}

// Close modals on backdrop click
['deleteModal','resetModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
            this.classList.remove('flex');
        }
    });
});
</script>
</body>
</html>
