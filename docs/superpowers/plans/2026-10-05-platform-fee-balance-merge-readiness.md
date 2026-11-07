# Platform fee balance merge preparation

User-authorized scope: rebase, verify, commit and push only `fix/platform-fee-balance`. The final merge into `solespace-b` is reserved for the user. No PR, merge or deployment is part of this task.

## Initial state and classification

- Worktree: `C:/xampp/htdocs/solespace-master/.worktrees/platform-fee-balance`.
- Initial HEAD: `539e204ff3e56722db810eda6df11f51790443e3`.
- Fetched target: `origin/solespace-b` at `92759450c2dda69ec10c691631edd62398385e91`.
- Initial relationship: 0 ahead, 5 behind; not diverged. Initial feature remote also points at the initial HEAD.
- Full snapshot: 414 tracked changes and 352 untracked files. Tracked diff: 414 files, 5127 insertions, 5026 deletions. Source-only tracked diff: 110 files, 2828 insertions, 956 deletions.
- A: 137 intended QA source/test/documentation files. B: 607 stale tracked/untracked build outputs. C: 21 file-cache/debug artifacts. D: one pre-existing custody plan.
- No conflict markers and no overlap between upstream application paths and the intended QA changes. All 2638 previously verified source/test hashes match. CodeGraph was consulted but reports a different-worktree index, so current target files and recorded hashes remain authoritative.
- Complete per-file status/class/reason/hash inventory, raw `git status --short`, raw `git diff --stat`, source patch and pre-rebase file backup: `%TEMP%/solespace-merge-ready-20261005/`. The ZIP contains only dirty/untracked paths; no ignored environment file is copied.

A includes the approved A–K implementation, regression prerequisites and inventory screenshot follow-up. The additional publication hygiene changes are this record and one ignore rule for untracked Laravel file cache. No middleware, payment, accounting or application rule is changed by the publication steps.

D is `docs/superpowers/plans/2026-10-03-shop-owned-logistics-custody-remediation.md`: preserve its exact content in a separate named local stash, exclude it from the published commit, and report the recoverable stash identifier. Cache contents stay local/ignored. The accidental root file `2` is backed up and excluded. Build output is regenerated after rebase because this repository tracks `public/build/manifest.json` and deployment assets.

## Intended paths before publication hygiene

