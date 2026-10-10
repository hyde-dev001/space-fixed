# Procurement Supplier Payment Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete SoleSpace's existing Inventory stock request -> manual PR -> manual PO -> canonical receipt -> Finance release -> shop-funded supplier payment -> settlement -> replacement/refund workflow without adding parallel business flows.

**Architecture:** Keep `PurchaseOrderReceiptService` as the only inventory-entry path and `ExpenseSettlementService` as the only Finance money-history writer. Add one supplier destination record, one manual payment-attempt record, and one supplier-adjustment record; expose them through thin controllers and existing pages while all state transitions, locking, idempotency, and tenant checks remain in focused services. Automated PayMongo supplier disbursement is externally blocked and remains future integration work.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent transactions and row locks, Spatie Media Library and Activitylog, PHPUnit, Inertia 2, React 18, TypeScript 5.7, Axios, Vitest, Testing Library, Vite 7, Tailwind CSS 4, pnpm.

---

## Approved source and boundaries

- Source of truth: `docs/superpowers/specs/2026-09-12-procurement-supplier-payment-workflow-design.md`.
- Preserve manual PR-to-PO creation. Do not activate `CreatePurchaseOrderFromPR` or event discovery.
- Preserve receipt-driven `partially_received` and `delivered`; payment never sets either status.
- Preserve the `ExpenseSettlementService` approval guard and append-only history.
- Supplier payment uses a real external manual bank/e-wallet transfer. No PayMongo supplier disbursement fallback or simulation is allowed.
- Shop Owner confirmation records the settlement and changes supplier email status to `ready_to_send`; Finance explicitly sends the receipt from the Finance workflow.
- Supplier receipt email is never sent automatically during Shop Owner confirmation.
- `Due Soon` means tomorrow through today + 3 calendar days, inclusive.
- Use only `manufacturing_defect`, `damaged`, `wrong_item`, `incorrect_size_or_variant`, and `other`; `other` requires notes.
- Reuse `supplier_adjustments` for receiving-time and post-payment defects. Do not add a refund or second defect table.
- Do not implement or simulate PayMongo supplier disbursement, transfer IDs, or payout webhooks. Existing customer PayMongo payment/refund behavior remains untouched.
- Do not edit `.env`, generated `vendor/`, `node_modules/`, or `public/build` files.

## File map

### Database and models

- Create: `database/migrations/2026_09_12_000001_create_supplier_payment_profiles_table.php` - one encrypted supplier destination per supplier.
- Create: `database/migrations/2026_09_12_000002_create_supplier_payment_attempts_table.php` - manual supplier-payment lifecycle and idempotency, not accounting.
- Create: `database/migrations/2026_09_12_000003_create_supplier_adjustments_table.php` - receiving and post-payment quality cases.
- Create: `database/migrations/2026_09_12_000004_add_supplier_workflow_links.php` - the three approved existing-table columns.
- Create: `database/migrations/2026_09_12_000005_add_manual_supplier_payment_fields.php` - additive manual-payment state, proof, checker, cancellation, and email fields.
- Create: `app/Models/SupplierPaymentProfile.php`.
- Create: `app/Models/SupplierPaymentAttempt.php`.
- Create: `app/Models/SupplierAdjustment.php`.
- Modify: `app/Models/Supplier.php` - payment-profile relationship and supported field serialization.
- Modify: `app/Models/PurchaseOrder.php` - payment-term constants, adjustment/payment relations, and completion guards.
- Modify: `app/Models/PurchaseOrderReceiptItem.php` - adjustment and replacement relationships.
- Modify: `app/Models/Finance/Expense.php` - payment-attempt relation.
- Modify: `app/Models/Finance/ExpenseSettlement.php` - supplier-refund entry type, adjustment relation, and refund-safe totals.

### Backend services and HTTP boundaries

- Modify: `app/Services/PurchaseOrderService.php` - payment-term snapshot precedence, completion integration, and in-transit notification.
- Modify: `app/Services/PurchaseOrderReceiptService.php` - defect creation, replacement linkage, payable distinction, and void guards.
- Modify: `app/Services/ExpenseApprovalService.php` - due-date allowlist and procurement Review and Release.
- Modify: `app/Services/Finance/ExpenseSettlementService.php` - procurement endpoint protection support and append-only supplier refunds.
- Create: `app/Services/Finance/SupplierPaymentService.php` - real manual bank/e-wallet attempt, proof, Shop Owner verification, settlement, and email orchestration.
- Create: `app/Services/SupplierAdjustmentService.php` - reporting, transitions, evidence, replacement totals, and refund resolution.
- Modify: `app/Http/Controllers/Erp/SupplierController.php` - supplier fields, payment-profile management, and archival guard.
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php` - sort allowlist and completion response.
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php` - sort allowlist.
- Modify: `app/Http/Controllers/Erp/PurchaseOrderReceiptController.php` - multipart receipt payloads and linked replacements.
- Create: `app/Http/Controllers/Erp/SupplierAdjustmentController.php` - adjustment list/report/review/refund-proof actions.
- Create: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php` - Review and Release, payment preview/initiation, payment-profile verification, and refund confirmation.
- Create: `app/Http/Controllers/ShopOwner/SupplierPaymentController.php` - Shop Owner review, confirmation, rejection, and proof access.
- Modify: `app/Http/Controllers/Api/Finance/ExpenseController.php` - procurement projection and reject public manual settlement bypass.
- Modify: `app/Http/Requests/StorePurchaseOrderRequest.php` - payment-term allowlist.
- Modify: `app/Http/Requests/StorePurchaseOrderReceiptRequest.php` - defect evidence and replacement validation.
- Create: `app/Http/Requests/StoreSupplierPaymentProfileRequest.php`.
- Create: `app/Http/Requests/StorePostPaymentIssueRequest.php`.
- Create: `app/Http/Requests/UpdateSupplierAdjustmentRequest.php`.
- Create: `app/Http/Requests/Finance/ReviewReleaseProcurementExpenseRequest.php`.
- Create: `app/Http/Requests/Finance/InitiateSupplierPaymentRequest.php`.
- Create: `app/Http/Requests/Finance/ConfirmSupplierRefundRequest.php`.
- Modify: `routes/procurement-api.php` - nested profile, adjustment, evidence, issue, and replacement-aware receipt routes.
- Modify: `routes/finance-api.php` - release, payment, profile verification, refund-confirmation, and explicit supplier-receipt-send routes.

### Notifications and audit

- Modify: `app/Enums/NotificationType.php` - only supplier-workflow-specific types that cannot safely use an existing meaning.
- Modify: `app/Services/NotificationService.php` - existing recipient resolution and preference-aware sends.
- Modify: `resources/js/utils/resolveNotificationActionUrl.ts` - route new notification types to the existing Finance/Procurement pages.
- Modify: `resources/js/types/notifications.ts` only if its union is explicit rather than string-compatible.

### Frontend

- Modify: `resources/js/types/procurement.ts` - profile, attempt, adjustment, evidence, receipt, and payment-term contracts.
- Modify: `resources/js/services/supplierApi.ts` - payment-profile operations.
- Modify: `resources/js/services/purchaseOrderApi.ts` - multipart receiving and adjustment operations.
- Create: `resources/js/services/supplierAdjustmentApi.ts` only if the methods cannot remain cohesive in `purchaseOrderApi`; do not create both representations.
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx` - existing supplier fields and profile management.
- Modify: `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx` - category, notes, images, and replacement linkage.
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx` - one adjustment list/detail surface and late-issue entry point.
- Create: `resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx` - focused adjustment interaction extracted from the already-large PO page.
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx` - render procurement details and host release/payment/refund actions.
- Create: `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx` - focused details/actions extracted from the already-large Expense page.
- Create: `resources/js/Pages/ERP/Finance/components/SupplierPaymentDialog.tsx` - immutable masked-destination confirmation.

### Tests

- Modify: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`.
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`.
- Modify: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`.
- Modify: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`.
- Modify: `tests/Feature/Procurement/ProcurementApiContractTest.php`.
- Modify: `tests/Feature/Procurement/ProcurementAuthorizationTest.php`.
- Modify: `tests/Feature/Finance/ExpenseSettlementTest.php`.
- Create: `tests/Feature/Finance/ProcurementExpenseReleaseTest.php`.
- Create: `tests/Feature/Finance/SupplierPaymentProfileTest.php`.
- Create: `tests/Feature/Finance/SupplierManualPaymentTest.php`.
- Create: `tests/Feature/Procurement/SupplierAdjustmentTest.php`.
- Create: `tests/Feature/Procurement/SupplierReplacementTest.php`.
- Create: `tests/Feature/Finance/SupplierRefundTest.php`.
- Modify: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`.
- Create: `app/Mail/SupplierPaymentConfirmationMail.php` and `resources/views/emails/supplier-payment-confirmation.blade.php`.
- Modify: `resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`.
- Modify: `resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx`.
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx`.
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`.
- Create: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`.
- Modify: `resources/js/services/__tests__/procurementApis.test.ts`.

## Execution rules

