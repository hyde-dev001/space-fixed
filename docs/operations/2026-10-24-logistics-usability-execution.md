# Logistics usability implementation - October 24, 2026

Worktree: `.worktrees/failed-delivery-recovery`; branch: `codex/failed-delivery-recovery`; HEAD remains `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5`.

This executes the user-approved [Phases 1-4](../superpowers/plans/2026-10-24-logistics-usability-improvements.md), using `ui-ux-pro-max` and the existing monochrome UI. One main agent worked sequentially. All earlier uncommitted recovery changes remain in place. No commit, push, rebase, merge, deployment, production migration, environment edit or dependency update is part of this task.

**Status: Phases 1-4 completed within the local verification scope below. All U01-U15 findings are resolved. Production release still requires the separate privacy correction and target-host checks.** The original [audit](2026-10-24-logistics-usability-audit.md) remains historical before evidence. The public repair-refund payout screenshot finding remains an independent production blocker; changing its dialog does not fix storage privacy.

## Findings and implementation

| Finding | Resolution and current code |
| --- | --- |
| U01 High - retry acceptance | `ErpLogisticsController::standaloneWorkItem` includes unaccepted outbound retry assignments in offers. Rider must explicitly Accept delivery before Start retry. No automatic acceptance, stage reset or custody inference. |
| U02 High - Staff mobile rows | `STAFF/JobOrders.tsx` uses compact order/item/quantity/return cards below `xl`, with named inspection actions. Original desktop table and permissions remain. |
| U03 High - Finance dialogs | `Finance/refundApproval.tsx` reuses shared `Modal` with opt-in accessible names, focus entry/trap/return, inert background and comfortable close targets. Pending financial execution blocks close/Escape. Nested scroll locks restore the body correctly. |
| U04 High - notice keyboard links | `Notifications/NotificationList.tsx` renders real destination anchors separate from read/archive controls. Existing canonical URL resolution and authorization remain. |
| U05 High - truthful customer tracking | `CustomerTrackingService::recoverySummary` exposes only an own-shipment recovery summary. `ShipmentTrackingPanel.tsx` shows delay/awaiting transfer/preparation above movement and the previous estimate, suppresses held movement/map, and distinguishes confirmed appointments. |
| U06 Medium - actor instructions | `MyDeliveries.tsx` and `BatchInterruptionDialog.tsx` explain actual hold/handoff/module blockers beside Start. Original-holder release/receipt work is named. Controller read projections include Logistics OFF blockers; mutation guards stay intact. |
| U07 Medium - parcel identification | `ShipmentCustodyService::pendingFor` projects authorized order/return/repair and shipment references, route stage and existing details links. References use bounded bulk queries, with no lookups for an empty handoff list. Return/order lookups remain shop-scoped. |
| U08 Medium - evidence and errors | `CustodyHandoffs.tsx` reuses extracted `DeliveryPhotoUpload.tsx` for preview/replace/removal, limits and native upload. Safe field errors appear/focus on the affected card; confirmed release/receipt has a persistent live announcement. A synchronous in-flight guard prevents duplicate sends. |
| U09 Medium - customer tasks/noise | `LogisticsNotificationService` keeps internal custody events/actor notices but does not send them as customer tasks. Delay/failure messages are informational. Tracking hides internal transfer events. The inbox now retains generic shipment notices in Delivery Updates even alongside categorized repair notices. |
| U10 Medium - rider/date decisions | Retry/batch dialogs reuse `DeliveryDatePicker`, show current confirmed holder separately from proposed rider, candidate/capacity context, empty guidance, configured window hours and shop timezone. Calendar Escape returns to its trigger without dismissing the parent. Server appointment/eligibility validation remains. |
| U11 Medium - COD read states | `Finance/CodRemittances.tsx` separates loading, unavailable and loaded-empty; Retry performs a real read. Previous totals are labelled stale after failure. Primary and supplemental cash confirmation are unavailable without a current successful ledger read. |
| U12 Low - notification density | Search and Unread only remain visible. Advanced Filters and Tools (bulk/archive/export/settings) start collapsed at compact widths, with explicit expanded state. Existing actions remain available. |
| U13 Low - control consistency | Neutral batch metadata/control styling, shared evidence UI and action-specific inspection labels. Inspection copy explains restock versus write-off without choosing a condition or approving a refund. |
| U14 High - waiting tab refresh | `MyDeliveries.tsx` reuses a bounded 15-second visible/online refresh and focus event. It preserves open dialogs, selected files, notes and active actions; manual Refresh deliveries remains. Tracking state also refreshes without requiring live-location mode. |
| U15 Medium - customer shell offset | Tracking/notification main content uses the existing customer-shell top spacing so the reference/Back control clears fixed navigation. Navigation policy is unchanged. |