| Class | Path |
| --- | --- |
| A | `app/Enums/NotificationType.php` |
| A | `app/Http/Controllers/Api/Finance/PlatformFeeController.php` |
| A | `app/Http/Controllers/Api/ManagerController.php` |
| A | `app/Http/Controllers/Api/RepairPosController.php` |
| A | `app/Http/Controllers/Api/RepairRequestController.php` |
| A | `app/Http/Controllers/Api/RepairWorkflowController.php` |
| A | `app/Http/Controllers/Api/StaffOrderController.php` |
| A | `app/Http/Controllers/PaymongoWebhookController.php` |
| A | `app/Listeners/NotifySupplierOrderOverdue.php` |
| A | `app/Listeners/SendLowStockNotification.php` |
| A | `app/Listeners/SendOutOfStockNotification.php` |
| A | `app/Models/RepairRequest.php` |
| A | `app/Notifications/LowStockNotification.php` |
| A | `app/Notifications/SupplierOrderOverdueNotification.php` |
| A | `app/Observers/RepairRequestPlatformFeeObserver.php` |
| A | `app/Services/Logistics/AssignmentService.php` |
| A | `app/Services/Logistics/BatchDispatchService.php` |
| A | `app/Services/Logistics/LogisticsActorPolicy.php` |
| A | `app/Services/Logistics/ShipmentLegService.php` |
| A | `app/Services/Logistics/ShipmentRequestService.php` |
| A | `app/Services/Logistics/SourceShipmentService.php` |
| A | `app/Services/Manager/ManagerReportService.php` |
| A | `app/Services/NotificationService.php` |
| A | `app/Services/OrderRefundService.php` |
| A | `app/Services/PaymentSettlementService.php` |
| A | `app/Services/PlatformBalanceService.php` |
| A | `app/Services/PlatformFeeLedgerService.php` |
| A | `app/Services/RepairDeliveryService.php` |
| A | `app/Services/RepairMaterialPlanningService.php` |
| A | `app/Services/RepairOnlineRefundWorkflowService.php` |
| A | `app/Services/RepairPosRefundService.php` |
| A | `app/Services/RepairWarrantyService.php` |
| A | `app/Services/ShopOwnerActorUserResolver.php` |
| A | `app/Support/Finance/FinanceShopContext.php` |
| A | `config/shop_modules.php` |
| A | `database/factories/OrderRefundFactory.php` |
| A | `database/factories/ShopOwnerFactory.php` |
| A | `docs/ai-learning-log.md` |
| A | `resources/js/Pages/ERP/Finance/Invoice.tsx` |
| A | `resources/js/Pages/ERP/Finance/PlatformBalance.test.tsx` |
| A | `resources/js/Pages/ERP/Finance/PlatformBalance.tsx` |
| A | `resources/js/Pages/ERP/Finance/__tests__/Invoice.owner-actions.test.tsx` |
| A | `resources/js/Pages/ERP/Manager/Reports.tsx` |
| A | `resources/js/Pages/Notifications/NotificationList.tsx` |
| A | `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx` |
| A | `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.service-modification.test.ts` |
| A | `resources/js/Pages/UserSide/Repairs/myRepairs.tsx` |
| A | `resources/js/components/common/NotificationBell.tsx` |
| A | `resources/js/components/header/NotificationCenter.tsx` |
| A | `resources/js/components/header/__tests__/NotificationCenter.test.tsx` |
| A | `resources/js/hooks/__tests__/useNotifications.contract.test.tsx` |
| A | `resources/js/hooks/useNotifications.ts` |
| A | `resources/js/layout/AppHeader.tsx` |
| A | `resources/js/providers/QueryProvider.tsx` |
| A | `routes/shop-owner-erp-api.php` |
| A | `tests/Feature/Customer/RepairServiceModificationTest.php` |
| A | `tests/Feature/Finance/FinanceTenantAuthorizationTest.php` |
| A | `tests/Feature/Finance/InvoicePaymentTest.php` |
| A | `tests/Feature/Finance/PayslipApprovalWorkflowTest.php` |
| A | `tests/Feature/Logistics/AssignmentServiceTest.php` |
| A | `tests/Feature/Logistics/BatchDispatchServiceTest.php` |
| A | `tests/Feature/Logistics/BusinessReferenceNumberTest.php` |
| A | `tests/Feature/Logistics/DeliveryExecutionTest.php` |
| A | `tests/Feature/Logistics/DeliveryIncidentServiceTest.php` |
| A | `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php` |
| A | `tests/Feature/Logistics/LogisticsConcurrencyTest.php` |
| A | `tests/Feature/Logistics/LogisticsNotificationTest.php` |
| A | `tests/Feature/Logistics/ReturnToShopTest.php` |
| A | `tests/Feature/Logistics/ShipmentLegServiceTest.php` |
| A | `tests/Feature/Logistics/ShipmentLegStatusConsumerTest.php` |
| A | `tests/Feature/Logistics/ShipmentRequestServiceTest.php` |
| A | `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php` |
| A | `tests/Feature/Logistics/StaffRetailShippingCoverageTest.php` |
| A | `tests/Feature/Manager/ManagerOrderAssignmentTest.php` |
| A | `tests/Feature/Manager/ManagerReportTest.php` |
| A | `tests/Feature/Manager/ManagerStaffWorkloadTest.php` |
| A | `tests/Feature/Notifications/InventoryNotificationTest.php` |
| A | `tests/Feature/Notifications/NotificationCriticalFlowsTest.php` |
| A | `tests/Feature/OrderRefundReturnInspectionTest.php` |
| A | `tests/Feature/Orders/OrderFulfillmentPolicyTest.php` |
| A | `tests/Feature/PaymentLifecycleFeatureTest.php` |
| A | `tests/Feature/PlatformFee/PlatformBalanceApiTest.php` |
| A | `tests/Feature/PlatformFee/PlatformFeeAdminBoundaryTest.php` |
| A | `tests/Feature/PlatformFee/PlatformFeeLedgerTest.php` |
| A | `tests/Feature/PlatformFee/PlatformFeePaymentWorkflowTest.php` |
| A | `tests/Feature/Repair/RepairAddressSnapshotTest.php` |
| A | `tests/Feature/Repair/RepairDeliveryQuoteTest.php` |
| A | `tests/Feature/Repair/RepairDeliveryReconciliationTest.php` |
| A | `tests/Feature/Repair/RepairIntakeHandoffTest.php` |
| A | `tests/Feature/Repair/RepairLogisticsIntakeTest.php` |
| A | `tests/Feature/Repair/RepairLogisticsPaymentTest.php` |
| A | `tests/Feature/Repair/RepairLogisticsReturnTest.php` |
| A | `tests/Feature/Repair/RepairReturnHandoffTest.php` |
| A | `tests/Feature/Repair/RepairReturnRecoveryTest.php` |
| A | `tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php` |
| A | `tests/Feature/Repair/Warranty/RepairWarrantyEligibilityTest.php` |
| A | `tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php` |
| A | `tests/Feature/RepairMixedRefundSplitSettlementTest.php` |
| A | `tests/Feature/RepairOnlineRefundAuthorizationTest.php` |
| A | `tests/Feature/RepairPackage/RepairPackageApiTest.php` |
| A | `tests/Feature/RepairPosManualQueueTest.php` |
| A | `tests/Feature/RepairPosPaymentFlowTest.php` |
| A | `tests/Feature/RepairPosRefundFlowTest.php` |
| A | `tests/Feature/RepairRefundSourceBoundaryTest.php` |
| A | `tests/Feature/RepairVatInclusivePayloadTest.php` |
| A | `tests/Feature/Repairer/RepairMaterialCompletionVarianceTest.php` |
| A | `tests/Feature/Repairer/RepairMaterialPlanningGateTest.php` |
| A | `tests/Feature/Repairer/RepairMaterialTemplateApiTest.php` |
| A | `tests/Feature/ShopOwner/RepairMaterialUsageApiTest.php` |
| A | `tests/Feature/StaffOrderRefundPayloadTest.php` |
| A | `app/Services/Logistics/LogisticsMovementEligibility.php` |
| A | `app/Services/PlatformFeeSourceReferenceResolver.php` |
| A | `app/Services/RepairResolutionEligibilityService.php` |
| A | `database/migrations/2026_10_04_000001_add_material_plan_snapshot_to_repair_requests.php` |
| A | `docs/superpowers/plans/2026-10-04-backend-baseline-comparison.md` |
| A | `docs/superpowers/plans/2026-10-04-deployed-qa-audit-and-remediation-plan.md` |
| A | `docs/superpowers/plans/2026-10-04-deployed-qa-final-verification.md` |
| A | `docs/superpowers/plans/2026-10-04-deployed-qa-implementation-progress.md` |
| A | `docs/superpowers/plans/2026-10-04-full-backend-regression-findings.md` |
| A | `docs/superpowers/plans/2026-10-04-isolated-mariadb-verification.md` |
| A | `docs/superpowers/plans/2026-10-04-platform-fee-repair-dry-run-review.md` |
| A | `docs/superpowers/plans/2026-10-04-report-csv-excel-verification.md` |
| A | `resources/js/Pages/ERP/Manager/__tests__/Reports.owner-download.test.tsx` |
| A | `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.payment-return.test.tsx` |
| A | `resources/js/hooks/__tests__/useNotifications.identity.test.tsx` |
| A | `resources/js/hooks/useNotificationIdentity.ts` |
| A | `resources/js/layout/__tests__/AppHeader.notification-identity.test.tsx` |
| A | `resources/js/providers/__tests__/QueryProvider.notifications.test.tsx` |
| A | `tests/Feature/Finance/FinanceShopContextTest.php` |
| A | `tests/Feature/Finance/OwnerInvoiceReadBoundaryTest.php` |
| A | `tests/Feature/Logistics/LogisticsModuleMovementBoundaryTest.php` |
| A | `tests/Feature/Manager/ManagerReportCsvExportTest.php` |
| A | `tests/Feature/Repair/RepairMaterialSnapshotTest.php` |
| A | `tests/Feature/Repair/Warranty/RepairResolutionConsistencyTest.php` |
| A | `tests/Feature/ShopOwner/OwnerManagerReportDownloadTest.php` |
| A | `tests/Feature/ShopOwner/ShopOwnerActorUserClassificationTest.php` |
| A | `tests/Unit/InventoryAlertMailTest.php` |

