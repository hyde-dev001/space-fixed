# Decouple Rider Delivery Progress from Dispatcher Proof Review

## Date

2026-08-25

## Status

Approved design; frozen after the requested revisions.

## Related work

- Customer delivery receipt dispute worktree: `feat/customer-delivery-receipt-dispute`
- Rider delivery-flow review brief: remove dispatcher approval from the rider's
  synchronous delivery path while preserving asynchronous proof review.
- Current implementation areas: `app/Services/Logistics/ProofService.php`,
  `app/Services/Logistics/ShipmentLegService.php`,
  `app/Services/Logistics/RiderActiveWorkGuard.php`,
  `app/Http/Controllers/Api/Logistics/ShipmentController.php`,
  `app/Http/Controllers/Logistics/ErpLogisticsController.php`, and
  `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`.

## Goal

Allow a rider to continue to the next eligible delivery immediately after
submitting delivery proof. Dispatcher review remains asynchronous and remains
the business and financial completion gate. A rejected proof must create a
clear correction workflow without pretending that the delivery returned to
`in_transit`, and proof corrections must not overwrite the original evidence.

The design must also preserve the existing active-work and custody protections:
a rider cannot start conflicting active work, but a delivery whose proof is
awaiting review or correction must not consume the rider's active-delivery
slot.

## Non-goals

- Automatically approve proof or mark a business delivery complete when the
  rider submits proof.
- Change customer acknowledgement into dispatcher approval, or expose
  internal rejection details to customers.
- Redesign pickup proof, return-to-shop `receive` proof, failed-delivery
  resolution, or custody-hold resolution beyond the state contracts needed to
  keep them safe.
- Replace the existing dispatcher shipment surface with a separate application
  unless the implementation review finds that the existing review filter cannot
  support the required queue.
- Rewrite historical proof files or mutate a rejected proof into an approved
  replacement.

## Current context and root cause

The current delivery path couples two different concerns:

1. `ProofService::recordProof()` records a delivery proof and moves the leg to
   `awaiting_proof_approval`.
2. `RiderActiveWorkGuard` treats `awaiting_proof_approval` as an active leg.
3. `ShipmentController::approveProof()` is the only path that calls
   `ShipmentLegService::markDelivered()`.
4. `ShipmentController::rejectProof()` currently changes a rejected leg back to
   `in_transit`.
5. `RiderActiveWorkGuard` and the rider delivery page therefore keep the rider
   blocked until a dispatcher acts, even though the rider has completed the
   physical handoff.

This also makes a rejected proof look like an ordinary delivery that still
needs to be attempted. It loses the distinction between a physical delivery
that has occurred, a proof submission that is under review, and a business
delivery that is officially completed.

The existing business completion behavior is otherwise correct for this
feature: shipment, order, refund, and batch completion are tied to an approved
proof and a delivered leg. That completion gate remains.

## Chosen architecture

Introduce an explicit rider-progress state alongside the existing business leg
status. The two state machines answer different questions:

- `ShipmentLeg.status`: what the business and dispatcher may claim about the
  leg, including whether the order may be completed.
- `ShipmentLeg.rider_progress_state`: whether this leg still requires the
  rider to perform active delivery work or whether the rider may progress
  elsewhere.

The rider-side terminal state is named `rider_released`. It intentionally does
not use `completed`, which remains a business completion concept.

### Rider progress states

| `rider_progress_state` | Meaning | Consumes active-delivery slot? | Rider presentation |
| --- | --- | --- | --- |
| `active` | The rider still owns an active delivery/custody/progression obligation, or the leg has not reached a safe release point. | Yes when the existing accepted-assignment/batch rules make the work active. | Current work or upcoming eligible work. |
| `proof_submitted` | Delivery proof was recorded and is pending dispatcher review. The physical delivery is not business-complete yet. | No. | Non-blocking submitted/review-pending notice. |
| `proof_action_required` | The latest delivery proof was rejected and the rider may submit a correction. | No. | Issue requiring replacement proof; never a normal in-transit delivery. |
| `rider_released` | The rider has no further action for this leg. This can mean approved delivery, cancellation, failure, or another explicitly terminal business outcome. | No. | History or review-pending history, not current work. |

