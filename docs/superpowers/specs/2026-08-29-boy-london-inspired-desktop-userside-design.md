# BOY London-Inspired SoleSpace Desktop User-Side Design

## Goal

Refresh the SoleSpace customer-facing desktop experience with a monochrome, editorial fashion-commerce direction inspired by BOY London's information hierarchy and storefront chrome, while preserving all mobile/tablet presentation and all existing backend behavior.

## Scope and boundaries

- Target only desktop presentation at the existing `lg` breakpoint and above.
- Leave mobile and tablet layout, interaction behavior, and responsive breakpoints unchanged.
- Cover the shared user-side navigation plus storefront, catalog, product detail, shop/showroom, services/repairs, cart, checkout/payment, orders/tracking, profile/address, communication, and auth pages.
- Do not change Laravel routes, controllers, models, validation, authorization, API payloads, payment logic, cart persistence, order state transitions, repair workflows, or Inertia prop contracts.
- Do not copy BOY London logos, copyrighted imagery, exact brand assets, or proprietary typography. Use SoleSpace identity and existing product imagery with a similar editorial structure.

## Design direction

The desktop user side will use a restrained black/white/soft-gray retail system with red reserved for sale/error signaling. The visual hierarchy is editorial: a thin promotional strip, compact header, bold uppercase section titles, large campaign imagery, edge-to-edge product imagery, and dense utility footer navigation.

### Desktop chrome

- Add a promotional announcement strip above the user-side header when the current page supports the shared storefront chrome.
- Rework the desktop navigation presentation around a centered SoleSpace wordmark, hamburger menu, category links, search, account, notification/message actions, and cart.
- Keep existing search suggestion behavior, authentication-aware badge counts, cart count, logout flow, and route destinations.
- Preserve keyboard focus, Escape-to-close, outside-click behavior, and scroll locking for drawers and dialogs.
- Use flat surfaces and hairline dividers as the default depth treatment. Avoid decorative gradients and large shadows in the desktop retail chrome.

### Storefront and catalog

- Treat the landing page as an editorial storefront: campaign hero, category discovery, featured products, service/repair discovery, and a utility footer.
- Treat product listing pages as a clean catalog: strong page title, compact filter/sort controls, consistent image stage, sale/new labels, shop metadata, and dense responsive desktop grid.
- Treat product detail pages as a split editorial layout with a dominant image/gallery area and a clear purchase panel. Preserve every existing variant, size, stock, 360-view, voucher, report, and cart action.
- Keep real product and shop data as the only source for content; no hardcoded replacement catalog data.

### Account and transaction pages

- Apply the same desktop header, typography, borders, buttons, and content width to profile, orders, tracking, communication, repairs, and auth pages.
- Preserve all forms, uploads, error messages, confirmation dialogs, status labels, refund evidence flows, address selection, shipping estimate behavior, payment redirects, and order actions.
- Use stronger information hierarchy for status, totals, deadlines, and next actions without changing their semantics or values.

## Design tokens

Use the existing `DESIGN.md` direction as the starting source of truth and scope additions to desktop user-side styles.

- Canvas: `#ffffff`
- Ink: `#111111`
- Soft surface: `#f5f5f5`
- Secondary text: `#707072`
- Hairline: `#e5e5e5`
- Sale/error: existing semantic red token or the current project error color
- Primary type: existing project sans font with uppercase display treatment for editorial headings
- Base rhythm: 8px spacing increments
- Motion: 150–300ms transitions, with `prefers-reduced-motion` support

No raw color values should be scattered across changed components when an existing project token or scoped CSS variable can express the same intent.

## Technical approach

Use one shared desktop presentation layer plus narrowly scoped page adjustments:

1. Refactor the desktop branch of `resources/js/Pages/UserSide/Shared/Navigation.tsx` while preserving its existing state, route, badge, search, cart, and authentication logic.
2. Add or extend scoped user-side desktop tokens and utility selectors in `resources/css/app.css`, guarded by `@media (min-width: 1024px)` and a user-side scope class.
3. Update desktop branches/classes in the UserSide pages listed above, reusing existing components and route helpers. Do not replace working backend-facing handlers with new data flows.
4. Keep all tablet/mobile classes and branches unchanged wherever possible; any shared markup change must be verified at widths below `1024px`.

## Failure and safety behavior

- If a product image is missing, retain the existing no-image fallback and reserve the image box to prevent layout shift.
- If search, cart, notifications, or location data is loading or unavailable, retain existing loading/error states and make the desktop presentation readable.
- If a route or prop is absent, render the existing fallback rather than inventing new backend assumptions.
- Do not conceal validation errors, stock limits, payment failures, refund restrictions, or authorization failures for visual reasons.

## Acceptance criteria

- All listed customer-facing desktop pages share a coherent SoleSpace editorial commerce system.
- Desktop header, drawers, search, account, notifications, messaging, cart, and logout interactions still use their current routes and state behavior.
- Product browsing, filtering, product details, variants, add-to-cart, checkout, payment, order tracking, repairs, profile, address management, and auth flows remain functionally intact.
- No Laravel/controller/API files are changed unless a verification issue proves one is required; any such change requires explicit review.
- Mobile and tablet screenshots and interaction checks show no intentional visual or behavioral change.
- Desktop checks pass at common widths including 1024px, 1280px, and 1440px without horizontal overflow.
- `git diff --check`, relevant frontend tests, frontend build, and relevant Laravel tests provide fresh verification evidence.

## Verification plan

- Run the focused existing UserSide frontend tests before and after implementation.
- Run `pnpm run build` and `git diff --check`.
- Run `composer test` if shared route or backend contract verification is affected; otherwise record the relevant Laravel test command and result.
- Use Playwright/browser verification at desktop widths for navigation, search, cart drawer, product listing/detail, checkout entry, profile, orders, and auth.
- Use Playwright/browser verification at representative mobile/tablet widths to confirm the protected presentation boundary.
