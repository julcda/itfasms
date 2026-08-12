<?php

/**
 * Phase 2 — SOA service helpers (procedural, mysqli).
 *
 * Reusable building blocks for the Statement of Account + SOA-based payment
 * system. Functions that mutate data assume the CALLER controls the
 * transaction (mysqli does not support nested transactions). The document
 * number generator is transaction-safe and gap-free.
 *
 * Depends on: config/database.php (db()), includes/functions.php (stmt_* helpers).
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// ── Schema guard ─────────────────────────────────────────────────────────────

/**
 * Whether a table exists in the current database.
 */
function soa_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    return stmt_fetch_assoc($stmt) !== null;
}

/**
 * Whether the Phase 2 core tables are installed.
 */
function soa_schema_ready(mysqli $db): bool
{
    return soa_table_exists($db, 'student_assessment')
        && soa_table_exists($db, 'soa_master')
        && soa_table_exists($db, 'payment_schedule')
        && soa_table_exists($db, 'payment_transaction');
}

// ── Settings ─────────────────────────────────────────────────────────────────

/**
 * Read a system_setting value with a fallback. Statically cached per request.
 */
function soa_setting(mysqli $db, string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $res = $db->query('SELECT setting_key, setting_value FROM system_setting');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cache[(string) $row['setting_key']] = (string) $row['setting_value'];
                }
            }
        } catch (Throwable) {
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/**
 * Active school year as ['id' => int, 'label' => string, 'start_year' => int].
 */
function soa_active_school_year(mysqli $db): array
{
    $label = '';
    $id    = 0;
    try {
        $stmt = $db->prepare(
            'SELECT School_year_id, School_year FROM schoolyear WHERE Status = 1 ORDER BY School_year_id DESC LIMIT 1'
        );
        $stmt->execute();
        $row = stmt_fetch_assoc($stmt);
        if ($row) {
            $id    = (int) $row['School_year_id'];
            $label = (string) $row['School_year'];
        }
    } catch (Throwable) {
        // fall through to defaults
    }
    if ($label === '') {
        $label = date('Y') . '-' . (date('Y') + 1);
    }
    $parts     = explode('-', $label);
    $startYear = isset($parts[0]) ? (int) trim($parts[0]) : (int) date('Y');
    return ['id' => $id, 'label' => $label, 'start_year' => $startYear ?: (int) date('Y')];
}

// ── Gap-free document numbers ────────────────────────────────────────────────

/**
 * Atomically increment and return the next sequence for a series+year.
 * Safe under concurrency (single-statement UPSERT with LAST_INSERT_ID).
 * Call inside the surrounding transaction so the number is consumed only on commit.
 */
function soa_next_sequence(mysqli $db, string $seriesCode, int $year): int
{
    $stmt = $db->prepare(
        'INSERT INTO document_series (series_code, year, last_seq)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE last_seq = last_seq + 1'
    );
    $stmt->bind_param('si', $seriesCode, $year);
    $stmt->execute();
    // Read the value back rather than trusting insert_id: document_series has its
    // own AUTO_INCREMENT `id`, which hijacks LAST_INSERT_ID on the FIRST insert of
    // a new series (returning the row id, not last_seq). The caller owns the
    // surrounding transaction, so this returns this transaction's sequence.
    $sel = $db->prepare('SELECT last_seq FROM document_series WHERE series_code = ? AND year = ? LIMIT 1');
    $sel->bind_param('si', $seriesCode, $year);
    $sel->execute();
    return (int) (stmt_fetch_assoc($sel)['last_seq'] ?? 1);
}

/**
 * Formatted document number, e.g. "SOA-2026-000142".
 */
function soa_next_document_number(mysqli $db, string $seriesCode, string $prefix, int $year): string
{
    $seq = soa_next_sequence($db, $seriesCode, $year);
    return sprintf('%s-%d-%06d', $prefix, $year, $seq);
}

// ── Fee derivation ───────────────────────────────────────────────────────────

/**
 * Fetch the fee inputs for one enrollment (classification-driven, matching the
 * existing cashier logic in cashier/index.php and account_setup.php).
 * Returns null if the enrollment does not exist.
 */
function soa_fetch_enrollment_fees(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare(
        "SELECT en.id, en.student_id, en.school_year, en.Department,
                en.Department_gradelevel, en.Department_section, en.Student_classification,
                COALESCE(NULLIF(en.student_type, ''), IF(p.id IS NOT NULL, 'New', 'Old')) AS student_type,
                en.waive_school_improvement                                 AS waive_improvement,
                en.waive_miscellaneous                                      AS waive_misc,
                COALESCE(p.surname, osp.surname)                            AS surname,
                COALESCE(p.firstname, osp.firstname)                        AS firstname,
                COALESCE(p.middlename, osp.middlename)                      AS middlename,
                COALESCE(p.lrn, osp.lrn)                                    AS lrn,
                IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
                IFNULL(sc.Section_name, en.Department_section)             AS section_name,
                pb.classification_id, pb.classification,
                pb.description                                             AS classification_desc,
                IFNULL(pb.rate, 0)                                         AS rate,
                IFNULL(pb.tuition, 0)                                      AS tuition,
                IFNULL(pb.School_improvement, 0)                          AS school_improvement,
                IFNULL(pb.Enrollment, 0)                                  AS admission,
                IFNULL(pb.Cash, 0)                                        AS cash_full,
                IFNULL(pb.Installment, 0)                                 AS monthly,
                IFNULL(pb.activity_fee, 0)                                AS activity_fee,
                IFNULL(pb.house_registration, 0)                          AS house_reg,
                IFNULL(bk.Book_Cost, 0)                                   AS book_cost
         FROM enrollment en
         LEFT JOIN preregistration p   ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         LEFT JOIN payment_breakdown pb
                ON pb.classification_id = en.Student_classification
               AND pb.type = COALESCE(NULLIF(en.student_type, ''), IF(p.id IS NOT NULL, 'New', 'Old'))
               AND pb.status = 'Active'
         LEFT JOIN payment_book bk
                ON bk.Gradelevel = CAST(en.Department_gradelevel AS CHAR)
               AND en.Department = 'Elementary'
         LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
         LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
         WHERE en.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    return stmt_fetch_assoc($stmt);
}

/**
 * Build N installment terms for a school year.
 * The last term absorbs the rounding remainder so the sum equals $base exactly.
 *
 * @return array<int,array{term_no:int,month_label:string,due_date:string,amount_due:float}>
 */
function soa_build_schedule(string $schoolYearLabel, int $count, int $startMonth, float $base): array
{
    $count      = max(1, $count);
    $parts      = explode('-', $schoolYearLabel);
    $year       = isset($parts[0]) ? (int) trim($parts[0]) : (int) date('Y');
    $mo         = max(1, min(12, $startMonth));
    $per        = floor(($base / $count) * 100) / 100; // 2-dp floor
    $allocated  = 0.0;
    $rows       = [];

    for ($i = 1; $i <= $count; $i++) {
        $ts     = mktime(0, 0, 0, $mo, 1, $year);
        $amount = ($i === $count) ? round($base - $allocated, 2) : $per;
        $allocated += $amount;
        $rows[] = [
            'term_no'     => $i,
            'month_label' => date('F Y', $ts),
            'due_date'    => date('Y-m-t', $ts), // month-end
            'amount_due'  => (float) $amount,
        ];
        $mo++;
        if ($mo > 12) { $mo = 1; $year++; }
    }
    return $rows;
}

/**
 * Resolve the per-month component breakdown of an installment (for SOA
 * transparency): what the monthly amount is made of — Tuition, Miscellaneous,
 * School Improvement, Books. Primary source is the `fee_schedule` reference
 * table (matched by department + student type + monthly total); falls back to
 * the payment_breakdown annual figures when no schedule row matches.
 * Statically cached per request (batches reuse the same few fee profiles).
 *
 * @return array<string,float> ordered label => monthly amount
 */
function soa_monthly_components(
    mysqli $db,
    string $department,
    string $studentType,
    float $monthlyAmount,
    float $pbTuition,
    float $pbImprovement,
    int $count
): array {
    static $cache = [];
    $key = $department . '|' . $studentType . '|' . number_format($monthlyAmount, 2, '.', '');
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $comp = null;
    if ($monthlyAmount > 0 && soa_table_exists($db, 'fee_schedule')) {
        // Match the closest monthly fee tier for this department + student type.
        // Within a department the tiers are ≥ ₱25 apart, so the nearest row is
        // unambiguous; reject only if nothing is within ₱12.50 (profile not in
        // the schedule, e.g. scholarships) so we can fall back cleanly.
        $stmt = $db->prepare(
            "SELECT tuition_monthly, misc_monthly, improvement_monthly, books_monthly,
                    ABS(total_monthly - ?) AS diff
             FROM fee_schedule
             WHERE department = ? AND student_type = ? AND status = 'Active'
             ORDER BY diff ASC LIMIT 1"
        );
        $stmt->bind_param('dss', $monthlyAmount, $department, $studentType);
        $stmt->execute();
        $row = stmt_fetch_assoc($stmt);
        if ($row && (float) $row['diff'] < 12.5) {
            $comp = [
                'Tuition Fee'        => (float) $row['tuition_monthly'],
                'Miscellaneous Fee'  => (float) $row['misc_monthly'],
                'School Improvement' => (float) $row['improvement_monthly'],
            ];
            if ((float) $row['books_monthly'] > 0) {
                $comp['Books / Materials'] = (float) $row['books_monthly'];
            }
        }
    }

    if ($comp === null) {
        // Fallback: derive from payment_breakdown annual figures.
        $count     = max(1, $count);
        $tuitionM  = round($pbTuition / $count, 2);
        $imprM     = round($pbImprovement / $count, 2);
        $remainder = round($monthlyAmount - $tuitionM - $imprM, 2);
        $comp = [
            'Tuition Fee'        => max(0.0, $tuitionM),
            'School Improvement' => max(0.0, $imprM),
            'Miscellaneous & Other' => max(0.0, $remainder),
        ];
    }

    // Drop all-zero components for cleanliness, but keep at least one line.
    $nonZero = array_filter($comp, static fn($v) => $v > 0);
    if ($nonZero !== []) {
        $comp = $nonZero;
    }

    return $cache[$key] = $comp;
}

/**
 * Resolve a student's GRADE-TIER base fee profile from the official `fee_schedule`
 * (Kinder / G1-3 / G4-6 / JHS Reg|ESC / SHS Reg|Voucher). payment_breakdown is
 * level-blind (one "REGULAR" classification spans Kinder…G6), so the fee_schedule
 * is the authoritative base for EVERY student. ESC/Voucher students get their
 * special tier; everyone else (Regular AND scholarship/sibling-discount students)
 * gets the Regular tier for their grade — scholarship discounts are then applied
 * on top of this base (tuition only). Returns null only when no grade tier matches.
 */
function soa_resolve_tier_fees(
    mysqli $db,
    string $department,
    string $gradeName,
    string $classificationName,
    string $studentType
): ?array {
    if (!soa_table_exists($db, 'fee_schedule')) {
        return null;
    }
    $deptMap = ['Junior High' => 'Junior High', 'Senior High' => 'Senior High', 'Elementary' => 'Elementary'];
    $dept    = $deptMap[$department] ?? $department;

    $stmt = $db->prepare(
        "SELECT * FROM fee_schedule WHERE department = ? AND student_type = ? AND status = 'Active'"
    );
    $stmt->bind_param('ss', $dept, $studentType);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    if ($rows === []) {
        return null;
    }

    // Decide which level string to match
    $needle = '';
    $g      = strtoupper($gradeName);
    if (stripos($department, 'element') !== false) {
        if (preg_match('/KINDER|TAHDER/i', $g)) {
            $needle = 'Kinder';
        } else {
            preg_match('/(\d+)/', $g, $m);
            $n = (int) ($m[1] ?? 0);
            $needle = ($n >= 4) ? 'Grade 4' : 'Grade 1';
        }
    } elseif (stripos($department, 'junior') !== false) {
        $needle = stripos($classificationName, 'ESC') !== false ? 'ESC' : 'Regular';
    } elseif (stripos($department, 'senior') !== false) {
        $needle = stripos($classificationName, 'VOUCHER') !== false ? 'Voucher' : 'Regular';
    }
    if ($needle === '') {
        return null;
    }
    foreach ($rows as $row) {
        if (stripos((string) $row['level'], $needle) !== false) {
            return $row;
        }
    }
    return null;
}

/**
 * Whether a classification is a "standard" tier (Regular/ESC/Voucher) — i.e. no
 * extra discount on top of the grade-tier fee. Everything else is a scholarship
 * or sibling discount whose tuition is reduced by payment_breakdown.rate.
 */
function soa_is_standard_classification(string $classificationName): bool
{
    return (bool) preg_match('/REGULAR|ESC|VOUCHER/i', $classificationName);
}

/**
 * The discount rate applied to the TUITION portion only. Standard classifications
 * get 0; scholarship/sibling classifications get payment_breakdown.rate (0–1).
 */
function soa_tuition_discount(string $classificationName, float $pbRate): float
{
    if (soa_is_standard_classification($classificationName)) {
        return 0.0;
    }
    return max(0.0, min(1.0, $pbRate));
}

/**
 * Whether this is a 2nd-or-later child in a multi-child family (2ND/3RD/4TH CHILD).
 * School Improvement is a PER-FAMILY fee — only one child (the 1st/anchor child)
 * pays it — so these siblings have their School Improvement fee waived.
 */
function soa_is_secondary_sibling(string $classificationName): bool
{
    return (bool) preg_match('/\b(2ND|3RD|4TH|4RTH|5TH|6TH)\s*CHILD\b/i', $classificationName);
}

/**
 * Per-month fee components (Tuition / Misc / Improvement / Books) for a student,
 * derived from the grade-tier fee_schedule with the scholarship tuition discount
 * applied. This is the single source of truth used by BOTH assessment creation
 * and the SOA slip, so they always agree.
 *
 * @return array<string,float> ordered label => monthly amount (zero lines dropped)
 */
function soa_components_for(
    mysqli $db,
    string $department,
    string $gradeName,
    string $classificationName,
    string $studentType,
    float $pbRate,
    ?bool $waiveImprovement = null,
    ?bool $waiveMisc = null
): array {
    $tier = soa_resolve_tier_fees($db, $department, $gradeName, $classificationName, $studentType);
    if (!$tier) {
        return [];
    }
    $disc = soa_tuition_discount($classificationName, $pbRate);
    // School Improvement is per-family: only one child per family pays it.
    // Waived when the classification marks a secondary sibling (2nd/3rd/4th child)
    // OR when explicitly flagged on the enrollment ($waiveImprovement === true).
    $waive = soa_is_secondary_sibling($classificationName) || $waiveImprovement === true;
    $improvement = $waive ? 0.0 : (float) $tier['improvement_monthly'];
    // Miscellaneous Fee can be explicitly exempted per student.
    $misc = $waiveMisc === true ? 0.0 : (float) $tier['misc_monthly'];
    $comp = [
        'Tuition Fee'        => round((float) $tier['tuition_monthly'] * (1.0 - $disc), 2),
        'Miscellaneous Fee'  => $misc,
        'School Improvement' => $improvement,
    ];
    if ((float) $tier['books_monthly'] > 0) {
        $comp['Books / Materials'] = (float) $tier['books_monthly'];
    }
    $nz = array_filter($comp, static fn($v) => $v > 0);
    return $nz !== [] ? $nz : ['Tuition Fee' => 0.0];
}

// ── Assessment creation (idempotent per enrollment+SY) ───────────────────────

/**
 * Ensure a student_assessment (+ charges + schedule + opening ledger entry)
 * exists for an enrollment in the active school year. Returns the assessment id,
 * or 0 if the enrollment/fees could not be resolved.
 *
 * The CALLER must own an open transaction.
 */
function soa_ensure_assessment(mysqli $db, int $enrollmentId, int $schoolYearId, string $createdBy): int
{
    // Already assessed?
    $chk = $db->prepare(
        'SELECT id FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1'
    );
    $chk->bind_param('ii', $enrollmentId, $schoolYearId);
    $chk->execute();
    $existing = stmt_fetch_assoc($chk);
    if ($existing) {
        return (int) $existing['id'];
    }

    $fees = soa_fetch_enrollment_fees($db, $enrollmentId);
    if (!$fees) {
        return 0;
    }

    $isElem        = strcasecmp((string) ($fees['Department'] ?? ''), 'Elementary') === 0;
    $studentType   = (string) ($fees['student_type'] ?? 'Old');
    $admission     = (float) $fees['admission'];
    $activity      = (float) $fees['activity_fee'];
    $houseReg      = (float) $fees['house_reg'];
    $bookDown      = $isElem ? (float) $fees['book_cost'] : 0.0;
    $monthly       = (float) $fees['monthly'];

    // Activity fee is level-based, but payment_breakdown is level-blind. Per the
    // official SOA: JHS = ₱490 (includes ₱70 Madrasah/Tarbiyah); Elementary & SHS
    // = ₱420. A zero stays zero (e.g. Pandarat full scholarship).
    if ($activity > 0) {
        $isJHS    = stripos((string) ($fees['Department'] ?? ''), 'junior') !== false;
        $activity = $isJHS ? 490.0 : 420.0;
    }

    // The official grade-tier fee_schedule is the authoritative base for EVERY
    // student (payment_breakdown can't tell Kinder/G1-3/G4-6 apart). Scholarship
    // & sibling-discount students get their grade's Regular tier with the tuition
    // discounted by payment_breakdown.rate; enrollment-day fees stay full.
    $classification = (string) ($fees['classification'] ?? '');
    $tier = soa_resolve_tier_fees(
        $db,
        (string) ($fees['Department'] ?? ''),
        (string) ($fees['gradelevel_name'] ?? ''),
        $classification,
        $studentType
    );
    if ($tier) {
        $admission = (float) $tier['enrollment_admission'];
        $activity  = (float) $tier['activity_fee'];
        $houseReg  = (float) $tier['house_registration'];
        $bookDown  = (float) $tier['enrollment_books'];
        // Monthly = grade-tier components with tuition discount + sibling school-
        // improvement waiver applied (single source of truth, shared with the slip).
        $comp      = soa_components_for(
            $db, (string) ($fees['Department'] ?? ''), (string) ($fees['gradelevel_name'] ?? ''),
            $classification, $studentType, (float) ($fees['rate'] ?? 0),
            (bool) ($fees['waive_improvement'] ?? false),
            (bool) ($fees['waive_misc'] ?? false)
        );
        $monthly   = round(array_sum($comp), 2);
    }

    $count         = max(1, (int) soa_setting($db, 'INSTALLMENT_COUNT', '10'));
    $startMonth    = max(1, min(12, (int) soa_setting($db, 'INSTALLMENT_START_MONTH', '8')));

    $enrollFees    = round($admission + $activity + $houseReg + $bookDown, 2);
    $instBase      = round($monthly * $count, 2);
    $totalAssessed = round($enrollFees + $instBase, 2);
    $discount      = 0.0;
    $netAssessed   = round($totalAssessed - $discount, 2);

    $syLabel       = (string) ($fees['school_year'] ?? '');
    $studentId     = (string) ($fees['student_id'] ?? '');
    $classId       = (int) ($fees['Student_classification'] ?? 0);

    // INSERT assessment
    $ins = $db->prepare(
        'INSERT INTO student_assessment
            (enrollment_id, school_year_id, student_id, classification_id, student_type,
             total_assessed, total_discount, net_assessed, enrollment_fees_total,
             installment_base, installment_count, total_paid, balance, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, \'Active\', ?)'
    );
    // types: i i s i s | d d d d | d(installment_base) i(installment_count) d(balance) s(created_by)
    $ins->bind_param(
        'iisisdddddids',
        $enrollmentId, $schoolYearId, $studentId, $classId, $studentType,
        $totalAssessed, $discount, $netAssessed, $enrollFees,
        $instBase, $count, $netAssessed, $createdBy
    );
    $ins->execute();
    $assessmentId = (int) $db->insert_id;

    // Charge lines (enrollment-day fees + installment base)
    $charges = [];
    if ($admission > 0) $charges[] = ['ADMISSION',   'Admission / Registration Fee', $admission, 0];
    if ($activity  > 0) $charges[] = ['ACTIVITY',    'Activity Fees',                $activity,  0];
    if ($houseReg  > 0) $charges[] = ['HOUSE_REG',   'House Registration',           $houseReg,  0];
    if ($bookDown  > 0) $charges[] = ['BOOKS',       'Books (enrollment down-payment)', $bookDown, 0];
    if ($instBase  > 0) $charges[] = ['INSTALLMENT', 'Monthly Tuition & Fees (× ' . $count . ')', $instBase, 1];

    if ($charges) {
        $cStmt = $db->prepare(
            'INSERT INTO assessment_charge
                (assessment_id, fee_item_id, line_type, description, amount, is_installment_base)
             VALUES (?, (SELECT id FROM fee_item WHERE code = ? LIMIT 1), \'charge\', ?, ?, ?)'
        );
        foreach ($charges as [$code, $desc, $amt, $isBase]) {
            $amtF = (float) $amt;
            $cStmt->bind_param('issdi', $assessmentId, $code, $desc, $amtF, $isBase);
            $cStmt->execute();
        }
    }

    // Installment schedule
    if ($instBase > 0) {
        $rows  = soa_build_schedule($syLabel, $count, $startMonth, $instBase);
        $sStmt = $db->prepare(
            'INSERT INTO payment_schedule
                (assessment_id, term_no, month_label, due_date, amount_due, amount_paid, balance, status)
             VALUES (?, ?, ?, ?, ?, 0.00, ?, \'Unpaid\')'
        );
        foreach ($rows as $r) {
            $termNo = (int) $r['term_no'];
            $label  = (string) $r['month_label'];
            $due    = (string) $r['due_date'];
            $amt    = (float) $r['amount_due'];
            $sStmt->bind_param('iissdd', $assessmentId, $termNo, $label, $due, $amt, $amt);
            $sStmt->execute();
        }
    }

    // Opening ledger entry (assessment = charge → debit)
    soa_ledger_add(
        $db, $assessmentId, $studentId, $schoolYearId,
        'Assessment', 'student_assessment', $assessmentId,
        'Assessment created', $netAssessed, 0.0, $netAssessed, $createdBy
    );

    return $assessmentId;
}

/**
 * Recompute an existing assessment's fee structure IN PLACE from the
 * enrollment's CURRENT classification/grade — without deleting the assessment
 * or its payments. Charges + schedule are rebuilt; total_paid/balance are
 * re-derived from the surviving Posted payments (e.g. backfilled enrollment
 * payments). This is what makes "reclassify → new fees" work after a student
 * already has an enrollment payment on file.
 *
 * Refuses only when the student has real monthly INSTALLMENT collections
 * (payments allocated to specific schedule terms) — those must be voided
 * first, since rebuilding the schedule would orphan their allocations.
 *
 * CALLER owns the transaction.
 *
 * @return int the same assessment id
 */
function soa_reassess(mysqli $db, int $assessmentId, string $updatedBy): int
{
    $aStmt = $db->prepare('SELECT enrollment_id, school_year_id, student_id FROM student_assessment WHERE id = ? LIMIT 1');
    $aStmt->bind_param('i', $assessmentId);
    $aStmt->execute();
    $row = stmt_fetch_assoc($aStmt);
    if (!$row) {
        throw new RuntimeException('Assessment not found.');
    }
    $enrollmentId = (int) $row['enrollment_id'];
    $schoolYearId = (int) $row['school_year_id'];

    // Block if any Posted payment is allocated to a schedule term (real monthly collection).
    $allocRs = $db->query(
        'SELECT COUNT(*) c FROM payment_installments pi
         JOIN payment_transaction pt ON pt.id = pi.payment_id
         WHERE pt.assessment_id = ' . $assessmentId . " AND pt.status = 'Posted'"
    );
    if ((int) ($allocRs->fetch_assoc()['c'] ?? 0) > 0) {
        throw new RuntimeException('This student has monthly installment payments — void those first before reassessing.');
    }

    $fees = soa_fetch_enrollment_fees($db, $enrollmentId);
    if (!$fees) {
        throw new RuntimeException('Could not resolve fees for this enrollment.');
    }

    // ---- identical fee computation to soa_ensure_assessment ----
    $isElem      = strcasecmp((string) ($fees['Department'] ?? ''), 'Elementary') === 0;
    $studentType = (string) ($fees['student_type'] ?? 'Old');
    $admission   = (float) $fees['admission'];
    $activity    = (float) $fees['activity_fee'];
    $houseReg    = (float) $fees['house_reg'];
    $bookDown    = $isElem ? (float) $fees['book_cost'] : 0.0;
    $monthly     = (float) $fees['monthly'];

    if ($activity > 0) {
        $isJHS    = stripos((string) ($fees['Department'] ?? ''), 'junior') !== false;
        $activity = $isJHS ? 490.0 : 420.0;
    }

    $classification = (string) ($fees['classification'] ?? '');
    $tier = soa_resolve_tier_fees(
        $db, (string) ($fees['Department'] ?? ''), (string) ($fees['gradelevel_name'] ?? ''),
        $classification, $studentType
    );
    if ($tier) {
        $admission = (float) $tier['enrollment_admission'];
        $activity  = (float) $tier['activity_fee'];
        $houseReg  = (float) $tier['house_registration'];
        $bookDown  = (float) $tier['enrollment_books'];
        $comp      = soa_components_for(
            $db, (string) ($fees['Department'] ?? ''), (string) ($fees['gradelevel_name'] ?? ''),
            $classification, $studentType, (float) ($fees['rate'] ?? 0),
            (bool) ($fees['waive_improvement'] ?? false),
            (bool) ($fees['waive_misc'] ?? false)
        );
        $monthly   = round(array_sum($comp), 2);
    }

    $count      = max(1, (int) soa_setting($db, 'INSTALLMENT_COUNT', '10'));
    $startMonth = max(1, min(12, (int) soa_setting($db, 'INSTALLMENT_START_MONTH', '8')));

    $enrollFees    = round($admission + $activity + $houseReg + $bookDown, 2);
    $instBase      = round($monthly * $count, 2);
    $totalAssessed = round($enrollFees + $instBase, 2);
    $discount      = 0.0;
    $netAssessed   = round($totalAssessed - $discount, 2);
    $syLabel       = (string) ($fees['school_year'] ?? '');
    $classId       = (int) ($fees['Student_classification'] ?? 0);

    // Surviving payments → new totals.
    $paid = (float) ($db->query(
        'SELECT IFNULL(SUM(amount),0) s FROM payment_transaction WHERE assessment_id = ' . $assessmentId . " AND status = 'Posted'"
    )->fetch_assoc()['s'] ?? 0);
    $totalPaid  = round($paid, 2);
    $newBalance = round($netAssessed - $totalPaid, 2);
    $status     = $newBalance <= 0 ? 'Settled' : 'Active';

    // Rebuild charges + schedule. Any previously generated SOA documents are now
    // stale (their soa_details FK-cascade off the old payment_schedule rows we are
    // about to delete, which would leave an empty, zero-breakdown slip). Drop them
    // so they are regenerated fresh against the new schedule.
    $db->query('DELETE FROM soa_master WHERE assessment_id = ' . $assessmentId);
    $db->query('DELETE FROM assessment_charge WHERE assessment_id = ' . $assessmentId);
    $db->query('DELETE FROM payment_schedule WHERE assessment_id = ' . $assessmentId);

    $upd = $db->prepare(
        'UPDATE student_assessment
            SET classification_id = ?, student_type = ?, total_assessed = ?, total_discount = ?,
                net_assessed = ?, enrollment_fees_total = ?, installment_base = ?, installment_count = ?,
                total_paid = ?, balance = ?, status = ?, updated_at = NOW()
          WHERE id = ?'
    );
    $upd->bind_param(
        'isdddddiddsi',
        $classId, $studentType, $totalAssessed, $discount,
        $netAssessed, $enrollFees, $instBase, $count,
        $totalPaid, $newBalance, $status, $assessmentId
    );
    $upd->execute();

    $charges = [];
    if ($admission > 0) $charges[] = ['ADMISSION',   'Admission / Registration Fee', $admission, 0];
    if ($activity  > 0) $charges[] = ['ACTIVITY',    'Activity Fees',                $activity,  0];
    if ($houseReg  > 0) $charges[] = ['HOUSE_REG',   'House Registration',           $houseReg,  0];
    if ($bookDown  > 0) $charges[] = ['BOOKS',       'Books (enrollment down-payment)', $bookDown, 0];
    if ($instBase  > 0) $charges[] = ['INSTALLMENT', 'Monthly Tuition & Fees (× ' . $count . ')', $instBase, 1];
    if ($charges) {
        $cStmt = $db->prepare(
            'INSERT INTO assessment_charge
                (assessment_id, fee_item_id, line_type, description, amount, is_installment_base)
             VALUES (?, (SELECT id FROM fee_item WHERE code = ? LIMIT 1), \'charge\', ?, ?, ?)'
        );
        foreach ($charges as [$code, $desc, $amt, $isBase]) {
            $amtF = (float) $amt;
            $cStmt->bind_param('issdi', $assessmentId, $code, $desc, $amtF, $isBase);
            $cStmt->execute();
        }
    }

    if ($instBase > 0) {
        $rows  = soa_build_schedule($syLabel, $count, $startMonth, $instBase);
        $sStmt = $db->prepare(
            'INSERT INTO payment_schedule
                (assessment_id, term_no, month_label, due_date, amount_due, amount_paid, balance, status)
             VALUES (?, ?, ?, ?, ?, 0.00, ?, \'Unpaid\')'
        );
        foreach ($rows as $r) {
            $termNo = (int) $r['term_no'];
            $label  = (string) $r['month_label'];
            $due    = (string) $r['due_date'];
            $amt    = (float) $r['amount_due'];
            $sStmt->bind_param('iissdd', $assessmentId, $termNo, $label, $due, $amt, $amt);
            $sStmt->execute();
        }
    }

    soa_ledger_add(
        $db, $assessmentId, (string) $row['student_id'], $schoolYearId,
        'Assessment', 'student_assessment', $assessmentId,
        'Reassessed (' . $classification . ')', 0.0, 0.0, $newBalance, $updatedBy
    );

    return $assessmentId;
}

/**
 * Append one immutable ledger row.
 */
function soa_ledger_add(
    mysqli $db, int $assessmentId, string $studentId, int $schoolYearId,
    string $entryType, ?string $refTable, ?int $refId, string $description,
    float $debit, float $credit, float $runningBalance, ?string $postedBy
): void {
    $stmt = $db->prepare(
        'INSERT INTO student_ledger
            (assessment_id, student_id, school_year_id, entry_type, ref_table, ref_id,
             description, debit, credit, running_balance, posted_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'isissisddds',
        $assessmentId, $studentId, $schoolYearId, $entryType, $refTable, $refId,
        $description, $debit, $credit, $runningBalance, $postedBy
    );
    $stmt->execute();
}

/**
 * Current outstanding balance per schedule term (amount_due - amount_paid),
 * keyed by term_no, plus the schedule row id.
 *
 * @return array<int,array{schedule_id:int,month_label:string,due_date:string,amount_due:float,amount_paid:float,balance:float,status:string}>
 */
function soa_get_schedule(mysqli $db, int $assessmentId): array
{
    $stmt = $db->prepare(
        'SELECT id, term_no, month_label, due_date, amount_due, amount_paid, balance, status
         FROM payment_schedule WHERE assessment_id = ? ORDER BY term_no'
    );
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $out = [];
    foreach (stmt_fetch_all_assoc($stmt) as $r) {
        $out[(int) $r['term_no']] = [
            'schedule_id' => (int) $r['id'],
            'month_label' => (string) $r['month_label'],
            'due_date'    => (string) $r['due_date'],
            'amount_due'  => (float) $r['amount_due'],
            'amount_paid' => (float) $r['amount_paid'],
            'balance'     => (float) $r['balance'],
            'status'      => (string) $r['status'],
        ];
    }
    return $out;
}

/**
 * Create a soa_master (+ soa_details snapshot) for an assessment over the
 * selected term numbers. Returns the new soa id. CALLER owns the transaction.
 *
 * @param int[] $selectedTerms
 */
/**
 * Term numbers (months) already covered by a generated SOA document for this
 * assessment — so the cashier can't re-generate the same month twice.
 * @return int[]
 */
function soa_generated_terms(mysqli $db, int $assessmentId): array
{
    $terms = [];
    $r = $db->query(
        'SELECT DISTINCT sd.term_no
         FROM soa_details sd JOIN soa_master sm ON sm.id = sd.soa_id
         WHERE sm.assessment_id = ' . (int) $assessmentId
    );
    if ($r) { while ($x = $r->fetch_assoc()) { $terms[] = (int) $x['term_no']; } }
    return $terms;
}

function soa_create_document(
    mysqli $db,
    int $assessmentId,
    string $studentId,
    int $schoolYearId,
    int $year,
    string $scope,
    string $scopeRef,
    array $selectedTerms,
    string $generatedBy,
    ?string $batchId
): int {
    $schedule = soa_get_schedule($db, $assessmentId);

    // Default to all terms with an outstanding balance when none chosen.
    $selectedTerms = array_values(array_unique(array_map('intval', $selectedTerms)));
    if ($selectedTerms === []) {
        foreach ($schedule as $termNo => $row) {
            if ($row['balance'] > 0) {
                $selectedTerms[] = $termNo;
            }
        }
    }
    // Guard: never create a blank SOA. Keep only months that exist in this
    // student's payment schedule — enrollment-fee-only students have none, so
    // there is nothing to bill and the caller should skip them.
    $selectedTerms = array_values(array_filter($selectedTerms, static fn($t) => isset($schedule[$t])));
    if ($selectedTerms === []) {
        return 0;
    }
    sort($selectedTerms);

    $totalDue = 0.0;
    foreach ($selectedTerms as $t) {
        if (isset($schedule[$t])) {
            $totalDue += max(0.0, $schedule[$t]['balance']);
        }
    }
    $totalDue = round($totalDue, 2);

    $prefix    = soa_setting($db, 'SOA_NUMBER_PREFIX', 'SOA');
    $series    = soa_setting($db, 'SOA_SERIES_CODE', 'SOA');
    $soaNumber = soa_next_document_number($db, $series, $prefix, $year);
    $termsJson = json_encode($selectedTerms);

    $ins = $db->prepare(
        'INSERT INTO soa_master
            (assessment_id, soa_number, scope, scope_ref, selected_terms_json,
             total_due, barcode_ref, qr_ref, batch_id, generated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $qrRef = $soaNumber;
    $ins->bind_param(
        'isssssssss',
        $assessmentId, $soaNumber, $scope, $scopeRef, $termsJson,
        $totalDue, $soaNumber, $qrRef, $batchId, $generatedBy
    );
    $ins->execute();
    $soaId = (int) $db->insert_id;

    // Snapshot selected terms
    if ($selectedTerms) {
        $dStmt = $db->prepare(
            'INSERT INTO soa_details
                (soa_id, schedule_id, term_no, month_label, amount_due, amount_paid_snapshot, amount_selected)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($selectedTerms as $t) {
            if (!isset($schedule[$t])) {
                continue;
            }
            $row      = $schedule[$t];
            $schedId  = (int) $row['schedule_id'];
            $label    = (string) $row['month_label'];
            $due      = (float) $row['amount_due'];
            $paid     = (float) $row['amount_paid'];
            $selected = max(0.0, (float) $row['balance']);
            $dStmt->bind_param('iiisddd', $soaId, $schedId, $t, $label, $due, $paid, $selected);
            $dStmt->execute();
        }
    }

    // Ledger note (no money movement)
    soa_ledger_add(
        $db, $assessmentId, $studentId, $schoolYearId,
        'SOA', 'soa_master', $soaId,
        'SOA generated ' . $soaNumber, 0.0, 0.0, 0.0, $generatedBy
    );

    return $soaId;
}

// ── Collection / payment posting (Milestone 3) ───────────────────────────────

/**
 * Lazily flag overdue installments. Safe to call before reads.
 */
function soa_mark_overdue(mysqli $db): void
{
    try {
        $db->query(
            "UPDATE payment_schedule
                SET status = 'Overdue'
              WHERE status IN ('Unpaid', 'Partial')
                AND balance > 0
                AND due_date < CURDATE()"
        );
    } catch (Throwable) {
        // non-fatal
    }
}

/**
 * Resolve a scanned/typed reference to an assessment for collection.
 * Tries: SOA number → OR number → student (id/lrn/name) search.
 * For a student match without an assessment yet, one is created lazily.
 *
 * @return array{assessment_id:int,soa_id:?int,preselect:int[],source:string}|null
 */
function soa_resolve_collection_ref(
    mysqli $db,
    string $ref,
    int $schoolYearId,
    string $syLabel,
    string $actor
): ?array {
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }

    // 1) SOA number
    $stmt = $db->prepare(
        'SELECT id, assessment_id, selected_terms_json FROM soa_master WHERE soa_number = ? LIMIT 1'
    );
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    if ($row = stmt_fetch_assoc($stmt)) {
        $terms = json_decode((string) ($row['selected_terms_json'] ?? '[]'), true) ?: [];
        return [
            'assessment_id' => (int) $row['assessment_id'],
            'soa_id'        => (int) $row['id'],
            'preselect'     => array_map('intval', $terms),
            'source'        => 'SOA ' . $ref,
        ];
    }

    // 2) OR number → assessment of that payment
    $stmt = $db->prepare(
        'SELECT pt.assessment_id
         FROM receipt_master rm JOIN payment_transaction pt ON pt.id = rm.payment_id
         WHERE rm.or_number = ? LIMIT 1'
    );
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    if ($row = stmt_fetch_assoc($stmt)) {
        return [
            'assessment_id' => (int) $row['assessment_id'],
            'soa_id'        => null,
            'preselect'     => [],
            'source'        => 'OR ' . $ref,
        ];
    }

    // 3) Student search → enrollment in active SY → ensure assessment
    $like = '%' . $ref . '%';
    $stmt = $db->prepare(
        "SELECT en.id
         FROM enrollment en
         LEFT JOIN preregistration p     ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         WHERE en.school_year = ?
           AND (en.student_id = ? OR p.lrn LIKE ? OR osp.lrn LIKE ?
                OR p.surname LIKE ? OR p.firstname LIKE ?
                OR osp.surname LIKE ? OR osp.firstname LIKE ?)
         ORDER BY en.id DESC LIMIT 1"
    );
    $stmt->bind_param('ssssssss', $syLabel, $ref, $like, $like, $like, $like, $like, $like);
    $stmt->execute();
    $en = stmt_fetch_assoc($stmt);
    if (!$en) {
        return null;
    }

    $db->begin_transaction();
    try {
        $assessmentId = soa_ensure_assessment($db, (int) $en['id'], $schoolYearId, $actor);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return null;
    }
    if ($assessmentId <= 0) {
        return null;
    }
    return [
        'assessment_id' => $assessmentId,
        'soa_id'        => null,
        'preselect'     => [],
        'source'        => 'Student ' . $ref,
    ];
}

/**
 * Post a payment against an assessment, allocating to the selected installment
 * terms (oldest-first), generating an OR, and writing the ledger — ALL in ONE
 * transaction with row locks. Self-contained: manages its own transaction.
 *
 * @param int[] $selectedScheduleIds payment_schedule.id values chosen by the cashier
 * @param array $user                current_user() array (id, full_name)
 * @return array{payment_id:int,or_number:string,allocated:float,advance:float,duplicate:bool}
 * @throws RuntimeException on validation failure
 */
function soa_post_payment(
    mysqli $db,
    int $assessmentId,
    ?int $soaId,
    string $method,
    ?string $referenceNo,
    float $tendered,
    array $selectedScheduleIds,
    array $user,
    string $idempotencyKey
): array {
    $tendered = round($tendered, 2);
    if ($tendered <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }
    $allowed = ['Cash', 'GCash', 'Maya', 'Bank', 'Voucher'];
    if (!in_array($method, $allowed, true)) {
        $method = 'Cash';
    }
    $cashierId   = (int) ($user['id'] ?? 0);
    $cashierName = (string) ($user['full_name'] ?? 'Cashier');
    $year        = (int) date('Y');
    $orPrefix    = soa_setting($db, 'OR_NUMBER_PREFIX', 'ITFA-OR');
    $orSeries    = soa_setting($db, 'OR_SERIES_CODE', 'OR');
    $overpay     = soa_setting($db, 'OVERPAY_POLICY', 'cascade_next');

    $selIds = array_values(array_unique(array_filter(array_map('intval', $selectedScheduleIds), static fn($v) => $v > 0)));

    $db->begin_transaction();
    try {
        // Idempotency guard
        $idChk = $db->prepare('SELECT id FROM payment_transaction WHERE idempotency_key = ? LIMIT 1');
        $idChk->bind_param('s', $idempotencyKey);
        $idChk->execute();
        if ($dup = stmt_fetch_assoc($idChk)) {
            $pid     = (int) $dup['id'];
            $orStmt  = $db->prepare('SELECT or_number FROM receipt_master WHERE payment_id = ? LIMIT 1');
            $orStmt->bind_param('i', $pid);
            $orStmt->execute();
            $orRow = stmt_fetch_assoc($orStmt);
            $db->commit();
            return ['payment_id' => $pid, 'or_number' => (string) ($orRow['or_number'] ?? ''), 'allocated' => 0.0, 'advance' => 0.0, 'duplicate' => true];
        }

        // Lock assessment
        $aStmt = $db->prepare(
            'SELECT id, student_id, school_year_id, net_assessed, total_paid
             FROM student_assessment WHERE id = ? FOR UPDATE'
        );
        $aStmt->bind_param('i', $assessmentId);
        $aStmt->execute();
        $assessment = stmt_fetch_assoc($aStmt);
        if (!$assessment) {
            throw new RuntimeException('Assessment not found.');
        }
        $studentId    = (string) $assessment['student_id'];
        $schoolYearId = (int) $assessment['school_year_id'];
        $netAssessed  = (float) $assessment['net_assessed'];
        $prevPaid     = (float) $assessment['total_paid'];

        // Insert payment header
        $changeAmt = 0.00;
        $insP = $db->prepare(
            'INSERT INTO payment_transaction
                (assessment_id, soa_id, method, reference_no, amount, tendered, change_amount,
                 status, received_by, idempotency_key, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'Posted\', ?, ?, NOW())'
        );
        $insP->bind_param(
            'iissddsss',
            $assessmentId, $soaId, $method, $referenceNo, $tendered, $tendered, $changeAmt,
            $cashierName, $idempotencyKey
        );
        $insP->execute();
        $paymentId = (int) $db->insert_id;

        // Allocation helper
        $allocStmt = $db->prepare('INSERT INTO payment_installments (payment_id, schedule_id, amount) VALUES (?, ?, ?)');
        $updSched  = $db->prepare(
            'UPDATE payment_schedule
                SET amount_paid = amount_paid + ?,
                    balance     = GREATEST(0, amount_due - (amount_paid + ?)),
                    status      = IF((amount_paid + ?) >= amount_due, \'Paid\', \'Partial\')
              WHERE id = ?'
        );
        $remaining = $tendered;
        $allocated = 0.0;

        $applyTo = function (array $rows) use (&$remaining, &$allocated, $allocStmt, $updSched, $paymentId): void {
            foreach ($rows as $row) {
                if ($remaining <= 0) {
                    break;
                }
                $balance = (float) $row['balance'];
                if ($balance <= 0) {
                    continue;
                }
                $alloc = min($remaining, $balance);
                $alloc = round($alloc, 2);
                if ($alloc <= 0) {
                    continue;
                }
                $schedId = (int) $row['id'];
                $allocStmt->bind_param('iid', $paymentId, $schedId, $alloc);
                $allocStmt->execute();
                $updSched->bind_param('dddi', $alloc, $alloc, $alloc, $schedId);
                $updSched->execute();
                $remaining -= $alloc;
                $allocated += $alloc;
            }
        };

        // 1) Selected terms first (oldest-first)
        if ($selIds !== []) {
            $inList = implode(',', $selIds);
            $rs = $db->query(
                "SELECT id, amount_due, amount_paid, balance
                 FROM payment_schedule
                 WHERE assessment_id = " . (int) $assessmentId . " AND id IN ($inList)
                 ORDER BY term_no FOR UPDATE"
            );
            $rows = [];
            if ($rs) { while ($r = $rs->fetch_assoc()) { $rows[] = $r; } }
            $applyTo($rows);
        }

        // 2) Overpayment cascade to remaining unpaid terms
        if ($remaining > 0.001 && $overpay === 'cascade_next') {
            $notIn = $selIds !== [] ? ' AND id NOT IN (' . implode(',', $selIds) . ')' : '';
            $rs = $db->query(
                "SELECT id, amount_due, amount_paid, balance
                 FROM payment_schedule
                 WHERE assessment_id = " . (int) $assessmentId . " AND balance > 0" . $notIn . "
                 ORDER BY term_no FOR UPDATE"
            );
            $rows = [];
            if ($rs) { while ($r = $rs->fetch_assoc()) { $rows[] = $r; } }
            $applyTo($rows);
        }

        $advance = round($remaining, 2); // unallocated surplus → advance/credit

        // Update assessment totals
        $newPaid    = round($prevPaid + $tendered, 2);
        $newBalance = round($netAssessed - $newPaid, 2);
        $newStatus  = $newBalance <= 0 ? 'Settled' : 'Active';
        $updA = $db->prepare(
            'UPDATE student_assessment SET total_paid = ?, balance = ?, status = ?, updated_at = NOW() WHERE id = ?'
        );
        $updA->bind_param('ddsi', $newPaid, $newBalance, $newStatus, $assessmentId);
        $updA->execute();

        // OR number (gap-free, inside this transaction)
        $seq      = soa_next_sequence($db, $orSeries, $year);
        $orNumber = sprintf('%s-%d-%06d', $orPrefix, $year, $seq);
        $insR = $db->prepare(
            'INSERT INTO receipt_master (payment_id, or_number, series, sequence, reprint_count, issued_by)
             VALUES (?, ?, ?, ?, 0, ?)'
        );
        $insR->bind_param('issis', $paymentId, $orNumber, $orSeries, $seq, $cashierName);
        $insR->execute();
        $receiptId = (int) $db->insert_id;

        // Receipt detail lines
        $insD = $db->prepare(
            'INSERT INTO receipt_details (receipt_id, fee_item_id, category, description, amount)
             VALUES (?, (SELECT id FROM fee_item WHERE code = ? LIMIT 1), ?, ?, ?)'
        );
        if ($allocated > 0) {
            $code = 'INSTALLMENT'; $cat = 'tuition'; $desc = 'Monthly Tuition & Fees (installment)';
            $insD->bind_param('isssd', $receiptId, $code, $cat, $desc, $allocated);
            $insD->execute();
        }
        if ($advance > 0) {
            $code = 'ADJUSTMENT'; $cat = 'advance'; $desc = 'Advance / Credit Payment';
            $insD->bind_param('isssd', $receiptId, $code, $cat, $desc, $advance);
            $insD->execute();
        }

        // Ledger entries
        soa_ledger_add($db, $assessmentId, $studentId, $schoolYearId, 'Payment', 'payment_transaction', $paymentId,
            'Payment received (' . $method . ')', 0.0, $tendered, $newBalance, $cashierName);
        soa_ledger_add($db, $assessmentId, $studentId, $schoolYearId, 'Receipt', 'receipt_master', $receiptId,
            'OR issued ' . $orNumber, 0.0, 0.0, $newBalance, $cashierName);
        if ($advance > 0) {
            soa_ledger_add($db, $assessmentId, $studentId, $schoolYearId, 'Advance', 'payment_transaction', $paymentId,
                'Advance / credit ₱' . number_format($advance, 2), 0.0, 0.0, $newBalance, $cashierName);
        }

        // Collection summary (per cashier per day)
        $cash   = $method === 'Cash' ? $tendered : 0.0;
        $online = in_array($method, ['GCash', 'Maya', 'Bank'], true) ? $tendered : 0.0;
        $insC = $db->prepare(
            'INSERT INTO collection_summary
                (cashier_id, cashier_name, business_date, school_year_id, txn_count, total_cash, total_online, total_collected, status)
             VALUES (?, ?, CURDATE(), ?, 1, ?, ?, ?, \'Open\')
             ON DUPLICATE KEY UPDATE
                txn_count       = txn_count + 1,
                total_cash      = total_cash + VALUES(total_cash),
                total_online    = total_online + VALUES(total_online),
                total_collected = total_collected + VALUES(total_collected)'
        );
        $insC->bind_param('isiddd', $cashierId, $cashierName, $schoolYearId, $cash, $online, $tendered);
        $insC->execute();

        // Audit
        $after = json_encode([
            'payment_id' => $paymentId, 'or_number' => $orNumber, 'method' => $method,
            'tendered' => $tendered, 'allocated' => $allocated, 'advance' => $advance,
            'new_balance' => $newBalance,
        ]);
        $ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $eid  = (string) $paymentId;
        $act  = 'POST_PAYMENT';
        $ent  = 'payment_transaction';
        $insL = $db->prepare(
            'INSERT INTO financial_audit_logs (actor_id, actor_name, action, entity, entity_id, after_json, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insL->bind_param('issssss', $cashierId, $cashierName, $act, $ent, $eid, $after, $ip);
        $insL->execute();

        $db->commit();
        return ['payment_id' => $paymentId, 'or_number' => $orNumber, 'allocated' => $allocated, 'advance' => $advance, 'duplicate' => false];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Payment failed: ' . $e->getMessage());
    }
}

/* ============================================================================
 * VOID / REFUND  —  maker-checker (Milestone 4)
 *   - A cashier (maker) files a request via soa_request_reversal().
 *   - A Super Admin (checker) approves it via soa_approve_reversal(), which is
 *     what actually unwinds the payment, or rejects it via soa_reject_reversal().
 * ========================================================================== */

/**
 * Maker step: file a Void or Refund request against a posted payment. Does NOT
 * move money — it only records a Pending request for a checker to approve.
 * Throws if the payment is missing, already reversed, or has an open request.
 *
 * @return int new payment_reversals.id
 */
function soa_request_reversal(mysqli $db, int $paymentId, string $type, string $reason, array $user): int
{
    $type   = strcasecmp($type, 'Refund') === 0 ? 'Refund' : 'Void';
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required.');
    }
    $actorId   = (int) ($user['id'] ?? 0);
    $actorName = (string) ($user['full_name'] ?? 'Cashier');

    // Payment must exist and still be live.
    $pStmt = $db->prepare('SELECT id, amount, status FROM payment_transaction WHERE id = ? LIMIT 1');
    $pStmt->bind_param('i', $paymentId);
    $pStmt->execute();
    $pay = stmt_fetch_assoc($pStmt);
    if (!$pay) {
        throw new RuntimeException('Payment not found.');
    }
    if (!in_array((string) $pay['status'], ['Posted'], true)) {
        throw new RuntimeException('This payment is already ' . strtolower((string) $pay['status']) . ' and cannot be reversed.');
    }

    // No duplicate open/approved request for the same payment.
    $dStmt = $db->prepare("SELECT id, status FROM payment_reversals WHERE payment_id = ? AND status IN ('Pending','Approved') LIMIT 1");
    $dStmt->bind_param('i', $paymentId);
    $dStmt->execute();
    if ($open = stmt_fetch_assoc($dStmt)) {
        throw new RuntimeException('A ' . strtolower((string) $open['status']) . ' request already exists for this payment.');
    }

    $amount = (float) $pay['amount'];
    $status = 'Pending';
    $ins = $db->prepare(
        'INSERT INTO payment_reversals (payment_id, type, amount, reason, requested_by, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param('isdsss', $paymentId, $type, $amount, $reason, $actorName, $status);
    $ins->execute();
    $reversalId = (int) $db->insert_id;

    $after = json_encode(['reversal_id' => $reversalId, 'payment_id' => $paymentId, 'type' => $type, 'amount' => $amount, 'reason' => $reason]);
    soa_audit($db, $actorId, $actorName, 'REQUEST_' . strtoupper($type), 'payment_reversals', (string) $reversalId, null, $after);

    return $reversalId;
}

/**
 * Checker step: approve a pending reversal. Self-contained transaction that
 * unwinds the payment — restores schedule + assessment balances, voids the
 * payment, writes a reversing ledger entry, and decrements that day's
 * collection summary. Returns a small result array.
 *
 * @return array{reversal_id:int,payment_id:int,type:string,amount:float,new_balance:float}
 */
function soa_approve_reversal(mysqli $db, int $reversalId, array $approver): array
{
    $approverName = (string) ($approver['full_name'] ?? 'Admin');
    $approverId   = (int) ($approver['id'] ?? 0);

    $db->begin_transaction();
    try {
        // Lock the request.
        $rStmt = $db->prepare('SELECT id, payment_id, type, amount, status FROM payment_reversals WHERE id = ? FOR UPDATE');
        $rStmt->bind_param('i', $reversalId);
        $rStmt->execute();
        $rev = stmt_fetch_assoc($rStmt);
        if (!$rev) {
            throw new RuntimeException('Reversal request not found.');
        }
        if ((string) $rev['status'] !== 'Pending') {
            throw new RuntimeException('This request is already ' . strtolower((string) $rev['status']) . '.');
        }
        $paymentId = (int) $rev['payment_id'];
        $type      = (string) $rev['type'];

        // Lock the payment.
        $pStmt = $db->prepare(
            'SELECT id, assessment_id, method, amount, status, received_by, paid_at
             FROM payment_transaction WHERE id = ? FOR UPDATE'
        );
        $pStmt->bind_param('i', $paymentId);
        $pStmt->execute();
        $pay = stmt_fetch_assoc($pStmt);
        if (!$pay) {
            throw new RuntimeException('Payment not found.');
        }
        if ((string) $pay['status'] !== 'Posted') {
            throw new RuntimeException('Payment is already ' . strtolower((string) $pay['status']) . '.');
        }
        $assessmentId = (int) $pay['assessment_id'];
        $amount       = (float) $pay['amount'];
        $method       = (string) $pay['method'];
        $cashierName  = (string) $pay['received_by'];
        $bizDate      = substr((string) $pay['paid_at'], 0, 10);

        // Restore each schedule term this payment had been allocated to, then
        // drop the allocation rows (schedule balances are the source of truth).
        $allocRs = $db->query('SELECT schedule_id, amount FROM payment_installments WHERE payment_id = ' . $paymentId);
        $restoreSched = $db->prepare(
            'UPDATE payment_schedule
                SET amount_paid = GREATEST(0, amount_paid - ?),
                    balance     = LEAST(amount_due, balance + ?),
                    status      = IF((amount_paid - ?) <= 0, \'Unpaid\', \'Partial\')
              WHERE id = ?'
        );
        if ($allocRs) {
            while ($a = $allocRs->fetch_assoc()) {
                $schedId = (int) $a['schedule_id'];
                $amt     = (float) $a['amount'];
                $restoreSched->bind_param('dddi', $amt, $amt, $amt, $schedId);
                $restoreSched->execute();
            }
        }
        $db->query('DELETE FROM payment_installments WHERE payment_id = ' . $paymentId);

        // Restore assessment totals.
        $aStmt = $db->prepare('SELECT student_id, school_year_id, net_assessed, total_paid FROM student_assessment WHERE id = ? FOR UPDATE');
        $aStmt->bind_param('i', $assessmentId);
        $aStmt->execute();
        $asmt = stmt_fetch_assoc($aStmt);
        $studentId    = (string) ($asmt['student_id'] ?? '');
        $schoolYearId = (int) ($asmt['school_year_id'] ?? 0);
        $netAssessed  = (float) ($asmt['net_assessed'] ?? 0);
        $newPaid      = round(max(0.0, (float) ($asmt['total_paid'] ?? 0) - $amount), 2);
        $newBalance   = round($netAssessed - $newPaid, 2);
        $newStatus    = $newBalance <= 0 ? 'Settled' : 'Active';
        $updA = $db->prepare('UPDATE student_assessment SET total_paid = ?, balance = ?, status = ?, updated_at = NOW() WHERE id = ?');
        $updA->bind_param('ddsi', $newPaid, $newBalance, $newStatus, $assessmentId);
        $updA->execute();

        // Void the payment (Refund and Void both unwind; status differs).
        $payStatus = $type === 'Refund' ? 'Refunded' : 'Voided';
        $updP = $db->prepare('UPDATE payment_transaction SET status = ? WHERE id = ?');
        $updP->bind_param('si', $payStatus, $paymentId);
        $updP->execute();

        // Reversing ledger entry (debit cancels the original credit).
        soa_ledger_add(
            $db, $assessmentId, $studentId, $schoolYearId, $type, 'payment_reversals', $reversalId,
            $type . ' of payment #' . $paymentId . ' (' . $method . ')', $amount, 0.0, $newBalance, $approverName
        );

        // Back out the day's collection for the original cashier (best effort).
        if ($cashierName !== '' && $bizDate !== '') {
            $cash   = $method === 'Cash' ? $amount : 0.0;
            $online = in_array($method, ['GCash', 'Maya', 'Bank'], true) ? $amount : 0.0;
            $updC = $db->prepare(
                'UPDATE collection_summary
                    SET txn_count       = GREATEST(0, txn_count - 1),
                        total_cash      = GREATEST(0, total_cash - ?),
                        total_online    = GREATEST(0, total_online - ?),
                        total_collected = GREATEST(0, total_collected - ?)
                  WHERE cashier_name = ? AND business_date = ?'
            );
            $updC->bind_param('dddss', $cash, $online, $amount, $cashierName, $bizDate);
            $updC->execute();
        }

        // Approve the request.
        $upd = $db->prepare("UPDATE payment_reversals SET status = 'Approved', approved_by = ? WHERE id = ?");
        $upd->bind_param('si', $approverName, $reversalId);
        $upd->execute();

        $after = json_encode([
            'reversal_id' => $reversalId, 'payment_id' => $paymentId, 'type' => $type,
            'amount' => $amount, 'payment_status' => $payStatus, 'new_balance' => $newBalance,
        ]);
        soa_audit($db, $approverId, $approverName, 'APPROVE_' . strtoupper($type), 'payment_reversals', (string) $reversalId, null, $after);

        $db->commit();
        return ['reversal_id' => $reversalId, 'payment_id' => $paymentId, 'type' => $type, 'amount' => $amount, 'new_balance' => $newBalance];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Approval failed: ' . $e->getMessage());
    }
}

/** Checker step: reject a pending reversal request (no money moves). */
function soa_reject_reversal(mysqli $db, int $reversalId, array $approver, string $note = ''): void
{
    $approverName = (string) ($approver['full_name'] ?? 'Admin');
    $approverId   = (int) ($approver['id'] ?? 0);

    $rStmt = $db->prepare('SELECT id, status, reason FROM payment_reversals WHERE id = ? LIMIT 1');
    $rStmt->bind_param('i', $reversalId);
    $rStmt->execute();
    $rev = stmt_fetch_assoc($rStmt);
    if (!$rev) {
        throw new RuntimeException('Reversal request not found.');
    }
    if ((string) $rev['status'] !== 'Pending') {
        throw new RuntimeException('This request is already ' . strtolower((string) $rev['status']) . '.');
    }
    $newReason = trim((string) $rev['reason'] . ($note !== '' ? ' | Rejected: ' . $note : ''));
    $upd = $db->prepare("UPDATE payment_reversals SET status = 'Rejected', approved_by = ?, reason = ? WHERE id = ?");
    $upd->bind_param('ssi', $approverName, $newReason, $reversalId);
    $upd->execute();

    soa_audit($db, $approverId, $approverName, 'REJECT_REVERSAL', 'payment_reversals', (string) $reversalId, null, json_encode(['note' => $note]));
}

/** Small audit helper shared by the reversal flow. */
function soa_audit(mysqli $db, int $actorId, string $actorName, string $action, string $entity, string $entityId, ?string $before, ?string $after): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $stmt = $db->prepare(
        'INSERT INTO financial_audit_logs (actor_id, actor_name, action, entity, entity_id, before_json, after_json, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssssss', $actorId, $actorName, $action, $entity, $entityId, $before, $after, $ip);
    $stmt->execute();
}

/* ============================================================================
 * END-OF-DAY CASH CLOSE  (Milestone 5)
 *   collection_summary rows are upserted live by soa_post_payment. Closing a
 *   day records the cashier's counted cash, computes over/short, and locks it.
 * ========================================================================== */

/**
 * The individual posted payments that make up one cashier's business day —
 * used to render the Z-report detail under a collection_summary row.
 *
 * @return array<int,array<string,mixed>>
 */
function soa_collection_payments(mysqli $db, string $cashierName, string $businessDate): array
{
    $stmt = $db->prepare(
        "SELECT pt.id, pt.method, pt.amount, pt.status, pt.paid_at, rm.or_number,
                COALESCE(
                    CONCAT(p.surname, ', ', p.firstname),
                    CONCAT(osp.surname, ', ', osp.firstname)
                ) AS full_name
         FROM payment_transaction pt
         LEFT JOIN receipt_master rm      ON rm.payment_id = pt.id
         LEFT JOIN student_assessment sa  ON sa.id = pt.assessment_id
         LEFT JOIN enrollment en          ON en.id = sa.enrollment_id
         LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
         LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
         WHERE pt.received_by = ? AND DATE(pt.paid_at) = ?
         ORDER BY pt.id"
    );
    $stmt->bind_param('ss', $cashierName, $businessDate);
    $stmt->execute();
    return stmt_fetch_all_assoc($stmt);
}

/**
 * Close one collection_summary row: record counted cash, compute variance
 * (declared − expected cash), lock to Closed. Idempotent-safe (throws if
 * already closed). Returns a small summary.
 *
 * @return array{collection_id:int,expected_cash:float,declared_cash:float,variance:float}
 */
function soa_close_collection(mysqli $db, int $collectionId, float $declaredCash, string $notes, array $user): array
{
    $actorId   = (int) ($user['id'] ?? 0);
    $actorName = (string) ($user['full_name'] ?? 'Cashier');
    $declaredCash = round($declaredCash, 2);

    $db->begin_transaction();
    try {
        $cStmt = $db->prepare('SELECT id, cashier_name, business_date, total_cash, status FROM collection_summary WHERE id = ? FOR UPDATE');
        $cStmt->bind_param('i', $collectionId);
        $cStmt->execute();
        $col = stmt_fetch_assoc($cStmt);
        if (!$col) {
            throw new RuntimeException('Collection not found.');
        }
        if ((string) $col['status'] === 'Closed') {
            throw new RuntimeException('This day is already closed.');
        }
        $expectedCash = (float) $col['total_cash'];
        $variance     = round($declaredCash - $expectedCash, 2);

        $upd = $db->prepare(
            "UPDATE collection_summary
                SET declared_cash = ?, variance = ?, notes = ?, status = 'Closed',
                    closed_by = ?, closed_at = NOW()
              WHERE id = ?"
        );
        $upd->bind_param('ddssi', $declaredCash, $variance, $notes, $actorName, $collectionId);
        $upd->execute();

        $after = json_encode([
            'collection_id' => $collectionId, 'business_date' => $col['business_date'],
            'expected_cash' => $expectedCash, 'declared_cash' => $declaredCash, 'variance' => $variance,
        ]);
        soa_audit($db, $actorId, $actorName, 'CLOSE_COLLECTION', 'collection_summary', (string) $collectionId, null, $after);

        $db->commit();
        return ['collection_id' => $collectionId, 'expected_cash' => $expectedCash, 'declared_cash' => $declaredCash, 'variance' => $variance];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Close failed: ' . $e->getMessage());
    }
}
