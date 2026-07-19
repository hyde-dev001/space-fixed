# Customer Address Logistics Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let customers pin saved addresses with Leaflet and prevent staff from selecting Shop-owned Logistics when the order address is outside the shop's configured coverage radius.

**Architecture:** Add one coverage-only contract to the existing `DeliveryScheduleService`, then reuse it in customer shipping estimates, staff order payloads, staff status validation, and shipment scheduling. Add one shared Leaflet picker consumed by Checkout and Payment; keep carrier selection in Staff Job Orders and leave the downstream shipment, batch, rider, tracking, and refund flows unchanged.

**Tech Stack:** Laravel 12/PHP, Eloquent, React 18/TypeScript, Inertia, Leaflet 1.9, Vitest, PHPUnit.

---

## File Map

- Modify `app/Services/Logistics/DeliveryScheduleService.php`: expose the canonical coverage-only result and reuse it during scheduling.
- Modify `app/Http/Controllers/UserSide/ShippingEstimateController.php`: resolve a customer-owned saved address and append Shop-owned eligibility to the existing estimate response.
- Modify `app/Http/Controllers/UserSide/UserAddressController.php`: apply the same Philippine coordinate bounds used by registration.
- Modify `app/Http/Controllers/Api/StaffOrderController.php`: include eligibility in retail-order JSON and reject invalid Shop-owned shipping updates.
- Create `resources/js/components/address/CustomerAddressMapPicker.tsx`: shared Leaflet click/drag/search/GPS/reverse-geocode picker.
- Modify `resources/js/Pages/UserSide/Orders/Checkout.tsx`: send picker coordinates when adding or editing an address.
- Modify `resources/js/Pages/UserSide/Orders/payment.tsx`: use the picker, send `address_id` to estimates, and display Shop-owned eligibility.
- Modify `resources/js/Pages/ERP/STAFF/JobOrders.tsx`: default or disable Shop-owned Logistics in Mark as Shipped.
- Modify focused PHP and Vitest files listed in each task below.

No migration, package installation, new API route, Logistics Shipments/Batches UI change, or customer carrier selector is needed.

### Task 1: Canonical Coverage-Only Contract

**Files:**
- Modify: `tests/Feature/Logistics/DeliveryScheduleServiceTest.php`
- Modify: `app/Services/Logistics/DeliveryScheduleService.php`

- [ ] **Step 1: Write the failing coverage-contract test**

Add a test proving that coverage depends only on coordinates and radius, not rider capacity:

```php
public function test_coverage_contract_is_independent_of_rider_capacity(): void
{
    $shop = ShopOwner::factory()->create([
        'shop_latitude' => 14.5995,
        'shop_longitude' => 120.9842,
    ]);
    LogisticsSetting::create([
        'shop_owner_id' => $shop->id,
        'coverage_radius_km' => 5,
    ]);
    $service = app(DeliveryScheduleService::class);

    $inside = $service->coverage($shop, 14.60, 120.98);
    $outside = $service->coverage($shop, 14.6760, 121.0437);

    $this->assertTrue($inside['available']);
    $this->assertNull($inside['reason']);
    $this->assertSame(5.0, $inside['coverage_radius_km']);
    $this->assertFalse($outside['available']);
    $this->assertSame('outside_coverage', $outside['reason']);
    $this->assertSame('address_needs_pin', $service->coverage($shop, null, null)['reason']);

    LogisticsSetting::query()->where('shop_owner_id', $shop->id)->firstOrFail()
        ->update(['coverage_radius_km' => $inside['distance_km']]);
    $shop->unsetRelation('logisticsSetting');
    $this->assertTrue($service->coverage($shop, 14.60, 120.98)['available']);

    $shop->update(['shop_latitude' => null, 'shop_longitude' => null]);
    $this->assertSame('shop_needs_pin', $service->coverage($shop->fresh(), 14.60, 120.98)['reason']);
}
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php artisan test tests/Feature/Logistics/DeliveryScheduleServiceTest.php --filter=coverage_contract`

Expected: FAIL because `DeliveryScheduleService::coverage()` does not exist.

- [ ] **Step 3: Implement the minimal coverage method**

Add a public method with this stable response shape:

Add `use Illuminate\Support\Facades\Log;` for the fail-closed warning.

