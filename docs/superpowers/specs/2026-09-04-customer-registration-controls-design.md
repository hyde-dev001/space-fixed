# Customer Registration Controls Design

**Status:** Approved

**Goal:** Align the customer registration address actions with the existing `Next` button and make password guidance hover-only without changing registration behavior.

## Scope

The change is limited to the customer registration page at `/register`, implemented by `resources/js/Pages/UserSide/Auth/Register.tsx`. The existing address search, GPS lookup, validation, map, and submission flows remain unchanged.

## Interaction and visual design

- `Search` and `Use My GPS` use the same black background, white text, rounded shape, and hover treatment as the `Next` button.
- Existing loading and disabled states remain visible through the current labels and disabled opacity.
- The password requirements panel stays absolutely positioned so it does not shift the form layout.
- The panel opens only while the password field group is hovered. The `group-focus-within` activation is removed, while the password input keeps its normal visible focus ring.
- Existing labels, `aria-describedby`, requirement text, and screen-reader-only state text remain in place.

## Implementation and testing

Reuse the existing utility classes and event handlers; add no dependency or new component. Extend the registration UI contract test to require the primary button color and to reject the removed focus-triggered password state. Run the focused registration tests, the full frontend suite, the production build, and `git diff --check`.
