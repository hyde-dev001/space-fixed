# Procurement Practical Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the seven production procurement defects by enforcing tenant-safe drafts, valid notification routes, one total-unit quantity meaning, Inventory-owned receiving, and Finance-only review of receipt expenses.

**Architecture:** Keep the existing Purchase Request, Purchase Order, Receipt, Inventory, and Expense models. Fix source-of-truth rules at their current shared boundaries: the PR controller copies approved Stock Request data, the PO/receipt services use physical totals and exact size allocations, notification services emit recipient-safe routes, and the existing Inventory Supplier Order page hosts the canonical receipt panel. Add one idempotent data migration for previously multiplied all-size records; do not add new workflow tables or a second receiving service.

**Tech Stack:** Laravel/PHP 8, Eloquent, Spatie permissions, Inertia React/TypeScript, Vitest/Testing Library, PHPUnit.

**Approved design:** `docs/superpowers/specs/2026-08-02-procurement-practical-gaps-design.md`

---

## File map

- `resources/js/utils/modalDraft.ts` — build tenant/user-scoped draft keys.
- `resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx` and `resources/js/Pages/ERP/inventory/StockRequest.tsx` — use scoped keys and reject stale restored sources.
- `app/Http/Requests/StorePurchaseRequestRequest.php` and `app/Http/Controllers/Erp/PurchaseRequestController.php` — stop accepting source-owned PR fields and copy them from the locked Stock Request.
- `app/Services/StockRequestApprovalService.php`, `app/Services/PurchaseRequestService.php`, and `app/Services/NotificationService.php` — emit real, recipient-safe destinations with record IDs.
- Purchase Request pages for Procurement, Finance, Shop Owner, and Inventory Stock Request — consume the query ID and open the matching same-shop record.
- `database/migrations/2026_08_02_000005_normalize_procurement_quantities.php` — normalize legacy all-size PR/PO/receipt quantities without replaying inventory or Finance.
- `app/Models/PurchaseOrderItem.php` and `app/Services/PurchaseOrderService.php` — retain exact eligible sizes but create new PO items with total quantities and multiplier `1`.
- `app/Http/Requests/StorePurchaseOrderReceiptRequest.php` and `app/Services/PurchaseOrderReceiptService.php` — validate and post exact per-size receipt allocations.
- `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx` — render per-size inputs when required.
- `resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx` and `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx` — move receipt actions to Inventory and leave Procurement read-only.
- `app/Policies/PurchaseOrderPolicy.php` and `database/seeders/RolesAndPermissionsSeeder.php` — enforce Inventory ownership of receiving.
- `app/Services/ExpenseApprovalService.php` and `app/Http/Controllers/ShopOwner/ExpenseController.php` — notify Finance and exclude procurement receipt expenses from Shop Owner approval.
- Focused PHPUnit and Vitest files — lock every reported regression.

---

### Task 1: Make drafts tenant-safe and Stock Requests authoritative

**Files:**
- Modify: `resources/js/utils/modalDraft.ts`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx`
- Modify: `resources/js/Pages/ERP/inventory/StockRequest.tsx`
- Modify: `app/Http/Requests/StorePurchaseRequestRequest.php`
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Create: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestPage.test.tsx`
- Test: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`

- [ ] **Step 1: Write failing frontend draft tests**

Cover key construction for two shops/users and restoration only when the saved `stockRequestId` exists in the current `initialAcceptedRequests`. Assert the old global key does not prompt restoration.

- [ ] **Step 2: Run the focused frontend test and confirm RED**

Run: `npm run test:frontend -- resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestPage.test.tsx`

Expected: FAIL because draft storage uses `erp.purchase-request.create-modal.draft` globally.

- [ ] **Step 3: Add the minimal scoped-key helper and page guards**

Add a helper shaped like:

```ts
export const scopedModalDraftKey = (base: string, shopId: number | string, userId: number | string) =>
  `${base}:${shopId}:${userId}`;
