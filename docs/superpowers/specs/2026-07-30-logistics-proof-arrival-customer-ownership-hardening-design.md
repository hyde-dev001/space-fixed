# Logistics Proof, Arrival, and Customer Ownership Hardening

## Goal

Close the highest-risk logistics trust-boundary gaps before adding more Phase 3 features:

1. Only the currently assigned rider may submit or review rider handoff proof for a leg.
2. Successful pickup and delivery proof require an arrival recorded for the current assignment.
3. Only the customer may choose re-delivery or shop pickup after repaired shoes return to the shop.

## Authorization design

The existing shipment controller remains the API boundary. A user-authenticated proof submission is allowed only when either:

- the user has the leg's current active rider assignment; or
- the user has the explicit back-office `assign-logistics-deliveries` or `approve-proof-of-delivery` capability.

The general `record-logistics-proof` capability alone is not a back-office bypass because riders also hold it. Shop owners retain their existing operational proof access. For rider submissions, the shared proof service rechecks the active assignment after locking the leg and before recording proof, so reassignment cannot race a controller authorization check.

Pickup-proof confirmation and rejection must bind all records to the same workflow: the proof must belong to the route leg, use the `pickup` handoff type, and be `pending` before its review status changes. Replaying an already approved confirmation on an already picked-up leg remains idempotent. Rejected proofs cannot be approved later, and already reviewed proofs cannot be rejected again. This prevents independently route-bound `{leg}` and `{proof}` records from being combined.

## Arrival design

Arrival is a server-side prerequisite, not only a UI condition.

- Pickup confirmation requires a `pickup_arrived` event linked to the current active assignment.
- Rider delivery-proof submission requires a `dropoff_arrived` event linked to the current active assignment.
- Shop-owner or authorized staff operational proof submission remains available without a rider arrival because it is an explicit back-office action.
- Location exceptions already recorded by `ArrivalService` count as arrivals because the configured policy allows continuation with a required reason.
- Reassignment invalidates the previous rider's arrival automatically because arrival lookup is assignment-scoped.

The checks reuse `ArrivalService::eventForAssignment`; no new table or abstraction is needed. The service performs the assignment and arrival checks inside the same transaction after locking the leg. This prevents a former rider from passing a controller check immediately before reassignment and then mutating the old leg.

## Customer ownership design

The customer remains the only actor allowed to choose `schedule_redelivery` or `shop_pickup` in the returned-repair recovery state.

- Keep the customer endpoint and its ownership, date, and delivery-window validation.
- Remove the state-changing recovery actions from shop-owner and repairer APIs and UIs.
- Staff and repairers may still see the recovery state and tell the customer to arrange it in My Repairs, but cannot choose on the customer's behalf.
- Existing recovery selections and paid re-delivery sessions remain valid; this change only prevents new staff-originated choices.

This supersedes the earlier assisted-fallback rule in `2026-07-30-customer-repair-return-recovery-design.md`.

## Error behavior

Rejected API actions return validation or authorization errors without storing files or mutating delivery, proof, payment, or recovery state. Existing idempotency behavior remains unchanged.

## Test design

Regression tests must first demonstrate:

- An unassigned same-shop rider cannot submit proof for another rider's leg.
- Pickup-proof rejection cannot mutate a proof from another leg or shop.
- Pickup confirmation fails without the current assignment's pickup arrival.
- Rider delivery-proof submission fails without the current assignment's drop-off arrival.
- A previous assignment's arrival does not unlock a reassigned delivery.
- Shop owner and repairer recovery endpoints no longer choose the customer's recovery option.
- The customer can still select either valid recovery option.

Run focused logistics and repair recovery tests, then the broader logistics suite and frontend tests affected by removed staff actions.
