<?php
/**
 * Shared registrar sidebar.
 * Expects $user, $activeSchoolYearLabel, and $connection to be in scope.
 * Expects h() and app_url() to be loaded.
 */
$_sidebarPage = basename($_SERVER['PHP_SELF'] ?? '');
function _reg_nav_link(string $page, string $href, string $label, string $icon, string $current): string
{
    $active = ($current === $page);
    $base   = 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-colors ';
    $cls    = $active
        ? $base . 'bg-white/15 border border-white/20 text-white font-semibold'
        : $base . 'text-slate-300 hover:bg-white/10 hover:text-white';
    return '<a href="' . h($href) . '" class="' . $cls . '">' . $icon . h($label) . '</a>';
}
$_reg_icons = [
    'index.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'masterlist.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>',
    'manage_enrollment.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
    'users.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'reprint_schedule.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>',
    'student_manage.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'promissory.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'student_records.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
    'grading_periods.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>',
];
?>
<aside class="bg-[#0a3a1e] text-slate-100 flex flex-col p-6 lg:p-7" style="background:linear-gradient(180deg,#0a3a1e 0%,#052815 100%)">
    <!-- Logo + brand -->
    <div class="flex items-center gap-3 mb-8">
        <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-10 h-10 object-contain">
        <div>
            <p class="text-[10px] uppercase tracking-widest text-amber-300 font-semibold">ITFA System</p>
            <p class="text-base font-extrabold leading-tight">Registrar Module</p>
        </div>
    </div>

    <nav class="space-y-1 flex-1">
        <?= _reg_nav_link('index.php',             app_url('registrar/index.php'),             'Enrollment Confirmation', $_reg_icons['index.php'],             $_sidebarPage) ?>
        <?= _reg_nav_link('masterlist.php',        app_url('registrar/masterlist.php'),        'Masterlist',              $_reg_icons['masterlist.php'],        $_sidebarPage) ?>
        <?= _reg_nav_link('manage_enrollment.php',  app_url('registrar/manage_enrollment.php'),  'Record Management',       $_reg_icons['manage_enrollment.php'],  $_sidebarPage) ?>
        <?= _reg_nav_link('reprint_schedule.php',    app_url('registrar/reprint_schedule.php'),    'Reprint Schedule',        $_reg_icons['reprint_schedule.php'],    $_sidebarPage) ?>
        <?= _reg_nav_link('student_manage.php',      app_url('depthead/student_manage.php'),       'Student Management',      $_reg_icons['student_manage.php'],      $_sidebarPage) ?>
        <?= _reg_nav_link('promissory.php',          app_url('registrar/promissory.php'),          'Promissory Notes',        $_reg_icons['promissory.php'],          $_sidebarPage) ?>
        <?= _reg_nav_link('student_records.php',     app_url('registrar/student_records.php'),     'Student Records',         $_reg_icons['student_records.php'],     $_sidebarPage) ?>
        <?= _reg_nav_link('grading_periods.php',     app_url('registrar/grading_periods.php'),     'Grading Periods',         $_reg_icons['grading_periods.php'],     $_sidebarPage) ?>

        <a href="<?= h(app_url('logout.php')) ?>"
           class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-300 hover:bg-white/10 hover:text-white transition-colors mt-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            Logout
        </a>
    </nav>

    <!-- User card -->
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 mt-6">
        <p class="text-xs text-slate-400">Logged in as</p>
        <p class="font-semibold mt-0.5"><?= h((string) ($user['full_name'] ?? 'Registrar')) ?></p>
        <p class="text-xs text-amber-300 mt-0.5"><?= h((string) ($user['role'] ?? 'Registrar')) ?></p>
        <div class="mt-3 pt-3 border-t border-white/10">
            <p class="text-xs text-slate-400">Active S.Y.</p>
            <p class="font-semibold text-sm mt-0.5"><?= h($activeSchoolYearLabel) ?></p>
        </div>
    </div>
</aside>
