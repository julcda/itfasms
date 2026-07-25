<?php

declare(strict_types=1);

/**
 * Unified account / credential management for the Super Admin.
 *
 * The system keeps accounts in THREE stores. This service presents them through
 * one interface so credentials can be managed in one place:
 *
 *   'staff'   user_account            staff, department heads, teachers (bcrypt)
 *   'legacy'  enrollment_users        5 legacy module logins (plaintext -> bcrypt)
 *   'student' student_portal_accounts student LRN logins (bcrypt)
 *
 * SECURITY RULES enforced here, not in the page:
 *   - Every function re-checks Super Admin. There is no trusted path.
 *   - Passwords are ALWAYS written as bcrypt, in every store. Resetting a
 *     legacy account therefore migrates it off plaintext (auth.php tries
 *     password_verify first).
 *   - Existing passwords are NEVER read back or displayed — not even the
 *     plaintext ones. A new password is shown once, at the moment it is set.
 *   - Self-protection: an admin cannot deactivate, demote, or delete themselves.
 *   - Every change is audited.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/soa_service.php';   // soa_audit()

const ACCT_MIN_PW = 8;

/** Gate for every action in this service. */
function acct_require_admin(array $user): void
{
    if (!is_super_admin($user)) {
        throw new RuntimeException('Only a Super Admin can manage accounts.');
    }
}

function acct_sources(): array
{
    return [
        'staff'   => 'Staff & Teachers',
        'legacy'  => 'Legacy Module Logins',
        'student' => 'Student Portal',
    ];
}

/**
 * One combined, searchable list across all three stores.
 *
 * @param string $source '' = all, else staff|legacy|student
 */
