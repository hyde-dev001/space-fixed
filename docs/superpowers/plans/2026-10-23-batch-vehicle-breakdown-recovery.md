# Batch Vehicle Breakdown Recovery Implementation Plan

> **For agentic workers:** After user approval, use `superpowers:executing-plans` sequentially with one main agent under `AGENTS.md`. Track steps with checkboxes. Do not implement, delegate, commit, push, merge, deploy or run production migrations from this planning task.

**Goal:** Hold a broken-down rider's remaining batch work and recover it through explicit dispatcher decisions and evidenced parcel custody, without fabricating customer attempts or changing financial authority.

**Architecture:** Extend existing delivery incidents with batch scope and an affected-stop snapshot. A small batch interruption coordinator reuses current batch, assignment, appointment, custody and event services. Same-rider recovery resumes the original batch; takeover uses a linked delivery batch with an aggregate handoff gate.

**Tech stack:** Existing Laravel 12/PHP 8.2, Eloquent transactions, MariaDB/InnoDB, Inertia/React/TypeScript, PHPUnit, Vitest and the disposable authenticated browser/concurrency harness. No new dependency or logistics engine.

## Authority and baseline

Approved by the user for sequential implementation on October 23, 2026; grounded in the [source audit](../../operations/2026-10-23-batch-vehicle-breakdown-audit.md). Work stays in `.worktrees/failed-delivery-recovery`, `codex/failed-delivery-recovery`, currently at `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5` plus the uncommitted recovery/MFA/seeder/notification work. Preserve that working tree, root/OCR worktrees, their builds and all unrelated changes. Do not branch from bare HEAD and omit the recovery prerequisites. No integration-base change or Git operation is implied by approval to implement.

## Proposed behavior for approval

1. **Report:** On Rider → My Deliveries, show **Report vehicle breakdown** for an accepted or in-progress batch, including before its first stop. Require the actual assigned authenticated rider, normal permissions/attendance, a reason/note, private evidence and a request UUID. An empty/terminal batch cannot be interrupted. Do not set rider availability globally or alter unrelated work.
2. **Atomic hold:** Lock and refresh the shop, batch and relevant legs before selecting affected stops. Persist the incident, original batch state, ordered affected-stop records, assignment/stage/custody snapshots and hold in one transaction. Include the unresolved current stop as well as unvisited remaining work. Preserve arrival evidence, completed/cancelled work, submitted proofs awaiting review and legitimate separate failures; do not add attempts, increment counters, reset timestamps or fabricate possession. An existing failed/incident-held stop stays under its own resolution and is not silently swept into continuation.
3. **Backend enforcement:** An active batch incident blocks customer execution on its affected legs even after refresh or a stale request. Check before side effects and before early success returns for start/pickup/in-transit operations. Block new arrival, proof, COD collection, failure submission, pool/rebatch/reassignment shortcuts and customer completion on held work. Permit authenticated evidence/incident review, required custody confirmations, existing cash remittance and review of an already-submitted proof excluded from recovery. Reads stay available.
4. **Same rider:** Dispatcher → Batches → interrupted batch → **Resolve interruption → Resume same rider**. Require exception-resolution permission, reason, expected incident/version and revalidated rider/module eligibility. Preserve accepted assignments, known custody and prior stage. Restore accepted versus in-progress state correctly; do not auto-start an accepted batch or manufacture another pickup. Relevant confirmed appointment windows remain binding at execution.
5. **Different rider:** **Resolve interruption → Assign replacement rider**, with a date/window, eligible rider, direct/via-shop route and per-stop customer agreement where an appointment changes. Atomically create one linked recovery batch and fresh offers for the authorized affected stops. Preserve stage, source identity, prior assignments, original ordering, pickup/arrival/proof history and custody. Keep completed stops in source history. The replacement accepts the batch; assignment/acceptance alone cannot start it.
6. **Every parcel:** Each physically possessed parcel requires the existing authenticated release and receipt with private evidence. Via-shop requires both transfers. Unknown custody for already-started work blocks takeover and stays under investigation. Uncollected work continues through normal pickup proof; absence of proof never establishes confirmed shop/rider possession. The replacement batch cannot start until all parcels it requires are confirmed with its rider and no required transfer is outstanding. A partial receipt cannot unlock its first stop.
7. **Return/escalation:** **Resolve interruption → Return to shop / escalate** keeps customer delivery held. Use existing custody-to-shop transfers for actual safekeeping receipt; if a transport leg is needed, use the existing authorized return mechanism only after validating its source and financial consequences. Never invoke failed-delivery cancellation/refund compensation just to store a parcel after vehicle trouble. If transport/custody cannot be safely established, keep the incident open with the existing exception review, actor/note and customer delay updates. Later continuation requires a new authorized decision; shop receipt must not automatically create an outbound handoff for this case. Genuine Retail Return/Repair Pickup receipts still require the existing inspection/financial workflow.
8. **History:** The source batch displays **Interrupted** while held. After successful takeover authorization/readiness it displays **Recovered by batch #…**, not fully delivered or casually cancelled. The new batch uses normal offered/accepted/in-progress/completed states with an incident hold overlay until ready. Store immutable decision events and timestamps; cancelling an unreleased transfer never clears the overall hold. A released/partly received transfer cannot be rolled back by changing riders. Repeated incidents create new occurrences after the prior incident closes; a breakdown on a recovery batch follows the same flow.
9. **Appointments and OFF:** Internal planning dates may start early when otherwise eligible. Preserve each confirmed appointment; changed dates/windows need a per-customer agreement note/actor/time. Check the first eligible stop at route start and each later stop when advanced; never skip order to bypass a window. With Logistics OFF, allow safe interruption reporting and already-released receipt for qualifying existing work; block new offers, outbound transfers and resume. Re-enabling requires explicit reauthorization, not an automatic resume.
10. **Notifications:** After commit, create one immediate persistent dispatcher alert per incident occurrence in the correct shop and a separate delay notice for each affected shipment's customer. Confirmed schedule changes and actual continuation produce separate occurrence-deduplicated updates. Notification retry uses the existing scheduler; display remains existing polling, not a new push/SMS system. Dispatcher links open the authorized Batches incident; customer links remain their own tracking pages and never expose other stops or private evidence.

