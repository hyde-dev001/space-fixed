# Repair Payment Activation Gate

## Goal

Prevent customers from paying for a repair before the repairer and customer agree on the work and required services.

## Approved flow

1. Customer submits a repair request.
2. Repairer and customer discuss feasibility and service changes.
3. Repairer accepts the request.
4. Repairer explicitly selects **Activate Payment**.
5. The customer sees **PAY NOW**.
6. Successful payment starts the selected shop-pickup logistics flow.

## Rules

- New `shop_pickup` and `customer_delivery` repair requests start with payment disabled.
- The customer payment action remains unavailable until `payment_enabled` is set by the existing activation endpoint.
- The activation endpoint rejects requests that have not reached a repairer-accepted status.
- Walk-in/POS and no-charge warranty behavior remains unchanged.
- Older accepted requests with payment disabled expose **Activate Payment** in the repair detail modal.

## Verification

- A new shop-pickup request persists `payment_enabled = false`.
- Payment activation is rejected before repairer acceptance.
- An accepted shop-pickup request does not show **PAY NOW** before activation.
- After activation, the accepted request shows **PAY NOW**.
- No shop-pickup shipment exists before successful payment.
- Successful initial payment creates the shop-pickup shipment.
- Existing repair logistics and payment tests remain green.
