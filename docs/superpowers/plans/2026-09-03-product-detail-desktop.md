# Product Detail Desktop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework only the desktop (`xl` and wider) customer product-detail experience to follow the BOY London page's spacious gallery-and-purchase-panel composition, while preserving the existing mobile/tablet UI and all size, cart, buy-now, size-guide, voucher, and review behavior.

**Architecture:** Extend the existing `LandingPageController::productShow` Inertia payload with a small, server-filtered related-product collection. Keep recently viewed products client-side in a versioned `localStorage` list behind a defensive pure helper. Add one reusable flat product rail, then compose desktop-only layout and disclosure sections inside the existing `ProductShow` page with `xl:` utilities and explicit `hidden xl:block` boundaries.

**Tech Stack:** Laravel 12, Eloquent, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, PHPUnit, Vite 7, pnpm.

## Global Constraints

- Work only in `resources/js/Pages/UserSide/Products/ProductShow.tsx` and narrowly supporting product-detail files.
- Base through `lg` styles and interactions must remain unchanged; new layout/rail/disclosure UI starts at `xl` (`1280px`).
- Preserve the existing size selector, Size Guide modal, quantity handlers, `AddToCartButton` props, Add to Cart flow, and Buy Now flow.
- Reuse existing colors and spacing from `DESIGN.md`: white canvas, `#111111` action surfaces, `#f5f5f5` image surfaces, fine neutral borders, no decorative shadows.
- Add no dependency or database migration.
- Keep source and the final fresh `public/build` together in the implementation commit, per `docs/git-workflow.md`.

---

### Task 1: Add the server-side recommendation contract

**Files:**
- Create: `tests/Feature/UserSide/ProductDetailRecommendationsTest.php`
- Modify: `app/Http/Controllers/UserSide/LandingPageController.php`

- [ ] **Step 1: Write a failing feature test for eligibility and ranking**

Create approved and rejected shops plus active/inactive products. Request `products.show` and assert that `relatedProducts`:

- excludes the current product;
- excludes inactive products and products from non-approved shops;
- puts same-category products first, then same-brand products, then newest fallback products;
- exposes only card-safe fields: `id`, `name`, `url`, `image`, `price`, `compare_at_price`, `brand`, and `category`.

Use a local helper that calls `Product::create` because this project has no `ProductFactory`.

- [ ] **Step 2: Write a failing feature test for the eight-item cap**

Seed ten eligible products and assert exactly eight unique IDs are returned.

- [ ] **Step 3: Run the focused backend test and confirm the expected failure**

Run: `php artisan test tests/Feature/UserSide/ProductDetailRecommendationsTest.php`

Expected: failure because `relatedProducts` is absent.

- [ ] **Step 4: Implement one ranked Eloquent query and compact mapping**

In `LandingPageController::productShow`, query `Product` once with these predicates:

```php
Product::query()
    ->where('is_active', true)
    ->whereKeyNot($product->getKey())
    ->whereHas('shopOwner', fn ($query) => $query->where('status', 'approved'))
```

Apply bound `CASE` ordering for category and brand matches, then newest `created_at` and `id`, limit eight, and map to the card-safe payload. Pass it as `relatedProducts` beside the existing `product` payload without changing existing keys.

- [ ] **Step 5: Run the focused backend test**

Run: `php artisan test tests/Feature/UserSide/ProductDetailRecommendationsTest.php`

Expected: both recommendation tests pass.

---

### Task 2: Add safe recent-history storage and the product rail

**Files:**
- Create: `resources/js/Pages/UserSide/Products/productHistory.ts`
- Create: `resources/js/Pages/UserSide/Products/productHistory.test.ts`
- Create: `resources/js/Pages/UserSide/Products/ProductRail.tsx`

- [ ] **Step 1: Write failing history-helper tests**

Cover:

- newest-first registration;
- deduplication by product ID;
- current-product exclusion from the returned display list;
- an eight-item persisted cap;
- malformed JSON and storage read/write exceptions returning a safe empty/history result.

- [ ] **Step 2: Run the focused frontend test and confirm failure**

Run: `.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Products/productHistory.test.ts --reporter=verbose`

Expected: failure because the helper does not exist.

- [ ] **Step 3: Implement the defensive versioned helper**

Export a `ProductRailItem` type, `RECENTLY_VIEWED_STORAGE_KEY`, and `registerRecentlyViewed(storage, currentProduct)`. Parse unknown storage data with shape validation, remove duplicate/current IDs, prepend the current item for persistence, cap at eight, catch all storage failures, and return only prior items for display.

- [ ] **Step 4: Implement the desktop-only flat rail**

`ProductRail.tsx` accepts `title` and `items`, returns `null` for an empty list, and renders a `hidden xl:block` section with:

- uppercase section heading and fine divider;
- responsive desktop grid of four cards;
- square `#f5f5f5` image stage with `object-contain`;
- product name, optional brand/category, current price, and optional struck comparison price;
- existing Inertia `Link`, no carousel package, shadows, gradients, or new controls.

- [ ] **Step 5: Run the focused helper test**

Run: `.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Products/productHistory.test.ts --reporter=verbose`

Expected: all helper tests pass.

---

### Task 3: Integrate the BOY London-inspired desktop composition

