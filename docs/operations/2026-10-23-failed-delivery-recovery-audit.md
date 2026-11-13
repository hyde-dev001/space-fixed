# Failed-delivery recovery audit - October 23, 2026

Audit and plan only. No application fix has been implemented. The current workflow does not fully meet the requested end-to-end recovery contract.

## Baseline and method

- Checkout: `.worktrees/shop-owner-registration-ocr`, branch `codex/shop-owner-registration-ocr`, HEAD `36f239bf98034abd5d0dc141741a28f9f2158bc7`.
- Initial tracked tree and index were clean. Existing untracked `output/` and `tests/Browser/__pycache__/` were preserved. The dirty original workspace was not used for this audit.
- One agent inspected actual routes, controllers, services, models, migrations, React actions, and tests sequentially. CodeGraph was consulted first; its index reported a different checkout/root context, so findings were checked against current worktree source rather than trusting indexed locations.
- Verification used the configured testing SQLite environment, array mail/session/cache and synthetic factory records. No production database, external notification delivery, live account, or deployment was used. Existing test harnesses initialize their disposable databases; no manual/shared-database reset was performed.
- New executable evidence is confined to `output/failed-delivery-audit/FailedDeliveryAuditProbeTest.php`. Its assertions deliberately reproduce current defects; passing these probes means the defects were observed, not fixed. The two Markdown documents are the only proposed source-controlled deliverables.

## Current workflow versus requested workflow

| Stage | Actual behavior | Required behavior / gap |
| --- | --- | --- |
| Rider reports failure | API validates assignment, allowed reasons, required photo/notes, actor and state; saves attempt, timestamp and originating batch/assignment. | Useful foundation; a later retry under the same retained assignment cannot create another failure (F01). |
| Below attempt limit | Delivery leg becomes `pending`, assignment is canceled, stop leaves the batch and date advances automatically. Pickup timestamp/proofs remain. | Dispatcher decision and physical custody must remain explicit (F02/F03). |
| At attempt limit | Delivery becomes `needs_resolution`, accepted assignment is retained, rider has a custody hold. | Appropriate retention intent, but no custody-transfer workflow and no eligible replacement-rider selection (F02/F03). |
| Dispatcher selects retry | Reason-only dialog/API; backend selects next operating day. Delivery stays `needs_resolution` with `resolution_type=retry`; failed repair pickup returns to `pending`. | Select valid date/window and same/different eligible rider; record customer agreement and decision actor/history. |
| Retry starts | Staged retry has frontend and backend date checks and requires accepted assigned rider. Normal pickup/start and batch start lack equivalent date enforcement. | Shared operational eligibility with a separate, protected customer appointment constraint (F04). |
| Handoff | Initial pickup proof, delivery proof, and two-step return-to-shop receipt exist. | No independent rider-to-rider or shop-mediated transfer ledger; an assignment change cannot prove possession (F02). |
| Completion | Delivery proof review, COD collection prerequisite, shipment/source completion and return receipt/refund paths exist. | Preserve these controls; fix fabricated item disposition and source-specific recovery gaps (F06/F09). |
| Notifications | Supported events create in-app rows after commit with tenant-scoped recipients and real tracking/ERP links. Client polls notifications. | Missing event mappings, over-broad deduplication and delivery durability gaps (F07/F08/F11). |

## Findings

No Critical finding was established. High findings below can lose recovery history, misrepresent possession, bypass an operational prerequisite, or leave a required recovery path unavailable. Medium findings affect accountability, eligibility consistency or notification reliability. Severity is operational impact, not a claim of an external exploit.

### F01 - High: another failure after an authorized retry is discarded

Evidence: `app/Services/Logistics/ShipmentLegService.php:977` returns the first attempt for the same leg/type/assignment even when a new idempotency key was supplied. `resolveRetry` at `:569` retains the assignment; `markInTransit` at `:225` reuses it. The database also enforces that identity in `database/migrations/2026_07_17_000003_harden_failed_delivery_refund_workflow.php:17`. The controller replay path at `app/Http/Controllers/Api/Logistics/ShipmentController.php:592` does not repair the service/schema conflict. Frontend `MyDeliveries.tsx:823` also keys failure request IDs by assignment rather than execution.

Reproduction: max attempts 1 -> failure saved -> dispatcher authorizes retry -> advance beyond due date -> rider starts retry -> submit failure with a fresh UUID. The service returns the original attempt; attempt count remains 1 and leg remains `in_transit`. Audit probe 1 confirms this with the real services and database.

