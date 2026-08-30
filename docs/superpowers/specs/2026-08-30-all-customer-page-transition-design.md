# All Customer Page Transition Design

## Goal

Extend the existing fast shared page-transition curtain to every listed customer-facing page that does not currently receive it, while keeping the same visual behavior and excluding shop-owner operations, ERP, super-admin, APIs, and non-page downloads.

## Scope

The transition policy will cover these customer-facing page paths:

- `/`, `/products`, `/products/{slug}`
- `/repair-services`, `/repair-shop/{id}`, `/repair-process`
- `/shop-profile/{id}`, `/shop-profile/{id}/virtual-showroom`
- `/services`, `/articles`, `/download`
- `/checkout`, `/payment`, `/order-success`, `/payment-failed`
- `/my-orders`, `/my-repairs`, `/customer-profile`
- `/messages`, `/message/{shopOwnerId?}`, `/customer/conversations`
- `/tracking/shipments/{shipment}` and its proof/attempt detail paths
- `/notifications`, `/notifications/settings`
- `/login`, `/register`, `/forgot-password`, `/otp`, `/new-password`, `/email/verify`
- `/shop-owner-register`, `/shop-owner/two-factor`

The exact list follows the existing customer-facing Inertia routes and page components. Dynamic identifiers are matched only at their intended path depth. The signed `/email/verify/{id}/{hash}` action, POST endpoints, API endpoints, APK binary route, and other non-page download actions are excluded.

The transition remains excluded from every `/erp/*`, `/admin/*`, shop-owner operational page, privileged auth page, and unrelated internal route. Existing landing footer reveal behavior and previously completed navigation labels remain unchanged.

## User experience

The existing `CustomerPageTransition` remains the only visual layer. On an eligible Inertia navigation between two covered customer paths, it shows a fixed white curtain with centered black `SOLESPACE`, then exits quickly after the visit finishes. It also closes on success, cancellation, or error and has a bounded fallback so it cannot remain blocking.

Initial document load continues to use the existing server-rendered app loader only. Same-path query/hash changes, external links, browser downloads, API calls, and non-Inertia navigations do not show the curtain. The effect does not lock scrolling, trap focus, or move focus. Reduced-motion users receive an immediate or near-immediate state change without the sliding movement.

## Architecture

Expand the existing pure route policy in `resources/js/utils/customerPageTransition.ts`; do not add page-specific wrappers or duplicate animation code. The policy parses URL pathnames, ignores query/hash values, supports the documented dynamic route patterns, and returns true only when source and destination are distinct covered customer page paths.

Keep the shared component mounted once in `resources/js/app.jsx`. It continues to subscribe to Inertia `start`, `finish`, `error`, and `cancel` events, accepts both string and `URL` visit targets, and cleans up its listeners and fallback timer on unmount. The route expansion must not alter provider boundaries or initial-loader behavior.

## Performance and accessibility

- Add no dependency, request, image, font, or page-level import.
- Use only opacity, transform, visibility, and pointer-events for the curtain state.
- Keep visual transition timing under 400ms and fallback closure bounded.
- Avoid scroll listeners, animation-frame React state, and duplicated page wrappers.
- Keep the curtain decorative with `aria-hidden="true"`; do not trap or move focus.
- Respect `prefers-reduced-motion: reduce`.

## Verification contract

Extend focused route-policy tests for every static and dynamic route group, plus excluded ERP/admin/API/download paths and same-path query/hash changes. Keep lifecycle tests for start, finish, error, cancel, URL-object targets, fallback closure, and cleanup.

Run the full frontend suite and a fresh production Vite build. Use browser smoke checks across representative landing, product, repair, profile/showroom, checkout/payment, order/repair history, messaging/tracking, notification, and auth routes. Confirm the curtain appears during eligible customer navigation, remains brief, does not cause overflow or scroll lock, and never appears on ERP/admin pages.

Likely changed source files are `resources/js/utils/customerPageTransition.ts`, its focused test, and only if needed the existing transition component/test. `resources/js/app.jsx` and `resources/css/app.css` should remain unchanged unless the audit finds a direct integration correction. Refresh `public/build` after the implementation. Do not modify backend routes or unrelated working-tree files.
