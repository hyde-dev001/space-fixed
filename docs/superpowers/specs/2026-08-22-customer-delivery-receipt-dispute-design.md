# Customer Delivery Receipt and Dispute Design

**Status:** Approved
**Target base:** `origin/solespace-b`
**Worktree:** `.worktrees/customer-delivery-receipt-dispute`
**Scope:** Retail customer orders using third-party or shop-owned logistics

## Problem

The current delivery flows use different completion rules:

- Third-party logistics uses the legacy customer confirmation endpoint. A customer confirmation can move an order from `shipped` to `delivered`.
- Shop-owned logistics uses rider delivery proof followed by dispatcher approval. The logistics leg becomes delivered, but the customer does not yet have a separate Shopee-style receipt acknowledgement.
- The current shop-owned completion path can represent the order as `completed`, while the requested operational state is `delivered`.

The feature must add customer receipt acknowledgement and customer delivery reporting without weakening the dispatcher-approved delivery record.

## Goals

1. Keep dispatcher approval of valid shop-owned rider proof as the authoritative delivery completion event.
2. Set shop-owned retail orders to `delivered` after the dispatcher approves valid proof.
3. Let the customer acknowledge receipt separately with an `Order Received` action.
4. Record customer delivery problems as a separate dispute/exception record.
5. Keep the original `delivered` order status and delivery event unchanged when a dispute is reported.
6. Show delivery, receipt, and dispute state consistently in customer orders, the existing Logistics Shipments page, and Staff Job Orders.
7. Preserve the existing third-party customer-confirmation behavior.

## Non-goals

- Do not create or integrate a new Action Center module.
- Do not change repair delivery, repair pickup, or return-to-shop handoff semantics.
- Do not automatically create a refund, replacement, or return workflow when a dispute is resolved. The dispatcher records the resolution and can use existing workflows separately.
- Do not add customer-uploaded dispute evidence in this first version. The dispatcher can inspect the existing rider proof, timestamps, assignment, and delivery events.

## State model

The order lifecycle and customer receipt lifecycle are independent.

```text
Shop-owned delivery

Rider proof
    ↓
Dispatcher approves valid proof
    ↓
order.status = delivered
customer_receipt_status = pending
    ├── Order Received → customer_receipt_status = confirmed
    └── Report Order   → customer_receipt_status = disputed
                         delivery_dispute.status = open
```

The main order status never rolls back because of a customer dispute.

### Receipt states

`customer_receipt_status` has these values:

- `pending`: delivery is complete but the customer has not acknowledged receipt.
- `confirmed`: the customer acknowledged physical receipt.
- `disputed`: the customer reported a delivery/order problem.

The receipt status is an order-level summary. A `customer_received_at` timestamp records the acknowledgement. A `customer_receipt_disputed_at` timestamp records the latest transition into dispute.

### Dispute states

`DeliveryDispute.status` has these values:

- `open`: newly reported and awaiting dispatcher review.
- `investigating`: dispatcher has started review.
- `resolved`: dispatcher selected a supported resolution.
- `rejected`: the report was rejected after review.

Supported reasons:

- `item_not_received`
- `damaged`
- `incomplete`
- `wrong_item`
- `other`

Supported resolution values:

- `customer_confirmed`
- `refund_required`
- `replacement_required`
- `return_required`
- `report_rejected`

## Data model

### `orders` additions

Add the following timestamp and state fields through a forward migration:

- `customer_receipt_status` — string, default `pending`, indexed with `status`.
- `customer_received_at` — nullable timestamp.
- `customer_receipt_disputed_at` — nullable timestamp.

New orders default to `pending`. Existing `delivered` and `completed` orders are backfilled to `confirmed` so the rollout does not create retroactive receipt prompts without a customer action. Other existing orders are backfilled to `pending`. Existing order status values remain unchanged during the backfill.

The `Order` model exposes the fields, casts timestamps, and provides a relation to delivery disputes.

