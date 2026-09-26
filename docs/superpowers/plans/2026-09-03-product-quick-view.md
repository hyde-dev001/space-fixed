# Product Listing Quick View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task with verification checkpoints.

**Goal:** Add an accessible Quick View purchase modal to the customer-facing `/products` catalog without changing the existing product detail, cart, checkout, inventory, or ERP flows.

**Architecture:** Keep catalog state in `Products.tsx` and move modal-only state into a focused `ProductQuickView` component. The product card will use separate image/details links and a sibling Quick View button so interactive elements are never nested. The modal will reuse the existing `AddToCartButton` contract and the listing API payload; no backend or dependency changes are needed.

**Tech Stack:** Laravel 12/Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, pnpm.

## Global Constraints

- “The existing product-card link, product detail page, cart endpoint, checkout, ERP pages, and unrelated working-tree changes remain out of scope.”
- “Reuse the existing `AddToCartButton` so authentication, CSRF, duplicate-click protection, stock errors, cart events, and success feedback stay centralized.”
- “The server remains authoritative for variant and stock validation when Add to Cart is submitted.”
- “Quick View is visible on touch layouts and available through keyboard focus on desktop; hover may enhance visibility but is not the only access path.”
- “The modal uses `role="dialog"`, `aria-modal="true"`, a labelled heading, a 44px-equivalent close target, and labelled image/quantity controls.”
- “No new dependency or backend/API route is allowed for this feature.”
- Use `pnpm` for frontend commands. Do not claim TypeScript or lint checks pass because this repo has no committed `tsconfig.json` or frontend lint script.
- Preserve all pre-existing unrelated changes in the working tree.

---

### Task 1: Lock down Quick View behavior with failing component tests

**Files:**
- Create: `resources/js/components/products/__tests__/ProductQuickView.test.tsx`
- Create in Task 2: `resources/js/components/products/ProductQuickView.tsx`

**Interfaces:**
- Consumes a `ProductQuickViewProduct`, `detailsHref`, optional `triggerRef`, and `onClose` callback from the component introduced in Task 2.
- Produces test coverage for the modal details, option state, quantity state, close behavior, and existing cart-button wiring.

- [ ] **Step 1: Write the failing test**

Create the focused test with a mocked Inertia `Link` and mocked `AddToCartButton` so the test observes the component contract instead of making a real network request:

```tsx
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProductQuickView, { type ProductQuickViewProduct } from '../ProductQuickView';

const addToCartProps = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('../../CartActions', () => ({
  default: (props: { label?: string; disabled?: boolean; onAdded?: () => void }) => {
    addToCartProps(props);
    return (
      <button type="button" disabled={props.disabled} onClick={props.onAdded}>
        {props.label}
      </button>
    );
  },
}));

const product: ProductQuickViewProduct = {
  id: 7,
  name: 'SoleSpace Runner',
  slug: 'solespace-runner',
  price: 5555,
  compare_at_price: 6000,
  main_image: '/storage/products/runner.jpg',
  gallery_images: ['/storage/products/runner-side.jpg'],
  brand: 'SoleSpace',
  stock_quantity: 4,
  sizes_available: ['US 8', 'US 9'],
  colors_available: ['Black', 'White'],
};

describe('ProductQuickView', () => {
  beforeEach(() => {
    addToCartProps.mockClear();
  });

  it('renders product details, image navigation, and a category-preserving details link', () => {
    render(
      <ProductQuickView
        product={product}
        detailsHref="/products/solespace-runner?category=men"
        onClose={vi.fn()}
      />,
    );

    expect(screen.getByRole('dialog', { name: 'SoleSpace Runner' })).toBeInTheDocument();
    expect(screen.getByText('SoleSpace')).toBeInTheDocument();
    expect(screen.getByText(/5,555/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View product details' })).toHaveAttribute(
      'href',
      '/products/solespace-runner?category=men',
    );
    expect(screen.getByRole('button', { name: 'Next product image' })).toBeInTheDocument();
  });

  it('passes selected color, size, image, and quantity to the existing cart action', () => {
    render(<ProductQuickView product={product} detailsHref="/products/solespace-runner" onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Color White' }));
    fireEvent.click(screen.getByRole('button', { name: 'Size US 9' }));
    fireEvent.click(screen.getByRole('button', { name: 'Increase quantity' }));

    const latestProps = addToCartProps.mock.lastCall?.[0];
    expect(latestProps).toMatchObject({
      productId: 7,
      stockQuantity: 4,
      disabled: false,
      label: 'Add to Cart',
    });
    expect(latestProps.product).toMatchObject({
      size: 'US 9',
      color: 'White',
      qty: 2,
      selectedImage: '/storage/products/runner.jpg',
    });
  });

  it('closes from Escape, backdrop click, and a successful cart add', () => {
    const onClose = vi.fn();
    const { container } = render(<ProductQuickView product={product} detailsHref="/products/solespace-runner" onClose={onClose} />);

    fireEvent.keyDown(document, { key: 'Escape' });
    fireEvent.click(container.firstElementChild as HTMLElement);
    fireEvent.click(screen.getByRole('button', { name: 'Add to Cart' }));

    expect(onClose).toHaveBeenCalledTimes(3);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm exec vitest run resources/js/components/products/__tests__/ProductQuickView.test.tsx
```

