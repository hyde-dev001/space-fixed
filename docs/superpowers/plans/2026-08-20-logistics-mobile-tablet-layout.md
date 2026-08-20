# Logistics Mobile and Tablet Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make ERP Logistics Shipments professional and usable on phones/tablets and make the compact ERP application menu a true modal, without changing desktop behavior or shipment logic.

**Architecture:** Keep the existing route components and data flow. Apply mobile-first Tailwind classes only to the Shipments page's presentation blocks, and keep `xl` classes as the desktop boundary. Add the modal interaction locally to `AppHeader_ERP` using existing React state/effects and preserve the existing inline desktop action row.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Tailwind CSS 4, Vitest, Testing Library, pnpm.

## Global Constraints

- Do not modify backend/API/database behavior or shipment business rules.
- Do not change desktop presentation at `xl` and above.
- Preserve unrelated working-tree changes already present in the repository.
- Use existing components and dependencies; add no package.
- Keep interactive controls at least 44px high and prevent page-level horizontal overflow below `xl`.
- Use `DESIGN.md`'s neutral surfaces, strong type, 8px rhythm, and restrained elevation where compatible with the existing attendance/ERP visual language.
- Reuse the existing `DeliveryDatePicker` from the Batches workflow for shipment scheduling; do not add a second date-picker implementation.

---

### Task 1: Lock the compact app-menu modal contract

**Files:**
- Modify: `resources/js/layout/AppHeader_ERP.tsx`
- Test: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`

**Interfaces:**
- Consumes: existing `useSidebar`, role-specific dropdown components, `NotificationBell`, and `ThemeToggleButton` props.
- Produces: a below-`xl` dialog labelled `Application menu`, with a trigger labelled `Toggle Application Menu` and a close control labelled `Close application menu`.

- [ ] **Step 1: Write the failing interaction tests**

Extend `AppHeader_ERP.test.tsx` to click the existing right-side trigger and assert:

```tsx
expect(screen.queryByRole('dialog', { name: 'Application menu' })).not.toBeInTheDocument();
const trigger = screen.getByRole('button', { name: 'Toggle Application Menu' });
fireEvent.click(trigger);
expect(screen.getByRole('dialog', { name: 'Application menu' })).toBeInTheDocument();
expect(screen.getByTestId('notification-bell')).toBeInTheDocument();
fireEvent.keyDown(document, { key: 'Escape' });
expect(screen.queryByRole('dialog', { name: 'Application menu' })).not.toBeInTheDocument();
expect(document.activeElement).toBe(trigger);
```

Also click `Close application menu` and assert that the dialog closes. Add `fireEvent` to the test imports if needed.

- [ ] **Step 2: Run the focused header test and confirm it fails**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppHeader_ERP.test.tsx
```

Expected: FAIL because the current implementation renders the action row inline and has no dialog or focus restoration.

- [ ] **Step 3: Implement the compact modal behavior**

In `AppHeader_ERP.tsx`:

- Add a `useRef<HTMLButtonElement | null>` for the application-menu trigger.
- Add an effect that, while the compact menu is open, listens for `Escape`, locks `document.body.style.overflow`, and restores the previous overflow on cleanup.
- Add `closeApplicationMenu` that closes the menu and returns focus to the trigger on the next animation frame.
- Keep the existing `xl:flex` desktop action row unchanged.
- For below `xl`, render a fixed backdrop and a `role="dialog" aria-modal="true" aria-labelledby="application-menu-title"` panel with `Close application menu`; place the same existing action components inside it.
- Close on backdrop click and stop propagation on the panel.
- Add `aria-expanded` and `aria-controls="application-menu-dialog"` to the compact trigger.
- Do not alter role-specific notification paths, account dropdown selection, or desktop behavior.

