# Rider Batch Bulk Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let riders select eligible stops within one in-progress batch and bulk-mark them picked up or in transit with confirmation and partial-result feedback.

**Architecture:** Keep status transitions on the existing secured per-stop endpoints. Add the missing in-progress guard to batched pickup, then orchestrate the small set of per-stop requests in `MyDeliveries` with per-batch selection and `Promise.allSettled`.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit, React 18, TypeScript, Inertia, Axios, SweetAlert2, Vitest, Testing Library.

---

## Isolated worktree setup

Do not junction dependencies to the main checkout. Prepare private worktree dependencies:

```powershell
Copy-Item -LiteralPath 'C:\xampp\htdocs\solespace-master\vendor' -Destination '.\vendor' -Recurse
pnpm install --offline --frozen-lockfile
```

If the local pnpm store lacks a package, rerun `pnpm install --frozen-lockfile` with network approval. Neither dependency directory is committed.

Run the baseline checks:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php vendor/bin/phpunit tests/Feature/Logistics/DeliveryExecutionTest.php --display-warnings
pnpm vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: existing tests pass. Record any existing PHPUnit deprecation separately.

### Task 1: Enforce in-progress state for batched pickup

**Files:**
- Modify: `tests/Feature/Logistics/DeliveryExecutionTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`

- [ ] **Step 1: Write the failing backend regression test**

Add a test that uses the existing `fixture()`, sets `requires_pickup_proof` to false, changes the attached batch from `in_progress` to `accepted`, expects `ValidationException`, and calls `markPickedUp`.

Also add a proof-free success assertion for the existing in-progress fixture to document normal outbound retail behavior:

```php
public function test_proof_free_batched_pickup_requires_an_in_progress_batch(): void
{
    [$leg] = $this->fixture();
    $leg->update(['requires_pickup_proof' => false]);

    $pickedUp = app(ShipmentLegService::class)->markPickedUp($leg->fresh());
    $this->assertSame('picked_up', $pickedUp->status->value);

    [$blockedLeg] = $this->fixture();
    $blockedLeg->update(['requires_pickup_proof' => false]);
    $blockedLeg->deliveryBatch->update(['status' => 'accepted']);

    $this->expectException(ValidationException::class);
    app(ShipmentLegService::class)->markPickedUp($blockedLeg->fresh());
}
```

- [ ] **Step 2: Run the backend test and verify RED**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php vendor/bin/phpunit tests/Feature/Logistics/DeliveryExecutionTest.php --display-warnings
```

Expected: FAIL because `markPickedUp` currently accepts a batched leg whose batch is not in progress.

- [ ] **Step 3: Add the minimal service guard**

In `markPickedUp`, load `deliveryBatch` with `shipment`. When `delivery_batch_id` is present and the batch status is not `in_progress`, throw `ValidationException` with a status message. Preserve non-batch pickup and the existing conditional proof rule.

- [ ] **Step 4: Run the backend test and verify GREEN**

Run the Step 2 command. Expected: all `DeliveryExecutionTest` tests pass.

- [ ] **Step 5: Commit the backend guard**

```powershell
git add app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/DeliveryExecutionTest.php
git commit -m "fix: require active batch for rider pickup"
```

### Task 2: Add per-batch bulk selection and actions

**Files:**
- Modify: `resources/js/services/logisticsApi.ts`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`

- [ ] **Step 1: Extend mocks and write failing UI tests**

Import `waitFor` and `within`. Add `markPickedUp`, `confirm`, `alert`, and controllable resolved/rejected promises to the hoisted mocks. Mock `@/utils/workflowFeedback`.

Add focused tests proving:

1. Checkboxes and the batch toolbar appear only for `assigned`/`picked_up` stops in an `in_progress` batch.
2. Select all affects only its batch when two batches are rendered.
3. A mixed selection followed by Mark Picked Up confirms once, calls `markPickedUp` only for assigned selected IDs, reports the picked-up/skipped counts, clears selection, and reloads only `batches`.
4. Mark In Transit calls `outForDelivery` only for picked-up selected IDs.
5. One rejected request produces successful/skipped/failed counts while successful requests remain processed.
6. Cancelled confirmation sends no API requests.
7. A deferred request disables that batch's checkboxes and action controls until processing finishes.

Use accessible names that include the batch and count, for example:

```tsx
screen.getByRole('checkbox', { name: 'Select stop 1 in batch 1' });
screen.getByRole('button', { name: 'Mark Picked Up (1)' });
screen.getByRole('checkbox', { name: 'Select all eligible stops in batch 1' });
```

- [ ] **Step 2: Run the focused frontend test and verify RED**

```powershell
pnpm vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: FAIL because the selection controls, feedback calls, and `markPickedUp` orchestration do not exist.

- [ ] **Step 3: Implement minimal per-batch selection**

First add the existing endpoint to `logisticsApi.ts`:

```ts
markPickedUp: (legId: number) => axios.post(`/api/logistics/legs/${legId}/picked-up`),
```

Do not add a bulk endpoint or dependency. Then, in `MyDeliveries.tsx`:

- import `workflowFeedback`;
- store selected leg IDs by batch ID and the currently processing batch ID;
- derive selectable, selected assigned, and selected picked-up legs inside each batch;
- render Select all, selected count, Clear, and the two action buttons in the batch header;
- render an accessible checkbox beside each eligible stop only when the batch is `in_progress`;
- disable selection and action controls for the processing batch.

Keep selection state local to this page; do not add a component abstraction or global store.

- [ ] **Step 4: Implement the shared bulk-action function**

Create one local function receiving the batch and action (`picked_up` or `in_transit`). It must:

1. derive eligible selected legs and skipped count from current props;
2. show an explanatory warning and return when none are eligible;
3. call `workflowFeedback.confirm` once with eligible/skipped counts;
4. return without requests when cancelled;
5. set the batch processing state;
6. call `logisticsApi.markPickedUp` or `logisticsApi.outForDelivery` for eligible legs through `Promise.allSettled`;
7. count fulfilled and rejected results without exposing internal errors;
8. show one result alert with successful, skipped, and failed counts;
9. clear that batch's selection and call `router.reload({ only: ['batches'] })`; and
10. clear processing state in `finally`.

Retain the existing single-stop controls and all other batch actions.

- [ ] **Step 5: Run the focused frontend test and verify GREEN**

Run the Step 2 command. Expected: all `MyDeliveries` tests pass.

- [ ] **Step 6: Commit the frontend behavior**

```powershell
git add resources/js/services/logisticsApi.ts resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
git commit -m "feat: add rider batch bulk status actions"
```

### Task 3: Regression verification

**Files:**
- Verify only.

- [ ] **Step 1: Run relevant backend tests**

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php vendor/bin/phpunit tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php --display-warnings
```

Expected: all pass; record any existing repository-level PHPUnit deprecation.

- [ ] **Step 2: Run the focused and adjacent frontend tests**

```powershell
pnpm vitest run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: all tests pass.

- [ ] **Step 3: Run the production build**

```powershell
pnpm run build
```

Expected: Vite exits successfully.

- [ ] **Step 4: Inspect source state**

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors and no uncommitted source changes.