`proof_action_required` explicitly does not consume the active-delivery slot.
Neither `proof_submitted` nor `rider_released` consumes that slot. A leg with
one of those states may remain visible for audit or correction while the rider
starts another delivery.

### Business leg statuses

Keep the existing statuses and add:

- `proof_correction_required`: the latest delivery proof was rejected and the
  leg requires a replacement proof. This is an internal business state; it is
  not a regression to `in_transit`.

The existing `awaiting_proof_approval` status remains the business state for a
pending delivery proof. Dispatcher approval still transitions the leg from
`awaiting_proof_approval` to `delivered`.

The state relationship for the new delivery-proof path is:

| Event | Business leg status | Rider progress state | Business completion |
| --- | --- | --- | --- |
| Rider begins/continues delivery | Existing active status, normally `picked_up`, `in_transit`, or `delivery_attempted` | `active` | Not complete |
| Initial delivery proof accepted | `awaiting_proof_approval` | `proof_submitted` | Not complete |
| Dispatcher approves current proof | `delivered` | `rider_released` | Complete and eligible for existing shipment/order/batch reconciliation |
| Dispatcher rejects current proof | `proof_correction_required` | `proof_action_required` | Not complete |
| Rider submits replacement proof | `awaiting_proof_approval` | `proof_submitted` | Not complete |
| Dispatcher approves replacement proof | `delivered` | `rider_released` | Complete and eligible for reconciliation |
| Dispatcher rejects replacement proof | `proof_correction_required` | `proof_action_required` | Not complete |
| Existing terminal cancellation/failure | Existing terminal status | `rider_released` | Existing cancellation/failure rules apply |

There is no rider transition from a rejected proof back to `in_transit`.
Submitting a correction changes `proof_correction_required` to
`awaiting_proof_approval`; it does not create a new delivery attempt or reset
the physical-delivery state.

### Frozen invariants

- `DeliveryBatch.status` is a business/dispatcher lifecycle field, not a rider
  blocking predicate. No active-work check may decide that a rider is blocked
  merely because a batch is `in_progress`. Every rider start/advance candidate
  must be derived from the batch's legs with
  `rider_progress_state = active`, plus the existing assignment, ordering, and
  custody rules.
- A batch may remain `in_progress` while all of its submitted or
  correction-required proofs await review. If it has no active rider stops, it
  does not consume the rider's active-delivery slot. The batch becomes
  business `completed` only through the existing delivered/cancelled
  reconciliation.
- Rejecting a proof never implies physical redelivery, a revisit, or a new
  delivery attempt. `rejectProof()` does not create an attempt, change the
  attempt number, set a retry schedule, set a return resolution, or create a
  new arrival obligation. The ordinary exit is an immutable replacement proof.
- If a dispatcher determines that a physical revisit is necessary, that action
  must enter the existing explicit logistics resolution workflow (failed
  attempt/incident resolution, retry, or return) through its own authorized
  operation. That workflow owns any later transition to a retryable status or
  `in_transit`; proof rejection itself never does. `proof_correction_required`
  is not silently accepted by normal arrival, retry, return, or transit
  transitions.

## Data model changes

### Shipment leg

Add a persisted `rider_progress_state` string column to `shipment_legs`, cast
to a new `RiderProgressState` PHP backed enum. The column is non-nullable with
the safe default `active`, so existing records remain blocking until the
deterministic backfill has evaluated their proof history.

Add `proof_correction_required` to `ShipmentLegStatus` and retain the existing
enum/string storage convention. The `ShipmentLeg` model exposes both casts and
the new state in its serialized rider and dispatcher payloads.

Index the rider state with the existing leg status/batch lookup dimensions used
by the active-work and delivery-page queries. The exact index should follow the
query plan after implementation; it must support filtering non-released legs
without replacing the existing assignment and tenant scopes.

### Handoff proof

Add a nullable `replaces_proof_id` self-reference to `handoff_proofs`:

- A replacement is a new row with its own idempotency key, file path,
  `recorded_at`, submitter, and immutable submission metadata.