## Reproduced defects and controlled corrections

Failing regressions preceded the offer projection, dialog/keyboard access, recovery status, handoff errors, notification density and COD read fixes. Logs are under ignored `output/failed-delivery-recovery/ux-improvements/`. A real MariaDB browser run caught an incorrect new repair reference column (`request_number` versus actual `request_id`); SQLite's empty projection had not exercised that populated path. New populated repair and cross-shop return-reference regressions failed before their fixes. A malformed own-shop return must never disclose another shop's order reference.

The wider Laravel run also exposed a test scheduling tomorrow on a closed Sunday. `StaffRetailShippingCoverageTest` now chooses the next weekday; production operating-day policy was not relaxed. Browser proof assertions now wait for the actual Inertia reload rather than treating a successful mutation response as a rendered screen. A single-shipment dispatcher control has the existing generic Open delivery name; the shared browser helper supports that and multi-shipment names.

The Repair Return browser uncovered a second real defect: when at least one repair/order notice was classified, generic shipment notices were omitted by grouped inbox rendering despite being saved. A mixed repair/order/shipment regression failed first; the frontend now includes Delivery Updates without rewriting stored notices, recipients, links or event deduplication.

## Verification

Fresh completed commands so far:

| Check | Result |
| --- | --- |
| Affected Logistics, COD, return/inspection/refund/Finance, employee MFA Laravel suites | 773 tests / 4,491 assertions, exit 0; one expected no-GD fallback skip in the GD-enabled process. `laravel-current.log`. |
| Additional customer/owner/employee MFA security suites | 25 tests / 186 assertions, exit 0. `mfa-current.log`. |
| Actual GD-disabled fallback | 1 test / 20 assertions, exit 0 in a separate process. `no-gd.log`. |
| Final affected frontend + MFA/routes | 38 files / 395 tests, exit 0 after the mixed-inbox correction. `frontend-final.log`. |
| Final custody/tracking projections | 32 tests / 256 assertions, exit 0; same expected GD-fallback skip. `read-projections-final.log`. |
| Disposable MariaDB recovery races | All ten scenarios passed. `recovery-concurrency.log`. |
| Disposable MariaDB batch races | All twelve scenarios passed. `batch-concurrency.log`. |
| Staff/Finance authenticated browser | Passed actual compact inspection, approval/manual synthetic payout and COD settlement, including pending close/Escape denial and keyboard focus checks. `browser/finance-browser-results.json`. |
| OCR packaging | Four tests passed; existing runtime `tesseract-7.0.0-core-7.0.0-eng-1.0.0` integrity check passed with local package-store access. |

The GD-enabled command covers private proof authorization/sanitization. No compiler/lint pass is claimed: the repository has no configured TypeScript compiler or frontend lint gate. Existing Node localStorage/React test warnings, local CARTO configuration warning and unresolved build-time `/images/auth-geometric-pattern.svg` warning remain.

The final browser matrix, build manifest, preservation results and review record follow. Earlier failed harness attempts and the discovered inbox defect are not counted as passing cases.

