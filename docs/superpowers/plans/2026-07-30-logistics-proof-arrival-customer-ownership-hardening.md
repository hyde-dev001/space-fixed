# Logistics Proof, Arrival, and Customer Ownership Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make proof mutations assignment-safe, enforce current-assignment arrivals for rider pickup and delivery proof, and reserve returned-repair recovery choices for the customer.

**Architecture:** Keep authorization at the existing controller and shared service boundaries. Reuse `ArrivalService::eventForAssignment` inside locked leg transactions; remove staff recovery mutation routes and UI actions; add a service-level customer ownership guard so alternate callers cannot bypass the rule.

**Tech Stack:** Laravel 11, PHPUnit feature tests, React/TypeScript, Vitest, Vite.

---

### Task 1: Lock rider proof mutations to the assigned leg

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Services/Logistics/ProofService.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`

- [ ] **Step 1: Write failing proof-authorization tests**

Add API tests proving:

- A same-shop rider with `record-logistics-proof` but no assignment receives `403`, stores no file, creates no proof, and does not change the leg.
- Same-shop users with `record-logistics-proof` plus either `assign-logistics-deliveries` or `approve-proof-of-delivery` retain the operational proof path.
- Back-office capability takes precedence when that user also has an active rider assignment.
- `rejectPickup` rejects a proof whose `shipment_leg_id` differs from the route leg.
- `rejectPickup` rejects a non-pickup or already reviewed proof.
- `confirmPickup` cannot revive a rejected pickup proof.
- Calling `ProofService::recordProof` with a rider whose assignment is no longer active fails without mutation.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php
```

Expected: the new tests fail because unassigned proof submission and cross-leg proof review are currently accepted.

- [ ] **Step 3: Implement the minimum shared-boundary checks**

- In `ShipmentController::proof`, classify users with `assign-logistics-deliveries` or `approve-proof-of-delivery` as back-office before resolving a rider profile; otherwise require the current active rider assignment.
- Preserve shop-owner access.
- In `ProofService::recordProof`, after locking the leg, require the supplied rider to still have an `assigned` or `accepted` assignment for that leg.
- In pickup confirmation/rejection, require the proof to belong to the route leg, use `handoff_type=pickup`, and be `pending` before mutation. Preserve only the existing already-approved/already-picked-up confirmation replay.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Logistics/LogisticsApiTest.php
```

Expected: all proof authorization tests pass.

- [ ] **Step 5: Commit**

```powershell
git add -- app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/Logistics/ProofService.php app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/LogisticsApiTest.php
git commit -m "fix: bind logistics proof actions to assignments"
```

### Task 2: Enforce arrival before successful rider handoff

**Files:**
- Modify: `tests/Feature/Logistics/DeliveryExecutionTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `app/Services/Logistics/ProofService.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`

- [ ] **Step 1: Write failing arrival-prerequisite tests**

Add tests proving:

- Pickup confirmation fails without a `pickup_arrived` event for the active assignment.
- The same confirmation succeeds after an assignment-scoped location-exception or verified arrival.
- An arrival from a cancelled previous assignment does not unlock the new assignment.
- Rider delivery-proof submission fails without a current-assignment `dropoff_arrived` event.
- Shop-owner/back-office proof submission remains allowed without a rider arrival, including users who also have a rider profile and active assignment.
- Return-to-shop `receive` proof keeps its existing flow and does not require a customer drop-off arrival.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsApiTest.php
```

Expected: rider pickup and delivery proof tests fail because the server currently trusts the UI arrival gate.

- [ ] **Step 3: Reuse the current assignment-scoped arrival lookup**

- In `ShipmentLegService::markPickedUp`, resolve the rider's current active assignment inside the locked transaction and require `pickup_arrived` through `ArrivalService::eventForAssignment`.
- In `ProofService::recordProof`, for rider `handoff_type=delivery`, require `dropoff_arrived` for the same active assignment before proof creation or status mutation.
- Do not require this arrival for shop-owner/back-office submissions or `receive` return handoffs.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Logistics/DeliveryArrivalTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/ReturnToShopTest.php
```

Expected: all arrival, execution, proof, and return handoff tests pass.

- [ ] **Step 5: Commit**

```powershell
git add -- app/Services/Logistics/ProofService.php app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsApiTest.php
git commit -m "fix: enforce rider arrivals at the service boundary"
```

### Task 3: Make returned-repair recovery customer-only

**Files:**
- Modify: `tests/Feature/Repair/RepairReturnRecoveryTest.php`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx`
- Modify: `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `routes/shop-owner-api.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx`
- Modify: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`

- [ ] **Step 1: Write failing ownership tests**

Change backend tests to require:

- Shop-owner and repairer recovery mutation routes are unavailable.
- Direct service calls reject an actor other than the repair customer.
- The customer still schedules re-delivery with a future date/window.
- The customer still selects free shop pickup.
- Existing paid recovery state remains unchanged.

Change both staff UI tests to require the recovery status/instruction but no `Schedule re-delivery` or `Set for shop pickup` buttons.

- [ ] **Step 2: Run backend and frontend tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php
npx vitest run "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx" "resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx"
```

Expected: tests fail because staff routes and actions still mutate recovery.

- [ ] **Step 3: Remove staff mutation paths and guard the service**

- Require `actorType === User::class` and `actorId === repair.user_id` inside the locked `resolveReturnRecovery` transaction.
- Remove the shop-owner and repairer recovery POST routes.
- Remove the now-unused `RepairWorkflowController::resolveReturnRecovery`.
- Remove both staff UI handlers/buttons and show a non-actionable instruction directing the customer to My Repairs.
- Keep the customer controller, schedule validation, payment flow, and existing recovery records unchanged.

- [ ] **Step 4: Run backend and frontend tests and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php
npx vitest run "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx" "resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx" "resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx"
```

Expected: customer recovery passes; staff mutation paths are absent.

- [ ] **Step 5: Commit**

```powershell
git add -- app/Services/RepairDeliveryService.php app/Http/Controllers/Api/RepairWorkflowController.php routes/shop-owner-api.php routes/web.php resources/js/Pages/ShopOwner/Repairs resources/js/Pages/ERP/repairer tests/Feature/Repair/RepairReturnRecoveryTest.php
git commit -m "fix: reserve repair return recovery for customers"
```

### Task 4: Regression verification and public build

**Files:**
- Modify: `public/build/**`

- [ ] **Step 1: Run logistics and affected repair suites**

Run:

```powershell
php artisan test tests/Feature/Logistics tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php
```

Expected: all tests pass.

- [ ] **Step 2: Run affected frontend tests**

Run:

```powershell
npx vitest run resources/js/Pages/ERP/Logistics/__tests__ "resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx" "resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx" "resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx"
```

Expected: all tests pass.

- [ ] **Step 3: Build production assets**

Run:

```powershell
npm run build
```

Expected: Vite production build succeeds.

- [ ] **Step 4: Commit the refreshed public build**

```powershell
git add -- public/build
git commit -m "build: refresh public assets for logistics hardening"
```

- [ ] **Step 5: Inspect final diff and status**

Run:

```powershell
git status --short
git log -6 --oneline
```

Expected: clean worktree with implementation and build commits present; do not push until requested.
