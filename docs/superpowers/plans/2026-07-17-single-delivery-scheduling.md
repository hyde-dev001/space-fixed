# Single Delivery Scheduling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let dispatchers schedule and assign a single delivery from the Shipments page, repair assigned unscheduled deliveries, and prevent failed-attempt reports before delivery starts.

**Architecture:** Reuse the existing `/api/logistics/legs/schedule` endpoint and scheduling service, extending only its eligible status set from `pending` to `pending|assigned`. Keep single-delivery UI state in the existing Shipments component. Enforce failed-attempt timing both before upload and inside the locked service operation, deleting a new upload if the locked operation rejects it.

**Tech Stack:** Laravel/PHP, React/TypeScript, Axios, Vitest/Testing Library, PHPUnit.

---

### Task 1: Allow assigned single deliveries to be scheduled

**Files:**
- Modify: `tests/Feature/Logistics/BatchDispatchServiceTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php:20-42`

- [ ] **Step 1: Write a failing service test**

Add a test that creates an unscheduled, non-batch `assigned` leg for the same shop, calls `schedule($shop, '2026-07-20', 'morning', [$leg->id])`, and asserts the date, window, `scheduled` status, and `estimated_at`. Add explicit service rejection cases for foreign-tenant, batched, already-scheduled, and invalid-status legs. Add API cases proving a user without dispatcher permissions is forbidden and a past date is rejected.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php --filter=assigned_unscheduled`

Expected: FAIL with `One or more deliveries cannot be scheduled.`

- [ ] **Step 3: Implement the minimum eligibility change**

In `BatchDispatchService::schedule`, replace the pending-only condition with:

```php
|| !in_array($leg->status->value, ['pending', 'assigned'], true)
```

Retain tenant, batch, duplicate schedule, operating-day, and blackout validation.

- [ ] **Step 4: Run the focused and full service tests**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php`

Expected: PASS.

### Task 2: Gate failed attempts after delivery starts

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php:158-175`
- Modify: `app/Services/Logistics/ShipmentLegService.php:213-228` without overwriting existing user changes in that method

- [ ] **Step 1: Replace the broad allowed-status API test with failing transition tests**

Test `assigned` and `picked_up` individually: submit valid fake photos, expect HTTP 422, zero attempts, and no stored files. Test `in_transit` and `delivery_attempted`: expect HTTP 201. Change `test_assigned_rider_can_report_a_delivery_issue_with_a_customer_safe_event` to use `in_transit`. Preserve existing unauthorized-rider, shop-owner, and dispatcher tests, and add a rider assigned to a leg belonging to another tenant to prove cross-tenant reporting is forbidden.

- [ ] **Step 2: Add a failing upload-cleanup race test**

Use `Storage::fake('public')`, arrange for the locked service check to reject after upload (by changing the leg status between the controller precheck and service operation through a test-bound service double or model event), then assert HTTP 422, zero attempts, and `Storage::disk('public')->allFiles()` is empty.

- [ ] **Step 3: Run the focused API tests and verify RED**

Run: `php artisan test tests/Feature/Logistics/LogisticsApiTest.php --filter=report_issue`

Expected: assigned/picked-up requests are incorrectly created before implementation.

- [ ] **Step 4: Implement matching controller and locked-service gates**

Before storing the file, reject unless the current status is `in_transit` or `delivery_attempted`. Change the non-opt-in allowed set in `recordFailedAttempt` to the same two statuses; retain the existing `true` opt-in used by current batch execution tests. Call `recordFailedAttempt` without `true` from `reportIssue`.

Wrap the service call so a thrown exception deletes `$payload['file_path']` from the public disk before rethrowing. Do not change authorization behavior.

- [ ] **Step 5: Run API and delivery execution tests**

Run: `php artisan test tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php`

Expected: PASS, including the user's existing batch failure tests.

### Task 3: Add single-delivery scheduling UI and hide premature issue controls

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx:71-156,278-340`
- Modify: `resources/js/types/logistics.ts:11-45`

- [ ] **Step 1: Refactor only the test fixture to support per-test props**

Keep a hoisted mutable props object returned by `usePage`. Reset it in `beforeEach` so tests can render dispatcher `pending`, dispatcher `assigned`, and rider statuses without duplicating the component mock. Add `delivery_batch_id?: number | null` to `TrackingShipmentLeg` so the production eligibility check is typed.

- [ ] **Step 2: Write failing scheduling UI tests**

Assert an eligible unscheduled pending leg shows `Delivery date`, `Delivery window`, and `Schedule & assign rider`; entering values posts `/api/logistics/legs/schedule` before `/api/logistics/legs/{id}/assign`. Assert an assigned unscheduled leg shows `Save schedule`. Simulate schedule success plus assignment failure, assert reload, mutate the mocked leg props to scheduled, rerender, then assert the retry is assign-only and does not resubmit scheduling.

- [ ] **Step 3: Write failing visibility tests**

Assert `Failed delivery attempt`/`Issue reason` are absent for `assigned` and `picked_up`, and available for `in_transit`.

- [ ] **Step 4: Run the component test and verify RED**

Run: `pnpm test:frontend resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

Expected: scheduling controls are missing and picked-up still renders failed-attempt controls.

- [ ] **Step 5: Implement minimal local state and actions**

Add per-leg schedule state `{ date, window }`, defaulting window to `morning`. Render native `input type="date"` and a morning/afternoon select only when `canAssign`, no batch is attached, the leg is unscheduled, and status is `pending` or `assigned`.

For an unassigned leg, require rider/date/window and post scheduling first, then assignment. For an assigned leg, post scheduling only. On partial success, reload `shipments` and `assignableRiders`; preserve existing toast/error handling. Change `canReportIssue` to only `in_transit|delivery_attempted`.

- [ ] **Step 6: Run frontend tests and build**

Run: `pnpm test:frontend resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

Run: `pnpm exec vite build --outDir .codex-vite-build --emptyOutDir`

Expected: PASS and build exit 0 without changing `public/build`. Remove only the verified `C:\xampp\htdocs\solespace-master\.codex-vite-build` directory afterward.

### Task 4: Verify the complete change

**Files:** No new production files.

- [ ] **Step 1: Run all focused backend tests**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/CustomerTrackingTest.php`

Expected: PASS.

- [ ] **Step 2: Run the focused frontend suite and build again**

Run: `pnpm test:frontend resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

Run: `pnpm exec vite build --outDir .codex-vite-build --emptyOutDir`

Expected: PASS and build exit 0 without changing `public/build`; remove only the verified temporary build directory afterward.

- [ ] **Step 3: Review the final diff**

Run: `git diff --check` and `git diff -- app/Services/Logistics/BatchDispatchService.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/Logistics/ShipmentLegService.php resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/types/logistics.ts tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

Expected: no whitespace errors; existing unrelated/user changes remain intact.
