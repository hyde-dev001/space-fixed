# Rider My Deliveries Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the rider’s stacked batch-plus-table page with one mobile-first task view that consistently presents batch and standalone work, while preventing more than one work item from becoming active.

**Architecture:** `ErpLogisticsController::deliveries()` will return one rider-specific read model assembled from existing `DeliveryBatch`, `ShipmentLeg`, and `DeliveryAssignment` records. `MyDeliveries.tsx` will render that model directly instead of inheriting the dispatcher-oriented `Shipments` table. A small pure TypeScript helper module will own progress, actionable-stop, and business-filter rules, while one shared Laravel guard will serialize batch and standalone starts.

**Tech Stack:** Laravel 12, Eloquent, Inertia, React 18, TypeScript, Tailwind CSS, Vitest/Testing Library, PHPUnit.

**Design reference:** `docs/superpowers/specs/2026-07-29-rider-my-deliveries-redesign-design.md`

---

## File Map

### Create

- `app/Services/Logistics/RiderActiveWorkGuard.php` — one shared, transaction-safe active-work check used by batch and standalone starts.
- `resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts` — pure presentation rules for progress, actionable delivery selection, labels, and business matching.
- `resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts` — focused unit tests for those pure rules.
- `tests/Feature/Logistics/RiderMyDeliveriesPageTest.php` — read-model and filter behavior for the rider page.

### Modify

- `app/Http/Controllers/Logistics/ErpLogisticsController.php` — replace the legacy rider shipment/batch props with the unified rider read model.
- `app/Http/Controllers/Api/Logistics/ShipmentController.php` — pass the assigned rider into standalone pickup transitions.
- `app/Models/Logistics/ShipmentLeg.php` — expose the current assignment through Eloquent’s native `latestOfMany`.
- `app/Services/Logistics/BatchDispatchService.php` — call the shared active-work guard before starting a batch.
- `app/Services/Logistics/ShipmentLegService.php` — call the shared guard before a standalone pickup becomes active.
- `resources/js/types/logistics.ts` — add typed rider work-item, issue, filter, and page-data contracts.
- `resources/js/services/logisticsApi.ts` — expose the existing generic in-transit endpoint for standalone deliveries.
- `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx` — render the dedicated task-first mobile page and reuse existing rider actions.
- `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx` — replace stacked/bulk expectations with task-first UI and action tests.
- `tests/Feature/Logistics/BatchDispatchServiceTest.php` — verify a batch cannot start beside other active work.
- `tests/Feature/Logistics/ShipmentLegServiceTest.php` — verify a standalone delivery cannot start beside other active work.

### Leave unchanged

- `resources/js/Pages/ERP/Logistics/Shipments.tsx` — remains the dispatcher’s individual-delivery page.
- `resources/js/Pages/ERP/Logistics/Batches.tsx` — remains the dispatcher’s batch creation and assignment page.
- Database schema — Phase 1 uses existing batches, assignments, attempts, proofs, and events.

---

### Task 1: Build the rider delivery read model

**Files:**

- Create: `tests/Feature/Logistics/RiderMyDeliveriesPageTest.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php:119-179`
- Modify: `app/Models/Logistics/ShipmentLeg.php:68-71`

- [ ] **Step 1: Write the failing feature test for priority work**

Create a rider, rider profile, accepted batch, in-progress batch, standalone assigned leg, standalone in-transit leg, and another rider’s work. Assert the response exposes only the signed-in rider’s data:

```php
public function test_it_returns_one_current_item_and_the_next_scheduled_work(): void
{
    $this->seed(RolesAndPermissionsSeeder::class);
    $shop = ShopOwner::factory()->create();
    $user = User::factory()->create(['shop_owner_id' => $shop->id]);
    $user->assignRole('Logistics Rider');
    $rider = RiderProfile::factory()->create([
        'shop_owner_id' => $shop->id,
        'linked_type' => User::class,
        'linked_id' => $user->id,
    ]);

    $activeBatch = DeliveryBatch::factory()->create([
        'shop_owner_id' => $shop->id,
        'rider_profile_id' => $rider->id,
        'status' => 'in_progress',
        'started_at' => '2026-07-29 08:00:00',
    ]);
    $activeShipment = Shipment::factory()->create([
        'shop_owner_id' => $shop->id,
        'purpose' => 'repair_pickup',
    ]);
    $activeLeg = ShipmentLeg::factory()->create([
        'shipment_id' => $activeShipment->id,
        'delivery_batch_id' => $activeBatch->id,
        'status' => 'in_transit',
        'stop_sequence' => 1,
    ]);
    DeliveryAssignment::factory()->create([
        'shipment_leg_id' => $activeLeg->id,
        'rider_profile_id' => $rider->id,
        'status' => 'accepted',
    ]);

    $nextShipment = Shipment::factory()->create([
        'shop_owner_id' => $shop->id,
        'purpose' => 'retail_delivery',
    ]);
    $nextLeg = ShipmentLeg::factory()->create([
        'shipment_id' => $nextShipment->id,
        'status' => 'assigned',
        'scheduled_delivery_date' => '2026-07-30',
        'delivery_window' => 'morning',
    ]);
    DeliveryAssignment::factory()->create([
        'shipment_leg_id' => $nextLeg->id,
        'rider_profile_id' => $rider->id,
        'status' => 'assigned',
    ]);

    $props = $this->actingAs($user, 'user')
        ->get('/erp/logistics/deliveries')
        ->assertOk()
        ->viewData('page')['props']['deliveryData'];

    $this->assertSame("batch:{$activeBatch->id}", $props['current']['key']);
    $this->assertSame("single:{$nextLeg->id}", $props['up_next']['key']);
    $this->assertFalse($props['has_active_conflict']);
}
```

