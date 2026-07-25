-- ============================================================================
-- GRADE REVIEW WORKFLOW — Department Head approves / returns submitted grades.
--
-- WHY: teacher/class_view.php already has a "Submit for Review" button that sets
-- student_grade.status = 'Submitted', but nothing consumed that state — the only
-- reader was a counter on registrar/grading_periods.php. The button implied an
-- approval workflow that did not exist. This adds the reviewer's side.
--
-- STATE MACHINE (student_grade.status):
--
--     Draft ──submit──> Submitted ──approve──> Approved ──lock──> Locked
--       ▲                   │
--       └──── edit ─────  Returned  <──return(reason)──┘
--
--   Teacher  may edit when status IN ('Draft','Returned')  [class Open + period Open]
--   Reviewer may edit when status <> 'Locked'              [period Open]
--   Nobody   edits 'Locked'.
--
-- SCOPING: a reviewer owns a class via classes.user_id — the SAME mechanism
-- depthead/index.php already uses ("classes managed by this dept head").
-- Active S.Y. splits cleanly: shs=197, jhs=193, elem=99 of 489 classes.
-- No department column is invented; user_account has none by design.
--
-- Idempotent & portable. Safe to re-run. Adds columns only.
-- ============================================================================

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_grade' AND COLUMN_NAME='reviewed_by')=0,
  "ALTER TABLE `student_grade` ADD COLUMN `reviewed_by` INT(11) DEFAULT NULL COMMENT '-> user_account.user_id (dept head)'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_grade' AND COLUMN_NAME='reviewed_at')=0,
  "ALTER TABLE `student_grade` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The reason a class was sent back. The teacher must be able to see WHY, not
-- just that it bounced — so it lives on the row, not only in the audit trail.
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_grade' AND COLUMN_NAME='review_note')=0,
  "ALTER TABLE `student_grade` ADD COLUMN `review_note` VARCHAR(255) DEFAULT NULL COMMENT 'Reason given when returned to the teacher'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The reviewer queue filters on (class, period, status) — already covered by
-- idx_sg_class_period; this one serves "everything awaiting me" across classes.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_grade' AND INDEX_NAME='idx_sg_period_status')=0,
  "ALTER TABLE `student_grade` ADD INDEX `idx_sg_period_status` (`grading_period_id`, `status`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- classes.user_id is the reviewer-scoping key; index it for the queue query.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND INDEX_NAME='idx_classes_owner_sy')=0,
  "ALTER TABLE `classes` ADD INDEX `idx_classes_owner_sy` (`user_id`, `School_year_id`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================================
-- DONE. status stays VARCHAR(10) — 'Submitted' (9) is the longest value.
-- ============================================================================
