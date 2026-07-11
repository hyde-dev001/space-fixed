# Logistics QoL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let riders filter and report delivery issues, and let authorised dispatchers resolve those requests as final cancellations with clear, safe feedback.

**Architecture:** Reuse the existing `delivery_attempts` audit record and `ShipmentLegService` transition boundary; add two narrowly scoped API actions for rider issue reports and dispatcher cancellation. Keep the role-aware shipments page and add filters, attempt context, explicit confirmation dialogs, and toast feedback there rather than creating pages, tables, or statuses.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, PHPUnit, Inertia React/TypeScript, Axios, SweetAlert2, Tailwind.

---

## File structure

- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php` — query rider status/time filters and load latest failure attempts for both views.
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php` — validate and authorise the two new leg actions.
- Modify: `app/Services/Logistics/ShipmentLegService.php` — record the actor on reports, perform final cancellation, and derive shipment completion/cancellation from every leg.
- Modify: `routes/web.php` — register the two authenticated logistics API routes.
- Modify: `resources/js/types/logistics.ts` — expose attempt details and assignment timestamps used by the page.
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx` — retain one role-aware component while adding rider filters/actions and dispatcher attention/cancellation UI.
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php` — cover rider-filtered Inertia results and attempt context.
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php` — cover endpoint validation, ownership, permissions, and accepted transitions.
- Modify: `tests/Feature/Logistics/ShipmentLegServiceTest.php` — cover events and the complete shipment-status matrix.

No migration, enum, new page component, cancellation-request table, or dependency is required: `delivery_attempts.reason_code`/`notes`, `delivery_events`, the existing `cancelled` enum value, and SweetAlert2 already cover the design.

### Task 1: Make service cancellation and shipment aggregation correct

**Files:**
- Modify: `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`

- [ ] **Step 1: Write failing service tests for final cancellation and shipment synchronisation**

  Add tests that create shipment legs with `requires_delivery_proof => false` and assert:

  ```php
  app(ShipmentLegService::class)->cancel($attemptedLeg, 'Recipient was unavailable.');

  $this->assertSame('cancelled', $attemptedLeg->fresh()->status->value);
  $this->assertSame('cancelled', $shipment->fresh()->status->value); // every leg cancelled
  $this->assertDatabaseHas('delivery_events', [
      'shipment_id' => $shipment->id,
      'shipment_leg_id' => $attemptedLeg->id,
      'event_type' => 'delivery_cancelled',
      'visibility' => 'customer',
      'message' => 'Delivery cancelled: Recipient was unavailable.',
  ]);
  ```

  Cover these matrices in individual tests:

  - `delivery_attempted` cancels, while `assigned`, `picked_up`, `in_transit`, `awaiting_proof_approval`, `delivered`, and `cancelled` each throw `ValidationException`.
  - all cancelled legs set shipment status to `cancelled` and leave `completed_at` null;
  - a delivered leg plus a cancelled leg sets shipment status to `completed` and a completion timestamp;
  - a cancelled leg plus an `in_transit` leg keeps shipment status `active`.

- [ ] **Step 2: Run the focused service tests to verify they fail**

  Run: `php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php`

  Expected: FAIL because `ShipmentLegService::cancel()` and all-terminal shipment synchronisation do not exist.

- [ ] **Step 3: Implement the minimal service methods**

  Add a `cancel(ShipmentLeg $leg, string $customerReason): ShipmentLeg` method that uses one `DB::transaction()` to validate the `delivery_attempted` state, update the leg to `cancelled`, synchronise the shipment, and record both required events:

  ```php
  $leg->loadMissing('shipment');
  $this->assertTransitionAllowed($leg, ['delivery_attempted'], 'cancelled');

  $leg->update(['status' => 'cancelled']);
  $this->syncShipmentStatus($leg);
  $this->events->record($leg->shipment, $leg, [
      'event_type' => 'delivery_cancelled',
      'visibility' => 'internal',
      'message' => 'Dispatcher cancelled the delivery.',
  ]);
  $this->events->record($leg->shipment, $leg, [
      'event_type' => 'delivery_cancelled',
      'visibility' => 'customer',
      'message' => "Delivery cancelled: {$customerReason}.",
  ]);
  return $leg->fresh();
  ```

  Assert both events in the tests; the internal event must not contain the rider note. Replace `syncShipmentStatus()` with one query over all leg statuses:

  ```php
  $statuses = $shipment->legs()->pluck('status');
  $allCancelled = $statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'cancelled');
  $allTerminal = $statuses->isNotEmpty() && $statuses->every(fn ($status) => in_array($status, ['delivered', 'cancelled'], true));

  if ($allCancelled) {
      $shipment->update(['status' => 'cancelled', 'completed_at' => null]);
  } elseif ($allTerminal && $statuses->contains('delivered')) {
      $shipment->update(['status' => 'completed', 'completed_at' => now()]);
      $this->completeShopOwnedRetailOrder($shipment);
      $this->completeShopOwnedReturn($shipment);
  } else {
      $shipment->update(['status' => 'active', 'completed_at' => null]);
  }
  ```

  Preserve existing pickup/delivery behavior and event visibility by leaving existing `transition()` callers unchanged.

- [ ] **Step 4: Run the focused service tests to verify they pass**

  Run: `php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit the service slice**

  ```bash
  git add app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/ShipmentLegServiceTest.php
  git commit -m "feat: finalise logistics delivery cancellations"
  ```

