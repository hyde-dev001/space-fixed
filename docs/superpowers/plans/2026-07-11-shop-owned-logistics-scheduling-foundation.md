# Shop-Owned Logistics Scheduling Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every new shop-owned retail delivery a capacity-aware estimated delivery date/window based on shop operating rules and coverage, and expose that estimate to dispatchers and customers.

**Architecture:** Add one tenant-owned logistics settings record and scheduling fields on shipment legs and addresses. A focused `DeliveryScheduleService` performs deterministic calendar, capacity, and Haversine calculations; `SourceShipmentService` invokes it only for shop-owned retail deliveries inside the existing shipment transaction, while existing tracking serializers and pages display the saved result. Addresses are geocoded when saved by extracting the Nominatim logic already used by shipping estimates; shipment creation never performs network I/O, and missing coordinates create a visible dispatcher-review state.

**Tech Stack:** Laravel 12, PHP 8.2+, Eloquent/MySQL, Inertia React/TypeScript, PHPUnit, Vitest

**Design spec:** `docs/superpowers/specs/2026-07-11-shop-owned-logistics-production-design.md`

---

## File structure

- Create `database/migrations/2026_07_11_000004_create_logistics_settings_table.php` — one scheduling policy per shop.
- Create `database/migrations/2026_07_11_000005_add_scheduling_to_addresses_and_shipment_legs.php` — customer coordinates and saved scheduling result.
- Create `app/Models/Logistics/LogisticsSetting.php` — settings casts/defaults and shop relation.
- Create `app/Services/Logistics/DeliveryScheduleService.php` — pure estimate, coverage, calendar, and capacity logic.
- Create `app/Services/AddressCoordinateService.php` — shared structured-address geocoding currently embedded in the shipping estimator.
- Create `app/Http/Controllers/Api/Logistics/LogisticsSettingController.php` — tenant-scoped read/update API.
- Create `resources/js/Pages/ERP/Logistics/Settings.tsx` — minimal settings form using native inputs.
- Create `tests/Feature/Logistics/DeliveryScheduleServiceTest.php` — scheduling boundary tests.
- Create `tests/Feature/Logistics/LogisticsSettingsTest.php` — authorization and validation tests.
- Create `tests/Feature/UserSide/UserAddressCoordinateTest.php` — coordinate validation/geocoding tests.
- Create `tests/Feature/UserSide/ShippingEstimateControllerTest.php` — extracted-geocoder regression test.
- Modify `app/Models/ShopOwner.php` — settings relation only.
- Modify `app/Models/UserAddress.php` — coordinate fields/casts.
- Modify `app/Models/Logistics/ShipmentLeg.php` — scheduling fields/casts.
- Modify `app/Http/Controllers/UserSide/UserAddressController.php` — accept supplied coordinates or geocode on address save.
- Modify `app/Http/Controllers/UserSide/ShippingEstimateController.php` — reuse the extracted coordinate service without changing responses.
- Modify `app/Services/Logistics/SourceShipmentService.php` — snapshot coordinates and schedule shop-owned retail legs.
- Modify `app/Services/Logistics/CustomerTrackingService.php` — serialize estimate/review state.
- Modify `app/Http/Controllers/Logistics/ErpLogisticsController.php` — render the settings page.
- Modify `routes/web.php` — settings page and authenticated API routes.
- Modify `resources/js/types/logistics.ts` — schedule fields.
- Modify `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx` — show estimated date/window.
- Modify `resources/js/Pages/ERP/Logistics/Shipments.tsx` — show schedule and coverage-review badge.
- Modify `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php` — integration coverage.
- Modify `tests/Feature/Logistics/CustomerTrackingTest.php` — customer payload coverage.
- Modify `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx` — display coverage.

No batch, retry, incident, live-location, OTP, COD, or third-party courier behavior belongs in this plan.

### Task 1: Persist scheduling policy and results

**Files:**
- Create: `database/migrations/2026_07_11_000004_create_logistics_settings_table.php`
- Create: `database/migrations/2026_07_11_000005_add_scheduling_to_addresses_and_shipment_legs.php`
- Create: `app/Models/Logistics/LogisticsSetting.php`
- Modify: `app/Models/ShopOwner.php`
- Modify: `app/Models/UserAddress.php`
- Modify: `app/Models/Logistics/ShipmentLeg.php`
- Test: `tests/Feature/Logistics/LogisticsSchemaTest.php`

- [ ] **Step 1: Write the failing schema/model test**

Assert that `logistics_settings` has `shop_owner_id`, `operating_days`, `cutoff_time`, `blackout_dates`, `lead_time_days`, `morning_start`, `morning_end`, `afternoon_start`, `afternoon_end`, `coverage_radius_km`, `daily_rider_capacity`, and `max_delivery_attempts`; `user_addresses` has `latitude`/`longitude`; and `shipment_legs` has `scheduled_delivery_date`, `delivery_window`, `schedule_status`, `schedule_override_reason`, `distance_km`, and `estimated_at`. Assert JSON/date/decimal casts and one settings row per shop.