```

Both modal pages read auth data from Inertia props, remove their legacy global key once, and use only the scoped key. Purchase Request restoration verifies its source against the current approved/unused collection before offering restore.

- [ ] **Step 4: Write failing backend source-authority tests**

Submit a valid accepted Stock Request while spoofing product, inventory, size, color, quantity, and priority in the PR payload. Assert the created PR uses the Stock Request values and computes `total_cost = quantity_needed * unit_cost`.

- [ ] **Step 5: Run the backend test and confirm RED**

Run: `php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`

Expected: FAIL because the controller currently trusts the browser fields and multiplies all-size totals.

- [ ] **Step 6: Copy source fields under the existing lock**

Remove source-owned fields from `StorePurchaseRequestRequest` requirements. In `PurchaseRequestController::store()`, assign them from `$sourceStockRequest` after the locked same-shop/status check, and calculate total cost once as total quantity times unit cost. Preserve supplier, justification, notes, and submit intent from the request.

- [ ] **Step 7: Run focused tests and commit**

Run:

```powershell
php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php
npm run test:frontend -- resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestPage.test.tsx
```

Commit: `fix: scope procurement drafts and trust stock requests`

---

### Task 2: Repair notification routes and record deep-links

**Files:**
- Modify: `app/Services/StockRequestApprovalService.php`
- Modify: `app/Services/PurchaseRequestService.php`
- Modify: `app/Services/NotificationService.php`
- Modify: `resources/js/Pages/ERP/inventory/StockRequest.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/StockRequestApproval.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx`
- Modify: `resources/js/Pages/ERP/Finance/PurchaseRequestApproval.tsx`
- Modify: `resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx`
- Test: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx`

- [ ] **Step 1: Write failing notification destination tests**

Assert exact actions:

```text
/erp/inventory/stock-request?stock_request=<id>
/erp/procurement/stock-request-approval?stock_request=<id>
/finance/purchase-request-approval?purchase_request=<id>
/shop-owner/purchase-request-approval?purchase_request=<id>
/erp/procurement/purchase-request?purchase_request=<id>
```

Cover the initial Finance notice, owner stage, Finance-final return, requester approval/rejection, and Stock Request requester results.

- [ ] **Step 2: Run notification tests and confirm RED**

Run: `php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php`

Expected: FAIL on the current forbidden/nonexistent URLs.

- [ ] **Step 3: Replace only the bad action URLs**

Build URLs with the existing payload IDs. Do not add a generic notification router or new route aliases.

- [ ] **Step 4: Add query-driven modal selection**

On each target page, parse its one supported query parameter after records load and open the matching record if present. Leave normal same-shop APIs authoritative and silently ignore an absent ID.

- [ ] **Step 5: Run notification and frontend tests and commit**

Run:

```powershell
php artisan test tests/Feature/Notifications/NotificationCriticalFlowsTest.php
npm run test:frontend -- resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx
```

Commit: `fix: route procurement notifications by recipient`

---

### Task 3: Normalize quantity semantics and existing records

**Files:**
- Create: `database/migrations/2026_08_02_000005_normalize_procurement_quantities.php`
- Modify: `app/Models/PurchaseOrderItem.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Modify: `app/Http/Controllers/ShopOwner/PurchaseRequestController.php`
- Modify: Purchase Request and Purchase Order quantity displays that call `getEffectiveQuantity`
- Create: `tests/Feature/Procurement/ProcurementQuantityNormalizationTest.php`
- Test: `tests/Feature/Procurement/PurchaseOrderItemsTest.php`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx`
- Modify: `resources/js/Pages/ERP/Finance/PurchaseRequestApproval.tsx`
- Modify: `resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx`

- [ ] **Step 1: Write failing new-record tests**

Create an all-size Stock Request for 200 units and assert PR quantity `200`, total cost `200 * unit_cost`, PO header/item quantity `200`, and `quantity_multiplier = 1` while eligible size IDs remain snapshotted.

- [ ] **Step 2: Write a failing migration test**

Seed a legacy all-size PR/PO item with quantity `50`, multiplier `4`, line total for `200`, and a posted receipt for `10` per size. After migration, assert PR/PO item quantity `200`, receipt accepted quantity `40`, multiplier `1`, unchanged stock movement/inventory/expense counts and amounts, and idempotent second execution.

- [ ] **Step 3: Run focused tests and confirm RED**

Run:

```powershell
php artisan test tests/Feature/Procurement/ProcurementQuantityNormalizationTest.php
php artisan test tests/Feature/Procurement/PurchaseOrderItemsTest.php
```

