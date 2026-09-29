# Customer Footer Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Use one responsive SoleSpace footer with the same fixed-under-curtain reveal architecture as the landing page on Checkout and the requested customer-facing pages.

**Architecture:** Keep the landing page's existing curtain footer behavior intact. Add a reusable `CustomerFooterReveal` shell that puts page content in an opaque foreground curtain, measures a transparent end spacer with `ResizeObserver`, and keeps the footer fixed underneath at `z-index: 0`. Native scrolling reveals the footer; no per-frame scroll transform is used.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Vite, Tailwind CSS.

## Global Constraints

- Limit the rollout to the listed customer-side pages.
- Preserve unrelated working-tree changes.
- Keep the footer reveal native-scroll-driven and respect reduced-motion preferences.
- Generate a fresh `public/build` after frontend changes.

### Task 1: Shared footer

**Files:** Create `resources/js/components/common/CustomerFooter.tsx`; modify `resources/css/app.css`; test with the existing frontend suite.

- [ ] Define typed Explore, Support, and Community link groups.
- [ ] Render desktop columns, mobile disclosure groups, metadata, and the oversized SoleSpace wordmark.
- [ ] Add the fixed footer, measured spacer, IntersectionObserver interaction gate, no-observer fallback, and reduced-motion CSS.
- [ ] Verify the component compiles and the footer classes have no horizontal overflow.

### Task 2: Page integration

**Files:** `Checkout.tsx`, `Products.tsx`, `Repair.tsx`, `MyOrders.tsx`, `myRepairs.tsx`, `customerProfile.tsx`, `ShopOwnerRegistration.tsx`, `apk.tsx`.

- [ ] Wrap each page in `CustomerFooterReveal` so the content curtain stays above the fixed footer.
- [ ] Replace the legacy Checkout-only footer with the shared footer.
- [ ] Keep category pages covered through the shared Products page.

### Task 3: Verification and delivery

- [ ] Run focused frontend tests and the full frontend test command.
- [ ] Run `git diff --check` and a fresh Vite build.
- [ ] Smoke-test representative routes in a local browser when available.
- [ ] Stage only this feature, `public/build`, and the plan; commit and push the current branch.
