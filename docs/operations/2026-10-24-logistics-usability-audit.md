# Logistics recovery UI/UX audit — October 24, 2026

Worktree: `.worktrees/failed-delivery-recovery`; branch: `codex/failed-delivery-recovery`; HEAD: `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5`.

**Audit and approval plan only. No application changes.** The earlier functional release checks remain historical evidence; they do not establish usability or complete keyboard journeys. This audit uses current worktree source and new authenticated Chromium walkthroughs. CodeGraph was consulted first, but its index described the root checkout, so current worktree files were authoritative.

**Assessment:** no end-to-end usability sign-off. The standalone outbound retry misses its acceptance step, and operational feedback, small-screen Staff inspection and keyboard access also need correction. The architecture and tested batch journeys remain useful foundations. No new security bypass is demonstrated by this audit. The previously reported public payout-screenshot storage remains a separate production blocker.

## Method and actual coverage

Existing UI authority: the Logistics mobile/tablet and Batches specifications dated August 20, the create-and-offer specification, existing ERP component conventions, and the requested monochrome identity. Semantic warning/error/success colors are appropriate; color alone is not treated as a defect.

Fresh local walkthroughs use Chromium **151.0.7922.34**, the guarded HTTP kernel on `127.0.0.1:8027`, worktree Vite, and the existing disposable MariaDB schema on port 33317. The guard verifies the actual database data directory, isolates storage/sessions, and prevents outbound payment HTTP. Fresh employees sign in with password, mandatory TOTP and normal Clock In; later read-only probes reauthenticate the same synthetic employees with their existing attendance. Customers use their own normal password login. There is no session injection or authentication bypass.

Screens are inspected at **1440×1000**, **768×1024**, and **390×844**; targeted customer/Staff checks also use **360×844** and **1024×1024**. These are browser viewport checks, not physical phone or Safari tests. Screenshots contain synthetic records only. Full-page captures show content beyond the viewport; the area below a fixed overlay in a full-page screenshot is not itself an overlay-sizing defect. Clean top-of-page captures were added to avoid confusing retained scroll position with a layout bug.

| Workflow | Fresh observation | Practical limit |
| --- | --- | --- |
| Dispatcher and riders, direct takeover | Report batch interruption, choose replacement, accept batch, release and receive each of two parcels, start and complete Retail Return, dispatcher receipt. | Paid/source/previous-pickup states are explicit synthetic fixture preconditions. |
| Dispatcher, riders and customer, shop-mediated takeover | Retail Delivery interruption, delay notice/tracking, replacement acceptance, two evidenced transfers per parcel through the shop, continuation and delivery confirmation. | Customer notification navigation was tested separately; internal customer-facing handoff events are noisy, not duplicate writes. |
| Standalone retries | Retail Delivery, Repair Pickup and Repair Return: arrival, failed visit with evidence, dispatcher schedule/rider decision, customer tracking and retry screen. | Retry authorization is not a claim that a new outbound retry can be accepted/started through this UI; see findings. |
| Staff inspection | Job Orders → Refund → exact returned item → disposition → confirmation; resellable and damaged specimens. | Stock verified as 5→6 versus 5→5; receipt did not fabricate inspection. |
| Finance | Refund Approvals → details → approval → manual payout with synthetic reference/evidence; rider COD submission → separate Finance cash confirmation. | A local manual payout record is not a real transfer/provider settlement. Staff approval and premature execution remain denied. |
| Keyboard and feedback | Recovery/retry Tab sequences, Finance Tab/Escape, notification destination click/DOM semantics, invalid handoff image, controlled unavailable COD read. | DOM/keyboard checks are not a complete screen-reader or WCAG certification. |

Evidence lives in ignored `output/failed-delivery-recovery/ux-audit/`: screenshots, per-screen text/metadata, `finance-browser-results.json`, `probes.json`, `handoff-validation.json`, and the output-local runners. Reports contain no production identities, credentials or OTPs. Fixture files and test-session material remain ignored and must not be published.

