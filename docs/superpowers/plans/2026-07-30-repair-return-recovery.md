# Repair Return Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete failed repaired-item delivery recovery by holding the returned shoes at the shop, offering staff only re-delivery or shop pickup, charging a new customer-paid delivery fee before re-dispatch, and hiding return handoff before successful intake.

**Architecture:** Keep `repair_requests.status = ready_for_pickup` and derive the operational recovery state from immutable repair-return shipment history. Store the selected recovery/payment requirement in the existing `logistics_payment_reconciliation` JSON and use a distinct `redelivery` repair payment-session phase, avoiding a migration or duplicate shipment record. Reopen the existing cancelled `repair_return` shipment and append one new outbound leg only after the matching re-delivery payment settles.

**Tech Stack:** Laravel 12/PHP 8, Eloquent transactions, PHPUnit feature tests, React 18/TypeScript, Axios, Vitest/Testing Library, Tailwind CSS.

---

## File Structure

- `app/Services/Logistics/ShipmentLegService.php` — end rider custody and put a returned repair into shop-held recovery state.
- `app/Services/RepairDeliveryService.php` — derive recovery state, apply the two staff choices, track the recovery key, and reopen the existing shipment after payment.
- `app/Services/PaymentSettlementService.php` — calculate and settle a delivery-only `redelivery` payment phase.
- `app/Services/NotificationService.php` — send idempotent customer recovery notifications.
- `app/Http/Controllers/Api/RepairWorkflowController.php` — authorize staff recovery actions and expose return-handoff/recovery state.
- `app/Http/Controllers/Api/RepairRequestController.php` — expose customer recovery state and allow the existing payment endpoint to create a redelivery-only checkout.
- `routes/web.php` and `routes/shop-owner-api.php` — add the repairer and shop-owner recovery action routes.
- `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx` — repairer recovery card and actions.
- `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx` — matching shop-owner recovery card and actions.
- `resources/js/Pages/UserSide/Repairs/myRepairs.tsx` — customer waiting, re-delivery payment, and shop-pickup states.
- `tests/Feature/Repair/RepairReturnRecoveryTest.php` — end-to-end backend recovery behavior.
- Existing repair logistics/payment and frontend test files — regression coverage at the existing boundaries.

No migration or new global repair status is required.

### Task 1: Derive and enter the shop-held recovery state

**Files:**
- Create: `tests/Feature/Repair/RepairReturnRecoveryTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php:329-356`
- Modify: `app/Services/RepairDeliveryService.php:528-590`

- [ ] **Step 1: Write the failing return-receipt test**

Create a real repair fixture with:

- `source_type = repair_request`
- `purpose = repair_return`
- exhausted original outbound leg
- delivered `return_to_shop` leg
- rider-confirmed receive proof
- completed repair payment and locked return plan

Assert after `confirmReturnReceipt()`:

```php
$this->assertSame('ready_for_pickup', $repair->fresh()->status);
$this->assertNull($repair->fresh()->shipped_at);
$this->assertNull($repair->fresh()->return_logistics_locked_at);
$this->assertFalse((bool) $repair->fresh()->pickup_enabled);
$this->assertDatabaseCount('pos_refunds', 0);

$recovery = app(RepairDeliveryService::class)
    ->returnHandoff($repair->fresh(), true)['recovery'];

$this->assertSame('returned_to_shop_awaiting_arrangement', $recovery['code']);
$this->assertSame('awaiting_arrangement', $recovery['state']);
$this->assertSame("return-to-shop:{$return->id}", $recovery['key']);
```

Also replay `confirmReturnReceipt()` and assert the same recovery key and no duplicate financial mutation.

- [ ] **Step 2: Write the failing premature-handoff visibility test**

Create a cancelled repair with `intake_delivery_method = shop_pickup`, no `received_at`, and no approved intake proof. Assert:

```php
$handoff = app(RepairDeliveryService::class)->returnHandoff($repair, true);
$this->assertFalse($handoff['visible']);
$this->assertNull($handoff['recovery']);
```

Add a successful intake case and assert `visible === true`.

