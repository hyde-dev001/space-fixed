# SoleSpace ERP UI Consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Normalize the ten requested HR and Finance presentation inconsistencies while preserving every existing business workflow and data contract.

**Architecture:** Keep all behavior in the existing page components and replace only presentation classes/layout where possible. Reuse the existing `DashboardMetricCard` primitive for payroll release metrics instead of creating another metric-card implementation. Add focused source/rendering contracts that protect the requested visual states without changing API or backend code.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite.

## Global Constraints

- Change presentation only; do not change business logic, database schemas, API contracts, permissions, approval workflows, calculations, or canonical status values.
- Reuse existing SoleSpace components and styling wherever possible.
- Primary actions use the existing black/white visual language; secondary actions use neutral gray borders and text.
- Information panels use neutral gray surfaces; semantic data statuses retain their existing meaning and colors.
- Do not add dependencies or perform unrelated refactors.

---

### Task 1: HR lifecycle and invitation presentation

**Files:**
- Modify: `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`
- Test: `resources/js/Pages/ERP/HR/__tests__/hr-ui-consistency.visual.test.ts`

**Interfaces:**
- Consumes: Existing invitation, suspension, termination, and rehire handlers and modal state.
- Produces: The same handlers and modal content with black primary invitation action and neutral gray informational panels.

- [x] Add source assertions for the black invitation action and gray informational panels.
- [x] Run the HR presentation test and confirm it fails against the current blue/red classes.
- [x] Change only the relevant invitation button and informational panel classes; keep labels, handlers, disabled states, and text unchanged.
- [x] Run the focused HR presentation test and confirm it passes.

### Task 2: Attendance correction action

**Files:**
- Modify: `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`
- Test: `resources/js/Pages/ERP/HR/__tests__/hr-ui-consistency.visual.test.ts`

**Interfaces:**
- Consumes: Existing `handleSaveEdit` callback and edit form state.
- Produces: The same correction submission with the canonical black primary button.

- [x] Add an assertion that the Save Correction button uses black/white classes and not blue primary classes.
- [x] Run the focused test to observe the expected failure.
- [x] Replace only the button presentation classes.
- [x] Re-run the focused test.

### Task 3: Overtime action spacing

**Files:**
- Modify: `resources/js/Pages/ERP/HR/OvertimeApprovals.tsx`
- Test: `resources/js/Pages/ERP/HR/__tests__/hr-ui-consistency.visual.test.ts`

**Interfaces:**
- Consumes: Existing `setIsAssignModalOpen(true)` handler and Assign Overtime modal flow.
- Produces: A compact right-aligned action with its intentional plus icon and no oversized padding/shadow.

- [x] Add assertions for `inline-flex`, compact `px-4 py-2`, standard text sizing, and the retained modal handler.
- [x] Run the focused test to observe the expected failure.
- [x] Replace the ad-hoc header button sizing with the compact existing action pattern; do not change the modal button or assignment handler.
- [x] Re-run the focused test.

### Task 4: Payroll release metrics

**Files:**
- Modify: `resources/js/Pages/ERP/HR/generateSlip.tsx`
- Test: `resources/js/Pages/ERP/HR/__tests__/hr-ui-consistency.visual.test.ts`

**Interfaces:**
- Consumes: Existing `governanceStatus`, loading state, and release-readiness text.
- Produces: Two `DashboardMetricCard` instances with unchanged values and descriptions.

- [x] Add assertions for the existing `DashboardMetricCard` import and two release metric usages, and for removal of the one-off card wrapper.
- [x] Run the focused test to observe the expected failure.
- [x] Import and use the existing dashboard metric component with existing icons and neutral tone; preserve all governance expressions exactly.
- [x] Re-run the focused test.

### Task 5: Finance expense procurement panel

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/Finance.presentation-consistency.test.ts`

**Interfaces:**
- Consumes: Existing `activeExpense.procurement_details` rendering.
- Produces: The same procurement fields in a white/neutral panel.

- [x] Add assertions that the procurement panel uses white/neutral classes and no blue surface classes.
- [x] Run the focused Finance presentation test to observe the expected failure.
- [x] Replace only the panel surface and heading color classes.
- [x] Re-run the focused test.

### Task 6: Create Invoice header action

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/createInvoice.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/Finance.presentation-consistency.test.ts`

**Interfaces:**
- Consumes: Existing `invoicesUrl`, `handleSaveInvoice`, and loading state.
- Produces: A responsive header row with Back on the left and Save Invoice on the right.

- [x] Add assertions for the responsive `justify-between` header and black Save Invoice action.
- [x] Run the focused test to observe the expected failure.
- [x] Combine the existing back link and save action into one responsive header row without changing either handler.
- [x] Re-run the focused test.

### Task 7: Invoice modal and page action neutrality

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/Invoice.tsx`
- Test: `resources/js/Pages/ERP/Finance/__tests__/Finance.presentation-consistency.test.ts`

**Interfaces:**
- Consumes: Existing invoice download, archive/restore, mark-sent, close, and row-action handlers.
- Produces: Black Download primary action and neutral Archive/Restore/Mark as sent/Close controls; semantic status badges remain unchanged.

- [x] Add assertions for black Download, neutral modal actions, and preserved semantic status classes.
- [x] Run the focused test to observe the expected failure.
- [x] Normalize only decorative action colors and borders in the touched invoice view/modal.
- [x] Re-run the focused test.

### Task 8: Regression and delivery gates

**Files:**
- Modify: `docs/superpowers/plans/2026-09-07-erp-ui-consistency.md`
- Include: fresh generated `public/build/**`

**Interfaces:**
- Consumes: All completed page and test changes.
- Produces: Verified feature branch ready for the user’s PR targeting `solespace-b`.

- [x] Run focused HR and Finance Vitest tests.
- [x] Run the full frontend Vitest suite.
- [x] Run `vite build` and include the fresh `public/build` output.
- [x] Run `git diff --check` and inspect the final diff for unrelated changes.
- [x] Run `php artisan test tests/Feature/Logistics`; report missing Composer dependencies if the checkout cannot run it.
- [x] Fetch and rebase onto `origin/solespace-b`, commit, push `feature/customer-navigation-dropup`, and verify the remote branch is clean and synchronized.
