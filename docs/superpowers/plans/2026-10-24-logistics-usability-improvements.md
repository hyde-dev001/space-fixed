# Logistics recovery usability improvement plan — pending approval

Based on the [October 24 UI/UX audit](../../operations/2026-10-24-logistics-usability-audit.md). Target: `.worktrees/failed-delivery-recovery`, branch `codex/failed-delivery-recovery`. **Proposal only: do not execute until approved.** Preserve the current uncommitted implementation, prior approval records, unrelated worktrees, existing build and OCR runtime.

Goal: make the next authorized operational action clear, usable by keyboard and on phones, without changing custody, appointments, permissions or financial authority. Execute sequentially in one main agent. No new logistics engine, dependency or schema migration is proposed.

## Phase 1 — Restore essential task access

- [ ] Reproduce U01 with a failing HTTP/projection/UI regression: dispatcher authorizes an outbound retry; a fresh assignment must appear as an offer and require acceptance before Start retry. Cover same/new rider, repeated visits and stale assignment. Keep failed-pickup behavior and original proof/custody history.
- [ ] Correct only the existing standalone offer projection/action mapping in `ErpLogisticsController.php`, `MyDeliveries.tsx` and nearby tests as required by that regression. Keep the backend acceptance guard. Do not auto-accept or reset pickup/stage to work around UI state.
- [ ] Give `NotificationList.tsx` a real descriptive destination link, independent of read/archive controls; test Tab/Enter, URL resolution and recipient authorization (U04).
- [ ] Reuse established accessible dialog behavior for Finance refund details/execution: name, focus entry/containment/return, safe Escape, inert background, comfortable close controls and error announcement (U03). Preserve pending-submit guards and separate approval/execution.
- [ ] Reuse the existing compact Logistics card/row pattern for Staff Job Orders below the desktop breakpoint; retain a readable desktop table. Keep order/item/quantity, disposition state and named action together (U02).

Checkpoint: an ordinary rider can accept a retry and reach its legitimate readiness gate; Staff can identify a mobile return row; a keyboard user can open their notice and complete the Finance dialog without reaching the obscured page. Narrow checks: existing/new Logistics projection/My Deliveries tests, Staff return-action tests, Finance refund tests and notification tests; real authenticated desktop/mobile keyboard walkthrough.

## Phase 2 — Make recovery and custody status actionable

- [ ] Replace contradictory Ready to start / No action needed / generic wait text with an actor-specific display of assignment, hold, handoff, appointment and module blockers. Show the current blocker beside the disabled button (U06).
- [ ] Correct the confirmed stale-screen behavior in U14 using the existing reload mechanism plus a clear last-refreshed/refresh action; add bounded visible-page refresh where it prevents the observed stalled cross-actor workflow. Never discard a draft/evidence selection on background refresh.
- [ ] Add a customer-safe recovery summary at the top of tracking, ahead of movement/ETA. Use existing incident/transfer data and allowlisted projections; keep canonical shipment states, source events and live-location authorization unchanged (U05).
- [ ] Enrich existing handoff cards with authorized shipment/order/repair reference, concise parcel context and explicit actor/stage. Link to existing details. Keep release/receipt separate for every parcel (U07).
- [ ] Preserve safe server field errors on the affected handoff card; reuse the current evidence uploader with preview/replace, limits and mobile capture choice. Announce confirmed receipt/release after a card disappears (U08).
- [ ] Make customer notification action flags audience-specific; retain internal custody events/actor alerts, reduce internal-operation noise in customer presentation, and provide explicit customer next steps (U09). Preserve occurrence deduplication and notification retry.

Checkpoint: report → dispatch decision → acceptance → release/receipt → start works across separately open tabs, including partial handoffs, invalid images, stale revisions, appointment denial/reschedule and Logistics OFF/ON. Customers see delay/arrangement/continuation accurately without receiving another actor's task. Narrow checks: recovery/batch/custody/notification suites, My Deliveries/tracking/dialog tests and authenticated direct/via-shop journeys.

## Phase 3 — Make Staff and dispatcher decisions legible

- [ ] Keep receipt distinct from inspection. Show clear resellable→restock and damaged→write-off consequences before an action-specific confirmation. Do not default to resellable or fabricate a condition to advance a refund.
- [ ] Reuse the existing date-picker/rider context patterns in retry and batch recovery. Show current holder versus proposed assignee, existing eligibility/capacity context, no-rider guidance and configured window hours. Preserve explicit customer agreement and server validation (U10).
- [ ] Separate COD loading/error/loaded-empty states; expose an actual Retry read action (U11). Keep prior totals clearly stale on failure and disable any action lacking current authoritative eligibility.
- [ ] Collapse secondary notification tools on compact layouts; retain filters/export/bulk actions (U12). Correct the customer-shell content offset on tracking/notifications without changing navigation policy (U15). Apply only the small neutral-control/label consistency changes in U13; keep semantic colors and existing identity.

Checkpoint: Staff identifies the correct return and records both conditions at 360/390/768/1024/1440 widths; Finance never mistakes a failed ledger read for no obligation; dispatcher understands the proposed rider/date/handoff before saving. Narrow checks: Staff return-action, Finance COD/refund, Batches/retry-dialog and notification tests, followed by the synthetic Staff/Finance browser journey.

## Phase 4 — Verify and hand off for review

- [ ] Run focused failing regressions before each coherent change; widen to affected Logistics, COD, refund/inspection, notification, MFA and frontend suites after they pass. Do not weaken attendance, authorization or financial tests to accommodate UI changes.
- [ ] Repeat authenticated browser journeys with synthetic data: all four shipment purposes, standalone retry acceptance, same/different rider, direct/via-shop transfers, partial receipt, repeated incidents, appointments, OFF/ON, refresh and double-click/error recovery. Test real clicks; do not substitute an API acceptance for a claimed UI pass.
- [ ] Exercise keyboard-only notification navigation and modal flows; sample screen-reader announcements and actual phone/file capture when devices are available. Check visible focus, compact target sizes, reflow and contrast in the changed states. Report unavailable devices explicitly.
- [ ] If authoritative mutation logic changes, rerun the relevant disposable MariaDB concurrency races. Display-only changes do not justify inventing a new locking system.
- [ ] Run the existing frontend build, verify manifest/imports and OCR asset integrity after approved source changes; inspect private proof links and cross-actor/tenant denials. Do not deploy or run production migrations.
- [ ] Perform sequential Standards, Spec, correctness/security, reuse, dead-code and fresh-verification review. Update the manual guide with the next page/action, screenshots, exact results and remaining limits. Keep the audit as historical evidence.

No commits, push, rebase, merge, deployment or production migrations are authorized by this plan. The separate repair-refund screenshot privacy correction requires its own approved scope; changing its upload label is not a storage fix. Production sign-off also depends on the existing Hostinger release checks.


## Approved execution - October 24, 2026

The user approved Phases 1-4 after this proposal. Execution stays sequential in the existing feature worktree. The original checklist above remains the approval contract; actual changes, regressions, screenshots and limitations are recorded in [the usability execution report](../../operations/2026-10-24-logistics-usability-execution.md).

- [x] Phase 1: offer acceptance, Staff compact cards, notification links, accessible Finance dialogs.
- [x] Phase 2: recovery status, custody context/errors and bounded cross-actor refresh.
- [x] Phase 3: date/rider context, inspection copy, COD read states and compact notification tools.
- [x] Phase 4: affected regressions, authenticated boundary/keyboard/mobile checks, InnoDB races, fresh build, OCR integrity, sequential review and preservation record completed within the execution report's explicit limits.
