# Dispatcher scheduling and rider acceptance follow-up

**Branch/worktree:** `fix/qa-four-follow-up`, `.worktrees/qa-four-follow-up`. Sequential main-agent work.

**Acceptance:** Authorized employee dispatcher can schedule one/multiple same-shop pending deliveries, create/offer a batch and assign a standalone delivery. The exact assigned employee rider can accept both offer types. Unprivileged users, other tenants, owner-only actors, wrong riders, disabled modules and real maintenance freezes remain denied. Existing assignment/state/idempotency rules stay authoritative. Operational pages do not show a false maintenance notice; blank 403 bodies produce useful feedback.

**Confirmed cause:** Shared APIs authenticate `user,shop_owner`, but both mutation controllers previously preferred the owner session independently. A second owner login overrode the route-authenticated dispatcher and policy correctly denied that owner operational dispatch. Mixed-session regressions reproduced the reported empty 403 for scheduling and assignment. Both helpers now honor the guard selected by Laravel authentication middleware, retaining their existing fallback and policy checks. This reproduces the reported failure locally; production session contents were not inspected.

Rider offer acceptance already preferred the employee. Batch and standalone acceptance were verified with both sessions present; no rider permission expansion was needed. Maintenance middleware returns 409/503, unlike the reported 403. BatchWorkspace previously rendered its maintenance reason even when saving was enabled; it now displays it only when saving is disabled. Empty error responses use nonblank feedback.

- [x] Add and run failing mixed-session schedule/batch/standalone assignment regressions, plus rider acceptance and unauthorized/tenant counterchecks.
- [x] Resolve logistics mutations using the guard selected by existing authentication middleware; preserve fallback identity checks and all policy decisions. No owner execution powers or bypass of maintenance/module/attendance checks.
- [x] Add failing UI regressions for false maintenance text and empty 403 messages; fix affected batch/scheduling feedback.
- [x] Run relevant logistics authorization/API/batch/rider/module/maintenance and frontend suites, isolated browser verification and fresh build.
- [x] Sequential review, reuse/dead-code, syntax/diff/manifest checks; record exact results. Publish on the same feature branch; no production data/settings/database edits.

## Expected files

- `app/Http/Controllers/Api/Logistics/DeliveryBatchController.php`
- `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- `tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php`
- `resources/js/Pages/ERP/Logistics/Batches.tsx`
- `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- This plan, `docs/ai-learning-log.md`, and generated `public/build`.

## Verification

- Red backend: `php -d extension=gd artisan test tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php` reproduced two expected-200/actual-403 failures; standalone rider acceptance and unauthorized/tenant counterchecks did not fail. Final focused rerun: four tests, 20 assertions, exit 0.
- Red frontend: `pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --maxWorkers=2` reproduced three failures (false maintenance notice and two blank error messages). Green: 125 tests passed.
- Broader backend command:

```powershell
php -d extension=gd artisan test tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php tests/Feature/Logistics/DeliveryBatchApiTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/RiderTenantAuthorizationTest.php tests/Feature/Logistics/ShopOwnerLogisticsResponsibilityTest.php tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php tests/Feature/Logistics/RiderProgressionTest.php tests/Unit/Services/Logistics/LogisticsActorPolicyTest.php tests/Feature/Maintenance/MaintenanceEnforcementTest.php
```

Result: 153 tests, 683 assertions, no failures, exit 0. Tests were labeled warnings for the absent worktree `.env`; no `.env` was created or edited.

- Broader frontend: `pnpm exec vitest run resources/js/Pages/ERP/Logistics resources/js/providers/__tests__/MaintenanceProvider.test.tsx --maxWorkers=2`: 10 files, 208 tests passed, exit 0. Existing Node local-storage warnings remain.
- `pnpm run build`: completed in 33.29s. Manifest has 397 entries; all listed output files and CSS assets exist.
- `php -l` on both changed controllers and the new regression test: no syntax errors. `vendor/bin/pint --test tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php`: passed after formatting the new test. `git diff --check`: passed.
- Isolated local Chromium/MariaDB browser QA, synthetic users only: New Batch -> select two unscheduled deliveries -> Save Draft (schedule 200/create 201) -> Review & Offer -> confirm (200); Shipments -> Open delivery -> choose date/rider -> Schedule & assign rider (schedule 200/assign 200); rider -> Accept batch (200) -> Accept delivery (200). Both employee sessions retained an owner session. Reload confirmed both acceptances persisted with no acceptance buttons remaining; final screenshot visually reviewed. Initial harness retries corrected the offer confirmation and accessible button selectors, with no additional production-code changes.
- Browser commands: `python -X utf8 storage/framework/cache/dispatcher-actions-browser.py`, `dispatcher-actions-browser-followup.py`, `dispatcher-actions-browser-accept.py`, and `dispatcher-actions-browser-final.py` (each script under the same cache directory). Logs/screenshots under `storage/logs/dispatcher-actions-*`; test-only helper, synthetic fixtures and services are ignored and excluded from the commit.
- Full unrelated backend/frontend suites were not rerun. No TypeScript compiler configuration/frontend lint script exists, so no type-check or frontend lint result is claimed.

## Sequential review record

| Gate | Result |
| --- | --- |
| Simplify / ponytail | Pass: use native Auth guard selection, array_unique and existing policy/helpers; no dependencies or new architecture. |
| Standards -> Spec -> correctness | Pass: minimal controller helper change fixes sibling mutation callers; batch/single/rider workflow and negative boundaries verified. |
| Clean TypeScript / React | Pass: focused error fallback and status condition; no new any, state, effects, imports or runtime abstraction. Existing catch-any/asserted response convention remains. |
| Karpathy guidelines | Pass: session hypothesis proved by red tests; source diff confined to authenticated actor selection and reported UI feedback. |
| Code splitting | N/A: no new imports or heavy UI; existing chunking retained. |
| Gauge improvements | Pass: two backend denials and three UI failures reproduced before the fix and green afterward. Performance not measured. |
| Security / Laravel | Pass: middleware chooses guard; tenant, role, rider linkage, assignment ownership, source state, module and attendance policies unchanged. Real freeze remains enforced. |
| Verification before completion | Pass: fresh focused/broader tests, build, syntax/formatting/diff checks and browser evidence recorded. |
| Reuse / dead-code | Pass: existing Auth, policies, batch services, maintenance provider and components reused; no orphan imports, abandoned branches or new dependencies. |
| Writing / durable learning | Pass: this plan records workflow and evidence; guard-selection lesson added to existing shared-session guidance. |

**Delivery:** Commit and push to `fix/qa-four-follow-up` with fresh `public/build`; user merges/deploys to `solespace-b`. Do not merge or deploy automatically.
