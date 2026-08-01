# Warranty Logistics Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep warranty repair work free while charging each customer-requested shop-rider leg separately, and let the owning customer recover the same warranty job after exhausted pickup or return-delivery attempts.

**Architecture:** Extend the existing repair payment phases, shipment reopening rules, reconciliation JSON, and customer recovery UI. Record terminal pickup recovery inside the existing failed-attempt transaction; resolve it through `RepairDeliveryService`; settle it through `PaymentSettlementService`; and reuse the current return-recovery flow. Add no table, migration, duplicate claim, dispatcher recovery route, or parallel payment subsystem.

**Tech Stack:** Laravel 12/PHP 8.2, Eloquent transactions, PHPUnit feature tests, React 18/TypeScript, Vitest, Vite.

---

## Task 1: Make standard warranty shipping payable per leg

**Files:**
- Modify: `tests/Feature/Repair/RepairLogisticsPaymentTest.php`
- Modify: `tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `app/Services/RepairWarrantyService.php`

- [ ] **Step 1: Replace the obsolete sponsored-shipping assertions with failing payment tests**

In `RepairLogisticsPaymentTest.php`, replace the tests that expect warranty delivery to be zeroed. Cover both warranty markers and assert that the covered service stays zero while the selected leg remains payable:

```php
$breakdown = $service->repairPaymentBreakdown($repair, 'initial');

$this->assertSame(0.0, $breakdown['service_amount']);
$this->assertSame((float) $repair->intake_delivery_fee, $breakdown['delivery_amount']);
$this->assertSame((float) $repair->intake_delivery_fee, $breakdown['amount']);
```

Add a separate final-phase assertion showing that a previously paid intake fee does not reduce or prepay `return_delivery_fee`.

Also cover the complete gate for each standard leg:

- before initial pickup payment, there is no intake lock, shipment leg, or dispatcher-assignable work;
- successful initial payment locks only the accepted intake snapshot and creates exactly one intake leg;
- that payment leaves the return phase unpaid for the full accepted return fee;
- before final return payment, there is no return lock or outbound leg;
- successful final payment locks only the accepted return snapshot and creates exactly one outbound leg;
- replaying either settlement creates no duplicate leg.

- [ ] **Step 2: Add failing warranty-approval dispatch-lock tests**

In `RepairWarrantyClaimFlowTest.php`, revise `test_approve_claim_preserves_shop_owned_quotes_as_shop_sponsored_delivery()` into explicit per-leg behavior:

```php
$this->assertSame('pending', $warrantyRepair->payment_status);
$this->assertTrue((bool) $warrantyRepair->payment_enabled);
$this->assertNull($warrantyRepair->intake_logistics_locked_at);
$this->assertNull($warrantyRepair->return_logistics_locked_at);
$this->assertNull($warrantyRepair->intakeShipment);
```

Also retain a zero-checkout test for walk-in/customer-arranged intake and shop pickup/customer-arranged return.

- [ ] **Step 3: Run the focused tests and confirm RED**

Run:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php
```

Expected: failures show warranty delivery is currently forced to zero and warranty approval pre-locks logistics.

- [ ] **Step 4: Stop zeroing valid warranty delivery amounts**

In `PaymentSettlementService::repairPaymentBreakdown()`, keep the existing warranty service override but remove the warranty override for the selected shop-owned delivery amount:

```php
if ($this->isWarrantyNoCharge($repair)) {
    $serviceAmount = 0.0;
}
```

Leave the existing `initial`, `final`, and `redelivery` phase calculation intact. Do not add a warranty-only calculator.

- [ ] **Step 5: Initialize warranty approval for the selected intake method**

In `RepairWarrantyService`, keep service/add-on/VAT and `final_total` at zero, but:

- for `shop_pickup`, leave payment pending/enabled and both logistics locks unset;
- for walk-in or customer-arranged intake, settle the zero initial phase without external checkout and write only the state needed to continue;
- never pre-lock a future shop return-delivery leg at approval.

Use the existing method names and state constants already used by normal repair payment; do not create a second warranty payment state machine.

- [ ] **Step 6: Preserve the existing per-phase settlement gates**

