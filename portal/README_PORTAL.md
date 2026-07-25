# ITFA Student Portal (Laravel)

A Laravel 12 re-implementation of the native `student/` module. It talks to the
**same `enrollment_db`** the native app uses and is a faithful port of the student
experience: LRN login, dashboard, Statement of Account (view + print), grades
(view + printable slip), account/photo/password, and certificates.

It is designed to run **either** as a subfolder of the existing app **or** as a
standalone subdomain (`student.itfa.edu.ph`) — no code changes, only `.env`.

## What it shares vs. owns

- **Shares (read-mostly):** `enrollment`, `preregistration` / `old_studentprofile`,
  `student_assessment`, `assessment_charge`, `payment_transaction`, `soa_master`,
  `payment_schedule`, `student_grade`, `grade_release`, `certificate`,
  `promissory_notes`, `student_back_accounts`, and the `uploads/` folder.
- **Owns (writes):** only `student_portal_accounts` (login + password, exactly as
  the native portal does) and student photos in the shared `uploads/student_photos`.
- **Creates no tables.** Sessions/cache/queue are file-based, so importing this
  portal changes nothing in your database schema.

## Requirements

PHP 8.2+ with `pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, bcmath,
fileinfo, curl`. All present on the current XAMPP box.

## Run locally (subfolder mode — already configured)

```bash
cd portal
php artisan serve            # http://127.0.0.1:8000
```

`.env` already points at `enrollment_db` on `127.0.0.1:3306` (root / no password)
with `DB_COLLATION=utf8mb4_general_ci` to match the existing tables. Log in with a
student's **LRN**; first login auto-provisions the account with default password
**`password`** and forces a change (same behavior as the native portal).

## Deploy as a subdomain — `student.itfa.edu.ph`

1. Upload the whole `portal/` folder to the server (anywhere, e.g. `/home/itfa/portal`).
   Run `composer install --no-dev --optimize-autoloader` there if `vendor/` isn't shipped.
2. `cp .env.example .env`, then edit: set `DB_*` to the live database, set
   `APP_URL`, `SHARED_UPLOADS_URL`, `SHARED_APP_URL`, and if the uploads folder is
   on the same box, `SHARED_UPLOADS_PATH` to its absolute path.
3. `php artisan key:generate` then `php artisan config:cache route:cache`.
4. Point the subdomain's **document root at `portal/public`** (never at `portal/`).
   Nginx example:
   ```
   server {
       server_name student.itfa.edu.ph;
       root /home/itfa/portal/public;
       index index.php;
       location / { try_files $uri $uri/ /index.php?$query_string; }
       location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.2-fpm.sock;
                           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; }
   }
   ```
   Apache: a `<VirtualHost>` with `DocumentRoot /home/itfa/portal/public` (the bundled
   `public/.htaccess` handles the rest). Add TLS (Let's Encrypt).
5. Ensure `storage/` and `bootstrap/cache/` are writable, and the shared
   `uploads/` path is readable/writable if students upload photos.

Because auth lives in its own session (cookie `itfa_portal_session`) and the DB is
shared, the subdomain and the native app stay in sync automatically — a payment
the cashier posts shows on the student's SOA immediately.

## Fidelity notes

- **Financial figures are exact** — assessment, payments, balance, promissory and
  back-account warnings all read the same rows the native app + cashier use.
- The SOA screen shows the **full per-component monthly breakdown** (Tuition /
  Miscellaneous / School Improvement / Books), resolved from the same `fee_schedule`
  grade-tier logic (`soa_components_for`) the cashier uses.
- **The printable SOA is the exact official slip** — a byte-for-byte port of
  `includes/soa_slip.php` (same account-title ledger, "for the month of" header,
  breakdown column, CODE128 barcode, bookkeeper/cashier signatures, promissory +
  back-account warnings), rendered 1-up like `student/soa_print.php`. It prints the
  latest cashier-generated SOA and refuses when none exists or it is fully paid.
- Grade slip + certificate prints also match the native layouts.
