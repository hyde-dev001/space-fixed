# Failed-delivery recovery execution

> Latest follow-up: [October 24 release readiness](2026-10-24-logistics-release-readiness.md). This document records its earlier checkpoint; use the follow-up for current catalog and Staff/Finance verification status.

> Follow-up: [verification-gap corrections](2026-10-23-failed-delivery-verification-gaps.md) closes the local enrolled-MFA and GD gaps and records scheduler/MariaDB verification. Earlier limitations below describe the original execution checkpoint. Three reproduced baseline route-catalog assertions and production-only checks still prevent a fully verified release claim.

Approved October 23, 2026, Phases 0-6. One main agent, sequential work. Branch `codex/failed-delivery-recovery`; worktree `.worktrees/failed-delivery-recovery`; integration base `origin/solespace-b` fetched at `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5` (OCR already merged upstream). No OCR-only changes were carried into this feature; only the approved audit/plan were copied and hash-checked. Root and OCR status snapshots are under `output/failed-delivery-recovery/baseline/`.

Contract: resolve the twelve findings using existing Logistics/financial architecture; preserve tenant/RBAC/attendance/batch/module/private-file/COD/refund controls. Assignment is not custody. Require authenticated release and receipt. Internal planning dates allow eligible early starts; customer-confirmed appointment windows require recorded authorized rescheduling. No ambiguous legacy custody backfill and no fabricated resellable inspection. No commit/push/merge/deploy/production migration is authorized.

Initial setup: ignored worktree root verified; branch/path verified unused; remote base fetched and dedicated worktree created. Logistics source diff from the audit HEAD was empty. The original checkout stays unchanged. Testing uses SQLite memory/array stores; later browser and production-engine concurrency runs must use disposable local resources.

## Phase status

- [x] Phase 0: isolated baseline, four converted failing desired-behavior regressions; further acceptance cases added red before each owning change.
- [x] Phase 1: fresh retry execution, decision actors, original incident narrative and interrupted-state restoration; further assignment attribution integration remains in Phase 3.
- [x] Phase 2: physical custody, assignment-bound pickup evidence, separate release/receipt transfers and participant UI; full browser/concurrency coverage remains Phase 6.
- [x] Phase 3: dispatcher schedule/rider and common eligibility.
- [x] Phase 4: notification mappings, occurrence identity and durable processing.
- [x] Phase 5: Retail Return, inspection and financial exceptions.
- [ ] Phase 6: local MFA/GD, scheduler, migration and browser checks now verified in the follow-up; broader baseline catalog failures and target-host checks remain open.

The original audit observations are not acceptance tests. Converted regressions must fail for the desired behavior before production changes. Commands, failures and limitations will be appended as executed; unchecked phases are not complete.

## Verified phase evidence

- Locked dependency setup completed with Composer `--no-scripts` and pnpm `--frozen-lockfile`; package/lock files unchanged. Composer initially hit sandbox archive-write permissions; that process was stopped and the authorized installation completed. Existing PSR-4 case warnings were recorded. No `.env` was copied or edited.
- Phase 0: four acceptance regressions failed on old attempt replay, canceled custody assignment, missing/distinct notifications and incorrect incident restoration. Original rider/dispatcher frontend baseline: 112 tests passed. Existing backend baseline reproduced the strict fallback-shape failure; only its expected two null allowlisted fields were corrected. `artisan test` also emitted environment-file warnings in this clean no-env checkout; direct configured PHPUnit runs avoid that wrapper warning without changing the harness or environment.
- Phase 1: configured PHPUnit narrow run passed 23 tests / 112 assertions. The previous accepted-assignment expectation was deliberately updated to a fresh assigned offer plus a canceled predecessor; no rider acceptance was fabricated.
- Phase 2: transfer endpoint regression first returned 404 instead of required unknown-custody validation. Previous-rider pickup evidence regression failed because the old code allowed execution. Both are green after the correction. Backend execution/return/authorization/progression/proof/module/transfer run: 106 tests / 401 assertions passed. Transfer/page projection run: 40 tests / 262 assertions passed. Frontend rider/dispatcher run: 113 tests passed, after a meaningful missing-handoff-panel failure. Exact logs: `phase2-green.log`, `phase2-projection.log`, `phase2-ui-green.log` under `output/failed-delivery-recovery/`.
- Legacy handling: additive columns default to unknown. Assignment/timestamps alone never populate confirmed custody. Existing in-flight work can close under its existing proof/authorization rules without inventing intermediate custody; a new staged retry requires confirmed possession. Explicit handling of unknown-custody recovery is tracked for source-specific reconciliation rather than backfilling data.
- Phase 3 in progress: regressions reproduced planning-date overblocking, missing single-rider work-day checks, missing date/rider selection, and a normal start ignoring a customer appointment. Four narrow backend tests passed after the first integration. Wider backend and rider/dispatcher/batch UI runs are being checked before proceeding.

