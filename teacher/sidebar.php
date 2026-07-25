<?php
/**
 * Shared teacher sidebar.
 * Expects $teacher (the teacher row) and $syLabel in scope; h()/app_url() loaded.
 */
$_page  = basename($_SERVER['PHP_SELF'] ?? '');
$_sy    = $syLabel ?? ($activeSchoolYearLabel ?? '—');
$_tname = function_exists('teacher_display_name') ? teacher_display_name($teacher ?? []) : 'Teacher';

function _t_nav(string $page, string $href, string $label, string $icon, string $current): string
{
    $active = ($current === $page);
    $base   = 'flex items-center gap-3 px-4 py-2 rounded-xl text-sm font-medium transition-colors ';
    $cls    = $active
        ? $base . 'bg-white/15 border border-white/20 text-white font-semibold'
        : $base . 'text-slate-300 hover:bg-white/10 hover:text-white';
    return '<a href="' . h($href) . '" class="' . $cls . '">' . $icon . h($label) . '</a>';
}
function _t_head(string $label): string
{
    return '<p class="px-4 pt-3 pb-1 text-[10px] uppercase tracking-widest text-amber-300/70 font-semibold">' . h($label) . '</p>';
}
$_ti = [
    'index.php'    => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
    'classes.php'  => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>',
    'schedule.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
    'change_password.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>',
];
?>
<aside class="bg-[#0a3a1e] text-slate-100 flex flex-col p-6 lg:p-7" style="background:linear-gradient(180deg,#0f4d28 0%,#052815 100%)">
    <div class="flex items-center gap-3 mb-8">
        <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-10 h-10 object-contain">
        <div>
            <p class="text-[10px] uppercase tracking-widest text-amber-300 font-semibold">ITFA System</p>
            <p class="text-base font-extrabold leading-tight">Teacher Module</p>
        </div>
    </div>

    <div class="rounded-2xl bg-white/10 border border-white/15 px-4 py-3 mb-5">
        <p class="text-sm font-bold leading-tight"><?= h($_tname) ?></p>
        <p class="text-[11px] text-amber-200/80 mt-0.5">S.Y. <?= h((string) $_sy) ?></p>
    </div>

    <nav class="space-y-1 flex-1">
        <?= _t_nav('index.php',    app_url('teacher/index.php'),    'Dashboard',  $_ti['index.php'],    $_page) ?>

        <?= _t_head('Teaching') ?>
        <?= _t_nav('classes.php',  app_url('teacher/classes.php'),  'My Classes', $_ti['classes.php'],  $_page) ?>
        <?= _t_nav('advisees.php', app_url('teacher/advisees.php'), 'My Advisees', '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-2.8-4"/></svg>', $_page) ?>
        <?= _t_nav('schedule.php', app_url('teacher/schedule.php'), 'My Schedule', $_ti['schedule.php'], $_page) ?>
        <a href="<?= h(app_url('teacher/classroom.php')) ?>" target="_blank" rel="noopener"
           class="flex items-center gap-3 px-4 py-2 rounded-xl text-sm font-medium text-slate-300 hover:bg-white/10 hover:text-white transition-colors">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Classroom
            <span class="ml-auto text-[9px] uppercase tracking-wide bg-emerald-400/20 text-emerald-200 px-1.5 py-0.5 rounded">New</span>
        </a>

        <?= _t_nav('certificates.php', app_url('teacher/certificates.php'), 'Certificates', '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>', $_page) ?>

        <?= _t_head('Staff Room') ?>
        <?php
        // Unread badge. Computed here so every teacher page shows it consistently.
        $_unread = 0;
        if (function_exists('msg_unread_total')) {
            $_unread = msg_unread_total(db(), (int) (current_user()['id'] ?? 0));
        }
        $_msgActive = $_page === 'messages.php';
        ?>
        <a href="<?= h(app_url('teacher/messages.php')) ?>"
           class="flex items-center gap-3 px-4 py-2 rounded-xl text-sm font-medium transition-colors <?= $_msgActive ? 'bg-white/15 border border-white/20 text-white font-semibold' : 'text-slate-300 hover:bg-white/10 hover:text-white' ?>">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            Messages
            <?php if ($_unread > 0): ?>
            <span class="ml-auto text-[10px] font-extrabold bg-rose-500 text-white rounded-full px-2 py-0.5"><?= $_unread ?></span>
            <?php endif; ?>
        </a>

        <?= _t_head('Account') ?>
        <?= _t_nav('account.php', app_url('teacher/account.php'), 'My Account', '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>', $_page) ?>
        <?= _t_nav('change_password.php', app_url('teacher/change_password.php'), 'Change Password', $_ti['change_password.php'], $_page) ?>
    </nav>

    <a href="<?= h(app_url('logout.php')) ?>"
       class="mt-6 flex items-center gap-3 px-4 py-2 rounded-xl text-sm font-semibold bg-white/10 hover:bg-white/20 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        Sign out
    </a>
</aside>
