# Batch vehicle breakdown recovery audit — October 23, 2026

Status: source audit and proposal only. No batch-breakdown implementation or runtime verification was performed. Read this with the [proposed implementation plan](../superpowers/plans/2026-10-23-batch-vehicle-breakdown-recovery.md) and the [existing recovery verification record](2026-10-23-failed-delivery-verification-gaps.md).

## Baseline and method

Worktree: `.worktrees/failed-delivery-recovery`; branch: `codex/failed-delivery-recovery`; HEAD: `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5`. The recent recovery implementation is uncommitted and is part of the required working-tree baseline, not present in HEAD alone. There were 700 changed/untracked paths, including generated build output, and no staged paths before this audit. Root and OCR worktree status, HEAD, staged lists and changed-file hashes were also captured in ignored `output/failed-delivery-recovery/batch-vehicle-audit/before.json`.

CodeGraph was consulted first. Its indexed paths resolve to the original checkout, so the feature conclusions below were checked against the live logistics worktree. Inspection covered batch services/controllers, execution and proof paths, incident and custody services, assignment/appointment eligibility, COD, notification processing, route/policy/middleware boundaries, page projections, components, migrations and nearby tests. Line references describe this working-tree snapshot and can move during implementation.

## Current versus required behavior

| Area | Actual implementation | Required extension / impact |
| --- | --- | --- |
| Failed visit | `ShipmentLegService::recordFailedAttempt`, line 1005, writes one attempt; lines 1171–1189 detach only that failed delivery from its batch and reconcile the remaining batch. Vehicle reason codes do not select a batch operation. | An unvisited stop must not receive a failed-attempt row just because the vehicle broke down. Other remaining stops currently remain operationally available after this failure path. |
| Incident | `DeliveryIncidentService::report`, line 28, accepts a `ShipmentLeg`; line 62 sets only that leg to `needs_resolution`. `delivery_incidents.shipment_leg_id` is non-null in `2026_07_12_000007_create_delivery_incidents_table.php`. | No persisted batch subject, affected-stop set, shared hold or batch resolution. A leg incident can stall the current-stop sequence, but that is not a reliable batch interruption workflow. |
| Start and execution | `BatchDispatchService::start`, line 283, checks a normal accepted batch; `RiderActiveWorkGuard::assertCanAdvanceLeg`, line 45, checks current work/ordering, not a batch incident. `ArrivalService::record`, line 31; `ProofService::recordProof`, line 36; and COD use these execution rules. Some transitions return early for an already-reached status. | A shared, transactionally checked hold must cover starts, arrival, pickup, outbound movement, new proof, new COD and new failed visits, including stale clients and early-return paths. |
| Batch lifecycle | `BatchDispatchService::cancel`, line 299, only permits unstarted batches; `offer`, line 84, expects a draft; draft composition requires at least two pending stops. `replaceStops`, line 143, only changes drafts. | These methods cannot safely implement an in-progress takeover by calling cancel/restore or resetting every stop to pending. A recovery batch with one remaining stop also needs an explicit, narrow exception. |
| History/UI | `ErpLogisticsController::batchWorkItem`, line 476, recognizes offered/accepted/in-progress/completed/cancelled work. `BatchDetailsModal.tsx`, line 18, prefers `stop_snapshot` over current legs. `logistics.ts`, line 427, has no interrupted/recovered batch states. | Show the incident, live hold, current affected stops, custody progress and lineage separately from the immutable original snapshot. Do not make a recovered partial batch look fully delivered. |
| Assignment | `AssignmentService::assignInternalRider`, line 29, rejects active assignments and certain exception states, then changes the leg to assigned. `ShipmentLegService::resolveRetry`, line 590, creates a new offer and handoff for a genuine failed delivery. | Reuse eligibility and assignment creation without inventing a failed visit, losing pickup history, or implicitly assigning custody. Do not loop `resolveRetry` over unvisited batch stops. |
| Physical transfers | `ShipmentCustodyService::request`, line 23, validates known custody and current target assignment; `confirm`, line 108, checks real participants, different release/receipt actors, acceptance and private evidence. `assertRiderHasCustody`, line 179, protects a single leg. | Reuse these transfers for each parcel. Add an all-required-parcels gate for a replacement batch; receipt of its first parcel alone must not enable execution. Unknown historical custody remains unknown. |
| Appointments | `DeliveryStartEligibility::blockedReason`, line 13, protects confirmed windows. Normal batch start checks its first stop; later operations check the relevant leg. Retry rescheduling checks agreement inside `ShipmentLegService::resolveRetry`. | Recovery must retain per-stop appointments and record agreement for each changed appointment. Do not require every future stop's window to be open simultaneously or skip dispatcher ordering. |
| Notifications | `BatchDispatchService::recordBatchEvent`, line 464, records an event against the first leg only. `DeliveryEventService`, lines 20 and 62, provides after-commit delivery and durable retries. `LogisticsNotificationService::createOnce`, line 249, includes shipment/leg in its deduplication key. | First-leg events alone cannot notify all affected customers. Per-leg fan-out without a batch occurrence rule would spam the dispatcher. Add batch incident/decision occurrence keys and separate dispatcher/customer audiences. |
| Return/finance | `requireReturn`, line 707, and `createReturnToShopLocked`, line 788, are failed-delivery recovery operations. `confirmReturnReceipt`, line 882, can cancel the original shipment and invoke inspection/repair recovery; `reserveFailedDeliveryRefundForReturn`, line 837, can reserve compensation. | A temporary vehicle interruption must not automatically enter cancellation/refund recovery. Reuse custody-to-shop for safekeeping; retain a held escalation if continuation is unsafe. Existing genuine customer returns keep their own approval/inspection workflow. |