- [ ] **Step 2: Run the focused test and verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsSchemaTest.php`

Expected: FAIL because the tables/columns/model do not exist.

- [ ] **Step 3: Add the minimal migrations and models**

Use a unique foreign key on `logistics_settings.shop_owner_id`. Defaults:

```php
'operating_days' => [1, 2, 3, 4, 5, 6], // ISO Monday-Saturday
'cutoff_time' => '15:00',
'blackout_dates' => [],
'lead_time_days' => 1,
'morning_start' => '08:00',
'morning_end' => '12:00',
'afternoon_start' => '13:00',
'afternoon_end' => '18:00',
'coverage_radius_km' => 20,
'daily_rider_capacity' => 20,
'max_delivery_attempts' => 2,
```

Use documented string values `scheduled`, `outside_coverage`, `needs_coordinates`, `needs_shop_coordinates`, and `needs_capacity`; do not add an enum. Add indexes on `(scheduled_delivery_date, delivery_window, schedule_status)` and address coordinates, plus a unique shipment index on `(source_type, source_id, purpose)` to close the concurrent source-request race. Store `distance_km` as nullable decimal.

- [ ] **Step 4: Run the schema test**

Run: `php artisan test tests/Feature/Logistics/LogisticsSchemaTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_11_000004_create_logistics_settings_table.php database/migrations/2026_07_11_000005_add_scheduling_to_addresses_and_shipment_legs.php app/Models/Logistics/LogisticsSetting.php app/Models/ShopOwner.php app/Models/UserAddress.php app/Models/Logistics/ShipmentLeg.php tests/Feature/Logistics/LogisticsSchemaTest.php
git commit -m "feat: store logistics scheduling policy"
```

### Task 2: Calculate deterministic delivery estimates

**Files:**
- Create: `app/Services/Logistics/DeliveryScheduleService.php`
- Create: `tests/Feature/Logistics/DeliveryScheduleServiceTest.php`

- [ ] **Step 1: Write failing service tests**

Cover: before/after cutoff using the ready-for-shipping timestamp; lead time; Sunday/blackout skipping; Asia/Manila boundary converted from stored UTC; Haversine inside/outside radius; missing destination coordinates; missing shop coordinates; morning then afternoon allocation; capacity shared across both windows; and the next date after daily capacity is exhausted.

Use `Carbon::setTestNow()` and create scheduled shop-owned legs as capacity fixtures. Do not call HTTP or the existing third-party shipping estimate controller.

- [ ] **Step 2: Run and verify failure**

Run: `php artisan test tests/Feature/Logistics/DeliveryScheduleServiceTest.php`

Expected: FAIL because `DeliveryScheduleService` does not exist.

- [ ] **Step 3: Implement the smallest service API**

```php
public function estimate(
    ShopOwner $shop,
    CarbonInterface $readyAt,
    ?float $destinationLatitude,
    ?float $destinationLongitude,
): array
```

Return exactly:

```php
[
    'scheduled_delivery_date' => '2026-07-13',
    'delivery_window' => 'morning',
    'schedule_status' => 'scheduled',
    'distance_km' => 8.4,
]
```

For missing destination coordinates, return `needs_coordinates`; for missing shop coordinates, return `needs_shop_coordinates`; both have null schedule fields. For an address beyond the configured radius, return `outside_coverage` with `distance_km`. Daily capacity is the active/available rider count multiplied by per-rider daily capacity and is shared across both windows. Allocate the first `ceil(daily capacity / 2)` stops to morning and the remainder to afternoon; once the daily total is full, try the next operating date. If there are no available riders, return `needs_capacity`.

Use a private Haversine method and Carbon calendar loop; no routing/geocoding dependency.

- [ ] **Step 4: Run the service tests**

Run: `php artisan test tests/Feature/Logistics/DeliveryScheduleServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/DeliveryScheduleService.php tests/Feature/Logistics/DeliveryScheduleServiceTest.php
git commit -m "feat: calculate delivery schedules"
```

### Task 3: Populate coordinates when customers save addresses

**Files:**
- Create: `app/Services/AddressCoordinateService.php`
- Modify: `app/Http/Controllers/UserSide/UserAddressController.php`
- Modify: `app/Http/Controllers/UserSide/ShippingEstimateController.php`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Test: `tests/Feature/UserSide/UserAddressCoordinateTest.php`
- Test: `tests/Feature/UserSide/ShippingEstimateControllerTest.php`

- [ ] **Step 1: Write failing address tests**

Assert valid submitted coordinates are stored; partial/out-of-range pairs return 422; absent coordinates geocode the structured address through a mocked HTTP response; and geocoding failure still saves the address with null coordinates. Assert users cannot update another user's address. Add one ShippingEstimateController regression assertion proving its existing response remains unchanged after extraction.

- [ ] **Step 2: Run and verify failure**

Run: `php artisan test tests/Feature/UserSide/UserAddressCoordinateTest.php`

Expected: FAIL because address writes ignore coordinates and no shared service exists.

- [ ] **Step 3: Extract and reuse existing geocoding**

Move only address-to-coordinate lookup from `ShippingEstimateController` into `AddressCoordinateService`. Let `UserAddressController::store()` and `update()` validate an optional complete latitude/longitude pair; otherwise geocode the validated structured address before saving. On update, retain existing coordinates when none of the structured address fields changed; geocode only when an address field changed. Add optional hidden coordinate fields to the active address form in `payment.tsx` so already-known browser/application coordinates can be submitted; do not add a map dependency. Preserve null coordinates on lookup failure so Logistics can flag dispatcher review.

- [ ] **Step 4: Run address and shipping-estimate regression tests**

Run: `php artisan test tests/Feature/UserSide/UserAddressCoordinateTest.php tests/Feature/UserSide/ShippingEstimateControllerTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AddressCoordinateService.php app/Http/Controllers/UserSide/UserAddressController.php app/Http/Controllers/UserSide/ShippingEstimateController.php resources/js/Pages/UserSide/Orders/payment.tsx tests/Feature/UserSide/UserAddressCoordinateTest.php tests/Feature/UserSide/ShippingEstimateControllerTest.php
git commit -m "feat: store customer address coordinates"
```

### Task 4: Schedule new shop-owned retail shipments

**Files:**
- Modify: `app/Services/Logistics/SourceShipmentService.php`
- Modify: `app/Services/Logistics/ShipmentRequestService.php`
- Modify: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`

