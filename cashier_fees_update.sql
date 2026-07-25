-- ============================================================
-- ITFA Cashier Fee Structure Update
-- Run this once against your database.
-- ============================================================

-- 1. Add activity_fee and house_registration columns
--    to payment_breakdown (safe, idempotent with IF NOT EXISTS).
-- ============================================================

ALTER TABLE `payment_breakdown`
  ADD COLUMN IF NOT EXISTS `activity_fee`      decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `Enrollment`,
  ADD COLUMN IF NOT EXISTS `house_registration` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `activity_fee`;

-- 2. Set activity fees
-- ============================================================
-- Most departments (Elementary/JHS):
--   Athletic(200) + TSC(120) + School Publication(100) + Madrasah(70) = 490
UPDATE `payment_breakdown`
   SET activity_fee = 490.00
 WHERE classification_id NOT IN (9, 12, 15)
   AND activity_fee = 0.00;

-- SHS Regular (15) and Voucher (12): no Madrasah = 420
UPDATE `payment_breakdown`
   SET activity_fee = 420.00
 WHERE classification_id IN (12, 15)
   AND activity_fee = 0.00;

-- Pandarat (full scholarship): zero activity fee
UPDATE `payment_breakdown`
   SET activity_fee = 0.00
 WHERE classification_id = 9;

-- 3. Set house registration fee
-- ============================================================
UPDATE `payment_breakdown`
   SET house_registration = 250.00
 WHERE classification_id != 9
   AND house_registration = 0.00;

UPDATE `payment_breakdown`
   SET house_registration = 0.00
 WHERE classification_id = 9;

-- 4. Update Cash / Installment amounts to match the official fee schedule
-- ============================================================

-- JHS Regular (non-ESC) — classification_id = 14
UPDATE `payment_breakdown` SET Cash = 7450.00, Installment = 655.00 WHERE classification_id = 14 AND type = 'New';
UPDATE `payment_breakdown` SET Cash = 7250.00, Installment = 655.00 WHERE classification_id = 14 AND type = 'Old';

-- SHS Regular — classification_id = 15
UPDATE `payment_breakdown` SET Cash = 7450.00, Installment = 655.00 WHERE classification_id = 15 AND type = 'New';
UPDATE `payment_breakdown` SET Cash = 7250.00, Installment = 655.00 WHERE classification_id = 15 AND type = 'Old';

-- ESC Grantee (JHS) — classification_id = 8
UPDATE `payment_breakdown` SET Cash = 6450.00, Installment = 555.00, Enrollment = 900.00 WHERE classification_id = 8 AND type = 'New';
UPDATE `payment_breakdown` SET Cash = 6250.00, Installment = 555.00, Enrollment = 700.00 WHERE classification_id = 8 AND type = 'Old';

-- Elementary Regular (G1–G3 values; G4–G6 differs only by ₱25 lab fee/month)
-- classification_id = 10
UPDATE `payment_breakdown` SET Cash = 12385.00, Installment = 891.75 WHERE classification_id = 10 AND type = 'New';
UPDATE `payment_breakdown` SET Cash = 12185.00, Installment = 891.75 WHERE classification_id = 10 AND type = 'Old';

