# Logistics QoL Design

## Goal

Improve the working ERP logistics pages without redesigning the module: riders can quickly find active work and report a cancellation request; dispatchers can resolve those requests with clear confirmations and feedback.

## Scope

- `/erp/logistics/deliveries` (rider mode): add `Status` and `Today / This week` filters.
- `/erp/logistics/shipments` (dispatcher mode): retain the existing filters and surface legs requiring attention.
- Add a rider **Report issue / Request cancellation** action with a required reason: `recipient_unavailable`, `wrong_or_incomplete_address`, `recipient_refused`, `vehicle_or_delivery_problem`, or `other`; a note is optional.
- A rider report uses the existing delivery-attempt record and moves the leg to `delivery_attempted`. It is a request, not a final cancellation.
- Dispatchers can finalise cancellation for a reported leg. The leg becomes `cancelled`; delivered or already-cancelled legs cannot be cancelled.
- Use the project’s existing SweetAlert dependency for confirmation before cancellation, reassignment, and delivery confirmation. Show success/error toasts after actions; do not show alerts for filters or routine reloads.
- Improve hierarchy only: status pills, a visible **Needs attention** badge, grouped expanded-leg details, clearer action hierarchy, and a destructive-action style.

## Non-goals

- No new logistics dashboard, Kanban board, drag-and-drop, search bar, or dependency.
- No new cancellation-request table or enum status.
- No separate rider page component; continue using the existing role-aware page.

## Roles and flow

1. A rider sees only their assigned deliveries and filters by status or time window.
2. For an eligible leg, the rider submits an issue/cancellation request with a reason. The existing attempt audit trail stores the reason, note, actor, and time.
3. Dispatcher mode visibly marks a leg in `delivery_attempted` as **Needs attention** and exposes the recorded reason/note.
4. The dispatcher either retains it for another delivery attempt or confirms final cancellation. Only the dispatcher action performs the `cancelled` transition.

## UI

### Rider

- Keep the list/table and add a compact filter bar.
- Each row prioritises delivery type, status, recipient/address summary, and the next operational action.
- Expanded details show proof upload, the issue/cancellation action, and the latest recorded failure reason when present.

### Dispatcher

- Keep the current status and purpose filters.
- Keep the expanded delivery view; group assignment, rider, proof, failure context, and actions in each leg card.
- Highlight legs needing attention and use an outlined destructive treatment for cancellation.

## Backend rules

- The existing ownership check remains the boundary for rider updates: a rider can report an issue only on their own assigned leg.
- Dispatcher cancellation is permission-protected and validates that the leg is not terminal.
- The cancellation service records an internal delivery event and synchronises shipment status consistently with the existing leg-status logic.
- Errors are returned by the API and shown to the user as toasts without clearing the visible page data.

## Tests

- Rider filters return only the requested status and time range.
- A rider cannot report an issue on another rider’s leg.
- An eligible rider report creates the existing failure-attempt audit record and sets `delivery_attempted`.
- Only an authorised dispatcher can cancel; delivered and cancelled legs are rejected.
- Existing assignment, proof submission, proof approval, and delivery transitions remain covered.
