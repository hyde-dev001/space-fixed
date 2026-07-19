# Customer Address Pin and Logistics Coverage Design

Date: 2026-07-19
Status: Approved
Owner: Customer Checkout and Retail Logistics

## Objective

Let customers pin delivery addresses with Leaflet and use those saved coordinates to determine whether each shop can offer Shop-owned Logistics within its configured coverage radius.

## Confirmed Decisions

1. Use Leaflet with the same interaction pattern as Shop Address Settings: map click, draggable marker, search, GPS, and reverse geocoding.
2. Checkout and Payment use saved addresses and allow add/edit with a shared customer address picker.
3. Coverage uses the existing Haversine straight-line calculation and each shop's `coverage_radius_km`.
4. Within coverage, both carriers remain available and Shop-owned Logistics is selected by default.
5. Outside coverage, Shop-owned Logistics is disabled while third-party delivery remains available.
6. A saved address without coordinates must be repinned before Shop-owned Logistics can be selected; third-party delivery remains available.
7. Multi-shop carts calculate coverage and select a carrier independently for every shop/order.
8. Extend the existing `/api/shipping/estimate` endpoint instead of introducing another coverage endpoint.

## Existing Components to Reuse

- `UserAddress.latitude` and `UserAddress.longitude` for the customer pin.
- Existing customer address create/update endpoints and server-side coordinate validation.
- Existing Leaflet dependency and the interaction pattern in Shop Address Settings.
- `DeliveryScheduleService` Haversine calculation and logistics setting lookup.
- Existing third-party shipping estimate behavior as the fallback option.

No new mapping package, address table, or coverage service is required.

## Customer Address Flow

1. The customer adds or edits a saved address from Checkout or Payment.
2. A shared Leaflet picker captures latitude and longitude alongside the structured address fields.
3. The address endpoint saves the pin to `user_addresses`.
4. Selecting a saved address sends its `address_id` when requesting a shipping estimate.
5. Addresses missing coordinates show a Repin action and cannot enable Shop-owned Logistics until saved with a valid pin.

Registration keeps its current Leaflet flow and continues creating the customer's default pinned address.

## Coverage and Carrier Flow

For each shop represented in the cart:

1. Checkout requests `/api/shipping/estimate` with `address_id` and `shop_id`.
2. The backend loads the authenticated customer's address and the requested shop; client-supplied coordinates are not trusted for coverage.
3. The backend compares the shop and destination coordinates using the same Haversine logic used by shipment scheduling.
4. The response retains the existing third-party estimate and adds the Shop-owned Logistics availability result.
5. The UI defaults that shop to Shop-owned Logistics only when available; otherwise it selects third-party delivery and explains why Shop-owned is unavailable.
6. Checkout submits a shop-to-carrier mapping so each generated order receives its selected `carrier_company`.
7. Order creation validates the selected Shop-owned carrier again against the saved address and current logistics settings before persisting the order.

## Shipping Estimate Contract

The existing endpoint accepts the current address fields for backward compatibility and adds:

- `address_id`: authenticated customer's saved address.
- `shop_id`: shop whose coverage is being checked.

The response keeps the current third-party estimate fields and adds:

```json
{
  "shop_owned": {
    "available": true,
    "reason": null,
    "distance_km": 4.2,
    "coverage_radius_km": 10
  }
}
```

Unavailable reasons are limited to values the UI needs:

- `address_needs_pin`
- `shop_needs_pin`
- `outside_coverage`
- `logistics_unavailable`

## UI Behavior

- Show one delivery section per shop in a multi-shop checkout.
- Show both carrier choices when Shop-owned Logistics is available.
- Disable Shop-owned Logistics with a short reason when unavailable.
- Show distance and radius as supporting information, not as editable values.
- Recalculate every shop when the selected address changes or a pin is updated.
- Prevent final submission while an estimate or required revalidation is pending.

## Validation and Failure Handling

- Require `address_id` to belong to the authenticated customer.
- Require `shop_id` to match a shop represented in the submitted cart during order creation.
- Validate latitude and longitude as a pair and within the existing Philippine bounds.
- Never accept a client claim that an address is within coverage.
- If coverage lookup fails, keep third-party delivery available and do not silently select Shop-owned Logistics.
- If settings change between estimate and checkout, reject the stale Shop-owned choice and return a clear validation error so the UI can refresh estimates.

## Testing

One focused backend feature test set covers:

- inside-radius, boundary, and outside-radius results;
- missing customer or shop coordinates;
- address ownership enforcement;
- per-shop results for a multi-shop cart;
- order creation rejecting a forged or stale Shop-owned selection.

Frontend tests cover:

- address picker saving coordinates;
- Shop-owned default within coverage;
- disabled Shop-owned and third-party fallback outside coverage or without a pin;
- independent carrier choices per shop.

## Non-Goals

- Route-distance coverage or traffic-aware coverage.
- Drawing or managing polygon service areas.
- Automatically migrating or guessing coordinates for legacy saved addresses.
- Replacing the existing third-party shipping estimator.
