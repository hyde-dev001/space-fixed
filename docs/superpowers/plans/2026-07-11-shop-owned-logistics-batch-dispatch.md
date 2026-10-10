# Shop-Owned Logistics Batch Dispatch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let dispatchers group scheduled shop-owned deliveries into capacity-aware batches, offer them to eligible riders, and let riders accept, reject, and start only their assigned runs.

**Architecture:** Extend the existing shipment-leg and assignment flow with one tenant-owned `delivery_batches` aggregate. Keep route suggestions deterministic and local with a nearest-neighbour calculation; all batch, assignment, acceptance, and start mutations lock the batch and affected legs and detect repeated requests from current state. Existing individual assignment remains available for urgent work, and third-party legs never enter this workflow.

**Tech Stack:** Laravel 12, PHP 8.2+, Eloquent/MySQL, Inertia React/TypeScript, PHPUnit, Vitest

**Design spec:** `docs/superpowers/specs/2026-07-11-shop-owned-logistics-production-design.md`

**Depends on:** `docs/superpowers/plans/2026-07-11-shop-owned-logistics-scheduling-foundation.md`

---

## File structure

- Create `database/migrations/2026_07_11_000006_create_delivery_batches_and_extend_dispatch.php` — batches, batch/stop ordering fields, rider work schedule, and leave/capacity data.
- Create `app/Models/Logistics/DeliveryBatch.php` — batch casts and relations.
- Create `database/factories/Logistics/DeliveryBatchFactory.php` — focused batch fixtures.
- Create `app/Services/Logistics/BatchSuggestionService.php` — eligible-leg grouping and nearest-neighbour stop ordering.
- Create `app/Services/Logistics/BatchDispatchService.php` — transactional draft editing, offering, acceptance/rejection, reassignment, and start transitions.
- Create `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php` — tenant-scoped dispatcher/rider endpoints.
- Create `resources/js/Pages/ERP/Logistics/Batches.tsx` — dispatcher batch workspace.
- Modify `app/Models/Logistics/ShipmentLeg.php` — batch relation and dispatch fields.
- Modify `app/Models/Logistics/RiderProfile.php` — batch relation, schedule/leave/capacity casts.
- Modify `app/Models/Logistics/DeliveryAssignment.php` — rejection metadata and active-assignment history.
- Modify `app/Services/Logistics/AssignmentService.php` — reuse one locked assignment path for manual and batch work.
- Modify `app/Http/Controllers/Logistics/ErpLogisticsController.php` — dispatch-pool, batches, and rider workload props.
- Modify `routes/web.php` — batch API and page routes.
- Modify `database/seeders/RolesAndPermissionsSeeder.php` — schedule/batch and rider-operation capabilities.
- Modify `resources/js/services/logisticsApi.ts` and `resources/js/types/logistics.ts` — batch API/types.
- Modify `resources/js/Pages/ERP/Logistics/Shipments.tsx` — urgent/manual assignment and pool links.
- Modify `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx` — offered/accepted batch actions and ordered stops.
- Test with focused files under `tests/Feature/Logistics` and one Vitest page test; do not create repositories, DTOs, a routing provider, or a map dependency.

No retry automation, incidents, custody return flow, overdue job, forecasting, live GPS, OTP, COD, or third-party dispatch behavior belongs in this plan.

### Task 1: Persist batches and dispatch constraints

**Files:**
- Create: `database/migrations/2026_07_11_000006_create_delivery_batches_and_extend_dispatch.php`
- Create: `app/Models/Logistics/DeliveryBatch.php`
- Create: `database/factories/Logistics/DeliveryBatchFactory.php`
- Modify: `app/Models/Logistics/ShipmentLeg.php`
- Modify: `app/Models/Logistics/RiderProfile.php`
- Modify: `app/Models/Logistics/DeliveryAssignment.php`
- Test: `tests/Feature/Logistics/DeliveryBatchSchemaTest.php`

- [ ] **Step 1: Write the failing schema/model test**

Assert `delivery_batches` contains tenant/rider, date/window, status, capacity, stop count, offer/accept/reject/start/complete/cancel timestamps, rejection reason, and dispatcher override reason. Assert shipment legs contain nullable `delivery_batch_id`, `stop_sequence`, `urgent_at`, and the Phase 1 `schedule_override_reason`; rider profiles contain nullable `work_days`, `leave_dates`, and `daily_capacity`; assignments contain `rejection_reason` and `rejected_at`. Leave attempt numbering and individual out-for-delivery timestamps to the delivery-execution phase.

