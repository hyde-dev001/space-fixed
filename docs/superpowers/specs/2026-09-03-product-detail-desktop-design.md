# Product Detail Desktop Redesign

## Outcome

Redesign only the desktop presentation of the customer product-detail page at
`/products/{slug}` so its layout and spacing follow the supplied BOY London
product-page reference. Preserve the existing mobile and tablet UI, interactions,
and purchase flow.

## Scope

- Change only `UserSide/Products/ProductShow` and the product-detail payload or
  focused tests required by that page.
- Apply the new presentation only at Tailwind's existing `xl` breakpoint
  (`1280px` and wider).
- Keep the shared customer navigation and every other storefront page unchanged.
- Preserve the current size-selection values and behavior, Size Guide modal,
  quantity controls, Add to Cart action, Buy Now action, voucher claims, review
  submission, review filtering, and review pagination.
- Add desktop-only `You May Also Like` and `Recently Viewed Items` sections after
  the complete comments/reviews section.
- Produce and commit a fresh `public/build` only after the final rebase, as
  required by `docs/git-workflow.md`.

## Design Direction

Use the supplied BOY London product page as the layout reference and the supplied
root `DESIGN.md` as the visual-system constraint. The resulting desktop page is
photography-first, flat, monochrome, and spacious:

- white canvas and near-black primary text/actions;
- soft neutral product-image stage;
- no decorative gradients or elevated product cards;
- restrained 1px dividers for structure;
- pill-shaped primary and secondary purchase actions;
- an 8px-derived spacing rhythm with larger section gaps;
- color reserved for existing semantic states such as sale, stock, and review
  ratings.

The implementation should reproduce the reference's composition and spacing,
not copy BOY London branding, text, assets, or checkout integrations.

## Desktop Layout

At `xl` and wider, use a centered commerce canvas with a maximum width around
`1440px` and adaptive outer gutters. The product hero is a two-column grid:

1. A dominant gallery column occupying roughly three-fifths of the content
   width. It keeps the existing image source, image-switching animation, zoom,
   thumbnails, previous/next controls, and optional 360-degree entry point.
2. A compact details column occupying the remaining width. Its top aligns with
   the gallery and may remain sticky while the gallery area is visible, provided
   it does not create a nested scroll region.

The details column order is:

1. product name;
2. brand when it differs from the product name;
3. current and compare-at price;
4. tax/shipping helper copy and existing views/sold metadata;
5. color choices;
6. the existing size selector and Size Guide trigger;
7. quantity and stock availability;
8. the existing Add to Cart and Buy Now controls;
9. seller link;
10. flat disclosure rows for Product Details, Returns Policy, and Shipping.

The Product Details row exposes the existing description and category. Returns
Policy and Shipping use concise storefront guidance without inventing guarantees,
fees, or delivery dates. Disclosure triggers must expose `aria-expanded`, preserve
keyboard operation, and provide a visible focus state.

## Mobile and Tablet Isolation

Widths below `1280px` are a protected surface:

- retain the current top bar, gallery, thumbnails, product information order,
  bottom purchase bar, modals, vouchers, and reviews;
- do not add recommendation rails at these widths;
- do not change base classes when an equivalent `xl:` override can express the
  desktop design;
- when desktop composition needs materially different markup, gate that markup
  with `hidden xl:block` and keep the existing mobile/tablet markup under
  `xl:hidden` while sharing the same state and event handlers.

Focused source-contract tests and browser checks at mobile and tablet widths must
confirm this isolation.

## You May Also Like

The server supplies a small desktop recommendation collection for the current
product. Selection rules are deterministic:

1. include only active products owned by approved shops;
2. exclude the current product;
3. prioritize products sharing the current category;
4. then prioritize products sharing the current brand;
5. fill any remaining slots with the newest eligible products;
6. return at most eight unique products.

Each card contains the existing product image accessor output, product name,
brand or category context, current price, optional struck-through compare-at
price, and a link to the canonical product-detail route. Cards use the flat
product-card treatment from `DESIGN.md` and reserve image space to avoid layout
shift.

If no eligible products exist, omit the section instead of showing fabricated
content.

## Recently Viewed Items

Recently viewed history is browser-local and desktop-only. No authentication or
database migration is required.

- Store a compact, versioned product-card record in `localStorage` after the
  product page mounts in a browser.
- Deduplicate by product ID, place the latest visit first, exclude the current
  product from the rendered rail, and retain at most eight records.
- Treat unavailable, malformed, or blocked storage as an empty list without
  interrupting the page.
- Render the same accessible product-card presentation used by recommendations.
- Omit the section when no previous product exists.

## Data and Component Boundaries

- `LandingPageController::productShow` remains responsible for authoritative
  product data and adds the recommendation payload.
- `ProductShow.tsx` remains responsible for existing product interaction state,
  desktop disclosures, and browser-local recent history.
- A small focused product-rail component may be extracted beside ProductShow if
  it prevents duplicated card markup; no generalized storefront framework or new
  dependency is needed.
- Existing cart and size helpers remain the source of truth. Recommendation cards
  navigate to product details and do not add a second quick-add flow.

## Error and Empty States

- A missing current product continues to use the existing Laravel 404 behavior.
- Recommendation lookup failure must not block the main product page.
- Missing card images use the existing neutral image surface or current product
  fallback convention.
- Invalid local recent-history data is discarded safely.
- Empty recommendation or recent-history collections render no heading or blank
  rail.

## Accessibility and Interaction

- Preserve semantic buttons and links.
- Keep all desktop interactive targets at least 44px in effective size.
- Provide descriptive image alt text and labels for icon-only controls.
- Keep visible keyboard focus states and logical DOM order.
- Preserve reduced-motion behavior for any existing image transitions; add no
  decorative motion.
- Use a single primary visual action per group: Add to Cart is solid black and
  Buy Now is the outlined secondary action, while their existing behavior remains
  unchanged.

## Verification

The implementation is accepted when fresh evidence shows:

- focused Laravel coverage verifies recommendation eligibility, exclusion,
  ordering, uniqueness, limit, and product payload shape;
- focused frontend coverage verifies desktop-only layout markers, disclosure
  accessibility, recent-history deduplication/error handling, and preserved size
  selection/cart control contracts;
- browser checks at desktop confirm the BOY London-style two-column composition,
  spacing, disclosures, and both product rails;
- browser checks below `1280px` confirm the prior mobile and tablet presentation
  and behavior remain intact;
- `pnpm run test:frontend`, relevant Laravel tests, `pnpm run build`, and
  `git diff --check` pass;
- the final commit contains only intended source/tests/docs plus the fresh
  `public/build` output.

## Out of Scope

- Redesigning `/products`, navigation, checkout, cart, Size Guide content,
  vouchers, comments/review behavior, or any shop-owner/ERP surface.
- Changing mobile or tablet presentation.
- Adding personalization services, analytics tracking, a recommendation engine,
  new tables, new third-party packages, or BOY London assets/content.
