# ITFA I-SMS — Resume Notes

**Last updated:** 2026-07-20 · **Status:** development on local XAMPP · **NOT deployed**
**Decision:** go-live still deferred — you are actively building/testing locally.

> **Start here when resuming.** §1 = what exists · §2 = the go-live checklist
> (nothing in it has been run online) · §3 = known issues · §4 = never upload.

---

## 1. Where we are

### Modules built and tested (local only)

| Module | Key files | State |
|---|---|---|
| **Back Accounts** (cashier) | `includes/back_account_service.php`, `cashier/back_accounts.php`, `cashier/back_account_receipt.php` | ✅ 491 legacy rows migrated, ₱1,786,763.50 reconciled |
| **Teacher Module** | `includes/{teacher_auth,teacher_service,grading_service}.php`, `teacher/*.php` | ✅ 112 logins provisioned |
| **Grade Review** (dept head) | `depthead/grade_review.php` | ✅ submit→return→fix→approve verified |
| **Grading Periods** (registrar) | `registrar/grading_periods.php` | ✅ lock / reopen |
| **Teacher Management** | `depthead/teachers.php` | ✅ deactivate blocks login + hides from dropdowns |
| **Teacher Dashboard v2** | `teacher/index.php` | ✅ action KPI tiles, grading meter, bulletin-style announcements |
| **Teacher Messaging** | `includes/message_service.php`, `teacher/messages.php` | ✅ 1-to-1 chat, unread badges, 5s polling |
| **Grade Slip release** | `includes/grading_service.php`, `student/{grades,grade_slip}.php` | ✅ head publishes → student sees + prints |
| **Account Management** | `includes/account_service.php`, `depthead/accounts.php` | ✅ Super Admin manages all 127 accounts across 3 stores |
| **Certificates of Recognition** | `includes/{certificate_service,certificate_render}.php`, `teacher/certificates.php`, `depthead/{advisers,certificates,certificate_print}.php`, `student/certificate.php`, `verify.php` | ✅ QR-verified, adviser→head→student |
| **Other Payments** (cashier) | `includes/other_payment_service.php`, `cashier/other_payment.php` | ✅ built earlier |
| **Search performance** | `migrations/student_search_indexes.sql` | ✅ 4.8s → 8ms (~300×) |

### Reference docs in the repo
`TEACHER_MODULE_DESIGN.md` (architecture/ERD/3NF/MVC) · `PHASE2_SOA_SYSTEM_DESIGN.md` ·
`CASHIER_MODULE_ASSESSMENT.md` · `STUDENT_PORTAL.md` ·
`docs/ITFA_Back_Accounts_User_Manual.pdf` · `docs/SOA_Payment_Promissory_Process-IBN.pdf` ·
`credentials.txt` **(never upload — §4)**

### 🔵 Your own test data is in the local DB — do not wipe it
Left deliberately untouched:
- **1 chat conversation** between users 26↔31 (*"Assalamo Alaykom!"*)
- **1 grade_release** row: Elementary head (Fahima Mustapha) published then withdrew First Grading
- **student_back_accounts = 492** (491 migrated + **1 you added**)

Baseline that must stay intact: **`student_grade` = 15,447** (the migrated legacy grades).

---

## 2. GO-LIVE CHECKLIST  ← none of this has been done online

### 2a. Migrations to run on the online DB, **in this order**
All idempotent; safe to re-run.

| # | File | What it does |
|---|------|--------------|
| 1 | `GOLIVE_consolidated.sql` | Other Payments (fixes the "need to migrate" error) + promissory + waivers + portal + deposits |
| 2 | `student_back_accounts.sql` | Back-account tables + migrates 491 legacy rows |
| 3 | `student_search_indexes.sql` | Missing indexes — big speed win, no data change |
| 4 | `teacher_module.sql` | `grading_period`, `student_grade`, `student_grade_history`, `advisory_class`, `class_schedule`; migrates 15,447 legacy grades; **drops the empty `student_grades` placeholder** |
| 5 | `teacher_accounts.sql` | Widens `user_account.role` to accept `'teacher'`; email NULLable; adds `must_change_password`/`status`/`last_login` |
| 6 | `php migrations/provision_teacher_accounts.php` | Creates the 112 teacher logins. **Must run AFTER #5.** Web-runnable as Super Admin if the host has no CLI |
| 7 | `grade_review.sql` | `reviewed_by`/`reviewed_at`/`review_note` on `student_grade` |
| 8 | `teacher_messaging.sql` | `chat_conversation`, `chat_message` |
| 9 | `grade_release.sql` | `grade_release` (grade-slip publishing) |
| 10 | `account_management.sql` | `enrollment_users.status` + `last_login` |
| 11 | `certificates.sql` | `certificate` table, CERT series, `PRINCIPAL_NAME` setting |

> ⚠ **#4 must ship together with its code.** It drops `student_grades`; the old
> `includes/registrar_service.php` reads that table, so deploying the SQL alone
> takes the Registrar's student-drop screen down with *"Table doesn't exist"*.