## Data changes

Use Artisan to generate two new migrations after checking the actual latest timestamps; do not edit the five existing recovery migrations. Names below specify the migration suffixes, not already-created files.

| Change | Proposed contents and invariants |
| --- | --- |
| `database/migrations/<generated>_add_batch_scope_to_delivery_incidents.php` | Make `shipment_leg_id` nullable for batch incidents; add nullable `delivery_batch_id` and `recovery_batch_id` FKs, request/decision UUIDs with unique nullable indexes, normalized decision-facts hash, decision actor/time and optimistic revision. Exactly one subject (leg or batch) is validated for every incident; old rows remain leg incidents. Keep original notes/evidence immutable; decisions use current fields plus existing immutable events. |
| `database/migrations/<generated>_add_batch_incident_holds_and_stops.php` | Add nullable `delivery_batches.active_incident_id` FK and a `delivery_incident_stops` table with incident/leg FKs, unique `(incident_id, shipment_leg_id)`, original sequence, original assignment/stage and bounded server snapshot, plus per-stop recovery decision/outcome linkage. Index active lookup paths. Child snapshots are private internal data with allowlisted projections. |

Batch status is currently a string; add application states `interrupted` and `recovered` and update all real status consumers. An active incident pointer can also protect the linked replacement batch until it becomes ready. One unresolved incident per source batch is enforced under the locked batch row; UUID indexes cover request replays. Do not infer or backfill historical holds/custody. Preserve history through restrictive FKs where appropriate; never introduce cascading deletion of existing delivery evidence.

Rollback testing uses disposable SQLite/MariaDB only. Restoring `shipment_leg_id` to NOT NULL cannot safely succeed while batch incidents exist: document and test that destructive down-migration is refused rather than deleting incidents or inventing a leg. Production rollback preference is coordinated code rollback with additive schema/evidence retained; an old release must not be restored blindly while it cannot enforce active holds.

## File-level structure

