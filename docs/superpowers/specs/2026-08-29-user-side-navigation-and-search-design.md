# User-Side Navigation, Cart Drawer, and Search Design

**Date:** 2026-08-29
**Status:** Approved for implementation

## Context

The landing page already has a left hamburger drawer and a right-side bag drawer, but the bag is conditionally mounted without an exit transition. Other user-side pages use a different header and mobile menu. The shared search endpoint also returns no products for an empty query, so the search overlay cannot show suggestions immediately when it opens.

## Goals

- Use the existing hamburger-style left drawer across all pages that belong to the customer/user side.
- Keep the drawer and bag animations spatially consistent and smooth.
- Remove `Home` and the bottom `Search` link from the left drawer.
- Make the header search icon open the same search overlay on every user-side page.
- Show product suggestions immediately when the search overlay opens, then show debounced query results as the customer types.
- Add the shared navigation to user-side page wrappers that currently do not mount it.

## Non-goals

- Do not replace the shared navigation with a new global layout or migrate unrelated ERP navigation.
- Do not add a search dependency or change the existing product/shop search contract beyond the empty-query product suggestions and product price fields needed by the UI.
- Do not change cart pricing, checkout behavior, authentication, or search ranking for non-empty queries.

## Design

### Shared user-side navigation

`resources/js/Pages/UserSide/Shared/Navigation.tsx` remains the single navigation implementation. Its drawer mode becomes the default for existing `<Navigation />` usages, while the explicit prop remains compatible with the landing page.

The drawer keeps the existing visual language: a white left panel, a dimmed page overlay, a 300ms `translateX` transition, and an Escape/outside-click close path. The current auth-aware navigation items remain available, except that `Home` is removed from the primary list and the bottom `Search` link is removed. The wordmark remains a link to the landing page.

The right-side bag drawer is always present in the drawer-mode markup so it can animate both directions. Its panel uses `translateX` from the right, while its overlay fades in and out. Closed states disable pointer interaction. Existing bag loading, item display, totals, and checkout links are preserved.

### Search overlay

The search icon in both desktop and mobile controls opens a centered search dialog. The dialog keeps the reference visual treatment: a compact search field and close button at the top, followed by a scrollable product grid with image, product name, shop name, and price.

When opened with an empty query, the client requests the existing suggestions endpoint with an empty query. The endpoint returns a small set of active products belonging to approved shops, ordered by newest first. This is presented as the initial product suggestion set. Query input remains debounced and uses the existing abort-controller flow for non-empty searches.

Search result links close the overlay before navigating. Existing shop suggestion behavior for non-empty queries remains available where the non-drawer fallback is used.

### User-side page coverage

Existing user-side pages that already render `Navigation` inherit drawer mode automatically. The shared navigation is added to the user-side articles page, virtual showroom page wrapper, and customer notification page wrappers so those entry points receive the same controls. The embedded showroom component and other non-page reusable components are not given their own navigation instance.

### Accessibility and motion

- Drawer, bag, search, and close controls retain explicit accessible labels.
- Overlay clicks and Escape close open surfaces.
- Body scrolling remains locked while the left drawer is open.
- Transitions include reduced-motion handling through the existing Tailwind motion utilities or equivalent CSS behavior.
- Product images use the product name as alternative text; missing images keep a visible fallback.

## Data flow

1. A user-side page renders the shared `Navigation`.
2. The menu trigger toggles the left drawer state; the drawer and overlay animate from their closed transforms/opacities.
3. The bag trigger toggles the cart drawer and loads the existing local or authenticated cart data.
4. The search trigger opens the search dialog and causes an empty-query suggestion request.
5. Search text changes reuse the current 220ms debounce and `/api/search/suggestions` endpoint.
6. The endpoint maps active, approved-shop products into the existing suggestion shape plus price data.

## Expected files and verification

Likely implementation files:

- `resources/js/Pages/UserSide/Shared/Navigation.tsx`
- `resources/js/Pages/UserSide/Shared/navigationItems.ts`
- `app/Http/Controllers/UserSide/LandingPageController.php`
- user-side page wrappers that currently lack the shared navigation
- focused frontend and feature tests for navigation items, search suggestions, and rendered drawer states

Acceptance criteria:

1. Opening and closing the left drawer animates smoothly on every user-side page using the shared navigation.
2. Opening and closing the bag uses a smooth right-side slide and overlay fade, including the close animation.
3. `Home` and the bottom `Search` item are absent from the drawer.
4. The search icon opens the reference-style product search panel on desktop and mobile.
5. The panel shows product suggestions without requiring an initial search character.
6. Typing still returns debounced matching product/shop suggestions, and selecting a result navigates normally.
7. Reduced-motion users are not forced to watch the transitions.
8. Existing unrelated working-tree changes remain untouched.

Verification will use the focused Vitest tests, the relevant Laravel feature test, `pnpm run build`, `pnpm run test:frontend`, `composer test` when the backend test is added, and `git diff --check`.
