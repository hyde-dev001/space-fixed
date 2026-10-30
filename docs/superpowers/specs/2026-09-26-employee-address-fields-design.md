# Employee profile and Philippine address fields

## Goal

Extend employee creation in Shop Owner User Access Control and HR with an optional suffix and structured Philippine address fields: address, province, city/municipality, and four-digit postal code. Province and city/municipality must use the existing complete location dataset, with the city list dependent on the selected province. Values must remain available through the owner and HR employee payloads without breaking existing employee records or request names.

## Approved behavior

- Suffix is optional.
- Province, city/municipality, and postal code are required when a street/address value is supplied.
- Province is a dropdown backed by `resources/js/data/philippineLocations.ts`.
- City/municipality is disabled until a province is selected, and its options are derived from that province. Changing province clears the city selection.
- Postal code accepts exactly four digits.
- Existing employee storage is reused: province maps to the legacy employee `state` column, city/municipality to `city`, and postal code to `zip_code`.
- Employee suffix is added as a nullable employee column. The linked user already has suffix storage; structured user address columns are added so other pages can read the same profile data directly.
- Owner and HR responses expose stable aliases (`province`, `city_municipality`, and `postal_code`) in addition to existing fields. Frontend transforms accept both the new aliases and legacy values.
- Existing optional address records remain valid. No existing endpoint, role check, or tenant scope is loosened.

## Implementation shape

1. Add the two additive nullable schema changes and update model fillable fields.
2. Add one shared address-field component using the existing select component and location dataset.
3. Add the shared component and suffix field to both employee creation modals, including dependent-reset behavior and payload fields.
4. Validate and persist fields in both owner and HR employee creation paths; preserve legacy HR request aliases and synchronize new values to the linked user.
5. Add response aliases to owner and HR employee payloads so downstream pages can fetch structured values consistently.
6. Add focused frontend and feature regression tests, then run the narrow frontend/PHP checks and production build.

## Risks and mitigations

- Legacy rows may have only `address`, `city`, `state`, or `zip_code`: aliases fall back to those columns and all new columns are nullable.
- Province data should not be duplicated: the existing 83-province/1,642-city dataset remains the single frontend source.
- Employee data is tenant-scoped and personal: retain existing owner/HR authorization, shop scoping, CSRF, and server-side validation.
- No destructive migration or dependency is required.
