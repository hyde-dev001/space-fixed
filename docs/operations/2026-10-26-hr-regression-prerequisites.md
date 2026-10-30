# HR regression prerequisites ? October 26, 2026

Worktree `.worktrees/failed-delivery-recovery`; branch `codex/failed-delivery-recovery`; starting HEAD `3ba757d16fe528bfdaa6169eb7c443d10a2a6d39`. This fixes the 21 existing failures disclosed during auto clock-out verification. It is a test-fixture correction, not a production attendance/authorization change. No commit, push, merge, rebase, deployment, production database command, environment edit, migration or dependency change was performed.

## Confirmed causes

The affected tests authenticated HR/Manager/Staff actors without creating active attendance. `EnsureEmployeeClockedIn` correctly returned HTTP 423 with `EMPLOYEE_NOT_CLOCKED_IN`, before their expected business success, validation, permission or tenant response could execute. The existing `Tests\TestCase::clockInEmployee` helper already provides the normal isolated attendance fixture. The fix calls it explicitly for the actors in these five suites; it does not change the base helper, bypass middleware, grant extra permissions or alter expected outcomes.

After attendance was supplied, 18 failures cleared. The remaining three employee-creation cases returned HTTP 422: their `location` is mapped to address by the existing controller and therefore requires province, city/municipality and a four-digit postal code. The tests now submit complete synthetic addresses. The original role/alias, business-type and created-account assertions remain.

| Test file | Correction |
| --- | --- |
| `tests/Feature/HR/EmployeeAccountRecoveryTest.php` | Clock in the HR actor; retain reset/invitation/audit and cross-shop assertions. Add an explicit negative request proving an HR actor without active attendance still receives 423 and cannot change the target account or create a reset audit event. |
| `tests/Feature/HR/EmployeeCashierCreationTest.php` | Clock in HR in the existing local fixture helper; complete all three address payloads. Add a negative case proving incomplete addresses still receive field errors and create neither user nor employee. |
| `tests/Feature/HR/EmployeeSuspensionWorkflowTest.php` | Clock in the existing HR actor before mutation tests. |
| `tests/Feature/HR/LeaveControllerTest.php` | Clock in the existing HR and Manager actors; retain leave-balance, approval, overlap, sorting and tenant assertions. |
| `tests/Feature/HR/SuspensionWorkflowAuthorizationTest.php` | Clock in HR and each deliberately unauthorized actor so permission/workflow denials are exercised after the attendance prerequisite. Preserve all original denial codes, shop boundaries, activation and audit assertions. |

The first diagnostic negative test compared an unrefreshed factory model against a fully hydrated row and initially failed on database-default attributes. Refreshing the baseline snapshot fixes that assertion setup; its 423/code assertion already passed. This diagnostic is not an extra application failure.

## Verification

Tests use the disposable in-memory SQLite database from `phpunit.xml`, synthetic users and the existing HTTP middleware. Source inspection traced CodeGraph's different-worktree warning to the root index; actual feature-worktree files were authoritative.

- The five-suite red run reproduced the existing 21 failures; the initial diagnostic snapshot mismatch above was recorded separately in the same run (33 tests / 71 assertions / 22 failures).
- Supplying attendance reduced the five-suite command to **3 failures**, all required-address validation errors (33 tests / 126 assertions).
- Corrected five suites plus both negative checks: **34 tests / 139 assertions passed**, exit 0; `output/hr-prerequisites-final-targeted.log`.
- Wider original HR/attendance/MFA/Logistics-page command: **178 tests / 1,200 assertions passed**, exit 0, no skips; `output/hr-prerequisites-wide.log`. This supersedes the earlier report's 21 unresolved failures.
- All five changed PHP test files parse; `git diff --check` passes. Production source, routes, config, build, OCR and dependency files match HEAD; staging index remains empty.

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/HR/EmployeeAccountRecoveryTest.php tests/Feature/HR/EmployeeCashierCreationTest.php tests/Feature/HR/EmployeeSuspensionWorkflowTest.php tests/Feature/HR/LeaveControllerTest.php tests/Feature/HR/SuspensionWorkflowAuthorizationTest.php
php -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/HR tests/Feature/ShopOwner/AttendanceGraceSettingsTest.php tests/Feature/Auth/EmployeeMfaLoginFlowTest.php tests/Feature/Logistics/LogisticsPageAccessTest.php --display-skipped
git diff --check
```

## Review and limits

Sequential reuse/simplification, Standards/spec, correctness and security review: existing attendance helper used only in relevant fixtures; original success/denial assertions preserved; new negative tests demonstrate both guards remain effective. No global auto-login/attendance setup, disabled middleware, extra capability, replacement business service or application behavior change. Dead-code/import scan found no new unused helpers. TS/React, bundle splitting, frontend build and browser walkthrough are N/A for test-only changes. Improvement measured as the original failure set clearing in the wider command; runtime performance was not measured. Durable fixture guidance is in `docs/ai-learning-log.md`.

The entire repository suite was not run. This correction does not verify Hostinger's production scheduler or deploy the preceding auto clock-out code; those release checks remain separate. Existing deferred financial-screenshot privacy exposure is unchanged. Ignored/untracked output, local databases, credentials and other worktrees remain untouched.


## Separately authorized publication

The user subsequently authorized commit/push of the completed test-fixture correction. A fresh publication run again passed **178 tests / 1,200 assertions**, exit 0, no skips (`output/hr-prerequisites-publish.log`). The eight approved test/documentation paths matched their completed verification hashes; PHP syntax and diff hygiene passed. Local and remote feature starting SHA both matched `3ba757d16fe528bfdaa6169eb7c443d10a2a6d39`. Production source, build/OCR assets and dependencies remain unchanged, so no frontend rebuild is needed. Output, databases, credentials and unrelated files are excluded. Earlier uncommitted descriptions refer to the implementation checkpoint. This publication does not authorize deployment, merge, force push or production database commands.
