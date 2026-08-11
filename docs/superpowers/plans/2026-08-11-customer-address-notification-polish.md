# Customer Address and Notification UI Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep Repair Services address management entirely inside an accessible modal and force customer notification popovers to remain light without changing API or business behavior.

**Architecture:** `Repair.tsx` owns the compact header trigger and uses `CustomerAddressManager` in a modal-only mode. The shared address manager keeps fetching, selection, validation, map pinning, saving, and focus behavior unchanged while choosing add/edit mode when the external trigger opens it. The notification components select a customer light palette from the existing customer API path; shop-owner and ERP paths keep their existing dark-aware palette.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library.

## Global Constraints

- Preserve the existing address endpoints, payloads, validation, map picker, coverage requests, notification routes, read/archive mutations, and saved theme behavior.
- Do not stage or overwrite the existing user-owned `package-lock.json` modification or untracked `DESIGN.md`.
- Do not add dependencies or a second component library; reuse the existing Tailwind and Lucide patterns.
- Customer-facing surfaces use the existing SoleSpace light palette; shop-owner/ERP dark-mode surfaces remain unchanged.
- Keep interactive controls keyboard accessible with visible focus states and touch targets of at least 44px.

---

### Task 1: Lock the modal-only address contract with regression tests

**Files:**
- Modify: `resources/js/components/address/__tests__/CustomerAddressManager.test.tsx`

**Interfaces:**
- Consumes: `CustomerAddressManager` props `showAddressSummary?: boolean` and `modalMode?: 'add' | 'edit'`.
- Produces: tests proving the Repair Services usage does not expose the address summary and opens the selected address in edit mode.

- [ ] **Step 1: Write the failing tests**

Add a controlled-manager test that passes `showAddTrigger={false}`, `showAddressSummary={false}`, `modalMode="edit"`, and `isModalOpen`. Assert the saved address text is absent before opening, then assert the dialog is absent before clicking the external `Edit address` trigger and has the `Edit delivery address` accessible name after clicking. Add a second assertion that `Add new address` switches that same dialog to `Add delivery address`.

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd exec vitest run resources/js/components/address/__tests__/CustomerAddressManager.test.tsx
```

Expected: FAIL because the new props are not implemented and the modal-only manager still renders the saved-address summary.

- [ ] **Step 3: Commit the test-only red state**

Do not commit a failing test to the final branch; continue immediately to Task 2 after confirming the failure is caused by the missing behavior.

---

### Task 2: Implement the modal-only address manager behavior

**Files:**
- Modify: `resources/js/components/address/CustomerAddressManager.tsx`

**Interfaces:**
- Consumes: existing address API and `onSelect` callback; new optional props `showAddressSummary?: boolean` and `modalMode?: 'add' | 'edit'`.
- Produces: an address manager that can render only its modal while retaining the default summary behavior for all existing consumers.

- [ ] **Step 1: Add the minimum props and address-to-form helper**

Keep `showAddressSummary` defaulting to `true` and `modalMode` defaulting to `'add'`. Reuse the existing province/city normalization in one helper used by both the existing address edit button and an externally opened edit modal.

- [ ] **Step 2: Select the initial modal mode when an externally controlled modal opens**

When `isModalOpen` changes to true, use the selected loaded address if `modalMode === 'edit'`; otherwise initialize an empty form. Preserve the existing `editingId === undefined` closed sentinel and do not change the save URL, request body, or callbacks.

- [ ] **Step 3: Hide only the summary markup when requested**

Render the current bordered summary card only when `showAddressSummary` is true. Keep the fetch and selection effects mounted in both modes; expose only the existing status as screen-reader-only content in modal-only mode so data loading remains announced without adding visible page content.

- [ ] **Step 4: Add the in-modal `Add new address` secondary action**

Show an underlined, keyboard-focusable `Add new address` action in the modal header only while editing an existing address. It calls the existing `openAdd` handler and changes the title and submit label without introducing a new endpoint or state machine.

- [ ] **Step 5: Run the focused tests to verify green**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd exec vitest run resources/js/components/address/__tests__/CustomerAddressManager.test.tsx
```

Expected: all address manager tests pass, including add, edit, validation, save, error, and modal close behavior.

---

