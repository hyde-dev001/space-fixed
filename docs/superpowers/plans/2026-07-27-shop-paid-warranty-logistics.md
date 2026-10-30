# Shop-Paid Warranty Logistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove all customer charges for shop-owned warranty pickup and return delivery while preserving quotes, coverage, and the existing Logistics shipment flow.

**Architecture:** Keep the accepted delivery fees and quotes on the warranty repair as shop-sponsored operational data. Mark the warranty repair payment gate completed and lock both delivery plans at claim approval, then protect the shared payment calculation so neither POS nor PayMongo can collect warranty charges. Reuse the existing warranty marker and customer UI helper; add no schema or workflow abstraction.

**Tech Stack:** Laravel/PHP, Eloquent, PHPUnit feature tests, React/TypeScript, Vitest/Testing Library, Vite.

---

### Task 1: Make Warranty Approval Sponsor Both Delivery Legs

**Files:**
- Modify: `tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php`
- Modify: `app/Services/RepairWarrantyService.php`
- Modify: `app/Services/RepairDeliveryService.php`

- [ ] **Step 1: Change the warranty approval regression test first**

Rename `test_approve_claim_copies_versioned_addresses_and_charges_shop_owned_delivery_fees` to describe shop-sponsored delivery. Keep the positive quote assertions, then require:

```php
$this->assertFalse((bool) $linked->payment_enabled);
$this->assertSame('completed', (string) $linked->payment_status);
$this->assertSame(0.0, (float) $linked->total_paid_amount);
$this->assertNotNull($linked->intake_logistics_locked_at);
$this->assertNotNull($linked->return_logistics_locked_at);
$this->assertSame(
    data_get($linked->return_address, 'version'),
    (string) $linked->return_address_confirmed_version,
);
```

After proving no shipment exists before acceptance, set the linked repair to `repairer_accepted`, call the existing readiness service twice, and require exactly one intake shipment:

```php
$linked->update(['status' => 'repairer_accepted']);
app(RepairDeliveryService::class)->tryCreateIntakeShipment($linked->fresh());
app(RepairDeliveryService::class)->tryCreateIntakeShipment($linked->fresh());

$this->assertSame(1, Shipment::query()
    ->where('source_type', 'repair_request')
    ->where('source_id', $linked->id)
    ->where('purpose', 'repair_pickup')
    ->count());
```

Then mark the same warranty repair ready for return, call the existing return readiness service twice, and require exactly one return shipment:

```php
$linked->update(['status' => 'ready_for_pickup']);
app(RepairDeliveryService::class)->tryCreateReturnShipment($linked->fresh());
app(RepairDeliveryService::class)->tryCreateReturnShipment($linked->fresh());

$this->assertSame(1, Shipment::query()
    ->where('source_type', 'repair_request')
    ->where('source_id', $linked->id)
    ->where('purpose', 'repair_return')
    ->count());
```

Add two focused cases using the same warranty fixture:

1. After approval, reduce coverage before accepted intake dispatch; `tryCreateIntakeShipment()` returns `null` and `logistics_payment_reconciliation` remains `null`.
2. Create an accepted warranty intake shipment, cancel the unstarted leg through `cancelPaidDeliveryLeg()`, and assert the shipment is cancelled while `logistics_payment_reconciliation` remains `null`.

These prove positive operational fee fields never create a customer refund or credit.

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php --filter=shop_sponsored
```

Expected: FAIL because approval currently enables payment, leaves both logistics locks null, and waits for the customer fee.

- [ ] **Step 3: Apply the minimal warranty creation change**

In `RepairWarrantyService::approveClaim`, preserve `$intakeFee`, `$returnFee`, and both quotes, but replace fee-driven payment state with:

```php
$logisticsLockedAt = now();
$paymentEnabled = false;
$paymentStatus = 'completed';
```

Persist:

```php
'payment_enabled' => false,
'payment_enabled_at' => null,
'payment_status' => 'completed',
'payment_status_derived' => 'completed',
'intake_logistics_locked_at' => $logisticsLockedAt,
'return_logistics_locked_at' => $logisticsLockedAt,
'return_address_confirmed_at' => $preferredReceive === 'shop_delivery' ? $logisticsLockedAt : null,
'return_address_confirmed_version' => $preferredReceive === 'shop_delivery'
    ? data_get($deliveryPlan, 'return.snapshot.version')
    : null,
```

The warranty claim already contains the customer's chosen return method and address, so approval is the confirmation event for that locked, shop-sponsored plan. Do not create a payment record or change normal paid repair creation.

- [ ] **Step 4: Guard the shared delivery compensation path**

At the start of `RepairDeliveryService::recordCompensation`, return `null` for `is_warranty_job = true` or `billing_mode = warranty_no_charge`. This single guard covers both dispatch-time coverage loss and staff cancellation without changing normal paid repair compensation.

- [ ] **Step 5: Run the focused warranty flow test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php
```