- [ ] **Step 4: Implement total-unit creation semantics**

Keep `eligible_size_ids`, set new item multiplier to `1`, remove size-count multiplication from PR totals, and display stored quantities directly.

- [ ] **Step 5: Implement the idempotent normalization migration**

For `quantity_multiplier > 1`, multiply ordered and receipt quantity fields by the old multiplier, set multiplier to `1`, and recalculate affected PO headers. Normalize matching all-size PR quantity from `total_cost / unit_cost`. Do not call inventory, movement, receipt-posting, or expense services. Because this is a corrective data rewrite with no schema change, `down()` is intentionally a no-op; dividing later would corrupt records created after deployment.

- [ ] **Step 6: Run quantity tests and commit**

Commit: `fix: use total units throughout procurement`

---

### Task 4: Receive all-size orders using exact Inventory allocations

**Files:**
- Modify: `app/Http/Requests/StorePurchaseOrderReceiptRequest.php`
- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Models/PurchaseOrderItem.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `resources/js/types/procurement.ts`
- Modify: `resources/js/services/purchaseOrderApi.ts`
- Modify: `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx`
- Test: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Test: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`

- [ ] **Step 1: Write failing backend allocation tests**

Submit an all-size receipt with nested rows:

```php
'size_quantities' => [
    ['inventory_size_id' => $size7->id, 'received_quantity' => 40, 'defective_quantity' => 2],
    ['inventory_size_id' => $size8->id, 'received_quantity' => 60, 'defective_quantity' => 1],
]
```

Assert aggregate receipt fields, exact size/parent/color increments, expense value from aggregate accepted units, remaining total, duplicate-size rejection, foreign/ineligible-size rejection, over-acceptance rollback, and exact void reversal.

- [ ] **Step 2: Run receiving tests and confirm RED**

Run:

```powershell
php artisan test tests/Feature/Procurement/PurchaseOrderReceivingTest.php
php artisan test tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php
```

- [ ] **Step 3: Extend validation minimally**

Allow optional `items.*.size_quantities`; validate integer non-negative rows and defects. The service requires allocations only when an item has multiple eligible size IDs, validates IDs under lock, aggregates totals, and includes normalized allocations in the idempotency hash. Load each PO item's Purchase Request inventory sizes/color variants in PO detail responses so the UI can label the snapshotted IDs without a second API.

- [ ] **Step 4: Post exact size effects**

Replace the all-size multiplier loop with the submitted net quantity for each eligible size. Parent and selected-color deltas equal the aggregate accepted total. Preserve the existing inventory-effect JSON shape so voiding continues to reverse recorded deltas.

- [ ] **Step 5: Write failing frontend per-size tests**

Assert multiple eligible sizes render received/defective inputs per size and the API payload contains exact rows; specific/non-size items retain the compact line input. Assert failed submission retains values.

- [ ] **Step 6: Implement the minimal receipt-panel UI**

Use `eligible_size_ids` plus size labels exposed in the PO detail payload. Do not create a new component library or variant table.

- [ ] **Step 7: Run focused backend/frontend tests and commit**

Commit: `fix: receive exact quantities by inventory size`

---

### Task 5: Make Supplier Order Monitoring the Inventory receiving workspace

**Files:**
- Modify: `resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx`
- Modify: `app/Policies/PurchaseOrderPolicy.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Create: `resources/js/Pages/ERP/inventory/__tests__/SupplierOrderMonitoring.test.tsx`
- Test: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx`
- Test: `tests/Feature/Procurement/ProcurementAuthorizationTest.php`

- [ ] **Step 1: Write failing authorization tests**

Assert an Inventory Manager with receiving permission and Inventory access can post a same-shop receipt, a Procurement-only account cannot receive, and neither can receive a completed/foreign PO.

- [ ] **Step 2: Write failing page tests**

Inventory page: no redirect button/read-only warning; View action fetches PO detail; in-transit modal renders receipt inputs; completed modal renders history only. Procurement page: receipt history remains, receive inputs are absent.

- [ ] **Step 3: Run focused tests and confirm RED**

Run:

```powershell
php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php
npm run test:frontend -- resources/js/Pages/ERP/inventory/__tests__/SupplierOrderMonitoring.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
```

- [ ] **Step 4: Reuse the canonical detail API and receipt panel**

Add only modal/view state to Supplier Order Monitoring. Fetch the selected PO with `purchaseOrderApi.getById`, pass `canReceive` based on status, and refresh after posting. Render the same panel in Procurement with `canReceive={false}`.

- [ ] **Step 5: Tighten policy and defaults**

Require `procurement.receive_purchase_orders`, same shop, and Inventory module access in `PurchaseOrderPolicy::receive()`. Remove receiving permission from Procurement Manager's seeded defaults; keep it for Inventory Manager.

- [ ] **Step 6: Run focused tests and commit**

Commit: `fix: move purchase order receiving to inventory`

---

### Task 6: Keep procurement expenses in Finance only

**Files:**
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `app/Http/Controllers/ShopOwner/ExpenseController.php`
- Create: `tests/Feature/Finance/ProcurementExpenseReviewTest.php`
- Test: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Test: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`

