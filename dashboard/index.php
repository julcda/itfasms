<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user = current_user();

if (is_enrollment_user($user)) {
    redirect_to(app_url('enrollment/index.php'));
}

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
    $activeTimelineYear = (int) date('Y');
}

$totalApplicantsCount = 0;
try {
    $totalStmt = $connection->prepare(
        'SELECT COUNT(*) AS total FROM preregistration WHERE YEAR(submission) = ?'
    );
    $totalStmt->bind_param('i', $activeTimelineYear);
    $totalStmt->execute();
    $totalRow = stmt_fetch_assoc($totalStmt);
    $totalApplicantsCount = (int) ($totalRow['total'] ?? 0);
} catch (Throwable) {
    $totalApplicantsCount = 0;
}

// Applicants by department
$applicantsByDept = ['Elementary' => 0, 'Junior High School' => 0, 'Senior High School' => 0];
try {
    $deptStmt = $connection->prepare(
        'SELECT department, COUNT(*) AS cnt
         FROM preregistration
         WHERE YEAR(submission) = ?
         GROUP BY department'
    );
    $deptStmt->bind_param('i', $activeTimelineYear);
    $deptStmt->execute();
    foreach (stmt_fetch_all_assoc($deptStmt) as $dr) {
        $applicantsByDept[(string) ($dr['department'] ?? '')] = (int) ($dr['cnt'] ?? 0);
    }
} catch (Throwable) {
}

// Applicants by student type
$applicantsByType = [];
try {
    $typeStmt = $connection->prepare(
        'SELECT studenttype, COUNT(*) AS cnt
         FROM preregistration
         WHERE YEAR(submission) = ?
         GROUP BY studenttype'
    );
    $typeStmt->bind_param('i', $activeTimelineYear);
    $typeStmt->execute();
    foreach (stmt_fetch_all_assoc($typeStmt) as $tr) {
        $applicantsByType[(string) ($tr['studenttype'] ?? '')] = (int) ($tr['cnt'] ?? 0);
    }
} catch (Throwable) {
}

