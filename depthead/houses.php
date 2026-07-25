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

// ── School years (default to latest year that actually has enrolled students) ──
$schoolYears = [];
try {
    $syRes = $connection->query('SELECT School_year FROM schoolyear ORDER BY School_year_id DESC');
    $schoolYears = $syRes ? array_column($syRes->fetch_all(MYSQLI_ASSOC), 'School_year') : [];
} catch (Throwable) {}

$defaultSy = '';
try {
    $defRes = $connection->query(
        "SELECT school_year FROM enrollment WHERE house_id IS NOT NULL AND school_year <> '' ORDER BY school_year DESC LIMIT 1"
    );
    $defRow = $defRes ? $defRes->fetch_assoc() : null;
    $defaultSy = (string) ($defRow['school_year'] ?? '');
} catch (Throwable) {}
if ($defaultSy === '') {
    $defaultSy = $schoolYears[0] ?? (date('Y') . '-' . (date('Y') + 1));
}
$activeSchoolYearLabel = $defaultSy; // for sidebar

// ── Filters ───────────────────────────────────────────────────────────────────
$filterSy    = trim((string) ($_GET['sy'] ?? $defaultSy));
$filterHouse = to_int($_GET['house'] ?? 0);
$filterDept  = trim((string) ($_GET['dept'] ?? ''));
$filterGrade = to_int($_GET['grade'] ?? 0);

// Full activity fee due (used to classify Paid vs Partial). Falls back to "any amount = paid".
$activityFeeDue = 0.0;
try {
    $afRes = $connection->query("SELECT MAX(activity_fee) AS af FROM fee_schedule WHERE status = 'Active'");
    $activityFeeDue = (float) (($afRes ? $afRes->fetch_assoc() : null)['af'] ?? 0);
} catch (Throwable) {}