### `delivery_disputes`

Create a dedicated table with:

- `id`
- `shop_owner_id`
- `order_id`
- nullable `shipment_id`
- nullable `shipment_leg_id`
- `customer_id`
- `status`
- `reason`
- nullable `notes`
- `reported_at`
- nullable `investigated_at`
- nullable `investigated_by_type`
- nullable `investigated_by_id`
- nullable `resolution`
- nullable `resolution_note`
- nullable `resolved_by_type`
- nullable `resolved_by_id`
- nullable `resolved_at`
- timestamps

Foreign keys are tenant-safe and use the project’s normal delete behavior. Add indexes for shop/status, order/status, and customer/status. Application-level locking prevents more than one `open` or `investigating` dispute for the same order. Historical resolved or rejected disputes remain queryable.

`DeliveryDispute` is separate from `DeliveryIncident`: the existing incident belongs to rider-originated logistics problems, while this record belongs to customer-reported post-delivery concerns.

## Domain services and transitions

### Customer receipt service

Add a small service that owns customer receipt transitions and keeps the existing controller route compatible.

The existing route remains:

```text
POST /orders/confirm-delivery
```

Rules:

- The authenticated customer must own the order.
- For a third-party order in `shipped`, set `order.status = delivered` and receipt status to `confirmed`.
- For a shop-owned order already in `delivered`, leave the main status unchanged and set receipt status to `confirmed`.
- Repeating an already successful confirmation returns an idempotent success response.
- A customer cannot confirm receipt while an active dispute is open or investigating.
- The service runs the state change in a transaction with a row lock.

### Shop-owned completion

Keep the current rider proof and dispatcher approval path:

```text
ShipmentController@approveProof
    → ShipmentLegService::markDelivered
    → shipment status synchronization
```

For a shop-owned retail order, the final order update becomes `delivered` rather than `completed`. It does not set customer receipt to confirmed. The existing delivered event remains the operational source of truth. A customer-facing notification asks the customer to confirm receipt.

### Customer dispute service

Add a service responsible for creating and resolving customer disputes.

Customer route:

```text
POST /orders/{order}/delivery-disputes
```

Rules:

- The customer must own the order.
- The order must be `delivered` or a legacy `completed` order.
- The existing order/refund reporting window is reused through the order’s existing deadline behavior.
- A reason is required; notes are optional.
- The order is locked while the dispute is created.
- A duplicate active report returns the existing active dispute idempotently.
- On creation, set `customer_receipt_status = disputed` and `customer_receipt_disputed_at = now()`.
- Never change `order.status` or delete/replace the dispatcher-approved delivered event.

Dispatcher routes on the existing logistics API surface:

```text
POST /api/logistics/delivery-disputes/{dispute}/investigate
POST /api/logistics/delivery-disputes/{dispute}/resolve
```

The dispatcher must be authorized for the dispute’s shop owner and hold the existing logistics exception/proof-approval authority appropriate to the branch. Resolution is transactional and idempotent. `report_rejected` sets dispute status to `rejected`; the other supported resolutions set dispute status to `resolved`. Resolutions affect receipt state as follows:

- `customer_confirmed`: receipt becomes `confirmed` and records `customer_received_at` if absent.
- `report_rejected`: receipt returns to `confirmed` if `customer_received_at` exists, otherwise `pending`.
- `refund_required`, `replacement_required`, or `return_required`: dispute becomes resolved while receipt remains `disputed`.

No resolution changes the main order status.

## Audit and notifications

Where a shipment exists, record customer-visible or internal delivery events for receipt confirmation, dispute reporting, investigation, and resolution. The original `delivered` event is not rewritten.

Use the existing notification infrastructure:

- Dispatcher proof approval for shop-owned delivery notifies the customer that the order is delivered and asks for `Order Received`.
- A customer dispute creates a high-priority dispatcher notification linking to the existing Logistics Shipments page with the dispute filter.
- A resolved dispute notifies the customer of the selected resolution.