- `replaces_proof_id` points to the rejected proof it corrects.
- The foreign key must preserve the audit chain; deleting a proof that has
  replacements should be restricted rather than silently detaching history.
- `HandoffProof` exposes the singular replaced proof and replacement collection
  relationships.

Submission facts are immutable after insertion, including the original file,
idempotency key, handoff type, proof type, submitter, timestamps, notes, and
submission metadata. Dispatcher review may update only the existing review
fields (`review_status`, reviewer, review time, and rejection reason) according
to the review transition rules. A rejected row is never edited into a
replacement.

### Proof-chain invariants

- A replacement must reference a rejected delivery proof on the same leg and
  tenant. It cannot reference a pending or approved proof, a proof on another
  leg, or a `receive` proof.
- At most one pending delivery proof may exist for a leg at a time. The leg
  lock makes this invariant deterministic when two correction uploads arrive
  concurrently.
- Reusing an idempotency key returns the same authorized proof record only when
  the request is a replay of that submission. It never changes the file or
  links the existing row to a different rejected proof. A key reused with a
  conflicting payload is rejected.
- A delivery leg cannot become `delivered` unless its current delivery proof is
  approved.
- A pending or rejected proof never makes the shipment, order, refund, or
  business batch complete.

## Deterministic legacy backfill

The schema default is only a safety fallback. A rerunnable backfill must set
both the rider state and, where necessary, the new business status using one
deterministic precedence order. For each leg, select the latest relevant
delivery proof by `recorded_at DESC, id DESC`. A relevant delivery proof has
`handoff_type = delivery`; return-to-shop `receive` proofs are excluded from
this backfill.

Apply these rules in order:

1. If the business leg status is `delivered`, `cancelled`, or `failed`, set
   `rider_progress_state = rider_released` and preserve the existing business
   status, even if an old proof row is inconsistent.
2. Otherwise, if the latest relevant delivery proof has
   `review_status = rejected`, set the business leg status to
   `proof_correction_required` and set
   `rider_progress_state = proof_action_required`. This rule intentionally
   repairs historical rows produced by the old rejection behavior that put the
   leg back in `in_transit`.
3. Otherwise, if the business leg status is
   `awaiting_proof_approval` and the latest relevant proof is pending, set
   `rider_progress_state = proof_submitted` and preserve the business status.
4. Otherwise, if the business leg status is
   `awaiting_proof_approval` and the latest relevant proof is approved, set
   `rider_progress_state = rider_released`, preserve the business status, and
   emit/queue a reconciliation marker for the stale `awaiting` leg. Do not
   reactivate the rider while that inconsistency is repaired.
5. Otherwise, if the business leg status is
   `awaiting_proof_approval` but there is no relevant delivery proof, set
   `rider_progress_state = active`. Do not release a rider without proof
   evidence.
6. For every other non-terminal status—including `pending`, `assigned`,
   `pickup_scheduled`, `picked_up`, `in_transit`, `delivery_attempted`, and
   `needs_resolution`—set `rider_progress_state = active`.

The rejected-proof rule has higher precedence than the generic `in_transit`
mapping. Therefore a legacy `in_transit` leg whose latest delivery proof was
rejected becomes `proof_correction_required`/`proof_action_required`, never
`in_transit`/`active`.

The backfill must be idempotent, process records in stable primary-key order,
lock or safely retry individual leg updates, and record counts for each mapping
plus any stale approved-proof reconciliation markers. It must not delete
proofs, rewrite files, approve proofs, mark orders complete, or alter
customer-visible proof content.

## Backend behavior

### Proof submission

`ProofService::recordProof()` remains the single authorization and persistence
boundary for rider proof submissions, with these two explicit modes:

This design's decoupled correction state and replacement chain apply only to
delivery PODs with `handoff_type = delivery`. Return-to-shop `receive` proofs
remain in their existing return handoff/receipt state machine and must not be
silently converted into a delivery correction or a new outbound delivery. If
the approval/rejection controller remains shared, it must branch explicitly by
handoff type so a receive proof cannot take the delivery-POD transition below.

