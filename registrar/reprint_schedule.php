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
        'SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
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

// ── All school years for dropdown ─────────────────────────────────────────────
$schoolYears = [];
try {
    $syAll = $connection->query('SELECT School_year FROM schoolyear ORDER BY School_year_id DESC');
    $schoolYears = $syAll ? $syAll->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── Grade levels for dropdown ─────────────────────────────────────────────────
$gradeLevels = [];
try {
    $glRes = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $glRes ? $glRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── Sections for dropdown ─────────────────────────────────────────────────────
$sections = [];
try {
    $scRes = $connection->query('SELECT Section_id, Section_name, Gradelevel_id FROM section ORDER BY Section_name');
    $sections = $scRes ? $scRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim((string) ($_GET['q']       ?? ''));
$filterSy    = trim((string) ($_GET['sy']      ?? $activeSchoolYearLabel));
$filterDept  = trim((string) ($_GET['dept']    ?? ''));
$filterGrade = to_int($_GET['grade']   ?? 0);
$filterSec   = to_int($_GET['section'] ?? 0);

// ── Pagination ────────────────────────────────────────────────────────────────
$perPage     = 30;
$currentPage = max(1, to_int($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ["e.Status = 'Officially Enrolled'", 'e.school_year = ?'];
$params = [$filterSy];
$types  = 's';

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(p.surname LIKE ? OR p.firstname LIKE ? OR p.lrn LIKE ?'
              . ' OR o.surname LIKE ? OR o.firstname LIKE ? OR o.lrn LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    $types   .= 'ssssss';
}

if ($filterDept !== '') {
    $where[]  = 'e.Department = ?';
    $params[] = $filterDept;
    $types   .= 's';
}
if ($filterGrade > 0) {
    $where[]  = 'e.Department_gradelevel = ?';
    $params[] = $filterGrade;
    $types   .= 'i';
}
if ($filterSec > 0) {
    $where[]  = 'CAST(e.Department_section AS UNSIGNED) = ?';
    $params[] = $filterSec;
    $types   .= 'i';
}

$joinSql = '
    FROM enrollment e
    LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
    LEFT JOIN (
        SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename, ops.lrn, ops.contact
        FROM old_studentprofile ops
        INNER JOIN (
            SELECT student_id, MAX(id) AS latest_id FROM old_studentprofile GROUP BY student_id
        ) latest ON latest.latest_id = ops.id
    ) o ON o.student_id = e.student_id
    LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
    LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = e.Department_section
';

$whereClause = ' WHERE ' . implode(' AND ', $where);

// Count total
$totalRows = 0;
try {
    $countStmt = $connection->prepare('SELECT COUNT(*) AS cnt ' . $joinSql . $whereClause);
    bind_dynamic_params($countStmt, $types, $params);
    $countStmt->execute();
    $countRow  = stmt_fetch_assoc($countStmt);
    $totalRows = (int) ($countRow['cnt'] ?? 0);
} catch (Throwable) {}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

// Fetch page of students
$students = [];
try {
    $selectSql = '
        SELECT
            e.id AS enrollment_id,
            e.student_id,
            e.Department,
            e.school_year,
            IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR)) AS gradelevel_name,
            IFNULL(sc.Section_name, e.Department_section)                AS section_name,
            e.Semester,
            e.Date_enrolled,
            COALESCE(
                CONCAT(p.surname, \', \', p.firstname, \' \', IFNULL(p.middlename, \'\')),
                CONCAT(o.surname, \', \', o.firstname, \' \', IFNULL(o.middlename, \'\'))
            ) AS full_name,
            COALESCE(p.lrn, o.lrn, e.student_id) AS lrn,
            IF(p.id IS NOT NULL, \'New\', \'Old\') AS student_type
    ';
    $orderSql  = ' ORDER BY e.Department, e.Department_gradelevel, sc.Section_name, full_name';
    $limitSql  = ' LIMIT ? OFFSET ?';

    $pageParams  = array_merge($params, [$perPage, $offset]);
    $pageTypes   = $types . 'ii';

    $dataStmt = $connection->prepare($selectSql . $joinSql . $whereClause . $orderSql . $limitSql);
    bind_dynamic_params($dataStmt, $pageTypes, $pageParams);
    $dataStmt->execute();
    $students = stmt_fetch_all_assoc($dataStmt);
} catch (Throwable) {}

$flash = flash_get();
$sectionsJson = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Build query string for pagination links (preserve all filters except page)
$baseQuery = http_build_query(array_filter([
    'q'       => $search,
    'sy'      => $filterSy !== $activeSchoolYearLabel ? $filterSy : '',
    'dept'    => $filterDept,
    'grade'   => $filterGrade > 0 ? $filterGrade : '',
    'section' => $filterSec > 0 ? $filterSec : '',
], fn($v) => $v !== '' && $v !== 0 && $v !== null));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reprint Schedule | ITFA Registrar</title>
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
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Reprint Class Schedule</h2>
            <p class="text-slate-500 mt-2">Search officially enrolled students and reprint their class schedule.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?></p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Search & Filters -->
        <form method="GET" action="" class="bg-white border border-slate-200 rounded-3xl shadow-panel p-5 mb-6 space-y-4">
            <!-- Search row -->
            <div class="flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-[220px]">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m0 0A7.5 7.5 0 104.5 4.5a7.5 7.5 0 0012.15 12.15z"/>
                    </svg>
                    <input
                        type="text"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="Search by name or LRN…"
                        autofocus
                        class="w-full pl-9 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
                    >
                </div>
                <!-- School Year -->
                <select name="sy" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= h($sy['School_year']) ?>" <?= $filterSy === $sy['School_year'] ? 'selected' : '' ?>>
                            S.Y. <?= h($sy['School_year']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="rounded-xl bg-green-600 text-white px-5 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">Search</button>
                <?php if ($search !== '' || $filterDept !== '' || $filterGrade > 0 || $filterSec > 0 || $filterSy !== $activeSchoolYearLabel): ?>
                    <a href="reprint_schedule.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">Clear</a>
                <?php endif; ?>
            </div>
            <!-- Filter row -->
            <div class="flex flex-wrap gap-3">
                <select name="dept" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Departments</option>
                    <?php foreach (['Elementary', 'Junior High', 'Senior High'] as $dept): ?>
                        <option value="<?= h($dept) ?>" <?= $filterDept === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="grade" id="filterGradeLevel" onchange="filterSections()" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Grade Levels</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= h((string) $gl['Gradelevel_id']) ?>" <?= $filterGrade === (int) $gl['Gradelevel_id'] ? 'selected' : '' ?>>
                            <?= h($gl['Gradelevel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="section" id="filterSection" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $sc): ?>
                        <option value="<?= h((string) $sc['Section_id']) ?>"
                                data-grade="<?= h((string) $sc['Gradelevel_id']) ?>"
                                <?= $filterSec === (int) $sc['Section_id'] ? 'selected' : '' ?>>
                            <?= h($sc['Section_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <!-- Results summary -->
        <div class="flex items-center justify-between mb-3 px-1">
            <p class="text-sm text-slate-500">
                <?php if ($totalRows > 0): ?>
                    Showing <strong><?= h((string) (($offset) + 1)) ?>–<?= h((string) min($offset + $perPage, $totalRows)) ?></strong>
                    of <strong><?= h((string) $totalRows) ?></strong> students
                <?php else: ?>
                    No students found.
                <?php endif; ?>
            </p>
            <?php if ($totalPages > 1): ?>
                <p class="text-sm text-slate-400">Page <?= h((string) $currentPage) ?> of <?= h((string) $totalPages) ?></p>
            <?php endif; ?>
        </div>

        <!-- Table -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-green-50 text-xs uppercase tracking-wide text-green-800">
                        <tr>
                            <th class="px-4 py-3 text-left">Student</th>
                            <th class="px-4 py-3 text-left">LRN / ID</th>
                            <th class="px-4 py-3 text-left">Department</th>
                            <th class="px-4 py-3 text-left">Grade &amp; Section</th>
                            <th class="px-4 py-3 text-left">Semester</th>
                            <th class="px-4 py-3 text-left">Date Enrolled</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if ($students === []): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                    <?= $search !== '' || $filterDept !== '' || $filterGrade > 0 || $filterSec > 0
                                        ? 'No officially enrolled students match your search.'
                                        : 'No officially enrolled students found for this school year.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $row): ?>
                                <?php
                                    $name        = trim((string) ($row['full_name'] ?? '')) ?: ('ID: ' . $row['student_id']);
                                    $lrn         = trim((string) ($row['lrn'] ?? '-'));
                                    $dept        = (string) ($row['Department'] ?? '-');
                                    $grade       = trim((string) ($row['gradelevel_name'] ?? '-'));
                                    $section     = trim((string) ($row['section_name'] ?? '-'));
                                    $semester    = trim((string) ($row['Semester'] ?? '—'));
                                    $type        = (string) ($row['student_type'] ?? 'New');
                                    $dateEnrolled = (string) ($row['Date_enrolled'] ?? '');
                                    $enrollId    = (int) $row['enrollment_id'];
                                ?>
                                <tr class="hover:bg-green-50/40 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-slate-900"><?= h($name) ?></div>
                                        <span class="inline-flex items-center mt-0.5 rounded-full px-2 py-0.5 text-xs font-semibold <?= $type === 'New' ? 'bg-emerald-100 text-emerald-700' : 'bg-green-100 text-green-700' ?>">
                                            <?= h($type) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= h($lrn) ?></td>
                                    <td class="px-4 py-3 text-slate-700"><?= h($dept) ?></td>
                                    <td class="px-4 py-3 text-slate-700">
                                        <?= h($grade) ?>
                                        <?= ($section !== '' && $section !== '-') ? '– ' . h($section) : '' ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500"><?= h($semester ?: '—') ?></td>
                                    <td class="px-4 py-3 text-slate-500 text-xs">
                                        <?= $dateEnrolled ? h(date('M d, Y', strtotime($dateEnrolled))) : '—' ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <a href="<?= h(app_url('registrar/class_schedule_print.php?enrollment_id=' . $enrollId)) ?>"
                                           target="_blank"
                                           class="inline-flex items-center gap-1.5 rounded-xl bg-green-600 text-white px-3 py-1.5 text-xs font-semibold hover:bg-green-700 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
                <?php
                    $buildPageUrl = function(int $p) use ($baseQuery): string {
                        $qs = $baseQuery !== '' ? $baseQuery . '&page=' . $p : 'page=' . $p;
                        return 'reprint_schedule.php?' . $qs;
                    };
                    $window = 2; // pages around current
                    $pages  = [];
                    $pages[] = 1;
                    for ($i = max(2, $currentPage - $window); $i <= min($totalPages - 1, $currentPage + $window); $i++) {
                        $pages[] = $i;
                    }
                    $pages[] = $totalPages;
                    $pages = array_unique($pages);
                ?>
                <!-- Prev -->
                <?php if ($currentPage > 1): ?>
                    <a href="<?= h($buildPageUrl($currentPage - 1)) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 transition-colors">&#8592; Prev</a>
                <?php else: ?>
                    <span class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm text-slate-300">&#8592; Prev</span>
                <?php endif; ?>

                <?php
                    $prevPage = 0;
                    foreach ($pages as $pg):
                        if ($prevPage > 0 && $pg - $prevPage > 1):
                ?>
                    <span class="px-2 py-2 text-slate-400 text-sm">…</span>
                <?php
                        endif;
                        $prevPage = $pg;
                ?>
                    <a href="<?= h($buildPageUrl($pg)) ?>"
                       class="rounded-xl border px-3 py-2 text-sm font-semibold transition-colors <?= $pg === $currentPage ? 'bg-green-600 border-green-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>">
                        <?= $pg ?>
                    </a>
                <?php endforeach; ?>

                <!-- Next -->
                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?= h($buildPageUrl($currentPage + 1)) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 transition-colors">Next &#8594;</a>
                <?php else: ?>
                    <span class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm text-slate-300">Next &#8594;</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    </main>
</div>

<script>
const allSections = <?= $sectionsJson ?>;

function filterSections() {
    const gradeId  = document.getElementById('filterGradeLevel').value;
    const secSel   = document.getElementById('filterSection');
    const prevVal  = secSel.value;

    // Remove all options except the first
    while (secSel.options.length > 1) secSel.remove(1);

    allSections.forEach(sec => {
        if (gradeId === '' || String(sec.Gradelevel_id) === String(gradeId)) {
            const opt = document.createElement('option');
            opt.value = sec.Section_id;
            opt.textContent = sec.Section_name;
            opt.dataset.grade = sec.Gradelevel_id;
            if (String(sec.Section_id) === String(prevVal)) opt.selected = true;
            secSel.appendChild(opt);
        }
    });
}

// On load, apply section filter to match selected grade
document.addEventListener('DOMContentLoaded', function () {
    const gradeId = document.getElementById('filterGradeLevel').value;
    if (gradeId !== '') filterSections();
});
</script>
</body>
</html>
