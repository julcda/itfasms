<?php
/**
 * Milestone 6 — backfill enrollment-day payments into the Phase-2 ledger (CLI).
 *
 * Thin wrapper over includes/backfill.php so the CLI and the browser runner
 * (cashier/run_backfill.php) share identical logic. Idempotent / re-runnable.
 *
 * Usage:  php migrations/backfill_enrollment_payments.php [limit]
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/backfill.php';

$db    = db();
$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 0;

$status = soa_backfill_status($db);
echo "Active S.Y.: {$status['sy']['label']} (id {$status['sy']['id']})\n";
echo "Records: total {$status['total']}, already imported {$status['done']}, remaining {$status['remaining']}\n";

$res = soa_backfill_run($db, $limit);
echo "\nDONE. imported={$res['imported']} skipped={$res['skipped']} no-assessment={$res['noAssess']} errors={$res['errors']} (processed {$res['processed']})\n";
foreach ($res['messages'] as $m) { echo "  ERR $m\n"; }

$after = soa_backfill_status($db);
echo "Remaining after run: {$after['remaining']}\n";
$paid = $db->query("SELECT COUNT(*) c, IFNULL(SUM(amount),0) s FROM payment_transaction WHERE status='Posted'")->fetch_assoc();
echo "payment_transaction(Posted): {$paid['c']} rows, total ₱" . number_format((float) $paid['s'], 2) . "\n";