#### Initial submission

- Require the existing tenant, rider, active-assignment, handoff, arrival, and
  idempotency checks.
- Require `rider_progress_state = active` and an allowed physical delivery
  status.
- Insert the proof as `pending`.
- Set the leg to `awaiting_proof_approval` and
  `rider_progress_state = proof_submitted` in the same transaction.
- Record the existing dispatcher `proof_required` notification/event with the
  proof identifier and leg identifier.
- Release the rider for active-work ordering as soon as the transaction commits;
  dispatcher review is not part of the rider request.

#### Replacement submission

- Use the existing proof endpoint with an explicit `replaces_proof_id`. The
  server, not the client, determines the mode from the validated proof chain;
  no separate replacement route is required.
- Require the referenced proof to be the latest rejected delivery proof for
  the leg, and require `rider_progress_state = proof_action_required`.
- Require the same tenant/rider authorization and idempotency checks. Do not
  require a new drop-off arrival because this is correction of an already
  recorded delivery, not a second physical handoff.
- Insert a new pending proof row linked by `replaces_proof_id`.
- Set the leg to `awaiting_proof_approval` and
  `rider_progress_state = proof_submitted` in the same transaction.
- Record an audit event containing both the rejected proof ID and the new
  proof ID, and notify the dispatcher review queue.
- Do not call the active-work guard for the replacement path. A rider may
  correct an earlier delivery after progressing to another stop, provided the
  assignment and tenant authorization remain valid.

The initial submission path may use the current rider progression guard. The
replacement path must have a distinct service authorization check so a
non-current correction is not mistaken for an attempt to bypass active-work
ordering.

### Dispatcher approval

Approval operates on a locked pending delivery proof and its locked leg. It
does not require the rider to still be current, assigned as the latest active
item, or available in the rider UI.

- If the proof is pending and is the current delivery proof, mark it approved,
  then transition the leg to `delivered` and set
  `rider_progress_state = rider_released` in one transactional service flow.
- Reconcile business batch, shipment, order, and return/refund state through
  the existing delivery completion path only after the leg is delivered.
- Record an approval event with the proof ID and leg ID.
- A duplicate approval request is safe and returns the existing approved
  result. It must not create another proof or repeat financial side effects.
- Approval of an older rejected proof is not allowed; the dispatcher must act
  on the pending replacement proof.

The controller should call a service operation that represents dispatcher
approval rather than reusing a rider-only `markDelivered()` path that enforces
the rider's current-work ordering.

### Dispatcher rejection

Rejection operates on a locked pending delivery proof and its locked leg:

- Mark only that proof as rejected and persist the reviewer, timestamp, and
  reason.
- Set the leg to `proof_correction_required` and
  `rider_progress_state = proof_action_required` in the same transaction.
- Record a `proof_rejected` event containing the rejected proof ID, leg ID, and
  reason in the internal audit stream.
- Notify the rider with the correction action and reason, subject to the
  existing tenant and notification authorization boundaries.
- Never update the leg to `in_transit`.

A duplicate rejection of the same already-rejected proof is safe and does not
change the proof chain. A proof that is already approved, or a proof replaced
by a pending correction, cannot be rejected through the original review action.

Rejection is not a redelivery command. It must not create a
`DeliveryAttempt`, increment or reset `attempt_number`, alter
`scheduled_delivery_date`, set `resolution_type`, cancel the assignment, or
record a new arrival requirement. If the dispatcher believes the parcel was
not physically delivered and a revisit is necessary, the dispatcher must use
the existing explicit failed-attempt/incident resolution workflow. Any new
retry or return state and its audit trail come from that workflow, not from
`rejectProof()`.

### Active-work guard and batch progression

Replace status-only proof-review blocking in `RiderActiveWorkGuard` with
`rider_progress_state` as the progression input while retaining assignment,
tenant, custody, and batch scopes.

