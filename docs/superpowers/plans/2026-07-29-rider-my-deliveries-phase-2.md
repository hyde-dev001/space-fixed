# Rider My Deliveries Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` for the user-selected inline execution mode.

**Goal:** Add configurable GPS-assisted arrival verification, a clearer issue workflow, dispatcher arrival visibility, and customer-safe proof-of-delivery viewing without changing canonical delivery statuses or continuously tracking riders.

**Architecture:** Keep `ShipmentLeg` as the canonical delivery state and record pickup/drop-off arrival as internal `DeliveryEvent` rows. Reuse the existing shop coverage setting and Leaflet dependency, add one shared arrival-radius setting, serialize only safe arrival summaries to rider/dispatcher pages, store handoff originals on Laravel's private disk, and generate a metadata-free customer response on demand. Batched and standalone deliveries use the same leg endpoint and UI action.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia, React 18, TypeScript, Tailwind CSS, Leaflet, Vitest/Testing Library, PHPUnit, Spatie Image (already installed transitively).

---

## Working agreement

- Work only in `C:\xampp\htdocs\solespace-master\.worktrees\rider-my-deliveries-phase-2`.
- Branch: `feat/rider-my-deliveries-phase-2`.
- Base: latest `origin/solespace-b`.
- Use test-first steps: add the focused failing check, run it and confirm the expected failure, implement the minimum change, then rerun it.
- Do not add a geolocation package, map package, image package, background location service, arrival status enum, or customer-visible rider coordinates.
- Keep the original proof file byte-for-byte unchanged. The customer response is re-encoded in memory; the UI details are not burned into the image.
- Use the existing `local` disk (`storage/app/private`) for handoff originals and the existing `public` disk only for the separate failed-attempt evidence flow.
- Use `apply_patch` for edits and preserve unrelated user changes.

## Shared contracts

### Arrival request

`POST /api/logistics/legs/{leg}/arrivals`

```json
{
  "arrival_type": "pickup",
  "latitude": 14.3001,
  "longitude": 120.9502,
  "accuracy_m": 18,
  "captured_at": "2026-07-29T10:30:00.000Z",
  "exception_reason": null,
  "exception_notes": null
}
```

- `pickup` resolves the server-owned `origin_snapshot`.
- `dropoff` resolves the server-owned `destination_snapshot`.
- The request never accepts target coordinates, a radius, an event type, visibility, or actor IDs.
- A first outside/low-accuracy/unavailable request returns `422` on `exception_reason`.
- Resubmitting with an allowed reason records the unverified arrival and lets the rider continue.
- An existing `{leg, pickup_arrived|dropoff_arrived}` event is returned unchanged.

### Safe arrival summary

Only this shape may be serialized to the rider and dispatcher pages:

```ts
export type DeliveryArrival = {
  id: number;
  arrival_type: 'pickup' | 'dropoff';
  result: 'verified' | 'outside_geofence' | 'low_accuracy' | 'location_unavailable';
  distance_m?: number | null;
  radius_m: number;
  accuracy_m?: number | null;
  exception_reason?: string | null;
  exception_notes?: string | null;
  recorded_at: string;
};
```

Do not serialize submitted rider latitude/longitude from event metadata.

### Issue evidence matrix

| Rider reason | Code | Photo | Notes |
|---|---|---:|---:|
| Customer unavailable | `recipient_unavailable` | Required | Optional |
| Incorrect address | `wrong_or_incomplete_address` | Required | Optional |
| Customer refused | `recipient_refused` | Required | Optional |
| Item damaged | `item_damaged` | Required | Optional |
| Unsafe location | `unsafe_location` | Optional | Required |
| Vehicle problem | `vehicle_or_delivery_problem` | Optional | Required |
| Other | `other` | Optional | Required |

Do not require a rider to take a photo in an unsafe location.

---

## Task 1: Persist the arrival radius and complete new-leg coordinate snapshots

**Files:**

- Create: `database/migrations/2026_07_29_000001_add_arrival_radius_to_logistics_settings_table.php`
- Modify: `app/Models/Logistics/LogisticsSetting.php`
- Modify: `app/Http/Controllers/Api/Logistics/LogisticsSettingController.php`
- Modify: `app/Services/Logistics/SourceShipmentService.php`
- Test: `tests/Feature/Logistics/LogisticsSettingsTest.php`
- Test: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`

### Step 1: Add failing settings tests

Add assertions that:

- a new shop's settings default `arrival_radius_m` to `100`;
- `50` and `500` are accepted;
- `49`, `501`, decimals, and missing values are rejected;
- the existing `coverage_radius_km` remains the shared service-coverage value.

Representative test:

```php
public function test_arrival_radius_defaults_to_100_metres_and_accepts_50_to_500(): void
{
    $shop = ShopOwner::factory()->create();

    $this->actingAs($shop, 'shop_owner')
        ->getJson('/api/logistics/settings')
        ->assertOk()
        ->assertJsonPath('settings.arrival_radius_m', 100);

    foreach ([50, 500] as $radius) {
        $payload = LogisticsSetting::firstOrCreate(['shop_owner_id' => $shop->id])->toArray();
        $payload['arrival_radius_m'] = $radius;

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/logistics/settings', $payload)
            ->assertOk()
            ->assertJsonPath('settings.arrival_radius_m', $radius);
    }
}
```

Run:

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Feature/Logistics/LogisticsSettingsTest.php
```

Expected: FAIL because `arrival_radius_m` is absent and unvalidated.

### Step 2: Add the setting with the approved default and range