- [ ] **Step 3: Run the tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php
```

Expected: FAIL because `returnHandoff()` has no `visible`/`recovery` state and return receipt does not reset the repair to shop-held recovery.

- [ ] **Step 4: Implement the minimum recovery transition**

In `ShipmentLegService::confirmReturnReceipt()`, after the return proof is approved and only when the shipment is `repair_request / repair_return`, lock the source `RepairRequest` and update:

```php
[
    'status' => 'ready_for_pickup',
    'shipped_at' => null,
    'pickup_enabled' => false,
    'pickup_enabled_at' => null,
    'pickup_enabled_by' => null,
    'return_logistics_locked_at' => null,
    'return_address_confirmed_at' => null,
    'return_address_confirmed_version' => null,
]
```

Do not call any refund or delivery-compensation service.

In `RepairDeliveryService`, add one private shipment-history reader used by `returnHandoff()`:

```php
private function returnRecoveryState(RepairRequest $repair): ?array
```

It must return recovery only when the latest completed `return_to_shop` leg belongs to a returned original outbound leg and no newer non-cancelled outbound leg exists. Return:

```php
[
    'code' => 'returned_to_shop_awaiting_arrangement',
    'label' => 'Returned to shop—awaiting customer arrangement',
    'state' => $shopPickupSelected
        ? 'shop_pickup'
        : ($activeRequirement ? 'awaiting_payment' : 'awaiting_arrangement'),
    'key' => "return-to-shop:{$returnLeg->id}",
    'can_schedule_redelivery' => ! $shopPickupSelected && $activeRequirement === null,
    'can_set_shop_pickup' => $activeRequirement?->status !== 'paid',
]
```

Require the return-to-shop receive proof to be approved before emitting recovery. Stop emitting recovery after staff releases the shop-pickup handoff (`pickup_enabled = true`), after customer receipt (`status = picked_up`), or after a newer outbound leg begins. Add `visible` and `recovery` to `returnHandoff()`. Set `visible = false` only for cancelled-before-intake repairs: cancelled status, no `received_at`, and no approved repair-pickup handoff.

- [ ] **Step 5: Run the focused backend test and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add -- tests/Feature/Repair/RepairReturnRecoveryTest.php app/Services/Logistics/ShipmentLegService.php app/Services/RepairDeliveryService.php
git commit -m "fix: enter repair return recovery after shop receipt"
```

### Task 2: Add the two authorized staff recovery actions

**Files:**
- Modify: `tests/Feature/Repair/RepairReturnRecoveryTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Services/NotificationService.php:343-560`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `routes/web.php:1400-1430`
- Modify: `routes/shop-owner-api.php:240-265`

- [ ] **Step 1: Write failing staff-action tests**

Cover repairer and shop-owner authorization, cross-shop rejection, wrong-state rejection, and replay.
Add the return-receipt notification assertion here: one `awaiting_arrangement` customer notification for the recovery key, even after receipt replay.

For re-delivery:

```php
$response = $this->actingAs($repairer, 'user')
    ->postJson("/api/repairer/repairs/{$repair->id}/return-recovery", [
        'action' => 'schedule_redelivery',
    ])
    ->assertOk()
    ->assertJsonPath('recovery.state', 'awaiting_payment');

$this->assertDatabaseMissing('shipment_legs', [
    'shipment_id' => $shipment->id,
    'sequence' => 3,
]);
```

Replay the same request and assert one active requirement with the same recovery key.
Assert one `awaiting_payment` customer notification for the recovery key.

For shop pickup:

```php
$this->postJson(..., ['action' => 'shop_pickup'])->assertOk();
$repair->refresh();
$this->assertSame('walk_in', $repair->return_delivery_method);
$this->assertSame('0.00', number_format((float) $repair->return_delivery_fee, 2, '.', ''));
$this->assertNull($repair->return_logistics_locked_at);
```

Create an unpaid `redelivery` session and assert it becomes `invalidated`. Create a paid matching session and assert shop pickup returns 422.
Assert one `shop_pickup` customer notification for the recovery key.

