# Procurement Dashboard Design

**Date:** 2026-09-02

**Status:** Approved direction; pending written-spec review

## Goal

Add a real Procurement Dashboard page for employee procurement accounts and upgrade the existing Shop Owner procurement dashboard with the same backend-owned data, visual language, and interaction model.

The employee dashboard will be available at `/erp/procurement/dashboard`. The Shop Owner route `/shop-owner/oversee/procurement` remains the canonical owner dashboard route and keeps its existing owner shell, tabs, authorization boundary, and tenant context.

Both routes render the existing `ERP/Procurement/Dashboard` Inertia page and consume one tenant-scoped procurement read model. The dashboard is a read-only overview; existing purchase-request, purchase-order, supplier, approval, and receiving workflows remain unchanged.

## Design principles

- Use the `Data-Dense Dashboard` pattern selected by `ui-ux-pro-max` for high information visibility in an operational ERP screen.
- Use the existing `DESIGN.md` as the visual source of truth: neutral black/white/cloud-gray surfaces, an 8px spacing rhythm, restrained borders, limited shadows, and semantic colors only for state.
- Reuse the existing `AppLayoutERP`, Lucide icons, Tailwind conventions, Inertia props, and installed ApexCharts dependency.
- Keep authorization and tenant isolation server-owned. React receives display data and approved URLs; it never chooses a shop owner or derives access from a client value.
- Prefer a useful empty state over placeholder data. Counts and chart values are zero when the shop has no procurement records.
- Keep the first version focused: no polling, no new database tables, no schema migration, and no new package dependency.

## Options considered

### Shared server-provided dashboard payload (selected)

Create one `ProcurementDashboardService` that accepts the resolved shop-owner tenant ID and returns the dashboard view model. The employee page route and canonical owner dashboard both call the same service through their existing actor-specific boundaries.

This keeps the employee and owner screens consistent, avoids multiple client requests, allows the six-month trend to be computed once, and adds the smallest new authorization surface.

### Compose existing metrics endpoints in React

The page could fetch the existing purchase-request and purchase-order metrics endpoints and combine their responses. This does not provide the requested six-month trend without additional endpoint behavior, and it creates separate employee/owner URL and permission concerns. It is not selected.

### Add a dedicated live dashboard API

A paired employee/owner API could support polling, filters, and real-time updates. This is useful for a later analytics phase, but it would add route-catalog, authorization, payload, and performance complexity that is not required for the first dashboard. It is intentionally deferred.

## Information architecture

The page remains inside the existing ERP shell.

### Header

- Eyebrow: `ERP module`
- Title: `Procurement Dashboard`
- Description: a short explanation that the page tracks purchasing requests, supplier commitments, and procurement activity for the current shop.
- Status badge: `Operational snapshot` with a neutral/green availability indicator and the text `Current shop procurement records`.
- A low-emphasis `Refresh data` action reloads only the dashboard Inertia prop. It does not introduce timed polling.

Employee navigation adds `Dashboard` as the first item in the Procurement section. Existing procurement links and employee permissions remain unchanged.

The Shop Owner canonical procurement shell keeps its existing `Dashboard`, `Purchase Requests`, `Purchase Orders`, and `Suppliers` tabs. The Dashboard tab continues to resolve to `/shop-owner/oversee/procurement`.

### KPI row

Show four compact cards in a responsive grid:

1. **Purchase requests** — total tenant-scoped purchase requests.
2. **Awaiting review** — requests in `pending_finance`, `pending_shop_owner`, or `pending_finance_final`.
3. **Purchase orders** — total tenant-scoped purchase orders.
4. **Open order value** — sum of `total_cost` for active purchase orders in the model's active receiving statuses (`sent`, `confirmed`, `in_transit`, and `partially_received`).

Each card includes a Lucide icon, a concise explanation, tabular numeric formatting, and no fabricated percentage or period-over-period comparison. Monetary values use the app's PHP peso presentation convention. When records are absent, the values are `0` or `₱0.00`.

### Six-month activity chart

Use an ApexCharts line/area chart titled `Procurement activity`.

- Default range: the current month plus the five preceding calendar months.
- Series: monthly purchase-request count and monthly purchase-order count.
- Missing months are returned as zero so the x-axis is stable.
- The chart includes a visible legend, keyboard/focus-compatible surrounding controls, readable tooltips, and a text summary/fallback for users who cannot use the chart interaction.
- On small screens the chart remains single-column and keeps labels legible; it must not require horizontal page scrolling.
- The chart uses two restrained accent colors that remain distinguishable in light and dark mode while preserving the neutral `DESIGN.md` surface treatment.

The chart is intentionally count-based. Financial amounts are not placed on the same axis as record counts.

### Workflow status panels

Below the chart, render two equal panels:

- **Purchase request status** — counts for the supported request statuses: Draft, Pending Finance, Pending Shop Owner, Pending Finance Final, Approved, and Rejected.
- **Purchase order status** — counts for the supported order statuses: Draft, Sent, Confirmed, In Transit, Partially Received, Delivered, Completed, and Cancelled.