This is an explicit invariant: `DeliveryBatch.status = in_progress` may gate a
batch lifecycle operation such as starting the batch or recording an arrival,
but it may never, by itself, make a rider's active-work candidate or block a
new standalone delivery. The guard must query whether the rider has an
eligible leg in that batch whose `rider_progress_state = active`. A batch with
only `proof_submitted`, `proof_action_required`, or `rider_released` legs is
not an active-work conflict regardless of its business batch status.

For an accepted standalone assignment, an active candidate is a leg whose
rider state is `active`. `proof_submitted`, `proof_action_required`, and
`rider_released` are excluded from active-slot candidates.

For an in-progress batch:

- The ordered next stop is the earliest stop whose
  `rider_progress_state = active` and whose existing batch/assignment rules
  make it eligible.
- Stops in `proof_submitted`, `proof_action_required`, or `rider_released` are
  released for rider progression, even though their business leg may still be
  awaiting dispatcher review.
- A batch with a later active stop remains the rider's current work and may
  continue to the next stop after an earlier proof submission.
- A batch with no active stops no longer consumes the rider's active-delivery
  slot. `DeliveryBatch.status` may remain `in_progress` while proofs are
  pending or require correction.
- The database batch remains business-complete only when the existing
  reconciliation rule sees every leg as `delivered` or `cancelled`. Rider
  progression must not set the batch's business status to `completed` merely
  because all stops have been submitted.

`assertCanAdvanceLeg()` should therefore compare the target against the first
eligible active work item derived from rider progress state. The proof
replacement operation is deliberately outside that ordering check, as defined
above.

### Concurrency and idempotency

The leg remains the transaction boundary for state transitions:

- Lock the leg before reading its latest delivery proof and rider progress
  state.
- Lock the proof row during dispatcher approval/rejection.
- Create a replacement proof under the same leg lock and reject a second
  pending replacement deterministically.
- Keep idempotency replay after tenant/rider authorization, so an unauthorized
  caller cannot use a known key to discover another rider's proof.
- Make approval, rejection, and replacement transitions conditional on the
  locked current state. A request racing with another review receives the
  stable final state rather than applying a second transition.
- Ensure a rider can submit proof for the next active batch stop only after the
  prior submission transaction commits, so ordering observes the new
  `rider_progress_state`.

## `ShipmentLegStatus` consumer audit

The status audit covers the PHP enum/model, logistics services and controllers,
the overdue monitor, repair and order projections, customer tracking, ERP
screens, rider presentation helpers, and their existing feature/frontend test
fixtures. `proof_correction_required` must be handled as an internal proof
correction state, not as an alias for any existing transit or terminal state.

