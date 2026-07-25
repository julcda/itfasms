-- ============================================================================
-- OTHER / MISCELLANEOUS PAYMENTS (Cashier) — ID, Sling, Certification, etc.
-- Builds on the existing (previously orphaned) `payment_others` table, adding
-- receipt/OR fields, an itemized detail table, and a fee-item catalog.
-- Idempotent & portable (MariaDB / MySQL).
-- ============================================================================

-- ── [1] Enhance payment_others with receipt / cashier fields ─────────────────
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

-- ── [2] Itemized detail lines for a receipt ──────────────────────────────────
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

-- ── [3] Fee-item catalog (the pick-list of common items) ─────────────────────
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

-- Document series seed for the OTHER-payment OR (safe no-op if present)
INSERT IGNORE INTO `document_series` (`series_code`, `year`, `last_seq`) VALUES ('OTHER', YEAR(CURDATE()), 0);
