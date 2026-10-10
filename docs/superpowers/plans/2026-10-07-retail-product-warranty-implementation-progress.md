# Retail Product Warranty implementation progress

Branch/worktree: `fix/qa-four-follow-up`, `.worktrees/qa-four-follow-up`; baseline `21cbd443629c31f4c3dee156ee105a2865866216`.

User approved implementation with ONE order issuance/reference/PDF/email and independent item coverage. The existing plan was revised before application changes. Execution is sequential; no subagents, `.env` changes or destructive database commands. The initial implementation was committed/pushed as `18b719253e`; the following owner-management removal supersedes its UI scope.

## Phase evidence so far

- A: Schema test reproduced missing tables. Four additive migrations, three models and factories created; uniqueness/default/relationships verified (9 assertions).
- B: Settings tests reproduced missing endpoint. Settings/repair regression command: `php artisan test tests/Feature/RetailWarranty/SchemaTest.php tests/Feature/RetailWarranty/SettingsTest.php tests/Feature/ShopOwner/RepairWarrantySettingsTest.php`: 8 tests, 51 assertions, no assertion failures; all report the existing absent-worktree-`.env` bootstrap warning. Frontend settings tests: 2 passed.
- C: Issuance tests reproduced missing capture service. Snapshot and supported fulfillment/payment hooks implemented. `php artisan test tests/Feature/RetailWarranty/IssuanceTest.php tests/Feature/RetailWarranty/SnapshotTest.php tests/Feature/RetailPosPaymentFlowTest.php`: 16 tests, 100 assertions, no failures; same `.env` warnings. Adjacent receipt/pickup tests also ran (23 tests without assertion failures). POS regression fixtures initially returned HTTP 423 because they lacked required attendance; added existing `clockInEmployee()` setup, keeping production middleware intact.
- D: Combined PDF test reproduced missing certificate service. Actual server PDF/private storage, all three line templates/Unicode, stable hash/bytes after retry/partial refund: 1 test, 10 assertions, no failures, existing `.env` warning.
- E: Delivery tests reproduced missing job. ONE real array-transport email/attachment for four items, replay suppression, no-recipient skip, unknown acceptance/no resend, pre-send recovery: 4 tests, 18 assertions, no failures, existing `.env` warnings.
- F: Customer access test reproduced missing route. Customer/private PDF isolation, safe projection and non-mutating expiry: 1 test, 11 assertions, no failures, same warning. Panel integration still in progress.
- G–I: Original owner history/staff access, existing refund integration and reconciliation implemented and reviewed. Owner issued-history management was subsequently removed by the approved revision; current configuration-only behavior is documented in the plan/results. See the final [results](2026-10-07-retail-product-warranty-results.md) and [file inventory](2026-10-07-retail-warranty-file-inventory.md) for the completed acceptance scenario, exact gates and remaining baseline limitations.

## Dependencies and environment

- Added `barryvdh/laravel-dompdf` v3.1.2 and Dompdf v3.1.6 with six installs, zero existing-package updates/removals. Windows Composer wrapper stripped `^` in the initial command; retried with `~3.1.1`. Installation/discovery then completed. No vendor files were edited manually.
- `composer audit --format=json` reports four advisories in three unchanged packages: Laravel debug-page XSS (low), CommonMark HTML handling (medium) and table scan denial of service (high), Flysystem malformed-path handling (low). New PDF dependencies had no reported advisory. These existing-package updates are outside this feature's scope.
- Mailable is deliberately synchronous inside an after-commit queued job so the database can record transport acceptance. Strict provider-level exactly-once remains unavailable without provider support; ambiguous states fail closed.
- Recorded original PDFs are integrity checked. A permanently missing/corrupted canonical file requires restoring the original backup; a pre-generation/storage failure can retry normally. Coverage is never deleted because a delivery channel fails.

## Remaining delivery gates

Final evidence: required three-item/refund scenario passed; full frontend 1,834/1,834 passed; fresh build passed; isolated MariaDB 325 migrations and new migration roundtrip passed; actual root-lock contention preserved one active reservation; actual delayed-payment JSON recovery preserved original policy; historical pre-removal desktop/mobile customer/owner downloads matched canonical bytes; three-page PDF inspected; syntax/manifest/diff checks passed. Full backend completed 3,892 tests with 347 failures: 346 matched the baseline and the remaining SQLite concurrency case passed its isolated rerun. This full-suite failure remains disclosed. Latest focused warranty results and sequential review stack are recorded in the results document. Publication authorized by the user with fresh `public/build`.

## Owner issued-warranty management removal revision

User-directed scope: configuration stays in Shop Settings / Operations / Retail Product Warranty. Remove issued history/search/status/pagination/details/PDF/manual-void UI and dedicated owner endpoints/catalog/helpers; no replacement page. Preserve database history, immutable snapshots, customer My Orders, staff scoped access and existing refund quantity/idempotency/certificate behavior. Existing refund assessment context has no owner PDF URL. The staff certificate action is moved to `Api/StaffRetailWarrantyController`.

Fresh revision evidence: 68 backend tests / 527 assertions with no failures (existing `.env` warnings); 27 frontend files / 126 tests passed; browser owner configuration save and absent management UI/API verified at desktop/mobile; customer canonical PDF unchanged, zero page JS errors; production build passed in 1m11s. Exact commands/review and historical full-suite limitations are recorded in the results document.
