# Axela-Inspired SoleSpace Landing Footer Design

## Goal

Adapt the current SoleSpace landing-page footer to the interaction pattern observed on the live [Axel Arigato storefront](https://axelarigato.com/): a sticky footer that sits beneath the scrolling page and is revealed as the visitor reaches the end, with a large clipped wordmark anchoring the final frame.

## Scope

- Change the footer rendered by `resources/js/Pages/UserSide/Products/LandingPage.tsx` only.
- Keep the existing global navigation, product flow, repair flow, services flow, and non-landing pages unchanged.
- Use SoleSpace copy, routes, colors, and existing local assets; do not copy Axel Arigato brand text, assets, or implementation code.
- Keep the current landing-page section order and existing scroll-reveal behavior outside the footer.

## Reference observations

The reference footer currently uses a pale yellow panel with a four-column desktop grid. SoleSpace keeps that structure but uses a white panel so the footer matches the landing page's white storefront sections. The first column is a brand label, followed by compact uppercase link groups. A second row carries copyright, shipping, and language controls, and an oversized brand wordmark is clipped at the bottom edge. The footer is `position: sticky` inside a footer-only reveal stage, so it stays below the landing content at page start and can underlap the final viewport as the visitor reaches the end. At mobile widths, the link groups collapse into compact expandable headings rather than showing every link at once.

## Design

### Desktop layout

- Render the footer on desktop and mobile instead of hiding it below the existing `md` breakpoint.
- Give the footer a white background of `#ffffff` with black text so it matches the landing page palette.
- Use a four-column grid: SoleSpace label, Explore, Support, and Community.
- Keep labels and links uppercase, compact, and left aligned to match the reference rhythm.
- Add a metadata row for copyright, shipping region, and language.
- Finish with a large `SOLESPACE` wordmark whose width intentionally exceeds the viewport so the lower edge clips the text.

### Scroll interaction

- Make the footer a sticky, bottom-anchored layer inside a bounded reveal stage after the preceding landing sections; it must begin outside the initial viewport.
- Keep the community section and footer content in explicit stacking layers so the content scrolls over the footer and the footer is revealed only near the end of the page.
- Keep the oversized wordmark anchored in the footer; the reveal animation comes from the sticky footer layer being exposed as the page content scrolls over it.
- Use native CSS sticky positioning and stacking order for the underlap interaction instead of a page-level scroll-progress listener.
- Keep the footer layer at `z-0` with foreground landing content at `z-10` so the Inertia app shell does not block footer links and mobile disclosures.
- Honor `prefers-reduced-motion: reduce` for the landing page’s existing reveal and control transitions; the footer wordmark itself has no motion transform to disable.

### Mobile layout

- Use one-column footer content with compact expandable groups implemented with native `details`/`summary` semantics.
- Keep groups closed by default to match the reference density; the brand label, copyright, shipping, and language rows remain visible.
- Retain the oversized wordmark, reducing its type size and overflow so it remains legible without causing horizontal page overflow.
- Preserve visible focus states and touch targets of at least 44px for interactive controls.

### Content and integration

- Link Explore actions to existing `products`, `repair`, and `services` routes.
- Use the landing page’s own `#landing-community` anchor for the community entry point where appropriate.
- Limit functional links to existing product, repair, services, and landing-page destinations; do not add backend routes for this visual update.
- Omit external social links unless an existing SoleSpace account URL is already present; do not invent brand accounts.

## Acceptance criteria

1. The landing page footer visually uses a white panel, compact uppercase link groups, metadata row, and oversized clipped `SOLESPACE` wordmark.
2. On desktop, the footer starts below the landing content, remains visually underneath it while scrolling, and is revealed in the final footer stage through the native sticky underlap interaction, with the wordmark anchored in place.
3. On mobile, the footer is present, groups are collapsed by default, and each group can be opened with keyboard and touch input.
4. No horizontal overflow is introduced at desktop or mobile widths.
5. Reduced-motion mode renders the footer and wordmark without motion while keeping all content available.
6. Existing landing routes and sections remain intact, and no non-landing source file is modified.
7. A focused landing layout test covers the new footer markers and safeguards the landing-only scope.

## Verification

- Focused contract test: `node_modules/.bin/vitest.cmd run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`
- Full frontend suite: `node_modules/.bin/vitest.cmd run`
- Production build: `node_modules/.bin/vite.cmd build`
- Browser checks at desktop and mobile widths for sticky reveal, accordion interaction, reduced motion, and horizontal overflow.
- `git diff --check`