- Use `@superpowers:test-driven-development` for every behavior change. Use `@laravel-best-practices`, `@security-review`, `@vercel-react-best-practices`, `@karpathy-guidelines`, and `@ponytail` where applicable during implementation.
- This repository defaults to sequential inline execution. Subagent-driven execution requires the user's explicit approval under `AGENTS.md`.
- Run CodeGraph before editing each application area, then inspect every caller of a changed shared service.
- Preserve unrelated work. Before each commit run `git status --short` and commit only the paths listed for that task.
- Use decimal strings/integer centavos for money. Never use floating-point values at provider or settlement boundaries.
- Use database row locks in a stable order: expense/PO, attempt or adjustment, then dependent rows.
- API responses expose masked destinations and sanitized failures only. Never serialize encrypted account numbers, destination snapshots, shop secrets, or raw provider bodies.
- Reuse `PurchaseOrderPolicy` for receive/manage/complete operations and
  `SupplierPolicy` for destination management. Finance routes reuse the
  existing approval/expense capabilities plus `FinanceShopContext`; no second
  role or policy system is planned.

### Task 1: Lock current specification conflicts with regression tests

**Files:**

- Modify: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Modify: `tests/Feature/Procurement/ProcurementApiContractTest.php`
- Modify: `tests/Feature/Finance/ExpenseSettlementTest.php`

- [ ] **Step 1: Record the worktree baseline.**

Run:

```bash
git status --short
git log -1 --oneline
```

Expected: only explicitly known work is present; the approved spec commit is `d60341963` or an intentional descendant.

- [ ] **Step 2: Run the current focused baseline before adding tests.**

```bash
php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php tests/Feature/Finance/ExpenseSettlementTest.php
pnpm exec vitest run resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx
```

Expected: preserve the recorded audit baseline; investigate any new failure before editing tests.

- [ ] **Step 3: Add failing conflict tests.**

Add assertions that:

1. final PR approval does not create a PO, while explicit manual PO creation succeeds;
2. Procurement can only progress `draft -> sent -> confirmed -> in_transit`, and cannot manually set `delivered`;
3. only the five approved payment terms validate;
4. unsupported payment terms, including COD and 50/50 terms, expose no due-date path;
5. arbitrary `sort_by` and invalid `sort_order` never reach SQL;
6. `POST /api/finance/expenses/{id}/settlements` rejects a procurement receipt expense even when it is posted; and
7. the service-level submitted-expense settlement guard remains unchanged.

- [ ] **Step 4: Run only the new tests and confirm they fail for those gaps.**

```bash
php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/ProcurementApiContractTest.php tests/Feature/Finance/ExpenseSettlementTest.php --filter='manual|payment_terms|due_date|sort|procurement.*settlement'
```

Expected: existing manual workflow assertions pass; new term, due-date, sorting, and settlement-boundary assertions fail without unrelated exceptions.

- [ ] **Step 5: Keep the red tests uncommitted.**

Do not create a standalone failing-test commit. Keep these regression tests in
the working tree and carry them into the task that implements each behavior.
The first green task commit must include the relevant tests and implementation.

### Task 2: Add the three records and three approved link columns

**Files:**

- Create: `database/migrations/2026_09_12_000001_create_supplier_payment_profiles_table.php`
- Create: `database/migrations/2026_09_12_000002_create_supplier_payment_attempts_table.php`
- Create: `database/migrations/2026_09_12_000003_create_supplier_adjustments_table.php`
- Create: `database/migrations/2026_09_12_000004_add_supplier_workflow_links.php`
- Create: `app/Models/SupplierPaymentProfile.php`
- Create: `app/Models/SupplierPaymentAttempt.php`
- Create: `app/Models/SupplierAdjustment.php`
- Modify: `app/Models/Supplier.php`
- Modify: `app/Models/PurchaseOrderReceiptItem.php`
- Modify: `app/Models/Finance/Expense.php`
- Modify: `app/Models/Finance/ExpenseSettlement.php`
- Test: `tests/Feature/Procurement/ProcurementApiContractTest.php`

- [ ] **Step 1: Add failing schema/model contract assertions.**

Assert the three tables, approved columns, indexes, foreign keys, encrypted casts, hidden sensitive fields, and relationships. Assert there is no `supplier_refunds`, second receipt, or attachment table.

- [ ] **Step 2: Run the schema test to verify it fails.**

```bash
php artisan test tests/Feature/Procurement/ProcurementApiContractTest.php --filter='supplier.*schema|workflow.*schema'
```

Expected: FAIL because the tables/models do not exist.

- [ ] **Step 3: Create the minimum schema.**

Use these exact responsibilities:

- `supplier_payment_profiles`: `shop_owner_id`, unique `supplier_id`, destination type, bank name/code, account name, encrypted account number in `text`, status, verifier, verification timestamp, timestamps.
- `supplier_payment_attempts`: shop, expense, supplier, profile, decimal amount, currency, provider, unique internal reference, nullable provider reference, shop-scoped idempotency key, encrypted destination snapshot in `longText`, status, sanitized failure fields, actor/lifecycle timestamps, nullable unique settlement link, timestamps.
- `supplier_adjustments`: shop, originating receipt item, shop-scoped idempotency key, issue stage, quantity, unit-cost snapshot, reason category, immutable Inventory notes, status, resolution, Procurement notes, expected/supplier-reported refund details, actor/timestamps, timestamps.
- `purchase_order_receipt_items.replacement_for_adjustment_id`, `finance_expense_settlements.supplier_adjustment_id`, and `finance_expense_settlements.notes` are nullable. Use restrictive/nulling delete behavior that preserves financial and audit history.

Do not add duplicate status columns to PO or Expense.

- [ ] **Step 4: Add model constants, casts, hidden fields, and relationships.**

`SupplierPaymentProfile` uses `'encrypted'` for `account_number`; `SupplierPaymentAttempt` uses `'encrypted:array'` for `destination_snapshot`. Both hide raw sensitive values and expose explicit masked serializers. `SupplierAdjustment` implements `HasMedia`, uses `InteractsWithMedia`, and registers private `local`-disk collections for defect, supplier-refund, and Finance-confirmation evidence.

- [ ] **Step 5: Run migrations and the focused model/schema test.**

```bash
php artisan migrate
php artisan test tests/Feature/Procurement/ProcurementApiContractTest.php --filter='supplier.*schema|workflow.*schema'
```

Expected: migrations succeed and the contract passes.

- [ ] **Step 6: Commit schema and models.**

```bash
git commit --only -m "feat: add supplier payment and adjustment records" -- database/migrations/2026_09_12_000001_create_supplier_payment_profiles_table.php database/migrations/2026_09_12_000002_create_supplier_payment_attempts_table.php database/migrations/2026_09_12_000003_create_supplier_adjustments_table.php database/migrations/2026_09_12_000004_add_supplier_workflow_links.php app/Models/SupplierPaymentProfile.php app/Models/SupplierPaymentAttempt.php app/Models/SupplierAdjustment.php app/Models/Supplier.php app/Models/PurchaseOrderReceiptItem.php app/Models/Finance/Expense.php app/Models/Finance/ExpenseSettlement.php tests/Feature/Procurement/ProcurementApiContractTest.php
```

### Task 3: Enforce payment terms, supplier fields, and safe sorting

**Files:**

