-- ============================================================================
--  SECTION HEAD-COUNT COMPARISON  (read-only report — changes nothing)
--  Legacy masterlist (enrollment, Officially Enrolled)  vs
--  Grades & LMS class list (studentinfo roster), per section, active school year.
-- ============================================================================

SET @sy_id  := (SELECT School_year_id FROM schoolyear WHERE Status=1 ORDER BY School_year_id DESC LIMIT 1);
SET @sy_lbl := (SELECT School_year    FROM schoolyear WHERE School_year_id=@sy_id);

-- ── Per-section table ───────────────────────────────────────────────────────
SELECT grade, section, legacy_masterlist, grades_and_lms,
       grades_and_lms - legacy_masterlist AS difference,
       CASE WHEN grades_and_lms = legacy_masterlist THEN 'match'
            WHEN grades_and_lms <  legacy_masterlist THEN 'SHORT'
            ELSE 'OVER' END AS status
FROM (
   SELECT REPLACE(g.Gradelevel, '\n', '') AS grade,
          sc.Section_name AS section, sc.Section_name AS sn,
          CASE WHEN g.Gradelevel LIKE 'KINDER%' THEN 0
               ELSE CAST(REGEXP_REPLACE(COALESCE(g.Gradelevel,''), '[^0-9]', '') AS UNSIGNED) END AS grade_order,
          (SELECT COUNT(*) FROM enrollment e
             WHERE e.Department_section = sc.Section_id
               AND e.school_year = @sy_lbl AND e.Status = 'Officially Enrolled') AS legacy_masterlist,
          (SELECT COUNT(*) FROM studentinfo si
             WHERE CAST(si.Section AS UNSIGNED) = sc.Section_id
               AND si.School_year_id = @sy_id) AS grades_and_lms
   FROM section sc
   LEFT JOIN gradelevel g ON g.Gradelevel_id = sc.Gradelevel_id
) t
WHERE legacy_masterlist > 0 OR grades_and_lms > 0
ORDER BY grade_order, sn;

-- ── Summary ────────────────────────────────────────────────────────────────
SELECT COUNT(*)                              AS sections,
       SUM(legacy_masterlist)                AS total_legacy,
       SUM(grades_and_lms)                   AS total_grades_lms,
       SUM(grades_and_lms - legacy_masterlist) AS net_difference,
       SUM(grades_and_lms = legacy_masterlist) AS sections_matched,
       SUM(grades_and_lms < legacy_masterlist) AS sections_short,
       SUM(grades_and_lms > legacy_masterlist) AS sections_over
FROM (
   SELECT
     (SELECT COUNT(*) FROM enrollment e WHERE e.Department_section=sc.Section_id
        AND e.school_year=@sy_lbl AND e.Status='Officially Enrolled') AS legacy_masterlist,
     (SELECT COUNT(*) FROM studentinfo si WHERE CAST(si.Section AS UNSIGNED)=sc.Section_id
        AND si.School_year_id=@sy_id) AS grades_and_lms
   FROM section sc
) t
WHERE legacy_masterlist > 0 OR grades_and_lms > 0;
