<?php

declare(strict_types=1);

/**
 * Teacher Module authentication guard.
 *
 * Teachers are staff: they log in through the normal login.php / user_account
 * flow (bcrypt) with role = 'teacher'. This guard adds two things on top of
 * require_login():
 *
 *   1. Role enforcement — only 'teacher' (or a Super Admin previewing) passes.
 *   2. Identity resolution — maps the session user_id to a teacher.Teacher_id.
 *      A staff account with no teacher row can never "become" a teacher.
 *
 * The teacher row is re-read from the DB on EVERY request (never cached in the
 * session), so deactivating a teacher revokes access immediately.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/teacher_service.php';
// Every teacher page renders the sidebar, which shows the unread-message badge.
// Loading it here (the one file all teacher pages require) keeps the badge
// consistent instead of silently reading 0 on pages that forgot the include.
require_once __DIR__ . '/message_service.php';

/**
 * The password every teacher account is issued with. Lives HERE (not in the
 * provisioning script) because both the provisioner and change_password.php
 * need it — mirrors STUDENT_DEFAULT_PW in student_auth.php.
 */
if (!defined('TEACHER_DEFAULT_PW')) {
    define('TEACHER_DEFAULT_PW', 'teacher');
}

/**
 * Page guard for every teacher/*.php page.
 *
 * @return array the live `teacher` row (with Teacher_id)
 */
function require_teacher_login(): array
{
    require_login();

    $user = current_user();

    // Super Admins may view the module for support, but only if they are also a
    // teacher; otherwise they are bounced. This prevents a silent privilege blur.
    if (!is_teacher_user($user)) {
        flash_set('error', 'Only Teacher accounts can access the Teacher Module.');
        redirect_to(app_url(user_home_path($user)));
    }

    $db      = db();
    $teacher = teacher_by_user_id($db, (int) ($user['id'] ?? 0));

    if (!$teacher) {
        // Role says teacher, but no teacher record is linked — a provisioning gap.
        logout_user();
        flash_set('error', 'Your account is not linked to a teacher record. Please contact the Registrar.');
        redirect_to(app_url('login.php'));
    }

    if ((string) ($teacher['status'] ?? 'Active') !== 'Active') {
        logout_user();
        flash_set('error', 'Your teacher account is inactive. Please contact the Registrar.');
        redirect_to(app_url('login.php'));
    }

    // Force the issued default password to be replaced before anything else.
    if (teacher_must_change_password($db, (int) $user['id'])
        && basename($_SERVER['PHP_SELF'] ?? '') !== 'change_password.php') {
        redirect_to(app_url('teacher/change_password.php'));
    }

    return $teacher;
}

/** True while the account still carries the password it was issued. */
function teacher_must_change_password(mysqli $db, int $userId): bool
{
    $stmt = $db->prepare('SELECT must_change_password FROM user_account WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return (int) (stmt_fetch_assoc($stmt)['must_change_password'] ?? 0) === 1;
}

/** Replace the password and clear the first-login flag. */
function teacher_update_password(mysqli $db, int $userId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE user_account SET password = ?, must_change_password = 0 WHERE user_id = ?');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
}

/**
 * AUTHORIZATION PRIMITIVE — the single line the whole module rests on.
 *
 * Every class-scoped page and POST must call this BEFORE reading or writing
 * anything. Ownership is derived from classes.Teacher_id against the Teacher_id
 * resolved from the SESSION — never from a form field or query string.
 *
 * Fails closed: any doubt returns false.
 */
function teacher_owns_class(mysqli $db, int $classId, int $teacherId): bool
{
    if ($classId <= 0 || $teacherId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM classes WHERE Class_id = ? AND Teacher_id = ? LIMIT 1');
    $stmt->bind_param('ii', $classId, $teacherId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt) !== null;
}

/**
 * Guard helper: authorize or stop. Use at the top of any class-scoped page.
 */
function require_class_ownership(mysqli $db, int $classId, int $teacherId): void
{
    if (!teacher_owns_class($db, $classId, $teacherId)) {
        flash_set('error', 'That class is not assigned to you.');
        redirect_to(app_url('teacher/classes.php'));
    }
}
