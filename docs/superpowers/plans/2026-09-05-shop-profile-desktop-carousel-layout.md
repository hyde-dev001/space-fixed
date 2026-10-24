# Shop Profile Desktop Carousel Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the desktop shop profile mirror the mobile category-led browsing experience with horizontal product rails and Products-page card proportions.

**Architecture:** Keep the existing ShopProfile.tsx data flow and mobile/repair branches intact. Add two module-level typed render units in the same page: ShopProfileProductCard for the Products-page card contract and ShopProfileProductRail for the repeated horizontal section. Render the rails from the existing filtered product/category values. This keeps the change local, avoids a new dependency or API request, and prevents five desktop sections from drifting apart.

**Tech Stack:** Laravel/Inertia page props, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite.

## Global Constraints

- Change only the shop profile desktop presentation and its regression coverage; do not change controllers, routes, models, APIs, product filters, repair data, cart behavior, or showroom authorization.
- Use the existing feature branch feature/monochrome-erp-theme-clean and preserve all unrelated working-tree changes.
- Do not add dependencies or edit .env, vendor/, or node_modules/.
- Keep product links at /products/{slug} and preserve image-error fallbacks, hover image cycling, sale labels, sold-out labels, lazy loading, and keyboard focus.
- Generate a fresh public/build only after the final source revision and include it in the pushed branch.

---

### Task 1: Add the desktop carousel regression contract

**Files:**
- Create: resources/js/Pages/UserSide/Profile/ShopProfile.desktop-carousel.test.ts
- Reference: resources/js/Pages/UserSide/Profile/ShopProfile.tsx

**Interfaces:**
- Consumes: the current ShopProfile.tsx source as a UTF-8 string, following the existing source-contract test style in resources/js/Pages/UserSide/Products/Products.layout.test.ts.
- Produces: executable acceptance coverage for the desktop rail wrapper, approved retail section labels, horizontal scrolling, the Products-page square-card contract, and preserved category/product/service behavior.

- [ ] **Step 1: Write the failing test**

Create the test with these assertions:

~~~ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const shopProfileSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Profile/ShopProfile.tsx'),
  'utf8',
);

describe('ShopProfile desktop product rails', () => {
  it('renders the approved category-led desktop browsing structure', () => {
    expect(shopProfileSource).toContain('data-testid="shop-profile-desktop-product-rails"');
    expect(shopProfileSource).toContain('title="Recommended For You"');
    expect(shopProfileSource).toContain('retailCategoriesForSections.map((category)');
    expect(shopProfileSource).toContain('onSeeMore={() => setSelectedCategory(category)}');
    expect(shopProfileSource).toContain('title={category}');
  });

  it('keeps each desktop rail horizontally scrollable and matches catalog card sizing', () => {
    expect(shopProfileSource).toContain('snap-x snap-mandatory gap-4 overflow-x-auto');
    expect(shopProfileSource).toContain('xl:aspect-square');
    expect(shopProfileSource).toContain('xl:min-h-48.5 xl:p-3.5');
    expect(shopProfileSource).toContain('data-testid="shop-profile-product-card"');
    expect(shopProfileSource).not.toContain('grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8');
  });

  it('preserves existing product navigation and non-retail content paths', () => {
    expect(shopProfileSource).toContain("href={'/products/' + product.slug}");
    expect(shopProfileSource).toContain('filteredRepairPackages');
    expect(shopProfileSource).toContain('filteredRepairServices');
    expect(shopProfileSource).toContain('virtual-showroom');
  });
});
~~~

- [ ] **Step 2: Run the test to verify it fails for the missing desktop contract**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/UserSide/Profile/ShopProfile.desktop-carousel.test.ts
~~~

Expected: the test file runs but fails because the current desktop branch still renders the old grid product layout and has no ShopProfileProductRail contract.

- [ ] **Step 3: Commit the red test**

Stage and commit only the new test:

~~~powershell
git add -- resources/js/Pages/UserSide/Profile/ShopProfile.desktop-carousel.test.ts
git diff --cached --check
git commit -m "test: define shop profile desktop carousel layout"
~~~

---

### Task 2: Add the shared shop-profile desktop card and rail units

**Files:**
- Modify: resources/js/Pages/UserSide/Profile/ShopProfile.tsx near the existing Product, Props, and desktop products branch.

