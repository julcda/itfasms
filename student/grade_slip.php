<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';
require_once __DIR__ . '/../includes/grading_service.php';

$account = require_student_login();
$db      = db();
$sess    = current_student();

$enrollmentId = (int) $sess['enrollment_id'];
$profile      = student_profile($db, $enrollmentId);
if (!$profile) {
    student_logout();
    redirect_to(app_url('student/login.php'));
}

$sy            = student_active_sy($db);
$studentInfoId = resolve_studentinfo_id_for_enrollment($db, $enrollmentId, false);
$periodId      = to_int($_GET['p'] ?? 0);

// The period must actually be released to THIS student — never trust the URL.
$allowed = release_schema_ready($db) ? student_released_periods($db, $sy['id']) : [];
$ok = false;
foreach ($allowed as $p) { if ((int) $p['id'] === $periodId) { $ok = true; break; } }
if (!$ok && $allowed) { $periodId = (int) $allowed[count($allowed) - 1]['id']; $ok = true; }

$slip = ($ok && $studentInfoId > 0) ? student_grade_slip($db, $studentInfoId, $periodId) : null;

if (!$slip || !$slip['released']) {
    flash_set('error', 'Your grade slip has not been released yet.');
    redirect_to(app_url('student/grades.php'));
}

$period   = gp_get($db, $periodId);
$avg      = $slip['average'];
$fullName = trim(strtoupper((string) $profile['surname']) . ', ' . (string) $profile['firstname']
          . ((string) ($profile['middlename'] ?? '') !== '' ? ' ' . mb_substr((string) $profile['middlename'], 0, 1) . '.' : ''));
