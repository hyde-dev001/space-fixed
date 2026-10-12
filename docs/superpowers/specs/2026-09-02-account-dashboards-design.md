# Account dashboards consistency design

Date: 2026-09-02
Status: Approved for implementation
Scope: ERP employee dashboards, with Procurement retained as the visual baseline

## Problem

The Procurement dashboard now provides the clearest operational view in the ERP, but the other account dashboards use different layouts, card styles, chart treatments, and data loading patterns. Staff has no true dashboard entry point, and Cashier currently opens directly into POS.

The goal is to give each listed account a useful, database-backed dashboard without changing existing business workflows, URLs, authorization boundaries, or tenant ownership rules.

## Goals

- Give Manager, Staff, Finance, HR, CRM, Cashier, Repairer, Inventory, and Logistics Dispatcher a consistent dashboard experience.
- Reuse the Procurement dashboard's information hierarchy: module header, operational snapshot, KPI cards, trend/health section, and actionable attention areas.
- Keep account-specific metrics meaningful to the role instead of forcing every role into one generic data model.
- Connect every value to existing tenant-scoped database queries or existing dashboard services. No placeholder metrics will be shipped.
- Add a real Staff dashboard while preserving the existing Staff Customers page.
- Add a Cashier dashboard entry point while preserving POS as the operational destination.
- Preserve existing dark-mode behavior, navigation, permissions, route names, and deep links.
- Commit a fresh `public/build` after the final source revision.

## Non-goals

- Redesigning the Shop Owner home, Super Admin, or customer-facing dashboards.
- Changing the database schema unless an existing source cannot provide a required value.
- Introducing a new charting, icon, state-management, or UI dependency.
- Replacing existing domain services with one broad cross-role service.
- Adding write actions to dashboards that belong in the existing module pages.

## Visual and interaction direction

The implementation follows `DESIGN.md`, the Procurement dashboard, and the `ui-ux-pro-max` guidance already selected for this task.

- Neutral monochrome chrome: white/canvas surfaces, charcoal text, soft cloud backgrounds, hairline borders, and restrained rounding.
- Semantic color only for status meaning such as healthy, warning, overdue, or blocked. Status must also have a text label or icon; color is never the only signal.
- An 8px spacing rhythm, readable 16px body text, and minimum 44px interactive targets.
- Responsive KPI grid: four columns on wide screens, two on medium screens, and one on small screens. Panels stack without horizontal scrolling.
- A common page header contains the module label, role-specific title and description, operational snapshot, and a refresh affordance where the current page supports reload.
- Charts use the existing ApexCharts dependency with black/gray series, visible labels or legends, meaningful tooltips, and a text summary for assistive technology and no-data states.
- Loading, empty, error, and refreshed-at states are explicit. Empty states explain what is missing and link to the relevant module when a safe route exists.
- Existing dark-mode variants remain available for every new shared surface. Motion is limited to existing application transitions and respects reduced-motion preferences.

## Route and account contract

Existing routes remain the public contract unless a role has no dashboard route.

| Account | Dashboard route | Contract | Main data source |
| --- | --- | --- | --- |
| Manager | `erp.manager.dashboard` | Restyle the existing page and preserve its range-based stats behavior | Existing manager dashboard service/API |
| Staff | `erp.staff.dashboard` | Render a real read-only dashboard; keep `/erp/staff/customers` mapped to customer management | New staff read model using existing tenant/actor context |
| Finance | `finance.dashboard` | Restyle the existing summary and trend page | Existing finance dashboard API/service |
| HR | Existing `erp.hr` flow with its current section contract | Keep the current HR entry behavior and normalize the overview/dashboard presentation | Existing HR analytics controller/read model |
| CRM | `crm.dashboard` | Restyle the existing CRM dashboard and preserve its channel/interaction data | Existing CRM dashboard controller |
| Cashier | New `erp.cashier.dashboard` | Add a read-only summary entry; keep POS at `erp.cashier.point-of-sale` | New cashier read model using existing sales/payment/refund records |
| Repairer | `erp.staff.repair-dashboard` | Restyle the existing repair dashboard and preserve repair analytics links | Existing repairer dashboard controller |
| Inventory | `erp.inventory.inventory-dashboard` | Restyle the existing inventory overview and preserve stock actions | Existing inventory read page/API |
| Logistics Dispatcher | `erp.logistics.dashboard` | Restyle the existing dispatcher dashboard and preserve shipment capabilities | Existing logistics dashboard controller |
| Procurement | Existing procurement dashboard routes | Retain as the visual and information-architecture baseline | Existing procurement dashboard service |

The exact middleware and capability gates on existing routes stay in place. Cashier will reuse the existing read access gate while the route is introduced; a new permission or migration will only be considered if the repository's authorization catalog proves the current gate is insufficient.

## Role-specific dashboard content

Each page uses the common structure but keeps a role-specific read model.

