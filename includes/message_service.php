<?php

declare(strict_types=1);

/**
 * Teacher messaging — 1-to-1 chat between staff accounts.
 *
 * SECURITY MODEL
 *   A conversation row stores its two participants in canonical order
 *   (user_low < user_high). msg_can_access() is the single authorization
 *   predicate: you may read a conversation only if your user_id is one of them.
 *   Every read and write goes through it. Bodies are stored raw and escaped
 *   with h() at render time — never trusted, never rendered unescaped.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

const MSG_MAX_LEN = 2000;

function msg_schema_ready(mysqli $db): bool
{
    foreach (['chat_conversation', 'chat_message'] as $t) {
        $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
        if (!$r || $r->num_rows === 0) {
            return false;
        }
    }
    return true;
}

/**
 * AUTHORIZATION PRIMITIVE — may this user read/write this conversation?
 * Fails closed.
 */
function msg_can_access(mysqli $db, int $conversationId, int $userId): bool
{
    if ($conversationId <= 0 || $userId <= 0) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1 FROM chat_conversation WHERE id = ? AND (user_low = ? OR user_high = ?) LIMIT 1'
    );
    $stmt->bind_param('iii', $conversationId, $userId, $userId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt) !== null;
}

/**
 * Find (or create) the conversation between two people.
 * Canonical ordering + the UNIQUE key make this race-safe: two simultaneous
 * opens converge on one row instead of creating duplicate threads.
 */
function msg_conversation_with(mysqli $db, int $meId, int $otherId): int
{
    if ($meId <= 0 || $otherId <= 0 || $meId === $otherId) {
        throw new RuntimeException('Invalid conversation.');
    }
    $low  = min($meId, $otherId);
    $high = max($meId, $otherId);

    $stmt = $db->prepare('SELECT id FROM chat_conversation WHERE user_low = ? AND user_high = ? LIMIT 1');
    $stmt->bind_param('ii', $low, $high);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    if ($row) {
        return (int) $row['id'];
    }

    $ins = $db->prepare('INSERT INTO chat_conversation (user_low, user_high) VALUES (?, ?)');
    $ins->bind_param('ii', $low, $high);
    try {
        $ins->execute();
        return (int) $db->insert_id;
    } catch (Throwable $e) {
        // Lost the race — the other side created it first. Read it back.
        $stmt->execute();
        $row = stmt_fetch_assoc($stmt);
        if ($row) {
            return (int) $row['id'];
        }
        throw $e;
    }
}

/** The signed-in user's inbox, most recent first, with unread counts. */
function msg_inbox(mysqli $db, int $meId): array
{
    $stmt = $db->prepare(
        "SELECT c.id, c.last_message, c.last_message_at,
                IF(c.user_low = ?, c.user_high, c.user_low) AS other_id,
                u.username, u.first_name, u.last_name, u.role, u.status AS other_status,
                t.Fullname AS teacher_name, t.Designation,
                (SELECT COUNT(*) FROM chat_message m
                  WHERE m.conversation_id = c.id AND m.sender_id <> ? AND m.read_at IS NULL) AS unread
         FROM chat_conversation c
         JOIN user_account u ON u.user_id = IF(c.user_low = ?, c.user_high, c.user_low)
         LEFT JOIN teacher t ON t.user_id = u.user_id
         WHERE c.user_low = ? OR c.user_high = ?
         ORDER BY c.last_message_at IS NULL, c.last_message_at DESC, c.id DESC"
    );
    $stmt->bind_param('iiiii', $meId, $meId, $meId, $meId, $meId);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    foreach ($rows as &$r) {
        $r['display_name'] = msg_display_name($r);
    }
    return $rows;
}

/** Total unread messages — drives the sidebar badge. */
function msg_unread_total(mysqli $db, int $meId): int
{
    if (!msg_schema_ready($db)) {
        return 0;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) c
         FROM chat_message m
         JOIN chat_conversation cv ON cv.id = m.conversation_id
         WHERE (cv.user_low = ? OR cv.user_high = ?)
           AND m.sender_id <> ? AND m.read_at IS NULL'
    );
    $stmt->bind_param('iii', $meId, $meId, $meId);
    $stmt->execute();
    return (int) (stmt_fetch_assoc($stmt)['c'] ?? 0);
}

/** Messages in a conversation (oldest first). Caller MUST have authorized. */
function msg_thread(mysqli $db, int $conversationId, int $limit = 200): array
{
    $stmt = $db->prepare(
        'SELECT m.id, m.sender_id, m.body, m.created_at, m.read_at,
                u.first_name, u.last_name, t.Fullname AS teacher_name
         FROM chat_message m
         JOIN user_account u ON u.user_id = m.sender_id
         LEFT JOIN teacher t ON t.user_id = u.user_id
         WHERE m.conversation_id = ?
         ORDER BY m.id DESC LIMIT ?'
    );
    $stmt->bind_param('ii', $conversationId, $limit);
    $stmt->execute();
    return array_reverse(stmt_fetch_all_assoc($stmt));
}

