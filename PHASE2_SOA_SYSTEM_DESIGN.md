# Phase 2 — Statement of Account (SOA) & SOA-Based Payment Processing
### Design & Implementation Plan for the ITFA Financial Management System

**Builds on:** `CASHIER_MODULE_ASSESSMENT.md` (Phase 1). This document reuses the ledger-centric spine recommended there and expands it into a full SOA + collection + receipt + ledger system.
**Audience:** ERP architect / dev team implementing against the existing `enrollment_db` (MariaDB/MySQL, procedural PHP 8, mysqli).
**Date:** 2026-06-19

---

## 0. Design Principles (carried from Phase 1)

1. **One source of truth per fact.** An `student_assessment` row is the *only* place a student's charges live; balances are *derived* from the ledger, never hand-maintained in three places.
2. **Append-only ledger.** Every financial event writes an immutable `student_ledger` row. Corrections are new entries (adjustment/reversal), never in-place edits.
3. **Everything money-moving is transactional.** Posting a payment touches 6+ tables inside ONE `START TRANSACTION … COMMIT`, with `SELECT … FOR UPDATE` on the schedule rows it settles.
4. **Gap-free, attributable document numbers.** OR and SOA numbers come from a sequence table inside the transaction — never from `AUTO_INCREMENT`.
5. **Normalized keys.** `school_year_id` FK everywhere (stop matching `'2026-2027'` strings). Real foreign keys with `ON DELETE RESTRICT` on all financial links.
6. **Snapshot what you print.** An SOA/receipt freezes the numbers it showed (in `soa_details` / `receipt_details`) so a reprint is faithful even after later payments.
7. **Additive migration.** New tables sit *beside* `backaccount_payment_records` / `enrollment_payment`; legacy data is backfilled, originals become read-only archives.

---

## 1. Functional Requirements Document (FRD)

### 1.1 Actors & permissions
| Actor | Capabilities |
|---|---|
| **Cashier** | Generate SOA (individual/section/grade/batch), collect payments via SOA scan, print OR, view own daily collection, request void (needs approval). |
| **Cashier Supervisor / Treasurer** | All cashier rights + approve void/refund, edit fee schedule, run end-of-day close (Z-report), view all collections. |
| **Registrar** | Read SOA/ledger (no money posting). |
| **Admin / Super Admin** | Full config: fee schedules, discount/scholarship rules, system settings (overpayment policy, installment count), user roles. |
| **(Future) Parent/Student portal** | Read-only SOA + ledger + online payment initiation. |

### 1.2 Functional requirements (numbered, testable)

**FR-A — Assessment & Balance**
- FR-A1: On enrollment confirmation, the system creates ONE `student_assessment` per (enrollment, school_year) with charge lines for tuition, school improvement, books, miscellaneous, enrollment/admission, activity, house registration — sourced from `fee_schedule`/`payment_breakdown` by classification + New/Old + level.
- FR-A2: Discounts, scholarships, and financial assistance are recorded as **negative charge lines** (`assessment_charge.line_type='discount'`) tied to a reason/scholarship code, so `net_assessed = Σ charges − Σ discounts`.
- FR-A3: Real-time balance = `net_assessed − Σ posted payments (non-voided)`, computed from `student_ledger` running balance, never stored authoritatively.
- FR-A4: Adjustments (e.g., late-fee, correction) post as `assessment_charge` + ledger entries; balance recomputes automatically.

**FR-B — Installment schedule**
- FR-B1: After deriving the *installment-eligible* balance (net assessed minus enrollment-day fees already collected), divide into **N installments** (default 10, configurable 6–12) with due dates starting the configured month (default August of SY first year, month-end due).
- FR-B2: Each `payment_schedule` row tracks `amount_due`, `amount_paid`, `balance`, `status ∈ {Unpaid, Partial, Paid, Overdue}`. Overdue is derived (`due_date < today AND balance > 0`), surfaced by a scheduled job/lazy update.
- FR-B3: Rounding: last installment absorbs the remainder so `Σ amount_due = installment-eligible balance` exactly (no centavo drift).

