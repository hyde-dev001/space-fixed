# Finance Integrity and Operational Payments Design

**Date:** 2026-08-11

**Status:** Final authoritative input for implementation planning

**Scope:** SME operational finance; P0 correctness/security, justified P1 integrity work, and directly required cleanup

## 1. Summary

This design turns the existing Finance module into a reliable SME operational-finance view without introducing general accounting. It fixes tenant isolation and authorization, moves dashboard calculations to one backend contract, replaces destructive invoice-payment fields with immutable payment history, adds lightweight expense settlements, protects money-state transitions from duplicate or concurrent submission, and removes misleading ledger/report scaffolding.

The work ships in three gated phases:

1. P0 tenant, authorization, receipt, tax, and dashboard correctness.
2. P1 payment, settlement, approval, invoice-generation, payroll, and error-handling integrity.
3. Route and stale-scaffolding consolidation required to leave one coherent implementation.

P2 and P3 product features remain deferred.

## 2. Goals

- Prevent cross-shop access to every Finance record and receipt.
- Enforce backend capabilities independently from tenant scoping and frontend visibility.
- Make dashboard figures follow one explicit, server-side calculation contract.
- Ensure each sale, refund, expense, payment, and settlement contributes exactly once.
- Support partial invoice payments with immutable payment and reversal history.
- Support lightweight partial settlement of supplier and other deferred expenses.
- Allow routine paid-at-entry expenses without forcing an accounts-payable workflow.
- Make job invoice creation, money writes, approvals, and payroll disbursement transactionally safe.
- Preserve operational modules as the source of truth for their workflows.
- Remove misleading ledger posting, broken accounting reports, duplicate Finance routes, and confirmed dead Finance code.
- Add behavioral tests that protect money invariants and tenant isolation.

## 3. Non-Goals

- General ledger or double-entry accounting
- Journal-entry posting or editing
- Chart of accounts
- Trial balance, balance sheet, or statutory financial statements
- Full accounts payable or accounts receivable subledgers
- Bank feeds or enterprise bank reconciliation
- Multi-company, multi-branch, or multi-currency accounting
- Cost-center accounting
- Enterprise budgeting, treasury, consolidation, or accounting-period closing
- Actual invoice email delivery
- New dashboard date filters or previous-period comparisons
- New CSV/reporting work, payout reconciliation, or Finance action queues
- Redesigning procurement, payroll, inventory, repair, sales, refund, or logistics workflows
- Rewriting historical migrations solely to clean them up

## 4. Current-State Problems

The approved gap analysis identified the following design inputs:

- Broad Finance route middleware lets a narrow approval permission reach unrelated invoice and expense operations.
- Receipt upload, download, and deletion resolve expenses without shop scoping.
- Tax-rate operations can default missing tenant context to Shop 1.
- Dashboard revenue can be pre-netted and then reduced by the same refund again; all expense statuses are counted; percentage changes are hard-coded.
- Invoice `post` presents nonexistent ledger behavior and conflicts with the simplified invoice lifecycle.
- Paid invoices overwrite one date and method and cannot retain partial-payment or correction history.
- Procurement expenses record an obligation but not its due or settlement state.
- Routine manual expenses and deferred supplier obligations currently share an unclear cash-state model.
- Job invoice creation, payment updates, and approval transitions are vulnerable to duplicate or concurrent execution.
- Payroll can be disbursed under approval-oriented permissions and its Finance expense creation is best-effort.
- Controllers expose raw exception messages.
- Canonical, legacy, and session Finance route families overlap.
- Broken accounting reports, journal relationships, dead hooks, stale types, and retired expense paths remain in active code.
- Existing tests emphasize approval flows and do not protect the main Finance security or money invariants.

This document designs only the smallest changes needed to resolve those findings.

## 5. Design Principles

1. **SME operational finance, not accounting software.** Finance answers what was earned, refunded, incurred, paid, due, and outstanding.
2. **One owner per financial fact.** Operational modules own operational events; Finance owns manual invoices, manual expenses, payment history for Finance-managed invoices, and expense settlements.
3. **Derive balances.** Paid totals, outstanding balances, and payment statuses come from immutable records rather than editable summary columns.
4. **History over destructive correction.** Incorrect payments and settlements are reversed and replaced.
5. **Tenant isolation and capability authorization are separate gates.** Both must pass on the backend.
6. **Server-side financial rules.** The dashboard consumes authoritative aggregates and does not rebuild them in React.
7. **Transactions where money state changes.** Locks and uniqueness are limited to demonstrated race conditions.
8. **Simple routine expense entry.** A cash expense may be created and settled atomically; deferred settlement remains available where it solves a real obligation.
9. **No speculative abstraction.** Payment and settlement tables are purpose-specific. Integration sources are a small controlled list, not a generic event framework.

## 6. Proposed Architecture

```text
Retail / POS -------- sale and executed-refund facts -------+
Repairs ------------- invoice/payment/refund references ----+
Procurement --------- receipt-backed expense obligation ----+--> Finance summary service --> Finance dashboard
Payroll ------------- disbursement-backed expense/payment --+
Manual Finance ------ invoices, payments, expenses ----------+
```

Operational modules remain authoritative for orders, POS transactions, repair payment transactions, procurement receipts, payroll state, and refunds. Finance uses their stable identifiers and existing relationships. It does not copy their workflows.

The target Finance implementation contains:

- One canonical `/api/finance` route family.
- A shared shop-context resolver used before tenant-scoped queries.
- Focused backend authorization for dashboard, invoices, expenses, tax, and payroll disbursement.
- `InvoicePaymentService` for payment and reversal transactions.
- `ExpenseSettlementService` for paid-at-entry expenses, deferred settlements, and reversals.
- `FinanceSummaryService` for the calculation contract.
- Transactional approval transitions and payroll-to-Finance synchronization.
- Existing audit/activity infrastructure for mutable lifecycle changes.
- Immutable payment and settlement rows as the primary evidence of money movement.

