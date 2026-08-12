<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_depthead_admin($user) && !is_super_admin($user)) {
    flash_set('error', 'Access denied. Admin login required.');
    redirect_to(app_url('login.php'));
}

// ── POST: transfer a student to another section ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('sectioning.php');
    }

    $action       = trim((string) ($_POST['action'] ?? ''));
    $enrollmentId = to_int($_POST['enrollment_id'] ?? 0);
    $newSectionId = to_int($_POST['new_section_id'] ?? 0);
    $redirectQs   = (string) ($_POST['return'] ?? '');
    $redirect     = 'sectioning.php' . ($redirectQs !== '' ? ('?' . $redirectQs) : '');

    try {
        if ($action !== 'transfer' || $enrollmentId <= 0 || $newSectionId <= 0) {
            throw new RuntimeException('Invalid transfer request.');
        }

        // Load the enrollment row
        $enrStmt = $connection->prepare(
            'SELECT id, student_id, school_year, Department_gradelevel, Department_section FROM enrollment WHERE id = ? LIMIT 1'
        );
        $enrStmt->bind_param('i', $enrollmentId);
        $enrStmt->execute();
        $enr = stmt_fetch_assoc($enrStmt);
        if (!$enr) {
            throw new RuntimeException('Enrollment record not found.');
        }

        // Verify the target section belongs to the same grade level
        $secStmt = $connection->prepare('SELECT Section_id, Section_name, Gradelevel_id FROM section WHERE Section_id = ? LIMIT 1');
        $secStmt->bind_param('i', $newSectionId);
        $secStmt->execute();
        $sec = stmt_fetch_assoc($secStmt);
        if (!$sec) {
            throw new RuntimeException('Target section not found.');
        }
        if ((int) $sec['Gradelevel_id'] !== (int) $enr['Department_gradelevel']) {
            throw new RuntimeException('You can only transfer a student into a section of the same grade level.');
        }

        $newSectionStr = (string) $newSectionId;
        $updStmt = $connection->prepare('UPDATE enrollment SET Department_section = ? WHERE id = ?');
        $updStmt->bind_param('si', $newSectionStr, $enrollmentId);
        $updStmt->execute();

        // Best-effort: keep studentinfo.Section in sync (by LRN + school year)
        try {
            $syId = resolve_school_year_id_by_label($connection, (string) ($enr['school_year'] ?? ''));
            $lrnStmt = $connection->prepare('SELECT lrn FROM preregistration WHERE CAST(id AS CHAR) = ? LIMIT 1');
            $sid = (string) $enr['student_id'];
            $lrnStmt->bind_param('s', $sid);
            $lrnStmt->execute();
            $lrn = (string) (stmt_fetch_assoc($lrnStmt)['lrn'] ?? '');
            $lrnDigits = preg_replace('/\D+/', '', $lrn) ?? '';
            if ($syId > 0 && $lrnDigits !== '') {
                $siStmt = $connection->prepare('UPDATE studentinfo SET Section = ? WHERE LRN_no = ? AND School_year_id = ?');
                $siStmt->bind_param('isi', $newSectionId, $lrnDigits, $syId);
                $siStmt->execute();

                // Re-enrol the student into the NEW section's classes. Teacher
                // rosters and grading read student_classes (not the section
                // label), so without this the transfer never reaches the
                // teachers' accounts.
                $siIdStmt = $connection->prepare('SELECT student_id FROM studentinfo WHERE LRN_no = ? AND School_year_id = ? LIMIT 1');
                $siIdStmt->bind_param('si', $lrnDigits, $syId);
                $siIdStmt->execute();
                $siId = (int) (stmt_fetch_assoc($siIdStmt)['student_id'] ?? 0);
                if ($siId > 0) {
                    // drop stale class links for this school year
                    $delStmt = $connection->prepare(
                        'DELETE sc FROM student_classes sc
                           JOIN classes c ON c.Class_id = sc.class_id
                          WHERE sc.student_id = ? AND c.School_year_id = ?'
                    );
                    $delStmt->bind_param('ii', $siId, $syId);
                    $delStmt->execute();
                    // enrol into every class of the new section for this year
                    $insStmt = $connection->prepare(
                        'INSERT INTO student_classes (class_id, student_id)
                         SELECT c.Class_id, ? FROM classes c
                          WHERE c.Section_id = ? AND c.School_year_id = ?
                            AND NOT EXISTS (SELECT 1 FROM student_classes s2
                                            WHERE s2.student_id = ? AND s2.class_id = c.Class_id)'
                    );
                    $insStmt->bind_param('iiii', $siId, $newSectionId, $syId, $siId);
                    $insStmt->execute();
                }
            }
        } catch (Throwable) {}

        flash_set('success', 'Student transferred to ' . $sec['Section_name'] . '.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to($redirect);
}

// ── School years (default latest year with enrollments) ───────────────────────
$schoolYears = [];
try {
    $syRes = $connection->query('SELECT School_year FROM schoolyear ORDER BY School_year_id DESC');
    $schoolYears = $syRes ? array_column($syRes->fetch_all(MYSQLI_ASSOC), 'School_year') : [];
} catch (Throwable) {}

$defaultSy = '';
try {
    $defRes = $connection->query("SELECT school_year FROM enrollment WHERE school_year <> '' ORDER BY school_year DESC LIMIT 1");
    $defaultSy = (string) (($defRes ? $defRes->fetch_assoc() : null)['school_year'] ?? '');
} catch (Throwable) {}
if ($defaultSy === '') {
    $defaultSy = $schoolYears[0] ?? (date('Y') . '-' . (date('Y') + 1));
}
$activeSchoolYearLabel = $defaultSy; // for sidebar

// ── Filters ───────────────────────────────────────────────────────────────────
$filterSy      = trim((string) ($_GET['sy'] ?? $defaultSy));
$filterDept    = trim((string) ($_GET['dept'] ?? ''));
$filterGrade   = to_int($_GET['grade'] ?? 0);
$filterSection = to_int($_GET['section'] ?? 0);

$returnQs = http_build_query(array_filter([
    'sy' => $filterSy, 'dept' => $filterDept, 'grade' => $filterGrade, 'section' => $filterSection,
], static fn($v) => $v !== '' && $v !== 0 && $v !== '0'));

// ── Lookups ───────────────────────────────────────────────────────────────────
$gradeLevels = [];
try {
    $glRes = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $glRes ? $glRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$sections = [];
try {
    $scRes = $connection->query('SELECT Section_id, Section_name, Gradelevel_id, Capacity FROM section ORDER BY Section_name');
    $sections = $scRes ? $scRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── Student list (only when a grade level is chosen) ──────────────────────────
$rows = [];
if ($filterGrade > 0) {
    $where  = ["e.Status = 'Officially Enrolled'", 'e.school_year = ?', 'e.Department_gradelevel = ?'];
    $params = [$filterSy, $filterGrade];
    $types  = 'si';
    if ($filterDept !== '') {
        $where[]  = 'e.Department = ?';
        $params[] = $filterDept;
        $types   .= 's';
    }
    if ($filterSection > 0) {
        $where[]  = 'e.Department_section = ?';
        $params[] = (string) $filterSection;
        $types   .= 's';
    }

    $sql = '
        SELECT
            e.id AS enrollment_id, e.student_id, e.Department, e.Department_gradelevel, e.Department_section,
            IFNULL(sc.Section_name, e.Department_section) AS section_name,
            CONCAT_WS(" ", NULLIF(TRIM(p.surname),""), NULLIF(TRIM(p.firstname),""), NULLIF(TRIM(p.middlename),"")) AS new_name,
            p.lrn AS new_lrn,
            CONCAT_WS(" ", NULLIF(TRIM(o.surname),""), NULLIF(TRIM(o.firstname),""), NULLIF(TRIM(o.middlename),"")) AS old_name,
            o.lrn AS old_lrn
        FROM enrollment e
        LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
        LEFT JOIN (
            SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename, ops.lrn
            FROM old_studentprofile ops
            INNER JOIN (SELECT student_id, MAX(id) AS latest_id FROM old_studentprofile GROUP BY student_id) lx
                ON lx.latest_id = ops.id
        ) o ON o.student_id = e.student_id
        LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = e.Department_section
    ';
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY section_name, new_name, old_name';

    try {
        $stmt = $connection->prepare($sql);
        bind_dynamic_params($stmt, $types, $params);
        $stmt->execute();
        $rows = stmt_fetch_all_assoc($stmt);
    } catch (Throwable) {
        $rows = [];
    }
}

$flash = flash_get();
$sectionsJson = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sectioning | ITFA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
            boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.22)' }
        } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Admin Management</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">Student Sectioning</h2>
            <p class="text-slate-500 mt-2">Review enrolled students per grade level and transfer them between sections of the same grade.</p>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="" class="mb-5 bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-3 items-end shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                <select name="sy" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= h((string) $sy) ?>" <?= $filterSy === (string) $sy ? 'selected' : '' ?>><?= h((string) $sy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Department</label>
                <select name="dept" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <option value="">All Departments</option>
                    <?php foreach (['Elementary', 'Junior High', 'Senior High'] as $dept): ?>
                        <option value="<?= h($dept) ?>" <?= $filterDept === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Grade Level <span class="text-rose-500">*</span></label>
                <select name="grade" id="filterGrade" onchange="filterSectionOpts()" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <option value="0">— Select grade —</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= h((string) $gl['Gradelevel_id']) ?>" <?= $filterGrade === (int) $gl['Gradelevel_id'] ? 'selected' : '' ?>><?= h((string) $gl['Gradelevel']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Section</label>
                <select name="section" id="filterSection" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <option value="0">All Sections</option>
                    <?php foreach ($sections as $sc): ?>
                        <option value="<?= h((string) $sc['Section_id']) ?>" data-grade="<?= h((string) $sc['Gradelevel_id']) ?>" <?= $filterSection === (int) $sc['Section_id'] ? 'selected' : '' ?>><?= h((string) $sc['Section_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rounded-xl bg-green-600 text-white px-5 py-2 text-sm font-semibold hover:bg-green-700 transition-colors">View</button>
        </form>

        <?php if ($filterGrade <= 0): ?>
            <div class="rounded-3xl border border-dashed border-slate-300 bg-white/60 p-12 text-center text-slate-400">
                <p class="font-semibold text-slate-500">Select a grade level to list its enrolled students.</p>
            </div>
        <?php elseif ($rows === []): ?>
            <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-12 text-center text-slate-400">
                <p class="font-semibold text-slate-500">No officially enrolled students found for this filter.</p>
            </div>
        <?php else: ?>
            <div class="mb-3 text-sm text-slate-500"><strong class="text-slate-800"><?= count($rows) ?></strong> student<?= count($rows) !== 1 ? 's' : '' ?> · S.Y. <?= h($filterSy) ?></div>
            <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-4 py-3 text-center w-8">#</th>
                                <th class="px-4 py-3 text-left">Student Name</th>
                                <th class="px-4 py-3 text-left">LRN</th>
                                <th class="px-4 py-3 text-left">Department</th>
                                <th class="px-4 py-3 text-left">Current Section</th>
                                <th class="px-4 py-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php $num = 1; foreach ($rows as $row):
                                $name = trim((string) ($row['new_name'] ?? '')) ?: trim((string) ($row['old_name'] ?? ''));
                                $name = $name !== '' ? $name : ('Student ID: ' . (string) $row['student_id']);
                                $lrn  = (string) ($row['new_lrn'] ?? '') ?: (string) ($row['old_lrn'] ?? '');
                            ?>
                            <tr class="hover:bg-green-50/30 transition-colors">
                                <td class="px-4 py-2.5 text-center text-slate-400 text-xs font-semibold"><?= $num++ ?></td>
                                <td class="px-4 py-2.5 font-semibold"><?= h($name) ?></td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-500"><?= h($lrn ?: '—') ?></td>
                                <td class="px-4 py-2.5 text-xs text-slate-500"><?= h((string) ($row['Department'] ?? '—')) ?></td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full bg-slate-100 text-slate-700 text-xs font-semibold px-2.5 py-0.5"><?= h((string) ($row['section_name'] ?? '—')) ?></span>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <button type="button"
                                            onclick="openTransfer(<?= (int) $row['enrollment_id'] ?>, <?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>, <?= (int) $row['Department_gradelevel'] ?>, <?= htmlspecialchars(json_encode((string) ($row['section_name'] ?? '')), ENT_QUOTES) ?>)"
                                            class="rounded-xl border border-green-300 bg-green-50 text-green-700 px-3 py-1.5 text-xs font-semibold hover:bg-green-100 transition-colors">
                                        Transfer
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

    </main>
</div>

<!-- Transfer Modal -->
<div id="transferModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between">
            <div>
                <h3 class="text-lg font-extrabold">Transfer Student</h3>
                <p class="text-sm text-slate-500 mt-1" id="transferStudent">—</p>
            </div>
            <button type="button" onclick="closeTransfer()" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="sectioning.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="transfer">
            <input type="hidden" name="enrollment_id" id="transferEnrollmentId">
            <input type="hidden" name="return" value="<?= h($returnQs) ?>">
            <div>
                <p class="text-xs text-slate-500 mb-2">Current section: <strong id="transferCurrent" class="text-slate-700">—</strong></p>
                <label class="block text-sm font-semibold text-slate-700 mb-1">New Section</label>
                <select name="new_section_id" id="transferSection" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="">— Select section —</option>
                </select>
                <p class="text-xs text-slate-400 mt-1">Only sections of the same grade level are shown.</p>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">Confirm Transfer</button>
                <button type="button" onclick="closeTransfer()" class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
const allSections = <?= $sectionsJson ?>;

function filterSectionOpts() {
    const gradeId = document.getElementById('filterGrade').value;
    const sel = document.getElementById('filterSection');
    for (const opt of sel.options) {
        if (!opt.value || opt.value === '0') { opt.hidden = false; continue; }
        opt.hidden = gradeId !== '0' && opt.getAttribute('data-grade') !== gradeId;
    }
    if (sel.options[sel.selectedIndex]?.hidden) sel.value = '0';
}

function openTransfer(enrollmentId, name, gradeId, currentSection) {
    document.getElementById('transferEnrollmentId').value = enrollmentId;
    document.getElementById('transferStudent').textContent = name;
    document.getElementById('transferCurrent').textContent = currentSection || '—';
    const sel = document.getElementById('transferSection');
    sel.innerHTML = '<option value="">— Select section —</option>';
    allSections
        .filter(s => String(s.Gradelevel_id) === String(gradeId))
        .forEach(s => {
            const o = document.createElement('option');
            o.value = s.Section_id;
            o.textContent = s.Section_name + (s.Capacity ? ' (cap ' + s.Capacity + ')' : '');
            sel.appendChild(o);
        });
    document.getElementById('transferModal').style.display = 'flex';
}
function closeTransfer() { document.getElementById('transferModal').style.display = 'none'; }
document.getElementById('transferModal').addEventListener('click', function (e) { if (e.target === this) closeTransfer(); });

filterSectionOpts();
</script>
</body>
</html>
