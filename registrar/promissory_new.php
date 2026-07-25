<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/promissory_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_registrar_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only the Registrar or Super Admin can issue Promissory Notes.');
    redirect_to(app_url('dashboard/index.php'));
}

$sy                    = soa_active_school_year($connection);
$syId                  = (int) $sy['id'];
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;
$ready                 = pn_table_ready($connection);

// ── POST: create a promissory note ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to('promissory_new.php');
    }
    try {
        $eid = to_int($_POST['enrollment_id'] ?? 0);
        if ($eid <= 0) {
            throw new RuntimeException('No student selected.');
        }
        $res = pn_create($connection, [
            'enrollment_id'         => $eid,
            'student_id'            => (string) ($_POST['student_id'] ?? ''),
            'school_year_id'        => $syId,
            'soa_id'                => to_int($_POST['soa_id'] ?? 0) ?: null,
            'outstanding_balance'   => (float) ($_POST['outstanding_balance'] ?? 0),
            'promissory_amount'     => (float) ($_POST['promissory_amount'] ?? 0),
            'date_issued'           => (string) ($_POST['date_issued'] ?? date('Y-m-d')),
            'promised_payment_date' => (string) ($_POST['promised_payment_date'] ?? ''),
            'reason'                => (string) ($_POST['reason'] ?? ''),
        ], $user);
        flash_set('success', 'Promissory note ' . $res['promissory_no'] . ' issued.');
        redirect_to(app_url('registrar/promissory_print.php?id=' . (int) $res['promissory_id']));
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('promissory_new.php?eid=' . to_int($_POST['enrollment_id'] ?? 0));
    }
}

// ── Lookup ────────────────────────────────────────────────────────────────────
$eid     = to_int($_GET['eid'] ?? 0);
$search  = trim((string) ($_GET['q'] ?? ''));
$student = null;
$results = [];
$assessment = null;
$prevNotes  = [];
$latestSoa  = 0;
$currentSoa = null;   // the student's current/latest generated SOA document

if ($eid > 0) {
    $stmt = $connection->prepare(
        "SELECT en.id, en.student_id, en.school_year, en.Department, en.Department_gradelevel, en.Department_section,
                COALESCE(CONCAT(p.surname,', ',p.firstname,' ',IFNULL(p.middlename,'')),
                         CONCAT(osp.surname,', ',osp.firstname,' ',IFNULL(osp.middlename,''))) AS full_name,
                COALESCE(p.lrn, osp.lrn) AS lrn,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name
         FROM enrollment en
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE en.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $student = stmt_fetch_assoc($stmt);

    if ($student) {
        // Current assessment / balance.
        $aStmt = $connection->prepare('SELECT id, net_assessed, total_paid, balance FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1');
        $aStmt->bind_param('ii', $eid, $syId);
        $aStmt->execute();
        $assessment = stmt_fetch_assoc($aStmt);
        // The student's CURRENT SOA — the promissory note is raised against THIS
        // document's amount due (the months billed), not the whole-year balance.
        if ($assessment) {
            $r = $connection->query(
                'SELECT id, soa_number, total_due, generated_at, selected_terms_json
                 FROM soa_master WHERE assessment_id = ' . (int) $assessment['id'] . ' ORDER BY id DESC LIMIT 1'
            );
            if ($r && ($x = $r->fetch_assoc())) {
                $currentSoa = $x;
                $latestSoa  = (int) $x['id'];
                // Month labels covered by this SOA (for display).
                $d = $connection->query('SELECT GROUP_CONCAT(month_label ORDER BY term_no SEPARATOR ", ") m FROM soa_details WHERE soa_id = ' . $latestSoa);
                $currentSoa['months'] = $d && ($dr = $d->fetch_assoc()) ? (string) ($dr['m'] ?? '') : '';
            }
        }
        // Previous notes for this student.
        if ($ready) {
            $pStmt = $connection->prepare(
                'SELECT promissory_id, promissory_no, promissory_amount, date_issued, promised_payment_date, status, cashier_verified
                 FROM promissory_notes WHERE enrollment_id = ? ORDER BY promissory_id DESC'
            );
            $pStmt->bind_param('i', $eid);
            $pStmt->execute();
            $prevNotes = stmt_fetch_all_assoc($pStmt);
        }
    }
} elseif ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $connection->prepare(
        "SELECT en.id, en.student_id,
                COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname)) AS full_name,
                COALESCE(p.lrn, osp.lrn) AS lrn,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, en.Department_section) AS section_name, en.Department
         FROM enrollment en
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
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