Migration:

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::table('logistics_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('arrival_radius_m')
                ->default(100)
                ->after('coverage_radius_km');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_settings', function (Blueprint $table) {
            $table->dropColumn('arrival_radius_m');
        });
    }
};
```

Add `arrival_radius_m` to `$fillable`, set the model default to `100`, cast it to `integer`, and add this controller rule:

```php
'arrival_radius_m' => ['required', 'integer', 'between:50,500'],
```

Keep the existing coverage rule:

```php
'coverage_radius_km' => ['required', 'numeric', 'gt:0'],
```

Run the settings test again.

Expected: PASS.

### Step 3: Add failing snapshot assertions

Extend existing source-shipment tests to assert:

- retail outbound shop origin contains the saved shop latitude/longitude;
- retail outbound customer destination still contains the accepted order-address coordinates;
- refund-return customer origin uses the accepted order-address coordinates;
- refund-return shop destination contains the saved shop coordinates;
- repair inbound and return snapshots retain both ends' coordinates.

Use explicit values such as:

```php
$shop->update(['shop_latitude' => 14.3011, 'shop_longitude' => 120.9522]);
$address->update(['latitude' => 14.3122, 'longitude' => 120.9611]);
```

Run:

```powershell
php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php
```

Expected: FAIL on retail-origin and refund-return coordinate assertions.

### Step 4: Populate only accepted source coordinates

In `ensureRetailOrderShipment()`, add:

```php
'latitude' => $order->shopOwner?->shop_latitude !== null
    ? (float) $order->shopOwner->shop_latitude
    : null,
'longitude' => $order->shopOwner?->shop_longitude !== null
    ? (float) $order->shopOwner->shop_longitude
    : null,
```

In `ensureRefundReturnShipment()`:

```php
$refund->loadMissing('order.shopOwner', 'order.address', 'customer');
$order = $refund->order;
$address = $order?->address;
```

Then add customer origin coordinates from `$address` and shop destination coordinates from `$order->shopOwner`.

Do not backfill or guess legacy coordinates from address text.

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsSettingsTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php
```

Expected: PASS.

### Step 5: Commit

```powershell
git add -- database/migrations/2026_07_29_000001_add_arrival_radius_to_logistics_settings_table.php app/Models/Logistics/LogisticsSetting.php app/Http/Controllers/Api/Logistics/LogisticsSettingController.php app/Services/Logistics/SourceShipmentService.php tests/Feature/Logistics/LogisticsSettingsTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php
git commit -m "feat: add logistics arrival radius"
```

---

## Task 2: Add the read-only Leaflet service-area preview

**Files:**

- Create: `resources/js/Pages/ERP/Logistics/DeliveryCoverageMap.tsx`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `resources/js/Pages/ERP/Logistics/Settings.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`

### Step 1: Add failing page-prop and UI tests

Backend assertion:

```php
->where('shopLocation.latitude', (float) $shop->shop_latitude)
->where('shopLocation.longitude', (float) $shop->shop_longitude)
->where('shopLocation.address', $shop->business_address)
```

Frontend assertions:

- `Arrival check radius (metres)` is initialized to `100`;
- the map component receives the saved shop pin and current coverage radius;
- editing `coverage_radius_km` updates the map prop immediately;
- a missing shop pin shows `Set the shop location in Shop Settings` and no map;
- arrival-radius help text explains pickup and customer arrival checks;
- numeric inputs remain labelled and keyboard accessible.

Mock the map component in `Settings.test.tsx`; do not make JSDOM render Leaflet.

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=settings
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx
```

Expected: FAIL because `shopLocation`, the arrival field, and map preview are absent.

### Step 2: Pass the saved shop pin as display-only data

Change `ErpLogisticsController::settings()` to load the shop once:

```php
$shop = ShopOwner::query()->findOrFail(
    $this->authorizedShopOwnerId('configure-logistics-settings')
);

return Inertia::render('ERP/Logistics/Settings', [
    'settings' => LogisticsSetting::firstOrCreate(['shop_owner_id' => $shop->id]),
    'shopLocation' => [
        'latitude' => $shop->shop_latitude !== null ? (float) $shop->shop_latitude : null,
        'longitude' => $shop->shop_longitude !== null ? (float) $shop->shop_longitude : null,
        'address' => $shop->business_address,
    ],
]);
```

Do not add a second shop-pin editor.

### Step 3: Implement the minimum Leaflet preview

`DeliveryCoverageMap.tsx` owns one read-only map, marker, and circle:

```tsx
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef } from 'react';

type Props = {
  latitude: number;
  longitude: number;
  radiusKm: number;
};

export default function DeliveryCoverageMap({ latitude, longitude, radiusKm }: Props) {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!containerRef.current) return;
    let disposed = false;
    let map: import('leaflet').Map | undefined;

    void import('leaflet').then((L) => {
      if (disposed || !containerRef.current) return;
      map = L.map(containerRef.current, { dragging: true, scrollWheelZoom: false })
        .setView([latitude, longitude], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
      }).addTo(map);
      L.circleMarker([latitude, longitude], {
        radius: 7,
        color: '#1d4ed8',
        fillColor: '#2563eb',
        fillOpacity: 1,
      }).addTo(map).bindTooltip('Saved shop location');
      const circle = L.circle([latitude, longitude], {
        radius: Math.max(radiusKm, 0) * 1000,
        color: '#2563eb',
        fillColor: '#60a5fa',
        fillOpacity: 0.18,
      }).addTo(map);
      map.fitBounds(circle.getBounds(), { padding: [20, 20] });
    });

    return () => {
      disposed = true;
      map?.remove();
    };
  }, [latitude, longitude, radiusKm]);

  return <div ref={containerRef} className="h-72 w-full rounded-xl" aria-label="Delivery service area map" />;
}
```

This intentionally recreates the small map when radius changes. A settings page changes rarely; no extra map abstraction is needed.

### Step 4: Group the two radius controls

Update the settings type:

```ts
arrival_radius_m: number;
```

Render a `Delivery service area` section containing:

- coverage-radius numeric input in kilometres;
- textual summary: `Addresses within X km of the saved shop pin qualify`;
- `DeliveryCoverageMap` only when both coordinates exist;
- missing-pin message and link to `/shop-owner/settings`;
- arrival-radius numeric input with `min={50}`, `max={500}`, `step={1}`;
- help text: `Used when riders tap I've arrived at pickup or customer locations.`

Use a minimum 44 px control height (`min-h-11`).

