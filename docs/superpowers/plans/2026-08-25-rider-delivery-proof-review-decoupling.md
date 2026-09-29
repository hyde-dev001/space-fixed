# Rider Delivery Proof Review Decoupling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let riders progress after submitting delivery PODs while keeping dispatcher approval as the business completion gate, with immutable proof corrections and state-safe batch progression.

**Architecture:** Add a persisted `rider_progress_state` state machine beside the existing business `ShipmentLegStatus`. Route delivery-POD submission and review through transactional services that preserve proof immutability, derive rider blocking only from `rider_progress_state = active`, and defer notifications until the state transaction commits. Audit every existing leg-status consumer so `proof_correction_required` is correction-only, never transit, completion, or ordinary schedulable work.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent transactions and backed enums, MySQL-compatible migrations, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-25-rider-delivery-proof-review-decoupling-design.md`

---

## Global constraints

- Work only in `C:\xampp\htdocs\solespace-master\.worktrees\customer-delivery-receipt-dispute`.
- The worktree has already been rebased onto `origin/solespace-b`; preserve that base and unrelated user changes.
- Do not edit `.env`, `vendor/`, `node_modules/`, or generated assets unless a verification command regenerates an explicitly required artifact.
- Keep `DeliveryBatch.status` as a business/dispatcher lifecycle field. It must never be the sole rider-blocking predicate.
- Use exactly these rider states: `active`, `proof_submitted`, `proof_action_required`, and `rider_released`. Do not introduce rider-side `completed`.
- Apply the new correction chain only to delivery PODs (`handoff_type = delivery`). Preserve the existing return-to-shop `receive` workflow.
- Rejected delivery proof is immutable. A replacement is a new `HandoffProof` row with a new idempotency key and `replaces_proof_id` pointing to the rejected row.
- `rejectProof()` must not create an attempt, retry schedule, return resolution, arrival requirement, or `in_transit` transition. Physical revisit uses the existing explicit logistics resolution workflow.
- Persist `DeliveryEvent` audit rows transactionally, but dispatch notifications/broadcasts/mail after the outermost transaction commits.
- Use `@superpowers:test-driven-development` for each behavior change, `@laravel-best-practices` for PHP/Laravel changes, `@security-review` for proof endpoints and private evidence, `@vercel-react-best-practices` and `@ui-styling` for React/TypeScript changes, `@ponytail` for the simplification pass, and `@verification-before-completion` before completion claims.
- The repository policy defaults to one main agent working sequentially. Do not dispatch subagents unless the user explicitly approves the optional parallel-review gate.

## File map

### Persistence and state

- Create: `app/Enums/Logistics/RiderProgressState.php`
- Modify: `app/Enums/Logistics/ShipmentLegStatus.php`
- Modify: `app/Models/Logistics/ShipmentLeg.php`
- Modify: `app/Models/Logistics/HandoffProof.php`
- Create: `database/migrations/2026_08_25_000001_add_rider_progress_state_to_shipment_legs.php`
- Create: `database/migrations/2026_08_25_000002_add_replacement_link_to_handoff_proofs.php`
- Create: `app/Console/Commands/BackfillRiderProgressState.php`

### Backend workflow

- Modify: `app/Services/Logistics/ProofService.php`
- Create: `app/Services/Logistics/ProofReviewService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Services/Logistics/RiderActiveWorkGuard.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Modify: `app/Services/Logistics/DeliveryEventService.php`
- Modify: `app/Services/Logistics/LogisticsNotificationService.php`

### Status consumers and projections

- Modify as required by the audit: `app/Services/Logistics/AssignmentService.php`
- Modify as required by the audit: `app/Services/Logistics/ArrivalService.php`
- Modify as required by the audit: `app/Services/Logistics/DeliveryScheduleService.php`
- Modify as required by the audit: `app/Services/Logistics/BatchSuggestionService.php`
- Modify as required by the audit: `app/Console/Commands/MonitorOverdueDeliveries.php`
- Modify as required by the audit: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify as required by the audit: `app/Services/Logistics/CustomerTrackingService.php`
- Modify as required by the audit: `app/Http/Controllers/Logistics/CustomerTrackingController.php`
- Modify as required by the audit: `app/Services/OrderReceiptService.php`
- Modify as required by the audit: `app/Http/Controllers/UserSide/OrderController.php`
- Modify as required by the audit: `app/Services/RepairDeliveryService.php`
- Modify as required by the audit: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify as required by the audit: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`

### Frontend

- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/components/logistics/ShipmentTrackingPanel.tsx`
- Modify as required by status presentation: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`

### Tests

- Create: `tests/Feature/Logistics/RiderProofReviewStateTest.php`
- Create: `tests/Feature/Logistics/RiderProgressBackfillTest.php`
- Create: `tests/Feature/Logistics/ProofReviewFlowTest.php`
- Create or extend: `tests/Feature/Logistics/RiderProgressionTest.php`
- Create or extend: `tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Modify: `tests/Feature/Logistics/DeliveryExecutionTest.php`
- Modify: `tests/Feature/Logistics/LogisticsConcurrencyTest.php`
- Modify: `tests/Feature/Logistics/LogisticsNotificationTest.php`
- Modify: `tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php`
- Modify: `tests/Feature/Logistics/DeliveryArrivalTest.php`
- Modify: `tests/Feature/Logistics/AssignmentServiceTest.php`
- Modify: `tests/Feature/Logistics/DeliveryScheduleServiceTest.php`
- Modify: `tests/Feature/Logistics/OverdueDeliveryMonitorTest.php`
- Modify: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Modify: `tests/Feature/Logistics/ReturnToShopTest.php`
- Modify relevant repair/staff coverage tests after the audit identifies the exact assertions.
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Modify: `resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx` only if the shared customer fixture requires a correction-status assertion.

