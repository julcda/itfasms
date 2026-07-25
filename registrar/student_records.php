<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/registrar_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_registrar_user($user) && !is_super_admin($user)) {
    flash_set('error', 'Only the Registrar or Super Admin can access Student Record Management.');
    redirect_to(app_url('dashboard/index.php'));
}

$sy                    = soa_active_school_year($connection);
$syId                  = (int) $sy['id'];
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;

// ── POST: drop student ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'drop') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch.');
        redirect_to('student_records.php');
    }
    $eid     = to_int($_POST['enrollment_id'] ?? 0);
    $confirm = trim((string) ($_POST['confirm_surname'] ?? ''));
    $reason  = trim((string) ($_POST['reason'] ?? ''));
    $delProf = isset($_POST['delete_profile']);

    try {
        $stu = registrar_resolve_student($connection, $eid);
        if (!$stu) {
            throw new RuntimeException('Student not found.');
        }
        if ($reason === '') {
            throw new RuntimeException('A reason is required to drop a student.');
        }
        if (strcasecmp($confirm, trim((string) $stu['surname'])) !== 0) {
            throw new RuntimeException('Confirmation failed: the surname you typed does not match. No records were deleted.');
        }
        $res = registrar_drop_student($connection, $eid, $user, ['delete_profile' => $delProf, 'reason' => $reason]);
        $tot = array_sum(array_map('intval', $res['deleted']));
        flash_set('success', 'Student "' . $stu['full_name'] . '" was dropped from S.Y. ' . $syLabel
            . '. Removed ' . $tot . ' record(s) across ' . count(array_filter($res['deleted'])) . ' areas (enrollment, SOA/ledger, payments, masterlist).');
        redirect_to('student_records.php');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('student_records.php?eid=' . $eid);
    }
}

// ── Lookup ────────────────────────────────────────────────────────────────────
$eid     = to_int($_GET['eid'] ?? 0);
$search  = trim((string) ($_GET['q'] ?? ''));
$results = [];
$preview = null;

