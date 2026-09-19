# Batch Rejection and Rider Capacity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make rider rejections visible and actionable to dispatchers, and enforce cumulative same-date rider capacity with an auditable override.

**Architecture:** Keep rejected batches as editable drafts and use the existing delivery-event pipeline for dispatcher notifications and immutable audit metadata. Calculate rider workload from same-date batches on both server and client; the client explains projected usage while `BatchDispatchService` remains authoritative inside its transaction.

**Tech Stack:** Laravel 12/PHP 8.2, Eloquent, PHPUnit, React 18, TypeScript, Inertia, Vitest/Testing Library.

---

### Task 1: Rejection state and dispatcher notification

**Files:**
- Modify: `tests/Feature/Logistics/BatchDispatchServiceTest.php`
- Modify: `tests/Feature/Logistics/LogisticsNotificationTest.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Modify: `app/Services/Logistics/LogisticsNotificationService.php`
- Modify: `app/Enums/NotificationType.php`

- [ ] **Step 1: Write failing service and notification tests**

Extend the rejection service test to assert `draft`, `rejection_reason`, `rejected_at`, and cleared rider assignment. Add a test that seeds the Logistics Dispatcher role, rejects a batch, and asserts one notification containing:

```php
[
    'type' => 'logistics_batch_rejected',
    'title' => 'Batch Offer Rejected',
    'action_url' => '/erp/logistics/batches',
    'requires_action' => true,
]
```

Assert the notification `data` contains `delivery_batch_id` and `rejection_reason`. Load the created `batch_rejected` event, call `LogisticsNotificationService::notifyForEvent($event)` again, and assert the same event still has only one notification. Re-offer and reject again, then assert two rejection notifications exist so separate rejection events are not deduplicated together.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
php vendor/bin/phpunit tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/LogisticsNotificationTest.php --filter="rejection|rejected" --display-warnings
```

Expected: FAIL because no `logistics_batch_rejected` notification type/mapping exists and re-offer does not clear stale rejection fields.

- [ ] **Step 3: Implement the minimal rejection flow**

Add `LOGISTICS_BATCH_REJECTED`, label it `Batch Offer Rejected`, and include it in the logistics notification category. When rejecting, include the rider ID, rider name, and reason in `batch_rejected` event metadata. In `LogisticsNotificationService`, allow and map `batch_rejected`, then call `notifyDispatchers` with a rejection-specific message, `/erp/logistics/batches`, and the batch/reason data.

For rejection notifications only, include `$event->id` in the group key so repeat delivery of one event stays idempotent while a later rejection produces a new notification. On successful re-offer, clear:

```php
'rejection_reason' => null,
'rejected_at' => null,
```

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Enums/NotificationType.php app/Services/Logistics/BatchDispatchService.php app/Services/Logistics/LogisticsNotificationService.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/LogisticsNotificationTest.php
git commit -m "fix: surface rejected batch offers"
```

### Task 2: Server-authoritative cumulative capacity

**Files:**
- Modify: `tests/Feature/Logistics/BatchDispatchServiceTest.php`
- Modify: `tests/Feature/Logistics/DeliveryBatchApiTest.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Modify: `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php`
- Modify: `resources/js/services/logisticsApi.ts`

- [ ] **Step 1: Write failing cumulative-capacity tests**

Create a rider with `daily_capacity = null` and shop `daily_rider_capacity = 6`. Give that rider a five-stop same-date `in_progress` batch in the afternoon, then attempt to offer a two-stop morning batch.

Assert the offer without a reason throws `ValidationException` on `capacity_override_reason`. Assert draft/cancelled batches do not add usage. Retry with `Operational priority`, assert the offer succeeds, and assert the new `batch_offered` event metadata includes:

```php
[
    'existing_stop_count' => 5,
    'offered_stop_count' => 2,
    'projected_stop_count' => 7,
    'daily_capacity' => 6,
    'capacity_override_reason' => 'Operational priority',
]
```

