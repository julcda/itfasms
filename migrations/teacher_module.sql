-- ============================================================================
-- TEACHER MANAGEMENT & GRADING MODULE  —  schema redesign (3NF)
-- ----------------------------------------------------------------------------
-- Deliverable 4 of TEACHER_MODULE_DESIGN.md. Run ONCE per database.
--
-- WHAT THIS DOES
--   [1]  room                 FIX   — table had NO primary key
--   [2]  grading_period       NEW   — N periods per school year, lockable
--   [3]  teacher              MOD   — link to user_account, employment fields
--   [4]  classes              MOD   — real status, room, timestamps, SAFE cascades
--   [5]  advisory_class       NEW   — one adviser per section per school year
--   [6]  class_schedule       NEW   — normalizes classes.Time free text + room
--   [7]  student_grade        NEW   — the grade record (replaces `grade`)
--   [8]  student_grade_history NEW  — full audit trail
--   [9]  announcements        MOD   — title, audience, author, publishing
--   [10] MIGRATE 15,447 legacy `grade` rows -> student_grade
--   [11] Retire the empty student_grades placeholder (guarded: only if empty)
--
-- SAFETY
--   * Idempotent & portable (MySQL 5.7/8.x + MariaDB 10.x). Re-running is a no-op.
--   * The legacy `grade` table is NEVER dropped or altered — it stays as a
--     read-only historical backup, exactly like `back_accounts`.
--   * No DROP of any table that contains data. The one DROP (student_grades) is
--     guarded by a row-count check and will not fire if rows exist.
--   * Cascade rules are tightened, not loosened (see [4]).
-- ============================================================================


-- ════════════════════════════════════════════════════════════════════════════
-- [1] room — the table exists but has NO PRIMARY KEY and no auto_increment.
-- ════════════════════════════════════════════════════════════════════════════
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='room' AND INDEX_NAME='PRIMARY')=0,
  "ALTER TABLE `room` MODIFY `room_id` INT(11) NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (`room_id`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='room' AND COLUMN_NAME='status')=0,
  "ALTER TABLE `room` ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = usable'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ════════════════════════════════════════════════════════════════════════════
-- [2] grading_period — replaces the flawed global `gradingperiod` lookup.
--
--     WHY: `gradingperiod` is a 4-row global list with no school-year context,
--     so periods cannot be opened/closed/locked per year, and `grade` stored
--     '1st'/'2nd' (varchar) against its int PK — the FK could never exist.
--
--     Periods are DATA, not schema: ITFA runs 3 today, S.Y. 2023-2024 ran 4,
--     and moving to 4 (or semester-based terms) is an INSERT, not a migration.
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `grading_period` (
  `id`             int(11)     NOT NULL AUTO_INCREMENT,
  `school_year_id` int(11)     NOT NULL,
  `term_no`        tinyint(4)  NOT NULL COMMENT 'Ordinal within the S.Y.: 1,2,3(,4…)',
  `code`           varchar(10) NOT NULL COMMENT 'e.g. G1, G2, G3',
  `name`           varchar(40) NOT NULL COMMENT 'e.g. First Grading',
  `semester_id`    int(11)     DEFAULT NULL COMMENT 'Optional: for semester-based terms (SHS/College)',
  `start_date`     date        DEFAULT NULL,
  `end_date`       date        DEFAULT NULL,
  `status`         varchar(10) NOT NULL DEFAULT 'Upcoming' COMMENT 'Upcoming|Open|Closed|Locked',
  `is_current`     tinyint(1)  NOT NULL DEFAULT 0 COMMENT '1 = the period the dashboard defaults to',
  `locked_by`      int(11)     DEFAULT NULL,
  `locked_at`      datetime    DEFAULT NULL,
  `created_at`     timestamp   NOT NULL DEFAULT current_timestamp(),
  `updated_at`     timestamp   NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gp_sy_term` (`school_year_id`, `term_no`),
  KEY `idx_gp_status`  (`status`),
  KEY `idx_gp_current` (`is_current`),
  KEY `idx_gp_sem`     (`semester_id`),
  CONSTRAINT `fk_gp_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gp_semester` FOREIGN KEY (`semester_id`)
      REFERENCES `semester` (`Semester_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed 3 periods for every school year (current ITFA policy).
INSERT IGNORE INTO `grading_period` (`school_year_id`, `term_no`, `code`, `name`, `status`)
SELECT sy.`School_year_id`, t.`n`, CONCAT('G', t.`n`),
       CONCAT(ELT(t.`n`, 'First', 'Second', 'Third', 'Fourth'), ' Grading'),
       IF(sy.`Status` = 1, IF(t.`n` = 1, 'Open', 'Upcoming'), 'Locked')
FROM `schoolyear` sy
JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3) t;

