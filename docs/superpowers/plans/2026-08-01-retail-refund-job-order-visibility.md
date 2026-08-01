# Retail Refund Job Order Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show retail refund evidence and return logistics in Job Orders, prevent stale receipt confirmation, and use one accurate payout amount in Job Orders and Finance.

**Architecture:** The server supplies a canonical payout and a minimal, authorized refund-return shipment summary. The React pages consume those fields; they do not recalculate shipping treatment locally. Existing protected proof-file routes serve all return-proof media.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit, React/TypeScript, Vitest, SweetAlert2.

---

### Task 1: Canonical payout and Staff Order payload

**Files:**
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `app/Http/Controllers/Api/RefundApprovalController.php`
- Modify: `app/Services/OrderRefundService.php`
- Test: `tests/Feature/StaffOrderRefundPayloadTest.php`
- Test: `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`

- [ ] **Step 1: Write failing feature tests**

Cover a normal returned-product refund with PHP 2,499 approved line totals and PHP 108 shipping. Assert both `/api/staff/orders` and `/api/staff/orders/{id}` plus Finance return `payoutAmountValue: 2499.00`, retain `shippingFee: 108.00`, and return refund evidence. Cover a legacy normal return with no lines and assert the PayMongo execution request excludes shipping. Cover `delivery_attempts_exhausted` with an approved shipping-inclusive payout and assert both payload and execution remain unchanged.

- [ ] **Step 2: Run RED**

Run `php artisan test tests/Feature/StaffOrderRefundPayloadTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php`. Expected: fail because canonical fields/evidence are absent.

- [ ] **Step 3: Implement canonical server fields**

Add one calculation method to the existing `OrderRefundService` and use it from `executeGatewayRefund()` and both controllers. For ordinary returns calculate `round(sum(order_refund_items.line_amount), 2)` when lines exist; otherwise subtract original shipping from `refund.amount`. For `delivery_attempts_exhausted`, retain `refund.amount`. Add `payoutAmountValue`, formatted payout, excluded shipping, and evidence media to the Finance and both Staff Order responses.

- [ ] **Step 4: Add an authorized return-logistics summary**

For the latest `order_refund`/`refund_return` shipment, serialize the `return_to_shop` leg's shipment ID, status, carrier/tracking data, and proof `{id, handoff_type, proof_type, file_url}`. Build `file_url` with `/api/logistics/proofs/{proof}/file`; never send a storage path. The authorization required for these URLs is added in Task 2.

- [ ] **Step 5: Run GREEN**

Run `php artisan test tests/Feature/StaffOrderRefundPayloadTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php`. Expected: pass.

- [ ] **Step 6: Commit**

Commit `fix: expose canonical retail refund payout` with the service, controllers, and tests.

### Task 2: Tenant-scoped staff access to refund-return proofs

**Files:**
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`

- [ ] **Step 1: Write failing authorization tests**

Create a refund-return proof and assert same-shop staff with only `access-staff-job-orders` receives it, while a cross-shop staff user and same-shop staff accessing a non-refund-return proof receive 403.

- [ ] **Step 2: Run RED**

Run `php artisan test tests/Feature/Logistics/LogisticsApiTest.php`. Expected: the same-shop staff access assertion fails.

- [ ] **Step 3: Add the narrow authorization branch**

In the existing proof-file authorization path, permit staff only when proof -> leg -> shipment matches `order_refund`/`refund_return` and the shipment shop owner matches the user's shop. Leave all other proof authorization unchanged.

- [ ] **Step 4: Run GREEN and commit**

Run the Task 2 command and expect pass. Commit `fix: authorize staff refund return proofs` with controller and test.

### Task 3: Job Order evidence, logistics, status, and stale action

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`

- [ ] **Step 1: Write failing UI tests**

Assert a succeeded normal refund whose paid amount equals `payoutAmountValue` says `Refunded`; a lower real payout says `Partially Refunded`; evidence and return-logistics data render; the receipt button disables while submitting; and the modal clears after a resellable confirmation.

- [ ] **Step 2: Run RED**

Run `npx vitest run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`. Expected: fail.

- [ ] **Step 3: Implement the smallest UI changes**

Extend the response types. Render conditional `Refund evidence` and `Return logistics` sections. Compare succeeded payout with `payoutAmountValue`, not `grand_total`. Track return confirmation in-flight state, then after success refresh orders, close the modal, and clear `viewOrder`.

- [ ] **Step 4: Run GREEN and commit**

Run the Task 2 test command and expect pass. Commit `fix: show retail return evidence and payout status` with the Job Order component and tests.

### Task 4: Align Finance review and Execute Payout

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/refundApproval.payout.test.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts`

- [ ] **Step 1: Write failing UI tests**

Use a normal-return fixture where `refundAmount` includes shipping but `payoutAmountValue` is PHP 2,499. Assert both the review panel and Execute Payout display PHP 2,499. Add the delivery-attempt exception fixture.

- [ ] **Step 2: Run RED**

Run `npx vitest run resources/js/Pages/ERP/Finance/__tests__/refundApproval.payout.test.tsx resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts`. Expected: fail.

- [ ] **Step 3: Implement and verify GREEN**

Add canonical payout fields to `RefundRequest`, normalize them from the server, and use them in review/execution display. Retain completed-execution values only when they are the same canonical payout. Run the Task 3 test command and expect pass.

- [ ] **Step 4: Commit**

Commit `fix: align finance refund payout display` with the Finance component and tests.

### Task 5: Regression verification and build

**Files:**
- Modify if generated: `public/build/manifest.json`, `public/build/assets/*`

- [ ] **Step 1: Run backend regression tests**

Run `php artisan test tests/Feature/StaffOrderRefundPayloadTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php`. Expected: pass.

- [ ] **Step 2: Run frontend regression tests**

Run `npx vitest run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts resources/js/Pages/ERP/Finance/__tests__/refundApproval.payout.test.tsx resources/js/Pages/ERP/Finance/__tests__/repairRefundExecutionPayload.test.ts`. Expected: pass.

- [ ] **Step 3: Build and commit generated assets if changed**

Run `npm run build`, `git diff --check`, and `git status --short`. If only `public/build` changed, commit `build: refresh retail refund assets`.