Expected: PASS, including coverage rejection and idempotent approval tests.

- [ ] **Step 6: Commit the warranty creation behavior**

```bash
git add app/Services/RepairWarrantyService.php app/Services/RepairDeliveryService.php tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php
git commit -m "fix: sponsor warranty repair logistics"
```

### Task 2: Block Warranty Charges at the Shared Payment Boundary

**Files:**
- Modify: `tests/Feature/Repair/RepairLogisticsPaymentTest.php`
- Modify: `app/Services/PaymentSettlementService.php`

- [ ] **Step 1: Replace the test that expects a warranty delivery charge**

Rename `test_zero_cost_warranty_still_charges_selected_shop_delivery_fee` to `test_warranty_delivery_fee_cannot_be_collected_from_customer`. Submit the existing positive delivery fee and require:

```php
$response->assertUnprocessable()
    ->assertJsonValidationErrors('payment_lines');

$this->assertDatabaseMissing('pos_transactions', [
    'module_type' => 'repair',
    'module_reference_id' => $repair->id,
]);
$this->assertSame(0.0, (float) $repair->fresh()->total_paid_amount);
```

- [ ] **Step 2: Run the payment regression and verify RED**

Run:

```bash
php artisan test tests/Feature/Repair/RepairLogisticsPaymentTest.php --filter=warranty_delivery_fee
```

Expected: FAIL because `repairPaymentBreakdown()` currently includes the stored intake fee.

- [ ] **Step 3: Zero warranty customer amounts in one shared calculation**

In `PaymentSettlementService::repairPaymentBreakdown`, identify sponsored warranty jobs once:

```php
$shopSponsoredWarranty = (bool) ($repair->is_warranty_job ?? false)
    || strtolower((string) ($repair->billing_mode ?? '')) === 'warranty_no_charge';
```

For those jobs only, return zero `service_total`, `service_amount`, `delivery_amount`, and `total_amount`. Continue calling `RepairDeliveryService::paymentDetails()` so ownership, address version, coverage, and quote validation remain unchanged.

- [ ] **Step 4: Run the complete repair payment test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/Repair/RepairLogisticsPaymentTest.php
```

Expected: PASS; normal deposits, balances, POS, PayMongo, and reconciliation remain unchanged.

- [ ] **Step 5: Commit the payment guard**

```bash
git add app/Services/PaymentSettlementService.php tests/Feature/Repair/RepairLogisticsPaymentTest.php
git commit -m "fix: prevent customer warranty delivery charges"
```

### Task 3: Make the Customer UI Explicit and Defensive

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`

- [ ] **Step 1: Add a failing customer UI regression**

Render a warranty repair with `payment_enabled: true` to simulate a stale or forged payload:

```tsx
mocks.repair = repair({
  status: "repairer_accepted",
  payment_status: "pending",
  payment_enabled: true,
  conversation_id: 15,
  is_warranty_job: true,
  billing_mode: "warranty_no_charge",
});
```

Require no payment action and the approved copy:

```tsx
expect(screen.queryByRole("button", { name: "PAY NOW" })).not.toBeInTheDocument();
expect(screen.getByText(
  "Warranty service and shop-owned shipping are covered by the shop.",
)).toBeInTheDocument();
```

- [ ] **Step 2: Run the UI test and verify RED**

Run:

```bash
npm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

Expected: FAIL because the current UI trusts `payment_enabled` and says only that rework has no additional charge.

- [ ] **Step 3: Reuse the existing warranty helper for the minimal UI guard**

In `myRepairs.tsx`:

- Add `!isWarrantyNoChargeOrder(order)` to the status-level `PAY NOW` condition.
- Add the same guard to each customer `PAY NOW` render condition.
- Replace the warranty summary copy with:

```tsx
Warranty service and shop-owned shipping are covered by the shop.
```

Do not add a new component or duplicate warranty detection.

- [ ] **Step 4: Run the focused UI test and production build**

Run:

```bash
npm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
npm run build
```

Expected: focused UI tests PASS and Vite build succeeds.

- [ ] **Step 5: Commit the UI guard**

```bash
git add resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx public/build
git commit -m "fix: show shop-covered warranty shipping"
```

### Task 4: Run the Regression Gate

**Files:**
- Verify only; no planned production edits.

- [ ] **Step 1: Run the focused warranty and payment suites**

```bash
php artisan test tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php
```

Expected: PASS.

- [ ] **Step 2: Run the complete Repair and Logistics suites**

```bash
php artisan test tests/Feature/Repair tests/Feature/Logistics
```

Expected: PASS with no failures.

- [ ] **Step 3: Re-run frontend verification**

```bash
npm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
npm run build
```

Expected: PASS and successful production build.

- [ ] **Step 4: Inspect final scope**

```bash
git status --short
git diff --check
git diff --stat origin/solespace-b...HEAD
```

Expected: only the approved spec, plan, backend fix, customer UI/test, and generated build changes; no deletions or unrelated files.
