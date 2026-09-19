# Shop Owner Personal Details and Address Design

## Status

Approved in conversation on 2026-09-19. This document is intentionally limited to the requested registration update.

## Outcome

Shop owner registration will collect `suffix`, `age`, and a personal address separately from the existing shop/business address. The personal address will use Leaflet and the existing GPS/reverse-geocoding flow so `Use My GPS` fills the address details automatically.

The existing Shop Information address, Cavite-only location policy, shop Leaflet map, geofence data, document workflow, and Super Admin approval decision flow remain unchanged.

## Recommended approach

Reuse `CustomerAddressMapPicker` for the new personal address. It already provides Leaflet rendering, address search, GPS, reverse geocoding, marker movement, and Philippine-address parsing. The current custom map in `ShopOwnerRegistration.tsx` remains responsible for the shop address because it has separate Cavite and geofence behavior.

The page will keep the current four-step wizard. Personal details and the personal address will be added to Step 1 rather than creating a new step or changing the progress contract.

## UI and data flow

Step 1 will add:

- optional suffix input;
- required age input, accepting whole numbers from 18 through 120;
- required personal address input;
- personal postal code, province, city/municipality, and barangay fields populated from the selected map location;
- Leaflet search/map controls and `Use My GPS` through `CustomerAddressMapPicker`.

When the picker returns a location, the page will update the personal address display text, address components, postal code, and coordinates together. The existing Step 2 shop address and shop GPS action will continue updating only shop fields.

## Persistence and compatibility

Add one additive migration to `shop_owners` with nullable legacy-safe columns:

- `suffix`, `age`;
- `address`, `address_region`, `address_province`, `address_city`, `address_barangay`, `address_postal_code`;
- `address_latitude`, `address_longitude`.

New registration and resubmission requests will validate the new fields, including Philippine coordinate bounds, and persist them in the same transaction as the existing shop owner registration data. Coordinates will be resolved through the existing Nominatim service so submitted address components cannot override the selected location. Existing shop-owner rows remain readable because the new columns are nullable.

Rejected applications opened for resubmission will receive the saved personal fields when present; legacy applications with empty fields will show blank inputs and must complete the new required fields before resubmitting.

## Super Admin approval compatibility

The Super Admin registration query will select and format the new personal fields for the existing registration detail modal. The list and approve/reject endpoints remain unchanged because approval changes only registration status through the existing decision service. The approval end-to-end test will verify that the new personal fields survive registration and that the same application can still be approved.

## Error handling and verification

- Client validation will highlight missing/invalid suffix-independent personal details and missing map selection.
- Server validation remains authoritative and returns normal Inertia validation errors.
- GPS/search failures will leave the user able to retry or select a location manually.
- Existing shop-location/Cavite validation will not be weakened or reused for the personal address.

Verification will cover the registration payload/database persistence, invalid personal address rejection, resubmission prefill/update, Super Admin detail payload, and approval end-to-end behavior, followed by the relevant frontend test/build and diff checks.

## Explicitly out of scope

- creating a separate address table for shop owners;
- changing existing shop address or geofence semantics;
- changing the Super Admin approval/rejection rules;
- migrating existing personal-address data that does not exist;
- refactoring the existing shop map into the shared picker.
