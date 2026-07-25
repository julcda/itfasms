<?php
/**
 * Shared student-portal sidebar.
 * Expects in scope: $profile (live student_profile row), $photoUrl (string|null).
 * Expects h() and app_url() loaded.
 */
$_spage = basename($_SERVER['PHP_SELF'] ?? '');
function _st_nav(string $page, string $href, string $label, string $icon, string $current, bool $soon = false): string
{
    $active = ($current === $page);
    $base = 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-colors ';
    $cls  = $active
        ? $base . 'bg-white/15 border border-white/20 text-white font-semibold'
        : $base . 'text-slate-300 hover:bg-white/10 hover:text-white';
    $badge = $soon ? '<span class="ml-auto text-[9px] uppercase tracking-wide bg-amber-400/20 text-amber-200 px-1.5 py-0.5 rounded">Soon</span>' : '';
    return '<a href="' . h($href) . '" class="' . $cls . '">' . $icon . '<span>' . h($label) . '</span>' . $badge . '</a>';
}
$_si = [
    'index'   => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
    'soa'     => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'account' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    'grades'  => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42a12 12 0 01.84 4.42c0 1.1-.9 2-2 2H7c-1.1 0-2-.9-2-2 0-1.55.3-3.04.84-4.42L12 14z"/></svg>',
];
$_name   = trim((string) (($profile['firstname'] ?? '') . ' ' . ($profile['surname'] ?? ''))) ?: 'Student';
$_initials = strtoupper(substr((string) ($profile['firstname'] ?? 'S'), 0, 1) . substr((string) ($profile['surname'] ?? ''), 0, 1));
?>
<aside class="bg-[#0a3a1e] text-slate-100 flex flex-col p-6 lg:p-7" style="background:linear-gradient(180deg,#0a3a1e 0%,#052815 100%)">
    <div class="flex items-center gap-3 mb-8">
        <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-10 h-10 object-contain">
        <div>
            <p class="text-[10px] uppercase tracking-widest text-amber-300 font-semibold">ITFA System</p>
            <p class="text-base font-extrabold leading-tight">Student Portal</p>
        </div>
    </div>

    <nav class="space-y-1 flex-1">
        <?= _st_nav('index.php',   app_url('student/index.php'),   'Dashboard',           $_si['index'],   $_spage) ?>
        <?= _st_nav('soa.php',     app_url('student/soa.php'),     'Statement of Account',$_si['soa'],     $_spage) ?>
        <?= _st_nav('account.php', app_url('student/account.php'), 'Account Management',  $_si['account'], $_spage) ?>
        <?= _st_nav('certificate.php', app_url('student/certificate.php'), 'Certificates', $_si['grades'], $_spage) ?>
        <?= _st_nav('grades.php',  app_url('student/grades.php'),  'Grades',              $_si['grades'],  $_spage) ?>

        <a href="<?= h(app_url('student/logout.php')) ?>"
           class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-300 hover:bg-white/10 hover:text-white transition-colors mt-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>

    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 mt-6 flex items-center gap-3">
        <?php if (!empty($photoUrl)): ?>
        <img src="<?= h($photoUrl) ?>" alt="" class="w-11 h-11 rounded-full object-cover border border-white/20">
        <?php else: ?>
        <div class="w-11 h-11 rounded-full bg-amber-500/30 border border-white/20 flex items-center justify-center text-sm font-bold text-white"><?= h($_initials) ?></div>
        <?php endif; ?>
        <div class="min-w-0">
            <p class="font-semibold text-sm truncate"><?= h($_name) ?></p>
            <p class="text-xs text-amber-300 truncate">LRN: <?= h((string) ($profile['lrn'] ?? '')) ?></p>
        </div>
    </div>
</aside>