| Consumer | Required treatment of `proof_correction_required` |
| --- | --- |
| `ShipmentLegStatus` and `ShipmentLeg` | Add the enum case and rider-state cast/serialization. Keep the status distinct from `in_transit`, `delivered`, `failed`, and `cancelled`. |
| `RiderActiveWorkGuard` and `ErpLogisticsController` rider work-item builders | Derive blocking, batch current/up-next selection, and active conflicts from `rider_progress_state = active` and existing assignment/custody rules. Do not use `DeliveryBatch.status = in_progress` alone. Correction-required legs are issues/history context, not current work. |
| `ShipmentLegService` transition allow-lists | Do not allow correction-required legs through normal pickup, out-for-delivery, arrival, in-transit, rider-delivered, cancellation, retry, or return transitions. Dispatcher approval acts on a pending proof and moves the leg to delivered; a replacement proof moves it to awaiting review. A physical revisit can enter only through the explicit resolution workflow. |
| `ProofService` and `ShipmentController` | Initial delivery proof requires an active physical status. Replacement proof is the only normal delivery-POD correction path. Approval/rejection acts only on the current pending proof; delivery rejection never regresses the leg to `in_transit` or starts a redelivery. `receive` proofs remain in the dedicated return handoff flow. |
| `AssignmentService`, `BatchDispatchService`, `DeliveryScheduleService`, and `BatchSuggestionService` | Do not assign, offer, schedule, pool, or re-batch a correction-required leg as new ordinary work. Batch/schedule capacity counters may retain already-consumed business capacity, but that accounting must not be reused as rider blocking. Urgency/editing actions must not turn correction-required into schedulable work. |
| `ArrivalService`, failed-attempt recording, `DeliveryIncidentService`, and resolution actions | Do not accept correction-required as a normal arrival or failed-attempt status. A revisit/retry/return requires an explicit dispatcher resolution action that records its own incident/attempt and then uses the existing retry/return transitions. |
| `ShipmentLegService::syncShipmentStatus()` and `reconcileBatchState()` | Treat correction-required as incomplete and non-terminal. It must leave shipment/order/batch business completion untouched; only delivered/cancelled legs satisfy the existing batch/shipment completion predicates. |
| `MonitorOverdueDeliveries` | Do not classify correction-required as an overdue transit stop or automatically move its delivery estimate. Review age belongs to the proof-review/correction queue. Return-to-shop `receive` monitoring remains separate. |
| `CustomerTrackingService`, `CustomerTrackingController`, and `ShipmentTrackingPanel.tsx` | Map correction-required to the customer-safe confirmation-in-progress presentation, expose no rejection reason or unapproved file, and allow proof files only for delivered legs with approved proof. Do not expose `rider_progress_state` in the customer payload. |
| `OrderReceiptService` and `UserSide/OrderController` | Preserve early customer receipt eligibility for both awaiting and correction-required shop-owned legs, while keeping official shipment/order completion approval-gated. Correction-required must not be reported as a failed attempt merely because a historical attempt exists, and raw internal correction status must not leak into customer-facing labels. |
| `RepairDeliveryService`, `StaffOrderController`, and `ERP/STAFF/JobOrders.tsx` | Keep repair handoff/release and staff proof visibility approval-gated. A correction-required leg is neither delivered nor a new redelivery; show a correction/review state rather than the old “waiting for approval” or a completed state. |
| `LogisticsNotificationService` and `DeliveryEventService` | Keep proof correction/review events internal or authorized to the rider/dispatcher. Notification payloads must identify the proof chain without exposing internal details to customers, and must follow the after-commit contract below. |
| `riderDeliveryPresentation.ts`, `MyDeliveries.tsx`, `Shipments.tsx`, shared logistics types, and fixtures | Add the rider state and correction status to the contract. Current/upcoming/actionable logic uses rider state; correction-required renders as a replacement-proof issue, never as active transit, successful delivery, or a blocked waiting card. Dispatcher controls remain limited to the current pending proof. |

Specific current branches that must be changed or covered include the
status-based batch/current mapping in `ErpLogisticsController`, the
`awaiting_proof_approval` active-status list in `RiderActiveWorkGuard`, the
`rejectProof()` `in_transit` update, the `OrderController` failed-attempt
exclusion list, the customer tracking confirmation label, the repair
delivery-state approval gate, and the frontend awaiting/current assertions.
The implementation plan must include a repository-wide search for both
`ShipmentLegStatus` and the concrete leg-status strings after the enum case is
added, including test fixtures and staff/repair projections.

## Transaction and after-commit notification contract

Proof submission, approval, rejection, replacement creation, and any related
leg/batch/shipment updates are one transactional state change. The durable
`DeliveryEvent` audit row is written inside that transaction so it rolls back
with the state. Notification, broadcast, mail, or other external side effects
must not run before the outermost transaction commits.

`DeliveryEventService` must therefore persist the event first and defer
`LogisticsNotificationService::notifyForEvent()` with Laravel after-commit
semantics (or an after-commit queued job). The callback/job must reload the
committed event and related records, and notification grouping must remain
idempotent by event/proof chain. Calls made outside an open transaction may
dispatch after the event insert succeeds, but calls nested in a transaction
must wait for the outermost commit.

This applies to `proof_required`, replacement-proof notifications,
`proof_rejected`, `proof_approved`, and any customer-visible delivery event.
The HTTP response is returned only after the state transaction commits. If the
transaction rolls back, no notification may claim that the proof or leg
transition occurred. Tests must verify both commit and rollback behavior.

## API and payload contract

Add `rider_progress_state` to the leg/work-item payloads consumed by the rider
page and dispatcher surfaces. Keep `status` present for business and customer
logic; clients must not infer rider blocking from `status` alone.

