# Customer Page Transition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fast shared white `SOLESPACE` curtain between eligible customer storefront pages without changing the landing footer reveal or ERP/admin flows.

**Architecture:** Keep a pure route allowlist utility separate from one React transition component. Mount the component once in `resources/js/app.jsx`; it listens to Inertia lifecycle events and uses CSS opacity/transform/visibility for the visual effect. The existing first-load loader remains independent.

**Tech Stack:** Laravel 12, Inertia 2, React 18, JavaScript/TSX, CSS, Vitest, Testing Library, Vite 7, pnpm.

## Global Constraints

- Eligible paths: `/`, `/products`, `/products/{slug}`, `/my-orders`, `/my-repairs`, `/repair-services`, `/services`.
- Exclude checkout, payment/order-result, profile, messaging, notifications, tracking, shop profiles, auth, downloads, repair-shop detail, shop-owner, ERP, and super-admin routes.
- No backend, page-data, footer, or owner-layout changes; no new dependency/request/image/font.
- Animate only opacity and transform, with visibility/pointer-events for state; target total visual duration under 400ms.
- No scroll lock, focus trap, focus movement, scroll listener, or per-frame React state.
- Support `prefers-reduced-motion: reduce` and use a bounded fallback close.
- Preserve unrelated working-tree changes and stage only feature files.

---

### Task 1: Define the customer route policy

**Files:**
- Create: `resources/js/utils/customerPageTransition.ts`
- Test: `resources/js/utils/__tests__/customerPageTransition.test.ts`

**Interfaces:**
- `isCustomerTransitionPath(pathname: string): boolean`
- `shouldStartCustomerPageTransition(currentUrl: string, destinationUrl: string): boolean`

- [ ] **Step 1: Write the failing tests.** Cover `/`, `/products`, `/products/<slug>`, `/my-orders`, `/my-repairs`, `/repair-services`, `/services`; reject `/products/a/b`, `/checkout`, `/repair-shop/1`, `/erp/hr`, and `/admin`; ignore query/hash differences; reject same-path navigation.
- [ ] **Step 2: Run the focused test and verify failure.** Run `node_modules/.bin/vitest.cmd run resources/js/utils/__tests__/customerPageTransition.test.ts`. Expected: FAIL because the module and exports do not exist.
- [ ] **Step 3: Implement the pure utility.** Normalize with `new URL(url, window.location.origin).pathname`, trim one trailing slash, allow the six static paths plus `/products/[^/]+`, and return true only when source and destination are eligible and their normalized pathnames differ.
- [ ] **Step 4: Run the focused test and verify it passes.** Run `node_modules/.bin/vitest.cmd run resources/js/utils/__tests__/customerPageTransition.test.ts`. Expected: PASS for all allowlist, exclusion, normalization, and same-path cases.
- [ ] **Step 5: Commit the task.** Run `git add resources/js/utils/customerPageTransition.ts resources/js/utils/__tests__/customerPageTransition.test.ts` then `git commit -m "test: define customer transition route policy"`.

### Task 2: Implement the shared Inertia lifecycle component

**Files:**
- Create: `resources/js/components/common/CustomerPageTransition.tsx`
- Test: `resources/js/components/common/__tests__/CustomerPageTransition.test.tsx`

**Interfaces:**
- Default `CustomerPageTransition` component with no required props.
- One node with `data-testid="customer-page-transition"`, `data-state="hidden|visible|leaving"`, and `aria-hidden="true"`.

