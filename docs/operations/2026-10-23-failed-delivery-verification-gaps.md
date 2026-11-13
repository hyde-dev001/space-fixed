# Failed-delivery recovery verification gaps — October 23, 2026

> Latest follow-up: [October 24 release readiness](2026-10-24-logistics-release-readiness.md). This document records its earlier checkpoint; use the follow-up for current catalog and Staff/Finance verification status.

Branch `codex/failed-delivery-recovery`, dedicated worktree `.worktrees/failed-delivery-recovery`, base `e9f4e25a11f8a81c2e4440b91dff7dd77fee3fe5`. This follow-up supersedes the original report's local MFA/GD verification gaps. No commit, push, merge, deployment, production migration, `.env` edit or global PHP configuration change was performed.

Contract: correct the confirmed pre-login route and seeder defects; exercise image privacy with GD enabled; verify the real notification schedule, retries and recipient isolation; test additive migration ordering/reversal on disposable MariaDB; preserve all existing recovery, authentication, attendance, tenant and financial controls. Before edits, all 692 existing changed/untracked paths and their hashes, HEAD and staged state were captured in `output/failed-delivery-recovery/verification-gaps/baseline.json`.

The file-level sequence was authentication catalog and login tests, seeder role mapping/tests, GD security assertions, scheduler tests, guarded deployment/browser verification tools, then production build and documentation. CodeGraph was consulted first; its root index returned code from a different checkout, so implementation decisions use the live worktree files. One main agent worked sequentially; no delegation occurred.

## Confirmed causes and exact corrections

### MFA challenge was treated as an authenticated ERP operation

`UserController::login` correctly logs out an enrolled employee, regenerates the session and stores the pending user/security-version facts after password validation. `EmployeeMfaController` already validates those facts and consumes the second factor before authenticating. However, `config/shop_modules.php` classified both `erp.mfa.challenge` routes as core user operations. `bootstrap/app.php` therefore injected full user authentication and ERP actor resolution before the challenge controller could run. A guest pending MFA was redirected to login or received JSON 401.

Only the catalog changed: both challenge routes are explicitly excluded from operational ERP gating and have public pre-login audience/no authenticated actor guard, matching the existing authentication-entry convention. Their declared web/session/CSRF and `throttle:10,1` middleware remain. No wildcard ERP exemption, new login route, remembered authentication shortcut or MFA controller bypass was added. Full user authentication and normal permissions remain mandatory on operational pages.

New `tests/Feature/Auth/EmployeeMfaLoginFlowTest.php` first failed on the actual challenge GET/POST redirects/401s. It now verifies seven employee roles (Dispatcher, Rider, Staff, Manager, Finance, HR, Repairer): password alone leaves the user a guest; the challenge loads; invalid codes fail; valid TOTP reaches normal Time In. Missing/revoked pending sessions, inactive employees, recovery-code reuse and invalid-attempt exhaustion remain denied. Existing employee/customer/owner MFA suites are included in final verification.

### Logistics seed values depended on database driver

`EmployeeSeeder` mapped Logistics to legacy values `LOGISTICS_DISPATCHER`/`LOGISTICS_RIDER`, unlike the existing HR and user-management controllers, which store `STAFF` and use Spatie for Logistics specialization. MariaDB's existing compatibility fallback could choose STAFF; SQLite returned the unsupported desired labels directly. The regression reproduced this inconsistent legacy value, rather than claiming every old MariaDB seed necessarily failed.

The two mappings now explicitly use STAFF and retain their distinct canonical Spatie roles. No enum expansion, permission broadening, role downgrade or schema migration was needed. The new seeder test asserts Dispatcher exception authority and Rider execution authority remain separate. Fresh strict MariaDB migration/seeding checks also verify the actual persisted values and permissions.

### GD was installed but disabled in CLI PHP

`php_gd.dll` exists locally; the normal CLI did not load it. `php -d extension=gd` loads it for the test process without editing shared php.ini or `.env`. The sanitizer itself required no production correction: it reencodes private originals, strips the JPEG metadata sentinel and preserves authorization/privacy.

The previously skipped test exposed a brittle header-order assertion: Symfony returns `no-store, private`, while the test required the equivalent reverse order. `CustomerTrackingTest` now checks both required directives, absence of public caching and a decodable sanitized image. It still checks the metadata sentinel is absent, the original private bytes are unchanged, a public duplicate is not read, unrelated users/shipments cannot read it, unapproved/wrong-purpose proofs fail, download disposition is correct and missing files return 404. No storage or image-security rule was relaxed.

