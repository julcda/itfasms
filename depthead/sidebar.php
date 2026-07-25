<?php
/**
 * Shared sidebar for the Department Head / Principal module.
 * Expects $user, $activeSchoolYearLabel to be in scope.
 * Expects h() and app_url() to be loaded.
 */
$_sidebarPage = basename($_SERVER['PHP_SELF'] ?? '');
function _dh_nav_link(string $page, string $href, string $label, string $icon, string $current): string
{
    $active = ($current === $page);
    $base   = 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-colors ';
    $cls    = $active
        ? $base . 'bg-white/15 border border-white/20 text-white font-semibold'
        : $base . 'text-slate-300 hover:bg-white/10 hover:text-white';
    return '<a href="' . h($href) . '" class="' . $cls . '">' . $icon . h($label) . '</a>';
}
$_dh_icons = [
    'index.php'  => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
    'preview.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>',
    'manage.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'users.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'students.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 8h3m-1.5-1.5v3"/></svg>',
    'teacher_load.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>',
    'houses.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
    'sectioning.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>',
    'grade_review.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>',
    'teachers.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a3 3 0 00-5.356-1.857M17 20H7m10 0v-1c0-.656-.126-1.283-.356-1.857M7 20H2v-1a3 3 0 015.356-1.857M7 20v-1c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
    'advisers.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
    'certificates.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>',
    'accounts.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>',
    'madrasah.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l8 4v2H4V7l8-4zM6 9v8m4-8v8m4-8v8m4-8v8M4 21h16"/></svg>',
    'teacher_loads.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
];
?>
<aside class="bg-[#0a3a1e] text-slate-100 flex flex-col p-6 lg:p-7" style="background:linear-gradient(180deg,#0a3a1e 0%,#052815 100%)">
    <!-- Logo + brand -->
    <div class="flex items-center gap-3 mb-8">
        <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-10 h-10 object-contain">
        <div>
            <p class="text-[10px] uppercase tracking-widest text-amber-300 font-semibold">ITFA System</p>
            <p class="text-base font-extrabold leading-tight">Dept Head Portal</p>
        </div>
    </div>

    <nav class="space-y-1 flex-1">
        <?= _dh_nav_link('index.php', app_url('depthead/index.php'), 'Manage Schedules', $_dh_icons['index.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('preview.php', app_url('depthead/preview.php'), 'Preview Schedule', $_dh_icons['preview.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('teacher_load.php', app_url('depthead/teacher_load.php'), 'Teaching Loads', $_dh_icons['teacher_load.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('grade_review.php', app_url('depthead/grade_review.php'), 'Grade Review', $_dh_icons['grade_review.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('advisers.php', app_url('depthead/advisers.php'), 'Class Advisers', $_dh_icons['advisers.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('certificates.php', app_url('depthead/certificates.php'), 'Certificates', $_dh_icons['certificates.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('teachers.php', app_url('depthead/teachers.php'), 'Teacher Management', $_dh_icons['teachers.php'], $_sidebarPage) ?>
        <?php if (is_depthead_user() || is_depthead_admin() || is_super_admin()): ?>
        <?= _dh_nav_link('manage.php', app_url('depthead/manage.php'), 'Management', $_dh_icons['manage.php'], $_sidebarPage) ?>
        <?php endif; ?>

        <?php if (is_depthead_admin() || is_super_admin()): ?>
        <p class="px-4 pt-4 pb-1 text-[10px] uppercase tracking-widest text-amber-300/70 font-semibold">Admin Reports</p>
        <?= _dh_nav_link('houses.php', app_url('depthead/houses.php'), 'House Members', $_dh_icons['houses.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('sectioning.php', app_url('depthead/sectioning.php'), 'Sectioning', $_dh_icons['sectioning.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('madrasah.php', app_url('depthead/madrasah.php'), 'Madrasah Sections', $_dh_icons['madrasah.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('teacher_loads.php', app_url('depthead/teacher_loads.php'), 'Loads Summary', $_dh_icons['teacher_loads.php'], $_sidebarPage) ?>
        <?php endif; ?>
        <?php if (is_super_admin()): ?>
        <?= _dh_nav_link('student_manage.php', app_url('depthead/student_manage.php'), 'Student Management', $_dh_icons['manage.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('students.php', app_url('depthead/students.php'), 'Student Records', $_dh_icons['students.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('accounts.php', app_url('depthead/accounts.php'), 'Account Management', $_dh_icons['accounts.php'], $_sidebarPage) ?>
        <?= _dh_nav_link('users.php', app_url('depthead/users.php'), 'Create / Delete Users', $_dh_icons['users.php'], $_sidebarPage) ?>
        <?php endif; ?>

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
        <p class="font-semibold mt-0.5"><?= h((string) ($user['full_name'] ?? '')) ?></p>
        <p class="text-xs text-amber-300 mt-0.5"><?= h((string) ($user['username'] ?? $user['role'] ?? '')) ?></p>
        <div class="mt-3 pt-3 border-t border-white/10">
            <p class="text-xs text-slate-400">Active S.Y.</p>
            <p class="font-semibold text-sm mt-0.5"><?= h($activeSchoolYearLabel) ?></p>
        </div>
    </div>
</aside>
