<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_cashier_user($user)) {
    flash_set('error', 'Only Cashier users can access this module.');
    redirect_to(app_url('dashboard/index.php'));
}

// ── Active school year ────────────────────────────────────────────────────────
$activeSchoolYearLabel = '';
try {
    $syStmt = $connection->prepare('SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1');
    $syStmt->execute();
    $syRow = stmt_fetch_assoc($syStmt);
    if ($syRow) $activeSchoolYearLabel = (string) ($syRow['School_year'] ?? '');
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . (date('Y') + 1);
}

// ── Auto-create & populate fee_schedule table if missing ─────────────────────
try {
    $connection->query(
        "CREATE TABLE IF NOT EXISTS `fee_schedule` (
          `id`                  int(11)       NOT NULL AUTO_INCREMENT,
          `department`          varchar(50)   NOT NULL,
          `level`               varchar(100)  NOT NULL,
          `student_type`        varchar(10)   NOT NULL,
          `tuition_monthly`     decimal(10,2) DEFAULT '0.00',
          `misc_monthly`        decimal(10,2) DEFAULT '0.00',
          `improvement_monthly` decimal(10,2) DEFAULT '0.00',
          `books_monthly`       decimal(10,2) DEFAULT '0.00',
          `total_monthly`       decimal(10,2) DEFAULT '0.00',
          `enrollment_admission` decimal(10,2) DEFAULT '0.00',
          `enrollment_books`    decimal(10,2) DEFAULT '0.00',
          `activity_fee`        decimal(10,2) DEFAULT '0.00',
          `house_registration`  decimal(10,2) DEFAULT '0.00',
          `total_enrollment`    decimal(10,2) DEFAULT '0.00',
          `full_payment`        decimal(10,2) DEFAULT '0.00',
          `sort_order`          int(11)       DEFAULT '0',
          `is_esc`              tinyint(1)    DEFAULT '0',
          `is_voucher`          tinyint(1)    DEFAULT '0',
          `schyear_id`          int(11)       DEFAULT NULL,
          `status`              varchar(10)   DEFAULT 'Active',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Seed data only when table is empty
    $cntRow = $connection->query("SELECT COUNT(*) AS c FROM `fee_schedule`")->fetch_assoc();
    if ((int)($cntRow['c'] ?? 0) === 0) {
        $seeds = [
            // Kindergarten / Tahderiyah
            ['Elementary','Kindergarten / Tahderiyah','New', 600.00,75.00,50.00,0.00,725.00, 1050.00,0.00,490.00,250.00,1790.00, 7950.00,1,0,0],
            ['Elementary','Kindergarten / Tahderiyah','Old', 600.00,75.00,50.00,0.00,725.00, 1050.00,0.00,490.00,250.00,1790.00, 7950.00,1,0,0],
            // Grade 1 – Grade 3
            ['Elementary','Grade 1 – Grade 3','New', 505.00,75.00,50.00,261.75,891.75, 850.00,2617.50,490.00,250.00,4207.50, 12385.00,2,0,0],
            ['Elementary','Grade 1 – Grade 3','Old', 505.00,75.00,50.00,261.75,891.75, 650.00,2617.50,490.00,250.00,4007.50, 12185.00,2,0,0],
            // Grade 4 – Grade 6
            ['Elementary','Grade 4 – Grade 6','New', 505.00,100.00,50.00,261.75,916.75, 850.00,2617.50,490.00,250.00,4207.50, 12635.00,3,0,0],
            ['Elementary','Grade 4 – Grade 6','Old', 505.00,100.00,50.00,261.75,916.75, 650.00,2617.50,490.00,250.00,4007.50, 12435.00,3,0,0],
            // JHS Regular
            ['Junior High','Grade 7 – Grade 10 (Regular)','New', 505.00,100.00,50.00,0.00,655.00, 900.00,0.00,490.00,250.00,1640.00, 7450.00,4,0,0],
            ['Junior High','Grade 7 – Grade 10 (Regular)','Old', 505.00,100.00,50.00,0.00,655.00, 700.00,0.00,490.00,250.00,1440.00, 7250.00,4,0,0],
            // JHS ESC
            ['Junior High','Grade 7 – Grade 10 (ESC)','New', 405.00,100.00,50.00,0.00,555.00, 900.00,0.00,490.00,250.00,1640.00, 6450.00,5,1,0],
            ['Junior High','Grade 7 – Grade 10 (ESC)','Old', 405.00,100.00,50.00,0.00,555.00, 700.00,0.00,490.00,250.00,1440.00, 6250.00,5,1,0],
            // SHS Regular
            ['Senior High','Grade 11 – Grade 12 (Regular)','New', 505.00,100.00,50.00,0.00,655.00, 900.00,0.00,420.00,250.00,1570.00, 7450.00,6,0,0],
            ['Senior High','Grade 11 – Grade 12 (Regular)','Old', 505.00,100.00,50.00,0.00,655.00, 700.00,0.00,420.00,250.00,1370.00, 7250.00,6,0,0],
            // SHS Voucher
            ['Senior High','Grade 11 – Grade 12 (Voucher)','New', 0.00,0.00,0.00,0.00,0.00, 0.00,0.00,420.00,250.00,670.00, 0.00,7,0,1],
            ['Senior High','Grade 11 – Grade 12 (Voucher)','Old', 0.00,0.00,0.00,0.00,0.00, 0.00,0.00,420.00,250.00,670.00, 0.00,7,0,1],
        ];
        $ins = $connection->prepare(
            'INSERT INTO `fee_schedule`
               (`department`,`level`,`student_type`,
                `tuition_monthly`,`misc_monthly`,`improvement_monthly`,`books_monthly`,`total_monthly`,
                `enrollment_admission`,`enrollment_books`,`activity_fee`,`house_registration`,`total_enrollment`,
                `full_payment`,`sort_order`,`is_esc`,`is_voucher`,`schyear_id`,`status`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,2,\'Active\')'
        );
        foreach ($seeds as $r) {
            // sssdddddddddddiii: 3 strings, 11 decimals (tuition→full_payment), 3 ints (sort,esc,voucher)
            $ins->bind_param('sssdddddddddddiii',
                $r[0],$r[1],$r[2],
                $r[3],$r[4],$r[5],$r[6],$r[7],
                $r[8],$r[9],$r[10],$r[11],$r[12],$r[13],
                $r[14],$r[15],$r[16]
            );
            $ins->execute();
        }
    }
} catch (Throwable $e) { /* table already in sync or non-fatal */ }

// ── Fetch fee schedule ────────────────────────────────────────────────────────
$feeRows = [];
try {
    $fsStmt = $connection->prepare(
        "SELECT * FROM fee_schedule WHERE status = 'Active' ORDER BY sort_order, department, level, student_type DESC"
    );
    $fsStmt->execute();
    $feeRows = stmt_fetch_all_assoc($fsStmt);
} catch (Throwable) {}

// Group by department → level
$grouped = [];
foreach ($feeRows as $row) {
    $dept  = (string) $row['department'];
    $level = (string) $row['level'];
    $grouped[$dept][$level][] = $row;
}

$flash = flash_get();
$logoUrl = h(app_url('itfalogo.png'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fee Schedule | ITFA Cashier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    boxShadow:  { panel: '0 18px 40px -20px rgba(30,58,138,0.18)' }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        @media print {
            @page { size: A4 landscape; margin: 12mm; }
            .no-print { display: none !important; }
            body  { background: #fff !important; }
            aside { display: none !important; }
            main  { padding: 0 !important; }
            .print-break { page-break-before: always; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <!-- ── Sidebar ──────────────────────────────────────────── -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- ── Main ─────────────────────────────────────────────── -->
    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)] overflow-x-hidden">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6 no-print">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Cashier</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">School Fee Schedule</h1>
                    <p class="text-slate-500 mt-2">Official fee reference for S.Y. <?= h($activeSchoolYearLabel) ?>. Use this as a guide when processing enrollments.</p>
                    <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($activeSchoolYearLabel) ?> &nbsp;·&nbsp; <?= h((string) ($user['full_name'] ?? 'Cashier')) ?></p>
                </div>
                <button onclick="window.print()"
                        class="no-print flex items-center gap-2 bg-green-700 hover:bg-green-800 text-white px-5 py-2.5 rounded-2xl text-sm font-bold transition-colors shadow-sm flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-6 0v4H9v-4h6z"/>
                    </svg>
                    Print / Save PDF
                </button>
            </div>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium flex items-center gap-3
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (empty($feeRows)): ?>
        <!-- ── Empty state ───────────────────────────────────────────── -->
        <div class="rounded-3xl bg-white border border-amber-200 shadow-panel p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-amber-50 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-amber-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
            </div>
            <h2 class="text-lg font-extrabold text-slate-800 mb-2">No Fee Schedule Found</h2>
            <p class="text-slate-500 text-sm max-w-md mx-auto">No active fee schedule entries were found. Please contact your system administrator.</p>
        </div>

        <?php else: ?>

        <!-- ── Summary Cards ─────────────────────────────────────────── -->
        <div class="grid sm:grid-cols-3 gap-4 mb-6 no-print">
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm text-center">
                <p class="text-3xl font-extrabold text-green-700">₱490</p>
                <p class="text-xs text-slate-500 mt-1 font-medium">Activity Fees — Junior High School</p>
                <p class="text-xs text-slate-400 mt-0.5">Athletic · TSC · Publication · Madrasah</p>
            </div>
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm text-center">
                <p class="text-3xl font-extrabold text-green-700">₱420</p>
                <p class="text-xs text-slate-500 mt-1 font-medium">Activity Fees — Elementary &amp; Senior High</p>
                <p class="text-xs text-slate-400 mt-0.5">Athletic · TSC · School Publication</p>
            </div>
            <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-sm text-center">
                <p class="text-3xl font-extrabold text-green-700">₱250</p>
                <p class="text-xs text-slate-500 mt-1 font-medium">House Registration — All Levels</p>
                <p class="text-xs text-slate-400 mt-0.5">Collected once per enrollment</p>
            </div>
        </div>

        <!-- ── Fee tables by department ─────────────────────────────── -->
        <?php
        $deptColors = [
            'Elementary'  => ['bg-emerald-700', 'bg-emerald-50',   'border-emerald-200', 'text-emerald-700'],
            'Junior High' => ['bg-green-700',    'bg-green-50',     'border-green-200',    'text-green-700'],
            'Senior High' => ['bg-violet-700',  'bg-violet-50',   'border-violet-200',  'text-violet-700'],
        ];
        $first = true;
        foreach ($grouped as $dept => $levels): ?>
        <?php
            [$headerBg, $subBg, $borderColor, $textColor] = $deptColors[$dept] ?? ['bg-slate-700','bg-slate-50','border-slate-200','text-slate-700'];
        ?>

        <div class="rounded-3xl border <?= $borderColor ?> bg-white shadow-panel mb-6 overflow-hidden <?= !$first ? 'print-break' : '' ?>">
            <!-- Department Header -->
            <div class="<?= $headerBg ?> text-white px-6 py-4 flex items-center gap-3">
                <div>
                    <p class="text-xs font-semibold text-white/70 uppercase tracking-widest">Department</p>
                    <h2 class="text-xl font-extrabold"><?= h($dept) ?></h2>
                </div>
            </div>

            <?php foreach ($levels as $level => $rows): ?>
            <!-- Level sub-section -->
            <div class="border-b <?= $borderColor ?> last:border-b-0">
                <div class="<?= $subBg ?> px-6 py-3 border-b <?= $borderColor ?>">
                    <h3 class="font-bold text-sm <?= $textColor ?>"><?= h($level) ?></h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="border-b border-slate-100">
                                <th class="px-4 py-2.5 text-left text-slate-400 font-semibold uppercase tracking-wider w-24">Type</th>
                                <th class="px-4 py-2.5 text-right text-slate-400 font-semibold uppercase tracking-wider">Monthly Fee</th>
                                <th class="px-4 py-2.5 text-right text-slate-400 font-semibold uppercase tracking-wider">Admission</th>
                                <?php if ($dept === 'Elementary'): ?>
                                <th class="px-4 py-2.5 text-right text-slate-400 font-semibold uppercase tracking-wider">Books (Down)</th>
                                <?php endif; ?>
                                <th class="px-4 py-2.5 text-right text-slate-400 font-semibold uppercase tracking-wider">Activity</th>
                                <th class="px-4 py-2.5 text-right text-slate-400 font-semibold uppercase tracking-wider">House Reg.</th>
                                <th class="px-4 py-2.5 text-right text-slate-500 font-bold uppercase tracking-wider bg-green-50">Total at Enrollment</th>
                                <th class="px-4 py-2.5 text-right text-slate-400 font-semibold uppercase tracking-wider">Full Payment</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($rows as $row): ?>
                            <?php
                                $isNew    = strtolower((string)$row['student_type']) === 'new';
                                $typeLabel = $isNew ? 'NEW' : 'OLD';
                                $typeCls   = $isNew
                                    ? 'bg-emerald-100 text-emerald-700'
                                    : 'bg-green-100 text-green-700';
                            ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-bold <?= $typeCls ?>">
                                        <?= h($typeLabel) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-700">
                                    <?= $row['total_monthly'] > 0 ? '₱' . number_format((float)$row['total_monthly'], 2) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-600">
                                    <?= $row['enrollment_admission'] > 0 ? '₱' . number_format((float)$row['enrollment_admission'], 2) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <?php if ($dept === 'Elementary'): ?>
                                <td class="px-4 py-3 text-right text-slate-600">
                                    <?= $row['enrollment_books'] > 0 ? '₱' . number_format((float)$row['enrollment_books'], 2) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <?php endif; ?>
                                <td class="px-4 py-3 text-right text-slate-600">
                                    <?= $row['activity_fee'] > 0 ? '₱' . number_format((float)$row['activity_fee'], 2) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-600">
                                    <?= $row['house_registration'] > 0 ? '₱' . number_format((float)$row['house_registration'], 2) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <td class="px-4 py-3 text-right bg-green-50">
                                    <span class="font-extrabold text-green-700 text-sm">
                                        ₱<?= number_format((float)$row['total_enrollment'], 2) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <?php if ($row['full_payment'] > 0): ?>
                                        <span class="font-semibold text-emerald-700">₱<?= number_format((float)$row['full_payment'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-300">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div><!-- /level -->
            <?php endforeach; ?>
        </div><!-- /dept -->

        <?php $first = false; endforeach; ?>

        <!-- ── Activity Fees Breakdown ──────────────────────────────── -->
        <div class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden mb-6">
            <div class="bg-slate-700 text-white px-6 py-4">
                <p class="text-xs font-semibold text-white/70 uppercase tracking-widest">Reference</p>
                <h2 class="text-xl font-extrabold">Activity Fees &amp; Other Fees (Annual)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Particulars</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Amount (PHP)</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php
                        $activityItems = [
                            ['Athletic Fee',                     200.00, 'Collected by the cashier. Applies to all departments.'],
                            ['TSC Fee (The Student Council)',     120.00, 'Applies to all departments.'],
                            ['School Publication Fee',           100.00, 'Applies to all departments.'],
                            ['Madrasah Registration (Tarbiyah)',  70.00, 'Junior High School only. Excluded from Elementary and SHS.'],
                        ];
                        $activitySubtotals = [
                            ['Elementary / SHS Total', 420.00, 'Athletic + TSC + Publication (no Madrasah)'],
                            ['Junior High Total',       490.00, 'Athletic + TSC + Publication + Madrasah'],
                        ];
                        ?>
                        <?php foreach ($activityItems as [$label, $amount, $notes]): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-3 font-medium text-slate-800"><?= h($label) ?></td>
                            <td class="px-6 py-3 text-right font-semibold text-slate-700">₱<?= number_format($amount, 2) ?></td>
                            <td class="px-6 py-3 text-slate-400 text-xs"><?= h($notes) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-green-50 border-t-2 border-green-200">
                            <td class="px-6 py-3 font-bold text-green-900">Elementary &amp; JHS Total</td>
                            <td class="px-6 py-3 text-right font-extrabold text-green-700">₱490.00</td>
                            <td class="px-6 py-3 text-xs text-green-600">All four activity fee items</td>
                        </tr>
                        <tr class="bg-violet-50 border-t border-violet-200">
                            <td class="px-6 py-3 font-bold text-violet-900">Senior High School Total</td>
                            <td class="px-6 py-3 text-right font-extrabold text-violet-700">₱420.00</td>
                            <td class="px-6 py-3 text-xs text-violet-600">Excludes Madrasah Registration</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Footer note ──────────────────────────────────────────── -->
        <div class="rounded-2xl bg-amber-50 border border-amber-200 px-6 py-4 text-xs text-amber-800">
            <strong>Note:</strong>
            House Registration (₱250.00) is collected from all students on enrollment day regardless of department or classification.
            Elementary students also pay a book down-payment of ₱2,617.50 at enrollment, with the remaining book balance spread across 10 monthly installments.
            This schedule is effective for S.Y. <?= h($activeSchoolYearLabel) ?>.
        </div>

        <?php endif; ?>

    </main>
</div>
</body>
</html>
