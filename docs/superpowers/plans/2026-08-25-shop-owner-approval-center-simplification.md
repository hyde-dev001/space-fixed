# Shop Owner Approval Center Simplification

## Goal

Make the Shop Owner Action Center an approval-only inbox. The page will show
only decisions that currently require the authenticated Shop Owner's action,
with coverage filters and the existing review/approve/reject workflow.

## Scope

- Rename the owner-facing page to **Approval Center**.
- Remove the `Urgent Exceptions` and `Waiting on Others` tabs and summaries from
  the centralized owner page.
- Normalize old exception/waiting bucket URLs back to the approval queue so
  legacy links do not expose the retired page modes.
- Keep the underlying exception and waiting adapters available for
  module-specific workflows; the Shop Owner Home and Approval Center consume
  only the owner-decision queue. This change is a presentation and
  route-boundary simplification, not a deletion of those workflows.
- Keep tenant scoping, current-owner/actionable filtering, coverage health
  states, source filters, pagination, and approval detail decisions intact.

## Implementation sequence

1. Update route and frontend regression tests for the approval-only contract.
2. Run the focused tests to establish the expected failures.
3. Restrict the page controller to the `needs_my_decision` queue and redirect
   known legacy exception/waiting URLs.
4. Simplify the React hierarchy and labels to one approval queue with source
   filters and the existing detail panel.
5. Run focused and broader Shop Owner/frontend tests, inspect the diff, and
   record verification evidence.

## Acceptance criteria

- The page title is `Approval Center` and communicates the number of approvals
  requiring the owner's decision.
- No `Urgent Exceptions` or `Waiting on Others` navigation or content appears
  on the owner approval page or canonical Shop Owner Home.
- Canonical Shop Owner Home receives and renders only the bounded owner-decision
  summary; it does not serialize retired exception or waiting summaries.
- The page exposes approval filters for all supported owner-approval families,
  including `All Approvals` and `Price Changes`.
- A record appears only when the existing owner-action-center adapters classify
  it as waiting on `shop_owner` and requiring owner action.
- Review opens the existing approval detail panel; approve/reject behavior is
  unchanged, and completed decisions leave the active queue after refresh.
- Known legacy exception/waiting bucket URLs redirect to the canonical approval
  queue.

## Verification

- `php artisan test tests/Feature/ShopOwner/ActionCenter`
- `php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php`
- `pnpm exec vitest run resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx --maxWorkers=1 --minWorkers=1`
- `pnpm exec vitest run resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx --maxWorkers=1 --minWorkers=1`
- `pnpm run test:frontend`
- `pnpm run build`
- `git diff --check`
