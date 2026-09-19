# Finance Integrity and Operational Payments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved SME Finance integrity design in three gated phases: secure and correct the existing module, add immutable payment/settlement state, then remove superseded routes and accounting scaffolding.

**Architecture:** Keep operational modules authoritative for their own sales, refunds, procurement receipts, and payroll disbursements. Add two purpose-specific append-only money-history tables, focused transaction services, one explicit Finance shop-context boundary, and one backend summary service; retain compatibility reads/writes only until backfill and caller migration are verified. Every money mutation runs inside a tenant-scoped transaction with row locking, database uniqueness, and state revalidation.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Spatie Permission/Activitylog, Inertia 2, React 18, TypeScript 5.7, TanStack Query, Vitest, Tailwind CSS 4, PHPUnit, SQLite tests, MySQL production.

---

## 1. Implementation Overview

Source of truth: `docs/superpowers/specs/2026-08-11-finance-integrity-design.md`.

Implementation is deliberately sequential:

1. **Phase 1 — P0 Correctness & Security:** lock down tenant/capability boundaries, move receipts to private storage, make tax tenant-explicit, encode the approved monetary fixtures, and replace client-side dashboard arithmetic with one backend contract.
2. **Phase 2 — P1 Money-State Integrity:** add immutable invoice payments and expense settlements, transactional paid-now/pay-later behavior, approval locking, repair-job uniqueness, atomic payroll synchronization, and explicit historical backfills/integrity reports.
3. **Phase 3 — Consolidation & Cleanup:** migrate every active caller to `/api/finance`, serve a short 410 compatibility window, then delete ledger/report/session/legacy code after reference and route inventories are clean.

Use `@superpowers:test-driven-development` for each behavior change, `@laravel-best-practices` for Laravel work, `@security-review` for every Finance endpoint and receipt path, `@vercel-react-best-practices` for changed React code, `@ponytail` for the simplification pass, and `@superpowers:verification-before-completion` before any completion claim.

### Planned file map

**New backend domain files**

- `app/Support/Finance/FinanceShopContext.php` — resolve the authenticated Finance tenant once and fail closed.
- `app/Services/Finance/FinanceSummaryService.php` — current-year KPIs, six-month trend, exact source precedence, and integrity warnings.
- `app/Services/Finance/InvoicePaymentService.php` — locked payment/reversal creation and replay validation.
- `app/Services/Finance/ExpenseSettlementService.php` — paid-now/deferred settlement/reversal transactions.
- `app/Http/Controllers/Api/Finance/FinanceSummaryController.php` — thin dashboard API adapter.
- `app/Models/Finance/InvoicePayment.php` and `app/Models/Finance/ExpenseSettlement.php` — append-only records and relationships.
- `app/Console/Commands/MigrateFinanceReceiptsToPrivateStorage.php` — resumable receipt copy/verification command.
- `app/Console/Commands/AuditFinanceIntegrity.php` — read-only duplicate, overpayment, ambiguous history, and legacy disburser report.
- `app/Console/Commands/BackfillFinanceMoneyHistory.php` — resumable deterministic payment, due-date, and payroll settlement backfill.
- `database/migrations/2026_08_11_000001_create_finance_money_history_tables.php` — payment/settlement tables and expense due date.
- `database/migrations/2026_08_11_000002_add_finance_job_invoice_unique_constraint.php` — guarded `(shop_id, job_order_id)` uniqueness after audit.

**New focused tests**

- `tests/Feature/Finance/FinanceTenantAuthorizationTest.php`
- `tests/Feature/Finance/FinanceReceiptSecurityTest.php`
- `tests/Feature/Finance/FinanceTaxRateAuthorizationTest.php`
- `tests/Feature/Finance/FinanceErrorResponseTest.php`
- `tests/Feature/Finance/FinanceSummaryTest.php`
- `tests/Feature/Finance/InvoicePaymentTest.php`
- `tests/Feature/Finance/ExpenseSettlementTest.php`
- `tests/Feature/Finance/FinanceConcurrencyTest.php`
- `tests/Feature/Finance/FinanceBackfillCommandTest.php`
- `tests/Feature/Finance/FinanceRouteContractTest.php`
- `tests/Feature/Finance/PayrollDisbursementFinanceSyncTest.php`
- `resources/js/Pages/ERP/Finance/__tests__/Dashboard.test.tsx`
- `resources/js/Pages/ERP/Finance/__tests__/Invoice.payments.test.tsx`
- `resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx`

**Primary existing files to modify**

- `routes/finance-api.php`, `routes/api.php`, `routes/web.php`
- `app/Http/Middleware/ShopIsolationMiddleware.php`
- `app/Http/Controllers/Api/Finance/InvoiceController.php`
- `app/Http/Controllers/Api/Finance/ExpenseController.php`
- `app/Http/Controllers/Api/Finance/TaxRateController.php`
- `app/Http/Controllers/Erp/HR/PayrollController.php`
- `app/Http/Controllers/ShopOwner/UserAccessControlController.php`
- `app/Models/Finance/Invoice.php`, `app/Models/Finance/Expense.php`
- `app/Services/ExpenseApprovalService.php`, `app/Services/PurchaseOrderReceiptService.php`
- `database/seeders/RolesAndPermissionsSeeder.php`, `database/seeders/PositionSeeder.php`
- `resources/js/hooks/useFinanceApi.ts`, `resources/js/hooks/useFinanceQueries.ts`
- `resources/js/Pages/ERP/Finance/Dashboard.tsx`
- `resources/js/Pages/ERP/Finance/Invoice.tsx`
- `resources/js/Pages/ERP/Finance/Expense.tsx`
- `resources/js/Pages/ERP/Finance/createInvoice.tsx`
- `resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx`
- `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx`

## 2. Preconditions / Known Existing Issues

- The Finance test suite currently needs a valid `APP_KEY`; there is no committed `.env.testing`. Before implementation, run tests with an ephemeral process-level key, for example:

```powershell
$env:APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='; php artisan test tests/Feature/Finance
```

  Do not edit `.env`. A green result may not be claimed without this prerequisite.
- Existing test runs can emit stale compiled-view path warnings. Clear test caches with `php artisan optimize:clear` if they obscure results; do not treat warnings as passing evidence.
- `ShopIsolationMiddleware` currently injects `user_shop_id`, while Finance controllers repeatedly derive `shop_owner_id` themselves and do not consistently support the Shop Owner role convention. Task 1 makes this one explicit boundary; no task may use client `shop_id` as authority.
- Existing POS idempotency is a stored key plus database uniqueness and replay lookup (`RetailPosPaymentService`, `RepairPosPaymentService`). Finance reuses that convention. A replay must additionally compare the stored parent/money fields with the new request and return `409 DUPLICATE_SUBMISSION` on a materially different payload.
- Existing Finance references are globally unique. This plan does not change their business meaning or introduce a new numbering system.
- The approved calculation semantics are already verified by the design. Implementation must encode them as fixed fixtures before replacing dashboard queries; it must not reopen those decisions or infer from similarly named fields.
- Before Task 14 adds the job-invoice unique constraint, `php artisan finance:audit-integrity` must report zero unresolved duplicate `(shop_id, job_order_id)` groups. The migration must abort otherwise.
- No implementation constraint requiring spec attention was found during planning. The existing `shop_owners.high_value_threshold`, POS idempotency fields, shop-scoped Finance models, procurement receipt link, payroll disbursement lock, and permission-management surface are sufficient to implement the approved contract without redesign.

