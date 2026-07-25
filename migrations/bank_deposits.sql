-- ============================================================================
-- Bank Deposit Certifications (cashier weekly cash deposit to the bank).
-- Each row is one certified deposit; a printable certificate is rendered from it.
-- Idempotent.
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