**Files:**
- Create: `resources/js/Pages/UserSide/Products/ProductShow.desktop-layout.test.ts`
- Modify: `resources/js/Pages/UserSide/Products/ProductShow.tsx`

- [ ] **Step 1: Write a failing source-contract test for breakpoint isolation**

Read `ProductShow.tsx` and assert stable integration markers for:

- `xl:max-w-[1440px]` and an asymmetric `xl:grid-cols-*` hero;
- a desktop sticky purchase panel;
- desktop-only Product Details, Returns Policy, and Shipping disclosure labels;
- both desktop-only rails after the customer-review section;
- continued presence of the existing `xl:hidden` mobile controls, Size Guide, size selection, and both `AddToCartButton` actions.

This complements behavior tests by preventing accidental removal of the explicit desktop/mobile boundaries.

- [ ] **Step 2: Run the desktop contract test and confirm failure**

Run: `.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Products/ProductShow.desktop-layout.test.ts --reporter=verbose`

Expected: failure because the new desktop markers are absent.

- [ ] **Step 3: Add typed page data and recent-history registration**

Import `ProductRail`, `ProductRailItem`, and `registerRecentlyViewed`; read `relatedProducts` with an empty-array fallback; create `recentlyViewed` state; and register the current product in an effect keyed by the stable product ID. Derive the current card from existing product fields and `window.location.pathname`, with no server rendering dependency.

- [ ] **Step 4: Restyle only the `xl` hero**

Keep every base/mobile/tablet class, then add desktop overrides for:

- centered `xl:max-w-[1440px]` content;
- approximately 60/40 image/purchase columns and 64px inter-column gap;
- large flat soft-gray image stage with squared desktop corners;
- compact product typography and pricing hierarchy;
- sticky, self-starting right purchase panel;
- restrained vertical rhythm and full-width black/outlined purchase buttons without desktop shadows.

Do not replace or reimplement the existing controls; only add desktop layout/styling classes around them.

- [ ] **Step 5: Add desktop disclosures while preserving the existing smaller-screen details**

Mark the current seller/details presentation `xl:hidden`. Add a separate `hidden xl:block` seller/disclosure region in the right panel using native buttons with `aria-expanded`, fine top borders, and one-open-at-a-time state:

- Product Details opens by default and shows existing description/category data;
- Returns Policy uses concise existing-site-safe return guidance without inventing a guarantee;
- Shipping explains that cost/timing are confirmed at checkout.

- [ ] **Step 6: Render recommendation rails after reviews**

Place `You May Also Like` and `Recently Viewed Items` after the full customer-review section. Both components are desktop-only and disappear entirely when empty.

- [ ] **Step 7: Run focused frontend tests**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Products/ProductShow.dark-mode.test.ts resources/js/Pages/UserSide/Products/ProductShow.desktop-layout.test.ts resources/js/Pages/UserSide/Products/productHistory.test.ts --reporter=verbose
```

Expected: all existing and new focused tests pass.

---

### Task 4: Review, verify in-browser, build, and publish the feature branch

**Files:**
- Modify only if a durable lesson is discovered: `docs/ai-learning-log.md`
- Generate: `public/build/**`

- [ ] **Step 1: Perform the required sequential review stack**

Record results for simplification, repository standards, specification, TypeScript/React cleanliness, Karpathy minimum-scope checks, code-splitting, measurable improvement, security applicability, reuse, and dead-code scan. Resolve every validated finding before broader verification.

- [ ] **Step 2: Run narrow and broad automated checks**

Run:

```powershell
php artisan test tests/Feature/UserSide/ProductDetailRecommendationsTest.php tests/Feature/UserSide/ProductVoucherClaimTest.php
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Products --reporter=verbose
pnpm run test:frontend
git diff --check
```

Expected: no failures and no whitespace errors. If a broader existing failure is unrelated, capture the exact command and evidence instead of claiming it passed.

- [ ] **Step 3: Verify desktop, tablet, and mobile in a real browser**

Start the local Laravel/Vite app if needed and use Playwright at representative widths:

- desktop: 1440px or wider shows the new gallery/panel/disclosures and both populated rails;
- tablet: 1024px retains the pre-existing single-column UI and controls;
- mobile: 390px retains the existing navigation, product flow, Size Guide, and fixed purchase action;
- exercise size selection, quantity, disclosure toggles, Add to Cart, Buy Now, and recent-history behavior; inspect console errors.

- [ ] **Step 4: Rebase before the production build**

Run:

```powershell
git fetch origin
git rebase origin/solespace-b
```

Resolve only feature-owned conflicts and rerun affected focused tests.

- [ ] **Step 5: Generate and verify a fresh public build**

Run:

```powershell
pnpm run build
git diff --check
git status --short
```

Confirm `public/build/manifest.json` and hashed assets changed consistently with the source.

- [ ] **Step 6: Stage only approved files and commit source plus build together**

Use explicit `git add` paths for the controller, product-detail TS/TSX/tests, feature test, implementation plan, any justified learning-log update, and `public/build`. Inspect `git diff --cached --stat` and `git diff --cached --check`, then commit with a narrow feature message.

- [ ] **Step 7: Push the feature branch**

Run: `git push -u origin feature/product-detail-desktop`

Report the branch and commit SHA so the user can open the PR. Do not create the PR.
