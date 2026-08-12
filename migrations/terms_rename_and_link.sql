-- ============================================================================
--  "Grading" -> "Term"  +  link each Term to its Semester (for SHS)
--  ---------------------------------------------------------------------------
--  1) Renames the grading periods so they read "First/Second/Third Term".
--  2) Links each Term to the matching Semester so Senior High can have DIFFERENT
--     subjects/classes per term:  Term 1 = 1st, Term 2 = 2nd, Term 3 = 3rd.
--     (Elementary/JHS subjects stay year-long via the "N/A" semester and are
--      unaffected.)
--  Idempotent. Safe to re-run.
-- ============================================================================

-- 1) Rename: replace "Grading" with "Term" in every period name.
UPDATE `grading_period`
   SET `name` = REPLACE(REPLACE(`name`, 'Grading', 'Term'), '  ', ' ')
 WHERE `name` LIKE '%Grading%';

-- 2) Link each Term to its Semester (1st=1, 2nd=2, 3rd=id 4). Only sets the
--    standard 3 terms; a 4th period (if any) is left untouched.
UPDATE `grading_period` gp
  JOIN (SELECT Semester_id FROM semester WHERE Semester='1st' LIMIT 1) s1
   SET gp.semester_id = s1.Semester_id
 WHERE gp.term_no = 1;

UPDATE `grading_period` gp
  JOIN (SELECT Semester_id FROM semester WHERE Semester='2nd' LIMIT 1) s2
   SET gp.semester_id = s2.Semester_id
 WHERE gp.term_no = 2;

UPDATE `grading_period` gp
  JOIN (SELECT Semester_id FROM semester WHERE Semester='3rd' LIMIT 1) s3
   SET gp.semester_id = s3.Semester_id
 WHERE gp.term_no = 3;

-- Review
SELECT gp.id, gp.term_no, gp.name, gp.semester_id, sem.Semester AS term_semester, gp.status, gp.is_current
FROM grading_period gp LEFT JOIN semester sem ON sem.Semester_id = gp.semester_id
WHERE gp.school_year_id = (SELECT School_year_id FROM schoolyear WHERE Status=1 ORDER BY School_year_id DESC LIMIT 1)
ORDER BY gp.term_no;
