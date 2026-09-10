# Live GPS Tracking Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing Rider, Customer, and Dispatcher maps share a persisted per-leg remaining route with bounded progress trimming, confirmed off-route rerouting, version protection, and smooth Rider presentation.

**Architecture:** Extend the existing one-row-per-leg `rider_current_locations` record with the current canonical route state instead of introducing a second tracking system or realtime transport. `RiderLocationService` will update that state after validated GPS writes, using `RouteEstimationService` for projection/trimming and only confirmed, cooldown-eligible reroutes; viewer endpoints will read the same stored state. Leaflet will interpolate marker presentation locally and ignore stale route versions.

**Tech Stack:** Laravel 12/PHP 8.2, Eloquent migrations, existing OSRM `RouteEstimationService`, Inertia/React/TypeScript, Leaflet, PHPUnit/Pest-style Laravel tests, Vitest.

---

### Task 1: Persist canonical route state and configuration

**Files:**
- Create: `database/migrations/2026_09_09_000001_add_canonical_route_state_to_rider_current_locations.php`
- Modify: `app/Models/Logistics/RiderCurrentLocation.php`
- Modify: `config/logistics_tracking.php`
- Test: `tests/Feature/Logistics/RiderLocationTest.php`

- [ ] Add nullable route geometry/metrics/target fields, route version/timestamp, and off-route counters/state to the existing current-location row, with safe defaults for existing records.
- [ ] Add configurable trim tolerance, off-route threshold, consecutive sample count, and reroute cooldown under the existing tracking configuration.
- [ ] Add model fillable/casts and update the schema assertion.
- [ ] Run the schema test and `git diff --check`.

### Task 2: Add tested route projection and trimming primitives

**Files:**
- Modify: `app/Services/Logistics/RouteEstimationService.php`
- Test: `tests/Feature/Logistics/RouteEstimationServiceTest.php`

- [ ] Write failing tests for nearest-point projection, passed-geometry trimming, and the no-backtracking tolerance.
- [ ] Implement small-meter geometry helpers using the standard haversine/equirectangular math already used by the service; return the projected point, distance, progress, and remaining geometry.
- [ ] Keep provider calls and existing cache/fallback behavior unchanged for ordinary estimates.
- [ ] Run the focused route-service tests.

### Task 3: Update the canonical route on validated Rider GPS writes

**Files:**
- Modify: `app/Services/Logistics/RiderLocationService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Test: `tests/Feature/Logistics/RiderLocationApiTest.php`
- Test: `tests/Feature/Logistics/RiderLocationSafetyTest.php`

- [ ] Write failing integration tests proving initial route creation, on-route trimming without a provider call, sustained off-route confirmation, cooldown, same active destination, and unchanged stop order.
- [ ] After the existing location validation/write, initialize or update the stored route under the existing leg/assignment authorization boundary.
- [ ] Project valid points onto the stored road geometry, prevent route progress from moving backward because of jitter, count consecutive off-route samples, and reroute only after confirmation and cooldown.
- [ ] Increment route version only when a new canonical route is generated; protect concurrent/stale writes with the existing row lock and current-location timestamp checks.
- [ ] Return the canonical route in the Rider response without changing GPS cadence or workflow state.
- [ ] Run the focused API/safety tests.

### Task 4: Make Customer and Dispatcher consume canonical route state

**Files:**
- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `resources/js/types/logistics.ts`
- Test: `tests/Feature/Logistics/CustomerLiveTrackingTest.php`
- Test: `tests/Feature/Logistics/RiderLiveLocationsTest.php`

- [ ] Write failing assertions that Customer and Dispatcher receive the same route geometry/version after a Rider update and preserve their existing authorization/visibility behavior.
- [ ] Read the persisted route state from `RiderLocationService` and lazily initialize only legacy rows that predate the new state.
- [ ] Include route version, active-leg identity, update timestamp, and off-route state in the shared route payload.
- [ ] Run the focused viewer tests.

### Task 5: Add lightweight map interpolation, heading, and version guards

**Files:**
- Modify: `resources/js/components/logistics/LiveTrackingMap.tsx`
- Modify: `resources/js/components/logistics/RiderGpsTracker.tsx`
- Modify: `resources/js/types/logistics.ts`
- Test: `resources/js/components/logistics/__tests__/LiveTrackingMap.test.tsx`
- Test: `resources/js/components/logistics/__tests__/RiderGpsTracker.test.tsx`

- [ ] Write failing tests for interpolated marker updates, reliable-heading rendering, and ignoring older route versions.
- [ ] Animate only the Leaflet marker between accepted GPS samples with `requestAnimationFrame`; do not alter or resubmit authoritative coordinates.
- [ ] Render a neutral Rider marker that exposes heading rotation only when the backend provides a valid heading, with a small jitter guard.
- [ ] Keep route drawing immediate and consume the canonical remaining geometry; retain existing direct-route fallback behavior.
- [ ] Run the focused frontend tests.

### Task 6: Verify, simplify, and document the handoff

**Files:**
- Modify: `docs/ai-learning-log.md` only if a durable project lesson is found.

- [ ] Run the focused Laravel and frontend suites, then the repository build, full relevant tests where practical, and `git diff --check`.
- [ ] Perform the required sequential standards, security, TypeScript/React, simplification, dead-code, and reuse review on the changed files.
- [ ] Check the generated `public/build` output is fresh if the repository tracks it.
- [ ] Report exact route-state scope, provider calls, update intervals, tests, and any unverified browser scenarios; do not claim Waze-level navigation beyond the implemented bounded behavior.
