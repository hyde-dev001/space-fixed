# Shop Profile Desktop Carousel Layout Design

## Goal

Bring the desktop shop profile product experience in line with the existing mobile design by showing the product categories as landscape carousels and matching the Products page card proportions, without changing the shop profile data contract or existing product behavior.

## Approved experience

### Desktop content structure

The desktop shop profile will keep the existing cover and shop-information header, then present the retail content as separate sections in this order when products exist:

1. Recommended For You
2. Men
3. Women
4. Kids
5. Sports

Each non-empty section will have a clear heading, a `See More` control that selects that category, and a horizontal product rail. The rail will expose multiple cards at desktop widths while allowing horizontal scrolling when the available width is smaller than the content. The existing Services section remains the selected view for repair-only shops and remains available for retail-and-repair shops.

The existing category tabs and virtual-showroom link remain available for direct navigation. Selecting a category continues to show the existing filtered category view; the new rails are the default desktop browsing presentation and do not remove the filter behavior.

### Product cards

Desktop rail cards will use the same visual contract as the Products page catalog cards:

- square image area at desktop widths;
- consistent card width and equal-height information area;
- product image cycling on hover where additional images exist;
- sale and sold-out labels;
- product name, brand, price, compare-at price, and stock information;
- the same neutral border, radius, shadow, hover lift, focus treatment, and lazy image loading.

The shop profile will use one local reusable card/rail rendering path for its desktop product sections so Recommended, Men, Women, Kids, and Sports cannot drift into different sizes or markup. The current mobile rail remains behaviorally compatible and may reuse the same card styling only where it does not harm the mobile layout.

### Responsive behavior and accessibility

- Desktop rails use a fixed card width derived from the Products page card proportions, with a responsive number of visible cards rather than a forced page-wide grid.
- Native horizontal scrolling remains available by mouse, touch, trackpad, and keyboard focus; no scroll-jacking or custom drag dependency is introduced.
- Product cards remain links to the existing `/products/{slug}` route.
- `See More` controls remain buttons with visible focus states and select the existing category state.
- Meaningful product images retain product-name alt text; unavailable images retain the existing fallback.
- Image cycling and hover transforms continue to respect the existing reduced-motion classes.
- The rail remains clipped within the page container so it does not create an accidental viewport-level horizontal scrollbar.

## Data flow and boundaries

- Use the existing `products`, `repairServices`, `repairPackages`, `categories`, `filteredProducts`, and `getProductsByCategory` values in `ShopProfile.tsx`.
- Do not change controllers, routes, models, database queries, product filtering rules, search behavior, cart behavior, or virtual-showroom authorization.
- Do not add dependencies or create a second product API request.
- Keep repair packages/services rendering and existing mobile presentation intact unless a shared card helper requires a strictly equivalent markup adjustment.

## Empty and error states

- Omit a category rail when its filtered product list is empty, matching the current mobile behavior.
- Keep the existing “No products in this category” state when the selected category has no products.
- Keep the existing image error fallback to the main image and “No Image” placeholder.
- Keep current link destinations and action-menu behavior unchanged.

## Verification

Add source-level regression coverage that proves:

- the desktop shop profile contains the approved category rail labels and horizontal rail classes;
- the desktop product card uses the Products page square image/card sizing contract;
- the existing category-selection action and product links remain present;
- repair services remain represented in the shop profile source.

Run the focused shop-profile tests, the full frontend suite, the production Vite build, and `git diff --check`. Browser verification should be attempted if the repository has a runnable Playwright/Puppeteer setup; otherwise report it as unavailable rather than inferring visual success.

## Non-goals

- No visual redesign of the shop cover, profile metadata, navigation, repair services, or virtual showroom.
- No backend/API/schema changes.
- No new carousel library, pagination model, or server-side product limit.
- No changes to the separate Products page catalog implementation beyond matching its existing card styling contract.

## Acceptance criteria

- On desktop shop profiles, users see the same category-led browsing structure as mobile: Recommended For You followed by available gender/category rails.
- Each desktop rail is horizontally browsable and visibly presents landscape product cards.
- Shop-profile product cards match the Products page card proportions and information hierarchy.
- Category selection, product navigation, image fallbacks, repair content, and showroom links continue to work.
- No unrelated files are staged or committed, and a fresh `public/build` is generated before the feature branch is pushed.
