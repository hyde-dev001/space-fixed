# Logistics QoL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add rider delivery filters and issue reporting, dispatcher-approved cancellation with customer-visible reasons, and a focused SweetAlert/UI polish pass for ERP logistics.

**Architecture:** Reuse the existing `delivery_attempts` row as the rider's cancellation request and `delivery_events` as the customer notification channel. A new cancellation service method owns the only final `cancelled` transition and shipment-status synchronisation. The existing role-aware ERP shipment component remains the single UI; the controller supplies rider-specific filters and leg context.

**Tech Stack:** Laravel 12/PHP, Eloquent, Inertia React/TypeScript, Axios, SweetAlert2 via `workflowFeedback`, PHPUnit, Vitest, Tailwind CSS.

---

## File structure

- `app/Services/Logistics/ShipmentLegService.php` — validates and applies the final dispatcher cancellation transition, writes audit/customer events, and synchronises shipment status.
- `app/Http/Controllers/Api/Logistics/ShipmentController.php` — exposes a rider-only report endpoint and dispatcher-only final-cancellation endpoint.
- `app/Http/Controllers/Logistics/ErpLogisticsController.php` — applies rider `status` and `window` query filters and loads attempts for the page.
- `routes/web.php` — registers the two logistics POST endpoints within the existing authenticated API group.
- `resources/js/types/logistics.ts` — exposes delivery-attempt data and the rider filter props to the existing page.
- `resources/js/Pages/ERP/Logistics/Shipments.tsx` — adds the compact rider filter bar, issue/cancellation modal, dispatcher cancellation action, feedback, and hierarchy polish.
- `tests/Feature/Logistics/ShipmentLegServiceTest.php` — covers terminal status synchronisation and audit/customer events.
- `tests/Feature/Logistics/LogisticsApiTest.php` — covers rider ownership, allowed report statuses, cancellation authority, and rejection states.
- `tests/Feature/Logistics/LogisticsPageAccessTest.php` — covers rider filtering and page props.

No migration, enum, new React component, or customer tracking page change is required: `CustomerTrackingService` already returns customer-visible delivery events and `ShipmentTracking.tsx` already renders their messages.

### Task 1: Implement and test the final cancellation transition

**Files:**
- Modify: `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`

- [ ] **Step 1: Write failing service tests for cancellation and shipment status sync**

Add tests that call `ShipmentLegService::cancel(...)` on a `delivery_attempted` leg with a prior failed attempt and assert:

```php
$this->assertSame('cancelled', $leg->fresh()->status->value);
$this->assertSame('cancelled', $shipment->fresh()->status->value);
$this->assertDatabaseHas('delivery_events', [
    'shipment_id' => $shipment->id,
    'shipment_leg_id' => $leg->id,
    'event_type' => 'delivery_cancelled',
    'visibility' => 'customer',
    'message' => 'Delivery cancelled: recipient unavailable.',
]);
```

Add separate cases proving: all cancelled legs cancel the shipment; a delivered/cancelled terminal mix completes it; a cancelled leg plus a non-terminal leg leaves it active; and non-`delivery_attempted` source statuses throw `ValidationException`.

- [ ] **Step 2: Run the focused test file to verify it fails**

Run: `php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php`

Expected: FAIL because `ShipmentLegService::cancel` does not exist and status sync lacks cancellation handling.

- [ ] **Step 3: Add the minimal service method and extend status synchronisation**

In `ShipmentLegService`, add `cancel(ShipmentLeg $leg, User|ShopOwner $actor): ShipmentLeg` that:

```php
$this->assertTransitionAllowed($leg, ['delivery_attempted'], 'cancelled');
$attempt = $leg->attempts()->latest('attempted_at')->firstOrFail();

return DB::transaction(function () use ($leg, $attempt, $actor) {
    $leg->update(['status' => 'cancelled']);
    $this->syncShipmentStatus($leg);
    $this->events->record($leg->shipment, $leg, [
        'event_type' => 'delivery_cancelled',
        'visibility' => 'customer',
        'message' => $this->customerCancellationMessage($attempt->reason_code),
        'metadata' => ['reason_code' => $attempt->reason_code],
        'created_by_type' => $actor::class,
        'created_by_id' => $actor->id,
    ]);
    return $leg->fresh();
});
```