These service names describe focused responsibilities, not a new framework. Existing project helpers should be reused where they already provide the same boundary.

## 7. Domain / Data Model

### 7.1 Source-of-truth ownership

| Concept | Authoritative source |
|---|---|
| Invoice definition, line items, and total | `finance_invoices` and `finance_invoice_items` |
| Standalone Finance invoice payment | `finance_invoice_payments` |
| Order/POS/repair payment | Existing originating payment/order/POS record |
| Invoice paid total and remaining balance | Derived from its authoritative payment source |
| Expense definition and incurred amount | `finance_expenses` |
| Manual/procurement expense settlement | `finance_expense_settlements` |
| Payroll payment | Payroll disbursement; synchronized atomically to one payroll-linked expense settlement |
| Order/POS/repair refund | Existing originating refund record |
| Revenue and expense summaries | `FinanceSummaryService` over authoritative sources |
| Tax configuration | Tenant-scoped Finance tax-rate record |

Finance invoices linked to an operational payment source are documentary. Finance shows their originating payment history read-only and does not allow a second manual Finance payment for the same charge.

### 7.2 `finance_invoice_payments`

Create a purpose-specific table:

| Column | Type and rule |
|---|---|
| `id` | `bigint` primary key |
| `shop_owner_id` | Required FK to `shop_owners`; cascade only when the tenant is deleted |
| `invoice_id` | Required FK to `finance_invoices`; restrict normal deletion |
| `entry_type` | String constrained by application/domain enum: `payment`, `reversal` |
| `amount` | `decimal(18,2)`, greater than zero |
| `payment_method` | String, required for payment; copied from the original for reversal |
| `reference` | Nullable string for check, bank, or provider reference |
| `received_at` | Required datetime |
| `recorded_by_user_id` | Nullable FK to `users`; required for interactive writes, nullable only for migration/system records |
| `idempotency_key` | Nullable request key following the project's existing idempotency convention; required for interactive payment creation, `NULL` for reversals/migration rows |
| `reverses_payment_id` | Nullable self-FK; unique when present |
| `reversal_reason` | Nullable text; required for reversal |
| `source` | Controlled string: `manual`, `legacy_migration`; add an operational value only for an existing integration that writes this table |
| timestamps | `created_at`, `updated_at`; application prohibits update/delete after creation |

Indexes:

- `(shop_owner_id, received_at)`
- `(invoice_id, entry_type)`
- Unique `(shop_owner_id, idempotency_key)` when `idempotency_key` is present
- Unique `reverses_payment_id`

Invariants:

- The payment, invoice, and authenticated actor shop context must match.
- Payment and reversal amounts are positive decimals.
- A reversal equals the full original payment amount.
- One reversal may reference a payment; corrections are reversal plus replacement.
- Valid paid amount is payments minus their reversals.
- Normal writes cannot make valid paid amount exceed invoice total.
- Operational status is derived as `unpaid`, `partially_paid`, or `paid`.
- Historical excess is not a normal status. The API returns `integrity_warning: overpayment_detected`, blocks further payment, and requires reconciliation.

Duplicate-submit protection is a write-integrity concern, not authentication or authorization. Reuse the project's existing `idempotency_key` request convention used by POS where practical. The specific key format and producer are not part of this design contract. The invariant is that a repeated request returns the first effective result and concurrent requests create at most one effective payment. Enforce it with the unique shop/key constraint, a database transaction, invoice locking, and balance/state revalidation. If the existing Finance client cannot reuse the current mechanism, add only the smallest request-token/UUID handling needed to supply this key; do not add a cache/session/expiry/token-consumption protocol.

### 7.3 `finance_expense_settlements`

Create a purpose-specific table with the same monetary, actor, reversal, and audit rules:

| Column | Type and rule |
|---|---|
| `id` | `bigint` primary key |
| `shop_owner_id` | Required FK to `shop_owners` |
| `expense_id` | Required FK to `finance_expenses` |
| `entry_type` | `settlement` or `reversal` |
| `amount` | `decimal(18,2)`, greater than zero |
| `payment_method` | Required for settlement |
| `reference` | Nullable string |
| `paid_at` | Required datetime |
| `recorded_by_user_id` | Nullable FK to `users`; required for interactive writes |
| `idempotency_key` | Nullable request key following the existing convention; required for interactive settlement creation, `NULL` for reversals/migration rows |
| `reverses_settlement_id` | Nullable unique self-FK |
| `reversal_reason` | Required for reversal |
| `source` | Controlled values only: `manual`, `procurement`, `payroll`, `legacy_migration` |
| `source_reference` | Nullable string; used only for an existing integration's stable identifier |
| timestamps | Application-protected immutable rows |

Indexes:

- `(shop_owner_id, paid_at)`
- `(expense_id, entry_type)`
- Unique `(shop_owner_id, idempotency_key)` when `idempotency_key` is present
- Unique `reverses_settlement_id`
- Unique `(source, source_reference)` when `source_reference` is present and globally stable; otherwise use a shop-scoped composite

The expense obligation is the existing `finance_expenses.amount`. `tax_amount` is informational and is not added a second time.

Derived settlement state is `unpaid`, `partially_paid`, or `paid`. Historical excess is returned as an integrity warning and blocks new settlement.

### 7.4 Changes to `finance_expenses`

Add:

- `due_date date nullable`
- Index `(shop_id, due_date)` using the table's existing tenant column naming

