# Cashier POS Size and Color Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace retail POS size/color dropdowns with an ERP-style combined modal and keep all retail product cards internally aligned for short and long product names.

**Architecture:** Keep the change in the existing cashier POS page to preserve the current catalog/cart helpers and avoid a one-use component file. Add one focused `RetailVariantModal` component in `POS.tsx`, backed by a small shared option-normalization helper, and route catalog and cart commits through the existing `updateRetailSelection` and `updateRetailCartVariant` functions.

**Tech Stack:** Laravel 12 + Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest, Testing Library, shared `Modal` component.

## Global Constraints

- Preserve existing variant resolution, stock checks, duplicate-line merging, cart quantities, pagination, checkout flow, and unrelated ERP UI.
- No new API, database field, dependency, or global state.
- Reuse the existing shared ERP `Modal` component and current SoleSpace colors, radius, spacing, and button conventions.
- Cancel, Escape, and backdrop dismissal must discard the temporary draft selection.
- The final working tree must preserve unrelated user changes; do not commit, stash, reset, or modify generated dependencies/assets.

---

### Task 1: Add failing retail variant interaction tests

**Files:**
- Create: `resources/js/Pages/ERP/cashier/__tests__/POS.retail-variants.test.tsx`
- Read: `resources/js/Pages/ERP/cashier/POS.tsx`

**Interfaces:**
- Consumes: the existing default `CashierPOS` export and the mocked `/api/retail-pos/products` response.
- Produces: executable expectations for catalog modal open/apply/cancel behavior and stable title-area hooks/classes.

- [x] **Step 1: Write the failing tests**

Use the same Inertia, layout, and axios mocking pattern as `POS.retail-pagination.test.tsx`. Use two products, one with a long name and variants `{ size: '7', color: 'Black' }`, `{ size: '8', color: 'Black' }`, and `{ size: '7', color: 'White' }`, all with positive stock. Assert:

```tsx
it('opens one size/color modal and only applies the draft after Apply', async () => {
  render(<CashierPOS />);

  await screen.findByText('A Very Long Shoe Name That Must Stay Aligned', { exact: true });

  fireEvent.click(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name/i }));

  const dialog = screen.getByRole('dialog', { name: /choose options for a very long shoe name/i });
  expect(within(dialog).getByRole('radio', { name: '7' })).toHaveAttribute('aria-checked', 'true');

  fireEvent.click(within(dialog).getByRole('radio', { name: '8' }));
  fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
  expect(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name.*7/i })).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name/i }));
  const reopenedDialog = screen.getByRole('dialog', { name: /choose options for a very long shoe name/i });
  fireEvent.click(within(reopenedDialog).getByRole('radio', { name: '8' }));
  fireEvent.click(within(reopenedDialog).getByRole('button', { name: 'Apply' }));

  expect(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name.*8/i })).toBeInTheDocument();
});

it('reserves the same title height for long and short product names', async () => {
  render(<CashierPOS />);

  await screen.findByText('A Very Long Shoe Name That Must Stay Aligned', { exact: true });

  expect(screen.getByTestId('retail-product-title-1')).toHaveClass('h-14', 'leading-7', 'line-clamp-2');
  expect(screen.getByTestId('retail-product-title-2')).toHaveClass('h-14', 'leading-7', 'line-clamp-2');
});
```

Use accessible names/data-testid values defined by the implementation rather than querying implementation-only DOM descendants.

- [x] **Step 2: Run the focused test to verify it fails for the missing behavior**

Run: `pnpm exec vitest run resources/js/Pages/ERP/cashier/__tests__/POS.retail-variants.test.tsx`

Expected: FAIL because the current product cards render native select elements and do not expose the combined variant dialog/title test hooks. Fix only test setup errors if any; do not implement production code before observing the expected feature failure.

---

### Task 2: Add shared variant option state and the ERP modal

**Files:**
- Modify: `resources/js/Pages/ERP/cashier/POS.tsx:1-180, 525-600, 2375-2525, 2882-2920`

**Interfaces:**
- Consumes: `RetailCatalogProduct`, `RetailProductVariant`, `normalizeVariantToken`, `getRetailSelectionForProduct`, `updateRetailSelection`, and `updateRetailCartVariant`.
- Produces: `RetailVariantModal`, `getRetailVariantOptions`, and parent modal state that accepts either a catalog product id or cart line id.

- [x] **Step 1: Add the shared modal import, types, and option helper**

Add `Modal` from `@/components/ui/modal`, define a temporary modal target with `kind: 'catalog' | 'cart'`, product/line ids, and draft `size`/`color`, and extract the existing stock-aware color/size option calculation into `getRetailVariantOptions(product, selection)`. Keep the current fallback ordering and normalization unchanged.

- [x] **Step 2: Add `RetailVariantModal` with draft-only state**

The component must:

