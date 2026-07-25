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
$photoUrl = student_photo_url($profile);

$enrollmentId = (int) $sess['enrollment_id'];
require __DIR__ . '/_soa_data.php';   // -> $soa

$statusColor = ['Fully Paid'=>'emerald','Partially Paid'=>'amber','Unpaid'=>'rose','No Assessment'=>'slate'][$soa['payStatus']];
$flash = flash_get();
$fullName = trim((string) ($profile['firstname'] . ' ' . ($profile['middlename'] ? $profile['middlename'][0] . '. ' : '') . $profile['surname']));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement of Account | Student Portal</title>
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
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-semibold">Financial Record</p>
                    <h1 class="text-2xl sm:text-3xl font-extrabold mt-1">Statement of Account</h1>
                    <p class="text-slate-500 mt-1 text-sm"><?= h($fullName) ?> · <?= h((string) $profile['grade_name']) ?> <?= h((string) $profile['section_name']) ?> · S.Y. <?= h((string) $profile['school_year']) ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-block px-3 py-1.5 rounded-full text-xs font-bold border bg-<?= $statusColor ?>-100 text-<?= $statusColor ?>-800 border-<?= $statusColor ?>-300"><?= h($soa['payStatus']) ?></span>
                    <?php if ($soa['officialSoaId'] > 0 && !$soa['officialSoaPaid']): ?>
                    <a href="<?= h(app_url('student/soa_print.php')) ?>" target="_blank" class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-4 py-2.5">🖨 Print Official SOA</a>
                    <?php elseif ($soa['officialSoaPaid']): ?>
                    <span class="rounded-xl bg-emerald-50 border border-emerald-300 text-emerald-700 text-sm font-semibold px-4 py-2.5" title="This SOA has been fully paid">✓ SOA fully paid</span>
                    <?php else: ?>
                    <span class="rounded-xl bg-slate-100 border border-slate-200 text-slate-400 text-sm font-semibold px-4 py-2.5 cursor-not-allowed" title="The Cashier has not generated your official SOA yet">🖨 Official SOA not available yet</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <?php if ($flash): ?>
        <div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flash['type']==='success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($soa['promissoryNotes']): ?>
        <div class="mb-6 rounded-2xl bg-rose-50 border border-rose-300 px-5 py-4">
            <div class="flex items-start gap-3">
                <span class="text-xl leading-none">⚠️</span>
                <div class="flex-1">
                    <p class="font-bold text-rose-800">This student has an unpaid promissory note that must be settled.</p>
                    <p class="text-sm text-rose-700 mt-0.5">Total under promissory arrangement: <strong>₱<?= number_format($soa['promissoryTotal'], 2) ?></strong> — this amount is included in your outstanding balance.</p>
                    <div class="mt-3 space-y-1.5">
                        <?php foreach ($soa['promissoryNotes'] as $pnRow): ?>
                        <div class="flex flex-wrap items-center gap-x-3 text-xs bg-white/70 rounded-lg px-3 py-2 border border-rose-200">
                            <span class="font-mono font-bold"><?= h((string) $pnRow['promissory_no']) ?></span>
                            <span class="font-semibold">₱<?= number_format((float) $pnRow['promissory_amount'], 2) ?></span>
                            <span class="text-slate-500">promised by <?= h(date('M j, Y', strtotime((string) $pnRow['promised_payment_date']))) ?></span>
                            <?= pn_status_badge((string) $pnRow['status']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($soa['backAccounts']): ?>
        <div class="mb-6 rounded-2xl bg-amber-50 border border-amber-300 px-5 py-4">
            <div class="flex items-start gap-3">
                <span class="text-xl leading-none">⚠️</span>
                <div class="flex-1">
                    <p class="font-bold text-amber-900">You have an unpaid back account from a previous school year.</p>
                    <p class="text-sm text-amber-800 mt-0.5">Total outstanding back account: <strong>₱<?= number_format($soa['backAccountTotal'], 2) ?></strong> — this is <em>separate</em> from this year's balance below. Please settle it at the Cashier's office.</p>
                    <div class="mt-3 space-y-1.5">
                        <?php foreach ($soa['backAccounts'] as $baRow): ?>
                        <div class="flex flex-wrap items-center gap-x-3 text-xs bg-white/70 rounded-lg px-3 py-2 border border-amber-200">
                            <span class="font-bold">S.Y. <?= h((string) $baRow['school_year']) ?></span>
                            <span class="font-semibold">₱<?= number_format((float) $baRow['balance'], 2) ?></span>
                            <?php if ((float) $baRow['amount_paid'] > 0.009): ?>
                            <span class="text-slate-500">₱<?= number_format((float) $baRow['amount_paid'], 2) ?> already paid of ₱<?= number_format((float) $baRow['original_amount'], 2) ?></span>
                            <?php endif; ?>
                            <span class="inline-flex rounded-full border px-2 py-0.5 font-bold <?= ba_status_badge((string) $baRow['status']) ?>"><?= h((string) $baRow['status']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$soa['assessment']): ?>
        <div class="rounded-3xl bg-white border border-amber-300 shadow-panel p-8 text-center">
            <h2 class="font-extrabold text-amber-700 mb-1">No assessment on file yet</h2>
            <p class="text-sm text-slate-600">Your Statement of Account has not been generated. Please visit the Cashier's office or check back soon.</p>
        </div>
        <?php else: ?>

        <?php if ($soa['officialSoaId'] === 0): ?>
        <div class="mb-6 rounded-2xl bg-green-50 border border-green-200 px-5 py-4 text-sm text-green-800 flex items-start gap-3">
            <span class="text-lg leading-none">ℹ️</span>
            <p>Your <strong>official printable SOA</strong> has not been generated by the Cashier yet. Your fee breakdown and payment history below are up to date; the printable copy will be available here once the Cashier issues it.</p>
        </div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-3 gap-6">
            <!-- Assessment breakdown -->
            <section class="lg:col-span-2 bg-white rounded-3xl border border-green-100 shadow-panel p-6">
                <h2 class="font-extrabold text-lg mb-4">Assessment Breakdown</h2>

                <div class="mb-5">
                    <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold mb-2">Monthly Fees <span class="normal-case text-slate-400">(× <?= (int) $soa['installmentCount'] ?> months)</span></p>
                    <div class="rounded-2xl border border-slate-100 divide-y divide-slate-100">
                        <?php foreach ($soa['monthly'] as $label => $amt): ?>
                        <div class="flex justify-between px-4 py-2.5 text-sm">
                            <span class="text-slate-600"><?= h($label) ?></span>
                            <span class="font-semibold">₱<?= number_format((float) $amt, 2) ?> <span class="text-slate-400 font-normal">/mo</span></span>
                        </div>
                        <?php endforeach; ?>
                        <div class="flex justify-between px-4 py-2.5 text-sm bg-slate-50">
                            <span class="font-semibold">Monthly total</span>
                            <span class="font-bold">₱<?= number_format($soa['monthlyTotal'], 2) ?> /mo</span>
                        </div>
                        <div class="flex justify-between px-4 py-2.5 text-sm bg-green-50/60">
                            <span class="font-semibold text-green-800">Installment subtotal (<?= (int) $soa['installmentCount'] ?> months)</span>
                            <span class="font-bold text-green-800">₱<?= number_format($soa['installmentBase'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <div class="mb-5">
                    <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold mb-2">Enrollment Fees</p>
                    <div class="rounded-2xl border border-slate-100 divide-y divide-slate-100">
                        <?php if (!$soa['enrollFees']): ?>
                        <div class="px-4 py-2.5 text-sm text-slate-400">None recorded.</div>
                        <?php endif; ?>
                        <?php foreach ($soa['enrollFees'] as $f): ?>
                        <div class="flex justify-between px-4 py-2.5 text-sm">
                            <span class="text-slate-600"><?= h($f['label']) ?></span>
                            <span class="font-semibold">₱<?= number_format($f['amount'], 2) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <div class="flex justify-between px-4 py-2.5 text-sm bg-slate-50">
                            <span class="font-semibold">Enrollment fees subtotal</span>
                            <span class="font-bold">₱<?= number_format($soa['enrollFeesTotal'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl bg-green-600 text-white px-5 py-4 flex justify-between items-center">
                    <span class="font-bold">TOTAL ASSESSMENT</span>
                    <span class="text-2xl font-extrabold">₱<?= number_format($soa['netAssessed'], 2) ?></span>
                </div>
            </section>

            <!-- Totals -->
            <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6 h-fit">
                <h2 class="font-extrabold text-lg mb-4">Summary</h2>
                <div class="space-y-3">
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Total Assessment</span><span class="font-bold">₱<?= number_format($soa['netAssessed'], 2) ?></span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Payments Made</span><span class="font-bold text-emerald-600">− ₱<?= number_format($soa['totalPaid'], 2) ?></span></div>
                    <div class="border-t border-slate-200 pt-3 flex justify-between"><span class="font-bold">Remaining Balance</span><span class="text-xl font-extrabold <?= $soa['balance'] > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">₱<?= number_format(max(0,$soa['balance']), 2) ?></span></div>
                    <?php if ($soa['balance'] < 0): ?>
                    <p class="text-xs text-emerald-700">Advance / credit of ₱<?= number_format(abs($soa['balance']), 2) ?>.</p>
                    <?php endif; ?>
                </div>
                <div class="mt-5 rounded-2xl bg-<?= $statusColor ?>-50 border border-<?= $statusColor ?>-200 px-4 py-3 text-center">
                    <p class="text-xs uppercase tracking-wide text-<?= $statusColor ?>-600 font-semibold">Payment Status</p>
                    <p class="text-lg font-extrabold text-<?= $statusColor ?>-800 mt-0.5"><?= h($soa['payStatus']) ?></p>
                </div>
            </section>
        </div>

        <!-- Payment history -->
        <section class="bg-white rounded-3xl border border-green-100 shadow-panel p-6 mt-6">
            <h2 class="font-extrabold text-lg mb-4">Payment History</h2>
            <?php if (!$soa['payments']): ?>
            <div class="rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm px-4 py-8 text-center">No payments recorded yet.</div>
            <?php else: ?>
            <div class="overflow-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-200">
                            <th class="py-2 pr-3">OR Number</th>
                            <th class="py-2 pr-3">Date</th>
                            <th class="py-2 pr-3">Method</th>
                            <th class="py-2 pr-3 text-right">Amount Paid</th>
                            <th class="py-2 pr-3 text-right">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($soa['payments'] as $p): ?>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-3 font-medium text-slate-700"><?= h($p['or_number']) ?></td>
                            <td class="py-2.5 pr-3 text-slate-500"><?= h(date('M j, Y', strtotime($p['paid_at']))) ?></td>
                            <td class="py-2.5 pr-3"><?= h($p['method']) ?></td>
                            <td class="py-2.5 pr-3 text-right font-semibold text-emerald-600">₱<?= number_format($p['amount'], 2) ?></td>
                            <td class="py-2.5 pr-3 text-right font-semibold"><?= ($p['running'] < 0 ? '−₱' . number_format(abs($p['running']),2) : '₱' . number_format($p['running'], 2)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-50">
                            <td colspan="3" class="py-2.5 pr-3 font-bold text-right">Total Paid</td>
                            <td class="py-2.5 pr-3 text-right font-extrabold text-emerald-700">₱<?= number_format($soa['totalPaid'], 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>
    </main>
</div>
</body>
</html>
