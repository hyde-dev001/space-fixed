# Logistics Batches Mobile Tablet Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the ERP Batches page polished and touch-friendly below `xl` while preserving the existing desktop rendering and batch behavior.

**Architecture:** Keep the current Batches state, callbacks, API services, and component boundaries. Add compact-only Tailwind variants in the existing page/components: stack the workspace and filters below `xl`, render a card view of batches below `xl`, and keep the current table/flex/grid presentation behind `xl` classes. Reuse existing status/action components and dialog semantics instead of adding dependencies or new business logic.

**Tech Stack:** Laravel/Inertia page, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, Vite.

## Global Constraints

- Treat widths below Tailwind `xl` as the compact ERP experience.
- At `xl` and above, preserve the current Batches page header, two-column workspace, active/history tables, modal sizing, and action placement.
- Do not change backend routes, API payloads, validation, permissions, state transitions, dependencies, or business rules.
- Use `DESIGN.md`: near-black ink, white/soft-cloud surfaces, restrained borders, 8px spacing rhythm, semantic status colors, and blue for primary interactive emphasis.
- Keep interactive controls at least 44px high/wide and avoid page-level horizontal overflow below `xl`.
- Preserve unrelated working-tree edits and stage only the Batches implementation, tests/spec/plan, and generated `public/build`.

---

### Task 1: Make the compact Batches shell and filters overflow-safe

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx` (page shell/header/workspace responsive classes)
- Modify: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx` (compact filter/card spacing)
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

**Interfaces:**
- Consumes the existing `Batches` state and `AvailableDeliveriesPanel` props/callbacks.
- Produces the same props and handlers; only class names and stable compact test hooks change.

- [ ] **Step 1: Write failing compact layout assertions**

Add assertions to the existing responsive workspace test:

```tsx
expect(screen.getByTestId('batch-page-main')).toHaveClass('overflow-x-clip');
expect(screen.getByTestId('batch-workspace')).toHaveClass('xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]');
expect(screen.getByTestId('batch-workspace')).not.toHaveClass('lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]');
expect(screen.getByTestId('batch-filter-grid')).toHaveClass('grid-cols-1');
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL because the current page has no `batch-page-main`/`batch-filter-grid` hooks and the two-column workspace begins at `lg`.

- [ ] **Step 3: Implement the compact shell**

Update the page root and workspace classes without changing handlers:

```tsx
<main data-testid="batch-page-main" className="min-w-0 space-y-6 overflow-x-clip p-4 sm:p-6">
  <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
    {/* existing title, module filter, and New Batch controls */}
  </div>
  <div
    id="batch-workspace"
    data-testid="batch-workspace"
    className={`grid min-w-0 scroll-mt-28 gap-5 ${deliveriesCollapsed
      ? 'xl:grid-cols-1'
      : 'xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]'}`}
  >
```

Keep the page header controls wrapped below `xl`, and add `min-w-0` to the header/content wrappers so long delivery text cannot force the page wider than the viewport.

- [ ] **Step 4: Implement compact filter spacing**

In `AvailableDeliveriesPanel.tsx`, add `data-testid="batch-filter-grid"` to the filter grid and use a mobile-first layout:

```tsx
<div data-testid="batch-filter-grid" className="mt-3 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
```

Use `min-w-0` on the search label and delivery cards, keep every input/select `min-h-11`, and replace the list wrapper padding with `p-1 sm:p-2` so cards retain breathing room without an oversized nested gutter.

- [ ] **Step 5: Run the focused test and verify it passes**

Run the same Vitest command. Expected: all existing Batches tests pass and the new compact class assertions pass.

- [ ] **Step 6: Commit the shell/filter slice**

```bash
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "fix: make batches compact filters overflow safe"
```

---

### Task 2: Add the compact active/history batch cards while retaining the desktop table

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchTable.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

**Interfaces:**
- Consumes the existing `BatchTable` props and all current callback signatures.
- Produces the same actions (`onOpen`, `onDetails`, `onReview`, `onCancel`, `onRestore`) through compact cards; the existing table remains the `xl` view.

- [ ] **Step 1: Write failing compact-card assertions**

Extend the active/history table test:

```tsx
expect(screen.getByTestId('compact-batch-list')).toBeInTheDocument();
expect(screen.getByTestId('compact-batch-list')).toHaveClass('xl:hidden');
expect(screen.getByTestId('desktop-batch-table')).toHaveClass('hidden', 'xl:block');
expect(screen.getByTestId('compact-batch-list')).toHaveTextContent('Batch #2');
```

Add a card action test that clicks `View details for batch 2` inside the compact list and confirms the existing Batch details dialog opens.

- [ ] **Step 2: Run the focused test and verify it fails**

```bash
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL because `BatchTable` currently renders only the horizontally scrollable table.

- [ ] **Step 3: Add compact card rendering**

Keep the current table markup intact inside a wrapper with `data-testid="desktop-batch-table"` and `className="hidden xl:block"`. Add a sibling `data-testid="compact-batch-list"` with `className="space-y-3 xl:hidden"`.

Each compact card must include:

