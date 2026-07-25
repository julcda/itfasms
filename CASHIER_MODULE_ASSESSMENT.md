# ITFA Cashier Module — Technical & Architectural Assessment

**Prepared for:** Pre-upgrade modernization of the IBN TAIMIYAH FOUNDATION ACADEMY enrollment/cashier system
**Scope:** `cashier/` module, payment data model, and surrounding architecture
**Basis:** Direct review of source code (`cashier/*.php`, `includes/functions.php`, `config/database.php`), SQL schema (`enrollment_db.sql`, `cashier_schema_update.sql`, `cashier_fees_update.sql`), and live data samples.
**Date:** 2026-06-19

---

## A. Executive Summary

The cashier module is a **functional but architecturally fragile** procedural-PHP application. It successfully collects enrollment-day fees, generates printable A5 official receipts, supports a separate monthly-installment ledger, and produces consolidated collection analytics. The UI is modern (Tailwind), and — importantly — **all queries use prepared statements** and CSRF tokens are enforced on state-changing POSTs.

However, the system carries serious risks for a financial module:

| Theme | Severity | Summary |
|---|---|---|
| **No database transactions** | 🔴 Critical | Every multi-step money operation (record payment → flip status; create account → generate schedule; collect month → recompute balance) runs as independent autocommit statements. A mid-sequence failure leaves the database inconsistent. |
| **No referential integrity** | 🔴 Critical | Tables are joined by convention only. There are **no foreign keys** anywhere, no `ON DELETE`/`ON UPDATE` rules. Orphaned payments and dangling `enrollment_id` references are possible and unguarded. |
| **Two parallel, disconnected payment ledgers** | 🔴 Critical | `enrollment_payment` (legacy, ~13k rows) and `backaccount_payment_records` (BAPR, the table the new module writes to) coexist. They are never reconciled. `student_account`/`monthly_payment` is a *third* model that most students never get. There is no single source of truth for "what a student owes / has paid." |
| **OR numbers derived from auto-increment** | 🟠 High | OR numbers are `ITFA-{year}-{padded id}`. They are predictable, not gap-free (deleted/failed rows leave holes), reset conceptually per year but the counter is global, and there is a window where a record can keep the placeholder `ITFA-YYYY-000000`. |
| **No duplicate-payment / double-submit protection** | 🟠 High | No unique constraint or transactional lock prevents a double-click (or concurrent request) from creating two BAPR rows + two OR numbers for the same enrollment. |
| **No refund / void / reversal module** | 🟠 High | The only correction path is a free-form **Edit** gated by a single hardcoded password (`TAIMIYANS`) embedded in both server code and client JS. No audit trail of who changed what. |
| **Runtime DDL migrations** | 🟠 High | Pages run `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` on every load. The financial schema mutates itself at request time. |
| **Fragmented / hardcoded fee definitions** | 🟡 Medium | Fee amounts (490/420/250 activity & house fees, activity sub-items) are duplicated across `payment_breakdown`, `fee_schedule`, SQL seeds, PHP, and JavaScript — at least 3–4 sources of truth. |
| **Historical-data analytics gap** | 🟡 Medium | Consolidation reports sum the new `fee_*` columns, which are `0`/`NULL` on the ~legacy BAPR rows, silently undercounting older periods. |

**Bottom line:** the module is safe enough to keep running short-term, but it is **not** built for financial-grade integrity, audit, or the multi-module ERP growth described in the brief. The recommended path is a **ledger-centric re-architecture** (assessment → charges → payments → allocations → official-receipt register) layered *on top of* the existing tables, migrated incrementally, before bolting on portals, gateways, accounting, payroll, etc.

---

## B. Current System Analysis

### B.1 Application architecture