Notification delivery must not make the underlying transaction fail; the database state remains authoritative.

## UI changes

### Customer `MyOrders`

Extend the order payload with receipt status, timestamps, reporting eligibility, and active dispute summary.

- Show `Order Received` for delivered shop-owned orders with `pending` receipt.
- Keep the existing third-party confirmation action, adapting it to the shared receipt service.
- Show `Receipt confirmed` after acknowledgement.
- Show `Customer Dispute`/`Report under investigation` for disputed orders.
- Keep `Report Order` available for delivered orders during the existing reporting window, including after receipt confirmation.
- The report dialog captures reason and optional notes and shows server validation errors.

### Existing ERP Logistics Shipments

Extend the existing page, not a new Action Center:

- Add a `Customer disputes` filter.
- Include dispute summaries in shipment/order details.
- Show customer reason/notes, rider proof, timestamps, rider assignment, and delivery events.
- Provide investigate and resolve actions using the new dispute endpoints.
- Keep order status visibly `Delivered` when a dispute exists.

### ERP Staff Job Orders

Extend the existing Staff Orders API payload and `ERP/STAFF/JobOrders.tsx` mapping:

- Reflect the canonical order status as `Delivered` after shop-owned dispatcher approval.
- Show separate `Receipt Pending`, `Receipt Confirmed`, or `Customer Dispute` awareness badges/details.
- Keep this page read-only for disputes; resolution remains on the existing Logistics Shipments page.
- Preserve the page’s existing refresh-on-focus behavior and use the same server payload as the logistics/customer views.

## Authorization and safety

- Customer endpoints use the authenticated `user` guard and enforce `customer_id` ownership.
- Dispatcher endpoints verify shop tenancy before loading or mutating a dispute.
- Customer receipt and dispute mutations use transactions and row locks.
- State transitions are explicit and idempotent.
- Reporting is rejected after the existing order/refund window closes.
- Customer dispute data must not expose another customer’s order, private rider evidence, or another shop’s shipment.
- No generated files, `.env`, vendor, or node_modules content is edited.

## Verification plan

### Backend

Add feature coverage for:

- shop-owned dispatcher proof approval setting order to `delivered` with receipt `pending`;
- customer confirmation setting shop-owned receipt to `confirmed` without changing `delivered`;
- legacy third-party confirmation preserving `shipped` → `delivered` behavior and recording receipt confirmation;
- dispute creation, reason validation, reporting-window enforcement, and idempotency;
- reporting after receipt confirmation;
- no order-status rollback after a dispute;
- investigate and resolution transitions;
- resolution-specific receipt restoration;
- customer ownership and dispatcher shop-tenant authorization;
- Staff Orders payload exposing receipt/dispute fields.

### Frontend

Add/update tests for:

- customer receipt action visibility and success/error states;
- report modal reason/notes validation and disputed state;
- Logistics Shipments dispute filter/details/resolution controls;
- Staff Job Orders delivery and receipt/dispute badges.

Run the narrowest relevant tests first, then:

```text
git diff --check
composer test
pnpm run test:frontend
pnpm run build
```

The frontend commands must use the repository’s declared pnpm tool. If pnpm is unavailable in the environment, record that limitation rather than reporting a passing frontend check.

## Acceptance criteria

1. Dispatcher-approved shop-owned delivery is `delivered` in the order and Staff Job Orders pages.
2. The customer sees and can use `Order Received` without changing the already-delivered order status.
3. The customer can report a delivered order, including after acknowledging receipt.
4. A report creates a queryable dispute and sets receipt status to `disputed` without changing `order.status`.
5. Dispatcher can investigate and resolve disputes from the existing Logistics Shipments page.
6. Staff Job Orders shows delivery/receipt/dispute awareness but cannot resolve disputes.
7. Third-party legacy confirmation remains working.
8. Repair/return flows and the unfinished Action Center remain unchanged.
