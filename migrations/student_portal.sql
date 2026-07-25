-- ============================================================================
-- STUDENT PORTAL — schema
-- Adds login accounts for enrolled students and a future-ready grades table.
-- Idempotent (safe to re-run). Reuses schoolyear(School_year_id) for the SY FK.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Login accounts. One row per enrolled student (keyed by enrollment_id).
-- Rows are created lazily on first login (see includes/student_auth.php), so
-- this migration only needs to create the table.
-- Named *_accounts to avoid colliding with the unrelated, pre-existing
-- `student_account` financial table.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_portal_accounts` (
  `id`                   int(11)      NOT NULL AUTO_INCREMENT,
  `enrollment_id`        int(11)      NOT NULL,
  `student_id`           varchar(110) NOT NULL,
  `lrn`                  varchar(40)  NOT NULL COMMENT 'Login username',
  `password_hash`        varchar(255) NOT NULL,
  `must_change_password` tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1 = still on default password',
  `status`               enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `last_login`           datetime     DEFAULT NULL,
  `created_at`           timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`           timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spa_enrollment` (`enrollment_id`),
  KEY `idx_spa_lrn` (`lrn`),
  CONSTRAINT `fk_spa_enrollment` FOREIGN KEY (`enrollment_id`)
      REFERENCES `enrollment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Grades — placeholder structure for the future Grade Viewing module.
-- Intentionally has no rows yet; the portal page shows "under construction".
-- One row = one subject's grades for a student in a school year.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_grades` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `enrollment_id`  int(11)      NOT NULL,
  `school_year_id` int(11)      NOT NULL,
  `subject`        varchar(120) NOT NULL,
  `component`      varchar(60)  DEFAULT NULL COMMENT 'e.g. Academic / Madrasah',
  `q1`             decimal(5,2) DEFAULT NULL,
  `q2`             decimal(5,2) DEFAULT NULL,
  `q3`             decimal(5,2) DEFAULT NULL,
  `q4`             decimal(5,2) DEFAULT NULL,
  `final_grade`    decimal(5,2) DEFAULT NULL,
  `remarks`        varchar(40)  DEFAULT NULL COMMENT 'Passed / Failed / INC',
  `created_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`     timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sg_enrollment` (`enrollment_id`),
  KEY `idx_sg_sy` (`school_year_id`),
  CONSTRAINT `fk_sg_enrollment` FOREIGN KEY (`enrollment_id`)
      REFERENCES `enrollment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sg_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
