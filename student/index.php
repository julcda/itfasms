<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';

$account = require_student_login();
$db      = db();
$sess    = current_student();

$profile = student_profile($db, (int) $sess['enrollment_id']);
if (!$profile) {
    student_logout();
    flash_set('error', 'Your enrollment record could not be loaded. Please contact the registrar.');
    redirect_to(app_url('student/login.php'));
}
$photoUrl = student_photo_url($profile);

// Financial snapshot (active-SY assessment).
$asmt = null;
$aStmt = $db->prepare(
    "SELECT sa.net_assessed, sa.total_paid, sa.balance, sa.status
     FROM student_assessment sa
     JOIN schoolyear sy ON sy.School_year_id = sa.school_year_id
     WHERE sa.enrollment_id = ? AND sy.Status = 1 LIMIT 1"
);
$aStmt->bind_param('i', $sess['enrollment_id']);
$aStmt->execute();
$asmt = stmt_fetch_assoc($aStmt);

$balance   = (float) ($asmt['balance'] ?? 0);
$paid      = (float) ($asmt['total_paid'] ?? 0);
$assessed  = (float) ($asmt['net_assessed'] ?? 0);
$payStatus = $assessed <= 0 ? 'No Assessment' : ($balance <= 0 ? 'Fully Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'));
$statusColor = ['Fully Paid'=>'emerald','Partially Paid'=>'amber','Unpaid'=>'rose','No Assessment'=>'slate'][$payStatus];

$flash = flash_get();
$fullName = trim((string) ($profile['firstname'] . ' ' . ($profile['middlename'] ? $profile['middlename'][0] . '. ' : '') . $profile['surname']));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium
            <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
        </div>
        <?php endif; ?>

        <!-- Hero / identity -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center gap-5">
                <?php if ($photoUrl): ?>
                <img src="<?= h($photoUrl) ?>" alt="" class="w-20 h-20 rounded-2xl object-cover border-2 border-green-200 shadow">
                <?php else: ?>
                <div class="w-20 h-20 rounded-2xl bg-green-600 text-white flex items-center justify-center text-2xl font-extrabold shadow">
                    <?= h(strtoupper(substr((string) $profile['firstname'],0,1) . substr((string) $profile['surname'],0,1))) ?>
                </div>
                <?php endif; ?>
                <div class="flex-1">
                    <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Welcome back</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-0.5"><?= h($fullName) ?></h1>
                    <p class="text-slate-500 mt-1 text-sm">LRN <?= h((string) $profile['lrn']) ?> &nbsp;·&nbsp; <?= h((string) $profile['student_type']) ?> Student</p>
                </div>
                <span class="self-start sm:self-center inline-block px-3 py-1.5 rounded-full text-xs font-bold border
                    bg-<?= $statusColor ?>-100 text-<?= $statusColor ?>-800 border-<?= $statusColor ?>-300">
                    <?= h((string) $profile['Status']) ?>
                </span>
            </div>
        </header>

        <!-- Info cards -->
        <section class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <?php
            $cards = [
                ['Grade Level', $profile['grade_name'], 'M12 14l9-5-9-5-9 5 9 5z'],
                ['Section', $profile['section_name'], 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2'],
                ['School Year', $profile['school_year'], 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
                ['Department', $profile['Department'], 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3'],
                ['Classification', $profile['classification_name'] ?: '—', 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z'],
                ['Student Status', $profile['Status'], 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ];
            foreach ($cards as [$label, $value, $path]): ?>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-panel p-5 flex items-start gap-4">
                <div class="w-10 h-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= h($path) ?>"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold"><?= h($label) ?></p>
                    <p class="font-bold text-slate-800 mt-0.5 truncate"><?= h((string) ($value ?: '—')) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </section>

        <!-- Financial summary -->
        <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-extrabold text-lg">Account Summary</h2>
                <a href="<?= h(app_url('student/soa.php')) ?>" class="text-sm font-semibold text-green-600 hover:text-green-800">View full SOA →</a>
            </div>
            <div class="grid sm:grid-cols-4 gap-px bg-slate-100 rounded-2xl overflow-hidden">
                <div class="bg-white p-5"><p class="text-xs uppercase text-slate-400 font-semibold">Total Assessment</p><p class="text-xl font-extrabold mt-1">₱<?= number_format($assessed, 2) ?></p></div>
                <div class="bg-white p-5"><p class="text-xs uppercase text-slate-400 font-semibold">Payments Made</p><p class="text-xl font-extrabold mt-1 text-emerald-600">₱<?= number_format($paid, 2) ?></p></div>
                <div class="bg-white p-5"><p class="text-xs uppercase text-slate-400 font-semibold">Remaining Balance</p><p class="text-xl font-extrabold mt-1 <?= $balance > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">₱<?= number_format(max(0,$balance), 2) ?></p></div>
                <div class="bg-white p-5"><p class="text-xs uppercase text-slate-400 font-semibold">Payment Status</p>
                    <p class="mt-2"><span class="inline-block px-3 py-1 rounded-full text-xs font-bold bg-<?= $statusColor ?>-100 text-<?= $statusColor ?>-800 border border-<?= $statusColor ?>-300"><?= h($payStatus) ?></span></p>
                </div>
            </div>
            <?php if ($balance < 0): ?>
            <p class="text-xs text-emerald-700 mt-3">You have an advance/credit of ₱<?= number_format(abs($balance), 2) ?> on your account.</p>
            <?php endif; ?>
        </section>

    </main>
</div>
</body>
</html>