- [ ] **Step 2: Write failing feature tests for list membership**

Add separate tests proving:

```php
public function test_business_filter_changes_only_the_lower_list(): void;
public function test_issues_are_delivery_rows_with_their_parent_batch(): void;
public function test_declined_batches_appear_in_history_from_rejected_assignments(): void;
public function test_all_contains_each_work_item_once(): void;
public function test_reassigned_standalone_work_is_history_not_active_work(): void;
public function test_earliest_started_work_is_current_and_the_rest_are_conflicts(): void;
public function test_offer_order_falls_back_to_offered_at_without_a_response_deadline(): void;
public function test_up_next_is_not_duplicated_in_the_upcoming_list(): void;
```

Required assertions:

- `business=repair` keeps the current Retail work item pinned but excludes its compact lower-list entry.
- Issues use `item_type: issue`, delivery ID, attempt ID, and `parent_key`.
- A rejected batch whose `rider_profile_id` was cleared still appears as `status: declined`.
- Work-item keys are unique in All.
- Another rider’s assignments never appear.
- Active candidates use the normalized `started_at` value; the earliest is current and every surplus candidate is `group: conflict`.
- Offers expose `response_deadline: null` with the current schema and order by `offered_at`, then key.
- The pinned `up_next` key is absent from the Upcoming lower list but appears once in All.

- [ ] **Step 3: Run the new test to verify it fails**

Run:

```bash
php artisan test tests/Feature/Logistics/RiderMyDeliveriesPageTest.php
```

Expected: FAIL because `deliveryData` does not exist.

- [ ] **Step 4: Add the validated filters**

Add imports:

```php
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
```

In `deliveries()`, replace the legacy `status` filter with:

```php
$tab = in_array($request->query('tab'), ['upcoming', 'history', 'issues', 'all'], true)
    ? $request->query('tab')
    : 'upcoming';
$business = in_array($request->query('business'), ['all', 'retail', 'repair'], true)
    ? $request->query('business')
    : 'all';
$window = in_array($request->query('window'), ['today', 'week'], true)
    ? $request->query('window')
    : 'all';
$search = trim((string) $request->query('search', ''));
```

Keep the existing shop/database timezone conversion and derive `$dates` from `window`; set it to null for All time.

Resolve the rider once:

```php
$rider = RiderProfile::query()
    ->where('shop_owner_id', $shopOwnerId)
    ->where('linked_type', User::class)
    ->where('linked_id', $user->id)
    ->firstOrFail();
```

- [ ] **Step 5: Load batches and standalone legs**

Add the native current-assignment relation:

```php
public function latestAssignment(): HasOne
{
    return $this->hasOne(DeliveryAssignment::class)->latestOfMany();
}
```

Load batches where the rider is currently assigned or has a rejected assignment. Eager-load only data used by the view:

```php
$batches = DeliveryBatch::query()
    ->with([
        'legs.shipment',
        'legs.proofs',
        'legs.assignments' => fn ($query) => $query->where('rider_profile_id', $rider->id),
        'legs.attempts' => fn ($query) => $query
            ->where('attempt_type', 'delivery')
            ->where('status', 'failed')
            ->latest('attempted_at')
            ->latest('id'),
    ])
    ->where('shop_owner_id', $shopOwnerId)
    ->where(function ($query) use ($rider) {
        $query->where('rider_profile_id', $rider->id)
            ->orWhereHas('legs.assignments', fn ($assignments) => $assignments
                ->where('rider_profile_id', $rider->id)
                ->where('status', 'rejected'));
    })
    ->get();
```