| Files / area | Responsibility |
| --- | --- |
| Create `app/Services/Logistics/BatchInterruptionService.php` | Report/snapshot, authorized resolution and recovery readiness orchestration only. Reuse existing movement, eligibility, assignments, custody and events. No duplicate proof, cash or refund processor. |
| Extend `app/Models/Logistics/DeliveryIncident.php`, `DeliveryBatch.php`, `ShipmentLeg.php`; create `app/Models/Logistics/DeliveryIncidentStop.php` | Batch subject, affected-stop/lineage/hold relationships, safe casts and hidden snapshots/paths. |
| Extend `app/Http/Controllers/Api/Logistics/DeliveryIncidentController.php`; create `app/Http/Requests/Logistics/ReportBatchInterruptionRequest.php` and `ResolveBatchInterruptionRequest.php` | Batch report and resolution endpoints, expected revision, bounded payloads and existing validated private evidence handling. Branch explicitly by subject in existing resolver/evidence authorization; never dereference a missing leg or use a first-leg shortcut as tenant authority. |
| `app/Services/Logistics/LogisticsActorPolicy.php`, `routes/web.php`, relevant `config/shop_modules.php` catalog entries | Add batch-scoped reporting/resolution checks using existing capabilities and middleware. Batch manager without exception authority cannot resume/reassign. Keep owner read-only and evidence access scoped. |
| `app/Services/Logistics/RiderActiveWorkGuard.php`, `BatchDispatchService.php`, `ShipmentLegService.php`, `ArrivalService.php`, `ProofService.php` | One shared locked hold check applied to real mutation paths and early returns. Prevent single-leg resolution/cancel/rebatch from escaping a batch hold. Excluded already-submitted proofs retain legitimate review. Audit `ProofReviewService.php`, COD and source reconciliation callers; edit only if a demonstrated bypass needs it. |
| `app/Services/Logistics/AssignmentService.php`, `DeliveryStartEligibility.php`, `ShipmentCustodyService.php` | Reuse/extract only necessary existing assignment/date validation for batch recovery. Make narrowly scoped recovery assignment and held-stage transfer support; no reset-to-pending workaround. Prevent automatic shop-to-rider follow-on while an incident requires reauthorization. Add aggregate readiness without weakening per-parcel confirmation. |
| `app/Services/Logistics/DeliveryEventService.php`, `LogisticsNotificationService.php` | Reuse after-commit delivery; add batch occurrence/audience dedup and fan-out. Keep event processor unchanged unless integration requires a small compatible extension. |
| `app/Http/Controllers/Logistics/ErpLogisticsController.php`, `resources/js/types/logistics.ts`, `resources/js/services/logisticsApi.ts` | Allowlisted incident/hold/lineage/readiness projections, active work and status consumers, authenticated API calls. No evidence storage paths in ordinary props. |
| `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`, `Batches.tsx`, `Shipments.tsx`; components `BatchDetailsModal.tsx`, `BatchTable.tsx`, `BatchHistoryModal.tsx`, `CustodyHandoffs.tsx`; create `components/BatchInterruptionDialog.tsx` | Rider report, dispatcher choices, live affected-stop history, clear held/awaiting-handoff state and links. Reuse current dialog, feedback, upload and custody UI. New dialog remains focused on this operation; update additional status consumers only where found. |
| Tests listed below; existing operations/manual testing/inventory docs | Add regression and authenticated execution evidence, next-page instructions, migration/release notes and exact remaining limits. Do not alter old approval records or claim old tests verified this extension. |

## Sequential phases (execution tracking)

### Phase 0 — Preserve baseline and establish failing coverage

- [x] Recheck `AGENTS.md`, branch/base and full tracked/untracked/staged snapshots; carry forward all uncommitted recovery prerequisites. No automatic rebase, stash or commit.
- [x] Read the audit, existing approved recovery plan/execution/manual guide and target status/notification consumers. Confirm isolated SQLite and disposable MariaDB before any test writes; no `.env` edit or production connection.
- [x] Run current narrow suites below and record exact baseline failures/skips, including known catalog assertions and GD availability separately. Do not weaken tests to accommodate either.
- [x] Add initial failing cases to new `tests/Feature/Logistics/BatchInterruptionTest.php`: before-first/mid/last remaining stop, preservation of delivered/failed/proof-review work, unchanged attempt counts and stale-client denial. Assert the current missing batch behavior is the failure, not environment setup.

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/BatchDispatchServiceTest.php tests/Feature/Logistics/DeliveryIncidentServiceTest.php tests/Feature/Logistics/ShipmentCustodyTransferTest.php tests/Feature/Logistics/RecoveryNotificationTest.php --display-skipped
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

Checkpoint: baseline preserved, actual gap reproduced by tests, no inferred visits or custody.

### Phase 1 — Persist and enforce the hold

