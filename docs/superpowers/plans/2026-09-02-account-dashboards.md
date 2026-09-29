# Account dashboards consistency implementation plan

> Execute this plan sequentially from `C:\programmers\xampp\files\htdocs\solespace-master\.worktrees\account-dashboards` on `feature/account-dashboards`.

## Outcome

Bring the ERP employee dashboards into the Procurement visual system, add database-backed Staff and Cashier dashboard entry points, preserve existing routes and permissions, verify tenant isolation, and push a clean branch with a fresh `public/build`.

## Constraints

- Do not touch the dirty root worktree or unrelated files.
- Do not edit `.env`, `vendor`, or `node_modules`.
- Reuse the current Lucide, ApexCharts, Inertia, Laravel, and authorization infrastructure.
- Do not add a migration or dependency unless inspection proves it is necessary.
- Keep every existing dashboard route and action link working.
- Run focused checks after each coherent slice; build only after final rebase.

## 1. Establish the baseline and exact contracts

Files/areas to inspect:

- `routes/web.php` and the ERP route files that define the dashboard entry points.
- `app/Http/Controllers/ReadPageController.php`.
- Existing dashboard services/controllers for Manager, Finance, HR, CRM, Inventory, Repairer, Logistics, and Procurement.
- `resources/js/Pages/ERP/{Manager,Finance,HR,CRM,inventory,repairer,Logistics,Procurement}` dashboard pages.
- `resources/js/layout/AppSidebar_ERP.tsx` and `config/shop_modules.php`.
- Existing dashboard tests and the shared Inertia/Vitest test setup.

Record the current prop shape, endpoint, capability middleware, tenant-scope helper, and navigation destination for each role. Confirm whether a canonical cashier aggregate already exists before adding a query. Treat any unexpected deletion or unrelated modification as a stop condition.

Verification: `git status --short`, targeted `rg` checks, and the existing dashboard test files.

## 2. Add typed shared dashboard presentation primitives

Create the smallest reusable UI layer under `resources/js/components/dashboard/`:

- `DashboardShell.tsx`: module eyebrow, title/description, operational snapshot, refresh action, and responsive content container.
- `DashboardMetricCard.tsx`: neutral/semantic metric card with icon, value, detail, optional safe link, and accessible label.
- `DashboardPanel.tsx`: bordered surface with title, description, optional action, and consistent dark-mode styles.
- `DashboardTrendChart.tsx`: typed wrapper around the existing ApexCharts integration for neutral series, labels, tooltips, no-data, and accessible summary text.
- `DashboardState.tsx`: shared loading, empty, error, and retry/refresh states.
- `types.ts` or equivalent only if needed for shared props; keep role-specific payload types in the role page or domain module.

Use Tailwind classes and existing application conventions. Avoid a new global theme rewrite and avoid `any` in new code. Use `lucide-react` icons and respect reduced motion. Add focused Vitest tests for the shared primitives, including light/dark classes, empty/error content, link labels, and chart no-data behavior.

Verification: `pnpm exec vitest run` for the new shared component tests.

## 3. Normalize existing dashboards onto the shared visual contract

Update only the presentation boundary first; preserve existing data-fetching behavior and action links.

- `resources/js/Pages/ERP/Manager/Dashboard.tsx`: map current range stats into the shared header, cards, trend panel, approvals/attention panel, and responsive activity content.
- `resources/js/Pages/ERP/Finance/Dashboard.tsx`: preserve the existing finance query/API and map revenue, invoices, expenses, refunds, and exceptions into the shared layout.
- `resources/js/Pages/ERP/HR/Dashboard.tsx` and `resources/js/Pages/ERP/HR/HR.tsx`: preserve the current `section` behavior and server analytics props while making overview/dashboard use the shared layout.
- `resources/js/Pages/ERP/CRM/CRMDashboard.tsx`: preserve channel engagement and recent interaction data while adopting shared cards, chart, and status panels.
- `resources/js/Pages/ERP/inventory/InventoryDashboard.tsx`: preserve the existing initial data and inventory API, keeping stock actions and the current chart source.
- `resources/js/Pages/ERP/repairer/dashboardRepair.tsx`: preserve repair analytics and links while mapping its metrics and tables into the common panel hierarchy.
- `resources/js/Pages/ERP/Logistics/Dashboard.tsx`: preserve dispatcher capability checks, shipment stats, attention items, and flow data while adopting the common visual system.
- `resources/js/Pages/ERP/Procurement/Dashboard.tsx`: use the existing Procurement implementation as the reference and only make compatibility adjustments required by the shared primitives.

