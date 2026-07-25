<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';

$teacher = require_teacher_login();

$db      = db();
$user    = current_user();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syLabel = $sy['label'];

// Uploads: validated image → uploads/teacher_{photos|signatures}/{Teacher_id}.{ext}
function teacher_store_image(array $file, string $kind, int $teacherId): void
{
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please choose an image to upload.');
    }
    if ((int) $file['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('Image must be 3 MB or smaller.');
    }
    $info    = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
    }
    $ext = $allowed[$info[2]];
    $dir = dirname(__DIR__) . '/uploads/' . ($kind === 'signature' ? 'teacher_signatures' : 'teacher_photos');
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $old) {
        $p = "$dir/$teacherId.$old";
        if (is_file($p)) { @unlink($p); }
    }
    if (!move_uploaded_file($file['tmp_name'], "$dir/$teacherId.$ext")) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('account.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'update_info') {
            teacher_update_profile($db, $tid,
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['contact'] ?? ''),
                (string) ($_POST['address'] ?? ''));
            flash_set('success', 'Your information has been updated.');
        } elseif ($action === 'upload_photo') {
            teacher_store_image($_FILES['photo'] ?? [], 'photo', $tid);
            flash_set('success', 'Profile picture updated.');
        } elseif ($action === 'upload_signature') {
            teacher_store_image($_FILES['signature'] ?? [], 'signature', $tid);
            flash_set('success', 'Signature saved — it will appear as your adviser signature on grade slips.');
        } elseif ($action === 'remove_signature') {
            $dir = dirname(__DIR__) . '/uploads/teacher_signatures';
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $e) { @unlink("$dir/$tid.$e"); }
            flash_set('success', 'Signature removed.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('account.php');
}

// Reload live row for display.
$teacher   = teacher_by_user_id($db, (int) $user['id']) ?? $teacher;
$advStmt   = $db->prepare(
    'SELECT sec.Section_name, gl.Gradelevel FROM advisory_class a
     LEFT JOIN section sec ON sec.Section_id = a.section_id
     LEFT JOIN gradelevel gl ON gl.Gradelevel_id = a.gradelevel_id
     WHERE a.teacher_id = ? AND a.school_year_id = ? LIMIT 1'
);
$advStmt->bind_param('ii', $tid, $sy['id']);
$advStmt->execute();
$advisory = stmt_fetch_assoc($advStmt);

