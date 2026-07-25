-- ============================================================================
-- TEACHER PROFILE — additional info + photo + signature for advisers.
-- email/contact already exist (teacher_module.sql); this adds `address`.
-- Photo and signature are stored as files (uploads/teacher_photos,
-- uploads/teacher_signatures) named by Teacher_id, resolved by extension —
-- the same approach as student photos, so no path column is needed.
-- The adviser's signature renders on the student Grade Slip and Certificate.
-- Idempotent & portable.
-- ============================================================================
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='address')=0,
  "ALTER TABLE `teacher` ADD COLUMN `address` VARCHAR(255) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
