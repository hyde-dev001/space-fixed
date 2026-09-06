# Clean Operational Page Headers

## Context

Operational pages across the ERP and Shop Owner areas repeat a large visible page title and subtitle above the actual working surface. The dashboards already use their header area intentionally and should remain unchanged. Inventory pages also expose context pills such as `Products` near the top header even though the table below already establishes the page context.

The customer navigation drawer has two related visual issues: the Account trigger lost its user icon, and the Logout action does not communicate its interactivity through a hover state.

## Design

Use a surgical, page-level cleanup rather than a global `h1` rule or a shared-layout refactor.

- Remove only visible top-level title/subtitle blocks from non-dashboard operational pages under Manager, Staff, Finance, HR, CRM, Cashier, Repairer, Inventory, Procurement, Logistics/Dispatcher, and Shop Owner variants.
- Keep dashboard page headers and dashboard cards unchanged. A dashboard is an actual dashboard route/component; operational pages such as product management, inventory, orders, approvals, settings, and support are in scope.
- Preserve Inertia/browser `<Head>` titles, internal section headings, modal/dialog headings, loading states, error/access-denied headings, and table/card labels.
- If a removed header shared a row with actions, preserve the actions and keep them aligned at the top of the content area.
- Remove stray top-header context pills such as `Products`, `Products + Repair Materials`, and `Inventory Tracking` where they only duplicate the page context; keep functional controls such as Refresh, filters, and primary actions.
- Keep adjacent icon-only action buttons in a horizontal, no-wrap row with enough column width and spacing; allow the table to scroll horizontally on narrow screens rather than stacking or overlapping actions.
- Restore the existing user/account icon in the customer Account trigger without changing its accordion behavior.
- Add a visible hover and keyboard-focus state to the Logout action while preserving its existing logout handler and red destructive styling.

## Boundaries

No route, data request, permission check, handler, table behavior, dashboard content, modal workflow, or browser title changes are part of this work. The change is limited to visible layout markup and interaction states.

## Acceptance criteria

1. Non-dashboard operational pages no longer show the redundant top-level title/subtitle block.
2. Dashboard headers remain present and visually unchanged.
3. Inventory pages do not show the stray top context label identified in the screenshots.
4. Account shows a user icon beside its label.
5. Logout has an obvious hover state and a visible keyboard-focus state, and still invokes the existing logout flow.
6. Existing page actions remain available and aligned after the title block is removed.
7. Table icon actions remain horizontally aligned and do not wrap into a vertical stack.
8. Existing frontend tests pass, new regression coverage protects the dashboard/header boundary and navigation states, and a fresh production build succeeds.

## Verification plan

- Add focused source/contract checks for the dashboard exclusions, representative operational headers, inventory context pills, the Account icon, and Logout hover/focus classes.
- Run the focused affected tests, then the full frontend suite.
- Run `git diff --check` and a fresh Vite production build.
- Preserve the existing worktree isolation and include the generated `public/build` output when the implementation is complete.
