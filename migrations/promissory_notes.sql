-- ============================================================================
-- PROMISSORY NOTES — Registrar-issued deferred-payment arrangements.
-- Integrated with the SOA/ledger (enrollment + soa_master) and the Cashier.
-- Idempotent.
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
