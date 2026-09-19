# Repair job orders layout refinement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the ERP repair job orders summary, table spacing, and status presentation with the approved monochrome visual direction, and remove the Warranty Queue refresh control without changing behavior.

**Architecture:** Keep the existing page components, APIs, filters, actions, and status mapping. Apply presentation-only Tailwind changes in the ERP repairer pages and protect them with focused source/visual tests.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Tailwind CSS, Vitest, Testing Library, Vite.

## Global Constraints

- No API, endpoint, filter, pagination, modal, action, or status-transition changes.
- Scope is limited to the ERP repairer job-orders/warranty pages and their focused tests.
- Table status labels are plain black text with no background in light mode; retain a readable dark-mode text variant.
- Preserve existing black active tab states, responsive overflow, and all row interactions.

---

## Task 1: Add focused regression coverage first

**Files:**
- Create `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.visual.test.tsx`
- Modify `resources/js/Pages/ERP/repairer/__tests__/WarrantyQueue.test.tsx`

- [x] Add a source-level visual regression test that asserts the workload summary is right-aligned and the job-order table status cell uses monochrome, background-free classes.
- [x] Add a component test asserting the Warranty Queue no longer renders a `Refresh Queue` button after its initial data load.
- [x] Run the focused tests and confirm they fail against the current implementation for the intended reasons.

## Task 2: Fix repairer page controls and summary placement

**Files:**
- Modify `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- Modify `resources/js/Pages/ERP/repairer/WarrantyQueue.tsx`

- [x] Change the Job Orders Repair header wrapper so the Active workload and Pending refund reviews block is aligned to the right.
- [x] Remove only the visible Warranty Queue `Refresh Queue` control; preserve the existing automatic refresh/data-fetch behavior.
- [x] Run the focused tests and confirm the new behavior passes.

## Task 3: Improve job-orders table spacing and normalize status labels

**File:**
- Modify `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`

- [x] Give the table a stable minimum width with horizontal overflow protection and rebalance its column widths for the visible content.
- [x] Increase header/body cell padding and align cells vertically for cleaner row rhythm without changing row data or actions.
- [x] Render the primary and secondary job-order status labels as readable black text without colored backgrounds or pill styling.
- [x] Preserve non-status content styling, including product/service indicators and action controls.
- [x] Run the focused tests again.

## Task 4: Verify, review, and record the change

- [x] Run the full frontend test suite with the repository’s direct Vitest binary if the pnpm wrapper remains unavailable.
- [x] Run the Vite production build with the repository’s direct Vite binary.
- [x] Run `git diff --check` and inspect the final diff for unrelated changes, stale imports, and preserved behavior.
- [x] Record the review results: simplify pass, standards/spec review, TypeScript/React review, Karpathy checklist, and verification evidence.
- [x] Commit the implementation and generated build output together if the build updates tracked assets.

### Review Record

- Simplify/ponytail: pass; the change reuses the existing page structure and fetch lifecycle, with no new dependency or abstraction.
- Standards/spec: pass; only the approved ERP repairer layout/status presentation and Warranty Queue control were changed.
- TypeScript/React: pass; no new state, effect, API call, assertion, or `any` was introduced.
- Karpathy: pass; the diff is surgical and keeps existing data, filters, actions, and row behavior intact.
- Security/code splitting: N/A; no backend, authorization, input, endpoint, or bundle-loading behavior changed.
- Gauge improvements: not measured; this is a visual-only refinement.
- Verification: focused tests 5/5, full Vitest 1304/1304, Vite build passed, and `git diff --check` passed.

## Verification Commands

```powershell
.\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.visual.test.tsx resources/js/Pages/ERP/repairer/__tests__/WarrantyQueue.test.tsx
.\\node_modules\\.bin\\vitest.cmd run
.\\node_modules\\.bin\\vite.cmd build
git diff --check
```