**Interfaces:**
- Consumes: Product, getProductImages, activeImageIndexes, startImageCycle, stopImageCycle, retailCategoriesForSections, filteredProducts, and getProductsByCategory already owned by ShopProfile.
- Produces: module-level ShopProfileProductCard and ShopProfileProductRail components used by the desktop retail branch.

- [ ] **Step 1: Add the typed card component before ShopProfile**

Add this module-level component after Props so it does not get recreated on every parent render. It copies the Products page card contract while allowing the parent to keep ownership of image-cycle state:

~~~tsx
type ShopProfileProductCardProps = {
  product: Product;
  getProductImages: (product: Product) => string[];
  activeImageIndex: number;
  onMouseEnter: () => void;
  onMouseLeave: () => void;
};

const ShopProfileProductCard = ({
  product,
  getProductImages,
  activeImageIndex,
  onMouseEnter,
  onMouseLeave,
}: ShopProfileProductCardProps) => {
  const productImages = getProductImages(product);
  const safeActiveImageIndex = activeImageIndex < productImages.length ? activeImageIndex : 0;

  return (
    <Link
      href={'/products/' + product.slug}
      data-testid="shop-profile-product-card"
      className="group flex h-full w-[min(86vw,28rem)] shrink-0 snap-start flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-[0_12px_28px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-300 hover:shadow-[0_24px_40px_-24px_rgba(15,23,42,0.55)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#16233b] xl:w-[calc((100%_-_2rem)/3)] xl:rounded-3xl xl:border-gray-300 xl:shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)] 2xl:w-[calc((100%_-_3rem)/4)]"
      onMouseEnter={onMouseEnter}
      onMouseLeave={onMouseLeave}
    >
      <div className="relative aspect-3/4 overflow-hidden bg-gray-50 xl:aspect-square">
        {product.compare_at_price && product.compare_at_price > product.price && (
          <div className="absolute left-4 top-4 z-10 rounded-full bg-red-600 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-white shadow-sm">SALE</div>
        )}
        {product.stock_quantity === 0 && (
          <div className="absolute left-4 top-4 z-10 rounded-full bg-black px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-white shadow-sm">SOLD OUT</div>
        )}
        {productImages.length > 0 ? (
          productImages.map((image, imageIndex) => (
            <img
              key={product.id + '-' + imageIndex}
              src={image}
              alt={product.name}
              className={'absolute inset-0 h-full w-full object-cover transition-all duration-700 ease-in-out motion-reduce:transition-none ' + (
                imageIndex === safeActiveImageIndex
                  ? 'scale-100 opacity-100 group-hover:scale-110'
                  : 'pointer-events-none scale-100 opacity-0'
              )}
              loading="lazy"
              onError={(event) => {
                if (product.main_image) {
                  event.currentTarget.src = product.main_image;
                }
              }}
            />
          ))
        ) : (
          <div className="flex h-full items-center justify-center text-sm text-gray-400">No Image</div>
        )}
      </div>
      <div className="flex min-h-36 flex-1 flex-col border-t border-gray-200 p-2.5 xl:min-h-48.5 xl:p-3.5">
        <h3 className="mb-1 min-h-8 line-clamp-2 text-xs font-bold uppercase tracking-[0.06em] text-black xl:mb-1.5 xl:min-h-10 xl:text-sm">{product.name}</h3>
        <div className="mb-1 min-h-4 xl:mb-1.5 xl:min-h-[1.1rem]">
          {product.brand && <p className="text-[10px] uppercase tracking-[0.12em] text-black/55 xl:text-xs">{product.brand}</p>}
        </div>
        <div className="mb-1 min-h-[1.1rem]">
          <span className={'text-xs font-medium ' + (product.stock_quantity > 0 ? 'text-green-600' : 'text-red-600')}>
            {product.stock_quantity > 0 ? product.stock_quantity + ' in stock' : 'Out of stock'}
          </span>
        </div>
        <div className="mt-auto flex items-baseline justify-between border-t border-gray-200 pt-2 xl:pt-3">
          <div className="flex flex-col gap-0.5">
            <div className="text-base font-bold text-black xl:text-lg">₱{product.price.toLocaleString()}</div>
            {product.compare_at_price && product.compare_at_price > product.price && (
              <div className="text-xs text-black/40 line-through">₱{product.compare_at_price.toLocaleString()}</div>
            )}
          </div>
        </div>
      </div>
    </Link>
  );
};
~~~

