<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/teacher_service.php';
require_once __DIR__ . '/../includes/certificate_render.php';

require_login();

$connection = db();
$user       = current_user();

// Advisers preview their own; heads / Super Admin preview any in their scope.
$isTeacher = is_teacher_user($user);
if (!teacher_can_manage($user) && !$isTeacher) {
    flash_set('error', 'Access denied.');
    redirect_to(app_url(user_home_path($user)));
}

$cert = cert_get($connection, to_int($_GET['id'] ?? 0));
if (!$cert) {
    flash_set('error', 'Certificate not found.');
    redirect_to(app_url($isTeacher ? 'teacher/certificates.php' : 'depthead/certificates.php'));
}

// A teacher may only preview a certificate they themselves issued.
if ($isTeacher) {
    $t = teacher_by_user_id($connection, (int) $user['id']);
    if (!$t || (int) $cert['adviser_teacher_id'] !== (int) $t['Teacher_id']) {
        flash_set('error', 'That certificate is not yours.');
        redirect_to(app_url('teacher/certificates.php'));
    }
}

cert_render_page($connection, $cert, app_url($isTeacher ? 'teacher/certificates.php' : 'depthead/certificates.php'));
