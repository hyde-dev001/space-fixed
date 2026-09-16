# Adaptive landing navigation icon colors

## Outcome

Keep the landing page's transparent fixed navigation readable as it crosses
light, dark, and image-backed content while preserving the existing header
layout and background behavior.

## Approved approach

Apply CSS `mix-blend-mode: difference` to the navigation SVG artwork only on
the landing page. The icon source color remains white, so the browser blends
it against the pixels behind it: dark content produces a light icon and light
content produces a dark icon. Buttons remain responsible for interaction and
badges remain outside the blend effect so their red status color is preserved.

The shared `Navigation` component will provide the adaptive class to the
hamburger, search, cart, and message SVGs. `NotificationBell` will accept the
smallest prop needed to apply the same class to its Bell SVG without blending
its unread badge. Non-landing navigation keeps its current colors and hover
behavior.

## Acceptance criteria

- Landing hamburger, search, cart, notification bell, and message icons remain
  visually readable over both light and dark content while scrolling.
- Header background, positioning, menu behavior, search behavior, and cart
  behavior are unchanged.
- Notification, cart, and message badges retain their red appearance.
- Shared navigation on non-landing pages keeps its existing color behavior.
- The focused navigation contract test and production frontend build pass.

## Verification

- Run the focused `Navigation.contract.test.ts` test.
- Run `pnpm run build`.
- Use browser verification to inspect the landing page before and after a small
  scroll, including the icon colors over hero and light content.
