# Customer Failed Delivery Details Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show customer-safe failed-attempt details and proof in shipment tracking, flag unresolved failures in My Purchases, and display shop-logistics delivery estimates.

**Architecture:** Extend the existing customer tracking and My Purchases payloads from the latest shipment/current leg instead of adding a second data source. Serve attempt photos through an authenticated ownership-checked route, and render the new fields in the two existing customer pages.

**Tech Stack:** Laravel, Eloquent, Inertia, React, TypeScript, Tailwind CSS, PHPUnit, Vitest, Testing Library

---

### Task 1: Customer-safe failed-attempt payload and proof route

**Files:**
- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `app/Http/Controllers/Logistics/CustomerTrackingController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Test: `tests/Feature/Logistics/LogisticsAuditPrivacyTest.php`

- [ ] **Step 1: Write failing payload tests**

Create failed attempts with every supported reason, an unknown reason, tied timestamps, missing proof, no attempt, and internal notes/recorder data. Assert each attempt object has exactly these keys:

```php
'latest_failed_attempt' => [
    'id' => $latestAttempt->id,
    'reason' => 'Recipient unavailable',
    'attempted_at' => $latestAttempt->attempted_at->toISOString(),
    'proof_url' => route('customer.tracking.attempt-proof', [$shipment, $latestAttempt]),
]
```

Assert selection uses `attempted_at DESC, id DESC`, unknown codes return `Delivery could not be completed`, missing files return `proof_url => null`, no attempt returns null, and serialized output contains no `notes`, `file_path`, raw `reason_code`, `next_attempt_at`, `recorded_by_type`, `recorded_by_id`, `resolution_type`, or `resolution_reason`. Add equal-sequence legs and assert the highest ID is current while failures on other legs remain historical.

- [ ] **Step 2: Write failing proof authorization tests**

Assert the owning customer receives 200 with the stored image, unauthenticated access redirects to the user login, another customer receives 403, an attempt belonging to another shipment receives 403, and a missing file receives 404.

- [ ] **Step 3: Run tests to verify RED**

Run:

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php tests/Feature/Logistics/LogisticsAuditPrivacyTest.php
```

Expected: FAIL because `latest_failed_attempt`, the route, and the controller action do not exist.

- [ ] **Step 4: Implement the minimal payload**

Load legs deterministically and load each leg's latest failed delivery attempt with:

```php
'legs' => fn ($query) => $query->orderBy('sequence')->orderBy('id'),
'legs.attempts' => fn ($query) => $query
    ->where('attempt_type', 'delivery')
    ->where('status', 'failed')
    ->latest('attempted_at')
    ->latest('id')
```

Map the five approved reason codes in `CustomerTrackingService`; use the safe fallback for everything else. Generate `proof_url` only when the public-disk file exists. Never serialize raw paths or notes.

- [ ] **Step 5: Implement the authorized proof response**

Add a named route beside the existing shipment tracking route:

```php
Route::get('/tracking/shipments/{shipment}/attempts/{attempt}/proof', [CustomerTrackingController::class, 'attemptProof'])
    ->middleware('auth:user')
    ->name('customer.tracking.attempt-proof');
```

In `attemptProof`, require the `user` guard, call `customerOwnsShipment`, verify `$attempt->leg->shipment_id === $shipment->id`, return 404 when the file is absent, and otherwise return `Storage::disk('public')->response($attempt->file_path)`.

- [ ] **Step 6: Run tests to verify GREEN**

Run the Task 1 command. Expected: PASS.

- [ ] **Step 7: Commit**

Inspect `git diff --` for the five Task 1 files. Stage only the intended hunks with `git add -p`; if an intended hunk overlaps pre-existing work, stop and preserve both before committing.

```powershell
git commit -m "feat: expose customer-safe failed delivery details"
```

### Task 2: My Purchases failure and estimate payload

**Files:**
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Test: `tests/Feature/Logistics/CustomerTrackingTest.php`

- [ ] **Step 1: Write failing order payload tests**

Create multiple shipments and equal-sequence legs for one order. Assert the newest shipment by `id DESC` and its current leg by `sequence DESC, id DESC` supply these exact final Inertia keys:

```php
'delivery_has_failed_attempt' => true,
'delivery_scheduled_date' => '2026-07-18',
'delivery_window' => 'morning',
```