### Step 5: Verify and commit

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=settings
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx
```

Expected: PASS.

```powershell
git add -- app/Http/Controllers/Logistics/ErpLogisticsController.php resources/js/Pages/ERP/Logistics/DeliveryCoverageMap.tsx resources/js/Pages/ERP/Logistics/Settings.tsx resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: preview logistics delivery coverage"
```

---

## Task 3: Record idempotent pickup and drop-off arrivals

**Files:**

- Create: `app/Services/Logistics/ArrivalService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Logistics/DeliveryArrivalTest.php`

### Step 1: Write the failing API tests

Create focused tests for:

1. assigned rider records a verified pickup arrival;
2. assigned rider records a verified drop-off arrival;
3. pickup targets `origin_snapshot`, drop-off targets `destination_snapshot`;
4. leg status does not change;
5. event visibility is `internal`;
6. metadata stores result, distance, radius, accuracy, capture time, submitted coordinates, and actor;
7. a second identical or changed request returns the first event and leaves one row;
8. outside radius, low accuracy, missing GPS, stale/future GPS, and missing target coordinates require a reason;
9. each exception records after an allowed reason is supplied;
10. `other` requires notes;
11. wrong rider, cross-tenant rider, customer, dispatcher-only actor, and unassigned rider receive `403`;
12. pickup in the wrong status and drop-off outside `in_transit` receive `422`;
13. a leg in an accepted-but-not-started batch cannot record arrival until its batch is `in_progress`;
14. invalid latitude, longitude, accuracy, and reason are rejected;
15. the API response uses the safe arrival shape and never returns submitted coordinates or raw event metadata.

Helper payload:

```php
private function arrivalPayload(string $type = 'pickup'): array
{
    return [
        'arrival_type' => $type,
        'latitude' => 14.3000,
        'longitude' => 120.9500,
        'accuracy_m' => 15,
        'captured_at' => now()->toISOString(),
    ];
}
```

Run:

```powershell
php artisan test tests/Feature/Logistics/DeliveryArrivalTest.php
```

Expected: FAIL with route not found.

### Step 2: Implement the service using the existing event table

`ArrivalService` should expose one method:

```php
public function record(ShipmentLeg $leg, User $actor, array $payload): DeliveryEvent
```

Core transaction:

```php
return DB::transaction(function () use ($leg, $actor, $payload) {
    $leg = ShipmentLeg::query()
        ->with('shipment.shopOwner.logisticsSetting')
        ->lockForUpdate()
        ->findOrFail($leg->id);

    $eventType = $payload['arrival_type'] === 'pickup'
        ? 'pickup_arrived'
        : 'dropoff_arrived';

    if ($existing = $leg->events()->where('event_type', $eventType)->first()) {
        return $existing;
    }

    if ($leg->delivery_batch_id) {
        $batch = DeliveryBatch::query()->lockForUpdate()->find($leg->delivery_batch_id);
        if (! $batch || $batch->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'batch' => ['Start this batch before recording an arrival.'],
            ]);
        }
    }

    $allowed = $payload['arrival_type'] === 'pickup'
        ? ['assigned', 'pickup_scheduled']
        : ['in_transit'];
    if (! in_array($leg->status->value, $allowed, true)) {
        throw ValidationException::withMessages([
            'status' => ['This delivery is no longer ready for that arrival action. Refresh and try again.'],
        ]);
    }

    $radius = (int) ($leg->shipment->shopOwner->logisticsSetting?->arrival_radius_m ?? 100);
    $target = $payload['arrival_type'] === 'pickup'
        ? $leg->origin_snapshot
        : $leg->destination_snapshot;
    $check = $this->classify($target, $payload, $radius);
    $this->requireExceptionReason($check, $payload);

    return $this->events->record($leg->shipment, $leg, [
        'event_type' => $eventType,
        'visibility' => 'internal',
        'message' => $this->message($payload['arrival_type'], $check['result']),
        'metadata' => [
            ...$check,
            'accuracy_m' => $payload['accuracy_m'] ?? null,
            'captured_at' => $payload['captured_at'] ?? null,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'exception_reason' => $payload['exception_reason'] ?? null,
            'exception_notes' => $payload['exception_notes'] ?? null,
        ],
        'created_by_type' => User::class,
        'created_by_id' => $actor->id,
    ]);
});
```

Use one private Haversine function returning metres. Reuse the same formula already used by attendance/coverage; do not refactor unrelated services in this phase.

Classification order:

1. missing target coordinates -> `location_unavailable`;
2. missing submitted coordinates/accuracy/capture time -> `location_unavailable`;
3. capture time older than five minutes or more than one minute in the future -> `location_unavailable`;
4. accuracy greater than the configured radius -> `low_accuracy`;
5. calculated distance greater than the radius -> `outside_geofence`;
6. otherwise -> `verified`.

Only `verified` may be recorded without `exception_reason`.

Allowed reasons:

```php
private const EXCEPTION_REASONS = [
    'gps_inaccurate',
    'pin_incorrect',
    'alternate_meeting_point',
    'access_restriction',
    'safety_concern',
    'other',
];
```

Use `ValidationException::withMessages(['exception_reason' => [$plainLanguageMessage]])` so the current Axios error handling can display the server decision.

### Step 3: Add the guarded endpoint

Controller validation:

```php
$payload = $request->validate([
    'arrival_type' => ['required', 'in:pickup,dropoff'],
    'latitude' => ['nullable', 'numeric', 'between:-90,90'],
    'longitude' => ['nullable', 'numeric', 'between:-180,180'],
    'accuracy_m' => ['nullable', 'numeric', 'between:0,5000'],
    'captured_at' => ['nullable', 'date'],
    'exception_reason' => ['nullable', 'in:gps_inaccurate,pin_incorrect,alternate_meeting_point,access_restriction,safety_concern,other'],
    'exception_notes' => ['nullable', 'string', 'max:1000'],
]);
```

Authorization:

```php
$actor = $this->authorizeAttemptActor($leg);
abort_unless($this->userHasActiveAssignment($leg, $actor), 403);
$event = $arrivals->record($leg, $actor, $payload);

