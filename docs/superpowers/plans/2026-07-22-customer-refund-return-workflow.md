# Customer Refund Return Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce Staff review, Finance authorization, return pickup, physical inspection, then Finance payout for normal company-account customer refunds.

**Architecture:** Keep `OrderRefundService` as the single transition authority and reuse the existing `shop_owner_status` fields for the company Staff decision. Add Staff-scoped HTTP endpoints and make the Staff UI reflect—but never replace—the backend guards. Preserve individual-shop and failed-delivery branches explicitly.

**Tech Stack:** Laravel 12, Eloquent transactions and row locks, PHPUnit, React/TypeScript, Inertia, Vitest.

---

### Task 1: Staff-first merchant review

**Files:**
- Modify: `app/Services/OrderRefundService.php:327-530`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php:20-700`
- Modify: `routes/web.php:1100-1130`
- Test: `tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php`
- Test: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`
- Create: `tests/Feature/OrderRefundApprovalWorkflowTest.php`

- [ ] **Step 1: Write failing service tests for the company flow**

Add focused cases proving that a normal company refund accepts an explicit Staff review first, records `shop_owner_status = approved` and the Staff user ID, and rejects Finance approval while Staff review is pending. Also prove individual and `delivery_attempts_exhausted` branches retain their current behavior.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php
```

Expected: the new Staff-first cases fail because the service currently requires Finance initial approval before merchant approval.

- [ ] **Step 3: Implement the minimal service stage**

Extend `approveRequestedRefund` and `rejectRequestedRefund` with an explicit `staff` stage for normal company-account, non-failed-delivery refunds. For that branch only:

```php
if ($stageNormalized === 'staff') {
    // company + normal request only
    // pending merchant decision -> approved
    // record shop_owner_approved_by/at
}
```

Require `shop_owner_status === 'approved'` before the normal company Finance branch changes `finance_status` from `pending` directly to `approved`. Do not alter individual-shop or failed-delivery transitions.

For rejection, allow Staff to change only a pending merchant decision to `rejected`. Reject normal-company Finance rejection until Staff has approved; preserve the existing individual-shop and failed-delivery rejection paths.

- [ ] **Step 4: Write failing Staff API authorization tests**

In `tests/Feature/OrderRefundApprovalWorkflowTest.php`, cover: permitted same-shop Staff can approve/reject; cross-shop and users lacking `access-staff-job-orders` receive 403/404; repeated/non-pending decisions return 422; Finance cannot approve or reject before Staff; individual-shop and failed-delivery endpoints retain their current transitions.

- [ ] **Step 5: Add the Staff routes and controller actions**

Add POST routes beside `arrange-return-pickup`:

```text
/api/staff/orders/{order}/refund/approve
/api/staff/orders/{order}/refund/reject
```

Reuse `canAccessStaffOrders`, scope the order and latest active `request_approval` refund to the authenticated Staff shop, validate a bounded rejection reason, call the shared service, and translate `invalid_state` to HTTP 422.

- [ ] **Step 6: Run the focused backend tests and verify GREEN**

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php
```

- [ ] **Step 7: Commit**

```powershell
git add app/Services/OrderRefundService.php app/Http/Controllers/Api/StaffOrderController.php routes/web.php tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php
git commit -m "fix(refunds): require staff review before finance approval"
```

### Task 2: Make pickup arrangement a single guarded transition

**Files:**
- Modify: `app/Services/OrderRefundService.php:685-741`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php:614-690`
- Modify: `app/Services/Logistics/SourceShipmentService.php:80-115`
- Test: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php:169-210`
- Create: `tests/Feature/OrderRefundPickupCommitTest.php`
- Create: `tests/Architecture/RefundPickupLockingTest.php`

- [ ] **Step 1: Write failing arrangement tests**

Use a data provider in `SourceModuleShipmentRequestTest` for missing Staff approval, missing Finance approval, wrong `return_status`, third-party duplicate, and shop-owned duplicate. Assert HTTP 422 and database absence of new shipments, legs, assignments, and arrangement timestamp changes.

Use `DatabaseMigrations` rather than `RefreshDatabase` in `OrderRefundPickupCommitTest`, so the root test transaction does not suppress `DB::afterCommit`. Assert one committed arrangement produces one shipment and one notification, while a forced shipment failure rolls back the refund and produces no notification.

Add two race regressions: (1) load the same refund into two stale model instances and assert one accepted transition, one invalid-state transition, one shipment, and one notification; (2) because the default `:memory:` SQLite test connection cannot exercise row-level locks across processes, add a narrow architecture test that reads the `arrangeStaffReturnPickup` method body through reflection and asserts the locked database refetch occurs before the state check/update. The pair fails if either stale-state refresh or `lockForUpdate` is removed; a true MySQL concurrency test is deferred until CI provides a shared locking test database.

- [ ] **Step 2: Run tests and verify RED**

```powershell
php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php --filter=return_pickup
php artisan test tests/Feature/OrderRefundPickupCommitTest.php tests/Architecture/RefundPickupLockingTest.php
```

Expected: third-party duplicate/wrong-state cases expose the current permissive state list.

- [ ] **Step 3: Implement the guarded transaction**

In `arrangeStaffReturnPickup`, lock and refresh the refund inside a database transaction, require both approvals and exactly `pending_customer_shipment` for every carrier, then update once. For shop-owned pickup, call `SourceShipmentService::ensureRefundReturnShipment` inside the same transaction; remove the controller's post-service shipment call. Dispatch the customer notification with `DB::afterCommit`, so rollback produces no notification. Keep shipment creation idempotent for the accepted request.

