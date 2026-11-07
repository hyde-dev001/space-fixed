# Logistics Shipment Cards and Product Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the text-heavy Logistics shipment table with responsive expandable cards and show the same tenant-safe retail product summary on Shipments, My Deliveries, and live Batch stops.

**Architecture:** Extend `ErpLogisticsController`'s existing source-summary attachment pattern: collect retail order IDs from the already-loaded shipments, load tenant-scoped orders with their item snapshots and optional products in one query, and attach a plain `order_summary` attribute. Reuse one React `RetailOrderSummary` component for compact and expanded presentations, while keeping all current shipment and batch action handlers in their existing pages.

**Tech Stack:** Laravel 12, Eloquent, Inertia.js, React 18, TypeScript, Tailwind CSS, Vitest/Testing Library, PHPUnit 11

---

## Scope Guardrails

- Keep one shipment as one delivery stop regardless of item quantity.
- Do not change shipping fees, rider capacity, delivery transitions, assignment rules, or proof workflows.
- Do not add a migration, package, API endpoint, service class, or polymorphic `Shipment` relation.
- Do not modify completed/cancelled Batch `stop_snapshot` or `cancelled_stops` payloads.
- Use `OrderItem` values as the model/image/color/size/quantity snapshots. Use `Product.brand` only when the related product still exists.
- Every order lookup, including search, must include the authorized `shop_owner_id`.

## File Map

- Modify `app/Http/Controllers/Logistics/ErpLogisticsController.php`
  - Validate and apply shipment search.
  - Attach repair and retail summaries without N+1 queries.
- Modify `tests/Feature/Logistics/LogisticsPageAccessTest.php`
  - Cover retail summaries, fallbacks, tenant isolation, dispatcher search, and rider scoping.
- Modify `resources/js/types/logistics.ts`
  - Define the shared `LogisticsOrderSummary` payload and attach it to shipment types.
- Create `resources/js/Pages/ERP/Logistics/components/RetailOrderSummary.tsx`
  - Render the compact Batch/card summary or the expanded variant list.
- Modify `resources/js/Pages/ERP/Logistics/Shipments.tsx`
  - Add server-side search and replace the table shell with responsive cards.
  - Keep the existing leg action block and mutation handlers.
- Modify `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`
  - Add the compact summary to available deliveries.
- Modify `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
  - Add the compact summary to live route stops.
- Modify `resources/js/Pages/ERP/Logistics/Batches.tsx`
  - Include supplied brand/model values in the existing client-side search.
- Modify `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
  - Cover cards, expansion, product variants, indicators, search, empty states, and retained actions.