return response()->json([
    'arrival' => [
        'id' => $event->id,
        'arrival_type' => $event->event_type === 'pickup_arrived' ? 'pickup' : 'dropoff',
        'result' => data_get($event->metadata, 'result'),
        'distance_m' => data_get($event->metadata, 'distance_m'),
        'radius_m' => data_get($event->metadata, 'radius_m'),
        'accuracy_m' => data_get($event->metadata, 'accuracy_m'),
        'exception_reason' => data_get($event->metadata, 'exception_reason'),
        'exception_notes' => data_get($event->metadata, 'exception_notes'),
        'recorded_at' => $event->created_at?->toISOString(),
    ],
], $event->wasRecentlyCreated ? 201 : 200);
```

Map each field explicitly. Do not return the Eloquent event or its raw metadata because that contains submitted rider coordinates.

Route:

```php
Route::post('/legs/{leg}/arrivals', [ShipmentController::class, 'arrival']);
```

### Step 4: Verify and commit

Run:

```powershell
php artisan test tests/Feature/Logistics/DeliveryArrivalTest.php
```

Expected: PASS.

```powershell
git add -- app/Services/Logistics/ArrivalService.php app/Http/Controllers/Api/Logistics/ShipmentController.php routes/web.php tests/Feature/Logistics/DeliveryArrivalTest.php
git commit -m "feat: record rider arrival verification"
```

---

## Task 4: Add the contextual I've arrived rider workflow

**Files:**

- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/services/logisticsApi.ts`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts`

### Step 1: Add failing safe-read-model tests

Create one pickup and one drop-off event whose metadata includes exact coordinates.

Assert the rider page receives:

```php
delivery.arrivals.pickup.result
delivery.arrivals.pickup.distance_m
delivery.arrivals.pickup.recorded_at
```

Also assert the serialized page does **not** contain the submitted latitude/longitude or raw `events` relation.

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=rider
```

Expected: FAIL because arrival events are not loaded or summarized.

### Step 2: Add one controller-local safe mapper

Eager-load only arrival events on batch and standalone legs:

```php
'legs.events' => fn ($query) => $query
    ->whereIn('event_type', ['pickup_arrived', 'dropoff_arrived'])
    ->oldest('id'),
```

and:

```php
'events' => fn ($query) => $query
    ->whereIn('event_type', ['pickup_arrived', 'dropoff_arrived'])
    ->oldest('id'),
```

Before returning `deliveryPayload()`:

```php
$payload = $leg->toArray();
unset($payload['events']);
$payload['arrivals'] = $leg->events
    ->keyBy(fn ($event) => $event->event_type === 'pickup_arrived' ? 'pickup' : 'dropoff')
    ->map(fn ($event) => [
        'id' => $event->id,
        'arrival_type' => $event->event_type === 'pickup_arrived' ? 'pickup' : 'dropoff',
        'result' => data_get($event->metadata, 'result'),
        'distance_m' => data_get($event->metadata, 'distance_m'),
        'radius_m' => data_get($event->metadata, 'radius_m'),
        'accuracy_m' => data_get($event->metadata, 'accuracy_m'),
        'exception_reason' => data_get($event->metadata, 'exception_reason'),
        'exception_notes' => data_get($event->metadata, 'exception_notes'),
        'recorded_at' => $event->created_at?->toISOString(),
    ])->all();
```

Never pass metadata wholesale.

### Step 3: Add failing rider interaction tests

Mock `navigator.geolocation.getCurrentPosition` and `logisticsApi.arrive`.

Cover:

- assigned/pickup-scheduled without pickup arrival shows one primary `I've arrived`;
- verified pickup arrival replaces it with `Confirm pickup`;
- in-transit without drop-off arrival shows `I've arrived` and hides proof submission;
- recorded drop-off arrival exposes proof submission;
- geolocation uses `{ enableHighAccuracy: true, maximumAge: 0 }`;
- outside/low/missing-target `422` reveals a reason form;
- permission denied reveals the same reason form without claiming arrival was saved;
- `Other` requires notes;
- a successful exception submission refreshes only `deliveryData`;
- offline disables arrival and says Retry after reconnect;
- repeated taps use the existing immediate in-flight lock.

Run:

```powershell
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts
```

Expected: FAIL because arrival types, API, and controls are absent.

### Step 4: Add the types and API call

In `resources/js/types/logistics.ts`:

```ts
export type DeliveryArrival = {
  id: number;
  arrival_type: 'pickup' | 'dropoff';
  result: 'verified' | 'outside_geofence' | 'low_accuracy' | 'location_unavailable';
  distance_m?: number | null;
  radius_m: number;
  accuracy_m?: number | null;
  exception_reason?: string | null;
  exception_notes?: string | null;
  recorded_at: string;
};
```

Add to `TrackingShipmentLeg`:

```ts
arrivals?: Partial<Record<'pickup' | 'dropoff', DeliveryArrival>>;
```

In `logisticsApi.ts`:

```ts
arrive: (legId: number, payload: Record<string, unknown>) =>
  axios.post(`/api/logistics/legs/${legId}/arrivals`, payload),
```

### Step 5: Implement one-time browser geolocation

Keep the helper in `MyDeliveries.tsx`; it has one caller:

```ts
const currentPosition = () => new Promise<GeolocationPosition>((resolve, reject) => {
  if (!navigator.geolocation) {
    reject(new Error('Location is unavailable on this device.'));
    return;
  }
  navigator.geolocation.getCurrentPosition(resolve, reject, {
    enableHighAccuracy: true,
    timeout: 10_000,
    maximumAge: 0,
  });
});
```

Build the payload only from the returned browser position:

```ts
{
  arrival_type: phase,
  latitude: position.coords.latitude,
  longitude: position.coords.longitude,
  accuracy_m: position.coords.accuracy,
  captured_at: new Date(position.timestamp).toISOString(),
}
```

Do not cache the position and do not watch the rider.

### Step 6: Implement the compact exception form

Show it only after:

- browser geolocation fails; or
- the endpoint responds with `422` on `exception_reason`.

Reason labels:

```ts
const arrivalReasons = [
  ['gps_inaccurate', 'GPS location is inaccurate'],
  ['pin_incorrect', 'Shop or customer pin is incorrect'],
  ['alternate_meeting_point', 'Met at another location'],
  ['access_restriction', 'Road or access restriction'],
  ['safety_concern', 'Safety concern'],
  ['other', 'Other'],
] as const;
```

The retry payload includes the last captured GPS evidence when available. If browser location failed, submit null evidence plus the required reason.

Extend `ActionRunner` with one optional error callback so this component can consume reason-required `422` responses without replacing existing errors:

```ts
type ActionRunner = (
  key: string,
  action: () => Promise<unknown>,
  confirmation?: ActionConfirmation,
  onError?: (error: unknown) => boolean,
) => void;
```

Routine `I've arrived` does not show a confirmation dialog. Existing irreversible pickup/proof confirmations stay unchanged.

### Step 7: Make arrival the contextual gate

- pickup state + no `arrivals.pickup` -> `I've arrived`;
- pickup state + arrival -> `Confirm pickup`;
- in transit + no `arrivals.dropoff` -> `I've arrived`;
- in transit + arrival -> proof controls and Report issue;
- show text such as `Verified arrival · 18 m · 10:30 AM` or `Outside service point · rider reason recorded`;
- communicate the result through text/icon and `aria-live`, not color alone.

Use the same logic for `item.kind === 'batch'` and `item.kind === 'single'`.

### Step 8: Verify and commit

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=rider
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts
```

Expected: PASS.

```powershell
git add -- app/Http/Controllers/Logistics/ErpLogisticsController.php resources/js/types/logistics.ts resources/js/services/logisticsApi.ts resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts tests/Feature/Logistics/LogisticsPageAccessTest.php resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts
git commit -m "feat: guide riders through verified arrival"
```

---

## Task 5: Show safe arrival results to dispatchers

**Files:**

- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

### Step 1: Add failing dispatcher tests

Backend:

- dispatcher page includes safe pickup/drop-off summaries;
- dispatcher batch details include the same summaries for their stops;
- exact submitted rider coordinates are absent;
- another shop cannot see the event.

Frontend:

- verified result shows `Verified arrival`, distance, and timestamp;
- outside result shows `Outside geofence` and the rider reason;
- low accuracy and location unavailable use distinct text;
- status has a text/icon indicator rather than color alone.
- shipment and batch-stop details use the same safe summary shape and wording.

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=dispatcher
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL because dispatcher legs do not contain arrival summaries.

### Step 2: Reuse the controller's safe mapper

In the dispatcher shipment and batch queries, eager-load only pickup/drop-off events. Before Inertia serializes each leg:

```php
$leg->setAttribute('arrivals', $this->arrivalPayload($leg));
$leg->unsetRelation('events');
```

Extract the mapper added in Task 4 into one private controller method used by both `deliveryPayload()` and dispatcher serialization. Do not create a service for a presentation-only array.

### Step 3: Render a compact delivery-timeline block

Inside each expanded dispatcher shipment leg and batch stop, render up to two rows:

```text
Pickup arrival   Verified arrival · 18 m · 10:30 AM
Customer arrival Outside geofence · 142 m · Pin incorrect
```

Map reason codes to rider-friendly text in the component. Show notes only to dispatcher/rider pages, never customer tracking.

Keep this inside the existing expanded delivery/batch-stop cards; do not redesign either dispatcher page.

### Step 4: Verify and commit

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=dispatcher
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: PASS.

```powershell
git add -- app/Http/Controllers/Logistics/ErpLogisticsController.php resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx tests/Feature/Logistics/LogisticsPageAccessTest.php resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: show arrival checks to dispatchers"
```

---

## Task 6: Make rider issue evidence conditional and show dispatcher instructions

**Files:**

- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`
- Test: `tests/Feature/Logistics/DeliveryExecutionTest.php`
- Test: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

### Step 1: Add failing backend evidence tests

For each reason in the shared matrix, assert:

- required evidence is rejected when absent;
- valid required evidence is accepted;
- optional evidence may be omitted;
- the existing assignment/tenant/idempotency guards still apply;
- temporary uploaded files are removed if attempt recording fails;
- customer tracking exposes only the safe reason label, not internal notes.

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/CustomerTrackingTest.php
```

Expected: FAIL on new reasons and optional-photo cases.

### Step 2: Make the service authoritative

Add public constants to `ShipmentLegService`:

```php
public const PHOTO_REQUIRED_REASONS = [
    'recipient_unavailable',
    'wrong_or_incomplete_address',
    'recipient_refused',
    'item_damaged',
];

public const NOTES_REQUIRED_REASONS = [
    'unsafe_location',
    'vehicle_or_delivery_problem',
    'other',
];
```

At the start of `recordFailedAttempt()` enforce the matrix so every caller follows it. Remove the current batch-only blanket photo requirement.

```php
if (in_array($payload['reason_code'], self::PHOTO_REQUIRED_REASONS, true)
    && empty($payload['file_path'])) {
    throw ValidationException::withMessages(['proof_file' => ['A photo is required for this reason.']]);
}

if (in_array($payload['reason_code'], self::NOTES_REQUIRED_REASONS, true)
    && blank($payload['notes'] ?? null)) {
    throw ValidationException::withMessages(['notes' => ['Add a short note for this reason.']]);
}
```

### Step 3: Mirror trust-boundary validation in the controller

Use `Rule::requiredIf()` for `proof_file` and `notes`, expand `reason_code`, and guard optional cleanup:

```php
$storedPath = null;
if ($request->hasFile('proof_file')) {
    $storedPath = $request->file('proof_file')->store("logistics-attempt/{$leg->id}", 'public');
    $payload['file_path'] = $storedPath;
}

try {
    $attempt = $legs->recordFailedAttempt(...);
    if ($storedPath && $attempt->file_path !== $storedPath) {
        Storage::disk('public')->delete($storedPath);
    }
} catch (\Throwable $exception) {
    if ($storedPath) {
        Storage::disk('public')->delete($storedPath);
    }
    throw $exception;
}
```

Update cancellation/customer reason labels for `item_damaged` and `unsafe_location`.

### Step 4: Add failing frontend tests

Assert:

- all seven rider-friendly reasons appear;
- photo input is marked required only for photo reasons;
- notes are marked required only for note reasons;
- unsafe-location submission works without a photo;
- dispatcher resolution text renders from `resolution_type` and `resolution_reason`;
- `retry` says `Dispatcher scheduled another attempt`;
- `return_required` says `Return item to shop`;
- batch and standalone delivery cards use the same instruction component.

Run:

```powershell
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: FAIL.

