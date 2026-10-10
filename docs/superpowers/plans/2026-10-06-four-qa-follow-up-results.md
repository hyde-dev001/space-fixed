# Four QA follow-up results

Worktree: `C:/xampp/htdocs/solespace-master/.worktrees/qa-four-follow-up`.
Branch: `fix/qa-four-follow-up`, based on freshly fetched `origin/solespace-b` at `14e3db0453`.
The original worktree and its unrelated changes were preserved. The user subsequently requested committing and pushing this task branch with a fresh `public/build`; merging remains the user's step.

| QA | Root cause | Fix | Automated regression coverage | Result |
| --- | --- | --- | --- | --- |
| 1 | Review listing and initial page props lacked report history; the UI used an empty local Set. Both write endpoints excluded dismissed history and checked then inserted without serialization. CRM also treated its user ID as a possible shop ID. | Expose `is_reported` from exact review type/ID and reporting-shop history; update list and selected detail from the successful API response. Disable Reported. Route both writes through a review-row lock and historical duplicate check. Remove the user-ID tenant fallback. | ReviewReportStateTest; CustomerReviews.state.test.tsx. Covers reload data, dismissed history, different review from the same customer, owner/CRM shared history, colliding actor/shop IDs, cross-shop rejection, immediate and reopened modal state. | Focused PASS |
| 2 | Moderation required an active customer, and the suspension primitive rejected a different report reason as a different suspension operation. | Explicitly reuse a validated current suspension only in report moderation; keep the customer-root lock, suspension/appeal identity checks, ordinary lifecycle conflicts, terminal immutability, and report audit events. Dismissal does not reactivate accounts. | FlaggedAccountWorkflowTest. Covers suspend/suspend/dismiss, identical retry, one suspension and appeal, unchanged mail count, three per-report audits, invalid cross-customer suspension identity, inactive accounts, and rollback. | Focused PASS |
| 3 | Submission generated an absolute Laravel route, but the admin notification serializer rejected absolute links and returned null. | Generate a relative named route for the in-app notification and canonicalize historical appeal events during serialization. Preserve absolute email URLs and rejection of unrelated external destinations. Existing appeal UI supports the listing, so no new detail route was introduced. | AdminNotificationInboxTest; SuspensionAppealFlowTest; NotificationCenter.test.tsx. Covers generated and historical URLs, click href and read mutation, refresh/direct page access, recipient isolation, and denied customer/staff access. | Focused PASS |
| 4 | Expense creation/update used UTC `now()` calendar dates instead of configured business timezone; the modal had no server date limit and discarded the API error. | Use existing `app.shop_timezone` for date-only validation, share the server business date with the native input, and display `errors.date[0]` through SweetAlert. Retain future-date rejection, UTC timestamp storage, and Finance authorization. | ExpenseSettlementTest; Expense.procurement-review.test.tsx. Covers yesterday/today/tomorrow at UTC/Manila midnight, configured Los Angeles boundary, create/update, paid-now/pay-later, date-only persistence, receipt, tenant/actor/tax/settlement/audit fields, native max date, and validation message. | Focused PASS |

## Verification