---

### Task 1: Establish the persisted state and proof-link contract

**Files:**
- Create: `tests/Feature/Logistics/RiderProofReviewStateTest.php`
- Create: `app/Enums/Logistics/RiderProgressState.php`
- Modify: `app/Enums/Logistics/ShipmentLegStatus.php`
- Modify: `app/Models/Logistics/ShipmentLeg.php`
- Modify: `app/Models/Logistics/HandoffProof.php`
- Create: `database/migrations/2026_08_25_000001_add_rider_progress_state_to_shipment_legs.php`
- Create: `database/migrations/2026_08_25_000002_add_replacement_link_to_handoff_proofs.php`

**Interfaces:**
- Produces the four-value `RiderProgressState` PHP enum and the
  `proof_correction_required` `ShipmentLegStatus` case.
- Adds `shipment_legs.rider_progress_state` with a safe `active` default and
  adds nullable `handoff_proofs.replaces_proof_id` with a self-referential
  restrictive foreign key.
- Exposes `ShipmentLeg::$casts['rider_progress_state']` and
  `HandoffProof::replacedProof()` / `HandoffProof::replacements()`.

- [x] **Step 1: Write failing schema and model tests**

Add tests named like:

```php
public function test_rider_progress_state_and_replacement_link_are_persisted(): void
{
    $this->assertTrue(Schema::hasColumn('shipment_legs', 'rider_progress_state'));
    $this->assertTrue(Schema::hasColumn('handoff_proofs', 'replaces_proof_id'));
    $this->assertSame('active', RiderProgressState::ACTIVE->value);
    $this->assertSame('proof_correction_required', ShipmentLegStatus::PROOF_CORRECTION_REQUIRED->value);
}

public function test_rider_progress_state_is_cast_and_proof_chain_is_linked(): void
{
    $leg = ShipmentLeg::factory()->create(['rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED]);
    $rejected = HandoffProof::factory()->create([
        'shipment_leg_id' => $leg->id,
        'handoff_type' => 'delivery',
        'review_status' => 'rejected',
    ]);
    $replacement = HandoffProof::factory()->create([
        'shipment_leg_id' => $leg->id,
        'handoff_type' => 'delivery',
        'replaces_proof_id' => $rejected->id,
        'review_status' => 'pending',
    ]);

    $this->assertSame(RiderProgressState::PROOF_ACTION_REQUIRED, $leg->fresh()->rider_progress_state);
    $this->assertTrue($rejected->fresh()->replacements->contains('id', $replacement->id));
    $this->assertTrue($replacement->fresh()->replacedProof->is($rejected));
}
```

- [x] **Step 2: Run the focused tests and verify they fail for missing schema/state**

Run:

```powershell
php artisan test tests/Feature/Logistics/RiderProofReviewStateTest.php
```

Expected: FAIL because the enum case, columns, casts, and relationships do not yet exist.

- [x] **Step 3: Implement the minimal persistence contract**

Implement only the enum, enum case, model casts/relationships/fillable fields,
and migrations. Keep the state column as a string-backed enum rather than a
database enum. Use restrictive deletion behavior for a proof with replacements;
do not add a cascade that can erase the audit chain.

- [x] **Step 4: Run the focused tests and schema regression**

Run:

```powershell
php artisan test tests/Feature/Logistics/RiderProofReviewStateTest.php tests/Feature/Logistics/DeliveryExecutionSchemaTest.php
```

Expected: PASS, including existing schema assertions.

- [x] **Step 5: Commit the persistence contract**

```powershell
git add app/Enums/Logistics/RiderProgressState.php app/Enums/Logistics/ShipmentLegStatus.php app/Models/Logistics/ShipmentLeg.php app/Models/Logistics/HandoffProof.php database/migrations/2026_08_25_000001_add_rider_progress_state_to_shipment_legs.php database/migrations/2026_08_25_000002_add_replacement_link_to_handoff_proofs.php tests/Feature/Logistics/RiderProofReviewStateTest.php
git diff --cached --check
git commit -m "feat: add rider proof review state persistence"
```

### Task 2: Add the deterministic, rerunnable legacy backfill

**Files:**
- Create: `tests/Feature/Logistics/RiderProgressBackfillTest.php`
- Create: `app/Console/Commands/BackfillRiderProgressState.php`
- Modify only if needed: `app/Models/Logistics/ShipmentLeg.php`

