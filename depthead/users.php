<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$connection = db();
$user       = current_user();

if (!is_super_admin($user)) {
    flash_set('error', 'Only Super Admin can access this module.');
    redirect_to(app_url('depthead/index.php'));
}

// ── Active school year label (for sidebar) ────────────────────────────────────
$activeSchoolYearLabel = '';
try {
    $syStmt = $connection->prepare(
        'SELECT School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
    );
    $syStmt->execute();
    $syRow = $syStmt->get_result()->fetch_assoc();
    if ($syRow && !empty($syRow['School_year'])) {
        $activeSchoolYearLabel = (string) $syRow['School_year'];
    }
} catch (Throwable) {}

if ($activeSchoolYearLabel === '') {
    $activeSchoolYearLabel = date('Y') . '-' . ((int) date('Y') + 1);
}

const DEFAULT_PASSWORD = '12345';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('users.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_user') {
            $username   = trim((string) ($_POST['username']   ?? ''));
            $email      = trim((string) ($_POST['email']      ?? ''));
            $firstName  = trim((string) ($_POST['first_name'] ?? ''));
            $lastName   = trim((string) ($_POST['last_name']  ?? ''));
            $role       = trim((string) ($_POST['role']       ?? 'user'));
            $password   = (string) ($_POST['password'] ?? '');

            if ($username === '' || $email === '' || $firstName === '' || $lastName === '') {
                throw new RuntimeException('All fields except password are required.');
            }

            if (!in_array($role, ['user', 'admin'], true)) {
                $role = 'user';
            }

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            // Check duplicate username
            $dupStmt = $connection->prepare(
                'SELECT user_id FROM user_account WHERE username = ? LIMIT 1'
            );
            $dupStmt->bind_param('s', $username);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('Username already exists.');
            }

            $plainPw = $password !== '' ? $password : DEFAULT_PASSWORD;
            $hashed  = password_hash($plainPw, PASSWORD_BCRYPT);

            $insStmt = $connection->prepare(
                'INSERT INTO user_account (username, password, email, first_name, last_name, role)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insStmt->bind_param('ssssss', $username, $hashed, $email, $firstName, $lastName, $role);
            $insStmt->execute();

            flash_set('success', "User account for {$firstName} {$lastName} created. Default password: " . ($password !== '' ? '(custom)' : DEFAULT_PASSWORD));
            redirect_to('users.php');

        } elseif ($action === 'edit_user') {
            $userId    = to_int($_POST['user_id']    ?? 0);
            $email     = trim((string) ($_POST['email']      ?? ''));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName  = trim((string) ($_POST['last_name']  ?? ''));
            $role      = trim((string) ($_POST['role']       ?? 'user'));

            if ($userId <= 0 || $firstName === '' || $lastName === '' || $email === '') {
                throw new RuntimeException('All fields are required.');
            }

            if (!in_array($role, ['user', 'admin'], true)) {
                $role = 'user';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $updStmt = $connection->prepare(
                'UPDATE user_account SET email = ?, first_name = ?, last_name = ?, role = ? WHERE user_id = ?'
            );
            $updStmt->bind_param('ssssi', $email, $firstName, $lastName, $role, $userId);
            $updStmt->execute();

            flash_set('success', 'User account updated successfully.');
            redirect_to('users.php');

        } elseif ($action === 'reset_password') {
            $userId = to_int($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Invalid user.');
            }

            $hashed  = password_hash(DEFAULT_PASSWORD, PASSWORD_BCRYPT);
            $updStmt = $connection->prepare(
                'UPDATE user_account SET password = ? WHERE user_id = ?'
            );
            $updStmt->bind_param('si', $hashed, $userId);
            $updStmt->execute();

            flash_set('success', 'Password has been reset to the default: ' . DEFAULT_PASSWORD);
            redirect_to('users.php');

        } elseif ($action === 'change_password') {
            $userId  = to_int($_POST['user_id'] ?? 0);
            $newPass = trim((string) ($_POST['new_password'] ?? ''));

            if ($userId <= 0) {
                throw new RuntimeException('Invalid user.');
            }
            if (strlen($newPass) < 4) {
                throw new RuntimeException('Password must be at least 4 characters.');
            }

            $hashed  = password_hash($newPass, PASSWORD_BCRYPT);
            $updStmt = $connection->prepare(
                'UPDATE user_account SET password = ? WHERE user_id = ?'
            );
            $updStmt->bind_param('si', $hashed, $userId);
            $updStmt->execute();

            flash_set('success', 'Password changed successfully.');
            redirect_to('users.php');

        } elseif ($action === 'delete_user') {
            $userId = to_int($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Invalid user.');
            }

            // Prevent deletion if user has classes
            $chkStmt = $connection->prepare(
                'SELECT COUNT(*) AS cnt FROM classes WHERE user_id = ?'
            );
            $chkStmt->bind_param('i', $userId);
            $chkStmt->execute();
            $chkRow = $chkStmt->get_result()->fetch_assoc();
            if ((int)($chkRow['cnt'] ?? 0) > 0) {
                throw new RuntimeException('Cannot delete: this user has class schedule entries assigned. Reassign or remove them first.');
            }

            $delStmt = $connection->prepare('DELETE FROM user_account WHERE user_id = ?');
            $delStmt->bind_param('i', $userId);
            $delStmt->execute();

            flash_set('success', 'User account deleted.');
            redirect_to('users.php');

        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $error) {
        flash_set('error', $error->getMessage());
        redirect_to('users.php');
    }
}