Supplier identity remains derived through the procurement receipt or purchase order. Do not duplicate `supplier_id` on the expense.

Manual expense creation accepts `payment_timing`:

- `paid_now`: create the expense and its initial full settlement atomically. Payment method and paid date are required. This is the default path for routine cash expenses.
- `pay_later`: create the expense without settlement. Due date is optional for manual expenses and required where the UI represents a known obligation.

Approval remains independent from settlement. A valid paid-now settlement means cash actually left the business, so it contributes to paid-expense and cash-movement totals even while approval is pending or rejected. The expense itself contributes to incurred expense only after approval. Rejecting an already-paid expense preserves the settlement and surfaces `paid_rejected_expense` as a reconciliation/integrity warning. A settlement reversal is created only when the underlying payment was actually reversed, refunded, or corrected. If approval must precede payment, the user creates a pay-later expense and records settlement only after approval.

### 7.5 Job invoice uniqueness

Add a unique constraint on `(shop_id, job_order_id)`. Multiple `NULL` values remain permitted. This enforces one Finance invoice per repair job without affecting standalone invoices.

### 7.6 Existing columns

`finance_invoices.payment_date` and `payment_method` become read-only compatibility fields during backfill and cease to be authoritative. Stale journal/account fields are removed only through later forward migrations after active references are removed and deployment validation succeeds.

## 8. Financial Calculation Contract

All aggregation occurs server-side. APIs return decimal strings; backend calculations use database decimals or integer minor units and never binary floating point.

### 8.1 Period

The core release preserves current scope:

- Primary summary: current calendar year.
- Trend: current existing six-month window.
- Application timezone defines calendar boundaries.
- Queries use half-open ranges: `start <= occurred_at < end`.
- Hard-coded and previous-period percentages are removed. New comparison controls are deferred.

### 8.2 Revenue-source matrix and exactly-once rule

Each contribution has a stable existing source identity such as order ID, POS transaction ID, Finance invoice payment ID, refund ID, expense ID, or settlement ID. This is an internal calculation rule, not a generic event abstraction.

Precedence:

1. Completed POS transaction for an in-store sale.
2. Existing order or repair payment record when that module collected payment.
3. Valid `finance_invoice_payments` for standalone Finance-managed invoices.
4. A `legacy_migration` payment on a linked invoice only when its expected authoritative operational payment is absent. It is a warned fallback, not a second source.

A documentary invoice linked to an already-counted operational payment does not contribute additional revenue.

The following physical semantics are confirmed from the current pricing, payment, invoice, and refund write paths. They constrain implementation; fields must not be combined contrary to this table.

| Source | Confirmed stored semantics |
|---|---|
| Online retail order | `total_amount` is the VAT-exclusive product subtotal after sale/voucher discounts. `vat_amount` is extracted from the discounted VAT-inclusive product price. `shipping_fee` is separate and untaxed. Legacy `total` is the customer grand charge. Order-item prices are not a reliable discounted allocation and must not be summed to replace `total_amount`. Refunds change status but do not rewrite these original amounts. |
| Retail POS transaction | `subtotal` is VAT-exclusive product value; `tax_amount` is extracted VAT; `total_amount` is VAT-inclusive charge; `paid_amount` equals the collected charge. Shipping and explicit transaction discount are currently zero. Refund status does not net the original stored amounts. |
| Repair POS transaction | Metadata `service_amount` is VAT-inclusive; `delivery_amount` is separate and untaxed. `subtotal` is VAT-exclusive service plus delivery, `tax_amount` is service VAT, and `total_amount`/`paid_amount` are VAT-inclusive service plus delivery. `discount_amount` is currently zero. Refund status does not net original amounts. |
| Standalone Finance invoice | `total` and invoice-item `amount` include tax; `tax_amount` is stored separately; item `unit_price * quantity` is pre-tax. There is no structured discount or non-retained-delivery field in the standalone form. |
| Linked Finance invoice | Field semantics vary by generator, including repair invoices with `tax_amount = 0`; it is documentary and never the primary calculation source when its operational payment exists. |
| Order refund | On successful execution, `amount` is the actual payout sent by the refund service. It may include shipping according to the explicit Finance shipping decision. `refund_executed_at` marks execution start; terminal successful timing is `refunded_at`. Item lines can reflect unallocated item prices and are not independently authoritative for discounted financial totals. |
| POS refund | On terminal success, `execution_amount` is the actual payout and `executed_at` is the completion time. `approved_amount` is approval state, not the authoritative executed cash amount. |

**Required implementation dependency — not a new product feature:** Encode these confirmed source semantics as fixed calculation fixtures before replacing the dashboard queries. If a legacy row lacks the fields needed to apply its source rule, return an integrity warning and exclude that ambiguous component from an authoritative KPI; do not silently substitute a similarly named field.

| Source | Included record | Revenue amount, excluding VAT | Period timestamp | Exactly-once key |
|---|---|---|---|---|
| Online retail order | `orders.payment_status` in `paid`, `refunded` with a valid `paid_at`; refunded orders retain their original gross contribution before the separate refund deduction | Confirmed `total_amount` plus `shipping_fee` only when the linked delivery method is shop-owned/retained | `orders.paid_at` | `order:{id}` |
| Retail POS | `pos_transactions.module_type = retail` and status in `paid`, `partially_refunded`, `refunded` | Confirmed `subtotal` | `pos_transactions.paid_at` | `pos:{id}` |
| Repair POS | `pos_transactions.module_type = repair` and status in `paid`, `partially_refunded`, `refunded` | VAT-exclusive service portion from `subtotal`, plus metadata `delivery_amount` only when its confirmed delivery method is shop-owned/retained | `pos_transactions.paid_at` | `pos:{id}` |
| Standalone Finance invoice payment | Valid payment row not reversed | The payment's proportional share of the invoice revenue basis | `finance_invoice_payments.received_at` | `invoice-payment:{id}` |
| Linked legacy fallback | A valid `legacy_migration` payment only when no authoritative linked order/POS/repair payment exists | Same proportional invoice-payment rule; response includes `legacy_source_missing` | `finance_invoice_payments.received_at` | `invoice-payment:{id}` |