## Before and after screenshots

All images contain synthetic test data. Full-page captures extend beyond the viewport; content below a fixed overlay in such a capture does not prove an overlay-sizing defect.

| State | Before audit | After implementation |
| --- | --- | --- |
| Retry offer on phone | [Missing acceptance](../../output/failed-delivery-recovery/ux-audit/retry-no-accept-390.png) | [Explicit acceptance and holder](../../output/failed-delivery-recovery/ux-improvements/browser/retail_delivery-retry-offer-390.png) |
| Staff returns on phone | [Colliding columns](../../output/failed-delivery-recovery/ux-audit/staff-table-clean-390.png) | [Named compact item cards](../../output/failed-delivery-recovery/ux-improvements/browser/staff-return-queue-390.png) |
| Finance details | [Old overlay](../../output/failed-delivery-recovery/ux-audit/finance-modal-clean-390.png) | [Named dialog / comfortable close](../../output/failed-delivery-recovery/ux-improvements/browser/finance-refund-details-390.png) |
| Held customer tracking | [In-transit impression](../../output/failed-delivery-recovery/ux-audit/customer-batch-held-390.png) | [Delay before estimate/movement](../../output/failed-delivery-recovery/ux-improvements/browser/customer-delayed-direct-390.png) |
| Parcel release | [Weak identification](../../output/failed-delivery-recovery/ux-audit/release-clean-390.png) | [Reference, actor, preview and field error](../../output/failed-delivery-recovery/ux-improvements/browser/handoff-error-direct-390.png) |
| Replacement waiting | [Ready wording](../../output/failed-delivery-recovery/ux-audit/replacement-awaiting-handoffs-390.png) | [Blocked until required parcels arrive](../../output/failed-delivery-recovery/ux-improvements/browser/replacement-awaiting-direct-390.png) |
| Customer notices | [Crowded navigation/tools](../../output/failed-delivery-recovery/ux-audit/notification-top-viewport.png) | [Collapsed tools and keyboard destination](../../output/failed-delivery-recovery/ux-improvements/browser/customer-notices-via_shop-390.png) |

## Exact follow-up files

Paths below are this usability follow-up's delta relative to its captured dirty baseline, not the much larger combined uncommitted logistics feature. Production build assets are listed separately by the manifest/inventory.

