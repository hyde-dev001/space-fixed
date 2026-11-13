# Repair Return Live Tracking Design

## Goal

Allow a customer to see the latest location of a shop-owned rider while a repaired item is being returned through a `repair_return` shipment.

## Existing flow

- `My Repairs` loads each repair shipment and links to `/tracking/shipments/{id}`.
- `CustomerTrackingService` serializes the customer-safe shipment payload.
- `RiderLocationService` validates and stores rider GPS updates and decides which legs are customer-trackable.
- `ShipmentTrackingPanel` polls the customer tracking endpoint every five seconds and renders the existing `LiveTrackingMap`.

## Design

Reuse the existing pipeline. Add `repair_return` to the shared customer-trackable-leg rules with these phases:

1. Before handoff: an assigned or scheduled internal rider is at the shop origin.
2. In transit: after `picked_up_at`, the rider is travelling to the customer destination.
3. Handoff pending: the existing tracking page can continue showing the last known position while delivery proof is being verified.

The tracking target snapshot must use the shop origin before handoff and the customer destination after handoff. The customer payload remains privacy-safe: no rider phone or identity is exposed, only location, route, status, and timestamps. Third-party courier legs remain excluded.

The UI will reuse the existing map and polling behavior, adding only the repair-return phase predicates and customer-facing labels. No new endpoint, map component, or database table is needed.

## Error handling and safety

- The global `LOGISTICS_LIVE_TRACKING_ENABLED` feature flag remains the gate.
- Existing assignment, active rider, tenant, stale-location, and rate-limit checks remain authoritative.
- If no current location exists, the customer sees the existing waiting message.
- If the location is stale or polling fails, the existing stale/retry states remain visible.

## Verification

- Add a backend regression test proving an internal repair-return rider location is accepted and exposed to the owning customer.
- Add a frontend regression test proving a repair-return payload renders the live return map/polling state.
- Run focused Laravel and Vitest tests, then `git diff --check` and the frontend build.