Use the normal settlement path to write only the lock belonging to the phase being paid, then call the existing shipment creation method for that direction. Initial settlement must not write `return_logistics_locked_at`; final settlement must not alter the paid intake snapshot. Confirm both shipment creators still reject an absent phase lock/payment.

- [ ] **Step 7: Run focused tests and confirm GREEN**

Run the command from Step 3.

Expected: all tests pass; warnings already present in the baseline may remain, but there are no failures.

- [ ] **Step 8: Commit the standard warranty payment change**

```powershell
git add -- app/Services/PaymentSettlementService.php app/Services/RepairWarrantyService.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php
git commit -m "fix: charge warranty logistics per leg"
```

## Task 2: Record terminal warranty pickup recovery atomically

**Files:**
- Create: `tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Services/RepairDeliveryService.php`

- [ ] **Step 1: Add failing terminal-pickup recovery tests**

Create a focused feature test using the existing warranty/shipment factories and helpers. On the last failed pickup attempt, assert:

```php
$repair->refresh();
$entry = collect(data_get($repair->logistics_payment_reconciliation, 'entries', []))
    ->firstWhere('type', 'pickup_recovery');

$this->assertSame('cancelled', $repair->status);
$this->assertSame('awaiting_arrangement', $entry['status']);
$this->assertSame($shipment->id, $entry['shipment_id']);
$this->assertDatabaseMissing('refund_requests', ['repair_request_id' => $repair->id]);
```

Extend the logistics regression to assert the failed leg, attempt evidence, and cancelled shipment remain unchanged.

- [ ] **Step 2: Run the focused tests and confirm RED**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php tests/Feature/Logistics/LogisticsApiTest.php --filter=warranty
```

Expected: the recovery entry assertion fails because terminal warranty pickup currently only cancels.

- [ ] **Step 3: Add the recovery marker in the existing failed-attempt transaction**

In `ShipmentLegService::recordFailedAttempt()`, after terminal warranty pickup is identified and before the transaction commits, call one small `RepairDeliveryService` method that upserts a `pickup_recovery` entry into the existing reconciliation JSON.

The entry needs only the fields used to enforce recovery and idempotency:

```php
[
    'type' => 'pickup_recovery',
    'status' => 'awaiting_arrangement',
    'shipment_id' => $shipment->id,
    'failed_leg_id' => $leg->id,
    'created_at' => now()->toISOString(),
]
```

Match warranty eligibility exactly: `is_warranty_job` or `billing_mode === 'warranty_no_charge'`. Reuse the existing reconciliation getter/setter conventions and preserve every unrelated entry.

- [ ] **Step 4: Make marker creation idempotent**

In `RepairDeliveryService`, upsert by recovery type plus failed leg/shipment. A replay must return the existing entry without appending a duplicate or changing the recorded failed attempt.

- [ ] **Step 5: Run the focused tests and confirm GREEN**

Run the command from Step 2.

- [ ] **Step 6: Commit the atomic recovery marker**

```powershell
git add -- app/Services/Logistics/ShipmentLegService.php app/Services/RepairDeliveryService.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php
git commit -m "fix: record warranty pickup recovery"
```

## Task 3: Let only the customer choose a pickup recovery plan

**Files:**
- Modify: `tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`
- Modify: `routes/web.php`
- Modify: `app/Services/NotificationService.php` only if the current generic repair notification cannot express the existing state

- [ ] **Step 1: Add failing endpoint, ownership, and validation tests**

Add tests for:

- owning customer can choose `shop_pickup`, `walk_in`, or `customer_delivery`;
- another customer receives 404;
- an authenticated staff user has no matching customer route/gets 404;
- shop pickup requires owned saved address, future date, time window, and live coverage quote;
- customer delivery requires its owned address snapshot but no coverage or shop schedule;
- walk-in requires neither;
- duplicate submission returns the same recovery state;
- an identical payable-plan replay in `awaiting_payment` returns the same plan/session state with no new notification;
- an identical free-plan replay after resolution returns the resolved state with no mutation;
- a different unpaid plan is allowed only before payment and invalidates its previous pending session;
- after resolution/payment, a different plan receives 409;
- a paid recovery cannot be replaced by a free plan;
- no dispatcher recovery endpoint exists.

Use a single request shape:

```php
[
    'method' => 'shop_pickup',
    'address_id' => $address->id,
    'delivery_date' => now()->addDay()->toDateString(),
    'delivery_window' => 'morning',
]
```

- [ ] **Step 2: Run the new test class and confirm RED**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php
```