Expected: FAIL because `ProductQuickView.tsx` does not exist yet. Do not create production code until this failure is observed.

- [ ] **Step 3: Commit the red test**

```bash
git add -- resources/js/components/products/__tests__/ProductQuickView.test.tsx
git commit -m "test: define product quick-view behavior"
```

### Task 2: Implement the focused Quick View component

**Files:**
- Create: `resources/js/components/products/ProductQuickView.tsx`
- Test: `resources/js/components/products/__tests__/ProductQuickView.test.tsx`

**Interfaces:**
- `ProductQuickViewProduct` contains `id`, `name`, `slug`, `price`, optional `compare_at_price`, `main_image`, optional `gallery_images`, optional `brand`, `stock_quantity`, optional `sizes_available`, optional `colors_available`, and optional `shop_owner`.
- `ProductQuickViewProps` is `{ product, detailsHref, triggerRef?, onClose }`.
- The component renders a modal immediately when mounted; the parent controls mounting and unmounting.

- [ ] **Step 1: Add the minimal component implementation**

Implement these exact behaviors:

```tsx
export type ProductQuickViewProduct = {
  id: number;
  name: string;
  slug: string;
  price: number;
  compare_at_price?: number | null;
  main_image: string | null;
  gallery_images?: string[];
  brand?: string | null;
  stock_quantity: number;
  sizes_available?: unknown[] | null;
  colors_available?: unknown[] | null;
  shop_owner?: { id: number; name?: string; business_name?: string };
};

type ProductQuickViewProps = {
  product: ProductQuickViewProduct;
  detailsHref: string;
  triggerRef?: React.RefObject<HTMLButtonElement | null>;
  onClose: () => void;
};
```

The implementation must:

- Normalize non-empty `sizes_available` and `colors_available` values to string options, defaulting each selection to the first option.
- Build a unique image list from `main_image` followed by `gallery_images`, show a fallback “No image available” state, and make previous/next controls no-ops at the ends.
- Display peso (PHP) pricing with a compare-at price when it is greater than the current price.
- Render color and size buttons only when the corresponding option list is non-empty; option buttons use `aria-label="Color <value>"` and `aria-label="Size <value>"`.
- Keep quantity between 1 and `stock_quantity`, disable the CTA at zero stock or when an existing option list is not selected, and pass this object to `AddToCartButton`:

```tsx
{
  ...product,
  size: selectedSize ?? undefined,
  color: selectedColor ?? undefined,
  qty,
  selectedImage,
}
```

