# Retail Failed-Delivery Refund Components Design

## Goal

Apply the repair responsibility policy to paid retail orders after maximum shop-owned delivery attempts.

## Policy

Customer-caused failures retain the paid shipping fee:

- `recipient_unavailable`
- `wrong_or_incomplete_address`
- `recipient_refused`

Delivery/operations-caused failures refund the remaining captured total, including shipping:

- `item_damaged`
- `vehicle_or_delivery_problem`

Ambiguous failures request the remaining captured total and require Finance to decide whether shipping is refundable:

- `unsafe_location`
- `other`
- missing/legacy reason codes

COD orders remain outside the automatic refund flow.

## Design

Pass the terminal delivery-attempt reason from `ShipmentLegService` to the existing `OrderRefundService::reserveFailedDeliveryRefund` method.

For customer-caused failures, the existing locked refund reservation subtracts the shipping fee from the remaining captured payment. The subtraction happens inside `reserveOrderRefund` so concurrent or prior succeeded refunds cannot over-reserve the payment.

Operations-caused and ambiguous failures keep the current full remaining reservation. Finance remains the approval gate, and the refund note states whether shipping was included, retained, or needs a Finance decision.

For ambiguous failures with a paid shipping fee, Finance cannot approve until it explicitly chooses one of two amounts:

- products only, retaining the shipping fee; or
- the full remaining amount, including shipping.

The existing Finance approval endpoint accepts the selected amount and rejects missing or arbitrary values. The Finance modal presents only those two choices. No migration or new endpoint is required.

## Verification

Feature tests cover customer-caused, operations-caused, ambiguous, COD, idempotency, prior refunds, the two-value Finance guard, and the end-to-end rider terminal-attempt path.