```php
public function coverage(ShopOwner $shop, ?float $latitude, ?float $longitude): array
{
    try {
        $settings = $shop->logisticsSetting
            ?: LogisticsSetting::firstOrCreate(['shop_owner_id' => $shop->id]);
    } catch (\Throwable $exception) {
        Log::warning('Logistics coverage lookup failed', ['shop_owner_id' => $shop->id, 'message' => $exception->getMessage()]);
        return ['available' => false, 'reason' => 'logistics_unavailable', 'distance_km' => null, 'coverage_radius_km' => null];
    }
    $radius = (float) $settings->coverage_radius_km;

    if ($latitude === null || $longitude === null) {
        return ['available' => false, 'reason' => 'address_needs_pin', 'distance_km' => null, 'coverage_radius_km' => $radius];
    }
    if ($shop->shop_latitude === null || $shop->shop_longitude === null) {
        return ['available' => false, 'reason' => 'shop_needs_pin', 'distance_km' => null, 'coverage_radius_km' => $radius];
    }

    $distance = round($this->distanceKm(
        (float) $shop->shop_latitude,
        (float) $shop->shop_longitude,
        $latitude,
        $longitude,
    ), 2);

    return [
        'available' => $distance <= $radius,
        'reason' => $distance <= $radius ? null : 'outside_coverage',
        'distance_km' => $distance,
        'coverage_radius_km' => $radius,
    ];
}
```

Refactor `estimate()` to call `coverage()` first and translate its unavailable reasons back to existing scheduling statuses:

```php
$coverage = $this->coverage($shop, $destinationLatitude, $destinationLongitude);
if (!$coverage['available']) {
    $status = match ($coverage['reason']) {
        'address_needs_pin' => 'needs_coordinates',
        'shop_needs_pin' => 'needs_shop_coordinates',
        'outside_coverage' => 'outside_coverage',
        default => 'needs_capacity',
    };
    return $this->result($status, distance: $coverage['distance_km']);
}
$distance = $coverage['distance_km'];
```

- [ ] **Step 4: Run the service regression tests**

Run: `php artisan test tests/Feature/Logistics/DeliveryScheduleServiceTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`

Expected: PASS; existing scheduling statuses and shipment creation remain unchanged.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/DeliveryScheduleService.php tests/Feature/Logistics/DeliveryScheduleServiceTest.php
git commit -m "feat: expose logistics coverage eligibility"
```

### Task 2: Saved-Address Shipping Estimate

**Files:**
- Modify: `tests/Feature/UserSide/ShippingEstimateControllerTest.php`
- Modify: `app/Http/Controllers/UserSide/ShippingEstimateController.php`

- [ ] **Step 1: Write failing estimate and ownership tests**

Create a shop, product belonging to that shop, authenticated customer, and pinned `UserAddress`. Post the existing structured address fields plus `item_pids` and `address_id`, then assert:

```php
->assertJsonPath('shop_owned.available', true)
->assertJsonPath('shop_owned.reason', null)
->assertJsonPath('shop_owned.coverage_radius_km', 10.0);
```

Add an ownership case using another customer's address:

```php
$this->actingAs($customer, 'user')
    ->postJson('/api/shipping/estimate', $this->payloadFor($product, $otherAddress))
    ->assertUnprocessable()
    ->assertJsonValidationErrors('address_id');
```

- [ ] **Step 2: Run the focused tests and verify they fail**

Run: `php artisan test tests/Feature/UserSide/ShippingEstimateControllerTest.php`

Expected: FAIL because `address_id` and `shop_owned` are not in the current contract.

- [ ] **Step 3: Extend the existing endpoint without changing third-party pricing**

Inject `DeliveryScheduleService`, validate nullable `address_id`, and resolve it only through the authenticated customer. Add imports for `Auth` and `ValidationException`:

```php
$address = null;
if (!empty($validated['address_id'])) {
    $user = Auth::guard('user')->user();
    $address = $user?->addresses()->find($validated['address_id']);
    if (!$address) {
        throw ValidationException::withMessages(['address_id' => 'The selected address is invalid.']);
    }
}