- Modify: `app/Models/PurchaseOrder.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `app/Http/Requests/StorePurchaseOrderRequest.php`
- Modify: `app/Http/Controllers/Erp/SupplierController.php`
- Modify: `app/Http/Controllers/Erp/ProcurementSettingsController.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- Create: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`
- Test: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Test: `tests/Feature/Procurement/ProcurementApiContractTest.php`

- [ ] **Step 1: Expand failing tests for all terms and snapshot precedence.**

Freeze time and assert Net 7/15/30/45/60 = receipt date plus the exact calendar-day offset. Assert precedence is explicit valid PO term, supplier default, valid Procurement setting, then Net 30, and editing the supplier later leaves the PO unchanged.

- [ ] **Step 2: Define the allowlist once on the PO domain.**

Use one constant map and one parser, for example:

```php
public const PAYMENT_TERM_DAYS = [
    'Net 7' => 7,
    'Net 15' => 15,
    'Net 30' => 30,
    'Net 45' => 45,
    'Net 60' => 60,
];
```

Reference `array_keys(PurchaseOrder::PAYMENT_TERM_DAYS)` from supplier, settings, and PO validation. Replace the permissive Net regex in `ExpenseApprovalService` with exact-map lookup.

- [ ] **Step 3: Snapshot the resolved term during locked PO creation.**

Do not read the supplier again after creating the PO. Resolve and persist the term in the same transaction that locks approved PRs and validates their common active supplier.

- [ ] **Step 4: Normalize list sorting.**

Map PO `sort_by` to `ordered_date`, `expected_delivery_date`, `po_number`, `status`, or `total_cost`; map PR sorting to `requested_date`, `pr_number`, `status`, or `total_cost`. Normalize direction to `asc` or `desc`, defaulting to the current descending date behavior.

- [ ] **Step 5: Expose existing supplier fields in the existing UI.**

Add controls for payment terms, lead time, products supplied, city, and country to create/edit/view flows. Do not add Supplier columns. Keep the current modal and `supplierApi` contract.

- [ ] **Step 6: Run backend and frontend tests.**

```bash
php artisan test tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/ProcurementApiContractTest.php tests/Unit/Services/PurchaseOrderServiceTest.php
pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/services/__tests__/procurementApis.test.ts
```

Expected: every term/due date, immutable snapshot, field round-trip, and sort allowlist passes.

- [ ] **Step 7: Commit term, supplier, and sorting changes.**

```bash
git commit --only -m "feat: enforce procurement payment terms" -- app/Models/PurchaseOrder.php app/Services/PurchaseOrderService.php app/Services/ExpenseApprovalService.php app/Http/Requests/StorePurchaseOrderRequest.php app/Http/Controllers/Erp/SupplierController.php app/Http/Controllers/Erp/ProcurementSettingsController.php app/Http/Controllers/Erp/PurchaseOrderController.php app/Http/Controllers/Erp/PurchaseRequestController.php resources/js/types/procurement.ts resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/ProcurementApiContractTest.php tests/Unit/Services/PurchaseOrderServiceTest.php resources/js/services/__tests__/procurementApis.test.ts
```

### Task 4: Add Finance Review and Release

**Files:**

- Create: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php`
- Create: `app/Http/Requests/Finance/ReviewReleaseProcurementExpenseRequest.php`
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `app/Http/Controllers/Api/Finance/ExpenseController.php`
- Modify: `routes/finance-api.php`
- Create: `tests/Feature/Finance/ProcurementExpenseReleaseTest.php`
- Modify: `tests/Feature/Finance/ExpenseSettlementTest.php`
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx`
- Create: `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx`
- Modify: `resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`

- [ ] **Step 1: Write failing release and bypass tests.**

Cover success, replay, non-procurement misuse, wrong status, voided receipt, amount mismatch, missing Finance capability, and cross-shop expense/receipt/PO/supplier. Assert the public settlement endpoint rejects procurement expenses while a direct service call still preserves its current submitted/posted guard.

- [ ] **Step 2: Run the new Finance tests and confirm failure.**

```bash
php artisan test tests/Feature/Finance/ProcurementExpenseReleaseTest.php tests/Feature/Finance/ExpenseSettlementTest.php --filter='procurement|release|manual.*settlement'
```

Expected: FAIL because the release endpoint is absent and the current public settlement endpoint permits posted procurement expenses.

- [ ] **Step 3: Implement one locked release method.**

`ExpenseApprovalService::reviewAndReleaseProcurementExpense()` must lock the expense, load the posted nonvoid receipt and same-shop PO/supplier, recompute `sum(accepted_quantity * purchase_order_items.unit_cost)` using decimal-safe arithmetic, and compare it to the stored expense amount. On success set only `status = posted`, `approved_by`, `approved_at`, and `approval_notes`; duplicate calls return a deterministic invalid-state/replay response rather than creating approval rows.

- [ ] **Step 4: Add the route and thin controller.**

Add `POST /api/finance/expenses/{id}/review-release` under the existing
`permission:access-approval-workflow|approve-expenses` capability group.
Resolve the Finance shop with existing `FinanceShopContext`, pass the actor to
the service, and use `FinanceErrorResponse` for domain failures. Viewing the
expense remains under `access-finance-expenses`.

- [ ] **Step 5: Expand the procurement expense projection.**

Return supplier, PO/receipt numbers, ordered/received/accepted/defective quantities, unit cost, payable, snapshotted term, receipt/due dates, expense status, derived payment status, and derived timing. Timing order is Overdue, Due Today, Due Soon for day offsets 1..3, then Not Due.

- [ ] **Step 6: Add the existing-page UI state.**

Render `Review & Release` only for submitted procurement expenses. Render `READY FOR PAYMENT` only for posted eligible expenses. Keep normal expense approval controls unchanged and do not show Pay Supplier while submitted.

- [ ] **Step 7: Run focused release tests.**

```bash
php artisan test tests/Feature/Finance/ProcurementExpenseReleaseTest.php tests/Feature/Finance/ExpenseSettlementTest.php
pnpm exec vitest run resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx
```

Expected: release and UI tests pass; existing manual expense approvals and settlement guards remain green.

- [ ] **Step 8: Commit Review and Release.**

```bash
git commit --only -m "feat: release procurement expenses for payment" -- app/Http/Controllers/Api/Finance/ProcurementExpenseController.php app/Http/Requests/Finance/ReviewReleaseProcurementExpenseRequest.php app/Services/ExpenseApprovalService.php app/Http/Controllers/Api/Finance/ExpenseController.php routes/finance-api.php tests/Feature/Finance/ProcurementExpenseReleaseTest.php tests/Feature/Finance/ExpenseSettlementTest.php resources/js/Pages/ERP/Finance/Expense.tsx resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx
```

### Task 5: Add supplier payment-profile management and masking

**Files:**

- Create: `app/Http/Requests/StoreSupplierPaymentProfileRequest.php`
- Modify: `app/Http/Controllers/Erp/SupplierController.php`
- Modify: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php`
- Modify: `routes/procurement-api.php`
- Modify: `routes/finance-api.php`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/services/supplierApi.ts`
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`
- Create: `tests/Feature/Finance/SupplierPaymentProfileTest.php`

- [ ] **Step 1: Write failing profile security and lifecycle tests.**

Test encrypted-at-rest account data, masked output, unique supplier profile, same-shop access, Procurement create/update, Finance verify/disable, unauthorized role denial, and bank/account edits resetting status and verifier fields to `unverified`.

- [ ] **Step 2: Run the focused test and confirm failure.**

```bash
php artisan test tests/Feature/Finance/SupplierPaymentProfileTest.php
```

Expected: FAIL because profile endpoints are absent.

- [ ] **Step 3: Implement Procurement profile management.**

Nest show/upsert routes under `/api/erp/procurement/suppliers/{id}/payment-profile`, authorize through the existing `SupplierPolicy`/`procurement.manage_suppliers`, lock and re-check `shop_owner_id`, validate destination fields, encrypt the account number through the model cast, and return only masked data.

- [ ] **Step 4: Implement Finance verification without editing.**

Expose verify and disable actions under the Finance expense/profile route. Finance receives bank name, account name, masked suffix, and status by default, with an explicit Finance-only reveal action for the account needed to perform the manual transfer. The reveal is no-store, audited, and never part of normal projections. Finance cannot submit replacement destination fields. Verification records the actor/time; disabling clears neither encrypted history nor payment-attempt snapshots.

- [ ] **Step 5: Add the existing Supplier and Finance UI controls.**

The supplier modal owns destination editing. The Finance procurement panel and payment dialog keep the destination masked by default and offer an explicit Show/Hide full-details action only to Finance. The Shop Owner sees masked details and payment approve/reject actions only; it has no profile verify/disable controls. A Finance disable action requires the existing SweetAlert confirmation. Do not cache raw details in normal projections, notifications, or errors.

- [ ] **Step 6: Run profile tests.**

```bash
php artisan test tests/Feature/Finance/SupplierPaymentProfileTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php
pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx
```

Expected: encryption, masking, transitions, role boundaries, and tenant denial pass.

- [ ] **Step 7: Commit profile support.**

```bash
git commit --only -m "feat: manage verified supplier payment profiles" -- app/Http/Requests/StoreSupplierPaymentProfileRequest.php app/Http/Controllers/Erp/SupplierController.php app/Http/Controllers/Api/Finance/ProcurementExpenseController.php routes/procurement-api.php routes/finance-api.php resources/js/types/procurement.ts resources/js/services/supplierApi.ts resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx tests/Feature/Finance/SupplierPaymentProfileTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php
```

### Task 6: Implement real manual supplier payment with Shop Owner verification

Automated PayMongo supplier disbursement is **externally blocked** for this
account because Wallet/Disbursements onboarding and a usable supplier-payout
sandbox are unavailable. Do not add a fake transfer adapter, transfer ID,
webhook, or dashboard state. Existing customer PayMongo payment/refund code is
outside this task and remains untouched.

**Files:**

- Create: `database/migrations/2026_09_12_000005_add_manual_supplier_payment_fields.php`
- Modify: `app/Models/SupplierPaymentAttempt.php`
- Modify: `app/Models/Finance/ExpenseSettlement.php`
- Modify: `app/Services/Finance/ExpenseSettlementService.php`
- Create: `app/Services/Finance/SupplierPaymentService.php`
- Create: `app/Http/Controllers/ShopOwner/SupplierPaymentController.php`
- Create: `app/Http/Requests/Finance/InitiateSupplierPaymentRequest.php`
- Create: `app/Http/Requests/Finance/SubmitSupplierPaymentProofRequest.php`
- Create: `app/Http/Requests/Finance/CancelSupplierPaymentRequest.php`
- Create: `app/Http/Requests/ShopOwner/RejectSupplierPaymentRequest.php`
- Modify: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php`
- Modify: `routes/finance-api.php`
- Modify: `routes/shop-owner-api.php`
- Create: `app/Mail/SupplierPaymentConfirmationMail.php`
- Create: `resources/views/emails/supplier-payment-confirmation.blade.php`
- Modify: `app/Services/ExpenseApprovalService.php` - reject unpaid posted procurement expenses when their receipt is voided.
- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Http/Controllers/Api/Finance/ExpenseController.php`
- Modify: `tests/Feature/Finance/ExpenseSettlementTest.php`
- Create: `tests/Feature/Finance/SupplierManualPaymentTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx`
- Modify: `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx`
- Create: `resources/js/Pages/ERP/Finance/components/SupplierPaymentDialog.tsx`
- Modify: `resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`
- Create: `resources/js/Pages/ERP/Finance/__tests__/SupplierPaymentDialog.test.tsx`

- [x] **Step 1: Record the provider blocker and approved payment rail.**

Document that the final-defense rail is a real external manual bank/e-wallet
transfer. PayMongo supplier disbursement remains future integration work; do
not require or create provider-specific request/webhook tests.

- [x] **Step 2: Add only the additive manual-payment schema.**