For a standalone invoice, define:

```text
Invoice Charge Basis = invoice.total
Invoice Revenue Basis = invoice.total - invoice.tax_amount
Payment Revenue = payment.amount * Invoice Revenue Basis / Invoice Charge Basis
```

Round each non-final payment to two decimals. The payment that closes the invoice receives the remaining unrecognized revenue cents so the payment-revenue sum equals the invoice revenue basis exactly. If charge basis is zero or components are inconsistent, exclude the record from authoritative totals and return an integrity warning.

### 8.3 Gross revenue

Gross revenue is financially completed sales/service value before refunds:

- Exclude VAT.
- Include delivery fees only when retained by the shop.
- Include only completed/paid authoritative sources.
- Exclude draft, pending, cancelled, failed, expired, archived, and unpaid records.

### 8.4 Refunds

- Include only executed refund amounts.
- Exclude requested, pending, rejected, or merely approved refunds.
- Use the same revenue basis as the original sale: refunded VAT is excluded; a shop-retained delivery fee reduces revenue only when actually refunded.
- Partial refunds contribute only their executed revenue component.
- Do not infer refunds from invoice display status.

| Refund source | Executed amount | Revenue-reduction amount | Period timestamp | Exactly-once key |
|---|---|---|---|---|
| Order refund | `amount` only when status is terminal `succeeded` | Split the executed payout using the refund workflow's explicit shipping decision. Refunded shop-retained shipping reduces revenue; refunded non-retained shipping does not. Allocate the remaining product payout to VAT-exclusive revenue using the source order's confirmed `total_amount` and `vat_amount`, capped by remaining refundable revenue. Do not sum order-item prices as a substitute. | `refunded_at` | `order-refund:{id}` |
| POS refund | `execution_amount` only when status is terminal `succeeded` | Use refund item/leg allocation when it identifies the refunded component. Otherwise allocate executed payout by the source transaction's confirmed revenue-basis/charge-basis ratio and emit `legacy_refund_allocation`. | `executed_at` | `pos-refund:{id}` |

When a legacy successful refund lacks a terminal timestamp or sufficient component evidence, do not guess from requested/approved state. Use a documented legacy fallback only when execution evidence exists, emit `legacy_refund_allocation`, and cover the chosen mapping with a fixed regression fixture.

```text
Net Revenue = Gross Revenue - Executed Revenue Refunds
```

Revenue must not be pre-netted and reduced by the same refund again.

### 8.5 Expenses

Incurred expenses include:

- Approved manual expenses.
- Valid procurement receipt expenses when receipt acceptance creates the obligation.
- Payroll expenses created by completed disbursement.

They exclude drafts, submitted manual expenses awaiting approval, rejected/voided records, and archived/soft-deleted records. That incurred-expense exclusion does not erase a valid cash settlement associated with a pending or rejected expense.

| Expense source | Included amount | Incurred-period timestamp | Exactly-once key |
|---|---|---|---|
| Approved manual expense | `finance_expenses.amount` | Business `finance_expenses.date` | `expense:{id}` |
| Procurement receipt expense | `finance_expenses.amount` created for the valid receipt | `purchase_order_receipts.received_at`; fall back to expense date only for migrated legacy rows | `expense:{id}` |
| Payroll expense | `finance_expenses.amount` synchronized from completed payroll | Payroll `payment_date` | `expense:{id}` |

```text
Incurred Expenses = sum(included finance_expenses.amount)
Paid Expenses = sum(valid expense settlements by paid_at)
Net Operating Result = Net Revenue - Incurred Expenses
Net Cash Movement = Customer Cash Receipts - Executed Cash Refunds - Paid Expenses
```

Cash metrics deliberately use actual inclusive cash movement rather than the VAT-exclusive revenue basis:

| Cash source | Amount | Period timestamp |
|---|---|---|
| Paid/refunded online order with `paid_at` | Customer charge originally received: `total_amount + shipping_fee + vat_amount` | `orders.paid_at` |
| Paid POS transaction | `paid_amount` | `pos_transactions.paid_at` |
| Standalone invoice payment | Valid `finance_invoice_payments.amount` | `received_at` |
| Successful order refund | Confirmed `order_refunds.amount`, including any VAT or delivery component actually returned | `refunded_at` |
| Successful POS refund | Confirmed `pos_refunds.execution_amount` | `executed_at` |
| Expense payment | Valid `finance_expense_settlements.amount`, including settlements on pending/rejected expenses whose cash movement has not actually been reversed | `paid_at` |

If a delivery charge is collected but later remitted to a courier, the collection remains a cash receipt and the remittance is represented by its expense settlement. This prevents hidden cash movement while keeping non-retained delivery out of revenue.

### 8.6 Owner-facing presentation

Primary dashboard KPIs remain deliberately small:

- Net revenue
- Incurred expenses
- Net operating result
- Net cash movement

Supporting detail appears below or in expandable context:

- Gross revenue
- Executed refunds
- Paid expenses
- Plain-language metric definitions and the active period

The UI must not label net operating result as statutory profit. It does not add accounting-heavy statements or ratios.

## 9. Workflow Specifications

### 9.1 Record standalone invoice payment