$shopOwned = $this->deliverySchedules->coverage(
    $shopOwner,
    $address?->latitude !== null ? (float) $address->latitude : null,
    $address?->longitude !== null ? (float) $address->longitude : null,
);
```

Append `'shop_owned' => $shopOwned` to successful and fallback JSON. Let `fallbackResponse()` accept an optional coverage result; when the shop cannot be resolved or a coverage lookup unexpectedly fails, return the conservative object below instead of omitting eligibility or throwing HTTP 500:

```php
['available' => false, 'reason' => 'logistics_unavailable', 'distance_km' => null, 'coverage_radius_km' => null]
```

Continue using text geocoding and OSRM for the existing third-party fee; never use route distance for the coverage decision.

Remove trust in `shop_owner_id` for customer coverage requests: resolve the single shop from `item_pids`, and return a validation error if those products span multiple shops.

- [ ] **Step 4: Run estimate tests**

Run: `php artisan test tests/Feature/UserSide/ShippingEstimateControllerTest.php`

Expected: PASS, including the pre-existing third-party response assertion.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/UserSide/ShippingEstimateController.php tests/Feature/UserSide/ShippingEstimateControllerTest.php
git commit -m "feat: include saved-address coverage in shipping estimate"
```

### Task 3: Staff Retail Coverage Payload and Server Enforcement

**Files:**
- Create: `tests/Feature/Logistics/StaffRetailShippingCoverageTest.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`

- [ ] **Step 1: Write failing staff API tests**

Use `RefreshDatabase`, create `access-staff-job-orders`, and authenticate a staff user belonging to the order's shop. Cover these cases:

```php
public function test_staff_orders_expose_shop_owned_coverage(): void
{
    // Arrange pinned customer address inside a 10 km radius.
    $this->actingAs($staff, 'user')->getJson('/api/staff/orders')
        ->assertOk()
        ->assertJsonPath('0.shop_owned_coverage.available', true);
}

public function test_staff_cannot_ship_outside_coverage_with_shop_owned_logistics(): void
{
    $this->actingAs($staff, 'user')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'shipped',
            'carrier_company' => 'Shop-owned logistics',
            'eta' => '1-3 business days',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('carrier_company');

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing']);
    $this->assertDatabaseMissing('shipments', ['source_type' => 'order', 'source_id' => $order->id]);
}
```

Also assert that the same outside-coverage order can be shipped through a complete third-party payload.

Add a stale-settings case: first assert the staff list reports the order eligible, reduce `coverage_radius_km`, then assert the Shop-owned PATCH returns 422 and leaves the order `processing`.

- [ ] **Step 2: Run the new test file and verify failures**

Run: `php artisan test tests/Feature/Logistics/StaffRetailShippingCoverageTest.php`

Expected: FAIL because the staff payload and guard do not exist.

- [ ] **Step 3: Add eligibility to staff order JSON**

Import and inject `DeliveryScheduleService`, eager-load `address` and `shopOwner.logisticsSetting`, and add one private formatter:

```php
private function shopOwnedCoverage(Order $order): array
{
    $order->loadMissing(['address', 'shopOwner.logisticsSetting']);

    try {
        return $this->deliverySchedules->coverage(
            $order->shopOwner,
            $order->address?->latitude !== null ? (float) $order->address->latitude : null,
            $order->address?->longitude !== null ? (float) $order->address->longitude : null,
        );
    } catch (\Throwable $exception) {
        Log::warning('Staff retail coverage lookup failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);
        return ['available' => false, 'reason' => 'logistics_unavailable', 'distance_km' => null, 'coverage_radius_km' => null];
    }
}
```

Add `'shop_owned_coverage' => $this->shopOwnedCoverage($order)` to both `index()` and `show()` JSON mappings.

- [ ] **Step 4: Guard Mark as Shipped before mutating the order**

Immediately after loading the order, before assigning `$order->status`, add:

```php
$usesShopOwned = strtolower(trim((string) ($validated['carrier_company'] ?? ''))) === 'shop-owned logistics';
if ($validated['status'] === 'shipped' && $usesShopOwned) {
    $coverage = $this->shopOwnedCoverage($order);
    if (!$coverage['available']) {
        $message = 'Shop-owned logistics is unavailable for this delivery address.';
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['carrier_company' => [$message]],
            'shop_owned_coverage' => $coverage,
        ], 422);
    }
}
```

Keep `SourceShipmentService::ensureRetailOrderShipment()` in its current shipped transition so downstream logistics behavior does not change.

