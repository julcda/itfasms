-- ============================================================================
-- UNIFIED ACCOUNT MANAGEMENT — support for the Super Admin credential console.
--
-- The system has THREE separate account stores:
--   user_account            staff + department heads + 112 teachers (bcrypt)
--   enrollment_users        5 legacy staff logins (PLAINTEXT — see below)
--   student_portal_accounts student LRN logins (bcrypt)
--
-- enrollment_users has no `status` column, so a legacy staff login could never
-- be disabled — only deleted. This adds it, and includes/auth.php now enforces
-- it at the login page (matching what user_account already does).
--
-- NOTE ON THE PLAINTEXT PROBLEM
--   enrollment_users.password currently holds PLAIN TEXT ('12345', 'admin$$$').
--   authenticate_enrollment_user() already tries password_verify() BEFORE the
--   plaintext comparison, so writing a bcrypt hash into that column works
--   immediately with no code change. The Super Admin console therefore writes
--   bcrypt on every password reset, which migrates these accounts off plaintext
--   one at a time through normal use. Once all five are hashed, the plaintext
--   fallback in auth.php can be deleted.
--
-- Idempotent & portable. Safe to re-run.
-- ============================================================================

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_users' AND COLUMN_NAME='status')=0,
  "ALTER TABLE `enrollment_users` ADD COLUMN `status` VARCHAR(10) NOT NULL DEFAULT 'Active' COMMENT 'Active|Inactive'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_users' AND COLUMN_NAME='last_login')=0,
  "ALTER TABLE `enrollment_users` ADD COLUMN `last_login` DATETIME DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_users' AND INDEX_NAME='idx_eu_status')=0,
  "ALTER TABLE `enrollment_users` ADD INDEX `idx_eu_status` (`status`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================================
-- DONE.
-- ============================================================================
