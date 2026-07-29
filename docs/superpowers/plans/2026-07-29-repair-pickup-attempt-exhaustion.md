# Repair Pickup Attempt Exhaustion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the final allowed failed repair pickup terminal, prevent any retry or reassignment, and create one full refund request for the remaining paid repair amount.

**Architecture:** Keep pickup attempts separate from delivery attempts but compare their own count with the existing `max_delivery_attempts` setting. The final failed pickup closes the leg, shipment, and repair request inside the existing locked transaction; `RepairPosRefundService` creates the full refund request, while warranty/no-charge repairs skip refund creation. Existing cancelled-state guards block stale retry and assignment calls.

**Tech Stack:** Laravel 12, Eloquent transactions and row locks, PHPUnit feature tests.

---

## File Structure

- `tests/Feature/Logistics/LogisticsApiTest.php`: endpoint-level regression coverage for terminal pickup state, stale actions, full refund creation, idempotency, and warranty behavior.
- `app/Services/Logistics/ShipmentLegService.php`: determine whether a pickup attempt is terminal and atomically close the logistics and repair records.
- `app/Services/RepairPosRefundService.php`: allow the pickup-exhaustion reason to use the repair-wide refundable balance.

### Task 1: Make the final pickup attempt terminal

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`

- [ ] **Step 1: Write the failing terminal-state regression test**

Add `test_final_repair_pickup_attempt_is_terminal_and_blocks_stale_actions()` beside the existing failed-pickup tests. Create a repair pickup with `max_delivery_attempts = 2`, submit one failed pickup, reschedule and reassign it with a fresh arrival, then submit the second failed pickup.

Assert:

```php
$this->assertSame('cancelled', $leg->fresh()->status->value);
$this->assertSame('pickup_attempts_exhausted', $leg->fresh()->resolution_type);
$this->assertSame('cancelled', $leg->shipment->fresh()->status->value);
$this->assertSame('cancelled', (string) $repair->fresh()->status);
$this->assertSame(2, $leg->attempts()->where('attempt_type', 'pickup')->count());
$this->assertSame(0, $leg->attempts()->where('attempt_type', 'delivery')->count());
$this->assertFalse(
    ShipmentLeg::query()->where('return_for_leg_id', $leg->id)->exists()
);
```

Submit the stale dispatcher actions and assert both are rejected:

```php
$this->actingAs($shop, 'shop_owner')
    ->postJson("/api/logistics/legs/{$leg->id}/resolve/retry", ['reason' => 'Retry stale page.'])
    ->assertUnprocessable();

$this->actingAs($shop, 'shop_owner')
    ->postJson("/api/logistics/legs/{$leg->id}/assign", [
        'assignment_type' => 'internal_rider',
        'rider_profile_id' => $leg->assignments()->latest('id')->value('rider_profile_id'),
    ])
    ->assertUnprocessable();
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter=final_repair_pickup_attempt_is_terminal
```

Expected: FAIL because the second failed pickup still leaves the leg and shipment active in `needs_resolution`.

- [ ] **Step 3: Implement the terminal pickup transition**

In `ShipmentLegService::recordFailedAttempt()`:

1. Resolve `$maxAttempts` once from the already-loaded logistics setting.
2. Set `$terminalPickup = $isPickup && $attemptNumber >= $maxAttempts`.
3. For a pickup attempt, update the leg with:

```php
'status' => $terminalPickup ? 'cancelled' : 'needs_resolution',
'resolution_type' => $terminalPickup ? 'pickup_attempts_exhausted' : 'pickup_failed',
'resolution_reason' => $terminalPickup
    ? 'Maximum pickup attempts reached.'
    : $payload['reason_code'],
```

4. When terminal, lock and cancel the source `RepairRequest`.
5. Keep the shipment `active` only for non-terminal attempts; otherwise set it to `cancelled` with `cancelled_at`.
6. Record a customer-visible `pickup_cancelled` event for the terminal attempt. Keep the existing failed-pickup events and batch cleanup.

Do not create a return-to-shop leg and do not change delivery attempt counters.

- [ ] **Step 4: Run the test and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter=final_repair_pickup_attempt_is_terminal
```

Expected: PASS.

- [ ] **Step 5: Commit the terminal-state change**

```powershell
git add -- app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/LogisticsApiTest.php
git commit -m "fix: close repair pickups after final attempt"
```

