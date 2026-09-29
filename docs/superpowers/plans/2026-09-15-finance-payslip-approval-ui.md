# Finance Payslip Approval UI Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Stop per-keystroke full-page loading in Finance payslip approval and align its detail breakdown with My Payslips.

**Architecture:** Keep the existing Finance page and fetch endpoint. Add a debounced, abortable list refresh that only uses the full-page spinner for the first load, then render the existing typed detail line items in compact earnings/deductions sections inside a wider modal.

**Tech Stack:** React 18, TypeScript, Inertia page shell, Tailwind CSS, Vitest.

---

### Task 1: Add focused regression coverage

**Files:**
- Create: `resources/js/Pages/ERP/Finance/__tests__/payslipApproval.layout.test.ts`

- [ ] Write source-contract assertions for debounced search, abortable list fetches, background loading, grouped earnings/deductions, totals, and the wider non-scroll detail modal.
- [ ] Run the focused test and confirm it fails against the current page.

### Task 2: Fix search refresh behavior

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/payslipApproval.tsx`

- [ ] Add the smallest debounce and abort-controller state around the existing list fetch.
- [ ] Separate initial loading from subsequent search/filter/page refreshes so the page remains mounted while results update.
- [ ] Ignore aborted requests without showing an error dialog.
- [ ] Run the focused regression test.

### Task 3: Align the detail breakdown and modal layout

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/payslipApproval.tsx`

- [ ] Derive earnings and deduction rows from existing `line_items` without changing approval data contracts.
- [ ] Render the same labels/sections/totals as My Payslips and retain notes, approval history, disbursement details, and actions.
- [ ] Widen and compact the modal, preserving responsive stacking and accessible close/action controls.
- [ ] Run the focused test and the full frontend test suite.

### Task 4: Verify the handoff

**Files:**
- No additional source files.

- [ ] Run `pnpm run build`.
- [ ] Run `git diff --check`.
- [ ] Inspect the final diff for unrelated changes, stale imports, and accidental backend/procurement edits.
