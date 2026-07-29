# Repair Failed Pickup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let riders record a failed repair pickup with required photo evidence, send the stop to dispatcher resolution, and show a sanitized record to the customer.

**Architecture:** Extend the existing multipart failed-attempt endpoint and `DeliveryAttempt` model with a pickup-specific branch. Persist `attempt_type = pickup`, keep delivery-attempt counters and refund/return behavior unchanged, and use `resolution_type = pickup_failed` until the dispatcher reschedules or cancels. Reuse the existing repair cancellation/compensation service and customer proof authorization.

**Tech Stack:** Laravel 12, Eloquent transactions and validation, Inertia, React 18, TypeScript, Axios, SweetAlert2, Vitest, PHPUnit.

---

## Scope and File Map

No database migration or new model is needed.

### Backend workflow

- Modify `app/Services/Logistics/ShipmentLegService.php`
  - validate pickup-specific reasons;
  - record the failed pickup transaction;
  - detach only the failed stop;
  - reschedule to the next operating day;
  - keep delivery attempts, refunds, and return legs separate.
- Modify `app/Http/Controllers/Api/Logistics/ShipmentController.php`
  - accept multipart `attempt_type = pickup`;
  - require a stable idempotency key;
  - route dispatcher cancellation through repair compensation.
- Modify `app/Services/RepairDeliveryService.php`
  - allow cancellation of a pre-custody `pickup_failed` leg;
  - preserve every post-custody guard;
  - record the customer-safe cancellation event.

### Rider UI

- Modify `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
  - show Failed pickup after repair-pickup arrival;
  - collect the approved reason, required photo, and conditional notes;
  - preserve the form while offline and block duplicate taps.
- Modify `resources/js/services/logisticsApi.ts`
  - add the existing multipart report-issue request as a typed API helper.

### Dispatcher UI

- Modify `app/Http/Controllers/Logistics/ErpLogisticsController.php`
  - serialize the latest failed pickup separately from delivery counts;
  - support the `failed_pickups` filter.
- Modify `resources/js/Pages/ERP/Logistics/Shipments.tsx`
  - add the Failed pickups filter, badge, evidence, and resolution actions.
- Modify `resources/js/types/logistics.ts`
  - expose safe attempt type and pickup count fields.

### Customer tracking

- Modify `app/Services/Logistics/CustomerTrackingService.php`
  - serialize the latest pickup or delivery failure with a safe label.
- Modify `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`
  - distinguish Pickup attempt unsuccessful from a failed delivery.
- Keep `app/Http/Controllers/Logistics/CustomerTrackingController.php`
  unchanged; its existing ownership and file checks already protect the photo.

### Tests

- Modify `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Modify `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify `tests/Feature/Repair/RepairDeliveryReconciliationTest.php`
- Modify `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Modify `tests/Feature/Logistics/CustomerTrackingTest.php`
- Modify `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Modify `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Modify `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

---

### Task 1: Record failed repair pickups safely

**Files:**

- Modify: `app/Services/Logistics/ShipmentLegService.php:19-31,335-474`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php:282-334`
- Test: `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`

- [ ] **Step 1: Write the failing service tests**

Add focused tests that create a `repair_request` shipment with purpose
`repair_pickup`, an `assigned` inbound leg, an active rider assignment, and a
`pickup_arrived` event.

Cover a standalone leg and a leg in an in-progress batch:

