# Delivery Batch Dispatcher Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the text-heavy delivery-batch page with a responsive, draft-first two-column dispatcher workspace with rich stop controls, status-aware batch management, review-before-offer, and clear feedback.

**Architecture:** Keep the existing Inertia route and logistics APIs authoritative. Split the current `Batches.tsx` into focused page-local components, keep transient builder/filter state in the page, and persist mutations through the existing API service. Make only the backend changes required for terminal urgency validation and one batch-level rider notification.

**Tech Stack:** Laravel/PHP, Inertia React 18, TypeScript, Tailwind CSS, Vitest/Testing Library, PHPUnit, `react-dnd`, `sweetalert2`, `lucide-react`.

**Design spec:** `docs/superpowers/specs/2026-07-15-delivery-batch-dispatcher-workspace-design.md`

---

## File Map

**Backend**

- Modify `app/Services/Logistics/BatchDispatchService.php`: terminal urgency guard, batch-offered event, batch event metadata.
- Modify `app/Services/Logistics/AssignmentService.php`: attach optional batch metadata to assignment events without removing audit events.
- Modify `app/Services/Logistics/LogisticsNotificationService.php`: suppress per-stop rider notifications for batch assignments and create one batch-offered notification.
- Modify `app/Enums/NotificationType.php`: add the batch-offered type, label, and logistics category.
- Modify `app/Http/Controllers/Logistics/ErpLogisticsController.php`: include shipment data for batch stop rows.
- Modify `tests/Feature/Logistics/BatchDispatchServiceTest.php`: urgent restriction and event assertions.
- Modify `tests/Feature/Logistics/LogisticsNotificationTest.php`: one batch offer notification while preserving individual assignment notification behavior.
- Modify `tests/Feature/Logistics/LogisticsPageAccessTest.php`: assert the enriched batch-page payload remains tenant-scoped.

**Frontend**

- Modify `resources/js/types/logistics.ts`: typed shipment snapshots, urgency, batch statuses, and page payload.
- Modify `resources/js/utils/workflowFeedback.ts`: add a non-blocking toast helper.
- Modify `resources/js/Pages/ERP/Logistics/Batches.tsx`: page orchestration, local draft state, API mutations, refresh handling.
- Create `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`: delivery filters and selection.
- Create `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`: new/existing draft and read-only batch workspace.
- Create `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`: rich stop row, drag/drop target, keyboard fallback, urgency/remove actions.
- Create `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx`: collapsible status-aware batch summary and menu.
- Create `resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx`: accessible rider/capacity/stops review.
- Rewrite `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`: page workflow and component integration coverage.

No settings, radius, coordinates, Leaflet, route optimization, migrations, or new dependencies are included.

---

### Task 1: Enforce terminal urgency rules

**Files:**
- Modify: `tests/Feature/Logistics/BatchDispatchServiceTest.php:77`
- Modify: `app/Services/Logistics/BatchDispatchService.php:133`

- [ ] **Step 1: Write the failing service test**

Add a focused test proving urgency is reversible before terminal status and rejected afterward:

```php
public function test_urgent_can_be_toggled_until_a_leg_is_terminal(): void
{
    [$shop, $legs, $service] = $this->draftFixture();
    $leg = $legs->first();

    $this->assertNotNull($service->markUrgent($leg, true)->urgent_at);
    $this->assertNull($service->markUrgent($leg->fresh(), false)->urgent_at);

    foreach (['delivered', 'cancelled'] as $status) {
        $terminalLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $leg->shipment_id,
            'status' => $status,
        ]);
        try {
            $service->markUrgent($terminalLeg, true);
            $this->fail("{$status} leg accepted an urgency change.");
        } catch (ValidationException) {
            $this->assertNull($terminalLeg->fresh()->urgent_at);
        }
    }
}
```