Where a Logistics component path below starts with `components/`, its exact directory is `resources/js/Pages/ERP/Logistics/components/`.

The first standalone walkthrough stopped because its harness omitted required arrival; another expected 200 where the successful failed-attempt endpoint returns 201. A pending-response diagnostic using request refetch also changed multipart handling and was discarded. These are harness failures, not application defects or successful acceptance runs. Corrected observations retain the normal arrival and original multipart request.

## Assessment by role

- **Dispatcher:** the separate retry and batch dialogs explain assignment versus custody and preserve reason/appointment fields. Recovery is discoverable inside batch details, including notification focus URLs. Rider choices give little operational context, and handoff work is detached from the decision summary.
- **Rider:** large primary controls, a prominent handoff region, parcel counts and explicit release/receipt are helpful. The screen can nevertheless claim readiness or no action while work is blocked, and acceptance/refresh behavior needs attention. New evidence inputs are less helpful than the existing proof uploader.
- **Customer:** notices and the tracking destination work by pointer. Delay information exists, but canonical lifecycle labels, technical event names, internal handoff notices and unexplained action badges obscure the actual next step.
- **Staff:** disposition choices explicitly name restock versus write-off, and inspection is separate from receipt. The mobile list does not support safe identification of the row to inspect.
- **Finance:** refund details identify the next approval stage; COD copy clearly distinguishes cash submission, physical receipt and shortage. Existing refund overlays are visually usable but lack keyboard-dialog behavior. Their public screenshot storage is outside this UI-only plan.

## Findings, severity and expected behavior

Severity describes the observed user impact, not a claim of financial compromise. **High** blocks a core task/access method or materially misleads an operational decision; **Medium** causes avoidable errors, uncertainty or recovery work; **Low** is secondary friction/consistency. No new Critical issue was confirmed.

**15 findings: six High, seven Medium, two Low.** Start with retry acceptance, accessible essential actions, readable Staff rows, and truthful recovery/update states. Existing Finance accessibility, Staff layout and customer-shell issues are explicitly separated from the new recovery integration defects.

### U01 — High · Functional: outbound retry has no acceptance step

`app/Http/Controllers/Logistics/ErpLogisticsController.php:580` only projects an unaccepted standalone assignment as an offer when its leg is `assigned` or `pickup_scheduled`. An outbound retry deliberately stays `needs_resolution`, so it becomes current work. `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx:895` renders Start retry; `app/Services/Logistics/ShipmentLegService.php:259` requires the new assignment to be accepted. This is distinct from the batch acceptance flow and failed-pickup rescheduling, which resets to `assigned`.

The actual same-rider Retail Delivery screen has an enabled Start retry and no acceptance action. Clicking it and confirming sends the real in-transit request and receives **403**, with no visible explanation. The response's empty `message` is retained by the nullish-coalescing fallback in `MyDeliveries.tsx:2354`; the resulting empty error is not rendered. [Enabled but unusable retry](../../output/failed-delivery-recovery/ux-audit/retry-no-accept-390.png); `retry-blocker.json` records the response. An earlier diagnostic assumed a Swal/alert would appear and stopped at that assertion; its absence is part of this confirmed feedback defect.

Expected: show the existing Accept/Decline offer for the current unaccepted retry assignment, then show the actual custody/appointment readiness. Keep backend acceptance mandatory. Do not auto-accept, fake pickup, or change possession to make the button work. Add both same- and replacement-rider cases before changing the projection.

### U02 — High · Usability: Staff mobile return rows collide

`resources/js/Pages/ERP/STAFF/JobOrders.tsx:2221` forces ten percentage-width columns into a `table-fixed` layout. At 360/390 pixels, headings, quantities, status and refund/ETA values overlap; identity is truncated and actions are pushed to the edge. This is existing Staff UI reached by the new recovery workflow, not a new custody defect. [Confirmed mobile screenshot](../../output/failed-delivery-recovery/ux-audit/staff-table-clean-390.png).

