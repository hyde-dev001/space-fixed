# Repair Shop Layout, ERP Sidebar, and Shop Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Stack the repair-shop rating below shop information, make ERP sidebar activation exact for the logistics dashboard, and render all eligible shop/showroom profiles in customer search.

**Architecture:** Preserve the existing Laravel search endpoint and its public approved-shop filters. Make surgical changes to the repair page, ERP sidebar matcher, and shared landing search modal, with regression tests at each boundary. The generated Vite output is refreshed only after source verification.

**Tech Stack:** Laravel 12, PHPUnit, React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Preserve unrelated working-tree changes and do not edit `.env`, `vendor/`, or `node_modules/`.
- Keep public search limited to approved shop owners; pending and rejected shops must not be rendered.
- Reuse the existing `/api/search/suggestions` response and route names; do not add dependencies.
- Stage explicit paths only and include a fresh `public/build` after the final frontend build.
- Before the final build/push, fetch and rebase the feature branch on `origin/solespace-b`.

---

### Task 1: Lock the requested regressions with tests

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Modify: `resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts`
- Modify: `tests/Feature/UserSide/SearchSuggestionsTest.php`

**Interfaces:**
- Tests consume the existing repair page source, `AppSidebarERP`, shared navigation source, and `GET /api/search/suggestions`.
- Tests produce executable acceptance coverage for the three requested UI/API behaviors.

- [ ] **Step 1: Assert the rating is a full-width section below the information content.**

Replace the old two-column assertions in `repairShow.info-layout.test.ts` with assertions for `flex flex-col`, the `customer-rating-landscape` marker, and the absence of the old responsive grid.

- [ ] **Step 2: Add the exact logistics shipments active-state regression.**

Set the mocked URL to `/erp/logistics/shipments`, render `AppSidebarERP`, and assert `Logistics` is active while `Logistics Dashboard` is inactive.

- [ ] **Step 3: Assert the landing dialog renders shop results.**

Extend `Navigation.contract.test.ts` to require the shop result section, profile link, optional showroom link, and a search-dialog label that covers both products and shops.

- [ ] **Step 4: Cover all approved shop types and showroom entitlement in the API test.**

Create approved `retail`, `repair`, and `both` shops plus a pending shop; assert `query=shop` returns the three approved records. Create an active subscription for one approved shop and assert `query=showroom` returns it and its `virtual_showroom_url`.

- [ ] **Step 5: Run the focused tests and confirm the new assertions fail for the current implementation.**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
php artisan test tests/Feature/UserSide/SearchSuggestionsTest.php
```

Expected: the rating layout, sidebar route, and landing shop-rendering assertions fail; the API assertions should confirm whether the existing endpoint already satisfies the requested approved-shop/showroom behavior. If an API assertion exposes a real gap, fix the endpoint minimally in Task 2 while retaining the same JSON fields.

### Task 2: Implement the minimal behavior changes

**Files:**
- Modify: `resources/js/Pages/UserSide/Repairs/repairShow.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx`
- Modify only if the API regression fails: `app/Http/Controllers/UserSide/LandingPageController.php`

**Interfaces:**
- The shared navigation continues to consume `SearchSuggestionShop` fields: `id`, `name`, `location`, `image`, `url`, and `virtual_showroom_url`.
- The search endpoint continues to return `products`, `shops`, and `categories` arrays with the existing field names.

- [ ] **Step 1: Stack the repair shop sections.**

Change only the information/rating wrapper layout to a vertical flex flow. Keep existing review state, handlers, links, and data untouched. Put the rating heading and summary/empty state in a responsive landscape row and retain neutral borders/backgrounds.

- [ ] **Step 2: Make only the dashboard route exact.**

In `isActive`, return `baseUrl === '/erp/logistics'` for `erp.logistics.dashboard` before generic descendant matching. Leave other route matching, permission filtering, and menu visibility unchanged.

- [ ] **Step 3: Render shop cards in the landing search modal.**

Add a `Shops`/dynamic shop-category section after products, using the existing shop suggestion data. Include profile image fallback, profile navigation, location, and a showroom action when the API supplies a URL. Keep the modal scrollable and accessible; retain product cards and no-results behavior.

- [ ] **Step 4: Apply a minimal API correction only if Task 1 proves it necessary.**

Keep `where('status', 'approved')`, preserve the existing business-type capability filters for explicit `repair`/`retail` queries, and ensure the generic `shop` query does not add a business-type restriction. Use the existing query builder bindings and eager/exists loading; do not expose extra shop-owner fields.

- [ ] **Step 5: Run focused tests until green.**

Run the same Vitest and PHPUnit commands from Task 1. Expected: all focused tests pass.

### Task 3: Review, verify, build, and push

**Files:**
- Modify: `docs/superpowers/specs/2026-09-05-repair-search-and-sidebar-layout-design.md`
- Modify: `docs/superpowers/plans/2026-09-05-repair-search-and-sidebar-layout.md`
- Modify: `public/build/**` (generated only by Vite)

**Interfaces:**
- The source, tests, and docs remain explicit and reviewable; generated assets match the final source.

- [ ] **Step 1: Perform sequential standards, spec, simplification, frontend, security, and reuse/dead-code reviews.**

Check the diff for unchanged business logic, repeated UI complexity, unsafe data exposure, unused imports, stale assertions, and accidental changes to unrelated worktree files. Record `N/A` for backend production/security changes if Task 1 confirms no endpoint code change.

- [ ] **Step 2: Run quality gates before the build.**

Run:

```powershell
git diff --check
.\node_modules\.bin\vitest.cmd run
php artisan test tests/Feature/UserSide/SearchSuggestionsTest.php
```

Expected: no whitespace errors, the frontend suite passes, and the search feature test passes. No TypeScript/lint pass may be claimed because the repository does not provide those scripts/configuration.

- [ ] **Step 3: Rebase and refresh generated assets.**

Run `git fetch origin --prune` and `git rebase --autostash origin/solespace-b`. Temporarily stash only unrelated HR TSX files if needed for the build, run `.\node_modules\.bin\vite.cmd build`, restore the stash, and verify the generated `public/build` is fresh.

- [ ] **Step 4: Stage explicit files and commit.**

Stage only the changed repair/sidebar/search source and tests, the new docs, and `public/build` with `git add -f -- public/build`; leave unrelated tracked/untracked changes unstaged.

- [ ] **Step 5: Verify and push the authorized feature branch.**

Run `git diff --cached --check`, inspect `git diff --cached --stat`, commit the scoped changes, push `feature/monochrome-erp-theme-clean`, and confirm local/remote hashes match. Do not create a PR.