The rider payload should expose a proof-review summary only to the authorized
rider and dispatcher, for example:

```json
{
  "status": "awaiting_proof_approval",
  "rider_progress_state": "proof_submitted",
  "proof_review": {
    "state": "pending",
    "proof_id": "...",
    "replaces_proof_id": null
  }
}
```

After rejection, the rider-facing shape becomes:

```json
{
  "status": "proof_correction_required",
  "rider_progress_state": "proof_action_required",
  "proof_review": {
    "state": "rejected",
    "proof_id": "...",
    "rejection_reason": "...",
    "replacement_allowed": true
  }
}
```

The replacement request carries a new idempotency key and the explicit
`replaces_proof_id`. The response identifies the new proof record; it does not
pretend that the rejected proof was updated.

Customer tracking payloads must continue to expose only approved proof
evidence. The internal `proof_correction_required` status is mapped to the
existing customer-safe confirmation-in-progress presentation. Customer
acknowledgement may remain eligible under the existing early-receipt rules,
but shipment/order/financial completion still requires dispatcher approval and
the delivered leg transition.

## Rider experience

Update the rider delivery presentation to use `rider_progress_state` for
current/upcoming/history grouping and action selection:

- `active` remains current or upcoming according to the existing ordering.
- `proof_submitted` is removed from the blocking current card. Show a compact,
  non-blocking confirmation such as “Delivery submitted successfully. Proof is
  awaiting dispatcher review.”
- `proof_action_required` appears in the issues/action area with the rejection
  reason and a `Replace delivery proof` upload action. It is not rendered as a
  normal current delivery and does not prevent the rider from starting the next
  eligible delivery.
- `rider_released` is history/review context and is not actionable.
- If a batch has another `active` stop, that stop remains current. If all batch
  stops are non-active, the batch is presented as rider-released with review
  pending rather than as a blocked “waiting for proof approval” state.
- Physical return or delivery-attempt resolution remains available only through
  the existing issue/resolution flow; a proof correction must not silently
  turn into a return.

The upload control must preserve offline/pending-mutation safeguards and must
not permit duplicate submissions while the same replacement request is in
flight. Accessible status text must distinguish “submitted for review” from
“business delivery completed.”

## Dispatcher experience

Reuse the existing ERP Shipments review surface and extend its filtering and
row/detail data rather than creating a parallel review application:

- Pending delivery proofs remain available through the existing
  `awaiting_proof_approval` review filter.
- Legs in `proof_correction_required` remain visible as flagged review history,
  including the rejected proof and any linked replacement.
- The queue/detail surface shows leg, rider, batch/order context, proof ID,
  replacement lineage, submission time, current review state, and reviewer
  details within existing tenant/permission scopes.
- Approve and reject controls are enabled only for the current pending proof.
- The UI must not imply that a rider is blocked while a proof is pending or
  rejected; it should communicate that dispatcher approval controls business
  completion.

The existing dispatcher notification destination may continue to be
`/erp/logistics/shipments?status=awaiting_proof_approval`; the implementation
may add a review-state filter if that is clearer than overloading the business
leg-status filter.

## Customer, order, and financial separation

`CustomerTrackingService` and the customer tracking UI must never expose the
internal rejection reason, proof chain, or unapproved files. Both
`awaiting_proof_approval` and `proof_correction_required` map to a safe
confirmation-in-progress status for customer display.

`OrderReceiptService` preserves the existing early receipt eligibility for a
shop-owned leg in confirmation-in-progress, including the correction-required
case, because customer acknowledgement is a separate concern. This does not
complete the shipment or order. Existing completion paths remain gated by an
approved proof and `ShipmentLeg.status = delivered`.

The new rider state must not be used as a substitute for business completion:
`proof_submitted`, `proof_action_required`, and `rider_released` are all
non-completion states unless the existing business status independently says
the leg is terminal.

## Audit, notifications, and observability

Preserve the existing internal events and add proof-chain metadata:

- `proof_required`: initial or replacement proof ID, leg ID, rider ID, and
  replacement source when applicable.