- Modify `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
  - Cover compact summaries in the pool and live route stops.

### Task 1: Attach Tenant-Scoped Retail Order Summaries

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php:1-15,496-548`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php:33-133,136-211,233-302,346-383`

- [ ] **Step 1: Write failing summary and fallback tests**

Add `Order`, `OrderItem`, and `Product` imports to `LogisticsPageAccessTest.php`. Add focused tests beside `test_dispatcher_pages_include_repair_source_summary()`:

```php
public function test_logistics_pages_include_retail_order_variants_and_totals(): void
{
    $this->seed(RolesAndPermissionsSeeder::class);

    $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
    $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
    $dispatcher->assignRole('Logistics Dispatcher');
    Permission::findOrCreate('manage-logistics-batches', 'user');
    $dispatcher->givePermissionTo('manage-logistics-batches');

    $product = Product::create([
        'shop_owner_id' => $shop->id,
        'name' => 'Air Max 90',
        'slug' => 'air-max-90-logistics-test',
        'price' => 5000,
        'brand' => 'Nike',
    ]);
    $order = Order::factory()->create([
        'shop_owner_id' => $shop->id,
        'order_number' => 'ORD-LOG-1001',
    ]);
    $order->items()->createMany([
        [
            'product_id' => $product->id,
            'product_name' => 'Air Max 90',
            'price' => 5000,
            'quantity' => 2,
            'subtotal' => 10000,
            'size' => '9',
            'color' => 'Black',
            'product_image' => 'products/air-max-black.jpg',
        ],
        [
            'product_name' => 'Classic Runner',
            'price' => 3000,
            'quantity' => 3,
            'subtotal' => 9000,
            'size' => '8',
            'color' => 'White',
            'product_image' => 'products/classic-runner.jpg',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'shop_owner_id' => $shop->id,
        'source_type' => 'order',
        'source_id' => $order->id,
    ]);
    $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
    ShipmentLeg::factory()->create([
        'shipment_id' => $shipment->id,
        'delivery_batch_id' => $batch->id,
    ]);

    $shipmentProps = $this->actingAs($dispatcher, 'user')
        ->get('/erp/logistics/shipments')
        ->assertOk()
        ->viewData('page')['props'];
    $summary = collect($shipmentProps['shipments']['data'])
        ->firstWhere('id', $shipment->id)['order_summary'];

    $this->assertTrue($summary['available']);
    $this->assertSame('ORD-LOG-1001', $summary['order_number']);
    $this->assertSame(5, $summary['total_quantity']);
    $this->assertSame(2, $summary['variant_count']);
    $this->assertSame(2, $summary['model_count']);
    $this->assertCount(1, collect($shipmentProps['shipments']['data'])
        ->firstWhere('id', $shipment->id)['legs']);
    $this->assertSame([
        ['brand' => 'Nike', 'model' => 'Air Max 90', 'color' => 'Black', 'size' => '9', 'quantity' => 2],
        ['brand' => null, 'model' => 'Classic Runner', 'color' => 'White', 'size' => '8', 'quantity' => 3],
    ], collect($summary['items'])->map->only(['brand', 'model', 'color', 'size', 'quantity'])->all());

    $batchProps = $this->actingAs($dispatcher, 'user')
        ->get('/erp/logistics/batches')
        ->assertOk()
        ->viewData('page')['props'];
    $this->assertSame(
        $summary,
        collect($batchProps['batches'])->firstWhere('id', $batch->id)['legs'][0]['shipment']['order_summary'],
    );
}
```

Add `test_missing_and_cross_shop_orders_use_safe_logistics_fallbacks()` that creates:

- A local `order` shipment whose `source_id` does not exist.
- A local `order` shipment whose `source_id` points at another shop's order.
- A local order item whose `product_id` points at another shop's Product.
- An `order_refund` shipment.
- A `repair_request` shipment.

Assert that the two invalid retail sources receive:

```php
[
    'available' => false,
    'order_id' => $shipment->source_id,
    'order_number' => null,
    'items' => [],
    'total_quantity' => 0,
    'variant_count' => 0,
    'model_count' => 0,
]
```

Assert that `order_refund` and `repair_request` payloads do not contain an `order_summary` key. This proves cross-shop source-ID manipulation cannot expose order data.

For the local item that references another shop's Product, assert its saved model/color/size/quantity remain available but its `brand` is `null`. This covers tenant isolation at both the Order and Product relations.

Also update `test_logistics_rider_only_sees_assigned_shipments()` so its assigned shipment references a real tenant order, then assert that shipment's `order_summary.order_number` is present in `/erp/logistics/deliveries`. Keep the existing assertion that the other rider's shipment is absent.

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/LogisticsPageAccessTest.php --filter="retail_order_variants|missing_and_cross_shop"
```

Expected: FAIL because `order_summary` is not attached.

- [ ] **Step 3: Add the minimal controller summary assembler**

Import `App\Models\Order`. Replace direct calls to `attachRepairSourceSummaries()` with a wrapper:

```php
private function attachShipmentSummaries(iterable $shipments, int $shopOwnerId): void
{
    $this->attachRepairSourceSummaries($shipments, $shopOwnerId);
    $this->attachRetailOrderSummaries($shipments, $shopOwnerId);
}
```

Add the retail loader beside the existing repair loader:

```php
private function attachRetailOrderSummaries(iterable $shipments, int $shopOwnerId): void
{
    $shipments = collect($shipments)
        ->filter(fn ($shipment) => $shipment instanceof Shipment && $shipment->source_type === 'order')
        ->unique('id');
    if ($shipments->isEmpty()) {
        return;
    }

    $orders = Order::query()
        ->with(['items.product' => fn ($products) => $products
            ->where('shop_owner_id', $shopOwnerId)
            ->select('id', 'brand')])
        ->where('shop_owner_id', $shopOwnerId)
        ->whereIn('id', $shipments->pluck('source_id'))
        ->get()
        ->keyBy('id');

    $shipments->each(function (Shipment $shipment) use ($orders): void {
        $order = $orders->get($shipment->source_id);
        $items = $order?->items ?? collect();

        $shipment->setAttribute('order_summary', [
            'available' => (bool) $order,
            'order_id' => (int) $shipment->source_id,
            'order_number' => $order?->order_number,
            'items' => $items->map(fn ($item) => [
                'id' => (int) $item->id,
                'brand' => $item->product?->brand,
                'model' => $item->product_name ?: 'Product',
                'image' => $item->product_image,
                'color' => $item->color,
                'size' => $item->size,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
            'total_quantity' => (int) $items->sum('quantity'),
            'variant_count' => $items->count(),
            'model_count' => $items->pluck('product_name')
                ->map(fn ($name) => mb_strtolower(trim((string) $name)))
                ->filter()->unique()->count(),
        ]);
    });
}
```

Call `attachShipmentSummaries()` in exactly these live-data paths:

1. The dispatcher `shipments()` paginator tap.
2. The rider `deliveries()` paginator tap.
3. The existing `batches()` merged collection of Batch leg, pool, and unscheduled shipments.

Wrap the rider paginator the same way the dispatcher paginator is wrapped:

```php
'shipments' => tap(
    Shipment::query()
        // existing rider assignment-scoped query remains unchanged
        ->latest()->paginate(10)->withQueryString(),
    fn ($shipments) => $this->attachShipmentSummaries($shipments->getCollection(), $shopOwnerId),
),
```

Do not attach summaries to Batch snapshots.

- [ ] **Step 4: Run the focused and existing repair-summary tests**

Run:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/LogisticsPageAccessTest.php --filter="retail_order_variants|missing_and_cross_shop|repair_source_summary|logistics_rider_only_sees_assigned_shipments"
```

Expected: PASS. The existing repair summary remains unchanged.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Logistics/ErpLogisticsController.php tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: expose logistics order summaries"
```

### Task 2: Add Tenant-Safe Server-Side Shipment Search

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php:222-263,412-445`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php:33-211,303-345`

- [ ] **Step 1: Write failing dispatcher and rider search tests**

Add `test_dispatcher_searches_shipments_by_order_contact_and_product()`:

1. Create a tenant order with a known order number, customer name, phone, address, and two items.
2. Give one item a related Product brand and the other a saved `product_name`.
3. Create one Shipment/leg for that order and one unmatched Shipment.
4. Query `/erp/logistics/shipments?search=...` for:
   - shipment ID,
   - order number,
   - receiver name,
   - phone,
   - address,
   - product brand,
   - product model.
5. Assert every query returns only the matching shipment and that `filters.search` preserves the term.
6. Create an order under another shop with a unique brand/model and a local shipment pointing to its ID. Assert searching that brand/model returns no local shipment.
7. Create a local order item that points at another shop's Product. Assert searching that Product's brand does not return the local shipment.

Add `test_rider_search_remains_assignment_scoped()` by extending the setup pattern in `test_logistics_rider_only_sees_assigned_shipments()`:

- Give the rider's assigned Shipment an order whose item model is `Assigned Runner`.
- Give another rider's Shipment an order whose item model is `Hidden Runner`.
- `/erp/logistics/deliveries?search=Assigned%20Runner` returns the assigned Shipment.
- `/erp/logistics/deliveries?search=Hidden%20Runner` returns an empty paginator.

- [ ] **Step 2: Run the new search tests to verify they fail**

Run:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/LogisticsPageAccessTest.php --filter="searches_shipments|rider_search"
```

Expected: FAIL because `search` is ignored and absent from the returned filters.

- [ ] **Step 3: Validate search and find matching tenant order IDs**

In both `shipments()` and `deliveries()`, validate the trust-boundary input:

```php
$search = trim((string) ($request->validate([
    'search' => ['nullable', 'string', 'max:100'],
])['search'] ?? ''));
```

Add one private helper that applies all search terms to an existing Shipment query:

```php
private function filterShipmentsBySearch($query, string $search, int $shopOwnerId)
{
    if ($search === '') {
        return $query;
    }

    $like = "%{$search}%";
    $orderIds = Order::query()
        ->where('shop_owner_id', $shopOwnerId)
        ->where(function ($orders) use ($like, $shopOwnerId) {
            $orders
                ->where('order_number', 'like', $like)
                ->orWhere('customer_name', 'like', $like)
                ->orWhere('customer_phone', 'like', $like)
                ->orWhere('customer_address', 'like', $like)
                ->orWhere('shipping_address_line', 'like', $like)
                ->orWhere('shipping_barangay', 'like', $like)
                ->orWhere('shipping_city', 'like', $like)
                ->orWhere('shipping_province', 'like', $like)
                ->orWhereHas('items', fn ($items) => $items
                    ->where('product_name', 'like', $like)
                    ->orWhereHas('product', fn ($products) => $products
                        ->where('shop_owner_id', $shopOwnerId)
                        ->where('brand', 'like', $like)));
        })
        ->pluck('id');

    return $query->where(function ($shipments) use ($like, $orderIds) {
        $shipments
            ->where('id', 'like', $like)
            ->orWhere('source_id', 'like', $like)
            ->orWhereHas('legs', fn ($legs) => $legs
                ->where('origin_snapshot', 'like', $like)
                ->orWhere('destination_snapshot', 'like', $like))
            ->orWhere(fn ($retail) => $retail
                ->where('source_type', 'order')
                ->whereIn('source_id', $orderIds));
    });
}
```

Apply it after the existing tenant constraint and before pagination:

```php
->when($search !== '', fn ($query) => $this->filterShipmentsBySearch($query, $search, $shopOwnerId))
```

Add `'search' => $search` to both dispatcher and rider `filters`. Keep all existing status/module/window and rider-assignment clauses intact.

- [ ] **Step 4: Run search and access tests**

Run:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/LogisticsPageAccessTest.php --filter="searches_shipments|rider_search|only_sees_assigned|filter_shipments"
```

Expected: PASS. Product matching is tenant-scoped and the rider still cannot see another rider's shipment.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Logistics/ErpLogisticsController.php tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: search logistics shipments"
```

### Task 3: Define and Render the Shared Retail Summary

**Files:**
- Modify: `resources/js/types/logistics.ts:15-20,45-99,154-163`
- Create: `resources/js/Pages/ERP/Logistics/components/RetailOrderSummary.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx:8-18,45-50`

- [ ] **Step 1: Add a failing frontend summary test**

Extend `defaultProps()` in `Shipments.test.tsx` with:

```tsx
order_summary: {
  available: true,
  order_id: 10,
  order_number: 'ORD-LOG-1001',
  total_quantity: 5,
  variant_count: 2,
  model_count: 2,
  items: [
    { id: 101, brand: 'Nike', model: 'Air Max 90', image: 'products/air-max.jpg', color: 'Black', size: '9', quantity: 2 },
    { id: 102, brand: null, model: 'Classic Runner', image: null, color: 'White', size: '8', quantity: 3 },
  ],
},
```

Add a test that will initially fail:

```tsx
it('shows a compact retail summary and every variant when expanded', () => {
  render(<Shipments />);

  expect(screen.getByText('Nike Air Max 90')).toBeInTheDocument();
  expect(screen.getByText(/5 pairs/)).toBeInTheDocument();
  expect(screen.getByText(/2 variants/)).toBeInTheDocument();
  expect(screen.getByText(/\+1 more/)).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.getByText(/Black.*Size 9.*Qty 2/)).toBeInTheDocument();
  expect(screen.getByText('Classic Runner')).toBeInTheDocument();
  expect(screen.getByText(/White.*Size 8.*Qty 3/)).toBeInTheDocument();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx -t "compact retail summary"
```

Expected: FAIL because neither the types nor presentation exist.

- [ ] **Step 3: Add the shared payload types**

In `resources/js/types/logistics.ts`, add:

```ts
export type LogisticsOrderItemSummary = {
  id: number;
  brand?: string | null;
  model: string;
  image?: string | null;
  color?: string | null;
  size?: string | null;
  quantity: number;
};

export type LogisticsOrderSummary = {
  available: boolean;
  order_id: number;
  order_number?: string | null;
  items: LogisticsOrderItemSummary[];
  total_quantity: number;
  variant_count: number;
  model_count: number;
};
```

Add `order_summary?: LogisticsOrderSummary | null` to:

- `TrackingShipmentLeg.shipment`
- `TrackingShipment`
- `LogisticsShipment`

- [ ] **Step 4: Create the minimal reusable component**

Create `RetailOrderSummary.tsx` with two modes and no new dependency:

```tsx
import React from 'react';
import { Package } from 'lucide-react';
import type { LogisticsOrderSummary } from '@/types/logistics';

type Props = {
  summary?: LogisticsOrderSummary | null;
  expanded?: boolean;
  instructions?: string | null;
};

const imageUrl = (path?: string | null) => {
  if (!path) return null;
  if (/^(https?:|data:|\/)/i.test(path)) return path;
  return path.startsWith('storage/') ? `/${path}` : `/storage/${path}`;
};

const ProductImage = ({ path, alt }: { path?: string | null; alt: string }) => {
  const src = imageUrl(path);
  return src
    ? <img src={src} alt={alt} className="h-12 w-12 shrink-0 rounded-lg border border-gray-200 object-cover" />
    : <span aria-label="No product image" className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-400"><Package size={20} /></span>;
};

export default function RetailOrderSummary({ summary, expanded = false, instructions }: Props) {
  if (!summary) return null;
  if (!summary.available) return <p className="text-sm font-medium text-amber-700">Order details unavailable</p>;

  if (expanded) {
    return <section aria-label="Order items" className="space-y-2">
      <h3 className="font-semibold text-gray-950 dark:text-white">Order items</h3>
      {summary.items.map((item) => (
        <div key={item.id} className="flex items-center gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
          <ProductImage path={item.image} alt={item.model} />
          <div className="min-w-0 flex-1">
            <p className="font-semibold text-gray-950 dark:text-white">{[item.brand, item.model].filter(Boolean).join(' ')}</p>
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {[item.color, item.size ? `Size ${item.size}` : null, `Qty ${item.quantity}`].filter(Boolean).join(' · ')}
            </p>
          </div>
        </div>
      ))}
    </section>;
  }

  const first = summary.items[0];
  if (!first) return <p className="text-sm text-gray-500">No order items recorded</p>;
  const more = Math.max(0, summary.model_count - 1);

  return <div className="flex min-w-0 items-center gap-3">
    <ProductImage path={first.image} alt={first.model} />
    <div className="min-w-0">
      <p className="truncate text-sm font-semibold text-gray-950 dark:text-white">{[first.brand, first.model].filter(Boolean).join(' ')}</p>
      <p className="text-xs text-gray-500">
        {summary.total_quantity} {summary.total_quantity === 1 ? 'pair' : 'pairs'} · {summary.variant_count} {summary.variant_count === 1 ? 'variant' : 'variants'}
        {more > 0 ? ` · +${more} more` : ''}
      </p>
      {instructions && <p className="text-xs font-medium text-blue-700">Delivery instructions</p>}
    </div>
  </div>;
}
```

- [ ] **Step 5: Temporarily render the component in Shipments to make the test pass**

Import `RetailOrderSummary` in `Shipments.tsx`. In the current source cell and expanded shipment block, render:

```tsx
<RetailOrderSummary summary={shipment.order_summary} />
```

and:

```tsx
<RetailOrderSummary summary={shipment.order_summary} expanded />
```

Task 4 will move both instances into the final card layout.

- [ ] **Step 6: Run the focused test**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx -t "compact retail summary"
```

Expected: PASS.

- [ ] **Step 7: Commit**

```powershell
git add resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/components/RetailOrderSummary.tsx resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git commit -m "feat: render logistics product summaries"
```

### Task 4: Replace the Shipment Table With Responsive Cards

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx:1-14,81-120,280-533`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx:45-375`

- [ ] **Step 1: Write failing card, search, accessibility, and state tests**

Update the first existing test name from “delivery table” to “shipment card” and add these assertions/tests:

```tsx
it('renders responsive cards without a wide shipment table', () => {
  render(<Shipments />);
  expect(screen.queryByRole('table')).not.toBeInTheDocument();
  expect(screen.getByText('Miguel Dela Rosa')).toBeInTheDocument();
  expect(screen.getByText('Dasmariñas, Cavite')).toBeInTheDocument();
});

it('expands a shipment accessibly even when the user has no mutation permission', () => {
  mocks.props.canRecordProof = false;
  render(<Shipments />);
  const open = screen.getByRole('button', { name: 'Open delivery' });
  expect(open).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(open);
  expect(screen.getByRole('button', { name: 'Close delivery' })).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('region', { name: 'Shipment 1 details' })).toBeInTheDocument();
});

it('submits server-side search and resets pagination', () => {
  render(<Shipments />);
  fireEvent.change(screen.getByLabelText('Search shipments'), { target: { value: 'Air Max' } });
  fireEvent.submit(screen.getByRole('search'));
  expect(mocks.get).toHaveBeenCalledWith('/erp/logistics/deliveries', expect.objectContaining({
    search: 'Air Max',
    page: 1,
  }), expect.any(Object));
});
```

Add tests for:

- `Jul 20, 2026 · Morning` rather than a raw ISO string.
- `Urgent`, `Overdue`, `Failed attempt`, and `Awaiting proof` indicators when matching leg data exists.
- `No shipments yet.` when total is zero and all filters/search are defaults.
- `No shipments match your filters.` when total is zero and search or a non-default filter is active.
- `Order details unavailable` for an unavailable retail summary.
- Existing repair summary still appears without an Order items section.

Use `vi.useFakeTimers()` / `vi.setSystemTime(new Date('2026-07-21T00:00:00Z'))` in the overdue test and restore real timers afterward so it cannot become date-dependent.

- [ ] **Step 2: Run the new tests to verify they fail**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: FAIL on table removal, search, accessible expansion, formatted schedule, and state copy.

- [ ] **Step 3: Add small display helpers and search state**

Remove the table-component import. Import only the already-installed Lucide icons used by the card (`CalendarDays`, `ChevronDown`, `MapPin`, `Phone`, `Search`, `UserRound`).

Extend `ShipmentFilters`:

```ts
type ShipmentFilters = {
  status: string;
  purpose?: string;
  window: string;
  module?: 'all' | LogisticsModule;
  search?: string;
};
```

Add:

```tsx
const formatDate = (value?: string | null) => value
  ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' })
      .format(new Date(`${value.slice(0, 10)}T00:00:00Z`))
  : 'Not scheduled';

const shortAddress = (value: string, max = 72) =>
  value.length > max ? `${value.slice(0, max - 1).trimEnd()}…` : value;

const [search, setSearch] = useState(filters.search ?? '');
```

After `selectedModule` and `visiblePurposeOptions`, derive the empty-state flag without treating the enforced module of a single-module shop as a user-applied filter:

```tsx
const hasActiveFilters = Boolean(filters.search?.trim())
  || filters.status !== 'all'
  || (filters.purpose ?? 'all') !== 'all'
  || (filters.window ?? 'all') !== 'all'
  || (showModuleFilter && selectedModule !== 'all');
```

Render a semantic search form before the filters:

```tsx
<form role="search" onSubmit={(event) => {
  event.preventDefault();
  updateFilter('search', search.trim());
}} className="flex w-full max-w-md gap-2">
  <label className="relative min-w-0 flex-1">
    <span className="sr-only">Search shipments</span>
    <Search className="absolute left-3 top-2.5 text-gray-400" size={18} />
    <input
      aria-label="Search shipments"
      value={search}
      onChange={(event) => setSearch(event.target.value)}
      placeholder="Shipment, order, customer, or product"
      className="w-full rounded-lg border border-gray-300 py-2 pl-10 pr-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
    />
  </label>
  <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Search</button>
</form>
```

- [ ] **Step 4: Replace only the table shell with cards**

Keep every mutation function above the return statement unchanged. Replace the `<Table>` block with:

```tsx
<div className="space-y-3">
  {shipments.data.length === 0 ? (
    <div className="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800">
      {hasActiveFilters ? 'No shipments match your filters.' : 'No shipments yet.'}
    </div>
  ) : shipments.data.map((shipment) => {
    const legs = shipment.legs ?? [];
    const firstLeg = legs[0];
    const recipient = contact(firstLeg);
    const activeAssignments = legs
      .flatMap((leg) => leg.assignments ?? [])
      .filter((assignment) => ['assigned', 'accepted'].includes(assignment.status));
    const rider = activeAssignments[0]?.rider_profile?.name ?? 'Unassigned';
    const urgent = legs.some((leg) => Boolean(leg.urgent_at));
    const failed = legs.some((leg) => leg.attempts?.[0]?.status === 'failed');
    const awaitingProof = legs.some((leg) => leg.status === 'awaiting_proof_approval');
    const overdue = legs.some((leg) =>
      Boolean(leg.scheduled_delivery_date)
      && leg.scheduled_delivery_date!.slice(0, 10) < new Date().toISOString().slice(0, 10)
      && !['delivered', 'cancelled'].includes(leg.status));
    const expanded = expandedShipmentId === shipment.id;

    return <article key={shipment.id} className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
      <div className="grid gap-4 p-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto] lg:items-center">
        <div className="min-w-0 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <strong>Shipment #{shipment.id}</strong>
            <span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusClass(shipment.status)}`}>{label(shipment.status)}</span>
            <span>{label(shipment.purpose)}</span>
            <span>{shipment.source_type === 'order' && shipment.order_summary?.order_number
              ? `Order ${shipment.order_summary.order_number}`
              : logisticsSourceLabel(shipment)}</span>
          </div>
          {shipment.source_summary && <p className="text-sm text-gray-600">{shipment.source_summary.customer_name} · {shipment.source_summary.shoe_summary}</p>}
          <RetailOrderSummary summary={shipment.order_summary} />
        </div>
        <div className="grid gap-2 text-sm text-gray-600 dark:text-gray-300">
          <span className="inline-flex items-center gap-2"><UserRound size={16} />{recipient.name || 'Customer not provided'}</span>
          <span title={recipient.address || undefined} className="inline-flex items-start gap-2">
            <MapPin className="mt-0.5 shrink-0" size={16} />
            {recipient.address ? shortAddress(recipient.address) : 'Address not provided'}
          </span>
          <span className="inline-flex items-center gap-2"><CalendarDays size={16} />{formatDate(firstLeg?.scheduled_delivery_date)}{firstLeg?.delivery_window ? ` · ${label(firstLeg.delivery_window)}` : ''}</span>
          <span>Rider: {rider}</span>
          <div className="flex flex-wrap gap-2">
            {urgent && <span>Urgent</span>}
            {overdue && <span>Overdue</span>}
            {failed && <span>Failed attempt</span>}
            {awaitingProof && <span>Awaiting proof</span>}
          </div>
        </div>
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={`shipment-${shipment.id}-details`}
          onClick={() => setExpandedShipmentId(expanded ? null : shipment.id)}
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700"
        >
          {expanded ? 'Close delivery' : 'Open delivery'}
          <ChevronDown className={expanded ? 'rotate-180' : ''} size={16} />
        </button>
      </div>

      {expanded && (
        <div
          id={`shipment-${shipment.id}-details`}
          role="region"
          aria-label={`Shipment ${shipment.id} details`}
          className="space-y-4 border-t border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40"
        >
          {shipment.source_type === 'order' && <RetailOrderSummary summary={shipment.order_summary} expanded />}
          {/* Move the existing lines 405-516 leg details/actions block here unchanged,
              grouping its contact/schedule fields under "Delivery details" and its
              status/assignment/action controls under "Assignment and progress". */}
        </div>
      )}
    </article>;
  })}
