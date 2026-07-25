<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    return is_string($token) && is_string($sessionToken) && hash_equals($sessionToken, $token);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function to_int(mixed $value): int
{
    return (int) ($value ?? 0);
}

function is_int_input(mixed $value): bool
{
    if (is_int($value)) {
        return true;
    }

    if (!is_string($value)) {
        return false;
    }

    $trimmed = trim($value);

    return $trimmed !== '' && preg_match('/^-?\d+$/', $trimmed) === 1;
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function app_base_url(): string
{
    $base = getenv('APP_BASE_URL') ?: '/enrollment';

    return rtrim($base, '/');
}

function app_url(string $path = ''): string
{
    $trimmedPath = ltrim($path, '/');
    if ($trimmedPath === '') {
        return app_base_url() . '/';
    }

    return app_base_url() . '/' . $trimmedPath;
}

function login_user(array $user): void
{
    $_SESSION['auth_user'] = [
        'id' => (int) ($user['id'] ?? 0),
        'username' => (string) ($user['username'] ?? ''),
        'full_name' => (string) ($user['full_name'] ?? ''),
        'role' => (string) ($user['role'] ?? 'User'),
        'email' => (string) ($user['email'] ?? ''),
    ];
}

function current_user(): ?array
{
    if (!isset($_SESSION['auth_user']) || !is_array($_SESSION['auth_user'])) {
        return null;
    }

    return $_SESSION['auth_user'];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }

    flash_set('error', 'Please log in to continue.');
    redirect_to(app_url('login.php'));
}

function logout_user(): void
{
    unset($_SESSION['auth_user']);
}

function normalized_role(?string $role): string
{
    return strtolower(trim((string) $role));
}

function is_enrollment_user(?array $user = null): bool
{
    $contextUser = $user ?? current_user();
    if (!is_array($contextUser)) {
        return false;
    }

    return normalized_role((string) ($contextUser['role'] ?? '')) === 'enrollment';
}

function is_cashier_user(?array $user = null): bool
{
    $contextUser = $user ?? current_user();
    if (!is_array($contextUser)) {
        return false;
    }

    return normalized_role((string) ($contextUser['role'] ?? '')) === 'cashier';
}

function is_registrar_user(?array $user = null): bool
{
    $contextUser = $user ?? current_user();
    if (!is_array($contextUser)) {
        return false;
    }

    return normalized_role((string) ($contextUser['role'] ?? '')) === 'registrar';
}

function is_depthead_user(?array $user = null): bool
{
    $contextUser = $user ?? current_user();
    if (!is_array($contextUser)) {
        return false;
    }

    // user_account rows have role 'user' (dept heads) or 'admin' (principal)
    // enrollment_users never has role 'user', so this safely identifies user_account logins
    return normalized_role((string) ($contextUser['role'] ?? '')) === 'user';
}

function is_depthead_admin(?array $user = null): bool
{
    $contextUser = $user ?? current_user();
    if (!is_array($contextUser)) {
        return false;
    }

    return normalized_role((string) ($contextUser['role'] ?? '')) === 'admin';
}

function is_super_admin(?array $user = null): bool
{
    $contextUser = $user ?? current_user();
    if (!is_array($contextUser)) {
        return false;
    }

    return normalized_role((string) ($contextUser['role'] ?? '')) === 'super admin';
}

function is_teacher_user(?array $user = null): bool
{
    $contextUser = $user ?? current_user();
    if (!is_array($contextUser)) {
        return false;
    }

    return normalized_role((string) ($contextUser['role'] ?? '')) === 'teacher';
}

function user_home_path(?array $user = null): string
{
    if (is_enrollment_user($user)) {
        return 'enrollment/index.php';
    }

    if (is_cashier_user($user)) {
        return 'cashier/index.php';
    }

    if (is_registrar_user($user)) {
        return 'registrar/index.php';
    }

    if (is_teacher_user($user)) {
        return 'teacher/index.php';
    }

    if (is_depthead_user($user)) {
        return 'depthead/index.php';
    }

    if (is_depthead_admin($user)) {
        return 'depthead/index.php';
    }

    if (is_super_admin($user)) {
        return 'depthead/index.php';
    }

    return 'dashboard/index.php';
}