- [ ] **Step 2: Run the test and verify failure**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php --filter=urgent_can_be_toggled`

Expected: FAIL because `markUrgent()` currently updates delivered legs.

- [ ] **Step 3: Add the minimal shared guard**

Update `markUrgent()` before its update:

```php
if (in_array($leg->status->value, ['delivered', 'cancelled'], true)) {
    throw ValidationException::withMessages([
        'leg' => 'Delivered or cancelled stops can no longer be changed.',
    ]);
}
```

- [ ] **Step 4: Run the focused service test**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php --filter=urgent`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/BatchDispatchService.php tests/Feature/Logistics/BatchDispatchServiceTest.php
git commit -m "fix: guard terminal delivery urgency"
```

---

### Task 2: Send one rider notification per offered batch

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsNotificationTest.php:94`
- Modify: `tests/Feature/Logistics/BatchDispatchServiceTest.php:19`
- Modify: `app/Enums/NotificationType.php:90`
- Modify: `app/Services/Logistics/AssignmentService.php:18`
- Modify: `app/Services/Logistics/BatchDispatchService.php:71`
- Modify: `app/Services/Logistics/LogisticsNotificationService.php:16`

- [ ] **Step 1: Write the failing batch notification test**

Create a company shop, linked rider user, one shipment, and two scheduled legs. Create and offer a batch, then assert:

```php
$this->assertSame(1, Notification::query()
    ->where('user_id', $riderUser->id)
    ->where('type', 'logistics_batch_offered')
    ->count());
$this->assertSame(0, Notification::query()
    ->where('user_id', $riderUser->id)
    ->where('type', 'logistics_assigned')
    ->count());
$this->assertDatabaseHas('notifications', [
    'user_id' => $riderUser->id,
    'title' => 'Delivery Batch Offered',
    'message' => 'A delivery batch with 2 stops has been offered to you.',
    'action_url' => '/erp/logistics/deliveries',
]);
$this->assertSame(2, DeliveryEvent::where('event_type', 'leg_assigned')->count());
$this->assertSame(1, DeliveryEvent::where('event_type', 'batch_offered')->count());
```

Keep the existing individual-assignment test unchanged so it proves `logistics_assigned` still works outside a batch.

- [ ] **Step 2: Run the notification tests and verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php --filter=rider`

Expected: FAIL with multiple `logistics_assigned` notifications and no batch-offered type.

- [ ] **Step 3: Add the notification enum value**

Add `LOGISTICS_BATCH_OFFERED = 'logistics_batch_offered'`, label it `Delivery Batch Offered`, and include it in the logistics category match in `NotificationType.php`.

- [ ] **Step 4: Preserve assignment audit events with batch metadata**

Change the assignment signature without affecting current callers:

```php
public function assignInternalRider(
    ShipmentLeg $leg,
    RiderProfile $rider,
    ShopOwner $actor,
    array $eventMetadata = [],
): DeliveryAssignment
```

Merge metadata when recording the event:

```php
'metadata' => ['rider_profile_id' => $rider->id] + $eventMetadata,
```

In `BatchDispatchService::offer()`, pass `['delivery_batch_id' => $batch->id]` for each assignment. After the batch status update, record one `batch_offered` event with `rider_profile_id`, `delivery_batch_id`, and `stop_count`. Extend `recordBatchEvent()` with an optional metadata argument and merge it with the batch ID.

- [ ] **Step 5: Route rider notifications by event semantics**

In `LogisticsNotificationService`:

```php
if ($event->event_type === 'leg_assigned' && empty($event->metadata['delivery_batch_id'])) {
    $this->notifyRider($event, $type);
}
if ($event->event_type === 'batch_offered') {
    $this->notifyRider($event, $type);
}
```

Add `batch_offered` to `shouldNotify()` and `notificationType()`. In `notifyRider()`, derive the batch message and destination only for `batch_offered`; retain the existing individual assignment message and URL otherwise. Include `delivery_batch_id` and `stop_count` in notification data when present.

- [ ] **Step 6: Run focused notification and dispatch tests**

Run: `php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php`

Expected: PASS, including the existing individual assignment test.

- [ ] **Step 7: Commit**

```bash
git add app/Enums/NotificationType.php app/Services/Logistics/AssignmentService.php app/Services/Logistics/BatchDispatchService.php app/Services/Logistics/LogisticsNotificationService.php tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php
git commit -m "fix: notify riders once per batch offer"
```

---

### Task 3: Enrich and type the batch-page payload

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php:150`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php:204`
- Modify: `resources/js/types/logistics.ts:1`

- [ ] **Step 1: Write the failing page payload assertion**

Extend the existing batches-page test with a batch leg whose destination snapshot contains customer details. Assert the Inertia batch prop contains `legs.0.shipment.source_type`, `source_id`, and the destination snapshot, expose the shop's `dailyRiderCapacity`, and keep another shop's batch absent.

- [ ] **Step 2: Run the page access test and verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=batches`