```php
public function test_failed_repair_pickup_waits_for_dispatcher_without_return_or_refund(): void
{
    [$leg, $assignment] = $this->assignedRepairPickup();
    $leg->events()->create([
        'shipment_id' => $leg->shipment_id,
        'event_type' => 'pickup_arrived',
        'visibility' => 'internal',
        'message' => 'Rider arrived for pickup.',
    ]);

    $attempt = app(ShipmentLegService::class)->recordFailedAttempt($leg, [
        'attempt_type' => 'pickup',
        'delivery_assignment_id' => $assignment->id,
        'idempotency_key' => '3aa7c6c2-0459-48be-ab0a-32090fe414cd',
        'reason_code' => 'customer_unavailable',
        'file_path' => "logistics-attempt/{$leg->id}/door.jpg",
    ], true);

    $this->assertSame('pickup', $attempt->attempt_type);
    $this->assertSame(1, $attempt->attempt_number);
    $this->assertSame('needs_resolution', $leg->fresh()->status->value);
    $this->assertSame('pickup_failed', $leg->fresh()->resolution_type);
    $this->assertNull($leg->fresh()->delivery_batch_id);
    $this->assertSame('cancelled', $assignment->fresh()->status);
    $this->assertFalse($leg->returnLeg()->exists());
    $this->assertDatabaseMissing('delivery_events', [
        'shipment_leg_id' => $leg->id,
        'event_type' => 'delivery_attempt_failed',
    ]);
    $this->assertDatabaseHas('delivery_events', [
        'shipment_leg_id' => $leg->id,
        'event_type' => 'pickup_attempt_failed',
        'visibility' => 'customer',
    ]);
}
```

For the batch case, assert that the failed leg is detached, the other stop
remains attached, `assigned_stop_count` is updated, and the batch remains
`in_progress`.

- [ ] **Step 2: Write failing API validation and replay tests**

Extend `LogisticsApiTest` using its existing `assignedRiderLeg()` and
`fakeAttemptPhoto()` patterns. Add a repair-specific fixture and test:

```php
$payload = [
    'attempt_type' => 'pickup',
    'delivery_assignment_id' => $assignment->id,
    'idempotency_key' => '66270d9f-a25b-4130-8494-9e757d92c798',
    'reason_code' => 'customer_unavailable',
    'proof_file' => $this->fakeAttemptPhoto(),
];

$first = $this->actingAs($rider, 'user')
    ->post("/api/logistics/legs/{$leg->id}/report-issue", $payload, [
        'Accept' => 'application/json',
    ])
    ->assertCreated();

$second = $this->actingAs($rider, 'user')
    ->post("/api/logistics/legs/{$leg->id}/report-issue", [
        ...$payload,
        'proof_file' => $this->fakeAttemptPhoto('duplicate.png'),
    ], ['Accept' => 'application/json'])
    ->assertCreated();

$this->assertSame($first->json('attempt.id'), $second->json('attempt.id'));
$this->assertSame(1, $leg->attempts()->where('attempt_type', 'pickup')->count());
```

Add separate assertions for:

- no recorded arrival;
- non-`repair_pickup` purpose;
- missing photo;
- unknown reason;
- `other` without notes;
- another rider's assignment;
- already `picked_up`;
- all eight approved reason codes;
- duplicate upload cleanup.

- [ ] **Step 3: Run the focused tests to verify failure**

Run:

```powershell
php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/LogisticsApiTest.php
```

Expected: FAIL because pickup reasons and pickup state handling do not exist and
`reportIssue` still hardcodes `attempt_type = delivery`.

- [ ] **Step 4: Add pickup-specific constants and service validation**

In `ShipmentLegService`, add:

```php
public const PICKUP_REASONS = [
    'customer_unavailable',
    'customer_requested_reschedule',
    'customer_refused_pickup',
    'item_not_ready',
    'wrong_address_or_pin',
    'unsafe_or_inaccessible_location',
    'vehicle_or_rider_problem',
    'other',
];
```

At the start of `recordFailedAttempt`, derive the type once:

```php
$attemptType = (string) ($payload['attempt_type'] ?? 'delivery');
$isPickup = $attemptType === 'pickup';
```

For pickup attempts, require:

```php
if (! in_array($payload['reason_code'] ?? null, self::PICKUP_REASONS, true)) {
    throw ValidationException::withMessages([
        'reason_code' => ['Choose a valid failed pickup reason.'],
    ]);
}
if (empty($payload['file_path'])) {
    throw ValidationException::withMessages([
        'proof_file' => ['A failed pickup photo is required.'],
    ]);
}
if (($payload['reason_code'] ?? null) === 'other' && blank($payload['notes'] ?? null)) {
    throw ValidationException::withMessages([
        'notes' => ['Add a short note for Other.'],
    ]);
}
```

