# Shop-Owned Logistics Production Design

## Goal

Make the existing shop-owned logistics workflow production-ready without changing third-party delivery. Preserve the current shipment, leg, assignment, proof, attempt, event, tenant, and permission foundations while adding capacity-aware scheduling, batch dispatch, reliable retries, exception handling, and custody tracking.

## Scope decisions

- Use hybrid dispatch: scheduled batches for normal work and manual assignment for urgent or exceptional deliveries.
- The customer cannot choose a delivery schedule. The system recommends an estimated date and broad morning/afternoon window; an authorised dispatcher may override it with a required reason.
- The first failed delivery automatically returns to the next delivery day's dispatch pool. The maximum attempt count is shop-configurable and defaults to two.
- Support prepaid orders only. Do not add COD handling.
- Require photo delivery proof and dispatcher approval. Do not add OTP/PIN verification.
- Show customer phone numbers only to authorised assigned riders and audit relevant access/actions.
- Do not add live rider location tracking.
- Validate delivery coverage using the shop's configurable radius.

## Approach

Incrementally extend the existing logistics module. A replacement dispatch engine would duplicate working state and increase migration risk; UI-only changes would not provide reliable scheduling, custody, concurrency, or retry guarantees.

## Core workflow

1. An order becomes ready for shop-owned delivery.
2. The system calculates an estimated delivery date and broad window from the shop calendar, cutoff, lead time, coverage, rider availability, and capacity.
3. The delivery enters the pending dispatch pool.
4. The system suggests a batch by delivery date, window, and geographic proximity.
5. A dispatcher confirms or edits the batch, stop order, and rider assignment.
6. The rider accepts or rejects the offer. Rejection requires a reason and returns the work to dispatcher attention.
7. The shop hands the item to the rider. Shop staff records a required pickup photo in the existing `handoff_proofs` table with `handoff_type = pickup`; the assigned rider confirms receipt, which performs the existing `picked_up` transition and begins rider custody. Pickup proof does not require a later dispatcher review because both handoff actors participate at the boundary. If the rider rejects the handoff because the parcel or proof is incorrect, the leg remains assigned, the rejection reason is audited, and shop staff must replace the proof before another confirmation. A customer-safe event may then indicate that the parcel left the shop.
8. The rider starts the accepted batch and marks each individual stop out for delivery when beginning that stop.
9. Successful delivery requires a photo proof and dispatcher approval before completion.
10. A failed attempt requires a reason and photo. Before the maximum attempt count, it is automatically scheduled for the next operating day and returned to the dispatch pool; the dispatcher may retain or replace the rider.
11. At the maximum attempt count, the delivery moves to `Needs resolution`. A dispatcher chooses another retry, cancellation, or a tracked return-to-shop. A genuinely lost parcel instead ends custody through a documented `loss_confirmed` incident resolution because physical return is impossible.
12. Return-to-shop is a shipment leg that ends only when the shop confirms receipt.

Starting a batch must not mark every order out for delivery. Physical custody must end with approved delivery proof or confirmed shop return receipt.

## Data model

### Delivery batches

Add `delivery_batches` with:

- `shop_owner_id` and assigned rider reference
- delivery date and `morning`/`afternoon` window
- status: `draft`, `offered`, `accepted`, `in_progress`, `completed`, or `cancelled`
- capacity and assigned-stop count
- offer, acceptance, rejection, start, completion, and cancellation timestamps
- rejection and dispatcher override reasons

Extend shipment legs with an optional batch reference, scheduled date/window, stop sequence, attempt number, out-for-delivery timestamp, and schedule override reason. Existing delivery assignments remain the rider assignment history.

Reuse `handoff_proofs` for pickup evidence. A pickup proof stores the photo path, recording shop user, timestamp, and optional note. Rider confirmation/rejection is stored as proof review metadata and an audit event; only the currently assigned rider may act. The service must retain superseded or rejected proof history rather than overwrite it. Delivery photo proof continues to require dispatcher approval.

### Delivery incidents