- [`app/Http/Controllers/Logistics/ErpLogisticsController.php`](../../app/Http/Controllers/Logistics/ErpLogisticsController.php)
- [`app/Services/Logistics/BatchInterruptionService.php`](../../app/Services/Logistics/BatchInterruptionService.php)
- [`app/Services/Logistics/CustomerTrackingService.php`](../../app/Services/Logistics/CustomerTrackingService.php)
- [`app/Services/Logistics/LogisticsNotificationService.php`](../../app/Services/Logistics/LogisticsNotificationService.php)
- [`app/Services/Logistics/ShipmentCustodyService.php`](../../app/Services/Logistics/ShipmentCustodyService.php)
- [`resources/js/Pages/ERP/Finance/CodRemittances.tsx`](../../resources/js/Pages/ERP/Finance/CodRemittances.tsx)
- [`resources/js/Pages/ERP/Finance/__tests__/CodRemittances.test.tsx`](../../resources/js/Pages/ERP/Finance/__tests__/CodRemittances.test.tsx)
- [`resources/js/Pages/ERP/Finance/__tests__/refundApproval.payout.test.tsx`](../../resources/js/Pages/ERP/Finance/__tests__/refundApproval.payout.test.tsx)
- [`resources/js/Pages/ERP/Finance/refundApproval.tsx`](../../resources/js/Pages/ERP/Finance/refundApproval.tsx)
- [`resources/js/Pages/ERP/Logistics/Batches.tsx`](../../resources/js/Pages/ERP/Logistics/Batches.tsx)
- [`resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`](../../resources/js/Pages/ERP/Logistics/MyDeliveries.tsx)
- [`resources/js/Pages/ERP/Logistics/Shipments.tsx`](../../resources/js/Pages/ERP/Logistics/Shipments.tsx)
- [`resources/js/Pages/ERP/Logistics/__tests__/BatchInterruptionDialog.test.tsx`](../../resources/js/Pages/ERP/Logistics/__tests__/BatchInterruptionDialog.test.tsx)
- [`resources/js/Pages/ERP/Logistics/__tests__/CustodyHandoffs.test.tsx`](../../resources/js/Pages/ERP/Logistics/__tests__/CustodyHandoffs.test.tsx)
- [`resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`](../../resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx)
- [`resources/js/Pages/ERP/Logistics/components/BatchDetailsModal.tsx`](../../resources/js/Pages/ERP/Logistics/components/BatchDetailsModal.tsx)
- [`resources/js/Pages/ERP/Logistics/components/BatchInterruptionDialog.tsx`](../../resources/js/Pages/ERP/Logistics/components/BatchInterruptionDialog.tsx)
- [`resources/js/Pages/ERP/Logistics/components/CustodyHandoffs.tsx`](../../resources/js/Pages/ERP/Logistics/components/CustodyHandoffs.tsx)
- [`resources/js/Pages/ERP/Logistics/components/DeliveryDatePicker.tsx`](../../resources/js/Pages/ERP/Logistics/components/DeliveryDatePicker.tsx)
- [`resources/js/Pages/ERP/Logistics/components/DeliveryPhotoUpload.tsx`](../../resources/js/Pages/ERP/Logistics/components/DeliveryPhotoUpload.tsx)
- [`resources/js/Pages/ERP/Logistics/components/RetryResolutionDialog.tsx`](../../resources/js/Pages/ERP/Logistics/components/RetryResolutionDialog.tsx)
- [`resources/js/Pages/ERP/STAFF/JobOrders.tsx`](../../resources/js/Pages/ERP/STAFF/JobOrders.tsx)
- [`resources/js/Pages/ERP/STAFF/__tests__/JobOrders.mobile.test.tsx`](../../resources/js/Pages/ERP/STAFF/__tests__/JobOrders.mobile.test.tsx)
- [`resources/js/Pages/Notifications/NotificationList.tsx`](../../resources/js/Pages/Notifications/NotificationList.tsx)
- [`resources/js/Pages/Notifications/__tests__/CustomerNotifications.test.tsx`](../../resources/js/Pages/Notifications/__tests__/CustomerNotifications.test.tsx)
- [`resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`](../../resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx)
- [`resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx`](../../resources/js/Pages/UserSide/Tracking/__tests__/ShipmentTracking.test.tsx)
- [`resources/js/components/logistics/ShipmentTrackingPanel.tsx`](../../resources/js/components/logistics/ShipmentTrackingPanel.tsx)
- [`resources/js/components/ui/modal/__tests__/Modal.test.tsx`](../../resources/js/components/ui/modal/__tests__/Modal.test.tsx)
- [`resources/js/components/ui/modal/index.tsx`](../../resources/js/components/ui/modal/index.tsx)
- [`resources/js/types/logistics.ts`](../../resources/js/types/logistics.ts)
- [`tests/Browser/failed_delivery_batch.py`](../../tests/Browser/failed_delivery_batch.py)
- [`tests/Browser/failed_delivery_batch_records.php`](../../tests/Browser/failed_delivery_batch_records.php)
- [`tests/Browser/failed_delivery_finance.py`](../../tests/Browser/failed_delivery_finance.py)
- [`tests/Browser/logistics_usability.py`](../../tests/Browser/logistics_usability.py)
- [`tests/Browser/logistics_usability_finance.py`](../../tests/Browser/logistics_usability_finance.py)
- [`tests/Browser/logistics_usability_fixture.php`](../../tests/Browser/logistics_usability_fixture.php)
- [`tests/Feature/Logistics/BatchInterruptionNotificationTest.php`](../../tests/Feature/Logistics/BatchInterruptionNotificationTest.php)
- [`tests/Feature/Logistics/RecoveryNotificationTest.php`](../../tests/Feature/Logistics/RecoveryNotificationTest.php)
- [`tests/Feature/Logistics/RiderMyDeliveriesPageTest.php`](../../tests/Feature/Logistics/RiderMyDeliveriesPageTest.php)
- [`tests/Feature/Logistics/ShipmentCustodyTransferTest.php`](../../tests/Feature/Logistics/ShipmentCustodyTransferTest.php)
- [`tests/Feature/Logistics/StaffRetailShippingCoverageTest.php`](../../tests/Feature/Logistics/StaffRetailShippingCoverageTest.php)