```tsx
<article className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition dark:border-gray-700 dark:bg-gray-800">
  <div className="flex min-w-0 items-start justify-between gap-3">
    <BatchSummary batch={batch} />
    <span className="shrink-0 text-xs font-semibold text-gray-500">
      {legsFor(batch).length}/{batch.capacity} stops
    </span>
  </div>
  <div className="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-700">
    <div><p className="text-xs text-gray-500">Schedule</p><p className="mt-1 font-semibold">{formatDate(batch.delivery_date)} Â· {label(batch.delivery_window)}</p></div>
    <div><p className="text-xs text-gray-500">Rider</p><p className="mt-1 font-semibold">{batch.rider_profile?.name || 'Not assigned'}</p></div>
  </div>
  <div className="mt-4 flex flex-wrap gap-2">
    <button type="button" aria-label={`View details for batch ${batch.id}`} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-gray-200 px-3 text-sm font-semibold">View details</button>
  </div>
</article>
```

Use the same icon buttons and labels currently used by the table, but let the action row wrap and keep all buttons `min-h-11 min-w-11`. Do not duplicate status/action business rules outside the existing helpers.

- [ ] **Step 4: Run the focused test and verify it passes**

Run the Batches test file. Expected: compact list/card assertions and all prior active/history behavior tests pass.

- [ ] **Step 5: Commit the card slice**

```bash
git add resources/js/Pages/ERP/Logistics/components/BatchTable.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "fix: add compact batches cards"
```

---

### Task 3: Polish compact workspace stops and Batches dialogs

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchDetailsModal.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchHistoryModal.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

**Interfaces:**
- Consumes the existing workspace, stop, modal props, callbacks, and focus management.
- Produces the same drag/reorder, remove, save, review, history, details, rider selection, and offer interactions with compact-only layout variants.

- [ ] **Step 1: Write failing compact surface assertions**

Add assertions after opening a new batch and a details/history dialog:

```tsx
expect(screen.getByRole('button', { name: 'Save Draft' })).toHaveClass('sm:w-auto');
expect(screen.getByRole('dialog', { name: 'Batch history' })).toHaveClass('max-h-[100dvh]');
expect(screen.getByRole('dialog', { name: 'Batch 6 details' })).toHaveTextContent('Stops in this batch');
```

Add stable test ids only where a class assertion needs a wrapper; do not change dialog roles or focus behavior.

- [ ] **Step 2: Run the focused test and verify the new assertions fail**

```bash
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL on the compact action/surface assertions before the class updates.

- [ ] **Step 3: Implement compact workspace/stop/modal styling**

Apply compact-only class variants:

- Workspace footer: `flex-col items-stretch sm:flex-row sm:items-center`, make the primary save/offer actions `w-full sm:w-auto`, and keep the footer `sticky bottom-0` with safe bottom padding.
- Stop row: use `min-w-0`, allow the metadata/action groups to wrap, and keep move/remove buttons at least 44px without changing DnD callbacks.
- Details/history/offer dialog content: use `max-h-[100dvh] p-4 sm:max-h-[90vh] sm:p-7`, stack metric grids at mobile and use `sm:grid-cols-2 xl:grid-cols-3`, and wrap footer actions as `flex-col-reverse sm:flex-row` with full-width mobile buttons.
- Keep `BatchTable` compact cards active inside history dialogs through the same `xl:hidden`/`hidden xl:block` variants.

- [ ] **Step 4: Run the focused related test set**

```bash
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: all related tests pass.

- [ ] **Step 5: Commit the workspace/dialog slice**

```bash
git add resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx resources/js/Pages/ERP/Logistics/components/BatchDetailsModal.tsx resources/js/Pages/ERP/Logistics/components/BatchHistoryModal.tsx resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "fix: polish compact batch workspace surfaces"
```

---

### Task 4: Review, build, and hand off the branch

**Files:**
- Modify: generated `public/build/`
- Review: all files changed by Tasks 1–3 and the approved spec/plan

- [ ] **Step 1: Run the frontend quality gates**

```bash
node_modules/.bin/vitest.cmd run
node_modules/.bin/vite.cmd build
git diff --check
```

Expected: Vitest passes, Vite reports a successful production build, and `git diff --check` prints no errors.

- [ ] **Step 2: Inspect compact/desktop scope and dead references**

Confirm compact markup uses `<xl` visibility classes, the existing desktop table/header/workspace classes remain behind `xl`, no unused imports or abandoned test hooks remain, and only the intended files are staged.

- [ ] **Step 3: Stage only the implementation and fresh build**

```bash
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx resources/js/Pages/ERP/Logistics/components/BatchTable.tsx resources/js/Pages/ERP/Logistics/components/BatchWorkspace.tsx resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx resources/js/Pages/ERP/Logistics/components/BatchDetailsModal.tsx resources/js/Pages/ERP/Logistics/components/BatchHistoryModal.tsx resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx public/build
git commit -m "fix: polish batches mobile tablet experience"
```

- [ ] **Step 4: Rebase and push safely**

Preserve unrelated changes with `git stash push -u`, run `git fetch origin --prune`, rebase onto `origin/solespace-b`, restore with `git stash pop`, then push:

```bash
git push -u origin feature/shipment-tracking-modal
```

Verify `HEAD` equals `origin/feature/shipment-tracking-modal` and report the commit hash, test/build results, and the preserved unrelated local files.
