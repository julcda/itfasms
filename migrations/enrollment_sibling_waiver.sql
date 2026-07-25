-- ============================================================================
-- Per-family School Improvement waiver.
-- enrollment.waive_school_improvement = 1 marks a student whose family already
-- pays the School Improvement fee through a sibling, so this student's monthly
-- School Improvement component is waived (set to 0) regardless of classification.
-- ============================================================================
ALTER TABLE `enrollment`
  ADD COLUMN IF NOT EXISTS `waive_school_improvement` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Sibling waiver: 1 = do not charge School Improvement (paid by a sibling)'
  AFTER `student_type`;