Use `000005_add_manual_supplier_payment_fields.php`; do not rewrite the
already-applied attempt migration. Add the manual method, verification,
rejection, cancellation, proof, and supplier-email audit fields. Checker IDs
reference `shop_owners`; the Finance initiator remains a `users` reference.

- [x] **Step 3: Implement the manual attempt lifecycle.**

Finance may initiate only for a posted procurement expense with a positive
full outstanding balance, posted nonvoid receipt, verified same-shop payment
profile, and valid supplier email. The attempt stores an encrypted destination
snapshot and immutable `supplier_email_to` snapshot. Supported methods are
`manual_bank_transfer` and `manual_e_wallet`.

The state machine is:

```text
initiating -> awaiting_verification -> succeeded
initiating -> cancelled
awaiting_verification -> rejected
```

`cancelled`, `rejected`, and `succeeded` are terminal. Cancellation requires a
reason and is allowed only before proof, external reference,
`externally_paid_at`, or settlement exists. A rejected attempt is never reused;
Finance starts a new attempt.

- [x] **Step 4: Require proof and external reference before checker review.**

Finance performs the real transfer outside SoleSpace, then submits the exact
full outstanding amount, method, external reference, paid timestamp, note, and
at least one private JPG/PNG/PDF proof through Spatie Media Library. Proof
access is tenant- and role-protected and uses no public storage URL.

- [x] **Step 5: Enforce the maker/checker boundary server-side.**

Finance uses the existing `access-finance-expenses` capability for initiation,
submission, cancellation, receipt sending, and retry. Shop Owner review uses the existing
`auth:shop_owner` plus `shop.isolation` route boundary. The service accepts a
`ShopOwner` checker and never compares its numeric ID to the Finance User ID.
Only the Shop Owner guard can confirm or reject; rejection requires a reason.

- [x] **Step 6: Settle only after Shop Owner confirmation.**

Confirmation locks the expense, receipt/PO/supplier, and attempt, rechecks the
full current outstanding balance and proof/reference, then calls only
`ExpenseSettlementService::record()` with source
`supplier_manual_payment`. It links the settlement and marks the attempt
`succeeded`; it never directly updates Expense to paid. Generic settlement and
generic reversal routes cannot bypass this flow. Confirmation does not send
supplier email; it only changes the email status to `ready_to_send`.

- [x] **Step 7: Require explicit Finance receipt sending after verification.**

The successful attempt and settlement commit first, then the attempt exposes
`ready_to_send`. Finance explicitly clicks `Send Payment Receipt` from the
existing Finance workflow. The send action resolves the immutable same-shop
supplier email snapshot, uses the existing Laravel mail infrastructure and
`SupplierPaymentConfirmationMail`, and never sends raw account data or proof.
Email status is independent:

~~~text
ready_to_send -> queued -> dispatched|failed
failed -> queued -> dispatched|failed
~~~

Use `sent` or `delivered` only when the configured mail infrastructure can
prove that state. A local log or accepted synchronous transport must not be
presented as inbox delivery. Sending is idempotent and never creates a second
settlement.

- [x] **Step 8: Preserve receipt void and PO completion guards.**

Initiating, awaiting-verification, and succeeded attempts block receipt void.
An unpaid posted expense may still be voided when it has no valid settlement;
the existing void-rejection path marks that invalid payable rejected. Rejected
and cancelled attempts do not add a payment block. PO completion blocks
initiating and awaiting-verification attempts while requiring all receipt
expenses to be posted and fully settled; receipt-driven `delivered` semantics
remain unchanged.

- [x] **Step 9: Run focused manual-payment tests and commit only green code.**

```bash
php artisan test tests/Feature/Finance/SupplierManualPaymentTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Finance/__tests__/SupplierPaymentDialog.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx
```

Commit only after these suites and PHP syntax checks pass. No
`SupplierPaymentTest.php`, `PaymongoSupplierPayoutGateway.php`, or
supplier-payout webhook branch is created.

### Task 7: Add receiving-time and post-payment issue reporting with private evidence

**Files:**

- Create: `app/Services/SupplierAdjustmentService.php`
- Create: `app/Http/Controllers/Erp/SupplierAdjustmentController.php`
- Create: `app/Http/Requests/StorePostPaymentIssueRequest.php`
- Modify: `app/Http/Requests/StorePurchaseOrderReceiptRequest.php`
- Modify: `app/Policies/PurchaseOrderPolicy.php`
- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `config/shop_modules.php`
- Modify: `routes/procurement-api.php`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/services/purchaseOrderApi.ts`
- Create conditionally: `resources/js/services/supplierAdjustmentApi.ts`
- Modify: `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx`
- Create: `resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx`
- Modify: `resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx`
- Create: `tests/Feature/Procurement/SupplierAdjustmentTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`
- Create: `resources/js/Pages/ERP/Procurement/__tests__/SupplierAdjustmentsPanel.test.tsx`
- Modify: `resources/js/services/__tests__/procurementApis.test.ts`

- [x] **Step 1: Write failing receiving-defect tests.**

For `defective_quantity > 0`, require an allowlisted category, notes, and at least one valid image; explicitly test `other` without notes. Assert reported actor/server timestamp, unit-cost snapshot, immutable report fields, excluded payable amount, private media, tenant-protected download, idempotent replay, payload conflict, and rollback/file cleanup when evidence storage fails.

- [x] **Step 2: Write failing post-payment issue tests.**

Require a posted nonvoid same-shop receipt, accepted units, posted expense, confirmed paid amount, quantity within remaining paid accepted units after open issues, category/notes/image, and Inventory authorization. Assert the original receipt, accepted quantity, stock movement, payment attempt, and settlement are unchanged. Include completed historical POs: the late adjustment is allowed but does not rewrite PO status.

- [x] **Step 3: Run issue tests and verify failure.**

```bash
php artisan test tests/Feature/Procurement/SupplierAdjustmentTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php --filter='defect|issue|evidence'
```

The initial red run failed because adjustment creation, evidence validation, and the UI surface were absent. Red tests were kept uncommitted.

- [x] **Step 4: Implement constants and lifecycle centrally.**

Put the five category constants, two issue stages, statuses, and two resolutions on `SupplierAdjustment`. `SupplierAdjustmentService` locks and rechecks shop ownership, validates transition pairs, records actor/timestamps, and writes Spatie Activitylog properties containing IDs, safe references, prior/new state, and notes but no full account details.

- [x] **Step 5: Extend canonical receipt posting atomically.**

Include category, notes, replacement ID, and stable evidence hashes in the receipt payload hash. Create each receiving-defect adjustment from its newly created receipt item inside the existing receipt transaction. Attach evidence to private `local` collections; on any exception remove newly staged media and let the database transaction roll back. Do not change accepted-stock or expense arithmetic.

- [x] **Step 6: Implement the explicit late-issue endpoint.**

Nest the action under the posted receipt item and PO so the controller can authorize `receive` on the canonical PO before calling the service. Use a shop-scoped idempotency key. Resolve supplier, PO, and expense through the receipt item; never trust submitted supplier/expense IDs.

- [x] **Step 7: Implement evidence access and immutable UI.**

Allow only configured image MIME types and sizes. Download by adjustment + media ID, verify media model binding and shop ownership, and return private/no-store responses. The receipt UI submits multipart data; the PO adjustment panel shows immutable Inventory report/evidence and appends Procurement actions separately.

- [x] **Step 8: Run issue and UI tests.**

```bash
php artisan test tests/Feature/Procurement/SupplierAdjustmentTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/SupplierAdjustmentsPanel.test.tsx resources/js/services/__tests__/procurementApis.test.ts
```

Result: corrected backend receiving/void suites pass 25 tests and 172 assertions; the broader focused backend set passed 72 tests and 29,997 assertions before the fixture correction, and the focused frontend set passed 4 files and 12 tests. The installed Vitest binary was used because pnpm is unavailable in this worktree.

- [x] **Step 9: Commit issue reporting.**

```bash
git commit --only -m "feat: report supplier quality adjustments" -- app/Services/SupplierAdjustmentService.php app/Http/Controllers/Erp/SupplierAdjustmentController.php app/Http/Requests/StorePostPaymentIssueRequest.php app/Http/Requests/StorePurchaseOrderReceiptRequest.php app/Policies/PurchaseOrderPolicy.php app/Services/PurchaseOrderReceiptService.php routes/procurement-api.php config/shop_modules.php resources/js/types/procurement.ts resources/js/services/purchaseOrderApi.ts resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx tests/Feature/Procurement/SupplierAdjustmentTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/SupplierAdjustmentsPanel.test.tsx resources/js/services/__tests__/procurementApis.test.ts
```

Omit `resources/js/services/supplierAdjustmentApi.ts` from the commit if the implementation keeps those methods in `purchaseOrderApi`.

### Task 8: Link replacement receipts through the canonical receiver

**Files:**

- Modify: `app/Http/Requests/StorePurchaseOrderReceiptRequest.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderReceiptController.php`
- Modify: `app/Policies/PurchaseOrderPolicy.php`
- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Services/SupplierAdjustmentService.php`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/services/purchaseOrderApi.ts`
- Modify: `resources/js/services/__tests__/procurementApis.test.ts`
- Modify: `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx`
- Create: `tests/Feature/Procurement/SupplierReplacementTest.php`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`

- [x] **Step 1: Write failing replacement tests.**

Added red coverage for same-shop/PO/item linkage, remaining-quantity protection, duplicate receipt keys, full resolution, defective replacement evidence, wrong adjustment/PO denial, and issue-stage-specific payable behavior. The shared receiving regression now links its replacement through the approved foreign key.

