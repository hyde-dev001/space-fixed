# Retail Job Order Logistics Summary Design

## Goal

Make logistics information consistently visible in the Retail Job Order details modal, matching the established Job Repair delivery-progress pattern. Staff must be able to see refund evidence, outbound and return shipment details, and return proof thumbnails without opening Logistics in another page.

## Current Problem

The modal currently renders `latest_refund.return_logistics` only when an internal refund-return shipment and a qualifying return leg both exist. This causes the entire block to disappear for normal deliveries, refunds that have not created a return shipment yet, and return records with incomplete logistics data. The current block also omits the shipment ID, carrier, assigned rider, and rider phone.

## Chosen Approach

Use the existing Staff Order response as the single source of truth. Add a compact top-level logistics summary for the order's outbound shipment and enrich the existing refund-return summary. Render one always-present **Logistics** section in the modal, using the same card, status labels, and explicit unavailable states already used by the Job Repair page.

This avoids frontend inference and avoids an extra request when the modal opens.

## Backend Contract

`StaffOrderController` will serialize logistics data using the canonical `shipments`, `shipment_legs`, `delivery_assignments`, `rider_profiles`, `shipping_methods`, and `handoff_proofs` records.

The order payload will expose:

```json
{
  "logistics": {
    "shipment_id": 12,
    "shipment_status": "active",
    "leg_id": 31,
    "leg_type": "outbound",
    "leg_status": "in_transit",
    "carrier": "Shop-owned logistics",
    "rider_name": "Marco Santos",
    "rider_phone": "09123456789",
    "tracking_number": "SS-00031",
    "tracking_url": null,
    "proofs": []
  }
}
```

When no outbound shipment exists, `logistics` is `null`. When a shipment exists but has no displayable leg yet, the summary still contains the shipment ID and shipment status; leg, carrier, rider, and tracking fields are nullable and `proofs` is an empty array. This lets the modal show the created shipment without inventing leg data.

`latest_refund.return_logistics` will keep its existing fields and add `carrier`, `rider_name`, and `rider_phone`. It follows the same shipment-without-leg rule: the object exists when the shipment exists, while leg-specific fields remain nullable until a qualifying leg exists. It will continue to expose protected proof URLs rather than storage paths. The assigned rider comes from the latest active or completed assignment for the selected leg. Carrier falls back from the shipping method to the existing order/refund carrier fields when needed.

Only proof records with a non-empty file path are exposed. Return proof thumbnails come only from the selected return leg after that leg reaches the completed `delivered` state; otherwise `proofs` is an empty array. Existing proof authorization remains unchanged.

## Modal Design

The Job Order details modal will retain the separate **Refund Evidence** thumbnail group.

An always-present **Logistics** section will follow it:

- If neither outbound nor return shipment exists, show `No logistics shipment yet.`
- If an outbound shipment exists, show a compact **Customer delivery** card.
- If a refund-return shipment exists, show a compact **Return to shop** card.
- Each available card shows shipment ID, shipment status, leg status, carrier, rider with phone, and tracking number/link.
- Proof thumbnails appear inside the relevant card. Return thumbnails are labeled `Return delivery proof` for screen readers and visible context.
- Missing secondary values show `Not assigned` or `Not available`; the whole section is never hidden.

Statuses use text labels in addition to visual styling, matching the Job Repair page and avoiding color-only communication.

## Data Selection Rules

- Outbound shipment: latest shipment for `source_type = order`, the current order ID, and purpose `customer_delivery`.
- Return shipment: latest shipment for `source_type = order_refund`, the latest refund ID, and purpose `refund_return`.
- Display leg: the most relevant delivery/return leg from the selected shipment, preferring the highest sequence. If none exists, serialize the shipment with nullable leg fields.
- Rider: latest assignment that represents the current or completed assignment for the displayed leg.
- Outbound proofs: protected URLs for proofs attached to the displayed outbound leg.
- Return proofs: protected URLs only for proofs attached to the displayed return leg when its status is `delivered`.

Tenant filtering remains mandatory on every shipment query.

## Error and Empty States

- Missing shipment: `No logistics shipment yet.`
- Shipment without a leg: show shipment ID/status and `Leg not created yet`.
- Shipment without rider: `Not assigned`.
- Shipment without tracking: `Not available`.
- Shipment without proof: `No proof submitted yet.`
- Broken or unauthorized proof requests keep the existing server response; no public file path is exposed.

## Testing

Backend feature tests will verify:

- List and show responses expose the same outbound logistics summary.
- Return logistics includes carrier, rider, tracking, and protected proof URLs.
- Orders without shipments return `logistics: null`.
- Cross-shop shipment data is never included.

Frontend tests will verify:

- The Logistics section is always rendered.
- The explicit empty state appears without shipments.
- Outbound and return cards show the required fields.
- Refund evidence and return proof thumbnails remain separate and accessible.

The focused PHP and Vitest suites, Pint, and a fresh production build must pass before handoff.
