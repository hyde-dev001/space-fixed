# Failed Delivery Reassignment and Refund Design

## Goal

Make failed delivery attempts actionable and visible. Dispatchers can find and reassign retryable deliveries, batch stops show their failed-attempt history, and online-paid retail orders that reach the configured maximum attempts enter the existing return-and-refund workflow.

## Delivery attempt lifecycle

`max_delivery_attempts` remains the shop-level limit. A failed attempt below the limit moves the leg back to `pending`, schedules the next operating day, closes the active rider assignment, and preserves the attempt history. The delivery can then be assigned directly or added to another batch.

The attempt that reaches the limit moves the leg to `needs_resolution` with `resolution_type = return_required`. It cannot be reassigned for another customer-delivery attempt. For online-paid retail orders, the system creates the return-to-shop work and a single idempotent refund request.

Non-retail logistics purposes keep the existing manual resolution workflow because they are not tied to an online order payment.

## Dispatcher UI

The Logistics shipments page adds a `Failed attempts` filter. It matches shipments with a failed delivery attempt and shows the latest reason, attempt count, configured maximum, schedule, and current resolution state.

Retryable failed deliveries show the existing rider selector after the previous active assignment is closed. Maxed-out deliveries show `Subject for refund` and no reassignment control.

The batch page loads the latest failed attempt and attempt counters for pool and batch stops. Each affected stop shows a visible `Failed attempt · X/Y` badge and reason. A maxed-out stop is excluded from the delivery pool.

## Refund and return integration

For a paid retail order at the maximum attempt:

1. Create one `request_approval` `OrderRefund`, keyed idempotently by the order and failed-delivery reason.
2. Refund the full captured order amount, including shipping fee.
3. Bypass Shop Owner approval because this is a system-confirmed delivery failure, not a customer dispute.
4. Leave Finance approval and gateway execution pending.
5. Mark the refund as a staff/shop-owned return and connect it to the existing return-to-shop custody flow.
6. The rider returns the parcel. Staff cannot confirm receipt until the return delivery is complete.
7. Staff must classify every returned line as `resellable` or `damaged`.
8. Apply the existing idempotent inventory disposition immediately on receipt: resellable quantities are restocked; damaged quantities are written off.
9. Once the return is `received`, Finance approves and executes the existing PayMongo refund flow.

Repeated requests, retries, or callbacks must not create another refund, return leg, or inventory movement.

## Data and service changes

Reuse `ShipmentLegService`, `AssignmentService`, `OrderRefundService`, and `RefundInventoryDispositionService`. Add no new refund tables or queues.

The failed-attempt service owns the shared transition: close active assignments, count actual failed delivery attempts, compare them with `max_delivery_attempts`, and start return/refund handling only at the limit. The count shown in UI comes from persisted delivery attempts rather than trusting a client value.

The return receipt service requires a disposition for every refundable order item before setting `return_status = received`, then applies each line through `RefundInventoryDispositionService`. Its existing idempotency marker prevents the later Finance execution path from restocking twice.

## Validation and authorization

- Only the owning shop's dispatcher can assign or reassign a leg.
- A leg with an active assignment cannot receive another assignment.
- A maxed-out or terminal leg cannot be reassigned.
- Refund creation applies only to paid online retail orders owned by the same shop.
- Staff receipt confirmation requires all order lines and valid dispositions.
- Finance remains the only company-account actor that can approve and execute the gateway refund.

## Verification

Backend tests cover assignment closure after a failed attempt, retry reassignment, exact maximum-attempt enforcement, idempotent refund/return creation, mandatory line inspection, single inventory application, and Finance gating.

Page tests cover the failed-attempt filter, single-delivery reassignment controls, max-attempt refund state, and batch stop badges. Run the full Logistics feature suite, focused refund tests, frontend Logistics tests, and the production build.
