# Retail Delivery Cancellation Awareness

## Goal

Keep retail orders shipped after a rider cancellation, while making order-management staff aware so they can follow up with the customer and coordinate a reassignment.

## Design

When a shop-owned retail delivery is cancelled, reuse the existing logistics cancellation event to create a high-priority notification for every user in the same shop with `access-staff-job-orders`. The notification links to the staff Job Orders page and includes the order and cancellation reason.

The staff orders API will include the most recently created cancelled logistics shipment for each retail order. Job Orders will render a read-only red `Delivery cancelled` alert with the reason. Reassignment stays in the existing Logistics > Shipments dispatcher workflow; this change adds no order-management action. The retail order remains `shipped`; no inventory, payment, or refund state changes.

## Verification

Feature tests will prove a cancelled retail shipment notifies retail-order staff and that the staff orders API exposes the cancellation state and reason. Existing logistics cancellation tests remain the regression coverage for shipment state.