// ── Fetch all user_account records ────────────────────────────────────────────
$accounts = [];
try {
    $res = $connection->query(
        'SELECT ua.user_id, ua.username, ua.email, ua.first_name, ua.last_name,
                ua.role, ua.created_at, ua.updated_at,
                COUNT(c.Class_id) AS class_count
         FROM user_account ua
         LEFT JOIN classes c ON c.user_id = ua.user_id
         GROUP BY ua.user_id
         ORDER BY ua.user_id'
    );
    $accounts = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (Throwable) {}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Management | Super Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: { 50: '#eff6ff', 300: '#93c5fd', 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8' }
                    },
                    boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.20)' }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 font-sans">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.12),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <!-- Header -->
        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Super Admin</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold mt-1">User Account Management</h2>
            <p class="text-slate-500 mt-2">Manage department head and class manager accounts used for class scheduling from the super-admin module. Default password: <strong class="font-mono"><?= DEFAULT_PASSWORD ?></strong>.</p>
        </header>

        <!-- Flash -->
        <?php if ($flash): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- Stats + Add button -->
        <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
            <p class="text-sm text-slate-500"><?= count($accounts) ?> user account<?= count($accounts) !== 1 ? 's' : '' ?> found</p>
            <button type="button" onclick="openCreateModal()"
                    class="rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                New User Account
            </button>
        </div>

        <!-- Table -->
        <section class="rounded-3xl border border-slate-200 bg-white shadow-panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-green-50 text-xs uppercase tracking-wide text-green-800">
                        <tr>
                            <th class="px-4 py-3 text-left">ID</th>
                            <th class="px-4 py-3 text-left">Username</th>
                            <th class="px-4 py-3 text-left">Full Name</th>
                            <th class="px-4 py-3 text-left">Email</th>
                            <th class="px-4 py-3 text-left">Role</th>
                            <th class="px-4 py-3 text-center">Classes</th>
                            <th class="px-4 py-3 text-left">Last Updated</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if ($accounts === []): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-slate-400">No user accounts found.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($accounts as $acct): ?>
                            <?php
                                $fullName   = trim(h((string)($acct['first_name'] ?? '')) . ' ' . h((string)($acct['last_name'] ?? '')));
                                $isAdmin    = ($acct['role'] ?? '') === 'admin';
                                $classCount = (int)($acct['class_count'] ?? 0);
                                $updated    = (string)($acct['updated_at'] ?? '');
                            ?>
                            <tr class="hover:bg-green-50/40 transition-colors">
                                <td class="px-4 py-3 font-mono text-xs text-slate-400"><?= h((string)$acct['user_id']) ?></td>
                                <td class="px-4 py-3 font-semibold font-mono"><?= h((string)$acct['username']) ?></td>
                                <td class="px-4 py-3"><?= $fullName ?></td>
                                <td class="px-4 py-3 text-slate-500 text-xs"><?= h((string)$acct['email']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $isAdmin ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= h(strtoupper((string)($acct['role'] ?? 'user'))) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($classCount > 0): ?>
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-700"><?= $classCount ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-300">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-400">
                                    <?= $updated && $updated !== '0000-00-00 00:00:00' ? h(date('M d, Y', strtotime($updated))) : '—' ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                        <!-- Edit -->
                                        <button type="button"
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                                    'user_id'    => (int)$acct['user_id'],
                                                    'username'   => $acct['username'],
                                                    'email'      => $acct['email'],
                                                    'first_name' => $acct['first_name'],
                                                    'last_name'  => $acct['last_name'],
                                                    'role'       => $acct['role'],
                                                ]), ENT_QUOTES) ?>)"
                                                class="rounded-xl border border-green-300 bg-green-50 text-green-700 px-2.5 py-1 text-xs font-semibold hover:bg-green-100 transition-colors">
                                            Edit
                                        </button>
                                        <!-- Change Password -->
                                        <button type="button"
                                                onclick="openPasswordModal(<?= (int)$acct['user_id'] ?>, <?= htmlspecialchars(json_encode($acct['username']), ENT_QUOTES) ?>)"
                                                class="rounded-xl border border-green-300 bg-green-50 text-green-700 px-2.5 py-1 text-xs font-semibold hover:bg-green-100 transition-colors">
                                            Password
                                        </button>
                                        <!-- Reset to default -->
                                        <button type="button"
                                                onclick="openResetModal(<?= (int)$acct['user_id'] ?>, <?= htmlspecialchars(json_encode($acct['username']), ENT_QUOTES) ?>)"
                                                class="rounded-xl border border-amber-300 bg-amber-50 text-amber-700 px-2.5 py-1 text-xs font-semibold hover:bg-amber-100 transition-colors">
                                            Reset PW
                                        </button>
                                        <!-- Delete -->
                                        <button type="button"
                                                onclick="openDeleteModal(<?= (int)$acct['user_id'] ?>, <?= htmlspecialchars(json_encode($acct['username']), ENT_QUOTES) ?>)"
                                                class="rounded-xl border border-rose-200 bg-rose-50 text-rose-600 px-2.5 py-1 text-xs font-semibold hover:bg-rose-100 transition-colors">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 text-xs text-slate-400">
                Passwords are stored as bcrypt hashes. Default password on create/reset: <strong class="font-mono text-slate-600"><?= DEFAULT_PASSWORD ?></strong>
            </div>
        </section>

    </main>