- [ ] **Step 2: Verify failure**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchSchemaTest.php`

Expected: FAIL because `delivery_batches` and the new columns do not exist.

- [ ] **Step 3: Add the minimal schema and relations**

Use string statuses `draft`, `offered`, `accepted`, `in_progress`, `completed`, `cancelled`; do not add another enum layer. Add indexes on `(shop_owner_id, delivery_date, delivery_window, status)`, `(rider_profile_id, delivery_date, status)`, and `(delivery_batch_id, stop_sequence)`. Add a unique active-batch guard suitable for the project database on each leg; if a partial unique index is unavailable, enforce it with the locked service path and the single nullable `delivery_batch_id` foreign key.

- [ ] **Step 4: Verify the schema**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchSchemaTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_11_000006_create_delivery_batches_and_extend_dispatch.php app/Models/Logistics/DeliveryBatch.php database/factories/Logistics/DeliveryBatchFactory.php app/Models/Logistics/ShipmentLeg.php app/Models/Logistics/RiderProfile.php app/Models/Logistics/DeliveryAssignment.php tests/Feature/Logistics/DeliveryBatchSchemaTest.php
git commit -m "feat: persist logistics delivery batches"
```

### Task 2: Suggest eligible batches and stop order

**Files:**
- Create: `app/Services/Logistics/BatchSuggestionService.php`
- Test: `tests/Feature/Logistics/BatchSuggestionServiceTest.php`

- [ ] **Step 1: Write failing suggestion tests**

Cover same-shop scheduled pending legs grouped by date/window; exclusion of batched, cancelled, delivered, outside-coverage, missing-coordinate, and third-party legs; daily capacity shared across morning/afternoon and existing manual assignments; unavailable/on-leave/off-schedule riders; deterministic proximity ordering; and urgent legs appearing first without silently exceeding capacity.

- [ ] **Step 2: Verify failure**

Run: `php artisan test tests/Feature/Logistics/BatchSuggestionServiceTest.php`

Expected: FAIL because `BatchSuggestionService` does not exist.

- [ ] **Step 3: Implement one deterministic service API**

```php
public function suggest(ShopOwner $shop, CarbonInterface $date, string $window): array
```

Return candidate riders with `rider_profile_id`, `capacity`, `assigned_count`, `overload_count`, and ordered `leg_ids`. Start from the shop coordinates saved by Phase 1, repeatedly select the nearest remaining destination with the existing Haversine calculation, and break equal distances by leg ID. Return suggestions only; do not persist drafts or promise road-aware routing.

- [ ] **Step 4: Verify suggestions**

Run: `php artisan test tests/Feature/Logistics/BatchSuggestionServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/BatchSuggestionService.php tests/Feature/Logistics/BatchSuggestionServiceTest.php
git commit -m "feat: suggest delivery batches"
```

### Task 3: Create and edit locked draft batches

**Files:**
- Create: `app/Services/Logistics/BatchDispatchService.php`
- Test: `tests/Feature/Logistics/BatchDispatchServiceTest.php`

- [ ] **Step 1: Write failing draft mutation tests**

Assert a dispatcher can create a draft from eligible legs, reorder stops, move a leg between drafts, mark a leg urgent, and delete an empty draft. Assert duplicate submissions return the existing state, cross-tenant/third-party/ineligible legs fail, and concurrent attempts cannot place one leg in two batches. Assert capacity overload and schedule override each require a non-empty reason.

- [ ] **Step 2: Verify failure**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php --filter=draft`

Expected: FAIL because draft mutations do not exist.

- [ ] **Step 3: Implement minimal locked draft mutations**

Expose `createDraft()`, `replaceStops()`, `removeStop()`, and `markUrgent()`. `replaceStops()` handles moves and reordering; `removeStop()` deletes the draft when its last stop is removed. In each transaction lock the shop row first, then batches by ascending ID, then legs by ascending ID to keep lock order stable. Re-read status and tenant after locking. Store contiguous one-based stop sequences and derive `assigned_stop_count` from persisted legs. Treat an identical requested state as success; return HTTP 409 later for a stale incompatible state.

- [ ] **Step 4: Verify draft mutations and concurrency**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php --filter=draft`