// Pipeline counts
$pipelineCounts = ['Unscheduled' => 0, 'Scheduled' => 0, 'For Enrollment' => 0, 'Processed' => 0];
try {
    $pipeSql = 'SELECT
                    SUM(CASE WHEN e.exam_id IS NULL THEN 1 ELSE 0 END) AS unscheduled,
                    SUM(CASE WHEN e.exam_id IS NOT NULL AND (e.Remarks LIKE ? OR e.Remarks IS NULL OR e.Remarks = \'\') THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN e.Status = ? THEN 1 ELSE 0 END) AS for_enrollment,
                    SUM(CASE WHEN e.Status = ? THEN 1 ELSE 0 END) AS processed
                FROM preregistration p
                LEFT JOIN (
                    SELECT ee.*
                    FROM entranceexamination ee
                    INNER JOIN (
                        SELECT student_id, MAX(exam_id) AS latest_exam_id
                        FROM entranceexamination
                        GROUP BY student_id
                    ) latest ON latest.latest_exam_id = ee.exam_id
                ) e ON e.student_id = p.id
                WHERE YEAR(p.submission) = ?';
    $pipeStmt = $connection->prepare($pipeSql);
    $pendingLike = '%Pending%';
    $forEnrollmentStatus = 'For Enrollment';
    $processedStatus = 'Processed';
    $pipeStmt->bind_param('sssi', $pendingLike, $forEnrollmentStatus, $processedStatus, $activeTimelineYear);
    $pipeStmt->execute();
    $pipeRow = stmt_fetch_assoc($pipeStmt);
    if ($pipeRow) {
        $pipelineCounts['Unscheduled'] = (int) ($pipeRow['unscheduled'] ?? 0);
        $pipelineCounts['Scheduled'] = (int) ($pipeRow['scheduled'] ?? 0);
        $pipelineCounts['For Enrollment'] = (int) ($pipeRow['for_enrollment'] ?? 0);
        $pipelineCounts['Processed'] = (int) ($pipeRow['processed'] ?? 0);
    }
} catch (Throwable) {
}

// Exam result summary
$avgScore = null;
$passCount = 0;
$failCount = 0;
try {
    $scoreStmt = $connection->prepare(
        'SELECT AVG(e.exam_score) AS avg_score,
                SUM(CASE WHEN e.Remarks = ? THEN 1 ELSE 0 END) AS passes,
                SUM(CASE WHEN e.Remarks = ? THEN 1 ELSE 0 END) AS fails
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
           AND e.exam_score IS NOT NULL'
    );
    $passedStatus = 'Passed';
    $failedStatus = 'Failed';
    $scoreStmt->bind_param('ssi', $passedStatus, $failedStatus, $activeTimelineYear);
    $scoreStmt->execute();
    $scoreRow = stmt_fetch_assoc($scoreStmt);
    if ($scoreRow) {
        $avgScore = $scoreRow['avg_score'] !== null ? round((float) $scoreRow['avg_score'], 1) : null;
        $passCount = (int) ($scoreRow['passes'] ?? 0);
        $failCount = (int) ($scoreRow['fails'] ?? 0);
    }
} catch (Throwable) {
}

$stats = [
    'preregistered' => $totalApplicantsCount,
    'scheduled' => $pipelineCounts['Scheduled'],
    'for_enrollment' => $pipelineCounts['For Enrollment'],
    'processed' => $pipelineCounts['Processed'],
];

$recentApplicants = [];
try {
    $recentStmt = $connection->prepare(
        'SELECT p.id, p.surname, p.firstname, p.department, p.studenttype, p.submission,
                e.exam_date, e.Status AS exam_status, e.Remarks
         FROM preregistration p
         LEFT JOIN (
             SELECT ee.*
             FROM entranceexamination ee
             INNER JOIN (
                 SELECT student_id, MAX(exam_id) AS latest_exam_id
                 FROM entranceexamination
                 GROUP BY student_id
             ) latest ON latest.latest_exam_id = ee.exam_id
         ) e ON e.student_id = p.id
         WHERE YEAR(p.submission) = ?
         ORDER BY p.id DESC
         LIMIT 8'
    );
    $recentStmt->bind_param('i', $activeTimelineYear);
    $recentStmt->execute();
    $recentApplicants = stmt_fetch_all_assoc($recentStmt);
} catch (Throwable) {
    $recentApplicants = [];
}

$deptMax = max(1, max($applicantsByDept));
$typeTotal = max(1, array_sum($applicantsByType));

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | ITFA Enrollment System</title>
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
                            50:  '#f0f7f2',
                            500: '#2e8b57',
                            600: '#166534',
                            700: '#0f4d28'
                        }
                    },
                    boxShadow: {
                        panel: '0 18px 40px -20px rgba(22,101,52,0.25)'
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
    <aside class="bg-[#166534] text-slate-100 p-6 lg:p-8" style="background:linear-gradient(180deg,#166534 0%,#0f4d28 100%)">
        <p class="text-xs uppercase tracking-[0.2em] text-green-300 font-semibold">ITFA System</p>
        <h1 class="text-xl font-extrabold mt-2">Enrollment Admin</h1>

        <div class="mt-8 space-y-2">
            <a href="<?= h(app_url('dashboard/index.php')) ?>" class="block rounded-xl bg-green-500/20 border border-green-300/30 px-4 py-3 font-semibold text-green-100">Dashboard</a>
            <a href="<?= h(app_url('admission/index.php')) ?>" class="block rounded-xl px-4 py-3 font-semibold text-slate-200 hover:bg-white/10">Admission</a>
            <a href="<?= h(app_url('examination/index.php')) ?>" class="block rounded-xl px-4 py-3 font-semibold text-slate-200 hover:bg-white/10">Examination</a>
            <a href="<?= h(app_url('enrollment/index.php')) ?>" class="block rounded-xl px-4 py-3 font-semibold text-slate-200 hover:bg-white/10">Enrollment</a>
            <a href="<?= h(app_url('logout.php')) ?>" class="block rounded-xl px-4 py-3 font-semibold text-slate-200 hover:bg-white/10">Logout</a>
        </div>

        <div class="mt-10 rounded-2xl border border-white/10 bg-white/5 p-4">
            <p class="text-xs text-slate-300">Logged in as</p>
            <p class="font-semibold mt-1"><?= h((string) ($user['full_name'] ?? 'User')) ?></p>
            <p class="text-xs text-green-200 mt-1"><?= h((string) ($user['role'] ?? 'Staff')) ?></p>
        </div>
    </aside>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.14),_rgba(241,245,249,0.7)_40%,_rgba(241,245,249,1)_75%)]">
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-brand-700 font-semibold">Dashboard</p>
            <div class="mt-1 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold">Admissions Analytics</h2>
                    <p class="text-slate-500 mt-2">See the full admission funnel, department mix, student types, and current entrance exam outcomes.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-green-700">S.Y. <?= h($activeSchoolYearLabel !== '' ? $activeSchoolYearLabel : (string) $activeTimelineYear) ?></span>
                    <a href="<?= h(app_url('admission/index.php')) ?>" class="inline-flex rounded-xl bg-brand-600 px-4 py-2.5 font-semibold text-white hover:bg-brand-700">Open Admission Module</a>
                </div>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
            <article class="rounded-2xl bg-white border border-slate-200 p-5 shadow-panel">
                <p class="text-sm text-slate-500">Total Applicants</p>
                <p class="text-3xl font-extrabold mt-2"><?= h((string) $stats['preregistered']) ?></p>
                <p class="mt-2 text-xs text-slate-400">Submission year <?= h((string) $activeTimelineYear) ?></p>
            </article>
            <article class="rounded-2xl bg-sky-50 border border-sky-200 p-5 shadow-panel">
                <p class="text-sm text-sky-700">Scheduled Exams</p>
                <p class="text-3xl font-extrabold mt-2 text-sky-800"><?= h((string) $stats['scheduled']) ?></p>
                <p class="mt-2 text-xs text-sky-500">Awaiting assessment date</p>
            </article>
            <article class="rounded-2xl bg-emerald-50 border border-emerald-200 p-5 shadow-panel">
                <p class="text-sm text-emerald-700">For Enrollment</p>
                <p class="text-3xl font-extrabold mt-2 text-emerald-800"><?= h((string) $stats['for_enrollment']) ?></p>
                <p class="mt-2 text-xs text-emerald-500">Qualified after admission</p>
            </article>
            <article class="rounded-2xl bg-green-50 border border-green-200 p-5 shadow-panel">
                <p class="text-sm text-green-700">Processed</p>
                <p class="text-3xl font-extrabold mt-2 text-green-800"><?= h((string) $stats['processed']) ?></p>
                <p class="mt-2 text-xs text-green-500">Completed admission handling</p>
            </article>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.9fr)]">
            <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                <div class="border-b border-slate-100 bg-slate-50 px-6 py-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Admission Pipeline</p>
                    <p class="mt-1 text-sm text-slate-400">Current distribution of applicants this year</p>
                </div>
                <div class="grid grid-cols-2 gap-3 p-5 md:grid-cols-4">
                    <?php
                    $pipelineCards = [
                        ['label' => 'Unscheduled', 'key' => 'Unscheduled', 'bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'dot' => 'bg-slate-400'],
                        ['label' => 'Scheduled', 'key' => 'Scheduled', 'bg' => 'bg-sky-50', 'text' => 'text-sky-800', 'dot' => 'bg-sky-500'],
                        ['label' => 'For Enrollment', 'key' => 'For Enrollment', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-800', 'dot' => 'bg-emerald-500'],
                        ['label' => 'Processed', 'key' => 'Processed', 'bg' => 'bg-green-50', 'text' => 'text-green-800', 'dot' => 'bg-green-500'],
                    ];
                    $pipelineTotal = max(1, array_sum($pipelineCounts));
                    foreach ($pipelineCards as $card):
                        $cardValue = $pipelineCounts[$card['key']] ?? 0;
                        $cardPct = min(100, (int) round($cardValue / $pipelineTotal * 100));
                    ?>
                        <article class="rounded-2xl border border-slate-100 <?= $card['bg'] ?> p-4">
                            <div class="mb-2 flex items-center gap-2">
                                <span class="inline-block h-2 w-2 rounded-full <?= $card['dot'] ?>"></span>
                                <p class="text-xs font-semibold text-slate-500"><?= h($card['label']) ?></p>
                            </div>
                            <p class="text-2xl font-extrabold <?= $card['text'] ?>"><?= $cardValue ?></p>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/70">
                                <div class="h-full rounded-full <?= $card['dot'] ?>" style="width:<?= $cardPct ?>%"></div>
                            </div>
                            <p class="mt-1 text-xs text-slate-400"><?= $cardPct ?>% of applicants</p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="border-t border-slate-100 px-6 py-5">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Applicants by Department</p>
                    <div class="space-y-3">
                        <?php
                        $deptColors = [
                            'Elementary' => ['bar' => 'bg-violet-500', 'text' => 'text-violet-700'],
                            'Junior High School' => ['bar' => 'bg-sky-500', 'text' => 'text-sky-700'],
                            'Senior High School' => ['bar' => 'bg-emerald-500', 'text' => 'text-emerald-700'],
                        ];
                        foreach ($applicantsByDept as $dept => $count):
                            $deptColor = $deptColors[$dept] ?? ['bar' => 'bg-slate-400', 'text' => 'text-slate-700'];
                            $deptPct = min(100, (int) round($count / $deptMax * 100));
                        ?>
                            <div class="flex items-center gap-3">
                                <p class="w-36 truncate text-xs font-semibold text-slate-600"><?= h($dept) ?></p>
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full <?= $deptColor['bar'] ?>" style="width:<?= $deptPct ?>%"></div>
                                </div>
                                <p class="w-10 text-right text-xs font-bold <?= $deptColor['text'] ?>"><?= $count ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <div class="flex flex-col gap-6">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-panel">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Quick Actions</p>
                            <h3 class="mt-1 text-lg font-bold text-slate-900">Admission Workbench</h3>
                        </div>
                        <a href="<?= h(app_url('admission/index.php')) ?>" class="inline-flex rounded-xl border border-brand-200 bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-100">Open</a>
                    </div>
                    <p class="mt-3 text-sm text-slate-500">Schedule entrance exams, encode scores, resolve duplicate LRNs, and override applicants when needed.</p>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                    <div class="border-b border-slate-100 bg-slate-50 px-5 py-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">By Student Type</p>
                    </div>
                    <div class="space-y-3 p-5">
                        <?php if (!$applicantsByType): ?>
                            <p class="py-4 text-center text-xs text-slate-400">No applicant data yet.</p>
                        <?php endif; ?>
                        <?php
                        $typeColors = ['New' => 'bg-green-500', 'Transferee' => 'bg-amber-500', 'Returning' => 'bg-emerald-500'];
                        foreach ($applicantsByType as $studentType => $count):
                            $typePct = min(100, (int) round($count / $typeTotal * 100));
                            $typeBar = $typeColors[$studentType] ?? 'bg-slate-400';
                        ?>
                            <div class="flex items-center gap-3">
                                <p class="w-24 truncate text-xs font-semibold text-slate-600"><?= h($studentType) ?></p>
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full <?= $typeBar ?>" style="width:<?= $typePct ?>%"></div>
                                </div>
                                <p class="w-6 text-right text-xs font-bold text-slate-700"><?= $count ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                    <div class="border-b border-slate-100 bg-slate-50 px-5 py-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Exam Results</p>
                    </div>
                    <div class="space-y-3 p-5">
                        <div class="flex items-center justify-between rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">
                            <p class="text-sm font-semibold text-emerald-800">Passed</p>
                            <p class="text-2xl font-extrabold text-emerald-700"><?= $passCount ?></p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl border border-rose-100 bg-rose-50 px-4 py-3">
                            <p class="text-sm font-semibold text-rose-800">Failed</p>
                            <p class="text-2xl font-extrabold text-rose-700"><?= $failCount ?></p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3">
                            <p class="text-sm font-semibold text-amber-800">Average Score</p>
                            <p class="text-2xl font-extrabold text-amber-700"><?= $avgScore !== null ? h((string) $avgScore) : '—' ?></p>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <section class="mt-6 rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
            <div class="border-b border-slate-100 px-6 py-4">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Recent Applicants</p>
                <p class="mt-1 text-sm text-slate-400">Latest preregistered students for this submission year</p>
            </div>
            <div class="divide-y divide-slate-100">
                <?php if (!$recentApplicants): ?>
                    <div class="px-6 py-8 text-center text-sm text-slate-500">No applicant data available yet.</div>
                <?php endif; ?>
                <?php foreach ($recentApplicants as $row): ?>
                    <?php
                    $name = trim(((string) ($row['surname'] ?? '')) . ', ' . ((string) ($row['firstname'] ?? '')));
                    $examStatus = (string) ($row['exam_status'] ?? 'Unscheduled');
                    $remarks = (string) ($row['Remarks'] ?? '');
                    $statusClasses = match ($examStatus) {
                        'For Enrollment' => 'bg-emerald-100 text-emerald-800',
                        'Processed' => 'bg-green-100 text-green-800',
                        'Examination' => 'bg-sky-100 text-sky-800',
                        default => 'bg-slate-100 text-slate-600',
                    };
                    $remarksClasses = match ($remarks) {
                        'Passed' => 'bg-emerald-50 text-emerald-700',
                        'Failed' => 'bg-rose-50 text-rose-700',
                        default => '',
                    };
                    ?>
                    <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-green-100 text-sm font-bold text-green-700">
                                <?= h(mb_strtoupper(mb_substr((string) ($row['surname'] ?? 'A'), 0, 1))) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900"><?= h($name) ?></p>
                                <p class="truncate text-xs text-slate-400"><?= h((string) ($row['department'] ?? '-')) ?> · <?= h((string) ($row['studenttype'] ?? '-')) ?></p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                <?= h((string) ($row['exam_date'] ?? 'No exam date')) ?>
                            </span>
                            <?php if ($remarks !== '' && $remarksClasses !== ''): ?>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $remarksClasses ?>"><?= h($remarks) ?></span>
                            <?php endif; ?>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusClasses ?>"><?= h($examStatus) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