- [ ] **Step 5: Run staff and source-shipment regression tests**

Run: `php artisan test tests/Feature/Logistics/StaffRetailShippingCoverageTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/StaffOrderController.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php
git commit -m "fix: block out-of-coverage shop-owned shipping"
```

### Task 4: Shared Leaflet Customer Address Picker

**Files:**
- Create: `resources/js/components/address/CustomerAddressMapPicker.tsx`
- Create: `resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx`
- Modify: `app/Http/Controllers/UserSide/UserAddressController.php`
- Modify: `tests/Feature/UserSide/UserAddressCoordinateTest.php`

- [ ] **Step 1: Write failing coordinate-bound and picker tests**

Extend `UserAddressCoordinateTest` with Philippine bounds:

```php
$this->actingAs($user, 'user')->postJson('/api/user/addresses', $this->payload([
    'latitude' => 35,
    'longitude' => 139,
]))->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
```

For the picker, mock Leaflet and geolocation, render the component, and assert that choosing a mocked reverse-geocode result calls `onChange` with the structured fields and coordinates. Reuse `parsePhilippineAddress` from `resources/js/Pages/UserSide/Auth/registrationAddress.ts`; do not duplicate the Philippine address parser.

- [ ] **Step 2: Run tests and verify failures**

Run: `php artisan test tests/Feature/UserSide/UserAddressCoordinateTest.php`

Run: `pnpm test:frontend -- resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx`

Expected: backend bounds test fails and the picker test fails because the component does not exist.

- [ ] **Step 3: Apply registration's Philippine bounds to saved addresses**

Change store and update rules to:

```php
'latitude' => 'nullable|required_with:longitude|numeric|between:4.5,21.5',
'longitude' => 'nullable|required_with:latitude|numeric|between:116,127',
```

- [ ] **Step 4: Implement the minimal shared picker**

Use this public contract:

```tsx
type CoordinateValue = { latitude: number; longitude: number } | null;

type Props = {
  value: CoordinateValue;
  onChange: (location: RegistrationAddress) => void;
  disabled?: boolean;
};
```

Move only the reusable map behavior from registration/shop settings: dynamic `import('leaflet')`, OpenStreetMap tiles, click, draggable marker, Philippine Nominatim search, GPS, reverse geocoding, cleanup, and `invalidateSize()`. Import `leaflet/dist/leaflet.css` in this component and reuse `parsePhilippineAddress`; no new dependency or geocoding backend is added.

Render a search input, Search button, Use My Location button, map container, selected-coordinate text, and accessible error/status text.

- [ ] **Step 5: Run focused tests**

Run: `php artisan test tests/Feature/UserSide/UserAddressCoordinateTest.php`

Run: `pnpm test:frontend -- resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/UserSide/UserAddressController.php tests/Feature/UserSide/UserAddressCoordinateTest.php resources/js/components/address/CustomerAddressMapPicker.tsx resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx
git commit -m "feat: add reusable customer address map picker"
```

### Task 5: Wire the Picker into Checkout and Payment

**Files:**
- Create: `resources/js/Pages/UserSide/Orders/__tests__/customerAddressMapIntegration.test.ts`
- Modify: `resources/js/Pages/UserSide/Orders/Checkout.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`

- [ ] **Step 1: Write the failing integration assertions**

Following the existing source-integration test style, assert both pages import and render `CustomerAddressMapPicker`, and that address POST/PUT bodies include `latitude` and `longitude`.

```ts
expect(checkoutSource).toContain('CustomerAddressMapPicker');
expect(checkoutSource).toContain('latitude: newAddressData.latitude');
expect(paymentSource).toContain('value={{ latitude: shippingLatitude, longitude: shippingLongitude }}');
expect(checkoutSource).toContain('Repin');
expect(paymentSource).toContain('Repin address');
```

- [ ] **Step 2: Run and verify failure**

Run: `pnpm test:frontend -- resources/js/Pages/UserSide/Orders/__tests__/customerAddressMapIntegration.test.ts`

Expected: FAIL because Checkout does not yet retain coordinates and neither form renders the shared picker.

- [ ] **Step 3: Wire Checkout add/edit forms**