- [ ] **Step 4: Run the focused header test and confirm it passes**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppHeader_ERP.test.tsx
```

Expected: PASS, including the existing owner and employee notification/account assertions.

### Task 2: Refresh Shipments mobile/tablet presentation

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

**Interfaces:**
- Consumes: existing shipment props, filter handlers, card actions, `RetailOrderSummary`, and `AppLayoutERP`.
- Produces: the same rendered data/actions with compact layout classes below `xl` and the current desktop layout retained at `xl`.

- [ ] **Step 1: Write the failing presentation-contract assertions**

Extend the existing responsive card test:

```tsx
expect(screen.getByTestId('shipments-page')).toHaveClass('overflow-x-hidden');
expect(screen.getByRole('search')).toHaveClass('w-full');
expect(screen.getByRole('article')).toHaveClass('min-w-0');
```

Add only the page-level `data-testid="shipments-page"` if a stable hook is not already available.

- [ ] **Step 2: Run the focused Shipments test and confirm the new assertions fail**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: FAIL only for the new presentation assertions.

- [ ] **Step 3: Apply the responsive layout classes**

In `Shipments.tsx`:

- Add `data-testid="shipments-page"` and `overflow-x-hidden` to the route page wrapper while preserving the existing `space-y-6` rhythm.
- Make the heading readable on compact widths and restore the desktop hierarchy at `xl`.
- Make the search form full-width below `sm`, keep the input/button at least 44px high, and retain its desktop max width.
- Make the filters a readable two-column grid below `xl`, with `min-w-0` and `min-h-11` controls, then restore the inline desktop arrangement at `xl`.
- Keep conditional filters and `updateFilter` calls unchanged.
- Add `min-w-0`, responsive padding, wrapping metadata, safe address breaks, and a full-width compact `Open delivery` button. Keep the current desktop multi-column grid at `xl`.
- Make pagination stack/wrap below `sm`, keep its content and links intact, and use `min-h-11` touch targets. Restore the current desktop arrangement at `sm`/ `xl`.
- Do not change shipment state, delivery modal markup, navigation, permissions, or API/action handlers.

- [ ] **Step 4: Run the focused Shipments test and confirm it passes**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: PASS with all existing shipment behavior assertions and the new compact layout contract.

### Task 3: Review and verify the delivery

**Files:**
- Review: `resources/js/layout/AppHeader_ERP.tsx`
- Review: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Review: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`
- Review: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [ ] **Step 1: Run the frontend suite**

```powershell
pnpm run test:frontend
```

Expected: PASS with no new failures.

- [ ] **Step 2: Run the production build**

```powershell
pnpm run build
```

Expected: Vite production build completes successfully.

- [ ] **Step 3: Check diff hygiene and changed-area references**

```powershell
git diff --check
rg -n "Toggle Application Menu|application-menu-dialog|shipments-page" resources/js/layout/AppHeader_ERP.tsx resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: no whitespace errors; every new hook has a real source/test reference.

- [ ] **Step 4: Browser-verify compact and desktop breakpoints**

Use the local browser test workflow at 390px, 768px, 1024px, and 1280px. Confirm search, filters, cards, pagination, modal open/close paths, focus restoration, and no page-level horizontal overflow. At 1280px confirm the desktop header actions and shipment card arrangement remain available.

- [ ] **Step 5: Record the implementation summary**

Report exact files changed, commands run, and any browser-verification limitation. Do not claim type-checking or linting passed because the repository has no committed TypeScript compiler configuration or frontend lint script.

### Task 4: Refine shipment detail UX and shared scheduling controls

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/RetailOrderSummary.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/DeliveryDatePicker.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

**Interfaces:**
- Consumes: existing shipment legs, `today`, scheduling state, and the Batches `DeliveryDatePicker`.
- Produces: a compact full-height phone dialog, readable tablet detail cards, and one unique calendar id per leg.

- [ ] **Step 1: Add the regression test for the shared date-picker behavior**

Open a pending dispatcher shipment leg and assert that the scheduling form exposes `Open delivery date picker`, opens `Delivery date calendar`, and updates the displayed date after selecting a future date.

- [ ] **Step 2: Implement the detail presentation changes**

Use mobile-first surface grouping, safe-area bottom padding, 44px controls, wrapping metadata, and `xl` overrides for the existing desktop modal proportions. Replace the native date input with `DeliveryDatePicker` and pass `today` as its minimum date.

- [ ] **Step 3: Run focused tests**

```powershell
& '.\node_modules\.bin\vitest.CMD' run resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx --reporter=dot
```

Expected: both files pass, including application-menu accessibility and shipment scheduling behavior.