Inside the locked transaction, require:

```php
if ($isPickup) {
    if ($leg->shipment->source_type !== 'repair_request'
        || $leg->shipment->purpose !== 'repair_pickup') {
        throw ValidationException::withMessages([
            'attempt_type' => ['Failed pickup is available only for repair pickups.'],
        ]);
    }
    $this->assertTransitionAllowed(
        $leg,
        ['assigned', 'pickup_scheduled'],
        'reported as a failed pickup',
    );
    if (! $leg->events()->where('event_type', 'pickup_arrived')->exists()) {
        throw ValidationException::withMessages([
            'arrival' => ['Record your pickup arrival before reporting a failed pickup.'],
        ]);
    }
} else {
    $this->assertTransitionAllowed(
        $leg,
        $allowAssigned
            ? ['assigned', 'picked_up', 'in_transit', 'delivery_attempted']
            : ['in_transit', 'delivery_attempted'],
        'delivery attempted',
    );
}
```

Define pickup attempt numbering as:

```php
$attemptNumber = $leg->attempts()
    ->where('attempt_type', $attemptType)
    ->count() + 1;
```

After creating the attempt, branch before existing delivery retry/refund logic:

```php
if ($isPickup) {
    $leg->update([
        'status' => 'needs_resolution',
        'failed_at' => now(),
        'delivery_batch_id' => null,
        'stop_sequence' => null,
        'resolution_type' => 'pickup_failed',
        'resolution_reason' => $payload['reason_code'],
    ]);
} else {
    // Keep the existing delivery-attempt, max-attempt, return, and refund logic.
}
```

Keep assignment cancellation and batch count updates common to both branches.
Use `pickup_attempt_failed` and **Pickup attempt unsuccessful.** for pickup;
leave the delivery event unchanged.

- [ ] **Step 5: Extend the multipart controller without a new route**

In `ShipmentController::reportIssue`, validate `attempt_type` first and make
the rules conditional:

```php
$attemptType = $request->validate([
    'attempt_type' => ['required', 'in:pickup,delivery'],
])['attempt_type'];
$isPickup = $attemptType === 'pickup';

$payload = $request->validate([
    'attempt_type' => ['required', 'in:pickup,delivery'],
    'delivery_assignment_id' => ['required', 'integer'],
    'idempotency_key' => ['required', 'uuid'],
    'reason_code' => [
        'required',
        Rule::in($isPickup
            ? ShipmentLegService::PICKUP_REASONS
            : [
                ...ShipmentLegService::PHOTO_REQUIRED_REASONS,
                ...ShipmentLegService::NOTES_REQUIRED_REASONS,
            ]),
    ],
    'notes' => [
        Rule::requiredIf(
            $isPickup
                ? $request->input('reason_code') === 'other'
                : in_array(
                    $request->input('reason_code'),
                    ShipmentLegService::NOTES_REQUIRED_REASONS,
                    true,
                ),
        ),
        'nullable',
        'string',
        'max:1000',
    ],
    'proof_file' => [
        Rule::requiredIf(
            $isPickup
                || in_array(
                    $request->input('reason_code'),
                    ShipmentLegService::PHOTO_REQUIRED_REASONS,
                    true,
                ),
        ),
        'nullable',
        'image',
        'max:10240',
    ],
]);
```

Pass `$isPickup` as the existing `$allowAssigned` argument. Preserve the
current stored-file cleanup on exceptions and idempotent replay.

- [ ] **Step 6: Run the focused backend tests**

Run the command from Step 3.

Expected: PASS.

- [ ] **Step 7: Commit the backend recording slice**

```powershell
git add -- app/Services/Logistics/ShipmentLegService.php app/Http/Controllers/Api/Logistics/ShipmentController.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/LogisticsApiTest.php
git diff --cached
git commit -m "feat: record repair failed pickup attempts"
```

---

### Task 2: Add the rider Failed Pickup action

**Files:**

- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx:41-52,212-600`
- Modify: `resources/js/services/logisticsApi.ts:1-49`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

- [ ] **Step 1: Add failing rider component tests**

Extend the `logisticsApi` mock with `reportIssue`. Render an assigned
`repair_pickup` leg with a recorded pickup arrival and assert:

```tsx
expect(screen.getByRole('button', { name: 'Confirm pickup' })).toBeEnabled();
expect(screen.getByRole('button', { name: 'Failed pickup' })).toBeEnabled();
```

Add tests proving:

- the action is hidden before arrival;
- the action is hidden for `retail_delivery`;
- all approved reasons render;
- a photo is required for every pickup reason;
- notes are required only for Other;
- the file input has `accept="image/*"` and `capture="environment"`;
- offline mode disables submission and leaves selected fields rendered;
- two taps while pending call the API once;
- successful submission reloads and exposes the next batch stop.

For submission, inspect the `FormData`:

```tsx
expect(mocks.reportIssue).toHaveBeenCalledOnce();
const [, form] = mocks.reportIssue.mock.calls[0];
expect(form.get('attempt_type')).toBe('pickup');
expect(form.get('reason_code')).toBe('customer_unavailable');
expect(form.get('proof_file')).toBe(photo);
expect(form.get('idempotency_key')).toMatch(
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
);
```

- [ ] **Step 2: Run the rider test to verify failure**

Run:

```powershell
npx.cmd vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: FAIL because only Confirm pickup is rendered in the pickup stage.

- [ ] **Step 3: Add the API helper**

In `logisticsApi`:

```ts
reportIssue: (legId: number, form: FormData) =>
  axios.post(`/api/logistics/legs/${legId}/report-issue`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }),
```

- [ ] **Step 4: Reuse the existing issue form state for pickup**

In `DeliveryActions`, derive:

```ts
const isRepairPickup = delivery?.shipment?.purpose === 'repair_pickup'
  && ['assigned', 'pickup_scheduled'].includes(delivery.status);
const issueKeys = useRef<Record<number, string>>({});
```

When the rider submits:

```ts
const idempotencyKey =
  issueKeys.current[delivery.id] ??
  (issueKeys.current[delivery.id] = crypto.randomUUID());

form.append('attempt_type', isRepairPickup ? 'pickup' : 'delivery');
form.append('idempotency_key', idempotencyKey);
```

For pickup, require `issueFile` for every reason and require notes only when
the reason is `other`. Preserve the current delivery-issue rules for drop-off.

After the arrival summary, render:

```tsx
<button
  type="button"
  disabled={mutationDisabled}
  onClick={() => setShowIssue((shown) => !shown)}
  className="min-h-11 w-full rounded-xl border border-amber-500 px-4 text-sm font-bold text-amber-800 disabled:opacity-50 dark:text-amber-200"
>
  Failed pickup
</button>
```

Use the approved pickup labels. Set the photo input to:

```tsx
<input
  type="file"
  accept="image/*"
  capture="environment"
  aria-label="Failed pickup photo"
  onChange={(event) => setIssueFile(event.target.files?.[0] ?? null)}
/>
```

Submit with the existing `runAction` confirmation:

```ts
runAction(
  `pickup-issue:${delivery.id}`,
  () => logisticsApi.reportIssue(delivery.id, form),
  {
    title: `Submit failed pickup for ${deliveryReference}?`,
    text: 'The dispatcher will choose whether to reschedule or cancel this pickup.',
    confirmButtonText: 'Submit failed pickup',
  },
);
```

Keep the panel mounted while offline and show **Retry after reconnect**.

- [ ] **Step 5: Run the rider tests**

Run the command from Step 2.

Expected: PASS.

- [ ] **Step 6: Commit the rider slice**

```powershell
git add -- resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/services/logisticsApi.ts resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
git diff --cached
git commit -m "feat: add rider failed pickup action"
```

---

### Task 3: Add dispatcher reschedule and compensated cancellation

**Files:**