$logo     = is_file(dirname(__DIR__) . '/itfalogo.png') ? app_url('itfalogo.png') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Grade Slip — <?= h($fullName) ?></title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4 portrait; margin: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #111; margin: 0; padding: 16px; background: #eef1f5; }
        .slip { width: 210mm; min-height: 290mm; margin: 0 auto; background: #fff; padding: 16mm 18mm; box-shadow: 0 10px 40px rgba(0,0,0,.12); position: relative; }

        .head { text-align: center; border-bottom: 2.5px solid #166534; padding-bottom: 10px; position: relative; }
        .head img { width: 62px; height: 62px; object-fit: contain; position: absolute; left: 0; top: 0; }
        .head .rep { font-size: 9.5px; letter-spacing: .5px; color: #555; }
        .head h1 { font-size: 17px; color: #166534; font-weight: 800; margin: 2px 0; letter-spacing: .3px; }
        .head .addr { font-size: 9.5px; color: #555; }
        .head .motto { font-size: 9px; font-style: italic; color: #777; margin-top: 2px; }

        .title { text-align: center; margin: 16px 0 4px; }
        .title h2 { font-size: 15px; font-weight: 800; letter-spacing: 4px; color: #166534; }
        .title .sub { font-size: 10.5px; color: #444; margin-top: 2px; }

        .info { border: 1px solid #cbd5e1; border-radius: 5px; padding: 10px 12px; margin: 14px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 5px 20px; font-size: 11px; }
        .info div { display: flex; }
        .info .l { color: #64748b; min-width: 86px; }
        .info .v { font-weight: 700; }

        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        thead th { background: #166534; color: #fff; font-size: 10.5px; padding: 7px 8px; text-align: left; letter-spacing: .3px; }
        thead th.c { text-align: center; }
        tbody td { border-bottom: 1px solid #e2e8f0; padding: 7px 8px; font-size: 11px; }
        tbody td.c { text-align: center; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .g { font-weight: 800; font-size: 12.5px; }
        .pass { color: #047857; } .fail { color: #b91c1c; } .pend { color: #94a3b8; font-style: italic; }

        tfoot td { padding: 9px 8px; font-weight: 800; font-size: 12px; border-top: 2px solid #166534; background: #f0f7f2; }

        .legend { margin-top: 12px; border: 1px solid #e2e8f0; border-radius: 5px; padding: 8px 10px; font-size: 9px; color: #555; }
        .legend b { color: #166534; }
        .legend span { display: inline-block; margin-right: 14px; }

        .sign { display: flex; justify-content: space-between; margin-top: 34px; gap: 20px; }
        .sign .sg { flex: 1; text-align: center; }
        .sign .sig-slot { height: 30px; display: flex; align-items: flex-end; justify-content: center; }
        .sign .sig-slot img { max-height: 30px; max-width: 90%; object-fit: contain; }
        .sign .ln { border-top: 1px solid #111; padding-top: 3px; font-size: 10.5px; font-weight: 700; }
        .sign .role { font-size: 9px; color: #555; }

        .foot { position: absolute; bottom: 12mm; left: 18mm; right: 18mm; text-align: center; font-size: 8px; color: #94a3b8; border-top: 1px solid #eee; padding-top: 5px; }

        .toolbar { width: 210mm; margin: 0 auto 10px; text-align: right; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 15px; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 12px; }
        .toolbar button { background: #166534; color: #fff; border: 0; }
        .toolbar a { background: #fff; border: 1px solid #cbd5e1; color: #334155; margin-left: 6px; }
        @media print { body { background: #fff; padding: 0; } .slip { box-shadow: none; } .toolbar { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print / Save PDF</button>
        <a href="<?= h(app_url('student/grades.php')) ?>">Back</a>
    </div>

    <div class="slip">
        <!-- ── Headings ── -->
        <div class="head">
            <?php if ($logo): ?><img src="<?= h($logo) ?>" alt="ITFA"><?php endif; ?>
            <div class="rep">Republic of the Philippines · Department of Education</div>
            <h1>IBN TAIMIYAH FOUNDATION ACADEMY, INC.</h1>
            <div class="addr">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte</div>
            <div class="motto">&ldquo;Molding the youth through Dunya-Akhirat Education&rdquo;</div>
        </div>

        <div class="title">
            <h2>GRADE SLIP</h2>
            <div class="sub"><?= h((string) ($period['name'] ?? '')) ?> &nbsp;·&nbsp; School Year <?= h($sy['label']) ?></div>
        </div>

        <!-- ── Student information ── -->
        <div class="info">
            <div><span class="l">Name:</span><span class="v"><?= h($fullName) ?></span></div>
            <div><span class="l">LRN:</span><span class="v"><?= h((string) ($profile['lrn'] ?: '—')) ?></span></div>
            <div><span class="l">Grade Level:</span><span class="v"><?= h((string) ($profile['grade_name'] ?: '—')) ?></span></div>
            <div><span class="l">Section:</span><span class="v"><?= h((string) ($profile['section_name'] ?: '—')) ?></span></div>
            <div><span class="l">Department:</span><span class="v"><?= h((string) ($profile['Department'] ?: '—')) ?></span></div>
            <div><span class="l">Sex:</span><span class="v"><?= h((string) ($profile['sex'] ?: '—')) ?></span></div>
        </div>

        <!-- ── Subjects and grades ── -->
        <table>
            <thead>
                <tr>
                    <th style="width:26px" class="c">#</th>
                    <th>Subject</th>
                    <th>Teacher</th>
                    <th class="c" style="width:70px">Grade</th>
                    <th class="c" style="width:110px">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slip['rows'] as $i => $r): ?>
                <tr>
                    <td class="c"><?= $i + 1 ?></td>
                    <td>
                        <?= h($r['subject']) ?>
                        <?php if ($r['code']): ?><span style="color:#94a3b8;font-size:9px"> · <?= h($r['code']) ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:10px;color:#555"><?= h($r['teacher']) ?></td>
                    <td class="c">
                        <?php if ($r['pending']): ?>
                            <span class="pend">—</span>
                        <?php else: ?>
                            <span class="g <?= $r['grade'] >= GRADE_PASSING ? 'pass' : 'fail' ?>"><?= number_format((float) $r['grade'], 2) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="c" style="font-size:10px">
                        <?= $r['pending'] ? '<span class="pend">Not yet released</span>' : h((string) $r['remarks']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right">GENERAL AVERAGE</td>
                    <td class="c"><span class="g <?= $avg !== null && $avg >= GRADE_PASSING ? 'pass' : ($avg === null ? '' : 'fail') ?>"><?= $avg === null ? '—' : number_format($avg, 2) ?></span></td>
                    <td class="c" style="font-size:10px"><?= h(grade_descriptor($avg)) ?></td>
                </tr>
            </tfoot>
        </table>

        <?php if (!$slip['complete']): ?>
        <p style="font-size:9px;color:#b45309;margin-top:8px;">
            &#9432; Some subjects are still being finalized. The general average covers the
            <?= (int) $slip['graded'] ?> subject<?= $slip['graded'] === 1 ? '' : 's' ?> released so far.
        </p>
        <?php endif; ?>

        <div class="legend">
            <b>Grading Scale:</b>
            <span>90–100 Outstanding</span>
            <span>85–89 Very Satisfactory</span>
            <span>80–84 Satisfactory</span>
            <span>75–79 Fairly Satisfactory</span>
            <span>Below 75 Did Not Meet Expectations</span>
            <br><b>Passing mark:</b> <?= number_format(GRADE_PASSING, 0) ?>
        </div>

        <?php
        // The signing adviser is the student's SECTION adviser. If they have
        // uploaded a signature it prints above the line; the name prints on it.
        $adviser = teacher_adviser_for_student($db, $studentInfoId, $sy['id']);
        ?>
        <div class="sign">
            <div class="sg">
                <div class="sig-slot">
                    <?php if ($adviser && $adviser['signature']): ?>
                    <img src="<?= h($adviser['signature']) ?>" alt="">
                    <?php endif; ?>
                </div>
                <div class="ln"><?= $adviser ? h(strtoupper((string) $adviser['name'])) : '&nbsp;' ?></div>
                <div class="role">Class Adviser</div>
            </div>
            <div class="sg">
                <div class="sig-slot"></div>
                <div class="ln">&nbsp;</div>
                <div class="role">Department Head</div>
            </div>
            <div class="sg">
                <div class="sig-slot"></div>
                <div class="ln">&nbsp;</div>
                <div class="role">Parent / Guardian</div>
            </div>
        </div>

        <div class="foot">
            This is a system-generated grade slip from ITFA I-SMS · Generated <?= h(date('F j, Y g:i A')) ?>
            <?php if ($slip['released_at']): ?> · Released <?= h(date('F j, Y', strtotime((string) $slip['released_at']))) ?><?php endif; ?>
        </div>
    </div>
</body>
</html>