Expected: FAIL because batch legs do not currently eager-load their shipment.

- [ ] **Step 3: Eager-load only the relationship needed by rich rows**

Change the batch query to:

```php
DeliveryBatch::with(['riderProfile', 'legs.shipment'])
```

Keep the existing shop-owner constraint and ordering.

Resolve the shop logistics setting once and pass its `daily_rider_capacity` as the scalar `dailyRiderCapacity` page prop. Do not expose or edit the rest of the settings payload from this page.

- [ ] **Step 4: Add narrow frontend types**

Extend `TrackingShipmentLeg` with `urgent_at` and optional `shipment`. Add literal `DeliveryBatchStatus` and a reusable page payload:

```ts
export type DeliveryBatchStatus = 'draft' | 'offered' | 'accepted' | 'in_progress' | 'completed' | 'cancelled';

export type DeliveryBatchPageProps = {
  batches: DeliveryBatch[];
  pool: TrackingShipmentLeg[];
  unscheduled: TrackingShipmentLeg[];
  riders: LogisticsRider[];
  dailyRiderCapacity: number;
};
```

Type snapshot fields used by the UI through a small `DeliveryContactSnapshot` type rather than repeated casts.

- [ ] **Step 5: Run the backend test and frontend build**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=batches`

Expected: PASS.

Run: `pnpm build`

Expected: PASS with no TypeScript/Vite errors.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Logistics/ErpLogisticsController.php resources/js/types/logistics.ts tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: enrich delivery batch page data"
```

---

### Task 4: Add reusable batch feedback and presentation primitives