- Modify: `app/Services/Logistics/ShipmentLegService.php:193-207,422-447`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php:336-383`
- Modify: `app/Services/RepairDeliveryService.php:16-24,289-455`
- Test: `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`
- Test: `tests/Feature/Repair/RepairDeliveryReconciliationTest.php`

- [ ] **Step 1: Write failing reschedule tests**

Create a failed pickup leg with `status = needs_resolution` and
`resolution_type = pickup_failed`. Freeze time before a blackout/non-operating
day and assert:

```php
$rescheduled = app(ShipmentLegService::class)
    ->resolveRetry($leg, 'Customer confirmed availability.');

$this->assertSame('pending', $rescheduled->status->value);
$this->assertSame('retry', $rescheduled->resolution_type);
$this->assertSame('Customer confirmed availability.', $rescheduled->resolution_reason);
$this->assertSame('2026-08-03', $rescheduled->scheduled_delivery_date->toDateString());
$this->assertDatabaseHas('delivery_events', [
    'shipment_leg_id' => $leg->id,
    'event_type' => 'pickup_rescheduled',
    'visibility' => 'customer',
]);
```

Assert the leg remains unassigned and outside a batch.

- [ ] **Step 2: Write failing cancellation and compensation tests**

Extend `RepairDeliveryReconciliationTest` with a paid repair intake whose leg
is `needs_resolution`, `resolution_type = pickup_failed`, and has no
`picked_up_at`.

Call the logistics cancel endpoint as a user with
`assign-logistics-deliveries`, passing:

```php
['reason' => 'Customer asked to cancel the pickup.']
```

Assert:

- leg and shipment are cancelled;
- one intake compensation entry is created;
- a customer-visible `pickup_cancelled` event is stored;
- replay creates no duplicate compensation or event;
- `picked_up`, `in_transit`, and any `picked_up_at` state still reject
  cancellation.

- [ ] **Step 3: Run the focused tests to verify failure**

Run:

```powershell
php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php
```

Expected: FAIL because retry uses the next calendar day and generic
cancellation does not invoke repair compensation.

- [ ] **Step 4: Reuse one next-operating-date helper**

Move the existing operating-days/blackout loop in `ShipmentLegService` into:

```php
private function nextOperatingDate(ShipmentLeg $leg): string
{
    $leg->loadMissing('shipment.shopOwner.logisticsSetting');
    $settings = $leg->shipment->shopOwner->logisticsSetting;
    $next = now(config('app.shop_timezone', 'Asia/Manila'))->addDay();

    while (
        ! in_array($next->dayOfWeekIso, $settings?->operating_days ?? [1, 2, 3, 4, 5, 6], true)
        || in_array($next->toDateString(), $settings?->blackout_dates ?? [], true)
    ) {
        $next->addDay();
    }

    return $next->toDateString();
}
```

Use it in the existing failed-delivery retry calculation and in
`resolveRetry`. Before updating, remember whether the leg was
`pickup_failed`. Emit `pickup_rescheduled` and pickup language for that branch;
leave the delivery retry event unchanged.

- [ ] **Step 5: Permit only the approved pre-custody repair cancellation**

In `RepairDeliveryService::cancelPaidDeliveryLeg`, replace the broad status
rejection with:

```php
$failedPickup = $isIntake
    && $activeLeg?->status->value === 'needs_resolution'
    && $activeLeg->resolution_type === 'pickup_failed'
    && $activeLeg->picked_up_at === null;

