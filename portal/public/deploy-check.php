<?php
/**
 * ===========================================================================
 *  ITFA Student Portal — DEPLOYMENT DIAGNOSTIC  (student.itfa.edu.ph)
 * ---------------------------------------------------------------------------
 *  Standalone health-check that runs even when Laravel itself cannot boot,
 *  so you see the REAL cause instead of a blank white 500 page.
 *
 *  Visit:  https://student.itfa.edu.ph/deploy-check.php
 *
 *  ⚠ SECURITY: this page reveals server + database details. DELETE IT the
 *    moment the portal is confirmed working. It refuses to run once
 *    APP_DEBUG=false unless you pass ?force=1.
 * ===========================================================================
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);                       // Laravel app root (…/portal)
$rows = [];                                     // [label, ok(bool|null), detail]
$add  = static function (string $label, $ok, string $detail = '') use (&$rows): void {
    $rows[] = [$label, $ok, $detail];
};

// ── Read .env (simple parser; no framework needed) ──────────────────────────
$env = [];
$envPath = $root . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'")) { $v = substr($v, 1, -1); }
        $env[trim($k)] = $v;
    }
}
$appDebug = strtolower($env['APP_DEBUG'] ?? '') === 'true';

// Refuse to expose details in a locked-down production unless forced.
if (!$appDebug && !isset($_GET['force'])) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Diagnostic disabled (APP_DEBUG=false). Append ?force=1 to run anyway, then DELETE this file.\n");
}

// ── 1) PHP version (Laravel 10 needs >= 8.1) ────────────────────────────────
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$add('PHP version ≥ 8.1', $phpOk, PHP_VERSION . ' (' . PHP_SAPI . ')');

// ── 2) Required extensions ──────────────────────────────────────────────────
foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'json', 'bcmath', 'fileinfo', 'curl', 'xml'] as $ext) {
    $add("ext: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'MISSING — enable in MultiPHP / EasyApache');
}

// ── 3) Composer autoloader ──────────────────────────────────────────────────
$autoload = $root . '/vendor/autoload.php';
$add('vendor/autoload.php', is_file($autoload), is_file($autoload) ? 'present' : 'MISSING — upload vendor/ or run composer install --no-dev');

// ── 4) .env + APP_KEY ───────────────────────────────────────────────────────
$add('.env file', is_file($envPath), is_file($envPath) ? $envPath : 'MISSING — copy .env.example to .env');
$hasKey = !empty($env['APP_KEY']) && $env['APP_KEY'] !== 'base64:';
$add('APP_KEY set', $hasKey, $hasKey ? 'set' : 'EMPTY — run: php artisan key:generate');
$add('APP_ENV', null, $env['APP_ENV'] ?? '(unset)');
$add('APP_DEBUG', null, ($env['APP_DEBUG'] ?? '(unset)') . ($appDebug ? '  ← errors WILL show on the site' : '  ← errors hidden (flip to true while debugging)'));
$add('APP_URL', ($env['APP_URL'] ?? '') === 'https://student.itfa.edu.ph', $env['APP_URL'] ?? '(unset)');

// ── 5) Writable paths ───────────────────────────────────────────────────────
foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $p) {
    $full = $root . '/' . $p;
    $ok   = is_dir($full) && is_writable($full);
    $add("writable: $p", $ok, $ok ? 'ok' : (is_dir($full) ? 'NOT writable — chmod 755/775' : 'missing dir'));
}

// ── 6) Database connection (shared enrollment DB) ───────────────────────────
$dbDetail = 'skipped';
$dbOk = null;
if (extension_loaded('pdo_mysql')) {
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? '3306';
    $name = $env['DB_DATABASE'] ?? '';
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $dbOk = true;
        $dbDetail = "connected to `$name` @ $host — $tables tables";
    } catch (Throwable $e) {
        $dbOk = false;
        $dbDetail = 'FAILED: ' . $e->getMessage();
    }
}
$add('Database connection', $dbOk, $dbDetail);

// ── 7) Try to actually boot Laravel (captures the real 500 cause) ───────────
$bootOk = null; $bootDetail = 'skipped (no autoloader)';
if (is_file($autoload)) {
    try {
        require $autoload;
        /** @var \Illuminate\Foundation\Application $app */
        $app = require $root . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $bootOk = true;
        $bootDetail = 'Laravel ' . $app->version() . ' booted cleanly';
    } catch (Throwable $e) {
        $bootOk = false;
        $bootDetail = get_class($e) . ': ' . $e->getMessage() . '  @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
}
$add('Laravel bootstrap', $bootOk, $bootDetail);

// ── Render ──────────────────────────────────────────────────────────────────
$fail = 0; foreach ($rows as $r) { if ($r[1] === false) { $fail++; } }
$icon = static fn($ok) => $ok === true ? '✅' : ($ok === false ? '❌' : 'ℹ️');
$clr  = static fn($ok) => $ok === true ? '#16a34a' : ($ok === false ? '#dc2626' : '#64748b');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Deployment Check</title>
<style>
  body{font-family:ui-sans-serif,system-ui,'Segoe UI',sans-serif;background:#0b1120;color:#e2e8f0;margin:0;padding:32px 16px;}
  .card{max-width:820px;margin:0 auto;background:#111827;border:1px solid #1f2937;border-radius:16px;overflow:hidden;}
  .head{padding:22px 26px;background:linear-gradient(135deg,#065f46,#0f766e);}
  h1{margin:0;font-size:19px;} .sub{margin-top:4px;font-size:13px;color:#d1fae5;}
  .banner{padding:12px 26px;font-weight:700;font-size:14px;}
  table{width:100%;border-collapse:collapse;font-size:13.5px;}
  td{padding:9px 26px;border-top:1px solid #1f2937;vertical-align:top;}
  td.lbl{white-space:nowrap;color:#cbd5e1;font-weight:600;width:210px;}
  td.det{color:#94a3b8;word-break:break-word;}
  .warn{margin:0;padding:14px 26px;background:#7f1d1d;color:#fecaca;font-size:12.5px;font-weight:600;}
  code{background:#1e293b;padding:1px 6px;border-radius:5px;color:#e2e8f0;}
</style></head><body>
<div class="card">
  <div class="head"><h1>ITFA Student Portal — Deployment Check</h1>
    <div class="sub">student.itfa.edu.ph &middot; <?= date('Y-m-d H:i:s') ?> &middot; docroot: <code><?= htmlspecialchars(__DIR__) ?></code></div></div>
  <div class="banner" style="background:<?= $fail ? '#7f1d1d' : '#064e3b' ?>;color:<?= $fail ? '#fecaca' : '#a7f3d0' ?>">
    <?= $fail ? "❌ $fail check(s) failed — fix the ❌ rows below." : '✅ All checks passed. The portal should load.' ?>
  </div>
  <table><?php foreach ($rows as [$label, $ok, $detail]): ?>
    <tr><td class="lbl"><?= $icon($ok) ?> <?= htmlspecialchars($label) ?></td>
        <td class="det" style="color:<?= $clr($ok) ?>"><?= htmlspecialchars($detail) ?></td></tr>
  <?php endforeach; ?></table>
  <p class="warn">⚠ SECURITY — DELETE THIS FILE (<code>public/deploy-check.php</code>) once the portal works. It exposes server and database details.</p>
</div>
</body></html>
