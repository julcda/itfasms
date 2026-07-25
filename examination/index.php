<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user = current_user();
$flash = flash_get();

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
        $parts = explode('-', (string) $activeSchoolYear['School_year']);
        $firstYear = isset($parts[0]) ? (int) trim($parts[0]) : 0;
        if ($firstYear > 0) {
            $activeTimelineYear = $firstYear;
        }
    }
} catch (Throwable) {
    $activeTimelineYear = (int) date('Y');
}

$search = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
$types = '';

$where[] = 'e.exam_date IS NOT NULL';
$where[] = '(e.exam_score IS NULL OR e.Remarks LIKE ? OR e.Remarks = "")';
$where[] = 'YEAR(p.submission) = ?';
$params[] = '%Pending%';
$params[] = $activeTimelineYear;
$types .= 'si';

if ($search !== '') {
    $where[] = '(p.surname LIKE ? OR p.firstname LIKE ? OR p.lrn LIKE ?)';
    $likeSearch = '%' . $search . '%';
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $types .= 'sss';
}

$sql = 'SELECT e.exam_id, e.student_id, e.exam_date, e.Payment_Status, e.Status, e.Remarks,
               p.lrn, p.surname, p.firstname, p.middlename, p.department, p.sex, p.contact
        FROM entranceexamination e
        INNER JOIN preregistration p ON p.id = e.student_id';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY e.exam_date ASC, e.exam_id DESC';

$stmt = $connection->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$scheduled = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$scheduledCount = count($scheduled);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Examination | ITFA Enrollment System</title>
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
                            500: '#2e8b57',
                            600: '#166534',
                            700: '#0f4d28'
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
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.16),_rgba(241,245,249,0.85)_42%,_rgba(241,245,249,1)_75%)]">
    <header class="max-w-7xl mx-auto px-4 pt-8 pb-4 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-white/90 backdrop-blur p-6 sm:p-8 shadow-panel border border-green-100">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs tracking-[0.2em] uppercase text-brand-700 font-semibold">ITFA Enrollment Platform</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Examination Room</h1>
                    <p class="text-slate-500 mt-2">Students scheduled for examination can now take the entrance questionnaire.</p>
                    <p class="text-xs text-slate-500 mt-2">Logged in as <?= h((string) ($user['full_name'] ?? 'User')) ?> (<?= h((string) ($user['role'] ?? 'Staff')) ?>)</p>
                </div>
                <div class="space-y-3">
                    <div class="flex flex-wrap gap-2 justify-start md:justify-end">
                        <a href="<?= h(app_url('dashboard/index.php')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Dashboard</a>
                        <a href="<?= h(app_url('admission/index.php')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Admission</a>
                        <a href="<?= h(app_url('examination/index.php')) ?>" class="rounded-xl bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Examination</a>
                        <a href="<?= h(app_url('enrollment/index.php')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Enrollment</a>
                        <a href="<?= h(app_url('logout.php')) ?>" class="rounded-xl border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100">Logout</a>
                    </div>
                    <div class="inline-flex rounded-2xl bg-green-50 border border-green-200 px-4 py-2 text-green-800 font-semibold">
                        Scheduled Students: <?= h((string) $scheduledCount) ?>
                    </div>
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

        <section class="bg-white border border-slate-200 rounded-3xl shadow-panel p-5 sm:p-6">
            <form method="get" class="grid grid-cols-1 lg:grid-cols-12 gap-3 mb-6">
                <div class="lg:col-span-10">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Search Scheduled Student</label>
                    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Surname, first name, or LRN"
                           class="mt-1 w-full rounded-xl border-slate-300 focus:border-brand-500 focus:ring-brand-500">
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
                        <th class="px-3 py-3 text-left">Student</th>
                        <th class="px-3 py-3 text-left">Department</th>
                        <th class="px-3 py-3 text-left">Exam Date</th>
                        <th class="px-3 py-3 text-left">Payment</th>
                        <th class="px-3 py-3 text-left">Status</th>
                        <th class="px-3 py-3 text-left">Action</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    <?php if (!$scheduled): ?>
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-slate-500">No scheduled student is waiting for examination.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($scheduled as $row): ?>
                        <?php $fullName = trim(($row['surname'] ?? '') . ', ' . ($row['firstname'] ?? '') . ' ' . ($row['middlename'] ?? '')); ?>
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-3 py-3 align-top">
                                <p class="font-semibold text-slate-900"><?= h($fullName) ?></p>
                                <p class="text-xs text-slate-500">LRN: <?= h((string) $row['lrn']) ?> | Student ID: <?= h((string) $row['student_id']) ?></p>
                                <p class="text-xs text-slate-500">Contact: <?= h((string) $row['contact']) ?></p>
                            </td>
                            <td class="px-3 py-3 align-top"><?= h((string) $row['department']) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) $row['exam_date']) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) $row['Payment_Status']) ?></td>
                            <td class="px-3 py-3 align-top"><?= h((string) ($row['Status'] ?: 'Examination')) ?></td>
                            <td class="px-3 py-3 align-top">
                                <a href="<?= h(app_url('examination/take.php?exam_id=' . (string) $row['exam_id'])) ?>"
                                   class="inline-flex rounded-xl bg-emerald-600 text-white px-3 py-2 text-xs font-semibold hover:bg-emerald-700">
                                    Start Examination
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
