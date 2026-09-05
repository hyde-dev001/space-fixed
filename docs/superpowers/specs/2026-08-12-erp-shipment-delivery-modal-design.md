# ERP Shipment Delivery Details Modal

Date: 2026-08-12

## Goal

Replace the inline shipment-details expansion on the ERP Logistics Shipments page with a polished, accessible modal that keeps the complete delivery workflow available without changing backend behavior.

## Approved approach

Wrap the existing shipment details markup in the repository's shared `Modal` component and keep the current `Shipments.tsx` state, permissions, callbacks, API calls, and server-provided shipment data in place.

The current card remains a compact operational summary. Its `Open delivery` button selects a shipment and opens the modal. The modal contains the full existing delivery details and actions, including order summary, delivery legs, rider assignment, scheduling, status transitions, delivery proof, failed-delivery recovery, issue reporting, and incident resolution.

This approach is intentionally surgical. Extracting the large action-heavy details block into a new component would improve file boundaries but would also require passing many state values and callbacks and would increase regression risk. A separate customer tracking modal is read-only and cannot support the ERP workflow.

## Interaction and visual design

### Shipment card

- Keep each card's summary information, badges, recipient, address, schedule, rider, and operational indicators visible in the list.
- Change `Open delivery` from an expand/collapse control into a modal trigger.
- Keep the label `Open delivery` for the closed state; do not render `Close delivery` on the card because the close action belongs inside the modal.
- Use `aria-haspopup="dialog"` and a shipment-specific accessible name when more than one card is shown.
- Remove the inline details region so opening a shipment does not push the rest of the list down.

### Delivery details modal

- Use the existing shared `Modal` backdrop/body-scroll behavior and a wide responsive surface suitable for the multi-column delivery workflow.
- On desktop, present a clear header with the shipment number, source/order context, status, and a visible close action; use a scrollable content area for long delivery details.
- On small screens, use a near-full-height surface with stacked content and internal vertical scrolling so controls remain reachable without horizontal overflow.
- Preserve the existing neutral ERP palette, blue primary actions, semantic warning/error/success states, rounded cards, and dark-mode variants. Do not introduce a new dependency or unrelated design system.
- Keep all existing action controls and their current enabled/disabled rules unchanged. Styling may improve hierarchy, spacing, grouping, and focus states, but action semantics and API endpoints remain the same.

### Accessibility

- Render one `role="dialog"` with `aria-modal="true"` and a unique `aria-labelledby` heading for the selected shipment.
- Close through the explicit close button, backdrop click, and Escape.
- Ensure the initiating `Open delivery` button regains focus after close.
- Keep keyboard focus inside the dialog while it is open and provide visible focus rings on interactive controls.
- Preserve existing labels and live/error regions for assignment, proof, incident, and action feedback.

## Data flow and state

1. `Shipments` stores the selected shipment ID (or `null` when closed).
2. Clicking a card's trigger sets the selected ID; the shipment object is read from the already-rendered `shipments.data` collection.
3. The modal renders the selected shipment's existing legs and receives the same closure-scoped state and callbacks currently used by the inline details block.
4. Closing the modal sets the selected ID to `null` and restores focus to the trigger.
5. Existing `axios`, `router.reload`, SweetAlert confirmation, permission gating, owner mode restrictions, and server-side filtering remain unchanged.

No backend route, controller, model, migration, authorization rule, or database change is required.

## Error handling and regression boundaries

- Existing action errors remain visible inside the selected shipment modal and continue to use the current toast/error behavior.
- Closing the modal must not cancel or alter an in-flight action; the existing request/reload behavior remains authoritative.
- Modal state must not change pagination, filters, search state, or shipment data.
- Only the selected shipment's details are rendered; no duplicate details panel should remain in the card list.
- Unrelated working-tree changes must remain untouched.

## Scope and likely files

- Modify `resources/js/Pages/ERP/Logistics/Shipments.tsx` for selected-shipment modal state, trigger semantics, modal layout, and details placement.
- Modify `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx` for modal open/close, accessibility, focus, and regression coverage while retaining existing logistics action tests.
- Create `docs/superpowers/specs/2026-08-12-erp-shipment-delivery-modal-design.md` and the corresponding implementation plan.
- Do not modify logistics backend code, shared modal behavior, unrelated ERP pages, or user-owned working-tree files.

## Verification

- Add and run focused shipment tests, including the modal trigger, dialog content, close behavior, Escape/backdrop handling, and focus restoration.
- Run the complete frontend test command after focused tests pass.
- Run the production frontend build.
- Run `git diff --check` and inspect the final diff for unrelated changes.
- If a local app server is available, use Playwright to verify desktop and narrow viewport modal behavior and confirm no horizontal overflow.