- [ ] **Step 2: Run the action tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php --filter="recovery_action|shop_pickup|redelivery"
```

Expected: FAIL because the route and service action do not exist.

- [ ] **Step 3: Implement one shared service action**

Add:

```php
public function resolveReturnRecovery(
    RepairRequest $repair,
    string $action,
    string $actorType,
    int $actorId,
): array
```

Inside one database transaction:

- Lock the repair and its repair-return shipment.
- Recompute the recovery key; never trust a client key.
- Accept only `schedule_redelivery` or `shop_pickup`.
- For `schedule_redelivery`, append or reuse one JSON entry:

```php
[
    'type' => 'return_recovery',
    'phase' => 'return',
    'action' => 'collect_redelivery_fee',
    'status' => 'awaiting_payment',
    'recovery_key' => $recoveryKey,
    'created_at' => now()->toISOString(),
]
```

- Preserve prior reconciliation entries and keep the top-level reconciliation status `resolved`; this is not a refund/finance hold.
- Keep `status = ready_for_pickup`, set `return_delivery_method = shop_delivery`, clear the old plan lock and confirmation, and enable payment.
- For `shop_pickup`, invalidate pending matching `redelivery` sessions, set method `walk_in`, fee/quote to zero/null, clear address confirmation and locks, and mark the recovery entry `shop_pickup_selected`.
- Reject shop pickup if a matching paid redelivery session exists.

- [ ] **Step 4: Add controller routes and notifications**

Add:

```php
Route::post('{id}/return-recovery', [RepairWorkflowController::class, 'resolveReturnRecovery']);
Route::post('/{id}/return-recovery', [RepairWorkflowController::class, 'resolveReturnRecovery'])
    ->name('shop_owner.repairs.return-recovery');
```

The controller must enforce these checks explicitly:

- Shop-owner route: authenticated shop owner and `repair.shop_owner_id === shopOwner.id`.
- Repairer route: authenticated user, `repair.shop_owner_id === user.shop_owner_id`, `repair.assigned_repairer_id === user.id`, and either role `STAFF`/`REPAIRER` or permission `access-repair-job-orders`/`access-repairer-dashboard`.

Do not rely on assignment alone or on the route prefix as authorization.

Add one notification method that accepts the recovery state and uses a recovery-key-based `group_key`:

- `awaiting_arrangement`: item returned to shop.
- `awaiting_payment`: confirm address and pay a new delivery fee.
- `shop_pickup_selected`: item ready for shop pickup.

Notification failures are logged but do not roll back the custody/payment transaction.

Trigger notifications at all four state changes:

- After the return-receipt transaction commits: `awaiting_arrangement`.
- After staff selects re-delivery: `awaiting_payment`.
- After staff selects shop pickup: `shop_pickup`.
- After redelivery payment commits and the new shipment leg exists: `ready_for_dispatch`.

- [ ] **Step 5: Run focused tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add -- tests/Feature/Repair/RepairReturnRecoveryTest.php app/Services/Logistics/ShipmentLegService.php app/Services/RepairDeliveryService.php app/Services/NotificationService.php app/Http/Controllers/Api/RepairWorkflowController.php routes/web.php routes/shop-owner-api.php
git commit -m "feat: add repair return recovery choices"
```

### Task 3: Charge and settle a delivery-only redelivery payment

**Files:**
- Modify: `tests/Feature/Repair/RepairReturnRecoveryTest.php`
- Modify: `tests/Feature/Repair/RepairLogisticsPaymentTest.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/RepairRequestController.php`

- [ ] **Step 1: Write failing payment-breakdown and settlement tests**

For an active `collect_redelivery_fee` requirement, assert:

```php
$breakdown = app(PaymentSettlementService::class)
    ->repairPaymentBreakdown($repair->fresh());

$this->assertSame('redelivery', $breakdown['phase']);
$this->assertSame('redelivery', $breakdown['due_type']);
$this->assertSame(0.0, $breakdown['service_amount']);
$this->assertSame((float) $repair->return_delivery_fee, $breakdown['delivery_amount']);
```

Settle a matching session and assert:

- `payment_status` remains `completed`.
- `total_paid_amount` increases only by the new delivery fee.
- return plan lock is newer than return receipt.
- the same cancelled shipment becomes `requested`.
- exactly one new pending outbound leg is appended with the next sequence.
- previous cancelled and return-to-shop legs remain unchanged.
- the recovery requirement becomes `paid`.
- webhook/session replay appends no extra leg.
- one `ready_for_dispatch` customer notification is sent for the recovery key.

Add stale address version, stale recovery key, and prior normal `final` payment cases; each must reconcile/reject rather than dispatch.

Repeat the successful redelivery case with a warranty repair and assert the quoted replacement delivery fee is charged while service amount remains zero.