function acct_list(mysqli $db, string $q = '', string $source = '', string $role = '', int $limit = 300): array
{
    $q    = trim($q);
    $like = '%' . $q . '%';
    $out  = [];

    // ── user_account ────────────────────────────────────────────────────────
    if ($source === '' || $source === 'staff') {
        $sql = "SELECT u.user_id, u.username, u.email, u.role, u.status,
                       u.must_change_password, u.last_login,
                       TRIM(CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,''))) AS name,
                       t.Teacher_id, t.Designation,
                       (u.password LIKE '\$2y\$%') AS hashed
                FROM user_account u
                LEFT JOIN teacher t ON t.user_id = u.user_id
                WHERE 1=1";
        $types = ''; $params = [];
        if ($q !== '') {
            $sql .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $types .= 'ssss'; array_push($params, $like, $like, $like, $like);
        }
        if ($role !== '') { $sql .= ' AND u.role = ?'; $types .= 's'; $params[] = $role; }
        $sql .= ' ORDER BY FIELD(u.role,\'super admin\',\'admin\',\'user\',\'teacher\'), u.username LIMIT ?';
        $types .= 'i'; $params[] = $limit;

        $stmt = $db->prepare($sql);
        bind_dynamic_params($stmt, $types, $params);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $r) {
            $out[] = [
                'source'   => 'staff',
                'id'       => (int) $r['user_id'],
                'username' => (string) $r['username'],
                'name'     => (string) ($r['name'] ?: $r['username']),
                'role'     => (string) $r['role'],
                'detail'   => (string) ($r['Designation'] ?: ($r['email'] ?? '')),
                'status'   => (string) ($r['status'] ?? 'Active'),
                'must_change' => (int) ($r['must_change_password'] ?? 0) === 1,
                'last_login'  => $r['last_login'] ?? null,
                'hashed'   => (int) ($r['hashed'] ?? 0) === 1,
                'is_teacher' => $r['Teacher_id'] !== null,
            ];
        }
    }

    // ── enrollment_users ────────────────────────────────────────────────────
    if (($source === '' || $source === 'legacy') && $role === '') {
        $sql = "SELECT user_id, username, email, full_name, Role, COALESCE(status,'Active') AS status,
                       last_login, (password LIKE '\$2y\$%') AS hashed
                FROM enrollment_users WHERE 1=1";
        $types = ''; $params = [];
        if ($q !== '') {
            $sql .= ' AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
            $types .= 'sss'; array_push($params, $like, $like, $like);
        }
        $sql .= ' ORDER BY username LIMIT ?';
        $types .= 'i'; $params[] = $limit;

        $stmt = $db->prepare($sql);
        bind_dynamic_params($stmt, $types, $params);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $r) {
            $out[] = [
                'source'   => 'legacy',
                'id'       => (int) $r['user_id'],
                'username' => (string) $r['username'],
                'name'     => (string) ($r['full_name'] ?: $r['username']),
                'role'     => (string) $r['Role'],
                'detail'   => (string) ($r['email'] ?? ''),
                'status'   => (string) $r['status'],
                'must_change' => false,
                'last_login'  => $r['last_login'] ?? null,
                'hashed'   => (int) ($r['hashed'] ?? 0) === 1,
                'is_teacher' => false,
            ];
        }
    }

    // ── student_portal_accounts ─────────────────────────────────────────────
    if (($source === '' || $source === 'student') && $role === '') {
        $sql = "SELECT spa.id, spa.lrn, spa.status, spa.must_change_password, spa.last_login,
                       TRIM(CONCAT(IFNULL(si.Lastname,''),', ',IFNULL(si.Firstname,''))) AS name
                FROM student_portal_accounts spa
                LEFT JOIN studentinfo si ON si.LRN_no = spa.lrn
                WHERE 1=1";
        $types = ''; $params = [];
        if ($q !== '') {
            $sql .= ' AND (spa.lrn LIKE ? OR si.Lastname LIKE ? OR si.Firstname LIKE ?)';
            $types .= 'sss'; array_push($params, $like, $like, $like);
        }
        $sql .= ' GROUP BY spa.id ORDER BY spa.lrn LIMIT ?';
        $types .= 'i'; $params[] = $limit;

        $stmt = $db->prepare($sql);
        bind_dynamic_params($stmt, $types, $params);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $r) {
            $out[] = [
                'source'   => 'student',
                'id'       => (int) $r['id'],
                'username' => (string) $r['lrn'],
                'name'     => (string) (trim((string) $r['name'], ' ,') ?: 'Student'),
                'role'     => 'student',
                'detail'   => 'LRN login',
                'status'   => (string) $r['status'],
                'must_change' => (int) ($r['must_change_password'] ?? 0) === 1,
                'last_login'  => $r['last_login'] ?? null,
                'hashed'   => true,
                'is_teacher' => false,
            ];
        }
    }

    return $out;
}

/** Counters for the console header. */
function acct_stats(mysqli $db): array
{
    $g = static function (mysqli $db, string $sql): int {
        $r = $db->query($sql);
        return (int) ($r ? ($r->fetch_assoc()['c'] ?? 0) : 0);
    };
    return [
        'staff'      => $g($db, "SELECT COUNT(*) c FROM user_account WHERE role <> 'teacher'"),
        'teachers'   => $g($db, "SELECT COUNT(*) c FROM user_account WHERE role = 'teacher'"),
        'legacy'     => $g($db, 'SELECT COUNT(*) c FROM enrollment_users'),
        'students'   => $g($db, 'SELECT COUNT(*) c FROM student_portal_accounts'),
        'plaintext'  => $g($db, "SELECT COUNT(*) c FROM enrollment_users WHERE password NOT LIKE '\$2y\$%'"),
        'inactive'   => $g($db, "SELECT COUNT(*) c FROM user_account WHERE status <> 'Active'")
                      + $g($db, "SELECT COUNT(*) c FROM enrollment_users WHERE COALESCE(status,'Active') <> 'Active'"),
    ];
}

/** Read one account (no password ever returned). */
function acct_get(mysqli $db, string $source, int $id): ?array
{
    foreach (acct_list($db, '', $source) as $a) {
        if ($a['id'] === $id && $a['source'] === $source) {
            return $a;
        }
    }
    return null;
}