Load standalone legs by assignment history:

```php
$standalone = ShipmentLeg::query()
    ->with([
        'shipment',
        'proofs',
        'assignments.riderProfile',
        'latestAssignment.riderProfile',
        'attempts' => fn ($query) => $query
            ->where('attempt_type', 'delivery')
            ->where('status', 'failed')
            ->latest('attempted_at')
            ->latest('id'),
    ])
    ->whereNull('delivery_batch_id')
    ->whereHas('shipment', fn ($query) => $query->where('shop_owner_id', $shopOwnerId))
    ->whereHas('assignments', fn ($query) => $query->where('rider_profile_id', $rider->id))
    ->get();
```

For each standalone leg:

- use `latestAssignment` as the current owner;
- take the highest-ID assignment for the signed-in rider as their latest assignment;
- expose active/upcoming work only when those are the same assignment and its status is `assigned` or `accepted`;
- expose an older rider assignment only in History, labelled Reassigned;
- map that rider assignment’s `rejected` status to Declined and `cancelled` to Cancelled;
- never infer a standalone offer because the existing single-assignment flow has no offer/accept/decline endpoint.

- [ ] **Step 6: Normalize records into one documented payload**

Add private controller methods rather than a new read-service abstraction:

```php
private function batchWorkItem(DeliveryBatch $batch, RiderProfile $rider): array;
private function standaloneWorkItem(ShipmentLeg $leg, RiderProfile $rider): array;
private function businessTypes(iterable $purposes): array;
private function workItemSortKey(array $item): array;
private function paginateDeliveryItems(Collection $items, Request $request): LengthAwarePaginator;
```

Every work item must have:

```php
[
    'item_type' => 'work',
    'key' => 'batch:12',          // or single:34
    'kind' => 'batch',            // or single
    'id' => 12,
    'status' => 'in_progress',
    'group' => 'current',
    'business_types' => ['repair'],
    'business_label' => 'Repair pickup',
    'delivery_date' => '2026-07-29',
    'delivery_window' => 'morning',
    'started_at' => '...',
    'offered_at' => '...',
    'response_deadline' => null,
    'terminal_at' => null,
    'deliveries' => [...],
]
```

Use these exact group rules:

```php
// Batch
offered     => offer
accepted    => upcoming
in_progress => current
completed   => history
cancelled   => history
rejected assignment with another/null current rider => history + declined

// Standalone leg
current rider assignment + assigned or pickup_scheduled => upcoming
current rider assignment + picked_up, in_transit, delivery_attempted, awaiting_proof_approval => current
delivered, cancelled, rejected, or superseded rider assignment => history
```

Use `repair_pickup` and `repair_return` as Repair. Use `retail_delivery` and `refund_return` as Retail. A mixed batch gets both values and the label Mixed.

Normalize `started_at`:

```php
batch      => batch.started_at
standalone => out_for_delivery_at ?? picked_up_at ?? latestAssignment.accepted_at ?? latestAssignment.assigned_at
```

The current schema has no offer response-deadline column. Serialize `response_deadline` as null and sort by `offered_at`, then key. Keep `response_deadline` as the first nullable sort key so a future field can be honored without changing list semantics; do not add a migration in Phase 1.

- [ ] **Step 7: Select priority work and construct issue rows**

Sort current candidates by normalized `started_at` ascending, kind, then ID. Select the first as `current`. Reclassify every surplus current candidate to `group: conflict`, expose them as `active_conflicts`, and set `has_active_conflict`.

Sort offers by nullable `response_deadline`, `offered_at`, then key. Sort upcoming by delivery date, Morning before Afternoon, assignment/acceptance time, then key. `up_next` is the first upcoming item.

Create one issue row only when all of these are true:

- the leg’s canonical status is `delivery_attempted`;
- `resolution_type` is null;
- the latest delivery attempt has status `failed`; and
- the attempt belongs to the signed-in rider’s assignment.

A later retry transition, Delivered/Cancelled status, non-null resolution, or reassignment removes the attempt from Issues without deleting its historical record.

Issue payload:

```php
[
    'item_type' => 'issue',
    'key' => "issue:{$attempt->id}",
    'id' => $attempt->id,
    'delivery_id' => $leg->id,
    'parent_key' => $batch ? "batch:{$batch->id}" : "single:{$leg->id}",
    'business_types' => [...],
    'reason' => $attempt->reason_code,
    'attempted_at' => $attempt->attempted_at,
    'delivery_date' => $leg->scheduled_delivery_date?->toDateString(),
    'search_text' => '...',
]
```

Filter and paginate only the lower list. Never apply business, date, or search filters to `offers`, `current`, `active_conflicts`, or `up_next`.

