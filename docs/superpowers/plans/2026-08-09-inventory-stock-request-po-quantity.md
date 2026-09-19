# Inventory Stock Request, PO Cancellation, and All-Size Quantity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Inventory create Stock Requests, block cancellation after a PO enters transit, and make new All Sizes quantities mean quantity per eligible size while preserving historical and legacy total-unit records.

**Architecture:** Reuse the existing Stock Request controller/policy, Purchase Order model/policy/UI, and physical-total procurement/receiving pipeline. Add only the route-scoped quantity-basis marker needed to distinguish new Inventory per-size requests from legacy total-unit callers; do not add a table, multiplier, or new module.

**Tech Stack:** Laravel 12, Spatie Permission, Eloquent, Inertia, React 18, TypeScript, Vitest, PHPUnit, Vite.

---

## File Map

- Modify `app/Policies/StockRequestApprovalPolicy.php`: add a route-specific Inventory create ability while retaining the Procurement/repair create ability.
- Modify `app/Http/Controllers/Erp/StockRequestApprovalController.php`: validate `quantity_basis`, resolve eligible shoe/color sizes for the Inventory route, and store one physical total.
- Modify `tests/Feature/Procurement/ProcurementAuthorizationTest.php`: prove Inventory can submit a Stock Request while remaining outside Procurement PR creation.
- Modify `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php`: cover the Inventory endpoint, legacy total callers, and per-size normalization.
- Modify `app/Models/PurchaseOrder.php`: expose one cancellable-state predicate and remove `in_transit` from the whitelist.
- Modify `app/Policies/PurchaseOrderPolicy.php`: use the model predicate for cancellation authorization.
- Modify `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx`: hide the Cancel action for non-cancellable states.
- Modify `tests/Unit/Models/PurchaseOrderTest.php`: cover direct model rejection of an unreceived In Transit PO.
- Modify `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`: cover API rejection in In Transit and retained Draft/Sent/Confirmed cancellation.
- Modify `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx`: cover UI cancellation visibility.
- Modify `resources/js/services/stockRequestApi.ts`: type the optional `quantity_basis` field for Inventory creation.
- Modify `resources/js/Pages/ERP/inventory/StockRequest.tsx`: label the new input as per-size, show the physical-total preview, and submit the marker.
- Modify `resources/js/Pages/ERP/inventory/__tests__/StockRequest.test.tsx`: cover the preview, marker payload, and stored physical total display.
- Modify `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`: prove accepted normalized Stock Requests flow to PR quantity and cost.
- Modify `tests/Feature/Procurement/PurchaseOrderItemsTest.php`: retain/assert PO quantity 200 and multiplier 1 for the normalized All Sizes flow.
- Modify `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`: receive 50 per eligible size and verify aggregate 200 inventory, movement, and expense effects.
- Modify `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`: keep exact per-size receipt payload coverage at the new physical total.
- Refresh `public/build/`: include the verified frontend changes for deployment.

### Task 1: Separate Inventory Stock Request Creation from Procurement PR Creation

**Files:**
- Modify: `app/Policies/StockRequestApprovalPolicy.php`
- Modify: `app/Http/Controllers/Erp/StockRequestApprovalController.php`
- Modify: `tests/Feature/Procurement/ProcurementAuthorizationTest.php`
- Modify: `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php`

- [ ] **Step 1: Write the failing authorization test**

Seed an Inventory Manager with the current Inventory/shared receiving permissions. Assert that `procurement.create_purchase_requests` is absent, then POST a valid request to `/api/erp/inventory/stock-requests` for an owned Inventory Item and expect `201` plus a persisted pending Stock Request. Also assert that the same user receives `403` on the Procurement `stock-requests` and `replenishment-requests` store aliases and the repair-material store route.

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php --filter=inventory_manager_can_create_stock_request
```

Expected: fail with the current `403 This action is unauthorized.` from the policy’s Procurement-only create check.

- [ ] **Step 3: Implement the minimum policy boundary**

Add a dedicated policy ability for the canonical Inventory Stock Request route that requires `view-inventory`; keep the existing create ability for Procurement, deprecated replenishment, and repair-material routes requiring `procurement.create_purchase_requests`. Select the ability from the named route in `StockRequestApprovalController::store()`. Do not add either Procurement create/submit permission back to the Inventory Manager role, and do not grant `access-stock-request-approval` or any Procurement page permission.

- [ ] **Step 4: Run focused authorization tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php
```

