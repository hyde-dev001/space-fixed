# Product Price Range Filter Design

Date: 2026-09-21

## Goal

Add a price-range filter to the public Products catalog. Selecting `Price range` from the existing sort/filter menu opens a modal matching the existing Color filter modal. Customers can enter a minimum and maximum price, apply the range, and see only shoes priced within the inclusive range.

## Approved approach

- Reuse the existing sort menu and Color filter modal structure in `resources/js/Pages/UserSide/Products/Products.tsx`.
- Add two labeled numeric inputs: `Minimum price` and `Maximum price`.
- Keep draft values local to the modal. Apply only when the customer presses `Apply`.
- Cancel, close, backdrop click, or Escape discards un-applied values.
- Persist the active range in URL query parameters so the filter survives refresh and is shareable.
- Apply the range in the public products API before pagination through the existing Spatie Query Builder flow in `app/Http/Controllers/Api/ProductController.php`.
- Use inclusive comparisons: `min_price <= price <= max_price`.
- Reset to page 1 whenever the range is applied or cleared.

## User experience

The modal uses the established light SoleSpace treatment: a 40% scrim, centered white dialog, rounded corners, border, shadow, visible close button, and a footer with `Clear`, `Cancel`, and one primary `Apply` action. It remains usable on small screens with stacked inputs and a scroll-safe max height.

The active menu item shows the selected range in Philippine peso format. Empty bounds are allowed for open-ended ranges. Invalid input is shown inline when a value is negative, non-numeric, or the minimum is greater than the maximum. The primary action remains keyboard and touch accessible, with visible focus states and 44px minimum control height.

## Data flow

1. Read `min_price` and `max_price` from the current URL on page load and when the URL changes.
2. Open the modal with the active range copied into draft state.
3. On Apply, normalize valid numeric values, update the URL, reset pagination, and refetch products.
4. Send the values as `filter[price_min]` and `filter[price_max]` to `/api/products/`.
5. Register those filters in `ProductController@index` as constrained numeric callbacks to avoid accepting malformed values or broadening the query unexpectedly.

## Alternatives considered

1. **Client-side filtering:** smallest backend diff, but incorrect with pagination and only filters already loaded products.
2. **Full page navigation:** uses native URL state, but interrupts the current catalog state and is inconsistent with the existing Color interaction.
3. **URL-backed API filtering (approved):** preserves the current interaction, keeps pagination correct, and reuses the existing API/query-builder boundary.

## Non-goals and risks

- No slider dependency, new component library, or new design system tokens.
- No change to existing sorting, search, category, or color semantics.
- No currency conversion; prices continue to use the catalog's Philippine peso values.
- The main regression risks are stale draft state, malformed bounds, and query filters being applied after pagination. Tests should cover all three.

## Verification

- Extend the existing Products source-contract test for the new menu item, URL parameters, API filter keys, labels, and validation paths.
- Add or extend a focused Laravel feature test for inclusive minimum/maximum price filtering and invalid bounds.
- Run the focused frontend test, focused Laravel test, frontend production build, and `git diff --check`.
- Browser-check desktop and mobile modal opening, keyboard dismissal, validation, apply/cancel behavior, and the filtered result set when the local app is runnable.
