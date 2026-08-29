# BOY London-Inspired Desktop User-Side Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the approved BOY London-inspired editorial storefront direction to SoleSpace desktop user-side pages at `lg` and above while leaving mobile/tablet presentation and backend behavior intact.

**Architecture:** Keep all existing Inertia data, handlers, route helpers, contexts, and page-level business logic. Add a scoped desktop presentation layer in the shared user-side navigation and CSS, then apply focused desktop classes to existing page sections. Every new visual rule is guarded by the user-side scope and `@media (min-width: 1024px)` so the current mobile/tablet baseline remains the default.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest, Playwright.

## Global Constraints

- Target only desktop presentation at the existing `lg` breakpoint and above.
- Leave mobile and tablet layout, interaction behavior, and responsive breakpoints unchanged.
- Do not change Laravel routes, controllers, models, validation, authorization, API payloads, payment logic, cart persistence, order state transitions, repair workflows, or Inertia prop contracts.
- Reuse existing components, icons, data types, and route helpers.
- Do not copy BOY London logos, copyrighted imagery, exact brand assets, or proprietary typography.
- Use `pnpm` for frontend commands.
- Preserve unrelated working-tree changes.

## File map

- Modify `resources/js/Pages/UserSide/Shared/Navigation.tsx`: desktop header, utility strip, nav drawer, search/account/cart presentation while retaining existing behavior.
- Modify `resources/css/app.css`: scoped desktop user-side tokens, typography, grid, drawer, button, footer, and reduced-motion rules.
- Modify `resources/js/Pages/UserSide/Products/LandingPage.tsx`: desktop editorial storefront sections using existing props and links.
- Modify `resources/js/Pages/UserSide/Products/Products.tsx`: desktop catalog header, filters, product grid, and metadata treatment.
- Modify `resources/js/Pages/UserSide/Products/ProductShow.tsx`: desktop product detail split layout and purchase panel treatment.
- Modify `resources/js/Pages/UserSide/Orders/Checkout.tsx`, `payment.tsx`, `OrderSuccess.tsx`, `PaymentSuccess.tsx`, and `PaymentFailed.tsx`: desktop transaction hierarchy only.
- Modify `resources/js/Pages/UserSide/Orders/MyOrders.tsx`, `Profile/customerProfile.tsx`, `Tracking/ShipmentTracking.tsx`, `Communication/message.tsx`, and repair pages: shared desktop surface and status hierarchy only.
- Modify `resources/js/Pages/UserSide/Auth/*.tsx` and `Shared/Services.tsx` only where the existing shared navigation or page wrapper needs a desktop class; retain every form and handler.
- Add `resources/js/__tests__/userSideDesktopPresentation.test.ts`: source-level guard that desktop rules are scoped and backend-facing routes are not replaced.
- Add `docs/superpowers/specs/2026-08-29-boy-london-inspired-desktop-userside-design.md`: approved design source already written.

---

### Task 1: Establish scoped desktop user-side design tokens

**Files:**
- Modify: `resources/css/app.css`
- Test: `resources/js/__tests__/userSideDesktopPresentation.test.ts`

**Interfaces:**
- Consumes: current `html.userside-dark`, `#app`, Tailwind CSS 4, and existing user-side page class conventions.
- Produces: `.userside-desktop-scope` token variables and desktop-only utility selectors available to all UserSide pages.

- [ ] **Step 1: Add failing source guard tests**

Create a Vitest test that reads `resources/css/app.css` and asserts the desktop token block contains `@media (min-width: 1024px)`, `--userside-ink`, `--userside-canvas`, `--userside-surface`, and `prefers-reduced-motion`, and that no unscoped `body` rule is introduced after the new block.