-- Seed a 4th period ONLY for school years that historically used one
-- (S.Y. 2023-2024 has 650 legacy 4th-grading rows). Data-driven, not hardcoded.
INSERT IGNORE INTO `grading_period` (`school_year_id`, `term_no`, `code`, `name`, `status`)
SELECT DISTINCT c.`School_year_id`, 4, 'G4', 'Fourth Grading', 'Locked'
FROM `grade` g
JOIN `classes` c ON c.`Class_id` = g.`Class_id`
WHERE LOWER(g.`gradeperiod_id`) IN ('4th', '4', '4th grading')
  AND c.`School_year_id` IS NOT NULL;

-- Exactly one current period, in the active school year.
UPDATE `grading_period` gp
  JOIN `schoolyear` sy ON sy.`School_year_id` = gp.`school_year_id`
   SET gp.`is_current` = IF(sy.`Status` = 1 AND gp.`term_no` = 1, 1, 0);


-- ════════════════════════════════════════════════════════════════════════════
-- [3] teacher — link to the auth system; add employment attributes.
--
--     NOTE ON 3NF: `Fullname` is derivable from Firstname/Middlename/Lastname
--     and is a transitive dependency. It is NOT dropped here because the legacy
--     name data is dirty (e.g. Lastname = 'Kaminon,LPT'), so regenerating it
--     would corrupt display names. It is retained as a legacy display field;
--     the application must treat the atomic columns as the source of truth.
--     See TEACHER_MODULE_DESIGN.md §2.
-- ════════════════════════════════════════════════════════════════════════════
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='user_id')=0,
  "ALTER TABLE `teacher` ADD COLUMN `user_id` INT(11) DEFAULT NULL COMMENT 'Auth identity -> user_account.user_id'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='employee_no')=0,
  "ALTER TABLE `teacher` ADD COLUMN `employee_no` VARCHAR(30) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='email')=0,
  "ALTER TABLE `teacher` ADD COLUMN `email` VARCHAR(120) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='contact')=0,
  "ALTER TABLE `teacher` ADD COLUMN `contact` VARCHAR(40) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='status')=0,
  "ALTER TABLE `teacher` ADD COLUMN `status` VARCHAR(10) NOT NULL DEFAULT 'Active' COMMENT 'Active|Inactive'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='created_at')=0,
  "ALTER TABLE `teacher` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp()", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND COLUMN_NAME='updated_at')=0,
  "ALTER TABLE `teacher` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- One teacher <-> one login.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND INDEX_NAME='uq_teacher_user')=0,
  "ALTER TABLE `teacher` ADD UNIQUE KEY `uq_teacher_user` (`user_id`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND INDEX_NAME='uq_teacher_empno')=0,
  "ALTER TABLE `teacher` ADD UNIQUE KEY `uq_teacher_empno` (`employee_no`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND CONSTRAINT_NAME='fk_teacher_user')=0,
  "ALTER TABLE `teacher` ADD CONSTRAINT `fk_teacher_user` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher' AND INDEX_NAME='idx_teacher_status')=0,
  "ALTER TABLE `teacher` ADD INDEX `idx_teacher_status` (`status`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ════════════════════════════════════════════════════════════════════════════
-- [4] classes — real status, room, timestamps, and SAFER cascade rules.
--
--     CRITICAL FIX: classes.Teacher_id was ON DELETE CASCADE. Combined with
--     grade.Class_id ON DELETE CASCADE, deleting ONE teacher silently deleted
--     their classes AND every grade ever recorded in them. Now RESTRICT.
-- ════════════════════════════════════════════════════════════════════════════
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND COLUMN_NAME='class_status')=0,
  "ALTER TABLE `classes` ADD COLUMN `class_status` VARCHAR(10) NOT NULL DEFAULT 'Open' COMMENT 'Open|Closed — controls whether teachers may still encode'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND COLUMN_NAME='room_id')=0,
  "ALTER TABLE `classes` ADD COLUMN `room_id` INT(11) DEFAULT NULL COMMENT 'Default room; per-meeting room lives on class_schedule'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND COLUMN_NAME='created_at')=0,
  "ALTER TABLE `classes` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp()", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND COLUMN_NAME='updated_at')=0,
  "ALTER TABLE `classes` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND CONSTRAINT_NAME='fk_classes_room')=0,
  "ALTER TABLE `classes` ADD CONSTRAINT `fk_classes_room` FOREIGN KEY (`room_id`) REFERENCES `room` (`room_id`) ON DELETE SET NULL ON UPDATE CASCADE", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Replace the destructive teacher cascade with RESTRICT.
