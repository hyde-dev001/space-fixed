# Finance Payslip Approval UI Fix

## Goal

Keep Finance payslip search responsive and make the payslip detail modal present the same earnings/deductions breakdown used by My Payslips.

## Design

- Keep the existing `/api/finance/payslip-approvals` fetch workflow and typed `line_items` payload.
- Debounce search requests by 300ms, cancel stale list requests, and reserve the full-page loading state for the initial load. Filtering and pagination stay in the existing page without an Inertia navigation or modal reset.
- Group existing detail line items by `type` into Earnings and Deductions sections, show Gross Pay, Total Deductions, and Net Pay using the existing API totals, and keep approval/disbursement history intact.
- Widen the detail modal to `max-w-6xl` and use compact responsive grids so desktop users can see the complete detail without a nested modal scroll region. Small viewports may stack the same content vertically.

## Constraints

- No new payroll tables, endpoints, or duplicate breakdown component.
- Existing approval, disbursement, authorization, and batch workflows remain unchanged.
- Preserve accessible labels and close/action controls.

## Verification

- Focused Vitest contract test for debounced search/background loading and grouped breakdown/modal layout.
- `pnpm run test:frontend -- resources/js/Pages/ERP/Finance/__tests__/payslipApproval.layout.test.ts`
- `pnpm run test:frontend`
- `pnpm run build`
- `git diff --check`