### Task 3: Move Repair Services address access into the header row

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/Repair.tsx`

**Interfaces:**
- Consumes: `selectedAddress`, `isAddressModalOpen`, and `CustomerAddressManager`'s modal-only props.
- Produces: a compact `Edit address`/`Add address` trigger immediately beside `Sort by`, with no visible Delivery address card on initial page render.

- [ ] **Step 1: Add the external trigger state contract**

Use the existing `isAddressModalOpen` state. Label the trigger `Edit address` when `selectedAddress` exists and `Add address` otherwise, retaining the existing underlined text style and visible focus ring.

- [ ] **Step 2: Configure the manager for modal-only selected-address editing**

Pass `showAddressSummary={false}` and `modalMode={selectedAddress ? 'edit' : 'add'}` while keeping `onSelect`, `initialAddressId`, `isModalOpen`, and `onModalOpenChange` unchanged. Remove the old page-level margin wrapper so the manager contributes no visible layout space when closed.

- [ ] **Step 3: Run Repair Services and address tests**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd exec vitest run resources/js/components/address/__tests__/CustomerAddressManager.test.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShopCoverageIntegration.test.tsx
```

Expected: address interaction tests pass and coverage behavior still receives the selected address.

---

### Task 4: Add customer-light notification regression coverage

**Files:**
- Create: `resources/js/components/common/__tests__/NotificationDropdown.test.tsx`

**Interfaces:**
- Consumes: `NotificationDropdown` with `/api/notifications` and `/api/shop-owner/notifications` base paths.
- Produces: focused assertions that customer popovers omit dark surfaces while shop-owner popovers keep dark-mode classes.

- [ ] **Step 1: Write the failing test**

Mock `@inertiajs/react` `Link` and `usePage`, the notification hooks with one notification, `NotificationItem`, and SweetAlert. Render the customer dropdown and assert its panel has `bg-white` and does not have `dark:bg-gray-900`; render the shop-owner dropdown and assert it retains `dark:bg-gray-900`.

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd exec vitest run resources/js/components/common/__tests__/NotificationDropdown.test.tsx
```

Expected: FAIL because the customer path currently includes the same dark-aware classes as ERP/shop-owner views.

---

### Task 5: Apply the customer-light palette to notification components

**Files:**
- Modify: `resources/js/components/common/NotificationDropdown.tsx`
- Modify: `resources/js/components/common/NotificationItem.tsx`
- Modify: `resources/js/components/common/NotificationBell.tsx`

**Interfaces:**
- Consumes: existing `basePath` values and the existing notification data/mutation hooks.
- Produces: light customer panel, item, icon, borders, empty state, and footer; unchanged dark-aware ERP/shop-owner rendering.

- [ ] **Step 1: Derive one customer-view boolean from the existing API path**

Use the existing `isCustomerView` rule in the dropdown and pass it explicitly to `NotificationItem`. Use the same normalized `/api/notifications` check in `NotificationBell` so the bell itself does not inherit a dark text class on customer pages.

- [ ] **Step 2: Replace only customer dark variants with light equivalents**

For customer rendering, omit `dark:*` utility classes from panel, header, controls, list divider, empty state, item state, and footer. Keep the existing class strings for shop-owner and ERP paths, including their dark hover and border variants. Keep all labels, links, API calls, archive confirmation, and navigation unchanged.

- [ ] **Step 3: Make the explicit item prop type-safe**

Add `isCustomerView?: boolean` to `NotificationItemProps` and use it only for palette selection. Do not change notification content or routing logic.

- [ ] **Step 4: Run notification and theme tests**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd exec vitest run resources/js/components/common/__tests__/NotificationDropdown.test.tsx resources/js/utils/__tests__/pageTheme.test.ts
```

Expected: customer light assertions and existing theme tests pass; shop-owner dark assertions remain green.

---

### Task 6: Review, build, and verify the complete UI-only change

**Files:**
- Modify: `docs/superpowers/specs/2026-08-11-customer-address-modal-light-pages-design.md`
- Create: `docs/superpowers/plans/2026-08-11-customer-address-notification-polish.md`
- Regenerate: `public/build/*`

- [ ] **Step 1: Run the full frontend suite**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd run test:frontend
```

Expected: all frontend test files pass.

- [ ] **Step 2: Run the production build**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd run build
```

Expected: Vite exits with code 0 and refreshes `public/build`.

- [ ] **Step 3: Run diff hygiene and a dead-code scan**

Run:

```powershell
git diff --check
rg -n "showAddressSummary|modalMode|isCustomerView" resources/js/components/address resources/js/Pages/UserSide/Repairs/Repair.tsx resources/js/components/common
```

Expected: no whitespace errors, all new props have active consumers/tests, and no unrelated API/backend files are changed.

- [ ] **Step 4: Review preserved user changes and final diff**

Run:

```powershell
git status --short
git diff --stat
```

Confirm `package-lock.json` remains modified and `DESIGN.md` remains untracked without being staged or overwritten.