- [x] **Step 2: Add delivered/completed late-replacement coverage.**

Covered a post-payment replacement on a historically completed PO. The same canonical receiver adds inventory without a second expense and preserves `completed`; the policy/controller path still rejects unlinked ordinary receiving outside receiving statuses.

- [x] **Step 3: Run replacement tests and confirm failure.**

```bash
php artisan test tests/Feature/Procurement/SupplierReplacementTest.php
```

Observed the expected pre-implementation failures: replacement linkage was ignored, completed-PO replacement was forbidden, and defective replacement evidence created a second adjustment.

- [x] **Step 4: Implement linked replacement eligibility.**

The canonical service locks the PO/order item and delegates adjustment/original-receipt/replacement-total locking to `SupplierAdjustmentService`. It enforces same-shop/PO/item ownership, rejects resolved/refund cases and overclaims, allows delivered/completed linked replacements, and preserves closed PO status.

- [x] **Step 5: Keep payable behavior issue-stage-specific.**

Accepted quantities continue through `postInventory`. Receiving-defect replacements create the normal receipt expense; post-payment replacements create inventory and stock movement only.

- [x] **Step 6: Keep defective replacements in the same case.**

Defective replacements require the existing category/notes/image validation and append private evidence plus activity to the original adjustment. Media custom properties identify the replacement receipt item; accepted replacement totals resolve only when complete and no replacement defect remains.

- [x] **Step 7: Run replacement and existing receiving tests.**

```bash
php artisan test tests/Feature/Procurement/SupplierReplacementTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php
pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx
```

Result: 58 backend tests passed with 313 assertions across replacement, receiving, void, authorization, and schema contracts; 14 focused frontend tests passed. `pnpm` is unavailable in this worktree, so the installed Vitest binary was used.

- [x] **Step 8: Commit replacement linkage.**

```bash
git commit --only -m "feat: receive supplier replacements canonically" -- app/Http/Requests/StorePurchaseOrderReceiptRequest.php app/Services/PurchaseOrderReceiptService.php app/Services/SupplierAdjustmentService.php app/Models/PurchaseOrderReceiptItem.php resources/js/types/procurement.ts resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx tests/Feature/Procurement/SupplierReplacementTest.php resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx
```

### Task 9: Record supplier refunds through ExpenseSettlementService

**Files:**

- Create: `app/Http/Requests/Finance/ConfirmSupplierRefundRequest.php`
- Modify: `app/Services/Finance/ExpenseSettlementService.php`
- Modify: `app/Services/SupplierAdjustmentService.php`
- Modify: `app/Models/Finance/ExpenseSettlement.php`
- Modify: `app/Http/Controllers/Erp/SupplierAdjustmentController.php`
- Modify: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php`
- Modify: `routes/procurement-api.php`
- Modify: `routes/finance-api.php`
- Modify: `resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx`
- Modify: `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx`
- Modify: `resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx`
- Create: `tests/Feature/Finance/SupplierRefundTest.php`
- Modify: `tests/Feature/Finance/ExpenseSettlementTest.php`

- [x] **Step 1: Write failing proof and refund-ledger tests.**

Cover refund eligibility only after confirmed payment, supplier proof ->
`awaiting_verification`, a separate Finance-side confirmation proof required for
Finance-only confirmation, actual amount/reference/date/notes, partial/full
totals, duplicate key replay, changed-payload conflict, over-refund rejection,
cross-shop denial, and expected-amount changes in the activity log. Assert
initial unpaid receiving defects cannot use refund resolution.

- [x] **Step 2: Write settlement-state regression tests.**

Assert the original supplier payment remains fully paid after one or more `supplier_refund` entries, `refunded_amount` is reported separately, reversal arithmetic remains unchanged, and refund entries cannot be reversed through the normal settlement reversal endpoint.

- [x] **Step 3: Run refund tests and confirm failure.**

```bash
php artisan test tests/Feature/Finance/SupplierRefundTest.php tests/Feature/Finance/ExpenseSettlementTest.php --filter='supplier_refund|refunded_amount|refund proof'
```

Expected: FAIL because supplier-refund entry handling does not exist.

- [x] **Step 4: Add append-only supplier-refund recording.**

Add `ExpenseSettlement::ENTRY_SUPPLIER_REFUND`. Update settled totals to add only settlement entries and subtract only linked reversal entries; never treat every unknown entry as a reversal. Add `ExpenseSettlementService::recordSupplierRefund()` to lock adjustment/expense, enforce same shop and paid capacity, reuse the existing shop-scoped idempotency/source-reference uniqueness, and append the approved link/notes fields.

- [x] **Step 5: Implement proof and Finance confirmation.**

Expose role-scoped supplier-proof upload actions from both the Procurement
adjustment route and the Finance procurement-expense route; both delegate to
the same adjustment service. Either role may attach supplier proof and
reported details without confirming cash. Require at least one supplier-proof
media item before moving to `awaiting_verification`. Finance confirmation
requires a new, distinct Finance-side private proof media item; supplier proof
cannot satisfy that field. Then call only `recordSupplierRefund()`. Sum
confirmed entries by adjustment; set `partially_refunded` below expected and
`resolved` at the expected amount. Preserve all original settlement rows.

- [x] **Step 6: Add existing-page UI actions.**

Procurement records expected amount, communication, and supplier proof in the
adjustment panel. Finance sees original payment, expected/remaining refund,
supplier proof, a separate Finance confirmation-proof upload, and confirmation
fields in the procurement expense panel. Supplier proof alone never displays
confirmed.

- [x] **Step 7: Run refund tests.**

```bash
php artisan test tests/Feature/Finance/SupplierRefundTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/Procurement/SupplierAdjustmentTest.php
pnpm exec vitest run resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx
```

Expected: proof, partial/full confirmation, immutable payment history, and tenant assertions pass.

- [x] **Step 8: Commit refund support.**

```bash
git commit --only -m "feat: verify supplier refunds in finance ledger" -- app/Http/Requests/Finance/ConfirmSupplierRefundRequest.php app/Services/Finance/ExpenseSettlementService.php app/Services/SupplierAdjustmentService.php app/Models/Finance/ExpenseSettlement.php app/Http/Controllers/Erp/SupplierAdjustmentController.php app/Http/Controllers/Api/Finance/ProcurementExpenseController.php routes/procurement-api.php routes/finance-api.php resources/js/Pages/ERP/Procurement/components/SupplierAdjustmentsPanel.tsx resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx tests/Feature/Finance/SupplierRefundTest.php tests/Feature/Finance/ExpenseSettlementTest.php
```

### Task 10: Add void/completion guards, notifications, and final tenant coverage

**Files:**

- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Models/PurchaseOrder.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Http/Controllers/Erp/SupplierController.php`
- Modify: `app/Enums/NotificationType.php`
- Modify: `app/Services/NotificationService.php`
- Modify: `resources/js/utils/resolveNotificationActionUrl.ts`
- Modify conditionally: `resources/js/types/notifications.ts`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Modify: `tests/Feature/Procurement/ProcurementAuthorizationTest.php`
- Modify: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`

- [x] **Step 1: Write failing void and completion tests.**

Assert an unpaid submitted or posted receipt may be voided and reversed only with no successful settlement and no initiating/awaiting-verification payment attempt. Failed, rejected, and cancelled attempts remain. Initiating/awaiting-verification/succeeded blocks void. Assert `delivered` remains receipt-owned, while manual `delivered -> completed` requires every receipt expense posted/fully settled, no active payment attempt, and no unresolved adjustment.

- [x] **Step 2: Add supplier archival and tenant matrix tests.**

Block archival for unresolved adjustments, unpaid posted expenses, or active attempts. Test Shop A cannot list, read, update, verify, pay, receive replacement, confirm refund, or download evidence for Shop B records. Verify existing permissions are reused: Inventory receiving permissions, Procurement PO/supplier permissions, and Finance expense permissions; add no redundant role system.

- [x] **Step 3: Add notification tests first.**

Cover payable creation -> Finance, release -> Procurement, in transit -> Inventory, issue -> Procurement, replacement request -> Inventory/Procurement, payment success -> Procurement, payment failure -> Finance, refund proof -> Finance, and refund confirmation -> Procurement. Assert recipients are same-shop and links resolve to existing pages.

- [x] **Step 4: Implement void and completion guards under locks.**

Query settlement state and active attempts before inventory reversal. Add payable/attempt/adjustment guards to `markAsCompleted()` or the service immediately surrounding it without touching `markAsDeliveredFromReceipts()`. A late issue on an already completed PO remains auditable and resolvable; do not rewrite historical completion.

- [x] **Step 5: Add minimal notification types and methods.**

Reuse `EXPENSE_SUBMITTED` for receipt-created payable if its wording remains accurate. Add supplier-specific enum values only where existing customer/repair meanings would mislead. Route all sends through `NotificationService` and existing recipient/preference logic; notification failures must not roll back financial or inventory transactions.

- [x] **Step 6: Run guard, authorization, and notification tests.**

```bash
php artisan test tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
```

Expected: all state, archival, recipient, and cross-shop tests pass without changing receipt-owned delivery.

- [x] **Step 7: Commit guards and notifications.**

Committed as `74c5978cd` after the focused guard, authorization, and
notification tests passed.

```bash
git commit --only -m "feat: guard procurement closure and notify owners" -- app/Services/PurchaseOrderReceiptService.php app/Models/PurchaseOrder.php app/Services/PurchaseOrderService.php app/Http/Controllers/Erp/SupplierController.php app/Enums/NotificationType.php app/Services/NotificationService.php resources/js/utils/resolveNotificationActionUrl.ts resources/js/types/notifications.ts tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
```

Omit `resources/js/types/notifications.ts` if no explicit union change is required.

### Task 11: Run the full review and verification gates

**Files:**

- Modify only if behavior changed: `docs/ai-learning-log.md`
- Verify: all files changed by Tasks 1-10

- [x] **Step 1: Run focused backend workflow suites.**

```bash
php artisan test tests/Feature/Procurement tests/Feature/Finance/ProcurementExpenseReleaseTest.php tests/Feature/Finance/SupplierPaymentProfileTest.php tests/Feature/Finance/SupplierRefundTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
```

Expected: PASS for all implemented non-provider workflow tests, including
`SupplierManualPaymentTest.php`. Automated PayMongo supplier disbursement is
externally blocked, so there is no provider payout test file or webhook branch
to run.

Result: PASS — 205 tests passed, 1066 assertions, with 2 intentional MySQL
lock-test skips on SQLite. No provider payout tests or webhook branch were
created.

- [x] **Step 2: Run focused frontend suites.**

```bash
pnpm exec vitest run resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrderReceiptPanel.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/services/__tests__/procurementApis.test.ts
```

Expected: PASS.

Result: PASS — the equivalent local `node_modules/.bin/vitest.cmd` command
passed 8 files and 27 tests. `pnpm` is unavailable in this environment.

- [ ] **Step 3: Run repository quality gates.**

```bash
composer test
pnpm run test:frontend
pnpm run build
git diff --check
git status --short
```

Expected: all non-provider tests/build pass, diff check is silent, and only
intentional changes remain. Do not report TypeScript lint/type-check as passed
because the repository has no committed scripts for them.

The final report must explicitly state:

```text
Automated PayMongo supplier disbursement: EXTERNALLY BLOCKED.
Implemented supplier payment: REAL MANUAL BANK/E-WALLET TRANSFER
-> external reference and private proof
-> Shop Owner verification
-> ExpenseSettlementService settlement
-> supplier payment-confirmation email.