Add an API test proving `capacity_override_reason` accepts a nullable string up to 1000 characters and reaches the service behavior.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
php vendor/bin/phpunit tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php --filter="capacity|override" --display-warnings
```

Expected: FAIL because `offer()` does not aggregate workload or accept an override.

- [ ] **Step 3: Implement the authoritative capacity check**

Change the service signature to:

```php
public function offer(DeliveryBatch $batch, RiderProfile $rider, ShopOwner $actor, ?string $capacityOverrideReason = null): DeliveryBatch
```

Inside the existing transaction, lock the rider, resolve capacity with `$rider->daily_capacity ?? $actor->logisticsSetting()->firstOrCreate([])->daily_rider_capacity`, and sum `assigned_stop_count` from other batches with the same `delivery_date`, rider ID, and statuses `offered`, `accepted`, `in_progress`, or `completed`. Reject projected usage above capacity unless the trimmed override is filled. Record the workload and reason in `batch_offered` metadata.

Validate and forward this controller input:

```php
'capacity_override_reason' => ['nullable', 'string', 'max:1000'],
```

Update `logisticsApi.offerBatch` to send the optional reason.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Api/Logistics/DeliveryBatchController.php app/Services/Logistics/BatchDispatchService.php resources/js/services/logisticsApi.ts tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php
git commit -m "fix: enforce cumulative rider capacity"
```

### Task 3: Same-date capacity visibility and override UI

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx`
- Modify: `resources/js/types/logistics.ts`

- [ ] **Step 1: Write failing modal tests**

Provide an existing five-stop afternoon batch assigned to the selected rider and a two-stop morning draft on the same date. Assert the rider option shows `5/6 used today`, and after selection the modal shows `5 used + 2 stops = 7/6`. Assert an override textarea appears, offering is disabled while empty, and submitting `Operational priority` sends:

```typescript
expect(logisticsApi.offerBatch).toHaveBeenCalledWith(batchId, riderId, 'Operational priority');
```

Also assert a batch on another date does not count and a null rider capacity falls back to `dailyRiderCapacity`.

- [ ] **Step 2: Run the frontend test and verify RED**

Run:

```powershell
& '.\node_modules\.bin\vitest.cmd' run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL because the modal only compares the candidate batch to the rider-specific capacity.

- [ ] **Step 3: Implement the minimal modal calculation**

Pass `batches` and `dailyRiderCapacity` to `OfferBatchModal`. For each rider, sum same-date `assigned_stop_count` across both windows for `offered`, `accepted`, `in_progress`, and `completed` batches, excluding the candidate batch. Resolve the limit from `rider.daily_capacity ?? dailyRiderCapacity`.

Display usage in each option and a selected-rider summary. Only show the override textarea when projected usage exceeds the limit; require trimmed content before enabling the offer button. Reset rider and override state whenever the modal opens. Forward the reason through `Batches.offerBatch` and keep the modal open on API failure.

- [ ] **Step 4: Run the frontend test and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/types/logistics.ts
git commit -m "fix: show projected rider capacity"
```

### Task 4: Rejected-draft banner

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- Modify: `resources/js/types/logistics.ts`

- [ ] **Step 1: Write failing banner tests**

Render a draft batch with `rejection_reason = 'Vehicle unavailable'` and `rejected_at`. Assert both the active batch card and opened workspace show `Rejected by rider`, the reason, and a formatted rejection time. Assert an ordinary draft without a reason has no rejection banner.

- [ ] **Step 2: Run the frontend test and verify RED**

Run the Task 3 Step 2 command. Expected: FAIL because neither component renders rejection details.

- [ ] **Step 3: Implement the banner**

Add `rejected_at?: string | null` to `DeliveryBatch`. Render the same compact red alert in `BatchCard` and `BatchWorkspace` when `batch.status === 'draft' && batch.rejection_reason`; include the reason and the formatted `rejected_at` when present. Keep existing edit and re-offer actions available.

- [ ] **Step 4: Run the frontend test and verify GREEN**

Run the Task 3 Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add resources/js/Pages/ERP/Logistics/components/BatchCard.tsx resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/types/logistics.ts
git commit -m "fix: display rider rejection on draft batches"
```

### Task 5: Full regression verification

**Files:**
- Verify only; do not modify generated assets in the main workspace.

- [ ] **Step 1: Run all logistics backend tests**

```powershell
php vendor/bin/phpunit tests/Feature/Logistics --display-warnings
```

Expected: all tests PASS with zero failures.

- [ ] **Step 2: Run all logistics frontend tests**

```powershell
& '.\node_modules\.bin\vitest.cmd' run resources/js/Pages/ERP/Logistics/__tests__
```

Expected: all test files PASS with zero failures.

- [ ] **Step 3: Run a production build outside tracked output**

```powershell
$outDir = Join-Path $env:TEMP 'solespace-batch-rejection-capacity-build'
npm run build -- --outDir $outDir
```

Expected: Vite exits 0 and writes through Vite's supported `--outDir` CLI override. Confirm `git status` contains no generated build changes from this worktree.

- [ ] **Step 4: Check the branch diff and status**

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors and no uncommitted source changes.
