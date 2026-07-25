-- ============================================================================
-- GO-LIVE CONSOLIDATED MIGRATION  —  run ONCE on the online database.
-- ----------------------------------------------------------------------------
-- Bundles every feature migration added after the Phase-2 SOA go-live:
--     • enrollment overrides   (Old/New type, sibling & misc fee waivers)
--     • Student Portal          (accounts + grades)
--     • Promissory Notes
--     • Bank Deposit Certifications
--     • Other / Misc Payments   (payment_others + items + fee catalog)
--
-- 100% IDEMPOTENT & PORTABLE (MySQL 5.7/8.x + MariaDB 10.x):
--   - New tables use CREATE TABLE IF NOT EXISTS.
--   - New columns are added only when absent, via information_schema guards
--     (works even on MySQL 8, which lacks ADD COLUMN IF NOT EXISTS).
--   - Seed rows use INSERT IGNORE.
-- Re-running it is a no-op. It NEVER drops or alters existing data.
--
-- PREREQUISITE: the Phase-2 SOA schema must already be live (it is — the
-- online cashier generates SOAs). This relies on: enrollment, schoolyear,
-- soa_master, document_series.
-- ============================================================================

-- A tiny reusable "add column if missing" helper macro is not possible in
-- plain SQL, so each guarded ALTER is spelled out below.

-- ============================================================================
-- [1] enrollment — Old/New override + fee waivers
-- ============================================================================
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment' AND COLUMN_NAME='student_type')=0,
  "ALTER TABLE `enrollment` ADD COLUMN `student_type` VARCHAR(3) NULL DEFAULT NULL COMMENT 'Old/New fee override; NULL = auto-detect' AFTER `Student_classification`", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment' AND COLUMN_NAME='waive_school_improvement')=0,
  "ALTER TABLE `enrollment` ADD COLUMN `waive_school_improvement` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sibling waiver: 1 = do not charge School Improvement'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment' AND COLUMN_NAME='waive_miscellaneous')=0,
  "ALTER TABLE `enrollment` ADD COLUMN `waive_miscellaneous` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Fee exemption: 1 = do not charge the Miscellaneous Fee'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================================