</div>
```

When moving the existing leg block:

- Keep all permission checks and API endpoints exactly as they are.
- Keep assignment/proof errors inside this expanded region.
- Keep receiver, phone, address, instructions, schedule, and stop once per leg under a `Delivery details` heading.
- Use `formatDate()` for the schedule.
- Do not gate expansion on `hasActionColumn`; read-only users still need details.
- Remove `hasActionColumn` after confirming it has no remaining use.
- Leave the existing pagination summary and links below the card list unchanged.

- [ ] **Step 5: Run all Shipment and My Deliveries tests**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Expected: PASS. Existing scheduling, assignment, proof, failed-attempt, return-handoff, and rider-mode tests still pass.

- [ ] **Step 6: Commit**

```powershell
git add resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git commit -m "feat: show responsive logistics shipment cards"
```

### Task 5: Add Compact Product Summaries to Live Batch Stops

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx:69-86`
- Modify: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx:1-8,76-98`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx:1-5,42-59`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx:35-69,122-139,202-215`

- [ ] **Step 1: Add failing pool, search, and route-stop tests**

Give `scheduledLeg.shipment` a retail summary:

```tsx
order_summary: {
  available: true,
  order_id: 81,
  order_number: 'ORD-BATCH-81',
  total_quantity: 5,
  variant_count: 2,
  model_count: 2,
  items: [
    { id: 1, brand: 'Adidas', model: 'Ultraboost', image: 'products/ultraboost.jpg', color: 'Black', size: '9', quantity: 2 },
    { id: 2, brand: 'Puma', model: 'Suede', image: null, color: 'Blue', size: '8', quantity: 3 },
  ],
},
```

Give its destination snapshot `delivery_instructions: 'Call on arrival'`.

Add:

```tsx
it('shows the compact retail summary in the delivery pool and live route stop', () => {
  mocks.props.batches = [batchForStatus(1, 'draft')];
  mocks.props.batches[0].legs = [{ ...scheduledLeg, stop_sequence: 1 }];
  render(<Batches />);
  openBuilder();

  expect(screen.getAllByText('Adidas Ultraboost').length).toBeGreaterThan(0);
  expect(screen.getAllByText(/5 pairs/).length).toBeGreaterThan(0);
  expect(screen.getAllByText(/\+1 more/).length).toBeGreaterThan(0);
  expect(screen.getAllByText('Delivery instructions').length).toBeGreaterThan(0);

  fireEvent.click(screen.getByRole('button', { name: 'Edit batch 1' }));
  expect(screen.getAllByText('Adidas Ultraboost').length).toBeGreaterThan(1);
});
```

Extend the existing `searches by %s` table with:

```tsx
['brand', 'Adidas', 'Order #81'],
['model', 'Ultraboost', 'Order #81'],
```

Keep the existing repair source-summary test unchanged.

- [ ] **Step 2: Run the focused tests to verify they fail**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx -t "compact retail summary|searches by"
```