if ($activeLeg
    && ! in_array($activeLeg->status->value, ['pending', 'assigned', 'pickup_scheduled'], true)
    && ! $failedPickup) {
    throw ValidationException::withMessages([
        'status' => ['This delivery can no longer be cancelled because rider custody or delivery processing already started.'],
    ]);
}
```

Record one `pickup_cancelled` customer event inside the same transaction when
`$failedPickup` is true. Reuse `DeliveryEventService`; do not expose internal
compensation data in the event message.

- [ ] **Step 6: Route pickup cancellation through the repair service**

Update `ShipmentController::cancel` to accept `Request` and
`RepairDeliveryService`.

For `repair_pickup` plus `pickup_failed`:

1. Require a dispatcher user with `assign-logistics-deliveries`.
2. Validate `reason` as required, string, maximum 500 characters.
3. Load the tenant-owned `RepairRequest`.
4. Read the current `cancellation_target` from
   `RepairDeliveryService::intakeHandoff`.
5. Call `cancelPaidDeliveryLeg` with phase `intake`, the current leg ID, plan
   token, reason, and dispatcher user ID.
6. Return the fresh leg and a customer-safe message.

Keep the existing failed-delivery cancellation branch unchanged.

- [ ] **Step 7: Run the focused backend tests**

Run the command from Step 3.

Expected: PASS.

- [ ] **Step 8: Commit the dispatcher backend slice**

```powershell
git add -- app/Services/Logistics/ShipmentLegService.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/RepairDeliveryService.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php
git diff --cached
git commit -m "feat: resolve failed repair pickups"
```

---

### Task 4: Add the dispatcher Failed Pickups workspace

**Files:**

- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php:48-117`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx:28-36,105-315,402-600`
- Modify: `resources/js/types/logistics.ts:80-140`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [ ] **Step 1: Write failing dispatcher payload/filter tests**

Update the current
`test_dispatcher_shipments_include_only_the_latest_failed_delivery_attempt_per_leg`
coverage so delivery counts remain delivery-only. Add a separate repair pickup:

```php
$pickupAttempt = $pickupLeg->attempts()->create([
    'attempt_type' => 'pickup',
    'status' => 'failed',
    'attempt_number' => 1,
    'reason_code' => 'customer_unavailable',
    'file_path' => 'logistics-attempt/pickup.jpg',
    'attempted_at' => '2026-07-29 10:00:00',
]);

$this->assertSame($pickupAttempt->id, $pickupPayload['legs'][0]['attempts'][0]['id']);
$this->assertSame(0, $pickupPayload['legs'][0]['failed_attempt_count']);
$this->assertSame(1, $pickupPayload['legs'][0]['failed_pickup_count']);
```

Call `/erp/logistics/shipments?status=failed_pickups` and assert only the
repair pickup shipment is returned. Assert a dispatcher has
`canAssign = true`, which is the UI permission gate.

- [ ] **Step 2: Write failing dispatcher UI tests**

Render a repair pickup leg with:

```ts
{
  status: 'needs_resolution',
  resolution_type: 'pickup_failed',
  failed_attempt_count: 0,
  failed_pickup_count: 1,
  attempts: [{
    id: 91,
    attempt_type: 'pickup',
    status: 'failed',
    reason_code: 'customer_unavailable',
    file_path: 'logistics-attempt/91/door.jpg',
    attempted_at: '2026-07-29T10:00:00Z',
  }],
}
```

Assert:

- the Failed pickups filter exists;
- the badge reads **Failed pickup · Needs action**;
- attempt `1` is not shown as `1 / maxDeliveryAttempts`;
- no **Subject for refund** label appears;
- arrival evidence and photo link render;
- Reschedule Pickup prompts for a note and posts to `/resolve/retry`;
- Cancel Pickup prompts for a reason and posts to `/cancel`;
- buttons are hidden without `canAssign`.

- [ ] **Step 3: Run the focused dispatcher tests to verify failure**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php
npx.cmd vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: FAIL because the dispatcher query filters out pickup attempts and the
UI has no failed-pickup controls.

- [ ] **Step 4: Serialize pickup failures without changing delivery counts**

In `ErpLogisticsController`:

- accept `failed_pickups` in the status allowlist;
- load the latest failed attempt across `pickup` and `delivery`;
- keep `failed_attempt_count` filtered to `attempt_type = delivery`;
- add `failed_pickup_count` filtered to `attempt_type = pickup`;
- filter `failed_pickups` by `purpose = repair_pickup` and a failed pickup
  attempt.

Do not change max-delivery-attempt behavior.

- [ ] **Step 5: Add the dispatcher types and UI**

In `TrackingShipmentLeg`, add:

```ts
failed_pickup_count?: number;
```

In both `attempts` and `latest_failed_attempt`, add:

```ts
attempt_type?: 'pickup' | 'delivery';
```

Add to `statusOptions`:

```ts
['failed_pickups', 'Failed pickups'],
```

Derive the state once per leg:

```ts
const isFailedPickup = shipment.purpose === 'repair_pickup'
  && leg.status === 'needs_resolution'
  && leg.resolution_type === 'pickup_failed'
  && latestAttempt?.attempt_type === 'pickup';
