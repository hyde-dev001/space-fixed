# Failed-class backend baseline comparison

Baseline commit: 539e204ff3e56722db810eda6df11f51790443e3. The initial full current-code Composer run failed in 77 classes. Those exact class files were run on a separate committed-HEAD archive and the current checkout, sequentially, using the same installed dependencies and PHP 8.2.12 with 1.5 GB memory.

## Isolation and evidence scope

The HEAD archive is under %TEMP%/solespace-qa-head-baseline-539e204f. Its Composer loader prioritizes archived App, Tests and Database namespaces; reflection checks reject application source resolution outside that archive. The archive uses its own bootstrap/config/routes/migrations and storage paths. Dependencies are referenced through a vendor junction; vendor files were not edited. No .env was copied. The temporary PHPUnit configuration forces testing and SQLite :memory:; no existing or production database is used.

This compares the failed-class cohort, not two complete repository suites or a deployed release. It does not prove MySQL concurrency. Seven risky handler-cleanup warnings appear in each run and remain reported separately.

| Run | Test cases | Assertions | Assertion failures | Runtime errors | JUnit passes |
| --- | --- | --- | --- | --- | --- |
| Committed HEAD | 723 | 34931 | 397 | 2 | 324 |
| Current before final shipping fixture and CSV-signature follow-ups | 724 | 35084 | 373 | 2 | 349 |

Both runs exit 2. Logs and full method-level JUnit: %TEMP%/solespace-qa-isolated-head-failed-classes.log/.xml and solespace-qa-current-failed-classes.log/.xml.

## Case-level comparison and action

- 373 failing/error cases are shared by the two runs. Attendance, catalog, unsupported mocking APIs and legacy contracts have not been weakened to pass them. Case-level matching establishes that those failures existed on this baseline; it does not establish a complete root cause or release readiness.
- 26 HEAD failures pass on the then-current checkout: 16 ManagerReport fixtures, nine intake-handoff attendance fixtures and one VAT-retry tracking fixture. One additional clocked-out intake control passes only in current.
- Two cases pass on HEAD but fail in then-current: NotificationCriticalFlowsTest's owner shipping notification and OrderFulfillmentPolicyTest's outbound idempotence. Both omitted Logistics eligibility or supported external tracking and now receive the approved shared movement denial. These were investigated rather than labelled baseline.
- Positive fixtures now use explicit third_party, carrier and tracking, preserving the plan's independently supported external transport. Notification coverage first forges unavailable shop_owned transport and requires 422, unchanged processing status, no notification row and no shipment; valid tracked external fulfillment then emits the intended notification. Outbound coverage still requires exactly one shipment and denies a repeated shipping transition. No gate or actor policy changed.
- The existing Staff generic-status denial fixture now clocks in its Staff actor so the status 422 is tested after attendance. A separate legacy customer-delivery test expected automatic COD payment settlement; committed/current OrderFulfillmentService::confirmDelivered only changes delivery state. The test now requires payment to remain pending with null paid_at. No collection or financial implementation changed.
- Fresh NotificationCriticalFlowsTest, OrderFulfillmentPolicyTest and LogisticsModuleMovementBoundaryTest pass 70 tests/240 assertions, with 249 existing deprecations (%TEMP%/solespace-qa-shipping-fixture-green.log). The final affected-file cohort passes 691 tests/4161 assertions. The two newly failed cases are resolved by legitimate prerequisites while negative coverage remains enforced.

The final monitored full Composer run after the CSV signature and fixture corrections completed: 3441 passed, 371 failed, 12 skipped, 64669 assertions, exit 2, 1624.96 seconds. All 371 remaining failing/error cases uniquely match failing/error methods in the committed-HEAD cohort above. All 337 failures with expected/actual HTTP status pairs match those HEAD pairs. Of 371 first runner reasons, 369 occur verbatim in HEAD XML; the remaining two differ only in runner display and generated Mockery class numbering. No final failure belongs to a changed/new test file. The post-run 2637-source/test fingerprint is unchanged.

Matching used exact class and normalized method names; two identical truncated PurchaseOrderReceivingTest titles were disambiguated by their test-stack file/line. Machine-readable final cases: %TEMP%/solespace-qa-final-composer-failures.json; summary: solespace-qa-final-composer-summary.json. The [full inventory](2026-10-04-full-backend-regression-findings.md) lists every exact method and reported failure. This adds full-current-code evidence but does not make the selected HEAD cohort a complete HEAD full-suite run, prove all root causes, or establish release readiness.

## Latest full comparison after C dispatch follow-up

The latest full Composer run includes the final assignment/batch/pickup and external-tracking guards: 3455 passed, 371 failed, 12 skipped, 64749 assertions, exit 2, 1221.48 seconds. All 371 case identities and first reasons match the preceding completed current-code run. Direct exact class/method lookup in the isolated HEAD JUnit uniquely matches all 371 failing/error cases. All 340 HTTP expected/actual pairs match, including redirect assertions accepting multiple codes; this broadens the earlier 337-pair count. The same two display/mock-counter differences account for first reasons not occurring verbatim in HEAD text. There are no failures in changed/new test files and no changes in the post-run 2637-file fingerprint. The affected-file cohort now passes 753 tests/4409 assertions across 62 files. Artifacts: %TEMP%/solespace-qa-final-composer-dispatch-failures.json, solespace-qa-final-composer-dispatch-summary.json and solespace-qa-final-composer-dispatch-head-comparison.json. The comparison still covers a selected HEAD cohort, and does not establish full-suite green or authenticated/runtime QA.

## Final comparison after MariaDB and inventory follow-ups

Full Composer is terminal: **3460 passed, 371 failed, 12 skipped, 64797 assertions; exit 2; 1008.05 seconds**. All 371 exact class/title/location identities and first reasons match the previously verified failure inventory; all 340 HTTP pairs match. No prior failed case is missing, no failure belongs to a changed/new test file, and the comparison reports zero anomalies. The unchanged case/method mapping retains the committed-HEAD attribution established above; the HEAD cohort was not rerun or represented as a full HEAD suite. All 2638 source/test hashes remain unchanged across the final run. The expanded affected cohort passes 783 tests/4522 assertions across 65 files. Current artifacts: `%TEMP%/solespace-qa-final-composer-mariadb-background.log/.exit`, `solespace-qa-final-composer-mariadb-failures.json`, `solespace-qa-final-composer-mariadb-summary.json` and `solespace-qa-final-targeted-inventory.log/.xml/.exit`.

The approved local MariaDB cohort separately passes 532 tests/2958 assertions plus 16 actual InnoDB locking scenarios; scratch-database cleanup is complete. This establishes local evidence for the planned C/F concurrency boundary, while authenticated deployed/provider checks and compatibility with the unknown deployed database version remain separate. The wider-suite baseline condition is met; the normal full suite still exits 2.
