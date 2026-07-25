<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';

// This is the ONE page reachable while the issued default password is still set,
// so require_teacher_login() must not bounce us here in a redirect loop — it
// exempts this filename explicitly.
$teacher = require_teacher_login();

$db      = db();
$user    = current_user();
$userId  = (int) $user['id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];
$mustChange = teacher_must_change_password($db, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('change_password.php');
    }
    try {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $row = $db->query('SELECT password FROM user_account WHERE user_id = ' . $userId)->fetch_assoc();
        if (!$row || !password_verify($current, (string) $row['password'])) {
            throw new RuntimeException('Your current password is incorrect.');
        }
        if (strlen($new) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }
        if ($new === $current) {
            throw new RuntimeException('Your new password must be different from your current one.');
        }
        if ($new !== $confirm) {
            throw new RuntimeException('New passwords do not match.');
        }
        if (strtolower($new) === TEACHER_DEFAULT_PW) {
            throw new RuntimeException('Please choose a password other than the issued default.');
        }

        teacher_update_password($db, $userId, $new);
        flash_set('success', 'Password updated. Welcome to the Teacher Module.');
        redirect_to(app_url('teacher/index.php'));
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('change_password.php');
    }
}

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(5,150,105,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php if (!$mustChange) { require __DIR__ . '/sidebar.php'; } else { echo '<div></div>'; } ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">
        <div class="max-w-lg mx-auto">

            <?php if ($mustChange): ?>
            <div class="text-center mb-6">
                <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-14 h-14 object-contain mx-auto mb-3">
                <h1 class="text-2xl font-extrabold">Set your password</h1>
                <p class="text-slate-500 text-sm mt-1">
                    Welcome, <?= h(teacher_display_name($teacher)) ?>. For your security, please replace the
                    password you were issued before continuing.
                </p>
            </div>
            <?php else: ?>
            <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mb-6">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Teacher · Account</p>
                <h1 class="text-2xl font-extrabold mt-1">Change Password</h1>
            </header>
            <?php endif; ?>

            <?php if ($flash): ?>
            <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
            <?php endif; ?>

            <?php if ($mustChange): ?>
            <div class="mb-5 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-3 text-sm text-amber-900">
                You are still using the default password issued by the Registrar. You cannot access the module until it is changed.
            </div>
            <?php endif; ?>

            <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel p-6">
                <form method="POST" action="change_password.php" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Current Password</label>
                        <input type="password" name="current_password" required autofocus
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">New Password</label>
                        <input type="password" name="new_password" required minlength="8"
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="text-xs text-slate-400 mt-1">At least 8 characters.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Confirm New Password</label>
                        <input type="password" name="confirm_password" required minlength="8"
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <button class="w-full rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-3">
                        <?= $mustChange ? 'Set Password & Continue' : 'Update Password' ?>
                    </button>
                </form>
            </section>

            <?php if ($mustChange): ?>
            <p class="text-center mt-4"><a href="<?= h(app_url('logout.php')) ?>" class="text-xs text-slate-400 hover:underline">Sign out</a></p>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