### Task 2: Add protected report and cancellation endpoints

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing API tests**

  Add tests for the two actions using the established assignment fixtures:

  ```php
  $this->actingAs($rider, 'user')
      ->postJson("/api/logistics/legs/{$leg->id}/report-issue", [
          'reason_code' => 'recipient_unavailable',
          'notes' => 'No answer at the gate.',
      ])
      ->assertCreated()
      ->assertJsonPath('attempt.reason_code', 'recipient_unavailable');

  $this->assertSame('delivery_attempted', $leg->fresh()->status->value);
  $this->assertDatabaseHas('delivery_attempts', [
      'shipment_leg_id' => $leg->id,
      'recorded_by_type' => User::class,
      'recorded_by_id' => $rider->id,
  ]);
  ```

  Assert invalid reason codes and a missing reason return 422; another rider returns 403; rider reports from `assigned`, `picked_up`, `in_transit`, and `delivery_attempted` are accepted. Add dispatcher tests proving only a user with `assign-logistics-deliveries` (or a shop owner) can call `/cancel`, and a cancellation returns the customer-safe mapped message. Assert the rider's note is absent from the customer event message and metadata.

- [ ] **Step 2: Run the focused API tests to verify they fail**

  Run: `php artisan test tests/Feature/Logistics/LogisticsApiTest.php`

  Expected: FAIL with 404s or missing controller methods.

- [ ] **Step 3: Register routes and implement request handling**

  Add the two routes inside the existing authenticated `api/logistics` group:

  ```php
  Route::post('/legs/{leg}/report-issue', [ShipmentController::class, 'reportIssue']);
  Route::post('/legs/{leg}/cancel', [ShipmentController::class, 'cancel']);
  ```

  In `reportIssue()`, reuse `authorizeLegUpdate()` so the active rider ownership boundary remains the single source of truth. Validate exactly the spec's codes and optional note:

  ```php
  $payload = $request->validate([
      'reason_code' => ['required', 'in:recipient_unavailable,wrong_or_incomplete_address,recipient_refused,vehicle_or_delivery_problem,other'],
      'notes' => ['nullable', 'string'],
  ]);
  $payload['recorded_by_type'] = $actor::class;
  $payload['recorded_by_id'] = $actor->id;
  ```

  Adjust `recordFailedAttempt()` to accept `assigned` as well as its present states; force `attempt_type` to `delivery` for this endpoint while preserving the existing generic attempts endpoint.

  In `cancel()`, call `authorizedShop('assign-logistics-deliveries')`, tenant-check the leg, map the saved latest delivery-attempt `reason_code` to these public strings, and invoke `ShipmentLegService::cancel()`:

  ```php
  [
      'recipient_unavailable' => 'Recipient was unavailable',
      'wrong_or_incomplete_address' => 'Address could not be completed',
      'recipient_refused' => 'Recipient refused the delivery',
      'vehicle_or_delivery_problem' => 'A delivery problem prevented completion',
      'other' => 'Delivery could not be completed',
  ]
  ```

  Load the latest `attempt_type = delivery` attempt by `attempted_at`/`id`; return 422 if the `delivery_attempted` leg has no recorded reason. Do not place notes in either cancellation event payload.

- [ ] **Step 4: Run the focused API tests to verify they pass**

  Run: `php artisan test tests/Feature/Logistics/LogisticsApiTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit the API slice**

  ```bash
  git add routes/web.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/LogisticsApiTest.php
  git commit -m "feat: let riders report logistics issues"
  ```

### Task 3: Query rider filters and dispatcher attention context

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`

- [ ] **Step 1: Write failing Inertia page tests**

  Add an assigned rider fixture with assignments at three timestamps and assert `/erp/logistics/deliveries?status=in_transit&window=today` returns only the matching leg's shipment. Add an assignment dated this Monday and one on the preceding Sunday, call `?window=week` with `Carbon::setTestNow()` set in the configured shop timezone, and assert Monday through Sunday inclusion. Assert the response returns `filters` as `['status' => 'in_transit', 'window' => 'today']`, and dispatcher shipment props include each leg's latest delivery attempt.