</div>

<!-- ── Create Modal ───────────────────────────────────────────────────────── -->
<div id="createModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between">
            <div>
                <h3 class="text-lg font-extrabold">Create User Account</h3>
                <p class="text-sm text-slate-500 mt-1">Leave password blank to use default: <span class="font-mono font-semibold"><?= DEFAULT_PASSWORD ?></span></p>
            </div>
            <button type="button" onclick="closeModal('createModal')" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="users.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">First Name</label>
                    <input type="text" name="first_name" required
                           class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Last Name</label>
                    <input type="text" name="last_name" required
                           class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Username</label>
                <input type="text" name="username" required autocomplete="off"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Email</label>
                <input type="email" name="email" required
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Role</label>
                <select name="role"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Password <span class="text-slate-400 font-normal">(optional)</span></label>
                <input type="password" name="password" autocomplete="new-password"
                       placeholder="Leave blank for default: <?= DEFAULT_PASSWORD ?>"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                    Create Account
                </button>
                <button type="button" onclick="closeModal('createModal')"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────── -->
<div id="editModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between">
            <div>
                <h3 class="text-lg font-extrabold">Edit User Account</h3>
                <p id="editUsernameLabel" class="text-sm text-slate-500 font-mono mt-1"></p>
            </div>
            <button type="button" onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="users.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">First Name</label>
                    <input type="text" name="first_name" id="editFirstName" required
                           class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Last Name</label>
                    <input type="text" name="last_name" id="editLastName" required
                           class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Email</label>
                <input type="email" name="email" id="editEmail" required
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Role</label>
                <select name="role" id="editRole"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                    Save Changes
                </button>
                <button type="button" onclick="closeModal('editModal')"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Change Password Modal ──────────────────────────────────────────────── -->