Do not remove existing page-specific actions. If a page lacks a real series or current snapshot, render the shared empty state rather than inventing values. Keep data reload/query keys unchanged until a test demonstrates a safe improvement.

Verification after each page family: its existing focused tests plus `git diff --check`.

## 4. Implement the Staff dashboard without breaking Customers

Backend:

- Add a focused Staff dashboard read service or extend the existing Staff read boundary after confirming the canonical models/status fields.
- Derive the authenticated ERP actor and shop scope server-side.
- Return only aggregate/summary fields needed for assigned work, pending work, attendance state, and completed work.
- Add or extend a controller/route handler for the existing `erp.staff.dashboard` route; leave `/erp/staff/customers` mapped to the existing customer controller.

Frontend:

- Create `resources/js/Pages/ERP/STAFF/Dashboard.tsx` using the shared primitives and the Inertia payload.
- Preserve staff-specific links and avoid showing actions the actor cannot perform.
- Add the Staff dashboard as the first staff navigation item without changing the Customers URL.

Tests:

- Feature tests for allowed access, denied access, tenant isolation, empty data, and the unchanged Customers route.
- A Vitest page contract test for the KPI/attention/empty states.

Verification: focused Staff feature and frontend tests.

## 5. Implement the Cashier dashboard and preserve POS

Backend:

- Confirm the canonical sales, payment, and refund models/queries used by POS and finance.
- Add a focused read-only Cashier dashboard controller/service using the existing authenticated shop/actor context.
- Add `erp.cashier.dashboard` without changing `erp.cashier.point-of-sale`; reuse the existing cashier access gate unless the authorization catalog requires a dedicated capability.
- Return today's sales, completed transactions, pending payments, refund count/queue, and a daily series only where canonical records support them.
- Register the new route in `config/shop_modules.php` if that catalog governs navigation/access.

Frontend/navigation:

- Create `resources/js/Pages/ERP/cashier/Dashboard.tsx` with the shared shell, KPI cards, daily trend, status panel, and POS quick link.
- Add Dashboard before POS in the cashier sidebar and update route path allowlists/active-state logic as needed.

Tests:

- Feature tests for route authorization, tenant isolation, empty data, and payload calculations.
- Vitest page contract tests and existing POS tests to ensure the new entry point does not affect checkout behavior.

Verification: focused Cashier feature/frontend tests and route listing check.

## 6. Align navigation and module metadata

Update `resources/js/layout/AppSidebar_ERP.tsx` and `config/shop_modules.php` only where the dashboard entry point or label/order is missing. Preserve capability filtering, active route matching, collapsible group behavior, and existing links. Confirm Logistics Dispatcher, HR, and Procurement route aliases continue to resolve.

Verification: route name inspection, sidebar tests if present, and browser navigation smoke checks.

## 7. Run sequential review gates before the final build

Review the final source diff in this order:

1. Simplify: remove duplicated dashboard markup, unused helpers/imports, and speculative abstractions.
2. Standards/spec/correctness: compare the diff to this plan and repository conventions.
3. TypeScript/React: check typed props, safe narrowing, effect/query behavior, and avoid unnecessary re-renders or direct heavyweight imports beyond existing chart usage.
4. Karpathy pass: verify assumptions, narrow scope, and delete only orphans created here.
5. Security/Laravel pass: verify middleware, tenant scoping, selected fields, authorization, and no client-controlled scope.
6. Reuse/dead-code pass: verify existing services/components/helpers were reused and no stale route/page references remain.

If a later review changes source, rerun the affected focused tests.

## 8. Verify application behavior and production output

Run:

- Focused changed Laravel tests.
- Focused Vitest tests for shared components and changed dashboard pages.
- `pnpm run test:frontend`.
- Browser verification with the existing Playwright/webapp testing setup for all nine listed account entry points at desktop and narrow widths, including light/dark mode where available.
- `git diff --check`.

Resolve failures before building. Do not report TypeScript/lint as passing unless the repository has runnable configured tooling.

## 9. Rebase, build, stage, commit, and push

After the source and tests are final:

1. `git fetch origin --prune`.
2. `git rebase origin/solespace-b`.
3. Re-run focused tests if the rebase touched relevant files.
4. Run `pnpm run build` once on the final revision.
5. Review `git diff --name-status origin/solespace-b...HEAD` and `git diff --stat origin/solespace-b...HEAD`.
6. Stage only intended source, test, documentation, and fresh `public/build` files.
7. Run `git diff --cached --check`.
8. Commit with a focused message, for example `feat: unify ERP account dashboards`.
9. Push with `git push --progress -u origin feature/account-dashboards`.

Do not push to `solespace-b` and do not create/merge the PR. Report the branch, commit, exact checks, and any limitations with evidence.