- [ ] **Step 4: Run tests and verify GREEN**

```powershell
php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php --filter=return_pickup
php artisan test tests/Feature/OrderRefundPickupCommitTest.php tests/Architecture/RefundPickupLockingTest.php
```

- [ ] **Step 5: Commit**

```powershell
git add app/Services/OrderRefundService.php app/Http/Controllers/Api/StaffOrderController.php app/Services/Logistics/SourceShipmentService.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/OrderRefundPickupCommitTest.php tests/Architecture/RefundPickupLockingTest.php
git commit -m "fix(refunds): guard return pickup arrangement"
```

### Task 3: Require complete inspection before payout

**Files:**
- Modify: `app/Services/OrderRefundService.php:743-1060`
- Test: `tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php:230-390`
- Create: `tests/Feature/OrderRefundReturnInspectionTest.php`
- Test: `tests/Feature/Logistics/ShipmentLegServiceTest.php:450-490`
- Test: `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php`

- [ ] **Step 1: Write failing inspection and payout tests**

Use the unit test only for state-machine payout expectations. In the new `RefreshDatabase` feature test, prove that a normal company refund with missing, partial, duplicate, or invalid line dispositions leaves `return_status` unchanged; an injected line-update failure rolls everything back; and every requested refund line must resolve to `resellable` or `damaged`. Add regressions proving a normal rider delivery remains `in_transit` pending Staff inspection, failed-delivery completion keeps its existing automatic behavior, and individual-shop receipt behavior remains unchanged.

- [ ] **Step 2: Run tests and verify RED**

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php
```

Expected: the existing test allowing payout in transit and optional inspection behavior fail the new contract.

- [ ] **Step 3: Make receipt and inspection atomic**

For normal company-account customer refunds only, wrap `confirmReturnReceived` disposition validation, line updates, inventory disposition, and refund status update in one transaction. Compare submitted `order_item_id` values with all refund lines and reject unless every line appears exactly once with `resellable` or `damaged`. Do not swallow persistence failures. Preserve the current individual-shop and `delivery_attempts_exhausted` receipt branches.

Do not modify `completeFailedDeliveryRefundReturn`. The normal Logistics delivery path already leaves customer-requested returns `in_transit`; retain it and add a regression so rider proof cannot become a substitute for Staff inspection.

- [ ] **Step 4: Restrict payout to receipt**

Change `executeApprovedRefund` from accepting `in_transit`/`received` to accepting only `received`, with the existing clear invalid-state response.

- [ ] **Step 5: Run focused tests and failed-delivery regressions**

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php
```

- [ ] **Step 6: Commit**

```powershell
git add app/Services/OrderRefundService.php tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php
git commit -m "fix(refunds): require inspected return before payout"
```

### Task 4: Align Staff and Finance UI affordances

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx:720-1100`
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx`
- Create: `resources/js/Pages/ERP/Finance/__tests__/refundApproval.return-gates.test.tsx`

- [ ] **Step 1: Write failing UI tests**

Cover the pure decision helpers and rendered actions for: pending Staff review, pending Finance authorization, ready for pickup, in transit, and ready for Finance payout. Assert the pickup action is absent unless both statuses are approved and `return_status` is `pending_customer_shipment`. Add focused Finance tests proving approve/reject actions are unavailable before Staff approval and payout is unavailable before `received`.

- [ ] **Step 2: Run tests and verify RED**

```powershell
npm.cmd exec vitest -- run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/Finance/__tests__/refundApproval.return-gates.test.tsx
```

- [ ] **Step 3: Implement the minimum Staff UI changes**

Add Staff approve/reject calls, refresh the order list after success, and update `canArrangeReturnPickup` to require both approvals. Use the approved labels and explain that Staff approval is evidence-based eligibility review; physical inspection happens after return.

- [ ] **Step 4: Clarify Finance authorization and payout copy**

Keep Finance authorization and payout as separate actions. Authorization copy must say no money is released yet; payout remains unavailable until the API reports `return_status = received`.

- [ ] **Step 5: Run UI tests and build**

```powershell
npm.cmd exec vitest -- run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/Finance/__tests__/refundApproval.return-gates.test.tsx
npm.cmd run build
```

- [ ] **Step 6: Commit**

```powershell
git add resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/Finance/refundApproval.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/Finance/__tests__/refundApproval.return-gates.test.tsx
git add -A public/build
git commit -m "fix(refunds): align staff and finance return actions"
```

### Task 5: Full workflow verification

**Files:**
- Verify only; modify a failing file only when the failure is caused by this branch.

- [ ] **Step 1: Run the complete refund and Logistics gates**

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php
php artisan test tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/OrderRefundReturnInspectionTest.php
php artisan test tests/Feature/OrderRefundPickupCommitTest.php tests/Architecture/RefundPickupLockingTest.php
php artisan test tests/Feature/Logistics
npm.cmd exec vitest -- run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/Finance/__tests__/refundApproval.return-gates.test.tsx
npm.cmd run build
```

Expected: all commands exit 0. The current Logistics baseline is 190 tests and 812 assertions before adding this plan's new cases, so final counts must not be lower.

- [ ] **Step 2: Review the branch-only diff**

```powershell
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
git status --short
```

Confirm there are no unexpected deletions and unrelated lock/temp changes remain excluded.

- [ ] **Step 3: Push the feature branch**

```powershell
git fetch origin
git rebase origin/solespace-b
git push -u origin fix/refund-pickup-rider-availability
```
