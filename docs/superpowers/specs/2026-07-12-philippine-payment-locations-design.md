# Philippine Payment Location Selection

## Goal

Replace the Cavite-only city selector in `resources/js/Pages/UserSide/Orders/payment.tsx` with dependent province and city/municipality selectors covering the Philippines. Preserve the existing checkout, saved-address, shipping-estimate, desktop, and address-sheet behavior.

## Scope

- Add a province-level selector containing every Philippine province plus Metro Manila (NCR).
- Populate the city/municipality selector only from the selected province-level entry.
- Cover every city and municipality in the bundled Philippine Standard Geographic Code (PSGC)-derived dataset.
- Preserve existing custom dropdown styling, spacing, interaction, and responsive layouts.
- Keep the current backend request and persisted-address contracts.

Barangay selection, postal-code inference, backend schema changes, runtime location API calls, and unrelated checkout refactoring are out of scope.

## Data Design

Add one static TypeScript data module containing province-level entries and their cities/municipalities. Generate or verify its contents against an authoritative PSGC source current at implementation time. Metro Manila is exposed as `Metro Manila` at the province-selection level so its sixteen cities and Pateros remain selectable despite NCR not being a province.

Each entry has a display name and an array of city/municipality names. Small normalization helpers perform case-, punctuation-, whitespace-, and diacritic-insensitive matching for saved legacy addresses. The dataset remains bundled with the application: checkout must not depend on a network request or a new package.

## Component Behavior

Both location forms in `payment.tsx`—the desktop checkout form and address-sheet form—receive the same two controls:

1. Province
2. City/Municipality

Selecting a province stores it in the existing `shippingRegion` state, clears `shippingCity`, clears the current shipping estimate and reason, and closes related dropdowns. The city/municipality control is disabled until a province is selected and then displays only that province's entries. Selecting a city/municipality retains the existing shipping-estimate trigger behavior.

The controls reuse the current button/listbox classes and scrolling behavior. Labels, placeholders, titles, and accessible names change only as needed to distinguish `Province` from `City/Municipality`.

## Saved and Checkout Address Flow

When checkout/session data or a saved address is loaded:

- Match `province`, then fall back to the existing `region` value.
- Normalize that value to a province-level dataset entry.
- Normalize the saved city/municipality within the matched province only.
- Preserve the original stored values as a fallback if an old record cannot be matched, avoiding silent address loss.

New and edited addresses continue sending the chosen province through both `region` and `province`, matching the existing API contract. Checkout/order payloads continue using `shipping_region`, `shipping_province`, and `shipping_city`; no backend change is required.

## Validation and Failure Handling

The existing required-field validation continues to require both `shippingRegion` and `shippingCity`. A province change always clears the city to prevent an invalid cross-province combination. Empty or unmatched saved values leave the relevant selector unselected rather than guessing.

## Verification

Add one focused frontend test for the location data/lookup logic. It verifies:

- every province-level entry has at least one city or municipality;
- province-level names and child names are unique within their relevant scope;
- Metro Manila includes its sixteen cities and Pateros;
- representative entries from Luzon, Visayas, and Mindanao resolve correctly;
- Cavite's existing values and common aliases remain restorable;
- changing the selected province cannot retain a city from the previous province.

Run the focused test and the existing frontend build/type compilation path. Manually inspect both desktop and address-sheet selectors for unchanged layout and dependent behavior.