```text
Authorize invoice capability
-> resolve tenant-scoped invoice
-> confirm Finance owns payment entry for this invoice
-> validate positive amount, method, date, and optional reference
-> begin transaction and lock invoice
-> recalculate valid paid amount
-> revalidate invoice state and remaining balance
-> reapply the existing idempotency-key convention and database uniqueness guard
-> create immutable payment
-> commit
-> return payment plus derived paid amount, remaining balance, and status
```

Payments are allowed for `sent` and `overdue` invoices and invoices with a derived partial balance. Draft, cancelled, archived, and fully paid invoices reject payment.

### 9.2 Reverse invoice payment

```text
Authorize
-> resolve tenant-scoped invoice and child payment
-> validate reason
-> begin transaction
-> lock invoice and original payment
-> revalidate that no reversal exists
-> create a full reversal row
-> derive balance/status
-> commit
```

No payment row is edited or deleted. Reversal duplicate protection is the unique `reverses_payment_id`; a reversal does not require a payment-creation idempotency key.

### 9.3 Create routine paid-now manual expense

```text
Authorize expense management
-> validate expense plus payment method/date/reference
-> validate the request idempotency key using the existing convention
-> begin transaction
-> create expense
-> create full settlement carrying the unique shop/idempotency key
-> submit or approve according to the existing high-value control
-> commit
```

The UI presents this as one routine expense form. It does not require the user to visit a separate supplier-settlement workflow. If the same key is submitted twice, settlement uniqueness rolls back the duplicate expense transaction and the API returns the first expense/settlement result.

If approval is pending, incurred-expense KPIs exclude the expense but cash KPIs include its valid settlement because cash actually moved. If the expense is rejected, preserve the settlement, keep the rejected approval state, and surface `paid_rejected_expense` until the existing correction/resubmission process resolves the expense or an authorized user records a real payment reversal/refund/correction. Approval rejection alone never creates financial movement.

### 9.4 Create pay-later expense and settle it

For supplier or other deferred obligations, create the expense without settlement. Settlement locks the expense, confirms it is incurred and payable, recalculates outstanding amount, prevents excess, and appends one settlement. Multiple partial settlements are allowed. Settlement reversals use unique `reverses_settlement_id` rather than a settlement-creation idempotency key.

### 9.5 Procurement obligation

```text
Accepted procurement receipt
-> create one idempotent receipt-backed expense
-> derive due date from a safely recognized payment term
-> mark the expense incurred without generic manual-expense approval
-> expose outstanding balance
```

Unrecognized payment terms produce no guessed due date. Staff may set it explicitly.

### 9.6 Manual expense approval

- Draft to submitted.
- Below the existing shop high-value threshold: one authorized Finance approval.
- At or above the threshold: Shop Owner approval.
- Rejection ends the approval workflow but does not change cash settlement state. A rejected expense with a valid settlement is flagged for reconciliation.
- Procurement and payroll expenses do not enter this generic workflow.
- Each transition runs in a transaction, locks the current approval record, and revalidates state.

### 9.7 Payroll disbursement

```text
Authorize explicit payroll disbursement
-> enforce final approval and actor separation
-> begin transaction and lock payroll
-> revalidate approval and unpaid state
-> mark payroll paid
-> create or resolve payroll expense
-> create full payroll-linked settlement with deterministic source reference
-> commit all changes together
```

The Finance write is no longer best-effort. Payroll disbursement and its Finance outflow succeed or fail together.

### 9.8 Create invoice from job

- Resolve and lock the tenant-scoped job inside a transaction.
- Check for an existing invoice after locking.
- Use the unique shop/job constraint as the final duplicate guard.
- Use the existing reference convention with sufficient uniqueness.
- A repeated request returns the existing invoice rather than creating another.
- No early response may leave an open transaction.

### 9.9 Mark invoice as sent

Rename the operation and UI to **Mark as sent**. It changes only internal lifecycle state and audit history. It does not claim an email or document was delivered.

## 10. Authorization & Tenant Isolation

Every Finance request must pass:

1. Authenticated shop-context resolution.
2. Tenant-scoped record resolution.
3. Capability authorization.

The smallest permission set that closes the identified escalation is:

| Permission | Allowed Finance scope |
|---|---|
| `access-finance-dashboard` | View dashboard summaries |
| `access-finance-invoices` | View and manage invoices, including Finance-owned payment history |
| `access-finance-expenses` | View and manage expenses, receipts, and settlements |
| `access-approval-workflow` plus the existing expense approval rule | Approve/reject manual expenses only; does not grant invoice/expense CRUD by itself |
| `manage-finance-tax` | View and change tax configuration |
| `disburse-payroll` | Disburse finally approved payroll |

Existing refund, shoe-price, repair-price, purchase-request, and payslip permissions remain limited to those workflows. Shop Owner retains full shop access through the existing role convention.

This design does not create separate permissions for invoice create/update/archive/payment or expense create/update/receipt/settlement because the approved gap does not justify that granularity. Controllers or policies still apply state and ownership rules per action.

Permission assignment and migration are explicit:

- Preserve every existing assignment of `access-finance-dashboard`, `access-finance-invoices`, `access-finance-expenses`, and `access-approval-workflow`; do not infer one from another.
- The existing `Finance` role keeps its three Finance page permissions and approval permissions. It receives `manage-finance-tax`, matching its current full-Finance responsibility.
- Shop Owner continues to bypass staff permission checks only within its own tenant and may configure tax or disburse payroll.
- Create `disburse-payroll` but do **not** grant it automatically to the `Finance` role or to holders of `access-payslip-approval`/`access-approval-workflow`. This separation is the security fix.
- Before the payroll route switches, expose `disburse-payroll` in the existing employee access-control screen and let the Shop Owner explicitly assign it to a designated staff disburser. Shop Owner access prevents operational lockout during rollout.
- Produce a deployment report of staff who could disburse under the old broad check. The report is informational and does not copy the unsafe entitlement.
- Existing narrow refund, pricing, purchase-request, and payslip permissions receive none of the Finance page or tax permissions.