-- [2] Student Portal — login accounts + grades placeholder
-- ============================================================================
CREATE TABLE IF NOT EXISTS `student_portal_accounts` (
  `id`                   int(11)      NOT NULL AUTO_INCREMENT,
  `enrollment_id`        int(11)      NOT NULL,
  `student_id`           varchar(110) NOT NULL,
  `lrn`                  varchar(40)  NOT NULL COMMENT 'Login username',
  `password_hash`        varchar(255) NOT NULL,
  `must_change_password` tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1 = still on default password',
  `status`               enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `last_login`           datetime     DEFAULT NULL,
  `created_at`           timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`           timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spa_enrollment` (`enrollment_id`),
  KEY `idx_spa_lrn` (`lrn`),
  CONSTRAINT `fk_spa_enrollment` FOREIGN KEY (`enrollment_id`)
      REFERENCES `enrollment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_grades` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `enrollment_id`  int(11)      NOT NULL,
  `school_year_id` int(11)      NOT NULL,
  `subject`        varchar(120) NOT NULL,
  `component`      varchar(60)  DEFAULT NULL COMMENT 'e.g. Academic / Madrasah',
  `q1`             decimal(5,2) DEFAULT NULL,
  `q2`             decimal(5,2) DEFAULT NULL,
  `q3`             decimal(5,2) DEFAULT NULL,
  `q4`             decimal(5,2) DEFAULT NULL,
  `final_grade`    decimal(5,2) DEFAULT NULL,
  `remarks`        varchar(40)  DEFAULT NULL COMMENT 'Passed / Failed / INC',
  `created_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`     timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sg_enrollment` (`enrollment_id`),
  KEY `idx_sg_sy` (`school_year_id`),
  CONSTRAINT `fk_sg_enrollment` FOREIGN KEY (`enrollment_id`)
      REFERENCES `enrollment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sg_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- [3] Promissory Notes
-- ============================================================================
CREATE TABLE IF NOT EXISTS `promissory_notes` (
  `promissory_id`         int(11)      NOT NULL AUTO_INCREMENT,
  `promissory_no`         varchar(30)  NOT NULL COMMENT 'Display ID, e.g. PN-2026-000001',
  `enrollment_id`         int(11)      NOT NULL,
  `student_id`            varchar(110) NOT NULL,
  `school_year_id`        int(11)      NOT NULL,
  `soa_id`                int(11)      DEFAULT NULL COMMENT 'SOA at time of issuance',
  `outstanding_balance`   decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Balance snapshot at issuance',
  `promissory_amount`     decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount covered by this note',
  `date_issued`           date         NOT NULL,
  `promised_payment_date` date         NOT NULL,
  `reason`                varchar(255) DEFAULT NULL,
  `status`                varchar(12)  NOT NULL DEFAULT 'Pending' COMMENT 'Pending|Paid|Overdue|Cancelled',
  `cashier_verified`      tinyint(1)   NOT NULL DEFAULT 0,
  `cashier_verified_by`   varchar(120) DEFAULT NULL,
  `cashier_verified_date` datetime     DEFAULT NULL,
  `created_by`            varchar(120) DEFAULT NULL,
  `created_at`            timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`            timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`promissory_id`),
  UNIQUE KEY `uq_promissory_no` (`promissory_no`),
  KEY `idx_pn_enrollment` (`enrollment_id`),
  KEY `idx_pn_student` (`student_id`),
  KEY `idx_pn_status` (`status`),
  KEY `idx_pn_issued` (`date_issued`),
  CONSTRAINT `fk_pn_enrollment` FOREIGN KEY (`enrollment_id`)
      REFERENCES `enrollment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pn_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pn_soa` FOREIGN KEY (`soa_id`)
      REFERENCES `soa_master` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- [4] Bank Deposit Certifications
-- ============================================================================
CREATE TABLE IF NOT EXISTS `bank_deposits` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `deposit_no`      varchar(30)  NOT NULL,
  `deposit_date`    date         NOT NULL,
  `amount`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank_name`       varchar(120) DEFAULT NULL,
  `bank_account`    varchar(60)  DEFAULT NULL,
  `reference_no`    varchar(60)  DEFAULT NULL COMMENT 'Bank deposit slip / reference',
  `period_from`     date         DEFAULT NULL COMMENT 'Collection period covered (optional)',
  `period_to`       date         DEFAULT NULL,
  `school_year_id`  int(11)      NOT NULL,
  `prepared_by`     varchar(120) DEFAULT NULL,
  `prepared_by_id`  int(11)      DEFAULT NULL,
  `notes`           varchar(255) DEFAULT NULL,
  `status`          varchar(10)  NOT NULL DEFAULT 'Active' COMMENT 'Active|Void',
  `voided_by`       varchar(120) DEFAULT NULL,
  `voided_at`       datetime     DEFAULT NULL,
  `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deposit_no` (`deposit_no`),
  KEY `idx_dep_date` (`deposit_date`),
  KEY `idx_dep_sy` (`school_year_id`),
  CONSTRAINT `fk_dep_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- [5] Other / Miscellaneous Payments  ← the tables the browser is asking for
-- ============================================================================
-- [5a] Enhance payment_others with receipt / cashier fields
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='or_number')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `or_number` VARCHAR(30) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='school_year')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `school_year` VARCHAR(20) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='student_id')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `student_id` VARCHAR(110) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='enrollment_id')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `enrollment_id` INT(11) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='payment_method')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `payment_method` VARCHAR(20) NOT NULL DEFAULT 'Cash'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='reference_no')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `reference_no` VARCHAR(60) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='cashier_name')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `cashier_name` VARCHAR(120) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='cashier_id')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `cashier_id` INT(11) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='status')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `status` VARCHAR(10) NOT NULL DEFAULT 'Paid'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='voided_by')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `voided_by` VARCHAR(120) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='voided_at')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `voided_at` DATETIME DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_others' AND COLUMN_NAME='created_at')=0,
  "ALTER TABLE `payment_others` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp()", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- [5b] Itemized detail lines for a receipt
CREATE TABLE IF NOT EXISTS `other_payment_items` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `payment_id`  int(11)      NOT NULL,
  `item_name`   varchar(160) NOT NULL,
  `quantity`    int(11)      NOT NULL DEFAULT 1,
  `unit_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount`      decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_opi_payment` (`payment_id`),
  CONSTRAINT `fk_opi_payment` FOREIGN KEY (`payment_id`)
      REFERENCES `payment_others` (`Payment_ID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- [5c] Fee-item catalog (counter pick-list)
CREATE TABLE IF NOT EXISTS `other_fee_items` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `name`           varchar(120) NOT NULL,
  `default_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '0 = open / ask at counter',
  `active`         tinyint(1)   NOT NULL DEFAULT 1,
  `sort_order`     int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ofi_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `other_fee_items` (`name`, `default_amount`, `sort_order`) VALUES
  ('School ID',            100.00, 1),
  ('Sling / ID Lace',       50.00, 2),
  ('ID with Sling',        150.00, 3),
  ('Certification',          0.00, 4),
  ('Certificate of Good Moral', 0.00, 5),
  ('Form 137 / SF10',        0.00, 6),
  ('Report Card (Form 138)', 0.00, 7),
  ('Entrance Examination',   0.00, 8),
  ('Uniform / TELA',         0.00, 9),
  ('Transcript of Records',  0.00, 10),
  ('Other / Miscellaneous',  0.00, 99);

-- ============================================================================
-- [6] Document-series seeds (gap-free OR/PN/DEP numbering). Safe no-ops.
-- ============================================================================
INSERT IGNORE INTO `document_series` (`series_code`, `year`, `last_seq`) VALUES
  ('OTHER', YEAR(CURDATE()), 0),
  ('PN',    YEAR(CURDATE()), 0),
  ('DEP',   YEAR(CURDATE()), 0);

-- ============================================================================
-- DONE. Re-running this file changes nothing further.
-- ============================================================================