```ts
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');

describe('desktop user-side design system', () => {
  it('keeps the new visual system desktop-scoped', () => {
    expect(css).toContain('@media (min-width: 1024px)');
    expect(css).toContain('--userside-ink');
    expect(css).toContain('--userside-canvas');
    expect(css).toContain('--userside-surface');
    expect(css).toContain('@media (prefers-reduced-motion: reduce)');
  });
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `pnpm exec vitest run resources/js/__tests__/userSideDesktopPresentation.test.ts`

Expected: FAIL because the scoped desktop token block does not exist yet.

- [ ] **Step 3: Add the scoped desktop token and utility block**

Append a single block to `resources/css/app.css` with `@media (min-width: 1024px)` and selectors under `.userside-desktop-scope`, defining the monochrome tokens, 8px rhythm, editorial heading scale, flat borders, button states, image-stage sizing, and reduced-motion override. Do not modify existing mobile/tablet selectors.

```css
@media (min-width: 1024px) {
  .userside-desktop-scope {
    --userside-ink: #111111;
    --userside-canvas: #ffffff;
    --userside-surface: #f5f5f5;
    --userside-muted: #707072;
    --userside-hairline: #e5e5e5;
    --userside-sale: #d30005;
    --userside-space: 8px;
    background: var(--userside-canvas);
    color: var(--userside-ink);
  }

  .userside-desktop-scope .userside-desktop-display {
    font-size: clamp(3rem, 6vw, 6rem);
    line-height: 0.9;
    letter-spacing: -0.04em;
    text-transform: uppercase;
  }

  .userside-desktop-scope .userside-desktop-button {
    min-height: 48px;
    border: 1px solid var(--userside-ink);
    border-radius: 9999px;
    padding: 0 24px;
    background: var(--userside-ink);
    color: var(--userside-canvas);
    transition: opacity 180ms ease, background-color 180ms ease, color 180ms ease;
  }

  .userside-desktop-scope .userside-desktop-button:hover {
    background: var(--userside-canvas);
    color: var(--userside-ink);
  }

  .userside-desktop-scope .userside-desktop-hairline {
    border-color: var(--userside-hairline);
  }
}

@media (prefers-reduced-motion: reduce) {
  .userside-desktop-scope *,
  .userside-desktop-scope *::before,
  .userside-desktop-scope *::after {
    scroll-behavior: auto !important;
    transition-duration: 0.01ms !important;
    animation-duration: 0.01ms !important;
  }
}
```

- [ ] **Step 4: Run the focused test and diff check**

Run: `pnpm exec vitest run resources/js/__tests__/userSideDesktopPresentation.test.ts` and `git diff --check`

Expected: PASS and no whitespace errors.

- [ ] **Step 5: Commit if Git permissions permit**

Run: `git add resources/css/app.css resources/js/__tests__/userSideDesktopPresentation.test.ts && git commit -m "style: add scoped desktop userside tokens"`

If `.git/index.lock` remains blocked, leave the files in the working tree and record the exact permission failure.

### Task 2: Refactor the shared desktop navigation chrome

**Files:**
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx`
- Modify: `resources/js/Pages/UserSide/Shared/navigationItems.ts` only if labels need desktop grouping without changing destinations
- Test: `resources/js/__tests__/userSideDesktopPresentation.test.ts`

**Interfaces:**
- Consumes: existing `useCart`, `usePage`, `useBadgeCounts`, `getCustomerNavItems`, `router`, `route`, search suggestion handlers, and logout handler.
- Produces: existing `Navigation` component with `.userside-desktop-scope` root and BOY-inspired desktop chrome; mobile/tablet branch remains behaviorally identical.

- [ ] **Step 1: Add source assertions for behavior preservation**

Extend the test to assert `Navigation.tsx` still contains `useCart`, `useBadgeCounts`, `handleSearch`, `handleLogout`, `router.visit`, `router.post`, and `effectiveCartCount`, and contains the desktop scope class.

- [ ] **Step 2: Run the test and verify the new assertion fails**

Run: `pnpm exec vitest run resources/js/__tests__/userSideDesktopPresentation.test.ts`

Expected: FAIL only on the new desktop scope assertion.

- [ ] **Step 3: Add the desktop presentation layer**

Update only the desktop JSX/classes in `Navigation.tsx`: add the promotional strip, centered SoleSpace lockup, icon actions with accessible labels, category grouping, and the existing search/account/cart panels inside the `.userside-desktop-scope` root. Use existing SVG/icon markup and route handlers. Keep mobile menu elements and mobile breakpoints untouched.

