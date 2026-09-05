# Batches Icon Actions and Workspace Focus Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace batch text actions with accessible ERP-style icon buttons and collapse Available deliveries while editing an existing batch.

**Architecture:** Keep the existing `BatchTable` action callbacks and `Batches.tsx` state flow. Add one local `deliveriesCollapsed` state, pass it to `AvailableDeliveriesPanel`, and use CSS to collapse the panel without unmounting it. Reuse installed Lucide icons and existing action semantics.

**Tech Stack:** React 18, TypeScript, Inertia, Tailwind CSS, Lucide React, Vitest, Vite.

## Global Constraints

- Do not modify backend routes, controllers, API payloads, or database behavior.
- Preserve unrelated working-tree changes.
- Keep icon-only controls keyboard accessible with `aria-label`, `title`, focus styles, and a minimum 44px touch target.
- Use existing Lucide icons and existing component boundaries; add no dependencies.

---

### Task 1: Add the focused workspace state

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`

**Interfaces:**
- `AvailableDeliveriesPanel` receives `collapsed: boolean`.
- `Batches` sets `collapsed` to `true` in `openBatch` and `false` in `startNewBatch`.

- [ ] Add `deliveriesCollapsed` state defaulting to `false`.
- [ ] Pass `collapsed={deliveriesCollapsed}` to the panel.
- [ ] Apply collapsed presentation while keeping the panel mounted, including an accessible button to reopen it.
- [ ] Keep current filter and selection state intact while collapsed.

### Task 2: Convert batch primary/detail actions to icons

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchTable.tsx`

**Interfaces:**
- Preserve `onOpen`, `onDetails`, and secondary action callback signatures.

- [ ] Import `Eye` and `Pencil` from `lucide-react`.
- [ ] Render Pencil for draft primary actions and Eye for route/progress/summary actions.
- [ ] Render Eye for View details in active and history tables.
- [ ] Add descriptive `aria-label` and `title` values, preserving current accessible action names.
- [ ] Keep secondary actions unchanged.

### Task 3: Update regression tests

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] Assert icon-only buttons are available by accessible name in active and history contexts.
- [ ] Assert opening a batch collapses Available deliveries and leaves the workspace visible.
- [ ] Assert New Batch reopens Available deliveries.
- [ ] Run the focused test file and fix only regressions caused by this change.

### Task 4: Verify and review

**Files:**
- No source changes expected.

- [ ] Run the full frontend suite.
- [ ] Run the production build.
- [ ] Run `git diff --check`.
- [ ] Review changed-file scope and confirm no backend/API files changed.