The preceding entries are the intermediate Phase 0-3 record. Later evidence follows; they do not establish release readiness.

## Completed implementation and acceptance mapping

All twelve audited defects have implementation changes and regression coverage. Phase 6 remains partially verified for the separate environment/authentication limitations below. No final approval, refund, payout or item condition is inferred from a Logistics status.

| Finding | Implemented result | Principal evidence |
| --- | --- | --- |
| F01 High, repeated failures | Fresh existing assignment per authorized retry; each execution records its own attempt. Replays cannot replace history. | FailedDeliveryRecoveryTest, DeliveryExecutionTest; actual second browser failure; duplicate-failure concurrency. |
| F02 High, possession and handoff | Confirmed holder independent of assignment; private assignment-bound release/receipt proofs, direct and shop-mediated transfers; old pickup proof cannot qualify another rider. | ShipmentCustodyTransferTest, proof/return/tenant suites; actual direct and two-transfer browser journeys; competing transfers/receipt/reassignment races. |
| F03 High, explicit dispatcher resolution | Every failed delivery/retail collection awaits explicit date/window/rider/reason selection and a fresh accepted offer. Transfer route and request identity recorded. | RetryResolutionTest; actual dispatcher dialog and same/different-rider browser cases. |
| F04 High, date consistency | Shared normal/retry/batch start policy protects confirmed appointment windows. Internal planning dates allow eligible early starts. Customer agreement is required to change a commitment. Monitor retains confirmed appointments. | Execution/batch/monitor/repair tests; future appointment blocked by actual UI and API after custody receipt. |
| F05 High, incident restoration | Snapshot interrupted state; dismiss only when execution/context remain compatible. No invented in-transit state. | Incident service/controller regressions. |
| F06 High, fabricated inspection | Physical return receipt stages existing Staff inspection. No automatic resellable classification/restock. Existing Finance decisions remain. | FailedDeliveryRefundWorkflowTest, OrderRefundReturnInspectionTest; persisted browser retail-return state awaits inspection. |
| F07 High, missing notifications | Failure, retry, reassignment, transfer, return, incident and completion mappings use existing persisted in-app notifications and safe tracking/ERP links. | Notification suites; persisted browser failure-recipient checks for all four purposes. |
| F08 Medium, occurrence identity | Unique recipient/business-occurrence identity; paired internal/customer events deduplicate, distinct attempts notify again. | Deduplication/RecoveryNotificationTest; repeated browser failure; MariaDB fan-out race. |
| F09 High, Retail Return | Failed customer collection enters explicit resolution; collected return still requires independent shop receipt. Source refund approval and inspection retained. | RetailReturnFailureRecoveryTest; authenticated refund_return browser journey. |
| F10 Medium, history | Original incident narrative retained; separate resolution note/responsibility and real authenticated decision actor in events/assignments/receipt. | Incident/retry/API regressions and immutable execution metadata checks. |
| F11 Medium, durable fan-out | Events persist notification progress; after-commit failure does not misreport the saved business action. Scheduled recovery and unique database identity prevent duplicate processing. | RecoveryNotificationTest and event/notification suites; actual concurrent processors. |
| F12 Medium, eligibility parity | Single offers share active employee/work-day/leave/capacity checks; execution rechecks actual-day availability and existing attendance/current-stop/module rules. | Assignment/execution/batch/tenant/module suites and batch-membership/start races. |