Documentation changes also include this execution report, the manual guide, the approved plan's execution progress and `docs/ai-learning-log.md`. Total follow-up non-build paths: **46**. The original audit/approval contract and seven recovery migrations are preserved; no schema change was needed.

## Final authenticated browser evidence

Chromium **151.0.7922.34**, desktop **1440x1000**, tablet **768x1024**, phone **390x844**; additional customer/Staff layouts **360x844** and **1024x1024**. Screenshots and geometry checks show no page-level horizontal overflow in sampled states. Selected screenshots were visually inspected: retry offer, Staff queue, Finance details, held tracking, inline handoff error, customer notices and COD read error.

| Journey | Actual result / limit |
| --- | --- |
| Retail Delivery, Repair Pickup, Repair Return, Retail Return standalone retries | Each completed through normal UI failure, dispatcher authorization, explicit rider acceptance, applicable release/receipt, arrival/proof and dispatcher review. Includes same-rider Repair Return and replacement riders. `browser/results-first-five.json` records four standalone passes plus the direct batch. |
| Mid-batch direct recovery | Report, replacement decision, acceptance, invalid image correction, each parcel release/receipt, partial-start denial, route start and completion; own customer delay/no-task notices and tracking link. Saved stop/attempt/refund records pass. |
| Mid-batch shop-mediated Repair Return | Both transfers for every affected parcel, partial-start denial and full completion passed through UI. The initial final inbox check exposed the mixed-group defect; after correction the route was rerun. Its final keyboard probe initially exhausted 80 shared-header Tab stops. The same completed fixture was reauthenticated normally for the final inbox/record checkpoint: **15 Tab presses from the visible Search field**, Enter opens its own tracking URL, completed records/zero pending transfers/preserved earlier stops/unchanged refunds verified. This is a resumed final checkpoint, not a claim that the earlier whole runner exited 0. `browser/via-shop-final-checkpoint.json`. |
| Before-first-stop Repair Pickup, same rider | Complete route passed. The rider's already-open tab exposes Start batch after the dispatcher resumes it, without a test navigation/manual reload; then normal pickup/start/proof/review completes. |
| One remaining Retail Return stop | Direct takeover and completion passed; earlier completed stops preserved. |
| Repeat incident, customer appointment, Logistics OFF/ON | All three fresh authenticated batch cases passed with saved records, stale mutation denials and required reauthorization. These use normal UI for workflow actions; the existing guarded fixture toggles the disposable shop module, not an owner-settings browser claim. `browser-boundaries.log`. |
| Staff and Finance | Normal password/TOTP/attendance, recovered return receipt, real UI inspection of resellable and damaged lines (stock 5 to 6 versus remains 5), Finance approval/manual synthetic payout, rider COD submission and distinct Finance receipt passed. Staff-without-Finance and premature execution were denied. Finance Tab containment, safe Escape/focus return, and pending close/Escape denial passed. A manual synthetic payout record is not a real funds transfer. |
| COD unavailable read | A controlled **GET-only 503** produces an error, not empty history. Retry then uses the real endpoint (200) and clears the error. No financial mutation is faked. Staff additional 360/1024 reflow passed. `browser/read-layout-probes.json`. |

