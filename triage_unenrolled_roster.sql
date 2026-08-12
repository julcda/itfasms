-- ============================================================================
--  TRIAGE (READ-ONLY): studentinfo roster rows with NO Officially-Enrolled
--  enrollment for the active school year.
--  ---------------------------------------------------------------------------
--  These rows are counted by the LMS/teacher roster but not by the legacy admin
--  (which counts Officially-Enrolled only), so they make the LMS read higher.
--  This script only REPORTS — it changes nothing. Work through it WITH THE
--  REGISTRAR and decide each row; a per-student delete template is at the bottom.
--
--  IMPORTANT: a non-12-digit ("suspect") LRN does NOT mean a fake student —
--  many elementary pupils have placeholder LRNs (e.g. Grade-3 "USAMA"/section 34
--  has real enrolled kids with short LRNs). Never bulk-delete by LRN pattern.
--
--  review_bucket:
--    1-no LRN      -> row has no LRN at all (can't be matched to enrollment)
--    2-suspect LRN -> LRN isn't a 12-digit number (could be a real elem. pupil)
--    3-valid LRN   -> proper 12-digit LRN but not enrolled this year (reconcile)
-- ============================================================================

SET @sy_id    := (SELECT School_year_id FROM schoolyear WHERE Status=1 ORDER BY School_year_id DESC LIMIT 1);
SET @sy_label := (SELECT School_year    FROM schoolyear WHERE Status=1 ORDER BY School_year_id DESC LIMIT 1);

SELECT DISTINCT
       CAST(si.Section AS UNSIGNED) AS section_id, sc.Section_name, g.Gradelevel,
       si.LRN_no, si.Lastname, si.Firstname,
       CASE WHEN si.LRN_no IS NULL OR si.LRN_no='' THEN '1-no LRN'
            WHEN si.LRN_no NOT REGEXP '^[0-9]{12}$'  THEN '2-suspect LRN'
            ELSE '3-valid LRN' END AS review_bucket
FROM studentinfo si
LEFT JOIN section    sc ON sc.Section_id = CAST(si.Section AS UNSIGNED)
LEFT JOIN gradelevel g  ON g.Gradelevel_id = sc.Gradelevel_id
LEFT JOIN preregistration   p   ON CONVERT(p.lrn   USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(si.LRN_no USING utf8mb4) COLLATE utf8mb4_general_ci
LEFT JOIN old_studentprofile osp ON CONVERT(osp.lrn USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(si.LRN_no USING utf8mb4) COLLATE utf8mb4_general_ci
LEFT JOIN new_studentprofile nsp ON CONVERT(nsp.lrn USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(si.LRN_no USING utf8mb4) COLLATE utf8mb4_general_ci
LEFT JOIN enrollment e ON CONVERT(e.school_year USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(@sy_label USING utf8mb4) COLLATE utf8mb4_general_ci
     AND e.Status='Officially Enrolled'
     AND ( CONVERT(e.student_id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(p.id  USING utf8mb4) COLLATE utf8mb4_general_ci
        OR CONVERT(e.student_id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(osp.student_id USING utf8mb4) COLLATE utf8mb4_general_ci
        OR CONVERT(e.student_id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(nsp.id USING utf8mb4) COLLATE utf8mb4_general_ci )
WHERE si.School_year_id = @sy_id AND e.id IS NULL
ORDER BY review_bucket, section_id, si.Lastname;

-- ── Per-student removal template (use ONLY after the registrar confirms a row
--    is genuinely not a real enrolled student). Uncomment and fill the LRN:
--
-- DELETE FROM studentinfo
--  WHERE School_year_id = @sy_id
--    AND CONVERT(LRN_no USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT('<LRN_HERE>' USING utf8mb4) COLLATE utf8mb4_general_ci
--    AND Section = '<SECTION_ID_HERE>';
