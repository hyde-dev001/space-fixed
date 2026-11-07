# Logistics Batches Table and Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Replace inline batch and history expansion on the ERP Logistics Batches page with a responsive active-batches table, modalized batch stop details, and a history button/modal while preserving existing operational actions.

**Architecture:** Keep `Batches.tsx` as the state and mutation coordinator. Replace the page-only expandable `BatchCard` with a reusable `BatchTable` that renders desktop table rows and mobile stacked rows. Add `BatchDetailsModal` for stop details and `BatchHistoryModal` for the history list; both use the existing `Modal` component and return focus to their triggers. Update the delivery-proof viewer close icon to black in the existing Shipments component.

**Tech Stack:** Laravel/Inertia React 18, TypeScript, Tailwind CSS 4, existing `Modal`, `lucide-react`, `react-dnd`, Vitest, Testing Library, Vite.

## Global Constraints

- Do not change backend routes, controllers, database queries, API contracts, or mutation handlers.
- Reuse existing `DeliveryBatch`, `TrackingShipmentLeg`, `BatchStopRow`, `Modal`, and logistics formatting conventions.
- Keep `Edit batch`, `View offer`, `View route`, `View progress`, `Review & Offer`, cancel, restore, reorder, and urgent-stop behavior intact.
- Use accessible buttons with descriptive labels, visible focus states, Escape/backdrop close behavior, and 44px minimum interactive targets.
- Do not stage or modify unrelated working-tree files: `package-lock.json`, `.pnpm-store/`, or `DESIGN.md`.
- Generated `public/build` must be regenerated after implementation and included in the feature commit.

### Task 1: Add failing Batches UX regression tests

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- Reference: `resources/js/Pages/ERP/Logistics/Batches.tsx`

**Interfaces:**
- Tests consume the existing mocked `DeliveryBatch` props and mutation spies.
- The tests will establish the public roles and labels used by `BatchTable`, `BatchDetailsModal`, and `BatchHistoryModal`.

- [ ] **Step 1: Add the active table contract test**

Add a test fixture with at least one active batch and assert:

```tsx
expect(screen.getByRole('table', { name: 'Active batches' })).toBeInTheDocument();
expect(screen.getByRole('columnheader', { name: 'Batch' })).toBeInTheDocument();
expect(screen.getByRole('row', { name: /Batch #2/ })).toBeInTheDocument();
expect(screen.getByRole('button', { name: 'View details for batch 2' })).toBeInTheDocument();
expect(screen.queryByRole('button', { name: 'Expand batch 2' })).not.toBeInTheDocument();
```

- [ ] **Step 2: Add the batch-details modal contract test**

Click `View details for batch 2` and assert that the labelled dialog shows the stop, while the stop is not rendered as an inline expanded section:

```tsx
fireEvent.click(screen.getByRole('button', { name: 'View details for batch 2' }));
expect(screen.getByRole('dialog', { name: 'Batch 2 details' })).toBeInTheDocument();
expect(screen.getByRole('dialog', { name: 'Batch 2 details' })).toHaveTextContent('Order #81');
expect(screen.getByRole('button', { name: 'Close batch details' })).toBeInTheDocument();
expect(screen.queryByRole('button', { name: 'Expand batch 2' })).not.toBeInTheDocument();
```

- [ ] **Step 3: Add the history button/modal test**

With one completed or cancelled batch, assert that history is a button beside the active filters and opens a labelled dialog:

```tsx
const historyButton = screen.getByRole('button', { name: 'History (1)' });
expect(historyButton).toBeInTheDocument();
fireEvent.click(historyButton);
expect(screen.getByRole('dialog', { name: 'Batch history' })).toBeInTheDocument();
expect(screen.getByRole('dialog', { name: 'Batch history' })).toHaveTextContent('Batch #6');
```

- [ ] **Step 4: Add keyboard/focus and preserved-action assertions**

Verify the details modal focuses its close button, Escape closes it and returns focus to the details trigger, and the existing primary action still opens the workspace:

```tsx
const detailsTrigger = screen.getByRole('button', { name: 'View details for batch 2' });
fireEvent.click(detailsTrigger);
const detailsClose = screen.getByRole('button', { name: 'Close batch details' });
await waitFor(() => expect(document.activeElement).toBe(detailsClose));
fireEvent.keyDown(document, { key: 'Escape' });
await waitFor(() => expect(document.activeElement).toBe(detailsTrigger));
fireEvent.click(screen.getByRole('button', { name: 'View route 2' }));
expect(screen.getByRole('heading', { name: 'Batch #2' })).toBeInTheDocument();
```

