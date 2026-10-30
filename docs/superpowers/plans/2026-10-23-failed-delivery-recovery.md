# Failed-delivery recovery implementation plan

> Status: proposed October 23, 2026. AUDIT/PLAN ONLY. Await separate approval before implementation, migrations, source edits or execution of these phases. Use `superpowers:executing-plans` after approval, sequentially in one main agent under `AGENTS.md`. No delegated writers/reviewers, commit, push, merge or deployment is authorized by this plan.

Goal: failure saved -> customer/dispatcher notified -> explicit resolution/date/eligible rider -> any required physical handoff confirmed -> eligible retry -> completion or another recorded failure, across Retail Delivery/Return and Repair Pickup/Return.

Grounding: [audit and fresh evidence](../../operations/2026-10-23-failed-delivery-recovery-audit.md), checkout `.worktrees/shop-owner-registration-ocr`, branch `codex/shop-owner-registration-ocr`, audit HEAD `36f239bf98034abd5d0dc141741a28f9f2158bc7`.

## Proposed behavior to approve

1. Every failed visit enters explicit dispatcher resolution. Preserve the current custodian if goods were collected; before-collection failure creates no rider possession. Attempt limits trigger escalation/explicit additional authorization rather than automatic approval, refund or silent new visits.
2. Dispatcher selects valid retry date/window and same/different eligible rider. Customer-agreed appointments remain binding; an earlier appointment requires recorded agreement and reason. Estimates are separately identified. Operational urgency alone cannot override a commitment.
3. Each authorized execution uses a fresh existing assignment row, even for the same rider, with normal offer acceptance. Preserve per-assignment attempt uniqueness and replay semantics. Same-rider retry retains possession without inventing a transfer.
4. New rider assignment is an offer to work, never proof of possession. If rider changes, require authenticated release and receipt via rider -> rider or rider -> shop -> rider. Until receipt, retain custody responsibility and block recipient execution. Confirmed return-to-shop receipt releases the former rider even when the future appointment is later.
5. Start normal/retry/batch work through common server eligibility: current assignment/acceptance, actor attendance, active-stop ordering, module availability, confirmed appointment and actual custody. Keep UI checks consistent. Estimates may permit preparatory work; confirmed appointment movement is conservatively gated unless a distinct preparation operation is designed and approved.
6. Separate parcel and cash custody. Preserve the collector and remittance obligation after reassignment. No second charge, automatic cash transfer, automatic payout or premature stock restoration.
7. Customer/dispatcher/rider updates are persisted per real business occurrence and safe to retry; another attempt generates another update. Keep current polling architecture and document its refresh delay.
8. Complete only through existing authorized proofs, receipt, inspection, source settlement and Finance decisions. Preserve RBAC, tenant isolation, suspension, attendance, private files, history and Logistics ON/OFF behavior.

## Minimal data changes proposed

These are future schema changes, not migrations authored or run during the audit. Recheck schema at execution time.

| Change | Reason / compatibility |
| --- | --- |
| Add `shipment_custody_transfers` with tenant/leg, source and target custodian identities, target assignment, status, release/receipt actor+timestamps, proof references and unique request identity. | Independent physical responsibility and two-party confirmation. Serialize transitions by locking the leg; one outstanding transfer per leg enforced transactionally. Reuse private HandoffProof storage rather than duplicate uploaded files. Current holder derives from confirmed custody events/transfer state; if a cached holder is needed, keep it on the locked leg and reconcile it transactionally. |
| Bind newly recorded pickup/transfer evidence to the applicable execution/assignment with nullable foreign keys as needed. | Legacy leg-level proofs remain historical; cannot authorize a different rider's new execution. Do not infer verified custody for ambiguous legacy rows. |
| Add explicit schedule provenance and customer-confirmed appointment data on existing legs (date/window plus agreement actor/time/reference), only where not already represented. | Distinguish estimates from binding appointments; preserve old data as unknown/estimated until confirmed. Never turn missing provenance into permission to bypass a known commitment. |
| Persist interrupted incident state, and separate resolution note/actor from original incident notes using existing event actor/metadata plus minimal incident snapshot fields. | Safe dismissal and durable narrative. No generic resolution-history table is needed. |
| Add nullable unique Logistics notification delivery identity and delivery progress on persisted events. | Safe retries and a recovery sweep, without imposing new uniqueness semantics on unrelated notifications. Check existing rows before backfilling identities; do not delete old notifications automatically. |

