# Registration GPS Postal Code Autofill Design

## Goal

When a shop owner clicks **Use My GPS** or saves a location from the registration map, the reverse-geocoded postal code should populate the existing Postal Code / ZIP Code field automatically.

## Root cause

`ShopOwnerRegistration.tsx` receives the reverse-geocoding payload but currently copies only `display_name` into `businessAddress` and infers the city. The existing `registrationAddress.ts` parser already knows that Nominatim exposes the postal code as `address.postcode`, but the registration GPS handlers do not use that mapping.

## Chosen approach

Reuse the existing registration address mapping and apply the postal-code value in both reverse-geocoding entry points:

1. Keep the current address and city behavior unchanged.
2. When the response contains a non-empty `address.postcode`, update `formData.postalCode` with it.
3. When the response has no postcode, preserve the current postal-code value so a manual value is not erased.
4. Use the same small helper for the GPS button and map “Save location” flow to avoid divergent behavior.

## Error handling

Reverse-geocoding failures continue to preserve the GPS coordinates and leave manually entered address fields unchanged. A missing postcode is treated as incomplete upstream data, not as a form error.

## Verification

- Add a focused unit regression test proving the address response maps `postcode` to the registration postal-code update and preserves an existing value when absent.
- Run the focused frontend test, the full frontend suite, the relevant registration/location PHP tests, the production build, and `git diff --check`.
