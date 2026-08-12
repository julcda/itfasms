-- ============================================================================
--  Provision missing grading/LMS roster rows  (fast, indexed version)
--  ---------------------------------------------------------------------------
--  Creates a `studentinfo` row for every Officially-Enrolled student (active
--  school year) who has NO roster row yet, so each section's teacher/LMS roster
--  matches the legacy (enrollment) count. Faithfully mirrors the native app's
--  resolve_studentinfo_id_for_enrollment() (includes/functions.php):
--     LRN_no = digits-only profile LRN | Type = Student_classification (def 10)
--     Department = UPPERCASE-normalised | Gradelevel/Section from enrollment
--     Status = 1 | password = '12345'
--  Skips anyone already on the roster (by LRN OR full name) -> no duplicates.
--
--  Run the WHOLE script in one go (phpMyAdmin: paste all, Go). It builds two
--  indexed temp tables, PREVIEWs what will be added, then INSERTs. Back up first.
-- ============================================================================

SET @sy_id    := (SELECT School_year_id FROM schoolyear WHERE Status=1 ORDER BY School_year_id DESC LIMIT 1);
SET @sy_label := (SELECT School_year    FROM schoolyear WHERE Status=1 ORDER BY School_year_id DESC LIMIT 1);

-- ── profile map: enrollment.student_id -> LRN/name (preregistration wins) ───
DROP TEMPORARY TABLE IF EXISTS _prof;
CREATE TEMPORARY TABLE _prof (
    sid        VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    lrn        VARCHAR(60)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    surname    VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    firstname  VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    middlename VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    PRIMARY KEY (sid)
);
INSERT IGNORE INTO _prof
SELECT CONVERT(CAST(id AS CHAR) USING utf8mb4) COLLATE utf8mb4_general_ci,
       CONVERT(lrn USING utf8mb4), CONVERT(surname USING utf8mb4),
       CONVERT(firstname USING utf8mb4), CONVERT(middlename USING utf8mb4)
FROM preregistration;
INSERT IGNORE INTO _prof
SELECT CONVERT(student_id USING utf8mb4) COLLATE utf8mb4_general_ci,
       CONVERT(lrn USING utf8mb4), CONVERT(surname USING utf8mb4),
       CONVERT(firstname USING utf8mb4), CONVERT(middlename USING utf8mb4)
FROM old_studentprofile;

-- ── existing roster keys for the active SY (LRN + full-name) ────────────────
DROP TEMPORARY TABLE IF EXISTS _roster;
CREATE TEMPORARY TABLE _roster (
    lrnkey  VARCHAR(60)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    namekey VARCHAR(770) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    KEY (lrnkey), KEY (namekey)
);
INSERT INTO _roster
SELECT NULLIF(CAST(LRN_no AS CHAR), ''),
       CONCAT_WS('|', CONVERT(TRIM(Lastname) USING utf8mb4), CONVERT(TRIM(Firstname) USING utf8mb4),
                      CONVERT(TRIM(IFNULL(Middlename,'')) USING utf8mb4))
FROM studentinfo WHERE School_year_id = @sy_id;

-- ── candidate = OE enrollment (active SY) + its profile, minus anyone already
--    on the roster by LRN or by name. Materialised once; used by PREVIEW+INSERT.
DROP TEMPORARY TABLE IF EXISTS _to_add;
CREATE TEMPORARY TABLE _to_add AS
SELECT
    NULLIF(REGEXP_REPLACE(pr.lrn, '[^0-9]', ''), '') AS lrn_digits,
    TRIM(pr.surname)    AS Lastname,
    TRIM(pr.firstname)  AS Firstname,
    TRIM(pr.middlename) AS Middlename,
    CASE WHEN CAST(e.Student_classification AS UNSIGNED) > 0
         THEN CAST(e.Student_classification AS UNSIGNED) ELSE 10 END AS Type,
    CASE LOWER(TRIM(e.Department))
         WHEN 'elementary'         THEN 'ELEMENTARY'
         WHEN 'junior high'        THEN 'JUNIOR HIGH'
         WHEN 'junior high school' THEN 'JUNIOR HIGH'
         WHEN 'jhs'                THEN 'JUNIOR HIGH'
         WHEN 'senior high'        THEN 'SENIOR HIGH'
         WHEN 'senior high school' THEN 'SENIOR HIGH'
         WHEN 'shs'                THEN 'SENIOR HIGH'
         ELSE UPPER(TRIM(e.Department)) END AS Department,
    NULLIF(CAST(e.Department_gradelevel AS UNSIGNED), 0) AS Gradelevel,
    CAST(e.Department_section AS UNSIGNED)               AS Section
FROM enrollment e
JOIN _prof pr ON pr.sid = CONVERT(e.student_id USING utf8mb4) COLLATE utf8mb4_general_ci
LEFT JOIN _roster rl ON rl.lrnkey  = NULLIF(REGEXP_REPLACE(pr.lrn, '[^0-9]', ''), '')
LEFT JOIN _roster rn ON rn.namekey = CONCAT_WS('|', TRIM(pr.surname), TRIM(pr.firstname), TRIM(IFNULL(pr.middlename,'')))
WHERE CONVERT(e.school_year USING utf8mb4) COLLATE utf8mb4_general_ci
      = CONVERT(@sy_label USING utf8mb4) COLLATE utf8mb4_general_ci
  AND e.Status = 'Officially Enrolled'
  AND CAST(e.Department_section AS UNSIGNED) > 0
  AND rl.lrnkey  IS NULL
  AND rn.namekey IS NULL;

-- ── 1) PREVIEW — the students that will be added ───────────────────────────
SELECT lrn_digits AS LRN_no, Lastname, Firstname, Section, Department, Gradelevel
FROM _to_add ORDER BY Section, Lastname;

-- ── 2) APPLY — insert them ─────────────────────────────────────────────────
INSERT INTO studentinfo
    (LRN_no, Lastname, Firstname, Middlename, Type, Department, Gradelevel, Section, School_year_id, Status, password)
SELECT lrn_digits, Lastname, Firstname, Middlename, Type, Department, Gradelevel, Section, @sy_id, 1, '12345'
FROM _to_add;

DROP TEMPORARY TABLE IF EXISTS _to_add;
DROP TEMPORARY TABLE IF EXISTS _prof;
DROP TEMPORARY TABLE IF EXISTS _roster;
