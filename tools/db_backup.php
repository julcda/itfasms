<?php
/**
 * Automated database backup — gzipped SQL dump into storage/backups/.
 *
 * Reuses the app's own db() connection, so it needs no hardcoded credentials
 * and works identically on local XAMPP and live cPanel. Designed to be run by
 * a scheduler (cPanel Cron / Windows Task Scheduler) once a day at 5PM Manila.
 *
 * Run:            php tools/db_backup.php
 * Keep window:    edit $keepDays below (old backups are pruned).
 *
 * SECURITY: the dump contains all student data. storage/backups/ is protected
 * by an .htaccess (deny all) and excluded from git. For maximum safety on a
 * live server, point $backupDir OUTSIDE public_html (see BACKUP_DIR env var).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This backup script may only be run from the command line.\n");
}

date_default_timezone_set('Asia/Manila');           // 5PM PH time stamping

// Timezone-proof gate: with --hour=17 the script only proceeds at 17:00 Manila,
// so you can schedule cron HOURLY and not worry about the server's timezone.
foreach ($argv as $arg) {
    if (preg_match('/^--hour=(\d{1,2})$/', $arg, $m) && (int) date('G') !== (int) $m[1]) {
        exit(0);                                    // not the scheduled Manila hour — quiet no-op
    }
}

require_once __DIR__ . '/../config/database.php';

$keepDays  = 14;                                    // how many daily backups to retain
$backupDir = getenv('BACKUP_DIR') ?: (__DIR__ . '/../storage/backups');

// ── Prepare a protected backup directory ─────────────────────────────────────
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Cannot create backup dir: $backupDir\n");
    exit(1);
}
// Self-protect: block web access even if the folder sits under public_html.
$ht = $backupDir . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "Require all denied\nDeny from all\n");
}

$db     = db();
$dbName = (string) ($db->query('SELECT DATABASE()')->fetch_row()[0] ?? 'database');
$stamp  = date('Y-m-d_His');
$file   = sprintf('%s/%s_%s.sql.gz', $backupDir, $dbName, $stamp);

$gz = gzopen($file, 'wb9');
if (!$gz) {
    fwrite(STDERR, "Cannot open output file: $file\n");
    exit(1);
}

$w = static function (string $s) use ($gz): void { gzwrite($gz, $s); };

$w("-- ITFA I-SMS database backup\n");
$w('-- Database: ' . $dbName . "\n");
$w('-- Generated: ' . date('Y-m-d H:i:s') . " (Asia/Manila)\n");
$w("-- ---------------------------------------------------------------\n");
$w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

// ── Dump every base table ────────────────────────────────────────────────────
$tables = [];
$rt = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
while ($row = $rt->fetch_row()) { $tables[] = $row[0]; }

$tableCount = 0;
foreach ($tables as $table) {
    $tableCount++;
    $create = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch_assoc();
    $ddl    = (string) ($create['Create Table'] ?? '');

    $w("\n-- ----- Table: `$table` -----\n");
    $w('DROP TABLE IF EXISTS `' . $table . "`;\n");
    $w($ddl . ";\n\n");

    // Stream rows unbuffered so large tables don't exhaust memory.
    $res = $db->query('SELECT * FROM `' . $table . '`', MYSQLI_USE_RESULT);
    if (!$res) { continue; }

    $cols     = $res->fetch_fields();
    $colList  = '`' . implode('`,`', array_map(static fn($f) => $f->name, $cols)) . '`';
    $rowBuf   = [];
    $bufCount = 0;

    while ($row = $res->fetch_row()) {
        $vals = [];
        foreach ($row as $v) {
            $vals[] = ($v === null) ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'";
        }
        $rowBuf[] = '(' . implode(',', $vals) . ')';
        if (++$bufCount >= 500) {                 // flush every 500 rows
            $w('INSERT INTO `' . $table . '` (' . $colList . ") VALUES\n" . implode(",\n", $rowBuf) . ";\n");
            $rowBuf = [];
            $bufCount = 0;
        }
    }
    if ($rowBuf !== []) {
        $w('INSERT INTO `' . $table . '` (' . $colList . ") VALUES\n" . implode(",\n", $rowBuf) . ";\n");
    }
    $res->free();
}

$w("\nSET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
gzclose($gz);

$sizeMb = round(filesize($file) / 1048576, 2);
printf("Backup OK: %s (%d tables, %s MB)\n", basename($file), $tableCount, $sizeMb);

// ── Prune old backups (keep the newest $keepDays) ────────────────────────────
$all = glob($backupDir . '/' . $dbName . '_*.sql.gz') ?: [];
rsort($all);                                       // newest first (name is timestamped)
$removed = 0;
foreach (array_slice($all, $keepDays) as $old) {
    if (@unlink($old)) { $removed++; }
}
if ($removed > 0) {
    printf("Pruned %d backup(s) older than the newest %d.\n", $removed, $keepDays);
}