Tenant rules:

- Never use an unscoped `findOrFail` for Finance objects.
- Never default missing shop context to Shop 1 or any tenant.
- Missing or ambiguous shop context returns 403.
- A child payment, settlement, or receipt must match both its parent and shop.
- Frontend visibility mirrors authorization but is not enforcement.
- Receipts use private storage and authorized streaming responses.

## 11. API Changes

All active Finance routes use the canonical `/api/finance` surface with existing session authentication, CSRF protection, shop middleware, and capability checks.

### 11.1 Dashboard

**GET `/api/finance/dashboard`**

Purpose: return authoritative primary KPIs, supporting detail, trend, period, and integrity warnings.

Authorization: `access-finance-dashboard` or Shop Owner.

Request: no new P2 date-filter parameters.

Response: decimal strings and explicit metric definitions.

Validation: resolved tenant required.

Side effects: none.

### 11.2 Invoices

| Method and path | Purpose | Important rules |
|---|---|---|
| `GET /api/finance/invoices` | List tenant invoices | Invoice access |
| `POST /api/finance/invoices` | Create standalone invoice | Draft with validated decimal items |
| `GET /api/finance/invoices/{invoice}` | Detail and authoritative payment history | Tenant-scoped child data |
| `PATCH /api/finance/invoices/{invoice}` | Update draft invoice | State revalidated |
| `DELETE /api/finance/invoices/{invoice}` | Archive eligible invoice | No hard delete |
| `POST /api/finance/invoices/{invoice}/restore` | Restore eligible archive | State revalidated |
| `POST /api/finance/invoices/{invoice}/mark-sent` | Internal lifecycle change | No delivery side effect |
| `POST /api/finance/invoices/from-job/{job}` | Idempotent job invoice | One invoice per shop/job |
| `POST /api/finance/invoices/{invoice}/payments` | Record partial/full payment | Lock, balance check, duplicate protection |
| `POST /api/finance/invoices/{invoice}/payments/{payment}/reverse` | Append full reversal | Reason required; one reversal |

Payment request:

```json
{
  "amount": "1000.00",
  "payment_method": "bank_transfer",
  "reference": "BANK-12345",
  "received_at": "2026-08-11T10:00:00+08:00",
  "idempotency_key": "opaque-request-key"
}
```

The request follows the project's existing idempotency-key convention. The key provides duplicate-write protection only and never replaces authentication, tenant scoping, capability authorization, or state validation. The design does not prescribe a new cache/session lifecycle or a UUID format.

### 11.3 Expenses

| Method and path | Purpose | Important rules |
|---|---|---|
| `GET /api/finance/expenses` | List expenses with incurred/settlement state | Expense access |
| `POST /api/finance/expenses` | Create paid-now or pay-later manual expense | Paid-now creates settlement atomically |
| `GET /api/finance/expenses/{expense}` | Detail, receipt metadata, and settlements | Tenant-scoped children |
| `PATCH /api/finance/expenses/{expense}` | Update fields allowed by state | No money-history editing |
| `POST /api/finance/expenses/{expense}/submit` | Submit manual expense | State transition |
| `POST /api/finance/expenses/{expense}/approve` | Approve | Locked transition |
| `POST /api/finance/expenses/{expense}/reject` | Reject approval | Preserves cash settlement; flags paid rejected expense for reconciliation |
| `POST /api/finance/expenses/{expense}/settlements` | Add deferred/partial settlement | Lock and outstanding check |
| `POST /api/finance/expenses/{expense}/settlements/{settlement}/reverse` | Reverse settlement | Full append-only reversal |
| `POST /api/finance/expenses/{expense}/receipt` | Upload private receipt | MIME/size validation and safe server name |
| `GET /api/finance/expenses/{expense}/receipt` | Stream authorized receipt | No public path disclosure |
| `DELETE /api/finance/expenses/{expense}/receipt` | Remove current receipt | Audit actor and prior file metadata |

### 11.4 Tax and payroll

- Tax CRUD remains under `/api/finance/tax-rates` and requires `manage-finance-tax` with explicit tenant resolution.
- Existing payroll disbursement route requires `disburse-payroll`; approval permissions alone cannot satisfy it.

### 11.5 Errors

Expected API codes include `FORBIDDEN`, `TENANT_CONTEXT_REQUIRED`, `INVALID_STATE`, `AMOUNT_EXCEEDS_BALANCE`, `ALREADY_REVERSED`, `DUPLICATE_SUBMISSION`, and `INTEGRITY_WARNING`. Full exceptions are logged with correlation context; clients receive stable generic messages.

### 11.6 Removed endpoints

During the documented compatibility window, stale write endpoints return 410, then are removed:

- Invoice `post`
- Invoice `mark-paid`
- Session-alias Finance routes
- Legacy Finance API variants
- Balance sheet, P&L, trial balance, AR aging, and AP aging routes

## 12. UI Changes

### 12.1 Dashboard

Keep the existing layout. Display four primary KPI cards: net revenue, incurred expenses, net operating result, and net cash movement. Place gross revenue, refunds, and paid expenses in supporting detail. Show the active period and short definitions. Remove hard-coded comparison percentages.

Loading, empty, permission-denied, error, and integrity-warning states must be explicit. No full redesign or new reporting navigation is included.

### 12.2 Invoice page