- [x] Add migration/model tests, including old leg incidents, repeated occurrences, nullable subject validation, UUID fact mismatch and one-open-incident concurrency.
- [x] Add the two additive migrations/models and coordinator report path; atomically snapshot all affected stops and establish the hold without changing shipment attempts/stages.
- [x] Apply the shared backend gate to starts, arrival, pickup, transit, proof, COD and failure mutations; test direct HTTP/service requests, not only UI. Review early returns and source-reconciliation escape routes. Active-work queries must retain the source rider's unresolved interruption/parcel responsibility even after legs move to a recovery batch; do not let an empty live-leg relation erase that responsibility or create ghost active work.
- [x] Extend existing incident evidence authorization/cleanup for a batch subject, maintaining validation, private storage, authorized viewer and rollback/replay cleanup. Test cross-shop, wrong-rider, unclocked-in, MFA and wrong-permission denials.
- [x] Run `BatchInterruptionTest.php`, `DeliveryIncidentServiceTest.php`, `DeliveryExecutionTest.php`, `DeliveryArrivalTest.php`, `tests/Feature/Cod/CodCollectionTest.php` and `RiderTenantAuthorizationTest.php` serially.

Checkpoint: all remaining applicable work held in one transaction; excluded completed/reviewed work and unrelated shipments retain their real state.

### Phase 2 — Dispatcher recovery and physical custody

- [x] Write failing `tests/Feature/Logistics/BatchInterruptionRecoveryTest.php` cases for same rider, new rider, one remaining stop, all-parcel readiness, unknown custody, appointment changes, OFF mode, rejected offer and return/escalation.
- [x] Implement same-rider resume with expected revision and eligibility; preserve stage/assignment/custody, and leave accepted work requiring normal Start batch.
- [x] Implement linked recovery batch through existing batch/assignment methods with a recovery-only composition/stage adapter. Preserve original snapshot/history and completed stops. Aggregate capacity over the proposed set without counting the replaced assignments twice. Any ineligible stop or assignment conflict rolls the decision back atomically.
- [x] Require new-rider acceptance and each parcel's existing release/receipt. Direct and via-shop transfers preserve original custody until final receipt. Do not start after partial receipt. Readiness completion is persisted under the same locks and recoverable after a refresh; never depend solely on a React callback.
- [x] Retain authoritative historical pickup timestamps and proofs; continuation must not request a fake second source pickup. New assignment arrivals/proofs remain assignment-scoped. Preserve genuine pickup workflow for uncollected stops.
- [x] Implement safekeeping/escalation with no automatic source cancellation, restock, compensation or inspection approval. Prevent the custody service's existing auto-follow-on from creating new outbound work for this held case. Released transfers need receipt or existing investigated resolution; only unreleased transfers can be cancelled.
- [x] Test appointment agreement per changed customer and execution-time windows, repeated breakdown on the recovery batch, current-holder responsibility and unchanged original COD/remittance rows.
- [x] Run the new recovery suite plus `RetryResolutionTest.php`, `ShipmentCustodyTransferTest.php`, `BatchDispatchServiceTest.php`, `LogisticsModuleMovementBoundaryTest.php`, COD suites and the four source workflow regressions.

Checkpoint: explicit dispatcher authorization and all required custody receipts precede replacement execution; the original workflow's financial and module boundaries remain intact.

### Phase 3 — Persistent notifications and page integration

- [x] Add failing `tests/Feature/Logistics/BatchInterruptionNotificationTest.php` cases: one dispatcher occurrence, per-customer isolation, valid batch focus/tracking links, repeated incidents, changed schedule, actual continuation, retry after notification failure and no duplicate scheduler delivery.
- [x] Extend existing event mappings/dedup only for batch incident occurrences. Use a single internal dispatcher event plus customer-visible per-leg events with no duplicate dispatcher fan-out. Include incident ID and decision revision so replay deduplicates but a later occurrence notifies again.
- [x] Add focused frontend cases in `Batches.test.tsx`, `MyDeliveries.test.tsx`, `Shipments.test.tsx` and new `BatchInterruptionDialog.test.tsx`. Cover disabled actions, incomplete parcel count, errors, refresh, double-click/replay, pending evidence and accessible keyboard/focus behavior.
- [x] Wire Rider → My Deliveries → Report vehicle breakdown; Dispatcher → Batches → affected batch → Resolve interruption; both participants → existing Package handoffs; replacement → My Deliveries → Accept batch → Start batch. Show source/recovery links and live stop progress beside the original historical snapshot. Notification query parameters must actually select/open the authorized incident, not merely point to a generic page.
- [x] Ensure partial reloads refresh `batches`/incident/readiness as well as current custody props; keep all statuses represented in list/history/counts. Show confirmed continuation only when movement resumes, not when an offer is merely assigned.
- [x] Run new notification/UI suites plus `RecoveryNotificationTest.php`, `DeliveryEventAfterCommitTest.php`, `LogisticsNotificationDeduplicationTest.php` and relevant catalog/route tests; compare known baseline failures explicitly.

