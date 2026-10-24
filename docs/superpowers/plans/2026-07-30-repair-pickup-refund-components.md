# Repair Pickup Refund Components Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make exhausted repair-pickup refunds include or retain the paid pickup fee according to the recorded failure reason.

**Architecture:** Reuse the payment breakdown already stored on repair POS transactions and online payment sessions. Keep the policy at the existing failed-attempt/refund boundary, with no schema or new workflow.

**Tech Stack:** Laravel, Eloquent, PHPUnit feature tests

---

### Task 1: Lock the component policy with a failing feature test

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`

- [x] **Step 1: Replace the full-refund-only assertion with responsibility cases**

Cover:

```php
[
    'customer_unavailable' => ['partial', 400.00, 'retained'],
    'vehicle_or_rider_problem' => ['full', 500.00, 'included'],
    'other' => ['full', 500.00, 'Finance must decide'],
]
```

Each case records PHP 400 repair payment plus PHP 100 paid intake delivery, submits the terminal failed pickup, and asserts one pending refund with the expected type, amount, and audit note.

- [x] **Step 2: Run the focused test and verify it fails**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter=final_paid_repair_pickup
```

Expected: customer-caused case fails because the current code still requests PHP 500 as a full refund.

### Task 2: Calculate the paid intake component

**Files:**
- Modify: `app/Services/RepairPosRefundService.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`

- [x] **Step 1: Add the smallest reusable calculation**

Add `computeRecordedPaidIntakeDeliveryAmount(int $repairId): float`:

1. Sum intake delivery components from paid repair POS transactions.
2. Sum delivery components from paid initial repair payment sessions.
3. Use the larger recorded channel total to avoid double counting backfilled online payments.
4. If component metadata is absent, use the locked `intake_delivery_fee` only when a paid shop-pickup plan exists.
5. Cap the result to `total_paid_amount`.

- [x] **Step 2: Run the focused test**

Expected: still fails until the failed-attempt flow uses the new amount.

### Task 3: Apply the reason policy at the existing refund boundary

**Files:**
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`

- [x] **Step 1: Add private reason groups**

Customer-caused reasons retain the pickup fee. `vehicle_or_rider_problem` includes it. Unclassified reasons are Finance decisions.

- [x] **Step 2: Pass the terminal attempt reason to `requestExhaustedPickupRefund`**

Calculate:

```php
$fullBalance = $this->repairRefunds->computeRecordedRepairRefundableAmount($repair->id);
$pickupFee = $this->repairRefunds->computeRecordedPaidIntakeDeliveryAmount($repair->id);
```

For customer-caused failures subtract the pickup fee and create a partial request. For operations-caused failures create a full request. For ambiguous failures create a full request and state both possible approval amounts in `reason_notes`.

- [x] **Step 3: Run the focused test and verify it passes**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter=final_paid_repair_pickup
```

Expected: PASS.

### Task 4: Online payment source and regression verification

**Files:**
- Verify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Verify: `tests/Feature/RepairPosRefundFlowTest.php`

- [x] **Step 1: Backfill an accounting source for online payments**

If a paid online repair has no POS source row, create one from its recorded PayMongo reference and payment-component snapshot. Cover this with an end-to-end terminal-pickup test.

- [x] **Step 2: Run pickup and cancellation regressions**

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter=repair_pickup
php artisan test tests/Feature/RepairPosRefundFlowTest.php --filter=cancelling_a_paid_repair
```

Expected: PASS, including idempotent replay, no warranty refund, and full pre-dispatch cancellation refund.

- [x] **Step 3: Run the full touched test files**

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/RepairPosRefundFlowTest.php
```

Expected: PASS.

- [x] **Step 4: Review the final diff**

Confirm no migration, no new dependency, and no changes to repair return-delivery refunds.