**Files:**
- Modify: `resources/js/utils/workflowFeedback.ts`
- Create: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Create: `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Add failing UI tests for batch summaries and stop detail**

Render a draft batch and assert customer, address, rider fallback, status text, capacity, urgent count, and accessible action names. Assert the card chevron expands inline details but does not invoke the Edit/View callback.

- [ ] **Step 2: Run the focused frontend test and verify failure**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL because rich cards/rows do not exist.

- [ ] **Step 3: Add a non-blocking toast method**

Extend `workflowFeedback`:

```ts
toast(icon: 'success' | 'error' | 'warning', title: string) {
  return Swal.fire({ toast: true, position: 'top-end', timer: 2200,
    timerProgressBar: true, showConfirmButton: false, icon, title });
},
```

- [ ] **Step 4: Implement `BatchStopRow`**

Use `lucide-react` icons with visible labels or `aria-label`. Render stop number, source reference, customer, phone, address, schedule, status, and urgent badge. Accept callbacks and capability flags; do not call APIs inside the row.

Add `useDrag`/`useDrop` only when `editable` is true. Call `onMove(fromIndex, toIndex)` on drop. Always expose Up/Down controls when editable.

- [ ] **Step 5: Implement `BatchCard`**

Use a native `<details>` element for the three-dot secondary menu. Keep expansion state separate from the primary Edit/View callback. Derive the primary label from status and render history cards read-only.

- [ ] **Step 6: Run the frontend test**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: PASS for summary/detail cases.

- [ ] **Step 7: Commit**

```bash
git add resources/js/utils/workflowFeedback.ts resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx resources/js/Pages/ERP/Logistics/components/BatchCard.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: add delivery batch UI primitives"
```

---

### Task 5: Build the available-delivery panel and local builder

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`
- Create: `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write failing selection/filter tests**

Cover the **New Batch** entry action, search by order/customer/phone/address, date/window/status filters, select-all limited to matching eligible rows, selected count, clear filters, and the two empty messages from the spec.

- [ ] **Step 2: Run tests and verify failure**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL because the two-column panel and filters do not exist.

- [ ] **Step 3: Implement the controlled available-delivery panel**

Combine `unscheduled` and `pool` once in `Batches.tsx`. Keep search/date/window/status and ordered selected IDs in the page. Pass filtered rows and callbacks into `AvailableDeliveriesPanel`; the component contains no API calls.

- [ ] **Step 4: Implement the new-batch workspace shell**

`BatchWorkspace` renders the local ordered selection through `BatchStopRow`, rider/capacity summary, and sticky **Save Draft** action. Wrap its stop list in `DndProvider` with `HTML5Backend`. Keep **Review & Offer** disabled until the draft exists.

Compare selected stop count with `dailyRiderCapacity`. When it is exceeded, show the warning plus a required **Capacity override reason** textarea. This is workflow input, not a logistics-settings editor.

- [ ] **Step 5: Preserve the exact save sequence**

Move the current schedule/create logic into a named `saveDraft()` in `Batches.tsx`:

```ts
const unscheduledIds = selectedIds.filter((id) =>
  unscheduled.some((leg) => leg.id === id) && !scheduledThisAttempt.includes(id));
if (unscheduledIds.length) await logisticsApi.scheduleLegs(unscheduledIds, date, window);
const { data } = await logisticsApi.createBatch({
  delivery_date: date, delivery_window: window, leg_ids: selectedIds,
  dispatcher_override_reason: selectedIds.length > dailyRiderCapacity
    ? overrideReason.trim()
    : undefined,
});
```

Retain selection/order, override reason, and `scheduledThisAttempt` when create fails. Disable **Save Draft** while over capacity and the reason is blank. After creation succeeds, show the **Draft saved** toast, store `data.batch.id` as `selectedBatchId`, switch the right workspace from local builder mode to saved-draft mode, clear the local builder fields, and reload `batches`, `pool`, and `unscheduled`. Keep `selectedBatchId` through the reload so the hydrated draft appears immediately with **Review & Offer** enabled; the dispatcher must not have to find and reopen it.

- [ ] **Step 6: Run workflow tests**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: PASS for filtering (including phone), selection, capacity override, schedule-before-create, retry deduplication, immediate saved-draft selection, and responsive workspace structure.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: add delivery batch builder workspace"
```

---

### Task 6: Wire draft stop mutations and confirmations

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write failing mutation tests**

Assert Up/Down and drop callbacks send one ordered ID list, removal shows customer/stop confirmation, removing the last stop shows the delete warning, urgency sends both `true` and `false`, terminal stops hide urgency, every request disables its own control while pending, and a stale-data response offers the specified refresh prompt.

- [ ] **Step 2: Run tests and verify failure**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL because the controls are not wired.

- [ ] **Step 3: Centralize mutation handlers in the page**

Implement `reorderStops`, `removeStop`, and `toggleUrgent` in `Batches.tsx`. Use `workflowFeedback.confirm()` for removals and `workflowFeedback.toast()` after success. For a final stop, change the confirmation title/text to state that the empty batch will be deleted.

