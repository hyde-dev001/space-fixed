# Immutable Batch Stop History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Persist an immutable ordered stop snapshot so completed and cancelled Batch History never loses deliveries when live legs move or detach.

**Architecture:** A small concrete serializer owns the stable JSON contract used by both the migration and batch service. Draft mutations refresh `delivery_batches.stop_snapshot`; offer freezes it. History UI selects the first non-empty persisted source and never derives historical rows or counts from mutable live membership.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL/SQLite-compatible migrations, React, TypeScript, Vitest, Testing Library

---

### Task 1: Snapshot schema, serializer, and legacy backfill

**Files:**
- Create: `app/Support/Logistics/BatchStopSnapshot.php`
- Create: `database/migrations/2026_07_17_000002_add_stop_snapshot_to_delivery_batches.php`
- Modify: `app/Models/Logistics/DeliveryBatch.php`
- Create: `tests/Feature/Logistics/DeliveryBatchStopSnapshotMigrationTest.php`

- [x] **Step 1: Write the failing serializer/backfill test**

Create legacy batches covering non-empty `cancelled_stops` and linked live legs across Draft, Offered, Accepted, In-Progress, Completed, and Cancelled statuses. Verify the migration normalizes/backfills ordered entries with exactly the approved keys and prefers `cancelled_stops` over live legs.

Use `DatabaseMigrations`. After the normal test migration pass, seed legacy rows with `stop_snapshot = null`, drop only `stop_snapshot` with `Schema::table`, load the target anonymous migration with `require`, and invoke `up()`. This recreates the pre-migration schema/data boundary while leaving teardown able to roll migrations back normally.

- [x] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchStopSnapshotMigrationTest.php`

Expected: FAIL because `stop_snapshot`, its serializer, and migration do not exist.

- [x] **Step 3: Add the stable serializer**

Implement `BatchStopSnapshot` as a final static helper with:

```php
public static function fromLegs(iterable $legs): array;
public static function normalize(array $stops): array;
```

Both methods return stops ordered by `stop_sequence` then `id` with only:

```php
[
    'id', 'sequence', 'leg_type', 'status',
    'origin_snapshot', 'destination_snapshot',
    'scheduled_delivery_date', 'delivery_window', 'schedule_status',
    'stop_sequence', 'urgent_at',
    'shipment' => ['id', 'source_type', 'source_id'],
]
```

Normalize enum statuses to strings, dates to `Y-m-d`, timestamps to ISO-8601, and missing nullable fields to null.

- [x] **Step 4: Add and backfill the column**

The migration adds nullable JSON `stop_snapshot`, then chunks batches with live `legs.shipment`. For each batch, normalize the first non-empty source (`cancelled_stops`, then live legs) and update the JSON column. `down()` drops only `stop_snapshot`.

Add `stop_snapshot` to model fillable/casts.

- [x] **Step 5: Run the migration test and verify GREEN**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchStopSnapshotMigrationTest.php`

Expected: PASS.

- [x] **Step 6: Commit Task 1**

```bash
git add app/Support/Logistics/BatchStopSnapshot.php app/Models/Logistics/DeliveryBatch.php database/migrations/2026_07_17_000002_add_stop_snapshot_to_delivery_batches.php tests/Feature/Logistics/DeliveryBatchStopSnapshotMigrationTest.php
git commit -m "feat: persist immutable batch stop snapshots"
```

### Task 2: Synchronize the Draft lifecycle and freeze on offer

**Files:**
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Modify: `tests/Feature/Logistics/BatchDispatchServiceTest.php`

- [x] **Step 1: Write failing lifecycle tests**

Add focused tests proving:

- create, reorder, remove, and Draft urgency changes refresh ordered `stop_snapshot`;
- Draft urgency change followed immediately by cancellation retains the updated urgency in the frozen snapshot;
- offer performs the final refresh;
- later live-leg status/batch changes and cancellation do not rewrite it;
- reject → Draft edit → re-offer intentionally refreshes it;
- restore selects non-empty `stop_snapshot` before `cancelled_stops`, falls back only for null/empty preferred data, and remains all-or-nothing on conflicts;
- successful restore reattaches all stops and refreshes the Draft snapshot.