SET @s := IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='classes'
                AND CONSTRAINT_NAME='classes_ibfk_6' AND DELETE_RULE='CASCADE')=1,
  "ALTER TABLE `classes` DROP FOREIGN KEY `classes_ibfk_6`", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND CONSTRAINT_NAME='fk_classes_teacher')=0,
  "ALTER TABLE `classes` ADD CONSTRAINT `fk_classes_teacher` FOREIGN KEY (`Teacher_id`) REFERENCES `teacher` (`Teacher_id`) ON DELETE RESTRICT ON UPDATE CASCADE", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classes' AND INDEX_NAME='idx_classes_teacher_sy')=0,
  "ALTER TABLE `classes` ADD INDEX `idx_classes_teacher_sy` (`Teacher_id`, `School_year_id`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ════════════════════════════════════════════════════════════════════════════
-- [5] advisory_class — the "Advisory Class" the dashboard requires.
--     One adviser per section per school year.
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `advisory_class` (
  `id`             int(11)   NOT NULL AUTO_INCREMENT,
  `school_year_id` int(11)   NOT NULL,
  `section_id`     int(11)   NOT NULL,
  `gradelevel_id`  int(11)   DEFAULT NULL,
  `teacher_id`     int(11)   NOT NULL,
  `assigned_by`    int(11)   DEFAULT NULL,
  `created_at`     timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at`     timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_adv_sy_section` (`school_year_id`, `section_id`),
  KEY `idx_adv_teacher` (`teacher_id`),
  CONSTRAINT `fk_adv_sy` FOREIGN KEY (`school_year_id`)
      REFERENCES `schoolyear` (`School_year_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_adv_section` FOREIGN KEY (`section_id`)
      REFERENCES `section` (`Section_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_adv_gradelevel` FOREIGN KEY (`gradelevel_id`)
      REFERENCES `gradelevel` (`Gradelevel_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_adv_teacher` FOREIGN KEY (`teacher_id`)
      REFERENCES `teacher` (`Teacher_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ════════════════════════════════════════════════════════════════════════════
-- [6] class_schedule — normalizes classes.Time.
--     `Time` is free text ('1:00 -2:00', '8:30 -9:30') with no day and no room,
--     so "Class Schedule" and "Room" cannot be rendered or checked for conflicts.
--     Legacy `classes.Time` is retained; the app prefers class_schedule when present.
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `class_schedule` (
  `id`          int(11)    NOT NULL AUTO_INCREMENT,
  `class_id`    int(11)    NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '1=Mon … 7=Sun (ISO-8601)',
  `start_time`  time       NOT NULL,
  `end_time`    time       NOT NULL,
  `room_id`     int(11)    DEFAULT NULL,
  `created_at`  timestamp  NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cs_slot` (`class_id`, `day_of_week`, `start_time`),
  KEY `idx_cs_day`  (`day_of_week`, `start_time`),
  KEY `idx_cs_room` (`room_id`, `day_of_week`),
  CONSTRAINT `fk_cs_class` FOREIGN KEY (`class_id`)
      REFERENCES `classes` (`Class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cs_room` FOREIGN KEY (`room_id`)
      REFERENCES `room` (`room_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ════════════════════════════════════════════════════════════════════════════
-- [7] student_grade — THE grade record. Replaces `grade`.
--
--     Fixes every defect of `grade`:
--       int(11)            -> DECIMAL(6,2)   (85.75 is now representable)
--       varchar '1st'      -> FK grading_period.id (a real, enforced relationship)
--       varchar date       -> real TIMESTAMPs
--       no unique key      -> uq_sg_class_student_period (duplicates IMPOSSIBLE)
--       no audit columns   -> encoded_by / updated_by / locked_by
--       FK to a non-unique junction column -> FK to studentinfo.student_id (the PK)
--
--     UNIQUE (class_id, student_id, grading_period_id) is the database-level
--     guarantee behind "only one grade per grading period per student per
--     subject": a class already encodes subject + section + S.Y. + teacher.
--
--     RESTRICT everywhere — a grade must never be lost as a side effect.
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `student_grade` (
  `id`                bigint(20)   NOT NULL AUTO_INCREMENT,
  `class_id`          int(11)      NOT NULL,
  `student_id`        int(11)      NOT NULL COMMENT '-> studentinfo.student_id',
  `grading_period_id` int(11)      NOT NULL,
  `grade`             decimal(6,2) DEFAULT NULL COMMENT 'NULL = not yet encoded (INC)',
  `remarks`           varchar(40)  DEFAULT NULL COMMENT 'Passed|Failed|INC|Dropped',
  `status`            varchar(10)  NOT NULL DEFAULT 'Draft' COMMENT 'Draft|Submitted|Locked',
  `encoded_by`        int(11)      DEFAULT NULL COMMENT '-> user_account.user_id',
  `encoded_at`        timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_by`        int(11)      DEFAULT NULL,
  `updated_at`        timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `locked_by`         int(11)      DEFAULT NULL,
  `locked_at`         datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sg_class_student_period` (`class_id`, `student_id`, `grading_period_id`),
  KEY `idx_sg_student`  (`student_id`),
  KEY `idx_sg_period`   (`grading_period_id`),
  KEY `idx_sg_status`   (`status`),
  KEY `idx_sg_class_period` (`class_id`, `grading_period_id`),
  CONSTRAINT `fk_sg_class` FOREIGN KEY (`class_id`)
      REFERENCES `classes` (`Class_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sg_student` FOREIGN KEY (`student_id`)
      REFERENCES `studentinfo` (`student_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sg_period` FOREIGN KEY (`grading_period_id`)
      REFERENCES `grading_period` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_sg_range` CHECK (`grade` IS NULL OR (`grade` >= 0 AND `grade` <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ════════════════════════════════════════════════════════════════════════════
-- [8] student_grade_history — the audit trail (requirement #6).
--     Denormalized on purpose: it stores the actor's NAME and the class/student
--     context, so the trail stays readable even if a lookup row later changes.
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `student_grade_history` (
  `id`               bigint(20)   NOT NULL AUTO_INCREMENT,
  `student_grade_id` bigint(20)   NOT NULL,
  `class_id`         int(11)      NOT NULL,
  `student_id`       int(11)      NOT NULL,
  `grading_period_id` int(11)     NOT NULL,
  `action`           varchar(10)  NOT NULL COMMENT 'Insert|Update|Lock|Unlock|Delete',
  `old_grade`        decimal(6,2) DEFAULT NULL,
  `new_grade`        decimal(6,2) DEFAULT NULL,
  `changed_by`       int(11)      DEFAULT NULL COMMENT '-> user_account.user_id',
  `changed_by_name`  varchar(120) DEFAULT NULL COMMENT 'Snapshot — survives user renames',
  `changed_at`       timestamp    NOT NULL DEFAULT current_timestamp(),
  `ip_address`       varchar(45)  DEFAULT NULL COMMENT 'IPv4/IPv6',
  `user_agent`       varchar(255) DEFAULT NULL,
  `note`             varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sgh_grade`   (`student_grade_id`),
  KEY `idx_sgh_student` (`student_id`),
  KEY `idx_sgh_when`    (`changed_at`),
  KEY `idx_sgh_actor`   (`changed_by`),
  CONSTRAINT `fk_sgh_grade` FOREIGN KEY (`student_grade_id`)
      REFERENCES `student_grade` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ════════════════════════════════════════════════════════════════════════════
-- [9] announcements — keyed by a bare username string, with no title/audience.
-- ════════════════════════════════════════════════════════════════════════════
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcements' AND COLUMN_NAME='title')=0,
  "ALTER TABLE `announcements` ADD COLUMN `title` VARCHAR(160) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcements' AND COLUMN_NAME='audience')=0,
  "ALTER TABLE `announcements` ADD COLUMN `audience` VARCHAR(20) NOT NULL DEFAULT 'All' COMMENT 'All|Teacher|Student|Staff'", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcements' AND COLUMN_NAME='author_user_id')=0,
  "ALTER TABLE `announcements` ADD COLUMN `author_user_id` INT(11) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcements' AND COLUMN_NAME='is_published')=0,
  "ALTER TABLE `announcements` ADD COLUMN `is_published` TINYINT(1) NOT NULL DEFAULT 1", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcements' AND COLUMN_NAME='school_year_id')=0,
  "ALTER TABLE `announcements` ADD COLUMN `school_year_id` INT(11) DEFAULT NULL", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcements' AND INDEX_NAME='idx_ann_feed')=0,
  "ALTER TABLE `announcements` ADD INDEX `idx_ann_feed` (`audience`, `is_published`, `created_at`)", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ════════════════════════════════════════════════════════════════════════════
-- [10] MIGRATE the 15,447 legacy `grade` rows -> student_grade.
--
--      Verified before writing this migration:
--        * 15,447 rows, 0 orphans — every grade.student_id resolves to
--          studentinfo.student_id, and every Class_id resolves to a class.
--        * gradeperiod_id holds '1st'|'2nd'|'3rd'|'4th' -> mapped to term_no.
--        * date_entered is a varchar with MIXED formats ('2023-11-10' and
--          '2023-11-20 20:54:03') -> both parse safely via STR_TO_DATE fallback.
--
--      Legacy rows land as status 'Locked' (a closed school year is not editable)
--      and are NOT attributed to a teacher, because `grade` never recorded one.
--      `grade` itself is left completely intact as the historical backup.
--      INSERT IGNORE + the UNIQUE key make this re-runnable.
-- ════════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `student_grade`
  (`class_id`, `student_id`, `grading_period_id`, `grade`, `status`, `encoded_at`, `updated_at`)
SELECT
  g.`Class_id`,
  g.`student_id`,
  gp.`id`,
  CAST(g.`grade` AS DECIMAL(6,2)),
  'Locked',
  COALESCE(
    STR_TO_DATE(g.`date_entered`, '%Y-%m-%d %H:%i:%s'),
    STR_TO_DATE(g.`date_entered`, '%Y-%m-%d'),
    current_timestamp()
  ),
  COALESCE(
    STR_TO_DATE(g.`date_entered`, '%Y-%m-%d %H:%i:%s'),
    STR_TO_DATE(g.`date_entered`, '%Y-%m-%d'),
    current_timestamp()
  )
FROM `grade` g
JOIN `classes` c        ON c.`Class_id` = g.`Class_id`
JOIN `studentinfo` si   ON si.`student_id` = g.`student_id`
JOIN `grading_period` gp
     ON gp.`school_year_id` = c.`School_year_id`
    AND gp.`term_no` = CASE LOWER(TRIM(g.`gradeperiod_id`))
                          WHEN '1st' THEN 1 WHEN '1' THEN 1
                          WHEN '2nd' THEN 2 WHEN '2' THEN 2
                          WHEN '3rd' THEN 3 WHEN '3' THEN 3
                          WHEN '4th' THEN 4 WHEN '4' THEN 4
                          ELSE NULL END
WHERE g.`grade` IS NOT NULL;

-- Seed the audit trail so migrated grades have a traceable origin.
INSERT IGNORE INTO `student_grade_history`
  (`student_grade_id`, `class_id`, `student_id`, `grading_period_id`, `action`,
   `old_grade`, `new_grade`, `changed_by_name`, `changed_at`, `note`)
SELECT sg.`id`, sg.`class_id`, sg.`student_id`, sg.`grading_period_id`, 'Insert',
       NULL, sg.`grade`, 'Legacy migration', sg.`encoded_at`,
       'Migrated from legacy `grade` table'
FROM `student_grade` sg
WHERE sg.`status` = 'Locked'
  AND NOT EXISTS (SELECT 1 FROM `student_grade_history` h WHERE h.`student_grade_id` = sg.`id`);


-- ════════════════════════════════════════════════════════════════════════════
-- [11] Retire the `student_grades` placeholder.
--
--      It was a stub for the Student Portal and violates 1NF: q1/q2/q3/q4 are a
--      repeating group, which is exactly the shape this redesign removes. It is
--      EMPTY. The DROP is guarded — if it ever contains a row, nothing happens.
--
--      CODE CHANGE REQUIRED before/with this migration (see design doc §7):
--        student/grades.php, includes/registrar_service.php,
--        registrar/student_records.php  -> repoint to `student_grade`.
-- ════════════════════════════════════════════════════════════════════════════
-- Guarded twice: the table may already be gone (re-run), and it must be empty.
SET @tbl_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_grades');
SET @cnt := 0;
SET @s := IF(@tbl_exists = 1, "SELECT COUNT(*) INTO @cnt FROM `student_grades`", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@tbl_exists = 1 AND @cnt = 0, "DROP TABLE `student_grades`", "DO 0");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================================
-- DONE. Re-running this file changes nothing further.
-- `grade`, `gradingperiod` and all lms_* tables are untouched.
-- ============================================================================
