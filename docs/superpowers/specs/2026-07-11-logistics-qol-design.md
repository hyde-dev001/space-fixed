# Logistics QoL Design

## Goal

Improve the working ERP logistics pages without redesigning the module: riders can quickly find active work and report a cancellation request; dispatchers can resolve those requests with clear confirmations and feedback.

## Scope

- `/erp/logistics/deliveries` (rider mode): add `Status` and `Today / This week` filters. The time window filters the rider's `assigned_at` timestamp in the shop timezone; a week runs Monday through Sunday.
- `/erp/logistics/shipments` (dispatcher mode): retain the existing filters and surface legs requiring attention.
- Add a rider **Report issue / Request cancellation** action with a required reason: `recipient_unavailable`, `wrong_or_incomplete_address`, `recipient_refused`, `vehicle_or_delivery_problem`, or `other`; a note is optional.
- A rider report uses the existing delivery-attempt record and moves the leg to `delivery_attempted`. It is a request, not a final cancellation. Rider reporting is allowed only from `assigned`, `picked_up`, `in_transit`, or `delivery_attempted`.
- Dispatchers can finalise cancellation only for a `delivery_attempted` leg. The leg becomes `cancelled`; assigned, picked-up, in-transit, awaiting-proof-approval, delivered, or already-cancelled legs cannot be cancelled.
- Final cancellation records a customer-visible tracking event with a plain-language cancellation reason. The customer never sees the rider's internal note.
- Use the project’s existing SweetAlert dependency for confirmation before cancellation, reassignment, and delivery confirmation. Show success/error toasts after actions; do not show alerts for filters or routine reloads.
- Improve hierarchy only: status pills, a visible **Needs attention** badge, grouped expanded-leg details, clearer action hierarchy, and a destructive-action style.

## Non-goals

- No new logistics dashboard, Kanban board, drag-and-drop, search bar, or dependency.
- No new cancellation-request table or enum status.
- No separate rider page component; continue using the existing role-aware page.

## Roles and flow

1. A rider sees only their assigned deliveries and filters by status or time window.
2. For an eligible leg, the rider submits an issue/cancellation request with a reason. For a customer who does not answer when the rider arrives, the rider selects `recipient_unavailable` from `in_transit`. The existing attempt audit trail stores the reason, note, actor, and time.
3. Dispatcher mode visibly marks a leg in `delivery_attempted` as **Needs attention** and exposes the recorded reason/note.
4. The dispatcher either leaves it in `delivery_attempted` for another attempt through the existing rider flow or confirms final cancellation. Only the dispatcher action performs the `cancelled` transition.

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
- Dispatcher cancellation is permission-protected and validates that the leg is in `delivery_attempted`.
- The cancellation service records an internal event and a customer-visible reason event. It synchronises shipment status as follows: all-cancelled legs make the shipment `cancelled`; all-terminal legs with at least one delivered leg make it `completed`; all other combinations remain `active`.
- Errors are returned by the API and shown to the user as toasts without clearing the visible page data.

## Tests

- Rider filters return only the requested status and time range.
- A rider cannot report an issue on another rider’s leg.
- An eligible rider report creates the existing failure-attempt audit record and sets `delivery_attempted`.
- Only an authorised dispatcher can cancel a `delivery_attempted` leg; assigned, picked-up, in-transit, awaiting-proof-approval, delivered, and cancelled legs are rejected.
- Final cancellation creates the correct customer-visible reason event and synchronises all-cancelled, mixed terminal, and active shipments.
- Existing assignment, proof submission, proof approval, and delivery transitions remain covered.