**FR-C — SOA generation (flexible scope)**
- FR-C1: Generate for a **single student** (search by Student ID / LRN / name).
- FR-C2: Generate for a **section** (all enrolled students in `section.Section_id`).
- FR-C3: Generate for a **grade level** (all sections under a `gradelevel.Gradelevel_id`).
- FR-C4: **Batch**: whole school / department / grade / section in one action, paginated 2-up.
- FR-C5: **Multi-month selection** — operator picks any subset of installment months (Month 1; or 1+2; or 1+2+3; or custom). SOA total = Σ selected months' `amount_due` (net of amounts already paid on those months).
- FR-C6: Each generated SOA is persisted as `soa_master` (+ `soa_details`) with a unique gap-free SOA number and a barcode/QR reference; it is a **reproducible snapshot**.

**FR-D — Paper-saving layout**
- FR-D1: A4 portrait, **2 students per sheet** (top/bottom). Cut line between halves. 50% paper saving.
- FR-D2: Single-student print also supported (one half, or a full-page detailed variant).

**FR-E — SOA-based payment**
- FR-E1: Cashier loads a student by **scanning the SOA barcode/QR or typing the SOA number** → system loads student, balance, schedule, prior payments, and the SOA's selected months.
- FR-E2: Cashier selects installments to pay, enters amount, system validates, posts in ONE transaction (FR-G), generates OR, updates schedule + ledger.
- FR-E3: **Partial payment** allowed → settles oldest selected months first; remaining month becomes `Partial`, balance carried forward.
- FR-E4: **Overpayment** → per system setting, excess either (a) cascades to the next unpaid installment(s), or (b) is stored as an **advance/credit** ledger entry to apply later.

**FR-F — Official Receipt**
- FR-F1: OR is generated automatically and atomically with the payment; `receipt_master` (header) + `receipt_details` (fee-category breakdown).
- FR-F2: Reprints are watermarked DUPLICATE and increment `reprint_count`; the OR number never changes.

**FR-G — Transaction integrity**
- FR-G1: Steps "post payment → allocate to installments → update schedule → write ledger → generate OR" are atomic. Any failure rolls back entirely; no orphan OR, no half-applied payment.
- FR-G2: Idempotency: a duplicate submit (same client token) within the window does not create a second payment.

**FR-H — Ledger**
- FR-H1: `student_ledger` records: assessment created, SOA generated, payment posted, OR generated, discount/scholarship applied, adjustment, reversal/refund, advance applied — each with debit/credit, running balance, actor, timestamp.

**FR-I — Dashboard**
- FR-I1: Today / month / year collection; outstanding receivables; overdue & partial student counts; collection by grade/section; trend; projected monthly collection (Σ upcoming `amount_due`).

**FR-J — Audit & close**
- FR-J1: `financial_audit_logs` captures every mutating action with before/after JSON + actor + IP.
- FR-J2: End-of-day **close** locks a cashier's day into `collection_summary`; post-close changes require an adjustment, not an edit.

### 1.3 Business rules
- BR1: A student cannot have two active assessments for the same school year (`UNIQUE(enrollment_id, school_year_id)`).
- BR2: Voiding a payment reverses its allocations and ledger impact via compensating entries; the original rows remain.
- BR3: SOA is informational; it never changes balances. Only `payment_transaction` (and adjustments/reversals) move money.
- BR4: Enrollment-day fees (admission, activity, house, book down-payment) are NOT part of the 10-month installment base; they are collected up front and recorded as their own charges/payments.

---

## 2. Database Architecture

