# Repair job orders layout refinement

## Goal

Refine the ERP repair job orders and warranty queue presentation to match the approved monochrome visual direction without changing repair workflows or API behavior.

## Scope

- `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
  - Align the active workload and pending refund review summary to the right side of the page header.
  - Improve table column proportions and cell spacing for readable customer, item, service, status, intake, payment, date, and action data.
  - Render every table status label as plain black text with no colored background, including `Assigned to Repairer` and secondary payment/refund labels.
- `resources/js/Pages/ERP/repairer/WarrantyQueue.tsx`
  - Remove the `Refresh Queue` control while retaining the existing automatic refresh and data-fetch behavior.

## Visual rules

- Preserve the existing black active tab states and page typography.
- Use the existing gray/black design tokens and responsive flex/grid utilities.
- Keep status text readable in the current light and dark layout variants; the status labels must not rely on colored backgrounds.
- Keep the table usable at narrow widths through its existing horizontal/vertical overflow behavior.

## Non-goals

- No API, endpoint, filter, pagination, modal, action, or status-transition changes.
- No changes to the separate ShopOwner repair-management components; this request is scoped to the ERP pages shown in the screenshots.

## Verification

- Add or update focused visual/source tests for header alignment, removal of `Refresh Queue`, and background-free status labels.
- Run the focused repairer tests.
- Run the full frontend Vitest suite, production Vite build, and `git diff --check`.
