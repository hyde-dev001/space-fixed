# Supplier Modal and Flagged Accounts Layout Design

## Goal

Improve spacing and readability for the Add New Supplier modal and the Flagged Accounts table without changing existing data, routes, permissions, filters, or moderation actions.

## Scope

### Add New Supplier modal

- Keep the current fields and submit behavior.
- Add responsive top and bottom breathing room around the modal.
- Constrain the modal to the viewport with a scrollable content region when needed.
- Keep the header and action footer usable while the form content scrolls.
- Preserve the existing SoleSpace colors, borders, typography, and button hierarchy.

### Flagged Accounts page

- Expand the page/table content area on desktop.
- Use a fixed table layout with explicit readable column widths.
- Increase row and cell spacing so customer, email, reason, reporter, date, status, and actions are easy to scan.
- Keep the table horizontally scrollable on narrow viewports instead of allowing the page layout to break.
- Keep server-side filtering and pagination; make the pagination summary and Previous/Next controls visible even when there is only one page.

## Approach

Apply the smallest change directly in the two existing page components:

- `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx`

No new component, dependency, database change, controller change, or route change is needed. The flagged-account controller already returns a Laravel paginator, and the existing page already requests filtered pages through Inertia.

## Interaction and accessibility

- Keep visible labels on all supplier inputs.
- Preserve keyboard-accessible native buttons and form controls.
- Add/retain accessible names for icon-only close and table action controls where touched.
- Keep disabled pagination controls visibly disabled and non-interactive.
- Preserve the existing modal escape route and backdrop dismissal.
- Maintain at least 44px touch targets for pagination and close controls.

## Verification

- Add only narrow frontend assertions when a changed behavior is not already covered.
- Run the targeted Flagged Accounts and procurement frontend tests.
- Run the frontend production build.
- Inspect the final diff and run `git diff --check`.
- If the local app is runnable, verify the modal at normal desktop/mobile widths and the table at desktop plus a narrow viewport.

## Out of scope

- Adding supplier fields or payment-profile functionality.
- Changing server-side pagination size or query behavior.
- Redesigning unrelated pages or changing the existing SoleSpace theme.