### 2.1 Table inventory (all requested names honored)
| Table | Role |
|---|---|
| `school_year` *(normalize existing)* | Canonical SY; FK target. |
| `fee_item` | Catalog of chargeable items (tuition, misc, books, activity, house, etc.). |
| `fee_schedule` *(rebuild)* | Amount per (SY, classification/level/type, fee_item, cadence). |
| `student_assessment` | Header: per enrollment+SY totals (assessed, discount, net, paid, balance, status). |
| `assessment_charge` | Charge/discount/adjustment lines feeding the assessment. |
| `payment_schedule` | The N monthly installment rows (due/paid/balance/status). *(= "payment_installments" plan)* |
| `soa_master` | A generated SOA document (number, scope, selected months, totals, barcode/QR ref, generated_by). |
| `soa_details` | Snapshot lines of an SOA (selected installments + amounts at print time). |
| `payment_transaction` | A posted collection event (method, amount, tendered, change, cashier, soa_id, status). |
| `payment_installments` | **Allocation**: how each payment was split across `payment_schedule` months. |
| `payment_adjustments` | Discounts / scholarships / financial assistance / corrections as events. |
| `payment_reversals` | Voids & refunds (links the reversed payment, reason, approver). |
| `receipt_master` | OR header (1:1 with a posted payment). |
| `receipt_details` | Fee-category breakdown printed on the OR. |
| `student_ledger` | Append-only ledger of every financial event. |
| `financial_audit_logs` | Who/what/when/before/after for all mutations. |
| `collection_summary` | Per-cashier/day rollup + end-of-day close state. |
| `document_series` | Sequence generator for OR & SOA numbers (gap-free, per series/year). |
| `system_setting` | Config (overpayment policy, installment count, due-day, etc.). |

### 2.2 Architecture diagram (logical layers)

```
┌──────────────────────────────────────────────────────────────────────┐
│ PRESENTATION  (cashier/soa.php, soa_batch.php, collect.php, dashboard) │
│   thin controllers → call services; render A4/A5 print views          │
└───────────────┬──────────────────────────────────────────────────────┘
                │  (no SQL in views — Phase G goal)
┌───────────────▼──────────────────────────────────────────────────────┐
│ SERVICE / DOMAIN LAYER                                                  │
│  AssessmentService  ScheduleService  SoaService                        │
│  PaymentService(atomic)  ReceiptService  LedgerService                 │
│  DiscountService  ReversalService  CollectionReportService             │
│  NumberSeriesService (document_series)                                 │
└───────────────┬──────────────────────────────────────────────────────┘
┌───────────────▼──────────────────────────────────────────────────────┐
│ DATA LAYER (FKs + transactions)                                        │
│  assessment ─ charges ─ schedule ─ soa ─ payment ─ allocation ─ receipt│
│  ledger (append-only) · audit_logs · collection_summary               │
└───────────────┬──────────────────────────────────────────────────────┘
                │  read-only archive
        ┌───────▼────────┐   ┌────────────────────┐
        │ backaccount_*  │   │ enrollment_payment │   (legacy, backfilled)
        └────────────────┘   └────────────────────┘
```

---

## 3. Entity Relationship Diagram (ERD)

