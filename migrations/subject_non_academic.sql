-- ============================================================================
--  Non-academic subjects (HRG, Recess, breaks) — schedule-only
--  ---------------------------------------------------------------------------
--  These are part of the daily schedule but are NOT graded classes and must NOT
--  appear in the LMS. This flag lets the schedule keep them while grading and
--  the Classroom (LMS) exclude them. Idempotent.
-- ============================================================================

ALTER TABLE `subject`
  ADD COLUMN IF NOT EXISTS `is_academic` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1 = graded/LMS subject; 0 = schedule-only (HRG, recess, breaks)';

-- Mark the non-academic subjects (case-insensitive).
UPDATE `subject`
   SET `is_academic` = 0
 WHERE UPPER(TRIM(Subject_name)) IN ('HRG','RECESS','LUNCH BREAK','POWER BREAK','HOMEROOM','HOMEROOM GUIDANCE','BREAK','LUNCH')
    OR UPPER(Subject_name) LIKE '%HRG%'
    OR UPPER(Subject_name) LIKE '%RECESS%'
    OR UPPER(Subject_name) LIKE '%BREAK%'
    OR UPPER(Subject_name) LIKE '%HOMEROOM%';

-- Show what was flagged (review before/after).
SELECT Subject_id, Subject_name, is_academic FROM `subject` WHERE is_academic = 0 ORDER BY Subject_name;