- `proof_rejected`: rejected proof ID, leg ID, rider ID, reason, and the current
  rider progress state.
- `proof_approved`: approved proof ID, leg ID, replacement source if any, and
  the resulting business/rider states.
- Replacement submission: original rejected proof ID and new proof ID.

Do not put internal rejection details in customer-visible events. Add metrics or
structured logs for the backfill mapping counts, pending review age,
correction-required count, replacement success/rejection count, stale approved
proof reconciliation count, and attempts to submit a second pending
replacement.

## Acceptance criteria

### Rider progression

- A rider submits valid delivery proof and receives a successful response while
  the leg remains business-incomplete.
- The submitted leg has `awaiting_proof_approval` and
  `proof_submitted`, and it no longer consumes the active-delivery slot.
- The rider can start the next eligible standalone delivery.
- After submitting one batch stop, the rider can progress to the next active
  batch stop.
- A batch with only submitted, correction-required, or released stops does not
  block new active work even while the business batch remains `in_progress`.
- Changing only `DeliveryBatch.status` to `in_progress` cannot create an
  active-work conflict; a conflict exists only when an eligible leg in that
  batch has `rider_progress_state = active`.
- A rider still cannot bypass active ordering, custody holds, tenant scopes,
  pickup/arrival requirements, or required proof evidence.

### Review and correction

- Dispatcher approval works after the rider has moved to another delivery and
  still marks the leg delivered through the existing business completion path.
- Dispatcher rejection sets `proof_correction_required` and
  `proof_action_required`; it never sets `in_transit`.
- Dispatcher rejection creates no delivery attempt, retry schedule, return
  resolution, or redelivery/arrival obligation. Any revisit uses the existing
  explicit logistics resolution workflow.
- A replacement submission creates a new immutable proof linked to the rejected
  proof, returns `proof_submitted`, and leaves the business leg awaiting review.
- The original rejected proof remains unchanged and auditable.
- Approval/rejection replays and concurrent submissions/reviews are safe and
  deterministic.
- A proof/leg transaction that rolls back leaves no dispatcher or rider
  notification claiming that the transition occurred; committed transitions
  dispatch notifications after commit.

### Compatibility and presentation

- Legacy backfill follows the six precedence rules above and is rerunnable.
- Customer tracking remains safe and exposes only approved proof evidence.
- Customer acknowledgement behavior remains separate from business completion.
- Rider UI no longer presents proof review as a blocking current-delivery
  waiting state and provides a correction upload action.
- Dispatcher UI can find pending and correction-required proofs without losing
  the existing shipment/order context.
- Every status-based consumer audited in the consumer matrix treats
  `proof_correction_required` as correction-only and does not classify it as
  active transit, completed delivery, or ordinary schedulable work.

## Verification requirements for implementation

The later implementation plan must include focused tests for:

- enum/column migration, proof self-link, and deterministic legacy mappings;
- initial standalone proof submission and active-slot release;
- batch progression based on rider state rather than dispatcher approval;
- approval after rider progression and business completion reconciliation;
- rejection without status regression and replacement proof immutability;
- replacement authorization, arrival exception, tenant isolation, and
  idempotency;
- duplicate and concurrent dispatcher decisions;
- after-commit event/notification behavior on commit and rollback;
- custody/active-assignment regressions and required-proof enforcement;
- the repository-wide `ShipmentLegStatus` consumer audit, including repair,
  staff-order, customer-order, monitoring, scheduling, and frontend branches;
- customer tracking/order-receipt safe mapping;
- rider issue/current/upcoming rendering and dispatcher review controls.

Relevant existing test areas include
`tests/Feature/Logistics/LogisticsApiTest.php`,
`tests/Feature/Logistics/ShipmentLegServiceTest.php`,
`tests/Feature/Logistics/DeliveryExecutionTest.php`,
`tests/Feature/Logistics/LogisticsNotificationTest.php`,
`tests/Feature/Logistics/LogisticsPageAccessTest.php`, and
`resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`.

No implementation, migration, route, or test changes are part of this design
spec. With this spec frozen, the implementation plan can be written as the
separate next-stage artifact.