- Rename **Send** to **Mark as sent**.
- Remove **Post to ledger**.
- Replace **Mark as paid** with **Record payment**.
- Show derived paid amount, remaining balance, and `unpaid`/`partially paid`/`paid` status.
- Show immutable payment/reversal history.
- Require confirmation for payment and reversal; require a reversal reason.
- For invoices whose payments belong to an operational module, show read-only source payment history and hide manual recording.
- Surface integrity warnings separately from operational status.

### 12.3 Expense page

- The create form defaults to a simple paid-now option with payment method/date/reference fields.
- A pay-later option reveals due date and creates no settlement.
- Show incurred/approval state separately from unpaid/partial/paid settlement state.
- Show paid amount and outstanding balance.
- Provide partial settlement and reversal actions only for authorized Finance expense users.
- A rejected expense with unreversed settlement shows a prominent reconciliation warning; rejection does not present or trigger a financial reversal.
- Keep procurement identifiers read-only and leave procurement workflow actions in Procurement.
- Keep payroll-linked expenses read-only because Payroll owns disbursement.

All forms show field validation, retryable generic errors, and conflict messages when state changed concurrently.

## 13. Concurrency & Transaction Integrity

| Workflow | Transaction | Lock/revalidation | Duplicate guard |
|---|---:|---|---|
| Job to invoice | Yes | Lock job; re-query invoice | Unique shop/job |
| Invoice payment | Yes | Lock invoice; recalculate balance | Existing idempotency key plus unique shop/key |
| Payment reversal | Yes | Lock invoice and payment | Unique reversed payment |
| Paid-now expense | Yes | Expense and settlement created together | Existing idempotency key stored uniquely on settlement; duplicate transaction rolls back |
| Deferred settlement | Yes | Lock expense; recalculate outstanding | Existing idempotency key for UI; stable source reference for integrations |
| Settlement reversal | Yes | Lock expense and settlement | Unique reversed settlement |
| Approval transition | Yes | Lock approval and revalidate stage | Existing review/state uniqueness |
| Payroll disbursement | Yes | Lock payroll and revalidate final approval | Paid guard plus deterministic payroll source reference |

Refund processing is not redesigned. Existing execution must gain a regression test proving one execution contributes once. A correctness fix needed to satisfy that existing invariant is a **Required implementation dependency — not a new product feature**.

## 14. Cleanup / Removal

Remove or consolidate after active callers migrate:

- Invoice post-to-ledger route, controller logic, model methods, UI action, and `posted` types.
- Active journal relationships and stale `journal_entry_id`/`account_id` application references.
- Broken accounting-report routes and `FinancialReportController`.
- Unused account, journal, budget, and generic transaction hooks in `useFinanceQueries.ts`.
- Duplicate legacy and session Finance route families.
- Retired alternate procurement-expense creation methods.
- Tombstone Finance approval page and empty notification utility after reference confirmation.
- `localStorage` bearer-token fallback from the session-authenticated Finance API helper.
- Stale Finance comments and TypeScript statuses/fields.
- Generic four-stage manual-expense workflow, replaced by one approval with existing high-value owner escalation.

Do not edit historical migrations for cosmetic cleanup. Do not drop old tables in the same phase merely because their active code is removed; any physical table retirement requires a later forward migration and production-data confirmation.

## 15. Migration & Backfill

### Phase 1: P0 security and calculation correctness

- Apply narrow route permissions and tenant-scoped resolution.
- Remove the Shop 1 tax fallback.
- Move new receipts to private storage.
- Migrate existing receipt files with a resumable command that records missing files without deleting metadata; keep authorized access compatible during migration.
- Deploy backend summary calculations and remove fake comparisons.
- Add P0 isolation and exact-money tests.

Gate: cross-shop access is denied and fixed monetary fixtures match expected results.

### Phase 2: P1 money-state integrity

- Create payment and settlement tables.
- Add expense due dates.
- Add job-invoice uniqueness after duplicate audit.
- Classify every historical invoice marked paid before backfill:
  - A standalone invoice receives one `legacy_migration` payment.
  - A linked invoice with an authoritative order/POS/repair payment receives no Finance payment row; its originating payment remains the balance and history source.
  - A linked invoice whose expected originating payment is missing receives one `legacy_migration` fallback payment plus `legacy_source_missing` integrity warning. That fallback temporarily drives invoice balance and revenue until the source is reconciled; it is excluded as soon as an authoritative linked payment exists.
- Each created `legacy_migration` payment follows these rules:
  - Amount equals invoice total.
  - Preserve existing payment date and method.
  - Fall back to invoice `updated_at` when date is missing.
  - Use `legacy_unknown` when method is missing.
  - Leave actor nullable and source explicit.
- Backfill procurement due dates only for safely recognized terms such as explicit Net-N values.
- Create payroll settlements only where completed payroll disbursement is authoritative.
- Do not assume an approved historical manual expense was paid.
- Report historical excess payments/settlements as integrity exceptions; do not silently clamp or discard them.

Before adding the job-invoice unique constraint, produce a duplicate report. The migration must not silently delete or merge financial records; unresolved duplicates block the constraint until reconciled.

Gate: every historical paid invoice is covered by exactly one authoritative linked source or one migration fallback, derived balances reconcile, and all integrity exceptions are visible.

### Phase 3: route and scaffolding cleanup

- Move all frontend callers to canonical routes.
- Stop compatibility writes to invoice payment columns.
- Remove compatibility routes and stale active code listed in Section 14.
- Drop deprecated columns only through a later forward migration after deployment validation.

No P2/P3 product work is included in this phase.

## 16. Testing & Verification

Tests target behavior and invariants rather than service internals.

### P0