Impact: a real second visit can appear successfully reported without entering history or dispatcher resolution. Repeated failures and escalation counts become unreliable.

Recommendation: give each authorized execution a fresh identity, including same-rider retries. Prefer another existing `DeliveryAssignment` row for the new execution, retaining the parcel custodian separately. Keep per-execution duplicate prevention and request replay; do not simply remove uniqueness. Test original-request replay versus a genuinely new execution through both API failure endpoints and the UI request-key lifecycle.

### F02 - High: early failure releases the assignment, not the parcel; old pickup evidence authorizes another rider

Evidence: `ShipmentLegService.php:1081` resets below-limit delivery failure to `pending`, and `:1096` cancels its assignment without a physical transfer. `AssignmentService.php:92` creates another assignment. `ProofService.php:187` accepts any leg-level pickup proof, without binding it to the receiving assignment/current custodian. `ShipmentLegService.php:114` uses that existence test. There is no release/receipt transfer entity or endpoint among Logistics models, migrations and routes (`routes/web.php:547`). Existing `pickup`, `delivery`, `receive` proofs are endpoint handoffs, not a rider transfer protocol.

Reproduction: rider A has picked up a parcel and an approved pickup proof -> first delivery failure -> A's assignment canceled -> assign/accept rider B -> B calls pickup/start without new proof or transfer. Both succeed, with the single old proof still present. Audit probe 2 confirms this; the date is also still in the future (F04).

Impact: the system can show B operating a parcel still physically held by A. A's canceled assignment disappears from active-custody checks, so A can appear available while still responsible for goods. No reliable transfer dispute trail exists.

Recommendation: preserve current possession on failure. Record transfers independently from offers/assignments, with tenant, leg, releasing custodian, receiving custodian, two authenticated confirmations, timestamps, evidence and replay identity. Require confirmed receipt before the recipient executes; keep source responsibility while receipt is pending. Support A -> B and A -> shop -> B. Same-rider retry needs no invented physical handoff. Never backfill historical assignments as confirmed physical receipts.

### F03 - High: dispatcher cannot choose retry date and replacement rider; early retries bypass resolution

Evidence: `ShipmentController.php:540` accepts only `reason`; `ShipmentLegService.php:608` calls `nextOperatingDate()` (`:1143`) instead of accepting a selected schedule. `resources/js/Pages/ERP/Logistics/Shipments.tsx:394` renders a reason-only retry dialog, and `resources/js/services/logisticsApi.ts:36` sends that contract. `AssignmentService.php:84` rejects `needs_resolution`; `LogisticsActorPolicy.php:42` only allows ordinary assignment from pending/failed. Below-limit delivery failure automatically returns to pending (`ShipmentLegService.php:1085`).

Impact: no complete dispatcher decision -> date -> eligible same/different rider workflow exists. At-limit custody cannot be safely reassigned; below-limit failures are scheduled without the required explicit decision. A future staged retry can hold a rider from unrelated work (`RiderActiveWorkGuard.php:155`) with no shop-receipt release option.

Recommendation: one locked resolution operation for every failed execution, selecting date/window, rider and transfer route if needed. Reuse offers/acceptance, employee policy, module checks and events. Capacity/leave conflicts must be rejected or use the existing justified capacity override convention; do not treat an assignment as a receipt. Record customer appointment agreement as described in F04.

### F04 - High: retry-only date gate is inconsistent; normal movement can violate future appointments

Evidence: `ShipmentLegService.php:233` rejects a staged retry before its scheduled date; `MyDeliveries.tsx:887` disables its button. Ordinary `markPickedUp` (`ShipmentLegService.php:78`), `markOutForDelivery` (`:195`), normal `markInTransit` (`:259`) and `BatchDispatchService.php:435` do not apply that check. The normal UI's batch start (`MyDeliveries.tsx:786`) and pickup/start controls (`:1257`, `:1302`) lack the retry date condition. Audit probe 2 actually starts a below-limit retry before its assigned next-day date.

The current scheduled date also conflates estimates, dispatcher dates and customer arrangements. Repair return creation accepts an explicit requested schedule (`SourceShipmentService.php:375`). `MonitorOverdueDeliveries.php:48` can rewrite overdue pending/assigned dates automatically. No inspected recovery contract records whether that date is a customer-confirmed appointment. The staged retry gate checks date, not its morning/afternoon window.

Impact: users experience apparently arbitrary early-start restrictions, while equivalent paths can start early. Removing the retry guard would also remove the only demonstrated date protection on that path.

