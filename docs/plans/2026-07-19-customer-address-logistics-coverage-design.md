# Customer Address Pin and Logistics Coverage Design

Date: 2026-07-19
Status: Approved
Owner: Customer Checkout and Retail Logistics

## Objective

Let customers pin delivery addresses with Leaflet and use those saved coordinates to determine whether the selected shop can offer Shop-owned Logistics within its configured coverage radius.

## Confirmed Decisions

1. Use Leaflet with the same interaction pattern as Shop Address Settings: map click, draggable marker, search, GPS, and reverse geocoding.
2. Checkout and Payment use saved addresses and allow add/edit with a shared customer address picker.
3. Coverage uses the existing Haversine straight-line calculation and each shop's `coverage_radius_km`.
4. Staff retains carrier selection when marking a processing order as shipped.
5. Within coverage, the Staff Job Orders shipping modal defaults to Shop-owned Logistics while third-party carriers remain available.
6. Outside coverage, Shop-owned Logistics is disabled in the staff shipping modal while third-party delivery remains available.
7. A saved address without coordinates must be repinned before Shop-owned Logistics can be selected; third-party delivery remains available.
8. Retail checkout remains single-shop, matching the existing Checkout UI and server validation.
9. Extend the existing `/api/shipping/estimate` endpoint instead of introducing another coverage endpoint.

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

Retail checkout already permits items from only one shop. For that selected shop:

1. Payment requests `/api/shipping/estimate` with `address_id` and the existing selected product IDs.
2. The backend loads the authenticated customer's address and resolves the single shop from those products; client-supplied coordinates or arbitrary shop IDs are not trusted for coverage.
3. The backend compares the shop and destination coordinates using the same Haversine logic used by shipment scheduling.
4. The response retains the existing third-party estimate and adds the Shop-owned Logistics eligibility result for customer visibility.
5. Payment creates the order without choosing a carrier, preserving the current retail lifecycle.
6. The Staff Orders response includes the same eligibility result, calculated from each order's saved address and shop. The Mark as Shipped modal consumes that result without a separate endpoint.
7. Within coverage, Shop-owned Logistics is the default carrier choice. Outside coverage or without a valid pin, Shop-owned Logistics is disabled with a clear reason.
8. The staff status endpoint validates Shop-owned eligibility again before saving `carrier_company` and creating the logistics shipment.

## Shipping Estimate Contract

The existing endpoint accepts the current address fields and `item_pids` for backward compatibility and adds:

- `address_id`: authenticated customer's saved address.

The shop continues to be resolved from `item_pids`, matching the existing single-shop checkout.

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

- Checkout and Payment continue enforcing the existing single-shop flow.
- Payment shows whether the selected address is eligible for Shop-owned Logistics, but the customer does not select the carrier.
- The Staff Job Orders shipping modal defaults to Shop-owned Logistics when it is eligible.
- In Staff Retail Process / Job Orders, opening Mark as Shipped for an outside-coverage address disables the Shop-owned Logistics option; staff can select only a third-party carrier.
- The disabled option shows a short reason, including the calculated distance and configured radius when available.
- Show distance and radius as supporting information, not as editable values.
- Recalculate when the selected address changes or a pin is updated.
- Prevent final submission while an estimate or required revalidation is pending.

## Usability and Accessibility

- Keep the map compact and responsive inside the existing Checkout and Payment address forms; it must not hide the structured address fields or primary actions on mobile.
- Give customers three obvious ways to set the pin: tap/click the map, drag the marker, or use Search / My Location.
- Show immediate loading, success, and friendly error feedback for search, GPS, reverse geocoding, saving, and coverage checks.
- Preserve typed address values when a map or network lookup fails so customers do not lose their work.
- Clearly label the selected pin and provide a one-action Repin path for legacy addresses without coordinates.
- Use keyboard-focusable controls, associated labels, visible focus states, `aria-live` status text, and touch targets of at least 44px.
- Dynamically load Leaflet only when an address form containing the map is open, and invalidate the map size after a modal or sheet becomes visible.
- Never block third-party checkout solely because Shop-owned Logistics is unavailable or an address has no pin.
- In Staff Job Orders, present one short eligibility message beside the carrier selector and never expose internal status codes.

## Validation and Failure Handling

- Require `address_id` to belong to the authenticated customer.
- Resolve the shop from the selected single-shop cart or order instead of trusting an arbitrary client shop ID.
- Validate latitude and longitude as a pair and within the existing Philippine bounds.
- Never accept a client claim that an address is within coverage.
- Reject a direct or forged staff request that sets `carrier_company` to Shop-owned Logistics when the order address is outside coverage or lacks valid coordinates.
- If coverage lookup fails, keep third-party delivery available and do not silently select Shop-owned Logistics.
- If settings change before staff marks the order shipped, reject a stale Shop-owned choice and refresh the staff shipping modal.

## Testing

One focused backend feature test set covers:

- inside-radius, boundary, and outside-radius results;
- missing customer or shop coordinates;
- address ownership enforcement;
- single-shop estimate resolving the correct shop from the order items;
- staff status update rejecting a forged or stale Shop-owned selection.

Frontend tests cover:

- address picker saving coordinates;
- Shop-owned default within coverage;
- disabled Shop-owned and third-party fallback outside coverage or without a pin;
- staff modal defaulting or disabling Shop-owned Logistics from the current coverage result.

## Non-Goals

- Route-distance coverage or traffic-aware coverage.
- Drawing or managing polygon service areas.
- Automatically migrating or guessing coordinates for legacy saved addresses.
- Replacing the existing third-party shipping estimator.
- Moving carrier selection from staff to the customer.
- Adding separate pricing for Shop-owned Logistics.
