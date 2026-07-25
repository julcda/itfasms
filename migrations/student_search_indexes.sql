-- ============================================================================
-- STUDENT SEARCH / LOOKUP INDEXES  —  performance only, no data change.
-- ----------------------------------------------------------------------------
-- WHY: enrollment, preregistration and old_studentprofile had NO indexes on the
-- columns every student lookup filters and joins on. The cashier's back-account
-- student picker was doing three full table scans with block-nested-loop joins
-- (~5,000 x 2,600 x 2,900 row combinations) and took ~4.8 SECONDS per keystroke.
--
-- These indexes also speed up every other student lookup in the system:
-- the student portal login (student_auth), SOA fee resolution, the back-account
-- SOA warning (enrollment.student_id), and the registrar tools.
--
-- Prefix lengths are used on the varchar(255) columns to stay under InnoDB's
-- 767-byte key limit on COMPACT row format (utf8mb4 = 4 bytes/char).
--
-- Idempotent & portable (MySQL 5.7/8.x + MariaDB 10.x). Adding an index never
-- changes data; it is safe to run on a live database.
-- ============================================================================

-- ── enrollment.student_id — the canonical student key, joined everywhere ─────
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment' AND INDEX_NAME='idx_en_student_id')=0,
  "ALTER TABLE `enrollment` ADD INDEX `idx_en_student_id` (`student_id`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment' AND INDEX_NAME='idx_en_sy_status')=0,
  "ALTER TABLE `enrollment` ADD INDEX `idx_en_sy_status` (`school_year`, `Status`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── preregistration (New students) ──────────────────────────────────────────
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='preregistration' AND INDEX_NAME='idx_prereg_lrn')=0,
  "ALTER TABLE `preregistration` ADD INDEX `idx_prereg_lrn` (`lrn`(32))", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='preregistration' AND INDEX_NAME='idx_prereg_surname')=0,
  "ALTER TABLE `preregistration` ADD INDEX `idx_prereg_surname` (`surname`(64))", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── old_studentprofile (Old students) ───────────────────────────────────────
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='old_studentprofile' AND INDEX_NAME='idx_osp_student_id')=0,
  "ALTER TABLE `old_studentprofile` ADD INDEX `idx_osp_student_id` (`student_id`(110))", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='old_studentprofile' AND INDEX_NAME='idx_osp_lrn')=0,
  "ALTER TABLE `old_studentprofile` ADD INDEX `idx_osp_lrn` (`lrn`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='old_studentprofile' AND INDEX_NAME='idx_osp_surname')=0,
  "ALTER TABLE `old_studentprofile` ADD INDEX `idx_osp_surname` (`surname`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================================
-- DONE. Re-running is a no-op.
-- ============================================================================
