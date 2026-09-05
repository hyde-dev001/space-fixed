# Shop-Owned Logistics Dispatcher-First Scheduling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create normal Shop-owned logistics legs as unscheduled work for the dispatcher while preserving customer-selected dates for warranty repair recovery.

**Architecture:** Keep `DeliveryScheduleService::estimate()` unchanged for quote and explicit planning consumers. Change scheduling only at the source-shipment boundaries: normal retail, repair intake, and repair return creation will emit the existing `unscheduled` state; an explicit warranty recovery schedule will bypass estimation and remain scheduled. Reuse the existing dispatcher scheduling endpoint and ERP unscheduled-leg flow.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, PHPUnit feature tests, Inertia/React ERP logistics UI.

---

### Task 1: Update retail source-shipment regression coverage

**Files:**
- Modify: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`
- Implementation target: `app/Services/Logistics/SourceShipmentService.php`

- [ ] **Step 1: Write the failing test**

Update the valid Shop-owned retail shipment test so it expects `schedule_status` to be `unscheduled`, with null `scheduled_delivery_date`, `delivery_window`, and `estimated_at`. Keep the origin/destination snapshot assertions and assert that the shipment does not create the customer-facing `delivery_estimated` event while retaining internal dispatcher attention.

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php --filter=shop_owned_retail_delivery
```

Expected: FAIL because the current implementation writes `schedule_status = scheduled` and emits `delivery_estimated`.

- [ ] **Step 3: Write the minimal retail implementation**

In `SourceShipmentService::ensureRetailOrderShipment()`:

1. Keep the Shop-owned carrier check and destination snapshots.
2. Replace the automatic `estimate()` call with a non-scheduling payload using `schedule_status = unscheduled`, null date/window, and null `estimated_at`.
3. Preserve any already-needed coverage distance without running rider/capacity estimation.
4. Keep the existing internal dispatcher-attention event and do not emit the customer-facing scheduled-estimate events for an unscheduled leg.

- [ ] **Step 4: Run the focused test to verify it passes**

Run the same command and expect PASS.

- [ ] **Step 5: Commit the retail boundary and test**

```powershell
git add -- tests/Feature/Logistics/SourceModuleShipmentRequestTest.php app/Services/Logistics/SourceShipmentService.php
git commit -m "Create retail logistics legs for dispatcher scheduling"
```

### Task 2: Make normal repair pickup and return legs unscheduled

**Files:**
- Modify: `app/Services/Logistics/SourceShipmentService.php`
- Modify: `tests/Feature/Repair/RepairLogisticsIntakeTest.php`
- Modify: `tests/Feature/Repair/RepairLogisticsReturnTest.php`

- [ ] **Step 1: Write the failing repair tests**

Change the normal repair pickup and return assertions to expect `schedule_status = unscheduled`, null delivery date/window, and null `estimated_at`. Preserve assertions for coverage, snapshots, delivery fee, and distance so only slot selection changes.

- [ ] **Step 2: Run the focused repair tests to verify they fail**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Repair/RepairLogisticsReturnTest.php --filter="logistics|pickup|return"
```

Expected: FAIL because both source paths currently call `estimate()` and store a schedule.

- [ ] **Step 3: Write the minimal normal repair implementation**

In `SourceShipmentService`:

1. Keep `coverage()` and its existing fail-closed validation for repair pickup and return addresses.
2. Replace normal inbound/outbound `estimate()` calls with the shared unscheduled payload and retain `coverage['distance_km']` where available.
3. Set `estimated_at` only for an actually scheduled payload; normal unscheduled legs must leave it null.
4. Keep `recordScheduleEvents()` so unscheduled legs produce internal dispatcher attention and scheduled legs retain their existing events.

- [ ] **Step 4: Run the focused repair tests to verify they pass**

Run the same repair test command and expect PASS.

- [ ] **Step 5: Commit the normal repair behavior**

```powershell
git add -- app/Services/Logistics/SourceShipmentService.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Repair/RepairLogisticsReturnTest.php
git commit -m "Defer normal repair logistics scheduling to dispatcher"
```

### Task 3: Preserve explicit warranty recovery scheduling

**Files:**
- Modify: `app/Services/Logistics/SourceShipmentService.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Test: `tests/Feature/Repair/RepairReturnRecoveryTest.php`
- Test: `tests/Feature/Repair/RepairLogisticsIntakeTest.php`

