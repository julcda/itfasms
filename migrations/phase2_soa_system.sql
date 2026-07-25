-- ============================================================================
-- PHASE 2 — Statement of Account (SOA) & SOA-Based Payment Processing
-- Milestone 1: schema foundation (tables + FKs + document series + seed config)
--
-- Safe to run multiple times (idempotent): CREATE TABLE IF NOT EXISTS +
-- INSERT IGNORE seeds. Does NOT touch existing legacy tables
-- (backaccount_payment_records, enrollment_payment, fee_schedule, student_account).
--
-- FK targets (existing PKs, verified): enrollment(id), schoolyear(School_year_id),
--   gradelevel(Gradelevel_id), section(Section_id). All InnoDB.
--
-- NOTE: we reuse the existing `schoolyear` table (School_year_id) rather than
--       creating a new `school_year` table, to avoid disrupting the live system.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1. Document number series (gap-free, per series_code + year)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_series` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `series_code` varchar(20)  NOT NULL COMMENT 'e.g. SOA, OR',
  `year`        int(11)      NOT NULL,
  `last_seq`    int(11)      NOT NULL DEFAULT 0,
  `updated_at`  timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_series_year` (`series_code`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 2. System settings (key/value)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_setting` (
  `setting_key`   varchar(60)  NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description`   varchar(255) DEFAULT NULL,
  `updated_by`    varchar(120) DEFAULT NULL,
  `updated_at`    timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 3. Fee item catalog (categories for charges & receipt breakdown)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fee_item` (
  `id`         int(11)     NOT NULL AUTO_INCREMENT,
  `code`       varchar(30) NOT NULL,
  `name`       varchar(120) NOT NULL,
  `category`   varchar(30) NOT NULL COMMENT 'tuition|improvement|books|misc|admission|activity|house|discount|adjustment|other',
  `sort_order` int(11)     NOT NULL DEFAULT 0,
  `is_active`  tinyint(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fee_item_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 4. Student assessment (header) — one per (enrollment, school year)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_assessment` (
  `id`                   int(11)      NOT NULL AUTO_INCREMENT,
  `enrollment_id`        int(11)      NOT NULL,
  `school_year_id`       int(11)      NOT NULL,
  `student_id`           varchar(110) NOT NULL,
  `classification_id`    int(11)      DEFAULT NULL,
  `student_type`         varchar(3)   DEFAULT NULL COMMENT 'New|Old',
  `total_assessed`       decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_discount`       decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_assessed`         decimal(12,2) NOT NULL DEFAULT 0.00,
  `enrollment_fees_total` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'admission+activity+house+book down (paid up front, not part of installment base)',
  `installment_base`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `installment_count`    int(11)      NOT NULL DEFAULT 10,
  `total_paid`           decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance`              decimal(12,2) NOT NULL DEFAULT 0.00,
  `status`               varchar(20)  NOT NULL DEFAULT 'Active' COMMENT 'Active|Settled|Void',
  `created_by`           varchar(120) DEFAULT NULL,
  `created_at`           timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`           timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assessment_enrollment_sy` (`enrollment_id`, `school_year_id`),
  KEY `idx_assessment_student` (`student_id`),
  KEY `idx_assessment_sy` (`school_year_id`),
  CONSTRAINT `fk_assessment_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollment` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_sy`         FOREIGN KEY (`school_year_id`) REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 5. Assessment charge lines (charges / discounts / adjustments)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assessment_charge` (
  `id`                  int(11)      NOT NULL AUTO_INCREMENT,
  `assessment_id`       int(11)      NOT NULL,
  `fee_item_id`         int(11)      DEFAULT NULL,
  `line_type`           varchar(12)  NOT NULL DEFAULT 'charge' COMMENT 'charge|discount|adjustment',
  `description`         varchar(160) NOT NULL,
  `amount`              decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_installment_base` tinyint(1)   NOT NULL DEFAULT 0,
  `source_ref`          varchar(60)  DEFAULT NULL,
  `created_at`          timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_charge_assessment` (`assessment_id`),
  CONSTRAINT `fk_charge_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_charge_fee_item`   FOREIGN KEY (`fee_item_id`)   REFERENCES `fee_item` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 6. Payment schedule (installment plan rows)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_schedule` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11)      NOT NULL,
  `term_no`       int(11)      NOT NULL,
  `month_label`   varchar(40)  NOT NULL,
  `due_date`      date         NOT NULL,
  `amount_due`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid`   decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance`       decimal(12,2) NOT NULL DEFAULT 0.00,
  `status`        varchar(12)  NOT NULL DEFAULT 'Unpaid' COMMENT 'Unpaid|Partial|Paid|Overdue',
  `created_at`    timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`    timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_assessment_term` (`assessment_id`, `term_no`),
  KEY `idx_schedule_status` (`status`),
  CONSTRAINT `fk_schedule_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 7. SOA master (generated SOA document)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soa_master` (
  `id`                 int(11)      NOT NULL AUTO_INCREMENT,
  `assessment_id`      int(11)      NOT NULL,
  `soa_number`         varchar(30)  NOT NULL,
  `scope`              varchar(12)  NOT NULL DEFAULT 'Student' COMMENT 'Student|Section|Grade|Dept|School',
  `scope_ref`          varchar(120) DEFAULT NULL,
  `selected_terms_json` varchar(255) DEFAULT NULL,
  `total_due`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `barcode_ref`        varchar(40)  DEFAULT NULL,
  `qr_ref`             varchar(120) DEFAULT NULL,
  `batch_id`           varchar(40)  DEFAULT NULL,
  `generated_by`       varchar(120) DEFAULT NULL,
  `generated_at`       timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_soa_number` (`soa_number`),
  KEY `idx_soa_assessment` (`assessment_id`),
  KEY `idx_soa_batch` (`batch_id`),
  CONSTRAINT `fk_soa_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 8. SOA details (snapshot of selected installments at print time)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soa_details` (
  `id`                   int(11)      NOT NULL AUTO_INCREMENT,
  `soa_id`               int(11)      NOT NULL,
  `schedule_id`          int(11)      NOT NULL,
  `term_no`              int(11)      NOT NULL,
  `month_label`          varchar(40)  NOT NULL,
  `amount_due`           decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid_snapshot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_selected`      decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_soa_details_soa` (`soa_id`),
  CONSTRAINT `fk_soa_details_soa`      FOREIGN KEY (`soa_id`)      REFERENCES `soa_master` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_soa_details_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `payment_schedule` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 9. Payment transaction (posted collection event)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_transaction` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `assessment_id`   int(11)      NOT NULL,
  `soa_id`          int(11)      DEFAULT NULL,
  `method`          varchar(20)  NOT NULL DEFAULT 'Cash' COMMENT 'Cash|GCash|Maya|Bank|Voucher|Advance',
  `reference_no`    varchar(60)  DEFAULT NULL,
  `amount`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `tendered`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `change_amount`   decimal(12,2) NOT NULL DEFAULT 0.00,
  `status`          varchar(10)  NOT NULL DEFAULT 'Posted' COMMENT 'Posted|Voided',
  `received_by`     varchar(120) DEFAULT NULL,
  `idempotency_key` varchar(64)  DEFAULT NULL,
  `paid_at`         datetime     NOT NULL DEFAULT current_timestamp(),
  `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_idempotency` (`idempotency_key`),
  KEY `idx_payment_assessment` (`assessment_id`),
  KEY `idx_payment_soa` (`soa_id`),
  KEY `idx_payment_paid_at` (`paid_at`),
  CONSTRAINT `fk_payment_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessment` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_soa`        FOREIGN KEY (`soa_id`)        REFERENCES `soa_master` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 10. Payment installments (allocation: payment -> schedule term)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_installments` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `payment_id`  int(11)      NOT NULL,
  `schedule_id` int(11)      NOT NULL,
  `amount`      decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at`  timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alloc_payment` (`payment_id`),
  KEY `idx_alloc_schedule` (`schedule_id`),
  CONSTRAINT `fk_alloc_payment`  FOREIGN KEY (`payment_id`)  REFERENCES `payment_transaction` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_alloc_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `payment_schedule` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 11. Payment adjustments (discount / scholarship / assistance / correction)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_adjustments` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11)      NOT NULL,
  `type`          varchar(20)  NOT NULL COMMENT 'Discount|Scholarship|Assistance|Adjustment',
  `code`          varchar(40)  DEFAULT NULL,
  `amount`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason`        varchar(255) DEFAULT NULL,
  `applied_by`    varchar(120) DEFAULT NULL,
  `applied_at`    timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_adjust_assessment` (`assessment_id`),
  CONSTRAINT `fk_adjust_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 12. Payment reversals (void / refund)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_reversals` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `payment_id`   int(11)      NOT NULL,
  `type`         varchar(10)  NOT NULL DEFAULT 'Void' COMMENT 'Void|Refund',
  `amount`       decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason`       varchar(255) DEFAULT NULL,
  `requested_by` varchar(120) DEFAULT NULL,
  `approved_by`  varchar(120) DEFAULT NULL,
  `status`       varchar(12)  NOT NULL DEFAULT 'Approved' COMMENT 'Pending|Approved|Rejected',
  `created_at`   timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reversal_payment` (`payment_id`),
  CONSTRAINT `fk_reversal_payment` FOREIGN KEY (`payment_id`) REFERENCES `payment_transaction` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 13. Receipt master (OR header, 1:1 with a posted payment)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `receipt_master` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `payment_id`    int(11)      NOT NULL,
  `or_number`     varchar(30)  NOT NULL,
  `series`        varchar(20)  NOT NULL DEFAULT 'OR',
  `sequence`      int(11)      NOT NULL DEFAULT 0,
  `reprint_count` int(11)      NOT NULL DEFAULT 0,
  `issued_by`     varchar(120) DEFAULT NULL,
  `issued_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_or` (`or_number`),
  UNIQUE KEY `uq_receipt_payment` (`payment_id`),
  CONSTRAINT `fk_receipt_payment` FOREIGN KEY (`payment_id`) REFERENCES `payment_transaction` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 14. Receipt details (fee-category breakdown printed on OR)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `receipt_details` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `receipt_id`  int(11)      NOT NULL,
  `fee_item_id` int(11)      DEFAULT NULL,
  `category`    varchar(30)  NOT NULL,
  `description` varchar(160) DEFAULT NULL,
  `amount`      decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_details_receipt` (`receipt_id`),
  CONSTRAINT `fk_receipt_details_receipt` FOREIGN KEY (`receipt_id`)  REFERENCES `receipt_master` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_receipt_details_item`    FOREIGN KEY (`fee_item_id`) REFERENCES `fee_item` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 15. Student ledger (append-only financial event log)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_ledger` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `assessment_id`   int(11)      NOT NULL,
  `student_id`      varchar(110) NOT NULL,
  `school_year_id`  int(11)      NOT NULL,
  `entry_type`      varchar(20)  NOT NULL COMMENT 'Assessment|SOA|Payment|Receipt|Discount|Scholarship|Adjustment|Reversal|Refund|Advance',
  `ref_table`       varchar(40)  DEFAULT NULL,
  `ref_id`          int(11)      DEFAULT NULL,
  `description`     varchar(200) DEFAULT NULL,
  `debit`           decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `running_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `posted_by`       varchar(120) DEFAULT NULL,
  `posted_at`       timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ledger_assessment` (`assessment_id`),
  KEY `idx_ledger_student` (`student_id`),
  CONSTRAINT `fk_ledger_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ledger_sy`         FOREIGN KEY (`school_year_id`) REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 16. Financial audit logs (who/what/when, before/after)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `financial_audit_logs` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `actor_id`    int(11)      DEFAULT NULL,
  `actor_name`  varchar(120) DEFAULT NULL,
  `action`      varchar(40)  NOT NULL,
  `entity`      varchar(40)  NOT NULL,
  `entity_id`   varchar(40)  DEFAULT NULL,
  `before_json` text         DEFAULT NULL,
  `after_json`  text         DEFAULT NULL,
  `ip`          varchar(45)  DEFAULT NULL,
  `created_at`  timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity`, `entity_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 17. Collection summary (per-cashier per-day rollup + end-of-day close)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `collection_summary` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `cashier_id`      int(11)      DEFAULT NULL,
  `cashier_name`    varchar(120) DEFAULT NULL,
  `business_date`   date         NOT NULL,
  `school_year_id`  int(11)      NOT NULL,
  `txn_count`       int(11)      NOT NULL DEFAULT 0,
  `total_cash`      decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_online`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_collected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `declared_cash`   decimal(12,2) DEFAULT NULL COMMENT 'Cash counted by cashier at close',
  `variance`        decimal(12,2) DEFAULT NULL COMMENT 'declared_cash - total_cash (over/short)',
  `notes`           varchar(255) DEFAULT NULL,
  `status`          varchar(10)  NOT NULL DEFAULT 'Open' COMMENT 'Open|Closed',
  `closed_by`       varchar(120) DEFAULT NULL,
  `closed_at`       datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collection_cashier_date` (`cashier_id`, `business_date`),
  CONSTRAINT `fk_collection_sy` FOREIGN KEY (`school_year_id`) REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- SEED CONFIG (idempotent)
-- ============================================================================

-- Fee item catalog
INSERT IGNORE INTO `fee_item` (`code`, `name`, `category`, `sort_order`) VALUES
  ('TUITION',     'Tuition Fee',            'tuition',     1),
  ('IMPROVEMENT', 'School Improvement Fee', 'improvement', 2),
  ('MISC',        'Miscellaneous Fee',      'misc',        3),
  ('BOOKS',       'Books / Learning Materials', 'books',   4),
  ('ADMISSION',   'Admission / Registration Fee', 'admission', 5),
  ('ACTIVITY',    'Activity Fees',          'activity',    6),
  ('HOUSE_REG',   'House Registration',     'house',       7),
  ('INSTALLMENT', 'Monthly Tuition & Fees', 'tuition',     8),
  ('DISCOUNT',    'Discount',               'discount',    9),
  ('SCHOLARSHIP', 'Scholarship',            'discount',   10),
  ('ASSISTANCE',  'Financial Assistance',   'discount',   11),
  ('ADJUSTMENT',  'Adjustment',             'adjustment', 12);

-- System settings (defaults; admin-tunable later)
INSERT IGNORE INTO `system_setting` (`setting_key`, `setting_value`, `description`) VALUES
  ('INSTALLMENT_COUNT',        '10',          'Number of monthly installments'),
  ('INSTALLMENT_START_MONTH',  '8',           'Calendar month (1-12) the first installment is due (August)'),
  ('OVERPAY_POLICY',           'cascade_next','cascade_next | store_advance'),
  ('SOA_SERIES_CODE',          'SOA',         'Document series code for SOA numbers'),
  ('OR_SERIES_CODE',           'OR',          'Document series code for Official Receipt numbers'),
  ('SOA_NUMBER_PREFIX',        'SOA',         'Printed prefix for SOA numbers'),
  ('OR_NUMBER_PREFIX',         'ITFA-OR',     'Printed prefix for OR numbers');

-- Document series seeds for the current calendar year (safe no-op if present)
INSERT IGNORE INTO `document_series` (`series_code`, `year`, `last_seq`) VALUES
  ('SOA', YEAR(CURDATE()), 0),
  ('OR',  YEAR(CURDATE()), 0);

-- ============================================================================
-- END Phase 2 Milestone 1 migration
-- ============================================================================