Add `latitude` and `longitude` to `newAddressData`, preserve them in `editingAddressData`, and render the picker below the structured fields in both modals. On picker change, update coordinates plus reverse-geocoded `region`, `province`, `city`, `barangay`, `postal_code`, and `address`/`address_line`.

Include the coordinate pair in both `/api/user/addresses` POST and PUT bodies. Reset coordinates to `null` when resetting the add form.

For every saved address whose `latitude` or `longitude` is missing, render a **Repin** action in the address selector. The action opens that address in the existing edit modal and focuses the shared picker; third-party checkout remains usable if the customer does not repin.

- [ ] **Step 4: Wire Payment's existing coordinate state**

Render the same picker in the address form using `shippingLatitude` and `shippingLongitude`. On change, update the existing coordinate state and structured shipping fields. Keep `handleUseAddressFromForm()` and `saveAddressToAccount()` as the only persistence paths; they already send the coordinate pair.

In the saved-address sheet, show **Repin address** for an address missing either coordinate. It calls the existing `handleEditAddressFromList(address)` and switches the sheet to form mode so the customer can repair and save that address directly.

- [ ] **Step 5: Run customer frontend tests**

Run: `pnpm test:frontend -- resources/js/Pages/UserSide/Orders/__tests__/customerAddressMapIntegration.test.ts resources/js/Pages/UserSide/Orders/__tests__/paymentLocationIntegration.test.ts`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/UserSide/Orders/Checkout.tsx resources/js/Pages/UserSide/Orders/payment.tsx resources/js/Pages/UserSide/Orders/__tests__/customerAddressMapIntegration.test.ts
git commit -m "feat: pin checkout delivery addresses"
```

### Task 6: Show Coverage on Payment

**Files:**
- Create: `resources/js/Pages/UserSide/Orders/__tests__/paymentCoverageIntegration.test.ts`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`

- [ ] **Step 1: Write failing Payment coverage assertions**

Assert the estimate request includes the selected saved address and the source contains all three user-facing states:

```ts
expect(paymentSource).toContain('address_id: checkoutData.address_id');
expect(paymentSource).toContain('Eligible for Shop-owned Logistics');
expect(paymentSource).toContain('Outside Shop-owned coverage');
expect(paymentSource).toContain('Pin this address to check Shop-owned coverage');
expect(paymentSource).toContain('Repin address');
```

- [ ] **Step 2: Run and verify failure**

Run: `pnpm test:frontend -- resources/js/Pages/UserSide/Orders/__tests__/paymentCoverageIntegration.test.ts`

Expected: FAIL because Payment currently ignores the new response object.

- [ ] **Step 3: Extend the local estimate type and request**

Add:

```ts
shop_owned?: {
  available: boolean;
  reason: 'address_needs_pin' | 'shop_needs_pin' | 'outside_coverage' | 'logistics_unavailable' | null;
  distance_km: number | null;
  coverage_radius_km: number | null;
};
```

Send `address_id: checkoutData.address_id` with the existing `item_pids` and structured address fields, then retain `data.shop_owned` in `shippingEstimate`.

- [ ] **Step 4: Render a non-selectable eligibility notice**

Near the shipping estimate, show:

- green: eligible, including distance and radius;
- amber: outside coverage, including distance and radius;
- gray: address needs pin or shop location unavailable. For `address_needs_pin`, include a **Repin address** button that opens the selected saved address through `handleEditAddressFromList()`.

Do not add a customer carrier radio and do not change the calculated third-party shipping fee.

- [ ] **Step 5: Run Payment tests**

Run: `pnpm test:frontend -- resources/js/Pages/UserSide/Orders/__tests__/paymentCoverageIntegration.test.ts resources/js/Pages/UserSide/Orders/__tests__/paymentLocationIntegration.test.ts`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/UserSide/Orders/payment.tsx resources/js/Pages/UserSide/Orders/__tests__/paymentCoverageIntegration.test.ts
git commit -m "feat: show shop-owned eligibility at payment"
```

### Task 7: Disable Shop-Owned Shipping in Staff Retail Process

**Files:**
- Create: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`

- [ ] **Step 1: Write failing staff UI integration assertions**

Following the repository's lightweight source-integration style, assert the page maps `shop_owned_coverage`, disables the Shop-owned option when unavailable, and renders the coverage reason/distance/radius.

