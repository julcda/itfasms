<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/certificate_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!teacher_can_manage($user)) {
    flash_set('error', 'Access denied. Department Head login required.');
    redirect_to(app_url(user_home_path($user)));
}

$sy                    = teacher_active_sy($connection);
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;
$syId                  = (int) $sy['id'];
$ready                 = cert_schema_ready($connection);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('certificates.php');
    }
    $periodId = to_int($_POST['period'] ?? 0) ?: null;
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'publish') {
            $n = cert_publish_period($connection, $syId, $periodId, $user);
            flash_set($n > 0 ? 'success' : 'error', $n > 0
                ? $n . ' certificate' . ($n === 1 ? '' : 's') . ' published — now visible in the students&rsquo; portal accounts.'
                : 'Nothing to publish: no draft certificates for this period.');
        } elseif ($action === 'revoke') {
            cert_revoke($connection, to_int($_POST['cert_id'] ?? 0), $user, (string) ($_POST['reason'] ?? ''));
            flash_set('success', 'Certificate revoked — the student can no longer view it.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('certificates.php?period=' . (int) $periodId);
}

$periods  = $ready ? gp_for_sy($connection, $syId) : [];
$current  = $ready ? gp_current($connection, $syId) : null;
$periodId = to_int($_GET['period'] ?? 0) ?: (int) ($current['id'] ?? 0);
$rows     = $ready ? cert_list($connection, $syId, $periodId ?: null, $user) : [];

$drafts = 0; $published = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'Draft') { $drafts++; }
    elseif ($r['status'] === 'Published') { $published++; }
}
$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificates | ITFA Department Head</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Department Head · Recognition</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Certificates of Recognition</h1>
            <p class="text-slate-500 mt-2">Review what your class advisers prepared, then publish. Published certificates appear in the student's portal account for viewing and printing.</p>
            <p class="text-xs text-green-700 mt-2">S.Y. <?= h($syLabel) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= $flash['message'] ?></div>
        <?php endif; ?>

        <?php if (!$ready): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-6">
            <h2 class="font-extrabold text-amber-700 mb-1">⚠ Certificate tables not installed</h2>
            <p class="text-sm text-slate-600">Run <code>migrations/certificates.sql</code> first.</p>
        </div>
        <?php else: ?>

        <div class="bg-white rounded-3xl border border-green-100 shadow-panel p-5 mb-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Grading Period</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($periods as $p): $on = (int) $p['id'] === $periodId; ?>
                <a href="certificates.php?period=<?= (int) $p['id'] ?>"
                   class="rounded-xl border px-4 py-2 text-sm font-bold transition-colors <?= $on ? 'bg-green-700 border-green-700 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-green-400' ?>">
                    <?= h((string) $p['name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Awaiting publish</p>
                <p class="text-2xl font-extrabold text-slate-700 mt-1"><?= $drafts ?></p>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-emerald-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-emerald-600 font-bold">Published</p>
                <p class="text-2xl font-extrabold text-emerald-700 mt-1"><?= $published ?></p>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4 flex items-center">
                <?php if ($drafts > 0): ?>
                <form method="POST" action="certificates.php" class="w-full"
                      onsubmit="return confirm('Publish <?= $drafts ?> certificate(s)? Students will be able to view and print them.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="period" value="<?= $periodId ?>">
                    <button class="w-full rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-3">📤 Publish <?= $drafts ?> Certificate<?= $drafts === 1 ? '' : 's' ?></button>
                </form>
                <?php else: ?>
                <p class="text-xs text-slate-400 w-full text-center">Nothing waiting to publish.</p>
                <?php endif; ?>
            </div>
        </div>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
            <?php if (!$rows): ?>
            <div class="p-10 text-center text-slate-400">
                <p class="font-semibold">No certificates for this period yet.</p>
                <p class="text-sm mt-1">Class advisers prepare them from their Certificates page.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3">Student</th>
                            <th class="text-left">Grade &amp; Section</th>
                            <th class="text-left">Honor</th>
                            <th class="text-center">Average</th>
                            <th class="text-left">Adviser</th>
                            <th class="text-center">Status</th>
                            <th class="text-right px-6">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $c): ?>
                        <tr class="hover:bg-green-50/30">
                            <td class="px-6 py-3">
                                <p class="font-bold"><?= h((string) $c['student_name']) ?></p>
                                <p class="text-[10px] text-slate-400 font-mono"><?= h((string) $c['certificate_no']) ?></p>
                            </td>
                            <td class="text-slate-600 text-xs"><?= h((string) ($c['grade_level'] ?? '')) ?> — <?= h((string) ($c['section_name'] ?? '')) ?></td>
                            <td><span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= cert_level_badge((string) $c['honor_level']) ?>"><?= h((string) $c['honor_level']) ?></span></td>
                            <td class="text-center font-bold"><?= $c['general_average'] !== null ? number_format((float) $c['general_average'], 2) : '—' ?></td>
                            <td class="text-xs text-slate-500"><?= h((string) ($c['adviser_name'] ?: '—')) ?></td>
                            <td class="text-center"><span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= cert_status_badge((string) $c['status']) ?>"><?= h((string) $c['status']) ?></span></td>
                            <td class="text-right px-6 whitespace-nowrap">
                                <a href="<?= h(app_url('depthead/certificate_print.php?id=' . (int) $c['id'])) ?>" target="_blank" class="text-xs font-bold text-green-700 hover:underline">Preview</a>
                                <?php if ($c['status'] !== 'Revoked'): ?>
                                <form method="POST" action="certificates.php" class="inline" onsubmit="return confirm('Revoke this certificate?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="cert_id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="period" value="<?= $periodId ?>">
                                    <input type="hidden" name="reason" value="Revoked by Department Head">
                                    <button class="ml-3 text-xs font-bold text-rose-600 hover:underline">Revoke</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