- [x] **Step 2: Run the service tests and verify RED**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php`

Expected: FAIL because service mutations do not maintain `stop_snapshot`.

- [x] **Step 3: Add one private synchronization method**

In `BatchDispatchService` add one method that loads ordered `legs.shipment`, serializes them with `BatchStopSnapshot::fromLegs()`, and updates `stop_snapshot`. Call it inside existing transactions after create/reorder/remove and immediately before offer changes status.

Refactor `markUrgent()` into a transaction. Lock the owning batch first (when present), then lock the leg, matching offer's batch→leg lock order. Update urgency and, only when the locked batch is still Draft, refresh its snapshot in the same transaction. This prevents offer and urgency updates from freezing stale data.

Do not call it from accept, start, complete, cancel, failed-attempt, retry, or re-batching flows.

- [x] **Step 4: Update restore source selection**

Choose IDs from the first non-empty `stop_snapshot`, then `cancelled_stops`. Validate only that chosen set; do not fall through after a validation error. Reattach all stops or none, then refresh the Draft snapshot and retain existing cancellation-field cleanup.

- [x] **Step 5: Run service tests and verify GREEN**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php`

Expected: PASS.

- [x] **Step 6: Commit Task 2**

```bash
git add app/Services/Logistics/BatchDispatchService.php tests/Feature/Logistics/BatchDispatchServiceTest.php
git commit -m "fix: freeze batch stop history after offer"
```

### Task 3: Render immutable History rows and counts

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`

- [x] **Step 1: Write failing History UI tests**

Add tests for completed and cancelled batches with empty live `legs` and populated `stop_snapshot`. Assert saved order rows, snapshot stop count, urgency count, and row totals. Add fallback tests for `cancelled_stops`, then live legs. Assert both the expanded card and workspace show `Historical stop details unavailable` only when all three sources are empty.

Add a backend Inertia assertion that `/erp/logistics/batches` returns the batch's `stop_snapshot` unchanged to the page prop.

- [x] **Step 2: Run UI tests and verify RED**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php`

Expected: FAIL because the UI does not know `stop_snapshot` or use it for History.

- [x] **Step 3: Add the TypeScript field and source selection**

Add `stop_snapshot?: TrackingShipmentLeg[] | null` to `DeliveryBatch`.

In both History components, choose the first non-empty array:

```tsx
const historyLegs = batch.stop_snapshot?.length
  ? batch.stop_snapshot
  : batch.cancelled_stops?.length
    ? batch.cancelled_stops
    : batch.legs;
const legs = ['completed', 'cancelled'].includes(batch.status) ? historyLegs : batch.legs;
```

Use `legs` for displayed stop count, urgent count, row total, and row mapping. Active batches continue using live legs.

- [x] **Step 4: Add the explicit unavailable state**

When a History batch's selected `legs` is empty, both `BatchCard` and `BatchWorkspace` render `Historical stop details unavailable`.

- [x] **Step 5: Run UI tests and verify GREEN**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: PASS.

- [x] **Step 6: Commit Task 3**

```bash
git add resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/components/BatchCard.tsx resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "fix: render immutable batch history snapshots"
```

### Task 4: Full verification

**Files:**
- Modify: `docs/superpowers/plans/2026-07-17-immutable-batch-stop-history.md` (check completed steps)

- [x] **Step 1: Run focused backend verification**

Run: `php artisan test tests/Feature/Logistics/DeliveryBatchStopSnapshotMigrationTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php`

Expected: PASS.

- [x] **Step 2: Run full logistics verification**

Run: `php artisan test tests/Feature/Logistics`

Expected: PASS.

- [x] **Step 3: Run frontend and build verification**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Run: `npm run build`

Expected: PASS and successful Vite production build.

- [x] **Step 4: Check committed scope**

Run: `git diff --check` and `git status --short`.

Do not commit generated `public/build`, dependency-install changes, or cache files.

- [x] **Step 5: Commit plan completion only if changed**

```bash
git add docs/superpowers/plans/2026-07-17-immutable-batch-stop-history.md
git commit -m "docs: complete immutable batch history plan"
```