Expected: reuse the Logistics compact row/card pattern below the desktop breakpoint. Keep order reference, item, returned quantity, inspection state and a named inspection action together. Preserve full details and desktop table; do not remove disposition or permission gates. Tablet widths must remain readable too.

### U03 — High · Accessibility: Finance overlays do not behave as dialogs

`resources/js/Pages/ERP/Finance/refundApproval.tsx:1735` and `:1980` build fixed overlays without dialog semantics, focus containment, initial-focus handling or Escape handling. Actual Tab reaches background View Details/navigation controls while the overlay is visible; Escape leaves refund details open. The mobile close targets measure **20×20 pixels**. [Details](../../output/failed-delivery-recovery/ux-audit/finance-modal-clean-390.png); `finance-final-focus.json` and `probes.json` record the keyboard result.

Expected: reuse the established accessible-dialog behavior: named modal, initial focus, inert background/contained Tab, Escape when safe, return focus, and comfortable close targets. Keep Escape/close blocked while a financial mutation is pending. Do not alter financial permissions or combine approval and execution.

### U04 — High · Accessibility: notification destinations are pointer-only

`resources/js/Pages/Notifications/NotificationList.tsx:752` navigates from an `onClick` on a plain `div`. Observed cards have no link, role or tab stop. Keyboard-accessible read/delete controls do not provide the delivery destination. Clicking the delay title does correctly open the customer's own `/tracking/shipments/459` in the synthetic journey; this is an access-method defect, not a broken stored URL.

Expected: a real descriptive tracking/batch link reachable by Tab and Enter, with read/archive controls separate. Preserve URL resolution and authorization. Do not turn the whole card into a button containing other buttons.

### U05 — High · Status clarity: held deliveries still look in transit

`app/Services/Logistics/CustomerTrackingService.php:90`/`:116` exposes lifecycle status; `resources/js/components/logistics/ShipmentTrackingPanel.tsx:441`, `:550`, `:612` display Active, In Transit and the estimated date while a batch is held. The actual delay explanation appears further down in Updates. A new arrangement also leaves these movement labels visible before the required transfers finish. [Held tracking](../../output/failed-delivery-recovery/ux-audit/customer-batch-held-390.png); [awaiting handoffs](../../output/failed-delivery-recovery/ux-audit/customer-reassigned-waiting-390.png).

Expected: a privacy-safe, derived customer recovery summary above movement/map/ETA: delayed, shop arranging recovery, awaiting parcel transfer, or continuing. Clearly distinguish an estimate from a confirmed appointment. Keep canonical shipment stages and immutable events unchanged; never announce movement merely because assignment changed.

### U06 — Medium · Rider instructions contradict readiness

`resources/js/Pages/ERP/Logistics/riderDeliveryPresentation.ts:107` labels all accepted batches Ready to start, while `MyDeliveries.tsx:786` correctly disables start for incomplete handoffs. The original holder also sees No rider action needed right now (`:1741`) while their release cards remain actionable above. `components/BatchInterruptionDialog.tsx:30` tells both parties to wait for dispatch even after dispatch has authorized recovery. [Replacement](../../output/failed-delivery-recovery/ux-audit/replacement-awaiting-handoffs-390.png); [original holder](../../output/failed-delivery-recovery/ux-audit/parcel-release-390.png).

The same-rider before-first-stop probe also leaves Start batch enabled after Logistics is switched OFF. The actual start request is denied **403**, so this is misleading affordance/silent feedback rather than proof of a module bypass (`off-start-result.json`). Expected: derive one actor-specific next step from current assignment, interruption, custody, appointment and module state. Show Awaiting release from A / Your receipt needed / Waiting for remaining parcels, with a link to the relevant existing handoff. Render backend `start_blocked_reason` beside the batch button, not just in its disabled condition. Explain Logistics OFF before asking for a start confirmation; normalize empty server messages to a useful safe fallback.

### U07 — Medium · Parcel reconciliation is too weak

