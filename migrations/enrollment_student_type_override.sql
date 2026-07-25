-- ============================================================================
-- Manual Old/New override for fee resolution.
-- enrollment.student_type: NULL/'' = auto-detect (New if in preregistration,
-- else Old); 'New' or 'Old' = force that payment_breakdown.type tier.
-- ============================================================================
ALTER TABLE `enrollment`
  ADD COLUMN IF NOT EXISTS `student_type` VARCHAR(3) NULL DEFAULT NULL
  COMMENT 'Old/New fee override; NULL = auto-detect' AFTER `Student_classification`;
