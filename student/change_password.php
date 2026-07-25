<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';

// Reachable while still on the default password (the only place that is).
$account = require_student_login(false);
$db      = db();

// Already changed? Nothing to force here — send them to account settings.
if ((int) $account['must_change_password'] === 0) {
    redirect_to(app_url('student/account.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to(app_url('student/change_password.php'));
    }
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $error = null;
    if (strlen($new) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strtolower($new) === STUDENT_DEFAULT_PW) {
        $error = 'Please choose a password different from the default.';
    }

    if ($error) {
        flash_set('error', $error);
        redirect_to(app_url('student/change_password.php'));
    }

    student_update_password($db, (int) $account['id'], $new);
    flash_set('success', 'Password updated. Welcome to your student portal!');
    redirect_to(app_url('student/index.php'));
}

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password | Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen font-sans text-slate-800 antialiased flex items-center justify-center p-4"
      style="background:linear-gradient(135deg,#166534 0%,#0a3a1e 45%,#0f4d28 100%)">

    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-14 h-14 object-contain mx-auto mb-3">
            <h1 class="text-xl font-extrabold text-white">Set a new password</h1>
            <p class="text-green-200 text-sm mt-1">For your security, please replace the default password.</p>
        </div>

        <div class="bg-white rounded-3xl shadow-2xl p-7">
            <?php if ($flash): ?>
            <div class="mb-4 rounded-xl border px-4 py-3 text-sm font-medium
                <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
                <?= h($flash['message']) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="change_password.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">New password</label>
                    <input type="password" name="new_password" required minlength="6"
                           class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Confirm new password</label>
                    <input type="password" name="confirm_password" required minlength="6"
                           class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <p class="text-xs text-slate-400">At least 6 characters, and not the word “password”.</p>
                <button type="submit" class="w-full rounded-xl bg-green-600 hover:bg-green-700 text-white font-bold py-3">
                    Update password &amp; continue
                </button>
            </form>
        </div>

        <p class="text-center text-green-300/70 text-xs mt-4">
            <a href="<?= h(app_url('student/logout.php')) ?>" class="hover:text-white underline">Cancel &amp; log out</a>
        </p>
    </div>
</body>
</html>
