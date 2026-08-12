-- ============================================================================
-- CERTIFICATES — AWARD MODEL UPDATE
--   Replaces the three honor bands (With / High / Highest Honors) with distinct
--   AWARD TYPES, each its own certificate:
--     • Academic Excellence  -> "Academic Excellence Award" (honor students, 90+)
--     • Perfect Attendance   -> "Perfect Attendance Award"  (any student)
--     • Special Award        -> a custom, per-issue title    (any student)
--
--   The existing unique key (student_id, school_year_id, grading_period_id, type)
--   already lets a student hold ONE of each type per period, so no key change.
--
--   Existing "Academic Honor" certificates are left intact — their honor_level
--   still prints; new certificates use `award_title` + the new `type` values.
--
--   Idempotent. Safe to re-run.
-- ============================================================================

-- Printed title of the award (e.g. "Academic Excellence Award", or a Special
-- Award name like "Leadership Award"). NULL for legacy honor certificates.
ALTER TABLE `certificate`
  ADD COLUMN IF NOT EXISTS `award_title` VARCHAR(120) NULL
      COMMENT 'Printed award title; NULL falls back to honor_level (legacy)'
      AFTER `honor_level`;

-- Honor band is no longer required (only legacy rows carry it).
ALTER TABLE `certificate`
  MODIFY `honor_level` VARCHAR(30) NULL
      COMMENT 'Legacy honor band (With/High/Highest Honors); NULL for award types';

-- Backfill: give the old honor certificates a printable award_title so the new
-- renderer (which prefers award_title) keeps showing them correctly.
UPDATE `certificate`
   SET `award_title` = `honor_level`
 WHERE `award_title` IS NULL AND `honor_level` IS NOT NULL;

-- ============================================================================
-- DONE.
-- ============================================================================
