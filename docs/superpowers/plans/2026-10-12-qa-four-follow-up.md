# QA Four Follow-up Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for inline execution. The repository requires one main agent and sequential work.

**Goal:** Let Riders remit disputed COD shortages against the original remittance with Finance confirmation, and base warranty transport choices on current eligibility rather than original repair methods.

**Architecture:** Store each supplemental COD handoff as a record linked to the original remittance. Lock the remittance while accepting or confirming supplements, derive the shortage from Finance-confirmed amounts, and reuse the existing COD settlement path only after exact cumulative confirmation. Remove the historical-method early return from the shared warranty eligibility helper; keep address, pin, coverage, Logistics, workflow, and shipment checks in their current paths.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit, Inertia, React 18, TypeScript, Vitest.

---

### Task 1: COD shortage regression tests

**Files:**
- Modify: tests/Feature/Cod/CodRemittanceTest.php

- [x] Add an end-to-end test for Rider supplement submission, Finance confirmation, and exact final settlement against the original remittance.
- [x] Add coverage for partial Finance confirmation, duplicate Rider submission, over-submission, and rider/tenant boundaries.
- [x] Run the focused tests and confirm they fail because the supplemental flow is missing.

### Task 2: COD supplemental custody flow

**Files:**
- Create: app/Models/CodRemittanceSupplement.php
- Create: database/migrations/2026_10_12_000001_create_cod_remittance_supplements_table.php
- Modify: app/Models/CodRemittance.php
- Modify: app/Services/CodRemittanceService.php
- Modify: app/Services/CodCollectionService.php
- Modify: app/Http/Controllers/Api/Logistics/CodRemittanceController.php
- Create: app/Http/Requests/Logistics/SubmitCodRemittanceSupplementRequest.php
- Modify: app/Http/Controllers/Api/Finance/CodRemittanceController.php
- Create: app/Http/Requests/Finance/ConfirmCodRemittanceSupplementRequest.php
- Modify: routes/web.php
- Modify: routes/finance-api.php
- Modify: config/shop_modules.php
- Modify: tests/Feature/Cod/CodRemittanceTest.php

- [x] Add the append-style supplemental record and parent relationship.
- [x] Add Rider submission with shop, rider, status, amount, idempotency, and outstanding-balance validation under a parent-row lock.
- [x] Add Finance confirmation with tenant checks, one-time confirmation, decimal-safe cumulative balance, and finalization through the existing COD payment and collection path.
- [x] Expose supplemental history to Finance and pending/remaining balance summaries to the Rider.
- [x] Run CodRemittanceTest and related COD regression tests.

### Task 3: COD Rider and Finance UI

**Files:**
- Modify: resources/js/types/logistics.ts
- Modify: resources/js/services/logisticsApi.ts
- Modify: resources/js/Pages/ERP/Logistics/CodCollections.tsx
- Modify: resources/js/Pages/ERP/Finance/CodRemittances.tsx

- [x] Add a Rider action for disputed remittances that shows the original expected amount, confirmed cash, remaining shortage, and dispute note.
- [x] Add Finance controls for confirming a pending supplemental handoff, including partial receipts.
- [x] Preserve the existing page layout, accessible labels, disabled/loading states, and exact-remittance path.
- [x] Run the focused frontend tests and frontend build.

### Task 4: Warranty current-eligibility regression

**Files:**
- Modify: tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php
- Modify: resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx

- [x] Replace the obsolete all-walk-in restrictions with tests that allow currently eligible delivery methods.
- [x] Keep tests for Logistics OFF, invalid address/pin/coverage, and Logistics turning OFF before approval.
- [x] Run focused backend and frontend warranty tests and confirm the replacement API test fails before the production change.

### Task 5: Warranty eligibility fix and review

**Files:**
- Modify: app/Services/RepairDeliveryService.php
- Modify: the tests listed in Task 4 if additional old-rule assertions are found

- [x] Remove original intake/return method as a transport eligibility bound.
- [x] Reuse the existing current address, pin, coverage, module, approval, and shipment validation.
- [x] Review accounting, authorization, tenant scope, and started-shipment continuation behavior.
- [x] Run focused warranty tests, related COD tests, frontend tests, frontend build, and git diff --check. The full frontend run had four timeout failures under default concurrency; all four files passed on an isolated rerun.

### Completion evidence

- Record exact test/build command results, modified files, migration and schema details, final worktree status, and remaining manual QA.

### Verification record

- `php artisan test tests/Feature/Cod/CodRemittanceTest.php --no-ansi`: PASS, 8 tests / 108 assertions.
- `php artisan test tests/Feature/Cod --no-ansi`: PASS, 57 tests / 397 assertions.
- `php artisan test tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php --no-ansi`: PASS, 59 tests / 207 assertions.
- `php artisan test tests/Feature/Repair/Warranty --no-ansi`: PASS, 63 tests / 401 assertions.
- `npm.cmd run test:frontend`: 297 files / 1,892 tests passed; four tests timed out under default concurrency. `npm.cmd run test:frontend -- resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx resources/js/Pages/ShopOwner/Settings/components/__tests__/BusinessScalingSettings.test.tsx --maxWorkers=4` passed 4 files / 153 tests.
- `npm.cmd run test:frontend -- resources/js/Pages/ERP/Finance/__tests__/CodRemittances.test.tsx resources/js/Pages/ERP/Logistics/__tests__/CodCollections.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx --maxWorkers=4`: PASS, 3 files / 65 tests.
- `npm.cmd run build`: PASS; Vite built 3,845 modules in 42.01s. Existing unresolved `/images/auth-geometric-pattern.svg` build-time warning remains runtime-resolved.
- `git diff --check`: PASS after final review; Git emitted only the existing CRLF-to-LF notice for `routes/web.php`.
- Backend test runs reported `.env` missing warnings because the worktree has no `.env`; tests exited successfully.
- TDD red phase: the COD regression failed before the supplemental endpoints/projection existed, and the warranty replacement tests failed under the historical-method restriction.

### Cross-function QA requested after implementation

- Added the Finance and Logistics COD remittance routes to the route catalog so the new supplemental endpoints receive their ERP audience, actor, and module middleware. Finance confirmation routes explicitly declare `POST` and financial risk.
- `php artisan test tests/Feature/Repair --no-ansi`: PASS, 211 tests / 1,592 assertions (Laravel reported missing-`.env` warnings).
- `php artisan test tests/Feature/PlatformFee tests/Feature/Finance/InvoicePaymentTest.php --no-ansi`: PASS, 60 tests / 397 assertions (missing-`.env` warnings).
- `php artisan test tests/Feature/Cod --no-ansi`: PASS after route catalog registration, 57 tests / 397 assertions (missing-`.env` warnings).
- `php artisan test tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php --no-ansi`: PASS, 59 tests / 207 assertions (missing-`.env` warnings).
- `php artisan test tests/Feature/Logistics --no-ansi`: FAIL, 507 tests / 2,596 assertions with one unrelated assertion failure in `LogisticsPageAccessTest::missing and cross shop orders use safe logistics fallbacks`; actual order summary includes `delivery_method` and `carrier_company`, which the expected fallback omits. That controller/test is outside the changed files.
- `php artisan test tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php --no-ansi`: still fails on 37 existing uncatalogued routes; the Finance COD page and all three Finance COD APIs are no longer among the missing entries.
- `php artisan erp:route-matrix --no-ansi`: still fails on existing owner refund route contracts and the owner platform-balance page; it reports no new COD remittance route error.
- The full `composer test` run was interrupted before a final summary. No full-suite pass is claimed.
