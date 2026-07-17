# Failed Delivery Reassignment and Refund Design

## Goal

Make failed delivery attempts actionable and visible. Dispatchers can find and reassign retryable deliveries, batch stops show their failed-attempt history, and online-paid retail orders that reach the configured maximum attempts enter the existing return-and-refund workflow.

## Delivery attempt lifecycle

`max_delivery_attempts` remains the shop-level limit. A failed attempt below the limit moves the leg back to `pending`, schedules the next operating day, closes the active rider assignment, and preserves the attempt history. The delivery can then be assigned directly or added to another batch.

The attempt that reaches the limit moves the leg to `needs_resolution` with `resolution_type = return_required`. It cannot be reassigned for another customer-delivery attempt. For online-paid retail orders, the system creates the return-to-shop work and a single idempotent refund request.

Recording a failure runs in one locked transaction. It captures the active rider and originating batch, records the attempt, creates the return assignment when the limit is reached, then closes the original assignment as `cancelled` with `cancelled_at`. This releases rider workload without losing custody: the same rider receives an `accepted` assignment on the singleton return leg before the delivery assignment closes.

Non-retail logistics purposes keep the existing manual resolution workflow because they are not tied to an online order payment.

## Dispatcher UI

The Logistics shipments page adds a `Failed attempts` filter. It matches shipments with a failed delivery attempt and shows the latest reason, attempt count, configured maximum, schedule, and current resolution state.

Retryable failed deliveries show the existing rider selector after the previous active assignment is closed. Maxed-out deliveries show `Subject for refund` and no reassignment control.

The batch page loads the latest failed attempt and attempt counters for pool and batch stops. Each affected stop shows a visible `Failed attempt - X/Y` badge and reason. A maxed-out stop is excluded from the delivery pool.

## Refund and return integration

For a paid retail order at the maximum attempt:

1. Create one `request_approval` `OrderRefund` with the stable idempotency key `delivery-attempts-exhausted:{order_id}:{outbound_leg_id}`. The reason text is metadata and is not part of identity.
2. Set `status = requested`, `shop_owner_status = approved`, `shop_owner_approved_at = now`, `shop_owner_approved_by = null`, `finance_status = pending`, `return_status = pending_staff_pickup`, `return_source = staff`, and `staff_return_carrier = Shop-owned logistics`. Record an audit note that Shop Owner approval was bypassed for a system-confirmed exhausted-delivery workflow.
3. Set the amount to the remaining refundable PayMongo capture after subtracting succeeded refunds. Treat every nonterminal refund, including `requested`, `pending_approval`, and `processing`, as a reservation. If a different active refund exists, block creation of the failed-delivery refund and surface the collision for Finance resolution; retry creation after that request becomes terminal. Never let combined succeeded and reserved amounts exceed the captured amount. The failed-delivery amount includes shipping.
4. Create inventory-only refund lines for every order item through transactional upsert keyed by `(order_refund_id, order_item_id)`. When an existing idempotent refund is recovered, reconcile its complete line set before returning it. Line quantities and SKU references drive inspection and stock movement, but `line_amount` must not replace the full remaining captured refund amount.
5. Leave Finance approval and gateway execution pending and keep this system refund out of Finance's actionable queue until the return is received.
6. Mark the refund as a staff/shop-owned return and connect it to the existing return-to-shop custody flow.
7. The rider returns the parcel. Staff cannot confirm receipt until the linked return leg is delivered with approved handoff proof.
8. Staff must classify every returned line as `resellable` or `damaged`.
9. Apply the existing idempotent inventory disposition immediately on receipt: resellable quantities are restocked; damaged quantities are written off.
10. Once the return is `received`, Finance approves and executes the existing PayMongo refund flow. For this workflow, gateway execution requires exactly `return_status = received`; `in_transit` is insufficient.

Repeated requests, retries, or callbacks must not create another attempt, refund, return leg, or inventory movement.

## Data and service changes

Reuse `ShipmentLegService`, `AssignmentService`, `OrderRefundService`, and `RefundInventoryDispositionService`. Add no new refund tables or queues.

Add only the persistence required for correctness:

- `delivery_attempts.attempt_number` for display and limit accounting, plus `delivery_assignment_id` with a unique key on `(shipment_leg_id, attempt_type, delivery_assignment_id)`. The server derives the active assignment under the leg lock and returns its existing attempt on conflict, so retrying the same rider operation cannot consume another attempt. Any non-assignment attempt endpoint must require a stable explicit idempotency key with a unique constraint;
- nullable `delivery_attempts.delivery_batch_id` so the originating batch retains failure provenance after the leg is detached;
- a unique key on nullable `shipment_legs.return_for_leg_id` so one outbound leg has at most one return leg;
- nullable `order_items.product_variant_id`, populated for new orders and backfilled only when product, size, and color identify exactly one variant;
- a unique key on `(order_refund_id, order_item_id)` for online refund inventory lines.

Legacy order items with an unresolved variant may still be inspected, but receipt must stop with an actionable SKU-resolution error rather than restocking only aggregate product stock and leaving variant stock incorrect.

The failed-attempt service owns the shared transition: close active assignments, count unique persisted failed delivery attempts, compare them with `max_delivery_attempts`, and start return/refund handling only at the limit. The count shown in UI comes from persisted delivery attempts rather than trusting a client value. Batch queries load the latest attempt, including its originating `delivery_batch_id`, for both current stops and newly reassigned pool stops.

The return receipt service locks the refund, linked delivered return leg, and refund lines in one transaction. It rejects missing, duplicate, foreign, unresolved-SKU, or quantity-mismatched order items. It requires one valid disposition for every line, applies every line through `RefundInventoryDispositionService` without swallowing failures, and only then sets `return_status = received`. Its existing `inventory_applied_at` marker makes later Finance execution a no-op for inventory.

## Validation and authorization

- Only the owning shop's dispatcher can assign or reassign a leg.
- Attempt and reassignment endpoints require the explicit Logistics dispatcher/rider permission in addition to tenant ownership.
- A leg with an active assignment cannot receive another assignment.
- A maxed-out or terminal leg cannot be reassigned.
- Refund creation applies only to paid online retail orders owned by the same shop.
- Staff receipt confirmation requires the owning shop's Staff Job Orders permission, a completed linked return, all order lines, and valid dispositions.
- Finance approval rejects this workflow before receipt. Finance remains the only company-account actor that can approve and execute the gateway refund.
- The bypassed Shop Owner decision is auditable through its approved status, timestamp, null actor, fixed reason code, and audit note; amount-based approval policy cannot reinsert Shop Owner approval for this workflow.

## Verification

Backend tests cover assignment closure after a failed attempt, custody-preserving return assignment, retry reassignment, exact maximum-attempt enforcement, duplicate HTTP attempt protection, batch provenance, idempotent refund/return creation, prior-refund amount collisions, mandatory and atomic line inspection, variant restock, single inventory application, and receipt-only Finance gating.

Page tests cover the failed-attempt filter, single-delivery reassignment controls, max-attempt refund state, and batch stop badges. Run the full Logistics feature suite, focused refund tests, frontend Logistics tests, and the production build.
