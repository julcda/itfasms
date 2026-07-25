<?php

declare(strict_types=1);

/**
 * Student Portal authentication.
 *
 * Students are NOT staff: they live in their own session namespace
 * ($_SESSION['student_user']) so the staff role helpers (is_super_admin, …)
 * can never match a student. Accounts are provisioned lazily on first login.
 *
 * Login username = LRN. Default password = "password" (see STUDENT_DEFAULT_PW),
 * stored bcrypt-hashed; first login forces a password change.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

const STUDENT_DEFAULT_PW = 'password';

/** The currently active school year as ['id'=>int,'label'=>string]. */
function student_active_sy(mysqli $db): array
{
    $res = $db->query('SELECT School_year_id, School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;
    return $row
        ? ['id' => (int) $row['School_year_id'], 'label' => (string) $row['School_year']]
        : ['id' => 0, 'label' => ''];
}

/**
 * Resolve an officially-enrolled student in the active SY by LRN, using the
 * same New-then-Old profile precedence as the cashier module
 * (preregistration by id, else old_studentprofile by student_id).
 *
 * @return array<string,mixed>|null
 */
function student_resolve_by_lrn(mysqli $db, string $lrn, string $syLabel): ?array
{
    $stmt = $db->prepare(
        "SELECT e.id AS enrollment_id, e.student_id, e.Status,
                e.Department, e.Department_gradelevel, e.Department_section,
                e.Student_classification, e.school_year,
                COALESCE(p.lrn, osp.lrn)             AS lrn,
                COALESCE(p.surname, osp.surname)     AS surname,
                COALESCE(p.firstname, osp.firstname) AS firstname,
                COALESCE(p.middlename, osp.middlename) AS middlename,
                COALESCE(p.contact, osp.contact)     AS contact,
                COALESCE(p.email, osp.email)         AS email,
                COALESCE(p.photo, '')                AS photo,
                IF(p.id IS NOT NULL, 'New', 'Old')   AS student_type
         FROM enrollment e
         LEFT JOIN preregistration p      ON e.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = e.student_id)
         WHERE e.school_year = ? AND e.Status = 'Officially Enrolled'
           AND COALESCE(p.lrn, osp.lrn) = ?
         ORDER BY e.id DESC
         LIMIT 1"
    );
    $stmt->bind_param('ss', $syLabel, $lrn);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** True if the LRN exists anywhere (any SY / any status) — used to tell apart
 *  "LRN doesn't exist" from "exists but not enrolled this SY". */
function student_lrn_exists(mysqli $db, string $lrn): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM preregistration WHERE lrn = ?
         UNION SELECT 1 FROM old_studentprofile WHERE lrn = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $lrn, $lrn);
    $stmt->execute();
    return stmt_fetch_assoc($stmt) !== null;
}

