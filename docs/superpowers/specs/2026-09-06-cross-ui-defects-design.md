# SoleSpace Cross-UI Defects Design

**Status:** Approved by the user on 2026-09-06

## Goal

Repair four UI defects without changing backend behavior: courier tracking save feedback, Super Admin flagged-account modal layering, decorative metric-card trend badges, and HR sidebar active-route matching.

## Scope and constraints

- Frontend React/TypeScript and shared UI component changes only.
- Do not change controllers, models, migrations, API contracts, route definitions, or business calculations.
- Preserve existing courier save success/error handling and request payloads.
- Preserve legitimate business percentages in forms, reports, detail rows, and charts.
- Preserve responsive behavior and existing light/dark styling.
- Make the smallest maintainable change in the existing component patterns.
- Leave unrelated pre-existing working-tree changes untouched.

## Root causes found

1. `resources/js/Pages/UserSide/Repairs/myRepairs.tsx` renders the shared intake/return courier tracking save label with a malformed encoded ellipsis string. The same component serves both legs, so one UI correction covers intake and return tracking.
2. `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx` renders its detail overlay inline with `z-50`, while the shared Super Admin header is `z-99999`. The overlay is therefore allowed to sit beneath the header and other high-layer interface elements. The repository already provides `resources/js/components/ui/modal/index.tsx` with the established viewport overlay, backdrop, escape handling, and scroll locking pattern.
3. Decorative increase/decrease chips are implemented in many page-local `MetricCard` components. The shared `DashboardMetricCard` does not own these badges, so removing one shared prop would not cover the legacy cards. The fix will remove only the badge presentation and its presentation-only inputs from each affected local card.
4. HR dashboard, employee, attendance, and payroll navigation entries reuse the `erp.hr` route with different `section` query parameters. `AppSidebar_ERP.tsx` currently treats any descendant of `/erp/hr` as active before honoring the section parameter, which makes unrelated HR entries active on `/erp/hr/articles`.

## Design

### Courier tracking

Keep the existing `saving` state, `disabled` condition, Axios request, refresh callback, success message, error message, and `finally` cleanup. Replace only the loading label with the ASCII-safe text `Saving...`. Add a regression assertion that an in-flight intake or return save is disabled and shows `Saving...`, then verify the normal label remains available after the request resolves.

### Flagged-account modal

Replace the page-local fixed overlay in `FlaggedAccounts.tsx` with the existing shared `Modal` component. Keep the current report-detail content, close control, decision actions, SweetAlert confirmations, and account state updates unchanged. Configure the shared modal to retain the current large responsive content width, internal scrolling, dark-mode surface, and page-owned close button.

The shared modal's body-scroll lock will preserve the browser scrollbar gap while the modal is open, so hiding page scroll cannot move the underlying layout. The overlay remains viewport-fixed and uses the established high layer rather than an arbitrary page-specific z-index. Verify that the header/sidebar remain behind the backdrop and that the dialog remains clickable at desktop and narrow viewport widths.

### Metric-card trend badges

Keep the metric title, value, icon, tone, description, links, cards, charts, and data-fetching logic. Remove the decorative badge block that displays an arrow plus a `change` percentage from every affected local metric-card implementation, including positive and negative variants. Remove only imports, props, destructured values, helper icons, and literal change arguments that become unused because of that removal.

The shared `DashboardMetricCard` remains unchanged because it already renders no decorative trend badge. Percentage values that are part of business data, such as VAT, discounts, tax rates, completion/report values, approval deltas, and chart series, remain untouched.

### HR sidebar matching

Update `AppSidebar_ERP.tsx` so route entries with a `section` parameter match only the canonical `/erp/hr?section=<value>` location, including the existing route-name fallback behavior. Parameterized entries will not claim descendants such as `/erp/hr/articles`; unparameterized entries will retain their existing descendant matching for detail routes. Keep the HR group expansion behavior based on an active child.

Add coverage for the HR dashboard, employees, attendance, payroll, My Payslips, and Articles locations. Each location must have only its intended item active; Articles must not activate the parameterized `/erp/hr` entries, while real attendance/payroll section URLs must still expand and activate their own parent/child navigation.

## Affected UI areas

- `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx`
- `resources/js/components/ui/modal/index.tsx`
- `resources/js/layout/AppSidebar_ERP.tsx`
- Existing frontend tests beside courier tracking, flagged accounts, and ERP navigation.
- Page-local legacy metric-card implementations identified by the `change`/`changeType` arrow-badge pattern under `resources/js/Pages`.

No backend files or route definitions are part of this design.

## Verification

1. Run focused frontend tests for courier tracking, flagged accounts, and `AppSidebar_ERP`.
2. Run the complete frontend suite with `pnpm run test:frontend`.
3. Build the frontend with `pnpm run build`.
4. Run `git diff --check`.
5. If the local app can be started safely, use Playwright to inspect the flagged-account modal at desktop and a small viewport, and verify the courier loading label and HR active states in rendered DOM.
6. Re-scan changed frontend files for malformed loading strings, decorative arrow-percentage chips, unused imports, and unintended business-percentage removals.

## Acceptance criteria

- Courier save buttons show `Saving...`, remain disabled while saving, and restore their original labels after completion.
- The flagged-account modal and backdrop cover the viewport, stay above the Super Admin interface, remain clickable, and do not shift page layout on open/close.
- Decorative trend percentage badges are gone from all affected statistic/metric cards.
- Legitimate business percentages remain present.
- HR Articles activates only Articles; each audited HR route activates only its intended navigation item and preserves intended parent expansion.
- No backend or workflow behavior changes are introduced.