`app/Services/Logistics/ShipmentCustodyService.php:226` projects only Delivery #… and participant labels; `components/CustodyHandoffs.tsx:40` presents otherwise similar cards. Two parcels require two separate uploads, but neither card supplies the source order/repair reference or a way to inspect the parcel context. [Two release cards](../../output/failed-delivery-recovery/ux-audit/release-clean-390.png).

Expected: include an authorized shipment/source reference, concise item/quantity context where already available, route stage and clear from/to participant. Link to existing allowed details. Keep independent release and receipt for every parcel; do not offer “confirm all” or reveal other recipients' details.

### U08 — Medium · Handoff errors hide the required correction

`components/CustodyHandoffs.tsx:29` discards the actual server response and shows a section-wide refresh instruction. A text file named `.png` produces a genuine 422 “photo must be an image,” but the UI tells the rider to check assignment. [Actual error](../../output/failed-delivery-recovery/ux-audit/handoff-validation-error-390.png); `handoff-validation.json` records the safe validation result. Native file inputs also lack the preview/replace guidance of `DeliveryPhotoUpload` already used on My Deliveries.

Expected: show the safe field error on that parcel; preserve the selected context; focus/announce the error and offer the relevant correction. Reuse the existing photo-input pattern with accepted formats, applicable size limit, preview/replace and an appropriate mobile capture choice. After success, announce the resulting state when the card disappears. Keep server validation/private evidence unchanged.

### U09 — Medium · Customer notices demand actions the customer cannot take

`app/Services/Logistics/ShipmentCustodyService.php:248` publishes individual handoff events to customers. `LogisticsNotificationService.php:90`/`:247` applies actor-neutral requires-action flags; `NotificationList.tsx:829` renders Action Required. One affected shipment's shop-mediated journey produced ten distinct notices, including two Package Handoff Required notices. These are legitimate separate occurrences, not duplicate-delivery evidence. [Customer notice](../../output/failed-delivery-recovery/ux-audit/customer-delay-notices-390.png); `customer-completion-notices.txt` records the sequence.

Expected: keep internal custody history and responsible-actor alerts; give customers concise delay/confirmed arrangement/continuation/completion updates. Set Action Required only when that recipient has a named authorized task; otherwise say No action needed / The shop will update you. Use customer language instead of Custody Transfer Requested or Batch Interruption Rescheduled headings (`ShipmentTrackingPanel.tsx:648`).

### U10 — Medium · Dispatcher decisions lack selection context

`components/RetryResolutionDialog.tsx:17` defaults to today/morning with no rider selected; `:71` and `components/BatchInterruptionDialog.tsx:120` show rider names and Morning/Afternoon without the configured hours or availability explanation. Both dialogs rely on the server to reject an ineligible choice. [Retry](../../output/failed-delivery-recovery/ux-audit/retail_delivery-retry-dialog-390.png); [batch recovery](../../output/failed-delivery-recovery/ux-audit/batch-recovery-dialog-390.png).

Expected: identify the current holder separately from the proposed rider, surface existing eligibility/capacity information and empty-list guidance, and show configured window hours/timezone. Make Internal planning versus Customer-confirmed appointment explicit without dropping required agreement notes. Reuse the established date picker/labels; retain authoritative backend eligibility. This is a context/reuse improvement, not proof that the server accepted an ineligible rider.

### U11 — Medium · COD read failure also reports an empty ledger

`resources/js/Pages/ERP/Finance/CodRemittances.tsx:47` sets an error and ends loading; `:131` independently renders an empty state. A controlled 503 GET shows both Could not be loaded and No COD remittances have been submitted. [Confirmed error state](../../output/failed-delivery-recovery/ux-audit/finance-read-error-390.png).

Expected: distinct loading, failed, loaded-empty and loaded-data states, with an explicit Retry read action. Never imply no cash obligation when the ledger could not be fetched. This probe intercepted only a read response; no cash state was fabricated.

### U12 — Low · Secondary controls overwhelm the mobile notification task