## Execution and verification

Rebase completed onto `92759450c2dda69ec10c691631edd62398385e91`. Because the initial feature HEAD was already an ancestor of this target, no published commit was rewritten; the feature pointer advanced by the five upstream commits. No merge into `solespace-b` occurred. All 137 intended files match the pre-rebase backup after line-ending normalization, and all 12 upstream source/documentation paths remain unmodified relative to the target. No unmerged index entries or conflict markers remain.

Recovery: pre-existing custody document stash `56a913bc51046ff9137ed67650fc2d9987b566dc`; intended-change stash `f16e906655ef589887d16587fe3d914ec3c6a60f`. Both are local and retained. The backup ZIP also retains original bytes. The first intended stash invocation had an incorrectly formed PowerShell path-list argument and made no source stash; the corrected invocation succeeded. Stash restoration then encountered transient Windows access failures on two test files. ACLs matched an accessible control, later non-writing handle probes succeeded, and only those two files were restored in place from the verified backup. No ACL change, process termination, business-rule change or test rewrite was used.

| Fresh check | Result |
| --- | --- |
| `git diff --check`; unmerged/conflict scan | PASS after restoration. |
| PHP syntax | 106 intended PHP files pass. |
| Phase 4 `pnpm run build` | PASS, exit 0, 34.63 seconds. |
| `pnpm run test:frontend --maxWorkers=4` | PASS, exit 0, 293 files/1813 tests, 292.16 seconds. Existing localStorage/map-key/test warnings remain in the log. |
| Phase 5 `pnpm run build` | PASS, exit 0, 19.77 seconds. Both post-rebase builds have identical file hashes. Manifest: 395 entries; 397 build files; no missing assets/imports. |
| Affected backend QA | PASS, exit 0: 65 files, 783 tests/4522 assertions, zero errors/failures/skips; 196.028 seconds. Eleven existing PHPUnit deprecations. `php -d memory_limit=1536M vendor/bin/phpunit --log-junit <temp>/affected.xml <65 paths>`. |
| Full backend | FAIL, exit 2: 3462 passed, 371 failed, 12 skipped, 64803 assertions; 1031.70 seconds. Original `composer test`, `COMPOSER_PROCESS_TIMEOUT=0`, temporary `PHP_INI_SCAN_DIR` with memory_limit=1536M. Monitored process is terminal. |