## Findings

| Priority | Finding | Operational impact |
| --- | --- | --- |
| High B1 | Vehicle failure reporting has no atomic batch incident/hold. | Remaining stops can proceed even though the vehicle is unusable; unvisited stops may be incorrectly reported as failed visits as a workaround. |
| High B2 | Execution checks have no shared interruption gate. | Disabling buttons would leave direct requests, stale tabs and already-in-transit early returns insufficiently protected. |
| High B3 | In-progress batch reassignment is not an existing operation. | Reusing draft cancellation/assignment naively risks lost stage/history, duplicate assignments, wrong stop order and ghost work. |
| High B4 | Custody is per parcel; no replacement-batch readiness gate exists. | One received parcel could be mistaken for permission to execute the entire replacement route. |
| High B5 | Failed-delivery return is not neutral storage recovery. | Reusing it indiscriminately could cancel orders or trigger financial/repair consequences unrelated to the breakdown. |
| Medium B6 | Batch event delivery is anchored to one shipment, with per-leg deduplication. | Affected customers can miss updates, or dispatchers can receive one duplicate alert per stop. |
| Medium B7 | Batch UI/history and projections do not represent interruption/recovery lineage. | Riders/dispatchers cannot reliably see held stops, partial transfers and the actual continuation route after refresh. |

These are gaps relative to the newly requested batch behavior, not claims that existing per-shipment recovery, custody or notification tests failed. No new test failure or browser reproduction is claimed in this audit.

## Reuse and constraints

- Reuse `DeliveryIncident`, private incident evidence handling, `DeliveryEventService`, the existing pending-notification command/scheduler and notification storage. Extend batch scope rather than create a parallel incident/outbox system.
- Reuse `AssignmentService` eligibility, `BatchDispatchService` acceptance/start, `RiderActiveWorkGuard`, `DeliveryStartEligibility`, `ShipmentCustodyService`, `CustodyHandoffs` and existing page layouts/dialogs.
- Preserve `LogisticsActorPolicy`, tenant checks, `EnsureEmployeeSecurity`, `EnsureEmployeeClockedIn`, source-module boundaries and owner read-only restrictions. Batch management alone must not grant exception-resolution authority.
- Logistics OFF can still permit safe reporting and already-released receipt for existing work under the current continuation policy; it must not authorize a new rider offer, outbound handoff or resume. Existing COD remittance can continue independently of a vehicle hold.
- Collected COD stays the original collector's liability. No parcel-transfer action changes the collection/remittance record, collects a second payment, marks stock resellable or approves a refund.

## Recommended design

Extend the existing incident model with a batch subject, an affected-stop relation and an active incident pointer on the batch. Keep individual shipment stages and attempt counters intact while the pointer holds execution. One small `BatchInterruptionService` coordinates existing services under the shop/batch/leg locks.

Same-rider recovery resumes the original batch after an authorized decision. Different-rider recovery creates a linked ordinary delivery batch containing only the still-authorized remaining stops, in their previous order. Original completed stops and the original stop snapshot remain on the source history. Replacement acceptance remains separate from custody, and start waits for every required parcel receipt. A single remaining stop uses the same recovery-batch path with a recovery-only minimum-size exception, not an invented failed delivery.

Alternatives considered: mutating the original batch's rider would obscure original rider/history ownership; converting every remaining stop into an individual retry would fabricate failure semantics and discard batch ordering. Neither is recommended.

## Verification boundary

This task changes only this audit and the proposed plan. Application tests, browser journeys, concurrency races, migrations and builds are future execution gates. Earlier results and the three pre-existing route-catalog failures are recorded in the existing verification report; they were not rerun or promoted here. The known pickup-arrival UI gap in the manual guide remains a separate issue unless it blocks a required pickup recovery journey.

Approval of the linked plan is required before implementation. No commit, push, merge, deployment, environment edit or production migration was performed.
