<?php

declare(strict_types=1);

/**
 * Shared Certificate of Recognition renderer.
 *
 * ONE renderer, used by the student portal, the adviser preview and the
 * Department Head preview — deliberately not duplicated. (The two SOA slip
 * renderers in this codebase already drifted apart; see RESUME_NOTES.md §3.3.)
 *
 * The QR encodes an absolute URL to verify.php carrying the certificate number
 * and its secret token, so anyone holding the paper can confirm it is genuine.
 */

require_once __DIR__ . '/certificate_service.php';

/** Render a full printable certificate page and exit. */
function cert_render_page(mysqli $db, array $cert, string $backUrl = ''): void
{
    $verifyUrl = cert_verify_url($cert);
    $logo      = is_file(dirname(__DIR__) . '/itfalogo.png') ? app_url('itfalogo.png') : '';
    $level     = (string) $cert['honor_level'];
    $isHighest = str_contains($level, 'Highest');
    $isHigh    = !$isHighest && str_contains($level, 'High');
    $accent    = $isHighest ? '#b45309' : ($isHigh ? '#6d28d9' : '#0369a1');
    $accentBg  = $isHighest ? '#fffbeb' : ($isHigh ? '#f5f3ff' : '#f0f9ff');
    $avg       = $cert['general_average'] !== null ? number_format((float) $cert['general_average'], 2) : null;
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate of Recognition — <?= h((string) $cert['student_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;900&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        @page { size: A4 landscape; margin: 0; }
        body { margin: 0; padding: 16px; background: #eef1f5; font-family: 'Manrope', Arial, sans-serif; }

        .sheet {
            width: 297mm; height: 210mm; margin: 0 auto; background: #fff; position: relative;
            padding: 12mm; box-shadow: 0 10px 40px rgba(0,0,0,.15);
        }
        /* Double rule border */
        .frame { position: absolute; inset: 8mm; border: 3px solid <?= $accent ?>; }
        .frame::after { content: ''; position: absolute; inset: 4mm; border: 1px solid <?= $accent ?>66; }

        .inner { position: relative; height: 100%; padding: 14mm 18mm; display: flex; flex-direction: column; align-items: center; text-align: center; }

        .crest { width: 62px; height: 62px; object-fit: contain; }
        .rep { font-size: 9.5px; letter-spacing: 1.5px; color: #64748b; text-transform: uppercase; margin-top: 4px; }
        .school { font-family: 'Cinzel', serif; font-size: 20px; font-weight: 900; color: #1e1b4b; letter-spacing: .5px; margin-top: 2px; }
        .addr { font-size: 9.5px; color: #64748b; }

        .title { font-family: 'Cinzel', serif; font-size: 34px; font-weight: 900; color: <?= $accent ?>; letter-spacing: 6px; margin-top: 10px; line-height: 1.1; }
        .subtitle { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 15px; color: #475569; margin-top: 2px; }

        .presented { font-family: 'Cormorant Garamond', serif; font-size: 14px; color: #475569; margin-top: 12px; letter-spacing: .5px; }
        .name { font-family: 'Cinzel', serif; font-size: 30px; font-weight: 700; color: #0f172a; margin-top: 4px; line-height: 1.15;
                border-bottom: 1.5px solid <?= $accent ?>55; padding: 0 24px 6px; display: inline-block; }
        .meta { font-size: 11px; color: #64748b; margin-top: 6px; }

        .body { font-family: 'Cormorant Garamond', serif; font-size: 14.5px; color: #334155; line-height: 1.6; margin-top: 12px; max-width: 195mm; }
        .honor { display: inline-block; margin-top: 8px; padding: 6px 22px; border: 2px solid <?= $accent ?>;
                 background: <?= $accentBg ?>; color: <?= $accent ?>; font-family: 'Cinzel', serif;
                 font-weight: 900; font-size: 19px; letter-spacing: 2px; }
        .avg { font-size: 11.5px; color: #475569; margin-top: 6px; }

        .given { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 12px; color: #64748b; margin-top: auto; padding-top: 8px; }

        .sigs { display: flex; justify-content: center; gap: 60px; width: 100%; margin-top: 10px; }
        .sig { text-align: center; min-width: 62mm; }
        .sig .ln { border-top: 1.2px solid #0f172a; padding-top: 4px; font-size: 12px; font-weight: 700; color: #0f172a; font-family: 'Cinzel', serif; letter-spacing: .3px; }
        .sig .role { font-size: 9.5px; color: #64748b; margin-top: 1px; }

        .qrbox { position: absolute; right: 14mm; bottom: 12mm; text-align: center; }
        .qrbox .cap { font-size: 7px; color: #94a3b8; margin-top: 2px; letter-spacing: .3px; }
        .qrbox .no { font-size: 8px; color: #475569; font-family: Consolas, monospace; font-weight: 700; }

        .void { position: absolute; top: 45%; left: 50%; transform: translate(-50%,-50%) rotate(-18deg);
                font-family: 'Cinzel', serif; font-size: 90px; font-weight: 900; color: rgba(220,38,38,.14); letter-spacing: 10px; }
        .draft { position: absolute; top: 14mm; left: 14mm; background: #fef3c7; border: 1px solid #f59e0b;
                 color: #92400e; font-size: 9px; font-weight: 800; padding: 3px 9px; letter-spacing: 1px; }

        .toolbar { width: 297mm; margin: 0 auto 10px; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 15px; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 12px; }
        .toolbar button { background: #4f46e5; color: #fff; border: 0; }
        .toolbar a { background: #fff; border: 1px solid #cbd5e1; color: #334155; margin-left: 6px; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; } .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <?php if ($backUrl !== ''): ?><a href="<?= h($backUrl) ?>">Back</a><?php endif; ?>
    </div>

    <div class="sheet">
        <div class="frame"></div>
        <?php if ((string) $cert['status'] === 'Revoked'): ?><div class="void">REVOKED</div><?php endif; ?>
        <?php if ((string) $cert['status'] === 'Draft'): ?><div class="draft">DRAFT — NOT YET PUBLISHED</div><?php endif; ?>

        <div class="inner">
            <?php if ($logo): ?><img class="crest" src="<?= h($logo) ?>" alt="ITFA"><?php endif; ?>
            <div class="rep">Republic of the Philippines · Department of Education</div>
            <div class="school">IBN TAIMIYAH FOUNDATION ACADEMY, INC.</div>
            <div class="addr">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>

            <div class="title">CERTIFICATE</div>
            <div class="subtitle">of Recognition</div>

            <div class="presented">is proudly presented to</div>
            <div class="name"><?= h((string) $cert['student_name']) ?></div>
            <div class="meta">
                <?= h((string) ($cert['grade_level'] ?: '')) ?><?= $cert['section_name'] ? ' — ' . h((string) $cert['section_name']) : '' ?>
                <?= $cert['lrn'] ? ' · LRN ' . h((string) $cert['lrn']) : '' ?>
            </div>

            <div class="body">
                In recognition of outstanding academic achievement and exemplary diligence in studies
                during <?= h((string) ($cert['period_name'] ?: 'the school year')) ?>,
                School Year <?= h((string) $cert['school_year']) ?>,
                hereby conferred the distinction of
            </div>

            <div class="honor"><?= h(strtoupper((string) $cert['honor_level'])) ?></div>
            <?php if ($avg !== null): ?>
            <div class="avg">General Average: <strong><?= h($avg) ?></strong></div>
            <?php endif; ?>

            <div class="given">
                Given this <?= h(date('jS \d\a\y \o\f F, Y', strtotime((string) ($cert['published_at'] ?: $cert['issued_at'])))) ?>
                at Sultan Kudarat, Maguindanao del Norte.
            </div>

            <?php
            /* Names are printed AS STORED — never strtoupper(). Academic
               post-nominals are case-sensitive: "MAEd" must not become "MAED",
               and surnames like "H.Salik" must not become "H.SALIK". */
            ?>
            <?php
            // The adviser's uploaded signature (if any) prints above their line.
            $advSig = null;
            if (function_exists('teacher_image_url') && (int) ($cert['adviser_teacher_id'] ?? 0) > 0) {
                $advSig = teacher_image_url((int) $cert['adviser_teacher_id'], 'signature');
            }
            ?>
            <div class="sigs">
                <div class="sig">
                    <?php if ($advSig): ?><img src="<?= h($advSig) ?>" alt="" style="max-height:34px;max-width:80%;object-fit:contain;display:block;margin:0 auto -2px;"><?php endif; ?>
                    <div class="ln"><?= h((string) ($cert['adviser_name'] ?: ' ')) ?></div>
                    <div class="role">Class Adviser</div>
                </div>
                <div class="sig">
                    <div class="ln"><?= h((string) $cert['principal_name']) ?></div>
                    <div class="role">Principal</div>
                </div>
            </div>
        </div>

        <div class="qrbox">
            <div id="qr"></div>
            <div class="no"><?= h((string) $cert['certificate_no']) ?></div>
            <div class="cap">Scan to verify</div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
    <script>
    (function () {
        var url = <?= json_encode($verifyUrl, JSON_UNESCAPED_SLASHES) ?>;
        try {
            // Type 0 = auto-size; 'M' error correction survives printing/scuffing.
            var qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            document.getElementById('qr').innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0 });
            var svg = document.querySelector('#qr svg');
            if (svg) { svg.setAttribute('width', '78'); svg.setAttribute('height', '78'); }
        } catch (e) {
            // Never leave a blank square — fall back to the printed URL.
            document.getElementById('qr').innerHTML =
                '<div style="font-size:6px;max-width:78px;word-break:break-all;color:#475569">' + url + '</div>';
        }
    })();
    </script>
</body>
</html>
    <?php
    exit;
}
