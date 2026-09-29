# Shop Owner Monochrome Consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the canonical Shop Owner Light Mode shell, dashboard cards, and application charts consistently black, white, and neutral gray while preserving semantic status colors and the original Dark Mode.

**Architecture:** Add the existing `erp-theme` contract to the canonical owner frame so prior Light Mode normalization reaches owner-mode ERP pages. Keep shell states explicit in the canonical components, scope shared metric-card and ApexCharts overrides to `html:not(.dark)`, and remove page-local decorative color from the Logistics and Procurement dashboards that bypasses the shared metric-card treatment.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, ApexCharts, Vitest, PHPUnit, Vite 7.

## Global Constraints

- Light Mode uses black `#111111`, white, and neutral grays for branding, active navigation, cards, section badges, and charts.
- Semantic red, green, and amber remain only when communicating warning, success, error, or status.
- Dark Mode styling and behavior must remain unchanged.
- Do not add dependencies or change page data flow, authorization, content, or layout structure.
- Preserve all unrelated working-tree changes and stage only files listed by this plan.

---

### Task 1: Canonical Shop Owner shell states

**Files:**
- Modify: `resources/js/layout/CanonicalOwnerLayout.tsx`
- Modify: `resources/js/layout/CanonicalOwnerSidebar.tsx`
- Modify: `resources/js/components/owner-shell/OwnerModuleTabs.tsx`
- Modify: `resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`
- Modify: `resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx`
- Create: `resources/js/components/owner-shell/__tests__/OwnerModuleTabs.test.tsx`

**Interfaces:**
- Consumes: the existing `erp-theme` Light Mode contract in `resources/css/app.css`.
- Produces: a canonical owner frame carrying `erp-theme canonical-owner-theme`, black Light Mode wordmark and active navigation, and black active module tabs with unchanged `dark:` variants.

- [ ] **Step 1: Add failing shell style tests**

Assert the canonical frame exposes both theme hooks, the wordmark uses `text-[#111111]` with its existing dark color, active links use `bg-[#111111] text-white`, and the current module tab uses the same Light Mode treatment while retaining explicit dark classes.

```tsx
expect(screen.getByTestId('canonical-owner-frame')).toHaveClass('erp-theme', 'canonical-owner-theme');
expect(screen.getByRole('link', { name: 'SoleSpace' })).toHaveClass('text-[#111111]', 'dark:text-blue-300');
expect(screen.getByRole('link', { name: 'Home' })).toHaveClass('bg-[#111111]', 'text-white', 'dark:bg-blue-500/15');
expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveClass('bg-[#111111]', 'text-white', 'dark:bg-blue-500/10');
```

- [ ] **Step 2: Run focused tests and verify failure**

Run:

```powershell
npm.cmd run test:frontend -- resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/components/owner-shell/__tests__/OwnerModuleTabs.test.tsx
```

Expected: FAIL because the canonical frame and active states still use blue Light Mode classes.

- [ ] **Step 3: Implement the minimal shell changes**

Use these Light Mode classes while preserving current Dark Mode variants:

```tsx
className="canonical-owner-theme erp-theme min-h-screen ... dark:bg-gray-950 dark:text-gray-100"

active
  ? 'menu-item-active bg-[#111111] text-white dark:bg-blue-500/15 dark:text-blue-300'
  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white'

className="text-xl font-bold tracking-tight text-[#111111] dark:text-blue-300"

isCurrent
  ? 'border-[#111111] bg-[#111111] text-white dark:border-blue-300 dark:bg-blue-500/10 dark:text-blue-200'
  : 'border-transparent text-gray-600 hover:border-gray-300 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-transparent dark:hover:text-white'
```

Replace Light Mode blue focus rings with `focus-visible:ring-[#111111]`; retain Dark Mode ring-offset classes.

- [ ] **Step 4: Run focused tests and verify pass**

Run the command from Step 2.

Expected: all selected Vitest files PASS.

---

