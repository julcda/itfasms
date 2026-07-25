<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/teacher_auth.php';

$teacher = require_teacher_login();

$db      = db();
$user    = current_user();
$tid     = (int) $teacher['Teacher_id'];
$sy      = teacher_active_sy($db);
$syId    = (int) $sy['id'];
$syLabel = $sy['label'];

/** Resolve enrollment_id for one advisee (needed for photo actions), or 0. */
function advisee_enrollment_id(mysqli $db, int $studentId, string $syLabel): int
{
    $row = teacher_advisee_get($db, $studentId);
    if (!$row) { return 0; }
    $p = teacher_advisee_profile($db, (string) ($row['LRN_no'] ?? ''), $syLabel);
    return (int) ($p['enrollment_id'] ?? 0);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to('advisees.php');
    }
    $sid    = to_int($_POST['student_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'update') {
            teacher_update_advisee($db, $sid, $tid, $syId, $syLabel, [
                'Lastname'     => $_POST['Lastname']     ?? '',
                'Firstname'    => $_POST['Firstname']    ?? '',
                'Middlename'   => $_POST['Middlename']   ?? '',
                'LRN_no'       => $_POST['LRN_no']       ?? '',
                'contact'      => $_POST['contact']      ?? '',
                'email'        => $_POST['email']        ?? '',
                'province'     => $_POST['province']     ?? '',
                'municipality' => $_POST['municipality'] ?? '',
                'barangay'     => $_POST['barangay']     ?? '',
            ], $user);
            flash_set('success', 'Student information updated.');
            redirect_to('advisees.php');
        } elseif ($action === 'upload_photo' || $action === 'remove_photo') {
            if (!teacher_advises_student($db, $sid, $tid, $syId)) {
                throw new RuntimeException('That student is not one of your advisees.');
            }
            $eid = advisee_enrollment_id($db, $sid, $syLabel);
            if ($action === 'upload_photo') {
                teacher_store_advisee_photo($_FILES['photo'] ?? [], $eid);
                flash_set('success', 'Student photo updated. It now appears on the student portal too.');
            } else {
                teacher_remove_advisee_photo($eid);
                flash_set('success', 'Student photo removed.');
            }
            redirect_to('advisees.php?edit=' . $sid);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('advisees.php?edit=' . $sid);
    }
    redirect_to('advisees.php');
}

$advisory = teacher_advisory($db, $tid, $syId);
$search   = trim((string) ($_GET['q'] ?? ''));
$advisees = $advisory ? teacher_advisees($db, $tid, $syId, $search) : [];

// Edit target — must be one of this adviser's own advisees, or we drop it.
$editId      = to_int($_GET['edit'] ?? 0);
$editRow     = null;
$editProfile = null;
$photoUrl    = null;
if ($editId > 0 && teacher_advises_student($db, $editId, $tid, $syId)) {
    $editRow     = teacher_advisee_get($db, $editId);
    if ($editRow) {
        $editProfile = teacher_advisee_profile($db, (string) ($editRow['LRN_no'] ?? ''), $syLabel);
        $eid         = (int) ($editProfile['enrollment_id'] ?? 0);
        $photoUrl    = $eid > 0 ? teacher_advisee_photo_url($eid, (string) ($editProfile['photo'] ?? '')) : null;
    }
}

$flash = flash_get();
$csrf  = csrf_token();