**Interfaces:**
- Adds `php artisan logistics:backfill-rider-progress-state`.
- Applies the frozen precedence order using the latest delivery proof ordered
  by `recorded_at DESC, id DESC`, excluding `receive` proofs.
- Emits a stable reconciliation marker/log for an approved proof on a stale
  awaiting leg, without approving proofs or completing business records.

- [x] **Step 1: Write failing mapping tests**

Cover these fixtures and assertions:

1. `delivered`, `cancelled`, and `failed` map to `rider_released` without
   changing business status.
2. Any non-terminal leg whose latest delivery proof is rejected maps to
   `proof_correction_required` / `proof_action_required`, including a legacy
   `in_transit` row.
3. `awaiting_proof_approval` plus latest pending delivery proof maps to
   `proof_submitted`.
4. `awaiting_proof_approval` plus latest approved delivery proof maps to
   `rider_released` and records a stale-leg reconciliation marker.
5. `awaiting_proof_approval` without a delivery proof maps to `active`.
6. Other non-terminal statuses map to `active`.
7. A `receive` proof never drives a delivery-POD mapping.
8. Running the command twice produces the same state and no duplicate marker.

Use tied `recorded_at` values with different IDs to prove the ordering is
deterministic.

- [x] **Step 2: Run the backfill tests and verify they fail**

```powershell
php artisan test tests/Feature/Logistics/RiderProgressBackfillTest.php
```

Expected: FAIL because the command and mapping logic do not exist.

- [x] **Step 3: Implement the command in stable chunks**

Process legs in ascending primary-key order. For each locked leg:

```text
if business status is delivered/cancelled/failed:
    rider state = rider_released
else if latest delivery proof is rejected:
    business status = proof_correction_required
    rider state = proof_action_required
else if status is awaiting and latest delivery proof is pending:
    rider state = proof_submitted
else if status is awaiting and latest delivery proof is approved:
    rider state = rider_released
    record one stale-awaiting reconciliation marker
else if status is awaiting and no delivery proof exists:
    rider state = active
else:
    rider state = active
```

Make updates idempotent, avoid file/proof mutations, and report mapping counts.
Use the existing `DeliveryEvent` or structured logging convention for the stale
marker without dispatching a customer or rider notification for the marker.

- [x] **Step 4: Run the mapping tests and a dry command invocation**

```powershell
php artisan test tests/Feature/Logistics/RiderProgressBackfillTest.php
php artisan logistics:backfill-rider-progress-state --help
```

Expected: PASS; the command prints its options without changing data when only
`--help` is supplied.

- [x] **Step 5: Commit the deterministic backfill**

```powershell
git add app/Console/Commands/BackfillRiderProgressState.php tests/Feature/Logistics/RiderProgressBackfillTest.php
git diff --cached --check
git commit -m "feat: backfill rider proof progress states"
```

### Task 3: Make logistics event notifications commit-safe

**Files:**
- Modify: `app/Services/Logistics/DeliveryEventService.php`
- Modify: `app/Services/Logistics/LogisticsNotificationService.php` only for
  proof-chain metadata/type routing
- Modify: `tests/Feature/Logistics/LogisticsNotificationTest.php`
- Modify: `tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php`

**Interfaces:**
- `DeliveryEventService::record()` still returns the persisted `DeliveryEvent`
  immediately to transaction callers.
- Notification side effects run after the outermost transaction commits and
  reload the committed event.
- Existing notification grouping remains idempotent; proof notifications add
  the relevant proof/replacement IDs to their data.

- [x] **Step 1: Write commit/rollback notification tests**

Add tests that:

- Call `record()` inside `DB::transaction()`, assert the event exists but its
  notification does not exist until the transaction callback returns.
- Throw from a transaction after recording `proof_required` or
  `proof_rejected`, then assert neither the event nor notification claims the
  rolled-back transition.
- Call `record()` outside an open transaction and preserve the existing direct
  notification behavior.
- Record the same event twice/replay the callback and assert the existing
  `group_key` deduplication remains stable.

- [x] **Step 2: Run the notification tests and verify the pre-change failure**

```powershell
php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php
```

Expected: the new transaction timing assertions fail because notification
creation is currently synchronous inside `DeliveryEventService::record()`.

- [x] **Step 3: Defer notification dispatch after commit**

Persist the event in the current transaction, capture only its ID, and use
Laravel `DB::afterCommit()` or an after-commit queued job to call
`notifyForEvent(DeliveryEvent::query()->findOrFail($eventId))`. Do not close over
an uncommitted model graph. Keep customer-visible event rows transactional and
do not notify customers for internal proof rejection details.

- [x] **Step 4: Run notification and privacy regressions**

```powershell
php artisan test tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php tests/Feature/Logistics/LogisticsAuditPrivacyTest.php
```

Expected: PASS, with no notification emitted from a rolled-back transaction.

- [x] **Step 5: Commit commit-safe event dispatch**

```powershell
git add app/Services/Logistics/DeliveryEventService.php app/Services/Logistics/LogisticsNotificationService.php tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php
git diff --cached --check
git commit -m "fix: defer logistics notifications until commit"
```

