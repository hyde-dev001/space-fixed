# Automatic clock-out hours correction ? October 26, 2026

Worktree `.worktrees/failed-delivery-recovery`, branch `codex/failed-delivery-recovery`. Starting HEAD `6bde8d2d398149029011fdeae2d3d8e6e9809acb`. The preceding logistics work is committed/pushed and untouched. This follow-up is local and uncommitted; no deployment, production connection/migration, environment edit, dependency change or payroll execution occurred.

## Reproduced defects

1. `AutoClockOutEmployees` selected only today's attendance after today's closing time. Missing the closing-day scheduler run left previous-day checkouts blank permanently, including when the job next ran just after midnight.
2. The command saved checkout first, then calculated recorded-lunch hours. The model's saving hook recalculated both saves using its legacy flat one-hour deduction and overwrote the supplied result. Approved overtime was also not persisted separately by the command. A later notes edit could overwrite finalized hours again.
3. Comparing overtime grace as `H:i:s` made a grace end after midnight sort before the current evening, closing the record too early. Time-only early-departure comparison also falsely marked approved next-day overtime as an early departure and could calculate negative departure minutes.

Five initial regressions failed against the original implementation. Additional failing cases demonstrated note-edit corruption, paid-overtime history mutation, approved-start mismatch and the next-day early-departure flag before their corresponding fixes.

## Implementation

- `app/Console/Commands/AutoClockOutEmployees.php`: process due open records in chunks. Anchor times to each attendance date in the configured shop timezone. Prefer the saved expected closing time; historical records without that evidence are left open with a review warning, rather than inferring old schedules from current settings. Use a transaction and row lock, recheck open state, and save checkout, regular hours, approved overtime and automatic reason together. Deduct completed recorded lunch only where it overlaps the worked interval. Keep the 30-minute overtime grace, approved start and hour cap. Preserve manual checkouts and future/not-yet-due records. Skip payroll-linked/paid overtime for HR review. Use the same employee/shop pairing for attendance and overtime.
- `app/Models/HR/AttendanceRecord.php`: retain authoritative hours supplied by finalization instead of overwriting them. Notes-only saves leave finalized hours intact; existing fallback calculation remains for time edits without supplied calculations. Compare expected and actual departures on the attendance/overnight dates and store nonnegative early-departure minutes. No permission, MFA, attendance-entry, payroll rate or payment approval was weakened.
- `tests/Feature/HR/AutoClockOutEmployeesTest.php`: regression coverage for recorded lunch, missed-day recovery/snapshot preservation, replay, overtime separation/cap/start/grace, midnight/overnight work, invalid/unknown historical cutoffs, paid overtime protection, zero hours, unchanged closed/future/other-shop records, scheduler registration, authenticated owner read projection and manual checkout.

No frontend application source, build, route, schema or scheduler frequency changed. The registered task remains every 15 minutes with overlap and single-server protection. Payroll calculations consume the finalized regular/overtime fields; this fix does not generate or settle payroll.

## Verification

Synthetic PHPUnit fixtures only; database is disposable in-memory SQLite from `phpunit.xml`. No command was run against the developer or production database.

- Initial command regressions: **6 tests / 15 assertions / 5 expected failures** before implementation.
- Later-note regression: **11 tests / 44 assertions / 1 failure** before the save-hook time-change guard; the same suite then passed.
- Final targeted command: `php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/HR/AutoClockOutEmployeesTest.php tests/Feature/HR/AttendanceLunchTest.php tests/Feature/HR/AttendanceLatenessPrecisionTest.php tests/Feature/HR/AttendanceClockInEvidenceTest.php tests/Feature/HR/PayrollControllerTest.php tests/Feature/ShopOwner/AttendanceGraceSettingsTest.php tests/Feature/Auth/EmployeeMfaLoginFlowTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php --display-skipped`. **104 tests / 787 assertions passed**, exit 0; `output/attendance-final-targeted.log`.
- Wider HR command: **171 tests / 1,092 assertions / 21 failures**, all unrelated employee account/creation, leave or suspension tests receiving HTTP 423. A separate bootstrap loaded the unchanged command/model from HEAD without overwriting the working files and reproduced the exact same 21 test failures (**32 tests / 68 assertions / 21 failures**). Tests/middleware were not weakened. Evidence: `output/attendance-regressions.log`, `attendance-baseline-failures.log` and ignored `output/attendance-baseline/`.
- Installed Pint passes for the rewritten command/test. The model change follows its existing formatting; no repository-wide lint/type/format pass is claimed. PHP syntax and `git diff --check` verified separately. Frontend/build checks are N/A: frontend bytes, including the production manifest, match HEAD.

Sequential review: minimum existing command/model scope; no new attendance engine or dependency. Reuse audit: existing Eloquent transactions, Carbon, recorded schedule/overtime fields, activity logging and owner page projection. Standards/spec/risk review confirms no production writes, no fabricated manual clock-out or approved overtime, no cross-shop overtime lookup, no override of completed attendance, and no paid-overtime history rewrite. No new TS/React or bundle change; those reviews are N/A. Improvement evidence is functional, not a measured performance claim. Changed areas checked for stale imports and duplicate writes.

## Production and historical-data limits

The screenshot shows blank checkout as well as `0h`, consistent with an open record; it does not prove the Hostinger scheduler ran. This local fix cannot establish the production cron configuration, last successful scheduler run, deployed PHP/command version, cache-lock state or actual shop/employee schedule data. The existing Laravel scheduler must be invoked by the host's every-minute scheduler; this command itself becomes due every 15 minutes. Verify that through the normal separately authorized release process and retain command logs. Do not run a second competing scheduler or disable attendance/security controls to compensate.

After approved deployment, the normal task can close eligible missed records using saved schedules. Missing snapshots, clock-ins later than the recorded cutoff and paid/payroll-linked overtime are deliberately left for HR investigation. An automatic cutoff is a policy timestamp, not new proof that an employee physically worked until that time. HR must review disputed records and any already-finalized payroll before historical correction. Previously closed rows with incorrect hours are not bulk-rewritten by this task. No production backfill, browser walkthrough or MariaDB race was performed for this follow-up.

Final hygiene: all three changed PHP files parse; Pint passes the command/test; `git diff --check` passes. HEAD and index remain at the published checkpoint, with these changes unstaged. Frontend/build, dependency/lock and tracked OCR runtime paths are unchanged. No production command or historical backfill was executed.


## Separately authorized publication

The user subsequently authorized committing and pushing this completed fix to `codex/failed-delivery-recovery`. A fresh pre-publication rerun passed **104 tests / 787 assertions**, exit 0 (`output/attendance-publish-tests.log`); Pint, PHP syntax, unchanged approved file hashes and diff hygiene also passed. Fetch confirmed the local/remote feature starting SHA matched `6bde8d2d398149029011fdeae2d3d8e6e9809acb`. Only this command, model, regression test, execution record and learning note are included. Local output/fixtures/databases, credentials, environment files and unrelated work are excluded. No rebase, force push, merge, deployment or production command is authorized by this publication. The earlier local/uncommitted descriptions above record the implementation checkpoint before this separate authorization.