### Step 5: Implement the rider form and instructions

Keep two frontend arrays in `MyDeliveries.tsx`:

```ts
const photoIssueReasons = new Set([
  'recipient_unavailable',
  'wrong_or_incomplete_address',
  'recipient_refused',
  'item_damaged',
]);
const noteIssueReasons = new Set([
  'unsafe_location',
  'vehicle_or_delivery_problem',
  'other',
]);
```

Reset a no-longer-required file/notes validation state when the reason changes. Preserve selected evidence when it remains valid.

Add a small presentation helper:

```ts
export const riderResolutionInstruction = (delivery: TrackingShipmentLeg) => {
  if (delivery.resolution_type === 'retry') {
    return `Dispatcher scheduled another attempt${delivery.resolution_reason ? `: ${delivery.resolution_reason}` : ''}`;
  }
  if (delivery.resolution_type === 'return_required') {
    return `Return item to shop${delivery.resolution_reason ? `: ${delivery.resolution_reason}` : ''}`;
  }
  return null;
};
```

Render it in the current card and expanded sequence as a non-color-only notice.

The legacy `riderMode` issue form in `Shipments.tsx` is currently unreachable from the rider navigation, but keep its reason labels/evidence matrix consistent because the component still contains that route mode.

### Step 6: Verify and commit

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/CustomerTrackingTest.php
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: PASS.

```powershell
git add -- app/Services/Logistics/ShipmentLegService.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/Logistics/CustomerTrackingService.php resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts resources/js/Pages/ERP/Logistics/Shipments.tsx tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/CustomerTrackingTest.php resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git commit -m "feat: clarify rider delivery issues"
```

---

## Task 7: Move handoff originals to private storage

**Files:**

- Create: `app/Console/Commands/MoveHandoffProofsToPrivate.php`
- Modify: `app/Http/Requests/Logistics/RecordHandoffProofRequest.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`
- Test: `tests/Feature/Logistics/MoveHandoffProofsToPrivateTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

### Step 1: Add failing private-storage tests

Assert:

- a photo proof request requires an actual uploaded image;
- `file_path` is prohibited at the HTTP boundary;
- upload is written to `Storage::disk('local')`;
- no public file is created;
- dispatcher can fetch the raw original through an authenticated, tenant-checked endpoint;
- another tenant/rider/customer cannot fetch it;
- dispatcher preview uses the endpoint rather than `/storage/{file_path}`.

Update old `LogisticsApiTest` cases that post `file_path` to upload `UploadedFile` instead. Direct `ProofService` unit tests may still pass internal paths.

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: FAIL because handoff files still use the public disk and arbitrary paths are accepted.

### Step 2: Harden upload validation and storage

`RecordHandoffProofRequest`:

```php
'proof_file' => [
    Rule::requiredIf(fn () => $this->input('proof_type') === 'photo'),
    'nullable',
    'file',
    'mimes:jpg,jpeg,png,webp',
    'max:5120',
],
'file_path' => ['prohibited'],
```

`ShipmentController::proof()`:

```php
$payload['file_path'] = $request->file('proof_file')
    ?->store("logistics-proof/{$leg->id}", 'local');
```

Keep server-generated paths only.

### Step 3: Add the dispatcher-only raw-file endpoint

Route:

```php
Route::get('/proofs/{proof}/file', [ShipmentController::class, 'proofFile']);
```

Controller:

```php
public function proofFile(HandoffProof $proof)
{
    $shop = $this->authorizedShop('view-logistics-shipments');
    $proof->loadMissing('leg.shipment');
    $this->abortUnlessTenant((int) $proof->leg->shipment->shop_owner_id, $shop);
    abort_unless($proof->file_path && Storage::disk('local')->exists($proof->file_path), 404);

    return Storage::disk('local')->response($proof->file_path);
}
```

Replace dispatcher preview URLs with:

```tsx
`/api/logistics/proofs/${proof.id}/file`
```

Do not expose `file_path` in a URL.

### Step 4: Add a failing idempotent migration-command test

Create a public proof fixture and assert the command:

- copies identical bytes to the same path on `local`;
- verifies the private write before deleting public;
- removes the public copy;
- leaves the model path unchanged;
- reports missing source files without inventing data;
- can run twice safely;
- removes a stale public duplicate when private already exists **only when SHA-256 hashes match**;
- keeps both copies and reports a conflict when public/private bytes differ;
- keeps the public original when a new private write cannot be verified byte-for-byte.
- `--restore-public` copies verified private bytes back to public without deleting private, so an application rollback cannot strand proofs;
- restore mode refuses to overwrite a different public file and exits non-zero on conflicts.

Run:

```powershell
php artisan test tests/Feature/Logistics/MoveHandoffProofsToPrivateTest.php
```

Expected: FAIL because the command does not exist.

### Step 5: Implement the one-purpose command

Signature:

```php
protected $signature = 'logistics:move-handoff-proofs-private
    {--restore-public : Restore verified public copies before rolling application code back}';
