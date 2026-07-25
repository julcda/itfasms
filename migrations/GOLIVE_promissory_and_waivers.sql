-- ############################################################################
-- GO-LIVE — Promissory Notes + Fee Waivers (Miscellaneous & School Improvement)
-- Idempotent and portable (MariaDB 10.x / MySQL 5.7+/8). Safe to re-run.
-- Run against the application database.
-- ############################################################################

-- ---------------------------------------------------------------------------
-- [A] PROMISSORY NOTES table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `promissory_notes` (
  `promissory_id`         int(11)      NOT NULL AUTO_INCREMENT,
  `promissory_no`         varchar(30)  NOT NULL COMMENT 'Display ID, e.g. PN-2026-000001',
  `enrollment_id`         int(11)      NOT NULL,
  `student_id`            varchar(110) NOT NULL,
  `school_year_id`        int(11)      NOT NULL,
  `soa_id`                int(11)      DEFAULT NULL COMMENT 'Current SOA at issuance',
  `outstanding_balance`   decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Current SOA amount due',
  `promissory_amount`     decimal(12,2) NOT NULL DEFAULT 0.00,
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
  CONSTRAINT `fk_pn_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pn_sy` FOREIGN KEY (`school_year_id`) REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pn_soa` FOREIGN KEY (`soa_id`) REFERENCES `soa_master` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- [B] FEE-WAIVER columns on enrollment (added only if missing)
--     waive_school_improvement — exempt the monthly School Improvement Fee
--     waive_miscellaneous      — exempt the monthly Miscellaneous Fee
-- ---------------------------------------------------------------------------
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment' AND COLUMN_NAME='waive_school_improvement')=0,
  "ALTER TABLE `enrollment` ADD COLUMN `waive_school_improvement` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exempt School Improvement Fee'",
  "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment' AND COLUMN_NAME='waive_miscellaneous')=0,
  "ALTER TABLE `enrollment` ADD COLUMN `waive_miscellaneous` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exempt Miscellaneous Fee'",
  "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ############################################################################
-- END
-- ############################################################################