```
school_year(id PK, label, is_active, starts_on, install_start_month)
   │1
   │N
student_assessment(id PK)
   ├ enrollment_id FK → enrollment(id)
   ├ school_year_id FK → school_year(id)
   ├ student_id, classification_id, student_type(New/Old)   ← snapshot
   ├ total_assessed, total_discount, net_assessed
   ├ enrollment_fees_total, installment_base
   ├ total_paid (derived/cached), balance (derived/cached)
   ├ status(Active/Settled/Void), created_by, created_at
   │1
   ├───N assessment_charge(id PK, assessment_id FK, fee_item_id FK,
   │        line_type[charge|discount|adjustment], description,
   │        amount, is_installment_base TINYINT, source_ref)
   │
   │1
   ├───N payment_schedule(id PK, assessment_id FK, term_no,
   │        month_label, due_date, amount_due, amount_paid,
   │        balance, status[Unpaid|Partial|Paid|Overdue])
   │        ▲
   │        │N
   │     payment_installments(id PK, payment_id FK, schedule_id FK, amount)   ← allocation
   │        │
   │1       │
   ├───N payment_transaction(id PK, assessment_id FK, soa_id FK NULL,
   │        method[Cash|GCash|Maya|Bank|Voucher|Advance],
   │        reference_no, amount, tendered, change_amount,
   │        status[Posted|Voided], received_by, paid_at)
   │        │1
   │        ├──1 receipt_master(id PK, payment_id FK, or_number UNIQUE,
   │        │       series, sequence, reprint_count, issued_by, issued_at)
   │        │       │1
   │        │       └──N receipt_details(id PK, receipt_id FK, fee_item_id FK,
   │        │                category, amount)
   │        │
   │        └──N payment_reversals(id PK, payment_id FK, type[Void|Refund],
   │                amount, reason, requested_by, approved_by, created_at)
   │
   ├───N soa_master(id PK, assessment_id FK, soa_number UNIQUE, scope[Student|Section|Grade|Dept|School],
   │        selected_terms_json, total_due, barcode_ref, qr_ref,
   │        generated_by, generated_at, batch_id NULL)
   │        │1
   │        └──N soa_details(id PK, soa_id FK, schedule_id FK, term_no,
   │                month_label, amount_due, amount_paid_snapshot, amount_selected)
   │
   ├───N payment_adjustments(id PK, assessment_id FK, type[Discount|Scholarship|Assistance|Adjustment],
   │        code, amount, reason, applied_by, applied_at)
   │
   └───N student_ledger(id PK, assessment_id FK, student_id, school_year_id FK,
            entry_type[Assessment|SOA|Payment|Receipt|Discount|Scholarship|Adjustment|Reversal|Refund|Advance],
            ref_table, ref_id, debit, credit, running_balance, posted_by, posted_at)

document_series(id PK, series_code, year, last_seq)          ← OR / SOA sequence
collection_summary(id PK, cashier_id, business_date, school_year_id FK,
                   txn_count, total_cash, total_online, total_collected,
                   closed_at, closed_by, status[Open|Closed])
financial_audit_logs(id PK, actor_id, action, entity, entity_id,
                     before_json, after_json, ip, created_at)
system_setting(key PK, value, updated_by, updated_at)

  Reference (existing): enrollment, gradelevel, section, house,
                        preregistration, old_studentprofile, payment_breakdown
```

**Cardinality highlights**
- `student_assessment 1—N payment_schedule` (N installments).
- `payment_transaction N—N payment_schedule` resolved through `payment_installments` (one payment can settle several months; one month can receive several payments → clean partials & advances).
- `payment_transaction 1—1 receipt_master 1—N receipt_details`.
- `soa_master 1—N soa_details` (snapshot of selected terms).

---

## 4. UI / UX Wireframes (ASCII)

### 4.1 SOA Generation hub (`cashier/soa.php`)
```
┌──────────────────────────────────────────────────────────────────────┐
│ Cashier ▸ Statement of Account                         S.Y. 2026-2027 │
├──────────────────────────────────────────────────────────────────────┤
│ Scope:  ( ) Student   (•) Section   ( ) Grade   ( ) Dept   ( ) School │
│                                                                        │
│ Section ▾ [ Grade 7 — Al Farabi              ]   Students: 48          │
│                                                                        │
│ Installment months to include on SOA:                                  │
│  [✓ M1] [✓ M2] [✓ M3] [ M4] [ M5] [ M6] [ M7] [ M8] [ M9] [ M10]      │
│  Quick: (Month 1 only) (M1+M2) (M1+M2+M3) (All unpaid) (Custom)        │
│                                                                        │
│ Layout:  (•) 2 students / A4 (paper-saver)   ( ) 1 student / page      │
│                                                                        │
│              [ Preview ]      [ Generate & Print ]                      │
├──────────────────────────────────────────────────────────────────────┤
│ Recent SOAs   SOA-2026-000142  Grade7-AlFarabi  48 stu  6/19 10:21 ↻   │
└──────────────────────────────────────────────────────────────────────┘
```