function bind_dynamic_params(mysqli_stmt $statement, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = &$params[$index];
    }

    $statement->bind_param($types, ...$refs);
}

function stmt_fetch_all_assoc(mysqli_stmt $statement): array
{
    $metadata = $statement->result_metadata();
    if ($metadata === false) {
        return [];
    }

    $fields = $metadata->fetch_fields();
    $row = [];
    $values = [];

    foreach ($fields as $field) {
        $row[$field->name] = null;
        $values[] = &$row[$field->name];
    }

    $statement->store_result();
    $statement->bind_result(...$values);

    $rows = [];
    while ($statement->fetch()) {
        $currentRow = [];
        foreach ($fields as $field) {
            $currentRow[$field->name] = $row[$field->name];
        }
        $rows[] = $currentRow;
    }

    $statement->free_result();

    return $rows;
}

function stmt_fetch_assoc(mysqli_stmt $statement): ?array
{
    return stmt_fetch_all_assoc($statement)[0] ?? null;
}

function next_table_id(mysqli $connection, string $table, string $idColumn): int
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $idColumn)) {
        throw new InvalidArgumentException('Invalid table or column name.');
    }

    $sql = sprintf(
        'SELECT COALESCE(MAX(`%s`), 0) + 1 AS next_id FROM `%s`',
        $idColumn,
        $table
    );
    $result = $connection->query($sql);
    $row = $result ? $result->fetch_assoc() : null;

    return max(1, (int) ($row['next_id'] ?? 1));
}

function normalize_studentinfo_department(string $department): string
{
    return match (strtolower(trim($department))) {
        'elementary' => 'ELEMENTARY',
        'junior high', 'junior high school', 'jhs' => 'JUNIOR HIGH',
        'senior high', 'senior high school', 'shs' => 'SENIOR HIGH',
        default => strtoupper(trim($department)),
    };
}

function resolve_school_year_id_by_label(mysqli $connection, string $schoolYearLabel): int
{
    $label = trim($schoolYearLabel);
    if ($label === '') {
        return 0;
    }

    $statement = $connection->prepare('SELECT School_year_id FROM schoolyear WHERE School_year = ? LIMIT 1');
    $statement->bind_param('s', $label);
    $statement->execute();

    return (int) (stmt_fetch_assoc($statement)['School_year_id'] ?? 0);
}