`NotificationList.tsx:39` opens Advanced Filters by default. At 390×844 the first delivery notice is below the initial viewport, behind export/bulk/archive controls and six filters. [Initial phone viewport](../../output/failed-delivery-recovery/ux-audit/notification-top-viewport.png).

Expected: start with current notices, search and a compact unread filter. Collapse advanced/bulk tools on compact screens while preserving their availability and state. Add a clear tracking link rather than making users infer that the text is clickable.

### U13 — Low · Reuse and monochrome consistency need a narrow pass

New recovery forms use native file/date inputs while the rider proof/date flows have established components. Finance's evidence chooser retains a purple primary file control (`refundApproval.tsx:2056`), batch details use blue metadata/status accents (`components/BatchDetailsModal.tsx:56`), and Staff's inspection confirm remains generically Yes, confirm (`JobOrders.tsx:1612`). [Evidence input](../../output/failed-delivery-recovery/ux-audit/finance-execution-390.png); [inspection](../../output/failed-delivery-recovery/ux-audit/staff-inspection-resellable-390.png).

Expected: reuse existing neutral primary/focus/input patterns and action-specific labels, such as Save inspection and Record payout. Keep semantic warning/error/success cues and existing spacing/type hierarchy. Do not introduce a redesign, new dependency, animation or new design-token system.

### U14 — High · Cross-actor workflow: waiting screens do not refresh themselves

`resources/js/Pages/ERP/Logistics/MyDeliveries.tsx:1749` promises This page will update, but `:2314` reloads delivery/custody props only after this tab's action or manual Refresh deliveries. There is no delivery-state polling/subscription. After the dispatcher successfully resumes the same rider's accepted batch, that rider's open tab still says Batch interrupted after **12 seconds**; clicking Refresh deliveries exposes Start batch. Notifications/GPS polling does not refresh these work props. [Stale tab](../../output/failed-delivery-recovery/ux-audit/same-rider-stale-390.png); `pending-result.json` records the comparison.

Expected: an honest and reliable cross-actor update path. Reuse the reload helper on an appropriate visible-page cadence/event, preserving unfinished input, or explicitly tell users when to refresh. Refresh must reveal new release/receipt work as well as batch state; do not infer authorization from the notification itself. The backend hold remains mandatory for stale starts.

### U15 — Medium · Customer mobile navigation overlaps the shipment reference

`resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx:15` places the main content after the promo bar with only vertical padding, while `resources/js/Pages/UserSide/Shared/Navigation.tsx:755` fixes the navigation over it. At 360/390 pixels, the shipment reference occupies the navigation's area. The notifications Back control has the same crowding. [Clean top-of-page capture](../../output/failed-delivery-recovery/ux-audit/customer-top-clean-360.png); [notification viewport](../../output/failed-delivery-recovery/ux-audit/notification-top-viewport.png).

Expected: reuse the established customer-shell content offset for the actual promo/navigation height, including when the promo disappears. Keep the reference/back action below navigation at phone/tablet widths, with no page-wide horizontal overflow. Do not change global navigation behavior merely to patch one page.

## Simplification and approval plan

Prioritize the functional retry acceptance check and accessible essential actions, then clear next-step/status feedback and mobile row identity. Reuse current source references, eligibility results, private viewers, photo input, date picker, feedback and dialog patterns. A shared display-only recovery summary can remove contradictory copy; it must not become a second logistics state machine. No schema change is presently justified by these presentation findings.

The [prioritized improvement plan](../superpowers/plans/2026-10-24-logistics-usability-improvements.md) is a proposal. Approval is required before application changes. The required actor confirmations, per-parcel evidence, appointments, Clock In, MFA/RBAC, tenant boundaries, COD collector responsibility, inspection and financial approvals remain mandatory.

## Boundaries and review record

