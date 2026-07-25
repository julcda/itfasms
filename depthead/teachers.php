<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/teacher_service.php';

require_login();

$connection = db();
$user       = current_user();

// Department Heads, the Principal, and Super Admin.
if (!teacher_can_manage($user)) {
    flash_set('error', 'Access denied. Department Head login required.');
    redirect_to(app_url(user_home_path($user)));
}

$sy                    = teacher_active_sy($connection);
$syLabel               = $sy['label'];
$activeSchoolYearLabel = $syLabel;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('teachers.php');
    }
    try {
        $tid    = to_int($_POST['teacher_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $res    = teacher_set_status($connection, $tid, $status, $user);

        $name = trim((string) ($res['teacher']['Fullname'] ?: ($res['teacher']['Firstname'] . ' ' . $res['teacher']['Lastname'])));
        if (!$res['changed']) {
            flash_set('success', $name . ' is already ' . strtolower($status) . '.');
        } elseif ($status === 'Inactive') {
            $msg = $name . ' has been deactivated — they can no longer log in and will not appear in teacher dropdowns.';
            if ($res['remaining_classes'] > 0) {
                $msg .= ' ⚠ They are still assigned to ' . $res['remaining_classes']
                     . ' class' . ($res['remaining_classes'] === 1 ? '' : 'es')
                     . ' this school year — please reassign those classes.';
            }
            flash_set('success', $msg);
        } else {
            flash_set('success', $name . ' has been reactivated and can log in again.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('teachers.php' . (!empty($_POST['qs']) ? '?' . (string) $_POST['qs'] : ''));
}

$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$rows   = teacher_manage_list($connection, $sy['id'], $q, $status);
$flash  = flash_get();
$csrf   = csrf_token();
$qs     = http_build_query(array_filter(['q' => $q, 'status' => $status]));

$nActive = 0; $nInactive = 0; $nOrphan = 0;
foreach ($rows as $r) {
    if ((string) $r['status'] === 'Active') { $nActive++; } else { $nInactive++; }
    if ((string) $r['status'] !== 'Active' && (int) $r['active_classes'] > 0) { $nOrphan++; }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teacher Management | ITFA</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Department Head · Faculty</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Teacher Management</h1>
            <p class="text-slate-500 mt-2">Deactivate a teacher to revoke their login and remove them from teacher dropdowns. Their name stays on past classes and grades — history is never rewritten.</p>
            <p class="text-xs text-green-700 mt-2">S.Y. <?= h($syLabel) ?></p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="rounded-2xl bg-white border border-emerald-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-emerald-600 font-bold">Active</p>
                <p class="text-2xl font-extrabold text-emerald-700 mt-1"><?= number_format($nActive) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Inactive</p>
                <p class="text-2xl font-extrabold text-slate-600 mt-1"><?= number_format($nInactive) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-amber-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-amber-600 font-bold">Needs Reassign</p>
                <p class="text-2xl font-extrabold text-amber-700 mt-1"><?= number_format($nOrphan) ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-green-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-green-600 font-bold">Total</p>
                <p class="text-2xl font-extrabold text-green-700 mt-1"><?= number_format(count($rows)) ?></p>
            </div>
        </div>

        <?php if ($nOrphan > 0): ?>
        <div class="mb-5 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4 text-sm text-amber-900">
            <b>⚠ <?= $nOrphan ?> deactivated teacher<?= $nOrphan === 1 ? ' is' : 's are' ?> still assigned to classes this school year.</b>
            They cannot log in, but their classes have no working teacher. Reassign those classes in <b>Manage Schedules</b>.
        </div>
        <?php endif; ?>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
            <form method="GET" action="teachers.php" class="flex flex-wrap gap-3 items-end p-6 border-b border-slate-100">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Search</label>
                    <input name="q" value="<?= h($q) ?>" placeholder="Name or username…"
                           class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Status</label>
                    <select name="status" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                        <option value="">All</option>
                        <option value="Active"   <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Filter</button>
                <?php if ($q !== '' || $status !== ''): ?>
                <a href="teachers.php" class="rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-sm font-bold px-5 py-2.5">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (!$rows): ?>
            <div class="p-10 text-center text-slate-400"><p class="font-semibold">No teachers found.</p></div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3">Teacher</th>
                            <th class="text-left">Login</th>
                            <th class="text-center">Classes</th>
                            <th class="text-center">Advisory</th>
                            <th class="text-left">Last Login</th>
                            <th class="text-center">Status</th>
                            <th class="text-right px-6">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $r):
                        $active  = (string) $r['status'] === 'Active';
                        $cls     = (int) $r['active_classes'];
                        $isSelf  = (int) ($r['user_id'] ?? 0) === (int) ($user['id'] ?? -1);
                        $name    = trim((string) ($r['Fullname'] ?: ($r['Firstname'] . ' ' . $r['Lastname']))) ?: 'Teacher #' . $r['Teacher_id'];
                    ?>
                        <tr class="hover:bg-green-50/30 <?= $active ? '' : 'bg-slate-50/60' ?>">
                            <td class="px-6 py-3">
                                <p class="font-bold <?= $active ? '' : 'text-slate-400' ?>"><?= h($name) ?></p>
                                <p class="text-xs text-slate-400"><?= h((string) ($r['Designation'] ?: '—')) ?></p>
                            </td>
                            <td>
                                <?php if ($r['username']): ?>
                                <span class="font-mono text-xs <?= $active ? 'text-slate-600' : 'text-slate-400 line-through' ?>"><?= h((string) $r['username']) ?></span>
                                <?php if ((int) $r['must_change_password'] === 1): ?>
                                <span class="ml-1 text-[9px] font-bold text-amber-700">DEFAULT PW</span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-xs text-rose-500 font-bold">no login</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="<?= !$active && $cls > 0 ? 'font-extrabold text-amber-700' : 'text-slate-600' ?>"><?= $cls ?></span>
                                <?php if (!$active && $cls > 0): ?><span class="block text-[9px] font-bold text-amber-700">REASSIGN</span><?php endif; ?>
                            </td>
                            <td class="text-center text-slate-600"><?= (int) $r['advisory_count'] ?: '—' ?></td>
                            <td class="text-xs text-slate-500"><?= $r['last_login'] ? h(date('M j, Y g:ia', strtotime((string) $r['last_login']))) : 'never' ?></td>
                            <td class="text-center">
                                <span class="text-[10px] font-extrabold rounded-full px-2.5 py-0.5 border <?= $active ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-slate-200 text-slate-600 border-slate-300' ?>">
                                    <?= $active ? 'ACTIVE' : 'INACTIVE' ?>
                                </span>
                            </td>
                            <td class="text-right px-6">
                                <?php if ($isSelf): ?>
                                <span class="text-xs text-slate-400">that's you</span>
                                <?php else: ?>
                                <form method="POST" action="teachers.php" class="inline"
                                      onsubmit="return confirm('<?= $active
                                          ? 'Deactivate ' . h(addslashes($name)) . '?\n\nThey will be unable to log in and will disappear from teacher dropdowns.' . ($cls > 0 ? '\n\n⚠ They still have ' . $cls . ' class(es) this school year — reassign them afterwards.' : '')
                                          : 'Reactivate ' . h(addslashes($name)) . '? They will be able to log in again.' ?>');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="teacher_id" value="<?= (int) $r['Teacher_id'] ?>">
                                    <input type="hidden" name="status" value="<?= $active ? 'Inactive' : 'Active' ?>">
                                    <input type="hidden" name="qs" value="<?= h($qs) ?>">
                                    <button class="text-xs font-bold <?= $active ? 'text-rose-600' : 'text-emerald-700' ?> hover:underline">
                                        <?= $active ? 'Deactivate' : 'Reactivate' ?>
                                    </button>
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

        <div class="mt-6 rounded-2xl bg-slate-50 border border-slate-200 px-5 py-4 text-xs text-slate-600">
            <b>What deactivating does:</b> blocks login immediately (at the login page, not after), hides them from every
            teacher dropdown, and stops any open session on their next click.
            <b>What it does not do:</b> unassign their classes or touch their grades — past schedules and report cards keep
            their name. If a deactivated teacher still holds classes, reassign those classes to someone else.
        </div>
    </main>
</div>
</body>
</html>