- [ ] **Step 2: Add the typed rail component immediately after the card**

Add one native horizontal scroll region per section. The button has a minimum 44px hit area and visible focus state:

~~~tsx
type ShopProfileProductRailProps = {
  title: string;
  items: Product[];
  onSeeMore: () => void;
  getProductImages: (product: Product) => string[];
  activeImageIndexes: Record<number, number>;
  onProductMouseEnter: (product: Product) => void;
  onProductMouseLeave: (productId: number) => void;
};

const ShopProfileProductRail = ({
  title,
  items,
  onSeeMore,
  getProductImages,
  activeImageIndexes,
  onProductMouseEnter,
  onProductMouseLeave,
}: ShopProfileProductRailProps) => {
  if (items.length === 0) {
    return null;
  }

  const headingId = 'shop-profile-rail-' + title.toLowerCase().replace(/[^a-z0-9]+/g, '-');

  return (
    <section aria-labelledby={headingId}>
      <div className="mb-5 flex items-end justify-between border-b border-black/15 pb-3">
        <h3 id={headingId} className="text-2xl font-bold text-black">{title}</h3>
        <button
          type="button"
          onClick={onSeeMore}
          className="min-h-11 shrink-0 rounded-lg px-3 text-sm font-medium text-black transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]"
        >
          See More
        </button>
      </div>
      <div className="flex snap-x snap-mandatory gap-4 overflow-x-auto overscroll-x-contain pb-4">
        {items.map((product) => (
          <ShopProfileProductCard
            key={product.id}
            product={product}
            getProductImages={getProductImages}
            activeImageIndex={activeImageIndexes[product.id] ?? 0}
            onMouseEnter={() => onProductMouseEnter(product)}
            onMouseLeave={() => onProductMouseLeave(product.id)}
          />
        ))}
      </div>
    </section>
  );
};
~~~

- [ ] **Step 3: Replace only the desktop retail grid with data-driven rails**

Keep the existing desktop cover/profile header, Services branch, and selected-category behavior. Replace the current desktop retail grid branch with:

~~~tsx
            ) : (
              <div data-testid="shop-profile-desktop-product-rails" className="space-y-12">
                <ShopProfileProductRail
                  title="Recommended For You"
                  items={filteredProducts.slice(0, 6)}
                  onSeeMore={() => setSelectedCategory('Shoes')}
                  getProductImages={getProductImages}
                  activeImageIndexes={activeImageIndexes}
                  onProductMouseEnter={startImageCycle}
                  onProductMouseLeave={stopImageCycle}
                />
                {retailCategoriesForSections.map((category) => (
                  <ShopProfileProductRail
                    key={category}
                    title={category}
                    items={getProductsByCategory(category).slice(0, 10)}
                    onSeeMore={() => setSelectedCategory(category)}
                    getProductImages={getProductImages}
                    activeImageIndexes={activeImageIndexes}
                    onProductMouseEnter={startImageCycle}
                    onProductMouseLeave={stopImageCycle}
                  />
                ))}
              </div>
            )
~~~

Keep the existing filteredProducts.length > 0 guard so the no-products state still appears for an empty selected category; do not alter the mobile branch. Update only the desktop product-section container to match the Products page catalog width and gutters:

~~~tsx
<div className="mx-auto w-full max-w-[1920px] px-4 py-12 sm:px-6 sm:py-16 xl:px-6 2xl:px-12">
~~~

- [ ] **Step 4: Run focused tests to verify the implementation is green**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/UserSide/Profile/ShopProfile.desktop-carousel.test.ts resources/js/Pages/UserSide/Products/Products.layout.test.ts
~~~

Expected: all tests in both files pass, including the new rail/card contract and existing Products-page layout contract.

- [ ] **Step 5: Commit the implementation**

Stage only the page and focused test:

~~~powershell
git add -- resources/js/Pages/UserSide/Profile/ShopProfile.tsx resources/js/Pages/UserSide/Profile/ShopProfile.desktop-carousel.test.ts
git diff --cached --check
git commit -m "feat: add shop profile desktop product rails"
~~~

