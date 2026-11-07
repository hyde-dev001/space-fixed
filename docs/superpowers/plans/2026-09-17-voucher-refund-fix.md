# Voucher Refund Amount and Status Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make normal product refunds use the amount actually captured by PayMongo minus shipping, including voucher orders, and show a successful full refund as `Refunded`.

**Architecture:** Keep the existing refund approval and gateway workflow. Resolve the captured amount at refund-request time when PayMongo can provide it, preserve a local fallback for legacy/test fixtures, and expose the effective payout amount to the shop-owner/staff order projections so the UI does not infer completion from pre-voucher line totals.

**Tech Stack:** Laravel 12, PHP 8.2, PayMongo refund service, React 18, TypeScript, Vitest.

---

### Task 1: Lock the refund amount contract with regression tests

**Files:**
- Modify: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`
- Modify: `resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx` (testable pure helper if needed)

- [x] Add backend tests for voucher-like full and partial refunds whose PayMongo capture is lower than the legacy/order-item total and whose shipping fee is excluded from the product refund.
- [x] Lock the product-only full-refund contract and the single-proration partial-refund contract.
- [x] Add frontend regression assertions for succeeded request-approval refunds; full item coverage renders `Refunded`, not `Partially Refunded`.
- [x] Run the focused tests and confirm the new backend behavior failed before implementation.

### Task 2: Use the captured payment amount for normal product refunds

**Files:**
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `app/Services/OrderRefundService.php` only if the shared reservation guard needs the same normalization

- [x] Resolve the PayMongo payment capture in the existing request path using the already-injected service.
- [x] Calculate the normal full refund as captured amount minus the persisted shipping fee; keep delivery-attempts-exhausted behavior unchanged.
- [x] Retain the existing local calculation when the capture lookup is unavailable so old/manual/test records remain usable.
- [x] Ensure explicit requested amounts cannot exceed the product-only refundable amount and do not introduce a second refund attempt.
- [x] Prevent an already-prorated partial line from being discounted again during gateway execution.
- [x] Run the focused Laravel refund tests.

### Task 3: Make shop-owner status use the authoritative refund amount

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/OrderController.php`
- Modify: `resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx`
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Test: existing focused source-contract tests

- [x] Include the effective latest refund payout amount in both shop-owner order projections.
- [x] Prefer that amount for online succeeded refunds; retain the existing POS summary behavior.
- [x] Treat a successful full request-approval refund as `Refunded`; compare item-line amounts only for partial refunds.
- [x] Keep customer and staff partial-refund displays aligned with the voucher allocation.
- [x] Run the focused frontend test and verify the existing order status/return flows remain intact.

### Task 4: Verify and review

- [x] Run `git diff --check`.
- [x] Run the focused Laravel and frontend tests; run the frontend build before completion because TSX changed.
- [x] Review the diff for unrelated workflow changes, secret exposure, and stale amount heuristics.
- [x] Record final build result and exact verification results before reporting completion.
