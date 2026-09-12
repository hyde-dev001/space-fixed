# Failed-Delivery Return Auto-Refund Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically receive and restock failed-delivery refunds when the shop approves the rider's return-to-shop handoff.

**Architecture:** Keep logistics as the trigger and reuse `OrderRefundService::confirmReturnReceived()` as the single refund/inventory completion path. `ShipmentLegService` generates `resellable` dispositions from the reserved refund lines after the return leg and proof are approved, including the already-delivered retry path. No frontend change or schema is needed.

**Tech Stack:** Laravel 11, Eloquent transactions, PHPUnit feature tests

---

### Task 1: Auto-complete failed-delivery return refunds

**Files:**
- Modify: `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php:146-216`
- Modify: `tests/Feature/Logistics/ReturnToShopTest.php:94-157`
- Modify: `app/Services/Logistics/ShipmentLegService.php:203-226`

- [ ] **Step 1: Write the failing first-confirmation test**

Update `test_failed_delivery_receipt_requires_completed_return_and_applies_every_line_once` so the proof starts as `rider_confirmed`, then call the real logistics receipt service instead of manually delivering the leg or submitting Staff inspection data:

```php
$proof = HandoffProof::factory()->create([
    'shipment_leg_id' => $return->id,
    'handoff_type' => 'receive',
    'proof_type' => 'photo',
    'review_status' => 'rider_confirmed',
]);

$product = $items->first()->product;
$variant = $items->first()->productVariant;
$productStock = $product->stock_quantity;
$variantStock = $variant->quantity;

app(ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $order->shopOwner);

$this->assertSame('received', $refund->fresh()->return_status);
$this->assertNotNull($refund->fresh()->return_confirmed_at);
$this->assertNull($refund->fresh()->return_confirmed_by_staff_id);
$this->assertSame($productStock + 2, $product->fresh()->stock_quantity);
$this->assertSame($variantStock + 2, $variant->fresh()->quantity);
$this->assertDatabaseMissing('order_refund_items', [
    'order_refund_id' => $refund->id,
    'inspection_disposition' => 'pending',
]);
$this->assertSame(2, $refund->items()->where('inventory_action', 'restock')->count());
```

- [ ] **Step 2: Add the failing legacy-retry assertions**

In the same test, simulate a record completed by the old logistics code, retry the same endpoint, and prove reconciliation without duplicate stock:

```php
$refund->update(['return_status' => 'pending_staff_pickup']);
$stockAfterFirstReceipt = $product->fresh()->stock_quantity;
$variantStockAfterFirstReceipt = $variant->fresh()->quantity;

app(ShipmentLegService::class)->confirmReturnReceipt($return->fresh(), $proof->fresh(), $order->shopOwner);

$this->assertSame('received', $refund->fresh()->return_status);
$this->assertSame($stockAfterFirstReceipt, $product->fresh()->stock_quantity);
$this->assertSame($variantStockAfterFirstReceipt, $variant->fresh()->quantity);
```

Retain the Finance approval assertions. Remove the obsolete explicit Staff inspection call from this test.

- [ ] **Step 3: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php --filter=failed_delivery_receipt_requires_completed_return_and_applies_every_line_once`

Expected: FAIL because logistics receipt currently leaves the refund `in_transit` and does not restock its lines.

- [ ] **Step 4: Add the minimal shared completion method**

Add this private method to `ShipmentLegService`:

```php
private function completeFailedDeliveryRefundReturn(ShipmentLeg $return, ShipmentLeg $original): void
{
    if ($return->shipment->source_type !== 'order' || $return->shipment->purpose !== 'retail_delivery') {
        return;
    }

    $refund = OrderRefund::query()
        ->with('items')
        ->where('order_id', $return->shipment->source_id)
        ->where('reason_code', 'delivery_attempts_exhausted')
        ->where('idempotency_key', "delivery-attempts-exhausted:{$return->shipment->source_id}:{$original->id}")
        ->latest('id')
        ->first();

    if (!$refund) {
        return;
    }

    $this->refunds->confirmReturnReceived(
        $refund,
        null,
        lineDispositions: $refund->items->map(fn ($line) => [
            'order_item_id' => (int) $line->order_item_id,
            'approved_qty' => (int) $line->approved_qty,
            'inspection_disposition' => 'resellable',
        ])->all(),
    );
}
```

In `confirmReturnReceipt`, load the original leg before the idempotent early return. Call the method both for an already-delivered/approved receipt and after approving a new receipt. Delete the old `pending_staff_pickup` to `in_transit` query.

- [ ] **Step 5: Keep the generic return test focused**

Remove the synthetic refund setup and its `in_transit` assertion from `ReturnToShopTest::test_rider_handoff_and_dispatcher_receipt_complete_return_through_api`. That test continues proving logistics receipt when no matching refund exists.

- [ ] **Step 6: Run focused tests and verify GREEN**

Run: `php artisan test tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php tests/Feature/Logistics/ReturnToShopTest.php`

Expected: both files pass; coverage proves automatic receipt, two-line restock, legacy retry reconciliation, idempotence, and the no-refund path.

- [ ] **Step 7: Run broader refund tests**

Run: `php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Unit/Services/RefundLineCalculatorServiceTest.php`

Expected: all tests pass with no errors or warnings.

- [ ] **Step 8: Review the final diff**

Run `git diff --check`, then inspect only the three files listed above. Expected: no unrelated changes.

- [ ] **Step 9: Commit the implementation**

```powershell
git add app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php tests/Feature/Logistics/ReturnToShopTest.php
git commit -m "fix: auto-complete failed delivery returns"
```
