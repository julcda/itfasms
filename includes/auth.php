<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function authenticate_user(string $username, string $password): ?array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return null;
    }

    $connection = db();

    // Primary auth source for this project.
    $user = authenticate_enrollment_user($connection, $username, $password);
    if ($user !== null) {
        return $user;
    }

    // Fallback for legacy account table with hashed passwords.
    return authenticate_legacy_user($connection, $username, $password);
}

function authenticate_enrollment_user(mysqli $connection, string $username, string $password): ?array
{
    try {
        $stmt = $connection->prepare(
            'SELECT user_id, username, email, password, full_name, Role,
                    COALESCE(status, \'Active\') AS status
             FROM enrollment_users
             WHERE username = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = stmt_fetch_assoc($stmt);
        if (!$row) {
            return null;
        }

        // Deactivated legacy staff accounts fail at the door, same as user_account.
        if (strcasecmp((string) ($row['status'] ?? 'Active'), 'Active') !== 0) {
            return null;
        }

        // NOTE: password_verify() is tried FIRST, so a bcrypt hash written by the
        // Super Admin console authenticates normally. The hash_equals() branch is
        // the legacy plaintext fallback and should be removed once every
        // enrollment_users row has been reset to a hash.
        $storedPassword = (string) ($row['password'] ?? '');
        $validPassword = password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);
        if (!$validPassword) {
            return null;
        }

        return [
            'id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'full_name' => (string) $row['full_name'],
            'role' => (string) ($row['Role'] ?: 'User'),
            'email' => (string) $row['email'],
        ];
    } catch (Throwable) {
        return null;
    }
}

function authenticate_legacy_user(mysqli $connection, string $username, string $password): ?array
{
    try {
        $stmt = $connection->prepare(
            'SELECT user_id, username, email, password, first_name, last_name, role, status
             FROM user_account
             WHERE username = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = stmt_fetch_assoc($stmt);
        if (!$row) {
            return null;
        }

        // A deactivated account must fail at the door — not merely be bounced by a
        // page guard after a session already exists. This covers deactivated
        // teachers AND any deactivated staff/department-head account.
        if (strcasecmp((string) ($row['status'] ?? 'Active'), 'Active') !== 0) {
            return null;
        }

        $storedPassword = (string) ($row['password'] ?? '');
        $validPassword = password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);
        if (!$validPassword) {
            return null;
        }

        $fullName = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));

        return [
            'id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'full_name' => $fullName !== '' ? $fullName : (string) $row['username'],
            'role' => ucfirst((string) ($row['role'] ?: 'user')),
            'email' => (string) $row['email'],
        ];
    } catch (Throwable) {
        return null;
    }
}
