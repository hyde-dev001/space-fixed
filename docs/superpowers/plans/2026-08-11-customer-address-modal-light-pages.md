# Customer Address Modal and Light Customer Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Repair Services address entry into an accessible modal and scope the saved dark theme away from registration and customer notifications pages.

**Architecture:** Keep `CustomerAddressManager` as the single owner of address fetching, editing, saving, and selection, changing only its presentation from inline form to modal. Add a small page-theme utility used by `app.jsx` and `ThemeContext` so light-only Inertia components remove the global `dark` class while all other pages keep the saved theme.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Tailwind CSS 4, Vitest, Testing Library, Vite.

## Global Constraints

- Preserve pre-existing `package-lock.json` and `DESIGN.md`; never stage either file.
- Do not change routes, controllers, APIs, validation rules, payment logic, or notification mutation behavior.
- Reuse the existing `CustomerAddressMapPicker`, `CustomerAddress` type, Tailwind classes, and project icon patterns.
- Keep touch targets at least 44px, visible keyboard focus, labeled fields, and an Escape/backdrop dismissal path.
- Use `pnpm` for project commands; use the installed local binary only if the package-manager shim is unavailable.

---

### Task 1: Record and validate the approved design

**Files:**
- Create: `docs/superpowers/specs/2026-08-11-customer-address-modal-light-pages-design.md`
- Create: `docs/superpowers/plans/2026-08-11-customer-address-modal-light-pages.md`

- [x] **Step 1: Record the approved interaction and theme boundaries**

The spec records the exact modal, alignment, light-only page, accessibility, and non-regression requirements approved by the user.

- [x] **Step 2: Review the plan for gaps**

The plan names every intended source/test file and keeps address data flow and saved theme behavior unchanged.

---

### Task 2: Add failing address-modal regression tests

**Files:**
- Modify: `resources/js/components/address/__tests__/CustomerAddressManager.test.tsx`

**Interfaces:**
- Consumes: `CustomerAddressManager`'s existing `onSelect` callback and address API contract.
- Produces: assertions that the add/edit form is absent until an explicit trigger opens the dialog.

- [ ] **Step 1: Assert the form is closed by default**

After the saved address loads, assert that the `Save address` button and `role="dialog"` are absent while the `Add address` trigger remains available.

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
pnpm exec vitest run resources/js/components/address/__tests__/CustomerAddressManager.test.tsx --reporter=dot --pool=forks --no-file-parallelism
```

Expected: FAIL because the current inline form does not expose the required dialog behavior.

- [ ] **Step 3: Add modal interaction tests**

Cover these user-visible behaviors:

```tsx
fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
expect(screen.getByRole('dialog', { name: 'Add delivery address' })).toBeInTheDocument();
fireEvent.keyDown(document, { key: 'Escape' });
expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
```

Also cover backdrop dismissal, editing an existing address in the same dialog, and successful save through the existing mocked POST/PUT responses.

- [ ] **Step 4: Run the focused tests and confirm the expected red state remains**

Run the same Vitest command and confirm the new dialog assertions fail for the missing implementation rather than because of a test setup error.

---

### Task 3: Add failing page-theme regression tests

**Files:**
- Create: `resources/js/utils/__tests__/pageTheme.test.ts`
- Create: `resources/js/utils/pageTheme.ts`

**Interfaces:**
- Produces: `isLightOnlyComponent(componentName: string)` and `syncPageTheme(componentName: string)` for the application shell.

- [ ] **Step 1: Write the expected component-scope tests**

The tests must assert that `UserSide/Auth/ShopOwnerRegistration` and `Notifications/CustomerNotifications` are light-only, while `Notifications/ShopOwnerNotifications` is not.

- [ ] **Step 2: Run the focused test before implementation**

Run:

```powershell
pnpm exec vitest run resources/js/utils/__tests__/pageTheme.test.ts --reporter=dot --pool=forks --no-file-parallelism
```

Expected: FAIL because `pageTheme.ts` does not exist yet.

- [ ] **Step 3: Keep the helper contract minimal**

The helper should only identify the two light-only Inertia component names and synchronize the document `dark` class from `localStorage.theme`; it must not own React state or page data.

---

### Task 4: Implement the address modal and control-row placement

**Files:**
- Modify: `resources/js/components/address/CustomerAddressManager.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/Repair.tsx`
- Test: `resources/js/components/address/__tests__/CustomerAddressManager.test.tsx`

**Interfaces:**
- Consumes: existing address fetch/save endpoints, `CustomerAddressMapPicker`, `onSelect`, and `initialAddressId`.
- Produces: a summary-only address manager with an accessible add/edit modal.

- [ ] **Step 1: Implement closed-by-default modal state**

Use the existing `editingId` sentinel: `undefined` means closed, `null` means add, and a numeric value means edit. Keep the existing form state and API handlers unchanged.

- [ ] **Step 2: Render the underlined trigger in the Repair Services sort row**

Add `showAddTrigger?: boolean` to `CustomerAddressManager`, defaulting to `true` for existing consumers. Render the manager with `showAddTrigger={false}`, `isModalOpen={isAddressModalOpen}`, and `onModalOpenChange={setIsAddressModalOpen}`. Render the single external native `Add address` button in `Repair.tsx` immediately before the existing sort button. The control must have a 44px hit area, underline styling, an accessible name, and a visible focus ring.

- [ ] **Step 3: Render the modal with focus and scroll behavior**

Use a fixed scrim and `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, a close button, a scrollable panel, and a native form layout. Focus the close button when opening, close on Escape/backdrop, restore focus to the trigger, and lock body overflow while open. Keep the save button as the only primary form action.