- [ ] **Step 2: Run the page-access tests to verify they fail**

  Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php`

  Expected: FAIL because rider status/window query filtering and attempt eager-loading are absent.

- [ ] **Step 3: Implement constrained eager loads and filters**

  In `deliveries()`, accept only known leg statuses (otherwise `all`) and `today|week` (otherwise `all`). Use the established `config('app.shop_timezone', 'Asia/Manila')` convention, calculate `[startOfDay, endOfDay]` or `[startOfWeek(Carbon::MONDAY), endOfWeek(Carbon::SUNDAY)]`, convert those boundaries to the database timezone before querying, and apply the interval to the assigned rider's `delivery_assignments.assigned_at` in both the parent `whereHas` and constrained `legs` relation. Apply the selected leg status to both queries.

  Eager-load `attempts` constrained to failed delivery attempts ordered newest-first for dispatcher and rider legs:

  ```php
  'attempts' => fn ($attempts) => $attempts
      ->where('attempt_type', 'delivery')
      ->where('status', 'failed')
      ->latest('attempted_at')
  ```

  Return the actual rider filters as `['status' => $status, 'window' => $window]`, add `withQueryString()` to rider pagination, and leave the dispatcher filters untouched.

- [ ] **Step 4: Run the page-access tests to verify they pass**

  Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit the query slice**

  ```bash
  git add app/Http/Controllers/Logistics/ErpLogisticsController.php tests/Feature/Logistics/LogisticsPageAccessTest.php
  git commit -m "feat: filter rider deliveries and show failure context"
  ```

### Task 4: Extend the existing role-aware logistics UI

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`

- [ ] **Step 1: Add the response types required by the existing page**

  Extend `TrackingShipmentLeg` without new shared abstractions:

  ```ts
  assigned_at?: string | null;
  attempts?: Array<{
    id: number;
    attempt_type: string;
    status: string;
    reason_code?: string | null;
    notes?: string | null;
    attempted_at?: string | null;
  }>;
  ```

  Add `window: string` to `ShipmentFilters`; keep `purpose` optional/read only for the rider path so dispatcher behavior is unchanged.

- [ ] **Step 2: Add the compact rider filter bar and stable reload behavior**

  Show status and `All time` / `Today` / `This week` selects when `riderMode` is true. Make `updateFilter()` select `/erp/logistics/deliveries` for rider mode and `/erp/logistics/shipments` otherwise, preserving `filters`, scroll, and state. Do not show a modal or toast for routine filter reloads.

- [ ] **Step 3: Add confirmation helpers and toast feedback using SweetAlert2**

  Import the installed `sweetalert2`. Replace the inline error-only action behavior with a small local `showToast(icon, title)` helper and `act()` that keeps current data on errors, reloads only `shipments` after success, and shows a success/error toast:

  ```ts
  const showToast = (icon: 'success' | 'error', title: string) =>
    Swal.fire({ toast: true, position: 'top-end', icon, title, showConfirmButton: false, timer: 3000, timerProgressBar: true });
  ```

  Wrap rider reassignment (where it remains available), delivery confirmation, and final cancellation with `await Swal.fire({ showCancelButton: true, ... })`; return unless `isConfirmed`. Do not confirm proof upload or non-destructive status movement unless the spec asks for it.

- [ ] **Step 4: Render the rider report form and dispatcher resolution context**

  In the existing expanded leg card, when `riderMode` and status is one of `assigned`, `picked_up`, `in_transit`, or `delivery_attempted`, render a compact reason select, optional note textarea, and `Report issue / Request cancellation` button. Submit `POST /api/logistics/legs/${leg.id}/report-issue`; validate selection client-side only to avoid an empty request, then rely on the API for all security and transition rules.

  For every leg, derive `const latestAttempt = leg.attempts?.[0]`. In dispatcher mode and `leg.status === 'delivery_attempted'`, show a `Needs attention` pill, the formatted reason, and internal note in the failure-context group. Offer an outlined red `Cancel delivery` button only in this state; confirmation calls `/api/logistics/legs/${leg.id}/cancel`. Never render the rider note in customer-facing UI or event copy.

  Reorganize the existing card into visible groups for assignment/rider, proof, failure context, and actions while retaining present assignment and proof controls. Use existing Tailwind utility classes; do not introduce a component library or a second page.

- [ ] **Step 5: Run the frontend build**

  Run: `pnpm build`

  Expected: PASS with the TypeScript/Vite bundle built successfully.

- [ ] **Step 6: Commit the UI slice**

  ```bash
  git add resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/Shipments.tsx
  git commit -m "feat: improve logistics rider and dispatcher actions"
  ```

### Task 5: Run the complete relevant verification set

**Files:**
- Verify only; no new files.

- [ ] **Step 1: Run all logistics feature tests**

  Run: `php artisan test tests/Feature/Logistics`

  Expected: PASS.

- [ ] **Step 2: Run the frontend test suite**

  Run: `pnpm test:frontend`

  Expected: PASS.

- [ ] **Step 3: Re-run the production build**

  Run: `pnpm build`

  Expected: PASS.

- [ ] **Step 4: Inspect the final diff**

  Run: `git diff --check && git status --short`

  Expected: no whitespace errors; only intended logistics source/test changes plus any generated build artifacts already managed by the repository.

- [ ] **Step 5: Commit verification-ready implementation**

  ```bash
  git add app/Http/Controllers/Logistics/ErpLogisticsController.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/Logistics/ShipmentLegService.php routes/web.php resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/types/logistics.ts tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php
  git commit -m "test: verify logistics quality-of-life flow"
  ```
