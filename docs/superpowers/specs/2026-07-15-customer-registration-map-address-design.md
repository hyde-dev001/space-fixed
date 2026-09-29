# Customer Registration Map Address Design

## Goal

Add a compact Philippines-wide Leaflet address picker to customer registration without changing the existing registration card size or visual style. Save the selected location as the customer's default shipping address so checkout is prefilled with the same customer and address information.

## Registration UI

Keep the current registration steps, card width, inputs, and spacing. Extend the existing Address area with:

- an address search input;
- a blue **Search** button;
- an outlined **Use My GPS** button; and
- a compact fixed-height Leaflet map with a draggable marker and click-to-place behavior.

Reuse the visual treatment and Leaflet behavior already present in `ShopOwnerRegistration.tsx`, scaled to fit the customer registration form. Do not include shop-only geofence controls such as the enable toggle, radius circle, radius slider, or save-settings button.

The address text remains editable after search, GPS lookup, map click, or marker drag. Any map interaction updates the coordinates and attempts reverse geocoding. Search centers the map on a Philippines result and updates the structured address values. GPS uses browser geolocation, then reverse geocodes the coordinates.

## Location Scope and Validation

Accept locations anywhere in the Philippines. A successful registration requires:

- non-empty editable address text;
- valid latitude and longitude; and
- reverse-geocoded region/province, city or municipality, and barangay values needed by the existing shipping-address model.

Postal code remains optional because the existing `user_addresses` schema permits it to be absent. Search results outside the Philippines are rejected. GPS denial, geocoding failure, and no-result searches display an inline error while leaving manual correction and another search available.

## Persistence

Submit the address text, coordinates, and structured address fields with the existing registration request. Extend the current server-side validation for these fields.

Create the `User` and its first `UserAddress` inside one database transaction. The `UserAddress` contains:

- the user's full name;
- phone number;
- editable address text as `address_line`;
- region and province;
- city or municipality;
- barangay;
- optional postal code;
- latitude and longitude; and
- `is_default = true`.

Keep the same address text in `users.address` for backward compatibility. If either record cannot be created, roll back both records. Existing valid-ID upload behavior remains unchanged.

## Payment Prefill

Reuse the existing authenticated user and `/api/user/addresses` loading in `payment.tsx`. On load, select the default address and populate:

- customer name from first name plus last name;
- email;
- phone number;
- address line;
- region/province;
- city or municipality;
- barangay;
- postal code; and
- stored coordinates.

The payment fields remain editable and keep the existing saved-address selector behavior.

## Implementation Boundaries

Use the already-installed `leaflet` package and OpenStreetMap/Nominatim endpoints already used by Shop Owner registration. Do not add a map dependency or perform an unrelated refactor of the Shop Owner form. Only extract shared code if it produces a smaller, safer change than the localized registration implementation.

## Verification

Add one focused backend feature test proving registration creates both the user and its default shipping address with the submitted structured values and coordinates. Run that test plus the frontend TypeScript/build check that covers the modified pages. Manually confirm search, GPS error handling, map click, marker drag, editable address text, and payment prefill.