- Pass `product.id`, `stockQuantity={product.stock_quantity}`, `label="Add to Cart"`, and `onAdded={onClose}` to `AddToCartButton`.
- Render `Link` to `detailsHref` with accessible name `View product details`; close only after the link navigation begins if needed.
- Use a fixed darkened backdrop and a white dialog panel with `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, a close button with `aria-label="Close quick view"`, and a responsive two-column/one-column layout.
- Close on Escape and backdrop click, keep inner-panel clicks open, set `document.body.style.overflow = 'hidden'` while mounted, restore the previous overflow value on cleanup, focus the close button on mount, and restore `triggerRef.current?.focus()` on cleanup.
- Use `motion-reduce:transition-none`/equivalent Tailwind classes for modal/image transitions and preserve visible focus rings.

- [ ] **Step 2: Run focused tests to verify green**

Run:

```bash
pnpm exec vitest run resources/js/components/products/__tests__/ProductQuickView.test.tsx
```

Expected: all Quick View tests PASS with zero failures.

- [ ] **Step 3: Commit the component**

```bash
git add -- resources/js/components/products/ProductQuickView.tsx resources/js/components/products/__tests__/ProductQuickView.test.tsx
git commit -m "feat: add product quick-view modal"
```

### Task 3: Integrate Quick View into the product catalog

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/Products.tsx`
- Modify: `resources/js/Pages/UserSide/Products/Products.layout.test.ts`
- Consume: `resources/js/components/products/ProductQuickView.tsx`

**Interfaces:**
- `Products.tsx` keeps its existing local `Product` shape and adds `sizes_available?: unknown[] | null` and `colors_available?: unknown[] | null`.
- The catalog owns `quickViewProduct: Product | null`, `quickViewTriggerRef`, `openQuickView(product, event)`, and a stable `closeQuickView` callback.
- The details URL is produced by one helper so card links and Quick View use the same category query.

- [ ] **Step 1: Write the failing layout contract**

Add this test to `Products.layout.test.ts` before modifying `Products.tsx`:

```ts
it('adds a sibling Quick View trigger and keeps the existing catalog link path', () => {
  expect(productsSource).toContain("import ProductQuickView from '../../../components/products/ProductQuickView';");
  expect(productsSource).toContain('const [quickViewProduct, setQuickViewProduct] = useState<Product | null>(null);');
  expect(productsSource).toContain('quickViewTriggerRef.current = event.currentTarget;');
  expect(productsSource).toContain('aria-label={`Quick view ${p.name}`}');
  expect(productsSource).toContain('<ProductQuickView');
  expect(productsSource).toContain('className="group flex h-full');
});
```

- [ ] **Step 2: Run the layout test to verify it fails**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Products/Products.layout.test.ts
```

Expected: FAIL because the import, state, trigger, and modal integration do not exist yet.

- [ ] **Step 3: Add the catalog state and trigger**

Add the import and state near the existing cart/search state:

```tsx
import ProductQuickView from '../../../components/products/ProductQuickView';

type Product = {
  // existing fields remain unchanged
  sizes_available?: unknown[] | null;
  colors_available?: unknown[] | null;
};

const [quickViewProduct, setQuickViewProduct] = useState<Product | null>(null);
const quickViewTriggerRef = useRef<HTMLButtonElement | null>(null);
const closeQuickView = useCallback(() => setQuickViewProduct(null), []);
const openQuickView = (product: Product, event: React.MouseEvent<HTMLButtonElement>) => {
  quickViewTriggerRef.current = event.currentTarget;
  setQuickViewProduct(product);
};

const getProductHref = (product: Product) =>
  activeCategory
    ? `/products/${product.slug}?category=${encodeURIComponent(activeCategory)}`
    : `/products/${product.slug}`;
```

Update the product card structure so the outer card handles the existing image-cycle hover behavior, the image and details each remain normal `Link`s, and Quick View is a sibling button positioned in the image area:

```tsx
<div key={p.id} data-scroll-reveal className="scroll-reveal h-full">
  <div
    className="group flex h-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-[0_12px_28px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-300 hover:shadow-[0_24px_40px_-24px_rgba(15,23,42,0.55)] xl:rounded-3xl xl:border-gray-300 xl:shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)]"
    onMouseEnter={() => startImageCycle(p)}
    onMouseLeave={() => stopImageCycle(p.id)}
  >
    <div className="relative aspect-3/4 overflow-hidden bg-gray-50 xl:aspect-square">
      <Link href={getProductHref(p)} className="absolute inset-0 block" aria-label={`View ${p.name}`}>
        {/* retain the existing image, sale, sold-out, and fallback rendering */}
      </Link>
      <button
        type="button"
        aria-label={`Quick view ${p.name}`}
        onClick={(event) => openQuickView(p, event)}
        className="absolute bottom-3 right-3 z-20 min-h-11 rounded-md bg-[#16233b] px-4 py-2 text-[10px] font-bold uppercase tracking-[0.12em] text-white opacity-100 transition-all hover:bg-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white motion-reduce:transition-none xl:opacity-0 xl:group-hover:opacity-100 xl:group-focus-within:opacity-100"
      >
        Quick view
      </button>
    </div>
    <Link href={getProductHref(p)} className="flex min-h-36 flex-1 flex-col border-t border-gray-200 p-2.5 xl:min-h-48.5 xl:p-3.5">
      {/* retain the existing product name, brand, shop, price, and stock rendering */}
    </Link>
  </div>
