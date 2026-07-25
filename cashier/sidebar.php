<?php
/**
 * Shared cashier sidebar.
 * Expects $user, $activeSchoolYearLabel, and $connection to be in scope.
 * Expects h() and app_url() to be loaded.
 */
// Be tolerant of pages that set $syLabel but not $activeSchoolYearLabel.
$activeSchoolYearLabel = $activeSchoolYearLabel ?? ($syLabel ?? '—');
$_sidebarPage = basename($_SERVER['PHP_SELF'] ?? '');
function _nav_link(string $page, string $href, string $label, string $icon, string $current): string
{
    $active = ($current === $page);
    $base   = 'flex items-center gap-3 px-4 py-2 rounded-xl text-sm font-medium transition-colors ';
    $cls    = $active
        ? $base . 'bg-white/15 border border-white/20 text-white font-semibold'
        : $base . 'text-slate-300 hover:bg-white/10 hover:text-white';
    return '<a href="' . h($href) . '" class="' . $cls . '">' . $icon . h($label) . '</a>';
}
function _nav_head(string $label): string
{
    return '<p class="px-4 pt-3 pb-1 text-[10px] uppercase tracking-widest text-amber-300/70 font-semibold">' . h($label) . '</p>';
}
$_icons = [
    'index.php'         => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
    'account_setup.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    'monthly_payments.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
    'history.php'       => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    'payment_history.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 3h14a1 1 0 011 1v17l-3-2-2 2-2-2-2 2-2-2-3 2V4a1 1 0 011-1z"/></svg>',
    'soa.php'           => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'soa_manage.php'    => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h10M4 18h10"/></svg>',
    'collect.php'       => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>',
    'other_payment.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h2m2-10h10a2 2 0 012 2v8a2 2 0 01-2 2h-8a2 2 0 01-2-2v-8a2 2 0 012-2zm5 5a1 1 0 11-2 0 1 1 0 012 0z"/></svg>',
    'back_accounts.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'dashboard.php'     => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
    'fee_schedule.php'  => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'consolidation.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',
    'reprint_or.php'    => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>',
    'reversals.php'     => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a4 4 0 014 4v2m0 0l-3-3m3 3l3-3M3 5v6h6"/></svg>',
    'close.php'         => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'deposits.php'      => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10l9-6 9 6v1H3v-1zM5 11v7m4-7v7m6-7v7m4-7v7M3 21h18"/></svg>',
    'promissory_verify.php' => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>',
    'report.php'        => '<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
];
?>
<aside class="bg-[#0a3a1e] text-slate-100 flex flex-col p-6 lg:p-7" style="background:linear-gradient(180deg,#0a3a1e 0%,#052815 100%)">
    <!-- Logo + brand -->
    <div class="flex items-center gap-3 mb-8">
        <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-10 h-10 object-contain">
        <div>
            <p class="text-[10px] uppercase tracking-widest text-amber-300 font-semibold">ITFA System</p>
            <p class="text-base font-extrabold leading-tight">Cashier Module</p>
        </div>
    </div>

    <nav class="space-y-0.5 flex-1 overflow-y-auto -mr-3 pr-3">
        <?= _nav_link('dashboard.php',      app_url('cashier/dashboard.php'),      'Dashboard',        $_icons['dashboard.php'],      $_sidebarPage) ?>

        <?= _nav_head('Statement of Account') ?>
        <?= _nav_link('soa.php',            app_url('cashier/soa.php'),            'Generate SOA',     $_icons['soa.php'],            $_sidebarPage) ?>
        <?= _nav_link('soa_manage.php',     app_url('cashier/soa_manage.php'),     'Manage / Reprint SOA', $_icons['soa_manage.php'], $_sidebarPage) ?>

        <?= _nav_head('Collections') ?>
        <?= _nav_link('collect.php',        app_url('cashier/collect.php'),        'Collect Payment',  $_icons['collect.php'],        $_sidebarPage) ?>
        <?= _nav_link('other_payment.php',  app_url('cashier/other_payment.php'),  'Other Payments',   $_icons['other_payment.php'],  $_sidebarPage) ?>
        <?= _nav_link('back_accounts.php',  app_url('cashier/back_accounts.php'),  'Back Accounts',    $_icons['back_accounts.php'],  $_sidebarPage) ?>
        <?= _nav_link('promissory_verify.php', app_url('cashier/promissory_verify.php'), 'Verify Promissory', $_icons['promissory_verify.php'], $_sidebarPage) ?>
        <?= _nav_link('payment_history.php', app_url('cashier/payment_history.php'), 'Payment History',  $_icons['payment_history.php'], $_sidebarPage) ?>
        <?= _nav_link('reversals.php',      app_url('cashier/reversals.php'),      'Void / Refund',    $_icons['reversals.php'],      $_sidebarPage) ?>
        <?= _nav_link('reprint_or.php',     app_url('cashier/reprint_or.php'),     'Reprint OR',       $_icons['reprint_or.php'],     $_sidebarPage) ?>

        <?= _nav_head('Day-End &amp; Reports') ?>
        <?= _nav_link('close.php',          app_url('cashier/close.php'),          'End-of-Day Close', $_icons['close.php'],          $_sidebarPage) ?>
        <?= _nav_link('deposits.php',       app_url('cashier/deposits.php'),       'Bank Deposits',    $_icons['deposits.php'],       $_sidebarPage) ?>
        <?= _nav_link('report.php',         app_url('cashier/report.php'),         'Collection Report', $_icons['report.php'],        $_sidebarPage) ?>
        <?= _nav_link('consolidation.php',  app_url('cashier/consolidation.php'),  'Consolidation',    $_icons['consolidation.php'],  $_sidebarPage) ?>

        <?= _nav_head('Setup') ?>
        <?= _nav_link('fee_schedule.php',   app_url('cashier/fee_schedule.php'),   'Fee Schedule',     $_icons['fee_schedule.php'],   $_sidebarPage) ?>
        <?= _nav_link('account_setup.php',  app_url('cashier/account_setup.php'),  'Account Setup',    $_icons['account_setup.php'],  $_sidebarPage) ?>

        <?= _nav_head('Legacy') ?>
        <?= _nav_link('index.php',          app_url('cashier/index.php'),          'Payment Queue',    $_icons['index.php'],          $_sidebarPage) ?>
        <?= _nav_link('monthly_payments.php', app_url('cashier/monthly_payments.php'), 'Monthly Payments', $_icons['monthly_payments.php'], $_sidebarPage) ?>
        <?= _nav_link('history.php',        app_url('cashier/history.php'),        'Edit Fees (Legacy)', $_icons['history.php'],      $_sidebarPage) ?>
    </nav>

    <a href="<?= h(app_url('logout.php')) ?>"
       class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-300 hover:bg-white/10 hover:text-white transition-colors mt-2 flex-shrink-0">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        Logout
    </a>

    <!-- User card -->
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 mt-6">
        <p class="text-xs text-slate-400">Logged in as</p>
        <p class="font-semibold mt-0.5"><?= h((string) ($user['full_name'] ?? 'Cashier')) ?></p>
        <p class="text-xs text-amber-300 mt-0.5"><?= h((string) ($user['role'] ?? 'Cashier')) ?></p>
        <div class="mt-3 pt-3 border-t border-white/10">
            <p class="text-xs text-slate-400">Active S.Y.</p>
            <p class="font-semibold text-sm mt-0.5"><?= h($activeSchoolYearLabel) ?></p>
        </div>
    </div>
</aside>