Construct the lower-list source exactly:

```php
$source = match ($tab) {
    'upcoming' => $workItems->where('group', 'upcoming')
        ->reject(fn ($item) => $item['key'] === ($upNext['key'] ?? null)),
    'history' => $workItems->where('group', 'history'),
    'issues' => $issues,
    'all' => $workItems,
};

$filtered = $source
    ->when($business !== 'all', fn ($items) => $items->filter(
        fn ($item) => in_array($business, $item['business_types'], true)
    ))
    ->when($dates, fn ($items) => $items->filter(
        fn ($item) => filled($item['delivery_date'])
            && Carbon::parse($item['delivery_date'])->betweenIncluded($dates[0], $dates[1])
    ))
    ->when($search !== '', fn ($items) => $items->filter(
        fn ($item) => str_contains(Str::lower($item['search_text']), Str::lower($search))
    ));
```

Each work item’s `search_text` must include its key/ID, child delivery IDs, shipment IDs, purpose, customer/merchant name, phone, and address. Each issue row includes the same parent/delivery search text plus its reason. Remove `search_text` before serializing.

Strip `search_text` from priority items and paginated items only after every filter and search operation has completed.

When a batch matches by child delivery ID, set `matched_delivery_id` on the compact work item so the frontend expands that child after View details.

Sort before pagination:

```php
upcoming => delivery date, Morning before Afternoon, assignment/acceptance time, key
history  => terminal timestamp descending, updated timestamp descending, key
issues   => attempted_at descending, delivery ID descending
all      => Current, Offer, Upcoming, Conflict, History; then the matching group sort
```

All includes `up_next` once because the spec defines All as every work item. Upcoming excludes the pinned `up_next` card so the default view does not duplicate it.

Use Laravel’s `LengthAwarePaginator` after merging the two per-rider collections:

```php
// ponytail: in-memory merge keeps batch and standalone ordering simple;
// replace with an ID union query only if per-rider history volume becomes measurable.
$page = max(1, $request->integer('page', 1));
$list = new LengthAwarePaginator(
    $filtered->forPage($page, 10)->values(),
    $filtered->count(),
    10,
    $page,
    ['path' => $request->url(), 'query' => $request->query()],
);
```

- [ ] **Step 8: Return only the new rider props**

Return:

```php
return Inertia::render('ERP/Logistics/MyDeliveries', [
    'deliveryData' => [
        'offers' => $offers->values(),
        'current' => $current,
        'active_conflicts' => $activeConflicts->values(),
        'has_active_conflict' => $activeConflicts->isNotEmpty(),
        'up_next' => $upNext,
        'list' => $list,
        'filters' => compact('tab', 'business', 'window', 'search'),
    ],
    'canRecordProof' => $user->can('record-logistics-proof'),
    'maxDeliveryAttempts' => (int) LogisticsSetting::firstOrCreate([
        'shop_owner_id' => $shopOwnerId,
    ])->max_delivery_attempts,
]);
```

Remove the rider page’s legacy `shipments`, `batches`, dispatcher capability, and assignable-rider props. Do not alter `shipments()`.

- [ ] **Step 9: Run the feature tests**

Run:

```bash
php artisan test tests/Feature/Logistics/RiderMyDeliveriesPageTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php
```

Expected: PASS. Update only legacy assertions that intentionally inspect the old rider props; keep authorization and timezone coverage.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Logistics/ErpLogisticsController.php app/Models/Logistics/ShipmentLeg.php tests/Feature/Logistics/RiderMyDeliveriesPageTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: add rider delivery work-item read model"
```

---

### Task 2: Add typed presentation rules

**Files:**

- Modify: `resources/js/types/logistics.ts`
- Create: `resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts`
- Create: `resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts`

- [ ] **Step 1: Write failing unit tests**

```ts
import {
  completedProgress,
  nextActionableDelivery,
  matchesBusiness,
} from '../riderDeliveryPresentation';

it('counts only delivered stops as complete', () => {
  expect(completedProgress([
    { id: 1, status: 'delivered' },
    { id: 2, status: 'awaiting_proof_approval' },
  ] as any)).toEqual({ completed: 1, total: 2, percent: 50 });
});

it('skips proof-pending and issue stops when selecting an action', () => {
  expect(nextActionableDelivery([
    { id: 1, status: 'awaiting_proof_approval', stop_sequence: 1 },
    { id: 2, status: 'delivery_attempted', stop_sequence: 2 },
    { id: 3, status: 'picked_up', stop_sequence: 3 },
  ] as any)?.id).toBe(3);
});