Phase 3 narrow backend run: 82 tests / 350 assertions. Source appointment cases: 20 / 124. Phase 4 run: 34 / 251 with the GD-dependent skip. Full affected frontend scope reached 259 tests across 16 files. Meaningful red tests preceded the relevant fixes; setup/fixture failures were not counted as acceptance passes.

Final review caught additional edge cases and added regressions: stale transfer cancellation reopening terminal work, cancellation by assignment-only staff, returning with a canceled replacement offer, retry-key replay changing appointment/route semantics, open handoff versus return, confirmed loss retaining release evidence, and shop receipt while Logistics is OFF. The last defect reproduced an outbound-intent exception rolling back a valid shop receipt; receipt now commits without arranging new outbound movement while OFF. Its custody/module/return regression run passed 78 tests / 269 assertions. After ON, dispatch must explicitly arrange the next offer/handoff; assignment still never establishes possession.

## Reproducible verification commands

Use the configured testing harness, not an application environment file. Commands were run from this dedicated worktree. pnpm is absent from PATH, so frontend commands use the installed cache through `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm ...`. No package/lock changes or extra dependency were needed. Native exit codes are explicitly captured because PowerShell treats pnpm warning/stderr text as NativeCommandError records.

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics tests/Feature/Cod tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Repair/RepairSponsoredDeliveryPlanReplayTest.php tests/Feature/Repair/RepairReturnRecoveryTest.php --display-skipped
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__ resources/js/components/logistics/__tests__ resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
python tests/Browser/failed_delivery_concurrency.py
php tests/Browser/failed_delivery_verify_records.php
git diff --check
```

The earlier broad backend run passed 641 tests / 3,452 assertions, with one skip and no failures, before the last OFF-shop-receipt regression. Final command summaries and source/build integrity are recorded in the final checks section below. Full unrelated repository suites and an unconfigured TypeScript compiler/linter gate are not claimed.

The skip is `CustomerTrackingTest::test_only_owner_can_receive_sanitized_approved_delivery_proof`: local PHP has no GD extension. The existing no-GD fallback tests pass, but the image-sanitization behavior still needs a GD-enabled run. Existing frontend act/localStorage warnings, Composer PSR-4 warnings and the Vite unresolved `/images/auth-geometric-pattern.svg` warning remain visible in logs.

## Disposable browser and database verification

Actual MariaDB 10.4.32 / InnoDB ran on loopback port 33317 with its own data directory under this feature's ignored output directory; 365 migrations applied to the disposable schema. No production/shared database or `.env` was used. Harness bootstrap checks the exact loopback host/port/schema and resolved server data directory before fixture writes. PHP on 8027 served the normal HTTP kernel, middleware, CSRF and built frontend. No auth bypass route, session injection or mocked Logistics response was introduced.

Eight browser observations are saved in `output/failed-delivery-recovery/browser-results.json`:

| Journey | Actual result |
| --- | --- |
| Retail Delivery | Failed visit -> dispatcher dialog -> rider B accepts -> rider A releases -> B receives -> early internal-planning start -> private delivery proof + authorized review -> delivered. |
| Repair Pickup | Failed customer collection -> explicit date/rider -> B accepts/collects -> proof + authorized review -> delivered. No pre-collection rider custody fabricated. |
| Repair Return | Failed paid/completed repair return -> date/rider -> confirmed direct handoff -> start -> proof/review -> delivered. Existing payment release guard was exercised. |
| Retail Return | Failed collection -> explicit date/rider -> collect -> rider return handoff + independent shop receipt -> delivered; refund remains awaiting Staff inspection/payment decisions. |
| Same rider | Fresh offer/acceptance retains the same confirmed holder; no fictional transfer. |
| Shop mediation | A releases -> shop receives -> shop releases -> B receives; B's UI and API stay blocked before final receipt. |
| Confirmed appointment | Future appointment still blocks the actual button and API after custody handoff. |
| Repeated failure | Another failed visit saves a distinct assignment-bound attempt and returns to resolution. |

These are password-authenticated, normal Clock In journeys using disposable unenrolled accounts. Dispatcher retry dialogs and transfer controls were operated in the UI; some issue/start/arrival/proof/review operations used authenticated browser fetch against real endpoints. They are not claims that every action was clicked in the UI. Final transports were real, persisted writes, unlike the earlier OCR browser harness. The handoff-complete screenshot was visually inspected at desktop width; mobile/device coverage is not claimed.

The final main runner invocation completed the first three purposes before a Retail Return fixture-only schema assumption failed. After correcting only that fixture, `python tests/Browser/failed_delivery_recovery.py refund_return retail_delivery@same retail_delivery@via_shop retail_delivery@appointment retail_delivery@repeat_failure` passed the remaining five cases. Early fixture payment/column/setup errors and network-idle waiting failures are retained in logs and are not counted as passes. Combined eight-case records were independently re-read by `failed_delivery_verify_records.php`: final states, distinct attempts, independent confirmed transfers, exactly one failure notice for each expected customer/own-shop dispatcher, valid link prefixes, private proof existence/no public copy, and no fabricated refund inspection/payout all passed.

Ten two-process concurrency scenarios passed using separate PHP workers and actual InnoDB transactions: duplicate failure, replayed retry, competing handoffs, duplicate receipt, competing batch membership, notification fan-out, return versus retry, replacement versus receipt, batch start versus an invalid pre-pickup delivery failure, and remittance versus reassignment. These establish the tested races, not a throughput/load benchmark. The batch/failure case correctly rejects an invalid failure before pickup; it is not evidence for every possible valid in-transit race.

Real concurrency initially reproduced a stale repeatable-read snapshot allowing competing handoffs and an index-gap deadlock during cross-shop transfer creation. Mutable replay/outstanding checks now use locking reads after the shop/leg locks; relevant transaction paths have bounded deadlock retries. Final workers returned expected idempotent results or validation conflicts, without duplicate attempts/active transfers/batch membership/cash reassignment. Safe summaries: `concurrency-results.json`, `browser-records.json`; raw credentials/tokens and personal proof contents are not published in reports.

### Remaining verification blockers

1. **Existing enrolled MFA login fails before Logistics.** `config/shop_modules.php` lists `erp.mfa.challenge` and verify as core user routes; `bootstrap/app.php` adds user authentication, while `UserController` deliberately logs out the user into pending MFA session state. Actual login returned to login instead of reaching the challenge. No authentication source was changed. MFA-enrolled journeys remain blocked; ordinary permitted unenrolled-account journeys above passed. Resolve this separately before asserting full normal-MFA acceptance.
2. **Existing legacy role enum mismatch.** The fresh MariaDB `users.role` enum does not include the Logistics-specific strings used by a seeder. Fixtures use the supported STAFF value plus canonical Spatie Logistics Dispatcher/Rider roles and real permission middleware. Existing enrollment/seeder paths need separate review; no enum/auth bypass was added.
3. **GD sanitization skip.** One explicit test remains unverified without GD; no extension/environment change was made.
4. Exact target-host MariaDB version/configuration, hosting paths, scheduler operation, mobile browsers and production rollout were not tested. No production access/deployment is authorized. Full unrelated frontend/Composer suites were not run.

## Sequential review record

1. Simplify: reuse assignments as execution identity, existing events/notifications, private proof storage, actor policies, Modal and refund inspection/Finance services. No separate retry shipment, cash ledger, state framework, queue service or dependency introduced.
2. Standards -> approved Spec -> correctness risks: reviewed sequentially in the main agent. F01-F12 mapping above; current guards/history preserved. The remaining MFA/GD/target-environment gates prevent a fully verified release claim.
3. Changed TS/React: focused typed components and API boundaries, safe server-projected handoff actions, request identity, loading/error handling, input labels, focus trap and restoration. No new any/assertion framework. Manual inspection and Vitest/build evidence are not compiler/lint evidence.
4. Karpathy: explicit contract, baseline red cases, narrow fixes and no unrelated auth/financial redesign. Owned tests were changed only for intended behavior or the demonstrated baseline safe-prop expectation mismatch.
5. Code splitting: two small existing-page components, no added heavy dependency/manual chunk. Existing production build succeeds; no latency or bundle-size improvement claim.
6. Gauge: observed corrected behavior from original failed regressions to fresh accepted assertions, real persisted retries/handoffs and rejected races. Performance, incident rate and user-error rates were not measured.
7. Security/Laravel: tenant/permission/attendance/owner-read-only tests, private file validation/cleanup, independent actors, assignment-bound proofs, maker/checker controls, replay validation, consistent lock order and bounded retries. Parcel reassignment cannot move COD debt; inspection and Finance authority stay separate. No secrets or production credentials in evidence.
8. Verification before completion: fresh selected tests, real browser writes, independent record inspection, InnoDB workers, build and hygiene. Limitations are explicitly retained.

Reuse/dead-code review confirmed the touched helpers' callers and runtime routes; the old automatic next-date helper still supports internal trusted callers and was retained. No unfamiliar legacy code was deleted. Durable lessons were added only to this isolated worktree's learning log. See [operations](failed-delivery-recovery.md) and [changed-file inventory](2026-10-23-failed-delivery-recovery-files.md).

## Final checks

| Check | Final result / evidence under `output/failed-delivery-recovery/` |
| --- | --- |
| Selected PHPUnit command above | Exit 0: 642 tests, 3,456 assertions, one explicit GD skip, no failures/errors; 3m39s. `backend-final.log`. |
| Full affected Vitest command above | Native exit 0: 16 files / 259 tests passed. `frontend-final.log`. A smaller intermediate run covered only 210 tests; it was not substituted for this final scope. |
| Production build | Native exit 0: 3,853 modules, 22.52s. `build-final-verified.log`. Existing pattern SVG warning retained. |
| Existing OCR assets | Read-only check exit 0 for `tesseract-7.0.0-core-7.0.0-eng-1.0.0`; no runtime file diff. Direct sandbox resolution failed until authorized access to pnpm's installed core package store; no dependency change was needed. |
| Real concurrency | Exit 0: all ten scenarios passed again after the final custody change. `concurrency-final.log`, `concurrency-results.json`. |
| Persisted browser records | Exit 0: all eight observations verified, with private evidence and expected failure recipients/history. `browser-records.json`. |
| Syntax / build integrity / hygiene | 56 changed/new PHP files pass `php -l`; two Python scripts compile; all 403 manifest entries and 404 referenced assets/imports resolve; tracked and untracked whitespace checks pass. `final-hygiene.json`. |
| Workspace protection | Root/OCR HEAD, complete status lists and staged-path lists match baseline exactly. Approved audit/plan copies match original bytes. `workspace-preservation.json`. Package/lock, `.env`, auth bootstrap/config and `public/ocr` have no feature diff. |
| Git | Dedicated branch remains at approved integration base; all feature changes unstaged. No commit, push, merge, deployment or production migration. |

Generated manifest SHA-256: `aeecf77b068985050617f80780551d28146bc3aab899593d714e0eb42d310a45`. Full non-build changed-path inventory includes five additive migrations, service/controller/model changes, two UI components, existing integration/test changes and guarded local verification tools. Build output is local to the dedicated worktree. Temporary PHP/MariaDB verification listeners are stopped after the evidence is collected.

Phases 0-5 are implemented. Phase 6 has the local automated, password-authenticated browser, database-race and build evidence above, but remains open for enrolled-MFA verification, the GD test and target-environment checks. This is an implemented development branch with explicit verification gaps, not a deployment-ready assertion.
