# Failed-Delivery Return Auto-Refund Design

## Goal

When a rider returns an undelivered retail order to the shop and the shop approves the return handoff proof, complete the return-receipt stage automatically so Finance can process the refund. This workflow is not a customer damage return and must not require a second Staff inspection confirmation.

## Flow

1. The rider and shop complete the existing two-party return-to-shop handoff.
2. In the same transaction that marks the return leg delivered, locate the active `delivery_attempts_exhausted` refund for the shipment's order.
3. Mark every refund line `resellable` and apply the existing inventory disposition once.
4. Mark the refund return as `received` and record the confirmation time. The approved handoff proof remains the authoritative shop-approver record; `return_confirmed_by_staff_id` stays null because no Staff confirmation occurs.
5. Repeating the logistics confirmation must remain idempotent: no duplicate stock movement and no repeated refund transition.

## Compatibility

Already-delivered return legs whose refunds are still `pending_staff_pickup` must be reconciled through the same automatic completion logic when the receipt endpoint is retried. New and existing records use the same service path.

## UI

The Staff order screen must not offer a manual inspection/confirmation action for `delivery_attempts_exhausted` refunds. It displays the resulting received/finance status from the backend.

## Failure Handling

If the shipment does not map to the matching failed-delivery refund, logistics receipt still completes and no unrelated refund is changed. Inventory and refund updates run transactionally with return receipt confirmation.

## Verification

- A focused feature test proves approved return receipt marks the refund received, marks its lines resellable, and applies inventory once.
- The test repeats receipt confirmation and proves stock is not incremented twice.
- The test covers retrying an already-delivered legacy return whose refund is still pending.
- Receipt confirmation still completes logistics when no matching refund exists.
- Existing return-to-shop and failed-delivery refund tests remain green.
