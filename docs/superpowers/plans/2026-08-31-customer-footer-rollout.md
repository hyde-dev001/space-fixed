# Customer Footer Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Use one responsive SoleSpace footer with a lightweight reveal animation on Checkout and the requested customer-facing pages.

**Architecture:** Keep the landing page's existing curtain footer behavior intact. Add a reusable `CustomerFooter` for ordinary pages, styled globally in `app.css`, and mount it at the bottom of each requested page without changing page data or routing.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Vite, Tailwind CSS.

## Global Constraints

- Limit the rollout to the listed customer-side pages.
- Preserve unrelated working-tree changes.
- Keep the animation transform/opacity-only and respect reduced-motion preferences.
- Generate a fresh `public/build` after frontend changes.

### Task 1: Shared footer

**Files:** Create `resources/js/components/common/CustomerFooter.tsx`; modify `resources/css/app.css`; test with the existing frontend suite.

- [ ] Define typed Explore, Support, and Community link groups.
- [ ] Render desktop columns, mobile disclosure groups, metadata, and the oversized SoleSpace wordmark.
- [ ] Add IntersectionObserver reveal with a no-observer fallback and reduced-motion CSS.
- [ ] Verify the component compiles and the footer classes have no horizontal overflow.

### Task 2: Page integration

**Files:** `Checkout.tsx`, `Products.tsx`, `Repair.tsx`, `MyOrders.tsx`, `myRepairs.tsx`, `customerProfile.tsx`, `ShopOwnerRegistration.tsx`, `apk.tsx`.

- [ ] Import `CustomerFooter` and place one instance after each page's main content.
- [ ] Replace the legacy Checkout-only footer with the shared footer.
- [ ] Keep category pages covered through the shared Products page.

### Task 3: Verification and delivery

- [ ] Run focused frontend tests and the full frontend test command.
- [ ] Run `git diff --check` and a fresh Vite build.
- [ ] Smoke-test representative routes in a local browser when available.
- [ ] Stage only this feature, `public/build`, and the plan; commit and push the current branch.