For the repair addendum, the controlled sequence is Shop Owner verifies payment,
settlement is recorded, email status becomes Ready to Send, Finance clicks
Send Payment Receipt, and only then is the supplier receipt dispatched.
```

Do not create placeholder PayMongo supplier-payout tests or claim automated
PayMongo supplier payout was tested.

Result: partial. `node_modules/.bin/vite.cmd build` passed and `git diff
--check` was clean. The full Vitest suite had 248 passing files and one known
pre-existing `Finance.presentation-consistency.test.ts` failure caused by an
older literal class-string expectation in `Expense.tsx`. `composer test`
reached the 300-second Composer timeout; before timeout, the new supplier
payment tests passed, while unrelated existing failures remained in refund
stage workflow and business-scaling route-catalog coverage.

- [x] **Step 4: Perform the required sequential reviews.**

Record results for: simplification/YAGNI, repository standards, approved-spec compliance, TypeScript readability, React bundle impact, security of auth/uploads/payments/secrets, reuse, dead code, and evidence. Verify no global credential fallback, raw bank detail serialization, arbitrary sorting, duplicate settlement path, second receiving path, or excluded feature was introduced.

Result: PASS. The diff reuses the existing receipt, settlement, notification,
policy, media, mail, and tenant-boundary infrastructure. No global credential
fallback, raw bank serialization, arbitrary sorting, duplicate settlement
writer, second receiving path, supplier portal, or other excluded feature was
introduced. No frontend code-splitting change was warranted; no bundle-size
improvement claim is made.

- [ ] **Step 5: Browser-check the existing pages when the local app is runnable.**

Use `@webapp-testing` to verify Supplier profile masking, receiving defect validation, adjustment evidence access, Finance release visibility, immutable payment confirmation, processing/failure/success states, replacement receiving, and partial/full refund confirmation at desktop and narrow widths.

Result: not run. The local application was not started in this verification
pass; backend feature tests and frontend component tests cover the implemented
states.

- [x] **Step 6: Record only durable project learning.**

Update `docs/ai-learning-log.md` only if implementation revealed reusable architecture guidance. Never record credentials, bank data, provider payload secrets, or personal information.

Result: no new durable learning entry was necessary.

- [ ] **Step 7: Create the final verification commit if documentation changed.**

```bash
git commit --only -m "docs: record procurement workflow verification" -- docs/ai-learning-log.md
```

Skip this commit when no durable learning was added.

Result: skipped; no durable learning documentation changed.

## Completion evidence required

The implementation handoff must list exact migrations, models, services, controllers/routes, policies or reused permissions, frontend components, notification changes, tests executed, results, unrelated existing failures, and the PayMongo blocker. It must explicitly confirm that `PurchaseOrderReceiptService`, `ExpenseSettlementService`, shop isolation, receipt-driven `delivered`, append-only payment/refund history, and every excluded feature remained intact.

## Post-audit repair addendum: prioritized procurement fixes

This addendum follows the read-only end-to-end procurement audit. It does not
rewrite the completed Tasks 1-11 and does not authorize production-code
changes by itself. It is the next implementation plan for the remaining
defects and hardening gaps.

### Audit inventory reconciliation

The inventory is corrected to **22 findings**:

- `K1`-`K9`: nine previously reported Supplier, notification, Finance,
  proof, modal, and email issues;
- `A1`-`A13`: thirteen additional findings discovered during the full audit.

`A13` remains a low-priority cleanup/dependency decision rather than being
silently omitted.

### Approved reusable e-wallet profile schema

Reusable supplier profiles support both `bank_account` and `e_wallet`.
Wallet data must never be placed in `bank_name`, `bank_code`, or a field named
`account_number`.

Phase 2 must add the smallest additive schema for:

- nullable `wallet_provider`;
- encrypted nullable `account_identifier`;
- nullable `bank_code` for e-wallet rows only;
- conditional validation and masking by `destination_type`.

Bank profiles continue to use the existing bank name/code and encrypted
account number. E-wallet profiles use `wallet_provider`, `account_name`, and
encrypted `account_identifier` such as a mobile number. Both destination
types retain the existing verification-reset, snapshot, tenant, and Finance
approval rules.

### Finding-to-phase map

| Finding | Phase |
| --- | --- |
| K1 duplicate Add Supplier fields | Phase 2, before K2 |
| K2 Add Supplier payment-profile creation | Phase 2, after K1 |
| K3 destination-type validation | Phase 2 |
| K4 account-number UX and masking | Phase 5 |
| K5 PO in-transit Inventory notification | Phase 2 |
| K6 oversized modals | Phase 5 |
| K7 stale Finance active expense/status | Phase 5 |
| K8 inline Shop Owner proof preview | Phase 5 |
| K9 supplier receipt-email state/content | Phase 2 |
| A1 shop-scoped PR/PO numbering | Phase 4 |
| A2 Stock Request raw sorting | Phase 1 |
| A3 PR update submission bypass | Phase 1 |
| A4 PO update payment-term bypass | Phase 3 |
| A5 floating-point receipt calculations | Phase 3 |
| A6 pre-commit external notifications | Phase 3 |
| A7 globally scoped manual references | Phase 2 |
| A8 stale retry evidence/request keys | Phase 1 |
| A9 future external payment dates | Phase 2 |
| A10 service-level tenant checks | Phase 1 |
| A11 duplicate confirmation-email race | Phase 1 |
| A12 raw supplier email in projections | Phase 5 |
| A13 dormant PayMongo/legacy payment states | Final cleanup review |

### Approved execution order

Execute phases strictly in this order:

`Phase 1 -> Phase 3 -> Phase 2 -> Phase 4 -> Phase 5`

Do not begin a later phase until the preceding phase's focused tests are
green. Phase 2 depends on the security/idempotency protections from Phase 1
and the financial arithmetic/transaction boundaries from Phase 3.

## Phase 1: Security, canonical workflow, and payment idempotency

**Goal:** close authorization, workflow-bypass, retry-evidence, and duplicate
email risks before changing presentation behavior.

**Files:**

- Modify: `app/Http/Controllers/Erp/StockRequestApprovalController.php`
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Modify: `app/Services/StockRequestApprovalService.php`
- Modify: `app/Services/PurchaseRequestService.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Services/Finance/SupplierPaymentService.php`
- Modify: `resources/js/Pages/ERP/Finance/components/SupplierPaymentDialog.tsx`
- Test: `tests/Feature/Procurement/ProcurementApiContractTest.php`
- Test: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`
- Test: `tests/Feature/Procurement/ProcurementAuthorizationTest.php`
- Test: `tests/Feature/Procurement/ProcurementConcurrencyTest.php`
- Test: `tests/Feature/Finance/SupplierManualPaymentTest.php`
- Test: `tests/Feature/Finance/ExpenseSettlementTest.php`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
- Test: `resources/js/Pages/ERP/Finance/__tests__/SupplierPaymentDialog.test.tsx`

- [x] **Step 1: Add red regression tests for A2, A3, A8, A10, and A11.**

Cover invalid Stock Request sorting, update-submit parity with explicit
submit, cross-shop direct service calls, retry evidence clearing and stable
idempotency keys, concurrent explicit receipt-send requests, and duplicate
supplier-email prevention. Keep maker-checker actor types unchanged.