## 3. Phase 1 — P0 Correctness & Security

### Task 1: Centralize fail-closed Finance shop context

**Goal:** Make explicit tenant resolution precede every Finance query and mutation, satisfying P0 criteria 1, 2, and 4.

**Affected files:** Create `app/Support/Finance/FinanceShopContext.php` and `tests/Feature/Finance/FinanceTenantAuthorizationTest.php`; modify `app/Http/Middleware/ShopIsolationMiddleware.php`, `routes/finance-api.php`, the three core Finance controllers, the Finance dashboard/repair-refund routes in `routes/web.php`, and—only where the new tests expose missing scope—the existing Finance-prefixed operational controllers `app/Http/Controllers/Erp/PurchaseRequestController.php`, `app/Http/Controllers/Api/PriceChangeRequestController.php`, `app/Http/Controllers/Api/RepairServiceController.php`, `app/Http/Controllers/Api/RefundApprovalController.php`, `app/Http/Controllers/Api/RepairRefundWorkflowController.php`, `app/Http/Controllers/Api/Finance/PayslipApprovalController.php`, and `app/Http/Controllers/Erp/HR/AuditLogController.php`.

**Database impact:** None.

- [ ] Generate the exact active `/api/finance` inventory with `php artisan route:list --json`. Add a data-provider assertion that every currently active route has authenticated session handling and `shop.isolation`; include dashboard, invoices, expenses, receipts, tax, audit logs, purchase requests, shoe/repair pricing, order/repair refunds, payslip approval, and payroll disbursement. Task 2 separately asserts the final capability middleware after it performs the split.
- [ ] Write failing tests for an employee with a shop, a Shop Owner under the existing role convention, a user with no shop, and attempts to read/update another tenant's currently existing invoice, expense, receipt, tax rate, audit record, purchase request, price change, repair service price change, order/repair refund, payslip, and payroll. Assert 403/404 without existence disclosure and prove Shop 1 is never used as fallback. Reuse the existing domain feature-test fixture helpers rather than duplicating each workflow. Payment/settlement child tests begin only after their schema/routes exist in Tasks 9–11.
- [ ] Run `php artisan test tests/Feature/Finance/FinanceTenantAuthorizationTest.php`; expect failures from inconsistent controller resolution.
- [ ] Implement the smallest resolver API:

```php
final class FinanceShopContext
{
    public function id(Request $request): int;
}
```

  Resolve only the authenticated `user` actor: `shop_owner_id`, or the actor's own ID when it has the existing `Shop Owner` role. Reject missing/ambiguous context with `TENANT_CONTEXT_REQUIRED`; ignore request `shop_id` as authority.
- [ ] Inject/use the resolver before building queries in `InvoiceController`, `ExpenseController`, `TaxRateController`, and `FinanceSummaryController`/the temporary dashboard closure. For operational Finance-prefixed routes, retain their authoritative domain tenant column but make the controller resolve and constrain the parent record before any child lookup or state change. Keep explicit tenant predicates even where a global/domain scope is defense in depth.
- [ ] Tighten `ShopIsolationMiddleware` so its attached context and comparisons use the same resolved ID for both supported actor shapes.
- [ ] Re-run the focused test; expect PASS.
- [ ] Commit with `git add -- app/Support/Finance/FinanceShopContext.php app/Http/Middleware/ShopIsolationMiddleware.php routes/finance-api.php app/Http/Controllers/Api/Finance app/Http/Controllers/Api/PriceChangeRequestController.php app/Http/Controllers/Api/RepairServiceController.php app/Http/Controllers/Api/RefundApprovalController.php app/Http/Controllers/Api/RepairRefundWorkflowController.php app/Http/Controllers/Erp/PurchaseRequestController.php app/Http/Controllers/Erp/HR/AuditLogController.php tests/Feature/Finance/FinanceTenantAuthorizationTest.php routes/web.php` then `git commit -m "fix: centralize finance tenant resolution"`.

**Verification:** Focused PHPUnit command above; `php artisan route:list --path=api/finance` still loads.

**Acceptance criteria:** P0-1, P0-2, P0-4.

### Task 2: Split Finance capabilities and stage the two new permissions

**Goal:** Prevent approval/refund/pricing permissions from escalating into unrelated Finance operations while adding only `manage-finance-tax` and `disburse-payroll`.