<div id="passwordModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm">
        <div class="p-6 border-b border-slate-100 flex items-start justify-between">
            <div>
                <h3 class="text-lg font-extrabold">Change Password</h3>
                <p id="passwordUsernameLabel" class="text-sm text-slate-500 font-mono mt-1"></p>
            </div>
            <button type="button" onclick="closeModal('passwordModal')" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" action="users.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token"  value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"      value="change_password">
            <input type="hidden" name="user_id"     id="passwordUserId">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">New Password</label>
                <input type="password" name="new_password" required autocomplete="new-password" minlength="4"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="flex-1 rounded-xl bg-green-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors">
                    Set Password
                </button>
                <button type="button" onclick="closeModal('passwordModal')"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Reset Password Confirm Modal ──────────────────────────────────────── -->
<div id="resetModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm">
        <div class="p-6 border-b border-slate-100">
            <h3 class="text-lg font-extrabold text-amber-700">Reset Password</h3>
            <p class="text-sm text-slate-500 mt-1">This will set the password to the system default.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-slate-700 mb-2">
                Reset password for <strong id="resetUsernameLabel" class="font-mono text-slate-900"></strong>?
            </p>
            <p class="text-sm text-slate-500 mb-6">
                New password will be: <span class="font-mono font-bold text-amber-700"><?= DEFAULT_PASSWORD ?></span>
            </p>
            <form method="POST" action="users.php" class="flex gap-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action"     value="reset_password">
                <input type="hidden" name="user_id"    id="resetUserId">
                <button type="submit"
                        class="flex-1 rounded-xl bg-amber-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-amber-700 transition-colors">
                    Yes, Reset
                </button>
                <button type="button" onclick="closeModal('resetModal')"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ───────────────────────────────────────────────── -->
<div id="deleteModal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm">
        <div class="p-6 border-b border-slate-100">
            <h3 class="text-lg font-extrabold text-rose-700">Delete User Account</h3>
            <p class="text-sm text-slate-500 mt-1">This cannot be undone.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-slate-700 mb-6">
                Delete user <strong id="deleteUsernameLabel" class="font-mono text-slate-900"></strong>?
            </p>
            <form method="POST" action="users.php" class="flex gap-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action"     value="delete_user">
                <input type="hidden" name="user_id"    id="deleteUserId">
                <button type="submit"
                        class="flex-1 rounded-xl bg-rose-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-rose-700 transition-colors">
                    Yes, Delete
                </button>
                <button type="button" onclick="closeModal('deleteModal')"
                        class="flex-1 rounded-xl border border-slate-200 bg-white text-slate-600 px-4 py-2.5 text-sm font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function openCreateModal() {
    document.getElementById('createModal').style.display = 'flex';
}

function openEditModal(data) {
    document.getElementById('editUserId').value         = data.user_id;
    document.getElementById('editUsernameLabel').textContent = data.username;
    document.getElementById('editFirstName').value      = data.first_name ?? '';
    document.getElementById('editLastName').value       = data.last_name ?? '';
    document.getElementById('editEmail').value          = data.email ?? '';
    document.getElementById('editRole').value           = data.role ?? 'user';
    document.getElementById('editModal').style.display  = 'flex';
}

function openPasswordModal(userId, username) {
    document.getElementById('passwordUserId').value             = userId;
    document.getElementById('passwordUsernameLabel').textContent = username;
    document.getElementById('passwordModal').style.display      = 'flex';
}

function openResetModal(userId, username) {
    document.getElementById('resetUserId').value            = userId;
    document.getElementById('resetUsernameLabel').textContent = username;
    document.getElementById('resetModal').style.display     = 'flex';
}

function openDeleteModal(userId, username) {
    document.getElementById('deleteUserId').value             = userId;
    document.getElementById('deleteUsernameLabel').textContent = username;
    document.getElementById('deleteModal').style.display      = 'flex';
}

['createModal', 'editModal', 'passwordModal', 'resetModal', 'deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>
</body>
</html>