/** Fetch the portal account for an enrollment, or null. */
function student_get_account(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare('SELECT * FROM student_portal_accounts WHERE enrollment_id = ? LIMIT 1');
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/** Create a portal account on first login (default password, must change). */
function student_provision_account(mysqli $db, array $resolved): array
{
    $enrollmentId = (int) $resolved['enrollment_id'];
    $studentId    = (string) $resolved['student_id'];
    $lrn          = (string) $resolved['lrn'];
    $hash         = password_hash(STUDENT_DEFAULT_PW, PASSWORD_DEFAULT);

    $stmt = $db->prepare(
        'INSERT INTO student_portal_accounts (enrollment_id, student_id, lrn, password_hash, must_change_password, status)
         VALUES (?, ?, ?, ?, 1, \'Active\')'
    );
    $stmt->bind_param('isss', $enrollmentId, $studentId, $lrn, $hash);
    $stmt->execute();
    return student_get_account($db, $enrollmentId) ?? [];
}

/**
 * Attempt a login.
 *
 * @return array{ok:bool,error?:string,student?:array}
 */
function student_login(mysqli $db, string $lrn, string $password): array
{
    $lrn = trim($lrn);
    if ($lrn === '' || $password === '') {
        return ['ok' => false, 'error' => 'Please enter your LRN and password.'];
    }

    $sy = student_active_sy($db);
    if ($sy['id'] === 0) {
        return ['ok' => false, 'error' => 'No active school year is configured. Please contact the registrar.'];
    }

    $resolved = student_resolve_by_lrn($db, $lrn, $sy['label']);
    if (!$resolved) {
        // Distinguish the two not-found cases for a clearer message.
        if (student_lrn_exists($db, $lrn)) {
            return ['ok' => false, 'error' => 'This LRN is not officially enrolled for S.Y. ' . $sy['label'] . '. Please contact the registrar.'];
        }
        return ['ok' => false, 'error' => 'LRN not found. Please check the number and try again.'];
    }

    $account = student_get_account($db, (int) $resolved['enrollment_id']);
    if (!$account) {
        $account = student_provision_account($db, $resolved);
    }
    if (($account['status'] ?? 'Active') !== 'Active') {
        return ['ok' => false, 'error' => 'Your portal account is inactive. Please contact the registrar.'];
    }

    if (!password_verify($password, (string) $account['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid password.'];
    }

    // Success — record login and open the session.
    $upd = $db->prepare('UPDATE student_portal_accounts SET last_login = NOW() WHERE id = ?');
    $aid = (int) $account['id'];
    $upd->bind_param('i', $aid);
    $upd->execute();

    student_set_session($resolved, $account);
    return ['ok' => true, 'student' => current_student()];
}

/** Store the minimal identity in the session; display data is re-fetched live. */
function student_set_session(array $resolved, array $account): void
{
    session_regenerate_id(true);
    $_SESSION['student_user'] = [
        'account_id'    => (int) $account['id'],
        'enrollment_id' => (int) $resolved['enrollment_id'],
        'student_id'    => (string) $resolved['student_id'],
        'lrn'           => (string) $resolved['lrn'],
        'name'          => trim(($resolved['firstname'] ?? '') . ' ' . ($resolved['surname'] ?? '')),
    ];
}

function current_student(): ?array
{
    return isset($_SESSION['student_user']) && is_array($_SESSION['student_user'])
        ? $_SESSION['student_user']
        : null;
}

function is_student_logged_in(): bool
{
    return current_student() !== null;
}

function student_logout(): void
{
    unset($_SESSION['student_user']);
}

/**
 * Page guard. Ensures a logged-in student and (by default) forces a pending
 * password change. Returns the live account row. Pages that should remain
 * reachable while the password is still default (the change page itself) pass
 * $enforcePasswordChange = false.
 *
 * @return array the live student_portal_accounts row
 */
function require_student_login(bool $enforcePasswordChange = true): array
{
    $sess = current_student();
    if (!$sess) {
        flash_set('error', 'Please log in to access the student portal.');
        redirect_to(app_url('student/login.php'));
    }

    $db = db();
    $account = student_get_account($db, (int) $sess['enrollment_id']);
    if (!$account || ($account['status'] ?? '') !== 'Active') {
        student_logout();
        flash_set('error', 'Your session is no longer valid. Please log in again.');
        redirect_to(app_url('student/login.php'));
    }

    if ($enforcePasswordChange && (int) $account['must_change_password'] === 1) {
        redirect_to(app_url('student/change_password.php'));
    }

    return $account;
}

/** Update the password and clear the first-login flag. */
function student_update_password(mysqli $db, int $accountId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE student_portal_accounts SET password_hash = ?, must_change_password = 0 WHERE id = ?');
    $stmt->bind_param('si', $hash, $accountId);
    $stmt->execute();
}

/**
 * Live profile + enrollment + assessment bundle for the logged-in student,
 * used by the dashboard / SOA / account pages.
 *
 * @return array<string,mixed>|null
 */
function student_profile(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare(
        "SELECT e.id AS enrollment_id, e.student_id, e.school_year, e.Status,
                e.Department, e.Department_gradelevel, e.Department_section, e.Student_classification,
                COALESCE(p.lrn, osp.lrn)             AS lrn,
                COALESCE(p.surname, osp.surname)     AS surname,
                COALESCE(p.firstname, osp.firstname) AS firstname,
                COALESCE(p.middlename, osp.middlename) AS middlename,
                COALESCE(p.contact, osp.contact)     AS contact,
                COALESCE(p.email, osp.email)         AS email,
                COALESCE(p.sex, osp.sex)             AS sex,
                COALESCE(p.photo, '')                AS photo,
                IF(p.id IS NOT NULL, 'New', 'Old')   AS student_type,
                IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR)) AS grade_name,
                IFNULL(sc.Section_name, e.Department_section) AS section_name,
                pbc.name AS classification_name
         FROM enrollment e
         LEFT JOIN preregistration p      ON e.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = e.student_id)
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = e.Department_section
         LEFT JOIN (SELECT classification_id, MAX(classification) AS name FROM payment_breakdown GROUP BY classification_id) pbc
                ON pbc.classification_id = e.Student_classification
         WHERE e.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * Resolve the student's photo URL: a portal-uploaded photo wins, then the
 * preregistration photo if the file actually exists, else null (caller shows
 * an initials avatar).
 */
function student_photo_url(array $profile): ?string
{
    $root = dirname(__DIR__);
    $enrollmentId = (int) ($profile['enrollment_id'] ?? 0);

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $rel = 'uploads/student_photos/' . $enrollmentId . '.' . $ext;
        if (is_file($root . '/' . $rel)) {
            return app_url($rel) . '?v=' . filemtime($root . '/' . $rel);
        }
    }
    $legacy = trim((string) ($profile['photo'] ?? ''));
    if ($legacy !== '') {
        foreach (['uploads/' . $legacy, 'admission/uploads/' . $legacy, $legacy] as $rel) {
            if (is_file($root . '/' . $rel)) {
                return app_url($rel);
            }
        }
    }
    return null;
}