### Task 2: Shared Light Mode cards and monochrome charts

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/metricCardSizing.test.ts`
- Create: `resources/js/__tests__/monochromeTheme.test.ts`
- Modify: `tests/Feature/AppShellLoaderTest.php`

**Interfaces:**
- Consumes: `.erp-theme`, `.metrics-card`, and ApexCharts' generated `.apexcharts-series[rel]` and `.apexcharts-legend-marker[rel]` hooks.
- Produces: Light Mode-only neutral card rules and a black/gray chart palette shared by all account layouts carrying `erp-theme`.

- [ ] **Step 1: Add failing CSS contract tests**

Verify that shared card rules are explicitly scoped to `html:not(.dark)` and chart rules define black primary plus gray secondary/tertiary series without a `.dark` selector.

```ts
expect(appCss).toContain('html:not(.dark) #app .metrics-card');
expect(appCss).toContain('html:not(.dark) #app .erp-theme .apexcharts-series[rel="1"]');
expect(appCss).toContain('stroke: #111111 !important;');
expect(appCss).toContain('fill: #111111 !important;');
expect(appCss).toContain('.apexcharts-legend-marker[rel="2"]');
```

Add a PHP source contract asserting the same Light Mode boundary and that no `.dark .erp-theme .apexcharts-series` override is introduced.

- [ ] **Step 2: Run tests and verify failure**

Run:

```powershell
npm.cmd run test:frontend -- resources/js/__tests__/metricCardSizing.test.ts resources/js/__tests__/monochromeTheme.test.ts
php artisan test tests/Feature/AppShellLoaderTest.php
```

Expected: FAIL because the chart contract does not exist and current metric-card selectors are not Light Mode scoped.

- [ ] **Step 3: Scope card rules and add the monochrome chart contract**

Prefix all shared `.metrics-card` and matching legacy-card selectors with `html:not(.dark) #app`. Leave existing `.dark` declarations unchanged.

Add Light Mode-only ApexCharts rules:

```css
html:not(.dark) #app .erp-theme .apexcharts-series[rel="1"] path {
  stroke: #111111 !important;
  fill: #111111 !important;
}

html:not(.dark) #app .erp-theme .apexcharts-series[rel="2"] path {
  stroke: #6b7280 !important;
  fill: #6b7280 !important;
}

html:not(.dark) #app .erp-theme .apexcharts-series[rel="3"] path {
  stroke: #9ca3af !important;
  fill: #9ca3af !important;
}

html:not(.dark) #app .erp-theme .apexcharts-legend-marker[rel="1"] { background: #111111 !important; }
html:not(.dark) #app .erp-theme .apexcharts-legend-marker[rel="2"] { background: #6b7280 !important; }
html:not(.dark) #app .erp-theme .apexcharts-legend-marker[rel="3"] { background: #9ca3af !important; }
```

Preserve ApexCharts opacity and dash configuration so area, line, and bar charts remain distinguishable.

- [ ] **Step 4: Run CSS contract tests and verify pass**

Run the command from Step 2.

Expected: both Vitest files and `AppShellLoaderTest` PASS.

---