Add `delivery_incidents` for `damaged`, `lost`, `vehicle_problem`, `customer_dispute`, and `other`. Store the leg, reporting rider, photos, notes, `reported`/`under_review`/`resolved` status, dispatcher resolution, optional responsible party, and timestamps.

Recipient unavailable, wrong address, recipient refusal, and a temporary vehicle/delivery problem that merely prevents one attempt remain ordinary delivery attempts. `vehicle_problem` is an incident only when it involves damage, loss, safety, prolonged vehicle failure, or another condition requiring dispatcher investigation. This distinction supersedes the earlier QoL wording that combined all vehicle and delivery problems into one failed-attempt reason; existing `vehicle_or_delivery_problem` attempts remain valid historical attempts. Incident resolution records operational findings but does not directly move money; existing order and refund workflows retain financial responsibility.

An authorised dispatcher may resolve a lost-item incident as `loss_confirmed` only with a required investigation note and supporting evidence. This is the terminal custody exception when neither delivery nor physical return is possible; it records customer-safe and internal audit events and hands financial handling to the existing refund workflow. Other cancellations while the rider has custody still require a confirmed return-to-shop leg.

### Settings and addresses

Shop logistics settings include operating days, cutoff time, blackout dates, default lead time, broad delivery windows, coverage radius, maximum daily deliveries per rider, and maximum attempt count.

Rider profiles retain availability and gain work schedule, leave awareness, and daily capacity. Structured customer addresses retain delivery instructions and gain coordinates. Missing coordinates require a dispatcher override reason rather than silently bypassing coverage validation.

## Scheduling and batching

### Estimated delivery calculation

Starting from the timestamp when the order becomes ready for shipping, apply lead time, skip closed and blackout dates, move work becoming ready after cutoff to the next operating day, reject or flag addresses outside coverage, and select the first delivery date/window with rider capacity. Order placement time does not drive the logistics cutoff.

Recalculate only when relevant facts change. Once communicated, a later promised date requires a recorded reason and customer notification; it must not change silently.

### Batch suggestions

Group pending deliveries by shop, scheduled date/window, geographic proximity, and available rider capacity. Suggest stop order with a simple nearest-neighbour calculation over coordinates. This is a planning aid, not a road-aware fastest-route promise.

Dispatchers may confirm suggestions, move individual legs, reorder stops, assign or replace riders, mark urgent deliveries, or override schedule/capacity with a required reason. Support both whole-batch and individual assignment. Daily rider capacity is shared across both delivery windows. An authorised override may exceed the rider or batch capacity, but must display the resulting overload and record the reason.

Unavailable or on-leave riders cannot receive offers. An offered batch becomes operational only after rider acceptance.

## Interfaces and permissions

### Dispatcher

Extend Logistics with:

- Dispatch pool: unbatched, unassigned, rejected, and urgent deliveries
- Batches: draft, offered, accepted, active, and completed runs
- Needs attention: failed attempts, overdue deliveries, rejected offers, incidents, and returns awaiting receipt
- Riders: availability, schedule, capacity, accepted workload, and performance summary

Customer-impacting or destructive actions require confirmation and a reason.

### Rider

The assigned rider may accept/reject offers, view ordered stops and instructions, start a batch, mark an individual stop out for delivery, call the customer, submit delivery proof, report failed attempts or incidents, and confirm return handoff. Riders see only their offered or assigned work.

### Customer

Tracking shows the estimated date/window and customer-safe scheduled, assigned, out-for-delivery, failed/rescheduled, delivered, cancelled, and returned events. It must hide rider identity details, internal notes, batches, incident responsibility, and dispatcher remarks.

### Authorization

Retain tenant-scoped, permission-based backend authorization. Add only capabilities for managing schedules/batches, accepting and operating assigned batches, resolving exceptions, managing logistics settings, and viewing operational reports. UI visibility is not an authorization boundary.

## Reliability and exception handling