/**
 * Set a new password. Always bcrypt, in every store.
 * For 'legacy' this replaces a plaintext value with a hash — auth.php already
 * verifies hashes first, so the account keeps working and stops being plaintext.
 */
function acct_set_password(mysqli $db, string $source, int $id, string $newPassword, array $admin, bool $forceChange = true): void
{
    acct_require_admin($admin);

    if (mb_strlen($newPassword) < ACCT_MIN_PW) {
        throw new RuntimeException('Password must be at least ' . ACCT_MIN_PW . ' characters.');
    }
    $acct = acct_get($db, $source, $id);
    if (!$acct) {
        throw new RuntimeException('Account not found.');
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $mc   = $forceChange ? 1 : 0;

    switch ($source) {
        case 'staff':
            $s = $db->prepare('UPDATE user_account SET password = ?, must_change_password = ? WHERE user_id = ?');
            $s->bind_param('sii', $hash, $mc, $id);
            break;
        case 'legacy':
            // No must_change_password column on this legacy table.
            $s = $db->prepare('UPDATE enrollment_users SET password = ? WHERE user_id = ?');
            $s->bind_param('si', $hash, $id);
            break;
        case 'student':
            $s = $db->prepare('UPDATE student_portal_accounts SET password_hash = ?, must_change_password = ? WHERE id = ?');
            $s->bind_param('sii', $hash, $mc, $id);
            break;
        default:
            throw new RuntimeException('Unknown account type.');
    }
    $s->execute();

    soa_audit($db, (int) ($admin['id'] ?? 0), (string) ($admin['full_name'] ?? 'Super Admin'),
        'ACCOUNT_PASSWORD_RESET', $source, (string) $id, null,
        json_encode(['username' => $acct['username'], 'forced_change' => $forceChange,
                     'migrated_from_plaintext' => $source === 'legacy' && !$acct['hashed']]));
}

/** Rename a login. Uniqueness is checked before the write. */
function acct_set_username(mysqli $db, string $source, int $id, string $username, array $admin): void
{
    acct_require_admin($admin);

    $username = trim($username);
    if ($username === '' || mb_strlen($username) > 50) {
        throw new RuntimeException('Enter a username of 1–50 characters.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
        throw new RuntimeException('Username may contain only letters, numbers, dot, dash and underscore.');
    }
    $acct = acct_get($db, $source, $id);
    if (!$acct) {
        throw new RuntimeException('Account not found.');
    }
    if ($source === 'student') {
        throw new RuntimeException('A student\'s username is their LRN and is managed by the Registrar.');
    }

    $table = $source === 'staff' ? 'user_account' : 'enrollment_users';
    $chk = $db->prepare("SELECT 1 FROM `$table` WHERE username = ? AND user_id <> ? LIMIT 1");
    $chk->bind_param('si', $username, $id);
    $chk->execute();
    if (stmt_fetch_assoc($chk)) {
        throw new RuntimeException('That username is already taken.');
    }

    $s = $db->prepare("UPDATE `$table` SET username = ? WHERE user_id = ?");
    $s->bind_param('si', $username, $id);
    $s->execute();

    soa_audit($db, (int) ($admin['id'] ?? 0), (string) ($admin['full_name'] ?? 'Super Admin'),
        'ACCOUNT_USERNAME_CHANGE', $source, (string) $id,
        json_encode(['username' => $acct['username']]), json_encode(['username' => $username]));
}

/** Activate / deactivate. Deactivation blocks login at the login page. */
function acct_set_status(mysqli $db, string $source, int $id, string $status, array $admin): void
{
    acct_require_admin($admin);

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        throw new RuntimeException('Invalid status.');
    }
    $acct = acct_get($db, $source, $id);
    if (!$acct) {
        throw new RuntimeException('Account not found.');
    }
    // Lockout protection.
    if ($source === 'staff' && $id === (int) ($admin['id'] ?? -1) && $status === 'Inactive') {
        throw new RuntimeException('You cannot deactivate your own account.');
    }

    switch ($source) {
        case 'staff':
            $s = $db->prepare('UPDATE user_account SET status = ? WHERE user_id = ?');
            $s->bind_param('si', $status, $id);
            $s->execute();
            // Keep a teacher's own record in step (the module reads teacher.status).
            $t = $db->prepare('UPDATE teacher SET status = ? WHERE user_id = ?');
            $t->bind_param('si', $status, $id);
            $t->execute();
            break;
        case 'legacy':
            $s = $db->prepare('UPDATE enrollment_users SET status = ? WHERE user_id = ?');
            $s->bind_param('si', $status, $id);
            $s->execute();
            break;
        case 'student':
            $s = $db->prepare('UPDATE student_portal_accounts SET status = ? WHERE id = ?');
            $s->bind_param('si', $status, $id);
            $s->execute();
            break;
    }

    soa_audit($db, (int) ($admin['id'] ?? 0), (string) ($admin['full_name'] ?? 'Super Admin'),
        $status === 'Inactive' ? 'ACCOUNT_DEACTIVATE' : 'ACCOUNT_ACTIVATE', $source, (string) $id,
        json_encode(['status' => $acct['status']]), json_encode(['status' => $status, 'username' => $acct['username']]));
}

/** Change a staff role. Guarded against self-demotion. */
function acct_set_role(mysqli $db, int $userId, string $role, array $admin): void
{
    acct_require_admin($admin);

    $allowed = ['user', 'super admin', 'teacher'];
    if (!in_array($role, $allowed, true)) {
        throw new RuntimeException('Invalid role.');
    }
    if ($userId === (int) ($admin['id'] ?? -1)) {
        throw new RuntimeException('You cannot change your own role.');
    }
    $acct = acct_get($db, 'staff', $userId);
    if (!$acct) {
        throw new RuntimeException('Account not found.');
    }
    // Promoting a non-teacher to 'teacher' would produce a login with no teacher
    // record — exactly the orphan state that breaks require_teacher_login().
    if ($role === 'teacher' && !$acct['is_teacher']) {
        throw new RuntimeException('That account has no teacher record, so it cannot be given the Teacher role. Create the teacher first.');
    }
    // Never remove the last Super Admin.
    if ($acct['role'] === 'super admin' && $role !== 'super admin') {
        $r = $db->query("SELECT COUNT(*) c FROM user_account WHERE role = 'super admin' AND status = 'Active'");
        if ((int) ($r->fetch_assoc()['c'] ?? 0) <= 1) {
            throw new RuntimeException('This is the last Super Admin — promote another account first.');
        }
    }

    $s = $db->prepare('UPDATE user_account SET role = ? WHERE user_id = ?');
    $s->bind_param('si', $role, $userId);
    $s->execute();

    soa_audit($db, (int) ($admin['id'] ?? 0), (string) ($admin['full_name'] ?? 'Super Admin'),
        'ACCOUNT_ROLE_CHANGE', 'staff', (string) $userId,
        json_encode(['role' => $acct['role']]), json_encode(['role' => $role, 'username' => $acct['username']]));
}

/** A readable, reasonably strong suggestion the admin can hand over. */
function acct_suggest_password(): string
{
    $words = ['Falcon', 'Cedar', 'Harbor', 'Lantern', 'Summit', 'Meadow', 'Copper', 'Orbit', 'Willow', 'Pilot'];
    return $words[random_int(0, count($words) - 1)] . random_int(100, 999) . '!';
}

/** Badge classes per role. */
function acct_role_badge(string $role): string
{
    return match (strtolower($role)) {
        'super admin' => 'bg-rose-100 text-rose-800 border-rose-300',
        'admin'       => 'bg-violet-100 text-violet-800 border-violet-300',
        'teacher'     => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        'student'     => 'bg-sky-100 text-sky-800 border-sky-300',
        'cashier'     => 'bg-amber-100 text-amber-800 border-amber-300',
        'registrar'   => 'bg-green-100 text-green-800 border-green-300',
        default       => 'bg-slate-100 text-slate-700 border-slate-300',
    };
}
