<?php
/**
 * One-shot maintenance script: regenerate monthly SOA slips for pupils whose
 * documents were removed by the bundle cleanup (they now have no slip for the
 * months they owe). It reuses the SAME generator the Cashier UI uses, so
 * document numbering, the "skip months already generated" guard, and the fee
 * snapshots are all handled correctly.
 *
 * Scope (edit to taste):
 *   - Grade level 15 (KINDER), sections 7 & 8 (AMINA AFTERNOON/MORNING)
 *   - Month sets to (re)generate: [1,2] (M1&M2) then [3] (M3)
 *
 * Safe to re-run: pupils who already have a month are skipped. Run it ONCE
 * after fix_kinder_m3_soa.sql + fix_kinder_bundles.sql.
 *
 * Run from CLI:   php tools/regenerate_freed_soa.php
 * (Dry run:       php tools/regenerate_freed_soa.php --dry )
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This maintenance script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/soa_service.php';

$dryRun = in_array('--dry', $argv, true);

// ── Scope ────────────────────────────────────────────────────────────────────
$gradelevelId = 15;             // KINDER
$sectionIds   = ['7', '8'];     // AMINA-AFTERNOON, AMINA-MORNING
$monthSets    = [[1, 2], [3]];  // generate M1&M2, then M3  (mirrors the UI)

$db          = db();
$sy          = soa_active_school_year($db);
$syId        = (int) $sy['id'];
$syLabel     = (string) $sy['label'];
$genYear     = (int) date('Y');
$cashierName = 'System (regen script)';

// Resolve the target enrollments (owe monthly = have a payment schedule).
$in = "'" . implode("','", array_map(static fn($s) => $db->real_escape_string($s), $sectionIds)) . "'";
$sql = "SELECT en.id, en.student_id,
               COALESCE(CONCAT(p.surname,', ',p.firstname), CONCAT(osp.surname,', ',osp.firstname), 'Student') AS name
        FROM enrollment en
        LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
        LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
        WHERE en.school_year = ? AND en.Department_gradelevel = ? AND en.Department_section IN ($in)
        ORDER BY name";
$stmt = $db->prepare($sql);
$stmt->bind_param('si', $syLabel, $gradelevelId);
$stmt->execute();
$enrollments = stmt_fetch_all_assoc($stmt);

printf("School year: %s (id %d)  ·  %s\n", $syLabel, $syId, $dryRun ? 'DRY RUN' : 'LIVE');
printf("Target: gradelevel %d, sections %s  ·  %d pupils\n\n", $gradelevelId, implode(',', $sectionIds), count($enrollments));

$created = 0;
$batchId = 'REGEN' . date('ymdHis');

$db->begin_transaction();
try {
    foreach ($enrollments as $en) {
        $enrollmentId = (int) $en['id'];
        $assessmentId = soa_ensure_assessment($db, $enrollmentId, $syId, $cashierName);
        if ($assessmentId <= 0) {
            continue;
        }
        $schedule  = soa_get_schedule($db, $assessmentId);
        $generated = soa_generated_terms($db, $assessmentId);

        foreach ($monthSets as $set) {
            // want = requested months, minus already-generated, kept to the schedule
            $want = array_values(array_diff($set, $generated));
            $want = array_values(array_filter($want, static fn($t) => isset($schedule[$t])));
            if ($want === []) {
                continue; // nothing to do for this month set
            }
            printf("  %-32s  assess %-5d  ->  generate M%s\n", $en['name'], $assessmentId, implode(',M', $want));
            if (!$dryRun) {
                $newId = soa_create_document(
                    $db, $assessmentId, (string) $en['student_id'], $syId, $genYear,
                    'Section', (string) $en['student_id'], $want, $cashierName, $batchId
                );
                if ($newId > 0) {
                    $created++;
                    // reflect the new coverage so the next month-set sees it
                    $generated = array_merge($generated, $want);
                }
            }
        }
    }
    if ($dryRun) {
        $db->rollback();
        echo "\nDRY RUN — no documents were created (rolled back).\n";
    } else {
        $db->commit();
        printf("\nDone. %d SOA document(s) created (batch %s).\n", $created, $batchId);
        echo "Reprint them in Cashier > Manage SOA (Grade=KINDER, Month=M3).\n";
    }
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "FAILED: {$e->getMessage()}\n");
    exit(1);
}