Expected: route not found or recovery resolution assertions fail.

- [ ] **Step 3: Add one customer-only route and thin controller action**

Under the existing `/api/customer/repairs/{id}` group in `routes/web.php`, add:

```php
Route::post('{id}/pickup-recovery', [RepairRequestController::class, 'resolvePickupRecovery']);
```

The controller must load through the existing customer-owned repair query so cross-customer access stays 404, validate the small method-specific request, and delegate to `RepairDeliveryService`. Do not add staff or dispatcher routes.

- [ ] **Step 4: Resolve free recovery methods with existing replan fields**

In `RepairDeliveryService`:

- verify the repair is a warranty job with active `pickup_recovery` in `awaiting_arrangement`;
- for `walk_in`, clear both the intake and pickup address snapshots plus intake quote/fee/schedule, restore `repairer_accepted`, and write a replan lock newer than shipment cancellation;
- for `customer_delivery`, save the owned address snapshot, clear shop fee/quote/schedule, restore `repairer_accepted`, and write the newer lock;
- create no shop shipment leg for either method;
- preserve the same repair, claim, shipment, old legs, and attempts.

Update the existing recovery entry rather than appending a second entry.

Normalize the selected method, address/version, date, window, and accepted quote into a recovery plan key. Replay behavior is explicit:

- if the key matches, return the current `awaiting_payment`, resolved-free, or paid state without another write or notification;
- if the key differs and the current state is unpaid `awaiting_arrangement`/`awaiting_payment`, invalidate the old pending session and replace the plan;
- if the key differs after free resolution or payment, return 409.

- [ ] **Step 5: Stage payable shop pickup without dispatching it**

For `shop_pickup`, reuse existing coverage/quote and address snapshot helpers, save the accepted fee and quote version, clear the intake logistics lock, and set recovery to `awaiting_payment`. Leave the repair `cancelled` and do not create or assign a leg.

If an unpaid plan is changed, invalidate its pending payment/session data using the current payment retry rules. Treat any fee change as a new key, not only an address/quote-version change. Once paid, reject changes with 409.

- [ ] **Step 6: Reuse existing notification behavior**

Send at most one customer notification for each state transition. Extend `NotificationService.php` only if no existing generic repair/logistics notification accepts these labels; otherwise call the existing method.

- [ ] **Step 7: Run the endpoint tests and confirm GREEN**

Run the command from Step 2.

- [ ] **Step 8: Commit customer-owned pickup planning**

```powershell
git add -- app/Services/RepairDeliveryService.php app/Http/Controllers/Api/RepairRequestController.php routes/web.php tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php
git add -- app/Services/NotificationService.php
git commit -m "feat: let customers replan warranty pickup"
```

If `NotificationService.php` is unchanged, omit its `git add` line.

## Task 4: Charge and settle the pickup retry exactly once

**Files:**
- Modify: `tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php`
- Modify: `tests/Feature/Repair/RepairLogisticsPaymentTest.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`

- [ ] **Step 1: Add failing pickup-retry payment tests**

Cover this sequence:

1. choose shop pickup after terminal failure;
2. retry payment session resolves `pickup_retry` and charges only the accepted new shipping quote;
3. successful settlement restores `repairer_accepted`, writes a newer intake lock, marks recovery paid, and appends exactly one pickup leg to the existing shipment;
4. repeated settlement returns success without another charge, leg, or notification;
5. old attempts remain on the old leg; the new leg starts with zero attempts;
6. stale quote/address/version is rejected before settlement;
7. every other cancelled repair still receives the existing conflict response from retry-payment-session.

Core assertions:

```php
$this->assertSame(0.0, $breakdown['service_amount']);
$this->assertSame($acceptedFee, $breakdown['delivery_amount']);
$this->assertSame($shipmentId, $repair->intakeShipment->id);
$this->assertCount($oldLegCount + 1, $repair->intakeShipment->legs);
```

