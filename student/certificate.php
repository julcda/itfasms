<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';
require_once __DIR__ . '/../includes/certificate_render.php';

$account = require_student_login();
$db      = db();
$sess    = current_student();

$studentInfoId = resolve_studentinfo_id_for_enrollment($db, (int) $sess['enrollment_id'], false);
$certs         = cert_for_student($db, $studentInfoId);

// Printing one: it must belong to THIS student and be published. cert_for_student
// returns published-only, so membership in that list IS the authorization.
$printId = to_int($_GET['print'] ?? 0);
if ($printId > 0) {
    foreach ($certs as $c) {
        if ((int) $c['id'] === $printId) {
            cert_render_page($db, $c, app_url('student/certificate.php'));   // exits
        }
    }
    flash_set('error', 'That certificate is not available.');
    redirect_to(app_url('student/certificate.php'));
}

$profile = student_profile($db, (int) $sess['enrollment_id']);
$sy      = student_active_sy($db);
$flash   = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Certificates | Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Student Portal</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">My Certificates</h1>
            <p class="text-slate-500 mt-1 text-sm">Awards published by your Department Head. Each carries a QR code so it can be verified.</p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$certs): ?>
        <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-10 text-center">
            <p class="text-5xl mb-3">🎓</p>
            <h2 class="font-extrabold text-lg text-slate-700">No certificates yet</h2>
            <p class="text-sm text-slate-500 mt-2 max-w-md mx-auto">
                Certificates of Recognition appear here once your class adviser awards one
                and your Department Head publishes it.
            </p>
        </div>
        <?php else: ?>
        <div class="grid md:grid-cols-2 gap-5">
            <?php foreach ($certs as $c):
                $lvl  = cert_display_title($c);
                $grad = match ((string) ($c['type'] ?? '')) {
                    'Perfect Attendance' => 'from-emerald-500 to-teal-600',
                    'Special Award'      => 'from-violet-500 to-purple-600',
                    default              => 'from-amber-500 to-orange-600',
                };
            ?>
            <article class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
                <div class="bg-gradient-to-br <?= $grad ?> px-5 py-4 text-white">
                    <p class="text-[10px] uppercase tracking-[0.2em] opacity-90">Certificate of Recognition</p>
                    <p class="font-extrabold text-lg leading-tight mt-0.5"><?= h($lvl) ?></p>
                </div>
                <div class="p-5">
                    <dl class="text-sm space-y-1.5">
                        <div class="flex justify-between"><dt class="text-slate-400">Period</dt><dd class="font-semibold"><?= h((string) ($c['period_name'] ?: '—')) ?></dd></div>
                        <div class="flex justify-between"><dt class="text-slate-400">School Year</dt><dd class="font-semibold"><?= h((string) $c['school_year']) ?></dd></div>
                        <?php if ($c['general_average'] !== null): ?>
                        <div class="flex justify-between"><dt class="text-slate-400">General Average</dt><dd class="font-extrabold text-emerald-700"><?= number_format((float) $c['general_average'], 2) ?></dd></div>
                        <?php endif; ?>
                        <div class="flex justify-between"><dt class="text-slate-400">Certificate No.</dt><dd class="font-mono text-xs"><?= h((string) $c['certificate_no']) ?></dd></div>
                    </dl>
                    <a href="certificate.php?print=<?= (int) $c['id'] ?>" target="_blank"
                       class="mt-4 block text-center rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">
                        🖨 View &amp; Print Certificate
                    </a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