it('matches mixed work in either business filter', () => {
  const item = { business_types: ['repair', 'retail'] } as any;
  expect(matchesBusiness(item, 'repair')).toBe(true);
  expect(matchesBusiness(item, 'retail')).toBe(true);
});
```

- [ ] **Step 2: Run the helper tests to verify they fail**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts
```

Expected: FAIL because the helper module does not exist.

- [ ] **Step 3: Add the page-data types**

Extend `TrackingShipmentLeg.shipment` with `purpose`, and add:

```ts
export type RiderDeliveryBusiness = 'all' | 'retail' | 'repair';
export type RiderDeliveryTab = 'upcoming' | 'history' | 'issues' | 'all';

export type RiderDeliveryWorkItem = {
  item_type: 'work';
  key: string;
  kind: 'batch' | 'single';
  id: number;
  status: string;
  group: 'offer' | 'current' | 'upcoming' | 'history' | 'conflict';
  business_types: Array<Exclude<RiderDeliveryBusiness, 'all'>>;
  business_label: string;
  delivery_date?: string | null;
  delivery_window?: 'morning' | 'afternoon' | null;
  started_at?: string | null;
  offered_at?: string | null;
  response_deadline?: string | null;
  terminal_at?: string | null;
  matched_delivery_id?: number | null;
  deliveries: TrackingShipmentLeg[];
};

export type RiderDeliveryIssue = {
  item_type: 'issue';
  key: string;
  id: number;
  delivery_id: number;
  parent_key: string;
  business_types: Array<Exclude<RiderDeliveryBusiness, 'all'>>;
  reason?: string | null;
  attempted_at?: string | null;
  delivery_date?: string | null;
};

export type RiderDeliveryPageData = {
  offers: RiderDeliveryWorkItem[];
  current: RiderDeliveryWorkItem | null;
  active_conflicts: RiderDeliveryWorkItem[];
  has_active_conflict: boolean;
  up_next: RiderDeliveryWorkItem | null;
  list: PaginatedResponse<RiderDeliveryWorkItem | RiderDeliveryIssue>;
  filters: {
    tab: RiderDeliveryTab;
    business: RiderDeliveryBusiness;
    window: 'all' | 'today' | 'week';
    search: string;
  };
};
```

- [ ] **Step 4: Implement the pure helpers**

```ts
const actionableStatuses = new Set(['assigned', 'picked_up', 'in_transit']);

export const orderedDeliveries = (deliveries: TrackingShipmentLeg[]) =>
  [...deliveries].sort((a, b) =>
    (a.stop_sequence ?? Number.MAX_SAFE_INTEGER) -
      (b.stop_sequence ?? Number.MAX_SAFE_INTEGER) ||
    a.id - b.id
  );

export const completedProgress = (deliveries: TrackingShipmentLeg[]) => {
  const completed = deliveries.filter(({ status }) => status === 'delivered').length;
  const total = deliveries.length;
  return { completed, total, percent: total ? Math.round(completed / total * 100) : 0 };
};

export const nextActionableDelivery = (deliveries: TrackingShipmentLeg[]) =>
  orderedDeliveries(deliveries).find(({ status }) => actionableStatuses.has(status));

export const matchesBusiness = (
  item: Pick<RiderDeliveryWorkItem | RiderDeliveryIssue, 'business_types'>,
  business: RiderDeliveryBusiness,
) => business === 'all' || item.business_types.includes(business);
```

Also move contact extraction and rider-friendly status formatting into this file. Keep them as functions, not classes.

