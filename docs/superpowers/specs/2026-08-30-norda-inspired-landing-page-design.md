# Norda-Inspired SoleSpace Landing Page Redesign

## Goal

Replace the current landing-page content from the statistics band through the `READY TO STEP INTO STYLE?` CTA with a SoleSpace-owned storefront flow inspired by the structure, proportions, and scroll-reveal feel of [nordarun.com](https://nordarun.com/), while keeping the rest of the application unchanged.

## Scope and non-goals

### In scope

- The customer landing page rendered by `resources/js/Pages/UserSide/Products/LandingPage.tsx`.
- Reusing the existing `products` prop and the local `/images/shop/p1.jpg` through `/images/shop/p4.jpg` assets.
- Replacing the statistics, features, featured-products, services, and current CTA sections with the new landing flow.
- Landing-page-only scroll reveal, stagger, image scale, responsive layout, accessibility, and reduced-motion behavior.
- A landing-specific frontend contract test and durable design/implementation documentation.

### Out of scope

- The existing hero section, `Navigation`, footer, product pages, repair pages, services pages, routes, database queries, and shared/global CSS.
- New dependencies, external image URLs, copied Norda brand assets, or copied Norda marketing copy.
- Changes to the controller's existing Inertia payload. Extra `stats` data may remain server-side for compatibility, but the redesigned page will not render the old statistics band.

## Design direction

The page should feel like a refined footwear storefront: generous whitespace, oversized black typography, quiet borders, image-led sections, and a strong editorial rhythm. Use the existing landing container convention (`max-w-screen-2xl` with responsive horizontal padding) so the page aligns with the current hero and navigation.

The reference's flow is adapted as follows:

1. `New releases` — a product rail driven by the existing product data.
2. `Shop by category` — three image tiles for Shoes, Repairs, and Services.
3. A full-width SoleSpace story banner.
4. Three benefit columns explaining the SoleSpace value proposition.
5. A black community CTA that replaces `READY TO STEP INTO STYLE?`.

The wording remains SoleSpace-owned and should not reproduce Norda's copy. Product and category links must use existing named routes: `products`, `repair`, `services`, and `products.show`.

## Section behavior

### New releases

- Use a large left-aligned `New releases` heading and a compact right-side link to the complete product catalog on desktop.
- Render the provided products in a horizontal, snap-friendly rail on small screens and a three-card desktop layout with intentional right-side breathing room.
- Each card keeps the existing product name, price, optional comparison price, stock state, and product-detail link.
- Use the product's `main_image` as the primary image, with fixed aspect ratio and lazy loading below the hero.
- Keep cards visually quiet: square image surface, minimal metadata, and a circular/arrow affordance on hover or focus.
- When there are no products, render a stable empty state inside the rail without changing the section height unexpectedly.

### Shop by category

- Use a large `Shop by category` heading with three equal image tiles on desktop.
- Use the local shop images as follows: `p1.jpg` for Shoes, `p2.jpg` for Repairs, and `p3.jpg` for Services.
- Each tile is a full clickable link with a bottom gradient, readable title, and visible arrow affordance.
- Stack the tiles on mobile to preserve readable text and avoid cramped tap targets.

### Story banner

- Use `p4.jpg` as a full-width image-led story panel with a dark overlay.
- Place a short SoleSpace eyebrow, large headline, supporting copy, and a single discovery CTA within the same spatial hierarchy as the reference.
- The copy should position SoleSpace as a place where people can find, maintain, and keep wearing the right pair.

### Benefits

- Use three equal columns on desktop and a readable stacked layout on mobile.
- Use inline SVG icons consistent with the current landing page rather than emoji or a new icon dependency.
- Recommended headings: `Curated footwear`, `Expert repairs`, and `One space for every step`.
- Keep the descriptions short enough to preserve the generous white-space rhythm.

### Community CTA

- Replace the current `READY TO STEP INTO STYLE?` section with a black, full-width community block.
- Use a split desktop layout: oversized white headline and CTAs on the left, an existing SoleSpace image on the right; stack these on mobile.
- Use the existing `products` and `repair` routes for the primary actions.
- The headline must be SoleSpace-owned, for example `STEP INTO SOLESPACE`, and must not copy the reference site's slogan.

## Motion and interaction

Reuse the page's existing root-scoped `IntersectionObserver` reveal system rather than adding Framer Motion, GSAP, or a global scroll handler.

- Trigger each reveal when roughly 16% of the element enters the viewport and unobserve it after the first reveal.
- Animate only `opacity` and `transform` for scroll transitions.
- Reveal section headings and supporting copy with a short upward movement.
- Stagger product cards and category tiles by approximately 80–100ms, capped so a long list cannot create a slow entrance.
- Scale story/category images subtly from about `1.04` to `1` while their content reveals.
- Reveal the final CTA headline from a slight vertical offset and keep buttons immediately interactive.
- Keep hover/focus feedback at the existing short interaction scale, with visible keyboard focus rings and no hover-only functionality.
- Disable movement and transition delays under `prefers-reduced-motion: reduce`; content must render fully visible.
- Do not animate width, height, top, or left, and do not introduce a per-frame `scroll` listener.

## Data and component boundary

- The page continues to accept the current `products` prop and existing product fields.
- The old client-side stats state, counters, timers, and stats-section markup become unnecessary and should be removed from `LandingPage.tsx` when the replacement is implemented.
- The product rail and category cards may be represented by small local arrays inside `LandingPage.tsx`; no new shared component is needed for a one-page change.
- Keep `Navigation mobileMenuTriggerIcon="hamburger" landingSidebar`, hero loader handoff classes, current named routes, and footer markup intact.
- Runtime changes are limited to `resources/js/Pages/UserSide/Products/LandingPage.tsx`. The only additional source change is a landing-specific frontend test under the same Products page area.

## Accessibility and performance

- Use sequential headings and meaningful `alt` text for every non-decorative image.
- Make the entire product/category tile clickable and keep interactive targets at least 44px on touch layouts.
- Preserve keyboard navigation and visible `focus-visible` states.
- Add `loading="lazy"`, `decoding="async"`, and responsive `sizes` to below-the-fold images; keep only the first hero image eager.
- Reserve image space with aspect-ratio or fixed layout boxes to avoid layout shift.
- Ensure only the intentional product rail can scroll horizontally; the document itself must not overflow horizontally.

## Verification contract

The redesign is accepted when:

1. The current hero, navigation, product detail links, repair links, services links, and footer continue to render.
2. The statistics band and old sections are gone from the landing page and replaced by the five sections above.
3. Product data and local SoleSpace images are used; no Norda image URL or copied slogan is introduced.
4. Desktop proportions resemble the reference rhythm: large headings, three-card/three-tile rows, generous gutters, and a split final CTA.
5. Mobile has a usable product snap rail, stacked image sections, no document-level horizontal scroll, and 44px-friendly controls.
6. Scroll reveal, stagger, scale transitions, and reduced-motion behavior are scoped to the landing page.
7. The landing-specific frontend test, `pnpm run test:frontend`, `pnpm run build`, and `git diff --check` provide fresh verification evidence.
8. Browser verification confirms the landing page at desktop and mobile widths, including scroll-triggered sections, links, and overflow behavior.