// ── Lookups ───────────────────────────────────────────────────────────────────
$houses = [];
try {
    $hRes = $connection->query('SELECT id, housename FROM house ORDER BY housename');
    $houses = $hRes ? $hRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$gradeLevels = [];
try {
    $glRes = $connection->query('SELECT Gradelevel_id, Gradelevel FROM gradelevel ORDER BY Gradelevel_id');
    $gradeLevels = $glRes ? $glRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ["e.Status = 'Officially Enrolled'", 'e.house_id IS NOT NULL', 'e.school_year = ?'];
$params = [$filterSy, $filterSy];
$types  = 'ss';

if ($filterHouse > 0) {
    $where[]  = 'e.house_id = ?';
    $params[] = $filterHouse;
    $types   .= 'i';
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

$sql = '
    SELECT
        e.id AS enrollment_id,
        e.student_id,
        e.house_id,
        e.Department,
        e.Department_gradelevel,
        h.housename,
        IFNULL(gl.Gradelevel, CONCAT("Grade ", e.Department_gradelevel)) AS gradelevel_name,
        IFNULL(sc.Section_name, e.Department_section)                    AS section_name,
        CONCAT_WS(" ", NULLIF(TRIM(p.surname),""), NULLIF(TRIM(p.firstname),""), NULLIF(TRIM(p.middlename),"")) AS new_name,
        p.lrn AS new_lrn,
        CONCAT_WS(" ", NULLIF(TRIM(o.surname),""), NULLIF(TRIM(o.firstname),""), NULLIF(TRIM(o.middlename),"")) AS old_name,
        o.lrn AS old_lrn,
        pay.paid AS paid_amount
    FROM enrollment e
    JOIN house h ON h.id = e.house_id
    /* pay = activity fee paid for this school year */
    LEFT JOIN preregistration p ON CAST(p.id AS CHAR) = e.student_id
    LEFT JOIN (
        SELECT ops.student_id, ops.surname, ops.firstname, ops.middlename, ops.lrn
        FROM old_studentprofile ops
        INNER JOIN (
            SELECT student_id, MAX(id) AS latest_id FROM old_studentprofile GROUP BY student_id
        ) latest ON latest.latest_id = ops.id
    ) o ON o.student_id = e.student_id
    LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
    LEFT JOIN section sc ON CAST(sc.Section_id AS CHAR) = e.Department_section
    LEFT JOIN (
        SELECT student_id, SUM(fee_activity) AS paid
        FROM backaccount_payment_records
        WHERE school_year = ?
        GROUP BY student_id
    ) pay ON pay.student_id = e.student_id
';
$sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY h.housename, e.Department_gradelevel, section_name, new_name, old_name';

$rows = [];
try {
    $stmt = $connection->prepare($sql);
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
} catch (Throwable $e) {
    $rows = [];
}

// Classify activity-fee payment for a student.
// 'paid' = full activity fee settled, 'partial' = some paid, 'unpaid' = nothing.
$activityStatus = static function (float $paid) use ($activityFeeDue): string {
    if ($paid <= 0) {
        return 'unpaid';
    }
    if ($activityFeeDue > 0 && $paid + 0.001 < $activityFeeDue) {
        return 'partial';
    }
    return 'paid';
};

// ── Group by house -> grade level ─────────────────────────────────────────────
$grouped       = [];
$houseTotals   = [];
$paidTotals    = [];
$partialTotals = [];
foreach ($rows as $row) {
    $house = (string) ($row['housename'] ?? 'Unassigned');
    $grade = (string) ($row['gradelevel_name'] ?? 'Unknown');
    $grouped[$house][$grade][] = $row;
    $houseTotals[$house] = ($houseTotals[$house] ?? 0) + 1;
    $st = $activityStatus((float) ($row['paid_amount'] ?? 0));
    if ($st === 'paid') {
        $paidTotals[$house] = ($paidTotals[$house] ?? 0) + 1;
    } elseif ($st === 'partial') {
        $partialTotals[$house] = ($partialTotals[$house] ?? 0) + 1;
    }
}
ksort($grouped);
$totalCount = count($rows);
$totalPaid  = array_sum($paidTotals);

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>House Members | ITFA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
            boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.22)' }
        } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        @media print {
            @page { size: A4 portrait; margin: 10mm 12mm; }
            html, body { background: #fff !important; }
            aside, .no-print { display: none !important; }
            .min-h-screen { display: block !important; }
            main { padding: 0 !important; background: none !important; }
            .print-page { page-break-inside: avoid; box-shadow: none !important; border: 1px solid #999 !important; }
            table { border-collapse: collapse; width: 100%; font-size: 8.5pt; }
            thead th { background: #166534 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody td { border: 1px solid #888; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6 no-print">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Admin Report</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">House Members</h2>
            <p class="text-slate-500 mt-2">Members of each house grouped by grade level, with <strong>activity fee</strong> payment status<?= $activityFeeDue > 0 ? ' (full fee ₱' . h(number_format($activityFeeDue, 2)) . ')' : '' ?>.</p>
        </header>

        <?php if ($flash): ?>
            <div class="mb-4 no-print rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="" class="mb-5 no-print bg-white rounded-2xl border border-slate-200 p-4 flex flex-wrap gap-3 items-end shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">School Year</label>
                <select name="sy" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= h((string) $sy) ?>" <?= $filterSy === (string) $sy ? 'selected' : '' ?>><?= h((string) $sy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">House</label>
                <select name="house" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <option value="0">All Houses</option>
                    <?php foreach ($houses as $hs): ?>
                        <option value="<?= h((string) $hs['id']) ?>" <?= $filterHouse === (int) $hs['id'] ? 'selected' : '' ?>><?= h((string) $hs['housename']) ?></option>
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
                <label class="block text-xs font-semibold text-slate-500 mb-1">Grade Level</label>
                <select name="grade" class="rounded-xl border border-slate-300 text-sm px-3 py-2 focus:ring-green-400 focus:border-green-400">
                    <option value="0">All Grades</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= h((string) $gl['Gradelevel_id']) ?>" <?= $filterGrade === (int) $gl['Gradelevel_id'] ? 'selected' : '' ?>><?= h((string) $gl['Gradelevel']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rounded-xl bg-green-600 text-white px-5 py-2 text-sm font-semibold hover:bg-green-700 transition-colors">Generate</button>
            <?php if ($rows !== []): ?>
            <button type="button" onclick="window.print()" class="ml-auto rounded-xl bg-slate-800 text-white px-5 py-2 text-sm font-semibold hover:bg-slate-900 transition-colors">Print</button>
            <?php endif; ?>
        </form>

        <?php if ($rows !== []): ?>
        <!-- Summary -->
        <div class="mb-5 no-print flex flex-wrap gap-3">
            <div class="rounded-2xl bg-white border border-green-200 px-5 py-3 shadow-sm">
                <p class="text-xs text-green-700 font-semibold uppercase tracking-wide">Total Members</p>
                <p class="text-3xl font-extrabold text-green-800 mt-1"><?= h((string) $totalCount) ?></p>
                <p class="text-xs text-slate-400 mt-0.5">S.Y. <?= h($filterSy) ?> · <?= $totalPaid ?> activity fee paid</p>
            </div>
            <?php foreach ($houseTotals as $house => $cnt):
                $hPaid    = (int) ($paidTotals[$house] ?? 0);
                $hPartial = (int) ($partialTotals[$house] ?? 0);
                $hUnpaid  = $cnt - $hPaid - $hPartial;
            ?>
            <div class="rounded-2xl bg-white border border-slate-200 px-5 py-3 shadow-sm">
                <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide"><?= h($house) ?></p>
                <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= $cnt ?></p>
                <p class="text-xs text-slate-400 mt-0.5">
                    <span class="text-emerald-600 font-semibold"><?= $hPaid ?> paid</span><?php if ($hPartial > 0): ?> · <span class="text-amber-600 font-semibold"><?= $hPartial ?> partial</span><?php endif; ?> · <span class="text-rose-500 font-semibold"><?= $hUnpaid ?> unpaid</span>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($rows === []): ?>
            <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-12 text-center text-slate-400">
                <p class="font-semibold text-slate-500">No house members found</p>
                <p class="text-sm mt-1">Try a different school year or filter. Students must be officially enrolled and assigned a house.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $house => $grades): ?>
            <section class="print-page mb-5 rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-green-50 to-slate-50 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-widest font-semibold">House · S.Y. <?= h($filterSy) ?></p>
                        <h3 class="text-lg font-extrabold text-slate-900 mt-0.5"><?= h($house) ?></h3>
                    </div>
                    <span class="rounded-full bg-green-100 text-green-800 border border-green-200 text-xs font-bold px-3 py-1">
                        <?= (int) ($houseTotals[$house] ?? 0) ?> member<?= (int) ($houseTotals[$house] ?? 0) !== 1 ? 's' : '' ?>
                    </span>
                </div>
                <?php foreach ($grades as $grade => $members): ?>
                <div class="px-6 pt-4 pb-1 text-xs font-bold uppercase tracking-widest text-green-600"><?= h($grade) ?> <span class="text-slate-400 font-medium">· <?= count($members) ?></span></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-4 py-2.5 text-center w-8">#</th>
                                <th class="px-4 py-2.5 text-left">Student Name</th>
                                <th class="px-4 py-2.5 text-left">LRN</th>
                                <th class="px-4 py-2.5 text-left">Section</th>
                                <th class="px-4 py-2.5 text-left">Department</th>
                                <th class="px-4 py-2.5 text-left">Activity Fee</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php $num = 1; foreach ($members as $row):
                                $name = trim((string) ($row['new_name'] ?? '')) ?: trim((string) ($row['old_name'] ?? ''));
                                $name = $name !== '' ? $name : ('Student ID: ' . (string) $row['student_id']);
                                $lrn  = (string) ($row['new_lrn'] ?? '') ?: (string) ($row['old_lrn'] ?? '');
                                $paid = (float) ($row['paid_amount'] ?? 0);
                                $payStatus = $activityStatus($paid);
                            ?>
                            <tr class="hover:bg-green-50/30 transition-colors">
                                <td class="px-4 py-2 text-center text-slate-400 text-xs font-semibold"><?= $num++ ?></td>
                                <td class="px-4 py-2 font-semibold"><?= h($name) ?></td>
                                <td class="px-4 py-2 font-mono text-xs text-slate-500"><?= h($lrn ?: '—') ?></td>
                                <td class="px-4 py-2 text-slate-700"><?= h((string) ($row['section_name'] ?? '—')) ?></td>
                                <td class="px-4 py-2 text-xs text-slate-500"><?= h((string) ($row['Department'] ?? '—')) ?></td>
                                <td class="px-4 py-2">
                                    <?php if ($payStatus === 'paid'): ?>
                                        <span class="rounded-full text-xs font-semibold px-2 py-0.5 bg-emerald-100 text-emerald-700">Paid · ₱<?= h(number_format($paid, 2)) ?></span>
                                    <?php elseif ($payStatus === 'partial'): ?>
                                        <span class="rounded-full text-xs font-semibold px-2 py-0.5 bg-amber-100 text-amber-700">Partial · ₱<?= h(number_format($paid, 2)) ?></span>
                                    <?php else: ?>
                                        <span class="rounded-full text-xs font-semibold px-2 py-0.5 bg-rose-100 text-rose-700">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