### Task 3: Neutralize owner-mode module dashboard cards

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Dashboard.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/Dashboard.tsx`
- Modify: `resources/js/Pages/ERP/inventory/InventoryDashboard.tsx`
- Modify: `resources/js/Pages/ERP/HR/Dashboard.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Dashboard.test.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx`
- Create: `resources/js/__tests__/ownerDashboardCardTheme.test.ts`

**Interfaces:**
- Consumes: the shared `metrics-card` contract from Task 2.
- Produces: neutral module hero cards, metric cards, headings, icon badges, and values in Light Mode while retaining semantic alert/progress states and every existing `dark:` class.

- [ ] **Step 1: Add failing dashboard theme tests**

Render Logistics and Procurement dashboards and assert their module hero and metric cards expose `metrics-card`, neutral Light Mode borders/icons, and preserved dark variants. Add a source audit covering Inventory and HR metric cards.

```tsx
expect(screen.getByTestId('logistics-module-summary')).toHaveClass('border-gray-200', 'bg-white', 'dark:border-gray-800');
expect(screen.getAllByLabelText(/Active shipments|Due today|Overdue deliveries|Delivery success rate/)[0]).toHaveClass('metrics-card', 'border-gray-200');
```

```ts
expect(inventorySource).toContain('metrics-card');
expect(hrSource).toContain('metrics-card');
expect(inventorySource).not.toContain('hover:-translate-y-1');
expect(hrSource).not.toContain('hover:-translate-y-1');
```

- [ ] **Step 2: Run focused dashboard tests and verify failure**

Run:

```powershell
npm.cmd run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Dashboard.test.tsx resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx resources/js/__tests__/ownerDashboardCardTheme.test.ts
```

Expected: FAIL because module summaries, tone maps, and legacy metric cards still expose decorative blue/green gradients and borders.

- [ ] **Step 3: Apply the neutral card treatment**

For Logistics and Procurement:

- replace Light Mode hero gradients and blue borders with `metrics-card border-gray-200 bg-white`;
- make hero/module icon badges `bg-gray-100 text-[#111111]` while preserving their current `dark:` classes;
- make non-semantic overline text and snapshot borders neutral;
- set metric-card Light Mode icon/value/border classes to gray/black, retaining current `dark:` tone classes;
- keep semantic success/error/attention messages and progress bars colored.

For Inventory and HR:

- add `metrics-card` to metric cards;
- remove hover translation/rotation and decorative gradient classes from Light Mode;
- use `bg-gray-100 text-[#111111]` for icon badges with the original `dark:` classes;
- retain semantic change/status pills and progress bars.

- [ ] **Step 4: Run focused dashboard tests and verify pass**

Run the command from Step 2.

Expected: all selected Vitest files PASS.

---

### Task 4: Audit, regression verification, and fresh production build

**Files:**
- Modify: `public/build/**` through the production build only.
- Do not modify or stage pre-existing unrelated files.

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: verified source plus fresh Vite production assets.

- [ ] **Step 1: Audit remaining decorative Light Mode colors**

Run:

```powershell
rg -n "bg-gradient|border-(blue|green|emerald|purple|indigo)-|text-(blue|green|emerald|purple|indigo)-|colors:" resources/js/layout/CanonicalOwnerSidebar.tsx resources/js/components/owner-shell resources/js/Pages/ERP/Finance/Dashboard.tsx resources/js/Pages/ERP/inventory/InventoryDashboard.tsx resources/js/Pages/ERP/Logistics/Dashboard.tsx resources/js/Pages/ERP/HR/Dashboard.tsx resources/js/Pages/ERP/Procurement/Dashboard.tsx resources/js/components/ecommerce
```

Classify each match: remove decorative Light Mode color, or retain it only when semantic or protected by `dark:`.

- [ ] **Step 2: Run the frontend regression suite**

Run:

```powershell
npm.cmd run test:frontend
```

Expected: PASS.

- [ ] **Step 3: Run relevant Laravel source-contract tests**

Run:

```powershell
php artisan test tests/Feature/AppShellLoaderTest.php
```

Expected: PASS.

- [ ] **Step 4: Generate a fresh production build**

Run:

```powershell
npm.cmd run build
```

Expected: Vite exits 0 and writes a new `public/build/manifest.json` plus hashed assets.

- [ ] **Step 5: Run final hygiene checks**

Run:

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors, no unmerged files, and only intended source/build files plus the user's pre-existing unrelated changes.

- [ ] **Step 6: Commit implementation and build separately**

```powershell
git add resources/css/app.css resources/js/layout/CanonicalOwnerLayout.tsx resources/js/layout/CanonicalOwnerSidebar.tsx resources/js/components/owner-shell/OwnerModuleTabs.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/components/owner-shell/__tests__/OwnerModuleTabs.test.tsx resources/js/__tests__/metricCardSizing.test.ts resources/js/__tests__/monochromeTheme.test.ts resources/js/__tests__/ownerDashboardCardTheme.test.ts tests/Feature/AppShellLoaderTest.php resources/js/Pages/ERP/Logistics/Dashboard.tsx resources/js/Pages/ERP/Procurement/Dashboard.tsx resources/js/Pages/ERP/inventory/InventoryDashboard.tsx resources/js/Pages/ERP/HR/Dashboard.tsx resources/js/Pages/ERP/Logistics/__tests__/Dashboard.test.tsx resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx
git commit -m "fix: unify shop owner light mode cards"
git add public/build
git commit -m "chore: refresh production build assets"
```

- [ ] **Step 7: Push the feature branch normally**

```powershell
git push origin feature/monochrome-erp-theme
```

Expected: remote branch advances to the fresh build commit without force because the previous rebase is already synchronized.

