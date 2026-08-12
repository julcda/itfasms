<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

/**
 * Super Admin maintenance layer for the student portal.
 *
 * Handles everything the console needs: authenticating a native Super Admin,
 * monitoring who accesses the portal, managing student portal accounts
 * (password resets, activation), database backups and system health.
 *
 * Writes are limited to the portal's own tables (student_portal_accounts,
 * portal_login_audit, portal_admin_audit). It never mutates the registrar's
 * enrollment/profile data.
 */
class AdminService
{
    /* ── Authentication (reuse native staff accounts) ────────────────────── */

    /**
     * Validate a native staff login and require an allowed maintenance role.
     * Mirrors includes/auth.php (enrollment_users first, user_account fallback,
     * bcrypt via password_verify → Hash::check). Read-only against those tables.
     *
     * @return array{ok:bool,error?:string,admin?:array}
     */
    public function authenticate(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Please enter your username and password.'];
        }

        $allowed = (array) config('portal.admin_roles', ['super admin']);

        foreach ([
            ['table' => 'enrollment_users', 'id' => 'user_id', 'role' => 'Role', 'name' => 'full_name'],
            ['table' => 'user_account',     'id' => 'user_id', 'role' => 'role', 'name' => null],
        ] as $src) {
            if (!$this->tableExists($src['table'])) {
                continue;
            }
            $row = DB::table($src['table'])->where('username', $username)->first();
            if (!$row) {
                continue;
            }
            $status = (string) ($row->status ?? 'Active');
            if (strcasecmp($status, 'Active') !== 0) {
                return ['ok' => false, 'error' => 'This account is deactivated.'];
            }
            $stored = (string) ($row->password ?? '');
            $valid = ($stored !== '' && Hash::check($password, $stored)) || hash_equals($stored, $password);
            if (!$valid) {
                return ['ok' => false, 'error' => 'Invalid username or password.'];
            }

            $role = strtolower(trim((string) ($row->{$src['role']} ?? '')));
            if (!in_array($role, $allowed, true)) {
                return ['ok' => false, 'error' => 'Your account is not permitted in the maintenance console.'];
            }

            $name = $src['name'] ? (string) ($row->{$src['name']} ?? '') : '';
            if ($name === '') {
                $name = trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? '')) ?: $username;
            }

            return ['ok' => true, 'admin' => [
                'id'       => (int) ($row->{$src['id']} ?? 0),
                'username' => $username,
                'name'     => $name,
                'role'     => ucwords($role),
            ]];
        }

        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    /* ── Dashboard statistics ────────────────────────────────────────────── */

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $accounts = DB::table('student_portal_accounts');
        $total     = (clone $accounts)->count();
        $active    = (clone $accounts)->where('status', 'Active')->count();
        $inactive  = (clone $accounts)->where('status', 'Inactive')->count();
        $everIn    = (clone $accounts)->whereNotNull('last_login')->count();
        $mustChg   = (clone $accounts)->where('must_change_password', 1)->count();

        $sy = $this->activeSyLabel();
        $enrolled = $sy
            ? (int) DB::table('enrollment')->where('school_year', $sy)
                ->where('Status', 'Officially Enrolled')->count()
            : 0;

        return [
            'accounts'        => $total,
            'active'          => $active,
            'inactive'        => $inactive,
            'ever_logged_in'  => $everIn,
            'never_logged_in' => max(0, $total - $everIn),
            'must_change'     => $mustChg,
            'enrolled'        => $enrolled,
            'not_provisioned' => max(0, $enrolled - $total),
            'logins_today'    => $this->countLogins(Carbon::today()),
            'logins_7d'       => $this->countLogins(Carbon::now()->subDays(7)),
            'active_today'    => $this->distinctActive(Carbon::today()),
            'sy'              => $sy,
            'last_backup'     => $this->lastBackup(),
        ];
    }

    private function countLogins(Carbon $since): int
    {
        return $this->tableExists('portal_login_audit')
            ? (int) DB::table('portal_login_audit')->where('event', 'login')
                ->where('created_at', '>=', $since)->count()
            : 0;
    }

    private function distinctActive(Carbon $since): int
    {
        return $this->tableExists('portal_login_audit')
            ? (int) DB::table('portal_login_audit')->where('event', 'login')
                ->where('created_at', '>=', $since)->distinct()->count('account_id')
            : 0;
    }

    /** Login volume for the last N days, oldest → newest. @return array<string,int> */
    public function loginTrend(int $days = 14): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[Carbon::today()->subDays($i)->format('M j')] = 0;
        }
        if ($this->tableExists('portal_login_audit')) {
            $rows = DB::table('portal_login_audit')
                ->selectRaw('DATE(created_at) d, COUNT(*) c')
                ->where('event', 'login')
                ->where('created_at', '>=', Carbon::today()->subDays($days - 1))
                ->groupBy('d')->get();
            foreach ($rows as $r) {
                $out[Carbon::parse($r->d)->format('M j')] = (int) $r->c;
            }
        }
        return $out;
    }

    /* ── Student portal accounts ─────────────────────────────────────────── */

    /**
     * Paginated, filterable list of portal accounts joined to the student's
     * name / grade / section. @param array<string,mixed> $f
     */
    public function accounts(array $f = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = $this->accountsQuery();

        if (!empty($f['search'])) {
            $s = '%' . trim((string) $f['search']) . '%';
            $q->where(function ($w) use ($s) {
                $w->where('spa.lrn', 'like', $s)
                  ->orWhereRaw("CONCAT_WS(' ', COALESCE(p.firstname, osp.firstname), COALESCE(p.surname, osp.surname)) LIKE ?", [$s])
                  ->orWhere('spa.student_id', 'like', $s);
            });
        }
        if (!empty($f['status'])) {
            $q->where('spa.status', $f['status']);
        }
        if (!empty($f['grade'])) {
            $q->where(DB::raw('IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR))'), $f['grade']);
        }
        if (!empty($f['section'])) {
            $q->where(DB::raw('IFNULL(sc.Section_name, e.Department_section)'), $f['section']);
        }
        switch ($f['login'] ?? '') {
            case 'never':  $q->whereNull('spa.last_login'); break;
            case 'today':  $q->whereDate('spa.last_login', Carbon::today()); break;
            case '7days':  $q->where('spa.last_login', '>=', Carbon::now()->subDays(7)); break;
            case 'active': $q->whereNotNull('spa.last_login'); break;
        }

        $sort = in_array($f['sort'] ?? '', ['name', 'last_login', 'grade'], true) ? $f['sort'] : 'last_login';
        match ($sort) {
            'name'  => $q->orderByRaw('COALESCE(p.surname, osp.surname) ASC'),
            'grade' => $q->orderBy('e.Department_gradelevel')->orderByRaw('COALESCE(sc.Section_name, e.Department_section)'),
            default => $q->orderByRaw('spa.last_login IS NULL, spa.last_login DESC'),
        };

        return $q->paginate($perPage)->withQueryString();
    }

    private function accountsQuery()
    {
        return DB::table('student_portal_accounts as spa')
            ->join('enrollment as e', 'e.id', '=', 'spa.enrollment_id')
            ->leftJoin('preregistration as p', function ($j) {
                $j->on('e.student_id', '=', DB::raw('CAST(p.id AS CHAR)'));
            })
            ->leftJoin('old_studentprofile as osp', function ($j) {
                $j->on('osp.student_id', '=', 'e.student_id')->whereNull('p.id');
            })
            ->leftJoin('gradelevel as gl', 'gl.Gradelevel_id', '=', 'e.Department_gradelevel')
            ->leftJoin('section as sc', function ($j) {
                $j->on(DB::raw('CAST(sc.Section_id AS CHAR)'), '=', 'e.Department_section');
            })
            ->selectRaw(
                "spa.id, spa.enrollment_id, spa.lrn, spa.student_id, spa.status,
                 spa.must_change_password, spa.last_login, spa.created_at,
                 TRIM(CONCAT_WS(' ', COALESCE(p.firstname, osp.firstname), COALESCE(p.surname, osp.surname))) AS name,
                 IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR)) AS grade_name,
                 IFNULL(sc.Section_name, e.Department_section) AS section_name,
                 e.Department"
            );
    }

    /** Distinct grade + section values for the filter dropdowns. @return array{grades:array,sections:array} */
    public function filterOptions(): array
    {
        $rows = DB::table('student_portal_accounts as spa')
            ->join('enrollment as e', 'e.id', '=', 'spa.enrollment_id')
            ->leftJoin('gradelevel as gl', 'gl.Gradelevel_id', '=', 'e.Department_gradelevel')
            ->leftJoin('section as sc', function ($j) {
                $j->on(DB::raw('CAST(sc.Section_id AS CHAR)'), '=', 'e.Department_section');
            })
            ->selectRaw('DISTINCT IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR)) AS grade,
                         IFNULL(sc.Section_name, e.Department_section) AS section')
            ->get();

        $grades = collect($rows)->pluck('grade')->filter()->unique()->sort()->values()->all();
        $sections = collect($rows)->pluck('section')->filter()->unique()->sort()->values()->all();
        return ['grades' => $grades, 'sections' => $sections];
    }

    public function findAccount(int $id): ?object
    {
        return (clone $this->accountsQuery())->where('spa.id', $id)->first();
    }

    /** Reset one account to the default password + force a change on next login. */
    public function resetPassword(int $accountId): bool
    {
        return DB::table('student_portal_accounts')->where('id', $accountId)->update([
            'password_hash'        => Hash::make(Portal::STUDENT_DEFAULT_PW),
            'must_change_password' => 1,
            'updated_at'           => now(),
        ]) > 0;
    }

    /** Bulk reset by grade and/or section. @return int number of accounts reset */
    public function bulkReset(?string $grade, ?string $section): int
    {
        $ids = $this->accountsQuery();
        if ($grade)   { $ids->where(DB::raw('IFNULL(gl.Gradelevel, CAST(e.Department_gradelevel AS CHAR))'), $grade); }
        if ($section) { $ids->where(DB::raw('IFNULL(sc.Section_name, e.Department_section)'), $section); }
        $accountIds = collect($ids->get())->pluck('id')->all();
        if (!$accountIds) {
            return 0;
        }
        return DB::table('student_portal_accounts')->whereIn('id', $accountIds)->update([
            'password_hash'        => Hash::make(Portal::STUDENT_DEFAULT_PW),
            'must_change_password' => 1,
            'updated_at'           => now(),
        ]);
    }

    public function setStatus(int $accountId, string $status): bool
    {
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            return false;
        }
        return DB::table('student_portal_accounts')->where('id', $accountId)
            ->update(['status' => $status, 'updated_at' => now()]) > 0;
    }

    /* ── Access monitoring (login audit) ─────────────────────────────────── */

    /** Record a login/logout/failed event. Best-effort — never breaks auth. */
    public function recordLogin(string $event, ?int $accountId, ?int $enrollmentId, ?string $lrn): void
    {
        if (!$this->tableExists('portal_login_audit')) {
            return;
        }
        try {
            DB::table('portal_login_audit')->insert([
                'account_id'    => $accountId,
                'enrollment_id' => $enrollmentId,
                'lrn'           => $lrn,
                'event'         => $event,
                'ip_address'    => request()->ip(),
                'user_agent'    => substr((string) request()->userAgent(), 0, 255),
                'created_at'    => now(),
            ]);
        } catch (\Throwable) {
            // monitoring must never block a login
        }
    }

    /** @param array<string,mixed> $f */
    public function loginLog(array $f = [], int $perPage = 40): LengthAwarePaginator
    {
        $q = DB::table('portal_login_audit as a')
            ->leftJoin('student_portal_accounts as spa', 'spa.id', '=', 'a.account_id')
            ->leftJoin('enrollment as e', 'e.id', '=', 'a.enrollment_id')
            ->leftJoin('preregistration as p', function ($j) {
                $j->on('e.student_id', '=', DB::raw('CAST(p.id AS CHAR)'));
            })
            ->leftJoin('old_studentprofile as osp', function ($j) {
                $j->on('osp.student_id', '=', 'e.student_id')->whereNull('p.id');
            })
            ->selectRaw(
                "a.*, TRIM(CONCAT_WS(' ', COALESCE(p.firstname, osp.firstname), COALESCE(p.surname, osp.surname))) AS name"
            )
            ->orderByDesc('a.created_at');

        if (!empty($f['event'])) {
            $q->where('a.event', $f['event']);
        }
        if (!empty($f['search'])) {
            $s = '%' . trim((string) $f['search']) . '%';
            $q->where(function ($w) use ($s) {
                $w->where('a.lrn', 'like', $s)
                  ->orWhereRaw("CONCAT_WS(' ', COALESCE(p.firstname, osp.firstname), COALESCE(p.surname, osp.surname)) LIKE ?", [$s])
                  ->orWhere('a.ip_address', 'like', $s);
            });
        }
        return $q->paginate($perPage)->withQueryString();
    }

    /* ── Admin action audit ──────────────────────────────────────────────── */

    public function logAdmin(array $admin, string $action, ?string $target = null, ?string $details = null): void
    {
        if (!$this->tableExists('portal_admin_audit')) {
            return;
        }
        try {
            DB::table('portal_admin_audit')->insert([
                'admin_id'   => (int) ($admin['id'] ?? 0),
                'admin_name' => (string) ($admin['name'] ?? 'Super Admin'),
                'action'     => $action,
                'target'     => $target,
                'details'    => $details,
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }

    public function adminLog(int $limit = 50)
    {
        return $this->tableExists('portal_admin_audit')
            ? DB::table('portal_admin_audit')->orderByDesc('created_at')->limit($limit)->get()
            : collect();
    }

    /* ── Database backups ────────────────────────────────────────────────── */

    /** @return array<int,array{name:string,size:int,modified:int}> */
    public function backups(): array
    {
        $dir = $this->backupDir();
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.sql*') ?: [] as $f) {
            if (is_file($f)) {
                $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'modified' => (int) filemtime($f)];
            }
        }
        usort($out, fn ($a, $b) => $b['modified'] <=> $a['modified']);
        return $out;
    }

    public function lastBackup(): ?array
    {
        return $this->backups()[0] ?? null;
    }

    /** @return array{ok:bool,file?:string,error?:string} */
    public function runBackup(): array
    {
        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return ['ok' => false, 'error' => 'Cannot create backup directory.'];
        }

        $bin = $this->mysqldump();
        if (!$bin) {
            return ['ok' => false, 'error' => 'mysqldump was not found on this server. Set MYSQLDUMP_BIN in .env.'];
        }

        $db   = (string) config('database.connections.' . config('database.default') . '.database');
        $host = (string) config('database.connections.' . config('database.default') . '.host');
        $port = (string) (config('database.connections.' . config('database.default') . '.port') ?: 3306);
        $user = (string) config('database.connections.' . config('database.default') . '.username');
        $pass = (string) config('database.connections.' . config('database.default') . '.password');

        $file = $dir . DIRECTORY_SEPARATOR . 'portal_' . $db . '_' . date('Ymd_His') . '.sql';

        $process = new Process([
            $bin, '--host=' . $host, '--port=' . $port, '--user=' . $user,
            '--single-transaction', '--quick', '--default-character-set=utf8mb4',
            '--result-file=' . $file, $db,
        ], null, ['MYSQL_PWD' => $pass], null, 600);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Backup failed to start: ' . $e->getMessage()];
        }

        if (!$process->isSuccessful() || !is_file($file) || filesize($file) === 0) {
            @unlink($file);
            $err = trim($process->getErrorOutput()) ?: 'unknown error';
            return ['ok' => false, 'error' => 'mysqldump failed: ' . substr($err, 0, 300)];
        }

        return ['ok' => true, 'file' => basename($file)];
    }

    public function backupPath(string $name): ?string
    {
        $safe = basename($name); // block traversal
        $path = $this->backupDir() . DIRECTORY_SEPARATOR . $safe;
        return is_file($path) ? $path : null;
    }

    public function deleteBackup(string $name): bool
    {
        $path = $this->backupPath($name);
        return $path ? @unlink($path) : false;
    }

    private function backupDir(): string
    {
        return rtrim((string) config('portal.backup_path', storage_path('app/backups')), '/\\');
    }

    private function mysqldump(): ?string
    {
        $configured = trim((string) config('portal.mysqldump_bin', ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        foreach ([
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/cpanel/mysql/bin/mysqldump',
        ] as $cand) {
            if (is_file($cand)) {
                return $cand;
            }
        }
        // Fall back to PATH lookup.
        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
        try {
            $p = Process::fromShellCommandline($which . ' mysqldump');
            $p->run();
            $line = trim(strtok($p->getOutput(), "\n"));
            if ($p->isSuccessful() && $line !== '' && is_file($line)) {
                return $line;
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /* ── System health ───────────────────────────────────────────────────── */

    /** @return array<string,mixed> */
    public function systemInfo(): array
    {
        $conn = config('database.default');
        $db   = (string) config("database.connections.$conn.database");

        $dbSize = 0.0;
        try {
            $row = DB::selectOne(
                'SELECT SUM(data_length + index_length) AS bytes
                 FROM information_schema.tables WHERE table_schema = ?',
                [$db]
            );
            $dbSize = (float) ($row->bytes ?? 0);
        } catch (\Throwable) {
        }

        $dir = $this->backupDir();
        return [
            'php_version'     => PHP_VERSION,
            'laravel_version' => app()->version(),
            'app_env'         => app()->environment(),
            'debug'           => (bool) config('app.debug'),
            'timezone'        => (string) config('app.timezone'),
            'db_name'         => $db,
            'db_host'         => (string) config("database.connections.$conn.host"),
            'db_size'         => $dbSize,
            'disk_free'       => @disk_free_space(base_path()) ?: 0,
            'disk_total'      => @disk_total_space(base_path()) ?: 0,
            'backup_dir'      => $dir,
            'backup_count'    => count($this->backups()),
            'mysqldump'       => $this->mysqldump() ?: 'not found',
        ];
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    private function activeSyLabel(): ?string
    {
        $row = DB::table('schoolyear')->where('Status', 1)->orderByDesc('School_year_id')->first();
        return $row ? (string) $row->School_year : null;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            try {
                $cache[$table] = DB::getSchemaBuilder()->hasTable($table);
            } catch (\Throwable) {
                $cache[$table] = false;
            }
        }
        return $cache[$table];
    }
}