- [x] **Step 2: Run the focused tests and verify causal failures.**

~~~text
php artisan test tests/Feature/Procurement/ProcurementApiContractTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php tests/Feature/Finance/SupplierManualPaymentTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
~~~

- [x] **Step 3: Allowlist Stock Request sorting.**

Map supported display fields to fixed query columns and normalize direction to
`asc` or `desc`. Never pass request text directly to `orderBy`.

- [x] **Step 4: Route every PR submission through the canonical service.**

Make the draft update path call `PurchaseRequestService::submitToFinance()`
instead of directly mutating `pending_finance`. Preserve manual PR-to-PO
creation, owner-approval snapshots, notifications, and tenant policy.

- [x] **Step 5: Add service-level same-shop assertions.**

At shared service boundaries, resolve the actor shop and assert that the
locked record belongs to it before mutation. Preserve controller policy checks;
this is defense in depth, not a second authorization system. Keep lock order
stable.

- [x] **Step 6: Make a new payment retry a genuinely new attempt.**

Keep one request key for one initiation action and reuse it for a network
retry. When Finance starts a new attempt after rejection/cancellation, clear
proof, external reference, paid date, note, and decision fields. Never reuse a
rejected attempt.

- [x] **Step 7: Close the explicit receipt-send race without moving accounting.**

Keep settlement and attempt success inside the committed financial transaction.
Confirmation sets email status to `ready_to_send` and performs no mail I/O.
The Finance send action claims only a ready/failed receipt using the existing
delivery state or audit mechanism, preventing two concurrent sends. Mail
failure remains separate from payment success.

- [x] **Step 8: Run the Phase 1 suite and commit only a green slice.**

Expected: invalid sort input is rejected, every submit path uses the canonical
service, direct cross-shop calls fail, retries cannot reuse evidence, and
concurrent Finance sends create at most one receipt dispatch.

Result: PASS. The focused backend gate passed 114 tests with 504 assertions
and two intentional SQLite lock-test skips. The explicit supplier receipt-send
frontend test passed 4 tests. Confirmation now records settlement and
`ready_to_send` without mail I/O; only the Finance send action may dispatch the
existing supplier receipt mailable.

## Phase 2: Supplier/payment workflow and notifications

**Goal:** clean the canonical Supplier form first, then make first-time
supplier setup, reusable bank/e-wallet profiles, Inventory notification,
manual payment references, payment dates, and supplier receipt status correct.

**Files:**

- Create: `database/migrations/2026_09_13_000001_add_supplier_wallet_profile_fields.php`
- Create: `database/migrations/2026_09_13_000003_scope_supplier_payment_reference_per_shop.php`
- Modify: `app/Http/Controllers/Erp/SupplierController.php`
- Modify: `app/Http/Requests/StoreSupplierPaymentProfileRequest.php`
- Modify: `app/Models/SupplierPaymentProfile.php` only for approved constants
  and safe serialization
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Services/NotificationService.php`
- Modify: `app/Services/Finance/SupplierPaymentService.php`
- Modify: `app/Mail/SupplierPaymentConfirmationMail.php`
- Modify: `resources/views/emails/supplier-payment-confirmation.blade.php`
- Modify: `app/Http/Controllers/ShopOwner/SupplierPaymentController.php`
- Modify: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php`
- Modify: `routes/finance-api.php`
- Modify: `resources/js/services/supplierApi.ts`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx`
- Modify: `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx`
- Modify: `resources/js/Pages/ERP/Finance/components/SupplierPaymentDialog.tsx`
- Test: `tests/Feature/Finance/SupplierPaymentProfileTest.php`
- Test: `tests/Feature/Finance/SupplierManualPaymentTest.php`
- Test: `tests/Feature/Finance/ProcurementExpenseReleaseTest.php`
- Test: `tests/Feature/Notifications/InventoryNotificationTest.php`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/SupplierPaymentDialog.test.tsx`
- Test: `resources/js/services/__tests__/procurementApis.test.ts`

- [x] **Step 1: Add red canonical Supplier form tests for K1.**

Assert that Add Supplier renders one and only one instance of every supplier
field, that Edit Supplier uses the same field meanings, and that the optional
payment-profile section can be rendered without duplicating supplier fields.

- [x] **Step 2: Remove duplicate JSX before adding profile creation.**

Use one local canonical field block for Add and Edit. Preserve the existing
form contract, restore any missing address input, and keep responsive modal
layout work deferred to Phase 5.

- [x] **Step 3: Add red profile, destination, notification, and email tests.**

Cover optional atomic profile creation, no orphan profile, supported
destination validation for bank/e-wallet fields, encrypted wallet identifiers,
verification reset, same-shop Inventory recipients, complete PO payload,
notification deduplication, and dispatch-vs-delivery email labels.

- [x] **Step 4: Implement the wallet schema and conditional validation.**

Add `wallet_provider` and encrypted `account_identifier` through the additive
migration. Make `bank_code` nullable only for e-wallet rows. Reject wallet
data in bank-only fields and reject bank-only fields that are required for no
destination type. Return masked values only.

- [x] **Step 5: Implement Add Supplier profile creation atomically.**

Use the existing Supplier policy and transaction. If the nested profile is
omitted, create no profile. If profile validation or persistence fails, roll
back the supplier as well.

- [x] **Step 6: Implement both approved destination types.**

Bank profiles use the existing bank fields and encrypted account number.
E-wallet profiles use the explicit wallet fields and encrypted account
identifier. Manual payment methods remain `manual_bank_transfer` and
`manual_e_wallet`; no PayMongo supplier payout is added.

- [x] **Step 7: Fix the in-transit notification at the existing transition.**

Keep `PurchaseOrderService::updateStatus()` as the only transition boundary.
After committed `confirmed -> in_transit`, target active same-shop users with
`inventory.view`, include PO/supplier/date/status data, and use a PO-specific
group key.

- [x] **Step 8: Correct supplier email dispatch reporting.**

Resolve the immutable snapshot recipient from the canonical same-shop
supplier/payment attempt. Keep settlement first and mail I/O after commit.
After Shop Owner confirmation, report `ready_to_send`. Finance must click
`Send Payment Receipt` before any mail I/O occurs. The send action transitions
to `queued`/`dispatched` or `failed`. Use `sent`/`delivered` only when the
configured infrastructure can prove that state; a local log or accepted
synchronous transport must not be presented as inbox delivery. Include shop,
receipt, method, reference, date, and verified/paid status in the existing
mailable.

- [x] **Step 9: Validate manual references and payment dates.**

Scope duplicate external-reference checks according to shop/method/provider
conventions and reject future `externally_paid_at` values. Preserve existing
full-outstanding-amount and verified-profile guards.

- [x] **Step 10: Run the Phase 2 suite and record the green gate.**

Expected: a supplier can be created with or without a valid profile, Inventory
receives one complete same-shop notification, Shop Owner confirmation produces
`ready_to_send` without sending mail, and the Finance action dispatches at most
one receipt while preserving settlement behavior.

Result: PASS — the focused backend gate passed 179 tests with 816 assertions,
including profile encryption/masking, atomic bank/e-wallet creation, payment
date/reference validation, same-shop Inventory notification delivery and
deduplication, explicit Finance receipt sending, and the existing procurement
authorization/release/receiving/settlement regressions. The focused frontend
gate passed 4 files and 19 tests. Changes remain uncommitted so the approved
Phase 4 tenant-key review can be completed against the same working tree.

## Phase 3: Financial correctness and transaction boundaries

**Goal:** remove rounding risk, invalid payment terms, and external side
effects that escape a failed receipt transaction.

**Files:**

- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `app/Services/NotificationService.php` only for after-commit dispatch
- Test: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Test: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Test: `tests/Feature/Procurement/ProcurementApiContractTest.php`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
- Test: `tests/Feature/Finance/ExpenseSettlementTest.php`
- Test: `tests/Feature/Finance/ProcurementExpenseReleaseTest.php`

- [x] **Step 1: Add red tests for A4, A5, and A6.**

Cover invalid PO term updates, multi-line decimal-safe receiving totals, and
notification/email suppression when later receipt work rolls back.

- [x] **Step 2: Reuse the canonical payment-term map.**

Apply the exact allowlist used during PO creation to PO updates and due-date
derivation. Do not add another regular expression or term list.

- [x] **Step 3: Replace receipt/PO float arithmetic at financial boundaries.**

Use integer centavos or decimal-string arithmetic for accepted quantity,
unit-cost multiplication, expense creation, and comparisons. Preserve
accepted/defective and post-payment replacement semantics.

- [x] **Step 4: Move external notification effects after the outer commit.**

Keep database notification rows transactional where required, but defer
email/external effects until the complete receipt transaction commits.
Notification failure must not roll back inventory, expense, or settlement work.

- [x] **Step 5: Run the Phase 3 suite and commit only a green slice.**

Expected: exact payable values remain stable for decimal costs, invalid terms
cannot enter PO updates, and failed receipt transactions create no external
approval email.

Result: PASS. The Phase 3 gate passed 85 tests with 407 assertions. Draft PO
updates now use the canonical six-term allowlist; PO and receipt totals reach
the database as decimal text derived from integer cents; and procurement
expense notifications are deferred until the outer transaction commits.

## Phase 4: Shop-scoped PR/PO numbering