/** The other participant's account row. */
function msg_other_party(mysqli $db, int $conversationId, int $meId): ?array
{
    $stmt = $db->prepare(
        "SELECT u.user_id, u.username, u.first_name, u.last_name, u.role, u.status,
                t.Fullname AS teacher_name, t.Designation, t.Teacher_id
         FROM chat_conversation c
         JOIN user_account u ON u.user_id = IF(c.user_low = ?, c.user_high, c.user_low)
         LEFT JOIN teacher t ON t.user_id = u.user_id
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('ii', $meId, $conversationId);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    if ($row) {
        $row['display_name'] = msg_display_name($row);
    }
    return $row;
}

/** Send a message. Authorization is re-checked here — callers cannot skip it. */
function msg_send(mysqli $db, int $conversationId, int $senderId, string $body): int
{
    if (!msg_can_access($db, $conversationId, $senderId)) {
        throw new RuntimeException('You are not part of that conversation.');
    }
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('Type a message first.');
    }
    if (mb_strlen($body) > MSG_MAX_LEN) {
        throw new RuntimeException('Message is too long (max ' . number_format(MSG_MAX_LEN) . ' characters).');
    }

    $db->begin_transaction();
    try {
        $ins = $db->prepare('INSERT INTO chat_message (conversation_id, sender_id, body) VALUES (?, ?, ?)');
        $ins->bind_param('iis', $conversationId, $senderId, $body);
        $ins->execute();
        $id = (int) $db->insert_id;

        $preview = mb_substr($body, 0, 160);
        $upd = $db->prepare('UPDATE chat_conversation SET last_message_at = NOW(), last_message = ? WHERE id = ?');
        $upd->bind_param('si', $preview, $conversationId);
        $upd->execute();

        $db->commit();
        return $id;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** Mark everything the OTHER person sent as read. */
function msg_mark_read(mysqli $db, int $conversationId, int $meId): void
{
    if (!msg_can_access($db, $conversationId, $meId)) {
        return;
    }
    $stmt = $db->prepare(
        'UPDATE chat_message SET read_at = NOW()
         WHERE conversation_id = ? AND sender_id <> ? AND read_at IS NULL'
    );
    $stmt->bind_param('ii', $conversationId, $meId);
    $stmt->execute();
}

/**
 * People this user can start a conversation with.
 * ACTIVE teachers only — a deactivated teacher must not be contactable, matching
 * the rule that they vanish from every other picker.
 */
function msg_contacts(mysqli $db, int $meId, string $q = '', int $limit = 50): array
{
    $sql =
        "SELECT u.user_id, u.username, u.first_name, u.last_name,
                t.Fullname AS teacher_name, t.Designation
         FROM user_account u
         JOIN teacher t ON t.user_id = u.user_id
         WHERE u.role = 'teacher' AND u.status = 'Active' AND t.status = 'Active'
           AND u.user_id <> ?";
    $types  = 'i';
    $params = [$meId];

    $q = trim($q);
    if ($q !== '') {
        $sql   .= " AND (t.Fullname LIKE ? OR t.Firstname LIKE ? OR t.Lastname LIKE ? OR u.username LIKE ?)";
        $like   = '%' . $q . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    $sql   .= ' ORDER BY t.Lastname, t.Firstname LIMIT ?';
    $types .= 'i';
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    foreach ($rows as &$r) {
        $r['display_name'] = msg_display_name($r);
    }
    return $rows;
}

/** Best available display name for a participant row. */
function msg_display_name(array $r): string
{
    $full = trim((string) ($r['teacher_name'] ?? ''));
    if ($full !== '' && strtoupper($full) !== 'N/A') {
        return $full;
    }
    $n = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
    return $n !== '' ? $n : (string) ($r['username'] ?? 'User');
}

/** Two-letter initials for the avatar bubble. */
function msg_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = mb_substr($parts[count($parts) - 1] ?? '', 0, 1);
    return strtoupper($a . ($b !== $a ? $b : ''));
}

/** Compact relative timestamp for chat bubbles and the inbox. */
function msg_when(string $ts): string
{
    $t = strtotime($ts);
    if ($t === false) {
        return '';
    }
    $diff = time() - $t;
    if ($diff < 60)     { return 'just now'; }
    if ($diff < 3600)   { return floor($diff / 60) . 'm ago'; }
    if ($diff < 86400)  { return date('g:ia', $t); }
    if ($diff < 172800) { return 'yesterday'; }
    if ($diff < 604800) { return date('D g:ia', $t); }
    return date('M j', $t);
}