Recommendation: centralize start eligibility for normal/retry/batch transitions. Keep customer-confirmed date/window as a lower bound unless an authorized, recorded customer-agreed reschedule supersedes it. Keep estimates distinct from commitments; an estimate alone need not prohibit preparatory work. For the first implementation, gate movement starts against confirmed appointment windows conservatively; any earlier warehouse preparation must be an explicit separate operation. Do not silently advance confirmed appointments in the overdue monitor. Use server shop timezone and existing window settings, not browser clock decisions alone.

### F05 - High: dismissing a pre-pickup incident invents an in-transit state

Evidence: incidents can be reported for an assigned leg (`DeliveryIncidentController.php:32`, `LogisticsActorPolicy.php:81`). `DeliveryIncidentService.php:55` changes any such leg to `needs_resolution`; dismissal at `:85` unconditionally sets `in_transit` when resolution is null. It does not restore the previous state or require pickup/proof.

Reproduction: assigned leg, required pickup proof, no pickup timestamp/proof -> report vehicle issue -> dispatcher dismisses it -> leg is `in_transit`, pickup timestamp remains null, proof count remains zero. Audit probe 4 confirms this.

Recommendation: save the interrupted operational state when reporting; on dismissal restore only that still-valid state under locks. Retry/return/loss must use the central recovery transition. Test incidents before pickup, during transit, during batch execution and against stale/newer decisions. Reporting an incident should retain possession, not create it.

### F06 - High: failed-delivery return receipt fabricates a resellable inspection

Evidence: `ShipmentLegService.php:909` calls `confirmReturnReceived` with every line's disposition hardcoded to `resellable`. Failure reason `item_damaged` is supported (`:33`; `OrderRefundService.php:34`). `OrderRefundService.php:1552` requires per-item inspection but receives fabricated values from this caller; `RefundInventoryDispositionService.php:54` converts those values into a stock return.

Scenario: an online-paid parcel fails because an item is damaged, dispatcher selects return, rider/shop confirm receipt. The automatic caller supplies resellable for every item, so receipt can restore damaged inventory without an actual item inspection. This is a traced production call path, not a new runtime inventory probe.

Recommendation: keep physical receipt separate from item disposition. Use the existing refund inspection service/UI with actual per-line quantities and resellable/damaged decisions from authorized staff. Keep stock/payment changes idempotent and retain existing Finance approval/shipping-fee rules. Do not mark items resellable from a handoff photo or failure reason alone.

### F07 - High: several required lifecycle notifications are never created

Evidence: producers save `pickup_attempt_failed`/`pickup_cancelled` (`ShipmentLegService.php:1110`), `pickup_rescheduled`/`delivery_retry_authorized` (`:608`), `return_to_shop_started` (`:746`) and `return_received` (`:853`); notification mappings at `LogisticsNotificationService.php:243` omit all of them. Normal `out_for_delivery` is emitted by `ShipmentLegService.php:208` but is internal and not mapped. `leg_assigned` notifies the new rider only, not the customer or former rider. Incident events are internal, so ordinary incident report/resolution notifications go to dispatchers, not customers.

Impact: a saved failure/retry/return timeline does not establish that the customer or dispatcher was notified. Repair pickup failure can be silent in the Logistics notification path. Customers cannot reliably follow retry/reassignment/return completion through notifications.

Recommendation: define explicit recipient/channel rules for each semantic occurrence: failure -> customer plus correct-shop resolvers; decision/reassignment -> customer, relevant riders and dispatcher; handoff required/received -> responsible actors; delivery/return completion -> customer plus operational completion recipients. Add safe fixed customer messages without internal investigation notes. Reuse current notification types where accurate and add only missing labels/types. Audit probe 3 confirms missing customer rows for pickup failure, pickup reschedule, retry authorization and return receipt.

### F08 - Medium: deduplication suppresses separate visits and retry progress

Evidence: `LogisticsNotificationService.php:229` groups most events solely by user/shipment/leg/event type. It adds event identity for a few incident/proof/batch events, but not delivery failures or `in_transit`. `LogisticsNotificationDeduplicationTest.php:18` explicitly expects two in-transit events to collapse, without distinguishing separate executions.

Reproduction: two failure events with different attempt numbers on one leg produce one customer notification. Audit probe 3 confirms this. The analogous retry departure is also grouped with the original departure.