The evidence runner uses normal authentication and mandatory MFA, Clock In, isolated sessions/private storage and the guarded disposable MariaDB. No session injection, acceptance bypass, mocked business mutation or production record is used. Initial synthetic source/payment/refund/custody preconditions remain fixtures; they are not represented as browser actions. The Finance pending probe continues the original multipart request unchanged. Customer tracking observed delay updates while already open; selected-photo/draft/open-dialog refresh pauses also have frontend regression coverage.

The five additional boundary cases ran with the exact command:

```powershell
python tests/Browser/failed_delivery_recovery.py --batch repair_pickup@before_same_complete refund_return@last_direct_complete retail_delivery@repeat retail_delivery@appointment retail_delivery@off
```

The four standalone retries/direct route ran via `python tests/Browser/logistics_usability.py`; the final via-shop UI rerun used `python tests/Browser/logistics_usability.py via_shop`, followed by the same-fixture final checkpoint described above. Staff/Finance used `python tests/Browser/logistics_usability_finance.py`. Detailed command logs and failed attempts remain ignored output; none is silently converted into a pass.

## Final build and sequential review

Fresh `pnpm run build`: **3,855 modules / 24.22 seconds / exit 0**. The build has **405 manifest entries and 406 referenced asset files**; every static/dynamic import, CSS and asset reference resolves. Manifest SHA-256: `663e78e65eb6cc01a3c8c8a9a05034cba4aa0e40faff02b67d4706f081218562`. All **24 OCR runtime files** retain their baseline SHA-256 values. OCR integrity and the four packaging tests passed; no OCR reader/runtime dependency or delivery packaging changed.

| Required review | Result |
| --- | --- |
| Simplify / reuse | Pass: reuse existing offers, movement policy, custody service, event delivery, date picker, Modal, uploader and partial reload. No dependency, schema migration, replacement logistics engine or payment processor. Empty handoff lists avoid new reference lookups. |
| Standards, Spec and correctness | Pass within recorded scope: all 15 findings map to changes and evidence above. Original assignment/custody/appointment/attendance/RBAC/module/financial guards remain. |
| Changed TS/React quality | Pass by inspection: ordinary typed props and narrow DTO additions; no new `any`, speculative generic layer or new heavy dependency. Existing unrelated legacy typing was preserved. No compiler/lint claim. |
| Karpathy / scope | Pass: regression failures precede fixes; actual browser failures distinguish code defects from harness timing/labels/Tab limits. No unrelated storage/security architecture change. |
| Code splitting | Pass by inspection/build graph: small uploader reused across existing pages; no new eager OCR/third-party reader import. New build references resolve. No speculative splitting of small components. |
| Gauge improvements | Observed: retry offer becomes accept-enabled, invalid evidence gets an actionable inline error, held tracking stops claiming movement, mixed inbox notices remain visible, mobile Staff cards are readable, keyboard dialog/notice actions work, and unavailable COD cannot confirm against stale data. Speed/load/memory improvement not measured. |
| Security / Laravel | Pass within tests: own-shop bounded reference projections, customer-safe recovery fields, private proof checks with actual GD, mandatory MFA/attendance and current financial authority retained. Known public payout-screenshot storage remains outside this correction. |
| Verification before completion | Fresh frontend/Laravel/browser/InnoDB/build/asset/syntax/whitespace/preservation evidence recorded. Skips and physical/production limits remain explicit. |

Reuse and dead-code review checked the moved uploader's callers/imports, typed schedule/recovery fields, interval/dialog cleanup and source lookup paths. No unused former local uploader remains. No new state-changing logistics or financial endpoint was introduced. The final syntax check parsed **12 changed PHP files** and compiled **four changed Python runners**. Tracked `git diff --check` and all **19 changed untracked source/test/report** whitespace checks passed. `pint` formatted only the newly created usability fixture; no whole-feature formatting sweep was performed.

