# Logistics Shipment Cards and Product Summary

## Problem

The ERP Shipments page is a wide, text-heavy table. Dispatchers can see the
receiver and address, but cannot identify the shoes in a retail order or the
number of pairs being delivered. Its expanded rows repeat delivery details and
show raw schedule values, making active work difficult to scan.

The Batches page has clearer cards and workflow hierarchy, but its available
delivery and route-stop cards have the same product-information gap.

## Goals

- Replace the ERP Shipments table with responsive, expandable shipment cards.
- Show enough retail-order information to identify a delivery at a glance.
- Show full variant details only when a shipment is expanded.
- Add the same compact product summary to Batch delivery choices and route
  stops.
- Preserve the rider-facing My Deliveries actions while applying the same
  responsive card structure.
- Preserve all existing shipment, batching, assignment, proof, status,
  pagination, and capacity behavior.

## Non-Goals

- Route maps or automatic route optimization.
- Changing the rule that one shipment is one delivery stop regardless of item
  quantity.
- Changing shipping fees, order grouping, rider capacity, or delivery status
  transitions.
- Adding Batch history pagination or advanced Batch filters in this change.
- Retrofitting product summaries into immutable completed or cancelled Batch
  stop snapshots.
- Adding product sections to repair or refund shipments when no retail-order
  items apply.

## Shipments Page

### Filters

Keep the dispatcher's status and purpose filters and the rider's status and time
filters, then add server-side search to both modes. Search matches shipment or
order number, receiver name, phone, address, product brand, or product model.
Filters remain in the URL and reset pagination to the first page when changed.

### Collapsed Card

Each shipment card shows:

- Shipment number and source order number.
- Purpose and semantic status badges.
- Receiver name and shortened address.
- Human-readable delivery date and window.
- Assigned rider or `Unassigned`.
- Product thumbnail, first brand/model, total pairs, and variant count for a
  retail delivery.
- `+N more` when the order contains additional shoe models.
- Operational indicators when applicable: urgent, overdue, failed attempt, or
  proof awaiting approval.
- A primary `Open delivery` action and an accessible expand/collapse control.

If a product has been removed, the card uses saved order-item values. If the
originating order itself is unavailable, the card shows `Order details
unavailable` instead of failing to render.

### Expanded Card

Expanded content has three sections:

1. **Order items** — one row per ordered variant with thumbnail, brand, model,
   color, size, and quantity.
2. **Delivery details** — receiver, phone, full address, delivery instructions,
   schedule, and stop number.
3. **Assignment and progress** — leg status, assigned rider, failed-attempt
   information, proof review, scheduling, assignment, and the existing
   permitted actions.

Receiver and address information appears once per relevant leg rather than
being repeated in both the summary and an unstructured text block. Repair and
refund shipments use the same card shell but retain their existing return or
service details without an empty order-items section.

### States and Responsiveness

- Distinguish an empty shipment history from filters that return no matches.
- Preserve the current pagination summary and links.
- Show mutation errors within the expanded shipment being acted on.
- Stack summary fields and expanded sections on small screens; do not require
  horizontal scrolling.
- Expansion controls expose `aria-expanded`, and status is not communicated by
  color alone.

The existing My Deliveries page renders the same Shipments component in rider
mode. It receives the same order summary and card treatment while retaining its
pickup, in-transit, proof, failed-attempt, and return-handoff actions and its
assignment-scoped query.

## Batches Page

The existing Batch layout and workflow remain unchanged. Add a compact retail
product summary to:

- Each row in **Available deliveries**.
- Each live route stop in the Batch workspace and expanded active Batch cards.

The summary contains a small thumbnail, first brand/model, total pairs, variant
count, and `+N more` for additional models. A delivery-instructions indicator
appears when notes exist. Full color and size rows stay on the Shipments page so
Batch cards remain focused on route planning.

The existing client-side Batch search also matches the supplied brand and model
summary. Repair and refund stops continue to display their current source and
contact information without irrelevant product placeholders.

Completed and cancelled Batch history continues to use its immutable stop
snapshots. Those snapshots do not contain order items, so historical cards keep
their existing contact and route details without a product summary.

## Data Design

Retail shipments receive an `order_summary` assembled from the originating
order:

- `order_id` and the stored `order_number`, with `source_id` as the display
  fallback when no order number exists.
- `items`: saved product name, image, color, size, quantity, and related product
  brand when available.
- `total_quantity`: sum of item quantities.
- `variant_count`: number of order-item variant rows.
- `model_count`: number of distinct product models.

Saved `OrderItem` values are authoritative snapshots for model, image, color,
size, and quantity. The current Product relation supplies brand when it still
exists. Missing relations use safe fallbacks.

Because `Shipment` stores `source_type` and `source_id` rather than an Eloquent
source relation, each controller query should collect the page's live retail
order IDs and load them in one query with their items and products. Every order
query must also match the authorized `shop_owner_id`. Summaries are then
attached by order ID. The same summary shape is used by Shipments, My
Deliveries, and active Batches to avoid separate presentation rules and N+1
queries.

No database migration or new dependency is required.

## Verification

Backend tests cover:

- A retail shipment returns all order variants and correct totals.
- Multiple models remain under one shipment and one stop.
- Missing products fall back to saved order-item values.
- A missing originating order returns `Order details unavailable`.
- Repair and refund shipments do not receive misleading retail items.
- Search matches order/customer/contact/product values while remaining scoped
  to the authorized shop.
- Cross-shop orders and items never appear in returned summaries, including
  when a source ID is manipulated.

Frontend tests cover:

- Collapsed retail summary and multiple-model label.
- Expanded brand, model, color, size, and quantity rows.
- Human-readable schedules and operational indicators.
- Empty, filtered-empty, missing-product, and responsive card states.
- Existing scheduling, rider assignment, failed-attempt, proof, pagination, and
  Batch actions remain available.
- Rider-mode status actions and assignment scoping remain available.
- Expand controls expose the correct accessible name and `aria-expanded` state.
- Compact summaries appear in both Batch available-delivery rows and route
  stops.

## Acceptance Criteria

- The Shipments page no longer uses the wide shipment table.
- A dispatcher can identify the order contents and total pairs without opening
  another module.
- Expanding a retail shipment shows every ordered variant.
- Batch planning shows the same compact order identity without becoming a full
  order-details screen.
- Multiple variants remain one shipment and one delivery stop.
- Existing logistics workflows continue to behave as before.