- [ ] **Step 2: Run the payment tests and confirm RED**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php --filter=pickup
```

Expected: `pickup_retry` is unknown or cancelled repairs are rejected before valid recovery is considered.

- [ ] **Step 3: Add `pickup_retry` to the existing repair phase resolver**

In `PaymentSettlementService`, detect an active customer-owned pickup recovery in `awaiting_payment` before normal initial/final selection. For this phase:

- service amount is zero;
- delivery amount comes from the accepted recovery quote;
- session metadata includes repair ID, recovery key, address/quote version, and phase;
- settled status comes from that recovery entry.

Do not create another checkout service or payment model.

- [ ] **Step 4: Narrowly allow retry checkout for cancelled recovery jobs**

In `RepairRequestController::retryPaymentSession()`, preserve the existing cancelled-order rejection except when `RepairDeliveryService` confirms an active `pickup_retry` recovery owned by the customer. Recompute the current quote/address version server-side before creating or returning a session.

- [ ] **Step 5: Settle and reopen through existing shipment creation**

Inside the existing payment-settlement database transaction, on successful `pickup_retry` settlement:

- lock the repair row;
- return early if the recovery is already paid;
- compare stored recovery/session versions to the current server state;
- mark the entry paid;
- restore `repairer_accepted`;
- write an intake lock newer than cancellation;
- call the existing `tryCreateIntakeShipment()` so its same-shipment reopen path appends one new leg.

The paid marker, repair state, logistics lock, and leg append must commit atomically. Emit notification only after that committed transition and key/dedupe it to the recovery entry so concurrent or replayed callbacks cannot send another one.

Do not reset or delete prior attempts.

- [ ] **Step 6: Run payment tests and confirm GREEN**

Run the command from Step 2.

- [ ] **Step 7: Commit pickup retry payment**

```powershell
git add -- app/Services/PaymentSettlementService.php app/Services/RepairDeliveryService.php app/Http/Controllers/Api/RepairRequestController.php tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php
git commit -m "fix: settle warranty pickup retry"
```

## Task 5: Preserve custody-safe warranty return recovery

**Files:**
- Modify: `tests/Feature/Repair/RepairReturnRecoveryTest.php`
- Modify: `tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php`
- Modify: `app/Services/RepairDeliveryService.php`

- [ ] **Step 1: Add failing warranty return-state regressions**

Add tests asserting:

- terminal outbound failure creates the return-to-shop leg without payment due;
- before that leg is delivered and receive proof approved, `returnRecoveryState()` returns `returning_to_shop` and `actions_available=false`;
- after approved shop receipt, it returns `awaiting_arrangement`;
- warranty redelivery charges only the new shipping fee;
- shop pickup remains free and the recovery payload includes the shop name/address used by the UI;
- no refund is created and warranty service stays zero.

- [ ] **Step 2: Run return recovery tests and confirm RED only where presentation is missing**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php --filter=return
```

- [ ] **Step 3: Add only the missing waiting presentation**

In `RepairDeliveryService::returnRecoveryState()`, reuse the existing return-to-shop leg and receive-proof checks. Return a non-actionable state while custody is unresolved:

```php
[
    'state' => 'returning_to_shop',
    'actions_available' => false,
    'message' => 'Returning to shop—rescheduling unlocks after shop receipt.',
]
```

Do not alter the existing `awaiting_arrangement`, `awaiting_payment`, paid redelivery, or free shop-pickup transitions unless a regression test proves a gap.

- [ ] **Step 4: Run return tests and confirm GREEN**

Run the command from Step 2.

- [ ] **Step 5: Commit return-state hardening**

```powershell
git add -- app/Services/RepairDeliveryService.php tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php
git commit -m "fix: gate warranty return recovery by custody"
```