Expected: FAIL because Batch rows do not render or search order summaries.

- [ ] **Step 3: Reuse `RetailOrderSummary` in both live Batch row types**

In `AvailableDeliveriesPanel.tsx`, import the component and render it below the customer:

```tsx
<RetailOrderSummary
  summary={leg.shipment?.order_summary}
  instructions={leg.destination_snapshot?.delivery_instructions}
/>
```

Change the search placeholder to:

```tsx
placeholder="Order, customer, address, or product"
```

In `BatchStopRow.tsx`, render the same component after the phone/address lines:

```tsx
<RetailOrderSummary
  summary={leg.shipment?.order_summary}
  instructions={destination?.delivery_instructions}
/>
```

The component returns `null` for repair/refund stops and historical snapshots without `order_summary`.

- [ ] **Step 4: Include product values in the existing client-side Batch search**

Extend only the searched values in `Batches.tsx`:

```tsx
const products = leg.shipment?.order_summary?.items
  .flatMap((item) => [item.brand, item.model]) ?? [];

return [
  sourceLabel(leg),
  destination?.name,
  destination?.phone,
  destination?.address,
  ...products,
].some((value) => String(value ?? '').toLowerCase().includes(query));
```

Do not add another Batch filter or request.

- [ ] **Step 5: Run the full Batch test file**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: PASS, including existing route editing, module compatibility, history snapshots, cancellation, and repair summary tests.

