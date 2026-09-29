# Payroll, Payslip, and Disbursement Accuracy

## Goal

Make the existing payroll workflow reconcile from period-effective salary and approved attendance through payslip approval and Finance disbursement without changing the approval stages or rewriting historical payrolls.

## Constraints

- Work only in the existing `fix/procurement-supplier-payment-workflow` worktree.
- Preserve unrelated work; do not switch branches, commit, or push.
- Keep statutory rates configured and effective-dated; do not invent or silently replace government tables.
- Use the existing `PayrollService` calculation path and Finance linkage.

## Implementation plan

1. Add failing regression coverage for period-effective salary, late/half-day attendance, canonical net reconciliation, stale approval, and disbursement amount/idempotency.
2. Remove payroll generation's automatic salary-change application; resolve the rate from the covered period and retain a calculation snapshot on generated payrolls.
3. Align batch attendance classification with the approved attendance statuses used by the attendance flow.
4. Add one decimal-safe payroll money/reconciliation path and use it before final approval and disbursement; fix the model's duplicate statutory deduction fallback.
5. Snapshot approval financial values, reject stale approvals, and prevent financial component/recalculation mutations after approval starts.
6. Keep disbursement amount equal to the stored reconciled `net_salary`, check the `markAsPaid` result, and preserve the existing unique Finance settlement/idempotency linkage.
7. Run the focused Laravel tests, diff hygiene, and the frontend tests/build only if changed frontend behavior requires it; record any pre-existing failures separately.

## Verification

- `php artisan test tests/Unit/Services/PayrollServiceTest.php tests/Feature/HR/PayrollControllerTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Feature/Finance/PayrollDisbursementFinanceSyncTest.php`
- New focused payroll/reconciliation tests.
- `git diff --check`

## Verification record

- PHP syntax checks passed for every changed PHP file.
- Payroll unit tests: 11 passed, 79 assertions.
- Focused finance/HR/attendance regression run: 43 passed, 312 assertions.
- Finance-to-owner queue and attendance auto-clockout regressions: 40 passed, 301 assertions, including the deterministic company-owner queue fixture.
- Auto-clockout persistence is now covered by a regression test; the command writes the close time and its reason marker together.
- Frontend tests: 268 files and 1,626 tests passed; one unrelated existing `OwnerApprovalDetailPanel` ordering assertion failed (1 file/1 test). The repository-pinned `pnpm` command is unavailable in this environment, so the existing Vitest binary was invoked directly.
- The focused frontend rerun was blocked by the local `node_modules` Vitest junction resolving to another worktree and Node returning `EPERM`; this change only extends the existing payslip response type and does not alter UI behavior.
- Vite production build passed after 3,831 modules transformed; generated `public/build` artifacts were restored and are not part of the change.
- Composer validation and `git diff --check` passed.