$photoUrl = teacher_image_url($tid, 'photo');
$sigUrl   = teacher_image_url($tid, 'signature');
$flash    = flash_get();
$csrf     = csrf_token();
$name     = teacher_display_name($teacher);
$initials = strtoupper(mb_substr((string) ($teacher['Firstname'] ?: 'T'), 0, 1) . mb_substr((string) ($teacher['Lastname'] ?: ''), 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Account | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(22,101,52,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(22,101,52,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-green-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Teacher · Account</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">My Account</h1>
            <p class="text-slate-500 mt-1 text-sm">Update your details, profile picture, and signature. Your signature appears as the <b>Class Adviser</b> signature on your advisory students&rsquo; grade slips and certificates.</p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-green-50 border-green-300 text-green-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-2 gap-6">

            <!-- Identity + photo -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-4">Profile</h2>
                <div class="flex items-center gap-4 mb-5">
                    <?php if ($photoUrl): ?>
                    <img src="<?= h($photoUrl) ?>" alt="" class="w-20 h-20 rounded-2xl object-cover border-2 border-green-200">
                    <?php else: ?>
                    <div class="w-20 h-20 rounded-2xl bg-green-700 text-white flex items-center justify-center text-2xl font-extrabold"><?= h($initials) ?></div>
                    <?php endif; ?>
                    <form method="POST" action="account.php" enctype="multipart/form-data" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="upload_photo">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Profile Picture (JPG/PNG/WEBP, ≤3MB)</label>
                        <input type="file" name="photo" accept="image/*" required class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-green-50 file:text-green-700 file:font-semibold">
                        <button class="mt-2 rounded-lg bg-green-700 hover:bg-green-800 text-white text-xs font-bold px-4 py-2">Upload Photo</button>
                    </form>
                </div>
                <dl class="text-sm divide-y divide-slate-100">
                    <?php
                    $ro = [
                        'Full Name'   => $name,
                        'Designation' => $teacher['Designation'] ?: '—',
                        'Employee No.'=> $teacher['employee_no'] ?: '—',
                        'Advisory'    => $advisory ? (($advisory['Gradelevel'] ?? '') . ' — ' . ($advisory['Section_name'] ?? '')) : 'Not an adviser this S.Y.',
                        'School Year' => $syLabel,
                    ];
                    foreach ($ro as $k => $v): ?>
                    <div class="flex justify-between py-2"><dt class="text-slate-500"><?= h($k) ?></dt><dd class="font-semibold text-right"><?= h((string) ($v ?: '—')) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <p class="text-xs text-slate-400 mt-3">Name, designation and advisory are managed by the Department Head.</p>
            </section>

            <div class="space-y-6">
                <!-- Contact info -->
                <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                    <h2 class="font-extrabold text-lg mb-4">Contact &amp; Additional Info</h2>
                    <form method="POST" action="account.php" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="update_info">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Email Address</label>
                            <input type="email" name="email" value="<?= h((string) ($teacher['email'] ?? '')) ?>" placeholder="you@example.com"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Contact Number</label>
                            <input type="text" name="contact" value="<?= h((string) ($teacher['contact'] ?? '')) ?>" placeholder="e.g. 0917…"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Address</label>
                            <input type="text" name="address" value="<?= h((string) ($teacher['address'] ?? '')) ?>" placeholder="Purok / Barangay, Municipality"
                                   class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        <button class="rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-bold px-5 py-2.5">Save Info</button>
                    </form>
                </section>

                <!-- Password shortcut -->
                <a href="<?= h(app_url('teacher/change_password.php')) ?>" class="block bg-white rounded-3xl border border-green-100 shadow-panel p-5 hover:border-green-300 transition-colors">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-lg bg-green-50 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-green-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>
                        </span>
                        <div><p class="font-bold text-sm">Change Password</p><p class="text-xs text-slate-400">Update your login password.</p></div>
                        <svg class="w-4 h-4 text-slate-400 ml-auto" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </a>
            </div>

            <!-- Signature — full width -->
            <section class="lg:col-span-2 bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                    <div>
                        <h2 class="font-extrabold text-lg">Adviser Signature</h2>
                        <p class="text-sm text-slate-500 mt-0.5">Upload a clear image of your signature (a transparent PNG works best). It is printed above the <b>Class Adviser</b> line on your advisory students&rsquo; grade slips and certificates.</p>
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Current signature</p>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 h-40 flex items-center justify-center p-4">
                            <?php if ($sigUrl): ?>
                            <img src="<?= h($sigUrl) ?>" alt="Signature" class="max-h-28 max-w-full object-contain">
                            <?php else: ?>
                            <p class="text-sm text-slate-400">No signature uploaded yet.</p>
                            <?php endif; ?>
                        </div>
                        <?php if ($sigUrl): ?>
                        <form method="POST" action="account.php" class="mt-2" onsubmit="return confirm('Remove your signature?');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="remove_signature">
                            <button class="text-xs font-bold text-rose-600 hover:underline">Remove signature</button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2"><?= $sigUrl ? 'Replace signature' : 'Upload signature' ?></p>
                        <form method="POST" action="account.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="upload_signature">
                            <input type="file" name="signature" accept="image/*" required
                                   class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-green-50 file:text-green-700 file:font-semibold mb-3">
                            <button class="rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-bold px-5 py-2.5">Save Signature</button>
                        </form>
                        <ul class="text-xs text-slate-400 mt-4 space-y-1 list-disc list-inside">
                            <li>Sign on white paper and photograph or scan it.</li>
                            <li>PNG with a transparent background looks cleanest.</li>
                            <li>Max size 3&nbsp;MB. JPG, PNG or WEBP.</li>
                        </ul>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