The dedicated GD case passed **1 test / 26 assertions**; a separate normal-PHP run passed the no-GD fallback **1 / 20**. With GD enabled, that fallback test intentionally skips because its branch is unavailable. The old sanitization gap is closed locally; Hostinger's web and CLI extensions still need verification. Existing no-GD delivery behavior can serve original private bytes, so production must enable GD to promise metadata stripping.

### Notification scheduler required verification, not a new implementation

`routes/console.php` already registers `logistics:deliver-pending-notifications` each minute with `withoutOverlapping()`. New tests verify that registration and completion of a partially delivered recipient set without duplicates or cross-shop dispatchers. The test explicitly bootstraps the console kernel: otherwise the HTTP-only test application may not have loaded console routes when earlier tests already ran migrations.

Existing after-commit failure/recovery, distinct-attempt deduplication and legacy-event exclusion cases remain. An output-local integration probe ran the actual scheduled Artisan child process against the guarded MariaDB instance using a persistent database cache mutex: a held mutex skipped execution, releasing it processed the pending event, and replay retained one persisted customer notice. This executes only the selected notification event, not unrelated financial schedules. Evidence: `scheduler-process.json`; command and recipient tests: `scheduler-green.log` and `focused-final.log`.

## Actual authenticated browser verification

`python tests/Browser/failed_delivery_recovery.py --mfa` completed all four purposes with real writes: Retail Delivery, Repair Pickup, Repair Return and Retail Return. Each fixture's dispatcher and two riders are enrolled in test TOTP. Password login reached the actual challenge UI, invalid input was rejected, valid authenticator digits were entered through the form, and normal Clock In succeeded. No session injection, middleware bypass, mocked transport or MFA disabling was used.

The existing recovery runner now supports `--mfa` and writes `browser-mfa-results.json` separately from the prior eight-case unenrolled-account evidence. The dispatcher dialog, independent release/receipt controls and real proof/review endpoints remain exercised as previously documented. Direct browser fetch is used for some operational steps; these are authenticated browser/API journeys, not a claim that every action was clicked in the UI.

`php -d extension=gd tests/Browser/failed_delivery_verify_records.php --mfa` independently passed all four persisted case checks: delivered states, distinct attempt identities, independent transfers, original private evidence and exactly one failure notice for the expected customer/own-shop dispatcher. Retail Return still awaits inspection/Finance; receipt does not invent item condition or payout. Other employee-role authentication/Time In behavior is verified through the HTTP tests; their unrelated department business workflows were not all exercised in a browser.

Evidence is synthetic-only under ignored `output/failed-delivery-recovery/`; no real credentials, authenticator codes, private applicant values or production data are published. The disposable PHP listener uses the ordinary HTTP kernel on loopback 8027; MariaDB is on 33317 with resolved data-directory guards. The listeners are stopped after verification.

## MariaDB and deployment compatibility

`tests/Browser/failed_delivery_deployment_check.php` refuses a nonempty verification schema and any database server outside the existing guarded loopback/data-directory boundary. On MariaDB **10.4.32 / InnoDB**, it applied **365 migrations**, verified the five logistics files were last/in order, seeded actual Logistics roles/accounts, preserved unknown custody defaults, reversed only the five feature migrations in reverse order, checked original attempt/assignment/leg records survived, then reapplied the feature migrations. Evidence: `mariadb-deployment.json`. The five migration files themselves were not changed in this follow-up.

Successful `down()` execution is not a data-preserving production rollback: it drops the new transfer table and feature columns, including custody, appointments and notification identities. Retain additive schema/history when reverting application code. No production rollback or migration command was run.

An additional output-local probe runs the normal `DatabaseSeeder` on a separate freshly migrated disposable schema, beyond the focused Logistics seeders; its actual result is listed in final evidence. These demo seeders are test setup and must not be invoked on production as a release step. The guarded deployment script and its assertions are the repeatable feature migration/role checks.

The default-seeder probe passed on a second fresh schema: 365 migrations and **18 Logistics accounts** checked for STAFF legacy values and separated Spatie authority (`full-seed.json`). An earlier attempt could not connect after the disposable listener had stopped between turns; its native CLI exit alone was not treated as success. The server was restarted and the final structured success result was verified.

Fresh frontend output is generated through the unchanged `pnpm run build`, including existing OCR preparation. `public/ocr` checksums pass and no OCR runtime source/asset diff was introduced. All referenced build assets/imports are checked. Private proof storage remains local/private; the browser record probe verifies files exist there and not on the public disk.

## Test-run limitations and pre-existing failures