Each status row includes a label, count, and proportional neutral/semantic bar. The count remains the accessible source of truth; the bar is supplemental. Status colors follow existing semantic conventions and are not used for decorative gradients.

### Recent activity

Render up to five latest tenant-scoped procurement records, combining purchase requests and purchase orders by creation date. Each row contains:

- record type;
- reference number (`pr_number` or `po_number`);
- product or short description;
- status label;
- total cost;
- created/requested date.

Rows link only to existing approved procurement pages or remain non-clickable when no safe detail URL is available. The service must not introduce cross-tenant identifiers into client links. If there are no records, show a clear empty state and links to the existing Purchase Requests and Purchase Orders pages.

## Backend contract

Create a focused `ProcurementDashboardService` with a method equivalent to:

```php
public function forShopOwner(int $shopOwnerId): array;
```

The returned view model contains stable, explicit keys:

```text
title
description
summary:
  purchase_requests
  awaiting_review
  purchase_orders
  open_order_value
trend:
  period_label
  months[]: label, start, end, purchase_requests, purchase_orders
request_statuses[]: key, label, count
order_statuses[]: key, label, count
recent_activity[]: type, reference, description, status, amount, occurred_at, url|null
refreshed_at
```

The service uses existing `PurchaseRequest`, `PurchaseOrder`, and related model scopes where available. All queries filter by the server-resolved tenant owner ID. The service does not accept a request-provided `shop_owner_id`, `shop_id`, or actor override.

The employee page method resolves the tenant using the existing employee ERP context/helper and passes that ID to the service. The owner canonical dashboard receives the tenant from `ErpActorContext`/canonical owner shell resolution. No owner ID is placed in the URL or accepted as a dashboard input.

The existing `CanonicalOwnerOverviewService` procurement branch is replaced or narrowly delegated so it returns the richer procurement view model without changing other module dashboards. Existing `CanonicalOwnerDashboardService` behavior and canonical route names remain intact.

## Route and navigation contract

Add the employee page route:

```text
GET /erp/procurement/dashboard
name: erp.procurement.dashboard
guard: auth:user
access: existing procurement dashboard/view procurement permission contract
```

Register it with the existing procurement route catalog and route-matrix tests. Add the route to the employee procurement sidebar and its static route-path fallback. Do not change the current URLs or middleware of purchase requests, purchase orders, stock-request approval, supplier management, or attendance.

The owner route remains:

```text
GET /shop-owner/oversee/procurement
name: shop-owner.shell.oversee.procurement
guard: existing canonical shop-owner shell boundary
component: ERP/Procurement/Dashboard
```

Do not create a second owner dashboard URL unless an existing route contract requires it. Owner tabs continue to use their current page URLs.

## Frontend behavior and accessibility

- Keep the existing `AppLayoutERP` wrapper and page title.
- Replace the current two-card-only presentation with the sections defined above while retaining the recognizable Procurement Dashboard header.
- Use typed dashboard props with safe defaults for partial/empty payloads.
- Use existing route helpers and server-provided URLs for navigation.
- Show a visible focus state on refresh and links; use semantic headings, landmark sections, `aria-labelledby`, and meaningful chart summaries.
- Respect `prefers-reduced-motion` for chart/hover transitions.
- Keep cards one column at approximately 375px, two columns on tablet, and four KPI columns on wide desktop. The chart is full width; status panels stack on narrow screens.
- Avoid emoji, raw decorative SVGs, and unlabelled icon-only controls.
- Do not add client-side authorization logic or a second owner navigation model.

## Testing and acceptance criteria

### Backend

- The service returns tenant-scoped summary counts, status counts, six zero-filled calendar buckets, and recent activity.
- The service excludes another shop owner's records from every section.
- Active order value follows the existing active-order status semantics.
- Employee dashboard route renders the real Inertia component for an authorized employee and preserves existing password/permission boundaries.
- Employee access is denied when the procurement dashboard/view permission contract is absent.
- Canonical owner procurement route renders the richer dashboard for an approved owner and still uses the owner tenant context.
- Cross-tenant or client-supplied owner IDs cannot change the result.
- Existing procurement page, owner shell, route coverage, and tenant-isolation tests remain green.

### Frontend

- Dashboard renders the four KPI cards, six-month chart series, status panels, recent activity, and empty state from Inertia props.
- Missing optional payload fields do not crash the page.
- Refresh invokes an Inertia-only dashboard reload.
- Employee sidebar renders Dashboard first in Procurement and preserves existing procurement items.
- Shop Owner canonical tabs still expose Dashboard as the active tab and retain existing links.
- Responsive and accessible labels are present for chart, status rows, links, and refresh action.

### Verification

Run focused tests first, then the relevant full suites, production build, diff hygiene check, and browser verification. Do not claim TypeScript lint or type-check success because the repository does not currently provide those scripts/configuration.

## Out of scope

- Purchase-request or purchase-order workflow changes.
- New database tables or migrations.
- New API namespaces or timed polling.
- Financial forecasting, supplier performance scoring, or period-over-period comparisons.
- Changes to unrelated employee or Shop Owner dashboards.
- Replacing the canonical owner route or owner shell.
