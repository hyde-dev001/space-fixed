# User-Side Header Safety and Transitions Design

## Goal

Keep the shared user-side header from visually overlapping page content and add a restrained luxury transition between Inertia page visits.

## Scope

All listed user-side routes use the shared navigation directly or in their desktop shell. The Home/Landing route remains the only route with a transparent header over the hero. All other routes use an opaque, fixed-height header surface so controls cannot show through the header while scrolling.

## Design

- The promo ticker remains in document flow at the top of every shared-navigation page.
- The navigation bar always has an explicit height. On Landing it is transparent and overlays the hero; on non-landing routes it is white with a subtle border and blur.
- Existing page padding, route handlers, forms, checkout/payment flows, and page-specific mobile headers remain unchanged. The shared opaque surface prevents visual/header stacking overlap without rewriting every page.
- Inertia lifecycle events add a short fade-and-rise animation to the app surface on successful navigation. Animation duration stays under 300ms and is disabled for `prefers-reduced-motion`.

## Acceptance Criteria

1. Product breadcrumbs, sort controls, and page content never render visibly through the desktop user-side header.
2. Landing keeps its hero-overlay header without recreating a top spacer.
3. The shared navigation has an explicit desktop/mobile height instead of a zero-height background container.
4. Navigation between Inertia pages receives a subtle transition with no new dependency and reduced-motion support.
5. Existing contract tests cover the shared header mode and the global transition hook.
