# Repair Job Order Performance and Delivery Revenue Design

## Goal

Keep the staff repair job-order page responsive while it refreshes logistics
status, and account for paid shop-owned pickup and return fees as delivery
revenue separate from repair service revenue.

## Confirmed Problems

- The repair page polls every 10 seconds, allows refresh requests to overlap,
  and replaces the table with its initial loading spinner during every poll.
- The repair list resolves shipment, leg, proof, and event state separately for
  every repair and delivery phase, creating query growth proportional to the
  number of repairs.
- Opening repair support chat uses a full browser navigation.
- Payment records preserve `service_amount` and `delivery_amount`, but the
  picked-up repair invoice and repair revenue card currently omit paid
  shop-owned delivery fees.

## Approved Design

### Refresh behavior

- Keep the first-load spinner.
- Run subsequent automatic refreshes in the background without hiding the
  current table or modal.
- Permit only one repair-list request at a time.
- Skip automatic refresh while the browser tab is hidden.
- Use Inertia navigation when opening repair support chat.

### Logistics loading

- Add a repair-to-logistics-shipments relationship scoped by
  `source_type = repair_request`.
- Eager-load repair pickup and return shipments, legs, proofs, and events with
  the repair list.
- Make the existing handoff formatter reuse the eager-loaded relationship,
  while retaining its direct-query fallback for single-repair callers.

### Delivery revenue

- Count a delivery fee only after its corresponding logistics payment lock is
  present. This avoids treating an unpaid quote as revenue.
- Add paid intake and return delivery fees to the picked-up repair invoice total.
- Store service and delivery amounts separately in invoice metadata.
- Create separate invoice lines for shop-owned intake pickup and return delivery.
- Store the invoice metadata keys `service_amount`, `intake_delivery_fee`,
  `return_delivery_fee`, `shipping_fee`, and `grand_total`.
- Calculate the repair page revenue as:
  `realized service revenue excluding VAT + paid locked intake fee + paid locked return fee`.
  Delivery fees must not enter the service VAT ratio and must be added only once.
  This preserves the application's current treatment of delivery fees while
  keeping service revenue net of VAT.
- Preserve the existing refund/reconciliation behavior; compensated fees whose
  payment lock is cleared are excluded.

## Error Handling

- A failed background refresh keeps the last successfully loaded table.
- A failed initial load continues to show the existing page error.
- Existing shipment query fallback remains available when a repair is loaded
  outside the job-order list.

## Verification

- A query-count regression proves adding more shop-owned repairs does not add
  per-repair handoff queries.
- Frontend integration tests prove polling is background-only, skips hidden
  tabs, and uses Inertia chat navigation.
- Invoice tests prove paid shop-owned fees create separate delivery lines and
  increase invoice total, while unpaid quotes do not.
- Revenue tests prove the repair revenue card includes only paid/locked delivery
  fees, including partial-payment and compensated-fee cases.

## Out of Scope

- Changing delivery tax policy.
- Reworking third-party courier fees into shop revenue.
- Adding queues, WebSockets, or new dependencies.
