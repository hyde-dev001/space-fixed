# Customer Page Transition Design

## Goal

Add a fast, shared page-transition curtain for customer storefront navigation. The effect should feel like the main page content is briefly covered by a white curtain, then revealed again after the next Inertia page is ready. It should echo the requested Axel Arigato-style transition without delaying normal navigation.

## Scope

The transition is enabled only for these customer storefront paths:

- `/`
- `/products`
- `/products/{slug}`
- `/my-orders`
- `/my-repairs`
- `/repair-services`
- `/services`

It is excluded from checkout, payment and order-result pages, customer profile, messaging, notifications, tracking, shop profiles, authentication, downloads, repair-shop detail pages, and every shop-owner, ERP, and super-admin route. The existing landing-page footer reveal remains unchanged; this feature concerns page-to-page navigation only.

The previously completed `Orders` → `My Orders` and `Repairs` → `My Repairs` label changes are not part of this feature.

## User experience

1. The initial page load keeps using the existing app loader and does not show the navigation curtain a second time.
2. When an eligible customer page starts an Inertia visit to another eligible customer page, a fixed white overlay becomes visible above the page content.
3. The overlay contains a centered black `SOLESPACE` wordmark rendered as text/CSS, with no image or network request.
4. After the visit finishes, succeeds, is cancelled, or errors, the overlay quickly fades/slides away to reveal the new page.
5. Same-document hash links, external links, downloads, form submissions, and non-Inertia browser navigations do not trigger the effect.
6. A navigation that starts on an excluded page does not trigger it. A visit to an excluded destination also does not trigger it, so ERP/admin screens never receive the curtain.
7. The effect never locks scrolling and does not take focus. When hidden, it has no pointer interaction and is removed from the accessibility tree.

The motion uses short compositor-friendly transitions: opacity and transform only, with a target total visual duration under 400ms. The overlay must never remain visible indefinitely; a bounded fallback timeout closes it if an event is interrupted.

## Architecture

Add one shared transition layer at the Inertia application root in `resources/js/app.jsx`, mounted once around the shared application rather than copied into individual pages. The layer subscribes to Inertia router lifecycle events and cleans up all subscriptions on unmount.

Use a small route classifier utility for the explicit customer path allowlist. The classifier must normalize the current and destination URLs using pathname values and handle the dynamic product slug without allowing unrelated paths. The transition should run only when both sides of the visit are eligible and the destination is not the current pathname plus query/hash equivalent.

The visual layer should use a single fixed DOM node with state represented by CSS classes or equivalent attributes. It should not animate by updating React state on every frame, use a scroll listener, or add a third-party animation dependency. Existing `appLoader` behavior remains responsible for first-load dismissal only.

## Accessibility and reduced motion

The curtain is decorative and uses `aria-hidden="true"`. It must not trap focus or move focus. Under `prefers-reduced-motion: reduce`, disable the animated movement and use the shortest possible visibility change while preserving the lifecycle behavior and preventing a flash of the underlying page.

## Performance and reliability

- No new dependency, request, image, font, or page-level bundle import.
- Use only `opacity`, `transform`, `visibility`, and `pointer-events` for the overlay transition.
- Subscribe to router events once and remove subscriptions during cleanup.
- Close on normal finish and error/cancel paths.
- Include a short maximum fallback timeout so a failed or interrupted visit cannot leave a blocking overlay.
- Keep the existing initial loader timing and styles independent from this transition.
- Do not change backend routes, page data, footer behavior, or ERP/admin layouts.

## Verification contract

Add focused tests for:

- the customer path allowlist, including product slugs and excluded paths;
- eligible-to-eligible navigation lifecycle and cleanup;
- excluded source/destination behavior;
- error/cancel/fallback closure;
- reduced-motion behavior where the test environment supports media-query mocking.

Run browser verification against the local app when available: navigate between `/`, `/products`, and `/services`; confirm the curtain is visible only during navigation, remains brief, does not create horizontal overflow, and does not appear on an ERP route. Check a narrow viewport and reduced-motion preference.

Likely implementation files are `resources/js/app.jsx`, a small shared transition component or utility under `resources/js`, its focused tests, the related CSS in `resources/css/app.css`, and the generated `public/build` output after the implementation build. No existing landing footer files should be modified for this feature.
