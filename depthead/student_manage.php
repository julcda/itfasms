<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/soa_service.php';

require_login();

$connection = db();
$user       = current_user();

// Accessible to Super Admin and to the Registrar.
if (!is_super_admin($user) && !is_registrar_user($user)) {
    flash_set('error', 'You do not have access to Student Management.');
    redirect_to(app_url('login.php'));
}

// Registrars (who are not also depthead/super admin) get the Registrar sidebar.
$isRegistrarOnly = is_registrar_user($user) && !is_super_admin($user)
    && !is_depthead_user($user) && !is_depthead_admin($user);
$sidebarFile = $isRegistrarOnly ? __DIR__ . '/../registrar/sidebar.php' : __DIR__ . '/sidebar.php';
$roleLabel   = $isRegistrarOnly ? 'Registrar' : 'Super Admin';

$adminName = (string) ($user['full_name'] ?? 'Admin');
$sy        = soa_active_school_year($connection);
$syLabel   = $sy['label'];
$syId      = $sy['id'];
$soaReady  = soa_schema_ready($connection);

// ── Dropdown data ─────────────────────────────────────────────────────────────
$classifications = [];
$grades          = [];
$sections        = [];
$houses          = [];
try {
    $r = $connection->query("SELECT classification_id, MAX(classification) AS name, MAX(description) AS descr
                             FROM payment_breakdown WHERE status='Active' GROUP BY classification_id ORDER BY classification_id");
    if ($r) { while ($x = $r->fetch_assoc()) { $classifications[] = $x; } }
    $r = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    if ($r) { while ($x = $r->fetch_assoc()) { $grades[] = $x; } }
    $r = $connection->query('SELECT Section_id, Section_name, Gradelevel_id FROM section ORDER BY Section_name');
    if ($r) { while ($x = $r->fetch_assoc()) { $sections[] = $x; } }
    $r = $connection->query('SELECT id, housename FROM house ORDER BY housename');
    if ($r) { while ($x = $r->fetch_assoc()) { $houses[] = $x; } }
} catch (Throwable) {}

$departments = ['Elementary', 'Junior High', 'Senior High'];
$statuses    = ['Officially Enrolled', 'For Cashier Payment', 'For Registrar Confirmation', 'For Madrasah Enrollment', 'Dropped', 'Transferred Out'];

function sm_audit(mysqli $db, array $user, string $action, string $entityId, ?string $after): void
{
    try {
        $aid = (int) ($user['id'] ?? 0); $name = (string) ($user['full_name'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); $ent = 'enrollment';
        $s = $db->prepare('INSERT INTO financial_audit_logs (actor_id, actor_name, action, entity, entity_id, after_json, ip) VALUES (?,?,?,?,?,?,?)');
        $s->bind_param('issssss', $aid, $name, $action, $ent, $entityId, $after, $ip);
        $s->execute();
    } catch (Throwable) {}
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('student_manage.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $eid    = to_int($_POST['enrollment_id'] ?? 0);
    $back   = 'student_manage.php?eid=' . $eid;

    try {
        if ($eid <= 0) {
            throw new RuntimeException('No student selected.');
        }

        if ($action === 'update_enrollment') {
            $dept   = trim((string) ($_POST['department'] ?? ''));
            $grade  = to_int($_POST['gradelevel_id'] ?? 0);
            $sec    = trim((string) ($_POST['section_id'] ?? ''));
            $cls    = to_int($_POST['classification_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? ''));
            $house  = to_int($_POST['house_id'] ?? 0);
            // Old/New fee override: '' (auto-detect) | 'New' | 'Old'.
            $typeIn = trim((string) ($_POST['student_type'] ?? ''));
            $stype  = in_array($typeIn, ['New', 'Old'], true) ? $typeIn : null;
            // Fee exemptions.
            $waive     = isset($_POST['waive_improvement']) ? 1 : 0;  // School Improvement
            $waiveMisc = isset($_POST['waive_misc']) ? 1 : 0;         // Miscellaneous

            $upd = $connection->prepare(
                'UPDATE enrollment
                 SET Department = ?, Department_gradelevel = ?, Department_section = ?,
                     Student_classification = ?, Status = ?, student_type = ?,
                     waive_school_improvement = ?, waive_miscellaneous = ?
                 WHERE id = ?'
            );
            // Department(s) gradelevel(i) section(s) classification(i) Status(s) student_type(s) waive(i) waiveMisc(i) id(i)
            $upd->bind_param('sisissiii', $dept, $grade, $sec, $cls, $status, $stype, $waive, $waiveMisc, $eid);
            $upd->execute();

            // House lives on enrollment.house_id (added separately) — update safely
            try {
                $hu = $connection->prepare('UPDATE enrollment SET house_id = ? WHERE id = ?');
                $hVal = $house > 0 ? $house : null;
                $hu->bind_param('ii', $hVal, $eid);
                $hu->execute();
            } catch (Throwable) {}

            sm_audit($connection, $user, 'UPDATE_STUDENT', (string) $eid, json_encode([
                'department' => $dept, 'grade' => $grade, 'section' => $sec,
                'classification' => $cls, 'status' => $status, 'house' => $house,
                'student_type' => $stype ?? 'auto', 'waive_school_improvement' => $waive,
                'waive_miscellaneous' => $waiveMisc,
            ]));
            flash_set('success', 'Student record updated. Rebuild the assessment to apply any fee/classification change.');
        } elseif ($action === 'rebuild_assessment') {
            if (!$soaReady) {
                throw new RuntimeException('SOA tables not installed.');
            }
            // Find existing assessment
            $chk = $connection->prepare('SELECT id FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1');
            $chk->bind_param('ii', $eid, $syId);
            $chk->execute();
            $exist = stmt_fetch_assoc($chk);

            $connection->begin_transaction();
            try {
                if ($exist) {
                    // Reassess in place — keeps the enrollment payment, re-derives
                    // totals. Throws if real monthly installment payments exist.
                    $newId = soa_reassess($connection, (int) $exist['id'], $adminName);
                } else {
                    $newId = soa_ensure_assessment($connection, $eid, $syId, $adminName);
                }
                $connection->commit();
            } catch (Throwable $e) {
                $connection->rollback();
                throw $e;
            }
            sm_audit($connection, $user, 'REBUILD_ASSESSMENT', (string) $eid, json_encode(['assessment_id' => $newId]));
            flash_set('success', 'Assessment & fees rebuilt from the current classification (payments preserved).');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to($back);
}

// ── Load selected student ─────────────────────────────────────────────────────
$eid     = to_int($_GET['eid'] ?? 0);
$search  = trim((string) ($_GET['q'] ?? ''));
$student = null;
$results = [];

if ($eid > 0) {
    $stmt = $connection->prepare(
        "SELECT en.id, en.student_id, en.school_year, en.Department, en.Strand,
                en.Department_gradelevel, en.Department_section, en.Student_classification,
                en.Status, en.house_id, en.waive_school_improvement, en.waive_miscellaneous,
                COALESCE(CONCAT(p.surname,', ',p.firstname,' ',IFNULL(p.middlename,'')),
                         CONCAT(osp.surname,', ',osp.firstname,' ',IFNULL(osp.middlename,''))) AS full_name,
                COALESCE(p.lrn, osp.lrn) AS lrn,
                COALESCE(p.contact, osp.contact) AS contact,
                en.student_type AS type_override,
                COALESCE(NULLIF(en.student_type,''), IF(p.id IS NOT NULL,'New','Old')) AS student_type,
                IF(p.id IS NOT NULL,'New','Old') AS type_detected,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name,
                h.housename, pbc.name AS class_name
         FROM enrollment en
         LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         LEFT JOIN house h       ON h.id = en.house_id
         LEFT JOIN (SELECT classification_id, MAX(classification) AS name FROM payment_breakdown GROUP BY classification_id) pbc
                ON pbc.classification_id = en.Student_classification
         WHERE en.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $student = stmt_fetch_assoc($stmt);
}

// Fee preview + assessment status for the selected student
$feeComp = []; $assessment = null; $stale = false; $payCount = 0; $soaCount = 0; $monthlyPreview = 0.0; $installmentPayCount = 0;
if ($student && $soaReady) {
    $fees = soa_fetch_enrollment_fees($connection, $eid);
    if ($fees) {
        $feeComp = soa_components_for(
            $connection, (string) $fees['Department'], (string) $fees['gradelevel_name'],
            (string) $fees['classification'], (string) $fees['student_type'], (float) $fees['rate'],
            (bool) ($fees['waive_improvement'] ?? false),
            (bool) ($fees['waive_misc'] ?? false)
        );
        $monthlyPreview = array_sum($feeComp);
    }
    $aStmt = $connection->prepare('SELECT id, classification_id, student_type, installment_base, installment_count, net_assessed, total_paid, balance FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1');
    $aStmt->bind_param('ii', $eid, $syId);
    $aStmt->execute();
    $assessment = stmt_fetch_assoc($aStmt);
    if ($assessment) {
        $stale = (int) $assessment['classification_id'] !== (int) $student['Student_classification'];
        $aid = (int) $assessment['id'];
        $payCount = (int) ($connection->query("SELECT COUNT(*) c FROM payment_transaction WHERE assessment_id=$aid AND status='Posted'")->fetch_assoc()['c'] ?? 0);
        // Payments allocated to monthly schedule terms (real collections) — these block reassess.
        $installmentPayCount = (int) ($connection->query("SELECT COUNT(DISTINCT pt.id) c FROM payment_installments pi JOIN payment_transaction pt ON pt.id=pi.payment_id WHERE pt.assessment_id=$aid AND pt.status='Posted'")->fetch_assoc()['c'] ?? 0);
        $soaCount = (int) ($connection->query("SELECT COUNT(*) c FROM soa_master WHERE assessment_id=$aid")->fetch_assoc()['c'] ?? 0);
    } else {
        $stale = true;
    }
}

// Search (enrolled students in active SY)
if ($eid <= 0 && $search !== '') {
    $like = '%' . $search . '%';
    $stmt = $connection->prepare(
        "SELECT en.id, en.student_id, en.Department,
                COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname)) AS full_name,
                COALESCE(p.lrn, osp.lrn) AS lrn,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name
         FROM enrollment en
         LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE en.school_year = ?
           AND (en.student_id = ? OR p.lrn LIKE ? OR osp.lrn LIKE ?
                OR p.surname LIKE ? OR p.firstname LIKE ? OR osp.surname LIKE ? OR osp.firstname LIKE ?)
         ORDER BY full_name LIMIT 40"
    );
    $stmt->bind_param('ssssssss', $syLabel, $search, $like, $like, $like, $like, $like, $like);
    $stmt->execute();
    $results = stmt_fetch_all_assoc($stmt);
}

$flash = flash_get();
$activeSchoolYearLabel = $syLabel; // for sidebar
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Management | ITFA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
            boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' }
        } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require $sidebarFile; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold"><?= h($roleLabel) ?></p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Student Management</h1>
            <p class="text-slate-500 mt-2">Update a student's department, grade, section, classification, status &amp; house — and rebuild their fees.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?> &nbsp;·&nbsp; <?= h($adminName) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Search -->
        <form method="GET" action="student_manage.php" class="mb-6 flex flex-wrap gap-3">
            <div class="relative flex-1 min-w-[260px]">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-600">🔍</span>
                <input type="text" name="q" value="<?= h($search) ?>" autofocus
                       placeholder="Search student by name, LRN, or ID…"
                       class="w-full rounded-2xl border-2 border-slate-200 pl-11 pr-4 py-3 text-sm font-medium focus:outline-none focus:border-green-500">
            </div>
            <button class="rounded-2xl bg-green-700 hover:bg-green-800 text-white px-6 py-3 text-sm font-bold">Search</button>
            <?php if ($eid > 0): ?><a href="student_manage.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">New search</a><?php endif; ?>
        </form>

        <?php if ($eid <= 0): ?>
            <?php if ($search !== ''): ?>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                <?php if ($results === []): ?>
                <div class="py-14 text-center text-slate-400 font-semibold">No enrolled students matched “<?= h($search) ?>”.</div>
                <?php else: ?>
                <table class="min-w-full text-sm">
                    <thead><tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase tracking-wider">
                        <th class="px-5 py-3 text-left">Student</th><th class="px-5 py-3 text-left">LRN / ID</th>
                        <th class="px-5 py-3 text-left">Dept / Grade / Section</th><th class="px-5 py-3 text-center">Manage</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($results as $r): ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-5 py-3 font-semibold text-slate-800"><?= h(strtoupper(trim((string) ($r['full_name'] ?? 'ID '.$r['student_id'])))) ?></td>
                            <td class="px-5 py-3 text-xs font-mono text-slate-500"><?= h((string) ($r['lrn'] ?: $r['student_id'])) ?></td>
                            <td class="px-5 py-3 text-xs text-slate-600"><?= h((string) $r['Department']) ?> · <?= h(trim((string) $r['grade_name'])) ?><?= $r['section_name'] ? ' · '.h((string) $r['section_name']) : '' ?></td>
                            <td class="px-5 py-3 text-center"><a href="student_manage.php?eid=<?= (int) $r['id'] ?>" class="inline-block rounded-lg bg-green-700 text-white text-xs font-semibold px-4 py-1.5 hover:bg-green-800">Manage →</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-12 text-center text-slate-400">
                <p class="font-semibold text-slate-500">Search for a student to manage their record.</p>
            </div>
            <?php endif; ?>

        <?php elseif (!$student): ?>
            <div class="bg-white rounded-3xl border border-rose-200 shadow-panel p-8 text-center text-rose-600 font-semibold">Student not found.</div>

        <?php else: ?>
        <?php
            $name = strtoupper(trim((string) ($student['full_name'] ?? 'Student')));
            $secId = (string) $student['Department_section'];
        ?>
        <!-- Student header -->
        <div class="rounded-3xl bg-green-700 text-white p-6 mb-6 shadow-lg">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs text-green-300 font-semibold uppercase tracking-widest">Managing Student</p>
                    <p class="text-2xl font-extrabold mt-1"><?= h($name) ?></p>
                    <p class="text-green-200 text-sm mt-0.5">
                        <?= h((string) $student['Department']) ?> · <?= h(trim((string) $student['grade_name'])) ?><?= $student['section_name'] ? ' · '.h((string) $student['section_name']) : '' ?>
                    </p>
                    <p class="text-green-300 font-mono text-xs mt-1">
                        <?= h((string) ($student['lrn'] ?: $student['student_id'])) ?> · <?= h((string) $student['student_type']) ?> · <?= h((string) ($student['class_name'] ?? '—')) ?>
                    </p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-bold bg-white/15 border border-white/20"><?= h((string) $student['Status']) ?></span>
            </div>
        </div>

        <div class="grid lg:grid-cols-[1fr_380px] gap-6">

            <!-- Academic / enrollment edit -->
            <form method="POST" action="student_manage.php" class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_enrollment">
                <input type="hidden" name="enrollment_id" value="<?= $eid ?>">
                <h2 class="font-extrabold text-lg mb-4">Academic &amp; Enrollment Record</h2>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Department</label>
                        <select name="department" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= h($d) ?>" <?= $student['Department']===$d?'selected':'' ?>><?= h($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Grade Level</label>
                        <select name="gradelevel_id" id="gradeSel" onchange="filterSections()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                            <?php foreach ($grades as $g): ?>
                            <option value="<?= (int) $g['Gradelevel_id'] ?>" <?= (int) $student['Department_gradelevel']===(int) $g['Gradelevel_id']?'selected':'' ?>><?= h(trim((string) $g['Gradelevel'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Section</label>
                        <select name="section_id" id="sectionSel" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                            <option value="">— none —</option>
                            <?php foreach ($sections as $s): ?>
                            <option value="<?= (int) $s['Section_id'] ?>" data-grade="<?= (int) $s['Gradelevel_id'] ?>" <?= $secId===(string) $s['Section_id']?'selected':'' ?>><?= h((string) $s['Section_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Status</label>
                        <select name="status" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                            <?php $found=false; foreach ($statuses as $st): $sel=$student['Status']===$st; $found=$found||$sel; ?>
                            <option value="<?= h($st) ?>" <?= $sel?'selected':'' ?>><?= h($st) ?></option>
                            <?php endforeach; if(!$found && $student['Status']!==''): ?>
                            <option value="<?= h((string) $student['Status']) ?>" selected><?= h((string) $student['Status']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">House</label>
                        <select name="house_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                            <option value="0">— unassigned —</option>
                            <?php foreach ($houses as $hh): ?>
                            <option value="<?= (int) $hh['id'] ?>" <?= (int) $student['house_id']===(int) $hh['id']?'selected':'' ?>><?= h((string) $hh['housename']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php $ovr = (string) ($student['type_override'] ?? ''); $det = (string) ($student['type_detected'] ?? 'Old'); ?>
                <div class="mt-4 rounded-2xl border-2 border-green-200 bg-green-50 p-4">
                    <label class="block text-xs font-bold text-green-700 uppercase tracking-wider mb-1">Student Type (Old / New — drives <span class="font-mono">payment_breakdown.type</span>)</label>
                    <select name="student_type" class="w-full rounded-xl border border-green-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                        <option value="" <?= $ovr===''?'selected':'' ?>>Auto-detect (currently: <?= h($det) ?>)</option>
                        <option value="New" <?= $ovr==='New'?'selected':'' ?>>New student</option>
                        <option value="Old" <?= $ovr==='Old'?'selected':'' ?>>Old / Continuing student</option>
                    </select>
                    <p class="text-[11px] text-green-700 mt-1.5">
                        Effective: <strong><?= h((string) $student['student_type']) ?></strong>.
                        Old vs New picks the matching <span class="font-mono">payment_breakdown</span> rate row. After changing, rebuild the assessment →
                    </p>
                </div>

                <div class="mt-4 rounded-2xl border-2 border-amber-200 bg-amber-50 p-4">
                    <label class="block text-xs font-bold text-amber-700 uppercase tracking-wider mb-1">Classification (drives fees &amp; discounts)</label>
                    <select name="classification_id" class="w-full rounded-xl border border-amber-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400">
                        <?php foreach ($classifications as $cl): ?>
                        <option value="<?= (int) $cl['classification_id'] ?>" <?= (int) $student['Student_classification']===(int) $cl['classification_id']?'selected':'' ?>>
                            <?= h((string) $cl['name']) ?> — <?= h((string) $cl['descr']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-amber-700 mt-1.5">e.g. 1ST CHILD, 2ND/3RD/4TH CHILD (sibling), ATHLETE SPECIAL PROVISION, scholarships. After changing, rebuild the assessment →</p>
                </div>

                <?php
                $waiveOn     = (int) ($student['waive_school_improvement'] ?? 0) === 1;
                $waiveMiscOn = (int) ($student['waive_miscellaneous'] ?? 0) === 1;
                ?>
                <div class="mt-4 rounded-2xl border-2 border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-xs font-bold text-emerald-800 uppercase tracking-wider mb-2">Fee Exemptions</p>
                    <label class="flex items-start gap-3 cursor-pointer py-1.5">
                        <input type="checkbox" name="waive_misc" value="1" <?= $waiveMiscOn ? 'checked' : '' ?>
                               class="mt-0.5 h-5 w-5 rounded accent-emerald-600">
                        <span>
                            <span class="block text-sm font-semibold text-slate-800">Waive Miscellaneous Fee</span>
                            <span class="block text-[11px] text-emerald-700">Student is exempted from the monthly Miscellaneous Fee.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 cursor-pointer py-1.5 border-t border-emerald-200/70">
                        <input type="checkbox" name="waive_improvement" value="1" <?= $waiveOn ? 'checked' : '' ?>
                               class="mt-0.5 h-5 w-5 rounded accent-emerald-600">
                        <span>
                            <span class="block text-sm font-semibold text-slate-800">Waive School Improvement Fee</span>
                            <span class="block text-[11px] text-emerald-700">Student is exempted from the monthly School Improvement Fee. (Also auto-waived for 2nd/3rd/4th-child siblings.)</span>
                        </span>
                    </label>
                    <p class="text-[11px] text-emerald-700/80 mt-1.5">After changing, rebuild the assessment to apply →</p>
                </div>

                <div class="flex gap-3 pt-5">
                    <button type="submit" class="rounded-xl bg-green-700 hover:bg-green-800 text-white px-6 py-2.5 text-sm font-bold">Save Record</button>
                    <a href="student_manage.php" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel</a>
                </div>
            </form>

            <!-- Financial / fees panel -->
            <div class="space-y-5">
                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-3">Fees for this Classification</h2>
                    <?php if (!$soaReady): ?>
                    <p class="text-sm text-slate-400">SOA tables not installed.</p>
                    <?php elseif ($feeComp === []): ?>
                    <p class="text-sm text-slate-400">No fee profile (grade tier not matched).</p>
                    <?php else: ?>
                    <table class="w-full text-sm">
                        <?php foreach ($feeComp as $lbl => $amt): ?>
                        <tr class="border-b border-slate-50"><td class="py-1.5 text-slate-600"><?= h($lbl) ?></td><td class="py-1.5 text-right font-semibold">₱<?= number_format($amt, 2) ?></td></tr>
                        <?php endforeach; ?>
                        <tr><td class="py-2 font-bold text-green-700">Monthly Total</td><td class="py-2 text-right font-extrabold text-green-700">₱<?= number_format($monthlyPreview, 2) ?></td></tr>
                    </table>
                    <p class="text-[11px] text-slate-400 mt-2">Computed live from the grade-tier schedule with sibling/scholarship rules applied.</p>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-3xl border <?= $stale ? 'border-amber-300' : 'border-slate-100' ?> shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-3">Assessment &amp; SOA</h2>
                    <?php if ($assessment): ?>
                    <div class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">Stored monthly</span><span class="font-semibold">₱<?= number_format(((float) $assessment['installment_base'])/max(1,(int)$assessment['installment_count']), 2) ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Net assessed</span><span class="font-semibold">₱<?= number_format((float) $assessment['net_assessed'], 2) ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Balance</span><span class="font-semibold">₱<?= number_format((float) $assessment['balance'], 2) ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-500">SOAs · Payments</span><span class="font-semibold"><?= $soaCount ?> · <?= $payCount ?></span></div>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-slate-400">No assessment yet for S.Y. <?= h($syLabel) ?>.</p>
                    <?php endif; ?>

                    <?php if ($stale): ?>
                    <div class="mt-3 rounded-xl bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800 font-medium">
                        ⚠ Fees are out of date with the current classification. Rebuild to apply.
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="student_manage.php" class="mt-4"
                          onsubmit="return confirm('Rebuild this student\'s assessment & SOAs from the current classification? (Existing SOAs for this student are replaced.)');">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="rebuild_assessment">
                        <input type="hidden" name="enrollment_id" value="<?= $eid ?>">
                        <button type="submit" <?= $installmentPayCount > 0 ? 'disabled' : '' ?>
                                class="w-full rounded-xl bg-amber-600 hover:bg-amber-700 disabled:opacity-40 disabled:cursor-not-allowed text-white px-4 py-2.5 text-sm font-bold">
                            ↻ Rebuild Assessment &amp; Fees
                        </button>
                        <?php if ($installmentPayCount > 0): ?>
                        <p class="text-[11px] text-rose-500 text-center mt-1.5">Has monthly installment payments — void those first to rebuild.</p>
                        <?php elseif ($payCount > 0): ?>
                        <p class="text-[11px] text-slate-400 text-center mt-1.5">Enrollment payment on file will be kept; fees recompute and balance updates.</p>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<script>
function filterSections() {
    const g = document.getElementById('gradeSel').value;
    document.querySelectorAll('#sectionSel option[data-grade]').forEach(function (o) {
        o.hidden = (o.dataset.grade !== g);
    });
    const sel = document.getElementById('sectionSel');
    if (sel.selectedOptions.length && sel.selectedOptions[0].hidden) { sel.value = ''; }
}
filterSections();
</script>
</body>
</html>
