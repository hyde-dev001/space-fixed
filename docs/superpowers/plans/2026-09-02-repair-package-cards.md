# Repair Package Analytics Cards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the repair dashboard's package analytics cards with shared ERP metric cards, complete its dark-mode styling, and remove the generic `ERP module` header label from account dashboards without changing the backend data contract.

**Architecture:** Keep `dashboardRepair.tsx` as the consumer of the existing package analytics endpoint. Reuse `DashboardMetricCard` for the five package metrics, apply the repository's existing neutral dark-mode classes to the surrounding package analytics surfaces, and remove the generic label at the shared dashboard-shell boundary.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Tailwind CSS 4, Vitest, Testing Library, Vite.

## Global Constraints

- Preserve the existing `/api/repair-packages/analytics` response and package analytics behavior.
- Do not add a dependency or alter database queries.
- Keep the change isolated to the repair dashboard, the shared dashboard shell boundary, the affected dashboard tests, the design/implementation notes, and the generated `public/build` output.
- Preserve light-mode appearance and existing responsive behavior outside the package analytics block.
- No account dashboard header may display the generic `ERP module` eyebrow.

---

### Task 1: Add the package-card regression test

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/__tests__/Dashboard.test.tsx`
- Modify: `resources/js/components/dashboard/__tests__/DashboardPrimitives.test.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx`

**Interfaces:**
- Consumes: The page's existing `initialDashboard` props and `/api/repair-packages/analytics` response shape.
- Produces: Failing tests that require five shared metric cards, a marked dark-mode package analytics surface, and no generic `ERP module` eyebrow.

- [x] **Step 1: Write the failing test**

Mock the Axios analytics response and render the non-owner repair dashboard. Assert that the resolved package analytics block exposes five `dashboard-metric-card` elements, the server values, and the `repair-package-analytics` surface with its dark-mode class. Update the shared-shell and Procurement dashboard tests to assert that `ERP module` is absent.

- [x] **Step 2: Run the test to verify it fails**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/repairer/__tests__/Dashboard.test.tsx
```

Expected: FAIL because the current package metrics use local plain `div` cards and the package analytics surface does not have the new test id/class.

### Task 2: Replace the five package cards, normalize dark mode, and remove the generic eyebrow

**Files:**
- Modify: `resources/js/Pages/ERP/repairer/dashboardRepair.tsx:316-452`
- Modify: `resources/js/components/dashboard/DashboardShell.tsx:4-75`
- Modify: `resources/js/Pages/ERP/Procurement/Dashboard.tsx:303-317`
- Modify: `resources/js/Pages/ERP/ModuleLanding.tsx:37-47`

**Interfaces:**
- Consumes: `analytics.overview`, `analytics.top_packages`, `analytics.monthly_trend`, and `analytics.recent_bookings` from the existing package analytics payload.
- Produces: Five `DashboardMetricCard` instances, dark-mode-safe package analytics panels, and dashboard headers with no generic `ERP module` label or payload changes.

- [x] **Step 1: Use the shared card primitive**

Import the existing Lucide icons and render the package metrics as:

```tsx
<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
  <DashboardMetricCard label="Packages" value={analytics.overview.total_packages} description={`${analytics.overview.active_packages} active • ${analytics.overview.inactive_packages} inactive`} context="Catalog" icon={Package} />
  <DashboardMetricCard label="Bookings" value={analytics.overview.total_bookings} description={`${analytics.overview.bookings_last_30_days} in the last 30 days`} context="Demand" icon={CalendarCheck2} />
  <DashboardMetricCard label="Net Revenue (Excl. VAT)" value={`₱${Number(analytics.overview.package_revenue).toFixed(2)}`} description={`Refund-adjusted • ₱${Number(analytics.overview.revenue_last_30_days).toFixed(2)} in the last 30 days`} context="Revenue" icon={CircleDollarSign} tone="success" />
  <DashboardMetricCard label="Avg Order" value={`₱${Number(analytics.overview.average_order_value).toFixed(2)}`} description={`Add-ons ₱${Number(analytics.overview.add_on_revenue).toFixed(2)}`} context="Value" icon={ReceiptText} />
  <DashboardMetricCard label="Add-on Attach Rate" value={`${analytics.overview.add_on_attach_rate}%`} description="Orders with add-ons attached" context="Add-ons" icon={Percent} />
</div>
```

- [x] **Step 2: Add the dark-mode surface classes**

Keep the existing markup and data for the tables and lists, adding the corresponding `dark:` variants to the outer package analytics section, table/list surfaces, headings, body text, borders, dividers, and muted rows. Add `data-testid="repair-package-analytics"` to the outer section for the regression test.

- [x] **Step 3: Remove the generic dashboard eyebrow**

Remove the default `ERP module` paragraph from `DashboardShell`, remove the matching standalone paragraph from the Procurement dashboard and the generic module landing page, and remove the now-unneeded top margin from their titles.

### Task 3: Verify, build, and deliver

**Files:**
- Modify: `public/build/` generated assets and `public/build/manifest.json`

- [x] **Step 1: Run the focused repairer suite**

Run:

```powershell
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/repairer/__tests__ resources/js/components/dashboard/__tests__/DashboardPrimitives.test.tsx
```

Expected: all focused repairer and shared-dashboard tests pass.

- [x] **Step 2: Run the full frontend suite**

Run:

```powershell
node_modules/.bin/vitest.cmd run
```

Expected: zero failed tests.

- [x] **Step 3: Generate the production build**

Run:

```powershell
node_modules/.bin/vite.cmd build
```

Expected: Vite exits with code 0 and emits a fresh repair dashboard bundle plus manifest entry.

- [x] **Step 4: Check and commit the isolated change**

Run:

```powershell
git diff --check
git add docs/superpowers/specs/2026-09-02-repair-package-cards-design.md docs/superpowers/plans/2026-09-02-repair-package-cards.md resources/js/Pages/ERP/repairer/dashboardRepair.tsx resources/js/Pages/ERP/repairer/__tests__/Dashboard.test.tsx public/build
git commit -m "fix: align repair package analytics cards"
```

Expected: no whitespace errors and one commit containing only this card/dark-mode/label update and its build output.

- [ ] **Step 5: Push the existing feature branch**

Run:

```powershell
git push
```

Expected: `feature/account-dashboards` updates successfully for the user's existing PR.