### Task 4: Implement initial and replacement delivery-POD submission

**Files:**
- Create: `tests/Feature/Logistics/ProofReviewFlowTest.php`
- Modify: `app/Services/Logistics/ProofService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php` for proof/state payload fields
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `tests/Feature/Logistics/LogisticsConcurrencyTest.php`

**Interfaces:**
- Keep `POST /api/logistics/legs/{leg}/proof` as the submission endpoint.
- Accept optional `replaces_proof_id` only for an authorized correction of the
  latest rejected delivery proof.
- Initial delivery submission changes the leg to
  `awaiting_proof_approval` / `proof_submitted`.
- Replacement submission creates a new pending proof linked to the rejected
  proof, changes the leg to `awaiting_proof_approval` / `proof_submitted`, and
  does not require a new arrival or active-work position.

- [x] **Step 1: Write failing submission/replacement tests**

Cover:

- A valid initial delivery proof records one pending proof, sets both states,
  and leaves shipment/order/batch business completion unchanged.
- A rider can submit an initial proof only from `rider_progress_state = active`
  and still needs the existing drop-off arrival.
- A replacement accepts a new idempotency key and creates a distinct row with
  `replaces_proof_id` pointing to the rejected proof.
- The original rejected proof's file, notes, metadata, reviewer, and review
  status remain unchanged.
- A replacement does not require a new drop-off arrival and succeeds after the
  rider has started another active delivery.
- A replacement with a pending/approved/receive/other-leg proof is rejected.
- Reusing an authorized idempotency key returns the same row; reusing it with a
  conflicting payload is rejected.
- Two concurrent replacement submissions produce at most one pending
  replacement under the locked leg.

- [x] **Step 2: Run the focused API tests and verify they fail**

```powershell
php artisan test tests/Feature/Logistics/ProofReviewFlowTest.php tests/Feature/Logistics/LogisticsApiTest.php --filter="proof|POD|delivery"
```

Expected: FAIL because there is no state update, replacement field, or
replacement validation.

- [x] **Step 3: Implement the two submission modes**

Inside the existing leg transaction:

1. Lock and tenant/assignment-authorize the leg before idempotency replay.
2. If `replaces_proof_id` is absent, require rider state `active`, the existing
   physical delivery status, and drop-off arrival.
3. If it is present, require the latest delivery proof on the same leg to be
   rejected, require rider state `proof_action_required`, and skip only the new
   arrival requirement.
4. Insert a new proof row for every new submission; never update an existing
   file/path/submission row.
5. For delivery PODs, update the leg status and rider state in the same
   transaction and record proof-chain metadata in the event.
6. Leave `receive` proof behavior on its current return flow.

Do not use the active-work guard for the replacement mode; retain tenant,
assignment, proof-chain, authorization, and concurrency checks.

- [x] **Step 4: Run focused API, concurrency, and idempotency tests**

```powershell
php artisan test tests/Feature/Logistics/ProofReviewFlowTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php
```

Expected: PASS for initial/replacement behavior and existing idempotent proof
replay behavior.

- [x] **Step 5: Commit submission behavior**

```powershell
git add app/Services/Logistics/ProofService.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Http/Controllers/Logistics/ErpLogisticsController.php tests/Feature/Logistics/ProofReviewFlowTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php
git diff --cached --check
git commit -m "feat: release rider after delivery proof submission"
```

### Task 5: Add transactional dispatcher approval and rejection services