- Red evidence: `qa-red-reviews.log` (missing authoritative report state), `qa-red-focused.log` (409 second-report suspension, null appeal URL, and 422 business-today date rejection), `qa-red-frontend.log` (missing persisted Reported button and business-date max).
- Final focused backend: `php vendor/bin/phpunit tests/Feature/CRM/ReviewReportStateTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/SuperAdmin/AdminNotificationInboxTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php` — **39 tests, 265 assertions, PASS** (`qa-final-focused.log`).
- Focused frontend: `npx.cmd --offline pnpm@11.14.0 exec vitest run --maxWorkers=2 resources/js/Pages/ERP/CRM/__tests__/CustomerReviews.state.test.tsx resources/js/Pages/ERP/CRM/__tests__/CustomerReviews.report.test.ts resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx` — **35 tests, 3 files, PASS** (`qa-green-frontend.log`).
- Fixture checks: `php vendor/bin/phpunit tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php tests/Feature/Finance/FinanceRouteContractTest.php` — **9 tests, 58 assertions, PASS**. Affected positive fixtures now clock in employees instead of disabling attendance. The existing regular-Admin moderation fixture now grants its required page permission.
- Initial implementation build: `npx.cmd --offline pnpm@11.14.0 run build` — **PASS**, 3,843 modules transformed, 1m50s (`qa-build.log`). Existing unresolved image-reference warnings remain. This initial build output was restored at that time; the later publication build below is retained.
- Additional verification build: `npx.cmd --offline pnpm@11.14.0 run build --outDir storage/app/qa-build` — **PASS, exit 0**, 1m04s (`qa-final-build.log`, invoked through `cmd.exe /d /c`). That verification output is in ignored storage.
- Broader backend command below — **260 tests, 1,280 assertions, PASS** (`qa-final-related-backend.log`). The initial broader run found four attendance-blocked positive fixtures and a transient manifest failure during overlapping build cleanup; fixture corrections and a stable-asset rerun resolved all five.
- Full frontend: `npx.cmd --offline pnpm@11.14.0 run test:frontend --maxWorkers=2` — **1,826 passed, 1 timeout; 294 passing files out of 295** (`qa-full-frontend.log`). The existing application-wide modal source scan exceeded its 5s timeout under load; no assertion failed.
- Isolated rerun: `npx.cmd --offline pnpm@11.14.0 exec vitest run --maxWorkers=1 resources/js/__tests__/globalModalBackdrop.contract.test.ts` — **3 tests PASS, exit 0** (`qa-frontend-timeout-rerun.log`, invoked through `cmd.exe /d /c` to keep Node stderr warnings from being treated as PowerShell errors). The timed-out scan completed in 686ms. The full-suite timeout is retained in this record; the full suite was not rerun a second time.
- `git diff --check`: PASS during review; rerun before delivery.
- No migrations or dependency/lockfile changes. `.env` was not created or edited.
- Full `composer test`, TypeScript compiler, and frontend lint: not run. No committed compiler/lint configuration exists; selected backend suites cover the affected and specified regression domains.

The broader backend command is:

```powershell
php vendor/bin/phpunit tests/Feature/CRM/ReviewReportStateTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/SuperAdmin/AdminNotificationInboxTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php tests/Feature/Notifications tests/Feature/SuperAdmin/SuspensionAppealInvariantTest.php tests/Feature/SuperAdmin/EmployeeSuspensionProvenanceTest.php tests/Feature/Finance/FinanceShopContextTest.php tests/Feature/Finance/FinanceTenantAuthorizationTest.php tests/Feature/Finance/FinanceTaxRateAuthorizationTest.php tests/Feature/Finance/FinanceRouteContractTest.php tests/Feature/PlatformFee/PlatformFeeLedgerTest.php tests/Feature/PlatformFee/PlatformBalanceApiTest.php tests/Feature/RepairPosPaymentFlowTest.php tests/Feature/Repair/Warranty/RepairResolutionConsistencyTest.php tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php
```

## Review stack record

| Gate | Result |
| --- | --- |
| Ponytail simplify | PASS: existing model/service/config/components reused; no extra abstraction or dependency. |
| Standards, Spec, correctness | PASS, sequential review; fixed missing attendance/page-access test fixtures and retained absolute email links. |
| Clean TypeScript/React | PASS: typed new report/API fields, safe business-date narrowing, functional updates, no added production `any`. Compiler/lint not run. |
| Karpathy | PASS: surgical scope; exact acceptance criteria and regression evidence. |
| Code splitting | N/A: no heavy dependency or bundle-loading behavior added. |
| Gauge improvements | Behavior verified red-to-green; latency, bundle-size delta, and query-count improvement not measured. |
| Security | PASS: shop scoping, actor/shop ID collision, privileged route/recipient isolation, suspension provenance, Finance boundaries, and receipt/audit behavior checked. |
| Verification-before-completion | PASS with stated limit: focused/broader backend and build evidence recorded; full frontend had one timeout whose isolated rerun passed. |
| Reuse/dead-code audit | PASS: both report writers use the shared model boundary; removed the orphan local reported-ID Set. No unrelated code deleted. |