function resolve_studentinfo_id_for_enrollment(mysqli $connection, int $enrollmentId, bool $createIfMissing = true): int
{
    if ($enrollmentId <= 0) {
        return 0;
    }

    $statement = $connection->prepare(
        "SELECT
            en.id,
            en.student_id,
            en.school_year,
            en.Department,
            en.Department_gradelevel,
            en.Department_section,
            en.Student_classification,
            COALESCE(p.lrn, osp.lrn, '') AS lrn,
            COALESCE(p.surname, osp.surname, '') AS surname,
            COALESCE(p.firstname, osp.firstname, '') AS firstname,
            COALESCE(p.middlename, osp.middlename, '') AS middlename
         FROM enrollment en
         LEFT JOIN preregistration p ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         WHERE en.id = ?
         LIMIT 1"
    );
    $statement->bind_param('i', $enrollmentId);
    $statement->execute();
    $record = stmt_fetch_assoc($statement);

    if (!$record) {
        return 0;
    }

    $schoolYearId = resolve_school_year_id_by_label($connection, (string) ($record['school_year'] ?? ''));
    if ($schoolYearId <= 0) {
        return 0;
    }

    $lrnDigits = preg_replace('/\D+/', '', (string) ($record['lrn'] ?? '')) ?? '';
    $lrnValue = $lrnDigits !== '' ? $lrnDigits : null;
    $lastName = trim((string) ($record['surname'] ?? ''));
    $firstName = trim((string) ($record['firstname'] ?? ''));
    $middleName = trim((string) ($record['middlename'] ?? ''));
    $typeId = to_int($record['Student_classification'] ?? 0);
    if ($typeId <= 0) {
        $typeId = 10;
    }

    $department = normalize_studentinfo_department((string) ($record['Department'] ?? ''));
    $gradeLevelId = to_int($record['Department_gradelevel'] ?? 0);
    $sectionId = to_int($record['Department_section'] ?? 0);
    $gradeLevelValue = $gradeLevelId > 0 ? $gradeLevelId : null;
    $sectionValue = $sectionId > 0 ? $sectionId : null;
    $status = 1;
    $password = '12345';

    $studentInfoId = 0;
    if ($lrnValue !== null) {
        $lookup = $connection->prepare('SELECT student_id FROM studentinfo WHERE LRN_no = ? AND School_year_id = ? LIMIT 1');
        $lookup->bind_param('si', $lrnValue, $schoolYearId);
        $lookup->execute();
        $studentInfoId = (int) (stmt_fetch_assoc($lookup)['student_id'] ?? 0);
    }

    if ($studentInfoId <= 0 && $lastName !== '' && $firstName !== '') {
        $lookup = $connection->prepare(
            'SELECT student_id FROM studentinfo WHERE Lastname = ? AND Firstname = ? AND Middlename = ? AND School_year_id = ? LIMIT 1'
        );
        $lookup->bind_param('sssi', $lastName, $firstName, $middleName, $schoolYearId);
        $lookup->execute();
        $studentInfoId = (int) (stmt_fetch_assoc($lookup)['student_id'] ?? 0);
    }

    if ($studentInfoId > 0) {
        $update = $connection->prepare(
            'UPDATE studentinfo
             SET LRN_no = ?, Lastname = ?, Firstname = ?, Middlename = ?, Type = ?, Department = ?, Gradelevel = ?, Section = ?, School_year_id = ?, Status = ?
             WHERE student_id = ?'
        );
        $update->bind_param(
            'ssssissiiii',
            $lrnValue,
            $lastName,
            $firstName,
            $middleName,
            $typeId,
            $department,
            $gradeLevelValue,
            $sectionValue,
            $schoolYearId,
            $status,
            $studentInfoId
        );
        $update->execute();

        return $studentInfoId;
    }

    if (!$createIfMissing) {
        return 0;
    }

    $insert = $connection->prepare(
        'INSERT INTO studentinfo (LRN_no, Lastname, Firstname, Middlename, Type, Department, Gradelevel, Section, School_year_id, Status, password)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->bind_param(
        'ssssissiiis',
        $lrnValue,
        $lastName,
        $firstName,
        $middleName,
        $typeId,
        $department,
        $gradeLevelValue,
        $sectionValue,
        $schoolYearId,
        $status,
        $password
    );
    $insert->execute();

    return (int) $insert->insert_id;
}

/**
 * Convert a peso amount to English words, e.g. 12345.50 ->
 * "Twelve Thousand Three Hundred Forty-Five Pesos and 50/100".
 * Shared by the Deposit Certification and Promissory Note printouts.
 */
function peso_in_words(float $amount): string
{
    $amount = round($amount, 2);
    $pesos  = (int) floor($amount);
    $cents  = (int) round(($amount - $pesos) * 100);

    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $threeDigits = static function (int $n) use ($ones, $tens): string {
        $out = '';
        if ($n >= 100) {
            $out .= $ones[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
            if ($n) { $out .= ' '; }
        }
        if ($n >= 20) {
            $out .= $tens[intdiv($n, 10)];
            if ($n % 10) { $out .= '-' . $ones[$n % 10]; }
        } elseif ($n > 0) {
            $out .= $ones[$n];
        }
        return $out;
    };

    if ($pesos === 0) {
        $words = 'Zero';
    } else {
        $scales = [1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 1 => ''];
        $words = '';
        $remaining = $pesos;
        foreach ($scales as $value => $name) {
            if ($remaining >= $value) {
                $chunk = intdiv($remaining, $value);
                $remaining %= $value;
                $words .= ($words ? ' ' : '') . $threeDigits($chunk) . ($name ? ' ' . $name : '');
            }
        }
    }

    return trim($words) . ' Pesos and ' . sprintf('%02d', $cents) . '/100';
}