Expected: Inventory Stock Request creation passes, Procurement page separation and legacy replenishment tests remain green.

- [ ] **Step 5: Commit**

```powershell
git add -- app/Policies/StockRequestApprovalPolicy.php app/Http/Controllers/Erp/StockRequestApprovalController.php tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php
git commit -m "fix: allow inventory stock request creation"
```

### Task 2: Make In-Transit POs Non-Cancellable

**Files:**
- Modify: `app/Models/PurchaseOrder.php`
- Modify: `app/Policies/PurchaseOrderPolicy.php`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx`
- Modify: `tests/Unit/Models/PurchaseOrderTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx`

- [ ] **Step 1: Write the failing model/API/UI tests**

Add tests that:

1. Directly calling `cancel()` on an In Transit PO with no posted receipt throws the existing validation exception and leaves the status unchanged.
2. An authorized API cancellation for an In Transit PO is denied and leaves the PO In Transit.
3. An authorized Draft/Sent/Confirmed PO with a posted receipt reaches the domain guard, returns 422, and remains unchanged.
4. The Purchase Orders detail modal does not render `Cancel PO` for In Transit, Partially Received, Delivered, Completed, or Cancelled orders, while a Draft/Sent/Confirmed fixture still renders it.

- [ ] **Step 2: Run the tests and verify RED**

Run:

```powershell
php artisan test tests/Unit/Models/PurchaseOrderTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php
node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
```

Expected: the In Transit model/API tests currently succeed or expose Cancel, and the UI test finds the Cancel button.

- [ ] **Step 3: Add one canonical cancellability predicate**

Define `PurchaseOrder::isCancellableState()` using only `draft`, `sent`, and `confirmed`. Have `PurchaseOrderPolicy::cancel()` and the UI use that status predicate. Keep the posted-receipt check inside the domain `cancel()` method so invalid state authorization returns 403 while an otherwise cancellable PO with a posted receipt continues through the existing 422 validation path.

- [ ] **Step 4: Align the UI with the server predicate**

Replace the current UI exclusion list so `Cancel PO` is rendered only for `draft`, `sent`, or `confirmed` when the user has `procurement.cancel_purchase_orders`.

- [ ] **Step 5: Run the tests and verify GREEN**

Run the Task 2 commands again. Expected: In Transit cancellation is blocked server-side and hidden in the modal, while the earlier cancellable states remain supported.

- [ ] **Step 6: Commit**

```powershell
git add -- app/Models/PurchaseOrder.php app/Policies/PurchaseOrderPolicy.php resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx tests/Unit/Models/PurchaseOrderTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
git commit -m "fix: block cancellation after PO transit"
```

### Task 3: Normalize New All-Size Requests to a Physical Total

**Files:**
- Modify: `app/Http/Controllers/Erp/StockRequestApprovalController.php`
- Modify: `resources/js/services/stockRequestApi.ts`
- Modify: `resources/js/Pages/ERP/inventory/StockRequest.tsx`
- Modify: `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php`
- Modify: `resources/js/Pages/ERP/inventory/__tests__/StockRequest.test.tsx`
- Modify: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderItemsTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx`

- [ ] **Step 1: Write failing backend normalization tests**

Use a shoe Inventory Item with four configured sizes, including one with zero on-hand quantity, and a selected color variant. POST the canonical Inventory endpoint with `quantity_needed: 50`, `requested_size: ""`, `requested_color` matching the variant case-insensitively, and `quantity_basis: "per_size"`. Assert the created Stock Request stores `quantity_needed: 200` and does not persist `quantity_basis`.

