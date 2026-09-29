# Product Detail Cart Feedback and Footer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox ( - [ ] ) syntax for tracking.

**Goal:** Remove the green 'Added to cart' panel from the shared customer cart drawer and add the existing responsive SoleSpace footer to the product-detail page without changing cart behavior.

**Architecture:** Keep AddToCartButton and the existing cart:added event unchanged. Navigation will continue to guard on openDrawer, open the shared drawer, and retrigger its existing cart fetch, but will no longer store or render presentation-only success metadata. ProductShow will reuse CustomerFooterReveal around its existing page content; fixed product modals will remain siblings outside that footer shell.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest 3, Vite 7, pnpm 11.

## Global Constraints

- Keep the existing customer add-to-cart and cart-drawer behavior while removing the green 'Added to cart' confirmation panel.
- Do not change cart API requests, authentication, CSRF handling, stock checks, cart counts, drawer opening, drawer refreshes, checkout, product data, or footer content.
- Reuse the existing shared CustomerFooterReveal; do not add a new footer component, links, CSS, endpoint, dependency, or backend change.
- Preserve error, guest, out-of-stock, Buy Now, and checkout behavior.
- Existing modal siblings must remain outside the footer shell so fixed overlays keep their current stacking behavior.
- Preserve unrelated ERP/HR working-tree changes and stage only intended files.
- Generate and include a fresh public/build only after the final source revision and required checks are ready.

## File Map

- Modify resources/js/Pages/UserSide/Shared/Navigation.tsx: remove only the inline cart-success presentation state and markup; retain the shared event listener, drawer state, refresh key, and cart loader.
- Modify resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts: replace the old success-panel expectations with a regression contract for drawer opening/refreshing and absence of the removed panel.
- Modify resources/js/Pages/UserSide/Products/ProductShow.tsx: import and apply CustomerFooterReveal around the page content.
- Modify resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts: include ProductShow.tsx in the shared-footer page contract.
- Generate public/build: refresh the tracked Vite production output after all source changes.
- Do not modify resources/js/components/CartActions.tsx, resources/js/types/cart-events.ts, backend routes/controllers, CSS, or unrelated dirty files.

---

### Task 1: Remove the inline cart success panel while preserving drawer behavior

**Files:**
- Modify: resources/js/Pages/UserSide/Shared/Navigation.tsx
- Test: resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts

**Interfaces:**
- Consumes the existing CartAddedEvent listener and its optional openDrawer flag.
- Produces the same drawer-open and refresh-key behavior for successful product adds.

- [x] **Step 1: Update the contract test with the failing regression assertion**

In the existing opens-and-refreshes test, keep the drawer behavior assertions:

~~~ts
expect(navigationSource).toContain('addCartAddedListener');
expect(navigationSource).toContain('removeCartAddedListener');
expect(navigationSource).toContain('const [cartRefreshKey, setCartRefreshKey] = useState(0);');
expect(navigationSource).toContain('if (!event.detail?.openDrawer) return;');
expect(navigationSource).toContain('setCartDrawerOpen(true);');
expect(navigationSource).toContain('setCartRefreshKey((key) => key + 1);');
expect(navigationSource).toContain('}, [cartDrawerOpen, isAuthenticated, cartRefreshKey]);');
~~~

Replace its current success-panel assertions with:

~~~ts
expect(navigationSource).not.toContain('cartAddedItem');
expect(navigationSource).not.toContain('Added to cart');
        expect(navigationSource).not.toContain('bg-emerald-50');
~~~

- [x] **Step 2: Run the focused contract test and verify it fails**

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
~~~

Expected result: FAIL because Navigation.tsx still contains the cartAddedItem state and green Added to cart markup.

- [x] **Step 3: Remove only the unused confirmation presentation from Navigation.tsx**

Make these surgical changes:

1. Remove type CartAddedItem from the cart-events import, leaving type CartAddedEvent:

~~~tsx
import {
  addCartAddedListener,
  dispatchCartAddedEvent,
  removeCartAddedListener,
  type CartAddedEvent,
} from '../../../types/cart-events';
~~~

2. Remove this state declaration:

~~~tsx
const [cartAddedItem, setCartAddedItem] = useState<CartAddedItem | null>(null);
~~~

3. Keep the listener's drawer behavior and remove only the item assignment:

~~~tsx
useEffect(() => {
  const handleCartAdded = (event: CartAddedEvent) => {
    if (!event.detail?.openDrawer) return;

    setLandingSidebarOpen(false);
    setAccountDrawerOpen(false);
    setCartDrawerOpen(true);
    setCartRefreshKey((key) => key + 1);
  };

  addCartAddedListener(handleCartAdded);
  return () => removeCartAddedListener(handleCartAdded);
}, []);
~~~

4. Remove the effect that clears cartAddedItem when the drawer closes.

5. Remove only the cartAddedItem conditional block immediately inside the cart drawer. Leave the loading message, empty-cart state, item list, estimated total, checkout link, overlay, and close behavior unchanged.

- [x] **Step 4: Run the focused contract test and verify it passes**

Run the same Vitest command. Expected result: PASS, with the drawer listener and refresh assertions still satisfied and no inline success panel present.