Required invariants:

```tsx
<div className="userside-desktop-scope">
  {/* Existing search, account, notification, message, and cart handlers remain wired here. */}
</div>
```

All new icon-only controls must retain `aria-label`, `type="button"`, visible focus styling, and a minimum 44px hit area. No new API call or state store is permitted in this task.

- [ ] **Step 4: Run focused navigation tests/build parse**

Run: `pnpm exec vitest run resources/js/__tests__/userSideDesktopPresentation.test.ts resources/js/Pages/UserSide/Auth/__tests__/UserLogin.test.tsx`

Expected: PASS.

- [ ] **Step 5: Commit if Git permissions permit**

Run: `git add resources/js/Pages/UserSide/Shared/Navigation.tsx resources/js/Pages/UserSide/Shared/navigationItems.ts resources/js/__tests__/userSideDesktopPresentation.test.ts && git commit -m "style: refresh desktop userside navigation"`

### Task 3: Apply editorial desktop treatment to storefront and catalog pages

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx`
- Modify: `resources/js/Pages/UserSide/Products/Products.tsx`
- Modify: `resources/js/Pages/UserSide/Products/ProductShow.tsx`
- Modify: `resources/js/Pages/UserSide/Profile/ShopProfile.tsx`
- Modify: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx`
- Modify: `resources/js/Pages/UserSide/Shared/Services.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/Repair.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/repairShow.tsx`
- Test: existing `resources/js/Pages/UserSide/Products/ProductShow.dark-mode.test.ts`, `showroomRooms.test.ts`, and focused source guard

**Interfaces:**
- Consumes: existing page props, product/shop data, filters, product variant handlers, cart actions, showroom controls, and repair links.
- Produces: desktop-only editorial layout classes without changing data shape, route destinations, or event handlers.

- [ ] **Step 1: Add desktop scope classes to page roots and major sections**

For each listed page, add `.userside-desktop-scope` to the existing user-side root or wrap only the desktop presentation container. Add semantic classes for page title, campaign hero, product grid, image stage, metadata, CTA, and footer. Use existing data-driven loops and links.

- [ ] **Step 2: Rework desktop catalog geometry**

Set the desktop products page to use a 3–4 column grid based on available width, with 8px gutters, fixed image aspect-ratio boxes, flat metadata rows, explicit sale styling, and compact filters. Preserve the existing mobile/tablet grid classes by adding only `lg:` classes or desktop-scoped selectors.

- [ ] **Step 3: Rework desktop product detail geometry**

Use a desktop two-column layout with the gallery/image stage on the left and the existing size/color/stock/purchase controls on the right. Keep the existing 360 viewer, voucher claim, shop/report actions, and add-to-cart handler in their current component flow.

- [ ] **Step 4: Run storefront tests and build**

Run: `pnpm exec vitest run resources/js/Pages/UserSide/Products/ProductShow.dark-mode.test.ts resources/js/Pages/UserSide/Products/showroomRooms.test.ts resources/js/__tests__/userSideDesktopPresentation.test.ts` and `pnpm run build`

Expected: all selected tests pass and Vite exits 0.

- [ ] **Step 5: Commit if Git permissions permit**

Run: `git add resources/js/Pages/UserSide/Products resources/js/Pages/UserSide/Profile/ShopProfile.tsx resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx resources/js/Pages/UserSide/Shared/Services.tsx resources/js/Pages/UserSide/Repairs/Repair.tsx resources/js/Pages/UserSide/Repairs/repairShow.tsx && git commit -m "style: apply editorial desktop storefront"`