// Small helper for read-only rows.
$pv = fn(string $k): string => trim((string) ($editProfile[$k] ?? '')) !== '' ? (string) $editProfile[$k] : '—';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Advisees | ITFA Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] }, boxShadow: { panel: '0 18px 40px -20px rgba(5,150,105,0.25)' } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.10),_rgba(241,245,249,0.86)_45%,_rgba(241,245,249,1)_78%)]">

        <header class="bg-white/90 backdrop-blur rounded-3xl border border-emerald-100 shadow-panel p-6 mb-6">
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-700 font-semibold">Teacher · Teaching</p>
            <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">My Advisees</h1>
            <p class="text-slate-500 mt-1 text-sm">Students in your advisory section. You can correct a student&rsquo;s <b>name, LRN, contact, address</b> and upload their <b>photo</b> — corrections update the record the cashier and student portal share.</p>
            <p class="text-xs text-emerald-700 mt-2">
                <?php if ($advisory): ?>
                Advisory: <b><?= h((string) ($advisory['Gradelevel'] ?? '')) ?> — <?= h((string) ($advisory['Section_name'] ?? '')) ?></b> · S.Y. <?= h($syLabel) ?>
                <?php else: ?>
                S.Y. <?= h($syLabel) ?>
                <?php endif; ?>
            </p>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$advisory): ?>
        <div class="rounded-3xl bg-white border border-slate-200 shadow-panel p-10 text-center text-slate-400">
            <p class="font-semibold text-slate-500">You are not assigned as a class adviser for S.Y. <?= h($syLabel) ?>.</p>
            <p class="text-sm mt-1">Advisee management is only available to class advisers. Please contact your Department Head if this is unexpected.</p>
        </div>
        <?php else: ?>

        <?php if ($editRow):
            $editName = trim((string) $editRow['Lastname'] . ', ' . (string) $editRow['Firstname']);
            $ini = strtoupper(mb_substr((string) ($editRow['Firstname'] ?: 'S'), 0, 1) . mb_substr((string) ($editRow['Lastname'] ?: ''), 0, 1));
        ?>
        <!-- Edit panel -->
        <section class="bg-white rounded-3xl border-2 border-emerald-200 shadow-panel p-6 mb-6">
            <div class="flex items-start justify-between gap-3 mb-5">
                <div>
                    <h2 class="font-extrabold text-lg">Correct student information</h2>
                    <p class="text-sm text-slate-500 mt-0.5">Editing <b><?= h($editName) ?></b>. Type names exactly as they should appear — capitalization is kept as entered.</p>
                </div>
                <a href="advisees.php" class="text-sm text-slate-500 hover:underline whitespace-nowrap">← Back to list</a>
            </div>

            <?php if (!$editProfile): ?>
            <div class="mb-5 rounded-2xl bg-amber-50 border border-amber-300 px-4 py-3 text-sm text-amber-900">
                This student&rsquo;s masterlist entry isn&rsquo;t linked to an enrollment profile for S.Y. <?= h($syLabel) ?>, so only their <b>name and LRN</b> can be corrected here (and no photo can be attached). Ask the Registrar to check the enrollment if contact/address should be editable.
            </div>
            <?php endif; ?>

            <div class="grid lg:grid-cols-[220px_1fr] gap-6">

                <!-- Photo -->
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Student Photo</p>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 aspect-square flex items-center justify-center overflow-hidden">
                        <?php if ($photoUrl): ?>
                        <img src="<?= h($photoUrl) ?>" alt="" class="w-full h-full object-cover">
                        <?php else: ?>
                        <div class="w-24 h-24 rounded-2xl bg-emerald-700 text-white flex items-center justify-center text-3xl font-extrabold"><?= h($ini) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($editProfile): ?>
                    <form method="POST" action="advisees.php" enctype="multipart/form-data" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="upload_photo">
                        <input type="hidden" name="student_id" value="<?= (int) $editRow['student_id'] ?>">
                        <input type="file" name="photo" accept="image/*" required
                               class="block w-full text-xs text-slate-600 file:mr-2 file:py-1.5 file:px-2.5 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
                        <button class="mt-2 w-full rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold px-3 py-2">Upload Photo</button>
                    </form>
                    <?php if ($photoUrl): ?>
                    <form method="POST" action="advisees.php" class="mt-1.5" onsubmit="return confirm('Remove this student\'s photo?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="remove_photo">
                        <input type="hidden" name="student_id" value="<?= (int) $editRow['student_id'] ?>">
                        <button class="text-xs font-bold text-rose-600 hover:underline">Remove photo</button>
                    </form>
                    <?php endif; ?>
                    <p class="text-[11px] text-slate-400 mt-2">JPG/PNG/WEBP, ≤3&nbsp;MB. Shows on the student portal too.</p>
                    <?php endif; ?>
                </div>

                <!-- Editable fields -->
                <form method="POST" action="advisees.php" class="grid sm:grid-cols-2 gap-4 content-start">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="student_id" value="<?= (int) $editRow['student_id'] ?>">

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Last Name <span class="text-rose-500">*</span></label>
                        <input type="text" name="Lastname" required value="<?= h((string) ($editRow['Lastname'] ?? '')) ?>"
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">First Name <span class="text-rose-500">*</span></label>
                        <input type="text" name="Firstname" required value="<?= h((string) ($editRow['Firstname'] ?? '')) ?>"
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Middle Name</label>
                        <input type="text" name="Middlename" value="<?= h((string) ($editRow['Middlename'] ?? '')) ?>" placeholder="Leave blank if none"
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">LRN</label>
                        <input type="text" name="LRN_no" inputmode="numeric" value="<?= h((string) ($editRow['LRN_no'] ?? '')) ?>" placeholder="12-digit Learner Reference No."
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-mono focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Contact Number</label>
                        <input type="text" name="contact" value="<?= h((string) ($editProfile['contact'] ?? '')) ?>" placeholder="e.g. 0917…" <?= $editProfile ? '' : 'disabled' ?>
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-slate-50 disabled:text-slate-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Email</label>
                        <input type="email" name="email" value="<?= h((string) ($editProfile['email'] ?? '')) ?>" placeholder="student@example.com" <?= $editProfile ? '' : 'disabled' ?>
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-slate-50 disabled:text-slate-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Province</label>
                        <input type="text" name="province" value="<?= h((string) ($editProfile['province'] ?? '')) ?>" <?= $editProfile ? '' : 'disabled' ?>
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-slate-50 disabled:text-slate-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Municipality / City</label>
                        <input type="text" name="municipality" value="<?= h((string) ($editProfile['municipality'] ?? '')) ?>" <?= $editProfile ? '' : 'disabled' ?>
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-slate-50 disabled:text-slate-400">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Barangay</label>
                        <input type="text" name="barangay" value="<?= h((string) ($editProfile['barangay'] ?? '')) ?>" <?= $editProfile ? '' : 'disabled' ?>
                               class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-slate-50 disabled:text-slate-400">
                    </div>

                    <div class="sm:col-span-2 flex items-center gap-3 pt-1">
                        <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5">Save Correction</button>
                        <a href="advisees.php" class="text-sm font-semibold text-slate-500 hover:text-slate-700">Cancel</a>
                    </div>
                </form>
            </div>

            <!-- Read-only reference -->
            <div class="mt-6 pt-5 border-t border-slate-100">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">For reference — managed by the Registrar</p>
                <dl class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2 text-sm">
                    <?php
                    $ro = [
                        'Grade & Section' => trim((string) ($editRow['Gradelevel'] ?? '')) !== '' ? ($advisory['Gradelevel'] . ' — ' . $advisory['Section_name']) : '—',
                        'Department'      => (string) ($editRow['Department'] ?? '—'),
                        'Sex'             => $pv('sex'),
                        'Birthdate'       => $pv('birthdate'),
                        'Birthplace'      => $pv('birthplace'),
                        'Previous School' => $pv('previous_school'),
                        'Year Graduated'  => $pv('year_graduated'),
                        'Father'          => $pv('father_name'),
                        'Father Contact'  => $pv('father_contact'),
                        'Mother'          => $pv('mother_name'),
                        'Mother Contact'  => $pv('mother_contact'),
                        'Parent Address'  => $pv('parent_address'),
                    ];
                    foreach ($ro as $k => $v): ?>
                    <div class="flex justify-between gap-3 py-1 border-b border-slate-50">
                        <dt class="text-slate-400"><?= h($k) ?></dt>
                        <dd class="font-semibold text-right text-slate-700"><?= h((string) $v) ?></dd>
                    </div>
                    <?php endforeach; ?>
                </dl>
                <p class="text-xs text-slate-400 mt-3">Birthdate, sex, parent/guardian details, grade level, section and enrollment status are corrected by the Registrar.</p>
            </div>
        </section>
        <?php endif; ?>

        <!-- Search -->
        <form method="GET" action="advisees.php" class="mb-5 flex flex-wrap gap-3">
            <div class="relative flex-1 min-w-[240px]">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-emerald-600">🔍</span>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search advisees by name or LRN…"
                       class="w-full rounded-2xl border-2 border-slate-200 pl-11 pr-4 py-3 text-sm font-medium focus:outline-none focus:border-emerald-500">
            </div>
            <button class="rounded-2xl bg-emerald-700 hover:bg-emerald-800 text-white px-6 py-3 text-sm font-bold">Search</button>
            <?php if ($search !== ''): ?><a href="advisees.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a><?php endif; ?>
        </form>

        <section class="bg-white rounded-3xl border border-emerald-100 shadow-panel overflow-hidden">
            <?php if (!$advisees): ?>
            <div class="p-10 text-center text-slate-400">
                <p class="font-semibold"><?= $search !== '' ? 'No advisees matched “' . h($search) . '”.' : 'No students are enrolled in your advisory section yet.' ?></p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left px-6 py-3 w-10">#</th>
                            <th class="text-left">LRN</th>
                            <th class="text-left">Student Name</th>
                            <th class="text-center">Status</th>
                            <th class="text-right px-6">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($advisees as $i => $r):
                        $sid  = (int) $r['student_id'];
                        $name = trim((string) $r['Lastname'] . ', ' . (string) $r['Firstname'] . ' ' . (string) ($r['Middlename'] ?? ''));
                    ?>
                        <tr class="hover:bg-emerald-50/30 <?= $editRow && (int) $editRow['student_id'] === $sid ? 'bg-emerald-50/60' : '' ?>">
                            <td class="px-6 py-2.5 text-slate-400"><?= $i + 1 ?></td>
                            <td class="font-mono text-xs text-slate-500"><?= h((string) ($r['LRN_no'] ?: '—')) ?></td>
                            <td class="font-semibold"><?= h($name) ?></td>
                            <td class="text-center text-xs text-slate-500"><?= h((string) ((int) $r['Status'] === 1 ? 'Enrolled' : ($r['Status'] ?: '—'))) ?></td>
                            <td class="text-right px-6">
                                <a href="advisees.php?edit=<?= $sid ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                                   class="text-xs font-bold text-emerald-700 hover:underline">Correct info</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500">
                <b class="text-slate-800"><?= count($advisees) ?></b> advisee<?= count($advisees) === 1 ? '' : 's' ?>.
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