- Assignment, acceptance, proof approval, retry, cancellation, and return transitions use database transactions and row locking from the phase in which each transition is introduced.
- Repeated requests use idempotency keys or existing-state detection from the phase in which each mutation is introduced, so they cannot duplicate batches, assignments, attempts, proofs, notifications, or return legs.
- Stale actions return a clear conflict response without discarding visible state.
- An overdue-monitoring job flags deliveries approaching cutoff, unanswered rider offers, late starts, overdue stops, unscheduled retries, and returns awaiting receipt.
- Overdue monitoring alerts dispatchers and updates customer estimates when needed; it never automatically cancels a shipment.
- Before pickup, an order cancellation may automatically cancel its delivery. After pickup, a dispatcher decides whether to continue, cancel with return-to-shop, or apply another permitted resolution.

## Notifications

Customers receive deduplicated notifications for the initial estimate, material schedule changes, out for delivery, failed attempt and new estimate, delivery, cancellation, and customer-visible return resolution.

Dispatchers receive actionable alerts for rejected offers, overdue deliveries, failed attempts, incidents, capacity conflicts, and returns awaiting receipt. Riders receive offers, reassignments, schedule changes, and resolution instructions.

## Operational dashboard

Show due today, overdue, failed attempts, unassigned deliveries, rider workload, and delivery success rate. Do not add forecasting or advanced map analytics.

## Testing

Automated coverage must include:

- Estimates across cutoff, closed days, blackout dates, capacity, and time zones
- Radius validation and missing-coordinate override
- Batch suggestions, stop ordering, capacity, and manual edits
- Rider acceptance/rejection, reassignment, and availability restrictions
- Individual out-for-delivery transitions
- Automatic first retry and configurable maximum attempts
- Dispatcher resolution after maximum attempts
- Photo proof submission and dispatcher approval
- Pickup-proof rejection, replacement history, assigned-rider-only confirmation, and concurrent confirmation attempts
- Incident lifecycle
- Cancellation before and after pickup
- Tracked return-to-shop and receipt confirmation
- Customer-safe events and notification deduplication
- Tenant isolation, permissions, concurrent actions, and duplicate submissions
- Continued passage of the existing logistics regression suite

## Rollout

1. Scheduling foundation: settings, estimates, capacity, radius coverage, calendar, and customer display.
2. Batch dispatch: suggestions, rider acceptance, stop order, urgent work, dispatcher workspace, and concurrency/idempotency safeguards for batch and assignment mutations.
3. Delivery execution: pickup custody, individual out-for-delivery, proof, retry, maximum-attempt resolution, overdue monitoring, and safeguards for every new transition.
4. Custody and incidents: incident reporting, post-pickup cancellation, return legs, shop receipt, and safeguards for incident and return mutations.
5. Production verification: cross-flow concurrency tests, deduplicated notifications, operational metrics, audit review, and full regression verification.

Each phase must be independently usable and must preserve third-party delivery behavior.

This design supersedes earlier shop-owned rules where they conflict: a rider-reported first failure now schedules a retry rather than serving only as a cancellation request; final cancellation after pickup is a dispatcher resolution only from `delivery_attempted`, `needs_resolution`, or an open lost/damaged incident, and must create a return-to-shop leg unless custody has already been resolved; and temporary delivery problems remain attempts while investigation-worthy vehicle problems become incidents. Existing delivery-proof approval, tenant isolation, and customer-safe event rules remain in force.

## Deferred

- Third-party courier integration
- Live GPS tracking
- Exact arrival times or customer-selected schedules
- COD and rider cash reconciliation
- OTP/PIN delivery verification
- Paid express checkout
- Road-aware route optimisation
- Forecasting and advanced map analytics

## Production-ready definition

The shop-owned workflow is ready when every physical item is traceable from shop custody to approved customer delivery, confirmed return, or documented `loss_confirmed` resolution; scheduling respects capacity, coverage, and operating calendars; duplicate and concurrent actions are safe; tenant permissions are enforced; every exception has a dispatcher resolution path; customers receive accurate, safe updates; and all logistics regression tests pass.