- [ ] **Step 5: Run the helper tests**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts
git commit -m "feat: add rider delivery presentation rules"
```

---

### Task 3: Render the task-first mobile hierarchy

**Files:**

- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

- [ ] **Step 1: Replace old test fixtures with the new prop contract**

Mock:

```ts
const mocks = vi.hoisted(() => ({
  props: {
    deliveryData: {
      offers: [],
      current: null,
      active_conflicts: [],
      has_active_conflict: false,
      up_next: null,
      list: {
        data: [], links: [], from: null, to: null, total: 0,
        current_page: 1, last_page: 1,
      },
      filters: { tab: 'upcoming', business: 'all', window: 'all', search: '' },
    },
    canRecordProof: true,
    maxDeliveryAttempts: 2,
  },
  reload: vi.fn(),
  get: vi.fn(),
}));
```

Mock `AppLayoutERP` and `Head` directly; remove the `Shipments` mock.

- [ ] **Step 2: Write failing hierarchy tests**

Cover:

- Page heading and sync/online label.
- One Current Delivery card only.
- `awaiting_proof_approval` does not increase completed progress.
- The current batch exposes “View all N deliveries.”
- Non-current delivery details are collapsed.
- Up Next renders one compact work item.
- Tabs are Upcoming, History, Issues, and All.
- Batch and standalone labels are distinct.
- Bulk checkboxes and bulk buttons are absent.
- Empty current, empty list, and active-conflict notices are readable.

Example:

```ts
expect(screen.getByRole('heading', { name: 'Current delivery' })).toBeVisible();
expect(screen.getByText('1 of 3 completed')).toBeVisible();
expect(screen.getByRole('button', { name: 'View all 3 deliveries' })).toBeVisible();
expect(screen.queryByText(/Mark Picked Up \(/)).not.toBeInTheDocument();
```

- [ ] **Step 3: Run the component test to verify it fails**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: FAIL against the old stacked page.

- [ ] **Step 4: Replace the inherited shipment page**

`MyDeliveries.tsx` must import `AppLayoutERP` and `Head`, not `Shipments`.

Render:

```tsx
<AppLayoutERP>
  <Head title="My Deliveries" />
  <div className="mx-auto max-w-3xl space-y-6 pb-10">
    <PageHeader />
    <ConnectionNotice />
    <OfferRegion />
    <CurrentDeliveryCard />
    <UpNextCard />
    <DeliveryLists />
  </div>
</AppLayoutERP>
```

Keep these as local functions in the same file. Do not create a component directory for one page.

- [ ] **Step 5: Implement the current card**

Required hierarchy:

```tsx
<section aria-labelledby="current-delivery-heading">
  <h2 id="current-delivery-heading">Current delivery</h2>
  <article className="rounded-2xl border-2 border-blue-300 bg-white shadow-sm">
    {/* business label + text status */}
    {/* delivered-only progress */}
    {/* one expanded actionable delivery or waiting state */}
    {/* Call, Directions, and contextual primary action */}
    {/* inline expandable ordered delivery list */}
  </article>
</section>
```

Use a labelled progressbar:

```tsx
<div
  role="progressbar"
  aria-label={`Batch progress: ${completed} of ${total} delivered`}
  aria-valuenow={percent}
  aria-valuemin={0}
  aria-valuemax={100}
/>
```

Minimum primary and secondary tap targets are `min-h-11`.

- [ ] **Step 6: Implement offers, Up Next, and list cards**

- Show the first offer; place additional offers in a native `<details>` block.
- Keep decline reason hidden until Decline is selected.
- Show only one `up_next` card.
- Render lower-list work items collapsed.
- Render issue rows with parent batch/single label.
- Use text plus icon/shape for every status.
- Use “Delivery” and “Stop,” never “Leg.”

- [ ] **Step 7: Run the hierarchy tests**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: hierarchy tests PASS; action tests may still be pending.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
git commit -m "feat: redesign rider deliveries around current work"
```

---

### Task 4: Preserve rider actions, filtering, and offline feedback

**Files:**

- Modify: `resources/js/services/logisticsApi.ts`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

- [ ] **Step 1: Write failing contextual-action tests**

Cover:

- Offered batch Accept and on-demand Decline.
- Accepted batch Start batch.
- Assigned standalone with approved pickup proof Confirm pickup.
- Assigned proof-free standalone Confirm pickup through `markPickedUp`.
- Picked-up batch uses `outForDelivery`.
- Picked-up standalone uses generic `markInTransit`.
- In-transit delivery can submit proof or report the existing issue.
- Awaiting-proof and unresolved-issue waiting states show no state-changing action.
- A legacy active conflict shows the conflict notice and disables Start, pickup, transit, proof, and issue mutations while retaining offer responses, Call, Directions, and View details.
- Any successful mutation reloads only `deliveryData`.

- [ ] **Step 2: Write failing filter and offline tests**

```ts
fireEvent.change(screen.getByLabelText('Business type'), { target: { value: 'repair' } });
expect(mocks.get).toHaveBeenCalledWith(
  '/erp/logistics/deliveries',
  expect.objectContaining({ business: 'repair', page: 1 }),
  expect.objectContaining({ preserveScroll: true, preserveState: true }),
);

fireEvent(window, new Event('offline'));
expect(screen.getByText(/Offline/)).toBeVisible();
```

Also test tab, time, search submission, Clear filters, and pagination links preserving query state.

- [ ] **Step 3: Run the component tests to verify they fail**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: FAIL because the new actions and controls are not wired.

- [ ] **Step 4: Expose the existing standalone in-transit endpoint**

Add:

```ts
markInTransit: (legId: number) =>
  axios.post(`/api/logistics/legs/${legId}/in-transit`),
```

Do not add a new route.

- [ ] **Step 5: Wire contextual actions**

Use existing APIs:

```ts
const advancePickup = (item: RiderDeliveryWorkItem, leg: TrackingShipmentLeg) => {
  const proof = leg.proofs?.filter(({ handoff_type }) => handoff_type === 'pickup').at(-1);
  if (proof) return logisticsApi.confirmPickup(leg.id, proof.id);
  return logisticsApi.markPickedUp(leg.id);
};

const startDelivery = (item: RiderDeliveryWorkItem, leg: TrackingShipmentLeg) =>
  item.kind === 'batch'
    ? logisticsApi.outForDelivery(leg.id)
    : logisticsApi.markInTransit(leg.id);
```

Reuse the existing proof endpoint:

```ts
const form = new FormData();
form.append('handoff_type', 'delivery');
form.append('proof_type', 'photo');
form.append('proof_file', file);
await axios.post(`/api/logistics/legs/${leg.id}/proof`, form, {
  headers: { 'Content-Type': 'multipart/form-data' },
});
```

Reuse the existing issue endpoint and fields. Do not add new reason codes or arrival behavior in Phase 1.

Disable only the action being submitted. On success:

```ts
router.reload({ only: ['deliveryData'] });
```

When `has_active_conflict` is true, do not dispatch Start, pickup, transit, proof, or issue mutations from this page. Accept and Decline remain available because they respond to a separate pending offer rather than advance competing active work. Phase 1 prevents new conflicts at the server start boundaries; server-side arbitration of already-corrupt active records remains Phase 3.

- [ ] **Step 6: Wire lower-list navigation**

Use one function:

```ts
const updateFilters = (patch: Partial<RiderDeliveryPageData['filters']>) =>
  router.get('/erp/logistics/deliveries', {
    ...deliveryData.filters,
    ...patch,
    page: 1,
  }, {
    preserveScroll: true,
    preserveState: true,
  });
```

Business options are All businesses, Retail, Repair. Tabs remain semantic buttons with `aria-current` or a tablist with correct keyboard behavior; prefer buttons unless full tab keyboard behavior is implemented.

- [ ] **Step 7: Add basic offline behavior**

Track `navigator.onLine` and listen for `online`/`offline`.

- Keep rendered data visible.
- Show Online with last render/sync time or Offline with the same timestamp.
- Disable server mutations while offline.
- Do not claim an offline action was saved.
- Announce the state through `role="status"`/`aria-live="polite"`.

- [ ] **Step 8: Run the frontend tests**

Run:

```bash
pnpm test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/js/services/logisticsApi.ts resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
git commit -m "feat: preserve rider delivery actions and filters"
```

---

### Task 5: Enforce one active work item

**Files:**

- Create: `app/Services/Logistics/RiderActiveWorkGuard.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php:18,242-248`
- Modify: `app/Services/Logistics/ShipmentLegService.php:18-49,52-64`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php:86-90`
- Modify: `tests/Feature/Logistics/BatchDispatchServiceTest.php`
- Modify: `tests/Feature/Logistics/ShipmentLegServiceTest.php`

- [ ] **Step 1: Write failing batch-start tests**

Add:

```php
public function test_rider_cannot_start_a_batch_while_a_standalone_delivery_is_active(): void;
public function test_rider_cannot_start_a_second_batch(): void;
public function test_repeating_start_for_the_same_batch_remains_idempotent(): void;
```

An active standalone leg has no batch and status in:

```php
['picked_up', 'in_transit', 'delivery_attempted', 'awaiting_proof_approval']
```

Expected validation key: `active_work`.

- [ ] **Step 2: Write failing standalone-start tests**

Add:

```php
public function test_rider_cannot_start_a_standalone_delivery_while_a_batch_is_active(): void;
public function test_rider_cannot_start_a_second_standalone_delivery(): void;
public function test_repeating_start_for_the_same_standalone_delivery_is_idempotent(): void;
```

Start means the transition from Assigned/Pickup scheduled into Picked up, through either `markPickedUp()` or `confirmPickup()`.

- [ ] **Step 3: Run the focused backend tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php
```

Expected: new guard tests FAIL.

- [ ] **Step 4: Add the shared guard**

```php
final class RiderActiveWorkGuard
{
    private const ACTIVE_LEG_STATUSES = [
        'picked_up',
        'in_transit',
        'delivery_attempted',
        'awaiting_proof_approval',
    ];

    public function assertCanStartBatch(RiderProfile $rider, DeliveryBatch $batch): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $hasBatch = DeliveryBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'in_progress')
            ->whereKeyNot($batch->id)
            ->exists();

        $hasStandalone = $this->activeStandaloneQuery($rider)->exists();
        $this->reject($hasBatch || $hasStandalone);
    }

    public function assertCanStartStandalone(RiderProfile $rider, ShipmentLeg $leg): void
    {
        RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();

        $hasBatch = DeliveryBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'in_progress')
            ->exists();

        $hasStandalone = $this->activeStandaloneQuery($rider)
            ->whereKeyNot($leg->id)
            ->exists();

        $this->reject($hasBatch || $hasStandalone);
    }

    private function activeStandaloneQuery(RiderProfile $rider)
    {
        return ShipmentLeg::query()
            ->whereNull('delivery_batch_id')
            ->whereIn('status', self::ACTIVE_LEG_STATUSES)
            ->whereHas('latestAssignment', fn ($query) => $query
                ->where('rider_profile_id', $rider->id)
                ->whereIn('status', ['assigned', 'accepted']));
    }

    private function reject(bool $blocked): void
    {
        if ($blocked) {
            throw ValidationException::withMessages([
                'active_work' => 'Finish your current delivery before starting another.',
            ]);
        }
    }
}
```

Do not add an interface or configuration layer.

- [ ] **Step 5: Guard batch start inside the existing transaction**

Inject `RiderActiveWorkGuard` into `BatchDispatchService`. In `start()`, call it inside the `riderTransition()` callback before setting `in_progress`. Preserve the existing same-batch idempotent return.

- [ ] **Step 6: Guard standalone pickup inside a transaction**

Inject `RiderActiveWorkGuard` into `ShipmentLegService`.

Change:

```php
public function markPickedUp(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
```

For standalone legs:

- require the assigned rider argument;
- lock the leg;
- return the already Picked up leg without another event when the same start is repeated;
- call `assertCanStartStandalone()` before transition.

For batched legs, preserve the existing in-progress-batch check.

In `confirmPickup()`, call `markPickedUp($leg, $rider)`.

In `ShipmentController::pickedUp()`, pass `$this->assignedRiderProfile($leg)` whenever the leg is standalone or the authenticated user is its assigned rider. Preserve authorized dispatcher behavior for batched operational updates.

- [ ] **Step 7: Run the focused backend tests**

Run:

```bash
php artisan test tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/DeliveryExecutionTest.php
```

Expected: PASS, including existing idempotency and pickup-proof behavior.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Logistics/RiderActiveWorkGuard.php app/Services/Logistics/BatchDispatchService.php app/Services/Logistics/ShipmentLegService.php app/Http/Controllers/Api/Logistics/ShipmentController.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php
git commit -m "fix: prevent concurrent rider work"
```

---

### Task 6: Verify the complete Phase 1 slice

**Files:**

- Verify all files listed above.
- Do not modify dispatcher pages unless a regression test proves the rider changes broke shared code.

- [ ] **Step 1: Run PHP formatting checks**

Run:

```bash
vendor/bin/pint --test app/Http/Controllers/Logistics/ErpLogisticsController.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Models/Logistics/ShipmentLeg.php app/Services/Logistics/BatchDispatchService.php app/Services/Logistics/ShipmentLegService.php app/Services/Logistics/RiderActiveWorkGuard.php tests/Feature/Logistics/RiderMyDeliveriesPageTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php
```

Expected: PASS. If formatting fails, run the same command without `--test`, then rerun.

- [ ] **Step 2: Run the focused PHP suite**

```bash
php artisan test tests/Feature/Logistics/RiderMyDeliveriesPageTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/DeliveryExecutionTest.php
```

Expected: PASS.

- [ ] **Step 3: Run the focused frontend suite**

```bash
pnpm test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: PASS.

- [ ] **Step 4: Run the production frontend build**

```bash
pnpm run build
```

Expected: Vite build completes successfully.

- [ ] **Step 5: Verify mobile behavior**

At approximately 390 × 844:

- Current Delivery is the first operational card.
- Only the actionable delivery is expanded.
- Offer actions remain separate.
- Up Next is compact.
- Lower tabs and native filters do not overflow.
- Buttons are at least 44 px high.
- Status always has text plus a non-color cue.
- Loaded details remain visible after an offline event.
- No “Leg” or bulk-action language remains.

Use the in-app browser when available. If authentication prevents browser verification, report that limitation and rely on the component tests rather than bypassing sign-in.

- [ ] **Step 6: Inspect the final diff**

```bash
git diff --check
git status --short
git diff --stat
```

Expected:

- no whitespace errors;
- only planned files changed;
- existing unrelated lockfile or user changes remain untouched.

- [ ] **Step 7: Commit any verification-only corrections**

```bash
git add <only planned corrected files>
git commit -m "test: verify rider delivery workspace"
```

Skip this commit if verification required no corrections.