```

Use **Failed pickup · Needs action**, the safe reason label, timestamp, arrival
summary, and the current photo link. Never use `maxDeliveryAttempts` or the
refund warning for this branch.

Add one SweetAlert helper that requires a textarea value, then calls the
existing `act` helper:

```ts
const resolveFailedPickup = async (
  legId: number,
  action: 'retry' | 'cancel',
) => {
  const result = await Swal.fire({
    title: action === 'retry' ? 'Reschedule pickup?' : 'Cancel pickup?',
    input: 'textarea',
    inputLabel: action === 'retry' ? 'Dispatcher note' : 'Cancellation reason',
    inputValidator: (value) => value.trim() ? undefined : 'Enter a reason.',
    showCancelButton: true,
    confirmButtonText: action === 'retry' ? 'Reschedule Pickup' : 'Cancel Pickup',
  });
  if (!result.isConfirmed) return;

  await act(
    `/api/logistics/legs/${legId}/${action === 'retry' ? 'resolve/retry' : 'cancel'}`,
    { reason: result.value.trim() },
  );
};
```

Gate both buttons with `canAssign && !riderMode`.

- [ ] **Step 6: Run the focused dispatcher tests**

Run the commands from Step 3.

Expected: PASS.

- [ ] **Step 7: Commit the dispatcher workspace slice**

```powershell
git add -- app/Http/Controllers/Logistics/ErpLogisticsController.php resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/types/logistics.ts tests/Feature/Logistics/LogisticsPageAccessTest.php resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git diff --cached
git commit -m "feat: add dispatcher failed pickup resolution"
```

---

### Task 5: Show sanitized failed pickup proof to the customer

**Files:**

- Modify: `app/Services/Logistics/CustomerTrackingService.php:12-111`
- Modify: `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx:235-275`
- Modify: `resources/js/types/logistics.ts:105-125`
- Test: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Test: `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

- [ ] **Step 1: Write failing customer payload tests**

Create a customer-owned `repair_pickup` shipment with a failed pickup attempt
containing a photo, raw reason code, internal note, actor IDs, and resolution
metadata.

Assert the safe payload is exactly:

```php
$this->assertSame([
    'id',
    'attempt_type',
    'reason',
    'attempted_at',
    'proof_url',
], array_keys($attempt));
$this->assertSame('pickup', $attempt['attempt_type']);
$this->assertSame('Customer unavailable / not home', $attempt['reason']);
$this->assertArrayNotHasKey('notes', $attempt);
$this->assertArrayNotHasKey('reason_code', $attempt);
$this->assertArrayNotHasKey('recorded_by_id', $attempt);
```

Add cases for all pickup reason labels, missing files, cross-customer proof
access, and deterministic selection when both pickup and delivery attempts
exist. The newest `attempted_at`, then highest ID, wins.

- [ ] **Step 2: Write failing customer UI tests**

Render a repair pickup shipment with a safe failed pickup attempt and assert:

```tsx
expect(screen.getByText('Pickup attempt unsuccessful')).toBeInTheDocument();
expect(screen.getByText('Customer unavailable / not home')).toBeInTheDocument();
expect(screen.getByAltText('Failed pickup proof')).toHaveAttribute(
  'src',
  '/tracking/shipments/1/attempts/9/proof',
);
```

Keep the existing failed-delivery title and alt text for
`attempt_type = delivery`. Verify a broken or missing photo shows
**Attempt photo unavailable**.

- [ ] **Step 3: Run the customer tests to verify failure**

