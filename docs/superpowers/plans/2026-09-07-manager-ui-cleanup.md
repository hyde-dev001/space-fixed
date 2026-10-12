# Manager ERP UI Cleanup Implementation Plan

> **For agentic workers:** Execute inline in this session with focused verification after each coherent change.

**Goal:** Remove the specified Manager-only decorative headers and Refresh controls, and align Audit Logs pagination with the existing Manager pagination UI without changing business behavior.

**Architecture:** Keep all existing page hooks, API calls, filters, actions, and sidebar navigation intact. Delete only presentation wrappers/controls that are explicitly requested, and copy the established pagination markup pattern from `SuspensionApprovals.tsx` into `AuditLogs.tsx` while preserving its current page state and request flow.

**Tech Stack:** Laravel 12, Inertia, React 18, TypeScript, Tailwind CSS, Vitest.

## Global Constraints

- UI/presentation changes only unless dead frontend code is made unused by a removal.
- Do not change Manager permissions, workflows, inventory/report/audit logic, APIs, schemas, statuses, or query behavior.
- Reuse existing SoleSpace components and pagination patterns; do not add a new pagination abstraction.
- Preserve unrelated working-tree changes.

---

### Task 1: Inspect affected Manager pages and canonical pagination

**Files:**
- Read: `resources/js/Pages/ERP/Manager/{JobOrders,RepairJobs,InventoryOverview,StaffWorkload,LeaveApprovals,Reports,AuditLogs}.tsx`
- Read: `resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx`

- [ ] Confirm the exact header/Refresh markup, related imports/state, and pagination behavior before editing.

### Task 2: Remove requested Manager presentation elements

**Files:**
- Modify: `resources/js/Pages/ERP/Manager/JobOrders.tsx`
- Modify: `resources/js/Pages/ERP/Manager/RepairJobs.tsx`
- Modify: `resources/js/Pages/ERP/Manager/InventoryOverview.tsx`
- Modify: `resources/js/Pages/ERP/Manager/StaffWorkload.tsx`
- Modify: `resources/js/Pages/ERP/Manager/LeaveApprovals.tsx`
- Modify: `resources/js/Pages/ERP/Manager/Reports.tsx`
- Modify: `resources/js/Pages/ERP/Manager/AuditLogs.tsx`

- [ ] Delete only the specified page-level header/label blocks and standalone Refresh buttons.
- [ ] Remove only imports/state/handlers made unused by those deletions.
- [ ] Preserve all data loading, filters, actions, workflow handlers, and sidebar labels.

### Task 3: Standardize Audit Logs pagination

**Files:**
- Modify: `resources/js/Pages/ERP/Manager/AuditLogs.tsx`
- Reference: `resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx`

- [ ] Replace Audit Logs’ Page X of Y controls with the existing compact numbered pagination structure and visual classes used by the canonical Manager page.
- [ ] Keep current `goToPage`, disabled/loading behavior, pagination metadata, and query state unchanged.
- [ ] Ensure controls retain accessible labels and responsive layout.

### Task 4: Verify and review

**Files:**
- Test: `resources/js/Pages/ERP/Manager/__tests__/*.contract.test.ts`

- [ ] Run affected Manager contract tests.
- [ ] Run the frontend test suite and production build if dependencies/tooling permit.
- [ ] Run `git diff --check`.
- [ ] Review the diff for backend/business-logic changes, dead imports, blank spacing, and unrelated edits.
