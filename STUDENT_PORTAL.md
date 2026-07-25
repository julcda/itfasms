# Student Portal — Documentation

A self-service portal that lets **officially-enrolled** students of the **active
school year** view their profile, Statement of Account, manage their account,
and (soon) view grades.

---

## 1. Database changes

Two new tables (migration: [`migrations/student_portal.sql`](migrations/student_portal.sql), idempotent, already applied).

### `student_portal_accounts` — login accounts
| Field | Type | Notes |
|---|---|---|
| `id` | int PK | |
| `enrollment_id` | int **UNIQUE**, FK→`enrollment.id` | one account per enrolled student |
| `student_id` | varchar | mirror of `enrollment.student_id` |
| `lrn` | varchar, indexed | **login username** |
| `password_hash` | varchar | bcrypt (`password_hash`) |
| `must_change_password` | tinyint, default **1** | 1 = still on the default password |
| `status` | enum(`Active`,`Inactive`), default Active | a way to disable a student |
| `last_login` | datetime | stamped on each successful login |
| `created_at` / `updated_at` | timestamp | |

> Named `student_portal_accounts` (not `student_accounts`) to avoid colliding
> with the pre-existing, unrelated `student_account` financial table.

### `student_grades` — placeholder for the future Grades module
`id, enrollment_id (FK), school_year_id (FK), subject, component, q1..q4,
final_grade, remarks, created_at, updated_at`. **No rows yet** — the page shows
"under construction".

No existing tables were modified.

---

## 2. Authentication flow

Students use a **separate session namespace** (`$_SESSION['student_user']`) so the
staff role helpers (`is_super_admin`, …) can never match a student. All logic
lives in [`includes/student_auth.php`](includes/student_auth.php).

```
Login (LRN + password)                       student/login.php
   │
   ├─ student_active_sy()         → active school year (schoolyear.Status = 1)
   ├─ student_resolve_by_lrn()    → find an *Officially Enrolled* student in the
   │                                active SY by LRN. New→preregistration(by id),
   │                                else Old→old_studentprofile(by student_id).
   │     └─ not found?  student_lrn_exists() distinguishes:
   │            • exists nowhere      → "LRN not found"
   │            • exists, not enrolled → "not officially enrolled for S.Y. …"
   ├─ account = student_get_account() OR student_provision_account()
   │            (lazy: first login creates the row, password = "password",
   │             hashed, must_change_password = 1)
   ├─ status must be Active            → else "account inactive"
   ├─ password_verify()               → else "Invalid password"
   └─ success → session_regenerate_id, set $_SESSION['student_user'],
                stamp last_login.
```

### Forced first-login password change
Every protected page calls `require_student_login()`, which re-reads the live
account row and, while `must_change_password = 1`, **redirects to
`student/change_password.php`** — so no module is reachable on the default
password. The change page calls `require_student_login(false)` to avoid a
redirect loop. After a successful change the flag is cleared.

### Session & protection
- `require_student_login()` — guards every page; logs out + redirects if the
  account is missing/Inactive.
- `student_logout()` — clears the namespace (`student/logout.php`).
- CSRF tokens on every POST (`csrf_token()` / `verify_csrf_token()`), reusing
  the project helpers.

### Default credentials
- **Username:** LRN · **Password:** `password` (constant `STUDENT_DEFAULT_PW`).
- Stored only as a bcrypt hash; replaced on first login.

---

## 3. Portal structure

```
student/
  login.php            LRN + password sign-in
  logout.php           ends the student session
  change_password.php  forced first-login password reset
  index.php            Dashboard — identity + info cards + account summary
  soa.php              Statement of Account (full breakdown + payment history)
  soa_print.php        Prints the EXACT official SOA slip (shared renderer)
  _soa_data.php        shared SOA data builder (on-screen breakdown + history)
  account.php          Account Management (contact, email, photo, password)
  grades.php           "Under construction" placeholder
  sidebar.php          shared navigation shell
includes/student_auth.php   auth + session + data helpers
uploads/student_photos/     student-uploaded profile photos ({enrollment_id}.ext)
```