## Preservation and remaining limits

All three captured HEADs, indexes, root/OCR full status and protected file hashes remain unchanged. The feature's unrelated source/config/migrations, `.env`, package/lockfiles, prior audits/approval records and OCR bytes are preserved. All feature changes remain unstaged. Baseline snapshots, owned-path inventory and `hygiene-current.json` stay under ignored output. No Git publication occurred.

Not verified: physical camera/file-picker behavior on a phone, Safari/other browser engines, real screen-reader announcements, a full-site keyboard/contrast/WCAG audit, production performance, target Hostinger caches/CSP/storage/scheduler/session configuration or live provider settlements. The shared customer header has a long keyboard path; this work verifies the inbox destination from its visible main-content Search control, not a complete shared-navigation accessibility redesign.

The separate public repair-refund payout screenshot issue continues to block unconditional production sign-off. This UI correction preserves private delivery/custody proof behavior but does not privatize financial screenshots. Deployment also requires the previously recorded seven migrations/matching assets/private storage/scheduler/host checks under separate authorization. No production migration or deployment was run.


## Reproducible final commands

All commands ran from this feature worktree. PHP filesystem-fake suites ran serially. Cached pnpm was invoked as `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm ...`; store traversal required local sandbox escalation, not a dependency installation.

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics tests/Feature/Cod tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/OrderRefundRecoveryLifecycleTest.php tests/Feature/RepairRefundRecoveryLifecycleTest.php tests/Feature/RepairMixedRefundSplitSettlementTest.php tests/Feature/RepairOnlineRefundAuthorizationTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Auth/EmployeeMfaLoginFlowTest.php tests/Feature/Finance/SupplierRefundTest.php tests/Feature/Finance/XenditSupplierPayoutTest.php --display-skipped
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/EmployeeMfaRestorationTest.php tests/Feature/CustomerMfaSecurityTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/Auth/ShopOwnerTotpSecurityTest.php tests/Unit/Services/EmployeeMfaServiceTest.php tests/Unit/Security/MfaDependencyContractTest.php --display-skipped
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/ShipmentCustodyTransferTest.php tests/Feature/Logistics/CustomerTrackingTest.php --display-skipped
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/CustomerTrackingTest.php --filter test_customer_can_receive_approved_delivery_and_return_proofs_without_gd
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__ resources/js/components/logistics/__tests__ resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.mobile.test.tsx resources/js/Pages/ERP/Finance/__tests__ resources/js/Pages/Notifications/__tests__ resources/js/components/ui/modal/__tests__ resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/__tests__/ziggySecurityRoutes.test.ts
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
python tests/Browser/failed_delivery_concurrency.py
python tests/Browser/failed_delivery_batch_concurrency.py
pnpm run build
node --test scripts/prepare-owner-ocr-assets.test.mjs
node scripts/prepare-owner-ocr-assets.mjs --check
php vendor/bin/pint tests/Browser/logistics_usability_fixture.php
git diff --check
```

The final narrow My Deliveries command passed **57 tests**, including explicit idle-refresh preservation of an unsubmitted note, the same selected File object and an open dialog. It extends an existing test without changing application code or the 395-test suite count. Output-local same-fixture checkpoint/read-layout/preservation tools are retained with their logs for reproducibility; they are not production utilities.


Final cleanup: the current disposable engine was queried as **MariaDB 10.4.32 / InnoDB**. Its guarded SHUTDOWN completed; the exact task-owned PHP/Vite processes were verified and stopped, with no listener remaining on 33317/8027/5189. Test schemas, synthetic evidence and logs remain in ignored output. Other user listeners were not stopped. A final post-cleanup preservation/manifest/syntax/whitespace check passed. `hygiene-final.json` and `final-files.json` retain the final inventory; all changes remain unstaged.
