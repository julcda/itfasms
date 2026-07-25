<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';

$account = require_student_login();
$db      = db();
$sess    = current_student();

$profile = student_profile($db, (int) $sess['enrollment_id']);
if (!$profile) {
    student_logout();
    redirect_to(app_url('student/login.php'));
}

$enrollmentId = (int) $sess['enrollment_id'];
$studentType  = (string) $profile['student_type'];   // New | Old
$studentId    = (string) $profile['student_id'];

// Which profile table + key column to write back to.
$profileTable = $studentType === 'New' ? 'preregistration' : 'old_studentprofile';
$profileKey   = $studentType === 'New' ? 'id' : 'student_id';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to(app_url('student/account.php'));
    }
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_contact') {
            $contact = trim((string) ($_POST['contact'] ?? ''));
            $email   = trim((string) ($_POST['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            $sql = "UPDATE `$profileTable` SET contact = ?, email = ? WHERE `$profileKey` = ? LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('sss', $contact, $email, $studentId);
            $stmt->execute();
            flash_set('success', 'Contact information updated.');

        } elseif ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');
            if (!password_verify($current, (string) $account['password_hash'])) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            if (strlen($new) < 6) {
                throw new RuntimeException('New password must be at least 6 characters.');
            }
            if ($new !== $confirm) {
                throw new RuntimeException('New passwords do not match.');
            }
            student_update_password($db, (int) $account['id'], $new);
            flash_set('success', 'Password changed successfully.');

        } elseif ($action === 'upload_photo') {
            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please choose an image to upload.');
            }
            $tmp  = $_FILES['photo']['tmp_name'];
            $size = (int) $_FILES['photo']['size'];
            if ($size > 3 * 1024 * 1024) {
                throw new RuntimeException('Image must be 3 MB or smaller.');
            }
            $info = @getimagesize($tmp);
            $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($allowed[$info[2]])) {
                throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
            }
            $ext = $allowed[$info[2]];
            $dir = dirname(__DIR__) . '/uploads/student_photos';
            // Remove any prior photo for this student (any extension).
            foreach (['jpg','jpeg','png','webp'] as $old) {
                $p = "$dir/$enrollmentId.$old";
                if (is_file($p)) { @unlink($p); }
            }
            if (!move_uploaded_file($tmp, "$dir/$enrollmentId.$ext")) {
                throw new RuntimeException('Could not save the uploaded image.');
            }
            flash_set('success', 'Profile picture updated.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(app_url('student/account.php'));
}

// Reload fresh profile (contact/email may have changed) for display.
$profile  = student_profile($db, $enrollmentId);
$photoUrl = student_photo_url($profile);
$flash    = flash_get();
$csrf     = csrf_token();
$fullName = trim((string) ($profile['firstname'] . ' ' . ($profile['middlename'] ? $profile['middlename'][0] . '. ' : '') . $profile['surname']));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Management | Student Portal</title>
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
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Settings</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Account Management</h1>
            <p class="text-slate-500 mt-1 text-sm">Update your contact details, photo, and password.</p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-2 gap-6">

            <!-- Personal info (read-only) + photo -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-4">Personal Information</h2>
                <div class="flex items-center gap-4 mb-5">
                    <?php if ($photoUrl): ?>
                    <img src="<?= h($photoUrl) ?>" alt="" class="w-20 h-20 rounded-2xl object-cover border-2 border-green-200">
                    <?php else: ?>
                    <div class="w-20 h-20 rounded-2xl bg-green-600 text-white flex items-center justify-center text-2xl font-extrabold"><?= h(strtoupper(substr((string) $profile['firstname'],0,1).substr((string) $profile['surname'],0,1))) ?></div>
                    <?php endif; ?>
                    <form method="POST" action="account.php" enctype="multipart/form-data" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="upload_photo">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Profile Picture (JPG/PNG/WEBP, ≤3MB)</label>
                        <input type="file" name="photo" accept="image/*" required class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-green-50 file:text-green-700 file:font-semibold">
                        <button class="mt-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-4 py-2">Upload Photo</button>
                    </form>
                </div>
                <dl class="text-sm divide-y divide-slate-100">
                    <?php
                    $ro = [
                        'Full Name' => $fullName, 'LRN' => $profile['lrn'], 'Sex' => $profile['sex'],
                        'Grade Level' => $profile['grade_name'], 'Section' => $profile['section_name'],
                        'Department' => $profile['Department'], 'Classification' => $profile['classification_name'] ?: '—',
                        'School Year' => $profile['school_year'], 'Status' => $profile['Status'],
                    ];
                    foreach ($ro as $k => $v): ?>
                    <div class="flex justify-between py-2"><dt class="text-slate-500"><?= h($k) ?></dt><dd class="font-semibold text-right"><?= h((string) ($v ?: '—')) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <p class="text-xs text-slate-400 mt-3">Name, LRN, grade and section are managed by the registrar and cannot be changed here.</p>
            </section>

            <div class="space-y-6">
                <!-- Contact info -->
                <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-4">Contact Details</h2>
                    <form method="POST" action="account.php" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="update_contact">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Contact Number</label>
                            <input type="text" name="contact" value="<?= h((string) $profile['contact']) ?>" placeholder="e.g. 0917…"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Email Address</label>
                            <input type="email" name="email" value="<?= h((string) $profile['email']) ?>" placeholder="you@example.com"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Save Contact Info</button>
                    </form>
                </section>

                <!-- Change password -->
                <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-4">Change Password</h2>
                    <form method="POST" action="account.php" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Current Password</label>
                            <input type="password" name="current_password" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1.5">New Password</label>
                                <input type="password" name="new_password" required minlength="6" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1.5">Confirm Password</label>
                                <input type="password" name="confirm_password" required minlength="6" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            </div>
                        </div>
                        <button class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-5 py-2.5">Update Password</button>
                    </form>
                </section>
            </div>
        </div>
    </main>
</div>
</body>
</html>