```ts
expect(source).toContain('shopOwnedCoverage');
expect(source).toContain('disabled={!selectedOrder?.shopOwnedCoverage?.available}');
expect(source).toContain('Outside delivery coverage');
expect(source).toContain('coverage_radius_km');
```

- [ ] **Step 2: Run and verify failure**

Run: `pnpm test:frontend -- resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`

Expected: FAIL because the staff page does not consume eligibility.

- [ ] **Step 3: Map the backend coverage payload**

Extend the `Order` type and `mapApiOrder()`:

```ts
shopOwnedCoverage?: {
  available: boolean;
  reason: string | null;
  distance_km: number | null;
  coverage_radius_km: number | null;
};

shopOwnedCoverage: order.shop_owned_coverage,
```

- [ ] **Step 4: Default or disable the carrier selector**

When opening Mark as Shipped:

```ts
const eligible = order.shopOwnedCoverage?.available === true;
setCarrierCompany(order.carrierCompany || (eligible ? SHOP_OWNED_LOGISTICS : ''));
```

Disable only the Shop-owned `<option>` when `eligible` is false. Keep Lalamove, J&T, and Express Padala selectable and keep their existing rider/tracking validation.

Below the selector, show the exact reason:

- `outside_coverage`: outside delivery coverage with distance/radius;
- `address_needs_pin`: customer address must be pinned;
- `shop_needs_pin`: shop location must be configured;
- fallback: Shop-owned Logistics is unavailable.

If stale state still leaves Shop-owned selected while unavailable, block `handleConfirmShipping()` locally before the request; the Task 3 backend guard remains authoritative.

Add `Accept: application/json` to the status PATCH headers so every validation response is JSON. When it returns 422 with a `carrier_company` coverage error, keep the modal open and refresh `/api/staff/orders`. Replace both `orders` and `selectedOrder` with the freshly mapped order, clear an invalid Shop-owned selection, and show the server message. This makes changed logistics settings visible immediately instead of leaving the modal on stale eligibility.

- [ ] **Step 5: Run staff UI and backend guard tests**

Run: `pnpm test:frontend -- resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts`

Run: `php artisan test tests/Feature/Logistics/StaffRetailShippingCoverageTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
git commit -m "feat: enforce coverage in staff retail shipping"
```

### Task 8: End-to-End Regression Verification

**Files:**
- No production files expected.

- [ ] **Step 1: Run the focused backend suite**

Run:

```bash
php artisan test tests/Feature/Logistics/DeliveryScheduleServiceTest.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php tests/Feature/UserSide/ShippingEstimateControllerTest.php tests/Feature/UserSide/UserAddressCoordinateTest.php tests/Feature/UserSide/CustomerRegistrationAddressTest.php
```

Expected: PASS.

- [ ] **Step 2: Run focused frontend tests**

Run:

```bash
pnpm test:frontend -- resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx resources/js/Pages/UserSide/Orders/__tests__/customerAddressMapIntegration.test.ts resources/js/Pages/UserSide/Orders/__tests__/paymentCoverageIntegration.test.ts resources/js/Pages/UserSide/Orders/__tests__/paymentLocationIntegration.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
```

Expected: PASS.

- [ ] **Step 3: Run production build**

Run: `pnpm build`

Expected: Vite build exits successfully with no TypeScript or bundling error. Do not commit generated `public/build` artifacts unless the repository's current branch policy explicitly requires them.

- [ ] **Step 4: Manually verify the primary flow**

1. Pin and save an inside-radius customer address from Checkout and Payment.
2. Confirm Payment shows Shop-owned eligibility but no carrier selector.
3. Move the paid order to Processing and open Mark as Shipped as staff.
4. Confirm Shop-owned is preselected inside coverage.
5. Repin the address outside coverage, reload staff orders, and confirm Shop-owned is disabled while third-party carriers remain selectable.
6. Confirm a forged Shop-owned PATCH returns 422 and leaves the order Processing.
7. Ship an inside-radius order through Shop-owned Logistics and confirm the existing Shipment → Batch → Rider → Delivered flow still works.

- [ ] **Step 5: Inspect the final diff**

Run: `git status --short` and `git diff --check`

Expected: only intended source/test changes plus pre-existing user changes; no whitespace errors or accidental build artifacts added by this implementation.
