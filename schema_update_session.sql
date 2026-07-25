-- ============================================================
--  ITFA Enrollment System — Schema Update
--  Generated : 2026-04-28
--  Safe to run on an existing production database.
--  All statements use IF NOT EXISTS so they can be run more
--  than once without error.
-- ============================================================

-- ------------------------------------------------------------
-- 1. payment_breakdown
--    Added: activity_fee, house_registration
--    Used by the Cashier payment modal fee breakdown.
-- ------------------------------------------------------------
ALTER TABLE `payment_breakdown`
    ADD COLUMN IF NOT EXISTS `activity_fee`       DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    ADD COLUMN IF NOT EXISTS `house_registration` DECIMAL(10,2) NOT NULL DEFAULT '0.00';

-- ------------------------------------------------------------
-- 2. backaccount_payment_records
--    Added: fee_admission, fee_activity, fee_books, fee_house_reg
--    Stores the per-fee breakdown for every payment record so
--    that reprinted receipts can show the full breakdown.
-- ------------------------------------------------------------
ALTER TABLE `backaccount_payment_records`
    ADD COLUMN IF NOT EXISTS `fee_admission` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    ADD COLUMN IF NOT EXISTS `fee_activity`  DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    ADD COLUMN IF NOT EXISTS `fee_books`     DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    ADD COLUMN IF NOT EXISTS `fee_house_reg` DECIMAL(10,2) NOT NULL DEFAULT '0.00';

-- ============================================================
--  END OF UPDATE
-- ============================================================
