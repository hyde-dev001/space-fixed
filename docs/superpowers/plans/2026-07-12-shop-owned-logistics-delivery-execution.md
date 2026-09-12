# Shop-Owned Logistics Delivery Execution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add pickup custody, individual out-for-delivery, proof approval, automatic retries, maximum-attempt resolution, and overdue monitoring to accepted shop-owned batches.

**Architecture:** Extend the existing locked `ShipmentLegService` and Phase 2 batch flow; do not create a second state machine. Each mutation locks the leg and active assignment, detects repeated state, records customer-safe/internal events, and leaves third-party legs unchanged.

**Tech Stack:** Laravel 12, Eloquent/MySQL, queued jobs, Inertia React/TypeScript, PHPUnit, Vitest

---

### Task 1: Persist execution state

**Files:** Modify `database/migrations/2026_07_11_000006_create_delivery_batches_and_extend_dispatch.php`, `app/Models/Logistics/ShipmentLeg.php`, `app/Models/Logistics/HandoffProof.php`; test `tests/Feature/Logistics/DeliveryExecutionSchemaTest.php`.

- [ ] Write a failing test for `attempt_number`, `out_for_delivery_at`, pickup-proof review metadata, and `needs_resolution` status.
- [ ] Run `php artisan test tests/Feature/Logistics/DeliveryExecutionSchemaTest.php`; expect failure.
- [ ] Add only those fields/casts and the new leg status.
- [ ] Re-run the test; expect pass.
- [ ] Commit: `feat: persist delivery execution state`.

### Task 2: Confirm pickup custody

**Files:** Modify `app/Services/Logistics/ProofService.php`, `app/Services/Logistics/ShipmentLegService.php`, `app/Http/Controllers/Api/Logistics/ShipmentController.php`, `routes/web.php`; test `tests/Feature/Logistics/PickupCustodyTest.php`.

- [ ] Test assigned-rider-only confirmation, rejection reason, replacement proof history, duplicate confirmation, and concurrent confirmation.
- [ ] Run the focused test and verify failure.
- [ ] Lock leg, assignment, and current proof; confirmation performs the existing `picked_up` transition, rejection leaves the leg assigned, and no proof row is overwritten.
- [ ] Re-run; expect pass.
- [ ] Commit: `feat: confirm rider pickup custody`.

### Task 3: Start individual stops

**Files:** Modify `app/Services/Logistics/ShipmentLegService.php`, `app/Http/Controllers/Api/Logistics/ShipmentController.php`, `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`; test `tests/Feature/Logistics/OutForDeliveryTest.php` and `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`.

- [ ] Test that only a picked-up stop in the rider's in-progress batch can become out for delivery and that starting a batch changes no leg.
- [ ] Verify failure.
- [ ] Add one locked `markOutForDelivery()` transition, timestamp, customer-safe event, endpoint, and per-stop rider button.
- [ ] Verify focused tests.
- [ ] Commit: `feat: start individual delivery stops`.

### Task 4: Complete delivery through approved photo proof

**Files:** Modify existing proof/status services and rider/dispatcher pages; test `tests/Feature/Logistics/DeliveryProofApprovalTest.php`.

- [ ] Test photo requirement, assigned-rider submission, dispatcher-only approval, duplicate approval, and batch completion after its final stop.
- [ ] Verify failure.
- [ ] Reuse `handoff_proofs`; approval completes the leg and derives batch completion without duplicating proof logic.
- [ ] Verify tests.
- [ ] Commit: `feat: approve shop owned delivery proof`.

### Task 5: Schedule retries and maximum-attempt resolution

**Files:** Modify `app/Services/Logistics/ShipmentLegService.php`, `app/Services/Logistics/DeliveryScheduleService.php`, API/UI files; test `tests/Feature/Logistics/DeliveryRetryTest.php`.

- [ ] Test required failed-attempt photo/reason, next operating day, retained/replaced rider, configured attempt maximum, `needs_resolution`, and idempotency.
- [ ] Verify failure.
- [ ] In one transaction create one attempt, increment attempt number, recalculate through the existing calendar service, detach from the batch, and either return to pool or enter `needs_resolution`.
- [ ] Verify tests.
- [ ] Commit: `feat: retry failed shop deliveries`.

### Task 6: Resolve maximum attempts

**Files:** Add dispatcher endpoints and `Needs attention` controls; test `tests/Feature/Logistics/DeliveryResolutionTest.php`.

- [ ] Test authorised retry and a `return_required` cancellation decision from `needs_resolution`. While custody is active, `return_required` records the reason/instructions but keeps the leg in `needs_resolution` and does not mark the order/shipment cancelled; Phase 4 creates and completes the return leg before final cancellation. Reject any direct post-pickup cancellation that would end custody without delivery, return receipt, or `loss_confirmed`.
- [ ] Verify failure.
- [ ] Add locked retry and staged `return_required` resolution actions with required reasons, rider instructions, and customer-safe events. The Phase 3 UI must clearly show `Return required` as awaiting physical return, not completed cancellation.
- [ ] Verify tests.
- [ ] Commit: `feat: resolve exhausted delivery attempts`.

### Task 7: Flag overdue work

**Files:** Create `app/Console/Commands/MonitorOverdueDeliveries.php`; modify `routes/console.php`; test `tests/Feature/Logistics/OverdueDeliveryMonitorTest.php`.

- [ ] Test deliveries approaching cutoff, unanswered offers, late starts/stops, unscheduled retries, deduplication, material estimate changes/customer notification, and never auto-cancel.
- [ ] Verify failure.
- [ ] Add one scheduled command that records attention events/dispatcher notifications and recalculates/notifies a customer only when overdue facts materially change the estimate.
- [ ] Verify tests.
- [ ] Commit: `feat: monitor overdue deliveries`.

### Task 8: Verify Phase 3

- [ ] Run `php artisan test tests/Feature/Logistics`.
- [ ] Run focused logistics Vitest files.
- [ ] Run `npm run build` and `git diff --check`.
- [ ] Confirm third-party behavior is unchanged.