**Files:**
- Create: `app/Services/Logistics/ProofReviewService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `tests/Feature/Logistics/ProofReviewFlowTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`
- Modify: `tests/Feature/Logistics/ReturnToShopTest.php`

**Interfaces:**
- `ProofReviewService` owns delivery-POD approval/rejection transaction
  boundaries and does not use rider active-work ordering.
- Approval of the current pending delivery proof sets proof approved, leg
  delivered, and rider state `rider_released`, then invokes existing shipment,
  order, refund, and business-batch reconciliation.
- Rejection sets proof rejected, leg `proof_correction_required`, and rider
  state `proof_action_required`; it never changes the leg to `in_transit` or
  creates a physical attempt.
- Shared controller endpoints branch by `handoff_type`; return `receive`
  proofs continue through their existing workflow.

- [x] **Step 1: Write failing review tests**

Cover:

- Dispatcher approval succeeds after the rider has progressed to another
  standalone delivery or batch stop.
- Approval is idempotent and cannot approve an old rejected proof when a
  pending replacement is current.
- Approval completes the leg and only then completes existing business
  shipment/order/batch records.
- Rejection sets the two correction states, preserves the proof row as
  rejected, and creates no `DeliveryAttempt`, retry schedule, return
  resolution, or `in_transit` status.
- Duplicate rejection is stable; approval/rejection races produce one valid
  final review state.
- Return-to-shop receive approval/rejection behavior remains unchanged.

- [x] **Step 2: Run review tests and verify the current rejection regression**

```powershell
php artisan test tests/Feature/Logistics/ProofReviewFlowTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/ReturnToShopTest.php --filter="proof|approval|reject|return"
```

Expected: the rejection test fails because the current controller writes
`in_transit`, and approval relies on the rider-oriented service boundary.

- [x] **Step 3: Implement `ProofReviewService` and explicit service calls**

Lock the proof and leg in one transaction. Validate that the proof is the
current pending delivery proof before changing review metadata. Use an explicit
non-rider delivery-completion method in `ShipmentLegService`; do not make the
controller pass a magic rider/null flag. Set `rider_released` as part of the
same state transition and preserve existing reconciliation behavior.

For rejection, write only review metadata, correction status/state, and the
internal audit event. Do not touch attempts, assignment custody, arrival data,
resolution fields, or schedule fields. Any future revisit must enter the
existing explicit failed-attempt/incident resolution operation.

- [x] **Step 4: Run focused review, return, and privacy tests**

```powershell
php artisan test tests/Feature/Logistics/ProofReviewFlowTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/ReturnToShopTest.php tests/Feature/Logistics/LogisticsAuditPrivacyTest.php
```

Expected: PASS, with no customer-visible rejection reason or unapproved proof.

- [x] **Step 5: Commit dispatcher review behavior**

```powershell
git add app/Services/Logistics/ProofReviewService.php app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/Logistics/ShipmentLegService.php tests/Feature/Logistics/ProofReviewFlowTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/ReturnToShopTest.php
git diff --cached --check
git commit -m "feat: make proof review asynchronous for riders"
```

### Task 6: Make active-work and batch progression state-driven

**Files:**
- Create or modify: `tests/Feature/Logistics/RiderProgressionTest.php`
- Modify: `app/Services/Logistics/RiderActiveWorkGuard.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php` only where batch
  start/active-work checks are coupled
- Modify: `app/Services/Logistics/ShipmentLegService.php` for state updates on
  physical/terminal transitions
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `tests/Feature/Logistics/DeliveryExecutionTest.php`
- Modify: `tests/Feature/Logistics/LogisticsConcurrencyTest.php`
- Modify: `tests/Feature/Logistics/RiderMyDeliveriesPageTest.php`

**Interfaces:**
- Active standalone queries use `rider_progress_state = active` plus existing
  accepted-assignment scopes.
- Batch active-work queries require at least one eligible active leg; batch
  `in_progress` alone never blocks a rider.
- Batch current/up-next payloads expose a current item only when an active stop
  exists; all-submitted/all-correction batches are review-pending rider
  history/context and do not block new work.
- Replacement proof submission bypasses active ordering but keeps its explicit
  authorization path.

- [x] **Step 1: Write failing active-work and batch tests**

Add tests for:

- A standalone `proof_submitted`, `proof_action_required`, or
  `rider_released` leg does not block a new standalone delivery.
- An active standalone leg still blocks a conflicting new delivery.
- A batch with `status = in_progress` and only non-active rider states does not
  block a standalone start or batch start.
- A batch with one submitted stop and one active stop selects the active stop
  as the next/current rider work.
- A batch with all stops submitted/correction-required remains business
  `in_progress` and does not complete or block the rider.
- A rejected earlier batch stop can be corrected while a later active stop is
  current.
- Concurrent progression observes the committed rider state and does not
  allow two active candidates.

- [x] **Step 2: Run focused progression tests and verify they fail**

```powershell
php artisan test tests/Feature/Logistics/RiderProgressionTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php tests/Feature/Logistics/RiderMyDeliveriesPageTest.php
```

Expected: FAIL because the current guard blocks on batch status and treats
`awaiting_proof_approval` as active.

- [x] **Step 3: Replace status-only active-work candidates**

Update `RiderActiveWorkGuard` so:

- `activeStandaloneQuery()` filters by `rider_progress_state = active`, not a
  hard-coded status list.
- Other in-progress batches are selected only when they have an eligible leg
  with rider state `active`.
- Custody holds remain explicit and continue to block when their existing
  accepted-assignment rules require it.
- `assertCanAdvanceLeg()` compares against the first eligible state-driven
  candidate, while replacement proof submission uses its separate path.

Update batch work-item construction to derive `current` from active legs rather
than `DeliveryBatch.status`. Update transition paths so physical active states
are `active`, delivery proof submission is `proof_submitted`, correction is
`proof_action_required`, and delivered/cancelled/failed terminal outcomes are
`rider_released`.

- [x] **Step 4: Run progression, batch, and page-access tests**

```powershell
php artisan test tests/Feature/Logistics/RiderProgressionTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php tests/Feature/Logistics/RiderMyDeliveriesPageTest.php
```

Expected: PASS, including existing active-work/custody regression coverage.

- [x] **Step 5: Commit state-driven progression**

```powershell
git add app/Services/Logistics/RiderActiveWorkGuard.php app/Services/Logistics/BatchDispatchService.php app/Services/Logistics/ShipmentLegService.php app/Http/Controllers/Logistics/ErpLogisticsController.php tests/Feature/Logistics/RiderProgressionTest.php tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php tests/Feature/Logistics/RiderMyDeliveriesPageTest.php
git diff --cached --check
git commit -m "feat: derive rider blocking from progress state"
```

### Task 7: Audit and correct backend `ShipmentLegStatus` consumers

**Files:**
- Create or modify: `tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Services/Logistics/AssignmentService.php`
- Modify: `app/Services/Logistics/ArrivalService.php`
- Modify: `app/Services/Logistics/DeliveryScheduleService.php`
- Modify: `app/Services/Logistics/BatchSuggestionService.php`
- Modify: `app/Services/Logistics/BatchDispatchService.php`
- Modify: `app/Console/Commands/MonitorOverdueDeliveries.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify as identified by the repository-wide search: `app/Services/Logistics/SourceShipmentService.php`

