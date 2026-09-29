# Shipment Tracking Modal and Refund Eligibility Tooltip

Date: 2026-08-11

## Goal

Allow customers to inspect outbound shipments and return shipments from the My Purchases page without leaving the page, while keeping the existing standalone tracking URL available as a fallback. Improve the tracking presentation so it follows the visual hierarchy, spacing, responsive behavior, and accessible interaction patterns used elsewhere in SoleSpace.

## Approved approach

Use the existing customer tracking endpoint as a progressive enhancement:

- `Track Shipment` and `Track Return` open the same reusable tracking modal with the selected shipment ID.
- The modal requests the existing tracking route with `Accept: application/json`; no new backend endpoint or authorization rule is needed.
- The existing `/tracking/shipments/{shipment}` route and page remain available for direct URLs, bookmarks, and an error fallback.
- Shared tracking presentation is extracted so the modal and standalone page render the same shipment data and proof-of-delivery behavior.

The main alternatives were duplicating the tracking markup inside MyOrders or replacing the standalone page with a modal-only flow. Duplication would create two diverging UIs, while removing the route would make direct links brittle. Sharing the presentation while retaining the route minimizes regression risk.

## Interaction and visual design

### Tracking modal

- Use a responsive, centered modal with a darkened/backdrop overlay, rounded container on larger screens, and full-height scrollable content on small screens.
- Keep the modal header visible while the shipment details scroll; show the shipment/return label, tracking ID, current status, and an explicit close button.
- Preserve the tracking page's key information: current leg, tracking number or delivery method, estimated delivery, movement, failed-attempt details, delivery proof, and customer-visible updates.
- Improve hierarchy with a compact status summary, grouped information cards, consistent navy/neutral SoleSpace colors, clear section headings, and responsive stacking for movement details.
- Preserve proof viewing as an accessible nested dialog with Escape handling, focus return, zoom controls, loading/error states, and download behavior.
- Close on Escape and backdrop click, lock page scrolling while open, restore focus to the initiating button, and provide `aria-modal`, labelled dialog content, and live loading/error messaging.
- If loading fails, show a concise retry action and a link to open the existing full tracking page.

### Refund eligibility tooltip

- Keep the existing refund eligibility calculation and disabled-button behavior unchanged.
- Wrap an ineligible disabled `REFUND` button in an interactive tooltip trigger so the explanation remains discoverable even though a disabled button cannot receive hover or keyboard focus.
- Show `Only online-paid orders are eligible for refund requests.` for orders blocked specifically by payment method; retain the existing context-specific message for other blockers.
- Support mouse hover, keyboard focus, and tap/click so the explanation works on desktop, keyboard navigation, and touch devices.
- Connect the trigger and tooltip with `aria-describedby`; do not make hover the only way to access the explanation.

## Data flow and failure handling

1. MyOrders stores the selected shipment ID and whether the modal is open.
2. Opening the modal starts a same-origin GET request to the existing tracking route with JSON accept headers and an AbortController.
3. The modal renders loading, success, and error states without changing the order list.
4. Closing or changing the selected shipment aborts the previous request and clears transient state.
5. The existing route remains the fallback if the request fails or if a customer opens the URL directly.

The endpoint already verifies that the authenticated customer owns the shipment, so the frontend will not add or bypass authorization logic.

## Scope and likely files

- Add a reusable tracking presentation/modal component under the existing customer/logistics component conventions.
- Update `resources/js/Pages/UserSide/Orders/MyOrders.tsx` to open both tracking actions in the modal and add the refund tooltip trigger.
- Update `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx` to consume the shared presentation while retaining standalone-page navigation.
- Update or add focused tests for modal loading/success/error, both tracking actions, tooltip accessibility, and existing proof behavior.
- Do not change the tracking controller, customer ownership checks, refund backend rules, or unrelated order actions.

## Verification

- Run the focused MyOrders and ShipmentTracking frontend tests.
- Run the full frontend test command when the focused tests pass.
- Run the production frontend build.
- Use Playwright/browser verification to confirm both tracking modals, responsive scrolling, Escape/backdrop close, refund tooltip hover/focus/tap, and standalone route fallback.
- Run `git diff --check` and confirm unrelated working-tree changes remain untouched.