- Manager: period performance, approvals or exceptions, operational workload, and links into manager actions.
- Staff: assigned work, pending tasks, attendance state, and recently completed work. Customer management remains a separate page.
- Finance: revenue/collections, invoices, expenses, refunds, and finance exceptions.
- HR: headcount, active workforce, leave queue, attendance signal, and HR attention items.
- CRM: active customers, open conversations, pending reviews, average rating, channel engagement, and recent interactions.
- Cashier: today's sales, completed transactions, pending payments, refund queue, and a daily transaction/sales trend. POS remains the action destination.
- Repairer: assigned repairs, in-progress repairs, awaiting-materials work, completed work, and repair revenue/service demand.
- Inventory: active items, low-stock items, out-of-stock items, supplier-order signal, and stock movement/attention.
- Logistics Dispatcher: active shipments, due-today shipments, overdue shipments, unassigned/failed work, and current dispatch flow.
- Procurement: purchase requests, purchase orders, supplier commitments, and procurement workflow/attention as already implemented.

The default reporting period is the existing page's supported period. Where a trend series exists, the shared presentation uses the same six-month range convention as Procurement unless the operational nature of the module requires a daily view, such as Cashier or Dispatcher. No frontend-generated fake values are used to fill missing periods.

## Frontend architecture

Create a small shared dashboard UI layer under `resources/js/components/dashboard/`:

- `DashboardShell`: common header, snapshot, responsive content container, and optional refresh affordance.
- `DashboardMetricCard`: typed label/value/detail/link/icon surface with neutral and semantic states.
- `DashboardPanel`: consistent bordered panel with title, description, header actions, and empty/error treatment.
- `DashboardTrendChart`: typed ApexCharts wrapper with loading/no-data text and accessible summary support.
- `DashboardState`: shared loading, empty, error, and retry/refresh presentation where existing page conventions do not already provide one.

These components own visual consistency, not business decisions. Each role page maps its existing or new payload into the shared components and keeps role-specific links, labels, and prioritization in the page module. A single universal dashboard payload is intentionally avoided so authorization and domain meaning remain clear.

Use existing Lucide icons and ApexCharts. Do not add a dependency or copy a second chart implementation into each page. Keep component props typed; do not introduce `any` into the new shared layer.

## Backend and data flow

1. The existing Laravel route/controller or Inertia page supplies the first dashboard snapshot from the server.
2. Existing read services and query objects are reused wherever they already provide the required data.
3. Staff and Cashier receive focused read models only for metrics that are not already available through a safe existing service.
4. All queries derive the shop/tenant from the authenticated ERP actor context. Browser-provided tenant IDs, shop-owner IDs, or account IDs are not accepted.
5. Queries select only fields needed by the dashboard, avoid N+1 relationships, and preserve existing soft-delete/status semantics.
6. The client renders the server snapshot and uses Inertia reload or the existing query mechanism for manual refresh. There is no polling or duplicate request waterfall introduced by this work.
7. Dashboard routes remain read-only; action links point to existing module routes and remain capability-aware.

The implementation will first inspect each existing payload and its authorization path. If an existing page already has a correct database-backed metric, its query will be preserved and only its presentation contract will be adapted.

## Authorization and tenant safety

- Preserve current `auth`, business-type, capability, and role middleware.
- Keep server-side route authorization as the source of truth; hiding a link in React is not authorization.
- Add feature coverage for unauthorized access and cross-tenant isolation for new Staff/Cashier data paths.
- Do not expose raw customer, employee, payment, or shipment records when an aggregate is sufficient.
- Do not modify `.env`, seed production-like data, or run destructive database commands.

## Test and verification contract

Before completion, run and record:

- Focused Laravel feature tests for each changed route/data source, including Staff and Cashier authorization, empty states, and tenant isolation.
- Frontend component/page tests for shared primitives and dashboard smoke contracts.
- Browser verification for all listed account entry points at desktop and narrow responsive widths, including loading/empty/error behavior where it can be reproduced safely.
- `pnpm run test:frontend` or the repository's direct equivalent if the wrapper is unavailable.
- `pnpm run build` after the final rebase, with the resulting `public/build` reviewed as intentional generated output.
- `git diff --check` and a final changed-file/dead-code review.

The repository has no committed TypeScript compiler configuration or frontend lint script, so those checks will not be reported as passing unless tooling is present and actually runs.

## Acceptance criteria

- Every listed account has a reachable dashboard experience using the existing route or the new Cashier route.
- Manager, Finance, HR, CRM, Repairer, Inventory, and Logistics retain their existing data behavior while adopting the shared visual system.
- Staff has a real database-backed dashboard and the existing Customers page still works.
- Cashier has a database-backed read-only dashboard and POS still works.
- Procurement remains functional and is the visual baseline for the other account dashboards.
- Values are tenant-scoped and permission-protected on the server.
- Charts and cards have meaningful empty/loading/error states and no fake data.
- No unrelated dirty files are included in the feature branch.
- Focused tests, browser checks, production build, and diff hygiene pass before push.
- The feature branch is pushed according to `docs/git-workflow.md`; no PR is created or merged by this task.

## Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Existing dashboards use different prop shapes and fetch paths | Normalize at the page boundary and reuse existing services instead of forcing a global API contract |
| Staff dashboard route currently overlaps with customer management behavior | Move only the dashboard route to the new page and leave the explicit Customers route unchanged |
| Cashier data may have multiple payment/status representations | Inspect existing POS/reporting queries first and use the repository's canonical status fields |
| Shared visual changes can accidentally alter dark mode or permissions | Make targeted page/component changes, preserve middleware, and verify both themes/roles in browser tests |
| Fresh `public/build` creates many hashed files | Build once after rebase, review the generated diff, and stage only intentional source plus build output |