**Interfaces:**
- `proof_correction_required` is not accepted by normal pickup, arrival,
  in-transit, delivery, cancellation, retry, return, assignment, scheduling,
  pooling, or ordinary overdue-transit branches.
- It remains incomplete for shipment/batch reconciliation and is visible to
  dispatchers as a proof-review/correction state.
- Capacity counters may account for already-consumed work, but no capacity
  query may be reused as active rider blocking.

- [x] **Step 1: Add negative consumer tests before changing branches**

Test that a correction-required leg:

- Cannot be picked up, started out for delivery, marked in transit, marked
  delivered by a rider, cancelled, retried, or returned through normal status
  transitions.
- Cannot be newly assigned, offered, pooled, scheduled, or re-batched as a
  fresh ordinary leg.
- Is not treated as an overdue transit stop or automatically rescheduled by the
  normal overdue monitor.
- Leaves shipment/batch business completion active/incomplete.
- Can only enter a physical revisit through an explicit resolution operation.

Use existing tests where fixtures already exist:
`ShipmentLegServiceTest`, `AssignmentServiceTest`, `DeliveryArrivalTest`,
`DeliveryScheduleServiceTest`, `OverdueDeliveryMonitorTest`, and
`DeliveryExecutionTest`.

- [x] **Step 2: Run the consumer tests and verify old status branches fail**

```powershell
php artisan test tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/AssignmentServiceTest.php tests/Feature/Logistics/DeliveryArrivalTest.php tests/Feature/Logistics/DeliveryScheduleServiceTest.php tests/Feature/Logistics/OverdueDeliveryMonitorTest.php
```

Expected: FAIL where the new status is currently accepted by broad
`status != cancelled`/terminal-only branches or omitted from allow-list
regressions.

- [x] **Step 3: Apply explicit allow-list and projection rules**

Use positive allow-lists for transitions and fresh work. Keep correction
outside normal transit and failed-attempt paths. Add a separate proof-review
age/flag path if overdue visibility is needed; do not reuse overdue delivery
estimate mutation. Preserve `ShipmentLegService::syncShipmentStatus()` and
`reconcileBatchState()` completion predicates as delivered/cancelled only.

Do not add a generic `default` branch that treats the new status as active
transit. For every changed branch, document whether the status is active work,
review-only, terminal, customer-safe confirmation-in-progress, or unavailable.

- [x] **Step 4: Run the consumer and existing logistics suites**

```powershell
php artisan test tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/AssignmentServiceTest.php tests/Feature/Logistics/DeliveryArrivalTest.php tests/Feature/Logistics/DeliveryScheduleServiceTest.php tests/Feature/Logistics/OverdueDeliveryMonitorTest.php tests/Feature/Logistics/DeliveryExecutionTest.php
```

Expected: PASS with correction-required excluded from accidental transit,
completion, scheduling, and monitoring behavior.

- [x] **Step 5: Commit backend consumer audit**

```powershell
git add app/Services/Logistics/ShipmentLegService.php app/Services/Logistics/AssignmentService.php app/Services/Logistics/ArrivalService.php app/Services/Logistics/DeliveryScheduleService.php app/Services/Logistics/BatchSuggestionService.php app/Services/Logistics/BatchDispatchService.php app/Console/Commands/MonitorOverdueDeliveries.php app/Http/Controllers/Logistics/ErpLogisticsController.php app/Services/Logistics/SourceShipmentService.php tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php tests/Feature/Logistics/ShipmentLegServiceTest.php tests/Feature/Logistics/AssignmentServiceTest.php tests/Feature/Logistics/DeliveryArrivalTest.php tests/Feature/Logistics/DeliveryScheduleServiceTest.php tests/Feature/Logistics/OverdueDeliveryMonitorTest.php
git diff --cached --check
git commit -m "fix: classify proof correction legs safely"
```

### Task 8: Update customer, order, repair, and staff projections

