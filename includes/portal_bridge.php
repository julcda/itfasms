<?php

declare(strict_types=1);

/**
 * SSO handoff into the Laravel Classroom (LMS) app.
 *
 * The Classroom lives in a separate Laravel codebase (portal/) sharing this
 * same database, but teachers must NEVER see a second login screen — per
 * design decision, LMS access is reached from inside their existing native
 * teacher session. This issues a one-time, 60-second, single-use ticket
 * (classroom_sso_tickets, owned by the Laravel migration) that the Laravel
 * app exchanges for a Teacher session on arrival.
 *
 * The ticket value itself IS the credential (64 random hex chars, unguessable,
 * single-use via `used_at`, short-lived via `expires_at`) — the same trust
 * model as a password-reset token. No extra HMAC layer needed on top.
 */

require_once __DIR__ . '/../config/database.php';

/** Base URL of the Laravel Classroom app. Override via env for deployment. */
function classroom_portal_url(): string
{
    $url = getenv('CLASSROOM_PORTAL_URL') ?: 'http://127.0.0.1:8000';
    return rtrim($url, '/');
}

/**
 * Mint a single-use ticket for this teacher and return the full URL to send
 * their browser to. $redirectPath is where the Laravel app lands them after
 * the ticket is consumed (e.g. '/teacher' or '/teacher/classes/123').
 */
function classroom_sso_url(mysqli $db, int $teacherId, string $redirectPath = '/teacher'): string
{
    $ticket = bin2hex(random_bytes(32));

    $stmt = $db->prepare(
        'INSERT INTO classroom_sso_tickets (ticket, teacher_id, redirect_path, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))'
    );
    $stmt->bind_param('sis', $ticket, $teacherId, $redirectPath);
    $stmt->execute();

    return classroom_portal_url() . '/sso/teacher/' . $ticket;
}