Run:

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php
npx.cmd vitest run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
```

Expected: FAIL because tracking loads only delivery attempts and the UI always
uses delivery language.

- [ ] **Step 4: Extend only the safe customer read model**

Add the pickup reason labels to `ATTEMPT_REASON_LABELS`:

```php
'customer_unavailable' => 'Customer unavailable / not home',
'customer_requested_reschedule' => 'Customer requested reschedule',
'customer_refused_pickup' => 'Customer refused pickup',
'item_not_ready' => 'Item not ready or unavailable',
'wrong_address_or_pin' => 'Wrong address or map pin',
'unsafe_or_inaccessible_location' => 'Unsafe or inaccessible location',
'vehicle_or_rider_problem' => 'Vehicle or rider problem',
```

Load failed attempts with:

```php
->whereIn('attempt_type', ['pickup', 'delivery'])
->where('status', 'failed')
->latest('attempted_at')
->latest('id')
```

Add only this safe discriminator:

```php
'attempt_type' => $attempt->attempt_type,
```

Keep the existing authorized proof route. Do not expose storage paths, notes,
raw reason codes, actor data, or dispatcher resolution data.

- [ ] **Step 5: Render pickup-specific customer language**

In `ShipmentTracking`:

```tsx
const isPickupFailure = attempt.attempt_type === 'pickup';
```

Use:

- active pickup: **Pickup attempt unsuccessful**;
- historical pickup: **Previous pickup attempt**;
- active delivery: existing **Delivery Attempt Failed**;
- historical delivery: existing **Previous delivery attempt**;
- pickup photo alt: **Failed pickup proof**.

- [ ] **Step 6: Run the customer tests**

Run the commands from Step 3.

Expected: PASS.

- [ ] **Step 7: Commit the customer tracking slice**

```powershell
git add -- app/Services/Logistics/CustomerTrackingService.php resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx resources/js/types/logistics.ts tests/Feature/Logistics/CustomerTrackingTest.php resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
git diff --cached
git commit -m "feat: show failed pickup proof to customers"
```

---

### Task 6: Run the release gate and refresh public assets

**Files:**

- Regenerate: `public/build/manifest.json`
- Regenerate: `public/build/assets/*`

- [ ] **Step 1: Inspect branch scope before rebasing**

Run:

```powershell
git status --short
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
```

Expected: only the approved spec, plan, failed-pickup source, and test files.
Stop on any unrelated deletion.

- [ ] **Step 2: Rebase on the latest shared branch**

Run:

```powershell
git fetch origin
git rebase origin/solespace-b
```

Resolve only verified conflicts. Do not force-push or modify unrelated work.

- [ ] **Step 3: Run focused backend verification**

Run:

```powershell
php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/CustomerTrackingTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php
```

Expected: PASS.

- [ ] **Step 4: Run the complete Logistics suite**

Run:

```powershell
php artisan test tests/Feature/Logistics
```

Expected: PASS with no reduction from the current baseline test count.

- [ ] **Step 5: Run focused frontend verification**

Run:

```powershell
npx.cmd vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
```

Expected: PASS.

- [ ] **Step 6: Build the production assets**

Run:

```powershell
npm.cmd run build
```

Expected: Vite completes successfully and writes a fresh manifest and hashed
assets under `public/build`.

- [ ] **Step 7: Verify the generated build**

Parse `public/build/manifest.json` and assert every `file`, `css`, and `assets`
entry exists. Confirm the Staff/Rider My Deliveries, Logistics Shipments, and
Shipment Tracking entries reference existing assets. Search only those
generated chunks for:

- `Failed pickup`;
- `Failed pickups`;
- `Pickup attempt unsuccessful`.

- [ ] **Step 8: Commit only the generated public build**

```powershell
git add -A -- public/build
git diff --cached --name-status
git diff --cached --check
git commit -m "build: refresh public assets for failed pickup"
```

Expected: every staged path is under `public/build`.

- [ ] **Step 9: Run the final branch check**

Run:

```powershell
git status --short --branch
git log -8 --oneline
git diff --name-status origin/solespace-b...HEAD
```

Expected: clean worktree and only intended commits.

- [ ] **Step 10: Push the feature branch**

```powershell
git push -u origin feat/rider-my-deliveries-phase-3
```

Do not create or merge the PR; the user will do that manually.
