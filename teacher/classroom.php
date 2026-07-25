<?php

declare(strict_types=1);

/**
 * Launch point into the Classroom (LMS) — every "Classroom" link/click in the
 * teacher module routes through here so a fresh SSO ticket is minted every
 * time. This also transparently repairs an expired Laravel session: if the
 * Classroom app ever bounces a teacher back here (their session there timed
 * out), this page is still guarded by require_teacher_login(), so the teacher
 * only re-authenticates if THEIR NATIVE session is gone too — otherwise this
 * mints a new ticket and sends them straight back in, no password required.
 */

require_once __DIR__ . '/../includes/teacher_auth.php';
require_once __DIR__ . '/../includes/portal_bridge.php';

$teacher = require_teacher_login();
$db      = db();

$redirect = (string) ($_GET['to'] ?? '/teacher');
// Only allow relative, in-app redirect targets — never an open redirect.
if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
    $redirect = '/teacher';
}

redirect_to(classroom_sso_url($db, (int) $teacher['Teacher_id'], $redirect));