- [ ] **Step 2: Run payment tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php --filter="redelivery"
```

Expected: FAIL because completed repairs have no distinct redelivery payment phase.

- [ ] **Step 3: Add redelivery phase calculation**

In `PaymentSettlementService`:

- Detect the active `return_recovery / collect_redelivery_fee / awaiting_payment` entry.
- Make `resolveRepairPaymentPhase()` return `redelivery` before the normal initial/final rules.
- For `redelivery`, calculate `service_amount = 0`, `leg = return`, and use the current versioned shop-delivery quote.
- Apply the normal sponsored-warranty zero-charge rule only to initial/final warranty phases. A `redelivery` phase always keeps `service_amount = 0` but charges the quoted replacement delivery fee, including warranty jobs.
- Make `isRepairPaymentDueNow()` true and `isRepairSettled()` false only while that requirement awaits payment.
- Make `isRepairPaymentPhaseSettled('redelivery')` check a paid `redelivery` session with the same `quote->recovery_key`; do not treat old `final` sessions or `payment_status = completed` as settlement.

- [ ] **Step 4: Settle redelivery without reopening repair charges**

In `settleRepairPhasePaid()` add a redelivery branch that:

```php
$repair->update([
    'payment_status' => 'completed',
    'payment_status_derived' => 'completed',
    'total_paid_amount' => round((float) $repair->total_paid_amount + $deliveryAmount, 2),
    'return_logistics_locked_at' => now(),
]);
```

Then mark the matching recovery entry paid/resolved and call `tryCreateReturnShipment()`.

Update every phase-to-leg or phase-to-due-type branch used by session settlement/reconciliation so `redelivery` maps to the return leg but remains distinct from `final`. Store and compare `recovery_key` in the session quote. A stale paid webhook goes to existing reconciliation handling and does not create a shipment.

In `RepairDeliveryService::tryCreateReturnShipment()`, require the matching paid recovery entry before reopening a cancelled recovery shipment for both normal and sponsored-warranty repairs. Accept that paid entry as fresh authorization, reopen the same cancelled shipment as `requested`, and rely on the existing source-shipment logic to append the next pending leg. The existing sponsored-warranty bypass remains valid only for the first return shipment, not a recovery re-delivery.

After settlement commits and the new pending leg exists, send the `ready_for_dispatch` recovery notification with the same recovery key. Webhook replay must not duplicate it.

- [ ] **Step 5: Wire the existing customer checkout endpoint**

In `RepairRequestController`:

- Label the checkout as `re-delivery fee`.
- Create `RepairPaymentSession.phase = redelivery`.
- Add only the delivery-fee line item.
- Preserve `payment_status = completed` while the checkout is pending.
- Include `recovery_key` in `quote`.
- Allow payment creation despite the previously settled normal final phase.
- Invalidate only pending redelivery sessions for the same repair when a fresher plan/version is saved.

Do not add a second payment endpoint.

- [ ] **Step 6: Run payment tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php --filter="redelivery"
```

Expected: PASS.

- [ ] **Step 7: Run existing repair payment and return regressions**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairLogisticsReturnTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```powershell
git add -- tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php app/Services/PaymentSettlementService.php app/Services/RepairDeliveryService.php app/Http/Controllers/Api/RepairRequestController.php
git commit -m "feat: collect customer paid repair redelivery fee"
```

### Task 4: Expose recovery state and actions in both staff job-order pages

**Files:**
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php:290-415`
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx`

- [ ] **Step 1: Write failing repairer and shop-owner UI tests**

For a `return_handoff` payload with `visible = true` and recovery `awaiting_arrangement`, assert:

- Exact label **Returned to shop—awaiting customer arrangement**.
- Only **Schedule re-delivery** and **Set for shop pickup** are available in the return section.
- Normal `Confirm delivered handoff` is absent.
- Clicking each action posts to its role-specific endpoint and refreshes the server payload.

For `visible = false`, assert no Return handoff heading, action, tracking, or status is rendered.

- [ ] **Step 2: Run frontend tests and verify RED**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx"
```

Expected: FAIL because recovery types/actions and visibility are not rendered.

- [ ] **Step 3: Expose a consistent server payload**

Keep `return_handoff`, adding:

```ts
visible: boolean;
recovery?: {
  code: 'returned_to_shop_awaiting_arrangement';
  label: string;
  state: 'awaiting_arrangement' | 'awaiting_payment' | 'shop_pickup';
  can_schedule_redelivery: boolean;
  can_set_shop_pickup: boolean;
};
```

The three existing `RepairWorkflowController` transforms already call `returnHandoff()`; do not duplicate derivation in the controller.

- [ ] **Step 4: Implement the minimal role-specific UI**

In each job-order component:

- Render the return section only when `returnHandoff?.visible !== false`.
- If `returnHandoff.recovery` exists, replace the normal handoff body with one recovery card.
- For recovery state `shop_pickup`, render the existing walk-in release handoff instead of the two arrangement buttons.
- Use existing Axios, SweetAlert confirmation, loading/error feedback, and refresh functions.
- Post repairer actions to `/api/repairer/repairs/{id}/return-recovery`.
- Post shop-owner actions to `/api/shop-owner/repairs/{id}/return-recovery`.
- Disable both buttons while one request is pending.
- Keep the buttons at least 44px high and retain visible text labels.

Do not add a modal, third action, or new shared component.

- [ ] **Step 5: Run frontend tests and verify GREEN**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add -- app/Http/Controllers/Api/RepairWorkflowController.php resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx "resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx" "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx"
git commit -m "feat: add staff repair return recovery actions"
```

