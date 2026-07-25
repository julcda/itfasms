-- ============================================================================
-- Per-student Miscellaneous Fee exemption.
-- enrollment.waive_miscellaneous = 1 exempts the student from the monthly
-- Miscellaneous Fee component (set to 0), alongside waive_school_improvement.
-- ============================================================================
ALTER TABLE `enrollment`
  ADD COLUMN IF NOT EXISTS `waive_miscellaneous` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Fee exemption: 1 = do not charge the Miscellaneous Fee'
  AFTER `waive_school_improvement`;