```

Process `HandoffProof::whereNotNull('file_path')->chunkById(100, ...)`.

For each path:

1. if private exists and public does not, count `already private`;
2. if both exist, compare SHA-256 hashes; delete public only when hashes match, otherwise keep both and count `conflict`;
3. if only public exists, read bytes, write to private, compare SHA-256 hashes, then delete public only after an exact match;
4. if neither exists, count `missing`;
5. on a failed private write/hash verification, remove only the unverified new private copy, keep public, and count `failed`.

Do not alter bytes or database paths in this command.

In `--restore-public` mode, reverse the copy direction, verify matching SHA-256 hashes, keep the private original, and refuse to overwrite a different public file. This mode exists only for a controlled code rollback to the pre-private-storage release.

### Step 6: Verify and commit

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/MoveHandoffProofsToPrivateTest.php
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: PASS.

```powershell
git add -- app/Console/Commands/MoveHandoffProofsToPrivate.php app/Http/Requests/Logistics/RecordHandoffProofRequest.php app/Http/Controllers/Api/Logistics/ShipmentController.php resources/js/Pages/ERP/Logistics/Shipments.tsx routes/web.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/MoveHandoffProofsToPrivateTest.php resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git commit -m "feat: secure logistics handoff proofs"
```

---

## Task 8: Expose approved POD safely to the owning customer

**Files:**

- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `app/Http/Controllers/Logistics/CustomerTrackingController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`
- Test: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Test: `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

### Step 1: Add failing payload-selection tests

Create proof combinations and assert:

- delivered + approved + photo + `delivery` is selected;
- `delivery` beats `receive`;
- within the same handoff type, newest `reviewed_at`, then highest ID wins;
- pickup, pending, rejected, non-photo, failed-attempt, and non-delivered proofs are excluded;
- an eligible record with a missing file returns `available: false`, `url: null`, and no storage path;
- payload includes only proof ID, availability, URL, delivered time, destination summary, tracking number, and `Delivered`.

Expected shape:

```php
'delivery_proof' => [
    'id' => $proof->id,
    'available' => true,
    'url' => route('customer.tracking.delivery-proof', [$shipment, $proof]),
    'delivered_at' => $leg->delivered_at?->toISOString(),
    'location' => $this->snapshotLabel($leg->destination_snapshot),
    'tracking_number' => $leg->tracking_number ?: "SHP-{$shipment->id}",
    'status' => 'Delivered',
],
```

Run:

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php
```

Expected: FAIL because `delivery_proof` is absent.

### Step 2: Load and select proofs deterministically

Add:

```php
'legs.proofs' => fn ($query) => $query
    ->whereIn('handoff_type', ['delivery', 'receive'])
    ->where('proof_type', 'photo')
    ->where('review_status', 'approved')
    ->orderByRaw("CASE WHEN handoff_type = 'delivery' THEN 0 ELSE 1 END")
    ->orderByDesc('reviewed_at')
    ->orderByDesc('id'),
```

Only attach `delivery_proof` when the leg status is `delivered`. Check existence on `Storage::disk('local')`.

Do not include raw event metadata, file paths, rejection notes, or GPS data.

### Step 3: Add failing ownership and sanitization tests

Assert the customer proof route:

- requires authentication;
- permits only the shipment owner;
- verifies proof -> leg -> shipment;
- rejects cross-shipment and cross-customer requests;
- rejects pending/rejected/pickup/non-photo/non-delivered proof;
- returns `404` when the private original is missing;
- strips appended metadata sentinel bytes from the response;
- leaves the private original bytes unchanged;
- supports inline and `?download=1` dispositions;
- never reads a public-disk duplicate.

Use a valid tiny JPEG plus an appended `GPS-SENTINEL` string. Re-encoding must remove the sentinel while the stored original remains byte-for-byte equal.

Run the customer tracking test again.

Expected: FAIL because the route is absent.

### Step 4: Add the customer-owned sanitized response

Route:

```php
Route::get(
    '/tracking/shipments/{shipment}/proofs/{proof}',
    [CustomerTrackingController::class, 'deliveryProof']
)->middleware('auth:user')->name('customer.tracking.delivery-proof');
```

Eligibility checks stay in the controller method and use the existing `customerOwnsShipment()` check.

Re-encode in memory with the already-installed image library, explicitly using its GD driver so the response cannot retain an Imagick image profile:

```php
use Spatie\Image\Enums\ImageDriver as ImageDriverEnum;
use Spatie\Image\Image;

$disk = Storage::disk('local');
abort_unless($proof->file_path && $disk->exists($proof->file_path), 404);
abort_unless(extension_loaded('gd'), 503);

$mime = $disk->mimeType($proof->file_path);
$format = match ($mime) {
    'image/jpeg' => 'jpeg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => abort(404),
};

$encoded = Image::useImageDriver(ImageDriverEnum::Gd)
    ->loadFile($disk->path($proof->file_path))
    ->orientation()
    ->base64($format, false);
$bytes = base64_decode($encoded, true);
abort_unless(is_string($bytes), 404);

$disposition = request()->boolean('download') ? 'attachment' : 'inline';

return response($bytes, 200, [
    'Content-Type' => $mime,
    'Content-Disposition' => "{$disposition}; filename=\"delivery-proof-{$proof->id}.{$format}\"",
    'Cache-Control' => 'private, no-store',
    'X-Content-Type-Options' => 'nosniff',
]);
```

The GD re-encode strips EXIF/embedded metadata. There is no fallback that serves the raw original. It does not save a second file or modify the original.

### Step 5: Add failing proof-viewer tests

Create `ShipmentTracking.test.tsx` if it does not exist. Assert:

- `View proof of delivery` appears only for `delivery_proof`;
- unavailable proof renders `Proof unavailable` and no broken image;
- button opens an accessible modal/dialog;
- image, date/time, destination, tracking number, and Delivered status appear;
- details are HTML beside/over the viewer, not part of the image URL;
- close and download controls have at least 44 px targets and accessible names;
- zoom controls work through 100%, 150%, and 200%;
- image-load failure changes the dialog to `Proof unavailable`;
- failed-attempt proof remains a separate section.

Run:

```powershell
npm.cmd run test:frontend -- resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
```

Expected: FAIL because the viewer is absent.

### Step 6: Implement the mobile-first POD viewer

Add a `CustomerDeliveryProof` type and `delivery_proof?: CustomerDeliveryProof | null` to `TrackingShipmentLeg`.

In each delivered movement row:

```tsx
{leg.delivery_proof?.available ? (
  <button type="button" onClick={() => setSelectedProof(leg.delivery_proof!)}>
    View proof of delivery
  </button>
) : leg.delivery_proof ? (
  <span>Proof unavailable</span>
) : null}
```

Dialog behavior:

- full viewport on mobile, centered large panel on desktop;
- close button first in focus order;
- `role="dialog"`, `aria-modal="true"`, labelled title;
- image uses the customer proof URL and meaningful alt text;
- zoom buttons set scale to `1`, `1.5`, or `2`;
- metadata appears in an adjacent/overlay panel that stays readable independently of the image;
- download link uses `${proof.url}?download=1`;
- Escape closes the dialog and focus returns to the opener;
- loading/error text uses `aria-live`.

Use local component state and CSS transforms only; do not add a gallery dependency.

### Step 7: Verify and commit

Run:

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php
npm.cmd run test:frontend -- resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
```