Recommendation: key by business occurrence (attempt/execution/transfer/decision), recipient and notification purpose. Replaying the same occurrence must not add another row; another visit must. Paired internal/customer event rows for one occurrence must share its identity to avoid duplicate dispatcher alerts.

### F09 - High: Retail Return has no equivalent failed-pickup/retry workflow

Evidence: `SourceShipmentService.php:184` creates retail refund returns as `purpose=refund_return`, `leg_type=return_to_shop`. `ShipmentLegService.php:995` rejects all failed attempts on return-to-shop legs; pickup failure is restricted to repair pickups at `:1008`, and retry resolution rejects return legs at `:582`. `MyDeliveries.tsx:804` chooses pickup-failure behavior only for repair pickup; its in-transit return branch at `:1326` offers handoff/receipt, not normal failed-visit reporting.

Impact: customer unavailable/item not ready on a retail return pickup cannot follow the same reason/history/date/reassignment recovery contract. Generic incidents cover some exceptional events but are not a substitute for a failed collection attempt. Separate shop-return recovery legs must not be mistaken for customer refund-return pickups.

Recommendation: distinguish customer collection from a parcel already being returned to shop by source/purpose/stage. Extend failed collection resolution to the customer-facing Retail Return stage; retain the existing shop receive-proof protocol for goods already in transit back to shop. Preserve Finance/staff refund approvals before initiating the return, and never refund before the required inspection/approval.

### F10 - Medium: resolution history loses actor and original incident narrative

Evidence: `ShipmentController.php:126` passes a ShopOwner object as assignment actor even when the authorized caller is a dispatcher; `AssignmentService.php:96` stores that object's identity. `resolveRetry`/`requireReturn` accept no acting user and omit `created_by` when recording decision events. `DeliveryEventService.php:27` consequently stores null decision actors. `DeliveryIncidentService.php:83` replaces the original report's `notes` with the resolution note; the incident model (`DeliveryIncident.php:12`) has no resolver field. `confirmReturnReceipt` at `ShipmentLegService.php:835` likewise records ShopOwner identity for the authorized dispatcher path.

Impact: timestamps and reasons exist, but staff cannot reliably establish who authorized a retry/reassignment/receipt, and the rider's original incident explanation is overwritten.

Recommendation: pass the authenticated actor separately from the tenant owner and write append-only decision events with previous/new state, schedule, assignment and custody references. Keep original incident notes; record the resolution narrative separately. Reuse `DeliveryEvent` actor/metadata fields rather than create another general audit subsystem.

### F11 - Medium: notification delivery has no durable retry and concurrent deduplication is not enforced

Evidence: `DeliveryEventService.php:31` invokes notification creation directly after commit. There is no processed/delivery marker, dispatched retry job or replay command in this Logistics event path. A crash/error after the event commits can leave missing notifications; an exception can also escape after business state has already changed. `Notification::firstOrCreate` uses `group_key`, but its migration (`2026_02_19_100256_add_phase6_features_to_notifications_table.php:33`) adds a normal index, not a uniqueness constraint. No later inspected migration adds a unique Logistics occurrence/recipient identity.

Impact: events are durable but notification fan-out is not guaranteed; two workers processing the same occurrence can race into duplicate rows. This is a source-established reliability/concurrency risk, not a reproduced production outage or a measured duplicate rate.

Recommendation: use persisted DeliveryEvents as the source for retryable notification processing, with delivery progress recorded and a recovery sweep for events missed between commit and dispatch. Add a nullable unique Logistics delivery identity without changing unrelated notification grouping semantics. Use fixed occurrence identities from F08. UI delivery is currently polling (`resources/js/hooks/useNotifications.ts:132`, `:163`, `:197`, 10/15 seconds), not instantaneous push; record that timing and test persistence immediately after commit. Do not introduce a new realtime infrastructure solely for this fix.

### F12 - Medium: single assignments and batches use different rider eligibility rules

Evidence: `AssignmentService.php:34` rejects inactive riders but accepts `busy` and does not check the selected date's work days, leave or daily capacity. `AssignShipmentLegRequest.php:24` only validates assignment type and rider existence; controller enforces tenant. In contrast, `BatchDispatchService.php:98` checks availability, work days, rider leave, approved HR leave and capacity. The single-assignment active/availability check also occurs before the rider row is locked (`AssignmentService.php:54`).

Impact: a single retry/normal assignment can be offered to a rider who would be ineligible for a batch on that same day. Starting work still has attendance/active-work controls; this finding does not claim those controls are absent.