Use a private reason-to-message map for the five approved codes, with `other` falling back to `Delivery cancelled.`. Do not include `notes` in the customer event. Update `syncShipmentStatus()` in this order: all legs cancelled → set `cancelled` and `cancelled_at`; all legs terminal (`delivered` or `cancelled`) with at least one delivered → retain the existing completed path; otherwise set `active`. Keep existing order/refund completion side effects only on the completed path.

- [ ] **Step 4: Run the focused test file to verify it passes**

Run: `php artisan test tests/Feature/Logistics/ShipmentLegServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit the service change**

```bash
git add app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/ShipmentLegServiceTest.php
git commit -m "feat: add dispatcher delivery cancellation"
```

### Task 2: Add rider reporting and dispatcher cancellation endpoints

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `routes/web.php:426-439`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`

- [ ] **Step 1: Write failing API tests**

Add tests for:

- an assigned rider can `POST /api/logistics/legs/{leg}/report-issue` with `reason_code=recipient_unavailable`, receives `201`, and the leg becomes `delivery_attempted`;
- a different rider receives `403`;
- `awaiting_proof_approval`, `delivered`, and `cancelled` legs receive `422` when reporting;
- a dispatcher can `POST /api/logistics/legs/{leg}/cancel` only after a report, and receives the updated cancelled leg;
- a rider cannot use `/cancel`, and a dispatcher receives `422` for assigned, picked-up, in-transit, awaiting-proof-approval, delivered, or cancelled legs.

Use the existing factory setup and permission pattern in this file. Assert the stored attempt contains the selected reason and the cancellation event is customer-visible.

- [ ] **Step 2: Run the API tests to verify they fail**

Run: `php artisan test tests/Feature/Logistics/LogisticsApiTest.php`

Expected: FAIL with 404 routes or missing controller actions.

- [ ] **Step 3: Register and implement minimal endpoints**

Add these routes inside the existing `api/logistics` group in `routes/web.php`:

```php
Route::post('/legs/{leg}/report-issue', [ShipmentController::class, 'reportIssue']);
Route::post('/legs/{leg}/cancel', [ShipmentController::class, 'cancel']);
```

Implement `reportIssue()` with a dedicated assigned-rider authorisation helper: require an authenticated user with `update-logistics-status`, reject a shop-owner/dispatcher bypass, enforce the current active assignment belongs to that user, then validate only the five approved reason codes plus nullable `notes` (maximum 1000 characters). Call the existing `recordFailedAttempt()` with `attempt_type => 'delivery'`, `recorded_by_type`, and `recorded_by_id` set from the user.

Implement `cancel()` with `authorizedShop('assign-logistics-deliveries')`, tenant validation, and `ShipmentLegService::cancel($leg, $actor)`. Do not accept a second cancellation reason: the approved rider report is the source of truth.

- [ ] **Step 4: Run API tests to verify they pass**

Run: `php artisan test tests/Feature/Logistics/LogisticsApiTest.php`

Expected: PASS.

- [ ] **Step 5: Commit endpoint changes**

```bash
git add routes/web.php app/Http/Controllers/Api/Logistics/ShipmentController.php tests/Feature/Logistics/LogisticsApiTest.php
git commit -m "feat: add rider delivery issue reporting"
```

### Task 3: Add deterministic rider filters and page data

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`

- [ ] **Step 1: Write failing rider-filter/page-prop tests**

Create assigned legs with assignments dated inside and outside the current Manila day/week, plus different leg statuses. Request:

```php
$response = $this->actingAs($rider, 'user')
    ->get('/erp/logistics/deliveries?status=in_transit&window=today')
    ->assertOk();