### Task 4: Apply desktop transaction, account, repair, communication, and auth treatment

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/Checkout.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/OrderSuccess.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/PaymentSuccess.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/PaymentFailed.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/UserSide/Profile/customerProfile.tsx`
- Modify: `resources/js/Pages/UserSide/Tracking/ShipmentTracking.tsx`
- Modify: `resources/js/Pages/UserSide/Communication/message.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`
- Modify: `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx`
- Modify: `resources/js/Pages/UserSide/Auth/*.tsx`
- Test: existing UserSide auth, order, repair, profile, and tracking tests

**Interfaces:**
- Consumes: existing forms, uploads, validation errors, status values, polling, payment redirects, shipping estimates, address components, and authorization-aware controls.
- Produces: consistent desktop presentation for transaction and account surfaces while preserving every existing workflow.

- [ ] **Step 1: Add scoped desktop roots without changing handlers**

Add the same `.userside-desktop-scope` root treatment to each page. Do not rename form fields, route URLs, API paths, status values, or test-visible accessible names.

- [ ] **Step 2: Apply hierarchy to checkout and payment pages**

Use a desktop split layout: order summary and totals in one column, address/payment/shipping controls in the other. Keep validation messages adjacent to fields, preserve loading/disabled states, and keep payment redirect and retry behavior unchanged.

- [ ] **Step 3: Apply hierarchy to orders, tracking, repairs, profile, messages, and auth**

Use flat white surfaces, hairline dividers, uppercase section labels, black primary actions, and red only for destructive/sale/error semantics. Keep existing mobile/tablet utility classes and components as the default below desktop.

- [ ] **Step 4: Run focused functional tests**

Run: `pnpm run test:frontend`

Expected: the frontend test command passes with 0 failures. If the repository test script runs a broader suite, record the exact count and address failures caused by the change before proceeding.

- [ ] **Step 5: Commit if Git permissions permit**

Run: `git add resources/js/Pages/UserSide/Orders resources/js/Pages/UserSide/Profile/customerProfile.tsx resources/js/Pages/UserSide/Tracking resources/js/Pages/UserSide/Communication resources/js/Pages/UserSide/Repairs resources/js/Pages/UserSide/Auth && git commit -m "style: align desktop account and checkout surfaces"`

### Task 5: Review, browser-verify desktop, and protect mobile/tablet

**Files:**
- Modify: only files with verified visual defects from Tasks 1–4
- Test: browser verification script under `tmp/` if needed; existing frontend and Laravel suites

**Interfaces:**
- Consumes: completed desktop presentation layer and all existing page flows.
- Produces: fresh verification evidence for desktop widths, protected mobile/tablet widths, route behavior, and diff hygiene.

- [ ] **Step 1: Run source and dead-code checks**

Run: `rg -n "userside-desktop-scope|userside-desktop-display|userside-desktop-button" resources/js resources/css/app.css` and inspect changed files for unused imports, unreachable branches, stale classes, and altered route/API strings.

- [ ] **Step 2: Run required quality gates**

Run: `git diff --check`, `pnpm run test:frontend`, and `pnpm run build`.

Expected: each command exits 0. There is no committed TypeScript compiler or frontend lint script, so do not report type-check/lint success unless tooling exists and was run.

- [ ] **Step 3: Run Playwright reconnaissance and interaction checks**

First run: `python .agents/skills/webapp-testing/scripts/with_server.py --help`.

Then use a native Playwright script against the local app to visit `/`, `/products`, one product detail route if available, `/login`, and the authenticated pages available in the test environment. At viewport widths 1024×900, 1280×900, and 1440×900, verify no horizontal overflow, header/drawer/search/cart interactions, and product CTA reachability. Capture screenshots only as test evidence.

- [ ] **Step 4: Verify the protected responsive boundary**

Use the same Playwright script at 768×1024 and 390×844. Verify the mobile/tablet navigation and primary page content still render without horizontal overflow and that no desktop-only scope rule applies below 1024px.

- [ ] **Step 5: Run relevant Laravel regression tests**

Run: `composer test -- --filter='UserSide|Checkout|Customer|Payment|Order'`

Expected: relevant backend contract tests pass. If the project’s Composer script does not accept this filter, run `composer test` and report the exact result.

- [ ] **Step 6: Complete the review record**

Record Standards, Spec, simplification, TypeScript/React performance, security, reuse, dead-code, and verification results in the final handoff. Mark security as `N/A` only if no auth, input, upload, payment, or endpoint behavior changed; otherwise inspect the changed flow with the existing security rules.