No new shipment per ordinary retry is needed. Reuse `DeliveryAssignment` for execution identity and keep `delivery_attempt_assignment_unique`; the existing source-attempt and return-source uniqueness remains. No COD/payment schema rewrite is proposed. Any extra schema dependency discovered later must be justified before expanding scope.

## Phase 0 - Protect baseline and make acceptance failures executable

Affected: docs/evidence and existing tests only after approval.

- [ ] Snapshot branch/HEAD, staged/unstaged/untracked paths and changed-file hashes. Preserve existing `output/`, browser cache and unrelated root/worktree work; no broad staging/reset/stash.
- [ ] Re-read current `AGENTS.md`, audit and schema; inspect CodeGraph first where usable and verify source paths against this checkout.
- [ ] Confirm SQLite testing isolation before tests; use fakes/private test disks. Do not edit `.env` or use shared/production credentials.
- [ ] Convert the four output-local observation probes into **failing desired-behavior regressions** in existing owned suites: `DeliveryExecutionTest.php`, `LogisticsNotificationTest.php`/`LogisticsNotificationDeduplicationTest.php`, `DeliveryIncidentServiceTest.php`. Do not retain assertions that celebrate defects as acceptance tests.
- [ ] Reproduce damaged-return inspection, Retail Return failure, future normal/batch start, date/rider selection and audit actor gaps through focused tests. Add API coverage, not only service calls.
- [ ] Resolve the baseline fallback-shape test mismatch deliberately in `tests/Feature/Logistics/LogisticsPageAccessTest.php:1408` after confirming intended safe props. Preserve cross-shop/missing-source checks. Record GD skip separately; do not label the whole baseline green.

Verification: `php artisan test tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/DeliveryIncidentServiceTest.php tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php`. Expected before fixes: targeted functional failures, not setup/syntax failures.

## Phase 1 - Correct execution identity, history and interrupted states

Depends on Phase 0. Addresses F01/F05/F10.

Likely edits:

- `app/Services/Logistics/ShipmentLegService.php`
- `app/Services/Logistics/AssignmentService.php`
- `app/Services/Logistics/DeliveryIncidentService.php`
- `app/Services/Logistics/DeliveryEventService.php`
- `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- `app/Http/Controllers/Api/Logistics/DeliveryIncidentController.php`
- `app/Models/Logistics/DeliveryIncident.php` and additive migration for the incident snapshot if required.
- `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx` failure request identity.

- [ ] Introduce a new existing assignment for each authorized retry execution, including same rider; keep current physical holder unchanged while changing work assignment. Do not use a new assignment as evidence of a transfer.
- [ ] Keep duplicate requests for the same execution idempotent; reject incompatible key replays. Fresh execution can record another failed visit, increment history and return to resolution.
- [ ] Pass authenticated actor separately from tenant owner for assignment/resolution/receipt and append immutable event metadata for before/after state and related identities.
- [ ] Save incident interrupted state and restore it only when dismissal is still compatible; do not jump assigned -> in_transit or overwrite original notes.
- [ ] Include attempt/execution identity and timestamps in safe dispatcher history while preserving customer redaction and original private evidence.

Tests: extend `DeliveryExecutionTest.php`, `DeliveryIncidentServiceTest.php`, `DeliveryIncidentControllerTest.php`, `SingleDeliveryOfferTest.php`, and frontend `__tests__/MyDeliveries.test.tsx`. Cases: fail -> authorize -> accept -> start -> fail again; same-request replay; stale previous assignment; conflict with newer decision; assigned/picked-up/in-transit incident dismissal. Narrow checks must pass before Phase 2.

## Phase 2 - Track physical custody and enforce handoff

Depends on Phase 1. Addresses F02 and the custody portion of F03/F09.

Create, only if no equivalent exists:

- `app/Models/Logistics/ShipmentCustodyTransfer.php`
- `app/Services/Logistics/ShipmentCustodyService.php`
- `app/Http/Controllers/Api/Logistics/ShipmentCustodyController.php`
- additive migration `database/migrations/<execution-date>_create_shipment_custody_transfers_table.php` and nullable proof binding migration as needed.
- `tests/Feature/Logistics/ShipmentCustodyTransferTest.php`.

Modify existing `ProofService.php`, `ShipmentLegService.php`, `RiderActiveWorkGuard.php`, `LogisticsActorPolicy.php`, `routes/web.php`, `resources/js/types/logistics.ts`, `resources/js/services/logisticsApi.ts`, `Shipments.tsx` and `MyDeliveries.tsx`. Apply the repository Design role/skills before UI changes; reuse current action/status/photo components.

- [ ] Failure preserves actual holder; before-collection failure has no rider holder. Reconcile uncertain legacy custody explicitly without fabricated backfill.
- [ ] Implement requested/released/received/canceled transfer transitions, safe stale/replay handling and immutable actor/timestamp/proof events. Lock same-shop participants/leg consistently; prohibit arbitrary from/to actor IDs from asserting receipt.
- [ ] Require source release and target receipt; for shop mediation require both physical transfers. Prohibit self-confirmation of both sides. Only actual recipient can confirm rider receipt; shop receipt uses existing dispatcher capability.
- [ ] Old leg pickup proof cannot satisfy another assignment's receipt. New rider cannot start, submit delivery evidence or collect COD before custody/operational prerequisites.
- [ ] Preserve existing return-to-shop two-step confirmation and release the former rider's hold only after verified receipt. Moving goods does not change collector/remittance records.

Verification: new transfer tests plus `ReturnToShopTest.php`, `PostPickupCancellationTest.php`, `RiderTenantAuthorizationTest.php`, `RiderProgressionTest.php`, `ProofReviewFlowTest.php`, `LogisticsModuleMovementBoundaryTest.php`. Test two concurrent recipients, cross-shop IDs, canceled/replaced assignment, duplicate release/receipt, missing/lost parcel and OFF continuation versus new dispatch.

## Phase 3 - Explicit dispatcher recovery and shared schedule/rider eligibility

Depends on Phases 1-2. Addresses F03/F04/F12.

Modify `ShipmentController.php::retryResolution`, `ShipmentLegService.php`, `AssignmentService.php`, `BatchDispatchService.php`, `DeliveryScheduleService.php`, `RiderActiveWorkGuard.php`, `LogisticsActorPolicy.php`, `SourceShipmentService.php`, `app/Console/Commands/MonitorOverdueDeliveries.php`, `ErpLogisticsController.php`, existing requests, frontend `Shipments.tsx`, `MyDeliveries.tsx`, `Batches.tsx`, types/API and their tests. Add one focused request/shared eligibility helper only if the existing classes cannot own the common rule cleanly.

- [ ] Every failed execution awaits explicit resolution; do not auto-reset delivery to pending before a dispatcher decides. Retain max-attempt escalation and require explicit additional retry authorization/reason beyond the configured limit.
- [ ] Retry request selects operating date/window, rider, agreement/provenance and transfer route. Reject unavailable date/rider, tenant mismatch, source/Finance invalidity and stale resolution version atomically.
- [ ] Reuse batch work-day/leave/capacity and employee policies for single offers; recheck profile under locks. Keep normal attendance requirements for actors accepting/executing work.
- [ ] Centralize server start rules, including appointment window and actual custody, across normal, retry and batch paths. UI uses server-derived availability/reasons; direct API calls remain protected. An appointment change requires recorded customer agreement, not a convenient earlier date.
- [ ] Stop the overdue monitor silently rewriting confirmed appointments. Reuse estimate-delay events for estimates, and escalate confirmed missed appointments for explicit resolution.
- [ ] Render concise state: awaiting decision, awaiting release/receipt, scheduled, ready to start, blocked reason and full dispatcher history. Avoid a new parallel shipment state machine.

Verification: existing `DeliveryExecutionTest.php`, `BatchDispatchServiceTest.php`, `DeliveryBatchApiTest.php`, `AssignmentServiceTest.php`, `SingleDeliveryOfferTest.php`, `OverdueDeliveryMonitorTest.php`; add `tests/Feature/Logistics/RetryResolutionTest.php` for atomic date/rider/custody decisions and customer agreement. Frontend existing Shipments/MyDeliveries/Batches tests cover selection, pending handoff, window boundary, same-day permitted retry, future committed appointment, missing/stale metadata and failed request recovery.

## Phase 4 - Complete notifications and accountable resolution trail

Depends on Phases 1-3. Addresses F07/F08/F10/F11.

Modify `LogisticsNotificationService.php`, `DeliveryEventService.php`, `app/Models/Logistics/DeliveryEvent.php`, `app/Enums/NotificationType.php` as needed, `app/Models/Notification.php`, `routes/console.php`, existing notification tests. Add a focused event-notification job/recovery command and additive event-progress/nullable delivery-key migration if no equivalent current facility exists.

- [ ] Maintain a producer/recipient matrix for failed pickup/delivery, retry decision, rider reassignment, required/received transfer, return departure/receipt, delivery completion and safe incident status.
- [ ] Share semantic occurrence identity across paired internal/customer events, dedup by occurrence/recipient/purpose and use database-backed unique delivery identity. Distinct failed visits/departures must produce distinct notices.
- [ ] Record events atomically with transitions. Process after commit with retry and a persisted-event recovery sweep; partial fan-out replay must fill missing recipients without resending delivered rows. Do not turn a committed failure report into an apparent unsaved failure when notification delivery errors.
- [ ] Validate every notification target under the intended customer/rider/dispatcher guard. Preserve same-shop recipients and customer ownership. Link to specific record/context where existing screens support it; never expose internal notes/evidence to unrelated recipients.
- [ ] Preserve existing polling; document persistence versus 10/15-second display refresh. External email/SMS/push is not silently claimed or added.

Verification: `LogisticsNotificationTest.php`, `LogisticsNotificationDeduplicationTest.php`, `DeliveryEventAfterCommitTest.php`, `CustomerTrackingTest.php` and new `tests/Feature/Logistics/RecoveryNotificationTest.php`. Include two attempts, repeated same request, two retries, reassignment to B, former rider notice, rollback, crash-between-commit-and-processing, partial recipient failure, concurrent same-event processing and cross-shop recipient/link denial.

## Phase 5 - Complete source-specific recovery, damaged inventory and COD exceptions

Depends on Phases 1-4. Addresses F06/F09 and cross-source financial invariants.

Likely edits: `ShipmentLegService.php`, `SourceShipmentService.php`, `DeliveryIncidentService.php`, `ProofReviewService.php`, existing refund controller/UI only where required for actual inspection, and `MyDeliveries.tsx`. Reuse `OrderRefundService.php`, `RefundInventoryDispositionService.php`, `RepairDeliveryService.php`, `CodCollectionService.php`, `CodRemittanceService.php`, `Finance/CodRefundPayoutService.php`; modify these protected financial seams only for demonstrated integration defects.

- [ ] Support failed customer collection for Retail Return separately from already-collected return-to-shop transport. Preserve initial refund approvals and no double refund.
- [ ] Remove fabricated resellable decisions. Physical receipt releases custody; authorized per-line inspection drives restock/write-off and subsequent refund stage through existing services.
- [ ] Confirm repair pickup terminal compensation/replanning and repair return shop-recovery/settlement remain correct; retain paid/sponsored distinctions.
- [ ] Lost/damaged escalation records investigated responsibility with actual actor/evidence. No automatic liability against a new rider; no automatic payout from a status change.
- [ ] Test collection by A -> failure -> reassignment to B: no second collection and cash stays A's remittance obligation. If cash was collected but goods cannot complete, use existing dispute/refund/Finance path; no implicit cash handoff.

Verification: `FailedDeliveryRefundWorkflowTest.php`, `ReturnToShopTest.php`, `DeliveryIncidentServiceTest.php`, `tests/Feature/Cod`, `OrderRefundReturnInspectionTest.php`, `tests/Feature/Repair/RepairDeliveryReconciliationTest.php`, `RepairSponsoredDeliveryPlanReplayTest.php`; add `tests/Feature/Logistics/RetailReturnFailureRecoveryTest.php`. Cover damaged/partial/missing items, duplicate receipt/inspection, cash collected/uncollected/settled/refund pending, remittance shortage/supplement and repeated failures.

## Phase 6 - End-to-end acceptance, concurrency and handoff

Depends on all previous phases; no partial phase set is a release.

- [ ] Run narrow changed suites, then the full selected Logistics/COD/repair regressions and relevant frontend suites. Compare with the recorded baseline. Resolve rather than hide assertion failures or skips.
- [ ] Run real authenticated browser journeys in disposable local storage/database with normal login/MFA/attendance. Four shipment purposes each require failure -> correct notifications -> resolution/date/rider -> necessary handoff -> start -> another failure or completion. Include same/different rider and shop-mediated transfer. Backend/API assertions verify records and private files; UI screenshots alone do not prove authorization.
- [ ] Verify customer-agreed future appointment cannot start early through normal/retry/batch endpoints. Verify operationally eligible same-day retry can start, and unconfirmed estimates follow the approved preparation rule. Test server timezone/day and morning/afternoon boundaries.
- [ ] Use a disposable database of the production engine for true parallel transactions: duplicate failure, competing resolutions, two receivers, replacement during confirmation, batch start versus failure, return versus retry, and collection/remittance/reassignment. Audit and standardize lock order in changed paths; SQLite passing is insufficient evidence for InnoDB deadlock behavior.
- [ ] Exercise Logistics OFF: deny new dispatch/retry, preserve authorized in-custody continuation/receipt and existing explicit cancellation/compensation exceptions. Include third-party tracking and owner read-only restrictions.
- [ ] Run build and diff hygiene after source changes. No type-check/lint success claim without configured tooling. Complete sequential AGENTS review stack, reuse/dead-code check and operations notes with actual evidence/remaining limits.

Future broad commands (not executed as implementation in this planning task):

```powershell
php artisan test tests/Feature/Logistics tests/Feature/Cod tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Repair/RepairSponsoredDeliveryPlanReplayTest.php tests/Feature/OrderRefundReturnInspectionTest.php
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__ resources/js/components/logistics/__tests__
pnpm run build
git diff --check
```

## Acceptance checklist for approval and later evidence

| Requirement | Must demonstrate |
| --- | --- |
| Failure and repeated visits | Immutable attempt per execution, real timestamps/reason/evidence, duplicate request unchanged, subsequent failure saved and escalated. |
| Dispatcher resolution | Required explicit decision, selectable eligible date/rider, actor/history, safe conflict/replay. |
| Custody | Assignment without receipt cannot operate; source/target confirmations; same-rider continuity; shop-mediated custody; stale/cross-shop/self-confirm denied. |
| Appointments and eligibility | Shared normal/retry/batch gates, no unagreed early appointment; work-day/leave/capacity/attendance/module/current-stop checks preserved. |
| Notifications | Correct recipients/links for every occurrence, immediate persisted rows after successful processing, delayed display characterized, retry/rollback/concurrent dedup verified. |
| Batch/source integrity | No duplicate live assignment/return/source shipment, historical membership preserved, competing transitions converge or fail safely. |
| Finance and exceptions | Actual inspection precedes stock decision; original cash holder unchanged; no duplicate collection/refund/remittance; approved loss/damage escalation. |
| Four purposes / ON-OFF | All four authenticated workflows, correct source settlement and module behavior; third-party and read-only owner boundaries unchanged. |

Execution handoff must include exact changed paths, migration compatibility/data reconciliation, fresh commands/results, browser/concurrency evidence and open limits. A later commit/push/deploy/production migration needs its own authorization. Approval of this plan authorizes only the development implementation/testing scope explicitly granted by the user.