For an unsaved selection, reorder/remove remain local while urgency calls the leg endpoint immediately. For an existing draft, reorder/remove call their APIs immediately. On failure, retain server-backed display state and show the normalized error.

- [ ] **Step 4: Refresh only relevant page props**

Use `router.reload({ only: ['batches', 'pool', 'unscheduled', 'riders'] })` after successful mutations. Track refresh state so panels show skeletons without blanking the whole page.

- [ ] **Step 5: Run tests**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: PASS for mutation and feedback cases.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: wire delivery batch stop actions"
```

---

### Task 7: Add review-before-offer and cancellation

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write failing offer/cancel tests**

Assert the review modal requires a rider, shows capacity/date/window/ordered stops/urgent count, warns when stops exceed rider capacity, asks final confirmation, preserves the draft when offer fails, and uses the correct batch status afterward. Assert cancellation collects a non-empty reason and calls the existing cancel endpoint.

- [ ] **Step 2: Run tests and verify failure**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL because the review modal does not exist.

- [ ] **Step 3: Implement the accessible review modal**

Reuse `resources/js/components/ui/modal/index.tsx` for presentation, but add component-local `role="dialog"`, `aria-modal`, labelled title, initial focus, Tab/Shift+Tab containment, Escape close, and trigger-focus restoration. Keep rider selection inside the modal.

- [ ] **Step 4: Wire offer and cancellation**

After modal review, use SweetAlert for the final offer confirmation, then call `logisticsApi.offerBatch`. On failure, keep the modal/draft intact and display the server message. On success, close the modal, toast, reload, and select the offered batch.

For cancellation, use a SweetAlert textarea with `inputValidator` requiring a reason, call `cancelBatch`, then toast and reload.

- [ ] **Step 5: Run tests**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: PASS for modal accessibility, capacity, offer failure/success, and cancellation.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: add delivery batch review and offer flow"
```

---

### Task 8: Finish active/history management and responsive states

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write failing status/history/state tests**

Cover active status tabs, collapsed history, primary action labels, status-specific secondary actions, read-only active routes, editable urgency before terminal stop status, loading skeletons, idle workspace, no riders, capacity warning, and responsive column/stack classes.

- [ ] **Step 2: Run tests and verify failure**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL for the unfinished collection and states.

- [ ] **Step 3: Complete active/history rendering**

Derive active and historical arrays from server props. Status tabs filter only active cards. History uses a collapsed section and never mixes with active status results. Primary actions load the right workspace; chevrons only expand inline summaries.

- [ ] **Step 4: Apply status-aware actions and responsive layout**

Render the desktop `lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]` workspace and stack below `lg`. Keep mobile actions sticky, avoid horizontal stop-row scrolling, and ensure touch targets are at least 40px high.

- [ ] **Step 5: Run frontend tests and build**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: PASS.

Run: `pnpm build`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/BatchCard.tsx resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: finish delivery batch dispatcher workspace"
```

---

### Task 9: Final regression verification

**Files:**
- Verify only; modify only if a test exposes a requirement defect.

- [ ] **Step 1: Run the focused backend suite**

Run: `php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php`

Expected: PASS.

- [ ] **Step 2: Run the logistics feature suite**

Run: `php artisan test tests/Feature/Logistics`

Expected: PASS.

- [ ] **Step 3: Run the focused frontend suite**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__`

Expected: PASS.

- [ ] **Step 4: Build production assets**

Run: `pnpm build`

Expected: PASS. Do not commit generated `public/build` changes unless this repository explicitly requires built assets for the feature branch.

- [ ] **Step 5: Inspect the final diff**

Run: `git diff --check`

Expected: no whitespace errors.

Run: `git status --short`

Expected: only intentional source/test changes; preserve all unrelated pre-existing worktree changes.

- [ ] **Step 6: Commit any verification-only correction**

Only if verification required a focused correction:

```bash
git add <exact corrected files>
git commit -m "fix: address delivery batch workspace regression"
```
