# Payslip Deduction Percentages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Display each payslip deduction's effective percentage of gross pay consistently in My Payslips, Generate Payslip, Finance approval, and Shop Owner approval.

**Architecture:** Keep payroll calculations and persisted records unchanged. Reuse the existing gross and deduction amounts at each presentation boundary through one shared TypeScript percentage/formatting utility, then render the indicator beside each deduction amount and total.

**Tech Stack:** Laravel 12/PHP 8.2 backend payloads, React 18, TypeScript, Tailwind CSS, Vitest, PHPUnit.

---

### Task 1: Add the shared deduction-percentage contract

**Files:**
- Create: `resources/js/utils/payrollDeductions.ts`
- Test: `resources/js/utils/__tests__/payrollDeductions.test.ts`

- [x] **Step 1: Write failing helper tests**

Cover a normal amount (`720` from gross `16117.80` → `4.47%`), string inputs, negative deduction values, and zero/missing gross pay (`0.00%`).

- [x] **Step 2: Run the focused test and confirm it fails**

Run: `pnpm.cmd exec vitest run resources/js/utils/__tests__/payrollDeductions.test.ts`

Expected: FAIL because the utility does not exist yet.

- [x] **Step 3: Implement the smallest shared utility**

Export a zero-safe numeric percentage function and a formatter that returns a two-decimal percentage string. Use the absolute deduction amount for the ratio and preserve the existing currency formatting outside this helper.

- [x] **Step 4: Run the focused test and confirm it passes**

Run: `pnpm.cmd exec vitest run resources/js/utils/__tests__/payrollDeductions.test.ts`

Expected: PASS.

### Task 2: Add indicators to employee and generation breakdowns

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/MyPayslips.tsx`
- Modify: `resources/js/Pages/ERP/HR/generateSlip.tsx`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/MyPayslips.layout.test.ts`
- Test: `resources/js/__tests__/generateSlipLayout.test.ts`

- [x] **Step 1: Add presentation assertions**

Assert that the breakdowns label the percentage column as `% of gross pay`.

- [x] **Step 2: Run the affected layout tests and confirm the new assertions fail**

Run: `pnpm.cmd exec vitest run resources/js/Pages/ERP/STAFF/__tests__/MyPayslips.layout.test.ts resources/js/__tests__/generateSlipLayout.test.ts`

Expected: FAIL on the new assertions.

- [x] **Step 3: Wire the utility into both pages**

Render the existing deduction rows with amount plus the shared percentage using the existing gross value. Include fallback statutory rows and total deductions; keep print markup aligned with the on-screen table. In Generate Payslip, apply the same display to the single preview and show the total percentage in the batch/summary views where only total deductions are available.

- [x] **Step 4: Run the affected layout tests**

Run: `pnpm.cmd exec vitest run resources/js/Pages/ERP/STAFF/__tests__/MyPayslips.layout.test.ts resources/js/__tests__/generateSlipLayout.test.ts`

Expected: PASS.

### Task 3: Add indicators to Finance and Shop Owner approval details

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/payslipApproval.tsx`
- Modify: `resources/js/components/owner-action-center/approvals/PayslipApprovalDetails.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/payslipApproval.layout.test.ts`
- Test: `resources/js/components/owner-action-center/__tests__/approvalRendererParity.test.tsx`

- [x] **Step 1: Add failing approval-view assertions**

Assert the Finance modal and Shop Owner renderer expose `% of gross pay` and render a sample line percentage from the existing line-item shape.

- [x] **Step 2: Run the focused approval tests and confirm they fail**

Run: `pnpm.cmd exec vitest run resources/js/Pages/ERP/Finance/__tests__/payslipApproval.layout.test.ts resources/js/components/owner-action-center/__tests__/approvalRendererParity.test.tsx`

Expected: FAIL on the new assertions.

- [x] **Step 3: Implement the shared approval presentation**

Use the selected request's gross pay as the denominator in Finance and the generic owner detail's gross value in Shop Owner approval. Preserve existing approval actions, status fields, and fallback note behavior.

- [x] **Step 4: Run the focused approval tests**

Run: `pnpm.cmd exec vitest run resources/js/Pages/ERP/Finance/__tests__/payslipApproval.layout.test.ts resources/js/components/owner-action-center/__tests__/approvalRendererParity.test.tsx`

Expected: PASS.

### Task 4: Run final quality gates

**Files:**
- Verify: all changed files above plus `public/build/` generated output.

- [x] **Step 1: Run the full focused frontend regression set**

Run: `pnpm.cmd exec vitest run resources/js/utils/__tests__/payrollDeductions.test.ts resources/js/Pages/ERP/STAFF/__tests__/MyPayslips.layout.test.ts resources/js/__tests__/generateSlipLayout.test.ts resources/js/Pages/ERP/Finance/__tests__/payslipApproval.layout.test.ts resources/js/components/owner-action-center/__tests__/approvalRendererParity.test.tsx`

Expected: all listed files pass.

- [x] **Step 2: Run the focused payroll backend suite**

Run: `php artisan test tests/Unit/Services/PayrollServiceTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php`

Expected: PASS; no payroll calculation or approval behavior changes are introduced.

- [x] **Step 3: Build and inspect the diff**

Run: `pnpm.cmd run build` and `git diff --check`.

Expected: production build succeeds, generated `public/build` is fresh, and diff hygiene is clean.

- [x] **Step 4: Review and commit**

Review changed files for reused helpers, unused imports, stale assertions, and accessibility of the new table labels. Commit the feature and generated build only after all gates pass.
