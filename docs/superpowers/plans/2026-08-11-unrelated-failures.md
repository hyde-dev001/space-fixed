# Repository Regression Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Restore the six failing repository tests without changing the approved Finance scope or publishing the branch.

**Architecture:** Fix each failure at the boundary where the regression occurs: application-level movement validation, gateway retry handling, the HR response contract, guard-aware shop isolation, and shipment schedule recovery. Existing tests provide the red cases; each focused test must pass before moving to the next root cause.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit/Pest-compatible Laravel tests, Eloquent transactions, PayMongo service doubles.

---

### Task 1: Reject invalid inventory movement types atomically

**Files:**
- Modify: `app/Models/InventoryItem.php`
- Test: `tests/Unit/InventoryItemTest.php`

- [ ] **Step 1: Make the existing invalid-movement test assert the application invariant**

Change the test to expect `InvalidArgumentException` with the item quantity and movement count unchanged. This keeps the test independent of SQLite/MySQL enum enforcement.

- [ ] **Step 2: Run the focused test and verify it fails because invalid types are accepted**

Run: `APP_KEY=... php artisan test tests/Unit/InventoryItemTest.php --filter=rolls_back_deduction_when_movement_cannot_be_recorded`

Expected: FAIL because `decrementStock` currently accepts `invalid_type`.

- [ ] **Step 3: Add minimal movement-type validation before stock mutation**

Reject values outside the existing stock movement enum in `decrementStock` before saving the quantity. Keep the existing transaction so failed movement writes cannot alter available stock.

- [ ] **Step 4: Run the focused inventory test and the full inventory unit file**

Run: `APP_KEY=... php artisan test tests/Unit/InventoryItemTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

Commit: `fix: validate inventory movement types before deduction`

### Task 2: Retry approved refunds rejected for same-day partial capture

**Files:**
- Modify: `app/Services/OrderRefundService.php`
- Test: `tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php`

- [ ] **Step 1: Confirm the existing retry test is red**

Run the focused test; it must fail with `failed` instead of `refunded`.

- [ ] **Step 2: Implement one retry in the approved-refund gateway path**

When the first gateway response matches the existing same-day partial-refund predicate, fetch the captured amount, retry once with that amount, and persist the updated refund amount only after the retry succeeds. Reuse the existing helper and preserve failure/settlement behavior.

- [ ] **Step 3: Run refund unit coverage**

Run: `APP_KEY=... php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php`

Expected: PASS.

- [ ] **Step 4: Commit**

Commit: `fix: retry approved refunds with captured amount`

### Task 3: Preserve the 13th-month release error response contract

**Files:**
- Modify: `app/Http/Controllers/Erp/HR/PayrollController.php`
- Test: `tests/Feature/HR/PayrollControllerTest.php`

- [ ] **Step 1: Confirm the existing December validation test is red**

Run the focused test; it must return 422 with no `error` field.

- [ ] **Step 2: Restore the established `error` response while logging the exception**

Keep the safe server-side log, but return the existing `13th-month release failed: ...` error payload and 422 status for this endpoint. Do not alter Finance error contracts elsewhere.

- [ ] **Step 3: Run both 13th-month tests**

Run: `APP_KEY=... php artisan test tests/Feature/HR/PayrollControllerTest.php --filter=thirteenth_month_release`

Expected: PASS.

- [ ] **Step 4: Commit**

Commit: `fix: preserve thirteenth month release errors`

### Task 4: Resolve shop isolation using the route's authenticated guard

**Files:**
- Modify: `app/Http/Middleware/ShopIsolationMiddleware.php`
- Tests: `tests/Feature/Repair/RepairAddressSnapshotTest.php`, `tests/Feature/Reports/ShopAndCustomerReportFlowTest.php`

- [ ] **Step 1: Confirm both multi-guard failures are red**

Run the failing repair address and report tests; both currently return 403 because a stale customer `user` guard wins over the active `shop_owner` guard.

- [ ] **Step 2: Prefer the guard selected by route authentication**

Use the request's active/default guard after `auth:shop_owner` or `auth:user` has run. Resolve the shop owner ID from the dedicated shop-owner guard on shop-owner routes, and use `FinanceShopContext` only for user-guard Finance/ERP routes.

- [ ] **Step 3: Run the two focused tests**

Run the two named tests; expected: PASS with the original 400 controller response and report workflow 200 response.

- [ ] **Step 4: Commit**

Commit: `fix: honor active guard in shop isolation`

### Task 5: Preserve the original schedule when reactivating a cancelled pickup

**Files:**
- Modify: `app/Services/RepairDeliveryService.php`
- Test: `tests/Feature/Repair/RepairLogisticsIntakeTest.php`

- [ ] **Step 1: Confirm the cancelled-pickup test is red**

Run the focused test; the replacement leg currently has a null schedule.

- [ ] **Step 2: Add a schedule fallback from the latest cancelled leg**

When no paid pickup-recovery entry supplies a schedule, carry forward the previous cancelled leg's `scheduled_delivery_date` and delivery window. Keep explicit recovery scheduling authoritative.

- [ ] **Step 3: Run repair logistics coverage**

Run: `APP_KEY=... php artisan test tests/Feature/Repair/RepairLogisticsIntakeTest.php`

Expected: PASS.

- [ ] **Step 4: Commit**

Commit: `fix: preserve cancelled pickup schedule on retry`

### Task 6: Full regression verification and handoff

**Files:**
- No new production files; review all task diffs and tests.

- [ ] **Step 1: Run all six previously failing tests together**
- [ ] **Step 2: Run the Finance, Procurement, approval, Inventory, Refund, HR, Repair, and Reports suites affected by the fixes**
- [ ] **Step 3: Run PHP lint and `git diff --check`**
- [ ] **Step 4: Confirm clean worktree and do not push or merge**