### 4.2 Printed SOA — A4 portrait, 2-up (paper-saver)
```
╔══════════════════════ A4 PORTRAIT ══════════════════════╗
║  [LOGO] IBN TAIMIYAH FOUNDATION ACADEMY, Inc.            ║
║         STATEMENT OF ACCOUNT      SOA No: SOA-2026-000142 ║
║  Student: ABUBAKAR, MASHARI O.    LRN: 81491740230002200 ║
║  Grade/Sec: Grade 7 — Al Farabi   S.Y.: 2026-2027        ║
║  ┌─ Financial Summary ───────────────────────────────┐   ║
║  │ Total Assessment   ₱22,000.00                      │   ║
║  │ Less Payments      ( 2,000.00)  Discounts (0.00)   │   ║
║  │ OUTSTANDING        ₱20,000.00                      │   ║
║  └────────────────────────────────────────────────────┘  ║
║  Installments (selected): M1+M2+M3   Monthly ₱2,000      ║
║   #  Month        Due Date    Due     Paid    Status     ║
║   1  Aug 2026     08/31      2,000    2,000   PAID        ║
║   2  Sep 2026     09/30      2,000      —      UNPAID ◀   ║
║   3  Oct 2026     10/31      2,000      —      UNPAID ◀   ║
║   ─ Amount due on this SOA (M2+M3): ₱4,000.00 ─          ║
║  [|||||| barcode SOA-2026-000142 ||||||]   [QR]          ║
║  Generated by: J. Cruz  ·  06/19/2026 10:21              ║
║··················  ✂  cut here  ·························║
║  [LOGO]  STATEMENT OF ACCOUNT     SOA No: SOA-2026-000143 ║
║  Student: ANGELES, BENLADIN B. ...  (second student)     ║
║   ...                                                     ║
╚══════════════════════════════════════════════════════════╝
```

### 4.3 SOA-based collection (`cashier/collect.php`)
```
┌──────────────────────────────────────────────────────────────────────┐
│ Cashier ▸ Collect Payment                                             │
│  Scan SOA / OR  [ ▮ SOA-2026-000142________ ]  (barcode/QR or type)   │
├──────────────────────────────────────────────────────────────────────┤
│ ABUBAKAR, MASHARI O.   Grade 7 — Al Farabi   ID 8149…  Bal ₱20,000.00 │
│  Select installments to pay:                                          │
│   [✓] M2 Sep  Due 2,000  Paid   —     → pay 2,000                      │
│   [✓] M3 Oct  Due 2,000  Paid   —     → pay 2,000                      │
│   [ ] M4 Nov  Due 2,000  Paid   —                                     │
│  Amount tendered: [ 4,000.00 ]  Method ▾ [Cash]  Ref [____]           │
│  Allocated 4,000 · Change 0.00 · Overpay policy: → next installment   │
│                          [ Validate ]   [ Post & Print OR ]           │
└──────────────────────────────────────────────────────────────────────┘
```

### 4.4 Cashier Dashboard (`cashier/dashboard.php`)
```
┌ Today ₱48,250 (19 txn) ┬ Month ₱612,400 ┬ Year ₱4.2M ┬ Receivables ₱1.8M ┐
├────────────────────────┴────────────────┴───────────┴───────────────────┤
│ Overdue accounts: 37   Partial: 22   Projected next month: ₱310,000      │
│ [ Collection by Grade ▮▮▮▮▮ ]    [ Collection trend (30d) ╱╲╱ ]          │
│ Overdue list →  ANGELES… 3 mos · SARAO… 2 mos · …      [End-of-Day ▸]    │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## 5. Workflow Diagrams

### 5.1 Cashier collection workflow (atomic)
```
Scan SOA/OR ─► load assessment+schedule (FOR UPDATE) ─► show outstanding
   └─► select installments ─► enter amount ─► VALIDATE
        (amount>0, ≤ selectable+overpay rule, day not closed, idempotency token)
                 │ fail → message, no writes
                 ▼ pass
        ┌─ BEGIN TRANSACTION ───────────────────────────────────────┐
        │ 1 INSERT payment_transaction (status=Posted)              │
        │ 2 allocate → INSERT payment_installments (oldest-first)   │
        │ 3 UPDATE payment_schedule (amount_paid/balance/status)    │
        │ 4 handle overpay → next installment OR advance ledger     │
        │ 5 next_seq(OR) → INSERT receipt_master + receipt_details  │
        │ 6 INSERT student_ledger (Payment, Receipt, [Advance])     │
        │ 7 UPDATE student_assessment.total_paid/balance/status     │
        │ 8 UPSERT collection_summary (today, cashier)              │
        │ 9 INSERT financial_audit_logs                             │
        └─ COMMIT (any error → ROLLBACK, nothing persisted) ───────┘
                 ▼
        Render OR (A5) → auto-print
