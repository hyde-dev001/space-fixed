# Retail Refund Job Order Visibility Design

## Goal

Make retail Job Orders and Finance show the same shipping-excluded payout for a normal returned-product refund, while giving staff the evidence and logistics context needed to receive a return safely.

## Confirmed causes

- The staff order payload includes refund status and tracking fields, but omits `evidence_media`, return-leg proof, and shipment-leg state.
- After return receipt is confirmed, the table refreshes but the open detail modal retains the old `viewOrder`, leaving its action visible.
- Job Order labels a completed normal refund as partial by comparing its shipping-excluded payout against the shipping-inclusive order grand total.
- Finance exposes both the full refund amount and its shipping-excluded amount; the detail panel shows the former while the execute confirmation shows the actual payout.

## Design

### One canonical payout amount

For normal product returns, the payout excludes the original shipping charge. The server will expose this as the refund's eligible payout amount; the Job Order status calculation and Finance detail panel will use it. Shipping remains visible as an original order charge, not part of the payout. The existing delivery-attempt-exhausted exception remains the only path that can include shipping when its finance decision permits it.

### Job Order details

The existing modal gets two compact, conditional sections:

- **Refund evidence**: thumbnails of customer-submitted refund evidence.
- **Return logistics**: shipment ID, return leg status, carrier/tracking, and proof thumbnails from the completed return leg.

Nothing is shown when no return shipment or evidence exists. Proof links remain protected by existing logistics authorization rather than exposing storage paths directly.

### Safe return receipt completion

After a successful `Confirm Return Received`, refresh the order data and close the stale modal. The newly fetched list reflects the next state; the obsolete action cannot be clicked again. The server-side state validation remains the final replay protection.

### Accurate status language

- `Refunded`: completed payout covers the eligible refund amount.
- `Partially Refunded`: only some approved items/quantity have been paid.
- Existing pending, rejected, return-in-transit, and ready-for-finance labels remain unchanged.

### Finance amount consistency

The Finance refund detail and Execute Payout confirmation both render the same eligible payout amount. Shipping is separately identified as excluded for a normal product return, avoiding any mismatch between review and execution.

## Scope and tests

- Extend the staff order response/type with refund evidence, payout metadata, and an authorized return-logistics summary.
- Render the new Job Order detail sections and refresh/close after return receipt.
- Correct retail status classification and Finance review amount rendering.
- Add focused feature tests for the staff payload and payout rule plus UI tests for status, proof/logistics rendering, and finance amount display.

No separate returns workspace, new storage scheme, or new logistics workflow is required.