```

Assert only the matching rider shipment is returned, `filters.status` is `in_transit`, `filters.window` is `today`, and the returned leg contains its `attempts` collection.

- [ ] **Step 2: Run the page-access tests to verify they fail**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php`

Expected: FAIL because rider query parameters are ignored and attempts are not eagerly loaded.

- [ ] **Step 3: Apply rider-only query filters in the ERP controller**

In `deliveries()`, whitelist `status` as `all`, `assigned`, `picked_up`, `in_transit`, `delivery_attempted`, `awaiting_proof_approval`, `delivered`, or `cancelled`; whitelist `window` as `all`, `today`, or `week`. Use `config('app.shop_timezone', 'Asia/Manila')`, calculate local start/end boundaries with Carbon, and compare `delivery_assignments.assigned_at` in both the shipment existence query and the eager-loaded leg query. Filter the leg query by selected status, load `attempts` ordered by latest `attempted_at`, and return both selected filter values in `filters`.

Keep dispatcher filtering unchanged and preserve the existing assignment-ownership constraints in every rider subquery.

- [ ] **Step 4: Run the page-access tests to verify they pass**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php`

Expected: PASS.

- [ ] **Step 5: Commit filter changes**

```bash
git add app/Http/Controllers/Logistics/ErpLogisticsController.php tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: filter rider deliveries"
```

### Task 4: Wire the approved UI actions and polish into the existing page

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`

- [ ] **Step 1: Extend page types before changing UI code**

Add the optional attempt shape to `TrackingShipmentLeg`:

```ts
attempts?: Array<{
  id: number;
  reason_code?: string | null;
  notes?: string | null;
  attempted_at?: string | null;
}>;
```

Extend the component's filters type with `window`, preserving dispatcher compatibility.

- [ ] **Step 2: Run the production build to establish the pre-change baseline**

Run: `npm run build`

Expected: PASS.

- [ ] **Step 3: Implement the smallest UI pass in `Shipments.tsx`**

- Import `workflowFeedback` from `@/utils/workflowFeedback`.
- Render the rider-only `Status` and `Today / This week` selects; send both values through the existing `router.get()` helper and reset `page` to 1.
- For rider-owned legs in `assigned`, `picked_up`, `in_transit`, or `delivery_attempted`, add **Report issue / Request cancellation**. Open a SweetAlert select using `workflowFeedback.alert`, require an approved reason, accept an optional short note, then post to `/api/logistics/legs/{id}/report-issue`.
- For dispatcher legs in `delivery_attempted`, display the latest attempt reason/note with a **Needs attention** badge and add the red-outline **Cancel delivery** button. Require `workflowFeedback.confirm()` before posting to `/api/logistics/legs/{id}/cancel`.
- Require `workflowFeedback.confirm()` before reassigning a rider and approving proof. Use `workflowFeedback.success({ toast: true, ... })` and `workflowFeedback.error(...)` around every mutation; reload only `shipments` (and `assignableRiders` after assignment) after success.
- Keep the existing table, expanded delivery cards, controls, and dark-mode classes. Improve visual hierarchy with status pills, grouped leg sections, and clear primary/destructive button styles; do not introduce a dashboard, drag/drop, or a second page component.

- [ ] **Step 4: Build and run focused backend regression tests**

Run: `npm run build; php artisan test tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php`

Expected: Vite build succeeds and all three PHPUnit files PASS.

- [ ] **Step 5: Commit the UI change**

```bash
git add resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/Shipments.tsx
git commit -m "feat: improve logistics delivery workflow"
```

### Task 5: Final focused verification

**Files:**
- No source changes expected.

- [ ] **Step 1: Run the full logistics feature suite**

Run: `php artisan test tests/Feature/Logistics`

Expected: PASS.

- [ ] **Step 2: Run the frontend suite**

Run: `npm run test:frontend`

Expected: PASS.

- [ ] **Step 3: Inspect the working tree before handoff**

Run: `git status --short`

Expected: only the implementation changes and their commits; do not stage unrelated pre-existing worktree changes.