Checkpoint: correct actors can complete the flow through existing pages; persisted notices survive notification failures and do not leak other stops.

### Phase 4 — End-to-end verification and handoff

- [x] Extend existing `tests/Browser/failed_delivery_fixture.php`, `failed_delivery_recovery.py`, `failed_delivery_verify_records.php`, `failed_delivery_concurrency.py` and `failed_delivery_worker.php` with batch scenarios. Reuse the isolated environment and normal password/MFA/Clock In; no session injection, bypass routes or production data. Keep output synthetic and ignored.
- [x] Complete browser cases before first stop, mid-batch and with one remaining stop; same-rider resume; direct/via-shop replacement; partial handoff blocked; return/escalation; appointment denial and authorized reschedule; repeated interruption; refresh/stale tab and Logistics OFF/ON. Verify all four supported shipment purposes without mixing Retail/Repair modules or skipping required approvals. If the known pickup-arrival UI gap prevents a required journey, report it and fix only the smallest necessary integration after recording that finding; do not substitute a direct API call for a claimed click-through pass.
- [x] On disposable production-engine MariaDB, race report versus pickup/start/arrival/new proof/COD/failure, duplicate reports, competing dispatcher decisions, release/receipt versus takeover/cancellation, and notification retries. Use independent processes and barriers; assert one outcome, no orphan assignments, preserved ordering/history and no execution after committed hold. Record lock/deadlock behavior and actual engine/version.
- [x] Verify migrations on fresh and representative upgraded disposable databases, unchanged old incidents/attempts/COD, index/lock behavior and safe rollback refusal with batch records. Test failure/rollback cleanup without deleting user data.
- [x] Run the focused new suites first, then the broad regression/build commands below. Keep existing GD skip and unrelated catalog failures separate. Check generated build imports/manifest, private proof access, unchanged OCR runtime and scoped diff; do not claim type/lint tooling that is not configured.
- [x] Review sequentially: simplify/reuse; repository Standards; requested behavior; TS/React quality; Karpathy/minimum scope; imports; measured functional evidence; security/Laravel; fresh verification. Inspect changed code for new unused/stale branches. No delegated reviews or commits without approval.
- [x] Update `docs/operations/failed-delivery-recovery.md`, manual guide (including next page at every step), execution report and changed-file inventory with actual results and remaining limits. Keep this plan as the approval contract. No deployment-readiness claim while required cases remain unverified.

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics tests/Feature/Cod tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Auth/EmployeeMfaLoginFlowTest.php --display-skipped
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__ resources/js/components/logistics/__tests__ resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx
python tests/Browser/failed_delivery_recovery.py --mfa
python tests/Browser/failed_delivery_concurrency.py
php -d extension=gd tests/Browser/failed_delivery_verify_records.php --mfa
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
git diff --check
```

Commands above are the approved verification contract. Executed commands, results, retries and limits are recorded in ../../operations/2026-10-23-batch-vehicle-breakdown-execution.md. Browser scripts must first be extended and their disposable environment isolation checked; baseline scripts do not already cover batch interruption. If `pnpm` is absent, use the existing cached pnpm invocation recorded in the prior report. Run PHP suites serially where filesystem fakes share directories. Production migrations, deployment, commits, pushing and merging remain separately authorized actions.

## Approval and verification status

Planning review: reuse/minimum design, repository Standards, requirements coverage and correctness/security risks reviewed sequentially. New TS/code-splitting/runtime measurements are N/A for documentation only; implementation checks are explicit above. No application tests, browser journeys, concurrency tests, build or migration ran during planning. No existing source, generated build, package/lock, environment or original approved recovery document was changed.

This proposal intentionally uses inline sequential review instead of generic skill subagent/commit instructions because the user and `AGENTS.md` restrict delegation and Git actions. The user subsequently approved Phases 0–4. Execution completed sequentially; see the separate October 24 execution record for fresh results and remaining release prerequisites. No Git integration or deployment action is authorized.
