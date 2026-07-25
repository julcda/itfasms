-- ============================================================================
-- CERTIFICATES OF RECOGNITION (Academic Honors)
--
-- FLOW
--   Class Adviser issues  ->  status 'Draft'
--   Department Head publishes -> status 'Published'  (only then is it visible
--                                                     in the Student Portal)
--   Either may revoke     ->  status 'Revoked'
--
-- HONOR LEVELS (DepEd standard bands, stored not derived — a certificate must
-- keep saying what it said when signed, even if the grade is later corrected):
--   With Honors          90–94
--   With High Honors     95–97
--   With Highest Honors  98–100
--
-- QR VERIFICATION
--   certificate_no is the human-readable id printed on the paper.
--   verify_token is a random secret; the QR encodes BOTH. verify.php requires
--   the token to match, so a printed certificate number alone cannot be used to
--   forge or enumerate records. The token is not derived from the number.
--
-- Idempotent & portable. Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `certificate` (
  `id`                int(11)      NOT NULL AUTO_INCREMENT,
  `certificate_no`    varchar(30)  NOT NULL COMMENT 'Printed id, e.g. CR-2026-000001',
  `verify_token`      varchar(32)  NOT NULL COMMENT 'Random secret encoded in the QR',
  `type`              varchar(30)  NOT NULL DEFAULT 'Academic Honor',
  `student_id`        int(11)      NOT NULL COMMENT '-> studentinfo.student_id',
  `student_name`      varchar(160) NOT NULL COMMENT 'Snapshot at issue time',
  `lrn`               varchar(40)  DEFAULT NULL,
  `grade_level`       varchar(60)  DEFAULT NULL COMMENT 'Snapshot',
  `section_name`      varchar(60)  DEFAULT NULL COMMENT 'Snapshot',
  `school_year_id`    int(11)      NOT NULL,
  `school_year`       varchar(20)  DEFAULT NULL COMMENT 'Snapshot',
  `grading_period_id` int(11)      DEFAULT NULL COMMENT 'NULL = whole school year',
  `period_name`       varchar(40)  DEFAULT NULL COMMENT 'Snapshot',
  `honor_level`       varchar(30)  NOT NULL COMMENT 'With Honors|With High Honors|With Highest Honors',
  `general_average`   decimal(6,2) DEFAULT NULL,
  `adviser_teacher_id` int(11)     DEFAULT NULL,
  `adviser_name`      varchar(160) DEFAULT NULL COMMENT 'Snapshot — signs the certificate',
  `principal_name`    varchar(160) NOT NULL DEFAULT 'MUJAHIDIN I. GARAY, LPT, MAEd',
  `status`            varchar(12)  NOT NULL DEFAULT 'Draft' COMMENT 'Draft|Published|Revoked',
  `remarks`           varchar(255) DEFAULT NULL,
  `issued_by`         int(11)      DEFAULT NULL,
  `issued_at`         timestamp    NOT NULL DEFAULT current_timestamp(),
  `published_by`      int(11)      DEFAULT NULL,
  `published_by_name` varchar(160) DEFAULT NULL,
  `published_at`      datetime     DEFAULT NULL,
  `revoked_by`        int(11)      DEFAULT NULL,
  `revoked_at`        datetime     DEFAULT NULL,
  `updated_at`        timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cert_no` (`certificate_no`),
  -- One honor certificate per student per period per type: re-issuing updates
  -- rather than silently creating a second, conflicting certificate.
  UNIQUE KEY `uq_cert_student_period` (`student_id`, `school_year_id`, `grading_period_id`, `type`),
  KEY `idx_cert_status`   (`status`),
  KEY `idx_cert_student`  (`student_id`, `status`),
  KEY `idx_cert_sy`       (`school_year_id`, `grading_period_id`),
  KEY `idx_cert_adviser`  (`adviser_teacher_id`),
  CONSTRAINT `fk_cert_student` FOREIGN KEY (`student_id`)
      REFERENCES `studentinfo` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cert_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cert_period` FOREIGN KEY (`grading_period_id`)
      REFERENCES `grading_period` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cert_adviser` FOREIGN KEY (`adviser_teacher_id`)
      REFERENCES `teacher` (`Teacher_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Numbering series for certificate_no (reuses the gap-free document_series).
INSERT IGNORE INTO `document_series` (`series_code`, `year`, `last_seq`)
VALUES ('CERT', YEAR(CURDATE()), 0);

-- The principal's name is configurable rather than hardcoded in the view,
-- so a change of principal is a settings edit, not a code change.
INSERT IGNORE INTO `system_setting` (`setting_key`, `setting_value`)
VALUES ('PRINCIPAL_NAME', 'MUJAHIDIN I. GARAY, LPT, MAEd');

-- ============================================================================
-- DONE.
-- ============================================================================
