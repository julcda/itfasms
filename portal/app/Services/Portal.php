<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Portal data/service layer.
 *
 * A faithful port of the native student module's read logic
 * (includes/student_auth.php + the SOA / grades / certificate helpers),
 * expressed with Laravel's query builder over the SAME `enrollment_db`.
 *
 * The messy legacy schema (LRN bridge, CAST joins, New-vs-Old profile
 * precedence) is preserved exactly so this portal shows the identical figures
 * the native app and cashier do. Read-only by design, except the student's own
 * password + photo.
 */
class Portal
{
    public const STUDENT_DEFAULT_PW = 'password';
    public const GRADE_PASSING = 75.0;

    /* ── School year ─────────────────────────────────────────────────────── */

    /** @return array{id:int,label:string} */
    public function activeSy(): array
    {
        $row = DB::table('schoolyear')->where('Status', 1)
            ->orderByDesc('School_year_id')->first();
        return $row
            ? ['id' => (int) $row->School_year_id, 'label' => (string) $row->School_year]
            : ['id' => 0, 'label' => ''];
    }

    /* ── Authentication (LRN login) ──────────────────────────────────────── */

    /** Resolve an officially-enrolled student in the active SY by LRN. */
    public function resolveByLrn(string $lrn, string $syLabel): ?object
    {
        return DB::selectOne(
            "SELECT e.id AS enrollment_id, e.student_id, e.Status,
                    e.Department, e.Department_gradelevel, e.Department_section,
                    e.Student_classification, e.school_year,
                    COALESCE(p.lrn, osp.lrn)             AS lrn,
                    COALESCE(p.surname, osp.surname)     AS surname,
                    COALESCE(p.firstname, osp.firstname) AS firstname,
                    COALESCE(p.middlename, osp.middlename) AS middlename,
                    COALESCE(p.photo, '')                AS photo,
                    IF(p.id IS NOT NULL, 'New', 'Old')   AS student_type
             FROM enrollment e
             LEFT JOIN preregistration p      ON e.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = e.student_id)
             WHERE e.school_year = ? AND e.Status = 'Officially Enrolled'
               AND COALESCE(p.lrn, osp.lrn) = ?
             ORDER BY e.id DESC LIMIT 1",
            [$syLabel, $lrn]
        );
    }

    public function lrnExists(string $lrn): bool
    {
        return DB::selectOne(
            'SELECT 1 AS x FROM preregistration WHERE lrn = ?
             UNION SELECT 1 FROM old_studentprofile WHERE lrn = ? LIMIT 1',
            [$lrn, $lrn]
        ) !== null;
    }

    public function getAccount(int $enrollmentId): ?object
    {
        return DB::table('student_portal_accounts')->where('enrollment_id', $enrollmentId)->first();
    }

    public function provisionAccount(object $resolved): object
    {
        DB::table('student_portal_accounts')->insert([
            'enrollment_id'        => (int) $resolved->enrollment_id,
            'student_id'           => (string) $resolved->student_id,
            'lrn'                  => (string) $resolved->lrn,
            'password_hash'        => Hash::make(self::STUDENT_DEFAULT_PW),
            'must_change_password' => 1,
            'status'               => 'Active',
        ]);
        return $this->getAccount((int) $resolved->enrollment_id);
    }

    /**
     * Attempt a login. Mirrors student_login().
     * @return array{ok:bool,error?:string,student?:array}
     */
    public function attemptLogin(string $lrn, string $password): array
    {
        $lrn = trim($lrn);
        if ($lrn === '' || $password === '') {
            return ['ok' => false, 'error' => 'Please enter your LRN and password.'];
        }

        $sy = $this->activeSy();
        if ($sy['id'] === 0) {
            return ['ok' => false, 'error' => 'No active school year is configured. Please contact the registrar.'];
        }

        $resolved = $this->resolveByLrn($lrn, $sy['label']);
        if (!$resolved) {
            return ['ok' => false, 'error' => $this->lrnExists($lrn)
                ? 'This LRN is not officially enrolled for S.Y. ' . $sy['label'] . '. Please contact the registrar.'
                : 'LRN not found. Please check the number and try again.'];
        }

        $account = $this->getAccount((int) $resolved->enrollment_id) ?? $this->provisionAccount($resolved);
        if (($account->status ?? 'Active') !== 'Active') {
            return ['ok' => false, 'error' => 'Your portal account is inactive. Please contact the registrar.'];
        }
        if (!Hash::check($password, (string) $account->password_hash)) {
            app(AdminService::class)->recordLogin('failed', (int) $account->id, (int) $resolved->enrollment_id, (string) $resolved->lrn);
            return ['ok' => false, 'error' => 'Invalid password.'];
        }

        DB::table('student_portal_accounts')->where('id', $account->id)->update(['last_login' => now()]);
        app(AdminService::class)->recordLogin('login', (int) $account->id, (int) $resolved->enrollment_id, (string) $resolved->lrn);

        return ['ok' => true, 'student' => [
            'account_id'    => (int) $account->id,
            'enrollment_id' => (int) $resolved->enrollment_id,
            'student_id'    => (string) $resolved->student_id,
            'lrn'           => (string) $resolved->lrn,
            'name'          => trim(($resolved->firstname ?? '') . ' ' . ($resolved->surname ?? '')),
            'must_change'   => (int) $account->must_change_password === 1,
        ]];
    }

    public function updatePassword(int $accountId, string $newPassword): void
    {
        DB::table('student_portal_accounts')->where('id', $accountId)
            ->update(['password_hash' => Hash::make($newPassword), 'must_change_password' => 0]);
    }

    /* ── Profile ─────────────────────────────────────────────────────────── */

    public function profile(int $enrollmentId): ?object
    {
        return DB::selectOne(
            "SELECT e.id AS enrollment_id, e.student_id, e.school_year, e.Status,
                    e.Department, e.Department_gradelevel, e.Department_section, e.Student_classification,
                    COALESCE(p.lrn, osp.lrn)             AS lrn,
                    COALESCE(p.surname, osp.surname)     AS surname,
                    COALESCE(p.firstname, osp.firstname) AS firstname,
                    COALESCE(p.middlename, osp.middlename) AS middlename,
                    COALESCE(p.contact, osp.contact)     AS contact,
                    COALESCE(p.email, osp.email)         AS email,
                    COALESCE(p.sex, osp.sex)             AS sex,
                    COALESCE(p.photo, '')                AS photo,
                    IF(p.id IS NOT NULL, 'New', 'Old')   AS student_type,
                    IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR)) AS grade_name,
                    IFNULL(sc.Section_name, e.Department_section) AS section_name,
                    pbc.name AS classification_name
             FROM enrollment e
             LEFT JOIN preregistration p      ON e.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = e.student_id)
             LEFT JOIN gradelevel gl ON gl.Gradelevel_id = e.Department_gradelevel
             LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = e.Department_section
             LEFT JOIN (SELECT classification_id, MAX(classification) AS name FROM payment_breakdown GROUP BY classification_id) pbc
                    ON pbc.classification_id = e.Student_classification
             WHERE e.id = ? LIMIT 1",
            [$enrollmentId]
        );
    }

    /** Shared uploads location — same folder the native app + cashier read/write. */
    public function sharedUploadsPath(): string
    {
        return rtrim((string) config('portal.uploads_path'), '/\\');
    }

    public function sharedUploadsUrl(): string
    {
        return rtrim((string) config('portal.uploads_url'), '/');
    }

    /** Portal-owned student photo storage (written + served on the portal's own domain). */
    public function studentPhotosPath(): string
    {
        // Fall back to public/student-photos so uploads still work even if the
        // config cache on the server predates the student_photos_path key.
        $path = (string) config('portal.student_photos_path');
        if ($path === '') {
            $path = public_path('student-photos');
        }
        return rtrim($path, '/\\');
    }

    public function studentPhotosUrl(): string
    {
        $url = (string) config('portal.student_photos_url');
        if ($url === '') {
            $url = '/student-photos';
        }
        return rtrim($url, '/');
    }

    /**
     * Resolve the student's photo URL. Priority:
     *   1. Portal-uploaded photo (own domain — always displays, even on a subdomain),
     *   2. the registrar's shared/native photo, then
     *   3. the legacy `photo` column.
     */
    public function photoUrl(object $profile): ?string
    {
        $enrollmentId = (int) ($profile->enrollment_id ?? 0);

        // 1) Portal-owned upload (public/student-photos), served on the portal domain.
        $pBase = $this->studentPhotosPath();
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $file = $pBase . '/' . $enrollmentId . '.' . $ext;
            if (is_file($file)) {
                return $this->studentPhotosUrl() . '/' . $enrollmentId . '.' . $ext . '?v=' . filemtime($file);
            }
        }

        // 2) Registrar's official photo in the shared/native uploads folder.
        $base = $this->sharedUploadsPath();
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $file = $base . '/student_photos/' . $enrollmentId . '.' . $ext;
            if (is_file($file)) {
                return $this->sharedUploadsUrl() . '/student_photos/' . $enrollmentId . '.' . $ext . '?v=' . filemtime($file);
            }
        }

        // 3) Legacy photo column.
        $legacy = trim((string) ($profile->photo ?? ''));
        if ($legacy !== '' && is_file($base . '/' . $legacy)) {
            return $this->sharedUploadsUrl() . '/' . $legacy;
        }
        return null;
    }

    /* ── Dashboard financial snapshot ────────────────────────────────────── */

    public function dashboardAssessment(int $enrollmentId): ?object
    {
        return DB::selectOne(
            "SELECT sa.net_assessed, sa.total_paid, sa.balance, sa.status
             FROM student_assessment sa
             JOIN schoolyear sy ON sy.School_year_id = sa.school_year_id
             WHERE sa.enrollment_id = ? AND sy.Status = 1 LIMIT 1",
            [$enrollmentId]
        );
    }

    /* ── Statement of Account (port of _soa_data.php) ────────────────────── */

    public function soaData(int $enrollmentId): array
    {
        $soa = [
            'assessment' => null, 'enrollFees' => [], 'enrollFeesTotal' => 0.0,
            'monthly' => [], 'monthlyTotal' => 0.0, 'installmentCount' => 0, 'installmentBase' => 0.0,
            'netAssessed' => 0.0, 'totalPaid' => 0.0, 'balance' => 0.0,
            'payStatus' => 'No Assessment', 'payments' => [],
            'officialSoaId' => 0, 'officialSoaPaid' => false,
            'promissoryNotes' => [], 'promissoryTotal' => 0.0,
            'backAccounts' => [], 'backAccountTotal' => 0.0,
        ];

        // Promissory notes (independent of an assessment).
        if ($this->tableExists('promissory_notes')) {
            DB::update("UPDATE promissory_notes SET status='Overdue' WHERE status='Pending' AND promised_payment_date < CURDATE()");
            $soa['promissoryNotes'] = DB::select(
                "SELECT promissory_id, promissory_no, promissory_amount, outstanding_balance,
                        date_issued, promised_payment_date, reason, status
                 FROM promissory_notes WHERE enrollment_id = ? AND status IN ('Pending','Overdue')
                 ORDER BY promissory_id DESC",
                [$enrollmentId]
            );
            $soa['promissoryTotal'] = (float) (DB::selectOne(
                "SELECT IFNULL(SUM(promissory_amount),0) s FROM promissory_notes
                 WHERE enrollment_id = ? AND status IN ('Pending','Overdue')",
                [$enrollmentId]
            )->s ?? 0);
        }

        // Prior-year back accounts (shown as a warning, never folded in).
        if ($this->tableExists('student_back_accounts')) {
            $sid = (string) (DB::table('enrollment')->where('id', $enrollmentId)->value('student_id') ?? '');
            if ($sid !== '') {
                $soa['backAccounts'] = DB::select(
                    "SELECT * FROM student_back_accounts
                     WHERE student_id = ? AND status IN ('Unpaid','Partial') AND balance > 0.009
                     ORDER BY school_year ASC, id ASC",
                    [$sid]
                );
                foreach ($soa['backAccounts'] as $b) { $soa['backAccountTotal'] += (float) $b->balance; }
                $soa['backAccountTotal'] = round($soa['backAccountTotal'], 2);
            }
        }

        $sy = $this->activeSy();
        $assessment = DB::selectOne(
            'SELECT id, net_assessed, total_paid, balance, enrollment_fees_total, installment_base, installment_count, status
             FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1',
            [$enrollmentId, $sy['id']]
        );

        if ($assessment) {
            $aid = (int) $assessment->id;
            $soa['assessment']       = $assessment;
            $soa['netAssessed']      = (float) $assessment->net_assessed;
            $soa['totalPaid']        = (float) $assessment->total_paid;
            $soa['balance']          = (float) $assessment->balance;
            $soa['enrollFeesTotal']  = (float) $assessment->enrollment_fees_total;
            $soa['installmentBase']  = (float) $assessment->installment_base;
            $soa['installmentCount'] = (int) $assessment->installment_count;

            foreach (DB::select("SELECT description, amount FROM assessment_charge WHERE assessment_id = ? AND is_installment_base = 0 ORDER BY id", [$aid]) as $c) {
                $soa['enrollFees'][] = ['label' => (string) $c->description, 'amount' => (float) $c->amount];
            }

            // Per-component monthly split (Tuition / Misc / Improvement / Books) —
            // exactly as the native SOA page shows it (soa_components_for).
            $fees = $this->fetchEnrollmentFees($enrollmentId);
            if ($fees) {
                $soa['monthly'] = $this->componentsFor(
                    (string) ($fees->Department ?? ''), (string) ($fees->gradelevel_name ?? ''),
                    (string) ($fees->classification ?? ''), (string) ($fees->student_type ?? 'Old'),
                    (float) ($fees->rate ?? 0), (bool) (int) ($fees->waive_improvement ?? 0), (bool) (int) ($fees->waive_misc ?? 0)
                );
                $soa['monthlyTotal'] = array_sum($soa['monthly']);
            }

            $running = $soa['netAssessed'];
            foreach (DB::select(
                "SELECT pt.amount, pt.method, pt.paid_at, COALESCE(rm.or_number,'—') AS or_number
                 FROM payment_transaction pt
                 LEFT JOIN receipt_master rm ON rm.payment_id = pt.id
                 WHERE pt.assessment_id = ? AND pt.status = 'Posted'
                 ORDER BY pt.paid_at ASC, pt.id ASC",
                [$aid]
            ) as $p) {
                $running = round($running - (float) $p->amount, 2);
                $soa['payments'][] = [
                    'or_number' => (string) $p->or_number, 'paid_at' => (string) $p->paid_at,
                    'method' => (string) $p->method, 'amount' => (float) $p->amount, 'running' => $running,
                ];
            }

            $soa['payStatus'] = $soa['netAssessed'] <= 0 ? 'No Assessment'
                : ($soa['balance'] <= 0 ? 'Fully Paid' : ($soa['totalPaid'] > 0 ? 'Partially Paid' : 'Unpaid'));

            if ($this->tableExists('soa_master')) {
                $o = DB::selectOne("SELECT id FROM soa_master WHERE assessment_id = ? ORDER BY id DESC LIMIT 1", [$aid]);
                if ($o) {
                    $soa['officialSoaId'] = (int) $o->id;
                    $rem = (float) (DB::selectOne(
                        "SELECT IFNULL(SUM(ps.balance),0) rem FROM soa_details sd
                         JOIN payment_schedule ps ON ps.id = sd.schedule_id WHERE sd.soa_id = ?",
                        [$soa['officialSoaId']]
                    )->rem ?? 0);
                    $soa['officialSoaPaid'] = ($soa['balance'] <= 0.009) || ($rem <= 0.009);
                }
            }
        }

        return $soa;
    }

    /* ── Grades ──────────────────────────────────────────────────────────── */

    /** Bridge the portal login to the masterlist student_id that carries grades (by LRN). */
    public function studentInfoIdByLrn(string $lrn, int $syId): int
    {
        $digits = preg_replace('/\D+/', '', $lrn) ?? '';
        if ($digits === '') { return 0; }
        $id = DB::table('studentinfo')->where('LRN_no', $digits)->where('School_year_id', $syId)
            ->value('student_id');
        return (int) ($id ?? 0);
    }

    public function releaseSchemaReady(): bool
    {
        return $this->tableExists('grade_release') && $this->tableExists('grading_period');
    }

    public function releasedPeriods(int $syId): array
    {
        if (!$this->releaseSchemaReady()) { return []; }
        return DB::select(
            "SELECT DISTINCT gp.* FROM grading_period gp
             JOIN grade_release r ON r.grading_period_id = gp.id AND r.status = 'Released'
             WHERE gp.school_year_id = ? ORDER BY gp.term_no",
            [$syId]
        );
    }

    public function gpGet(int $periodId): ?object
    {
        return DB::table('grading_period')->where('id', $periodId)->first();
    }

    /** Port of student_grade_slip(). */
    public function gradeSlip(int $studentInfoId, int $periodId): array
    {
        $out = ['released' => false, 'rows' => [], 'average' => null, 'complete' => false,
                'released_at' => null, 'subjects' => 0, 'graded' => 0];
        if ($studentInfoId <= 0 || $periodId <= 0 || !$this->releaseSchemaReady()) {
            return $out;
        }

        $rows = DB::select(
            "SELECT s.Subject_name, s.subject_code,
                    t.Fullname AS teacher_name, t.Firstname AS t_first, t.Lastname AS t_last,
                    sg.grade, sg.remarks, sg.status AS grade_status,
                    r.status AS release_status, r.released_at
             FROM student_classes sc
             JOIN classes cl        ON cl.Class_id = sc.class_id
             LEFT JOIN subject s    ON s.Subject_id = cl.Subject_id
             LEFT JOIN teacher t    ON t.Teacher_id = cl.Teacher_id
             LEFT JOIN student_grade sg
                    ON sg.class_id = cl.Class_id AND sg.student_id = sc.student_id AND sg.grading_period_id = ?
             LEFT JOIN grade_release r
                    ON r.grading_period_id = ? AND r.owner_user_id = cl.user_id AND r.status = 'Released'
             WHERE sc.student_id = ? ORDER BY s.Subject_name",
            [$periodId, $periodId, $studentInfoId]
        );

        $sum = 0.0; $n = 0; $anyReleased = false; $releasedAt = null;
        foreach ($rows as $r) {
            $isReleased = (string) ($r->release_status ?? '') === 'Released';
            $isFinal    = in_array((string) ($r->grade_status ?? ''), ['Approved', 'Locked'], true);
            $visible    = $isReleased && $isFinal && $r->grade !== null;
            if ($isReleased) { $anyReleased = true; $releasedAt = $releasedAt ?: ($r->released_at ?? null); }
            $grade = $visible ? (float) $r->grade : null;
            if ($grade !== null) { $sum += $grade; $n++; }
            $out['rows'][] = [
                'subject' => (string) ($r->Subject_name ?: '—'),
                'code'    => (string) ($r->subject_code ?? ''),
                'teacher' => trim((string) ($r->teacher_name ?: ($r->t_first . ' ' . $r->t_last))) ?: '—',
                'grade'   => $grade,
                'remarks' => $grade === null ? null
                    : ((string) ($r->remarks ?? '') ?: ($grade >= self::GRADE_PASSING ? 'Passed' : 'Failed')),
                'pending' => !$visible,
            ];
        }
        $out['subjects']    = count($rows);
        $out['graded']      = $n;
        $out['released']    = $anyReleased;
        $out['released_at'] = $releasedAt;
        $out['average']     = $n > 0 ? round($sum / $n, 2) : null;
        $out['complete']    = $n > 0 && $n === count($rows);
        return $out;
    }

    public function gradeDescriptor(?float $g): string
    {
        if ($g === null)  { return '—'; }
        if ($g >= 90)     { return 'Outstanding'; }
        if ($g >= 85)     { return 'Very Satisfactory'; }
        if ($g >= 80)     { return 'Satisfactory'; }
        if ($g >= self::GRADE_PASSING) { return 'Fairly Satisfactory'; }
        return 'Did Not Meet Expectations';
    }

    /** Section adviser (+ signature) for the grade slip, port of teacher_adviser_for_student(). */
    public function adviserForStudent(int $studentInfoId, int $syId): ?array
    {
        if ($studentInfoId <= 0 || $syId <= 0 || !$this->tableExists('advisory_class')) {
            return null;
        }
        $row = DB::selectOne(
            "SELECT t.Teacher_id, t.Fullname, t.Firstname, t.Lastname
             FROM studentinfo si
             JOIN advisory_class a ON a.section_id = si.Section AND a.school_year_id = ?
             JOIN teacher t        ON t.Teacher_id = a.teacher_id
             WHERE si.student_id = ? LIMIT 1",
            [$syId, $studentInfoId]
        );
        if (!$row) { return null; }
        $tid = (int) $row->Teacher_id;
        $sig = null;
        $base = $this->sharedUploadsPath();
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            if (is_file($base . '/teacher_signatures/' . $tid . '.' . $ext)) {
                $sig = $this->sharedUploadsUrl() . '/teacher_signatures/' . $tid . '.' . $ext;
                break;
            }
        }
        return [
            'name'      => trim((string) ($row->Fullname ?: ($row->Firstname . ' ' . $row->Lastname))),
            'signature' => $sig,
        ];
    }

    /* ── Certificates ────────────────────────────────────────────────────── */

    public function certificatesForStudent(int $studentInfoId): array
    {
        if ($studentInfoId <= 0 || !$this->tableExists('certificate')) { return []; }
        return DB::select(
            "SELECT * FROM certificate WHERE student_id = ? AND status = 'Published'
             ORDER BY school_year_id DESC, id DESC",
            [$studentInfoId]
        );
    }

    public function certificate(int $id, int $studentInfoId): ?object
    {
        if (!$this->tableExists('certificate')) { return null; }
        return DB::selectOne(
            "SELECT * FROM certificate WHERE id = ? AND student_id = ? AND status = 'Published' LIMIT 1",
            [$id, $studentInfoId]
        );
    }

    public function setting(string $key, string $default = ''): string
    {
        if (!$this->tableExists('system_setting')) { return $default; }
        $v = DB::table('system_setting')->where('setting_key', $key)->value('setting_value');
        return $v !== null ? (string) $v : $default;
    }

    /* ── Fee-component split (port of soa_service tier logic) ────────────── */

    public function fetchEnrollmentFees(int $enrollmentId): ?object
    {
        return DB::selectOne(
            "SELECT en.Department,
                    COALESCE(NULLIF(en.student_type,''), IF(p.id IS NOT NULL,'New','Old')) AS student_type,
                    en.waive_school_improvement AS waive_improvement,
                    en.waive_miscellaneous      AS waive_misc,
                    IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS gradelevel_name,
                    pb.classification, IFNULL(pb.rate,0) AS rate,
                    IFNULL(pb.tuition,0) AS tuition, IFNULL(pb.School_improvement,0) AS school_improvement
             FROM enrollment en
             LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
             LEFT JOIN payment_breakdown pb
                    ON pb.classification_id = en.Student_classification
                   AND pb.type = COALESCE(NULLIF(en.student_type,''), IF(p.id IS NOT NULL,'New','Old'))
                   AND pb.status = 'Active'
             LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
             WHERE en.id = ? LIMIT 1",
            [$enrollmentId]
        );
    }

    public function feeScheduleTier(string $department, string $gradeName, string $classificationName, string $studentType): ?object
    {
        if (!$this->tableExists('fee_schedule')) { return null; }
        $rows = DB::select("SELECT * FROM fee_schedule WHERE department = ? AND student_type = ? AND status = 'Active'", [$department, $studentType]);
        if ($rows === []) { return null; }

        $needle = ''; $g = strtoupper($gradeName);
        if (stripos($department, 'element') !== false) {
            if (preg_match('/KINDER|TAHDER/i', $g)) { $needle = 'Kinder'; }
            else { preg_match('/(\d+)/', $g, $m); $n = (int) ($m[1] ?? 0); $needle = $n >= 4 ? 'Grade 4' : 'Grade 1'; }
        } elseif (stripos($department, 'junior') !== false) {
            $needle = stripos($classificationName, 'ESC') !== false ? 'ESC' : 'Regular';
        } elseif (stripos($department, 'senior') !== false) {
            $needle = stripos($classificationName, 'VOUCHER') !== false ? 'Voucher' : 'Regular';
        }
        if ($needle === '') { return null; }
        foreach ($rows as $row) {
            if (stripos((string) $row->level, $needle) !== false) { return $row; }
        }
        return null;
    }

    private function isStandardClassification(string $c): bool
    {
        return (bool) preg_match('/REGULAR|ESC|VOUCHER/i', $c);
    }

    public function tuitionDiscount(string $classificationName, float $pbRate): float
    {
        return $this->isStandardClassification($classificationName) ? 0.0 : max(0.0, min(1.0, $pbRate));
    }

    public function isSecondarySibling(string $c): bool
    {
        return (bool) preg_match('/\b(2ND|3RD|4TH|4RTH|5TH|6TH)\s*CHILD\b/i', $c);
    }

    /** Grade-tier monthly component split. Port of soa_components_for(). */
    public function componentsFor(string $dept, string $gradeName, string $classificationName, string $studentType, float $pbRate, ?bool $waiveImprovement = null, ?bool $waiveMisc = null): array
    {
        $tier = $this->feeScheduleTier($dept, $gradeName, $classificationName, $studentType);
        if (!$tier) { return []; }
        $disc  = $this->tuitionDiscount($classificationName, $pbRate);
        $waive = $this->isSecondarySibling($classificationName) || $waiveImprovement === true;
        $improvement = $waive ? 0.0 : (float) $tier->improvement_monthly;
        $misc = $waiveMisc === true ? 0.0 : (float) $tier->misc_monthly;
        $comp = [
            'Tuition Fee'        => round((float) $tier->tuition_monthly * (1.0 - $disc), 2),
            'Miscellaneous Fee'  => $misc,
            'School Improvement' => $improvement,
        ];
        if ((float) $tier->books_monthly > 0) { $comp['Books / Materials'] = (float) $tier->books_monthly; }
        $nz = array_filter($comp, static fn ($v) => $v > 0);
        return $nz !== [] ? $nz : ['Tuition Fee' => 0.0];
    }

    /** Fallback split from payment_breakdown annuals. Port of soa_monthly_components(). */
    public function monthlyComponents(string $dept, string $studentType, float $monthlyAmount, float $pbTuition, float $pbImprovement, int $count): array
    {
        $comp = null;
        if ($monthlyAmount > 0 && $this->tableExists('fee_schedule')) {
            $row = DB::selectOne(
                "SELECT tuition_monthly, misc_monthly, improvement_monthly, books_monthly, ABS(total_monthly - ?) AS diff
                 FROM fee_schedule WHERE department = ? AND student_type = ? AND status = 'Active'
                 ORDER BY diff ASC LIMIT 1",
                [$monthlyAmount, $dept, $studentType]
            );
            if ($row && (float) $row->diff < 12.5) {
                $comp = ['Tuition Fee' => (float) $row->tuition_monthly, 'Miscellaneous Fee' => (float) $row->misc_monthly, 'School Improvement' => (float) $row->improvement_monthly];
                if ((float) $row->books_monthly > 0) { $comp['Books / Materials'] = (float) $row->books_monthly; }
            }
        }
        if ($comp === null) {
            $count = max(1, $count);
            $tuitionM = round($pbTuition / $count, 2); $imprM = round($pbImprovement / $count, 2);
            $remainder = round($monthlyAmount - $tuitionM - $imprM, 2);
            $comp = ['Tuition Fee' => max(0.0, $tuitionM), 'School Improvement' => max(0.0, $imprM), 'Miscellaneous & Other' => max(0.0, $remainder)];
        }
        $nz = array_filter($comp, static fn ($v) => $v > 0);
        return $nz !== [] ? $nz : $comp;
    }

    /* ── Official SOA slip (exact replica of includes/soa_slip.php) ──────── */

    /**
     * Assemble the exact official-slip data for the student's latest cashier SOA.
     * @return array{status:string, slip?:array}  status: ok|none|paid
     */
    public function officialSlipData(int $enrollmentId): array
    {
        $sy = $this->activeSy();
        $assessment = DB::selectOne('SELECT id, balance FROM student_assessment WHERE enrollment_id = ? AND school_year_id = ? LIMIT 1', [$enrollmentId, $sy['id']]);
        if (!$assessment) { return ['status' => 'none']; }
        $aid = (int) $assessment->id;

        if (!$this->tableExists('soa_master')) { return ['status' => 'none']; }
        $o = DB::selectOne('SELECT id FROM soa_master WHERE assessment_id = ? ORDER BY id DESC LIMIT 1', [$aid]);
        if (!$o) { return ['status' => 'none']; }
        $soaId = (int) $o->id;

        $rem = (float) (DB::selectOne("SELECT IFNULL(SUM(ps.balance),0) rem FROM soa_details sd JOIN payment_schedule ps ON ps.id = sd.schedule_id WHERE sd.soa_id = ?", [$soaId])->rem ?? 0);
        if ($rem <= 0.009 || (float) $assessment->balance <= 0.009) { return ['status' => 'paid']; }

        $doc = DB::selectOne(
            "SELECT sm.soa_number, sa.installment_base, sa.installment_count, sa.student_type,
                    IFNULL(pb.tuition,0) AS pb_tuition, IFNULL(pb.School_improvement,0) AS pb_improvement,
                    en.Department, en.school_year, en.waive_school_improvement AS waive_improvement, en.waive_miscellaneous AS waive_misc,
                    COALESCE(CONCAT(p.surname,', ',p.firstname,' ',IFNULL(p.middlename,'')), CONCAT(osp.surname,', ',osp.firstname,' ',IFNULL(osp.middlename,''))) AS full_name,
                    IFNULL(gl.Gradelevel, CAST(en.Department_gradelevel AS CHAR)) AS grade_name,
                    IFNULL(sc.Section_name, en.Department_section) AS section_name,
                    pb.description AS classification_desc, pb.classification AS classification_code, IFNULL(pb.rate,0) AS pb_rate
             FROM soa_master sm
             JOIN student_assessment sa ON sa.id = sm.assessment_id
             JOIN enrollment en         ON en.id = sa.enrollment_id
             LEFT JOIN preregistration p      ON en.student_id = CAST(p.id AS CHAR)
             LEFT JOIN old_studentprofile osp ON (p.id IS NULL AND osp.student_id = en.student_id)
             LEFT JOIN gradelevel gl ON gl.Gradelevel_id = en.Department_gradelevel
             LEFT JOIN section sc    ON CAST(sc.Section_id AS CHAR) = en.Department_section
             LEFT JOIN payment_breakdown pb ON pb.classification_id = en.Student_classification AND pb.type = sa.student_type AND pb.status = 'Active'
             WHERE sm.id = ? LIMIT 1",
            [$soaId]
        );
        if (!$doc) { return ['status' => 'none']; }

        $selected = array_map(static fn ($d) => (int) $d->term_no, DB::select('SELECT term_no FROM soa_details WHERE soa_id = ? ORDER BY term_no', [$soaId]));
        $sched    = DB::select('SELECT term_no, month_label, amount_due, amount_paid, balance FROM payment_schedule WHERE assessment_id = ? ORDER BY term_no', [$aid]);
        $chg = [];
        foreach (DB::select("SELECT IFNULL(fi.category,'other') AS category, ac.amount FROM assessment_charge ac LEFT JOIN fee_item fi ON fi.id = ac.fee_item_id WHERE ac.assessment_id = ?", [$aid]) as $c) {
            $chg[(string) $c->category] = (float) $c->amount;
        }
        $pd = DB::selectOne("SELECT SUM(fee_admission) p_adm, SUM(fee_activity) p_act, SUM(fee_books) p_books, SUM(fee_house_reg) p_house, MAX(payment_date) p_date, GROUP_CONCAT(DISTINCT or_number ORDER BY id SEPARATOR ', ') p_ors FROM backaccount_payment_records WHERE enrollment_id = ?", [$enrollmentId]);

        // ── renderHalf computation (exact port) ──
        $count   = max(1, (int) $doc->installment_count);
        $grade   = trim((string) $doc->grade_name);
        $type    = strtoupper((string) ($doc->student_type ?: 'Old'));
        $classDesc = strtoupper(trim((string) ($doc->classification_desc ?? '')));
        $isKinder  = stripos($grade, 'kinder') !== false || stripos($grade, 'tahder') !== false;
        $monthly = round(((float) $doc->installment_base) / $count, 2);

        $comp = $this->componentsFor((string) $doc->Department, $grade, (string) ($doc->classification_code ?? ''), (string) ($doc->student_type ?: 'Old'), (float) $doc->pb_rate, (bool) (int) $doc->waive_improvement, (bool) (int) $doc->waive_misc);
        if ($comp === []) {
            $comp = $this->monthlyComponents((string) $doc->Department, (string) ($doc->student_type ?: 'Old'), $monthly, (float) $doc->pb_tuition, (float) $doc->pb_improvement, $count);
        }
        $tuM = $comp['Tuition Fee'] ?? 0.0;
        $miM = $comp['Miscellaneous Fee'] ?? ($comp['Miscellaneous & Other'] ?? 0.0);
        $imM = $comp['School Improvement'] ?? 0.0;
        $bkM = $comp['Books / Materials'] ?? 0.0;

        $collected = 0.0; $schedByTerm = [];
        foreach ($sched as $sr) { $collected += (float) $sr->amount_paid; $schedByTerm[(int) $sr->term_no] = $sr; }
        $monthsPaid = $monthly > 0 ? $collected / $monthly : 0.0;
        $selFactor = 0.0; $selMonths = [];
        foreach ($selected as $t) {
            $sr = $schedByTerm[$t] ?? null; if (!$sr) { continue; }
            $due = (float) $sr->amount_due; $bal = (float) $sr->balance;
            $selFactor += $due > 0 ? $bal / $due : 0.0;
            $selMonths[] = (string) $sr->month_label;
        }
        $monthLabel = $selMonths === [] ? '—' : (count($selMonths) === 1 ? $selMonths[0] : ($selMonths[0] . ' – ' . end($selMonths)));

        $pAdm = (float) ($pd->p_adm ?? 0); $pAct = (float) ($pd->p_act ?? 0);
        $pBk  = (float) ($pd->p_books ?? 0); $pHouse = (float) ($pd->p_house ?? 0);
        $pDate = !empty($pd->p_date) ? date('n/j/y', strtotime((string) $pd->p_date)) : '';
        $pOr = (string) ($pd->p_ors ?? '');
        $upD = static fn (float $p): string => $p > 0 ? $pDate : '';
        $upO = static fn (float $p): string => $p > 0 ? $pOr : '';

        $reg = (float) ($chg['admission'] ?? 0); $act = (float) ($chg['activity'] ?? 0); $hou = (float) ($chg['house'] ?? 0);
        $bkDown = $bkM * $count;

        $rows = [];
        $rows[] = [1, 'Registration Fee', $reg, $pAdm, $reg - $pAdm, 0.0, [['A. Forms', 0], ['B. ID Card', 0], ['C. Handbook', 0], ['D. Test Paper', 0]], $upD($pAdm), $upO($pAdm)];
        $miAnnual = $miM * $count;
        $rows[] = [2, 'Miscellaneous Fees', $miAnnual, $miM * $monthsPaid, $miAnnual - $miM * $monthsPaid, $miM * $selFactor, [['A. Laboratory fee', 0], ['B. Electric fee', 0], ['C. Library fee', 0], ['D. Internet/ICT fee', 0], ['E. Facilities Maintenance fee', 0], ['F. Medical/clinic fee', 0]], '', ''];
        $imAnnual = $imM * $count;
        $rows[] = [3, 'School Improvement Fee', $imAnnual, $imM * $monthsPaid, $imAnnual - $imM * $monthsPaid, $imM * $selFactor, [], '', ''];
        $tuAnnual = $tuM * $count;
        $rows[] = [4, 'Tuition Fee', $tuAnnual, $tuM * $monthsPaid, $tuAnnual - $tuM * $monthsPaid, $tuM * $selFactor, [], '', ''];
        $rows[] = [5, 'Activity Fee', $act, $pAct, $act - $pAct, 0.0, [], $upD($pAct), $upO($pAct)];
        $rows[] = [6, 'House Reg. Fee', $hou, $pHouse, $hou - $pHouse, 0.0, [], $upD($pHouse), $upO($pHouse)];
        if ($isKinder) {
            $rows[] = [7, 'Graduation Fee', 0.0, 0.0, 0.0, 0.0, [], '', ''];
        } else {
            $bkAnnual = $bkDown + $bkM * $count; $bkPaid = $pBk + $bkM * $monthsPaid;
            $rows[] = [7, 'Books Fee', $bkAnnual, $bkPaid, $bkAnnual - $bkPaid, $bkM * $selFactor, [], $upD($pBk), $upO($pBk)];
        }

        $tCharge = $tPaid = $tBal = $tBrk = 0.0;
        foreach ($rows as $r) { $tCharge += $r[2]; $tPaid += $r[3]; $tBal += $r[4]; $tBrk += $r[5]; }

        // Warnings (same sources as the native slip)
        $pn = null;
        if ($this->tableExists('promissory_notes')) {
            $rowsPn = DB::select("SELECT promissory_no, promissory_amount, promised_payment_date FROM promissory_notes WHERE enrollment_id = ? AND status IN ('Pending','Overdue')", [$enrollmentId]);
            if ($rowsPn) {
                $sum = 0.0; $labels = [];
                foreach ($rowsPn as $x) { $sum += (float) $x->promissory_amount; $labels[] = $x->promissory_no . ' (due ' . date('n/j/y', strtotime((string) $x->promised_payment_date)) . ')'; }
                $pn = ['sum' => $sum, 'labels' => $labels];
            }
        }
        $ba = null;
        if ($this->tableExists('student_back_accounts')) {
            $sid = (string) (DB::table('enrollment')->where('id', $enrollmentId)->value('student_id') ?? '');
            if ($sid !== '') {
                $rowsBa = DB::select("SELECT school_year, balance FROM student_back_accounts WHERE student_id = ? AND status IN ('Unpaid','Partial') AND balance > 0.009", [$sid]);
                if ($rowsBa) {
                    $sum = 0.0; $labels = [];
                    foreach ($rowsBa as $x) { $sum += (float) $x->balance; $labels[] = 'S.Y. ' . $x->school_year; }
                    $ba = ['sum' => $sum, 'labels' => array_values(array_unique($labels))];
                }
            }
        }

        $appBase = rtrim((string) config('portal.app_base_url'), '/');
        return ['status' => 'ok', 'slip' => [
            'logo'        => $appBase . '/itfalogo.png',
            'name'        => strtoupper(trim((string) $doc->full_name)),
            'soaNo'       => (string) $doc->soa_number,
            'school_year' => (string) $doc->school_year,
            'monthLabel'  => strtoupper($monthLabel),
            'classLine'   => trim($classDesc . ' ' . $type),
            'grade'       => $grade,
            'section'     => (string) ($doc->section_name ?? ''),
            'rows'        => $rows,
            'tCharge'     => $tCharge, 'tPaid' => $tPaid, 'tBal' => $tBal, 'tBrk' => $tBrk,
            'promissory'  => $pn, 'backAccount' => $ba,
            'bookkeeper'  => $this->setting('SOA_BOOKKEEPER', 'Bookkeeper'),
            'cashierSig'  => $this->setting('SOA_CASHIER_SIGNATORY', 'Cashier'),
            'bookSigUrl'  => $appBase . '/Pahima%20Tahir.png',
            'cashSigUrl'  => $appBase . '/BAJUNAID%20GARAY.png',
        ]];
    }

    /* ── Schema helpers ──────────────────────────────────────────────────── */

    private array $tableCache = [];

    public function tableExists(string $table): bool
    {
        return $this->tableCache[$table] ??= DB::selectOne(
            'SELECT 1 x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== null;
    }
}
