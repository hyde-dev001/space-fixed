# ERP Shipment Delivery Details Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the ERP Logistics Shipments page's inline delivery expansion with an accessible responsive modal while preserving every existing logistics action, permission rule, API call, and server-side data flow.

**Architecture:** Keep the existing action-heavy details JSX and closure state in `Shipments.tsx` to minimize regression risk. Replace the expansion state with a selected-shipment modal state, wrap the existing details block in the shared `Modal`, and add local dialog focus management without changing backend or shared modal contracts.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Lucide React, Vitest, Testing Library, Vite, pnpm.

## Global Constraints

- Keep all existing assignment, scheduling, status, proof, incident, recovery, owner-mode, rider-mode, and permission behavior unchanged.
- Do not change Laravel routes, controllers, models, migrations, authorization rules, shared API services, or database data.
- Reuse `resources/js/components/ui/modal/index.tsx`; do not add a dependency or create a second modal primitive.
- Preserve unrelated working-tree changes in `package-lock.json`, `.pnpm-store/`, and `DESIGN.md`.
- Use `pnpm` for frontend commands.
- Do not report TypeScript or lint checks as passing because the repository has no committed TypeScript compiler configuration or frontend lint script.
- Use `apply_patch` for source and test edits.

---

## File Map

- Modify `resources/js/Pages/ERP/Logistics/Shipments.tsx`:
  - import the shared modal and modal icons;
  - replace expansion state with selected-shipment state;
  - retain the existing action callbacks and their state;
  - convert the card action into a dialog trigger;
  - move the existing details markup into a responsive modal surface;
  - add close, Escape, backdrop, focus-return, and keyboard focus-trap behavior through the shared modal plus local dialog markup.
- Modify `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`:
  - replace the old inline-expansion contract test with modal behavior assertions;
  - add a focus/close regression test while retaining all existing operational action tests.
- Create `docs/superpowers/specs/2026-08-12-erp-shipment-delivery-modal-design.md` (already completed and committed): approved UX and regression boundaries.
- Create this plan document.

## Task 1: Change the focused test contract first

**Files:**

- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx` near the existing `expands shipment details accessibly without mutation permission` test.

**Interfaces:**

- Consumes: the existing `defaultProps`, `mocks`, and `Shipments` render harness.
- Produces: executable expectations for `aria-haspopup="dialog"`, dialog labeling, no inline region, explicit close, Escape close, and focus restoration.

- [ ] **Step 1: Replace the old expansion assertion with the modal contract.**

Replace the current test body with:

```tsx
it('opens shipment details in an accessible modal and restores trigger focus', () => {
  mocks.props.canRecordProof = false;
  render(<Shipments />);

  const open = screen.getByRole('button', { name: 'Open delivery' });
  expect(open).toHaveAttribute('aria-haspopup', 'dialog');
  expect(open).not.toHaveAttribute('aria-expanded');
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

  fireEvent.click(open);

  expect(screen.getByRole('dialog', { name: 'Shipment 1 delivery details' })).toBeInTheDocument();
  expect(screen.getByText('Delivery details')).toBeInTheDocument();
  expect(screen.queryByRole('region', { name: 'Shipment 1 details' })).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Close delivery details for Shipment 1' }));

  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(document.activeElement).toBe(open);
});
```

- [ ] **Step 2: Add an Escape-key regression test.**

Add this test directly after the modal contract test:

```tsx
it('closes the selected shipment modal with Escape without changing the shipment list', () => {
  render(<Shipments />);
  const open = screen.getByRole('button', { name: 'Open delivery' });

  fireEvent.click(open);
  expect(screen.getByRole('dialog', { name: 'Shipment 1 delivery details' })).toBeInTheDocument();

  fireEvent.keyDown(document, { key: 'Escape' });

  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(screen.getByRole('article')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Open delivery' })).toBeInTheDocument();
});
```

- [ ] **Step 3: Add a multiple-card scroll-lock regression test.**

Add this test after the Escape test:

```tsx
it('keeps the page scroll locked when the first of multiple shipment modals is open', () => {
  const second = structuredClone(mocks.props.shipments.data[0]);
  second.id = 2;
  mocks.props.shipments.data.push(second);
  render(<Shipments />);

  fireEvent.click(screen.getByRole('button', { name: 'Open delivery for Shipment 1' }));

  expect(screen.getAllByRole('dialog')).toHaveLength(1);
  expect(document.body).toHaveStyle({ overflow: 'hidden' });
});
```

- [ ] **Step 4: Run the focused test file and confirm the new contract fails for the expected missing modal behavior.**

Run:

```bash
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: the new modal tests fail because the current implementation still exposes `aria-expanded` and renders an inline region; unrelated existing tests should continue to execute.

## Task 2: Add selected-shipment and focus state

**Files:**

- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx` imports and state section near the existing `expandedShipmentId` declaration.

**Interfaces:**

- Consumes: the current `shipments.data` list and the existing `Modal` close contract.
- Produces: `selectedShipmentId`, `openShipment`, `closeShipment`, `closeButtonRef`, and a focus-trap handler used by the modal markup.

- [ ] **Step 1: Update imports.**

Change the React and icon imports to include the hooks/icons required by the modal:

```tsx
import React, { useEffect, useRef, useState } from 'react';
import { CalendarDays, ExternalLink, MapPin, Search, UserRound, X } from 'lucide-react';
```

Add the existing shared modal import below the layout import:

```tsx
import { Modal } from '@/components/ui/modal';
```

Remove `ChevronDown` because the card is no longer an expand/collapse control.

- [ ] **Step 2: Replace expansion state with selected-shipment and focus state.**

Replace:

```tsx
const [expandedShipmentId, setExpandedShipmentId] = useState<number | null>(null);
```

with:

```tsx
const [selectedShipmentId, setSelectedShipmentId] = useState<number | null>(null);
const returnFocusRef = useRef<HTMLButtonElement | null>(null);
const closeButtonRef = useRef<HTMLButtonElement | null>(null);
```

- [ ] **Step 3: Add modal open/close handlers after the last local state declaration.**

Add:

```tsx
const openShipment = (shipmentId: number, trigger: HTMLButtonElement) => {
  returnFocusRef.current = trigger;
  setSelectedShipmentId(shipmentId);
};

const closeShipment = () => {
  const trigger = returnFocusRef.current;
  setSelectedShipmentId(null);
  trigger?.focus();
};
```

- [ ] **Step 4: Focus the modal close control after a shipment opens.**

Add this effect after the handlers:

```tsx
useEffect(() => {
  if (selectedShipmentId === null) return;

  const frame = window.requestAnimationFrame(() => closeButtonRef.current?.focus());
  return () => window.cancelAnimationFrame(frame);
}, [selectedShipmentId]);
```

- [ ] **Step 5: Add the local keyboard focus trap before the component return.**

Add:

```tsx
const trapDialogFocus = (event: React.KeyboardEvent<HTMLDivElement>) => {
  if (event.key !== 'Tab') return;

  const focusable = Array.from(event.currentTarget.querySelectorAll<HTMLElement>(
    'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  ));
  if (focusable.length === 0) return;

  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
};
```

- [ ] **Step 6: Run the focused tests to catch any type or hook placement errors before changing the JSX.**

Run:

```bash
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: the modal tests still fail on the missing trigger/dialog markup, while the test runner successfully compiles the new imports, state, and handlers.

## Task 3: Convert the card action and details block to a modal

**Files:**

- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx` inside the shipment card map, replacing the current `expanded` calculation, button, and conditional details region.

**Interfaces:**

- Consumes: `selectedShipmentId`, `openShipment`, `closeShipment`, `trapDialogFocus`, the current `shipment`, and all existing detail action closures.
- Produces: a single accessible dialog for the selected shipment with the original operational controls intact.

- [ ] **Step 1: Replace the per-card expansion flag.**

Replace:

```tsx
const expanded = expandedShipmentId === shipment.id;
```

with:

```tsx
const selected = selectedShipmentId === shipment.id;
```

- [ ] **Step 2: Replace the `Open delivery` button.**

Use this trigger, keeping the existing button styling as the base:

```tsx
<button
  type="button"
  aria-label={shipments.data.length > 1 ? `Open delivery for Shipment ${shipment.id}` : undefined}
  aria-haspopup="dialog"
  onClick={(event) => openShipment(shipment.id, event.currentTarget)}
  className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300 dark:hover:bg-blue-950/50"
>
  Open delivery
  <ExternalLink aria-hidden="true" size={16} />
</button>
```

Do not include `aria-expanded`, `aria-controls`, `Close delivery`, or `ChevronDown` in the card trigger.

- [ ] **Step 3: Replace the inline details wrapper with the shared modal shell.**

Replace the current `{expanded && (...)` wrapper with:

```tsx
<Modal
  isOpen={selected}
  onClose={closeShipment}
  size="6xl"
  showCloseButton={false}
  className="m-4 max-h-[calc(100dvh-2rem)] overflow-hidden"
>
  <div
    role="dialog"
    aria-modal="true"
    aria-labelledby={`shipment-${shipment.id}-details-title`}
    onKeyDown={trapDialogFocus}
    className="flex max-h-[min(92dvh,60rem)] flex-col overflow-hidden"
  >
    <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-800 sm:px-6">
      <div className="min-w-0">
        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">Shipment #{shipment.id}</p>
        <div className="mt-1 flex flex-wrap items-center gap-2">
                        <h2 id={`shipment-${shipment.id}-details-title`} aria-label={`Shipment ${shipment.id} delivery details`} className="text-xl font-bold tracking-tight text-gray-950 dark:text-white">Delivery details</h2>
          <span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusClass(shipment.status)}`}>{label(shipment.status)}</span>
        </div>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          {shipment.source_type === 'order' && shipment.order_summary?.order_number
            ? `Order ${shipment.order_summary.order_number}`
            : logisticsSourceLabel(shipment)}
        </p>
      </div>
      <button
        ref={closeButtonRef}
        type="button"
        aria-label={`Close delivery details for Shipment ${shipment.id}`}
        onClick={closeShipment}
        className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
      >
        <X aria-hidden="true" size={20} />
      </button>
    </header>
    <div className="min-h-0 flex-1 overflow-y-auto border-t border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40 sm:p-6">
      {shipment.source_type === 'order' && <RetailOrderSummary summary={shipment.order_summary} expanded />}
    </div>
  </div>
</Modal>
```

- [ ] **Step 4: Move the existing details content into the modal scroll area without changing action logic.**

Use `apply_patch` to move the exact JSX currently between the old details-region opening line and its matching closing `)}` into the new scroll area. The moved block must begin with:

```tsx
{shipment.source_type === 'order' && <RetailOrderSummary summary={shipment.order_summary} expanded />}
<div className="space-y-3">
```

and must end with the existing assignment/action error paragraphs immediately before the closing details wrapper:

```tsx
{assignmentError && <p className="text-sm text-red-600">{assignmentError}</p>}
{actionError && <p className="text-sm text-red-600">{actionError}</p>}
</div>
```

Keep every line between those boundaries verbatim, including the leg-derived variables, delivery-leg JSX, action controls, validation messages, permission gates, API URLs, and error handling. The only structural changes in this move are removing the old region wrapper and placing the unchanged block inside the modal scroll area.

While moving the block:

- remove the old `id="shipment-${shipment.id}-details"`, `role="region"`, `aria-label`, and `expanded` condition;
- retain all existing `canAssign`, `canUpdateStatus`, `canRecordProof`, `canApproveProof`, `riderMode`, `ownerMode`, and `assignableRiders` checks;
- retain all existing `axios`, `logisticsApi`, `act`, `confirmAct`, `router.reload`, and SweetAlert calls;
- retain the existing responsive `lg:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]` leg layout, while allowing the modal scroll area to stack naturally below the `lg` breakpoint;
- do not introduce a second details renderer or duplicate any action control.

- [ ] **Step 5: Run the focused shipment tests and fix only modal-related failures.**

Run:

```bash
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: all shipment tests pass, including existing assignment, scheduling, proof, incident, repair, rider, owner-mode, filter, and data presentation coverage.

## Task 4: Simplify and review the changed UI

**Files:**

- Review: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Review: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [ ] **Step 1: Remove only change-created dead code.**

Confirm `ChevronDown`, `expandedShipmentId`, `setExpandedShipmentId`, `expanded`, `aria-expanded`, and `aria-controls` are no longer referenced. Keep all unrelated imports, helpers, and action code.

- [ ] **Step 2: Perform the sequential standards/spec/risk review.**

Check:

- the modal uses the repository's shared `Modal` component and Tailwind conventions;
- only one dialog exists for the selected shipment;
- all existing action permissions and API endpoints are unchanged;
- dialog labeling, Escape, backdrop, close button, focus return, focus trap, dark mode, and responsive scrolling are present;
- no backend or unrelated page changes are included.

- [ ] **Step 3: Run focused tests after the review.**

Run:

```bash
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: PASS with zero failures.

## Task 5: Run repository quality gates

**Files:**

- Verify: changed source and test files plus the final working tree.

- [ ] **Step 1: Run the full frontend test suite.**

Run:

```bash
pnpm run test:frontend
```

Expected: exit code 0 with no failed tests.

- [ ] **Step 2: Run the production frontend build.**

Run:

```bash
pnpm run build
```

Expected: Vite exits with code 0 and emits the production assets.

- [ ] **Step 3: Check diff hygiene.**

Run:

```bash
git diff --check
git status --short
```

Expected: `git diff --check` exits 0; status shows only the intended shipment page/test/plan files plus the user's pre-existing changes.

- [ ] **Step 4: Use browser verification if the local app is runnable.**

First run the helper help command as required:

```bash
python .agents/skills/webapp-testing/scripts/with_server.py --help
```

If a local Laravel/Vite server can be started, verify with a headless Playwright script:

1. Navigate to the ERP shipments route with an authenticated test session.
2. Wait for `networkidle`.
3. Click `Open delivery` and assert one visible dialog.
4. Confirm the list does not gain an inline details region or horizontal overflow at desktop and narrow viewport sizes.
5. Close with the visible close button, reopen, close with Escape, and confirm focus returns to the trigger.
6. Click the backdrop and confirm the dialog closes.

If authentication or the local server prevents this check, report it as not run with the exact blocker instead of claiming browser verification passed.

- [ ] **Step 5: Commit the implementation if the workspace permits Git writes.**

Stage only the implementation plan, shipment page, and focused test changes:

```bash
git add docs/superpowers/plans/2026-08-12-erp-shipment-delivery-modal.md resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git commit -m "feat: open ERP shipment details in modal"
```

If Git writes are blocked by the managed workspace, leave the files unstaged and report the exact permission error; do not touch or reset unrelated changes.