$fullBalance = $assessment ? (float) $assessment['balance'] : 0.0;   // whole-year balance (context only)
$soaDue      = $currentSoa ? (float) $currentSoa['total_due'] : 0.0;  // current SOA amount — the PN basis
$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Promissory Note | ITFA Registrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Registrar</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">New Promissory Note</h1>
            <p class="text-slate-500 mt-2">Search the student, review their SOA, then issue the promissory arrangement.</p>
            <p class="text-xs text-green-700 mt-2"><a href="<?= h(app_url('registrar/promissory.php')) ?>" class="hover:underline">← All promissory notes</a></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Search -->
        <form method="GET" action="promissory_new.php" class="mb-6 flex flex-wrap gap-3">
            <div class="relative flex-1 min-w-[260px]">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-600">🔍</span>
                <input type="text" name="q" value="<?= h($search) ?>" autofocus placeholder="Search by Student ID, LRN, or Name…"
                       class="w-full rounded-2xl border-2 border-slate-200 pl-11 pr-4 py-3 text-sm font-medium focus:outline-none focus:border-green-500">
            </div>
            <button class="rounded-2xl bg-green-700 hover:bg-green-800 text-white px-6 py-3 text-sm font-bold">Search</button>
        </form>

        <?php if ($eid <= 0 && $search !== ''): ?>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                <?php if (!$results): ?>
                <div class="py-14 text-center text-slate-400 font-semibold">No enrolled students matched “<?= h($search) ?>”.</div>
                <?php else: ?>
                <table class="min-w-full text-sm">
                    <thead><tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase tracking-wider">
                        <th class="px-5 py-3 text-left">Student</th><th class="px-5 py-3 text-left">LRN / ID</th><th class="px-5 py-3 text-left">Grade / Section</th><th class="px-5 py-3 text-center">Select</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($results as $r): ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-5 py-3 font-semibold"><?= h(strtoupper(trim((string) ($r['full_name'] ?: 'ID '.$r['student_id'])))) ?></td>
                            <td class="px-5 py-3 text-xs font-mono text-slate-500"><?= h((string) ($r['lrn'] ?: $r['student_id'])) ?></td>
                            <td class="px-5 py-3 text-xs text-slate-600"><?= h(trim((string) $r['grade_name'])) ?><?= $r['section_name'] ? ' · '.h((string) $r['section_name']) : '' ?></td>
                            <td class="px-5 py-3 text-center"><a href="promissory_new.php?eid=<?= (int) $r['id'] ?>" class="inline-block rounded-lg bg-green-700 text-white text-xs font-semibold px-4 py-1.5 hover:bg-green-800">Select →</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        <?php elseif ($student): ?>
        <div class="grid lg:grid-cols-[1fr_400px] gap-6">
            <!-- SOA snapshot + previous notes -->
            <section class="space-y-6">
                <div class="rounded-3xl bg-green-700 text-white p-6 shadow-lg">
                    <p class="text-xs text-green-300 font-semibold uppercase tracking-widest">Student</p>
                    <p class="text-2xl font-extrabold mt-1"><?= h(strtoupper(trim((string) $student['full_name']))) ?></p>
                    <p class="text-green-200 text-sm mt-0.5"><?= h((string) $student['Department']) ?> · <?= h(trim((string) $student['grade_name'])) ?><?= $student['section_name'] ? ' · '.h((string) $student['section_name']) : '' ?></p>
                    <p class="text-green-300 font-mono text-xs mt-1">LRN <?= h((string) ($student['lrn'] ?: $student['student_id'])) ?> · S.Y. <?= h((string) $student['school_year']) ?></p>
                </div>

                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-3">Statement of Account</h2>
                    <?php if (!$assessment): ?>
                    <p class="text-sm text-amber-600">No assessment on file yet for this student.</p>
                    <?php else: ?>
                        <?php if ($currentSoa): ?>
                        <div class="rounded-2xl border-2 border-green-200 bg-green-50 p-4 mb-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-[11px] uppercase text-green-500 font-semibold tracking-wide">Current SOA</p>
                                    <p class="font-mono font-bold text-green-800"><?= h((string) $currentSoa['soa_number']) ?></p>
                                    <?php if (!empty($currentSoa['months'])): ?><p class="text-xs text-slate-500 mt-0.5">For: <?= h((string) $currentSoa['months']) ?></p><?php endif; ?>
                                </div>
                                <div class="text-right">
                                    <p class="text-[11px] uppercase text-green-500 font-semibold tracking-wide">Amount Due</p>
                                    <p class="text-2xl font-extrabold text-green-800">₱<?= number_format($soaDue, 2) ?></p>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 mb-3 text-sm text-amber-800">
                            No SOA has been generated for this student yet. The Cashier must generate the student's SOA before a promissory note can be raised against it.
                        </div>
                        <?php endif; ?>
                    <div class="grid grid-cols-3 gap-px bg-slate-100 rounded-2xl overflow-hidden text-center">
                        <div class="bg-white p-3"><p class="text-[11px] uppercase text-slate-400 font-semibold">Yr Assessed</p><p class="font-bold text-sm mt-1">₱<?= number_format((float) $assessment['net_assessed'], 2) ?></p></div>
                        <div class="bg-white p-3"><p class="text-[11px] uppercase text-slate-400 font-semibold">Yr Paid</p><p class="font-bold text-sm mt-1 text-emerald-600">₱<?= number_format((float) $assessment['total_paid'], 2) ?></p></div>
                        <div class="bg-white p-3"><p class="text-[11px] uppercase text-slate-400 font-semibold">Yr Balance</p><p class="font-bold text-sm mt-1 text-rose-600">₱<?= number_format(max(0,$fullBalance), 2) ?></p></div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-2">The promissory note covers the <strong>current SOA amount due</strong>, not the whole-year balance.</p>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-3">Previous Promissory Notes</h2>
                    <?php if (!$prevNotes): ?>
                    <p class="text-sm text-slate-400">None on record.</p>
                    <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($prevNotes as $pn): ?>
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-2.5 text-sm">
                            <div>
                                <span class="font-mono font-semibold"><?= h((string) $pn['promissory_no']) ?></span>
                                <span class="text-slate-400"> · ₱<?= number_format((float) $pn['promissory_amount'], 2) ?> · promised <?= h(date('M j, Y', strtotime((string) $pn['promised_payment_date']))) ?></span>
                            </div>
                            <?= pn_status_badge((string) $pn['status']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Generate form -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6 h-fit">
                <h2 class="font-extrabold text-lg mb-4">Issue Promissory Note</h2>
                <?php if (!$assessment): ?>
                <p class="text-sm text-amber-600">An assessment must exist before issuing a note. Ask the Cashier to generate the SOA first.</p>
                <?php elseif (!$currentSoa): ?>
                <p class="text-sm text-amber-600">No SOA has been generated yet. Ask the Cashier to generate the student's SOA first — the promissory note is raised against that SOA.</p>
                <?php else: ?>
                <form method="POST" action="promissory_new.php" class="space-y-4" onsubmit="return confirm('Issue this promissory note against the current SOA?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="enrollment_id" value="<?= (int) $student['id'] ?>">
                    <input type="hidden" name="student_id" value="<?= h((string) $student['student_id']) ?>">
                    <input type="hidden" name="soa_id" value="<?= $latestSoa ?>">
                    <input type="hidden" name="outstanding_balance" value="<?= number_format(max(0,$soaDue),2,'.','') ?>">

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Current SOA Amount Due
                            <span class="font-mono text-xs text-green-600">(<?= h((string) $currentSoa['soa_number']) ?>)</span>
                        </label>
                        <div class="rounded-xl bg-slate-50 border border-slate-200 px-4 py-2.5 text-sm font-bold">₱<?= number_format(max(0,$soaDue), 2) ?></div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Amount Covered by Note <span class="text-rose-500">*</span></label>
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400 text-lg">₱</span>
                            <input type="number" step="0.01" min="0" max="<?= number_format(max(0,$soaDue),2,'.','') ?>" name="promissory_amount" required
                                   value="<?= number_format(max(0,$soaDue),2,'.','') ?>"
                                   class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-bold focus:ring-2 focus:ring-green-400">
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Cannot exceed the current SOA amount due.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Date Issued <span class="text-rose-500">*</span></label>
                        <input type="date" name="date_issued" value="<?= h(date('Y-m-d')) ?>" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Promised Payment Date <span class="text-rose-500">*</span></label>
                        <input type="date" name="promised_payment_date" value="<?= h(date('Y-m-d', strtotime('+14 days'))) ?>" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Reason for Request</label>
                        <textarea name="reason" rows="3" placeholder="e.g. awaiting salary, financial hardship…" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-400"></textarea>
                    </div>
                    <button class="w-full rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-3">Generate &amp; Print</button>
                </form>
                <?php endif; ?>
            </section>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-12 text-center text-slate-400">
            <p class="font-semibold text-slate-500">Search for a student to issue a promissory note.</p>
        </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