Also assert another order is not flagged, older shipment/leg failures are ignored, `awaiting_proof_approval` and `delivered` clear the flag, and full/partial/null schedules serialize exactly as specified.

- [ ] **Step 2: Run the test to verify RED**

Run:

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php
```

Expected: FAIL because the My Purchases payload lacks these fields.

- [ ] **Step 3: Implement deterministic selection**

Eager-load shipment legs ordered by `sequence ASC`, then `id ASC` so `last()` selects the required highest values, with their latest failed delivery attempt ordered by `attempted_at DESC, id DESC`. Extend the existing per-order shipment summary and final Inertia order payload with the same exact names:

```php
'delivery_has_failed_attempt' => $latestAttempt
    && !in_array($currentLeg->status->value, ['awaiting_proof_approval', 'delivered'], true),
'delivery_scheduled_date' => optional($currentLeg?->scheduled_delivery_date)->toDateString(),
'delivery_window' => $currentLeg?->delivery_window,
```

Pass these through the existing `delivery_*` order fields without changing third-party carrier data.

- [ ] **Step 4: Run the test to verify GREEN**

Run the Task 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

Inspect both Task 2 files and stage only intended hunks with `git add -p` before committing.

```powershell
git commit -m "feat: add failed attempt summary to purchases"
```

### Task 3: Customer tracking and My Purchases UI

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`
- Modify: `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx`

- [ ] **Step 1: Write failing tracking UI tests**

Assert an unresolved current-leg failure renders `Delivery Attempt Failed`, its safe reason, formatted time, and proof image/link. Assert every non-current leg failure renders as historical detail, a delivered current leg keeps its failure as historical detail without the active amber warning, a null proof renders `Attempt photo unavailable`, and an image request error replaces the image with the same fallback.

- [ ] **Step 2: Write failing My Purchases tests**

Assert only the matching shop-owned order renders `Failed delivery attempt` and links to `/tracking/shipments/{id}`. Assert shop logistics formats `2026-07-18` plus `morning` as `July 18, 2026 · Morning` and shows a date alone when the window is null. Add the explicit fixture `{ delivery_scheduled_date: null, delivery_window: 'afternoon' }` and assert it shows `Not scheduled yet` without `Afternoon`. Assert third-party delivery does not use this estimate block.

- [ ] **Step 3: Run UI tests to verify RED**

Run:

```powershell
npx vitest run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx
```

Expected: FAIL because the new details, warning, and logistics estimate are not rendered.

- [ ] **Step 4: Extend existing TypeScript types**

Add nullable `latest_failed_attempt` to `TrackingShipmentLeg`, and add exactly three new fields to the existing local My Orders order type: `delivery_has_failed_attempt`, `delivery_scheduled_date`, and `delivery_window`. Do not introduce a new shared abstraction.

- [ ] **Step 5: Render the tracking failure panel**

Use the existing date/title helpers. Determine the current leg from the already ordered payload's last leg. Render an amber panel only when that leg is unresolved; render failures on every other leg, and a delivered/awaiting current leg failure, as subdued historical details. Track failed image IDs in local state so `onError` replaces a stale or 404 `proof_url` with `Attempt photo unavailable`.

- [ ] **Step 6: Render the purchase warning and estimate**

Inside the existing Delivery Tracking section, render the warning/link when `delivery_has_failed_attempt` is true. When `is_shop_owned_delivery` is true, first require a non-null scheduled date; without one, show `Not scheduled yet` and ignore any window value. Only after formatting a valid date with `Intl.DateTimeFormat` may a title-cased window be appended.

- [ ] **Step 7: Run UI tests to verify GREEN**

Run the Task 3 command. Expected: PASS.

- [ ] **Step 8: Commit**

Inspect all five Task 3 files and stage only intended hunks with `git add -p` before committing.

```powershell
git commit -m "feat: show customer failed delivery details"
```

### Task 4: Full verification

- [ ] **Step 1: Run logistics tests**

```powershell
php artisan test tests/Feature/Logistics
```

Expected: all logistics tests pass with no count regression.

- [ ] **Step 2: Run customer UI tests**

```powershell
npx vitest run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx
```

Expected: all selected tests pass.

- [ ] **Step 3: Build production assets**

```powershell
npm run build
```

Expected: Vite exits successfully.

- [ ] **Step 4: Check the intended diff**

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors. Preserve and report all pre-existing unstaged changes and the saved stash; do not stage `public/build` rewrites or cache artifacts.
