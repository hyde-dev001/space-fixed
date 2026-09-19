# Repair Pickup Refund Components Design

## Goal

Make the automatic refund after exhausted repair-pickup attempts match who caused the failure, while preserving the existing Finance approval workflow.

## Approved policy

- Customer-caused pickup failure: refund the remaining paid repair amount and retain the paid intake pickup fee.
- Rider/operations-caused pickup failure: refund the full remaining paid balance, including the paid intake pickup fee.
- Ambiguous pickup failure: send the full remaining balance to Finance with an explicit note showing the lower amount if Finance decides to retain the pickup fee.
- Customer cancellation before dispatch: keep the existing full-refund behavior.
- Warranty/no-charge repairs: keep the existing no-refund behavior.

## Reason classification

Customer-caused:

- `customer_unavailable`
- `customer_requested_reschedule`
- `customer_refused_pickup`
- `item_not_ready`
- `wrong_address_or_pin`

Operations-caused:

- `vehicle_or_rider_problem`

Finance decision:

- `unsafe_or_inaccessible_location`
- `other`

## Design

The existing payment records already store repair and delivery components separately:

- POS payments store `metadata.service_amount`, `metadata.delivery_amount`, and the intake leg/initial phase.
- Online payments store `RepairPaymentSession.service_amount` and `RepairPaymentSession.delivery_amount`.
- Older locked repair records retain `RepairRequest.intake_delivery_fee`.

Add one calculation to `RepairPosRefundService` that returns the recorded paid intake delivery amount, preferring current payment-component records and using the locked intake fee only as a legacy fallback. Do not add a migration.

Online repair payments may not have a POS source row until a refund is requested. Reuse the existing gateway-reference backfill pattern to create that accounting source once, attach the original PayMongo reference, and store it as `latest_pos_transaction_id`. This is an accounting bridge only; it does not collect another payment.

When the final pickup attempt fails, pass the actual failure reason into the existing automatic-refund method:

- Customer-caused: `requested_amount = remaining refundable balance - paid intake fee`; request type is `partial`.
- Operations-caused: request the full remaining balance; request type is `full`.
- Ambiguous: request the full remaining balance so Finance can approve either outcome; the note must show the paid pickup fee and the reduced approval amount.

All requests remain `requested` with `finance_status = pending`. Existing Finance approval already accepts a lower approved amount.

## Safety and edge cases

- Never request a negative amount.
- Do not create a refund if no refundable repair amount remains.
- Preserve idempotency: replaying the failed-attempt request must not create another refund.
- Backfill at most one refund source for an online payment.
- Never create a monetary refund for warranty/no-charge work.
- Cap the detected pickup component to the recorded paid amount.
- Keep the existing full refund for customer cancellation before dispatch.

## Verification

Feature tests cover customer-caused, operations-caused, ambiguous, idempotent replay, warranty, and pre-dispatch cancellation behavior.
