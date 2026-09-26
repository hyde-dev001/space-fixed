# Staff Job Order Delivery Status Sync Design

## Problem

Shop-owned logistics correctly completes a delivered shipment and stores the
retail order status as `completed`. The customer order page treats
`completed` as a delivered terminal state, but ERP Staff Job Orders only
counts and filters the literal status `delivered`. An ERP tab that was already
open also keeps its old `shipped` state because it fetches orders only when the
component mounts.

This leaves a delivered order in the Shipped tab until refresh, then hides the
`completed` order from both Shipped and Delivered.

## Approved Behavior

- ERP Staff Job Orders treats API status `completed` as the Delivered UI state.
- Existing `completed` orders appear in the Delivered tab without a database
  migration or backfill.
- Returning focus to an already-open ERP tab refreshes its orders so a
  shop-owned delivery no longer remains visually stuck at Shipped.
- The Delivered state does not show the `Activate Receive` action.
- A shop-owned order never shows `Activate Receive`, even while a stale record
  is still displayed as Shipped. The action remains available for shipped
  third-party deliveries.

## Design

Keep `completed` as the persisted backend status because the logistics service
already uses and tests that terminal state. Normalize only the Staff Job Orders
read model while mapping API orders:

```ts
status: order.status === "completed" ? "delivered" : order.status
```

Reuse the existing `refreshOrders` request and attach it to the native window
`focus` event. Remove the listener when the component unmounts. Do not add
polling, WebSockets, dependencies, or a database migration.

Reuse the mapped `carrierCompany` field and the existing
`SHOP_OWNED_LOGISTICS` constant in the modal action guard. Do not change the
third-party receive flow or add a separate logistics flag.

## Data Flow

1. Logistics marks the final shipment leg `delivered`.
2. `ShipmentLegService` completes the shipment and stores the shop-owned retail
   order as `completed`.
3. ERP Staff Job Orders fetches `/api/staff/orders`.
4. Its API mapper converts `completed` to the existing `delivered` display
   state.
5. Existing Delivered filters, counts, and badge styling work without further
   changes. The modal action guard also excludes shop-owned logistics.
6. When the user returns to the ERP browser tab, the focus listener invokes
   the existing refresh request and replaces stale order state.

## Error Handling

The focus refresh uses the page's existing `refreshOrders` error handling. A
failed refresh leaves the current list intact and logs the existing fetch
error; it does not clear valid order data.

## Testing

- Add a focused frontend regression test proving `completed` is normalized to
  `delivered`.
- Add a component-level regression check proving a window focus refresh
  replaces an initially shipped order with the completed/delivered response.
- Add a component-level regression proving `Activate Receive` is hidden for a
  shipped shop-owned order while remaining available to shipped third-party
  deliveries.
- Run the existing Staff Job Orders frontend tests and the logistics shipment
  service tests.
- Run the production frontend build before preparing the Hostinger deployment.

## Deployment

Deploy the updated source and freshly generated `public/build` assets from the
`solespace-b` branch, then purge Hostinger/CDN cache. No database command is
required.