if ($eid > 0) {
    $preview = registrar_drop_preview($connection, $eid);
    if (!($preview['ok'] ?? false)) {
        $preview = null;
    }
} elseif ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $connection->prepare(
        "SELECT en.id, en.student_id, en.Status,
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

$flash = flash_get();
$csrf  = csrf_token();

$labels = [
    'enrollment'             => 'Enrollment (masterlist entry)',
    'student_assessment'     => 'SOA assessment(s)',
    'soa_master'             => 'Generated SOA document(s)',
    'payment_transaction'    => 'Posted payment(s)',
    'receipt_master'         => 'Official Receipt(s)',
    'promissory_notes'       => 'Promissory note(s)',
    'student_portal_account' => 'Student portal account',
    'student_grade'          => 'Grade record(s)',
    'backaccount_payments'   => 'Enrollment payment record(s)',
    'studentinfo'            => 'Student masterlist row(s)',
    'student_classes'        => 'Class masterlist entries',
    'class_tracking'         => 'Classification tracking row(s)',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Record Management | ITFA Registrar</title>
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
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Student Record Management</h1>
            <p class="text-slate-500 mt-2">Drop a student who is no longer part of the active school year. This permanently removes the student and <strong>all linked records</strong> — SOA, payments, and the student/class masterlist.</p>
            <p class="text-xs text-green-700 mt-2">Active S.Y.: <?= h($syLabel) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Search -->
        <form method="GET" action="student_records.php" class="mb-6 flex flex-wrap gap-3">
            <div class="relative flex-1 min-w-[260px]">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-green-600">🔍</span>
                <input type="text" name="q" value="<?= h($search) ?>" autofocus placeholder="Search by Student ID, LRN, or Name…"
                       class="w-full rounded-2xl border-2 border-slate-200 pl-11 pr-4 py-3 text-sm font-medium focus:outline-none focus:border-green-500">
            </div>
            <button class="rounded-2xl bg-green-700 hover:bg-green-800 text-white px-6 py-3 text-sm font-bold">Search</button>
            <?php if ($eid > 0): ?><a href="student_records.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">New search</a><?php endif; ?>
        </form>

        <?php if ($eid <= 0 && $search !== ''): ?>
            <div class="bg-white rounded-3xl border border-slate-100 shadow-panel overflow-hidden">
                <?php if (!$results): ?>
                <div class="py-14 text-center text-slate-400 font-semibold">No enrolled students matched “<?= h($search) ?>”.</div>
                <?php else: ?>
                <table class="min-w-full text-sm">
                    <thead><tr class="bg-slate-50 border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase tracking-wider">
                        <th class="px-5 py-3 text-left">Student</th><th class="px-5 py-3 text-left">LRN / ID</th><th class="px-5 py-3 text-left">Grade / Section</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-center">Manage</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($results as $r): ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-5 py-3 font-semibold"><?= h(strtoupper(trim((string) ($r['full_name'] ?: 'ID '.$r['student_id'])))) ?></td>
                            <td class="px-5 py-3 text-xs font-mono text-slate-500"><?= h((string) ($r['lrn'] ?: $r['student_id'])) ?></td>
                            <td class="px-5 py-3 text-xs text-slate-600"><?= h(trim((string) $r['grade_name'])) ?><?= $r['section_name'] ? ' · '.h((string) $r['section_name']) : '' ?></td>
                            <td class="px-5 py-3 text-xs"><?= h((string) $r['Status']) ?></td>
                            <td class="px-5 py-3 text-center"><a href="student_records.php?eid=<?= (int) $r['id'] ?>" class="inline-block rounded-lg bg-green-700 text-white text-xs font-semibold px-4 py-1.5 hover:bg-green-800">Open →</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        <?php elseif ($preview): ?>
        <?php $stu = $preview['student']; $counts = $preview['counts']; $hasMoney = $preview['posted_amount'] > 0 || $preview['backacc_amount'] > 0; ?>
        <div class="grid lg:grid-cols-[1fr_420px] gap-6">

            <!-- Record summary -->
            <section class="space-y-5">
                <div class="rounded-3xl bg-green-700 text-white p-6 shadow-lg">
                    <p class="text-xs text-green-300 font-semibold uppercase tracking-widest">Student Record</p>
                    <p class="text-2xl font-extrabold mt-1"><?= h(strtoupper(trim((string) $stu['full_name']))) ?></p>
                    <p class="text-green-200 text-sm mt-0.5"><?= h((string) $stu['Department']) ?> · <?= h(trim((string) $stu['grade_name'])) ?><?= $stu['section_name'] ? ' · '.h((string) $stu['section_name']) : '' ?></p>
                    <p class="text-green-300 font-mono text-xs mt-1">LRN <?= h((string) ($stu['lrn'] ?: $stu['student_id'])) ?> · <?= h((string) $stu['profile_type']) ?> · S.Y. <?= h((string) $stu['school_year']) ?> · <?= h((string) $stu['Status']) ?></p>
                </div>

                <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-1">Records that will be permanently deleted</h2>
                    <p class="text-xs text-slate-400 mb-4">Everything linked to this student's active-S.Y. enrollment.</p>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <?php foreach ($labels as $key => $label): $n = (int) ($counts[$key] ?? 0); ?>
                        <div class="flex items-center justify-between rounded-xl border <?= $n>0 ? 'border-slate-200' : 'border-slate-100 opacity-50' ?> px-3 py-2 text-sm">
                            <span class="text-slate-600"><?= h($label) ?></span>
                            <span class="font-bold <?= $n>0 ? 'text-slate-800' : 'text-slate-300' ?>"><?= number_format($n) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($hasMoney): ?>
                    <div class="mt-4 rounded-xl bg-rose-50 border border-rose-300 px-4 py-3 text-sm text-rose-800">
                        ⚠ <strong>Financial records exist.</strong> This student has ₱<?= number_format($preview['posted_amount'] + $preview['backacc_amount'], 2) ?> in recorded payments that will be <strong>permanently erased</strong>. Consider whether this student should be <em>Dropped</em> via status instead.
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Danger zone -->
            <section class="bg-white rounded-3xl border-2 border-rose-200 shadow-panel p-6 h-fit">
                <h2 class="font-extrabold text-lg text-rose-700 mb-1">⚠ Drop Student</h2>
                <p class="text-xs text-slate-500 mb-4">This <strong>cannot be undone</strong>. A snapshot is saved to the audit log before deletion.</p>

                <form method="POST" action="student_records.php" class="space-y-4"
                      onsubmit="return confirm('FINAL CONFIRMATION: permanently delete this student and ALL their records? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="drop">
                    <input type="hidden" name="enrollment_id" value="<?= (int) $stu['enrollment_id'] ?>">

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Reason <span class="text-rose-500">*</span></label>
                        <textarea name="reason" rows="2" required placeholder="e.g. transferred out / no longer enrolled / duplicate record"
                                  class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-rose-400"></textarea>
                    </div>

                    <?php if ((int) ($preview['other_enrollments'] ?? 0) === 0): ?>
                    <label class="flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 p-3 cursor-pointer">
                        <input type="checkbox" name="delete_profile" value="1" class="mt-0.5 h-4 w-4 rounded accent-rose-600">
                        <span class="text-xs text-slate-600">Also delete the student's <strong>profile</strong> (<?= h((string) $stu['profile_type']) ?> record). Only do this if the student has no history in any other school year.</span>
                    </label>
                    <?php else: ?>
                    <p class="text-[11px] text-slate-400">Profile is kept — this student has enrollment(s) in other school years.</p>
                    <?php endif; ?>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Type the surname <span class="font-mono text-rose-600"><?= h(strtoupper((string) $stu['surname'])) ?></span> to confirm</label>
                        <input type="text" name="confirm_surname" required autocomplete="off" placeholder="Surname"
                               class="w-full rounded-xl border-2 border-rose-200 px-3 py-2.5 text-sm font-bold focus:ring-2 focus:ring-rose-400">
                    </div>

                    <button class="w-full rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold py-3">
                        Drop Student &amp; Delete All Records
                    </button>
                    <a href="student_records.php" class="block text-center text-sm text-slate-500 hover:text-slate-700">Cancel</a>
                </form>
            </section>
        </div>

        <?php else: ?>
        <div class="bg-white rounded-3xl border border-slate-100 shadow-panel p-12 text-center text-slate-400">
            <p class="font-semibold text-slate-500">Search for a student to view and manage their records.</p>
        </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
