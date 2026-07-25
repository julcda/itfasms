-- ============================================================================
-- STUDENT BACK ACCOUNTS — properly student-linked prior-year balances.
-- ----------------------------------------------------------------------------
-- WHY: the legacy `back_accounts` table is unusable as an operational table:
--   • `No` is int(3) and NOT auto_increment  → cannot grow past 999 rows
--   • `Student_id` is a bare bigint with no declared relationship
--   • `Name` truncated at 31 chars; `Status` casing inconsistent ('Paid'/'unpaid')
--   • `Date_paid` is a varchar, not a DATE; no payment/OR trail
--
-- WHAT: creates `student_back_accounts` (student-linked, with balance tracking)
-- and `back_account_payments` (OR-issuing payment trail), then migrates all
-- 491 legacy rows across. The original `back_accounts` table is LEFT UNTOUCHED
-- as a historical backup.
--
-- LINKAGE: back_accounts.Student_id matches old_studentprofile.student_id for
-- 489/491 rows. `student_id` here is the canonical key that also matches
-- enrollment.student_id, so a back account follows the student across years.
-- It is a soft (indexed) link, not an FK — 2 legacy rows have no profile and
-- an FK would reject them.
--
-- Idempotent & portable (MySQL 5.7/8.x + MariaDB 10.x). Safe to re-run.
-- ============================================================================

-- ── [1] The back account itself ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_back_accounts` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `student_id`      varchar(110) NOT NULL COMMENT 'Canonical link: enrollment.student_id / old_studentprofile.student_id',
  `lrn`             varchar(40)  DEFAULT NULL,
  `student_name`    varchar(160) NOT NULL,
  `school_year`     varchar(20)  NOT NULL COMMENT 'S.Y. the debt originated from',
  `grade_section`   varchar(60)  DEFAULT NULL COMMENT 'Grade/section at the time of the debt',
  `original_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance`         decimal(12,2) NOT NULL DEFAULT 0.00,
  `status`          varchar(10)  NOT NULL DEFAULT 'Unpaid' COMMENT 'Unpaid|Partial|Paid|Cancelled',
  `remarks`         varchar(255) DEFAULT NULL,
  `legacy_no`       int(11)      DEFAULT NULL COMMENT 'back_accounts.No — traceability + migration idempotency',
  `created_by`      varchar(120) DEFAULT NULL,
  `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`      timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sba_legacy` (`legacy_no`),
  KEY `idx_sba_student` (`student_id`),
  KEY `idx_sba_status` (`status`),
  KEY `idx_sba_sy` (`school_year`),
  KEY `idx_sba_lrn` (`lrn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── [2] Payments made against a back account (issues an OR) ─────────────────
CREATE TABLE IF NOT EXISTS `back_account_payments` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `back_account_id` int(11)      NOT NULL,
  `or_number`       varchar(30)  DEFAULT NULL,
  `amount`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method`  varchar(20)  NOT NULL DEFAULT 'Cash',
  `reference_no`    varchar(60)  DEFAULT NULL,
  `cashier_name`    varchar(120) DEFAULT NULL,
  `cashier_id`      int(11)      DEFAULT NULL,
  `status`          varchar(10)  NOT NULL DEFAULT 'Paid' COMMENT 'Paid|Voided',
  `voided_by`       varchar(120) DEFAULT NULL,
  `voided_at`       datetime     DEFAULT NULL,
  `paid_at`         timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bap_or` (`or_number`),
  KEY `idx_bap_ba` (`back_account_id`),
  KEY `idx_bap_paid` (`paid_at`),
  CONSTRAINT `fk_bap_ba` FOREIGN KEY (`back_account_id`)
      REFERENCES `student_back_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── [3] Migrate the legacy rows (idempotent via UNIQUE legacy_no) ───────────
-- Status mapping : 'Paid' (any casing) -> Paid, balance 0. Everything else -> Unpaid.
-- Amount mapping : legacy `Balance` holds the REMAINING amount owed. For unpaid
--                  rows the original is unknown, so original_amount = balance.
--                  Negative legacy balances (overpayments) are floored to 0 and
--                  preserved in `remarks` rather than silently dropped.
-- LRN lookup uses a LIMIT 1 subquery: old_studentprofile.student_id has one
-- duplicate, so a JOIN would fan out rows.
INSERT IGNORE INTO `student_back_accounts`
  (`legacy_no`, `student_id`, `lrn`, `student_name`, `school_year`, `grade_section`,
   `original_amount`, `amount_paid`, `balance`, `status`, `remarks`, `created_by`)
SELECT
  b.`No`,
  CAST(b.`Student_id` AS CHAR),
  (SELECT o.`lrn` FROM `old_studentprofile` o
     WHERE o.`student_id` = CAST(b.`Student_id` AS CHAR) LIMIT 1),
  IFNULL(NULLIF(TRIM(b.`Name`), ''), '(unnamed)'),
  IFNULL(NULLIF(TRIM(b.`School_year`), ''), '2023-2024'),
  NULLIF(TRIM(IFNULL(b.`Gradelevel_and_Section`, '')), ''),
  GREATEST(IFNULL(b.`Balance`, 0), 0),
  0.00,
  IF(LOWER(TRIM(b.`Status`)) = 'paid', 0.00, GREATEST(IFNULL(b.`Balance`, 0), 0)),
  IF(LOWER(TRIM(b.`Status`)) = 'paid', 'Paid', 'Unpaid'),
  CONCAT('Migrated from legacy back_accounts #', b.`No`,
         IF(TRIM(IFNULL(b.`Date_paid`, '')) = '', '', CONCAT('; legacy date_paid ', TRIM(b.`Date_paid`))),
         IF(IFNULL(b.`Balance`, 0) < 0, CONCAT('; legacy overpayment of ', b.`Balance`), '')),
  'Legacy migration'
FROM `back_accounts` b;

-- ── [4] OR series for back-account collections ─────────────────────────────
INSERT IGNORE INTO `document_series` (`series_code`, `year`, `last_seq`)
VALUES ('BACK', YEAR(CURDATE()), 0);

-- ============================================================================
-- DONE. Re-running is a no-op (UNIQUE legacy_no blocks duplicate migration).
-- ============================================================================