**Affected files:** Modify `routes/finance-api.php`, `routes/api.php`, `routes/web.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `database/seeders/PositionSeeder.php`, `app/Http/Controllers/ShopOwner/UserAccessControlController.php`, and `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx`; extend `tests/Feature/Finance/FinanceTenantAuthorizationTest.php` and create `tests/Feature/Finance/FinanceTaxRateAuthorizationTest.php`.

**Database impact:** Seeder adds two Spatie permissions. Existing assignments remain unchanged; Finance receives `manage-finance-tax`, nobody receives `disburse-payroll` automatically.

- [ ] Write failing table-driven authorization tests proving each retained permission grants only its approved scope and that refund, price, purchase-request, payslip-approval, and approval-workflow users cannot open dashboard/invoice/expense/tax APIs. Extend the Task 1 route inventory assertion to check each route's final exact capability middleware after this task splits the groups; leave payroll on its staged old authorization until Task 13's test and rollout gate switch it.
- [ ] Seed `manage-finance-tax` and `disburse-payroll`. Add only `manage-finance-tax` to the `Finance` role; preserve all existing assignments and never infer one permission from another.
- [ ] Update `UserAccessControlController::isFinancePermission()` and its grouping logic so both permissions appear in the Finance group; the React screen should render the server-provided permission without hard-coded grants.
- [ ] Split `routes/finance-api.php` into narrow groups: dashboard, invoices, expenses, tax, and the existing unrelated approval routes. Apply `access-approval-workflow` only to manual expense approve/reject routes together with the existing approval rule; do not place it on CRUD/payment routes.
- [ ] Keep `disburse-payroll` visible but do not switch the payroll route until Task 13 has produced the old-access report and atomic sync.
- [ ] Run the two focused test files; expect PASS.
- [ ] Commit with `git add -- routes database/seeders app/Http/Controllers/ShopOwner/UserAccessControlController.php resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx tests/Feature/Finance` then `git commit -m "fix: narrow finance capabilities"`.

**Verification:** `php artisan test tests/Feature/Finance/FinanceTenantAuthorizationTest.php tests/Feature/Finance/FinanceTaxRateAuthorizationTest.php`; inspect `php artisan route:list --path=api/finance -v`.

**Acceptance criteria:** P0-1, P0-3, P0-4; stages P1-20 without enabling it early.

### Task 3: Make tax operations explicitly tenant-scoped

**Goal:** Remove every missing-tenant fallback and make tax access require `manage-finance-tax`.

**Affected files:** Modify `app/Http/Controllers/Api/Finance/TaxRateController.php`, `routes/finance-api.php`, `routes/api.php`, `routes/web.php`, `resources/js/hooks/useFinanceQueries.ts`, and `resources/js/Pages/ERP/Finance/createInvoice.tsx`; extend `tests/Feature/Finance/FinanceTaxRateAuthorizationTest.php`.

**Database impact:** None.

- [ ] Write failing tests for missing tenant, cross-shop tax ID, narrow Finance permission without tax capability, Finance role, and Shop Owner access.
- [ ] Replace every controller fallback/default with `FinanceShopContext::id()` and tenant-scoped `TaxRate::where('shop_id', $shopId)` resolution before show/update/delete/calculate/default/effective actions.
- [ ] Put canonical tax endpoints under `/api/finance/tax-rates` with `manage-finance-tax`; retain existing aliases only for the compatibility window.
- [ ] Move `useTaxRates()` and the invoice form caller to the canonical path and preserve current empty/error UI behavior without swallowing 403 as an empty tax list.
- [ ] Run the focused tests and the invoice form frontend test subset; expect PASS.
- [ ] Commit with `git add -- app/Http/Controllers/Api/Finance/TaxRateController.php routes resources/js/hooks/useFinanceQueries.ts resources/js/Pages/ERP/Finance/createInvoice.tsx tests/Feature/Finance/FinanceTaxRateAuthorizationTest.php` then `git commit -m "fix: scope finance tax rates by tenant"`.

**Verification:** Focused PHPUnit test; `pnpm run test:frontend -- resources/js/Pages/ERP/Finance`.

**Acceptance criteria:** P0-1, P0-2, P0-4.

### Task 4: Secure receipt storage and migrate existing files resumably

**Goal:** Prevent cross-tenant receipt access and public-path disclosure while preserving existing metadata and missing-file evidence.

**Affected files:** Create `app/Console/Commands/MigrateFinanceReceiptsToPrivateStorage.php`, `tests/Feature/Finance/FinanceReceiptSecurityTest.php`, and `tests/Feature/Finance/FinanceReceiptMigrationCommandTest.php`; modify `app/Http/Controllers/Api/Finance/ExpenseController.php`, `routes/finance-api.php`, `routes/api.php`, `routes/web.php`, `resources/js/Pages/ERP/Finance/Expense.tsx`, and `resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx`.

**Database impact:** No schema change. Existing `receipt_path` metadata is retained; files are copied from `public` to private `local` storage before public copies are removed in a later verified deployment step.

- [ ] Write failing tests covering cross-shop upload/download/replace/delete, safe generated filenames, allowed MIME/size, authorized streaming, missing private file, and audit metadata captured before clearing fields.
- [ ] Resolve the tenant-scoped expense before validation or storage access. Store new receipts on `Storage::disk('local')` under a shop/expense-scoped server-generated path; retain the original name only as metadata/download name.
- [ ] Stream through an authorized controller response. Never return a physical path or public URL.
- [ ] Implement `finance:migrate-receipts-private {--dry-run} {--chunk=100}`. For each metadata row: skip an already verified private target, copy and checksum a present public source, report but do not clear missing metadata, and never delete a source in the same command run.
- [ ] Update both receipt links to `GET /api/finance/expenses/{expense}/receipt`.
- [ ] Run focused tests; expect PASS.
- [ ] Commit with `git add -- app/Console/Commands/MigrateFinanceReceiptsToPrivateStorage.php app/Http/Controllers/Api/Finance/ExpenseController.php routes resources/js/Pages/ERP/Finance/Expense.tsx resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx tests/Feature/Finance` then `git commit -m "fix: protect finance expense receipts"`.

**Verification:** `php artisan test tests/Feature/Finance/FinanceReceiptSecurityTest.php tests/Feature/Finance/FinanceReceiptMigrationCommandTest.php`; `php artisan finance:migrate-receipts-private --dry-run` on a production snapshot before rollout.

**Acceptance criteria:** P0-1, P0-2.

### Task 5: Encode authoritative source semantics as fixed fixtures

**Goal:** Freeze the approved online-order, retail POS, repair POS, standalone-invoice, order-refund, and POS-refund monetary meanings before dashboard replacement.

**Affected files:** Create `tests/Feature/Finance/FinanceSummaryTest.php`; modify only existing fixture helpers when reuse is needed in `tests/Feature/CheckoutPromoPricingTest.php`, `tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php`, `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`, `tests/Feature/RetailPosRefundFlowTest.php`, `tests/Feature/RepairPosPaymentFlowTest.php`, and `tests/Feature/ShopOwnerDashboardRevenueTest.php`.

**Database impact:** Test data only.

- [ ] Add fixed decimal fixtures for every row in specification §8.2, including discounted order totals, separate VAT/shipping, unallocated order-item prices, retail POS identity, repair service VAT plus delivery metadata, standalone invoice tax basis, successful refund terminal fields, and ignored requested/approved refund amounts.
- [ ] Add negative fixtures for missing terminal timestamps, inconsistent invoice basis, and insufficient legacy refund allocation. Expected result is a named integrity warning plus exclusion of only the ambiguous component.
- [ ] Add exactly-once fixtures where refunded sales keep original gross contribution and the successful refund is deducted once.
- [ ] Run the focused source tests; expect failures because no summary service exists.
- [ ] Commit only the red fixtures with `git add -- tests/Feature/Finance/FinanceSummaryTest.php tests/Feature/CheckoutPromoPricingTest.php tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosRefundFlowTest.php tests/Feature/ShopOwnerDashboardRevenueTest.php` then `git commit -m "test: freeze finance source semantics"`.

**Verification:** The focused command must fail for missing behavior, not malformed setup.

**Acceptance criteria:** Required dependency for P0-5, P0-6, P0-7.

### Task 6: Implement the backend Finance calculation contract

**Goal:** Produce decimal-safe, exactly-once current-year KPIs and the existing six-month trend from authoritative records.

**Affected files:** Create `app/Services/Finance/FinanceSummaryService.php` and `app/Http/Controllers/Api/Finance/FinanceSummaryController.php`; modify `routes/finance-api.php` and `routes/web.php`; complete `tests/Feature/Finance/FinanceSummaryTest.php`.

**Database impact:** Read-only queries.

- [ ] Implement a single public boundary:

```php
final class FinanceSummaryService
{
    /** @return array{period: array, primary: array, supporting: array, trend: array, definitions: array, integrity_warnings: array} */
    public function forCurrentPeriod(int $shopId, CarbonImmutable $now): array;
}
```

- [ ] Use half-open application-timezone ranges. Keep money as database decimal strings or integer cents through allocation/summation; format API values as two-decimal strings.
- [ ] Apply the exact precedence keys from §8.2. Count completed POS first, then authoritative order/repair payments, then standalone invoice payments, then warned linked legacy fallback only when its operational source is absent.
- [ ] Reuse the existing shop-owned delivery convention (`carrier_company = Shop-owned logistics` for online orders and stored repair POS metadata delivery method) rather than inventing an integration abstraction.
- [ ] Implement proportional standalone invoice revenue allocation with final-payment cent reconciliation. Exclude invalid zero/inconsistent bases with `INTEGRITY_WARNING`.
- [ ] Use successful `OrderRefund.amount/refunded_at` and `PosRefund.execution_amount/executed_at`; never use requested/approved amounts as executed cash. Reuse the explicit `OrderRefundService` Finance shipping-decision marker stored in `reason_note`; if an old row has no reliable included/retained decision, emit `legacy_refund_allocation` and apply only the documented fixture-covered fallback instead of guessing from line prices.
- [ ] Include only approved manual, valid procurement, and completed-payroll expenses in incurred totals; paid-expense/cash totals come only from valid settlement rows once Phase 2 is present. Until then, return paid-expense as `0.00` behind a clearly named `Schema::hasTable('finance_expense_settlements')` compatibility branch removed in Task 11—do not infer payment from approval.
- [ ] Add `GET /api/finance/dashboard` with dashboard capability and stable generic errors. Replace the long dashboard data-building closure in `routes/web.php` with a page render only.
- [ ] Run `FinanceSummaryTest`; expect all exact string assertions and warnings to pass.
- [ ] Commit with `git add -- app/Services/Finance/FinanceSummaryService.php app/Http/Controllers/Api/Finance/FinanceSummaryController.php routes tests/Feature/Finance/FinanceSummaryTest.php` then `git commit -m "feat: calculate authoritative finance summary"`.

**Verification:** `php artisan test tests/Feature/Finance/FinanceSummaryTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosRefundFlowTest.php tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php`.

**Acceptance criteria:** P0-5, P0-6, P0-7.

### Task 7: Make the dashboard a thin owner-facing presentation

**Goal:** Render four primary KPIs and supporting detail from the backend contract without client financial arithmetic or fake percentages.

**Affected files:** Modify `resources/js/Pages/ERP/Finance/Dashboard.tsx`; create `resources/js/Pages/ERP/Finance/__tests__/Dashboard.test.tsx`; modify `resources/js/hooks/useFinanceQueries.ts` only if the page fetches instead of receiving the summary as an Inertia prop.

**Database impact:** None.

- [ ] Write a failing component test with decimal-string summary data and warning states. Assert the four primary cards, three supporting figures, period/definitions, six-month trend, and absence of hard-coded percentages or “statutory profit.”
- [ ] Delete `parseAmount`, `resolveInvoiceNetRevenue`, invoice/refund/expense reductions, month grouping, `change`, and arrow comparison UI from the page.
- [ ] Render `net_revenue`, `incurred_expenses`, `net_operating_result`, and `net_cash_movement` as primary cards; render gross revenue, executed refunds, and paid expenses below as supporting detail.
- [ ] Add explicit loading, empty, forbidden, generic error, and integrity-warning states. Warnings identify affected components without exposing exception text.
- [ ] Feed ApexCharts only the backend trend series; do not recalculate values in React.
- [ ] Run the focused frontend test and build; expect PASS.
- [ ] Commit with `git add -- resources/js/Pages/ERP/Finance/Dashboard.tsx resources/js/Pages/ERP/Finance/__tests__/Dashboard.test.tsx resources/js/hooks/useFinanceQueries.ts` then `git commit -m "fix: render backend finance metrics"`.

**Verification:** `pnpm run test:frontend -- resources/js/Pages/ERP/Finance/__tests__/Dashboard.test.tsx`; `pnpm run build`.

**Acceptance criteria:** P0-5, P0-8, P0-9.

### Task 8: Standardize safe Finance API errors

**Goal:** Stop returning raw exception messages while retaining logged correlation context and actionable stable codes.

**Affected files:** Create `tests/Feature/Finance/FinanceErrorResponseTest.php`; modify `app/Http/Controllers/Api/Finance/InvoiceController.php`, `ExpenseController.php`, `TaxRateController.php`, `FinanceSummaryController.php`, `app/Http/Controllers/Erp/HR/PayrollController.php`, and every active Finance-prefixed operational controller from Task 1: `app/Http/Controllers/Erp/PurchaseRequestController.php`, `app/Http/Controllers/Api/PriceChangeRequestController.php`, `app/Http/Controllers/Api/RepairServiceController.php`, `app/Http/Controllers/Api/RefundApprovalController.php`, `app/Http/Controllers/Api/RepairRefundWorkflowController.php`, `app/Http/Controllers/Api/Finance/PayslipApprovalController.php`, and `app/Http/Controllers/Erp/HR/AuditLogController.php`.

**Database impact:** None.

- [ ] Add failing data-provider tests that force or mock a query/storage/service exception through each active Finance-prefixed controller group and assert the response contains a generic message plus one approved code, never SQL, paths, stack traces, or `$e->getMessage()`.
- [ ] Map expected domain failures to `FORBIDDEN`, `TENANT_CONTEXT_REQUIRED`, `INVALID_STATE`, `AMOUNT_EXCEEDS_BALANCE`, `ALREADY_REVERSED`, `DUPLICATE_SUBMISSION`, or `INTEGRITY_WARNING`.
- [ ] Log the full exception server-side with actor ID, shop ID, operation, record ID, and Laravel request/correlation ID when available.
- [ ] Remove raw `error`/`message` exception payloads from all active Finance-prefixed catch blocks without creating a new global exception framework. Existing operational services remain authoritative; change only their Finance-facing response boundary.
- [ ] Run affected Finance tests; expect PASS.
- [ ] Commit with `git add -- app/Http/Controllers/Api/Finance app/Http/Controllers/Api/PriceChangeRequestController.php app/Http/Controllers/Api/RepairServiceController.php app/Http/Controllers/Api/RefundApprovalController.php app/Http/Controllers/Api/RepairRefundWorkflowController.php app/Http/Controllers/Erp/PurchaseRequestController.php app/Http/Controllers/Erp/HR/AuditLogController.php app/Http/Controllers/Erp/HR/PayrollController.php tests/Feature/Finance/FinanceErrorResponseTest.php` then `git commit -m "fix: harden finance error responses"`.

**Verification:** `php artisan test tests/Feature/Finance`.

**Acceptance criteria:** P1-21; required P0 security hardening.

**Phase 1 gate:** Do not start Phase 2 until Tasks 1–8 pass, route inspection shows capability-specific middleware, cross-shop receipt tests pass, and every fixed monetary fixture matches the approved contract.

## 4. Phase 2 — P1 Money-State Integrity

### Task 9: Add immutable payment and settlement persistence

**Goal:** Establish purpose-specific append-only money history and expense due dates without changing historical migrations.

**Affected files:** Create `database/migrations/2026_08_11_000001_create_finance_money_history_tables.php`, `app/Models/Finance/InvoicePayment.php`, `app/Models/Finance/ExpenseSettlement.php`, and focused schema assertions in `tests/Feature/Finance/InvoicePaymentTest.php` and `ExpenseSettlementTest.php`; modify `app/Models/Finance/Invoice.php` and `Expense.php`.

**Database impact:** Create both tables exactly as specification §7.2/§7.3; add nullable indexed `finance_expenses.due_date`. Forward migration only.

- [ ] Write failing schema/model tests for decimal casts, tenant/parent/user/self references, unique reversal links, lookup indexes, nullable migration actor/key, and due-date cast/index.
- [ ] Create `finance_invoice_payments` using `shop_owner_id`, `invoice_id`, `entry_type`, `amount`, method/reference/date/actor/key/reversal/source fields and timestamps. Use a composite unique `(shop_owner_id, idempotency_key)`; MySQL and SQLite both permit multiple nulls.
- [ ] Create `finance_expense_settlements` analogously and unique `(source, source_reference)` because current payroll and procurement IDs are globally stable. Leave `source_reference` null for manual rows.
- [ ] Add model constants for controlled entry/source strings and `Rule::in` validation in controllers; do not build a generic source/event base class.
- [ ] Add relationships and derived helper queries (`validPaidAmount`, `validSettledAmount`) that sum payment/settlement minus full reversals using decimals. Block model update/delete for persisted history rows; migrations and tests may use query builder explicitly.
- [ ] Add `due_date` to `Expense::$fillable/$casts` and `(shop_id, due_date)` index.
- [ ] Run both focused tests; expect PASS.
- [ ] Commit with `git add -- database/migrations/2026_08_11_000001_create_finance_money_history_tables.php app/Models/Finance tests/Feature/Finance/InvoicePaymentTest.php tests/Feature/Finance/ExpenseSettlementTest.php` then `git commit -m "feat: add finance money history"`.

**Verification:** `php artisan test tests/Feature/Finance/InvoicePaymentTest.php tests/Feature/Finance/ExpenseSettlementTest.php`.

**Acceptance criteria:** Foundation for P1-10 through P1-16 and P1-22.

### Task 10: Implement invoice payments, reversals, and derived invoice state

**Goal:** Support partial immutable payments with safe replay, full reversals, derived balances/status, and overpayment warnings.

**Affected files:** Create `app/Services/Finance/InvoicePaymentService.php`; modify `app/Http/Controllers/Api/Finance/InvoiceController.php`, `app/Models/Finance/Invoice.php`, `routes/finance-api.php`, `resources/js/hooks/useFinanceQueries.ts`, and `resources/js/Pages/ERP/Finance/Invoice.tsx`; complete `tests/Feature/Finance/InvoicePaymentTest.php`, create `tests/Feature/Finance/FinanceConcurrencyTest.php` and `resources/js/Pages/ERP/Finance/__tests__/Invoice.payments.test.tsx`.

**Database impact:** Writes append-only payment/reversal rows; legacy invoice payment fields remain compatibility-only.

- [ ] Write failing tests for partial/full payment, disallowed invoice states, linked operational invoices, cross-shop invoice/payment child access, excess payment, same-key replay, same-key/different-payload conflict, concurrent balance race, full reversal, duplicate reversal, and historical overpayment warning.
- [ ] Implement `record(Invoice $invoice, User $actor, array $data)` in one transaction: tenant recheck, `lockForUpdate`, derived balance revalidation, replay lookup, exact field comparison, append payment, and return the first effective result on an exact replay.
- [ ] Compare invoice ID, amount, method, reference, and normalized `received_at` on replay. Return 409 when the key exists with materially different data; the key is never treated as authorization.
- [ ] Implement `reverse(...)` with invoice/payment locks, parent/shop checks, required reason, full original amount, copied method, and unique `reverses_payment_id`. Corrections are reverse then a separately authorized replacement.
- [ ] Serialize `paid_amount`, `remaining_balance`, `unpaid|partially_paid|paid`, payment/reversal history, source ownership, and warnings. If valid paid exceeds total, return `overpayment_detected`, block new payment, and never emit `overpaid` as operational status.
- [ ] Add canonical payment/reversal routes. Keep `mark-paid` as a 410 compatibility response after the UI migrates; do not update `payment_date/payment_method` for new writes.
- [ ] Replace “Mark as paid” with confirmed “Record payment,” show read-only operational history for linked invoices, add reversal confirmation/reason, and surface warnings separately.
- [ ] Run backend/frontend/concurrency tests; expect PASS.
- [ ] Commit with `git add -- app/Services/Finance/InvoicePaymentService.php app/Http/Controllers/Api/Finance/InvoiceController.php app/Models/Finance/Invoice.php routes/finance-api.php resources/js/hooks/useFinanceQueries.ts resources/js/Pages/ERP/Finance/Invoice.tsx tests/Feature/Finance resources/js/Pages/ERP/Finance/__tests__/Invoice.payments.test.tsx` then `git commit -m "feat: add immutable invoice payments"`.

**Verification:** Focused payment tests; for concurrency, use two DB connections/processes on MySQL in addition to SQLite functional coverage.

**Acceptance criteria:** P1-10, P1-11, P1-12, P1-13.

### Task 11: Implement paid-now/pay-later expenses and settlements

**Goal:** Keep routine expenses lightweight while preserving actual cash movement independently from approval state.

**Affected files:** Create `app/Services/Finance/ExpenseSettlementService.php`; modify `app/Http/Controllers/Api/Finance/ExpenseController.php`, `app/Models/Finance/Expense.php`, `app/Services/Finance/FinanceSummaryService.php`, `routes/finance-api.php`, `resources/js/hooks/useFinanceQueries.ts`, and `resources/js/Pages/ERP/Finance/Expense.tsx`; complete `tests/Feature/Finance/ExpenseSettlementTest.php` and `FinanceSummaryTest.php`, extend `FinanceConcurrencyTest.php`, and create `resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx`.

**Database impact:** Append settlement/reversal rows; writes `due_date`; no destructive money updates.

- [ ] Write failing tests for atomic paid-now creation, exact duplicate replay, same-key/different-expense conflict, transaction rollback, cross-shop expense/settlement child access, pending/rejected paid expense cash treatment, pay-later creation, partial settlements, excess/concurrent settlement, full reversal, and historical excess warning.
- [ ] For `paid_now`, validate expense fields plus method/paid date/reference/key, then create expense and full settlement in one transaction. On duplicate settlement uniqueness, roll back the new expense and return the original expense/settlement only after comparing both parent expense and settlement fields.
- [ ] For `pay_later`, create no settlement; accept optional manual due date. Require due date only where the UI explicitly represents a known obligation.
- [ ] Settlement service locks the tenant-scoped expense, requires an incurred/payable state for deferred settlement, derives outstanding amount, and appends a partial settlement. Reversal locks parent/original and appends one full reversal.
- [ ] Preserve valid settlement rows when an expense is rejected. Emit `paid_rejected_expense`; include settlement in paid/cash totals and exclude rejected expense from incurred totals. Never call reversal logic from approve/reject transitions.
- [ ] Remove the Task 6 no-table compatibility branch from `FinanceSummaryService` now that the settlement migration is a Phase 2 prerequisite. Calculate `paid_expenses` and the expense component of `net_cash_movement` only from valid settlements by `paid_at`, including unreversed settlements on pending/rejected expenses. Extend `FinanceSummaryTest` with these exact cases.
- [ ] Update expense list/detail serialization with separate approval/incurred and settlement states, paid/outstanding amounts, history, due date, and integrity warnings.
- [ ] Make the create form default to paid-now; reveal due date for pay-later. Add deferred settlement/reversal actions only for expense-authorized users; procurement/payroll sources remain read-only.
- [ ] Run focused backend/frontend/concurrency tests; expect PASS.
- [ ] Commit with `git add -- app/Services/Finance/ExpenseSettlementService.php app/Services/Finance/FinanceSummaryService.php app/Http/Controllers/Api/Finance/ExpenseController.php app/Models/Finance/Expense.php routes/finance-api.php resources/js/hooks/useFinanceQueries.ts resources/js/Pages/ERP/Finance/Expense.tsx tests/Feature/Finance resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx` then `git commit -m "feat: add lightweight expense settlements"`.

**Verification:** `php artisan test tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/Finance/FinanceConcurrencyTest.php tests/Feature/Finance/FinanceSummaryTest.php`; focused Vitest file.

**Acceptance criteria:** P1-14, P1-15, P1-16.

### Task 12: Simplify and lock manual expense approval

**Goal:** Replace the generic four-stage expense workflow with one Finance approval and existing high-value Shop Owner escalation, advancing once under concurrency.

**Affected files:** Modify `app/Services/ExpenseApprovalService.php`, `app/Http/Controllers/Api/Finance/ExpenseController.php`, `app/Models/Approval.php`, `resources/js/Pages/ERP/Finance/Expense.tsx`, and `resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx`; update `tests/Feature/Finance/ExpenseApprovalWorkflowTest.php` and `FinanceConcurrencyTest.php`.

**Database impact:** No new schema. Existing approval/history rows are preserved; transitions update lifecycle state only.

- [ ] Replace tests that assert the four-stage workflow with approved one-stage and high-value owner-escalation cases using `shop_owners.high_value_threshold`.
- [ ] In each transition, start a transaction, resolve tenant-scoped expense, lock current approval/expense, re-read status/stage, and either advance once or return current state/conflict.
- [ ] Exclude procurement and payroll expenses from generic approval. Retain their operational ownership rules.
- [ ] Keep approval rejection completely separate from settlement state. Test that paid-now rejection creates no reversal row and preserves cash/warning behavior.
- [ ] Remove only expense-specific four-stage branches/constants/copy; do not alter other domains that still use `Approval`.
- [ ] Run focused approval and concurrency tests; expect PASS.
- [ ] Commit with `git add -- app/Services/ExpenseApprovalService.php app/Http/Controllers/Api/Finance/ExpenseController.php app/Models/Approval.php resources/js/Pages/ERP/Finance/Expense.tsx resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx tests/Feature/Finance` then `git commit -m "fix: simplify expense approval integrity"`.

**Verification:** `php artisan test tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/FinanceConcurrencyTest.php`.

**Acceptance criteria:** P1-16, P1-18, P1-19.

### Task 13: Add procurement due dates and atomic payroll Finance synchronization

**Goal:** Derive known supplier due dates safely and make completed payroll disbursement create one authoritative expense settlement in the same transaction under a dedicated capability.

**Affected files:** Create `app/Console/Commands/AuditFinanceIntegrity.php` and `tests/Feature/Finance/FinanceIntegrityAuditCommandTest.php`; modify `app/Services/ExpenseApprovalService.php`, `app/Services/PurchaseOrderReceiptService.php`, `app/Http/Controllers/Erp/HR/PayrollController.php`, `routes/finance-api.php`, and Finance permission files from Task 2; update `tests/Feature/Procurement/PurchaseOrderReceivingTest.php` and `tests/Feature/HR/PayrollControllerTest.php`; create `tests/Feature/Finance/PayrollDisbursementFinanceSyncTest.php`.

**Database impact:** Writes recognized due dates and payroll settlement rows. Deterministic `source=payroll`, `source_reference=payroll:{id}` prevents duplicates.

- [ ] Add procurement tests: `Net 30` derives `received_at + 30 days`; COD/unrecognized/free text remains null; one accepted receipt still creates one expense.
- [ ] Add payroll tests for Shop Owner, explicitly designated disburser, old broad approver denied, actor separation, retry, concurrency, and forced Finance write failure rolling back payroll/expense/settlement together.
- [ ] Parse only anchored, bounded `Net N` values from existing `purchase_orders.payment_terms`; do not guess other formats. Save due date on the receipt-backed expense.
- [ ] Move the current private `createExpenseFromPaidPayroll()` work into the existing disbursement transaction. Create/resolve the payroll expense, then call `ExpenseSettlementService` with deterministic source reference and actual method/date/reference.
- [ ] Remove the best-effort catch. Payroll state, expense, and settlement must commit or roll back together.
- [ ] Create the read-only `finance:audit-integrity` command with an initial `--section=legacy-disbursers` implementation and a command test. It lists staff who pass the old broad check, emits stable IDs/counts, mutates nothing, and grants nothing. Tasks 14 and 15 extend this same command with further sections.
- [ ] After the permission is visible and the report is reviewed, change `canDisbursePayroll()`/route middleware to Shop Owner or `disburse-payroll` only. Do not grant it to Finance or approval roles automatically.
- [ ] Run procurement/payroll/Finance tests; expect PASS.
- [ ] Commit with `git add -- app/Console/Commands/AuditFinanceIntegrity.php app/Services app/Http/Controllers/Erp/HR/PayrollController.php routes/finance-api.php database/seeders app/Http/Controllers/ShopOwner/UserAccessControlController.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/HR/PayrollControllerTest.php tests/Feature/Finance/PayrollDisbursementFinanceSyncTest.php tests/Feature/Finance/FinanceIntegrityAuditCommandTest.php` then `git commit -m "fix: synchronize finance outflows atomically"`.

**Verification:** Focused tests plus `php artisan finance:audit-integrity --section=legacy-disbursers` on a production snapshot.

**Acceptance criteria:** P1-14, P1-20.

### Task 14: Enforce one Finance invoice per repair job

**Goal:** Make repeated/concurrent job conversion return one invoice without open transactions or silent duplicate deletion.

**Affected files:** Create `database/migrations/2026_08_11_000002_add_finance_job_invoice_unique_constraint.php`; modify `app/Http/Controllers/Api/Finance/InvoiceController.php` and `routes/finance-api.php`; extend `tests/Feature/Finance/FinanceConcurrencyTest.php` and `AuditFinanceIntegrity.php`.

**Database impact:** Add unique `(shop_id, job_order_id)` after a read-only duplicate audit. Multiple null `job_order_id` values remain valid.

- [ ] Add failing repeated and concurrent `POST /api/finance/invoices/from-job/{job}` tests, cross-shop job tests, and a migration guard test with duplicate rows.
- [ ] Change `createFromJob` to resolve/lock the tenant-scoped job inside `DB::transaction`, re-query for an invoice after locking, and return the existing invoice on replay.
- [ ] Preserve the existing reference convention; handle the unique-constraint race by resolving the winning row, not leaking a database exception.
- [ ] Add job duplicate groups to `finance:audit-integrity`. The forward migration checks for unresolved groups and aborts with instructions; it never deletes or merges rows.
- [ ] Run audit against a snapshot, reconcile outside this code change if duplicates exist, then run the migration test and concurrency test.
- [ ] Commit with `git add -- database/migrations/2026_08_11_000002_add_finance_job_invoice_unique_constraint.php app/Http/Controllers/Api/Finance/InvoiceController.php routes/finance-api.php app/Console/Commands/AuditFinanceIntegrity.php tests/Feature/Finance/FinanceConcurrencyTest.php` then `git commit -m "fix: prevent duplicate job invoices"`.

**Verification:** `php artisan test tests/Feature/Finance/FinanceConcurrencyTest.php`; `php artisan finance:audit-integrity --section=job-invoices` must be clean before `php artisan migrate`.

**Acceptance criteria:** P1-17.

### Task 15: Backfill historical money history and expose integrity exceptions

**Goal:** Classify every historical paid invoice and deterministic payroll/procurement record without guessing, clamping, or discarding financial history.

**Affected files:** Create `app/Console/Commands/BackfillFinanceMoneyHistory.php` and `tests/Feature/Finance/FinanceBackfillCommandTest.php`; modify the Task 13 command `app/Console/Commands/AuditFinanceIntegrity.php`, its `tests/Feature/Finance/FinanceIntegrityAuditCommandTest.php`, and `FinanceSummaryService.php` only to consume explicit migration warnings/precedence.

**Database impact:** Inserts `legacy_migration` invoice payments and proven payroll settlements; updates safely derived due dates. No historical row deletion or silent status rewrite.

- [ ] Write command tests for standalone paid invoice, linked invoice with source, linked orphan, missing payment date/method fallbacks, Net-N due date, completed/unpaid payroll, approved manual expense, repeat run, overpayment, and ambiguous legacy refund.
- [ ] Implement `finance:backfill-money-history {--dry-run} {--chunk=100}` with deterministic checkpoints based on primary key. Exact classification:
  - standalone paid invoice → one full `legacy_migration` payment;
  - linked invoice with authoritative operational payment → no Finance payment;
  - linked orphan → one fallback payment and `legacy_source_missing` warning;
  - missing date → `updated_at`; missing method → `legacy_unknown`; actor/key null;
  - completed payroll only → one payroll settlement;
  - approved manual expense → no assumed settlement;
  - recognized Net-N only → due date.
- [ ] Make reruns idempotent using source/reference and existing created-row evidence. Never generate interactive idempotency keys for migrations.
- [ ] Expand the audit report to historical excess, paid rejected expenses, linked-source orphans, ambiguous refund allocation, missing receipt files, and unresolved duplicates. Output counts and IDs; never mutate in audit mode.
- [ ] Update summary precedence so a linked fallback is excluded immediately when its authoritative operational source appears.
- [ ] Run command tests twice against the same fixture database; expect identical row counts on the second run.
- [ ] Commit with `git add -- app/Console/Commands app/Services/Finance/FinanceSummaryService.php tests/Feature/Finance/FinanceBackfillCommandTest.php tests/Feature/Finance/FinanceIntegrityAuditCommandTest.php` then `git commit -m "feat: backfill finance money history safely"`.

**Verification:** `php artisan test tests/Feature/Finance/FinanceBackfillCommandTest.php`; dry-run and real-run reports on a restore/snapshot before production.

**Acceptance criteria:** P1-13, P1-22.

**Phase 2 gate:** Do not start destructive compatibility cleanup until every historical paid invoice is covered by one authoritative operational source or one explicit fallback, derived balances reconcile, payroll sync is atomic, duplicate-job audit is clean, and all remaining exceptions are visible in `finance:audit-integrity`.

## 5. Phase 3 — Consolidation & Cleanup

### Task 16: Migrate all active Finance callers to the canonical route family

**Goal:** Leave one `/api/finance` API while preserving a short, observable 410 window for retired writes.

**Affected files:** Modify `routes/finance-api.php`, `routes/api.php`, `routes/web.php`, `app/Http/Controllers/Api/Finance/InvoiceController.php`, `resources/js/hooks/useFinanceApi.ts`, `resources/js/hooks/useFinanceQueries.ts`, `resources/js/Pages/ERP/Finance/Invoice.tsx`, `Expense.tsx`, `createInvoice.tsx`, and `resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx`; create `tests/Feature/Finance/FinanceRouteContractTest.php`.

**Database impact:** Stop new compatibility writes to `finance_invoices.payment_date/payment_method`; do not drop columns yet.

- [ ] Generate a pre-change inventory with `php artisan route:list --json` and `rg -n "/api/finance/(session|legacy)|mark-paid|/post|/send" resources/js tests routes app`; save expected active callers in the route-contract test data provider.
- [ ] Add canonical `mark-sent`, payment/reversal, settlement/reversal, receipt, dashboard, invoice, expense, and tax routes with existing session auth/CSRF/shop middleware and exact capability checks.
- [ ] Rename `InvoiceController::send()` to an internal-only `markSent()` lifecycle action. It may change only invoice status and audit history; use audit action/message such as `mark_invoice_sent` / `Invoice marked as sent`, and remove all controller responses, notifications, modal copy, success text, and button labels that claim the invoice was emailed or delivered.
- [ ] Change all active hooks/pages to canonical paths. In `Invoice.tsx`, expose **Mark as sent** with confirmation text that describes an internal status change only. Remove `useFinanceApi` path switching and `localStorage` bearer-token fallback; always use session credentials/CSRF.
- [ ] During one deployment window, make `post`, `mark-paid`, session aliases, and legacy Finance write variants return 410 with a stable replacement hint and server log counter. Do not proxy writes because that can duplicate money.
- [ ] Prove no active frontend caller hits a 410 route; update tests to canonical paths.
- [ ] Commit with `git add -- routes app/Http/Controllers/Api/Finance/InvoiceController.php resources/js/hooks resources/js/Pages/ERP/Finance resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx tests/Feature/Finance/FinanceRouteContractTest.php` then `git commit -m "refactor: consolidate finance api routes"`.

**Verification:** Route-contract test, `php artisan route:list --path=api/finance`, focused Vitest suite, and a browser smoke test of dashboard/invoice/expense flows.

**Acceptance criteria:** P1-24; compatibility part of P1-23.

### Task 17: Remove confirmed ledger, report, hook, type, and workflow orphans

**Goal:** Delete misleading accounting behavior and stale Finance code only after reference confirmation.

**Affected files:** Delete `app/Http/Controllers/FinancialReportController.php` and `resources/js/utils/financeStaticNotifications.ts` only if final reference scans confirm no runtime caller; modify/delete the confirmed dead portions of `routes/api.php`, `routes/web.php`, `routes/finance-api.php`, `app/Models/Finance/Invoice.php`, `app/Models/Finance/Expense.php`, `resources/js/hooks/useFinanceQueries.ts`, `resources/js/Pages/ERP/Finance/Invoice.tsx`, `Expense.tsx`, `Finance.tsx`, and stale Finance types/pages found by the scan. Historical migrations remain untouched.

**Database impact:** None in this release. `journal_entry_id`, account columns, payment compatibility columns, and old physical tables are not dropped until a later forward migration after production-data confirmation.

- [ ] Run `rg` reference scans for `postToLedger`, `createJournalEntry`, `journalEntry`, `journal_entry_id`, `account_id`, `posted`, `FinancialReportController`, report URIs, session aliases, account/journal/budget hooks, `financeStaticNotifications`, alternate procurement expense creation, and four-stage expense identifiers.
- [ ] Add failing route/UI assertions proving ledger posting and balance sheet/P&L/trial balance/AR/AP aging are unavailable and no Finance page advertises them.
- [ ] Delete report routes/controller, invoice ledger methods/relationship/action/type, dead account/journal/budget/generic-transaction hooks, retired alternate procurement-expense paths, confirmed tombstone approval page/notification utility, stale comments/statuses, and compatibility routes whose 410 telemetry is zero after the window.
- [ ] Do not remove shared `Approval`, account/journal tables, or code used by another module. Do not edit historical migrations.
- [ ] Run the same reference scans; expected result is no active runtime reference, with any retained database column appearing only in historical migrations/backfill compatibility documentation.
- [ ] Run route, Finance, procurement, payroll, refund, frontend, and build checks.
- [ ] Commit with explicit paths from the clean scan, then `git commit -m "refactor: remove stale finance accounting paths"`.

**Verification:** `php artisan test tests/Feature/Finance tests/Feature/Procurement tests/Feature/HR/PayrollControllerTest.php`; `pnpm run test:frontend`; `pnpm run build`; clean reference scans.

**Acceptance criteria:** P1-23, P1-24.

## 6. Migration / Deployment Sequence

1. **Preflight:** set a valid test `APP_KEY`; capture database/file backups; run current targeted Finance/refund/procurement/payroll tests; record `route:list`; run receipt and Finance integrity dry-runs.
2. **Deploy Phase 1 code:** tenant resolver, capability route split, tax fix, private receipt writes, summary API, dashboard UI, and generic error responses. No money-history schema is required yet.
3. **Receipt migration:** run `finance:migrate-receipts-private --dry-run`, then real resumable copy. Verify counts/checksums and authorized downloads. Keep public sources until at least one post-deploy verification cycle; remove only verified copies operationally, never metadata for missing files.
4. **Phase 1 gate:** run cross-tenant/security tests and exact source fixtures. Roll back application code if needed; private copies are additive and safe to retain.
5. **Deploy additive Phase 2 schema:** run `2026_08_11_000001_create_finance_money_history_tables.php`. It only creates tables/adds nullable due date and is safe before code starts writing.
6. **Deploy payment/settlement application code:** enable canonical new writes while legacy payment columns remain read-only compatibility fields.
7. **Dry-run backfill:** run `finance:audit-integrity` and `finance:backfill-money-history --dry-run` on a production restore, then production. Resolve reported ambiguity outside automated code; do not clamp/delete.
8. **Real backfill:** run the resumable command, rerun it to prove idempotency, then run the audit. Gate on exact invoice-source coverage and reconciled balances.
9. **Job uniqueness:** run the duplicate audit. Only after zero unresolved groups, deploy/run `2026_08_11_000002_add_finance_job_invoice_unique_constraint.php`. Its rollback drops only the new unique index.
10. **Payroll permission rollout:** create/expose `disburse-payroll`, run the legacy-disburser report, let Shop Owners assign designated staff, then switch route authorization and atomic sync. Shop Owner access prevents lockout.
11. **Caller migration:** deploy canonical frontend callers and 410 responses for retired writes. Monitor 410 logs and verify no supported client uses them.
12. **Cleanup deployment:** after the compatibility window and zero active callers, remove aliases, reports, ledger actions, stale hooks/types/pages, and localStorage auth fallback. Do not drop old columns/tables in this release.
13. **Rollback rule:** application releases may roll back while additive tables/columns and backfilled history remain. Never roll back by deleting payment/settlement rows. A later application version must continue treating them as authoritative.

## 7. Requirement → Task Traceability

| Acceptance criterion | Implementing task(s) |
|---|---|
| P0-1 Explicit shop + tenant object resolution | 1, 2, 3, 4 |
| P0-2 No cross-shop Finance/receipt access | 1, 4, 9–11 |
| P0-3 Narrow permissions grant no unrelated Finance access | 2 |
| P0-4 Tax missing-context 403/no Shop 1 | 1, 3 |
| P0-5 Server-side decimal-safe exact dashboard | 5, 6, 7 |
| P0-6 Each sale/refund contributes once | 5, 6 |
| P0-7 Non-incurred expenses excluded | 5, 6, 11 |
| P0-8 Hard-coded comparisons removed | 7 |
| P0-9 Concise primary KPIs/supporting detail | 7 |
| P1-10 Partial immutable invoice payments | 9, 10 |
| P1-11 Reversal plus replacement; no edits/deletes | 9, 10 |
| P1-12 Duplicate/concurrent payment safety | 9, 10 |
| P1-13 Historical overpayment warning | 10, 15 |
| P1-14 Due dates and partial settlements | 9, 11, 13 |
| P1-15 Atomic routine paid-now expense | 11 |
| P1-16 Approval rejection never invents cash reversal | 11, 12 |
| P1-17 One Finance invoice per repair job | 14 |
| P1-18 Concurrent approval advances once | 12 |
| P1-19 One Finance approval + high-value owner escalation | 12 |
| P1-20 Dedicated payroll capability + atomic sync | 2, 13 |
| P1-21 No raw exception details | 8, 10–15 |
| P1-22 Safe paid-invoice backfill | 15 |
| P1-23 No ledger posting/broken reports | 16, 17 |
| P1-24 One canonical Finance API | 16, 17 |

No P0/P1 criterion is orphaned. Tasks 5 and 6 are the approved calculation-fixture dependency; Tasks 4, 14, and 15 are the approved migration/audit dependencies.

## 8. Deferred Work

The following are not implementation tasks and receive no routes, permissions, tables, UI placeholders, or abstractions:

- Dashboard date-range presets and previous-period comparisons
- Server-side CSV/report export refinements
- Payment-provider payout reconciliation
- Finance exception/action queue
- Receipt OCR
- Category budgeting
- General ledger/double-entry, journal editing, chart of accounts, trial balance, balance sheet, statutory statements, full AP/AR, bank feeds/reconciliation, multi-company/branch/currency, cost centers, enterprise budgeting/treasury/consolidation/period close, and actual invoice email delivery

## 9. Final Verification Gate

Run in this order with a fresh valid test key. Do not claim a check passed unless its current output was read.

1. **Schema/migration checks**

```powershell
$env:APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='; php artisan test tests/Feature/Finance/InvoicePaymentTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/Finance/FinanceBackfillCommandTest.php
php artisan finance:migrate-receipts-private --dry-run
php artisan finance:audit-integrity
php artisan finance:backfill-money-history --dry-run
```

Expected: the focused tests exercise migrations on PHPUnit's SQLite in-memory database; commands are read-only in dry-run; unresolved financial exceptions are explicitly listed, never silently repaired. Run the three Artisan commands against a restored production snapshot before production, never an unverified live database.

2. **Narrow Finance suite**

```powershell
$env:APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='; php artisan test tests/Feature/Finance
```

Expected: PASS; exact decimal strings, tenant denials, duplicate guards, reversals, paid-rejected semantics, backfill idempotency, and route contract are covered.

3. **Adjacent backend regressions**

```powershell
$env:APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='; php artisan test tests/Feature/Procurement tests/Feature/HR/PayrollControllerTest.php tests/Feature/CheckoutPromoPricingTest.php tests/Feature/UserSide/RetailInvoiceShippingFeeTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosRefundFlowTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/ShopOwnerDashboardRevenueTest.php
```

Expected: PASS; operational modules remain authoritative and exactly-once fixtures stay green.

4. **Frontend behavior and production build**

```powershell
pnpm run test:frontend -- resources/js/Pages/ERP/Finance
pnpm run build
```

Expected: PASS/build success; dashboard contains no financial reductions or fake comparisons; invoice/expense actions use canonical routes.

5. **Route and dead-code checks**

```powershell
php artisan route:list --path=api/finance
rg -n "/api/finance/session|mark-paid|postToLedger|FinancialReportController|balance-sheet|profit-loss|trial-balance|ar-aging|ap-aging|localStorage.getItem\('auth_token'\)" routes app resources/js tests
```

Expected after Phase 3: one canonical active route family; no active stale caller. Historical migrations or explicit compatibility documentation may be the only retained references.

6. **Repository quality gates**

```powershell
git diff --check
composer test
git status --short
```

Expected: no whitespace errors; broad Laravel suite passes when practical; status contains only intended Finance plan/implementation changes plus preserved pre-existing user work.

7. **Required review stack record**

- `simplify` / `@ponytail`: no generic event/source framework, cache-token subsystem, polymorphic actor, action-level permission matrix, or AP-like routine-expense workflow.
- Standards/spec/correctness review: sequentially compare the diff to this plan and all 24 criteria.
- TypeScript/React review: no `any` added at changed boundaries, no client financial logic, no unnecessary component splitting, and no new heavy dependency.
- Security review: tenant scope and capability checked independently; receipt paths private; replay keys never authorize; raw exceptions absent.
- Reuse/dead-code review: reuse `ShopScoped`, Spatie permissions/activity, existing POS idempotency convention, procurement receipt link, payroll locks, and existing access-control UI; delete only reference-confirmed orphans.
- Improvement evidence: exact-money fixtures, route inventory before/after, audit/backfill counts, and behavior tests. Performance claims are `not measured` unless query/latency baselines are captured.

The implementation is complete only when both phase gates and this final gate pass, every remaining integrity exception is visible, and no deferred/non-goal feature has been introduced.