- render through the shared `Modal` with `showCloseButton={false}` and existing ERP modal layering;
- expose `role="dialog"`, `aria-modal="true"`, and a labelled heading;
- render labelled Size and Color radio-style button groups with `aria-checked`, visible focus states, selected styling, and at least `min-h-11` hit areas;
- use the existing companion-option rules when a size or color change makes the current pair unavailable;
- show selected-variant stock in an `aria-live="polite"` status;
- disable `Apply` when no matching variant is available or the selected variant has no stock;
- call `onCancel` for close button, Cancel, Escape, and backdrop dismissal without mutating the parent selection;
- call `onApply(size, color)` only when the selected pair can be applied.

Use a stable heading id from `useId` and a simple 150–300ms color/opacity transition. Do not add a new dependency or a custom focus-trap implementation.

- [x] **Step 3: Add parent open/apply handlers and close the modal on mode changes**

Add one `retailVariantModal` state in `PointOfSalePage`, open it with the current catalog/cart pair, and apply through:

```tsx
if (target.kind === 'catalog') {
  updateRetailSelection(target.productId, { size, color });
} else {
  updateRetailCartVariant(target.lineId, size, color);
}
setRetailVariantModal(null);
```

Include `Boolean(retailVariantModal)` in `AppLayoutERP`’s `hideHeader` prop and in the existing top-level POS content visibility guard. Close the target whenever the active POS mode changes.

---

### Task 3: Replace catalog and cart selects and lock card geometry

**Files:**
- Modify: `resources/js/Pages/ERP/cashier/POS.tsx:3049-3170, 3195-3315, 4180-4188`

**Interfaces:**
- Consumes: `RetailVariantModal`, `getRetailVariantOptions`, and parent open/apply handlers from Task 2.
- Produces: visible catalog/cart trigger buttons and the mounted modal instance.

- [x] **Step 1: Replace catalog native selects with paired modal triggers**

Keep two visible controls in each product card, but make them buttons showing the current Size and Color values. Each opens the same product modal. Preserve the existing stock-derived selected values and Add button logic; only the selection UI changes.

- [x] **Step 2: Reserve the product title area and align the card footer**

Keep the existing `h-56` card and flex column. Give the title a stable two-line `h-14 leading-7 line-clamp-2` block with `overflow-hidden`, keep controls in a fixed-height row, and leave the price/Add footer on `mt-auto` with the existing divider. Add `data-testid="retail-product-title-${product.id}"` to the title element so the layout contract is testable without changing user-facing content.

- [x] **Step 3: Replace cart native selects with the same modal triggers**

Use the current cart item's size/color to open the target with `kind: 'cart'` and its `lineId`. Preserve the existing cart stock and duplicate-line behavior by committing only through `updateRetailCartVariant`.

- [x] **Step 4: Mount the modal once at the end of the POS render**

Resolve the target product from `retailProducts`; render nothing when the target is stale. Render the modal after the existing POS sections and before the current order/history/receipt modal blocks so its z-index and close behavior remain predictable.

---

### Task 4: Verify behavior and inspect the final scope

**Files:**
- Inspect: `resources/js/Pages/ERP/cashier/POS.tsx`
- Inspect: `resources/js/Pages/ERP/cashier/__tests__/POS.retail-variants.test.tsx`
- Inspect: `docs/superpowers/specs/2026-09-09-pos-size-color-modal-design.md`
- Inspect: `docs/superpowers/plans/2026-09-09-pos-size-color-modal.md`

- [x] **Step 1: Run focused POS tests after implementation**

Run: `pnpm exec vitest run resources/js/Pages/ERP/cashier/__tests__/POS.retail-variants.test.tsx resources/js/Pages/ERP/cashier/__tests__/POS.retail-pagination.test.tsx`

Expected: all focused tests pass with no unhandled errors.

- [x] **Step 2: Run the complete frontend test suite**

Run: `pnpm run test:frontend`

Expected: Vitest exits with code 0 and no failed tests.

- [x] **Step 3: Run the production build**

Run: `pnpm run build`

Expected: Vite exits with code 0 and produces the normal build output. Do not manually edit generated `public/build` files.

- [x] **Step 4: Run diff and scope checks**

Run: `git status --short`; `git diff --stat`; `git diff --check`; `git diff -- resources/js/Pages/ERP/cashier/POS.tsx resources/js/Pages/ERP/cashier/__tests__/POS.retail-variants.test.tsx docs/superpowers/specs/2026-09-09-pos-size-color-modal-design.md docs/superpowers/plans/2026-09-09-pos-size-color-modal.md`

Expected: only the scoped POS/test/spec/plan files are changed by this work; all pre-existing user changes remain present and no debug output, stale imports, temporary files, or whitespace errors are introduced.

- [x] **Step 5: Perform the sequential review record**

Check the final diff against the approved spec: standards, specification, simplification, correctness/risk, TypeScript/React quality, reuse, and dead-code checks must each be recorded in the final handoff as pass, finding resolved, or N/A. Browser verification should be run if the local app can be started safely; otherwise report it as not run with the reason.

No commit is part of this plan because the repository instructions reserve commits for an explicit user request.