## Changed files

Production:

- `app/Http/Controllers/Api/AdminNotificationController.php`
- `app/Http/Controllers/Api/CRM/CRMReviewController.php`
- `app/Http/Controllers/Api/Finance/ExpenseController.php`
- `app/Http/Controllers/ShopOwner/CustomerReviewController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Models/ReviewReport.php`
- `app/Services/AccountSuspensionService.php`
- `app/Services/FlaggedAccountModerationService.php`
- `app/Services/SuspensionAppealService.php`
- `resources/js/Pages/ERP/CRM/CustomerReviews.tsx`
- `resources/js/Pages/ERP/Finance/Expense.tsx`

Tests:

- `tests/Feature/CRM/ReviewReportStateTest.php` (new)
- `tests/Feature/Finance/ExpenseSettlementTest.php`
- `tests/Feature/Finance/FinanceRouteContractTest.php`
- `tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php`
- `tests/Feature/SuperAdmin/AdminNotificationInboxTest.php`
- `tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php`
- `tests/Feature/SuspensionAppeals/SuspensionAppealFlowTest.php`
- `resources/js/Pages/ERP/CRM/__tests__/CustomerReviews.state.test.tsx` (new)
- `resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`
- `resources/js/components/header/__tests__/NotificationCenter.test.tsx`

Documentation:

- `docs/ai-learning-log.md`
- `docs/superpowers/plans/2026-10-06-four-qa-follow-up.md` (new)
- `docs/superpowers/plans/2026-10-06-four-qa-follow-up-results.md` (new)

## Remaining manual acceptance

Live browser QA was not performed: this isolated worktree has no configured `.env` or authenticated QA accounts. Component interaction and backend route tests cover automated behavior.

- [ ] CRM: report a fresh review, observe disabled Reported, reload, attempt duplicate, and report another review from the same customer.
- [ ] Admin: suspend through report A, resolve report B as suspend or dismiss, and verify the customer remains suspended with one effective suspension.
- [ ] Notifications: submit a fresh appeal, click its unread notification, verify the authenticated appeals listing and read state, then refresh.
- [ ] Finance: create today's expense in the configured business timezone, check date/amount/tax/receipt/settlement/audit, and forge a tomorrow request to verify useful validation feedback.
- [ ] MySQL/MariaDB concurrency: submit simultaneous duplicate-review reports and simultaneous moderation decisions for two reports on one customer. SQLite regression tests do not exercise real row-lock scheduling; production paths use existing aggregate/review row locks.

Before publication, source status contained 20 modified tracked files and 4 new files (the two regression files and two plan/results documents). Publication also includes a fresh `public/build` and its manifest. No migration, dependency, or lockfile changes. The original worktree remains on its original branch with its unrelated work preserved.

## Publication checks — 2026-10-06

- Fetched `origin/solespace-b`; it remained at `14e3db0453`, so no rebase was needed. The remote `fix/qa-four-follow-up` branch did not exist before publication.
- Re-ran the five focused backend suites listed above: **39 tests, 265 assertions, PASS** (`qa-publish-backend.log`).
- Re-ran frontend tests for the CRM report state and endpoint contract, expense modal, notification click, and previously timed-out global modal scan: **40 tests, 5 files, PASS** (`qa-publish-frontend.log`). Command: `npx.cmd --offline pnpm@11.14.0 exec vitest run --maxWorkers=2 resources/js/Pages/ERP/CRM/__tests__/CustomerReviews.state.test.tsx resources/js/Pages/ERP/CRM/__tests__/CustomerReviews.report.test.ts resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx resources/js/components/header/__tests__/NotificationCenter.test.tsx resources/js/__tests__/globalModalBackdrop.contract.test.ts`.
- Built the final source into `public/build` with `npx.cmd --offline pnpm@11.14.0 run build`; the generated manifest and assets are included in the publication commit.
- Commit/push verification and the final commit ID are reported in the delivery message. The earlier full-suite timing limitation and remaining manual acceptance checks above remain applicable.

