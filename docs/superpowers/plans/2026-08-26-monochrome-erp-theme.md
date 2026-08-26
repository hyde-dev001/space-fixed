# SoleSpace Monochrome ERP Theme Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with review checkpoints.

**Goal:** Apply a consistent luxury monochrome theme to all shared ERP and shop-owner surfaces, buttons, modals, and SweetAlert2 dialogs while preserving semantic status colors and application behavior.

**Architecture:** Make the migration at shared CSS tokens and shared shell/component boundaries first. Update the ERP and shop-owner shells, reusable button primitive, workspace module cards, and global SweetAlert2 styles; then scan connected pages for remaining blue/indigo presentation and change only shared or clearly theme-owned cases. Business logic, routes, permissions, and status color mappings remain unchanged.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript/TSX, Tailwind CSS 4, Vite 7, Vitest, SweetAlert2.

## Global Constraints

- Use the approved white/black luxury theme with neutral gray surfaces and hairlines.
- Keep green, red, and amber for semantic status states.
- Every connected button, including modal and SweetAlert2 buttons, must use black/white/neutral treatment.
- Preserve existing behavior, routes, permissions, module access, labels, dimensions, and animations unless a color-only adjustment is required.
- Preserve unrelated working-tree changes, including Logistics files, `package-lock.json`, `.pnpm-store`, and existing brainstorm artifacts.
- Do not edit `.env`, `vendor/`, or `node_modules/`.

---

### Task 1: Establish shared monochrome tokens and SweetAlert2 theme

**Files:**
- Modify: `resources/css/app.css`
- Inspect: `resources/js/utils/pageTheme.ts`
- Test: `resources/js/utils/__tests__/pageTheme.test.ts` only if existing assertions cover changed theme class behavior

**Interfaces:**
- Consumes: existing `brand-*`, `dark:*`, and `user-swal2-*` CSS conventions.
- Produces: reusable light/dark neutral color behavior for shared UI and SweetAlert2 without changing the `ThemeContext` API.

- [ ] **Step 1: Inspect existing theme variables and page-theme behavior**

Run:

```powershell
rg -n "--color-brand|--color-gray|@theme|dark|swal2|user-swal2" resources/css/app.css resources/js/utils/pageTheme.ts
```

Expected: identify existing semantic tokens and confirm that dark mode is controlled by the `dark` class.

- [ ] **Step 2: Adjust neutral semantic tokens**

Use the existing token structure in `resources/css/app.css` and map shared primary/action values to near-black, white, charcoal, and neutral gray. Keep success/error/warning tokens unchanged. Do not add a second theme provider or rename the `ThemeContext` contract.

- [ ] **Step 3: Rewrite shared SweetAlert2 chrome**

Update popup, title/body/summary colors, confirm/cancel buttons, borders, focus rings, and input styles. Light mode uses a white popup, black confirm button, and soft-gray cancel button. Dark mode uses a charcoal popup, white text, and a black confirm button with a visible neutral border. Preserve z-index and animation rules; retain semantic icon colors only when they communicate status.

- [ ] **Step 4: Run focused frontend tests**

Run: `pnpm run test:frontend`

Expected: existing tests pass; update only assertions describing the approved visual contract.

---

### Task 2: Update ERP and shop-owner shared shells

