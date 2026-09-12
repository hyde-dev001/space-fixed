# Customer Dark-Mode Surfaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the shared customer drawers, checkout page, and repair-shop package selection readable and visibly themed in customer dark mode while preserving light mode and non-customer themes.

**Architecture:** Keep the existing `html.userside-dark` theme boundary. Add small semantic markers to the affected customer surfaces, use explicit dark utility variants for interactive states, and add only narrowly scoped CSS fallbacks for arbitrary color utilities and gradients that the existing global mappings do not cover.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest, pnpm.

## Global Constraints

- Primary customer text in dark mode uses `#f8fafc` or an equivalent existing light token.
- Secondary text remains visibly distinct from the dark surface and meets the existing customer palette intent.
- Drawer and checkout borders/dividers remain visible in dark mode.
- Checkout empty states and summary surfaces use dark surfaces rather than white/light gradients.
- A selected repair package has a high-contrast border, a tinted surface, and a visible selected indicator.
- Light mode keeps its current appearance.
- Existing drawer motion, focus behavior, and click interactions remain unchanged.
- Keep all new CSS selectors beneath `html.userside-dark #app` so ERP and shop-owner themes are unaffected.
- Do not add dependencies or edit `.env`, generated `vendor/`, or `node_modules/` content.

---

### Task 1: Add failing dark-mode regression contracts

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`
- Create: `resources/js/Pages/UserSide/Orders/__tests__/Checkout.dark-mode.test.ts`
- Create: `resources/js/Pages/UserSide/Repairs/__tests__/repairShow.dark-mode.test.ts`

**Interfaces:**
- Consumes: Current source strings from `Navigation.tsx`, `Checkout.tsx`, `repairShow.tsx`, and `app.css`.
- Produces: Failing source contracts that define the required drawer markers, checkout marker/gradient treatment, and repair selected-state markers.

- [ ] **Step 1: Extend the shared navigation contract with dark drawer assertions**

Append this test inside the existing `describe('user-side navigation shell', ...)` block:

```ts
  it('keeps customer drawers readable in dark mode', () => {
    const cartStart = navigationSource.indexOf('aria-label="Shopping cart"');
    const cartEnd = navigationSource.indexOf('aria-label="Site menu"', cartStart);
    const cartSource = navigationSource.slice(cartStart, cartEnd);

    expect(cartSource).toContain('userside-customer-drawer');
    expect(cartSource).toContain('dark:text-white');
    expect(cartSource).toContain('dark:text-slate-400');
    expect(appCssSource).toContain('.userside-customer-drawer');
    expect(appCssSource).toContain('[class~="text-[#777777]"]');
  });
```

- [ ] **Step 2: Create the checkout dark-mode contract**

Create `resources/js/Pages/UserSide/Orders/__tests__/Checkout.dark-mode.test.ts` with:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const checkoutSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/Checkout.tsx'),
  'utf8',
);
const appCssSource = readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf8');

describe('Checkout customer dark mode', () => {
  it('marks the checkout surface and remaps its light empty-state gradient', () => {
    expect(checkoutSource).toContain('userside-checkout-page');
    expect(appCssSource).toContain('.userside-checkout-page');
    expect(appCssSource).toContain('[class~="bg-linear-to-b"][class~="from-white"][class~="to-slate-50"]');
    expect(appCssSource).toContain('linear-gradient(180deg, #111827 0%, #0f172a 100%)');
  });
});
```

- [ ] **Step 3: Create the repair package selection contract**

Create `resources/js/Pages/UserSide/Repairs/__tests__/repairShow.dark-mode.test.ts` with:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const repairShowSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('Repair shop package selection dark mode', () => {
  it('keeps selected packages distinct on the customer dark surface', () => {
    expect(repairShowSource).toContain('repair-package-card--selected');
    expect(repairShowSource).toContain('dark:border-[#7da2ff]');
    expect(repairShowSource).toContain('dark:bg-[#1b2f50]');
    expect(repairShowSource).toContain('dark:border-[#b8cdff]');
  });
});
```

- [ ] **Step 4: Run the new focused contracts before implementation**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/Pages/UserSide/Orders/__tests__/Checkout.dark-mode.test.ts resources/js/Pages/UserSide/Repairs/__tests__/repairShow.dark-mode.test.ts
```

