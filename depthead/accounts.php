<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/account_service.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_super_admin($user)) {
    flash_set('error', 'Only Super Admin can manage system accounts.');
    redirect_to(app_url(user_home_path($user)));
}

$activeSchoolYearLabel = '';
try {
    $r = $connection->query('SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1');
    $activeSchoolYearLabel = (string) ($r && ($x = $r->fetch_assoc()) ? $x['School_year'] : '');
} catch (Throwable) {}
if ($activeSchoolYearLabel === '') { $activeSchoolYearLabel = date('Y') . '-' . ((int) date('Y') + 1); }

$qs = http_build_query(array_filter([
    'q'      => (string) ($_GET['q'] ?? ''),
    'source' => (string) ($_GET['source'] ?? ''),
    'role'   => (string) ($_GET['role'] ?? ''),
]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('accounts.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $source = (string) ($_POST['source'] ?? '');
    $id     = to_int($_POST['id'] ?? 0);
    $back   = 'accounts.php' . (!empty($_POST['qs']) ? '?' . (string) $_POST['qs'] : '');

    try {
        if ($action === 'set_password') {
            $pw     = (string) ($_POST['password'] ?? '');
            $force  = isset($_POST['force_change']);
            $acct   = acct_get($connection, $source, $id);
            acct_set_password($connection, $source, $id, $pw, $user, $force);

            $msg = 'Password updated for ' . ($acct['username'] ?? '') . '. New password: ' . $pw;
            if ($source === 'legacy' && $acct && !$acct['hashed']) {
                $msg .= ' — this account was stored in PLAIN TEXT and is now securely hashed.';
            }
            if ($force && $source !== 'legacy') {
                $msg .= ' The user must change it at next login.';
            }
            flash_set('success', $msg);

        } elseif ($action === 'set_username') {
            acct_set_username($connection, $source, $id, (string) ($_POST['username'] ?? ''), $user);
            flash_set('success', 'Username updated.');

        } elseif ($action === 'set_status') {
            acct_set_status($connection, $source, $id, (string) ($_POST['status'] ?? ''), $user);
            flash_set('success', 'Account status updated.');

        } elseif ($action === 'set_role') {
            acct_set_role($connection, $id, (string) ($_POST['role'] ?? ''), $user);
            flash_set('success', 'Role updated.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to($back);
}

$q       = trim((string) ($_GET['q'] ?? ''));
$source  = (string) ($_GET['source'] ?? '');
$role    = (string) ($_GET['role'] ?? '');
$rows    = acct_list($connection, $q, $source, $role);
$stats   = acct_stats($connection);
$flash   = flash_get();
$csrf    = csrf_token();
$suggest = acct_suggest_password();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Management | ITFA Super Admin</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-rose-700 font-semibold">Super Admin</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Account Management</h1>
            <p class="text-slate-500 mt-2">Reset passwords, rename logins, change roles, and enable or disable any account in the system — staff, teachers, legacy module logins, and student portal accounts.</p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <?= h($flash['message']) ?>
            <?php if ($flash['type']==='success' && str_contains($flash['message'], 'New password:')): ?>
            <p class="text-xs mt-1 font-normal">⚠ Copy it now — it cannot be shown again.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Staff</p>
                <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format($stats['staff']) ?></p>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-emerald-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-emerald-600 font-bold">Teachers</p>
                <p class="text-2xl font-extrabold text-emerald-700 mt-1"><?= number_format($stats['teachers']) ?></p>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-sky-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-sky-600 font-bold">Students</p>
                <p class="text-2xl font-extrabold text-sky-700 mt-1"><?= number_format($stats['students']) ?></p>
            </div>
            <div class="rounded-2xl bg-white ring-1 ring-slate-200 shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Inactive</p>
                <p class="text-2xl font-extrabold text-slate-600 mt-1"><?= number_format($stats['inactive']) ?></p>
            </div>
            <div class="rounded-2xl <?= $stats['plaintext'] > 0 ? 'bg-rose-50 ring-1 ring-rose-300' : 'bg-white ring-1 ring-slate-200' ?> shadow-panel p-4">
                <p class="text-xs uppercase tracking-wider <?= $stats['plaintext'] > 0 ? 'text-rose-600' : 'text-slate-500' ?> font-bold">Plain-text PW</p>
                <p class="text-2xl font-extrabold <?= $stats['plaintext'] > 0 ? 'text-rose-700' : 'text-slate-600' ?> mt-1"><?= number_format($stats['plaintext']) ?></p>
            </div>
        </div>

        <?php if ($stats['plaintext'] > 0): ?>
        <div class="mb-6 rounded-2xl bg-rose-50 border border-rose-300 px-5 py-4">
            <p class="font-bold text-rose-900">🔴 <?= $stats['plaintext'] ?> account<?= $stats['plaintext'] === 1 ? ' is' : 's are' ?> still stored as plain text</p>
            <p class="text-sm text-rose-800 mt-1">
                Anyone who reads the database, a backup, or <code>enrollment_db.sql</code> can see these passwords directly.
                <b>Resetting the password here stores it securely (bcrypt) and fixes it permanently</b> — the account keeps working straight away.
            </p>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="accounts.php" class="bg-white rounded-3xl border border-green-100 shadow-panel p-5 mb-6 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-semibold text-slate-500 mb-1.5">Search</label>
                <input name="q" value="<?= h($q) ?>" placeholder="Username, name, LRN or email…"
                       class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1.5">Account type</label>
                <select name="source" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                    <option value="">All types</option>
                    <?php foreach (acct_sources() as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $source === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1.5">Role</label>
                <select name="role" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                    <option value="">All roles</option>
                    <?php foreach (['super admin','user','teacher'] as $r): ?>
                    <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucwords($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Filter</button>
            <?php if ($q !== '' || $source !== '' || $role !== ''): ?>
            <a href="accounts.php" class="rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-sm font-bold px-5 py-2.5">Clear</a>
            <?php endif; ?>
        </form>

        <section class="bg-white rounded-3xl border border-green-100 shadow-panel overflow-hidden">
            <div class="px-6 py-3 border-b border-slate-100 text-xs text-slate-500">
                Showing <b><?= number_format(count($rows)) ?></b> account<?= count($rows) === 1 ? '' : 's' ?>
            </div>

            <?php if (!$rows): ?>
            <div class="p-10 text-center text-slate-400"><p class="font-semibold">No accounts match.</p></div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3">Account</th>
                            <th class="text-left">Type</th>
                            <th class="text-left">Role</th>
                            <th class="text-left">Last login</th>
                            <th class="text-center">Status</th>
                            <th class="text-right px-6">Manage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $a):
                        $isSelf = $a['source'] === 'staff' && $a['id'] === (int) $user['id'];
                        $active = $a['status'] === 'Active';
                        $key    = $a['source'] . '-' . $a['id'];
                    ?>
                        <tr class="hover:bg-green-50/30 <?= $active ? '' : 'bg-slate-50/60' ?>">
                            <td class="px-6 py-3">
                                <p class="font-bold <?= $active ? '' : 'text-slate-400' ?>"><?= h($a['name']) ?></p>
                                <p class="text-xs font-mono text-slate-500"><?= h($a['username']) ?>
                                    <?php if (!$a['hashed']): ?>
                                    <span class="ml-1 text-[9px] font-sans font-extrabold text-rose-700 bg-rose-100 border border-rose-300 rounded px-1">PLAIN TEXT</span>
                                    <?php endif; ?>
                                    <?php if ($a['must_change']): ?>
                                    <span class="ml-1 text-[9px] font-sans font-bold text-amber-700">must change</span>
                                    <?php endif; ?>
                                </p>
                            </td>
                            <td class="text-xs text-slate-500"><?= h(acct_sources()[$a['source']] ?? $a['source']) ?></td>
                            <td><span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= acct_role_badge($a['role']) ?>"><?= h(strtoupper($a['role'])) ?></span></td>
                            <td class="text-xs text-slate-500"><?= $a['last_login'] ? h(date('M j, Y g:ia', strtotime((string) $a['last_login']))) : 'never' ?></td>
                            <td class="text-center">
                                <span class="text-[10px] font-extrabold rounded-full px-2 py-0.5 border <?= $active ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-slate-200 text-slate-600 border-slate-300' ?>"><?= $active ? 'ACTIVE' : 'INACTIVE' ?></span>
                            </td>
                            <td class="text-right px-6">
                                <button type="button" onclick="togglePanel('<?= $key ?>')" class="text-xs font-bold text-green-700 hover:underline">Manage ▾</button>
                            </td>
                        </tr>
                        <tr id="p-<?= $key ?>" class="hidden bg-slate-50">
                            <td colspan="6" class="px-6 py-4">
                                <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">

                                    <!-- Password -->
                                    <form method="POST" action="accounts.php" class="bg-white rounded-xl border border-slate-200 p-3"
                                          onsubmit="return confirm('Set a new password for <?= h(addslashes($a['username'])) ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="set_password">
                                        <input type="hidden" name="source" value="<?= h($a['source']) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="qs" value="<?= h($qs) ?>">
                                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Reset password</p>
                                        <input name="password" required minlength="<?= ACCT_MIN_PW ?>" value="<?= h($suggest) ?>"
                                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono mb-2">
                                        <?php if ($a['source'] !== 'legacy'): ?>
                                        <label class="flex items-center gap-2 text-xs text-slate-600 mb-2">
                                            <input type="checkbox" name="force_change" checked class="rounded"> Must change at next login
                                        </label>
                                        <?php endif; ?>
                                        <button class="w-full rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-2">Set Password</button>
                                    </form>

                                    <!-- Username -->
                                    <form method="POST" action="accounts.php" class="bg-white rounded-xl border border-slate-200 p-3">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="set_username">
                                        <input type="hidden" name="source" value="<?= h($a['source']) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="qs" value="<?= h($qs) ?>">
                                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Username</p>
                                        <input name="username" value="<?= h($a['username']) ?>" <?= $a['source'] === 'student' ? 'disabled' : 'required' ?>
                                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono mb-2 <?= $a['source'] === 'student' ? 'bg-slate-100 text-slate-400' : '' ?>">
                                        <?php if ($a['source'] === 'student'): ?>
                                        <p class="text-[11px] text-slate-400">A student's username is their LRN.</p>
                                        <?php else: ?>
                                        <button class="w-full rounded-lg bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold px-3 py-2">Rename</button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Role -->
                                    <div class="bg-white rounded-xl border border-slate-200 p-3">
                                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Role</p>
                                        <?php if ($a['source'] !== 'staff'): ?>
                                        <p class="text-[11px] text-slate-400">Role is fixed for <?= h(acct_sources()[$a['source']]) ?> accounts.</p>
                                        <?php elseif ($isSelf): ?>
                                        <p class="text-[11px] text-slate-400">You cannot change your own role.</p>
                                        <?php else: ?>
                                        <form method="POST" action="accounts.php" onsubmit="return confirm('Change this account\'s role?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="set_role">
                                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="qs" value="<?= h($qs) ?>">
                                            <select name="role" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-2">
                                                <?php foreach (['user' => 'Department Head / Staff', 'super admin' => 'Super Admin', 'teacher' => 'Teacher'] as $rv => $rl): ?>
                                                <option value="<?= $rv ?>" <?= $a['role'] === $rv ? 'selected' : '' ?>><?= h($rl) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="w-full rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-xs font-bold px-3 py-2">Update Role</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Status -->
                                    <div class="bg-white rounded-xl border border-slate-200 p-3">
                                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Access</p>
                                        <?php if ($isSelf): ?>
                                        <p class="text-[11px] text-slate-400">You cannot deactivate your own account.</p>
                                        <?php else: ?>
                                        <form method="POST" action="accounts.php"
                                              onsubmit="return confirm('<?= $active ? 'Deactivate this account? They will be unable to log in.' : 'Reactivate this account?' ?>');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="source" value="<?= h($a['source']) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="status" value="<?= $active ? 'Inactive' : 'Active' ?>">
                                            <input type="hidden" name="qs" value="<?= h($qs) ?>">
                                            <p class="text-[11px] text-slate-500 mb-2"><?= $active ? 'Blocks login immediately.' : 'Restores login access.' ?></p>
                                            <button class="w-full rounded-lg <?= $active ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' ?> text-white text-xs font-bold px-3 py-2">
                                                <?= $active ? 'Deactivate' : 'Reactivate' ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <div class="mt-6 rounded-2xl bg-slate-50 border border-slate-200 px-5 py-4 text-xs text-slate-600">
            <b>How passwords work here:</b> existing passwords can never be read back — they are stored one-way.
            When you set a new one it is shown to you <b>once</b> so you can pass it on, then stored securely.
            Every reset, rename, role change and deactivation is written to the audit log with your name.
        </div>
    </main>
</div>
<script>
function togglePanel(k) { document.getElementById('p-' + k).classList.toggle('hidden'); }
</script>
</body>
</html>
