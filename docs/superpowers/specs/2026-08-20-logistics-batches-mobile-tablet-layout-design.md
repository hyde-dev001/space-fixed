# Logistics Batches Mobile and Tablet Layout Design

**Date:** 2026-08-20
**Status:** Approved for implementation

## Goal

Make the ERP Batches page polished, readable, and easy to operate on phones and tablets while leaving the existing desktop presentation and batch behavior unchanged.

## Scope

- Target `resources/js/Pages/ERP/Logistics/Batches.tsx` and its existing Batches components.
- Treat widths below Tailwind `xl` as the compact ERP experience, matching the approved logistics responsive shell.
- Refresh only compact presentation: page header controls, available-delivery filters/cards, batch workspace, active/history batch presentation, and related Batches dialogs.
- Preserve batch selection, scheduling, drag/reorder, Inertia navigation, API calls, permissions, modal behavior, and all `xl` desktop rendering.
- Do not add dependencies or change backend routes, payloads, validation, database behavior, or business rules.

## Visual direction

Use the repository `DESIGN.md` principles that fit the ERP surface: near-black ink for hierarchy, white and soft-cloud surfaces, restrained borders, an 8px spacing rhythm, pill-shaped status/primary controls, and blue only for primary interactive emphasis. Keep status colors semantic and readable: amber for scheduling attention, green for scheduled/success, red for destructive or failed states. Use comfortable 44px minimum interactive targets and avoid decorative gradients or heavy shadows.

## Responsive behavior

### Phone

- Stack the page title, module selector, and New Batch action without clipping or horizontal overflow.
- Keep the available-delivery panel full width with a full-width search field, one-column filters, a clear selection summary, and touch-friendly delivery cards.
- Stack the workspace vertically: available deliveries first, then the new/edit batch workspace; editing may collapse the delivery panel as it does today.
- Render active and history batches as readable cards with schedule, rider, stop capacity, status, and labelled actions; keep the table hidden below `xl`.
- Stack dialog summary metrics, route stops, rider selection, and footer actions with safe viewport spacing.

### Tablet

- Keep the same information order and card vocabulary as phone, adding breathing room and two-column filter/summary arrangements only where the available width is safe.
- Keep every card and control inside the viewport; no page-level horizontal scroll and no clipped header actions.
- Preserve full-width workspace sections below `xl`; use the same compact cards for active/history batches.

### Desktop

- At `xl` and above, keep the current Batches page header, two-column workspace, active/history tables, modal sizing, and action placement unchanged.
- Desktop keeps the existing table-based `BatchTable`; compact card markup is hidden at `xl` and above.

## Component decisions

- `Batches.tsx`: add compact-only layout classes and stable responsive test hooks without changing state or handlers.
- `AvailableDeliveriesPanel.tsx`: improve compact filter spacing, selection summary, and delivery card hierarchy; keep the existing data and callbacks.
- `BatchWorkspace.tsx` and `BatchStopRow.tsx`: make compact stop cards and sticky action footers fit narrow widths while retaining drag/reorder and arrow controls.
- `BatchTable.tsx`: render a compact card list below `xl` and retain the current table at `xl` and above; use the same callbacks and status/action rules.
- `BatchDetailsModal.tsx`, `BatchHistoryModal.tsx`, and `OfferBatchModal.tsx`: tune compact padding, stacked metric cards, action wrapping, and viewport-safe surfaces without changing dialog semantics.
- `Batches.test.tsx`: add responsive class/card assertions and preserve behavior coverage for selection, filters, workspace actions, history, and dialogs.

## Accessibility and interaction

- Preserve semantic headings, labels, table semantics at desktop, dialog focus behavior, Escape handling, and restore-focus behavior.
- Keep button, checkbox, select, and card action hit areas at least 44px high/wide.
- Keep keyboard and pointer interactions available; compact cards must not depend on hover.
- Keep light/dark borders, text, status badges, and focus rings distinguishable.

## Acceptance criteria

1. At 390px, 768px, and 1024px, the Batches page has no accidental page-level horizontal overflow and all primary actions remain reachable.
2. Compact filters, delivery cards, workspace stops, and batch cards follow a consistent 8px spacing rhythm and touch-friendly sizing.
3. Active and history batch cards expose the same actions and callbacks as the current desktop table.
4. Existing selection, date/window/status filtering, module compatibility, batch creation/editing, offer/review, cancel/restore, history, and details behavior remains passing.
5. At `xl` and above, the existing Batches desktop presentation remains unchanged.
6. A fresh production `public/build` is generated and included with the implementation commit.

## Verification

- Focused: `node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`
- Related logistics: `node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`
- Full frontend: `node_modules/.bin/vitest.cmd run`
- Build: `node_modules/.bin/vite.cmd build`
- Hygiene: `git diff --check`