- [ ] **Step 1: Write failing Finance/Owner boundary tests**

After receipt posting, assert the expense is `submitted`, has no multi-level approval, is listed for Finance, is absent from Shop Owner expense index, returns `404` from direct Shop Owner approve/reject, and can be approved/rejected once by authorized Finance.

- [ ] **Step 2: Run tests and confirm RED**

Run: `php artisan test tests/Feature/Finance/ProcurementExpenseReviewTest.php`

- [ ] **Step 3: Apply the procurement-only filter and Finance notice**

Scope all Shop Owner expense queries to `whereNull('procurement_receipt_id')`. Notify Finance only when `submitProcurementExpense()` actually creates a new expense. Keep general expense workflows unchanged.

- [ ] **Step 4: Verify receipt void behavior and commit**

Run:

```powershell
php artisan test tests/Feature/Finance/ProcurementExpenseReviewTest.php
php artisan test tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php
```

Commit: `fix: send procurement expenses only to finance`

---

### Task 7: Update documentation and run release verification

**Files:**
- Modify: `docs/procurement/inventory-procurement-workflow.md`
- Modify: `docs/superpowers/plans/2026-08-02-procurement-practical-gaps.md` (check completed steps)
- Regenerate: `public/build/**`

- [ ] **Step 1: Update the operator workflow**

Document total-unit semantics, Inventory receiving ownership, exact size allocations, notification destinations, and Finance-only receipt-expense review.

- [ ] **Step 2: Run backend regression suites**

Run:

```powershell
php artisan test tests/Feature/Procurement tests/Feature/Notifications/NotificationCriticalFlowsTest.php tests/Feature/Finance/ProcurementExpenseReviewTest.php tests/Unit/Services/PurchaseOrderServiceTest.php tests/Unit/Services/PurchaseRequestServiceTest.php
```

Expected: all pass; the existing MySQL-only concurrency test may remain skipped on SQLite.

- [ ] **Step 3: Run frontend tests and production build**

Run:

```powershell
npm run test:frontend -- resources/js/Pages/ERP/Procurement/__tests__ resources/js/Pages/ERP/inventory/__tests__/SupplierOrderMonitoring.test.tsx
npm run build
```

Expected: all selected Vitest tests pass and Vite emits a fresh `public/build/manifest.json` and assets.

- [ ] **Step 4: Run static release checks**

Run:

```powershell
php artisan route:list --path=purchase-request
php artisan route:list --path=purchase-orders
git diff --check
git status --short
```

- [ ] **Step 5: Manual smoke test**

Use two shops and four roles to verify all seven reported scenarios, including the exact Inventory page screenshot path. Confirm a completed PO is view-only and an in-transit all-size PO receives exact per-size totals.

- [ ] **Step 6: Commit release artifacts**

Commit: `docs: finalize procurement practical workflow`

---

## Done criteria

- All seven reported defects have an automated regression test.
- No cross-shop draft or API record is visible.
- Notifications land on accessible existing pages and select the intended record.
- One physical quantity is preserved end-to-end.
- Only Inventory records receipts/defects; Procurement only sees receipt history.
- Receipt expenses require Finance review without a second Shop Owner approval.
- Existing stock movements and expenses are not replayed during normalization.
- Backend tests, frontend tests, and a fresh production build pass.