Expected: PASS, including the separate-connection concurrency case.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/BatchDispatchService.php tests/Feature/Logistics/BatchDispatchServiceTest.php
git commit -m "feat: manage draft delivery batches"
```

### Task 4: Reuse assignment history when offering batches

**Files:**
- Modify: `app/Services/Logistics/AssignmentService.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Test: `tests/Feature/Logistics/AssignmentServiceTest.php`
- Test: `tests/Feature/Logistics/BatchDispatchServiceTest.php`

- [ ] **Step 1: Write failing offer/reassignment tests**

Assert offering a draft validates current availability, work day, leave, shared daily capacity, and tenant; creates one active assignment per leg through `AssignmentService`; marks the batch `offered`; and records offer timestamps/events once. Assert manual urgent assignment uses the same eligibility/capacity rules. Assert replacement cancels prior active assignment history rather than overwriting it and requires a reason after an offer.

- [ ] **Step 2: Verify failure**

Run: `php artisan test tests/Feature/Logistics/AssignmentServiceTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php --filter=offer`

Expected: FAIL because assignments have no batch offer path or shared capacity check.

- [ ] **Step 3: Extend the existing assignment service**

Add a locked internal method used by both `assignInternalRider()` and batch offering. Do not create a second assignment model/service. Count active assignments once for the whole date: batch-backed assignments through their legs plus active assignments whose legs have no batch. Do not separately add batch stop counts after their assignments exist. An authorised overload proceeds only with the supplied reason, persisted on the batch/leg and in the internal event metadata.

- [ ] **Step 4: Verify assignment regressions**

Run: `php artisan test tests/Feature/Logistics/AssignmentServiceTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/AssignmentService.php app/Services/Logistics/BatchDispatchService.php tests/Feature/Logistics/AssignmentServiceTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php
git commit -m "feat: offer batches through rider assignments"
```

### Task 5: Accept, reject, start, and cancel batches

**Files:**
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Test: `tests/Feature/Logistics/BatchDispatchServiceTest.php`

- [ ] **Step 1: Write failing rider transition tests**

Assert only the linked assigned rider can accept/reject an offered batch. Acceptance marks all active assignments accepted exactly once. Rejection requires a reason, marks assignments rejected, clears the batch rider, returns the batch to `draft` and its legs to dispatcher attention without deleting history, and emits one internal alert. Starting requires accepted status, marks only the batch `in_progress`, and does not mark every leg `in_transit` or out for delivery. Dispatcher cancellation requires a reason, is allowed only from `draft`, `offered`, or `accepted`, cancels active assignments, detaches legs back to the dispatch pool, preserves history, and is idempotent for the same reason. An `in_progress` batch cannot be cancelled in Phase 2; post-start cancellation belongs to the custody/incident phase.

- [ ] **Step 2: Verify failure**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php --filter='accept|reject|start|cancel'`

Expected: FAIL because rider batch transitions do not exist.

- [ ] **Step 3: Add transactional state transitions**

Lock the batch, assignments, and legs; validate the actor and current status after locking. Repeated identical acceptance/start/cancellation returns the current batch. A repeated rejection or cancellation with the same reason returns current state; a different stale action raises a conflict. Rejection resets the batch to `draft`; cancellation from `draft`, `offered`, or `accepted` sets `cancelled` and removes the batch reference from its legs so they can be dispatched again. Reject cancellation from `in_progress`; the later custody/incident phase owns that transition. Record internal `batch_accepted`, `batch_rejected`, `batch_started`, and `batch_cancelled` events, with no customer-visible rider/batch details.

- [ ] **Step 4: Verify transitions and duplicate requests**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/BatchDispatchService.php tests/Feature/Logistics/BatchDispatchServiceTest.php
git commit -m "feat: operate assigned delivery batches"
```

### Task 6: Expose tenant-scoped batch APIs and permissions

