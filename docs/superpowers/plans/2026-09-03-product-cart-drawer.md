# Product Add-to-Cart Side Drawer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Open the shared customer cart drawer and show the added item after a successful product Add to Cart action.

**Architecture:** Extend the existing `cart:added` browser event with opt-in drawer metadata. `AddToCartButton` emits that metadata only after the server confirms success; shared `Navigation` listens for the opt-in event, opens the existing drawer, refreshes `/api/cart`, and renders the confirmation status. Checkout and logout event producers remain passive because they do not set the flag.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Vitest, Testing Library, Vite 7, Tailwind CSS 4.

## Global Constraints

- Reuse the existing `AddToCartButton`, `cart:added` event, `Navigation` drawer, and `/api/cart` endpoint.
- Do not add dependencies, backend routes, database changes, or duplicate cart request logic.
- Preserve authentication, CSRF, stock validation, Buy Now, error feedback, and existing cart count synchronization.
- Do not open the drawer for Checkout quantity updates, logout, failed adds, guest attempts, or Buy Now.
- Use accessible live-region feedback and keep existing drawer keyboard/overlay behavior.
- Preserve unrelated working-tree changes.

---

### Task 1: Add successful-add event metadata

**Files:**
- Modify: `resources/js/types/cart-events.ts:2-5`
- Modify: `resources/js/components/CartActions.tsx:158-184`
- Test: `resources/js/components/__tests__/CartActions.test.tsx`

**Interfaces:**
- Produces `CartAddedItem` and optional `openDrawer`/`item` fields on `CartAddedEventDetail`.
- `AddToCartButton` dispatches `{ added, total, openDrawer: true, item }` only after `response.ok && data.success`.

- [x] **Step 1: Write the failing test**

Render `AddToCartButton` with an authenticated user, stub a successful `/api/cart/add` response, listen for `cart:added`, click the button, and assert the event includes `openDrawer: true` plus the product name, price, selected image, size, color, and quantity.

- [x] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/components/__tests__/CartActions.test.tsx
```

Expected: FAIL because the current event detail contains only `added` and `total`.

- [x] **Step 3: Implement the minimal event contract and dispatch payload**

Add the typed optional metadata to `cart-events.ts`. In the existing success branch of `CartActions.tsx`, dispatch the product data from the already-resolved `product`, `selectedImage`, `size`, `color`, and `addQty`; remove only the blocking success SweetAlert so the side drawer can be the confirmation surface. Leave the request, failure branch, and other SweetAlerts intact.

- [x] **Step 4: Run the focused test to verify it passes**

Run the same Vitest command. Expected: PASS, with no changes to existing event producers.

- [x] **Step 5: Commit**

```powershell
git add resources/js/types/cart-events.ts resources/js/components/CartActions.tsx resources/js/components/__tests__/CartActions.test.tsx
git commit -m "feat: include added item in cart event"
```

### Task 2: Auto-open and refresh the shared cart drawer

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx:1-10,99-104,570-610,1460-1485`
- Test: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`

**Interfaces:**
- Consumes `addCartAddedListener`, `removeCartAddedListener`, and `CartAddedEvent`.
- Uses a numeric `cartRefreshKey` to retrigger the existing cart loader without duplicating its fetch logic.
- Renders a `role="status"` message from the optional event item.

- [x] **Step 1: Write the failing Navigation contract assertions**

Add assertions requiring the shared Navigation source to import the typed cart-added listener, guard on `event.detail.openDrawer`, close competing drawers, set `cartDrawerOpen`, refresh via a key, clear the status when closed, and render `Added to cart`.

- [x] **Step 2: Run the focused Navigation test to verify it fails**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
```

Expected: FAIL because Navigation currently has no cart-added listener or confirmation status.

- [x] **Step 3: Implement the shared listener and confirmation**

Subscribe once in `Navigation`. For flagged events, close the landing/account drawers, open the cart drawer, store the added item, and increment `cartRefreshKey`. Add the key to the existing drawer-loading effect dependencies. Render a compact live status above the existing cart contents and clear it when the drawer closes. Keep all existing manual cart-open handlers and drawer markup behavior.

- [x] **Step 4: Run the focused tests to verify they pass**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/components/__tests__/CartActions.test.tsx
```

Expected: PASS.

- [x] **Step 5: Commit**

```powershell
git add resources/js/Pages/UserSide/Shared/Navigation.tsx resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
git commit -m "feat: open cart drawer after product add"
```

### Task 3: Verify the complete customer flow

**Files:**
- Test: existing frontend test suite

- [x] **Step 1: Run the full frontend tests**

```powershell
.\node_modules\.bin\vitest.cmd run
```

Expected: all existing and new tests pass.

- [x] **Step 2: Build with the local Vite binary**

```powershell
.\node_modules\.bin\vite.cmd build --outDir .tmp-vite-build
```

Expected: Vite completes successfully; remove only the verified temporary output afterward.

- [x] **Step 3: Check diff hygiene and changed scope**

```powershell
git diff --check HEAD~2..HEAD
git status --short
```

Expected: no whitespace errors; only the pre-existing unrelated working-tree changes remain uncommitted.