**Files:**
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Modify: `resources/js/layout/AppLayout_shopOwner.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/layout/AppSidebar_shopOwner.tsx`
- Modify: `resources/js/layout/AppHeader_ERP.tsx`
- Modify: `resources/js/layout/AppHeader_shopOwner.tsx`
- Modify: `resources/js/config/shopOwnerNavigation.ts` only for presentation classes
- Test: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Test: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Test: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`

**Interfaces:**
- Consumes: Task 1 neutral tokens and existing sidebar/header props and navigation data.
- Produces: black sidebar, monochrome logo/wordmark, neutral navigation icons, and neutral header controls for every shared ERP/shop-owner page.

- [ ] **Step 1: Replace sidebar surface and logo styling**

In both sidebar components, change the sidebar surface to the approved black anchor treatment, replace blue/purple gradient wordmarks with monochrome wordmark text or existing official logo assets, and set icon/text active states to white/neutral. Preserve navigation structure, collapsed behavior, and route links.

- [ ] **Step 2: Replace header control accents**

In both header components, replace blue `focus`, `hover`, `brand`, and search/control accents with black/neutral equivalents. Keep notification unread colors semantic where applicable. Ensure focus-visible rings remain visible in both light and dark contexts.

- [ ] **Step 3: Update shop-owner navigation presentation**

Change only presentation fields such as blue/purple background, hover, and decorative color values in `shopOwnerNavigation.ts`. Preserve navigation keys, route names, permission checks, and labels.

- [ ] **Step 4: Run shared layout tests**

Run: `pnpm run test:frontend -- resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx`

Expected: PASS with the same navigation behavior and updated class expectations only where tests assert old theme classes.

---

### Task 3: Standardize reusable buttons and ERP workspace module cards

**Files:**
- Modify: `resources/js/components/ui/button/Button.tsx`
- Modify: `resources/js/Pages/ERP/Workspace.tsx`
- Inspect/modify only shared theme-owned actions in `resources/js/components/ui/modal/index.tsx`, `resources/js/components/ui/dropdown/DropdownItem.tsx`, `resources/js/components/common/ThemeToggleButton.tsx`, and shared notification/modal components as needed
- Test: `resources/js/Pages/ERP/__tests__/Workspace.test.tsx`
- Test: `resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx`

**Interfaces:**
- Consumes: Task 1 neutral tokens and existing component props/variants.
- Produces: black primary buttons, neutral outline/secondary buttons, monochrome modal/dropdown actions, and module cards matching the supplied direction.

- [ ] **Step 1: Update shared `Button` variants**

Keep the existing `variant`, `size`, icon, disabled, and `className` APIs. Change primary to black/white with neutral hover and outline to white/charcoal with black or neutral borders. Replace blue/brand focus rings with black or white rings according to the active surface.

- [ ] **Step 2: Update module card presentation**

In `Workspace.tsx`, change enabled module glyphs, Ready badge, card hover/focus outline, eyebrow, Open module action, Owner mode badge, and Manage modules button to neutral black/white styles. Keep module filtering, links, labels, and accessibility attributes unchanged.

- [ ] **Step 3: Update shared modal/dropdown actions**

Replace blue/brand action classes in shared modal, dropdown, notification, and theme-toggle primitives with the same black/neutral button vocabulary. Do not alter business-specific handlers or modal content.

- [ ] **Step 4: Run workspace and button-related tests**

Run: `pnpm run test:frontend -- resources/js/Pages/ERP/__tests__/Workspace.test.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx`

Expected: PASS; update only visual class assertions that encode the approved monochrome contract.

---

### Task 4: Remove remaining connected blue/indigo chrome and verify representative roles

**Files:**
- Modify: shared ERP/shop-owner components identified by the targeted scan, especially notifications, profile/account menus, settings, forms, tables, approval cards, and modal components.
- Test: affected existing tests only when assertions cover changed presentation classes.

**Interfaces:**
- Consumes: Tasks 1–3 shared theme vocabulary.
- Produces: consistent monochrome treatment across manager, staff, finance, HR, CRM, cashier, repairer, inventory, procurement, logistics dispatcher, and shop-owner pages without changing business semantics.

- [ ] **Step 1: Scan connected surfaces for old presentation colors**

Run:

```powershell
rg -n "bg-(blue|indigo|purple)-|text-(blue|indigo|purple)-|border-(blue|indigo|purple)-|ring-(blue|indigo|purple)-|from-blue|to-purple|brand-" resources/js/layout resources/js/components resources/js/Pages/ERP resources/js/config
```

Classify each result as shared chrome, semantic/status color, data visualization, map/location/product color, or unrelated customer-facing UI. Change shared chrome only; preserve semantic and domain-specific colors unless they violate the approved button/modal/sidebar contract.

- [ ] **Step 2: Verify representative page families**

Use local browser tooling when runnable and inspect ERP workspace/module landing, one manager/staff dashboard, one finance/HR/inventory/procurement page with a modal or table action, one logistics dispatcher page, one shop-owner retail/repair page, and notifications/settings/profile. Confirm light/dark mode, black sidebar, monochrome logo, black buttons inside and outside modals, readable status badges, and visible focus states.

- [ ] **Step 3: Run full frontend verification**

Run: `pnpm run test:frontend`

Expected: PASS.

- [ ] **Step 4: Build the production frontend**

Run: `pnpm run build`

Expected: Vite build completes successfully.

- [ ] **Step 5: Check diff hygiene and changed-file scope**

Run:

```powershell
git diff --check
git status --short
git diff --stat
```

Expected: no whitespace errors; unrelated pre-existing changes remain intact; only approved theme/spec/plan files and targeted shared UI files are changed.

---

## Plan Self-Review

- Spec coverage: light mode, dark mode, all button surfaces, SweetAlert2, shared shell, module cards, semantic status colors, role coverage, behavior preservation, tests, build, and browser verification are covered by Tasks 1–4.
- Placeholder scan: no TBD/TODO/“implement later” steps are used.
- Type consistency: existing component APIs are preserved; tasks communicate through CSS tokens and existing React props rather than new untracked interfaces.
