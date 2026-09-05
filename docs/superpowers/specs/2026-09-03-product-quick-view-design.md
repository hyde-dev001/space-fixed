# Product Listing Quick View

Date: 2026-09-03
Status: Approved for implementation

## Scope

Add a Quick View purchase flow to the customer-facing `/products` page shown in the third reference screenshot. The existing product-card link, product detail page, cart endpoint, checkout, ERP pages, and unrelated working-tree changes remain out of scope.

## Goals

- Let customers open a product preview without leaving the catalog.
- Match the reference interaction: an image-area Quick View action opens a focused product modal.
- Support image browsing, color and size selection when those options exist, quantity selection, Add to Cart, and a link to the existing product details page.
- Reuse the existing `AddToCartButton` so authentication, CSRF, duplicate-click protection, stock errors, cart events, and success feedback stay centralized.
- Keep the catalog responsive and keyboard accessible.

## Non-goals

- Rebuilding or embedding `ProductShow` inside the modal.
- Changing cart, checkout, product APIs, inventory rules, or ERP product management.
- Adding a new dependency or changing the product image storage pipeline.

## Proposed structure

`Products.tsx` will own the currently selected quick-view product and render a new focused `ProductQuickView` component outside the product grid. Each product card will use a non-nested structure: the existing product `Link` remains the card surface and a sibling button is positioned over the image. This preserves normal card navigation and avoids invalid button-inside-link nesting.

`ProductQuickView` will receive the listing product and its details URL. It will manage only modal-local state: selected image, selected color, selected size, and quantity. It will use the listing response's existing `main_image`, `gallery_images`, `sizes_available`, `colors_available`, `price`, `compare_at_price`, `stock_quantity`, `brand`, and shop data. The server remains authoritative for variant and stock validation when Add to Cart is submitted.

## Interaction and accessibility

- Quick View is visible on touch layouts and available through keyboard focus on desktop; hover may enhance visibility but is not the only access path.
- The modal uses `role="dialog"`, `aria-modal="true"`, a labelled heading, a 44px-equivalent close target, and labelled image/quantity controls.
- Clicking the backdrop or pressing Escape closes the modal. Clicking inside the panel does not.
- Opening the modal locks page scrolling and cleanup restores scrolling when it closes.
- The close control receives focus when the modal opens where practical, and focus is returned to the triggering Quick View button on close.
- Reduced-motion users are not forced into animated transitions.
- The layout is a two-column preview on larger screens and a vertically scrollable single-column panel on smaller screens.

## Purchase behavior

The modal initializes the first available color and size when options are provided. Selectors are only rendered for option lists present in the product payload. Add to Cart uses `AddToCartButton` with the selected size, color, image, quantity, and listing stock. The CTA is disabled when an existing option list has no selection or the product is out of stock; backend validation still handles stale or unavailable variants. On a successful add, the existing cart event and success feedback run, then the quick view closes. `View Product Details` navigates to the same category-preserving URL used by the card.

## Failure handling

Image load failures fall back to the product's main image, matching the existing card behavior. Cart/authentication/stock failures remain handled by `AddToCartButton`. Closing and reopening a product starts from that product's initial image and default options rather than leaking state from another product.

## Verification

- Add component behavior tests for opening/closing, option selection, quantity, and CTA wiring.
- Extend the product-page layout contract to prove the Quick View trigger is present and is not nested inside the card link.
- Run the focused Vitest tests, the full frontend test script, `pnpm run build`, `git diff --check`, and browser verification of the catalog/modal flow when the local app is runnable.