- [ ] **Step 1: Add the failing explicit-schedule guard test**

Add or extend a warranty recovery test so a customer-selected date/window remains exactly on the created recovery leg. Where practical, inject a `DeliveryScheduleService` test double and assert that explicit recovery does not call `estimate()`.

- [ ] **Step 2: Run the focused recovery tests to verify the intended guard**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairLogisticsIntakeTest.php --filter="recovery|retry|scheduled"
```

Expected: the new guard fails if the normal unscheduled refactor overwrites or drops the explicit customer schedule.

- [ ] **Step 3: Implement the exception narrowly**

1. In `ensureRepairReturnShipment()`, start with the unscheduled payload and replace it with the validated requested date/window only when both explicit recovery values are present.
2. Do not call `estimate()` when the explicit recovery schedule is supplied.
3. In the direct repair pickup recovery/retry leg creation in `RepairDeliveryService`, derive `schedule_status`, date/window, and `estimated_at` from the explicit recovery values instead of hard-coding `scheduled`.
4. Keep existing customer recovery payment, address, and plan validation unchanged.

- [ ] **Step 4: Run the recovery tests to verify they pass**

Run the same recovery command and expect PASS.

- [ ] **Step 5: Commit the warranty recovery exception**

```powershell
git add -- app/Services/Logistics/SourceShipmentService.php app/Services/RepairDeliveryService.php tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairLogisticsIntakeTest.php
git commit -m "Preserve customer schedules for warranty recovery"
```

### Task 4: Verify dispatcher scheduling and the complete focused suite

**Files:**
- Test: `tests/Feature/Logistics/DeliveryBatchApiTest.php`
- Test: `tests/Feature/Logistics/BatchDispatchServiceTest.php`
- Test: `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`
- Test: `tests/Feature/Repair/RepairLogisticsIntakeTest.php`
- Test: `tests/Feature/Repair/RepairLogisticsReturnTest.php`
- Test: `tests/Feature/Repair/RepairReturnRecoveryTest.php`

- [ ] **Step 1: Confirm the existing dispatcher promotion tests cover `unscheduled` legs**

Run:

```powershell
php artisan test tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php --filter="unscheduled|schedule"
```

Expected: PASS, proving the existing dispatcher endpoint still promotes the new source legs to `scheduled`.

- [ ] **Step 2: Run the complete focused logistics suite**

```powershell
php artisan test tests/Feature/Logistics/SourceModuleShipmentRequestTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Repair/RepairLogisticsIntakeTest.php tests/Feature/Repair/RepairLogisticsReturnTest.php tests/Feature/Repair/RepairReturnRecoveryTest.php
```

Expected: PASS with no new warnings.

- [ ] **Step 3: Run repository quality checks**

```powershell
git diff --check HEAD~3 HEAD
composer test
```

If the focused implementation uses fewer than three commits, adjust the diff range to include all implementation commits and exclude the previously committed spec/plan as needed.

- [ ] **Step 4: Review changed files for scope and dead code**

Confirm that `DeliveryScheduleService::estimate()` remains unchanged for quote/preview callers, no new migration or endpoint was added, no unrelated worktree files were staged, and all temporary variables/imports introduced by the refactor are used.

- [ ] **Step 5: Commit any final test-only or cleanup change**

```powershell
git status --short --branch
git diff --check
```

Only commit a final cleanup if it is required by the tests or the approved spec; preserve unrelated working-tree changes.
