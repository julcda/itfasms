<?php

declare(strict_types=1);

/**
 * Provision a login for every teacher.
 *
 * Creates a `user_account` row (role = 'teacher', bcrypt, must_change_password = 1)
 * for each `teacher` that has no user_id yet, and links it back via teacher.user_id.
 *
 * PREREQUISITE: run migrations/teacher_accounts.sql first (adds the 'teacher'
 * enum value, makes email NULLable, adds must_change_password/status/last_login).
 *
 * IDEMPOTENT — teachers already linked are skipped, so it is safe to re-run
 * after new teachers are added.
 *
 * USAGE
 *   CLI :  php migrations/provision_teacher_accounts.php
 *   Web :  /enrollment/migrations/provision_teacher_accounts.php   (Super Admin only)
 *
 * Usernames are derived from the teacher's name (first initial + surname),
 * sanitized — the legacy name data is dirty (e.g. Lastname = 'Kaminon,LPT') —
 * and de-duplicated with a numeric suffix.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
// TEACHER_DEFAULT_PW is defined in teacher_auth.php — the single source of truth,
// shared with teacher/change_password.php. Never redeclare it here.
require_once __DIR__ . '/../includes/teacher_auth.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_login();
    if (!is_super_admin(current_user())) {
        http_response_code(403);
        exit('Forbidden — Super Admin only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$db = db();

/** Reduce a raw name fragment to safe username characters. */
function tp_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z]/', '', $s) ?? '';   // strips ',', '.', spaces, 'LPT' stays but that's fine
    return $s;
}

/** Build a unique username, checking the DB and this run's reservations. */
function tp_unique_username(mysqli $db, string $first, string $last, array &$taken): string
{
    $f = tp_slug($first);
    $l = tp_slug($last);

    $base = ($f !== '' ? substr($f, 0, 1) : '') . $l;
    if ($base === '') {
        $base = 'teacher';
    }
    $base = substr($base, 0, 40);

    $try = $base;
    $n   = 1;
    while (true) {
        if (!isset($taken[$try])) {
            $st = $db->prepare('SELECT 1 FROM user_account WHERE username = ? LIMIT 1');
            $st->bind_param('s', $try);
            $st->execute();
            if (stmt_fetch_assoc($st) === null) {
                $taken[$try] = true;
                return $try;
            }
        }
        $n++;
        $try = $base . $n;
    }
}

// ── Preflight: the schema migration must have run ────────────────────────────
$roleType = $db->query(
    "SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_account' AND COLUMN_NAME = 'role'"
)->fetch_assoc()['t'] ?? '';
if (!str_contains((string) $roleType, 'teacher')) {
    exit("ABORTED — run migrations/teacher_accounts.sql first (user_account.role cannot hold 'teacher' yet).\n");
}

$rows = $db->query(
    "SELECT Teacher_id, Firstname, Lastname, Fullname, email
     FROM teacher
     WHERE user_id IS NULL AND status <> 'Inactive'
     ORDER BY Teacher_id"
);

$hash    = password_hash(TEACHER_DEFAULT_PW, PASSWORD_DEFAULT);
$taken   = [];
$created = 0;
$failed  = 0;
$report  = [];

echo "Provisioning teacher logins…\n\n";

while ($t = $rows->fetch_assoc()) {
    $tid   = (int) $t['Teacher_id'];
    $first = (string) $t['Firstname'];
    $last  = (string) $t['Lastname'];
    $email = trim((string) ($t['email'] ?? '')) ?: null;

    $db->begin_transaction();
    try {
        $username = tp_unique_username($db, $first, $last, $taken);

        $ins = $db->prepare(
            "INSERT INTO user_account (username, password, email, first_name, last_name, role, must_change_password, status)
             VALUES (?, ?, ?, ?, ?, 'teacher', 1, 'Active')"
        );
        $ins->bind_param('sssss', $username, $hash, $email, $first, $last);
        $ins->execute();
        $uid = (int) $db->insert_id;

        $upd = $db->prepare('UPDATE teacher SET user_id = ? WHERE Teacher_id = ?');
        $upd->bind_param('ii', $uid, $tid);
        $upd->execute();

        $db->commit();
        $created++;
        $report[] = sprintf('  #%-4d %-28s -> %s', $tid, trim($t['Fullname'] ?: "$first $last"), $username);
    } catch (Throwable $e) {
        $db->rollback();
        $failed++;
        $report[] = sprintf('  #%-4d %-28s !! %s', $tid, trim((string) $t['Fullname']), $e->getMessage());
    }
}

echo implode("\n", $report) . "\n\n";

$linked = (int) ($db->query('SELECT COUNT(*) c FROM teacher WHERE user_id IS NOT NULL')->fetch_assoc()['c'] ?? 0);
$total  = (int) ($db->query('SELECT COUNT(*) c FROM teacher')->fetch_assoc()['c'] ?? 0);

echo str_repeat('─', 60) . "\n";
echo "Created : $created\n";
echo "Failed  : $failed\n";
echo "Linked  : $linked / $total teachers now have a login\n";
echo str_repeat('─', 60) . "\n";
echo "Default password: '" . TEACHER_DEFAULT_PW . "' — every account is flagged\n";
echo "must_change_password = 1, so it is replaced on first login.\n";
echo "\nDistribute usernames to teachers, then DROP the legacy plaintext column:\n";
echo "  ALTER TABLE teacher DROP COLUMN Password;\n";