Expected: FAIL because the new markers, dark variants, and scoped CSS do not exist yet. The failure must be assertion failures, not test-discovery or syntax errors.

---

### Task 2: Theme the shared customer drawers

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx:1398-1530`
- Modify: `resources/css/app.css` after the existing customer dark-mode rules

**Interfaces:**
- Consumes: The existing `html.userside-dark` customer theme marker and shared drawer state/motion classes.
- Produces: `.userside-customer-drawer` surfaces with readable primary/muted text, visible separators, dark image placeholders, and a contrasting checkout/shop action.

- [ ] **Step 1: Add the semantic drawer marker and explicit dark variants**

Update the three shared drawer `className` values without changing their state expressions or transition classes:

```tsx
className={`userside-customer-drawer fixed left-[min(88vw,31rem)] top-0 z-[110] flex h-dvh w-[min(88vw,26rem)] max-w-[26rem] flex-col border-l border-white/60 bg-white/60 text-[#111111] dark:border-slate-700 dark:bg-slate-900/95 dark:text-white shadow-2xl backdrop-blur-2xl transition-[transform,opacity] duration-300 ease-out motion-reduce:transition-none ${accountDrawerOpen ? 'visible translate-x-0 opacity-100' : 'invisible translate-x-full opacity-0 pointer-events-none'}`}
```

```tsx
className={`userside-customer-drawer fixed right-0 top-0 z-[110] flex h-dvh w-[min(92vw,30rem)] max-w-[30rem] flex-col border-l border-white/60 bg-white/60 text-[#111111] dark:border-slate-700 dark:bg-slate-900/95 dark:text-white shadow-2xl backdrop-blur-2xl transition-transform duration-300 ease-out motion-reduce:transition-none ${cartDrawerOpen ? 'translate-x-0' : 'translate-x-full pointer-events-none'}`}
```

```tsx
className={`userside-customer-drawer fixed left-0 top-0 z-[110] flex h-dvh w-[min(88vw,31rem)] flex-col overflow-y-auto border-r border-white/60 bg-white/60 text-[#111111] dark:border-slate-700 dark:bg-slate-900/95 dark:text-white shadow-2xl backdrop-blur-2xl transition-transform duration-300 ease-out motion-reduce:transition-none ${landingSidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}
```

Add `dark:text-slate-400` to the drawer muted labels, `dark:border-slate-700` to drawer dividers, `dark:bg-slate-800` to the cart image placeholder, and `dark:bg-slate-100 dark:text-slate-950` to the drawer product/checkout action buttons. Preserve each current light-mode class and all click handlers.

- [ ] **Step 2: Add scoped fallback rules for arbitrary drawer utilities**

Append these rules to `resources/css/app.css`:

```css
html.userside-dark #app .userside-customer-drawer {
  background-color: rgb(15 23 42 / 0.96) !important;
  border-color: #334155 !important;
  color: #f8fafc !important;
}

html.userside-dark #app .userside-customer-drawer [class~="text-[#555555]"],
html.userside-dark #app .userside-customer-drawer [class~="text-[#777777]"],
html.userside-dark #app .userside-customer-drawer [class~="text-[#707072]"],
html.userside-dark #app .userside-customer-drawer [class~="text-[#999999]"] {
  color: #94a3b8 !important;
}

html.userside-dark #app .userside-customer-drawer [class~="border-[#dedede]"],
html.userside-dark #app .userside-customer-drawer [class~="border-[#ededed]"],
html.userside-dark #app .userside-customer-drawer [class~="border-[#e5e5e5]"],
html.userside-dark #app .userside-customer-drawer [class~="border-[#cacacb]"] {
  border-color: #334155 !important;
}

html.userside-dark #app .userside-customer-drawer [class~="bg-[#f3f3f3]"],
html.userside-dark #app .userside-customer-drawer [class~="bg-[#f5f5f5]"] {
  background-color: #1f2937 !important;
}

html.userside-dark #app .userside-customer-drawer [class~="bg-[#111111]"] {
  background-color: #f8fafc !important;
  color: #0b1220 !important;
}
```

- [ ] **Step 3: Re-run the shared navigation contract**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
```

Expected: PASS for the existing navigation contracts and the new dark drawer contract.

---

### Task 3: Theme the checkout page and empty state

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/Checkout.tsx:1195`
- Modify: `resources/css/app.css` after the drawer rules from Task 2

**Interfaces:**
- Consumes: Checkout’s existing cart state, address modals, summary, and responsive empty states.
- Produces: A `.userside-checkout-page` dark surface with dark empty-state gradients, readable cards/forms, visible borders, and unchanged checkout behavior.

- [ ] **Step 1: Add the checkout surface marker**

Change the checkout root from:

```tsx
<div className="min-h-screen flex flex-col bg-white">
```

to:

```tsx
<div className="userside-checkout-page min-h-screen flex flex-col bg-white">
```

Keep the existing `bg-white` class so light mode remains unchanged.

- [ ] **Step 2: Add scoped checkout dark-surface and gradient rules**

Append these rules to `resources/css/app.css`:

```css
html.userside-dark #app .userside-checkout-page {
  background-color: #0b1220 !important;
  color: #f8fafc;
}

html.userside-dark #app .userside-checkout-page [class~="bg-white"] {
  background-color: #111827 !important;
}

html.userside-dark #app .userside-checkout-page [class~="bg-gray-50"],
html.userside-dark #app .userside-checkout-page [class*="bg-gray-50/"] {
  background-color: #151f32 !important;
}

html.userside-dark #app .userside-checkout-page [class~="bg-linear-to-b"][class~="from-white"][class~="to-slate-50"] {
  background-image: linear-gradient(180deg, #111827 0%, #0f172a 100%) !important;
}

html.userside-dark #app .userside-checkout-page [class~="bg-linear-to-br"][class~="from-slate-100"][class~="to-slate-200"] {
  background-image: linear-gradient(135deg, #1b2a41 0%, #253550 100%) !important;
}

html.userside-dark #app .userside-checkout-page [class~="text-black"] {
  color: #f8fafc !important;
}

html.userside-dark #app .userside-checkout-page [class~="text-gray-600"] {
  color: #cbd5e1 !important;
}

html.userside-dark #app .userside-checkout-page [class~="text-gray-500"] {
  color: #94a3b8 !important;
}

html.userside-dark #app .userside-checkout-page [class~="border-gray-100"],
html.userside-dark #app .userside-checkout-page [class~="border-gray-200"],
html.userside-dark #app .userside-checkout-page [class~="border-gray-300"],
html.userside-dark #app .userside-checkout-page [class~="border-slate-200"] {
  border-color: #2f405a !important;
}
```

- [ ] **Step 3: Re-run the checkout and navigation contracts**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/Pages/UserSide/Orders/__tests__/Checkout.dark-mode.test.ts
```

Expected: PASS with zero failures.

---

### Task 4: Make repair package selection visible in dark mode

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/repairShow.tsx:792-817`
- Modify: `resources/css/app.css` after the checkout rules from Task 3

**Interfaces:**
- Consumes: Existing `selectedPackageId` state and `isSelected` conditional.
- Produces: A selected package card with a blue-toned dark border/surface and a visible indicator, while preserving the current black selected state in light mode.

- [ ] **Step 1: Add package state markers and dark variants**

Update the package card and indicator class expressions to include the state markers and dark variants:

```tsx
className={`repair-package-card w-75 min-w-75 sm:w-85 sm:min-w-85 xl:w-full xl:min-w-0 h-62.5 sm:h-65 xl:h-full shrink-0 bg-white rounded-2xl p-5 xl:p-6 border-2 transition-all cursor-pointer text-left snap-start ${
  isSelected
    ? 'repair-package-card--selected border-black shadow-md dark:border-[#7da2ff] dark:bg-[#1b2f50]'
    : 'border-gray-200 hover:border-gray-300 hover:shadow-lg dark:border-slate-700 dark:hover:border-slate-500'
}`}
```

```tsx
<div className={`repair-package-selection-indicator w-6 h-6 rounded-full border-2 flex items-center justify-center shrink-0 transition-all ${
  isSelected
    ? 'repair-package-selection-indicator--selected border-black bg-black dark:border-[#b8cdff] dark:bg-[#b8cdff]'
    : 'border-gray-300 dark:border-slate-600'
}`}>
```

- [ ] **Step 2: Add a scoped safety override for selected package contrast**

Append these rules to `resources/css/app.css`:

```css
html.userside-dark #app .userside-repair-shop-page .repair-package-card--selected {
  background-color: #1b2f50 !important;
  border-color: #7da2ff !important;
  box-shadow: 0 12px 28px -24px rgba(125, 162, 255, 0.9);
}

html.userside-dark #app .userside-repair-shop-page .repair-package-selection-indicator--selected {
  background-color: #b8cdff !important;
  border-color: #b8cdff !important;
}
```

- [ ] **Step 3: Run all focused contracts**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/Pages/UserSide/Orders/__tests__/Checkout.dark-mode.test.ts resources/js/Pages/UserSide/Repairs/__tests__/repairShow.dark-mode.test.ts
```

Expected: PASS with zero failures.

---

### Task 5: Review and verify the complete change

**Files:**
- Review: `resources/js/Pages/UserSide/Shared/Navigation.tsx`
- Review: `resources/js/Pages/UserSide/Orders/Checkout.tsx`
- Review: `resources/js/Pages/UserSide/Repairs/repairShow.tsx`
- Review: `resources/css/app.css`
- Review: The three focused dark-mode contract tests

**Interfaces:**
- Consumes: All changes from Tasks 1-4.
- Produces: Fresh test, build, diff-hygiene, and browser evidence for the three requested dark-mode behaviors.

- [ ] **Step 1: Run the full frontend test suite**

Run:

```bash
pnpm run test:frontend
```

Expected: Vitest exits with code 0 and reports zero failed tests.

- [ ] **Step 2: Build the frontend bundle**

Run:

```bash
pnpm run build
```

Expected: Vite exits with code 0 and emits the production bundle without CSS/TypeScript compilation errors.

- [ ] **Step 3: Check diff hygiene and changed-area dead code**

Run:

```bash
git diff --check
rg -n "userside-customer-drawer|userside-checkout-page|repair-package-card--selected|repair-package-selection-indicator--selected" resources/js resources/css/app.css
```

Expected: `git diff --check` emits no errors, and every marker has both a source use and a matching scoped CSS/test reference where planned.

- [ ] **Step 4: Run browser verification when the local app is runnable**

First run the helper usage check:

```bash
python .agents/skills/webapp-testing/scripts/with_server.py --help
```

Then use a headless Playwright script against the local app to verify:

1. Toggle/save customer dark mode and open the shared cart drawer; drawer title, item text, muted metadata, borders, and action text remain readable.
2. Visit `/checkout`; the page background and empty-cart panel are dark rather than white, with readable heading/body text.
3. Visit the repair shop page, select a package, and confirm the selected card has a visible tinted surface/border and selected indicator.
4. Toggle back to light mode and confirm the existing light surfaces remain white/neutral.

Expected: The three requested symptoms are absent in dark mode, with no console errors and no changed click/navigation behavior.

- [ ] **Step 5: Complete the sequential review stack**

Record results for the applicable gates: simplification/ponytail, Standards/spec/correctness review, TypeScript/React readability and performance review, Karpathy surgical-diff review, code-splitting (`N/A` because this is styling-only), improvement measurement (`not measured` unless browser/bundle baselines are captured), security (`N/A` because no auth/input/API boundary changes), verification, reuse, dead-code scan, and writing/log review.

## Execution record

- [x] Added regression contracts and confirmed the three new dark-mode checks pass.
- [x] The customer drawers use the existing `html.userside-dark` boundary with readable primary/muted text, dividers, placeholders, and actions.
- [x] Checkout now has a scoped surface marker and dark empty-state gradients.
- [x] Repair package selection now has a scoped marker, tinted selected card, and high-contrast indicator.
- [x] Production Vite build passed (`3,731` modules transformed).
- [x] Browser verification passed for checkout, site menu, side-cart, and a selected repair package; external font requests were stubbed because the test environment blocks Google Fonts.
- [x] `git diff --check` passed.
- [ ] Full frontend suite is not fully clean because the pre-existing `Navigation.contract.test.ts` expectation for `>Repairs</h1>` still fails; `880` tests passed and the three new dark-mode tests passed.
- [x] Review result: minimal scoped diff, existing theme boundary/components reused, no security or code-splitting changes, and no temporary browser files retained.
