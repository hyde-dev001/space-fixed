# Batch vehicle breakdown recovery — execution record

> Latest follow-up: [October 24 release readiness](2026-10-24-logistics-release-readiness.md). This document records its earlier checkpoint; use the follow-up for current catalog and Staff/Finance verification status.

Worktree: `.worktrees/failed-delivery-recovery`; branch `codex/failed-delivery-recovery`; starting HEAD `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5`. The user approved [Phases 0–4](../superpowers/plans/2026-10-23-batch-vehicle-breakdown-recovery.md) after the [source audit](2026-10-23-batch-vehicle-breakdown-audit.md). Execution uses one main agent, sequentially. No commit, push, rebase, merge, deployment or production migration is authorized.

## Phase 0 — baseline and reproduction

- Root, OCR and logistics HEAD/status/staged lists and changed-file hashes captured in ignored `output/failed-delivery-recovery/batch-interruption/baseline.json`; 702 existing logistics changed/untracked paths including both approved batch documents, no staged paths. Existing recovery prerequisites remain in this working tree.
- PHPUnit isolation checked: testing SQLite `:memory:`, fake mail, array sessions/cache. No `.env` changes. Existing browser/MariaDB harness explicitly checks loopback 33317, schema `solespace_recovery_test` and the worktree-specific data directory before writes.
- Baseline backend command from Phase 0: **57 tests / 222 assertions, exit 0** (`baseline-backend.log`). GD enabled per process, no GD skip in these selected tests.
- Baseline frontend command from Phase 0: **2 files / 113 tests, exit 0** (`baseline-frontend.log`). The first sandboxed cached-pnpm attempt could not access the user cache; the same approved offline invocation passed with sandbox escalation. No package installation/update.
- New HTTP regression initially failed **201 expected / 404 actual** for the missing batch report endpoint (`hold-red.log`). An earlier fixture-only Collection/eager-loading setup error was corrected first and is not counted as functional reproduction.

## Phase 1 — persisted hold

- Two Artisan-generated additive schema migrations: `2026_10_23_132125_add_batch_scope_to_delivery_incidents.php` and `2026_10_23_132200_add_batch_incident_holds_and_stops.php`. Existing five recovery migrations unchanged. Populated down-migrations refuse evidence loss; no production migration was run.
- Batch incident, ordered affected-stop snapshots and hold written under existing shop/batch/leg transaction locks. Individual stage/custody/timestamps/attempt counts remain intact. Completed, proof-review and existing exception stops are excluded from continuation.
- Shared hold checks cover stale pickup/transit/arrival/proof/completion/failure/assignment paths and preserve the original rider's unresolved active-work responsibility. Existing private evidence validation/authorization and cleanup support the batch subject; actual rider, permissions and attendance remain required.
- New focused hold suite: **5 tests / 29 assertions**, exit 0. Expanded Phase 1 command (hold, incidents, execution, arrival, COD and tenant suites): **60 tests / 333 assertions, exit 0** (`phase1.log`). Includes duplicate replay/fact mismatch, private upload cleanup, direct service denial, maker/tenant/attendance/capability boundaries and populated rollback refusal.

## Phase 2 — recovery gate passed

- Initial resume regression failed **200 expected / 403 actual**, demonstrating the old leg-only resolution route (`recovery-red.log`).
- First recovery cases passed **6 tests / 59 assertions**: same-rider state preservation, direct/via-shop all-parcel gating, unstarted resume, unknown custody and safekeeping without financial side effects.
- Edge regressions then exposed missing started-stage custody checks, appointment enforcement on resumed in-transit arrivals, a fixture missing canonical `shop_owner_id`, and declined recovery-offer handling. Corrected the fixture rather than weakening OFF policy. Expanded hold/recovery run passed **20 tests / 142 assertions** (`recovery-edge-green.log`).
- Date/calendar/customer agreement validation is shared with existing per-delivery retry. Logistics OFF/ON reauthorization uses the existing module-toggle audit occurrence plus module identity/timestamp, including toggles within the same frozen clock second; no new module subsystem.
- Larger Phase 2 regressions: **206 tests / 1,115 assertions, exit 0** (`phase2.log`), including retry, custody, batch, COD, OFF boundaries, retail-return and repair regressions.

## Phase 3 — notifications and UI gate passed