Expected: PASS.

```powershell
git add -- app/Services/Logistics/CustomerTrackingService.php app/Http/Controllers/Logistics/CustomerTrackingController.php routes/web.php resources/js/types/logistics.ts resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx tests/Feature/Logistics/CustomerTrackingTest.php resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
git commit -m "feat: show customer proof of delivery"
```

---

## Task 9: Run Phase 2 regression, refresh the public build, and prepare the branch

**Files:**

- Modify: `public/build/**` generated assets and manifest
- Verify: `docs/superpowers/specs/2026-07-29-rider-my-deliveries-redesign-design.md`
- Verify: `docs/git-workflow.md`

### Step 1: Format only changed PHP files

```powershell
vendor\bin\pint.bat --dirty
git status --short
```

Expected: only Phase 2 files and generated build files later; no unrelated workspace changes.

If Pint changes code, rerun the affected targeted PHP tests before continuing.

### Step 2: Run the focused backend regression

```powershell
$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
php artisan test tests/Feature/Logistics/LogisticsSettingsTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/Logistics/DeliveryArrivalTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/CustomerTrackingTest.php tests/Feature/Logistics/MoveHandoffProofsToPrivateTest.php tests/Feature/Logistics/ReturnToShopTest.php
```

Expected: PASS with no failures or errors. Existing non-failing warnings must be reported, not hidden.

### Step 3: Run the focused frontend regression

```powershell
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
```

Expected: PASS.

### Step 4: Run the whole logistics backend suite

```powershell
php artisan test tests/Feature/Logistics
```

Expected: PASS.

### Step 5: Build the fresh public frontend

```powershell
npm.cmd run build
```

Expected:

- exit code `0`;
- `public/build/manifest.json` exists;
- generated assets include the updated logistics settings, rider, dispatcher, and customer tracking chunks;
- no missing-import or TypeScript build error.

### Step 6: Commit the verified build

```powershell
git add -- public/build
git commit -m "build: refresh public frontend assets"
```

If application-formatting changes remained after Task 8, commit those with their owning task instead of hiding them in the build commit.

### Step 7: Rebase on the latest integration branch

```powershell
git fetch origin solespace-b
git rebase origin/solespace-b
```

Expected: clean rebase. Resolve only Phase 2 conflicts; never discard unrelated upstream work.

If the rebase changes any touched application file, repeat Steps 2–5 and amend only the build commit if generated hashes changed.

### Step 8: Run non-destructive deployment checks locally

```powershell
php artisan migrate --pretend
php artisan list | Select-String 'logistics:move-handoff-proofs-private'
```

Expected:

- migration SQL includes `arrival_radius_m`;
- the proof migration command is registered;
- the fake-disk test from Task 7 proves the first and repeated runs.

Do not run the data-moving command against the developer's existing storage as a verification shortcut. Production deployment must run it after database/file backup and before customer POD links are enabled.

### Step 9: Push without creating a PR

```powershell
git push -u origin feat/rider-my-deliveries-phase-2
```

The user will create the pull request manually.

### Step 10: Final handoff evidence

Report:

- branch and final commit SHA;
- targeted backend result;
- full Logistics suite result;
- frontend result;
- build result and manifest path;
- proof-migration dry/local result;
- any warnings;
- confirmation that no PR was created.

---

## Deployment order

1. Back up the production database and `storage/app/public/logistics-proof`.
2. Deploy application code and fresh `public/build`.
3. Run `php artisan migrate --force`.
4. Run `php artisan logistics:move-handoff-proofs-private`.
5. Verify dispatcher proof previews and one owning-customer POD response.
6. Confirm public copies under `storage/app/public/logistics-proof` were removed only after verified private writes.
7. If application rollback is required, run `php artisan logistics:move-handoff-proofs-private --restore-public` while the Phase 2 code is still deployed, confirm a zero exit code and matching hash counts, then roll application code back. Keep the private originals. Do not roll code back when restore reports a conflict.

## Acceptance checklist

- [ ] One shared coverage radius still governs retail delivery, repair pickup, and repair return.
- [ ] Logistics Settings shows the saved shop pin and a read-only Leaflet coverage circle.
- [ ] Arrival radius defaults to 100 m and accepts only 50–500 m.
- [ ] New retail/refund/repair leg snapshots contain accepted source coordinates.
- [ ] Batched and standalone deliveries use the same arrival endpoint.
- [ ] Arrival is internal, idempotent, assignment-checked, and status-neutral.
- [ ] Only fresh, sufficiently accurate, in-radius evidence becomes Verified.
- [ ] Outside, low-accuracy, unavailable, and legacy no-coordinate flows require a reason but do not block work.
- [ ] Offline UI never claims an arrival was saved.
- [ ] Dispatcher sees result, distance/time, and reason without exact rider coordinates.
- [ ] Issue evidence follows the approved conditional matrix.
- [ ] Rider sees dispatcher retry/return instructions.
- [ ] HTTP proof uploads cannot select `file_path`.
- [ ] Handoff originals are private and dispatcher access is tenant-checked.
- [ ] Owning customer sees only approved delivery/receive photos for Delivered legs.
- [ ] POD choice is deterministic.
- [ ] Customer image response strips metadata and leaves the original unchanged.
- [ ] Proof details are UI overlay/adjacent content, not a permanent watermark.
- [ ] Missing proof degrades to `Proof unavailable`.
- [ ] No new dependency, continuous GPS tracking, new canonical arrival status, or durable offline queue was added.