## Task 6: Update My Repairs labels and recovery actions

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`

- [ ] **Step 1: Add failing frontend tests for the approved customer experience**

Cover:

- `Warranty repair: Free` remains visible;
- first pickup and return shipping buttons remain visible when their phase is payable;
- shipping labels are `Pickup shipping fee`, `Return shipping fee`, or `New shipping fee` and never claim all shipping is shop-covered;
- cancelled warranty pickup shows `Pickup failed—action required` and the three customer methods;
- shop pickup displays date/window/address and payment action; free options do not;
- successful plan/payment refreshes the repair list;
- return in transit shows the explanatory state and no buttons;
- approved shop receipt shows only `Schedule re-delivery` and `Set for shop pickup`;
- the free shop-pickup choice displays the shop name and address before confirmation.

- [ ] **Step 2: Run the component test and confirm RED**

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

- [ ] **Step 3: Reuse the existing cards and payment controls**

In `myRepairs.tsx`:

- rename/extend `SponsoredIntakeReplanCard` for pickup recovery rather than creating a new page;
- submit the recovery choice to `/api/customer/repairs/{id}/pickup-recovery`;
- use existing saved-address, native date, and delivery-window controls;
- remove warranty guards that hide valid initial/final shipping payment actions;
- retain the service-free badge independently of shipping amounts;
- render `returning_to_shop` as explanatory text with no action buttons;
- keep the existing return recovery action component after custody is confirmed and show the server-provided shop name/address for `Set for shop pickup`;
- invalidate/refetch the current repairs query after every successful mutation.

Status must be communicated with text/icons as well as color, and existing touch-target styles must remain at least 44px.

- [ ] **Step 4: Run frontend tests and confirm GREEN**

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

- [ ] **Step 5: Commit customer UI changes**

```powershell
git add -- resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
git commit -m "fix: expose warranty logistics recovery"
```

## Task 7: Verify the full flow and publish a fresh build

**Files:**
- Verify: all files above
- Update: `public/build/**` through the existing Vite build only

- [ ] **Step 1: Run the complete focused backend regression set**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; php artisan test tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Logistics/LogisticsApiTest.php
```

Expected: zero failures and zero errors. Existing baseline warnings may remain only if they are unchanged.

- [ ] **Step 2: Run the focused frontend regression**

```powershell
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

Expected: all tests pass.

- [ ] **Step 3: Run format checks only on changed PHP files**

```powershell
vendor/bin/pint --test app/Services/PaymentSettlementService.php app/Services/RepairWarrantyService.php app/Services/RepairDeliveryService.php app/Services/Logistics/ShipmentLegService.php app/Http/Controllers/Api/RepairRequestController.php tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php
```

Expected: PASS. If it fails, run the same command without `--test`, inspect the diff, then rerun `--test`.

- [ ] **Step 4: Build fresh public assets**

```powershell
pnpm run build
```

Expected: Vite exits 0 and writes a fresh manifest and hashed files under `public/build`.

- [ ] **Step 5: Inspect the final diff for scope and generated assets**

```powershell
git status --short
git diff --stat origin/solespace-b...HEAD
git diff --check
```

Expected: only warranty logistics/payment/recovery files, tests, this plan/spec, and fresh `public/build` assets are present; `git diff --check` prints nothing.

- [ ] **Step 6: Commit the verified public build**

```powershell
git add -- public/build
git commit -m "build: refresh warranty logistics assets"
```

- [ ] **Step 7: Run the focused backend and frontend commands once more after the build commit**

Repeat Steps 1 and 2. Do not claim completion unless both exit 0.

## Manual end-to-end acceptance checklist

- [ ] Approve a warranty claim with shop pickup: service is free, pickup fee is payable, dispatcher cannot assign before payment.
- [ ] Pay pickup fee: exactly one intake leg becomes assignable; return fee remains unpaid and unchanged.
- [ ] Complete intake and repair; choose shop return delivery: only return fee is payable before dispatch.
- [ ] Exhaust warranty pickup attempts: no refund is created; the same repair shows customer recovery; old evidence remains.
- [ ] Choose another shop pickup and pay: the same shipment gains exactly one leg and starts a fresh leg attempt count.
- [ ] Repeat the payment callback: no second charge, leg, or notification appears.
- [ ] Exhaust pickup again, then choose walk-in/customer courier: no shipping charge or shop rider leg is created.
- [ ] Exhaust warranty return delivery: item returns to shop without a new charge; customer has no action before approved receipt.
- [ ] Approve shop receipt: customer sees only paid re-delivery or free shop pickup.
- [ ] Sign in as another customer, repair staff, and dispatcher: none can choose the customer's recovery plan.