The final serialized broad run executed **702 tests / 33,200 assertions**, exit **1**, with **three pre-existing catalog failures** and **one intentional no-GD fallback skip**. There were no remaining Logistics, COD, repair, MFA or image-security failures in that run. The failures were not filtered out or converted into passing expectations:

- `ShopModuleCatalogTest::test_route_entries_use_the_approved_capability_schema`: the existing `shop-owner.shell.platform-balance` self-service entry has owner audience while the assertion requires user audience.
- `ShopModuleCatalogTest::test_named_internal_routes_are_present_in_the_authoritative_map`: existing uncatalogued routes, first `shop-owner.settings.xendit-key`.
- `ShopModuleRouteCoverageTest::test_loaded_internal_routes_are_catalogued_in_both_directions_and_methods_match`: 37 existing missing entries, including platform-balance/supplier/owner-shell routes and `erp.logistics.cod-collections`.

Output-local copies of the unchanged tests loaded the exact HEAD catalog and reproduced the same failures (`catalog-baseline.log`, `catalog-unit-baseline.log`). The follow-up changes only the two MFA entries; no unrelated route policy was guessed to make the inventory green. This remaining catalog debt prevents a clean broader acceptance/deployment-ready claim.

One initial broad run also had a legacy-proof 404 while other PHP test processes were cleaning the same Storage fake directories. The legacy-proof case passed in isolation and in the final serialized run. Laravel's `Storage::fake()` cleans its shared disk root; subsequent PHP filesystem suites were serialized. The initial schedule registration assertion also depended on implicit console bootstrap; the test now explicitly loads the real console kernel. Neither observation justified weakening proof access or scheduler behavior.

## Sequential review and preservation

Simplify -> Standards -> approved requirements -> risk review were performed in the main agent. The two production changes reuse existing pre-login catalog and legacy STAFF/Spatie conventions; controllers, finance, custody and operational gates remain unchanged by this follow-up. Changed TS/React/code-splitting review is N/A: no frontend application source changed. Karpathy review: failed regressions precede the fixes; no new dependency, enum migration or auth framework. Gauge: real challenge access and consistent role persistence are demonstrated; speed/security-incident rates are not measured.

Security/Laravel review retains guest pending-session validation, active/security-version checks, TOTP/recovery replay protection, attempt limits, throttling, operational auth/tenant/attendance and private-proof authorization. Existing tests and real MFA/browser writes provide the evidence; no standalone “security certification” is claimed. Reuse/dead-code checks retain all existing runtime entry points and remove no unfamiliar code. Final syntax, diff, build, artifact, protected-file and workspace checks are recorded below.

Root/OCR work remains separate. No broad staging/reset, package/lock update, environment edit, production operation or Git publication is authorized by this task. The approved audit/plan snapshots remain byte-identical. Unrelated catalog failures are explicitly retained rather than silently expanded into this fix.

## Final evidence

All logs below are relative to `output/failed-delivery-recovery/verification-gaps/` unless stated otherwise.

| Verification | Actual result |
| --- | --- |
| Initial MFA/seeder regressions | 13 tests, 12 meaningful failures on challenge access/401 and inconsistent legacy role; `auth-seeder-red.log`. |
| Final focused authentication, seeders, scheduler, notification and customer proof suites | Native exit 0, **70 tests / 664 assertions**, one intentional no-GD fallback skip; `focused-final.log`. |
| Broad Logistics/COD/repair/authentication/catalog run | Exit 1, **702 tests / 33,200 assertions**, three baseline catalog failures and the intentional fallback skip; `backend-final.log`. This is not an all-green repository claim. |
| Baseline catalog reproduction | Same feature inventory failure and two unit failures using exact HEAD config in unchanged test copies; `catalog-baseline.log`, `catalog-unit-baseline.log`. |
| GD sanitization | Exit 0, **1 test / 26 assertions**, actual GD-enabled sanitizer/security behavior; `gd-sanitization.log`. |
| No-GD fallback | Exit 0, **1 test / 20 assertions**, normal PHP without global configuration edits; `no-gd-fallback.log`. |
| Frontend | Native exit 0, **18 files / 264 tests** including Logistics/tracking/Staff return actions and MFA security UI/routes; `frontend.log`. |
| Production build | Native exit 0, **3,853 modules, 26.15 seconds**; `build.log`. Existing auth-pattern SVG warning retained. |
| OCR runtime integrity | Read-only check exit 0 for `tesseract-7.0.0-core-7.0.0-eng-1.0.0`; installed package-store access required sandbox escalation, not a dependency change. |
| Enrolled-MFA browser recovery | Exit 0, four workflows completed; `browser-mfa.log`, `../browser-mfa-results.json`. |
| Independent browser record/private-file checks | Exit 0, all four persisted observations; `browser-mfa-records.json`. |
| MariaDB installation / feature rollback / reapply | Structured success: 365 migrations and five feature migrations in order, original business rows retained; `mariadb-deployment.json`. |
| Full default seed sequence | Structured success on fresh MariaDB schema, 18 Logistics accounts checked; `full-seed.json`. |
| Real scheduled child process | Structured success: database overlap mutex skipped a concurrent run; retry processed the event; replay left one notice; `scheduler-process.json`. |