### 2b. Files to upload
```
ROOT       verify.php                    ← public QR target, must be reachable
includes/  auth.php  functions.php  registrar_service.php
           soa_service.php  soa_slip.php  back_account_service.php
           teacher_auth.php  teacher_service.php  grading_service.php
           message_service.php  account_service.php
           certificate_service.php  certificate_render.php
cashier/   back_accounts.php  back_account_receipt.php  sidebar.php  soa.php
           other_payment.php  other_receipt.php
teacher/   (whole folder — index, classes, class_view, grade_history, schedule,
            advisees, change_password, messages, certificates, account, sidebar)
depthead/  grade_review.php  teachers.php  advisers.php  accounts.php
           certificates.php  certificate_print.php  sidebar.php  index.php  manage.php
registrar/ grading_periods.php  sidebar.php  schedule.php  student_records.php
student/   _soa_data.php  soa.php  soa_print.php  grades.php  grade_slip.php
           certificate.php  sidebar.php
migrations/ (all)
```
Simplest: upload everything **except** the §4 list.

### 2c. After deploying
1. Change `admin1`, `jhs`, `registrar` passwords (§5).
2. **Reset the 4 remaining plaintext logins** via Super Admin → Account Management —
   each reset converts that account to bcrypt (see §3.5).
3. Distribute teacher usernames (default `teacher`, forced change at first login).
4. **Assign class advisers** (Dept Head → Class Advisers) — certificates cannot be
   issued until a section has an adviser.
5. Once teachers have logged in: `ALTER TABLE teacher DROP COLUMN Password;`
6. Smoke test: Cashier → Other Payment (no migrate warning) · a teacher login ·
   Dept Head → Grade Review · scan a certificate QR.

---

## 3. Known issues / next tasks

### 3.1 🔴 Deleting a teacher orphans their login
`depthead/manage.php` (~line 320) runs `DELETE FROM teacher …` and never touches
`user_account`. **Already happened:** teacher *Joehynee Sumandal* was deleted but
login **`jsumandal`** still exists (role `teacher`, Active). They can authenticate,
then get bounced with *"not linked to a teacher record."*

**Fix:** replace hard-delete with Deactivate (now that `depthead/teachers.php` exists),
or delete the `user_account` row alongside. Then clean up `jsumandal`.

### 3.2 🟠 The `N/A` placeholder teacher holds **58 real classes**
Not cosmetic — 58 active-S.Y. classes are attributed to a fake teacher record
(`Fullname = 'N/A'`, username `n`). Also 4 teachers have their licence suffix stored
as the SURNAME (`Lastname = 'LPT'` → usernames `flpt`, `nlpt`, `slpt`, `hlpt`), and
placeholders "Asatidz A–E" → `aa`…`ae`. Fix the `teacher` name fields, reassign the
58 classes, then re-issue those logins.

### 3.3 🟠 `cashier/soa.php` duplicates the SOA slip renderer
It mirrors `includes/soa_slip.php` and **has already drifted** — the cashier's copy
is missing the promissory-note warning the student's copy has. Collapse into one
shared partial. (The certificate module deliberately uses ONE renderer,
`includes/certificate_render.php`, to avoid repeating this.)

### 3.4 🟡 `class_schedule` is empty → no real weekly schedule
`classes.Time` is free text (`"3:00-4:00 PM (TUESDAY)"`). `teacher/schedule.php`
falls back to it. Build a Registrar screen to enter day/start/end/room per class to
light up the weekly grid, room display and conflict detection.

### 3.5 🟡 Security items still open
- **4 of 5 `enrollment_users` passwords are still PLAIN TEXT**
  (`admission`, `enrollment`, `registrar`, `admin`). `cashier` is now bcrypt.
  **Fix path exists:** Super Admin → Account Management → reset the password.
  `auth.php` tries `password_verify()` first, so a reset silently migrates the
  account to bcrypt with no code change. Once all 5 are hashed, delete the
  `hash_equals()` plaintext fallback in `includes/auth.php`.
- No session-cookie hardening (HttpOnly/Secure/SameSite).
- `display_errors = 1` hardcoded in `cashier/account_setup.php` and `cashier/history.php`.
- **No `.htaccess` anywhere** — see §4.

### 3.6 🟡 `announcements` table contains spam + XSS payloads
8 rows posted by `Anonymous540` / `Anonymous966` etc., including an injected
`<iframe>` to `mbn1.wapka.co` and a CSS-expression payload. **No PHP file in this
codebase inserts into `announcements`** — they came from an endpoint since removed.
They render **inert** (strip_tags + `h()`, verified), but the teacher dashboard's
bulletin board is showing junk. Clear them and add a real composer for Dept
Heads/Admin.

