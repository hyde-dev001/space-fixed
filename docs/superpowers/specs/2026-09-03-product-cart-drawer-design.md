# Product Add-to-Cart Side Drawer Confirmation

**Date:** 2026-09-03

## Goal

After a successful customer Add to Cart action, open the existing shared right-side cart drawer and make the newly added item visible with a clear confirmation.

## Approved behavior

- Applies to Add to Cart actions on ProductShow and ProductQuickView.
- Does not apply to Buy Now actions or cart quantity changes in Checkout.
- A successful add opens the shared cart drawer, refreshes its server-backed contents, and shows an accessible `Added to cart` status with the product name and quantity.
- A failed add, unauthenticated add attempt, or out-of-stock action does not open the drawer.
- The existing server request, authentication handling, CSRF protection, stock validation, cart count synchronization, and error feedback remain unchanged.

## Design

`AddToCartButton` already owns the successful `/api/cart/add` response and dispatches the shared `cart:added` event. The event detail will gain optional presentation metadata:

```ts
type CartAddedItem = {
  name?: string | null;
  price?: number | null;
  image?: string | null;
  size?: string | null;
  color?: string | null;
  quantity?: number;
};

interface CartAddedEventDetail {
  added?: number;
  total: number;
  openDrawer?: boolean;
  item?: CartAddedItem;
}
```

Only the successful product Add to Cart path sets `openDrawer: true`. Existing event producers that only update the total remain passive, so Checkout quantity controls and logout cannot unexpectedly open the drawer.

`Navigation` will subscribe to the existing event, close any competing customer drawer, open the cart drawer, and increment a local refresh key. The existing drawer loading effect will use that key to re-fetch `/api/cart`, keeping the displayed list authoritative. A short `role="status"` confirmation inside the drawer will use the event item metadata and be cleared when the drawer closes.

The blocking success SweetAlert in `AddToCartButton` will be removed after a successful add because the drawer confirmation is the primary feedback. Error and out-of-stock SweetAlerts remain.

## Compatibility and accessibility

- No backend routes, database schema, or dependencies change.
- The existing shared drawer markup and responsive behavior remain in place.
- The confirmation uses live-region semantics and React text rendering.
- Existing Escape/backdrop close behavior and cart navigation remain unchanged.

## Verification

- Add a focused `CartActions` test proving the successful event includes the drawer flag and item metadata.
- Extend the shared Navigation contract test for the guarded event listener, refresh key, and confirmation status.
- Run the focused tests, the full Vitest suite, local Vite build, and `git diff --check`.