**Goal:** make new human-readable procurement references independent per shop
without renumbering historical records or changing internal IDs/URLs. The
canonical tenant key was verified as `shop_owner_id` for both PR and PO:
each table foreign-keys it to `shop_owners`; Finance's `shop_id` is a separate
legacy convention and is not used for these records.

**Files:**

- Create: `database/migrations/2026_09_13_000002_scope_procurement_reference_numbers_per_shop.php`
- Modify: `app/Services/PurchaseRequestService.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Models/PurchaseRequest.php` only for reference-scope helpers
- Modify: `app/Models/PurchaseOrder.php` only for reference-scope helpers
- Test: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`
- Test: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Test: `tests/Feature/Procurement/ProcurementConcurrencyTest.php`
- Test: `tests/Feature/Procurement/ProcurementApiContractTest.php`
- Test: `tests/Unit/Services/PurchaseRequestServiceTest.php`
- Test: `tests/Unit/Services/PurchaseOrderServiceTest.php`

- [x] **Step 1: Add red cross-shop and concurrent-reference tests.**

Assert Shop A and Shop B can both receive their first matching reference,
concurrent requests cannot duplicate a reference within one shop, and
historical records remain accessible by existing IDs and old references.

- [x] **Step 2: Replace global uniqueness with shop-scoped uniqueness.**

Before writing the migration, verify which tenant key is canonical by tracing
the `shop_id` relationships on Purchase Request, Purchase Order, policies,
controllers, and existing tenant scopes. The review confirmed that
`shop_owner_id` is the true canonical key for these two tables, so the
migration replaces global PR/PO unique indexes with composite indexes on
`shop_owner_id` plus the reference column. It does not renumber or rewrite
historical rows.

- [x] **Step 3: Lock the existing shop row during generation.**

Generate the next reference while holding the canonical tenant row lock,
scoped by document type and shop. Keep duplicate-key retry only as a final
database safety net.

- [x] **Step 4: Run numbering tests and verify old links/reports.**

Expected: references are race-safe per shop and existing IDs, foreign keys,
URLs, and historical references still resolve.

Result: PASS — the migration allows independent per-shop PR/PO references,
the generators lock the canonical `shop_owners` row and scan only that shop's
references, and the Phase 4 gate passed 92 tests with 324 assertions. Four
MySQL-only concurrency tests are intentionally skipped on SQLite; the
cross-shop and sequence tests pass locally.

## Phase 5: UI, proof preview, privacy, and stale-state fixes

**Goal:** repair remaining presentation and state-refresh issues without
changing backend workflow transitions.

**Files:**

- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx`
- Modify: `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx`
- Modify: `resources/js/Pages/ERP/Finance/components/SupplierPaymentDialog.tsx`
- Modify: `app/Http/Controllers/ShopOwner/SupplierPaymentController.php`
- Modify: `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php`
- Modify: `app/Http/Controllers/Api/Finance/ExpenseController.php`
- Modify: `app/Services/Finance/SupplierPaymentService.php`
- Modify: `resources/js/types/procurement.ts`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/SupplierPaymentDialog.test.tsx`
- Test: `resources/js/services/__tests__/procurementApis.test.ts`
- Test: `tests/Feature/Finance/SupplierManualPaymentTest.php`

- [x] **Step 1: Add red UI/state/proof tests for K4, K6, K7, K8, and A12.**

Cover empty/masked account numbers, show/hide input, responsive modal
structure, refreshed active expense/status, inline proof response, nested
lightbox state, missing proof, and masked supplier email projections. The red
run failed on raw email output, forced proof downloads, new-tab proof opening,
stale status text, and the missing modal shell. K1 is tested and fixed in
Phase 2 before K2.

- [x] **Step 2: Remove duplicate JSX and reuse the existing modal shell.**

Keep the current theme and components. Use a fixed header/footer, scrolling
body, viewport max height, and responsive two-column fields. Do not introduce
a new form or modal framework.

- [x] **Step 3: Reconcile Finance state after each relevant refetch.**

Update `activeExpense` from the refreshed query result after payment, profile,
release, or refund actions. Derive the status badge from backend state rather
than showing literal `Review only` for every procurement expense.

- [x] **Step 4: Implement secure inline proof preview.**

Keep private storage and current authenticated shop-owner/Finance routes.
Return supported media inline with safe MIME handling, show bounded image
thumbnails, open a nested lightbox, preserve rejection text, and return a
safe unavailable state for missing files.

- [x] **Step 5: Mask sensitive presentation fields with controlled Finance reveal.**

Expose only masked account/email values and safe provider/profile data in normal
responses. Finance may explicitly reveal the current payment destination while
paying, then hide it again; the reveal response is no-store and audited. Never
serialize encrypted values, raw storage paths, secrets, or frontend-controlled
mail recipients. Shop Owner remains masked and cannot verify or disable a
payment profile.

- [x] **Step 6: Run the Phase 5 frontend/backend suites.**

Expected: no browser zoom or hard refresh is required, proof review stays
inside the approval workflow, and payment/profile state is consistent in the
table and open modal.

Result: PASS — the red regressions were implemented with the existing
controllers, model serializer, Finance refetch path, and modal conventions.
The Phase 5 backend gate passed 223 tests with 1,161 assertions and four
intentional SQLite skips for MySQL row-lock coverage. The frontend gate passed
five files and 23 tests, including inline image/PDF proof preview, Escape/close
handling, unavailable-file fallback, masked email projections, backend-derived
status, and responsive supplier/payment shells.

The repository-wide Vitest command was also run: 252 test files and 1,451
tests passed; three existing/temporary contract failures were reported. The
two modal-backdrop failures were caused by the new lightbox marker and were
fixed; the focused modal contracts then passed 5 tests. The remaining
`Finance.presentation-consistency.test.ts` failure is an existing exact
class-string expectation for the Finance page, outside the procurement
behavior change. The focused Purchase Orders page suite passed 8 tests after
adding its missing `getSupplierAdjustments` mock. The production Vite build
passed and `git diff --check` was clean.

### Follow-up PO closure, approval queue, and refresh repair

- [x] Require a posted receipt, fully settled receipt expenses, no active
  supplier payment attempt, and no unresolved supplier adjustment before a
  delivered PO can be completed.
- [x] Return completion eligibility and blocker messages from the PO detail
  endpoint and hide the completion action until that server decision is true.
- [x] Exclude `requires_owner_approval = false` requests from the Shop Owner
  pending queue while preserving the approval snapshot on the request.
- [x] Remove COD and 50/50 payment-term options and reject unsupported terms
  through the canonical PO allowlist.
- [x] Refresh the PO list, approved-PR selector, and metrics when the
  Procurement page becomes visible again; await Finance approval-list refreshes.

Result: PASS — the focused procurement backend regression gate passed 104
tests with 426 assertions, the focused frontend gate passed 5 files with 22
tests, and `git diff --check` was clean.

## Final cleanup review for A13

- [ ] Inspect callers of dormant PayMongo defaults/status constants and legacy
  `supplier_orders` read surfaces.
- [ ] Do not remove or migrate them during repair phases unless a live
  procurement path depends on them.
- [ ] Confirm customer PayMongo payment/refund behavior remains untouched.
- [ ] Record the result as compatibility debt or a separate approved cleanup
  task.

## Dependency-ordered execution checklist

- [x] Execute this approved addendum phase-by-phase; do not skip the green
  gate between phases.
- [x] Complete Phase 1 and green tests.
- [x] Complete Phase 3 and green financial/transaction tests.
- [x] Complete Phase 2, including K1 before K2 and the approved wallet schema.
- [x] Complete Phase 4 only after verifying the canonical tenant key:
  procurement PR/PO records use `shop_owner_id`; Finance's `shop_id` is a
  separate context key and was not used for procurement numbering.
- [x] Complete Phase 5 after backend response contracts were stable.
- [x] Run the full relevant backend suite, frontend suite, production build,
  and diff hygiene checks; record the one pre-existing frontend contract
  failure rather than claiming a clean repository-wide suite.
- [x] Do not create a Supplier Portal, alternate settlement writer, second
  receiving path, automatic PO/payment path, fake PayMongo payout, or new
  approval/proof/payment system.

## Recommended verification commands

~~~text
php artisan test tests/Feature/Procurement tests/Feature/Finance/ProcurementExpenseReleaseTest.php tests/Feature/Finance/SupplierPaymentProfileTest.php tests/Feature/Finance/SupplierManualPaymentTest.php tests/Feature/Finance/SupplierRefundTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php tests/Feature/Notifications/InventoryNotificationTest.php

pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/SuppliersManagement.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/Pages/ERP/Finance/__tests__/Expense.settlements.test.tsx resources/js/Pages/ERP/Finance/__tests__/SupplierPaymentDialog.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/services/__tests__/procurementApis.test.ts

composer test
pnpm run test:frontend
pnpm run build
git diff --check
git status --short
~~~

The final implementation report must distinguish:

- automated PayMongo supplier disbursement remains externally blocked;
- reusable bank/e-wallet profile support is backed by explicit destination
  fields, encrypted identifiers, conditional validation, and masked output;
- no wallet value is stored in bank-specific columns;
- email status distinguishes dispatch/queue acceptance from actual inbox
  delivery and never claims more than the configured mail infrastructure can
  prove;
- manual supplier payment remains external bank/e-wallet transfer followed by
  private proof, Shop Owner verification, `ExpenseSettlementService`, and a
  Finance-controlled supplier receipt email;
- all confirmed healthy receipt, settlement, authorization, tenant, and
  maker-checker behavior remains unchanged.