**Files:**
- Create or modify: `tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php`
- Modify: `app/Services/Logistics/CustomerTrackingService.php`
- Modify: `app/Http/Controllers/Logistics/CustomerTrackingController.php`
- Modify: `app/Services/OrderReceiptService.php`
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `app/Services/RepairDeliveryService.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Modify: `tests/Feature/Logistics/CustomerTrackingTest.php`
- Modify relevant repair reconciliation/return tests.

**Interfaces:**
- Customer payloads expose only safe confirmation-in-progress status and
  approved proof evidence; they never expose rider progress or rejection
  details.
- Shop-owned receipt confirmation remains eligible for awaiting and
  correction-required legs, without completing the business order.
- Repair/staff workflows remain approval-gated and label correction-required as
  review/correction, not delivered or a new redelivery.

- [x] **Step 1: Add failing projection/privacy tests**

Cover:

- Customer tracking maps both `awaiting_proof_approval` and
  `proof_correction_required` to the existing safe confirmation-in-progress
  presentation.
- Customer tracking and proof-file endpoints expose no rejected reason,
  replacement lineage, unapproved file, or `rider_progress_state`.
- `OrderReceiptService::canConfirm()` and `confirm()` accept the correction
  state for early receipt but leave order/shipment business completion pending.
- `UserSide/OrderController` does not mark correction-required as a failed
  attempt from a historical attempt row.
- Repair handoff/release remains blocked until approved proof and reports a
  correction/review reason instead of “completed” or implicit redelivery.
- Staff order views may show internal correction state but never expose an
  unapproved proof file.

- [x] **Step 2: Run focused projection tests and verify failures**

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php tests/Feature/Logistics/LogisticsAuditPrivacyTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php
```

Expected: FAIL because customer/order/repair code currently recognizes only
`awaiting_proof_approval` and may serialize the new status raw.

- [x] **Step 3: Implement safe status projection**

Add one local status mapping at each existing projection boundary rather than
leaking the internal enum into customer payloads. Treat correction-required as
confirmation-in-progress for receipt eligibility, but preserve approved-proof
and delivered-leg requirements for business completion. Update repair/staff
copy and status labels without creating a new physical delivery path.

- [x] **Step 4: Run projection, privacy, and repair tests**

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php tests/Feature/Logistics/LogisticsAuditPrivacyTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php tests/Feature/Logistics/ReturnToShopTest.php
```

Expected: PASS, including approved-only customer proof access and existing
repair return behavior.

- [x] **Step 5: Commit customer/order/repair projections**

```powershell
git add app/Services/Logistics/CustomerTrackingService.php app/Http/Controllers/Logistics/CustomerTrackingController.php app/Services/OrderReceiptService.php app/Http/Controllers/UserSide/OrderController.php app/Services/RepairDeliveryService.php app/Http/Controllers/Api/StaffOrderController.php resources/js/Pages/ERP/STAFF/JobOrders.tsx tests/Feature/Logistics/CustomerTrackingTest.php tests/Feature/Logistics/LogisticsAuditPrivacyTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Logistics/StaffRetailShippingCoverageTest.php tests/Feature/Logistics/ReturnToShopTest.php
git diff --cached --check
git commit -m "fix: keep proof correction status customer-safe"
```

### Task 9: Update rider, dispatcher, and customer frontend contracts

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/components/logistics/ShipmentTrackingPanel.tsx`
- Modify as required: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Modify: `resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx` only if needed for shared fixtures.

**Interfaces:**
- Add a typed `RiderProgressState` and expose `rider_progress_state` only in
  rider/dispatcher work-item payloads.
- Represent replacement proof action data, including rejected proof ID and
  replacement permission, without exposing it to customers.
- Rider current/up-next/action selection uses rider state, not business status.
- Dispatcher sees pending and correction-required review context; approve/reject
  controls target only the current pending proof.
- Customer panel maps correction-required to the existing safe confirmation
  label and never renders proof-review internals.

- [x] **Step 1: Write failing frontend tests**

Add/update tests for:

- `proof_submitted` no longer renders as a blocking “Waiting for proof
  approval” current card and the next active delivery is selected.
- `proof_action_required` appears in the issues area with rejection reason and
  replacement upload action; submitting sends a new idempotency key and
  `replaces_proof_id`.
- A batch whose stops are all non-active does not create an active conflict or
  block offers/new work, even when its business status is `in_progress`.
- A batch with a later active stop selects that stop as current.
- Dispatcher Shipments renders correction-required review context and does not
  show approval controls for a rejected/non-pending proof.
- Customer tracking uses the safe confirmation label for correction-required
  and still hides rejection details/unapproved proofs.

- [x] **Step 2: Run focused Vitest tests and verify the old UI fails**

```powershell
pnpm exec vitest run --pool=threads --maxWorkers=1 --minWorkers=1 resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx
```

Expected: FAIL because the current rider helpers treat awaiting proof as
actionable/current and do not expose a replacement action or rider state.

- [x] **Step 3: Implement typed state-driven presentation**

Update `logistics.ts` and backend payload assumptions first. Then:

- Make `nextActionableDelivery()` and completed/progress presentation use
  `rider_progress_state` where rider behavior is concerned; keep delivered
  business status for business completion counts.
- Remove proof-submitted/action-required legs from blocking current work.
- Add proof-correction issue items and a replacement upload action that uses
  the existing proof endpoint with `replaces_proof_id`.
- Keep offline/pending mutation locks and accessible live/status text.
- Add dispatcher correction filters/details without changing customer payloads.
- Map correction-required to the existing customer-safe confirmation copy.

Use existing components and Tailwind patterns. Do not add a new review page or
new frontend state library.

- [x] **Step 4: Run focused and full frontend tests**