Recommendation: reuse the existing employee/rider eligibility rules for both offer paths, rechecking time-sensitive state under the same transaction. Assignment may be planned before the rider clocks in; acceptance/execution still require the existing attendance policy. Do not add an attendance bypass for recovery.

## Shipment and exception coverage

| Source | Existing flow and controls | Recovery coverage |
| --- | --- | --- |
| Retail Delivery (`order`, `retail_delivery`, outbound) | Internal rider proof/arrival, COD prerequisite, proof review and order completion. Third-party carrier remains tracking-only. Paid failed-delivery returns reserve a refund and require shop receive proof. | Both early automatic and at-limit staged retry paths exist; F01-F08/F10-F12 affect recovery. |
| Retail Return (`order_refund`, `refund_return`, return_to_shop) | Staff/Finance approval gates source creation; customer collection and shop receipt use existing proof workflow. | Normal return supported, failed collection resolution incomplete (F09). Do not apply the outbound failed-delivery refund twice. |
| Repair Pickup (`repair_request`, `repair_pickup`, inbound) | Before handoff, pickup-specific reasons/photo/arrival and configured attempt limit. Nonterminal failure enters resolution with assignment canceled; dispatcher retry returns to pending. Terminal pickup cancellation invokes repair recovery/compensation/replanning. | No parcel transfer is needed when collection never happened. Pickup notifications, selectable schedule and unified eligibility/history are incomplete. Preserve paid/sponsored recovery and new-plan requirements after terminal cancellation. |
| Repair Return (`repair_request`, `repair_return`, outbound) | Delivery proof review activates existing repair handoff/settlement. Return-to-shop recovery resets shipped repair to ready-for-pickup and asks for arrangement. | Shares delivery retry/custody defects. Keep repair settlement distinct from retail COD; do not create an OrderRefund for a repair by source-ID coincidence. |

Lost/damaged/vehicle/customer-dispute incidents require validated private evidence, same-shop assignment and resolver capability. Confirmed loss requires a lost incident plus investigation note/evidence; its existing retail refund/COD reconciliation must be retained. Dismissal and narrative/history defects are F05/F10. The current data has `responsible_party` but the inspected resolution API does not capture it; liability decisions should be recorded explicitly when relevant, never inferred from the new assigned rider.

## Safeguards verified and boundaries to preserve

- RBAC/tenant checks are real: `LogisticsActorPolicy.php:121`, `:204`, `:281`; assignment queries are shop-scoped; customer tracking and proof reads check source ownership (`CustomerTrackingController.php:20`). Owner read access does not authorize dispatcher/rider mutations.
- Employee suspension and clock-in middleware apply to web/API mutations (`bootstrap/app.php:144`; `EnsureEmployeeClockedIn.php:39`). User/rider identities and current assignments are checked. Absence of attendance checks inside every service is not itself evidence of an HTTP bypass.
- Active batch/standalone and current-stop ordering are guarded (`RiderActiveWorkGuard.php:45`). Failed stops detach from live batches, final open-stop removal reconciles batch state, and snapshots preserve offered/historical membership. Draft composition rejects duplicates, cross-shop/source-module mixtures and ineligible statuses.
- Source/return/request identities and database uniqueness protect against duplicate shipments/return legs (`2026_10_02_000001_allow_historical_shipment_attempts.php:16`, failed-delivery migration `:28`). Existing tests exercise replay and repair intake concurrency. No duplicate/ghost shipment was established by this audit; physical custody corruption is a separate finding.
- COD cash records bind the collecting user/profile and leg, require exact amount and eligible state (`CodCollectionService.php:89`, `:270`). Delivery/proof cannot finish without collection (`:248`). Package reassignment must not overwrite the original collector or transfer cash responsibility.
- Remittance validates shop, exact owner of each collection, amount/status, unique inclusion and idempotency (`CodRemittanceService.php:32`); Finance confirms/disputes and supplements shortages, with settlement through existing invoice/payment services (`:267`, `:370`, `:505`). No duplicate collection/remittance or automatic cash reassignment was demonstrated in the selected tests.
- Logistics ON/OFF and third-party boundaries are centralized (`LogisticsMovementEligibility.php:18`, `:85`): new movement requires ON; supported work already in custody can continue/close. Retry explicitly requires ON. Preserve this distinction in transfer/resolution changes instead of enabling new dispatch when OFF.
- Overdue monitoring and incident escalation already exist. Reuse them; do not introduce automatic customer refunds, automatic liability or automatic cancellation as a consequence of a failed retry.