Primary commands (frontend uses installed cached pnpm through npm exec):

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Auth/EmployeeMfaLoginFlowTest.php tests/Feature/EmployeeMfaRestorationTest.php tests/Feature/CustomerMfaSecurityTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/Auth/ShopOwnerTotpSecurityTest.php tests/Unit/Services/EmployeeMfaServiceTest.php tests/Feature/Logistics/LogisticsSeederTest.php tests/Feature/Logistics/RecoveryNotificationTest.php tests/Feature/Logistics/DeliveryEventAfterCommitTest.php tests/Feature/Logistics/LogisticsNotificationDeduplicationTest.php tests/Feature/Logistics/LogisticsNotificationTest.php tests/Feature/Logistics/CustomerTrackingTest.php --display-skipped
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Logistics tests/Feature/Cod tests/Feature/OrderRefundReturnInspectionTest.php tests/Feature/Repair/RepairDeliveryReconciliationTest.php tests/Feature/Repair/RepairSponsoredDeliveryPlanReplayTest.php tests/Feature/Repair/RepairReturnRecoveryTest.php tests/Feature/Auth/EmployeeMfaLoginFlowTest.php tests/Feature/EmployeeMfaRestorationTest.php tests/Feature/CustomerMfaSecurityTest.php tests/Feature/Auth/ShopOwnerTwoFactorLoginFlowTest.php tests/Feature/Auth/ShopOwnerTotpSecurityTest.php tests/Unit/Services/EmployeeMfaServiceTest.php tests/Unit/Security/MfaDependencyContractTest.php tests/Unit/BusinessScaling/ShopModuleCatalogTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php --display-skipped
pnpm run test:frontend resources/js/Pages/ERP/Logistics/__tests__ resources/js/Pages/UserSide/Tracking/__tests__ resources/js/components/logistics/__tests__ resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-actions.test.tsx resources/js/components/UserProfile/__tests__/EmployeeTotpSecurity.test.tsx resources/js/__tests__/ziggySecurityRoutes.test.ts
pnpm run build
node scripts/prepare-owner-ocr-assets.mjs --check
python tests/Browser/failed_delivery_recovery.py --mfa
php -d extension=gd tests/Browser/failed_delivery_verify_records.php --mfa
php -d extension=gd tests/Browser/failed_delivery_deployment_check.php
```

Deployment remains conditional. The requested local MFA, legacy-role, GD, scheduler and MariaDB checks are resolved; the wider route-catalog debt still fails three assertions. Target-host PHP/GD, cache refresh, persistent session/HTTPS behavior, exact MariaDB/SQL mode and existing-data migration locks, storage permissions, live cron, OCR/CDN/CSP paths and rollout must be verified under separate production authorization. No full unrelated project suite, production deployment, load benchmark or external email delivery is claimed. See [Hostinger runbook](failed-delivery-recovery.md#hostinger-scheduler-and-environment-checks).

Final hygiene passed: 62 changed/new PHP files parse, two Python scripts compile, tracked/new-file whitespace checks pass, all 403 manifest entries and 404 referenced assets/imports exist. The unchanged deterministic build manifest SHA-256 is `aeecf77b068985050617f80780551d28146bc3aab899593d714e0eb42d310a45`. Root/OCR HEAD, full status and staged lists match their original snapshots; approved audit/plan copies match byte-for-byte. Package/lock, `.env`, `bootstrap/app.php`, existing recovery production sources and OCR runtime files were preserved. All feature files remain unstaged; temporary database/listener services are stopped after evidence collection. Detailed follow-up path hashes and preservation assertions are in `final-hygiene.json` and `preservation.json`.

Production changes in this follow-up are limited to `config/shop_modules.php` and the two Logistics role mappings in `database/seeders/EmployeeSeeder.php`. Additional changes are the MFA tests, seeder/image/notification tests, guarded local deployment/MFA browser tools, operations/execution/inventory documentation and one durable learning note. No additional migration or frontend application change was necessary.