---

### Task 3: Run review and quality checks

**Files:**
- Review: resources/js/Pages/UserSide/Profile/ShopProfile.tsx
- Review: resources/js/Pages/UserSide/Profile/ShopProfile.desktop-carousel.test.ts
- Review: docs/superpowers/specs/2026-09-05-shop-profile-desktop-carousel-layout-design.md

**Interfaces:**
- Consumes: the committed design and implementation.
- Produces: evidence that the requested desktop rails are covered without changing unrelated behavior.

- [ ] **Step 1: Run the full frontend suite**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run
~~~

Expected: exit code 0 with all existing and new frontend tests passing. Record any pre-existing warnings without treating them as new failures.

- [ ] **Step 2: Run diff hygiene and the relevant backend smoke test**

Run:

~~~powershell
git diff --check ae8e9fad2..HEAD
php artisan test tests/Feature/UserSide/SearchSuggestionsTest.php --compact
~~~

Expected: no whitespace errors and the existing search feature test remains green, proving this presentation-only change did not disturb the public shop-search flow.

- [ ] **Step 3: Perform the sequential code review**

Check the diff against these concrete gates:

- Standards/spec: only the shop profile page, focused test, design/plan docs, and final generated build are in scope; desktop rails use existing props/helpers and preserve mobile, repair, showroom, and product links.
- Simplify: no new dependency, API, state machine, or unrelated refactor; the card and rail are the minimum module-level reusable units needed to keep repeated markup consistent.
- React/TypeScript: components are module-level, props are typed, no any or unsafe assertion is introduced, and derived rail data stays in render.
- Accessibility/performance: links and See More have focus styles, images keep alt text and lazy loading, rails use native scrolling, and motion remains transform/opacity-based with reduced-motion support.
- Security/data integrity: no server/API/auth/payment/storage code changed; existing public product fields and routes are reused.
- Gauge: no before/after performance baseline was captured; report as not measured.

- [ ] **Step 4: Commit any documentation-only correction if required**

If review finds a stale design/plan statement, update only the affected Markdown file with apply_patch, rerun git diff --check, and commit it as:

~~~powershell
git add -- docs/superpowers/specs/2026-09-05-shop-profile-desktop-carousel-layout-design.md docs/superpowers/plans/2026-09-05-shop-profile-desktop-carousel-layout.md
git diff --cached --check
git commit -m "docs: clarify shop profile carousel implementation"
~~~

Do not modify production code during this documentation-only step.

---

### Task 4: Rebase, build, and push the feature branch

**Files:**
- Generate: public/build/
- Preserve unstaged: all unrelated existing working-tree files reported by git status --short.

**Interfaces:**
- Consumes: the final committed source, current origin/solespace-b, and the existing feature branch.
- Produces: a fresh committed public/build and a remote branch ready for the user’s PR.

- [ ] **Step 1: Rebase before the final build**

Run:

~~~powershell
git fetch origin --prune
git rebase --autostash origin/solespace-b
~~~

Expected: the feature branch is based on the latest shared branch; preserve and restore unrelated local changes if Git creates an autostash.

- [ ] **Step 2: Generate the production build once after the rebase**

Run:

~~~powershell
.\\node_modules\\.bin\\vite.cmd build
~~~

Expected: Vite exits 0 and writes fresh hashed assets to public/build.

- [ ] **Step 3: Stage only the generated build and verify the staged scope**

Run:

~~~powershell
git add -f -- public/build
git diff --cached --check
git diff --cached --name-only
~~~

Expected: all staged files are under public/build/; unrelated tracked modifications and untracked files remain unstaged.

- [ ] **Step 4: Commit and push the feature branch**

Run:

~~~powershell
git commit -m "build: refresh frontend assets"
git push origin feature/monochrome-erp-theme-clean
~~~

Expected: push succeeds without force-push; do not create a Pull Request because the user will do that.

- [ ] **Step 5: Verify the remote and final working tree**

Run:

~~~powershell
$local = git rev-parse HEAD
$remote = git ls-remote origin refs/heads/feature/monochrome-erp-theme-clean
Write-Output "local=$local"
Write-Output "remote=$remote"
git status --short --branch
~~~

Expected: the remote branch hash matches local HEAD, and only the already-known unrelated user changes remain outside the commit.