-- 5. Create fee_schedule reference table
-- ============================================================
CREATE TABLE IF NOT EXISTS `fee_schedule` (
  `id`                  int(11)      NOT NULL AUTO_INCREMENT,
  `department`          varchar(50)  NOT NULL,
  `level`               varchar(100) NOT NULL,
  `student_type`        varchar(10)  NOT NULL COMMENT 'New or Old',
  `tuition_monthly`     decimal(10,2) DEFAULT '0.00',
  `misc_monthly`        decimal(10,2) DEFAULT '0.00'  COMMENT 'Miscellaneous per month',
  `improvement_monthly` decimal(10,2) DEFAULT '0.00',
  `books_monthly`       decimal(10,2) DEFAULT '0.00',
  `total_monthly`       decimal(10,2) DEFAULT '0.00',
  `enrollment_admission` decimal(10,2) DEFAULT '0.00' COMMENT 'Admission/Registration at enrollment',
  `enrollment_books`    decimal(10,2) DEFAULT '0.00'  COMMENT 'Book down-payment at enrollment',
  `activity_fee`        decimal(10,2) DEFAULT '0.00',
  `house_registration`  decimal(10,2) DEFAULT '0.00',
  `total_enrollment`    decimal(10,2) DEFAULT '0.00'  COMMENT 'Total due on enrollment day',
  `full_payment`        decimal(10,2) DEFAULT '0.00'  COMMENT 'Full annual payment',
  `sort_order`          int(11)       DEFAULT '0',
  `is_esc`              tinyint(1)    DEFAULT '0',
  `is_voucher`          tinyint(1)    DEFAULT '0',
  `schyear_id`          int(11)       DEFAULT NULL,
  `status`              varchar(10)   DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Populate fee_schedule (truncate first for idempotency)
-- ============================================================
TRUNCATE TABLE `fee_schedule`;

INSERT INTO `fee_schedule`
  (`department`, `level`, `student_type`,
   `tuition_monthly`, `misc_monthly`, `improvement_monthly`, `books_monthly`, `total_monthly`,
   `enrollment_admission`, `enrollment_books`, `activity_fee`, `house_registration`, `total_enrollment`,
   `full_payment`, `sort_order`, `is_esc`, `is_voucher`, `schyear_id`, `status`)
VALUES
-- ── Kindergarten / Tahderiyah ─────────────────────────────────────────────────
('Elementary', 'Kindergarten / Tahderiyah', 'New',
  600.00, 75.00, 50.00, 0.00, 725.00,
  1050.00, 0.00, 490.00, 250.00, 1790.00,
  7950.00, 1, 0, 0, 2, 'Active'),

('Elementary', 'Kindergarten / Tahderiyah', 'Old',
  600.00, 75.00, 50.00, 0.00, 725.00,
  1050.00, 0.00, 490.00, 250.00, 1790.00,
  7950.00, 1, 0, 0, 2, 'Active'),

-- ── Elementary Grade 1 – Grade 3 ─────────────────────────────────────────────
('Elementary', 'Grade 1 – Grade 3', 'New',
  505.00, 75.00, 50.00, 261.75, 891.75,
  850.00, 2617.50, 490.00, 250.00, 4207.50,
  12385.00, 2, 0, 0, 2, 'Active'),

('Elementary', 'Grade 1 – Grade 3', 'Old',
  505.00, 75.00, 50.00, 261.75, 891.75,
  650.00, 2617.50, 490.00, 250.00, 4007.50,
  12185.00, 2, 0, 0, 2, 'Active'),

-- ── Elementary Grade 4 – Grade 6 ─────────────────────────────────────────────
('Elementary', 'Grade 4 – Grade 6', 'New',
  505.00, 100.00, 50.00, 261.75, 916.75,
  850.00, 2617.50, 490.00, 250.00, 4207.50,
  12635.00, 3, 0, 0, 2, 'Active'),

('Elementary', 'Grade 4 – Grade 6', 'Old',
  505.00, 100.00, 50.00, 261.75, 916.75,
  650.00, 2617.50, 490.00, 250.00, 4007.50,
  12435.00, 3, 0, 0, 2, 'Active'),

-- ── Junior High School (Regular) ──────────────────────────────────────────────
('Junior High', 'Grade 7 – Grade 10 (Regular)', 'New',
  505.00, 100.00, 50.00, 0.00, 655.00,
  900.00, 0.00, 490.00, 250.00, 1640.00,
  7450.00, 4, 0, 0, 2, 'Active'),

('Junior High', 'Grade 7 – Grade 10 (Regular)', 'Old',
  505.00, 100.00, 50.00, 0.00, 655.00,
  700.00, 0.00, 490.00, 250.00, 1440.00,
  7250.00, 4, 0, 0, 2, 'Active'),

-- ── Junior High School (ESC Grantee) ─────────────────────────────────────────
('Junior High', 'Grade 7 – Grade 10 (ESC)', 'New',
  405.00, 100.00, 50.00, 0.00, 555.00,
  900.00, 0.00, 490.00, 250.00, 1640.00,
  6450.00, 5, 1, 0, 2, 'Active'),

('Junior High', 'Grade 7 – Grade 10 (ESC)', 'Old',
  405.00, 100.00, 50.00, 0.00, 555.00,
  700.00, 0.00, 490.00, 250.00, 1440.00,
  6250.00, 5, 1, 0, 2, 'Active'),

-- ── Senior High School (Regular) ─────────────────────────────────────────────
('Senior High', 'Grade 11 – Grade 12 (Regular)', 'New',
  505.00, 100.00, 50.00, 0.00, 655.00,
  900.00, 0.00, 420.00, 250.00, 1570.00,
  7450.00, 6, 0, 0, 2, 'Active'),

('Senior High', 'Grade 11 – Grade 12 (Regular)', 'Old',
  505.00, 100.00, 50.00, 0.00, 655.00,
  700.00, 0.00, 420.00, 250.00, 1370.00,
  7250.00, 6, 0, 0, 2, 'Active'),

-- ── Senior High School (With Voucher) ────────────────────────────────────────
('Senior High', 'Grade 11 – Grade 12 (Voucher)', 'New',
  0.00, 0.00, 0.00, 0.00, 0.00,
  0.00, 0.00, 420.00, 250.00, 670.00,
  0.00, 7, 0, 1, 2, 'Active'),

('Senior High', 'Grade 11 – Grade 12 (Voucher)', 'Old',
  0.00, 0.00, 0.00, 0.00, 0.00,
  0.00, 0.00, 420.00, 250.00, 670.00,
  0.00, 7, 0, 1, 2, 'Active');

-- ============================================================
-- Activity Fee Reference (informational comment)
-- Athletic Fee       : PHP 200.00  (all departments)
-- TSC Fee            : PHP 120.00  (all departments)
-- School Publication : PHP 100.00  (all departments)
-- Madrasah Reg.      : PHP  70.00  (Elementary & JHS only; SHS excluded)
-- ──────────────────────────────────────────────────────────
-- Elementary / JHS total : PHP 490.00
-- Senior High total      : PHP 420.00
-- ============================================================