- [ ] **Step 5: Run the focused test file to verify the new tests fail**

Run:

```powershell
.\node_modules\.bin\vitest.CMD run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx --reporter=dot
```

Expected: FAIL because the current page still renders expandable cards and a native `details` history disclosure.

### Task 2: Build the reusable responsive batch table

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/components/BatchTable.tsx`
- Delete: `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`

**Interfaces:**
- `BatchTable` consumes `DeliveryBatch[]` and callbacks for primary actions, details, secondary actions, restore, and urgent-stop behavior.
- It produces an accessible table named by `aria-label="Active batches"` for active mode and a labelled history list/table for history mode.
- Each details callback receives `(batchId: number, trigger: HTMLButtonElement)` so the parent can restore focus after closing the modal.

- [ ] **Step 1: Define the table props and shared action labels**

Use typed props shaped like:

```tsx
type BatchTableProps = {
  batches: DeliveryBatch[];
  variant?: 'active' | 'history';
  onOpen: (batchId: number) => void;
  onDetails: (batchId: number, trigger: HTMLButtonElement) => void;
  onReview?: (batchId: number) => void;
  onCancel?: (batchId: number) => void;
  onRestore?: (batchId: number) => void;
};
```

Keep the current labels (`Edit batch`, `View offer`, `View route`, `View progress`, and `View summary`) so existing workflow expectations remain stable.

- [ ] **Step 2: Render desktop table rows with explicit columns**

Render a bordered responsive container and table with these headers: `Batch`, `Status`, `Schedule`, `Rider`, `Stops`, and `Actions`. Each row must show the batch id, status/module badges, delivery date/window, rider, assigned/immutable stop count, urgent count, primary action, details button, and the existing secondary action menu where applicable.

The details control must be a normal button with an accessible name:

```tsx
<button
  type="button"
  aria-label={`View details for batch ${batch.id}`}
  onClick={(event) => onDetails(batch.id, event.currentTarget)}
  className="min-h-11 rounded-lg border ..."
>
  View details
</button>
```

- [ ] **Step 3: Render narrow-screen stacked rows without expansion**

Use the same data and callbacks in a `md:hidden` stacked presentation. Do not render a chevron, `<details>`, or local expanded state. Keep primary and details actions at 44px minimum height.

- [ ] **Step 4: Wire active batches through `BatchTable`**

Replace the active `DndProvider`/`BatchCard` grid in `Batches.tsx` with:

```tsx
<BatchTable
  batches={visibleActiveBatches}
  onOpen={openBatch}
  onDetails={openBatchDetails}
  onReview={openReview}
  onCancel={cancelBatch}
/>
```

Remove the page-only `BatchCard` import and delete the now-unreferenced component after checking `rg` confirms there are no consumers.

- [ ] **Step 5: Run focused tests after table implementation**

Run the Batches test file. Expected: table and action assertions pass; modal assertions remain failing until Task 3 and Task 4.

### Task 3: Add the batch-details modal

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/components/BatchDetailsModal.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

**Interfaces:**
- `BatchDetailsModal` accepts `isOpen`, `batch`, `onClose`, and optional `onToggleUrgent`.
- It selects persisted historical stops using the same precedence as the current `BatchCard` and `BatchWorkspace`: `stop_snapshot`, then `cancelled_stops`, then `legs` for history; `legs` for active batches.

- [ ] **Step 1: Add parent modal state and trigger refs**

In `Batches.tsx`, add `useRef` and state for `detailsBatchId`, plus `detailsTriggerRef`. Implement:

```tsx
const openBatchDetails = (batchId: number, trigger: HTMLButtonElement) => {
  detailsTriggerRef.current = trigger;
  setDetailsBatchId(batchId);
};