- Dispatcher notification regression failed with zero notices before batch event publication/mapping. Existing persisted after-commit notifications now fan out per affected shipment while notifying the correct dispatcher once. Incident/revision occurrence keys permit later decisions/incidents without replay duplicates. Customer links remain individual tracking pages; dispatcher links select the authorized batch incident.
- New backend page regression showed missing held current work before the projection update. Safe projections now retain source/recovery history, private evidence URLs for authorized viewers and per-parcel readiness without raw snapshots/storage paths.
- Backend Phase 3: **42 tests / 254 assertions, exit 0** (`phase3-backend.log`). Frontend Phase 3: **4 files / 187 tests, exit 0** (`ui-green.log`). UI regressions failed first on missing controls/hold feedback, partial-handoff start and held shipment proof feedback.
- Corrected a shadowed browser `window` reference in the new notification-focus effect. The isolated dialog test submits its real handler directly after setting a synthetic File because jsdom does not populate the native file-input value; native chooser/validation remains enabled and is a required real-browser check. Existing React act/layout warnings remain in the broader page tests; no compiler/lint pass is claimed.

## Phase 4 — verification and handoff, October 24

The implementation is locally verified within the boundaries below. It remains uncommitted and unreleased. Three existing route-catalog failures and target-host checks prevent a general production-readiness claim.

### Corrections found during verification

- Four edge regressions reproduced ordinary cancellation resetting held work, changed source-batch membership, OFF receipt incorrectly depending on a historical pickup timestamp, and missing shop-to-rider reauthorization. The corrected edge/custody run passed **31 tests / 163 assertions**. A separate stale single-leg cancellation regression then failed before the shared locked hold guard was added.
- MariaDB empty reversal failed because the migration dropped the batch/status index before its referencing foreign key. The new migration now removes the foreign key first. The final fresh-schema check completed **367 migrations**, empty reversal/reapply, legacy incident/attempt/custody preservation, indexes and **two populated rollback refusals** on MariaDB **10.4.32**. No existing recovery migration was edited.
- The missing customer-pickup arrival UI was reproduced by a failing frontend regression. **I've arrived for pickup** now calls the existing authenticated arrival endpoint; it does not create possession or weaken failed-pickup validation. The fresh Repair Pickup browser journey clicked it normally.
- Visual review found the interrupted source batch displayed 1/1 (100%) after two held stops moved to recovery. A failing regression preceded the shared progress-input correction. The final browser asserts 1/3, and the affected 3-file frontend rerun passed **129 tests**. Recovered states also use immutable history in the existing workspace/table/details consumers.
- Early harness failures used an ambiguous select label, expected 200 rather than the arrival endpoint's correct 201, and assumed an OFF denial must be 422 although module authorization correctly returned 403. These were harness corrections, not application bypasses. A run crossing midnight correctly required fresh attendance. The retry test also used UTC's previous date against the shop's next calendar day; its input now uses the shop timezone. The multi-reason API test freezes time to avoid crossing attendance dates within its loop.
- An early broad PHP run overlapped another fake-storage suite and lost a proof file. The focused API rerun passed, and the final broad run was serial with no storage failure. No proof validation was removed.

### Fresh automated results

| Check | Actual result / evidence under ignored output |
| --- | --- |
| Broad Logistics/COD/return inspection/repair/employee MFA | **681 tests / 3,898 assertions**, exit 0, one intentional GD-fallback skip; `regressions-backend-verified.log`. |
| Additional batch privacy and GD image access | **27 tests / 303 assertions**, exit 0, same intentional skip; `privacy-gd-final.log`. Includes correct dispatcher access, foreign-shop report/decision/evidence denial and unchanged revision. |
| Actual no-GD fallback, separate PHP process | **1 test / 20 assertions**, exit 0; `no-gd.log`. Global PHP configuration unchanged. |
| Affected frontend suite | **17 files / 271 tests**, exit 0; `frontend-verified.log`. The intermediate progress correction also passed **3 files / 129 tests**. No compiler/lint tooling is claimed. |
| Existing route catalog | **21 tests / 29,326 assertions, 3 failures**, exit 1; `catalog.log`. Same prior capability-schema platform-balance mismatch, missing named entries and route inventory failures. `config/shop_modules.php` is unchanged from the pre-batch baseline. No new batch report endpoint appears among missing named routes. |
| Fresh production build | **3,854 modules, 22.70 seconds**, exit 0; `build-verified.log`. Existing unresolved auth-geometric-pattern.svg warning remains. |
| OCR runtime and packaging | Version `tesseract-7.0.0-core-7.0.0-eng-1.0.0` verified, **4 packaging tests passed**. All **24 public/ocr files are tracked**, unchanged by this extension. |
| Manifest/import integrity | **404 entries / 405 asset files**, every static/dynamic import resolves. SHA-256 `060b9670e2fca57f341a4c0b94abfa646e43635f0be5fafae56304a877605532`. |