Artifact prefix: `%TEMP%/solespace-merge-ready-20261005/`. Source/test/config fingerprint covers 2645 files and is unchanged across frontend, both builds, affected backend and full Composer. All 397 build hashes also remain unchanged. No backend suites ran concurrently. Stage, commit and feature-only push follow this pre-commit record; their final metadata belongs in the handoff, since this document cannot include its own commit SHA.

All 371 full-suite failed cases uniquely match the previously verified pre-rebase failure inventory. All first reasons and all 340 expected/actual HTTP pairs are unchanged. No prior failed case disappeared, no unmatched failed case appeared, and no failed case is in an intended QA test file. The two upstream-added backend cases account for the two additional passes. The prepared committed-target archive was not needed for a new-case investigation and no tests were run in it.

Classification: all 371 are verified **pre-existing failures relative to this QA remediation**, not newly introduced regressions. Observed outcomes are 300 attendance 423, 34 authorization 403, two unsupported test/method APIs and 35 other assertions/errors across 72 classes. These outcome categories do not establish every root cause; unresolved existing application/fixture issues remain recorded in the [method inventory](2026-10-04-full-backend-regression-findings.md). The full suite is not green. Twelve driver/extension-dependent skips remain. No enforcement or expectation was weakened to bypass a failure. Comparison: `backend-full-summary.json` and `backend-full-failures.json` under the artifact prefix.