const closeBatchDetails = () => {
  const trigger = detailsTriggerRef.current;
  setDetailsBatchId(undefined);
  window.requestAnimationFrame(() => trigger?.focus());
};
```

- [ ] **Step 2: Implement the labelled dialog shell and focus behavior**

Use the existing `Modal` with `size="4xl"` and `showCloseButton={false}`. Render `role="dialog"`, `aria-modal="true"`, `aria-label={`Batch ${batch.id} details`}`, an explicit `Close batch details` button in the header, `onKeyDown` Tab trapping, and a scrollable body. Focus the close button when the selected batch changes.

- [ ] **Step 3: Render batch summary and stop details**

Show batch id, status/module badges, date/window, rider, stop/urgent counts, and the stop list using `BatchStopRow` inside `DndProvider`. Pass `editable={false}` and the existing urgent callback when the batch is active. Show `Historical stop details unavailable` only when all historical sources are empty.

- [ ] **Step 4: Mount the modal from `Batches.tsx`**

Resolve `detailsBatch = batches.find((batch) => batch.id === detailsBatchId)` and render:

```tsx
<BatchDetailsModal
  isOpen={detailsBatchId !== undefined}
  batch={detailsBatch}
  onClose={closeBatchDetails}
  onToggleUrgent={toggleUrgent}
/>
```

- [ ] **Step 5: Verify modal interactions**

Run the focused Batches tests and confirm details open in a dialog, no inline expansion exists, Escape/backdrop close works through `Modal`, and focus returns to the triggering row button.

### Task 4: Convert history to a button and modal

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/components/BatchHistoryModal.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

**Interfaces:**
- `BatchHistoryModal` accepts `isOpen`, `batches`, `onClose`, `onOpen`, `onDetails`, and `onRestore` callbacks.
- It renders the same `BatchTable` with `variant="history"` inside a labelled `Batch history` dialog.

- [ ] **Step 1: Add history state and trigger ref**

In `Batches.tsx`, add `historyOpen` and `historyTriggerRef`, with open/close handlers that focus the `History (count)` button after close.

- [ ] **Step 2: Move the history action beside the active `All` filter**

Render the history button in the same filter/action group as the active status pills:

```tsx
<button
  type="button"
  onClick={(event) => openHistory(event.currentTarget)}
  className="min-h-10 rounded-lg border ..."
>
  History ({historyBatches.length})
</button>
```

Remove the bottom native `<details>` block entirely.

- [ ] **Step 3: Implement the history modal**

Use `Modal size="6xl"`, a labelled dialog, top-right close button, scrollable table body, and the existing `BatchTable` history variant. Preserve `View summary` workspace behavior and `Restore to draft` mutation behavior.

- [ ] **Step 4: Verify history behavior**

Run the focused tests for history opening, history details, immutable snapshot precedence, restore behavior, close behavior, and focus restoration. Update old tests that click `History (1)` as a native `<details>` summary to click the new button and use `View details for batch X` for stop inspection.

### Task 5: Apply the delivery-proof close-icon polish

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [ ] **Step 1: Change the enlarged proof close icon to black**

Change only the enlarged viewer close button from white text to black text, with a dark hover color and a visible black focus ring that remains legible against the existing light backdrop. Keep its transparent background and top-right placement.

- [ ] **Step 2: Update the focused proof-viewer assertion**

Assert `right-4`, `top-4`, `text-black`, `bg-transparent`, and no dark button background/backdrop blur.

- [ ] **Step 3: Run focused UI tests**

Run:

```powershell
.\node_modules\.bin\vitest.CMD run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --reporter=dot
```

Expected: all Batches and Shipments tests pass.

### Task 6: Run full verification and prepare the branch

**Files:**
- Modify: `public/build/` (fresh generated artifacts only)

- [ ] **Step 1: Run the full frontend suite**

Run:

```powershell
.\node_modules\.bin\vitest.CMD run --reporter=dot
```

Expected: all frontend test files and tests pass; existing unrelated `cacheFor` mock warnings may remain stderr-only.

- [ ] **Step 2: Build production assets**

Run:

```powershell
.\node_modules\.bin\vite.CMD build
```

Expected: Vite exits 0 and `public/build/manifest.json` points to existing `app.jsx` and `Batches.tsx` artifacts.

- [ ] **Step 3: Run diff and artifact hygiene checks**

Run `git diff --check`, scan `public/build` for conflict markers, verify no old `BatchCard` import/reference remains, and confirm only the planned source/test/docs/build files are staged.

- [ ] **Step 4: Commit and push the feature branch**

Fetch and rebase `origin/solespace-b` per `docs/git-workflow.md`, preserving unrelated local files. Stage only the Batches/Shipments source and tests, the design/plan docs, and `public/build`; commit with:

```powershell
git commit -m "feat: improve logistics batches table and modals"
git push -u origin feature/shipment-tracking-modal
```

Expected: remote feature branch matches local HEAD and remains ready for a PR into `solespace-b`.
