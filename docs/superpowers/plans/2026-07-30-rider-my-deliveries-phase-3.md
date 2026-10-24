# Rider My Deliveries Phase 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the approved Phase 3 delivery-completion and synchronization hardening without adding Phase 4 tracking or routing features.

**Architecture:** Keep the change inside the existing logistics services. `ShipmentLegService` will reconcile a batch whenever an attached stop reaches a terminal outcome, `RiderActiveWorkGuard` will identify the same canonical current item used by the rider read model, and existing transition services will return the already-committed result when the same rider action is replayed. `MyDeliveries.tsx` will refresh delivery data after stale conflicts and let the rider refresh manually.

**Tech Stack:** Laravel 12, Eloquent transactions and row locks, Inertia, React, TypeScript, Vitest, PHPUnit.

---

### Task 1: Reconcile terminal batch outcomes

**Files:**
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Test: `tests/Feature/Logistics/DeliveryExecutionTest.php`

- [x] Add failing tests for a batch with delivered plus cancelled stops and for a delivered stop followed by a detached failed stop.
- [x] Run the focused tests and confirm they fail because the batch remains `in_progress`.
- [x] Add one private batch-state reconciler and call it after delivery, cancellation, and failed-attempt detachment.
- [x] Complete a batch when attached stops are all terminal and at least one was delivered; cancel it when no attached stops remain or all attached stops are cancelled.
- [x] Run the focused tests and existing delivery-execution tests.

### Task 2: Make rider transitions replay-safe

**Files:**
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Modify: `app/Services/Logistics/AssignmentService.php`
- Modify: `app/Services/Logistics/ProofService.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Http/Requests/Logistics/RecordHandoffProofRequest.php`
- Modify: `app/Models/Logistics/HandoffProof.php`
- Create: `database/migrations/2026_07_30_000001_add_idempotency_key_to_handoff_proofs_table.php`
- Test: `tests/Feature/Logistics/BatchDispatchServiceTest.php`
- Test: `tests/Feature/Logistics/SingleDeliveryOfferTest.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`
- Test: `tests/Feature/Logistics/ShipmentLegServiceTest.php`

- [x] Add failing replay tests for batch decline, standalone decline, delivery-proof submission, and completed leg transitions.
- [x] Run each focused test and confirm the duplicate request currently fails or creates duplicate state.
- [x] Return the matching prior decline when its latest rider assignment already records that response.
- [x] Return the existing pending proof when a delivery-proof request is replayed while awaiting approval.
- [x] Treat repeated terminal transition calls as successful reads without creating duplicate events.
- [x] Run the focused service and API tests.

### Task 3: Arbitrate legacy multiple-active work on the server

**Files:**
- Modify: `app/Services/Logistics/RiderActiveWorkGuard.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Services/Logistics/ArrivalService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Test: `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Test: `tests/Feature/Logistics/DeliveryArrivalTest.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

- [x] Add failing tests proving the earliest-started item may advance while a later legacy-active item is rejected.
- [x] Implement canonical item selection using started time, then batch-before-single, then ID—the same ordering as the rider page.
- [x] Apply the guard at rider mutation trust boundaries: arrival, pickup confirmation, start delivery, proof submission, return handoff, and issue reporting.
- [x] Keep the canonical Current Delivery enabled in the UI and explain that only conflicting assignments are blocked.
- [x] Run focused backend and frontend tests.

### Task 4: Recover clearly from stale records

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`

- [x] Add failing tests for a manual refresh control and automatic refresh after a stale/conflict response.
- [x] Add one delivery-data refresh helper that updates the visible last-sync time.
- [x] On `409`, or a validation response explicitly indicating stale/changed/no-longer-current work, show a recovery message and reload `deliveryData`.
- [x] Preserve non-stale validation errors without refreshing.
- [x] Run the My Deliveries frontend tests.

### Task 5: Verify the complete Phase 3 slice

**Files:**
- Modify: `docs/superpowers/plans/2026-07-30-rider-my-deliveries-phase-3.md`

- [x] Run all focused backend and frontend Phase 3 tests.
- [x] Run `php artisan test tests/Feature/Logistics`.
- [x] Run `npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`.
- [x] Run `npm run build`.
- [x] Audit the four Phase 3 requirements against source and tests.
- [x] Mark completed plan steps and review `git diff --check`, `git status --short`, and the branch diff.