</div>
```

The actual edit must preserve all existing card data and image-cycle logic. Move the current sale badge, sold-out badge, image cycle, fallback image, product metadata, shop link behavior, price, and stock rendering into the corresponding link regions without changing their values or API calls.

- [ ] **Step 4: Render the modal outside the product grid**

After the grid/pagination content and before the mobile bottom navigation, render:

```tsx
{quickViewProduct && (
  <ProductQuickView
    key={quickViewProduct.id}
    product={quickViewProduct}
    detailsHref={getProductHref(quickViewProduct)}
    triggerRef={quickViewTriggerRef}
    onClose={closeQuickView}
  />
)}
```

- [ ] **Step 5: Run the focused catalog tests to verify green**

Run:

```bash
pnpm exec vitest run resources/js/Pages/UserSide/Products/Products.layout.test.ts resources/js/components/products/__tests__/ProductQuickView.test.tsx
```

Expected: both test files PASS and the existing layout assertions remain green.

- [ ] **Step 6: Commit the catalog integration**

```bash
git add -- resources/js/Pages/UserSide/Products/Products.tsx resources/js/Pages/UserSide/Products/Products.layout.test.ts
git commit -m "feat: add quick view to product cards"
```

### Task 4: Run the complete quality and browser verification gates

**Files:**
- Inspect only: all files changed by Tasks 1–3 and the approved spec/plan.
- Do not modify unrelated working-tree files.

**Interfaces:**
- Verifies the committed component and catalog integration against the approved design.

- [ ] **Step 1: Run diff hygiene**

```bash
git diff --check HEAD~3..HEAD
```

Expected: no output and exit code 0.

- [ ] **Step 2: Run the full frontend test suite**

```bash
pnpm run test:frontend
```

Expected: Vitest exits 0 with zero failed tests.

- [ ] **Step 3: Build the frontend**

```bash
pnpm run build
```

Expected: Vite exits 0 and produces the normal build output without compilation errors.

- [ ] **Step 4: Verify the browser flow when the local app is runnable**

Open `/products` and verify all of the following manually in a desktop viewport and a narrow mobile viewport:

1. Product cards still navigate to `/products/<slug>` when the image/details surface is clicked.
2. Quick View is visible on touch/mobile and appears on desktop hover or keyboard focus.
3. The modal opens for the selected product with the correct name, price, image, option lists, and details URL.
4. Previous/next image buttons, color/size selection, and quantity controls update only the modal.
5. Add to Cart remains disabled until required options are selected, then invokes the existing cart flow.
6. Escape, backdrop click, close button, and successful add close the modal; background scrolling is restored.
7. Existing cart count, checkout navigation, search, sorting, pagination, and mobile navigation continue to work.

- [ ] **Step 5: Perform the sequential review record**

Record in the final handoff:

- Simplify: no new dependency, duplicate cart logic, backend route, or speculative abstraction added.
- Standards/spec: approved spec requirements are covered by the component, integration, tests, and browser checks.
- TypeScript/React: prop boundaries are explicit, interactive controls are not nested, and the new component has one focused responsibility.
- Security: no new input endpoint or authorization path; cart/auth/CSRF remain in `AddToCartButton`.
- Improvements: baseline bundle/query/render metrics are `not measured`; report behavior/test/build evidence instead.
- Reuse/dead code: existing `AddToCartButton`, `Link`, image data, and card hover logic are reused; no orphaned imports or branches remain.