- [ ] **Step 4: Run the address tests green**

Run:

```powershell
pnpm exec vitest run resources/js/components/address/__tests__/CustomerAddressManager.test.tsx --reporter=dot --pool=forks --no-file-parallelism
```

Expected: all address manager tests pass, including validation and server-error coverage.

---

### Task 5: Implement light-only page theme synchronization

**Files:**
- Modify: `resources/js/utils/pageTheme.ts`
- Modify: `resources/js/app.jsx`
- Modify: `resources/js/context/ThemeContext.tsx`
- Test: `resources/js/utils/__tests__/pageTheme.test.ts`

**Interfaces:**
- Consumes: Inertia component names from initial props and navigation events, plus the existing `localStorage.theme` value.
- Produces: light-only rendering for registration/customer notifications and unchanged dark rendering elsewhere.

- [ ] **Step 1: Implement the two-component allowlist**

Use exact component names rather than URL substring matching:

```ts
const LIGHT_ONLY_COMPONENTS = new Set([
  'UserSide/Auth/ShopOwnerRegistration',
  'Notifications/CustomerNotifications',
]);
```

- [ ] **Step 2: Synchronize the document class on initial load and Inertia navigation**

Call the helper from `app.jsx` for the initial component and every `router.on('navigate')` event. When a page is light-only, remove `dark`; when it is not, apply the saved theme exactly as the existing provider does.

- [ ] **Step 3: Keep ThemeContext from re-adding dark on a light-only page**

Route the existing theme effect through the helper or a shared `applyThemeClass` function so a theme toggle or initial localStorage hydration cannot immediately re-add `dark` on a light-only component. Keep the saved localStorage value and shop-owner/ERP behavior unchanged.

- [ ] **Step 4: Run the theme tests green**

Run:

```powershell
pnpm exec vitest run resources/js/utils/__tests__/pageTheme.test.ts --reporter=dot --pool=forks --no-file-parallelism
```

Expected: light-only components remove the dark class and shop-owner notifications remain eligible for dark mode.

---

### Task 6: Full verification and handoff

**Files:**
- Review: all changed files from Tasks 1–5

- [ ] **Step 1: Run focused frontend tests**

```powershell
pnpm exec vitest run resources/js/components/address/__tests__/CustomerAddressManager.test.tsx resources/js/utils/__tests__/pageTheme.test.ts --reporter=dot --pool=forks --no-file-parallelism
```

- [ ] **Step 2: Run the complete frontend suite**

```powershell
pnpm run test:frontend
```

- [ ] **Step 3: Run the relevant backend smoke test**

```powershell
php artisan test tests/Feature/Logistics/CustomerTrackingTest.php
```

- [ ] **Step 4: Build and check diff hygiene**

```powershell
pnpm run build
git diff --check
```

- [ ] **Step 5: Review the final diff**

Confirm that only the approved docs, address component/page/tests, page-theme helper/tests, app/theme wiring, and generated build output are changed. Confirm `package-lock.json` and `DESIGN.md` remain unstaged and uncommitted.