- [ ] **Step 1: Write failing integration tests**

Add order fixtures with a linked `UserAddress` containing coordinates and assert a shop-owned outbound leg saves the calculated date/window/status/distance and snapshots the structured address, instructions, and coordinates. Add missing customer/shop-coordinate, outside-radius, and no-capacity cases. Assert third-party carrier orders and refund/repair shipments retain existing behavior and are not scheduled by this retail-delivery path.

- [ ] **Step 2: Run and verify failure**

Run: `php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php --filter=schedul`

Expected: FAIL because source shipment creation does not calculate or persist schedules.

- [ ] **Step 3: Pass scheduling data through the existing request boundary**

Allow only the new leg schedule fields through `ShipmentRequestService` validation. In `SourceShipmentService::ensureRetailOrderShipment()`, schedule only when `carrier_company` is `Shop-owned logistics`. Use the order's freshly persisted `updated_at` immediately after the status changes to `shipped` as `readyAt`; add an assertion at each existing order-status caller that the model is refreshed before invoking the source service.

Keep source idempotency and capacity reservation atomic by moving the existing `findExisting()` check inside a `SourceShipmentService` transaction. First lock the stable `shop_owners` row with `lockForUpdate()`; then re-check for an existing source shipment, `firstOrCreate()` the singleton logistics settings, calculate capacity, and create the shipment/leg through `ShipmentRequestService` before releasing the shop lock. Nested Laravel transactions reuse the same connection, so the shop lock remains held. Do not calculate capacity or return from a pre-transaction existence check.

Retain the unique `(source_type, source_id, purpose)` index as defense in depth. If an insert still loses to a duplicate-key race, catch only that constraint violation, load the matching shipment, and return it; rethrow every other database exception. Add a concurrency test using separate database connections or processes around the locked boundary and assert both callers receive the same shipment with one persisted leg; sequential calls alone do not verify locking.

Record one internal `delivery_schedule_created` event and one customer-visible `delivery_estimated` event only when status is `scheduled`. Record an internal attention event for missing coordinates, capacity, or outside coverage; do not promise a date to the customer.

- [ ] **Step 4: Run integration and regression tests**

Run: `php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/Logistics/ShipmentRequestServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/SourceShipmentService.php app/Services/Logistics/ShipmentRequestService.php tests/Feature/Logistics/SourceModuleShipmentRequestTest.php
git commit -m "feat: schedule shop owned deliveries"
```

### Task 5: Add tenant-scoped logistics settings API

**Files:**
- Create: `app/Http/Controllers/Api/Logistics/LogisticsSettingController.php`
- Create: `tests/Feature/Logistics/LogisticsSettingsTest.php`
- Modify: `routes/web.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`

- [ ] **Step 1: Write failing API authorization and validation tests**