---

### Task 2: Add the shared footer to the product-detail page

**Files:**
- Modify: resources/js/Pages/UserSide/Products/ProductShow.tsx
- Test: resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts

**Interfaces:**
- Consumes the existing CustomerFooterReveal component and its ReactNode children contract.
- Produces the same responsive footer behavior already used by Products, Repairs, Checkout, profiles, and orders.

- [x] **Step 1: Extend the footer contract with the product-detail page**

Add ProductShow to the existing customerPageSources array in CustomerFooter.reveal.test.ts:

~~~ts
const customerPageSources = [
  'Pages/UserSide/Orders/Checkout.tsx',
  'Pages/UserSide/Products/Products.tsx',
  'Pages/UserSide/Products/ProductShow.tsx',
  'Pages/UserSide/Repairs/Repair.tsx',
  // existing entries continue unchanged
];
~~~

- [x] **Step 2: Run the focused footer test and verify it fails**

Run:

~~~powershell
pnpm exec vitest run resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts
~~~

Expected result: FAIL because ProductShow.tsx does not yet contain CustomerFooterReveal.

- [x] **Step 3: Wrap the product page content with the existing footer shell**

Add this import to ProductShow.tsx:

~~~tsx
import { CustomerFooterReveal } from '../../../components/common/CustomerFooter';
~~~

Change the page return from:

~~~tsx
return (
  <>
    <Head title={product.name} />
    <div className="userside-product-show-page min-h-screen bg-white font-outfit antialiased">
      {/* existing product page content */}
    </div>

    {/* Virtual 3D Showroom Modal */}
~~~

to:

~~~tsx
return (
  <>
    <Head title={product.name} />
    <CustomerFooterReveal>
      <div className="userside-product-show-page min-h-screen bg-white font-outfit antialiased">
        {/* existing product page content */}
      </div>
    </CustomerFooterReveal>

    {/* Virtual 3D Showroom Modal */}
~~~

Only add the opening and closing wrapper around the existing page-content div. Do not move or wrap the Virtual 3D Showroom or image lightbox modal siblings.

- [x] **Step 4: Run the focused footer and product layout tests**

Run:

~~~powershell
pnpm exec vitest run resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts resources/js/Pages/UserSide/Products/ProductShow.desktop-layout.test.ts resources/js/Pages/UserSide/Products/ProductShow.dark-mode.test.ts
~~~

Expected result: PASS, with the existing product layout contracts unchanged and ProductShow included in the shared footer contract.

---

### Task 3: Run the final gates, generate the production bundle, and push the feature branch

**Files:**
- Generate: public/build/**
- Review: source/test changes from Tasks 1-2 and the already committed design/plan docs

**Interfaces:**
- No runtime interface changes beyond removal of the drawer's presentation-only confirmation.
- The generated Vite manifest/assets must describe the final source revision.

- [x] **Step 1: Review the intended diff and preserve unrelated changes**

Run:

~~~powershell
git status --short
git diff -- resources/js/Pages/UserSide/Shared/Navigation.tsx resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/Pages/UserSide/Products/ProductShow.tsx resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts
~~~

Expected result: only the four task files are modified by this work; existing ERP/HR changes, package-lock.json, .pnpm-store/, .superpowers/, and DESIGN.md remain untouched and unstaged.

- [x] **Step 2: Run the full frontend suite**

Run:

~~~powershell
pnpm run test:frontend
~~~

Expected result: Vitest completes successfully with no failures.

- [x] **Step 3: Fetch and rebase before the final production build**

Run the repository workflow commands:

~~~powershell
git fetch origin --prune
git rebase origin/solespace-b
~~~

If a conflict occurs, stop and inspect it; resolve only with the user-intended changes preserved, then continue the rebase. Do not reset or force-push.

- [x] **Step 4: Build the final public/build once**

Run:

~~~powershell
pnpm run build
~~~

Expected result: Vite completes successfully and refreshes the tracked production manifest/assets from the final source revision.

- [x] **Step 5: Run final hygiene and review gates**

Run:

~~~powershell
git diff --check
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
git status --short
~~~

Confirm that source/test changes, the design/plan documentation, and fresh public/build are intentional, while unrelated working-tree files remain unstaged. Frontend lint/type-check are N/A because this repository has no committed TypeScript config or frontend lint script.

- [x] **Step 6: Stage only intended files and commit the implementation with the fresh build**

Run:

~~~powershell
git add resources/js/Pages/UserSide/Shared/Navigation.tsx resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/Pages/UserSide/Products/ProductShow.tsx resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts public/build docs/superpowers/plans/2026-09-04-product-detail-cart-feedback-footer.md
git diff --cached --check
git diff --cached --stat
git commit -m "fix: simplify cart feedback and add product footer"
~~~

Do not stage the unrelated files listed in the existing working tree.

- [ ] **Step 7: Push only the feature branch**

Run:

~~~powershell
git push --progress -u origin feature/monochrome-erp-theme-clean
~~~

Expected result: the implementation commit and fresh public/build are available on the feature branch; no pull request is created and solespace-b is not modified.