### 3.7 🟡 Two credential paths for staff
`depthead/users.php` (create/delete) still has its own password reset using default
`12345`, separate from the new `depthead/accounts.php`. Strip the duplicate logic
from `users.php` so credentials have one path — same drift risk as §3.3.

### 3.8 Open questions
- Add a **Principal sign-off** after Dept Head approval? (currently Dept Head → Registrar locks)
- Weighted subject averages? `subject` has **no units/credits column**, so grade-slip
  and certificate averages are a simple mean.
- Review the ~115 duplicate-name preregistration records flagged earlier.

---

## 4. ⛔ NEVER upload these (web root is publicly served, no .htaccess)

```
credentials.txt              ← every login in plain text
credentials                  ← empty stray file; just delete
enrollment_db.sql            ← 8.4 MB FULL DB DUMP (all PII + plaintext passwords)
enrollment.zip               ← 2.6 MB full source archive
*.sql in the repo root
config/online_database.php   ← LIVE DB password in plain text; unused by any code
```
Proven: `curl http://localhost/enrollment/credentials.txt` → **HTTP 200**.

**Best first task next session:** add a root `.htaccess` denying `.txt`/`.sql`/`.zip`
and `config/`, plus blocking script execution in `uploads/`. Closes the whole class
of problem in one file.

> Note: `verify.php` **must stay public** (it is the certificate QR target). It is
> safe by design — it requires the certificate number *and* a secret token, and
> reveals only what is already printed on the certificate.

---

## 5. Passwords changed during development (originals unrecoverable — bcrypt)

| Account | Now | Note |
|---|---|---|
| `admin1` (super admin) | `Adm1n!2345` | **change it** |
| `jhs` (dept head) | `Dept1234!` | **change it** |
| `registrar` (user_account) | `Reg1234!` | **change it** |
| **`cashier`** (enrollment_users) | **`Cashier2026!`** | was `12345`; **now bcrypt** — this one is an improvement, keep it |
| `fhsalik`, `jmacmod` (teachers) | `teacher` + forced change | reset to default |

`credentials.txt` §3 still lists `cashier / 12345` — **update it**.
Teachers `mabduljalal` (#101) and `utimosa` (#102) are **Inactive** (you deactivated
them while testing). Reactivate via Dept Head → Teacher Management → filter Inactive.

---

## 6. Gotchas learned the hard way

1. **`catch (Throwable)` around page logic disguises fatals as validation errors.**
   A missing constant surfaced as a red "wrong password"-looking flash. If a form
   rejects valid input for no reason, read the flash text carefully.
2. **Constants/functions must live in the file that shares them.**
   `TEACHER_DEFAULT_PW` lives in `teacher_auth.php`; `teacher_service.php` explicitly
   requires `soa_service.php` for `soa_audit()`.
3. **Never join `enrollment` to profile tables via `CAST()`** — unindexable; cost 4.8s
   per keystroke. Search `preregistration`/`old_studentprofile` directly and UNION.
4. **Hiding a record from a dropdown can silently corrupt edits.** Class edit forms do
   `setValue(teacher_id)`; if that option is gone, saving reassigns the class. Inactive
   teachers who still hold a class stay in the picker, flagged `⚠ INACTIVE`.
5. **`RESTRICT` turns silent cascades into loud failures — that's the point.**
6. **Guard EVERY query against a not-yet-migrated table.** mysqli runs in exception
   mode, so one unguarded `SELECT` from a missing table = **HTTP 500** before the
   friendly "run the migration" notice can render. This caused the live
   `cashier/back_accounts.php` 500 (fixed 2026-07-20).
7. **MariaDB rejects an aggregate ALIAS in `ORDER BY`** ("reference to group
   function") — repeat the expression instead.
8. **Never `strtoupper()` a person's name.** "MAEd" → "MAED" and "H.Salik" →
   "H.SALIK" are wrong on a formal certificate.
9. **Count `bind_param` types against placeholders.** A 17-placeholder INSERT with a
   13-char type string fails at runtime, not at lint.
10. **`information_schema.TABLE_ROWS` is an ESTIMATE for InnoDB.** Use `COUNT(*)`
    before trusting a row count (it showed 15,124 when the real number was 15,447).
11. Column names that bite: `soa_master.soa_number` (not `soa_no`); `soa_details` has
    no `amount`; settings table is **`system_setting`** (not `soa_settings`);
    `soa_audit()` writes to **`financial_audit_logs`**; `user_account.role` is an enum.

---

## 7. Suggested order next session

1. **`.htaccess`** (§4) — ~5 minutes, closes the credential-exposure class.
2. Reset the 4 remaining plaintext logins via Account Management (§3.5) — fixes the
   #1 security finding with no code change.
3. Fix the teacher-delete orphan (§3.1) and clean up `jsumandal`.
4. Fix the `N/A` teacher holding 58 classes (§3.2).
5. Clear the spam announcements + add a composer (§3.6).
6. Then go live: §2 in order.
7. Optional cleanup: unify the SOA renderers (§3.3), unify credential paths (§3.7).