Assert shop owners and users with `configure-logistics-settings` can read/update only their shop's singleton settings. Assert other users receive 403. Validate ISO weekdays, `H:i` times, unique `Y-m-d` blackout dates, positive lead/capacity/attempt values, radius greater than zero, morning/afternoon start before end, and no overlapping windows.

- [ ] **Step 2: Run and verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsSettingsTest.php`

Expected: FAIL because the endpoints/controller do not exist.

- [ ] **Step 3: Implement `show()` and `update()`**

Register `GET` and `PUT /api/logistics/settings` inside the existing authenticated logistics route group. Reuse the controller's established authorised-shop lookup pattern; for updates, start a transaction, lock the same `shop_owners` row used by scheduling, `firstOrCreate()` the singleton, and update only validated fields. This prevents policy changes racing with schedule reservation. Do not add repository, request, or DTO classes.

- [ ] **Step 4: Run settings and permission tests**

Run: `php artisan test tests/Feature/Logistics/LogisticsSettingsTest.php tests/Feature/Logistics/LogisticsSeederTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Logistics/LogisticsSettingController.php tests/Feature/Logistics/LogisticsSettingsTest.php routes/web.php database/seeders/RolesAndPermissionsSeeder.php
git commit -m "feat: configure logistics scheduling"
```

### Task 6: Add the minimal settings page

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/Settings.tsx`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`

- [ ] **Step 1: Write the failing page access test**

Assert shop owners and authorised ERP users can load `/erp/logistics/settings`; unauthorised and cross-tenant users cannot. Assert the Inertia prop contains the persisted settings.

- [ ] **Step 2: Run and verify failure**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=settings`

Expected: FAIL because the page route does not exist.

- [ ] **Step 3: Implement the page with native controls**

Use checkboxes for operating days, `<input type="time">` for cutoff/windows, `<input type="date">` for blackout additions, and numeric inputs for lead time, radius, capacity, and attempts. Submit to the settings API and use the already-installed SweetAlert toast pattern. No calendar, map, or form dependency.

- [ ] **Step 4: Verify page and TypeScript build**

Run: `php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php --filter=settings`

Expected: PASS.

Run: `npm run build`

Expected: PASS with no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/Settings.tsx app/Http/Controllers/Logistics/ErpLogisticsController.php routes/web.php tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: add logistics schedule settings page"
```

### Task 7: Display delivery estimates to dispatchers and customers

**Files:**
- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Modify: `resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

- [ ] **Step 1: Write failing serializer and UI tests**

Assert customer tracking serializes scheduled date/window/status but never internal override reasons. Assert the tracking page renders `Estimated delivery`, the formatted date, and `Morning`/`Afternoon`. Assert dispatcher shipment data renders `Needs coordinates`, `Shop location required`, `Outside coverage`, or `Needs capacity` attention badges for `needs_coordinates`, `needs_shop_coordinates`, `outside_coverage`, and `needs_capacity` respectively.

- [ ] **Step 2: Run and verify failure**

Run: `php artisan test tests/Feature/Logistics/CustomerTrackingTest.php`

Expected: FAIL because estimate fields are absent.

Run: `npx vitest run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

Expected: FAIL because the UI does not render the estimate.

- [ ] **Step 3: Add the minimal payload and UI**

Extend `TrackingShipmentLeg` and the tracking serializer with `scheduled_delivery_date`, `delivery_window`, and `schedule_status`. Render a compact estimate card only for `scheduled`; render no false promise for other statuses. Add dispatcher badges to the existing expanded leg card without creating another page.

- [ ] **Step 4: Run focused tests**

Run: `php artisan test tests/Feature/Logistics/CustomerTrackingTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php`

Expected: PASS.

Run: `npx vitest run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Logistics/CustomerTrackingService.php resources/js/types/logistics.ts resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx resources/js/Pages/ERP/Logistics/Shipments.tsx tests/Feature/Logistics/CustomerTrackingTest.php resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx
git commit -m "feat: show logistics delivery estimates"
```

### Task 8: Verify the independently usable foundation

**Files:**
- Verify only; fix failures in the files already listed above.

- [ ] **Step 1: Run logistics tests**

Run: `php artisan test tests/Feature/Logistics`

Expected: PASS, including all existing logistics tests.

- [ ] **Step 2: Run frontend tracking tests**

Run: `npx vitest run resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`

Expected: PASS.

- [ ] **Step 3: Build production assets**

Run: `npm run build`

Expected: PASS.

- [ ] **Step 4: Inspect the diff**

Run: `git diff --check`

Expected: no whitespace errors.

Run: `git status --short`

Expected: only intended Phase 1 source/test changes plus pre-existing user changes; do not stage generated or unrelated files.

- [ ] **Step 5: Commit any verification-only corrections**

```bash
git add <only-files-corrected-during-verification>
git commit -m "test: verify logistics scheduling foundation"
```
