# Standardize Rider/Dispatcher/Customer Delivery Type Labels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose one server-derived `delivery_type`/`delivery_label` contract and use it consistently for rider, dispatcher, live-tracking, and customer delivery views.

**Architecture:** Keep `Shipment.source_type`, `Shipment.purpose`, and `ShipmentLeg` direction/recovery fields as the domain source of truth. Add one deterministic, side-effect-free resolver in the logistics service layer; serializers append its normalized values without removing existing source/purpose fields. React consumes the fields and keeps only display formatting, not classification logic.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia, React 18, TypeScript, Vitest.

---

### Task 1: Add the failing server resolver contract tests

**Files:**
- Create: `tests/Unit/Services/Logistics/DeliveryTypeResolverTest.php`
- Test: `app/Services/Logistics/DeliveryTypeResolver.php` (to be created in Task 2)

- [x] **Step 1: Write tests for the four canonical mappings, recovery legs, and unknown purpose.**
- [x] **Step 2: Run the resolver test and confirm it fails because the resolver does not exist.**

Run: `php artisan test tests/Unit/Services/Logistics/DeliveryTypeResolverTest.php`
Expected: FAIL because the resolver contract is missing.

### Task 2: Implement the minimal deterministic resolver

**Files:**
- Create: `app/Services/Logistics/DeliveryTypeResolver.php`
- Modify: `app/Models/Logistics/ShipmentLeg.php` only if the established service call shape requires a model helper (prefer no model change).

- [x] **Step 1: Implement recovery/return-leg checks before ordinary purpose checks.**
- [x] **Step 2: Map `order`/`retail_delivery` to `retail_delivery`, `order_refund`/`refund_return` to `retail_return`, and repair purposes to their normalized types.**
- [x] **Step 3: Return `unknown`/`Unknown Delivery Type` for invalid or incomplete context; never default to Retail Delivery.**
- [x] **Step 4: Run the resolver test and confirm it passes.**

Run: `php artisan test tests/Unit/Services/Logistics/DeliveryTypeResolverTest.php`
Expected: PASS.

### Task 3: Add normalized values to backend response boundaries

**Files:**
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `app/Services/Logistics/RiderLocationService.php`
- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php` only if its response path needs explicit serialization.
- Test: `tests/Feature/Logistics/RiderMyDeliveriesPageTest.php`, `tests/Feature/Logistics/CustomerTrackingTest.php`, and focused live-location/API tests.

- [x] **Step 1: Write response assertions for rider work items, dispatcher/batch delivery rows, live locations, and customer tracking.**
- [x] **Step 2: Run the focused feature tests and confirm the new assertions fail.**
- [x] **Step 3: Inject one resolver instance and append `delivery_type`/`delivery_label` at each response boundary without removing `purpose` or source fields.**
- [x] **Step 4: Ensure mixed batches derive labels per leg/stop and do not use one first-leg label for every stop.**
- [x] **Step 5: Run the focused feature tests and confirm they pass.**

### Task 4: Make frontend consumers render the server contract

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`
- Modify: `resources/js/components/logistics/DispatcherLiveTracking.tsx`
- Modify: `resources/js/components/logistics/ShipmentTrackingPanel.tsx`
- Modify: `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`
- Test: Existing focused frontend tests under `resources/js/**/__tests__/`.

- [x] **Step 1: Add typed optional/required normalized fields matching the response boundaries.**
- [x] **Step 2: Update labels to render `delivery_label`; retain source/reference identifiers as secondary information.**
- [x] **Step 3: Remove frontend classification maps and generic repair/retail fallbacks that are now redundant.**
- [x] **Step 4: Add or update tests proving server-provided labels render for all four workflows and unknown values remain safe.**
- [x] **Step 5: Run the focused frontend test suite and confirm it passes.**

### Task 5: Review, verify, and document the result

**Files:**
- Modify: `docs/ai-learning-log.md` only if a durable repository lesson was identified.

- [x] **Step 1: Run Laravel focused logistics tests.**
- [x] **Step 2: Run frontend tests.**
- [x] **Step 3: Run `git diff --check` and inspect the diff for duplicated mapping, authorization changes, and schema changes.**
- [x] **Step 4: Run `pnpm run build` to produce a fresh `public/build` only after source/tests are green.**
- [x] **Step 5: Confirm no migration or persisted delivery-type column was added.**
- [x] **Step 6: Commit the focused implementation and generated build only if the worktree contains no unrelated staged changes.**
