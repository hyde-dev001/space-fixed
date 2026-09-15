# Shop-Owned Logistics Custody and Incidents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track investigation-worthy incidents and end rider custody only through approved delivery, confirmed shop return, or documented loss confirmation.

**Architecture:** Add one tenant-owned incident aggregate and reuse shipments/legs for physical returns. Incident findings never move money; existing cancellation/refund services remain the financial boundary.

**Tech Stack:** Laravel 12, Eloquent/MySQL, Inertia React/TypeScript, PHPUnit, Vitest

---

### Task 1: Persist incidents

**Files:** Create migration, `app/Models/Logistics/DeliveryIncident.php`, factory; modify leg relations; test `tests/Feature/Logistics/DeliveryIncidentSchemaTest.php`.

- [ ] Test types, status, reporter, photos, notes, resolution, responsible party, and tenant indexes.
- [ ] Verify failure; implement minimal string statuses/types; verify pass.
- [ ] Commit: `feat: persist delivery incidents`.

### Task 2: Report and review incidents

**Files:** Create `DeliveryIncidentService` and API controller/routes; modify rider/dispatcher pages; test `tests/Feature/Logistics/DeliveryIncidentApiTest.php`.

- [ ] Test assigned-rider reporting, required evidence, tenant isolation, dispatcher review, duplicate requests, and ordinary attempt reasons staying out of incidents.
- [ ] Verify failure.
- [ ] Add locked report/review transitions and internal alerts; expose no responsibility/internal notes to customers.
- [ ] Verify and commit: `feat: manage delivery incidents`.

### Task 3: Confirm documented loss

**Files:** Modify incident service and existing refund handoff; test `tests/Feature/Logistics/LostParcelResolutionTest.php`.

- [ ] Test dispatcher-only `loss_confirmed`, required investigation note/evidence, terminal custody event, and no direct refund mutation.
- [ ] Verify failure; implement one locked terminal resolution; verify pass.
- [ ] Commit: `feat: resolve confirmed parcel loss`.

### Task 4: Create tracked return-to-shop legs

**Files:** Modify `SourceShipmentService`, `ShipmentLegService`, API/UI; test `tests/Feature/Logistics/ReturnToShopTest.php`.

- [ ] Test post-pickup cancellation creates exactly one inbound return leg, retains rider custody, and rejects duplicate return creation.
- [ ] Verify failure.
- [ ] Reuse the existing shipment/leg models and stable shop lock; do not add a separate return table.
- [ ] Verify and commit: `feat: track parcel return to shop`.

### Task 5: Confirm rider return handoff

**Files:** Modify return service/controller and rider page; test `tests/Feature/Logistics/ReturnHandoffTest.php`.

- [ ] Test assigned-rider-only handoff confirmation/rejection, required shop-facing proof, replacement proof history, stable locks, duplicate requests, and audit events.
- [ ] Verify failure; add a locked rider confirmation that leaves custody active until shop receipt; verify pass.
- [ ] Commit: `feat: confirm rider return handoff`.

### Task 6: Confirm shop receipt and end custody

**Files:** Modify return service/controller and dispatcher page; test `tests/Feature/Logistics/ReturnReceiptTest.php`.

- [ ] Test shop-only receipt after rider handoff confirmation, proof, duplicate/concurrent requests, custody completion, final staged cancellation, and customer-safe event.
- [ ] Verify failure; add locked receipt transition; verify pass.
- [ ] Commit: `feat: confirm returned parcel receipt`.

### Task 7: Enforce post-pickup cancellation rules

**Files:** Modify cancellation entry points; test `tests/Feature/Logistics/PostPickupCancellationTest.php`.

- [ ] Test that final post-pickup cancellation is allowed only from `delivery_attempted`, `needs_resolution`, or an open lost/damaged incident, rejects every other state, and requires return-to-shop unless delivery, receipt, or `loss_confirmed` already resolves custody.
- [ ] Verify failure; route every caller through one shared guard; verify all cancellation regressions.
- [ ] Commit: `fix: enforce logistics custody cancellation`.

### Task 8: Extend overdue monitoring for returns

**Files:** Modify the Phase 3 overdue command; test `tests/Feature/Logistics/OverdueReturnMonitorTest.php`.

- [ ] Test returns awaiting rider handoff and shop receipt, deduplicated dispatcher alerts, and no automatic custody/cancellation transition.
- [ ] Verify failure; extend the existing monitor rather than adding a second job; verify pass.
- [ ] Commit: `feat: monitor overdue parcel returns`.

### Task 9: Verify Phase 4

- [ ] Run incident, return, cancellation, tenant, concurrency, and customer-tracking suites.
- [ ] Run all logistics tests, focused Vitest files, `npm run build`, and `git diff --check`.
