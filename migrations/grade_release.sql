-- ============================================================================
-- GRADE SLIP RELEASE — the Department Head publishes results to students.
--
-- WHY a separate table rather than a flag on grading_period:
--   grading_period is school-year-wide, but a Department Head only owns their own
--   department's classes (via classes.user_id — the same scoping the depthead
--   module and grade_review.php already use). A flag on the period would let the
--   Elementary head publish Senior High results. One row per (period, owner)
--   keeps each head's publish action inside their own department.
--
--   Verified against live data: 1,644 of 1,646 students have every class under a
--   single owner_user_id, so a student's slip resolves cleanly. The 2 exceptions
--   simply see the subjects whose owner has published — an honest partial slip
--   rather than a wrong one.
--
-- VISIBILITY RULE (the whole contract):
--   A student may see a subject's grade when
--     (a) a grade_release row exists for that class's owner + grading period, AND
--     (b) the grade itself is 'Approved' or 'Locked'.
--   Draft / Submitted / Returned grades are never shown — publishing must not
--   leak work in progress.
--
-- Withdrawing sets status='Withdrawn' instead of deleting, so the audit trail of
-- "this was once visible to students" survives.
--
-- Idempotent & portable. Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `grade_release` (
  `id`                int(11)     NOT NULL AUTO_INCREMENT,
  `school_year_id`    int(11)     NOT NULL,
  `grading_period_id` int(11)     NOT NULL,
  `owner_user_id`     int(11)     NOT NULL COMMENT 'Dept head who owns the classes (classes.user_id)',
  `status`            varchar(10) NOT NULL DEFAULT 'Released' COMMENT 'Released|Withdrawn',
  `note`              varchar(255) DEFAULT NULL,
  `released_by`       int(11)     DEFAULT NULL,
  `released_by_name`  varchar(120) DEFAULT NULL COMMENT 'Snapshot — survives renames',
  `released_at`       datetime    DEFAULT NULL,
  `withdrawn_by`      int(11)     DEFAULT NULL,
  `withdrawn_at`      datetime    DEFAULT NULL,
  `created_at`        timestamp   NOT NULL DEFAULT current_timestamp(),
  `updated_at`        timestamp   NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_release_period_owner` (`grading_period_id`, `owner_user_id`),
  KEY `idx_release_status` (`status`),
  KEY `idx_release_owner`  (`owner_user_id`, `school_year_id`),
  CONSTRAINT `fk_release_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_release_period` FOREIGN KEY (`grading_period_id`)
      REFERENCES `grading_period` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_release_owner` FOREIGN KEY (`owner_user_id`)
      REFERENCES `user_account` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- DONE.
-- ============================================================================