- **Style:** Procedural PHP 8 (uses `declare(strict_types=1)`, `match`, enums of behavior via functions). **No framework, no router, no MVC, no ORM, no autoloader.** Each `.php` file is a self-contained controller **and** view: it does auth → reads `$_GET/$_POST` → runs SQL → emits HTML.
- **DB access:** `config/database.php` exposes a single `db(): mysqli` singleton. `mysqli_report(MYSQLI_REPORT_ERROR | STRICT)` is on (exceptions on SQL error). Connection params come from env vars with localhost/`root`/no-password defaults.
- **Shared library:** `includes/functions.php` provides: session bootstrap, `h()` escaping, CSRF (`csrf_token`/`verify_csrf_token` with `hash_equals`), flash messages, role predicates, `bind_dynamic_params()`, and hand-rolled `stmt_fetch_all_assoc()` (binds result metadata to fetch associative rows — a workaround for not using `mysqlnd`'s `get_result()`).
- **Auth model:** session-based. Login populates `$_SESSION['auth_user']` with `role`. Role strings are checked by predicates: `is_cashier_user`, `is_registrar_user`, `is_enrollment_user`, `is_depthead_user` (role `user`), `is_depthead_admin` (role `admin`), `is_super_admin`. **Two separate user tables** back these (`enrollment_users`, `user_account`) — a sign of organic accretion.
- **Every cashier page** begins with the same guard: `require_login()` → `is_cashier_user()` or redirect. There is **no central middleware**; the check is copy-pasted into each file.

### B.2 The cashier workflow (as built)

```
┌──────────────┐   Status =                ┌─────────────────────┐
│ Enrollment   │ 'For Cashier Payment'     │  cashier/index.php   │
│   module     │ ────────────────────────► │   Payment Queue      │
└──────────────┘                           └──────────┬──────────┘
                                                       │ open modal, edit fee breakdown
                                                       │ (admission/activity/books/house_reg)
                                                       │ choose Cash|Installment, key amount
                                                       ▼
                                  ┌────────────────────────────────────────────┐
                                  │ POST process_payment (NO TRANSACTION):      │
                                  │ 1. INSERT backaccount_payment_records        │
                                  │    (or_number = placeholder 'ITFA-YYYY-000000')│
                                  │ 2. UPDATE …SET or_number='ITFA-YYYY-{id}'    │
                                  │ 3. UPDATE enrollment SET Status=             │
                                  │    'For Registrar Confirmation'              │
                                  │ 4. $_SESSION['receipt_data'] = …             │
                                  └───────────────────┬──────────────────────────┘
                                                      ▼
                                  ┌──────────────────────────────┐
                                  │ cashier/receipt.php          │  → auto window.print() (A5)
                                  │ (fresh = session,            │     CODE128 barcode of OR no.
                                  │  reprint = ?reprint=id → DUP)│
                                  └──────────────────────────────┘

   Parallel / separate installment track (mostly unused on real data):
   account_setup.php ──creates──► student_account + 10× monthly_payment
   monthly_payments.php ──collects──► updates monthly_payment + recomputes student_account
   monthly_receipt.php ──prints──► OR series 'ITFA-MP-YYYY-{mp_id}'

   Cross-cutting:
   history.php       → list/filter/paginate BAPR; edit (password 'TAIMIYANS')
   reprint_or.php    → searchable OR reprint index
   consolidation.php → analytics by house/department/classification + daily trend
   fee_schedule.php  → printable reference (self-seeds fee_schedule table)
```

### B.3 Payment-mode handling

The module recognizes **only two methods**, validated against a whitelist (`['Cash','Installment']`); anything else silently coerces to `Cash`.

| Mode | What actually happens | Tables touched |
|---|---|---|
| **Cash (full)** | Single BAPR row with `payment_method='Cash'`; cashier types the amount received; no balance tracking. | `backaccount_payment_records` |
| **Installment (at enrollment queue)** | `index.php` records a **single BAPR row** tagged `Installment` — it does **not** create a `student_account`. So "Installment" here just labels the down-payment. | `backaccount_payment_records` |
| **Installment (account_setup track)** | Separate manual flow: creates `student_account` + a 10-month `monthly_payment` schedule starting August; collection happens in `monthly_payments.php`. | `student_account`, `monthly_payment` |
| **Scholarship / Discount / Subsidy** | **Not a payment mode.** Encoded upstream in `payment_breakdown` via `classification_id` + `rate` (e.g., Pandarat = full scholarship, ESC grantee, 2nd/3rd child discounts). The cashier sees pre-discounted amounts; nothing is recorded as "discount granted." | `payment_breakdown` (read-only) |
| **Bank transfer / GCash / Maya / gateway / QR** | **None exist.** | — |

**Business rules embedded in code:**
- Activity fee defaults: JHS = ₱490 (includes ₱70 Madrasah/Tarbiyah), Elementary & SHS = ₱420. House registration = ₱250 for all except Pandarat. These constants live in **JS** (`index.php`), **PHP** (`receipt.php`), **SQL** (`cashier_fees_update.sql`), and **`fee_schedule` seeds**.
- Elementary students additionally pay a book down-payment; the remainder is "spread over 10 months" (conceptually — only realized if a `student_account` is created).
- Discounts are applied by selecting the right `payment_breakdown` row (`classification_id` + `type` New/Old + `status='Active'`).

### B.4 Reporting

- **`history.php`** — paginated transaction list with today/month/SY/grand-total cards. (Note: the four stat cards are duplicated in the markup — a copy-paste bug rendering two "This Month" / "School Year" blocks.)
- **`consolidation.php`** — grand totals by fee category, and breakdowns by **house**, **department**, and **classification**, plus a 30-day daily trend (Chart.js). Sums the per-fee columns on BAPR.
- **`fee_schedule.php`** — printable official fee reference; self-creates and seeds the `fee_schedule` table if empty.

---

## C. Database Relationship Map

> ⚠️ All relationships below are **logical/by-convention**. The schema declares **no foreign keys**; primary keys are added via late `ALTER TABLE … ADD PRIMARY KEY` at the end of the dump. Joins frequently bridge type mismatches with `CAST(... AS CHAR)` (e.g., `enrollment.student_id` varchar ↔ `preregistration.id` int).

```
                         schoolyear (School_year_id, School_year[label], Status)
                              ▲ matched by LABEL string, not id
                              │
  preregistration (id) ──┐    │
   (NEW students)        │    │   old_studentprofile (student_id) ── (OLD students)
                         │    │        │
                         ▼    │        ▼
                 ┌─────────────────────────────────┐
                 │ enrollment (id PK)               │
                 │  student_id varchar  ────────────┼─→ resolves to prereg.id OR osp.student_id
                 │  school_year varchar(label)      │
                 │  Department / Department_gradelevel(int) / Department_section(varchar)
                 │  Student_classification (int) ───┼─→ payment_breakdown.classification_id
                 │  house_id (added ad hoc) ────────┼─→ house.id
                 │  Status (workflow string)        │
                 └───────┬───────────────┬──────────┘
                         │               │
   gradelevel(Gradelevel_id)   section(Section_id)   payment_breakdown
        ▲ (int join)            ▲ (CAST join)          (classification_id, type, rate,
        │                       │                       tuition, School_improvement,
        │                       │                       Cash, Installment, Enrollment,
        │                       │                       + activity_fee, house_registration [runtime])
        │                       │
   ┌────┴───────────────────────┴─────────────────────────────────────────────┐
   │ backaccount_payment_records (BAPR) — the de-facto enrollment-fee ledger    │
   │  id PK | student_id varchar | name | payment_amount | payment_date         │
   │  [runtime-added] or_number, payment_method, school_year, cashier_name,     │
   │                  enrollment_id ─→ enrollment.id (no FK),                    │
   │                  fee_admission, fee_activity, fee_books, fee_house_reg      │
   └───────────────────────────────────────────────────────────────────────────┘

   ── Installment sub-system (properly shaped, but largely unused) ──
   student_account (id PK, UNIQUE enrollment_id, total_fee, total_paid, balance, status)
        │ 1
        │ N
   monthly_payment (id PK, student_account_id, enrollment_id, student_id,
                    month_label, due_date, amount_due, amount_paid, or_number, status)

   ── Legacy, abandoned by the new module ──
   enrollment_payment (payment_id PK, student_id, payment_method,
                       tuition, school_improvement, enrollment_fee,
                       book_payment, total_payment)   ← ~13k historical rows, never written today

   ── Reference / fee sources ──
   fee_schedule (department/level/student_type → fee columns)   ← parallel to payment_breakdown
   payment_book (Gradelevel → Book_Cost, Elementary)
   house (id, housename)
   studentinfo (synced from enrollment via resolve_studentinfo_id_for_enrollment())
```

### Idealized target chain (what the brief asks for, not what exists)

```
Student → Enrollment → Assessment → Charge lines → Payment → Allocation → Official Receipt → Ledger
```
Today only **Student → Enrollment → (flat) Payment row** exists. There is **no Assessment, no Charge-line, no Allocation, no Ledger, no OR register** entity.

---

## D. Identified Issues

### D.1 Data-integrity & transactional
1. **No transactions.** `index.php` (INSERT BAPR → UPDATE OR → UPDATE enrollment), `account_setup.php` (INSERT account → loop INSERT 10 schedule rows), and `monthly_payments.php` (UPDATE month → SUM → UPDATE account) all run in autocommit. Any failure mid-way → inconsistent financial state. **There is not a single `begin_transaction()` in the module.**
2. **No foreign keys.** Deleting an enrollment orphans its BAPR rows and student_account silently; `enrollment_id` may point nowhere.
3. **Double-submit / duplicate payments.** The POST guard relies on `enrollment.Status='For Cashier Payment'`; but with no transaction + row lock and no unique constraint on `BAPR.enrollment_id`, two near-simultaneous submits can both pass the check and create two payments + two ORs.
4. **OR-number scheme is unsafe for finance:** derived from `AUTO_INCREMENT` ⇒ predictable, gappy (rolled-back/edited rows), not per-year-sequential, and the INSERT-then-UPDATE leaves a placeholder window. `history.php` edit lets a user overwrite an OR number freely.
5. **Three competing balance models:** flat BAPR (no balance), `student_account.balance`, and legacy `enrollment_payment.total_payment`. No reconciliation → "true balance" is unknowable from one query.
6. **`payment_amount` is decoupled from the fee breakdown.** Cashier can key any number independent of `fee_admission+activity+books+house_reg`. Receipt shows both; nothing enforces equality or records the variance as a partial balance.

### D.2 Security & access control
7. **Hardcoded shared edit password** `'TAIMIYANS'` appears in `history.php` server code **and** client JS (`GATE_PASSWORD`). The JS gate is cosmetic; the server check uses the same constant. No per-user authorization, no record of who edited.
8. **No audit trail.** Edits to amounts, OR numbers, dates, and cashier name are destructive in place. For a cashier system this is a compliance red flag.
9. **`display_errors = 1`** is forced on in `history.php`, `account_setup.php`, `monthly_payments.php` — leaks paths/SQL in production.
10. **Plaintext default password `'12345'`** written by `resolve_studentinfo_id_for_enrollment()` when auto-creating `studentinfo`.
11. **Session not regenerated on login** (fixation risk); role authorization is string-compare with two user tables (privilege-confusion surface).
12. **CDN dependencies without SRI** (Tailwind, Chart.js, JsBarcode) — availability and supply-chain exposure.
   *(Positive: SQL is consistently parameterized; CSRF is enforced on POSTs.)*

### D.3 Schema design & normalization
13. **Runtime DDL** (`ALTER TABLE … ADD COLUMN IF NOT EXISTS`) on page load — schema drift, race conditions, and per-request overhead.
14. **Denormalized school-year label** (`varchar`) duplicated on BAPR, student_account, monthly_payment, enrollment instead of `School_year_id`. Matching is string-based.
15. **New/Old student type is *computed*** by probing `preregistration` per row (a correlated subquery in `consolidation.php`) instead of stored.
16. **Fee duplication:** `payment_breakdown` (classification-based, used by the app) vs `fee_schedule` (department/level-based reference) hold overlapping fee data; activity/house constants are also hardcoded in PHP and JS. ≥3 sources of truth.
17. **Type-mismatch joins everywhere** (`CAST(p.id AS CHAR)`, `CAST(sc.Section_id AS CHAR)`), preventing index use and inviting subtle mismatches.
18. **`name` denormalized onto BAPR** — receipts and history show a frozen name snapshot that can diverge from the profile.

### D.4 Reporting & correctness
19. **Legacy BAPR rows undercount in consolidation:** pre-migration rows have `fee_* = 0/DEFAULT` and `payment_method = NULL`, so category/method breakdowns silently exclude them while grand totals include them.
20. **Duplicated stat-card markup** in `history.php` (renders two extra cards) — cosmetic but indicates untested copy-paste.
21. **No date/period close.** Daily collection can be edited after the fact with the shared password; no "Z-reading"/end-of-day lock.

### D.5 Performance & scalability
22. `stmt_fetch_all_assoc()` materializes whole result sets into PHP arrays (no streaming, no server-side aggregation for large lists).
23. `LIKE '%term%'` searches and per-row correlated subqueries won't scale past tens of thousands of rows; type-cast joins defeat indexes.

---

## E. Recommended Database Architecture

A ledger-centric model. Introduce these **alongside** existing tables; migrate gradually (Section H).

### E.1 Core financial spine (new)

```
school_year (id PK, label, is_active, starts_on)                 ← single source; FK target everywhere

fee_item (id PK, code, name, category[tuition|misc|activity|books|house|other], is_active)

fee_schedule (id PK, school_year_id FK, department, level, student_type,
              classification_id, fee_item_id FK, amount, cadence[annual|monthly|once],
              UNIQUE(school_year_id, classification_id, level, student_type, fee_item_id))

assessment (id PK, enrollment_id FK, school_year_id FK, student_id,
            payment_plan[cash|installment], installment_months,
            total_assessed, total_discount, net_assessed,
            total_paid, balance, status, created_by, created_at)
   └─ assessment_line (id PK, assessment_id FK, fee_item_id FK,
                       description, amount, discount_amount, net_amount, due_date)

payment (id PK, assessment_id FK, or_number UNIQUE, method[cash|gcash|maya|bank|voucher],
         reference_no, amount, tendered, change_amount,
         received_by, paid_at, status[posted|voided], voided_by, voided_at, void_reason)
   └─ payment_allocation (id PK, payment_id FK, assessment_line_id FK, amount)
        ← maps each peso of a payment to the charge line it settles (handles partials cleanly)

official_receipt (id PK, or_number UNIQUE, payment_id FK, series, sequence,
                  reprint_count, issued_by, issued_at)          ← gap-free OR register

ledger_entry (id PK, assessment_id FK, entry_type[charge|payment|discount|void|adjustment],
              ref_table, ref_id, debit, credit, running_balance, posted_at, posted_by)
                                                                ← immutable append-only audit/ledger

audit_log (id PK, actor_user_id, action, entity, entity_id, before_json, after_json, ip, at)
```

### E.2 Key principles
- **Foreign keys with explicit `ON DELETE RESTRICT`** for all financial links.
- **OR numbers from a dedicated sequence table** (`or_series`) inside the same transaction, gap-free and per-series — never from `AUTO_INCREMENT`.
- **Every money mutation wrapped in `START TRANSACTION … COMMIT`** with `SELECT … FOR UPDATE` on the assessment row.
- **Immutability:** corrections are new `ledger_entry`/`payment(status=voided)` rows, never in-place edits.
- **Normalized `school_year_id`** FK everywhere; drop label matching.
- **Store student_type and classification** on the assessment at creation time (snapshot), so reports don't recompute.
- Keep `backaccount_payment_records` and `enrollment_payment` as **read-only archives**; backfill them into `payment`/`ledger_entry` during migration.

---

## F. Recommended Cashier System Upgrade

| Capability | Description |
|---|---|
| **Student Account Ledger** | One canonical `assessment` per enrollment with line-level charges, real-time `balance`, and a payment timeline. Replaces the three competing balance models. |
| **Real-time balance monitoring** | Cashier sees assessed / paid / balance live; partial enrollment-day payments carry a true remaining balance. |
| **Payment history timeline** | Per-student chronological view across all methods and school years, sourced from `ledger_entry`. |
| **Automated OR generation** | Transactional, gap-free, per-series OR numbers from `or_series`; `official_receipt` register tracks reprints. |
| **Payment reversal / void module** | First-class void with reason + authorization role + audit, replacing the shared-password edit. Adjustments post compensating ledger entries. |
| **Discounts/scholarships as data** | Record discount per line (`discount_amount`) and reason/scholarship code, so subsidies are reportable (DepEd ESC, vouchers, internal grants). |
| **Multi-method payments** | `payment.method` enum + `reference_no` to support cash, GCash, Maya, bank, voucher, with per-method daily breakdown. |
| **Collection dashboard / cashier analytics** | Keep consolidation.php's strengths but source from the ledger so legacy rows are included; add per-cashier Z-reading and end-of-day close/lock. |
| **End-of-day close** | Locks the day's transactions, prints a Z-report; post-close changes require an adjustment entry, not an edit. |
| **Role-based authorization** | Distinct permissions: collect, reprint, void, edit-fee-schedule, close-day, view-reports. |

---

## G. Future-Ready Architecture Blueprint

To support Accounting, Payroll, HRIS, Inventory, LMS, and SIS over the next decade:

1. **Extract a service layer.** Move SQL out of page files into namespaced repository/service classes (`Cashier\PaymentService`, `Cashier\AssessmentRepository`) behind an autoloader (Composer/PSR-4). Pages become thin controllers.
2. **Introduce a real framework incrementally** (Laravel or Slim + Eloquent/Doctrine) — front controller, routing, middleware (auth/role/CSRF), migrations replacing runtime DDL, and a DI container. Strangler-fig pattern: route new modules through the framework while legacy pages keep working.
3. **Single identity & RBAC service.** Unify `enrollment_users` + `user_account` into one `users` + `roles` + `permissions` model; one login, one authorization layer all modules consume.
4. **Domain modularization (bounded contexts):** `SIS` (students, enrollment, sections, grades), `Finance` (assessment, payments, OR, ledger — feeds Accounting), `HR/Payroll`, `Inventory`, `LMS`. Each owns its tables; cross-context references go through IDs/services, not ad-hoc joins.
5. **General Ledger as the integration backbone.** The cashier `ledger_entry` rolls up into a double-entry GL that Accounting and Payroll also post to — the natural seam for the future Accounting System.
6. **Integration & notification layer.** A queue/event bus (or even DB-backed jobs) for SMS, email receipts, and payment-gateway webhooks (GCash/Maya/bank), plus a public API for student/parent portals.
7. **Reporting/analytics store.** Read-replica or summary tables for dashboards; AI/forecasting (collection projections, delinquency risk) reads from the ledger, never the OLTP write path.
8. **Cross-cutting:** centralized config (no localhost/root defaults in prod), structured logging, automated tests around money math, CI, and least-privilege DB accounts.

---

## H. Migration Strategy (zero data loss)

**Phase 0 — Safety net.** Full backup + restore drill. Put `enrollment_db.sql` under version control. Stand up a staging clone. Freeze runtime DDL by applying all `ADD COLUMN` migrations once, as reviewed migration files.

**Phase 1 — Stabilize the current module (no schema redesign yet).**
- Wrap the three multi-step operations in transactions (`index.php`, `account_setup.php`, `monthly_payments.php`).
- Add `UNIQUE`/idempotency guard against double-submit (e.g., unique `(enrollment_id, school_year)` on the enrollment-fee payment, or a transactional status flip with `FOR UPDATE`).
- Move the edit password out of code; turn `display_errors` off in prod; add an `audit_log` capturing every edit (before/after).
- Fix the duplicated stat cards and the legacy-row undercount (treat `fee_* IS NULL/0` rows explicitly in reports).

**Phase 2 — Introduce the ledger schema (additive).**
- Create `school_year` FK column, `fee_item`, `assessment(_line)`, `payment(_allocation)`, `official_receipt`, `or_series`, `ledger_entry`, `audit_log`. Add FKs. No writes from the app yet.

**Phase 3 — Backfill.**
- Script-migrate `backaccount_payment_records` and `enrollment_payment` into `payment` + `ledger_entry`, deriving `assessment` rows from `enrollment` + `payment_breakdown`. Reconcile totals against the old tables; produce a variance report. Keep originals read-only.

**Phase 4 — Cut over writes.**
- Point the cashier module at the new services behind a feature flag, one screen at a time (collection → receipt → installments → history → consolidation). Dual-read during verification; compare new ledger totals to legacy nightly.

**Phase 5 — Decommission & extend.**
- Once parity holds for a full collection cycle, retire direct writes to the legacy tables. Then layer on void/refund, end-of-day close, multi-method/gateway, and portals/notifications per Section G.

**Rule throughout:** never delete or in-place-overwrite historical financial rows; corrections are always new, attributable entries.

---

### Appendix — Files reviewed
`config/database.php`, `includes/functions.php`, `cashier/index.php`, `cashier/receipt.php`, `cashier/reprint_or.php`, `cashier/history.php`, `cashier/account_setup.php`, `cashier/monthly_payments.php`, `cashier/consolidation.php`, `cashier/fee_schedule.php`, `cashier/sidebar.php`, `cashier_schema_update.sql`, `cashier_fees_update.sql`, `enrollment_db.sql` (tables: `backaccount_payment_records`, `enrollment`, `enrollment_payment`, `payment_breakdown`, `schoolyear`, `section`, `gradelevel`, `preregistration`, `old_studentprofile`, `house`, `payment_book`, `studentinfo`).
