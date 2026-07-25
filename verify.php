<?php

declare(strict_types=1);

/**
 * PUBLIC certificate verification — the QR code target.
 *
 * Deliberately requires BOTH the certificate number and its secret token, so a
 * printed number alone cannot be used to enumerate or forge records. No login is
 * required (anyone holding the paper must be able to check it), and only
 * non-sensitive fields are shown — never grades per subject, contact details or
 * anything not already printed on the certificate itself.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/certificate_service.php';

$db     = db();
$certNo = (string) ($_GET['c'] ?? '');
$token  = (string) ($_GET['k'] ?? '');
$cert   = ($certNo !== '' && $token !== '') ? cert_verify($db, $certNo, $token) : null;
$valid  = $cert !== null && (string) $cert['status'] === 'Published';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificate Verification | ITFA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body{font-family:Manrope,system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-5">
<div class="w-full max-w-lg">
    <div class="text-center mb-5">
        <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-14 h-14 object-contain mx-auto mb-2">
        <p class="text-xs uppercase tracking-[0.2em] text-green-700 font-bold">Ibn Taimiyah Foundation Academy, Inc.</p>
        <h1 class="text-xl font-extrabold text-slate-800">Certificate Verification</h1>
    </div>

    <?php if ($valid): ?>
    <div class="bg-white rounded-3xl border-2 border-emerald-400 shadow-xl overflow-hidden">
        <div class="bg-emerald-600 text-white px-6 py-4 flex items-center gap-3">
            <svg class="w-7 h-7 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                <p class="font-extrabold text-lg leading-tight">Authentic Certificate</p>
                <p class="text-xs text-emerald-100">Verified against the school records</p>
            </div>
        </div>
        <div class="p-6">
            <dl class="text-sm divide-y divide-slate-100">
                <div class="flex justify-between py-2"><dt class="text-slate-500">Certificate No.</dt><dd class="font-mono font-bold"><?= h((string) $cert['certificate_no']) ?></dd></div>
                <div class="flex justify-between py-2 gap-4"><dt class="text-slate-500 shrink-0">Awarded to</dt><dd class="font-bold text-right"><?= h((string) $cert['student_name']) ?></dd></div>
                <div class="flex justify-between py-2 gap-4"><dt class="text-slate-500 shrink-0">Grade &amp; Section</dt><dd class="font-semibold text-right"><?= h((string) ($cert['grade_level'] ?? '')) ?> — <?= h((string) ($cert['section_name'] ?? '')) ?></dd></div>
                <div class="flex justify-between py-2 gap-4"><dt class="text-slate-500 shrink-0">Recognition</dt><dd class="font-extrabold text-right"><?= h((string) $cert['honor_level']) ?></dd></div>
                <?php if ($cert['general_average'] !== null): ?>
                <div class="flex justify-between py-2"><dt class="text-slate-500">General Average</dt><dd class="font-bold"><?= number_format((float) $cert['general_average'], 2) ?></dd></div>
                <?php endif; ?>
                <div class="flex justify-between py-2 gap-4"><dt class="text-slate-500 shrink-0">Period</dt><dd class="font-semibold text-right"><?= h((string) ($cert['period_name'] ?: '—')) ?> · <?= h((string) $cert['school_year']) ?></dd></div>
                <div class="flex justify-between py-2"><dt class="text-slate-500">Issued</dt><dd class="font-semibold"><?= h(date('F j, Y', strtotime((string) ($cert['published_at'] ?: $cert['issued_at'])))) ?></dd></div>
                <div class="flex justify-between py-2 gap-4"><dt class="text-slate-500 shrink-0">Principal</dt><dd class="font-semibold text-right"><?= h((string) $cert['principal_name']) ?></dd></div>
            </dl>
        </div>
    </div>

    <?php elseif ($cert && (string) $cert['status'] === 'Revoked'): ?>
    <div class="bg-white rounded-3xl border-2 border-rose-400 shadow-xl overflow-hidden">
        <div class="bg-rose-600 text-white px-6 py-4">
            <p class="font-extrabold text-lg">⚠ Certificate Revoked</p>
            <p class="text-xs text-rose-100">This certificate was issued but has since been revoked by the school.</p>
        </div>
        <div class="p-6 text-sm text-slate-600">
            <p><b>Certificate No.:</b> <span class="font-mono"><?= h((string) $cert['certificate_no']) ?></span></p>
            <p class="mt-1">Please contact the Registrar&rsquo;s office for clarification.</p>
        </div>
    </div>

    <?php else: ?>
    <div class="bg-white rounded-3xl border-2 border-slate-300 shadow-xl overflow-hidden">
        <div class="bg-slate-700 text-white px-6 py-4">
            <p class="font-extrabold text-lg">✕ Not Verified</p>
            <p class="text-xs text-slate-300">No published certificate matches this code.</p>
        </div>
        <div class="p-6 text-sm text-slate-600">
            <p>The certificate could not be verified. It may not exist, may not yet be published, or the code may have been mistyped.</p>
            <p class="mt-2 text-xs text-slate-400">Scan the QR code directly from the certificate, or contact the Registrar&rsquo;s office.</p>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-center text-xs text-slate-400 mt-5">ITFA I-SMS · Certificate verification service</p>
</div>
</body>
</html>