## Dispatcher third-party visibility follow-up — 2026-10-08

The dispatcher Shipments query included third-party retail deliveries and rendered them read-only, which still exposed Lalamove jobs in its list. The query now excludes explicit and carrier-inferred third-party orders before pagination; shipment records and customer/staff tracking remain intact.

- `php artisan test tests/Feature/Logistics/DispatcherSessionAuthorizationTest.php` — 7 tests, 92 assertions, no failures (Laravel warned that this isolated worktree has no `.env`).
- `php artisan test tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php` — 59 tests, 204 assertions, no failures (same environment warning).
- `npm run build` — PASS; fresh `public/build` generated with 3,845 modules. No asset diff was needed because the generated output matched the already committed build.
- PHP syntax checks, dispatcher-test Pint check, manifest asset check (398 referenced files), and `git diff --check` — PASS. Controller Pint check still reports one pre-existing formatting issue outside this diff; unrelated lines were left untouched.

## Refunded order Fee Ledger visibility — 2026-10-08

The Platform Balance Fee Ledger now omits marketplace order charges once an associated refund succeeds, including successful partial refunds. Pending and failed refunds remain visible. The original fee charge, refund record, refund adjustments/credits, and aggregate balance accounting are preserved.

- Added an API regression test proving successful-refund rows are hidden, pending-refund rows remain, and the underlying charge/refund records remain stored.
- `php artisan test tests/Feature/PlatformFee/PlatformBalanceApiTest.php tests/Feature/PlatformFee/PlatformFeeRefundTest.php tests/Feature/PlatformFee/PlatformFeeLedgerTest.php` — **30 tests, 126 assertions, no failures**. Laravel emitted the existing missing-worktree-`.env` warning for each test.
- `npm.cmd run build` — **PASS**, Vite transformed 3,845 modules. Existing unresolved `/images/auth-geometric-pattern.svg` warning remains; generated files matched the committed `public/build`, so there is no asset diff.
- `app/Http/Controllers/Api/Finance/PlatformFeeController.php` and `tests/Feature/PlatformFee/PlatformBalanceApiTest.php` changed; no frontend bundle behavior changed.

## Registered retail warranty visibility — 2026-10-08

Registered company retail-and-repair shops store the legacy business type `both (retail & repair)`. The settings page previously hid the warranty configuration, the update request rejected saves, and fulfillment skipped warranty issuance because each path accepted only canonical `both`. All three paths now use the existing `BusinessAccessControlService` normalizer. Repair-only shops remain excluded, and customer access and warranty records are unchanged.

- Added regression coverage for visibility/save and future eligible issuance using the registered business-type value.
- `php artisan test tests/Feature/RetailWarranty` — 44 tests, 310 assertions, no failures; Laravel emits the existing warning because this worktree has no `.env`.
- `npm.cmd run test:frontend -- resources/js/Pages/ShopOwner/Settings/__tests__/shopSetting.retail-warranty.test.tsx` — 6 tests passed. Existing localStorage/CARTO test-environment warnings remain non-failing.
- `npm.cmd run build` — PASS; Vite transformed 3,845 modules. Existing unresolved `/images/auth-geometric-pattern.svg` warning remains. Fresh `public/build` is included.

## Staff refund evidence in job orders — 2026-10-08

The staff API already returned refund-request `evidence_media`, but the job-order details modal only showed separate delivery-dispute proof. The initial page props also omitted refund evidence. The page now includes that field from initial load and renders customer images in the existing evidence viewer and videos with playback controls. Staff permissions and tenant-scoped API access remain unchanged.

- `php artisan test tests/Feature/StaffOrderRefundPayloadTest.php` — 12 tests, 92 assertions, no failures; Laravel emits the existing warning because this worktree has no `.env`.
- `npm.cmd run test:frontend -- resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts -t "shows customer evidence and return logistics proof in order details|keeps the delivery proof viewer above the order details modal"` — 2 tests passed, 35 skipped.
- A fresh Vite build and `public/build` output are included with the change.