- [ ] **Step 1: Write failing lifecycle tests.** Mock `@inertiajs/react` router events with controllable `start`, `finish`, `error`, and `cancel` callbacks. With `window.history` at `/products`, assert an eligible `/services` start changes state to `visible`, terminal events change it to `leaving`, `/erp/hr` does not open, and unmount removes every subscription.
- [ ] **Step 2: Run the focused test and verify failure.** Run `node_modules/.bin/vitest.cmd run resources/js/components/common/__tests__/CustomerPageTransition.test.tsx`. Expected: FAIL because the component does not exist.
- [ ] **Step 3: Implement the component.** Subscribe once in `useEffect` to `start`, `finish`, `error`, and `cancel`; use the Task 1 functions and `window.location.href`; clear one fallback timer on terminal events and unmount; open only for eligible source/destination; close to `leaving` on terminal events and then to `hidden` after the CSS exit duration; use a 700ms maximum fallback. Render exactly one decorative curtain and text `SOLESPACE`.
- [ ] **Step 4: Run the focused test and verify it passes.** Run `node_modules/.bin/vitest.cmd run resources/js/components/common/__tests__/CustomerPageTransition.test.tsx`. Expected: PASS for lifecycle, exclusion, terminal closure, fallback, and cleanup behavior.
- [ ] **Step 5: Commit the task.** Run `git add resources/js/components/common/CustomerPageTransition.tsx resources/js/components/common/__tests__/CustomerPageTransition.test.tsx` then `git commit -m "feat: add customer page transition lifecycle"`.

### Task 3: Mount once and style the curtain

**Files:**
- Modify: `resources/js/app.jsx`
- Modify: `resources/css/app.css` beside the existing app-loader styles
- Test: `resources/js/__tests__/appTransitionMount.test.tsx`

- [ ] **Step 1: Write the failing mount assertion.** Mock the Inertia app and providers, render the root provider tree, and assert exactly one `CustomerPageTransition` test id is present.
- [ ] **Step 2: Run the assertion and verify failure.** Run `node_modules/.bin/vitest.cmd run resources/js/__tests__/appTransitionMount.test.tsx`. Expected: FAIL because the root does not mount the component.
- [ ] **Step 3: Mount the component once.** Import `CustomerPageTransition` in `resources/js/app.jsx` and render it once alongside `{children}` inside `ApplicationProviders`; do not add it to pages or layouts.
- [ ] **Step 4: Add the CSS.** Use a fixed `inset: 0` white layer, black centered wordmark, `z-index: 1000`, `opacity`, `transform`, `visibility`, and `pointer-events`. Use 220ms enter and 260ms leave transitions. Hidden must be non-interactive; visible must cover the viewport; leaving must reveal the page. Add `@media (prefers-reduced-motion: reduce)` with a 1ms transition. Do not edit footer selectors.
- [ ] **Step 5: Run focused tests.** Run `node_modules/.bin/vitest.cmd run resources/js/utils/__tests__/customerPageTransition.test.ts resources/js/components/common/__tests__/CustomerPageTransition.test.tsx resources/js/__tests__/appTransitionMount.test.tsx`. Expected: PASS.
- [ ] **Step 6: Commit the task.** Run `git add resources/js/app.jsx resources/css/app.css resources/js/__tests__/appTransitionMount.test.tsx` then `git commit -m "feat: mount customer page curtain transition"`.

### Task 4: Verify browser behavior and refresh public assets

**Files:**
- Modify: `public/build/*` through the build command only

- [ ] **Step 1: Run frontend tests.** Run `pnpm run test:frontend`; expected: PASS. If the pnpm wrapper hangs, run the repository’s direct Vitest equivalent and record that exact result.
- [ ] **Step 2: Build fresh assets.** Run `pnpm run build`; expected: Vite succeeds and refreshes `public/build`.
- [ ] **Step 3: Run hygiene checks.** Run `git diff --check`, `git status --short`, and `git diff --stat`; expected: no whitespace errors and only feature files/build output are staged. Leave the existing ERP, package-lock, `.pnpm-store`, staff-article, and `DESIGN.md` changes untouched.
- [ ] **Step 4: Browser-check the contract.** With the local app, verify `/products` → `/services` and `/services` → `/products/<slug>` show a brief white centered `SOLESPACE` curtain; initial load does not double-show it; ERP routes do not show it; no overflow, scroll lock, focus jump, or persistent overlay occurs; narrow viewport and reduced-motion remain usable.
- [ ] **Step 5: Commit generated assets.** Run `git add public/build` then `git commit -m "build: refresh customer transition assets"`.
- [ ] **Step 6: Push only the feature branch after all checks pass.** Run `git fetch origin --prune`, rebase only if required, then `git push origin feature/monochrome-erp-theme-clean`. Do not push `solespace-b`; the user will create the PR.