**Files:**
- Create: `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php`
- Modify: `routes/web.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Test: `tests/Feature/Logistics/DeliveryBatchApiTest.php`
- Test: `tests/Feature/Logistics/LogisticsSeederTest.php`

- [ ] **Step 1: Write failing API/authorization tests**

Cover list/suggest/create/update/offer/reassign/cancel dispatcher actions and accept/reject/start rider actions. Assert tenant isolation, permissions, validation, 409 stale-state responses, and that rider list/show payloads expose only their offered or assigned batches. Assert customer instructions needed to evaluate an offer omit the phone number; the phone appears only after the linked rider's assignment is `accepted`, and every accepted-rider phone access is audited.

- [ ] **Step 2: Verify failure**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/LogisticsSeederTest.php`

Expected: FAIL because routes and capabilities do not exist.

- [ ] **Step 3: Add thin controller endpoints**

Add `manage-logistics-batches` and `operate-assigned-batches`; retain existing `assign-logistics-deliveries` for individual assignment. Validate scalar/array payloads in the controller, call `BatchSuggestionService`/`BatchDispatchService`, and map the service's stale-state exception to 409. Do not duplicate business rules in request handlers or UI guards.

- [ ] **Step 4: Verify APIs and existing logistics authorization**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php tests/Feature/Logistics/LogisticsSeederTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Logistics/DeliveryBatchController.php routes/web.php database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/LogisticsSeederTest.php
git commit -m "feat: add delivery batch endpoints"
```

### Task 7: Add the dispatcher batch workspace

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/services/logisticsApi.ts`
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write failing page and component tests**

Assert authorised dispatchers can load the page and other riders/tenants cannot. Render pool groups, suggested stops, capacity/overload, urgent state, draft editing, reorder buttons, rider selection, required override reason, offer, and stale-conflict refresh messaging. Use buttons for stop movement/reordering; drag-and-drop is not required.

- [ ] **Step 2: Verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=batch`

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL because the route/page do not exist.

- [ ] **Step 3: Build the minimal workspace**

Render one page with status tabs (`draft`, `offered`, `accepted`, `in_progress`, `completed`) and a dispatch-pool panel. Reuse existing table/card, Axios, Inertia, and SweetAlert patterns. Link the existing Shipments page to the pool and retain its manual assignment action for urgent/exception work.

- [ ] **Step 4: Verify backend and frontend**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=batch`

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/Batches.tsx app/Http/Controllers/Logistics/ErpLogisticsController.php routes/web.php resources/js/services/logisticsApi.ts resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/Shipments.tsx tests/Feature/Logistics/LogisticsPageAccessTest.php resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: add logistics batch workspace"
```

### Task 8: Add the rider batch view

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/services/logisticsApi.ts`
- Modify: `resources/js/types/logistics.ts`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

- [ ] **Step 1: Write failing rider UI tests**

Assert offered batches show accept/reject, rejection requires a reason, accepted batches show ordered stops and delivery instructions, and start is available only after acceptance. Assert the page does not expose other riders' batches or dispatcher-only notes/actions and starting does not change individual leg status in the client.

- [ ] **Step 2: Verify failure**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

Expected: FAIL because the page has no batch controls.

- [ ] **Step 3: Extend the existing rider page**

Add a compact batch section above existing individual deliveries. Use the batch API, existing confirmation/toast pattern, and server-returned ordered stops. Keep customer phone hidden until the backend identifies the current user as the accepted assigned rider.

- [ ] **Step 4: Verify the component and build**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

Run: `npm run build`

Expected: PASS with no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/services/logisticsApi.ts resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
git commit -m "feat: add rider batch controls"
```

### Task 9: Verify Phase 2 independently

**Files:**
- Verify only; fix failures in files already listed above.

- [ ] **Step 1: Run the batch and assignment suite**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchSchemaTest.php tests/Feature/Logistics/BatchSuggestionServiceTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/AssignmentServiceTest.php`

Expected: PASS.

- [ ] **Step 2: Run all logistics regressions**

Run: `php artisan test tests/Feature/Logistics`

Expected: PASS, including existing proof, cancellation, tracking, tenant, and third-party behavior.

- [ ] **Step 3: Run focused frontend tests and production build**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

Run: `npm run build`

Expected: PASS.

- [ ] **Step 4: Inspect the diff**

Run: `git diff --check`

Expected: no whitespace errors.

Run: `git status --short`

Expected: only intended Phase 2 files plus pre-existing user changes.

- [ ] **Step 5: Commit verification-only corrections**

```bash
git add <only-files-corrected-during-verification>
git commit -m "test: verify logistics batch dispatch"
```