```

### 5.2 SOA generation workflow
```
choose scope (student/section/grade/dept/school) + month selection + layout
   ▼
resolve student set  (enrollment ⋈ gradelevel/section for current SY)
   ▼ for each student
ensure student_assessment exists (create from fee_schedule if missing)
ensure payment_schedule exists (generate N installments if missing)
   ▼
next_seq(SOA) → INSERT soa_master (+ batch_id if batch)
snapshot selected terms → INSERT soa_details (due & paid-so-far)
INSERT student_ledger (entry_type=SOA, no money movement)
   ▼
paginate 2 students / A4 → render print view → (print/save PDF)
```

### 5.3 Receipt workflow
```
payment COMMIT ─► receipt_master already created in same txn
   ▼
render OR view from receipt_master + receipt_details (+ barcode/QR of OR no.)
   ▼ reprint?
load by OR no. → watermark DUPLICATE → reprint_count++ (audited)  [OR no. unchanged]
```

### 5.4 Void / refund workflow
```
cashier requests void(payment_id, reason) ─► supervisor approves
   ▼ BEGIN TXN
INSERT payment_reversals(type=Void|Refund, approved_by)
reverse payment_installments → restore payment_schedule paid/balance/status
INSERT student_ledger (Reversal, compensating debit/credit)
UPDATE payment_transaction.status=Voided ; UPDATE assessment totals
INSERT financial_audit_logs
COMMIT   (original payment & OR rows preserved)
```

---

## 6. Database Migration Plan (zero data loss)

| Phase | Action | Reversible? |
|---|---|---|
| **0 Backup** | Full dump + staging clone + restore drill. Convert existing runtime `ALTER`s into reviewed migration files. | n/a |
| **1 Normalize SY** | Add `school_year_id` FK columns alongside existing label columns; backfill by matching label → id; keep labels temporarily. | yes |
| **2 Create new schema (additive)** | Create all Section-2 tables with FKs + `document_series` + `system_setting`. No app writes yet. | yes (drop) |
| **3 Seed config** | Load `fee_item`, rebuilt `fee_schedule` (from `cashier_fees_update.sql` values), settings (installments=10, overpay policy, due-day). | yes |
| **4 Backfill assessments** | For each active enrollment, derive `student_assessment` + `assessment_charge` from `payment_breakdown`/`fee_schedule`. Generate `payment_schedule`. | yes |
| **5 Backfill payments** | Migrate `backaccount_payment_records` + `enrollment_payment` → `payment_transaction` + `receipt_master` + `student_ledger`; allocate against schedule where determinable, else as generic credits. Produce a **reconciliation/variance report** vs legacy totals. | yes |
| **6 Cutover (flagged)** | Feature-flag new screens one at a time (SOA → collect → receipt → dashboard). Dual-read; nightly compare new ledger totals to legacy. | toggle off |
| **7 Decommission** | After a full collection cycle at parity, stop writing legacy tables; keep them read-only. Drop temporary SY label columns. | archival |

**Invariant:** never delete/overwrite historical financial rows; corrections are new attributable entries.

**Idempotency for backfill:** every migration script keys on a natural unique (`enrollment_id+school_year_id`, legacy `id`) and is safe to re-run.

---

## 7. Security Controls

1. **Transactions + row locks** on every money path (`SELECT … FOR UPDATE` on schedule/assessment) — eliminates double-post & partial writes.
2. **Server-side idempotency token** per collection form → blocks double-submit/double-click.
3. **Gap-free document numbers** via `document_series` inside the txn (no `AUTO_INCREMENT` ORs; no predictable holes).
4. **RBAC** from a unified user/role/permission model; distinct perms: `collect`, `reprint`, `void`, `refund`, `edit_fee`, `close_day`, `view_reports`, `generate_soa`. Remove the hardcoded `'TAIMIYANS'` password entirely.
5. **Maker-checker** for voids/refunds/adjustments (request by cashier, approve by supervisor).
6. **Immutable audit** (`financial_audit_logs`) on every mutation with before/after JSON, actor, IP; ledger is append-only.
7. **End-of-day close** locks the day; post-close edits impossible — only adjustments.
8. **Input validation & parameterized SQL** (already a strength — keep it); validate amounts server-side (`>0`, `≤ payable + overpay allowance`, 2-decimal).
9. **Session hardening:** regenerate id on login, idle timeout, `HttpOnly`/`SameSite`/`Secure` cookies, CSRF on all POSTs (extend existing).
10. **Operational hygiene:** `display_errors=0` in prod, least-privilege DB account (no DDL at runtime), structured error logging, SRI/self-host for CDN assets, no plaintext default passwords.
11. **QR/barcode verification:** OR QR encodes a signed token (OR no. + hash) so a scanned receipt can be verified against `receipt_master` (anti-forgery).
12. **PII/financial data:** restrict ledger/SOA exports by role; log every export.

---

## 8. Technical Implementation Roadmap

**Milestone 1 — Foundation (schema + services)**
- Migrations for all Phase-2 tables + FKs + `document_series` + settings.
- `NumberSeriesService`, `AssessmentService`, `ScheduleService`, `LedgerService` with unit tests on money math (rounding, balance derivation).
- Backfill scripts + reconciliation report (Migration Phases 1–5).

**Milestone 2 — SOA generation**
- `SoaService` + `cashier/soa.php` (scopes: student/section/grade/dept/school, multi-month, 2-up A4 print, batch).
- Barcode/QR rendering; `soa_master`/`soa_details` persistence; ledger SOA entries.

**Milestone 3 — SOA-based collection + receipt (atomic)**
- `PaymentService.post()` (full transactional workflow §5.1), partial + overpayment policies, idempotency.
- `ReceiptService` + OR print (A5) + reprint/DUPLICATE; `receipt_master/details`.
- Scan/lookup by SOA/OR.

**Milestone 4 — Ledger, void/refund, dashboard, close**
- `cashier/ledger.php` (per-student timeline), `ReversalService` (maker-checker), `cashier/dashboard.php` (KPIs + projections), end-of-day close → `collection_summary`/Z-report.

**Milestone 5 — Hardening & cutover**
- RBAC rollout, audit coverage, session hardening, remove legacy edit password, feature-flag cutover, dual-read parity verification, decommission legacy writes.

**Milestone 6 — Future integration hooks (post-cutover)**
- GL posting feed from `student_ledger` → Accounting.
- Payment-gateway webhooks (GCash/Maya/bank) → `payment_transaction(method)`.
- Parent/Student portal (read SOA/ledger, initiate online pay), SMS/email receipts, Executive dashboard rollups; shared identity/RBAC reused by Payroll/HRIS/Inventory/LMS.

---

## 9. Appendix — Key derivations & formulas

- **Net assessed:** `net_assessed = Σ assessment_charge(line_type='charge') − Σ assessment_charge(line_type IN('discount'))`.
- **Installment base:** `installment_base = net_assessed − enrollment_fees_total` (admission/activity/house/book down-payment collected up front).
- **Monthly due:** `floor(installment_base / N, 2)` for terms 1..N-1; term N = `installment_base − Σ(terms 1..N-1)` (absorbs remainder).
- **Balance (authoritative = ledger):** `balance = net_assessed − Σ ledger.credit(payments,non-voided) + Σ ledger.debit(refunds/reversals)`.
- **SOA total (selected terms):** `Σ max(0, schedule.amount_due − schedule.amount_paid)` over selected `term_no`.
- **Overpayment:** `excess = tendered − Σ allocations`; setting `OVERPAY_POLICY ∈ {cascade_next, store_advance}`.
- **Overdue (derived):** `due_date < CURRENT_DATE AND balance > 0`.

> Data-quality note for batch generation: existing `gradelevel.Gradelevel` values contain stray `\n` and embed strand (e.g. `GRADE-11(ABM)`), and `enrollment.Department_section` is a varchar joined to `section.Section_id` via `CAST`. The migration should clean grade labels and add a real `section_id` int FK on enrollment so section/grade SOA batches are reliable and index-friendly.
