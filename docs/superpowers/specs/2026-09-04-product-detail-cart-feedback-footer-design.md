# Product Detail Cart Feedback and Footer Design

**Date:** 2026-09-04

## Goal

Keep the existing customer add-to-cart and cart-drawer behavior while removing the green `Added to cart` confirmation panel shown inside the shared cart drawer. Add the established SoleSpace customer footer to the product-detail page shown in the second screenshot.

## Scope

- Applies to the shared customer cart drawer rendered by `Navigation`.
- Applies to the public product-detail page rendered by `ProductShow`.
- Does not change cart API requests, authentication, CSRF handling, stock checks, cart counts, drawer opening, drawer refreshes, checkout, product data, or footer content.
- Does not touch unrelated ERP/HR working-tree changes.

## Approved behavior

After a successful Add to Cart action:

1. The existing `cart:added` event is still dispatched.
2. The shared cart drawer still opens and refreshes its authoritative cart contents.
3. The green `Added to cart` status panel is not rendered.

On the product-detail page, the existing shared `CustomerFooterReveal` renders the responsive SoleSpace footer after the product content. The footer uses the same links, responsive behavior, measured spacer, accessibility gating, and styling already used by other customer pages.

## Design and data flow

The success event remains the integration point between `AddToCartButton` and `Navigation`. `Navigation` will continue to respond only when `event.detail.openDrawer` is true, close competing drawers, open the cart drawer, and increment the existing refresh key. The presentation-only `cartAddedItem` state, its close-reset effect, and the green live-region markup will be removed because no consumer remains for that data in `Navigation`.

`ProductShow` will import `CustomerFooterReveal` and wrap its existing page content with it. No new footer component, links, CSS, endpoint, dependency, or backend change is needed. Existing modal siblings remain outside the footer shell so fixed overlays keep their current stacking behavior.

## Compatibility and accessibility

- The add-to-cart success path remains non-blocking and continues to surface the updated cart contents.
- Error, guest, out-of-stock, Buy Now, and checkout behaviors remain unchanged.
- Removing the confirmation live region removes only the requested status announcement; cart contents remain available through the drawer.
- The shared footer retains its existing mobile disclosure controls and inert-until-reveal behavior.

## Verification

- Add a focused Navigation contract assertion that the drawer-open listener and refresh behavior remain, while the removed green confirmation text/markup is absent.
- Extend the shared footer contract coverage to include `ProductShow`.
- Run focused frontend tests, the full frontend suite, `pnpm run build`, and `git diff --check`.
- Review the final diff for unrelated files, then stage only the intended source, test, design, and fresh `public/build` files before committing and pushing the feature branch.