```powershell
pnpm exec vitest run --pool=threads --maxWorkers=1 --minWorkers=1 resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx
pnpm run test:frontend
```

Expected: PASS with no new frontend failures.

- [x] **Step 5: Commit frontend progression and review UI**

```powershell
git add resources/js/types/logistics.ts resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/components/logistics/ShipmentTrackingPanel.tsx resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx resources/js/Pages/ERP/Logistics/__tests__/myDeliveries.test.ts resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx
git diff --cached --check
git commit -m "feat: show non-blocking proof review actions to riders"
```

### Task 10: Run the repository-wide status audit and concurrency/security review

**Files:**
- Review all changed backend/frontend files from Tasks 1-9.
- Modify only if the audit finds a missed status branch or stale test fixture.
- Review: `docs/superpowers/specs/2026-08-25-rider-delivery-proof-review-decoupling-design.md`

**Interfaces:**
- Every concrete `ShipmentLegStatus` consumer classifies
  `proof_correction_required` explicitly.
- Private proof authorization, tenant isolation, idempotency, file cleanup,
  and replacement lineage remain intact.

- [x] **Step 1: Run the repository-wide status search**

```powershell
rg -n --glob '*.php' --glob '*.tsx' --glob '*.ts' "ShipmentLegStatus|proof_correction_required|awaiting_proof_approval|in_transit|delivery_attempted|needs_resolution|status->value" app resources tests database
rg -n --glob '*.php' "whereIn\('status'|where\('status'" app resources tests database
```

For every match involving a shipment leg, classify it as active work, proof
review, correction-only, terminal, customer-safe projection, scheduling, or
unrelated status. Add a focused test for any missed correction branch instead
of broadening a default branch.

- [x] **Step 2: Run security and concurrency tests**

```powershell
php artisan test tests/Feature/Logistics/RiderTenantAuthorizationTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php tests/Feature/Logistics/LogisticsAuditPrivacyTest.php tests/Feature/Logistics/MoveHandoffProofsToPrivateTest.php
```

Expected: PASS. Verify unauthorized riders cannot replay another rider's
idempotency key, attach a replacement from another leg/tenant, read private
proof files, or correct a leg after assignment/tenant authorization is gone.

- [x] **Step 3: Run a simplification and standards pass**

Use `@ponytail` and the repository Laravel/TypeScript review checklists to
remove duplicate status arrays, dead branches, unused imports, speculative
abstractions, or a second notification path. Keep the state transition logic
in the smallest existing service boundaries that satisfy the spec.

- [x] **Step 4: Run the focused backend suite after audit fixes**

```powershell
php artisan test tests/Feature/Logistics/ProofReviewFlowTest.php tests/Feature/Logistics/RiderProgressionTest.php tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/CustomerTrackingTest.php
```

Expected: PASS.

### Task 11: Execute final verification and prepare the implementation handoff

**Files:**
- Review only: all changed files, the frozen spec, and this plan.

- [ ] **Step 1: Run the full Laravel test suite**

```powershell
composer test
```

Expected: the complete PHPUnit suite passes. If a failure is unrelated to the
change, record the exact test and evidence rather than marking it passed.

- [x] **Step 2: Run the full frontend suite and production build**

```powershell
pnpm run test:frontend
pnpm run build
```

Expected: Vitest and Vite complete successfully. No TypeScript compiler or
linting result may be reported because the repository does not define those
scripts/configuration.

- [x] **Step 3: Run diff hygiene and inspect the final diff**

```powershell
git diff --check
git status --short
git diff --stat
git diff --name-only
```

Confirm there are no `.env`, `vendor/`, `node_modules/`, unrelated worktree,
or generated-file changes outside the repository’s normal build policy.

- [x] **Step 4: Perform the final review stack**

Record results for:

1. Simplify/ponytail review.
2. Sequential repository standards review.
3. Sequential frozen-spec/acceptance review.
4. Laravel correctness, authorization, transaction, and private-proof security
   review.
5. TypeScript/React naming, narrowing, rendering, accessibility, and
   performance review using the Vercel React checklist.
6. Code-splitting review: no new heavy dependency or unconditional bundle
   split without evidence.
7. Gauge claims: report behavior/test evidence; do not claim latency/query or
   bundle improvement without a baseline.
8. Dead-code and reuse audit.
9. Verification-before-completion evidence.

- [x] **Step 5: Commit the final implementation changes**

Stage only files belonging to the implementation and tests. Do not push or
create a pull request in this plan.

```powershell
git add <exact-reviewed-files>
git diff --cached --check
git diff --cached --stat
git commit -m "feat: decouple rider progress from proof review"
```

Expected: one clean feature-branch commit or a small sequence of task commits,
with the final worktree status showing only the intended branch relationship.

## Execution handoff

The frozen spec is the source of truth. Execute these tasks sequentially and
stop after each focused test checkpoint. If a test exposes a conflict with the
spec, pause and resolve the contract before changing implementation behavior.

The repository-default execution mode is inline sequential work using
`superpowers:executing-plans`. Subagent-driven execution requires explicit user
approval because the repository operating model disallows subagents by default.