**Navigation:** Dashboard · Statement of Account · Account Management ·
Grades *(Soon)* · Logout.

### Financial data source
The SOA reuses the existing Phase-2 ledger — no new financial logic:
- Totals from `student_assessment` (net / paid / balance).
- Enrollment-fee lines from `assessment_charge` (non-installment).
- Monthly split via `soa_components_for()` (`includes/soa_service.php`).
- Payment history from `payment_transaction` + `receipt_master` (Posted only),
  with a running balance computed oldest-first.
- **Payment status:** Fully Paid / Partially Paid / Unpaid (derived from
  balance vs paid), plus "No Assessment" when none exists.

### Profile / photo
`student_profile()` returns a live New/Old-merged profile (name, LRN, grade,
section, contact, email, classification). `student_photo_url()` prefers a
portal-uploaded photo (`uploads/student_photos/{enrollment_id}.{jpg|png|webp}`),
then a legacy `preregistration.photo` if the file exists, else the UI shows an
initials avatar. Contact/email edits write back to the correct profile table
(`preregistration` for New, `old_studentprofile` for Old).

### Official SOA slip (exact replica, cashier-gated)
`student/soa_print.php` prints the **exact same official document a cashier
issues**, via the shared renderer `includes/soa_slip.php`
(`soa_render_print_page()`) — the 7-column school form (Charges / Amount Paid /
Balance / Date / OR No. / Account Title / Breakdown), "NO PERMIT NO EXAM",
SOA number + barcode, and Bookkeeper/Cashier signatories.

**The portal never generates an SOA itself.** It only renders the latest
`soa_master` the **cashier** already generated for that student. If none exists,
the "Print Official SOA" button is replaced with *"Official SOA not available
yet"* and `soa_print.php` redirects back with a notice. `_soa_data.php` exposes
`$soa['officialSoaId']` (0 = none yet) which drives this. The on-screen `soa.php`
still shows the breakdown **and the payment-history table** regardless, so the
student always sees their up-to-date account; only the printable official copy
waits on the cashier.

> `includes/soa_slip.php` is shared with the cashier hub — keep it in sync with
> the inline renderer in `cashier/soa.php` if the slip layout ever changes.
>
> **PDF:** no server-side PDF library is bundled; the print view's *Save as PDF*
> (browser) produces the PDF. Swap in a PDF lib over `soa_slip.php` later if
> server-generated files are needed.

---

## 4. Future extension points — Grades module

1. **Data is ready:** populate `student_grades` (one row per subject:
   `q1..q4`, `final_grade`, `remarks`, tagged by `enrollment_id` +
   `school_year_id`) from the academic/registrar module.
2. **Render:** replace the placeholder in [`student/grades.php`](student/grades.php)
   with quarterly tables — query `student_grades` by the session's
   `enrollment_id` and active SY, group by `component` (Academic / Madrasah),
   show Q1–Q4 + final + remarks, and a GPA/average summary.
3. **Nav:** drop the `true` (Soon badge) argument on the Grades `_st_nav(...)`
   call in [`student/sidebar.php`](student/sidebar.php) once live.
4. **Optional print:** clone `soa_print.php`'s print pattern for a Report Card.

---

## 5. Operational notes

- **Provisioning is lazy** — accounts are created on first login, so there is no
  bulk migration to run; new enrollees can log in immediately.
- **LRN duplicates:** real 12-digit LRNs are unique; junk/test values are not.
  The resolver picks the most recent matching enrollment (`ORDER BY e.id DESC`)
  and is always scoped to the active SY + `Officially Enrolled`.
- **Disable a student:** set `student_portal_accounts.status = 'Inactive'`.
- **Reset a student to the default password:** set `password_hash` to a hash of
  `password` and `must_change_password = 1` (or just delete the row — it
  re-provisions on next login).