- Cross-shop receipt upload, download, replacement, and deletion return 403/404 without revealing existence.
- Cross-shop invoice, expense, payment, settlement, and tax access fails.
- Refund/price/purchase approval permissions cannot call invoice or expense management APIs or view the dashboard.
- Missing tenant context returns 403 and never reads/writes Shop 1.
- Full and partial refunds reduce revenue exactly once.
- Draft, pending, rejected, voided, and archived expenses do not enter incurred totals.
- Paid expenses use valid settlements only.
- VAT and shop-retained delivery fee treatment match fixed examples.
- Online-order fixtures prove discounted `total_amount`, separate VAT/shipping, and unallocated order-item prices are not double-counted.
- Retail POS fixtures prove `subtotal + tax_amount = total_amount = paid_amount` for the current flow without recomputing from mutable order state.
- Repair POS fixtures prove service VAT and delivery treatment from the stored metadata/source breakdown.
- Refund fixtures use successful `amount`/`refunded_at` for orders and `execution_amount`/`executed_at` for POS; requested or approved amounts never enter executed totals.
- Dashboard primary and supporting metrics reconcile to the contract.

### P1

- Partial invoice payment derives exact paid amount, balance, and status.
- Concurrent or repeated duplicate submission creates one effective payment.
- Payment exceeding remaining balance fails.
- Full reversal restores balance and preserves both rows.
- Historical excess produces an integrity warning, not a normal status.
- Paid-now expense creates expense and settlement atomically.
- Paid-now rejection preserves the settlement, excludes the expense from incurred totals, includes the actual cash movement in paid/cash totals, and exposes `paid_rejected_expense` until resolved.
- Pay-later expense supports multiple partial settlements.
- Concurrent settlement cannot exceed expense amount.
- Reversal restores outstanding amount.
- Duplicate job requests resolve to one invoice.
- Concurrent approval attempts advance one stage once.
- Payroll disbursement and Finance expense/settlement all commit or all roll back.
- A payroll approver without `disburse-payroll` cannot disburse.
- Historical paid standalone/source-orphan invoices receive one migration-marked payment; source-linked invoices retain one operational authority without a duplicate Finance payment.
- Raw exception details never appear in API responses.
- Removed routes are unavailable after their compatibility window.

Money assertions use exact decimal strings or integer minor units. Tests must not compare financial values through binary floating-point approximations.

Concrete implementation verification will include the narrow Finance feature tests first, then relevant payroll, procurement, order/refund, frontend tests, build, `git diff --check`, and broader Laravel tests when practical. The current missing test application key and stale compiled-view warnings must be repaired before a green suite can be claimed.

## 17. Risks & Edge Cases

- **Ambiguous historical paid records:** migration explicitly labels inferred payments and reports inconsistent totals.
- **Source-linked invoice duplication:** revenue precedence excludes documentary invoice payments when an operational source owns collection.
- **Routine expense rejected after recording cash:** preserve the settlement and surface `paid_rejected_expense`. Create a reversal only after evidence that the payment itself was reversed, refunded, or corrected.
- **Unparseable supplier terms:** leave due date blank rather than guessing.
- **Partial refund composition:** use existing refund line/fee evidence; if an executed legacy refund lacks enough detail, flag it for integrity review rather than silently applying an arbitrary VAT allocation.
- **Receipt file missing during private-storage migration:** retain metadata, log the missing source, and show a controlled unavailable state.
- **Concurrent users:** locked revalidation returns a conflict or current state instead of overwriting.
- **Legacy route consumers:** inventory all callers before the compatibility window ends; do not preserve parallel routes indefinitely.
- **Soft-deleted financial parents:** payment/settlement history remains queryable to authorized users; application hard deletion is prohibited.

## 18. Acceptance Criteria

### P0

1. Every Finance endpoint resolves an explicit shop and tenant-scoped object before acting.
2. A user from one shop cannot read or mutate another shop's Finance data or receipts.
3. A narrow refund, price, purchase, or payslip approval permission grants no unrelated Finance access.
4. Tax operations fail with 403 when shop context is missing and never default to another tenant.
5. Dashboard calculations are server-side, decimal-safe, and covered by exact examples.
6. Each sale and executed refund contributes once.
7. Expense totals exclude non-incurred states.
8. Hard-coded comparison values are removed.
9. Primary owner KPIs remain concise, with accounting detail kept secondary.

### P1

10. Standalone invoices support partial immutable payments and derived balances.
11. Payment corrections use full reversal plus replacement; no payment row is edited or deleted.
12. Duplicate/concurrent payment submission cannot create duplicate effective money state.
13. Historical overpayment is surfaced as an integrity warning and blocks new payment.
14. Deferred expenses support due dates and multiple partial settlements.
15. Routine paid-now expenses create their settlement in the same transaction and require no separate AP-like workflow.
16. Approval rejection never invents a settlement reversal; actual payment corrections preserve immutable reversal history.
17. One repair job cannot create multiple Finance invoices.
18. Approval transitions advance once under concurrency.
19. Manual expense approval uses one Finance approval with existing high-value Shop Owner escalation.
20. Payroll disbursement requires its own explicit capability and commits payroll, expense, and settlement atomically.
21. Raw server exception details are never returned to clients.
22. Existing paid invoices are safely backfilled without silently discarding inconsistencies.
23. The active application no longer presents ledger posting or broken accounting reports.
24. One canonical Finance API remains after the migration window.

## 19. Deferred / Optional Items

The following approved P2/P3 ideas remain outside this implementation and require separate approval before design or planning:

- Dashboard date-range presets and previous-period comparisons
- Server-side CSV/report export refinements
- Payment-provider payout reconciliation
- Finance exception/action queue
- Receipt OCR
- Category budgeting

Their deferral must not leave placeholders, speculative tables, permissions, routes, or abstractions in the core implementation.
