# Repair Job Order Performance and Unified Delivery Revenue Design

## Goal

Keep the staff repair job-order page responsive while it refreshes logistics
status, and account consistently for paid shop-owned retail shipping, repair
pickup, and repair return fees as delivery revenue.

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
- The retail staff page excludes shipping fees from its net-revenue card.
- The shop-owner dashboard combines retail and repair revenue, but currently
  applies the product/service VAT treatment to delivery fees as well.
- The automatically generated retail invoice does not include the shipping fee
  collected during checkout.

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

### Unified delivery revenue

- Use one delivery-revenue category composed of paid shop-owned retail shipping,
  repair intake pickup, and repair return delivery fees.
- Count a retail shipping fee only when the order is paid and its selected
  carrier is `Shop-owned logistics`.
- Count a repair delivery fee only after its corresponding logistics payment
  lock is present. This avoids treating an unpaid quote as revenue.
- Never recognize a third-party courier fee as shop delivery revenue.
- Show delivery revenue separately in invoice metadata and line items, while
  including it once in the applicable module's net-revenue total and in the
  combined shop-owner total.
- Update the automatically generated retail invoice to include its collected
  shipping fee in the invoice total and as a separate shipping line. Carrier
  selection may happen later, so the invoice records the customer charge while
  revenue classification remains dependent on the selected carrier.
- Add paid intake and return delivery fees to the picked-up repair invoice total.
- Store service and delivery amounts separately in invoice metadata.
- Create separate invoice lines for shop-owned intake pickup and return delivery.
- Store the invoice metadata keys `service_amount`, `intake_delivery_fee`,
  `return_delivery_fee`, `shipping_fee`, and `grand_total`.
- Calculate the repair page revenue as:
  `realized service revenue excluding VAT + paid locked intake fee + paid locked return fee`.
- Calculate the retail page revenue as:
  `realized product revenue excluding VAT + paid shop-owned retail shipping fee`.
- Calculate the combined shop-owner revenue as:
  `retail product revenue excluding VAT + repair service revenue excluding VAT + all paid shop-owned delivery fees`.
- Delivery fees must not enter the product/service VAT ratio and must be added
  only once.
  This preserves the application's current treatment of delivery fees while
  keeping product and service revenue net of VAT.
- Preserve the existing refund/reconciliation behavior; compensated fees whose
  payment lock is cleared and refunded retail charges are excluded.

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
  increase invoice total, while unpaid repair quotes do not. Retail invoice
  tests prove the collected shipping charge is represented once.
- Revenue tests prove the retail, repair, and combined shop-owner totals include
  only paid shop-owned delivery fees, including partial-payment, refund, and
  compensated-fee cases.

## Out of Scope

- Changing delivery tax policy.
- Reworking third-party courier fees into shop revenue.
- Adding queues, WebSockets, or new dependencies.