Also assert:

- an omitted marker stores a legacy total unchanged;
- an explicit size, non-shoe item, repair source, missing/mismatched color, invalid marker, or no eligible size rows returns 422 before persistence;
- `per_size` on Procurement stock-request/replenishment aliases and the repair route returns 422/403 as appropriate and never multiplies;
- the deprecated Procurement replenishment endpoint remains total-unit behavior, including compatibility All Sizes tokens canonicalized to the existing null/blank representation.

- [ ] **Step 2: Run the backend normalization tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php --filter=quantity
```

Expected: the marker is currently an unknown/unvalidated field and the stored quantity remains 50.

- [ ] **Step 3: Implement the request-only quantity contract**

Validate optional `quantity_basis` as `total|per_size`. Apply `per_size` only on the named Inventory Stock Request route with the default manual source; reject it on Procurement/repair aliases. Treat an empty `requested_size` plus existing compatibility All Sizes tokens as All Sizes, then canonicalize the stored value to null/blank before downstream PR/PO snapshotting. Resolve the selected color variant’s configured unique size rows, or the item’s configured size rows when no color variants exist; reject invalid combinations and multiply the entered quantity by the resolved row count once. Store only the resulting physical total in `quantity_needed`; never store or infer a multiplier.

- [ ] **Step 4: Update the Inventory form and API type**

Add `quantity_basis?: "total" | "per_size"` to `createFromInventory`. For a shoe All Sizes selection, label the field `Quantity per size`, show `number of eligible sizes × entered quantity = total units`, and send `quantity_basis: "per_size"`. Keep specific-size, non-shoe, repair, and legacy callers on the total-unit contract. Show the stored physical total in Stock Request details.

- [ ] **Step 5: Run the backend and component tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Procurement/PurchaseOrderItemsTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/ProcurementQuantityNormalizationTest.php
node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/inventory/__tests__/StockRequest.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
```

Expected: 4 × 50 becomes 200 through Stock Request → PR → PO; PO header/item total cost is based on 200; receiving 50 per size accepts 200, updates each size by 50, and calculates aggregate stock movement/expense from 200. Existing total-unit, normalization, and receipt edge cases remain green.

- [ ] **Step 6: Commit**

```powershell
git add -- app/Http/Controllers/Erp/StockRequestApprovalController.php resources/js/services/stockRequestApi.ts resources/js/Pages/ERP/inventory/StockRequest.tsx tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php resources/js/Pages/ERP/inventory/__tests__/StockRequest.test.tsx tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Procurement/PurchaseOrderItemsTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
git commit -m "fix: normalize all-size stock request quantities"
```

### Task 4: Regression Verification and Deployment Build

**Files:**
- Refresh: `public/build/`

- [ ] **Step 1: Run focused frontend tests**

```powershell
node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/inventory/__tests__/StockRequest.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrdersPage.test.tsx
```

- [ ] **Step 2: Run focused backend tests**

```powershell
php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Procurement/PurchaseOrderItemsTest.php tests/Feature/Procurement/ProcurementQuantityNormalizationTest.php tests/Unit/Models/PurchaseOrderTest.php
```

- [ ] **Step 3: Run the complete frontend suite**

```powershell
node node_modules/vitest/vitest.mjs run
```

- [ ] **Step 4: Build production assets**

```powershell
node node_modules/vite/bin/vite.js build
```

Expected: Vite exits 0 and refreshes the manifest/chunks for the Stock Request and Purchase Orders pages.

- [ ] **Step 5: Check, commit, and push**

```powershell
git status --short
git diff --check
git add -A -- public/build
git commit -m "build: refresh stock request and purchase order assets"
git push --force-with-lease origin fix/procurement-practical-gaps
```

The force-with-lease is required because this branch was previously rebased; do not use an unconditional force push. Existing unreceived POs must remain unchanged after deployment.