Earlier checkout totals are historical. Authenticated deployed/provider QA remains a separate manual requirement.

## Publication review

The previously recorded sequential review of the QA implementation remains applicable: all intended application and test files match their verified pre-rebase contents after line-ending normalization. The upstream changes have no overlapping QA application paths and remain intact. This publication step adds no application behavior or dependency. Fresh integration checks above replace the earlier checkout's build/frontend/affected-backend evidence.

| Gate | Publication result |
| --- | --- |
| simplify / reuse | Existing services, framework features and capability projection retained. Publication uses Git rebase, explicit staging and the existing pnpm/Composer commands. No dependency or generic abstraction added. |
| Standards / Spec / correctness | Current branch/path and fetched base verified. Exact A/B/C/D inventory and backup retained. QA changes restored without choosing either side blindly; unrelated custody document preserved separately. Critical resolution, module, tenant, finance capability and notification identity boundaries rechecked against current source. |
| clean-code-typescript / React | Original typed identity/capability and stale-request handling review retained. Complete fresh frontend suite passes. TypeScript compiler/linter not run: no committed compiler configuration or lint script. |
| karpathy-guidelines | Minimal publication changes: cache ignore rule and documentation. No business-rule or test-expectation change to force results green. |
| code-splitting / gauge-improvements | No new runtime import or bundle split. Two fresh production builds agree byte-for-byte. Query count, render latency and bundle performance improvements not measured. |
| security / Laravel | Tenant, RBAC, attendance, module checks, provider verification and accounting rules retained. No environment-file edit, production payment, backfill or database reset. Real inbox tests retain denied-recipient controls; owner exports retain tenant/status/path/formula boundaries. |
| dead-code / reuse audit | No application code removed by publication. New resolution, movement, reference and notification identity helpers retain callers and focused coverage. Replaced notification facade imports remain removed; unfamiliar legacy components remain untouched. |
| verification-before-completion | Fresh frontend/build/affected-backend results pass. Full Composer is terminal with exactly 371 baseline-matched failures, no changed reasons/status pairs or intended-test failures, and unchanged source fingerprints. Final staging/remote checks follow. No claim of a green full suite or deployed verification. |

## Remaining manual QA and rollout

Authenticated testing must establish that the deployed release contains this commit, then exercise notification role/shop switching; repair refund versus warranty; full/partial service refunds versus delivery-only compensation; Logistics OFF creation and already-started continuation; independent third-party retail tracking; repair fee finalization and ledger totals; owner invoice read-only actions; owner report download and readable CSV; stock-entry alert delivery; and signed PayMongo sandbox redirects with server verification. Local tests do not establish deployed behavior.

Apply the additive `2026_10_04_000001_add_material_plan_snapshot_to_repair_requests.php` migration before code that uses the column. The previous authorized MariaDB/InnoDB evidence remains historical: 532 tests/2958 assertions and 16 observed independent-connection locking scenarios, followed by deletion of the owned scratch database. Publication does not rerun database races or establish compatibility with an unknown deployed database version. Historical fee backfill remains outside this authorization.