This audit does not certify screen-reader output, all contrast pairs, dark mode, physical phone cameras/keyboards, Safari/Firefox, very large queues, real-world field usability, provider payouts, production performance, or Hostinger behavior. Appointment fields/guards and repeated-incident UI were inspected in source; new timed-appointment and repeated-incident browser journeys were not run. No production data or hosting setting was touched. Existing proof access controls were preserved rather than reimplemented. The known public repair-refund payout screenshots (`app/Http/Controllers/Api/RepairRefundWorkflowController.php:512`, rendered by `refundApproval.tsx:2078`) remain a separate release privacy blocker; changing the upload label does not resolve their storage/read authorization.

Sequential review: source/Standards and requested workflow comparison completed; minimum/reuse review favors existing controls; security review separates UI findings from authoritative gates and the existing privacy blocker. TS/code-splitting review is N/A because no frontend code changed. Improvement claims are observations, not measured speed or task-time gains. Tests/build/migrations were not rerun for documentation-only work; authenticated audit scripts and their actual results are the fresh evidence.

### Executed audit commands and outcomes

Run from the feature worktree; all Python runners below are ignored, output-local diagnostics, not application/test-suite source changes:

```powershell
python output/failed-delivery-recovery/ux-audit/finance_audit.py
python output/failed-delivery-recovery/ux-audit/customer_audit.py
python output/failed-delivery-recovery/ux-audit/single_audit.py
python output/failed-delivery-recovery/ux-audit/probes.py
python output/failed-delivery-recovery/ux-audit/pending.py
python output/failed-delivery-recovery/ux-audit/retry_blocker.py
python output/failed-delivery-recovery/ux-audit/off_probe.py
python output/failed-delivery-recovery/ux-audit/contrast.py
php -d extension=gd output/failed-delivery-recovery/ux-audit/verify.php
```

- Finance runner: **exit 0**; direct takeover/return receipt, actual Staff dispositions, approval/manual synthetic payout, COD settlement, and negative Staff/premature-execution requests. Persisted checks confirm restock 6 versus damaged stock 5 and original COD collector responsibility.
- Combined customer runner: the **batch segment completed**, including actual shop-mediated handoffs and delivery confirmation; the overall runner **exited 1** at the next standalone case's missing-arrival setup. The corrected standalone runner then **exited 0** for Retail Delivery, Repair Pickup and Repair Return failed visits and retry authorization. No successful outbound retry execution is claimed.
- Independent record check: **exit 0**, two affected deliveries delivered, source batch recovered, linked batch completed, **four received transfers**, ten customer notices and three customer action badges for the sampled shipment.
- DOM/keyboard/read-error probes: **exit 0**; recorded missing Finance dialog semantics/focus containment, Escape result, pointer destination, notification semantics and the contradictory COD error/empty state.
- Corrected pending/same-rider probe: **exit 0**; one real report request despite two disabled-button clicks while its original response was withheld; `aria-busy=true` and disabled Saving/Cancel; accepted batch stayed unstarted after resume. The rider's stale tab persisted for 12 seconds and refreshed correctly with the actual Refresh button.
- Final retry and OFF probes: **exit 0** as diagnostic assertions: actual **403** denials, enabled buttons and **zero visible error alerts**. These exits confirm the defects; they are not acceptance passes. The synthetic module was restored ON in a `finally` block.
- Sampled contrast probe: **exit 0**, 18 plain customer-tracking text samples on resolved opaque backgrounds, minimum calculated luminance contrast **4.76:1** (`contrast-samples.json`). This narrow sample did not establish a contrast defect and does not certify other controls, overlays, dark mode or all states. Recovery/retry focus sequences remained inside their dialogs; Finance's separate overlay failed that check.

Final preservation, whitespace checks and listener cleanup are recorded in `output/failed-delivery-recovery/ux-audit/final-hygiene.json`. Only this report and the approval plan are new non-ignored repository files. No commit or implementation approval is implied by this report.

All original captured file hashes, three HEADs and indexes, and root/OCR status are preserved. The feature status adds only these two documents; nothing is staged. Dedicated audit HTTP/Vite/MariaDB listeners were stopped, and disposable records/screenshots were retained. No application source, test source, `.env`, dependency/lockfile, production build or OCR runtime was modified. Learning-log changes are N/A for this documentation-only audit.
