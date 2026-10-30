# Batches Action Menu Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Remove the urgent stop action, eliminate duplicate view icons, and render the three-dot menu outside the table clipping context.

**Architecture:** Keep existing batch callbacks and API behavior. Render only the workflows still needed in the table plus the details action in `BatchTable`; use unboxed Lucide icon buttons. Remove route and history summary primary actions while retaining the details modal as the read-only history/route inspection surface. Replace the native in-row details menu with a small body-level fixed portal anchored to the three-dot button.

**Tech Stack:** React 18, TypeScript, Tailwind CSS, Lucide React, Vitest, Vite.

## Global Constraints

- Do not change backend routes, API payloads, or persistence behavior.
- Remove only the urgent UI control; keep existing urgent badges/counts and data display.
- Preserve accessible labels, titles, focus rings, and keyboard escape/outside-click behavior.
- Preserve unrelated working-tree changes.

---

### Task 1: Simplify visible batch actions

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchTable.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchHistoryModal.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`

- [ ] Remove the stop urgent-toggle prop/button and its now-unused page handler/wiring.
- [ ] Keep urgent labels and counts unchanged.
- [ ] Render the Pencil edit action only for drafts; remove accepted View route and completed/cancelled View summary actions while keeping one Eye details action for those rows.
- [ ] Keep one Eye details action for every row, including history.
- [ ] Remove colored backgrounds/borders from Pencil, Eye, and More actions; use hover text color and focus rings.

### Task 2: Render More actions outside the table

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchTable.tsx`

- [ ] Replace the in-row native details menu with a controlled icon button.
- [ ] Position the menu with `createPortal(..., document.body)` and fixed coordinates derived from the trigger rect.
- [ ] Close on menu selection, outside pointer down, and Escape; keep action callbacks unchanged.

### Task 3: Update regression coverage

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] Remove tests that require the deleted urgent button and assert it is absent from stop rows.
- [ ] Assert accepted/history rows expose only one Eye details action and no View route/summary action.
- [ ] Assert icon buttons use the intended titles/classes and the More actions menu is rendered outside the table row.
- [ ] Run focused tests, full frontend tests, production build, and `git diff --check`.
