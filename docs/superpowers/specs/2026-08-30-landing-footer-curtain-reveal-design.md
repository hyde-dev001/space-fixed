# SoleSpace Landing Footer Curtain Reveal Design

## Goal

Create a smooth footer reveal on the SoleSpace landing page in which the foreground landing content scrolls upward like a curtain and exposes a stationary footer underneath. The interaction should match the layered rhythm of the live [Axel Arigato storefront](https://axelarigato.com/) while retaining SoleSpace branding, content, routes, and responsive footer UI.

## Scope

- Change only the landing page rendered by `resources/js/Pages/UserSide/Products/LandingPage.tsx`, its focused layout contract test, design/plan documentation, and generated `public/build` output.
- Preserve all landing sections, navigation behavior, footer copy, footer links, mobile disclosure groups, and oversized `SOLESPACE` wordmark.
- Do not change global layouts, shared navigation, backend routes, product pages, repair pages, services pages, or ERP pages.
- Do not add a third-party animation dependency.

## Current behavior and problem

The current implementation uses a sticky footer inside a bounded stage with responsive negative top margins. This creates a static overlap between the final landing section and the footer, but the footer still participates in the stage's normal document flow. It therefore lacks the stronger curtain illusion in which the footer remains visually anchored while the foreground page edge moves upward across it.

## Approved approach

Use a fixed footer beneath an opaque foreground curtain, with a dynamically measured transparent spacer at the end of the document. Native scrolling moves the curtain; the footer itself does not need a per-frame scroll transform.

### Page layers

The landing page will have three sibling layers inside one landing-only root:

1. `landing-curtain`: contains the existing navigation and all landing sections. It is opaque, positioned above the footer, and retains the existing section order and scroll-reveal animations.
2. `footer-curtain-spacer`: a transparent document-flow spacer whose height matches the rendered footer. Scrolling through this spacer moves the bottom edge of the curtain upward and exposes the fixed footer.
3. `landing-footer`: the existing approved footer UI, fixed to the viewport bottom beneath the curtain.

The foreground curtain uses a higher stacking level than the footer. At initial load it covers the fixed footer completely. When the spacer reaches the viewport, the curtain's bottom edge moves upward through ordinary document scrolling while the footer stays in place, producing the parallax-like reveal.

### Dynamic footer height

- Attach a `ResizeObserver` to the footer and write its measured pixel height to a landing-root CSS custom property named `--landing-footer-height`.
- Make the transparent spacer consume that custom property so its scroll distance always equals the current footer height.
- Re-measure when the viewport changes or a mobile `details` group changes the footer height; `ResizeObserver` handles both without a scroll listener.
- Provide responsive CSS fallback heights for the first render and for browsers without `ResizeObserver`, preventing a zero-height reveal or large layout jump.
- Remove the current negative-margin overlap and compensating footer top padding because the curtain/spacer relationship replaces both.

### Footer visibility and interaction

- Observe the reveal spacer with `IntersectionObserver`.
- Before the spacer reaches the viewport, keep the hidden fixed footer non-interactive with `inert`, `aria-hidden`, and disabled pointer events so keyboard focus cannot move behind the curtain.
- Enable footer interaction when the reveal spacer intersects the viewport and keep it enabled through the final frame.
- If `IntersectionObserver` is unavailable, enable the footer as an accessibility-first fallback rather than leaving its links unreachable.
- Clean up both observers and any inline CSS custom property during component unmount.

### Motion behavior

- Do not add a `scroll` event listener, `requestAnimationFrame` loop, or scroll-progress React state.
- The perceived animation comes from the foreground curtain moving with native document scroll while the fixed footer remains stationary.
- Desktop and mobile use the same layered reveal.
- Mobile receives no additional transform or depth motion, keeping the interaction gentler and reducing paint work.
- Existing `prefers-reduced-motion` behavior remains intact. Because the curtain reveal is direct scrolling rather than a timed animation, reduced-motion mode keeps the same page geometry without an additional animated transform.

### Responsive behavior

- Preserve the desktop four-column footer navigation.
- Preserve the mobile native `details`/`summary` groups, closed by default.
- Allow the mobile footer to scroll internally only when expanded content exceeds `100svh`, preventing controls from becoming unreachable on short screens.
- Keep the oversized wordmark clipped inside the footer and prevent horizontal page overflow.
- Use the measured footer height at every viewport width instead of duplicating hard-coded spacer values across breakpoints.

## Accessibility

- Footer links and disclosures must not enter the tab order while hidden beneath the curtain.
- When the reveal begins, links and disclosure controls become available to keyboard and pointer input.
- Existing focus-visible styles and minimum 44px disclosure targets remain unchanged.
- Opening a mobile disclosure must update the spacer height without hiding the focused control or introducing horizontal overflow.
- Reduced-motion users receive the same readable content and direct-scroll reveal without extra interpolation.

## Failure handling and progressive enhancement

- `ResizeObserver` unavailable: retain a responsive CSS fallback spacer height and keep the footer usable.
- `IntersectionObserver` unavailable: enable footer interaction immediately while preserving visual stacking.
- Observer callbacks must stop after unmount and must not update detached elements.
- No network request or backend state is involved in the interaction.

## Acceptance criteria

1. At initial page load, the footer is fixed beneath the landing curtain and is not visible or focusable.
2. Near the bottom of the landing page, the curtain edge scrolls upward while the footer remains at a stable viewport position, revealing it progressively.
3. At the final scroll position, the complete collapsed footer UI is revealed and interactive; expanded mobile content remains reachable through internal scrolling on short viewports.
4. The current negative-margin overlap and compensating footer top padding are removed.
5. The spacer automatically matches the footer's rendered height, including after a mobile disclosure opens.
6. Desktop and mobile both receive the effect; mobile has no additional parallax transform.
7. Mobile disclosures remain keyboard- and touch-operable, including on short viewports.
8. No horizontal overflow, per-frame scroll listener, new dependency, or non-landing source change is introduced.
9. Existing landing sections, routes, reveal animations, footer copy, and wordmark UI remain intact.

## Verification

- Add a failing focused source contract before implementation.
- Run `node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`.
- Use Playwright at desktop and mobile widths to verify initial concealment, stable footer geometry, progressive curtain exposure, interaction gating, disclosure resizing, reduced motion, and horizontal overflow.
- Run the full frontend suite with `node_modules/.bin/vitest.cmd run`.
- Generate a fresh production build with `node_modules/.bin/vite.cmd build` and include `public/build`.
- Run `git diff --check` and confirm the committed source scope remains landing-only.