### Task 5: Show the customer recovery and payment flow

**Files:**
- Modify: `app/Http/Controllers/Api/RepairRequestController.php:960-1060`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx`

- [ ] **Step 1: Write failing customer payload/UI tests**

Add `return_recovery` and `redelivery_payment_due` to the customer listing assertion.

Frontend cases:

1. `awaiting_arrangement` — shows the returned-to-shop label and explanatory text, with no refund message, address editor, payment button, or rider tracking for a new leg.
2. `awaiting_payment` — shows the reopened `ReturnDeliveryPlanCard`, requires fresh address confirmation, and shows **Pay new delivery fee**.
3. `shop_pickup` — shows **Ready for pickup at shop**, shop details, and the existing receipt flow; no delivery payment.

After staff activates the walk-in release, `return_recovery` becomes null and the existing customer receipt-confirmation UI owns the remaining flow.

- [ ] **Step 2: Run customer tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php --filter="customer_payload"
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

Expected: FAIL because the payload/UI do not expose recovery.

- [ ] **Step 3: Add the customer payload**

In the existing customer repair list map add:

```php
'return_recovery' => app(RepairDeliveryService::class)->returnHandoff(
    $repair,
    $settlementService->isRepairSettled($repair),
)['recovery'],
'redelivery_payment_due' => $settlementService->isRepairPaymentDueNow($repair)
    && $settlementService->repairPaymentBreakdown($repair)['phase'] === 'redelivery',
```

Avoid calling `repairPaymentBreakdown()` when no payment is due.

- [ ] **Step 4: Add the customer recovery card**

Extend `RepairOrder` with the two new fields. Render a compact state card before normal return controls.

- Hide `ReturnDeliveryPlanCard` while `awaiting_arrangement`.
- Show it after staff selects re-delivery.
- Reuse `handlePayNow()` and change the label to **Pay new delivery fee** when `redelivery_payment_due`.
- Hide old tracking until a new outbound leg exists.
- For `walk_in`, show the existing shop information and receipt-confirmation controls.

Do not display refund language or add another payment handler.

- [ ] **Step 5: Run customer tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php --filter="customer_payload"
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add -- app/Http/Controllers/Api/RepairRequestController.php resources/js/Pages/UserSide/Repairs/myRepairs.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx tests/Feature/Repair/RepairReturnRecoveryTest.php
git commit -m "feat: show customer repair return recovery"
```

### Task 6: Full verification and public build

**Files:**
- Verify only; modify production files only if a failing test exposes a scoped regression.

- [ ] **Step 1: Run focused repair/logistics backend tests**

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairReturnHandoffTest.php tests/Feature/Repair/RepairLogisticsReturnTest.php tests/Feature/Repair/RepairLogisticsPaymentTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Logistics/ReturnToShopTest.php tests/Feature/Logistics/LogisticsApiTest.php
```

Expected: PASS with zero failures.

- [ ] **Step 2: Run focused frontend tests**

```powershell
pnpm exec vitest run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx" resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
```

Expected: PASS with zero failures.

- [ ] **Step 3: Run logistics and repair regression suites**

```powershell
php artisan test tests/Feature/Logistics tests/Feature/Repair
```

Expected: PASS, allowing only existing documented environment skips.

- [ ] **Step 4: Build production assets**

```powershell
pnpm build
```

Expected: Vite exits 0 and writes the fresh public build/manifest.

- [ ] **Step 5: Inspect the final diff**

```powershell
git diff --check
git status --short
git diff --stat HEAD~1
```

Expected: no whitespace errors; only planned source, test, spec/plan, and generated public-build changes.

- [ ] **Step 6: Commit the verified public build if generated files are tracked**

```powershell
git add -- public/build
git commit -m "build: refresh public assets for repair return recovery"
```

Skip this commit only if `public/build` is intentionally ignored.