Concurrency limitation: SQLite tests are not proof of InnoDB lock safety. Current methods acquire locks in different orders (assignment: shop -> rider -> leg; failure: assignment -> batch -> leg -> rider; return: leg -> shop; batch start: shop -> batch -> rider -> legs). A deadlock was not reproduced. The implementation plan requires a consistent lock order for changed transitions and real multi-connection database tests before claiming production concurrency coverage.

## Verification performed

All results below are fresh audit runs, not copied OCR evidence. Logs remain in existing untracked `output/`.

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Logistics --stop-on-failure` | Exit 1: 307 passed, 1 failed, 1 skipped; 199 pending after stop; 1,811 assertions; 151.78s. |
| `php artisan test tests/Feature/Logistics tests/Feature/Cod tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Repair/RepairSponsoredDeliveryPlanReplayTest.php` | Exit 1: 580 passed, 1 failed, 1 skipped; 3,125 assertions; 189.05s. All selected suites were allowed to finish. |
| `php artisan test output/failed-delivery-audit/FailedDeliveryAuditProbeTest.php` | Exit 0: 4 observation probes / 12 assertions, 8.31s. Confirms F01, F02/early start, F07/F08, F05. These passing defect observations are not green acceptance tests. |
| `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx resources/js/components/logistics/__tests__/ShipmentTrackingModal.test.tsx` | Exit 0: 12 files / 224 tests passed, 43.79s. Existing React act warnings and missing CARTO-key warning remain. Initial sandboxed cache invocation failed ENOTCACHED; authorized local cache access ran the command successfully. No project dependencies were added/updated. |

The one Laravel failure is `LogisticsPageAccessTest::test_missing_and_cross_shop_orders_use_safe_logistics_fallbacks`, `tests/Feature/Logistics/LogisticsPageAccessTest.php:1427`. Its strict expected array omits the two null keys `delivery_method` and `carrier_company` that the current serializer includes (`ErpLogisticsController.php:1386`). This is a reproduced baseline expectation mismatch, not demonstrated cross-tenant disclosure. The assertion stops before its remaining checks, so those remaining assertions are not claimed passed. Recommend updating only the expected allowlisted shape after confirming intended props during implementation; no validation should be relaxed.

The skipped test requires GD to verify sanitized approved proof output (`CustomerTrackingTest.php:470`). Its no-GD authorized fallback test passed. No browser run, build, type checker, linter, full repository suite, external mail/push delivery, mobile measurement, production cron/queue check or production concurrency test was performed. These were not necessary to write the audit; relevant implementation acceptance gates remain in the plan.

## Review record and handoff

| Sequential gate | Audit result |
| --- | --- |
| Simplify / reuse | Recommendations reuse assignments, events, proof/receipt, attendance, HR, refund and COD services. No new dispatch framework, payment ledger or realtime platform proposed. |
| Standards -> expected workflow -> correctness risks | Findings checked against current source and callers; test expectations distinguished from required behavior. Proposed changes remain unexecuted. |
| Changed TS/React quality and splitting | N/A: no TS/TSX or bundle behavior changed. |
| Karpathy / measured evidence | Four isolated observations establish specific defects; no performance/fraud/accuracy claim. Other source findings and untested risks are labeled. |
| Security / Laravel | Reviewed tenant identity, capability, attendance, private evidence, state/replay, lock, COD/refund and ON/OFF boundaries. Fixes must preserve these. |
| Verification / dead code | Fresh test outputs recorded with failure/skip. No production code removed or written; no dead-code cleanup required. Documentation/link/diff checks recorded in final handoff. |

Recommended order: fix execution identity and incident state; introduce accountable custody transfers; wire explicit dispatcher recovery and shared schedule eligibility; repair notifications/history; complete source-specific return/inspection behavior; verify the entire workflow. Exact paths, acceptance cases and future commands are in [the approval-ready plan](../superpowers/plans/2026-10-23-failed-delivery-recovery.md).

No commit, push, merge, deployment, environment edit or production migration was performed. Implementation requires the user's approval of the separate plan. No general learning-log edit is needed; these findings are task-specific.

Final hygiene: `git diff --check` produced no findings; tracked diff and staged-path lists remained empty. Both new Markdown files passed `git diff --no-index --check -- NUL <path>` with no whitespace output (exit 1 denotes the new-file difference). Read-only assertions checked relative links, balanced fences, all twelve finding IDs, seven ordered phases and four shipment-purpose coverage. Final HEAD remained the audit baseline. Existing untracked directories were retained; nothing was staged.
