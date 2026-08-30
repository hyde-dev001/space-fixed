# All Customer Page Transition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand the existing shared page-transition curtain to every listed customer-facing page while keeping ERP/admin and non-page routes excluded.

**Architecture:** Keep the existing `CustomerPageTransition` component, CSS, and Inertia lifecycle unchanged. Expand only the pure pathname policy and its tests, using exact static routes plus anchored dynamic patterns; then regenerate `public/build`.

**Tech Stack:** React 18, Inertia 2, TypeScript, Vitest, Vite 7, pnpm.

## Global Constraints

- Cover all listed customer routes, including customer auth and shop-owner registration/2FA pages.
- Exclude `/erp/*`, `/admin/*`, shop-owner operational pages, privileged auth, APIs, binary downloads, and signed email-verification actions.
- Do not add dependencies, page wrappers, network requests, scroll listeners, or animation-frame state.
- Preserve the existing white curtain, black `SOLESPACE`, reduced-motion behavior, fallback timeout, footer animation, and initial loader.
- Stage only feature files and fresh `public/build`; preserve unrelated dirty files.

---

### Task 1: Extend the route policy with failing coverage tests

**Files:**
- Modify: `resources/js/utils/__tests__/customerPageTransition.test.ts`

**Interfaces:**
- Tests continue to target `isCustomerTransitionPath(pathname: string): boolean` and `shouldStartCustomerPageTransition(currentUrl: string, destinationUrl: string): boolean`.

- [ ] **Step 1: Add failing tests for every route group.** Add eligible cases for `/repair-process`, `/repair-shop/42`, `/shop-profile/42`, `/shop-profile/42/virtual-showroom`, `/articles`, `/download`, `/checkout`, `/payment`, `/order-success`, `/payment-failed`, `/customer-profile`, `/messages`, `/message`, `/message/42`, `/customer/conversations`, `/tracking/shipments/42`, `/tracking/shipments/42/proofs/7`, `/tracking/shipments/42/attempts/3/proof`, `/notifications`, `/notifications/settings`, `/login`, `/register`, `/forgot-password`, `/otp`, `/new-password`, `/email/verify`, `/shop-owner-register`, and `/shop-owner/two-factor`.
- [ ] **Step 2: Add failing exclusion and depth tests.** Assert false for `/api/search/suggestions`, `/api/customer/badge-counts`, `/apk/download`, `/email/verify/1/hash`, `/repair-shop/1/extra`, `/shop-profile/1/virtual-showroom/extra`, `/tracking/shipments/1/extra`, `/erp/hr`, `/admin`, and `/shop-owner/dashboard`.
- [ ] **Step 3: Add cross-route behavior tests.** Assert eligible-to-eligible navigation returns true, eligible-to-excluded and excluded-to-eligible return false, and query/hash-only changes return false.
- [ ] **Step 4: Run the focused test and verify it fails for missing policy coverage.** Run `node_modules/.bin/vitest.cmd run resources/js/utils/__tests__/customerPageTransition.test.ts`. Expected: FAIL on the newly added paths while existing tests still run.

### Task 2: Implement the complete explicit customer pathname allowlist

**Files:**
- Modify: `resources/js/utils/customerPageTransition.ts`
- Test: `resources/js/utils/__tests__/customerPageTransition.test.ts`

**Interfaces:**
- Preserve both existing exported function names and signatures so `CustomerPageTransition.tsx` requires no interface change.

- [ ] **Step 1: Update the static route set.** Add `/repair-process`, `/articles`, `/download`, `/checkout`, `/payment`, `/order-success`, `/payment-failed`, `/customer-profile`, `/messages`, `/message`, `/customer/conversations`, `/notifications`, `/notifications/settings`, `/login`, `/register`, `/forgot-password`, `/otp`, `/new-password`, `/email/verify`, `/shop-owner-register`, and `/shop-owner/two-factor` to the existing static set.
- [ ] **Step 2: Add anchored dynamic patterns.** Extend `isCustomerTransitionPath` with these exact-depth patterns:

```ts
/^\/products\/[^/]+$/
/^\/repair-shop\/[^/]+$/
/^\/shop-profile\/[^/]+(?:\/virtual-showroom)?$/
/^\/message\/[^/]+$/
/^\/tracking\/shipments\/[^/]+(?:\/proofs\/[^/]+|\/attempts\/[^/]+\/proof)?$/
```

Keep one trailing-slash normalization and pathname-only comparison. Do not permit API prefixes, nested extras, or signed verification parameters.
- [ ] **Step 3: Run the focused policy test and verify it passes.** Run `node_modules/.bin/vitest.cmd run resources/js/utils/__tests__/customerPageTransition.test.ts`. Expected: PASS for all eligible route groups, exclusions, depth boundaries, and same-path cases.
- [ ] **Step 4: Run the existing transition lifecycle tests.** Run `node_modules/.bin/vitest.cmd run resources/js/components/common/__tests__/CustomerPageTransition.test.tsx resources/js/__tests__/appProviderBoundary.test.ts`. Expected: PASS, confirming no component, root mount, URL-object, or cleanup regression.
- [ ] **Step 5: Commit the policy expansion.** Run `git add resources/js/utils/customerPageTransition.ts resources/js/utils/__tests__/customerPageTransition.test.ts` then `git commit -m "feat: cover all customer page transitions"`.

### Task 3: Run final verification and refresh the build

**Files:**
- Modify: `public/build/*` through the production build command only

- [ ] **Step 1: Run the complete frontend suite.** Run `node_modules/.bin/vitest.cmd run`. Expected: all existing tests and the expanded transition tests pass.
- [ ] **Step 2: Build fresh production assets.** Run `node_modules/.bin/vite.cmd build`. Expected: Vite completes successfully and refreshes `public/build`.
- [ ] **Step 3: Run browser smoke verification.** With the local app, navigate between representative routes from product, repair, shop/showroom, checkout/payment, orders/repairs, messages/tracking, notifications, and auth. Confirm the curtain is brief and centered, no horizontal overflow or scroll lock occurs, and ERP/admin routes never show it. Record external-resource console errors separately if the sandbox blocks them.
- [ ] **Step 4: Run hygiene checks.** Run `git diff --check`, `git status --short`, and `git diff --stat`. Expected: no whitespace errors; unrelated ERP/package-lock/.pnpm-store/staff-article/DESIGN.md changes remain unstaged.
- [ ] **Step 5: Commit the generated build.** Run `git add public/build` then `git commit -m "build: refresh all customer transition assets"`.
- [ ] **Step 6: Push according to `docs/git-workflow.md`.** Run `git fetch origin --prune`; if rebase is blocked by unrelated dirty files, do not stash or alter them—report the blocker and push only the committed feature branch with `git push origin feature/monochrome-erp-theme-clean`. The user will create the PR.