Commands used from this worktree (pnpm runs through the existing cached `npm.cmd exec --offline --package=pnpm@11.14.0 -- pnpm` wrapper):

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics tests/Feature/Cod tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Auth/EmployeeMfaLoginFlowTest.php --display-skipped
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/BatchInterruptionTest.php tests/Feature/Logistics/CustomerTrackingTest.php --display-skipped
php vendor/phpunit/phpunit/phpunit tests/Feature/Logistics/CustomerTrackingTest.php --filter without_gd
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Unit/BusinessScaling/ShopModuleCatalogTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php --display-skipped
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__ resources/js/components/logistics/__tests__ resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx
python tests/Browser/failed_delivery_recovery.py --batch
python tests/Browser/failed_delivery_recovery.py --batch retail_delivery@mid_direct_complete repair_pickup@before_same_complete repair_return@mid_shop_complete refund_return@mid_direct_complete
python tests/Browser/failed_delivery_recovery.py --batch retail_delivery@off
python tests/Browser/failed_delivery_recovery.py --batch retail_delivery@mid_direct
python tests/Browser/failed_delivery_batch_concurrency.py
python tests/Browser/failed_delivery_concurrency.py
python tests/Browser/failed_delivery_recovery.py --mfa
php -d extension=gd tests/Browser/failed_delivery_verify_records.php --mfa
php -d extension=gd tests/Browser/failed_delivery_batch_deployment_check.php
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
node --test scripts/prepare-owner-ocr-assets.test.mjs
git diff --check
```

### Authenticated browser and concurrency coverage

Chromium uses normal password/TOTP login and normal Clock In, the actual Inertia pages/HTTP endpoints, private original synthetic evidence and the guarded loopback MariaDB app. No injected sessions, bypass routes, mocked transport or production records. Initial completed/collected stops and approved source states are disposable fixture setup, not claims that the browser performed their earlier business history.

- **13 batch observations:** before-first, mid-batch, one remaining, same-rider resume, direct/via-shop replacement, partial receipt denial, wrong confirmer denial, duplicate report replay, stale movement denial, safekeeping, repeated occurrence, per-customer appointment reschedule and OFF/ON reauthorization. `browser-results.json` contains safe outcomes; `browser-interruption-results.json` retains the initial 12-case run. The source progress correction was rechecked in `browser-progress-final.log`.
- **Four additional full logistics completions:** Retail Delivery direct takeover, uncollected Repair Pickup same-rider resume, Repair Return shop-mediated takeover and Retail Return direct takeover. Real UI actions continue through all affected stops and dispatcher proof approval/physical return receipt; `browser-completion-results.json`. Retail Return receipt does not claim inspection approval or Finance payout; those remain separately governed and regression-tested.
- OFF/ON is an environment control through the existing toggle action on the guarded disposable fixture. Rider/dispatcher actions are authenticated UI/HTTP. This is not an owner-settings click-through claim. OFF denies start; ON still denies until the actual dispatcher dialog reauthorizes.
- **Four existing per-delivery MFA journeys** reran and completed, with independent private-file/notification/source record checks. Their original runner uses UI retry/handoff and authenticated API arrival/proof/completion calls; do not describe those older journeys as all-click UI verification.
- **12 independent-process batch races:** duplicate report, report versus start/pickup/transit/arrival/proof/COD/failure, competing decisions, final receipt versus replacement start, release versus cancellation and notification retry. All passed on MariaDB 10.4.32 with a timed process barrier, one incident, no duplicate active assignment and post-hold movement denial. A real failure/proof that commits before the report remains genuine history; the report does not invent another visit. No unhandled lock/deadlock error occurred in these runs; no throughput/load claim is inferred.
- **10 existing independent-process recovery races** passed again, including failure/retry/receipt, batches, notifications, return-vs-retry and COD remittance-vs-reassignment. Parcel transfer never changed the collected-cash rider; an additional focused batch regression verifies A's collection survives takeover and remains remittable by A.

Synthetic screenshots remain ignored. The source interrupted/handoff screenshot was visually reviewed and exposed the corrected progress defect. The final corrected browser asserts the progress denominator. No mobile-device, physical-GPS accuracy, external notification or production load measurement is claimed.

### Sequential review and preservation

1. **Simplify/reuse — pass:** one batch coordinator extends existing incidents, child stop evidence and guards. Reuse batch offers, assignments, appointment checks, custody proofs, private upload storage, after-commit events and notification retry; no second logistics/cash/refund engine or dependency.
2. **Standards/Spec/risks — pass within recorded scope:** locked current state, explicit affected set/lineage, immutable original snapshots and decision events, per-customer appointments and OFF reauthorization. No fabricated failures, possession, pickup, approval, refund or restock.
3. **TS/React quality — pass by inspection:** typed safe projections/dialog payloads, bounded original upload, synchronous duplicate-submit ref, focus handling, existing components; no new `any` or compiler/lint claim. Progress helper accepts only its used status field.
4. **Karpathy/minimum scope — pass:** meaningful red/green cases, shared gates before side effects/replay, no unrelated refactor. Corrected the demonstrated pickup-arrival and progress integrations only.
5. **Imports/code splitting — pass by inspection/build:** small dialog reuses already-present dependencies; no new heavy package/eager OCR import or speculative manual chunks. Build/import integrity verified; bundle/performance improvement not measured.
6. **Measured behavior — pass:** missing endpoint/old leg-only resolution and demonstrated bypasses fail before fixes; fresh tests and real UI now hold/confirm/continue. No delivery-accuracy, fraud, GPS or latency claim.
7. **Security/Laravel — pass within tested scope:** tenant/rider/capability/attendance checks, private evidence, UUID facts/revision conflicts, consistent shop-first transactions, independent release/receipt, aggregate readiness, unchanged manual financial authority and additive-history rollback refusal.
8. **Fresh verification/reuse/dead code — pass within these checks:** reviewed actual callers/status consumers and scoped delta; removed duplicate shop-lock calls introduced during this extension. New handlers/services/types have live routes or UI callers. Existing unrelated code was not deleted.

Root and OCR HEAD/status/index/file hashes match the before-edit snapshot; the logistics HEAD/index are unchanged. The final hygiene/inventory records changed baseline-owned paths explicitly and confirm untouched environment/package/lock/seeder/catalog/OCR files. No staging, commits, pushes, rebases, merges, deployment or production migration occurred. Local test databases/storage/logs remain ignored; temporary test services are stopped after evidence collection.

### Release prerequisites and remaining limits

- Apply the **two new batch migrations after the existing five recovery migrations** in a separately approved coordinated release; preserve backups, private proof storage, active holds, immutable histories and matching PHP/frontend/OCR assets. Empty reversal was tested locally; populated reversal is intentionally refused. Old code that does not enforce active holds cannot safely be restored blindly.
- Resolve or separately accept the three existing catalog failures before a full acceptance claim. Full unrelated repository suites, exhaustive fault injection, other DB/browser versions, phones, real GPS accuracy and high-volume contention were not tested here.
- Hostinger's actual PHP/GD/SQL mode, production data/DDL lock duration, storage permissions, scheduler/queue operation, HTTPS/session/cache state, CSP/public asset paths and release rollback remain production-only checks. Nothing was deployed to establish them.
- Logistics completion and physical receipt are verified; the final Staff inspection and Finance payout browser journeys are not claimed. Existing return-inspection/COD/refund/repair regression suites passed and those workflows retain their approvals.

Use the updated [manual guide](failed-delivery-recovery-manual-testing.md#13-batch-vehicle-breakdown-page-by-page-test) for exact next-page steps. Feature scope and file hashes are listed in the [batch file inventory](2026-10-23-batch-vehicle-breakdown-files.md).

Final handoff checks: **59 batch non-build paths / 113 combined recovery non-build paths**, 33 changed/new PHP files parse and three changed/new Python scripts compile. Tracked and new-file whitespace checks pass; root/OCR HEAD, full status, staged lists and baseline file hashes are unchanged. Logistics remains on the original HEAD with zero staged paths. Dedicated ports 8027, 5189 and 33317 have no listener after cleanup; the unrelated existing services were left intact. The final source-progress screenshot was visually inspected and shows **1 of 3 completed / 33%**, rather than the pre-fix 100%.