### Task 2: Create one full refund request for paid repairs

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Services/RepairPosRefundService.php`

- [ ] **Step 1: Write failing paid-refund and warranty tests**

Add `test_final_paid_repair_pickup_requests_one_full_refund()`:

1. Create a paid repair with two paid `PosTransaction` records: an earlier
   transaction for 300 and the latest transaction for 200. Set
   `total_paid_amount = 500`, point `latest_pos_transaction_id` at the 200
   transaction, and set the pickup limit to one. This proves the refund uses
   the repair-wide remaining paid amount rather than the latest transaction's
   200 balance.
2. Submit the final failed pickup and replay the same idempotency key.
3. Assert exactly one refund exists:

```php
$this->assertDatabaseHas('pos_refunds', [
    'source_transaction_id' => $latestSource->id,
    'module_type' => 'repair',
    'module_reference_id' => $repair->id,
    'status' => 'requested',
    'request_type' => 'full',
    'requested_amount' => 500,
    'reason_code' => 'pickup_attempts_exhausted',
]);
$this->assertSame(1, PosRefund::query()
    ->where('module_type', 'repair')
    ->where('module_reference_id', $repair->id)
    ->where('reason_code', 'pickup_attempts_exhausted')
    ->count());
```

Add `test_final_warranty_pickup_cancels_without_refund()` with `is_warranty_job = true`, `billing_mode = warranty_no_charge`, and a one-attempt limit. Assert the repair and logistics records are cancelled and no `PosRefund` exists.

- [ ] **Step 2: Run both tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter="final_(paid_repair|warranty)_pickup"
```

Expected: the paid test FAILS because no refund is created. The warranty test may already pass its no-refund assertion but must also verify the terminal state.

- [ ] **Step 3: Implement full refund request creation**

Inject `RepairPosRefundService` into `ShipmentLegService`.

Add a private method called only by a terminal pickup:

```php
private function requestExhaustedPickupRefund(RepairRequest $repair, int $actorId): void
{
    if ((bool) $repair->is_warranty_job
        || (string) $repair->billing_mode === 'warranty_no_charge'
        || (float) $repair->total_paid_amount <= 0
        || ! $repair->latest_pos_transaction_id) {
        return;
    }

    $source = PosTransaction::query()->find((int) $repair->latest_pos_transaction_id);
    $amount = $this->repairRefunds->computeRepairRefundableAmount((int) $repair->id);
    if (! $source || $amount <= 0) {
        return;
    }

    $this->repairRefunds->requestRefund($source, [
        'request_type' => 'full',
        'requested_amount' => $amount,
        'reason_code' => 'pickup_attempts_exhausted',
        'reason_notes' => 'Auto-created after maximum repair pickup attempts were reached.',
    ], $actorId);
}
```

Call it after cancelling the locked repair, using `recorded_by_id` as the audit actor. The attempt idempotency return and row lock ensure replay does not create another refund.

In `RepairPosRefundService::shouldUseRepairWideLimit()`, include `pickup_attempts_exhausted` with `customer_cancelled_repair` so the request covers the repair-wide remaining paid amount:

```php
return in_array($reasonCode, [
    'customer_cancelled_repair',
    'pickup_attempts_exhausted',
], true);
```

- [ ] **Step 4: Run both tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter="final_(paid_repair|warranty)_pickup"
```

Expected: PASS.

- [ ] **Step 5: Run all failed-pickup regressions**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter="pickup"
php artisan test tests/Feature/Repair/RepairDeliveryReconciliationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the refund behavior**

```powershell
git add -- app/Services/Logistics/ShipmentLegService.php app/Services/RepairPosRefundService.php tests/Feature/Logistics/LogisticsApiTest.php
git commit -m "fix: refund exhausted repair pickups"
```

### Task 3: Verify the integrated logistics behavior

**Files:**
- Verify only; no additional source changes expected.

- [ ] **Step 1: Run focused backend suites**

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/RepairPosRefundFlowTest.php
```

Expected: PASS.

- [ ] **Step 2: Run the complete Logistics feature suite**

```powershell
php artisan test tests/Feature/Logistics
```

Expected: PASS.

- [ ] **Step 3: Check formatting and repository state**

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors and only intentional plan/source/test changes.

No frontend source changes are planned, so a new Vite build is unnecessary.