- [ ] **Step 6: Commit**

```powershell
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: identify products in delivery batches"
```

### Task 6: Verify the Complete Logistics Change

**Files:**
- Verify only; fix failures in the smallest owning file.

- [ ] **Step 1: Run all Logistics backend tests**

Run:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics
```

Expected: all Logistics tests pass. The baseline before implementation was 201 tests and 880 assertions, so totals must be at least those values plus the new tests.

- [ ] **Step 2: Run all affected frontend tests**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: all tests pass. The baseline before implementation was 80 tests across these files, so the total must be at least 80 plus the new tests.

- [ ] **Step 3: Build the production frontend**

Run:

```powershell
.\node_modules\.bin\vite.cmd build
```

Expected: exit code 0 with no TypeScript or bundling error.

- [ ] **Step 4: Check the diff and branch**

Run:

```powershell
git diff --check
git status --short
git log --oneline -6
```

Expected:

- `git diff --check` prints nothing.
- Only intended tracked changes exist.
- The implementation commits are on `feature/logistics-shipment-cards`.

- [ ] **Step 5: Request code review before integration**

Use `superpowers:requesting-code-review` with:

- Spec: `docs/superpowers/specs/2026-07-26-logistics-shipment-cards-product-summary-design.md`
- Plan: `docs/superpowers/plans/2026-07-26-logistics-shipment-cards-product-summary.md`
- Base commit: `2654dddfe`
- Head commit: the final implementation commit

Fix only correctness, security, accessibility, or spec-alignment findings. Do not add speculative abstractions.
