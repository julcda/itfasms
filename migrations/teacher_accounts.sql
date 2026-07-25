-- ============================================================================
-- TEACHER LOGIN ACCOUNTS — prepares user_account to hold teachers.
-- Run BEFORE migrations/provision_teacher_accounts.php.
--
-- WHY each change:
--   role   — is an ENUM('user','super admin'); 'teacher' cannot be inserted
--            until the enum accepts it. Additive: existing values keep working.
--   email  — is NOT NULL UNIQUE. Teachers have no email on file, and '' would
--            collide on the 2nd teacher. Made NULLable (UNIQUE permits many NULLs).
--   must_change_password / last_login — mirrors student_portal_accounts so a
--            default password cannot survive first login.
--
-- Idempotent & portable. Safe to re-run.
-- ============================================================================

-- ── role: allow 'teacher' ───────────────────────────────────────────────────
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_account'
                AND COLUMN_NAME='role' AND COLUMN_TYPE LIKE '%teacher%')=0,
  "ALTER TABLE `user_account` MODIFY `role` ENUM('user','super admin','teacher') NOT NULL DEFAULT 'user'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── email: allow NULL (teachers often have none) ────────────────────────────
SET @s := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_account' AND COLUMN_NAME='email')='NO',
  "ALTER TABLE `user_account` MODIFY `email` VARCHAR(255) NULL DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── first-login password change enforcement ─────────────────────────────────
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_account' AND COLUMN_NAME='must_change_password')=0,
  "ALTER TABLE `user_account` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = still on the issued default password'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_account' AND COLUMN_NAME='status')=0,
  "ALTER TABLE `user_account` ADD COLUMN `status` VARCHAR(10) NOT NULL DEFAULT 'Active' COMMENT 'Active|Inactive'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_account' AND COLUMN_NAME='last_login')=0,
  "ALTER TABLE `user_account` ADD COLUMN `last_login` DATETIME DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_account' AND INDEX_NAME='idx_ua_role')=0,
  "ALTER TABLE `user_account` ADD INDEX `idx_ua_role` (`role`, `status`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================================
-- DONE. Next: php migrations/provision_teacher_accounts.php
-- ============================================================================
