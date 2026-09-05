# Role-Based ERP Article Guides Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a text-first Articles help center with a separate, complete bilingual catalog for every requested ERP audience while keeping server access and sidebar links scoped to the active account.

**Architecture:** The existing hub and detail components will consume a shared `ArticleCatalog` contract instead of Staff-only data. The server will render one fixed `articleAudience` per named route and a middleware gate will check the existing role, permission, business, registration, approval, and forced-password rules before the page is rendered. The browser will dynamically import only the catalog module for that audience; Shop Owner articles will use the same module with business and registration filters.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest, PHPUnit, pnpm.

## Global Constraints

- Regular Staff keeps `/erp/articles` and remains limited to the existing 32 Staff guides.
- The requested audiences are Regular Staff, Manager, Finance, HR, CRM, Cashier, Repairer, Inventory, Procurement, Logistics Dispatcher, and Shop Owner.
- Shop Owner guides must respect `registration_type` (`company` or `individual`) and `business_type` (`retail`, `repair`, or `both`); Logistics Rider has no catalog.
- Every article must have English and Tagalog title, purpose, prerequisites, exact page/menu, complete numbered steps, screen labels/statuses/buttons, next result/owner, recovery guidance, and same-catalog related links.
- Use simple workplace words in article copy; preserve exact UI labels such as `Pending`, `Processing`, `Under Review`, and `Submit` when users need to find them.
- Remove screenshot metadata, screenshot holders, lightbox behavior, screenshot-only tests, and all 94 WebP files. Do not add new media.
- In light mode ordinary article surfaces are white. Successful outcomes are green, rejected or failed outcomes are red, and neutral or warning outcomes remain white with neutral borders.
- The language control contains only keyboard-accessible `English` and `Tagalog` buttons; remove the language icon.
- Articles is the last item in every applicable employee or Shop Owner sidebar/group.
- Do not change ERP business logic, role definitions, permission definitions, transaction behavior, or force-password-change behavior.
- Use the existing Laravel routes, pages, permissions, role rules, and tests as article sources; do not describe controls the application does not expose.
- Use `pnpm`/the repository's existing Node entry points, run `git diff --check`, run focused tests before broad tests, and create a fresh `public/build` after the final rebase.
- Do not create a Pull Request; push only the feature branch so the user can create the PR.

---

## File Map

Create the shared contract and loaders in `resources/js/data/articleGuides.ts`, `resources/js/data/articleAudience.ts`, `resources/js/data/articleCatalogs/index.ts`, and `resources/js/data/articleCatalogs/roleCatalogFactory.ts`. Keep the existing 32 Staff entries in `resources/js/data/staffArticles.ts`, but make that module satisfy the shared contract and export the Staff catalog. Add one catalog module per requested non-Staff audience under `resources/js/data/articleCatalogs/`: `manager.ts`, `finance.ts`, `hr.ts`, `crm.ts`, `cashier.ts`, `repairer.ts`, `inventory.ts`, `procurement.ts`, `logisticsDispatcher.ts`, and `shopOwner.ts`.

Use `resources/js/utils/articleGuides.ts` for catalog-independent access filtering, search, category counts, and related links. Keep `resources/js/utils/staffArticles.ts` as a small compatibility wrapper for Staff callers during the migration, or remove it after all references and tests use the shared utility.

Use `app/Http/Controllers/Erp/ArticlesController.php` for audience-aware rendering and `app/Http/Middleware/EnsureArticleAudienceAccess.php` for the named audience gate. Register the middleware in `bootstrap/app.php`; add employee article routes to `routes/web.php` and Shop Owner article routes to `routes/shop-owner-erp.php`; add the Shop Owner route contracts to `config/shop_modules.php` so the existing ERP actor/context middleware recognizes the owner route.

Update `resources/js/Pages/ERP/Articles/Index.tsx`, `resources/js/Components/articles/ArticleHub.tsx`, and `resources/js/Components/articles/ArticleDetail.tsx` to use the shared catalog and audience base path. Delete `resources/js/Components/articles/ArticleScreenshot.tsx`, `resources/js/Components/articles/ArticleLightbox.tsx`, and their tests. Delete the screenshot-only files under `public/images/articles/staff/`.

Update `resources/js/layout/AppSidebar_ERP.tsx`, `resources/js/layout/AppSidebar_shopOwner.tsx`, and `resources/js/layout/CanonicalOwnerSidebar.tsx` so the active audience's Articles link is last. Update their focused tests plus `resources/js/data/__tests__/staffArticles.test.ts`, add catalog/audience tests, update `resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx`, and expand `tests/Feature/Staff/StaffArticlesRouteTest.php` with the role and owner route matrix.

---

### Task 1: Define the shared article contract and audience route map

**Files:**
- Create: `resources/js/data/articleGuides.ts`
- Create: `resources/js/data/articleAudience.ts`
- Create: `resources/js/utils/articleGuides.ts`
- Create: `resources/js/data/articleCatalogs/index.ts`
- Create: `resources/js/data/articleCatalogs/roleCatalogFactory.ts`
- Test: `resources/js/data/__tests__/articleGuides.test.ts`

**Interfaces:**
- `ArticleLanguage = "en" | "tl"`.
- `ArticleAudience = "staff" | "manager" | "finance" | "hr" | "crm" | "cashier" | "repairer" | "inventory" | "procurement" | "logistics-dispatcher" | "shop-owner"`.
- `ArticleGuide` contains `slug`, `order`, `category`, `recommended`, `access`, `translations`, and `sourceCoverage`; `ArticleStep` has only `id`, `title`, and `body`.
- `ArticleCatalog` contains `audience`, localized `label`, localized `title`, localized `intro`, `categories`, and `articles`.
- `ArticleViewer` contains `permissions`, `roles`, `legacyRole`, `businessType`, `registrationType`, and `ownerMode`.
- `ARTICLE_AUDIENCE_CONFIG[audience]` returns the route name, base path, and page title used by the hub/detail links.
- `loadArticleCatalog(audience): Promise<ArticleCatalog>` uses an explicit dynamic-import switch so only the selected audience module is fetched.
- `getAccessibleArticles`, `getArticleBySlug`, `searchArticles`, `getArticleCategories`, and `resolveRelatedArticles` accept a catalog or article list and never read another catalog.

- [ ] **Step 1: Write failing contract and loader tests.** Assert the audience list has exactly the requested 11 entries, each config has a distinct base path, a Staff config points to `/erp/articles`, no config mentions Logistics Rider, a step has no screenshot property, and loading Staff plus one non-Staff audience returns a catalog with matching `audience`.

- [ ] **Step 2: Run the focused test and confirm the new symbols are missing.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/articleGuides.test.ts --reporter=dot`

Expected: FAIL because the shared contract and loader do not exist yet.

- [ ] **Step 3: Implement the shared types, route map, factory, utilities, and loader.** Keep the factory limited to localized text-to-translation assembly and the utilities limited to filtering/search/category/related behavior. Use normalized, accent-insensitive search and require every related slug to resolve within the same catalog.

```ts
export const ARTICLE_AUDIENCE_CONFIG = {
  staff: { basePath: "/erp/articles", indexRoute: "erp.articles.index", label: { en: "Staff", tl: "Staff" } },
  manager: { basePath: "/erp/manager/articles", indexRoute: "erp.manager.articles.index", label: { en: "Manager", tl: "Manager" } },
  finance: { basePath: "/finance/articles", indexRoute: "finance.articles.index", label: { en: "Finance", tl: "Finance" } },
  hr: { basePath: "/erp/hr/articles", indexRoute: "erp.hr.articles.index", label: { en: "HR", tl: "HR" } },
  crm: { basePath: "/crm/articles", indexRoute: "crm.articles.index", label: { en: "CRM", tl: "CRM" } },
  cashier: { basePath: "/erp/cashier/articles", indexRoute: "erp.cashier.articles.index", label: { en: "Cashier", tl: "Cashier" } },
  repairer: { basePath: "/erp/repairer/articles", indexRoute: "erp.repairer.articles.index", label: { en: "Repairer", tl: "Repairer" } },
  inventory: { basePath: "/erp/inventory/articles", indexRoute: "erp.inventory.articles.index", label: { en: "Inventory", tl: "Inventory" } },
  procurement: { basePath: "/erp/procurement/articles", indexRoute: "erp.procurement.articles.index", label: { en: "Procurement", tl: "Procurement" } },
  "logistics-dispatcher": { basePath: "/erp/logistics/articles", indexRoute: "erp.logistics.articles.index", label: { en: "Logistics Dispatcher", tl: "Logistics Dispatcher" } },
  "shop-owner": { basePath: "/shop-owner/erp/articles", indexRoute: "shop-owner.erp.articles.index", label: { en: "Shop Owner", tl: "Shop Owner" } },
} as const satisfies Record<ArticleAudience, ArticleAudienceConfig>;
```

- [ ] **Step 4: Run the focused test and confirm it passes.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/articleGuides.test.ts --reporter=dot`

Expected: PASS.

- [ ] **Step 5: Commit the contract.**

```bash
git add resources/js/data/articleGuides.ts resources/js/data/articleAudience.ts resources/js/utils/articleGuides.ts resources/js/data/articleCatalogs/index.ts resources/js/data/articleCatalogs/roleCatalogFactory.ts resources/js/data/__tests__/articleGuides.test.ts
git commit -m "feat: add shared article catalog contract"
```

---

### Task 2: Migrate the Staff catalog and remove screenshot code/assets

**Files:**
- Modify: `resources/js/data/staffArticles.ts`
- Modify: `resources/js/data/staffArticleAccess.ts`
- Modify: `resources/js/utils/staffArticles.ts`
- Modify: `resources/js/data/__tests__/staffArticles.test.ts`
- Delete: `resources/js/Components/articles/ArticleScreenshot.tsx`
- Delete: `resources/js/Components/articles/ArticleLightbox.tsx`
- Delete: `resources/js/components/articles/__tests__/ArticleScreenshot.test.tsx`
- Delete: `resources/js/components/articles/__tests__/ArticleLightbox.test.tsx`
- Delete: `public/images/articles/staff/**/*.webp`
- Delete if screenshot-only: `public/images/articles/staff/README.md`

**Interfaces:**
- `STAFF_ARTICLE_CATEGORIES` and `STAFF_ARTICLES` remain exported for existing Staff imports.
- `STAFF_ARTICLE_CATALOG` is the shared `ArticleCatalog` for audience `staff`.
- Existing Staff access behavior remains: one Staff permission, non-excluded role, and retail-capable business (`retail` or `both`); specialized roles remain excluded.

- [ ] **Step 1: Replace screenshot assertions with text-only catalog assertions.** Keep the 32-count/order/category assertions. Check English/Tagalog identifier parity, at least three non-empty steps, workflow/outcomes/errors/related content, source coverage, and absence of `screenshotId`, `screenshots`, and `/images/articles/` values.

- [ ] **Step 2: Run the Staff data test and confirm it fails on the old screenshot contract.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/staffArticles.test.ts --reporter=dot`

Expected: FAIL because the current Staff entries still contain screenshot fields/assets.

- [ ] **Step 3: Remove screenshot types, builders, metadata, and fields from the Staff data without changing the 32 article slugs or order.** Expand weak step bodies with exact page/menu names, button/status labels, what to check, and what the next owner does. Preserve only source coverage that points to real routes/pages/permissions/tests.

- [ ] **Step 4: Replace Staff utility internals with the shared utility and retain compatibility exports where current callers still use Staff names.** Ensure related links are resolved against the passed Staff list, never a different audience.

- [ ] **Step 5: Delete the screenshot components/tests and remove the 94 exact WebP files under `public/images/articles/staff/`.** Verify the directory contains no image files and no source reference remains.

- [ ] **Step 6: Run the focused Staff and article-component tests.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/staffArticles.test.ts resources/js/Components/articles --reporter=dot`

Expected: PASS for the remaining article tests, with deleted screenshot tests omitted by Vitest.

- [ ] **Step 7: Commit the Staff migration.**

```bash
git add resources/js/data/staffArticles.ts resources/js/data/staffArticleAccess.ts resources/js/utils/staffArticles.ts resources/js/data/__tests__/staffArticles.test.ts resources/js/Components/articles public/images/articles/staff
git commit -m "refactor: make Staff articles text first"
```

---

### Task 3: Add complete role catalogs with real route/page sources

**Files:**
- Create: `resources/js/data/articleCatalogs/staff.ts`
- Create: `resources/js/data/articleCatalogs/manager.ts`
- Create: `resources/js/data/articleCatalogs/finance.ts`
- Create: `resources/js/data/articleCatalogs/hr.ts`
- Create: `resources/js/data/articleCatalogs/crm.ts`
- Create: `resources/js/data/articleCatalogs/cashier.ts`
- Create: `resources/js/data/articleCatalogs/repairer.ts`
- Create: `resources/js/data/articleCatalogs/inventory.ts`
- Create: `resources/js/data/articleCatalogs/procurement.ts`
- Create: `resources/js/data/articleCatalogs/logisticsDispatcher.ts`
- Create: `resources/js/data/articleCatalogs/shopOwner.ts`
- Test: `resources/js/data/__tests__/articleCatalogs.test.ts`

**Interfaces:**
- Every module exports `const <audience>ArticleCatalog: ArticleCatalog` and a default export.
- Every catalog's related slugs point only to articles in that module.
- Every role article's `sourceCoverage` names actual route/page/permission/test files, and every access declaration uses existing permissions or role names.

- [ ] **Step 1: Add catalog coverage tests before writing catalog entries.** Test that all 11 loaders return their matching audience, every catalog is non-empty, every article has both translations and at least three steps, each localized structure has matching IDs, each article has at least one source route/page/permission/test, and related links stay within the same catalog.

- [ ] **Step 2: Run the coverage test and confirm it fails because the catalog modules are missing.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/articleCatalogs.test.ts --reporter=dot`

Expected: FAIL with missing catalog modules.

- [ ] **Step 3: Add `staff.ts` as the Staff catalog adapter and write the Manager catalog.** Manager guides must cover the Manager Dashboard, retail Job Orders, repair jobs, Inventory Overview, Staff & Workload, Leave Approvals, Suspension/Termination/Rehire Approvals, Reports & Analytics, Shoe Pricing, and Audit Logs. Use business filters for retail-only and repair-capable guides and manager capability/permission names from `routes/web.php`, `RequireManagerCapability`, and the sidebar.

- [ ] **Step 4: Write the Finance, HR, and CRM catalogs.** Finance must cover Dashboard, Invoices, Expenses, and the visible approval families. HR must cover dashboard, Employees, Attendance, Leave, Overtime, Payroll/View Slip, Salary Changes, Suspend Accounts, and Audit Logs. CRM must cover Dashboard, Customers, Customer Support, Customer Reviews, Opportunities, and Leads where the existing route exposes them. Each guide must say what page to open, which fields/statuses to check, what save/submit/review result means, and who handles a pending or rejected result.

- [ ] **Step 5: Write the Cashier, Repairer, Inventory, Procurement, and Logistics Dispatcher catalogs.** Cashier must cover the dashboard and unified Point of Sale. Repairer must cover repair dashboard/job orders, warranty queue, pricing/services, stock/material requests, repair support, and point of sale. Inventory must cover the inventory dashboard, upload/manage stock, product inventory, stock movement, stock requests, material request approval, and supplier order monitoring. Procurement must cover dashboard, purchase requests, purchase orders, stock request approval, and suppliers. Logistics Dispatcher must cover the dispatcher dashboard, shipments, batches, deliveries, riders, and settings; do not add a Logistics Rider catalog or Rider-only account guide.

- [ ] **Step 6: Write the Shop Owner catalog with business and registration variants.** Cover the owner Home/Assist Center, Retail and Repair operations, Cashier/POS, Customers/Support, Finance, Workforce, Inventory, Procurement, Logistics, Reports, Audit, and settings only when the existing owner sidebar/routes expose them. Add `allowedRegistrationTypes` and `allowedBusinessTypes` to guides so company, individual, retail, repair, and both variants receive only relevant instructions. Use the existing owner routes in `routes/shop-owner-erp.php`, `routes/shop-owner-shell.php`, `CanonicalOwnerShellService`, and `AppSidebar_shopOwner` as source coverage.

- [ ] **Step 7: Run the catalog coverage tests and fix all content parity, access, route-source, and same-catalog related-link failures.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/data/__tests__/articleGuides.test.ts resources/js/data/__tests__/staffArticles.test.ts resources/js/data/__tests__/articleCatalogs.test.ts --reporter=dot`

Expected: PASS.

- [ ] **Step 8: Commit the catalogs.**

```bash
git add resources/js/data/articleCatalogs resources/js/data/__tests__/articleCatalogs.test.ts resources/js/data/__tests__/articleGuides.test.ts
git commit -m "feat: add role based article catalogs"
```

---

### Task 4: Add server-side audience routes and access gates

**Files:**
- Create: `app/Http/Controllers/Erp/ArticlesController.php`
- Create: `app/Http/Middleware/EnsureArticleAudienceAccess.php`
- Modify: `app/Http/Controllers/Erp/StaffArticlesController.php`
- Modify: `routes/web.php`
- Modify: `routes/shop-owner-erp.php`
- Modify: `bootstrap/app.php`
- Modify: `config/shop_modules.php`
- Test: `tests/Feature/Staff/StaffArticlesRouteTest.php`

**Interfaces:**
- `ArticlesController::index(Request $request): InertiaResponse|RedirectResponse` and `ArticlesController::show(Request $request, string $slug): InertiaResponse|RedirectResponse` read only the fixed route default and pass `articleAudience` and `articleSlug` to `ERP/Articles/Index`.
- `EnsureArticleAudienceAccess::handle(Request $request, Closure $next, string $audience): Response` accepts only the 10 non-Staff audience keys, checks the authenticated guard and existing account rules, and fails with 403 for a different audience.
- Staff keeps `StaffArticlesController` and `staff.articles` so its current access boundary and forced-password redirect remain unchanged; its render props add `articleAudience: "staff"`.

- [ ] **Step 1: Add route/access tests for the fixed paths.** Cover one eligible account for each employee audience, approved Shop Owners for company/individual and retail/repair/both, unauthenticated redirects, forced-password redirects, specialized Staff denial, business-specific Repairer denial on retail, and cross-audience detail URLs returning the active catalog page without the other catalog's slug.

- [ ] **Step 2: Run the route tests and confirm the new route names/middleware are missing.**

Run: `php artisan test tests/Feature/Staff/StaffArticlesRouteTest.php`

Expected: FAIL on missing route/controller/middleware behavior.

- [ ] **Step 3: Implement the controller and middleware using existing normalized role/permission/business helpers.** The middleware must recognize role names `MANAGER`, `FINANCE`, `FINANCE STAFF`, `FINANCE MANAGER`, `HR`, `CRM`, `CASHIER`, `REPAIRER`, `INVENTORY`, `INVENTORY MANAGER`, `PROCUREMENT`, `PROCUREMENT MANAGER`, and `LOGISTICS DISPATCHER`, plus the exact permission lists already used by the corresponding sidebar and route groups. Exclude `LOGISTICS RIDER` from every dispatcher route. For Shop Owner, require `auth:shop_owner`, approved status, valid registration/business values, and the existing ERP actor/context middleware; do not use employee permissions.

```php
return Inertia::render('ERP/Articles/Index', [
    'articleSlug' => $slug,
    'articleAudience' => $audience,
]);
```

- [ ] **Step 4: Register the middleware alias and add these employee route pairs with fixed defaults and audience gates:** `/erp/manager/articles`, `/finance/articles`, `/erp/hr/articles`, `/crm/articles`, `/erp/cashier/articles`, `/erp/repairer/articles`, `/erp/inventory/articles`, `/erp/procurement/articles`, and `/erp/logistics/articles`. Keep each route under the account's existing path/name namespace and preserve `auth:user`, `check.suspension`, and force-password handling.

- [ ] **Step 5: Add `/shop-owner/erp/articles` and its detail route under the existing Shop Owner ERP group.** Add `shop-owner.erp.articles.index/show` entries to `config/shop_modules.php` as owner-safe core GET routes with `actor_guard: shop_owner`, all registration types, all three business types, and no module permission requirement. This lets `EnsureErpAudience`, `ResolveErpActorContext`, and Inertia owner-shell sharing remain active.

- [ ] **Step 6: Run the focused route tests and confirm they pass.**

Run: `php artisan test tests/Feature/Staff/StaffArticlesRouteTest.php`

Expected: PASS with no new failures; pre-existing PHPUnit file-content warnings may remain and must be reported if present.

- [ ] **Step 7: Commit the server route/access layer.**

```bash
git add app/Http/Controllers/Erp/ArticlesController.php app/Http/Controllers/Erp/StaffArticlesController.php app/Http/Middleware/EnsureArticleAudienceAccess.php routes/web.php routes/shop-owner-erp.php bootstrap/app.php config/shop_modules.php tests/Feature/Staff/StaffArticlesRouteTest.php
git commit -m "feat: scope article routes by ERP audience"
```

---

### Task 5: Convert the page and article components to the active catalog

**Files:**
- Modify: `resources/js/Pages/ERP/Articles/Index.tsx`
- Modify: `resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx`
- Modify: `resources/js/Components/articles/ArticleHub.tsx`
- Modify: `resources/js/Components/articles/ArticleDetail.tsx`
- Test: `resources/js/Components/articles/__tests__/ArticleHub.test.tsx` if the existing suite has a hub test; otherwise add it beside the component.

**Interfaces:**
- `ArticleHub` receives `catalog`, `articles`, `basePath`, `language`, and `onLanguageChange`.
- `ArticleDetail` receives `catalog`, `article`, `accessibleArticles`, `basePath`, `language`, and `onLanguageChange`.
- `Index.tsx` reads the server-provided `articleAudience`, calls `loadArticleCatalog`, derives the viewer from the shared `auth` props, and renders a loading/error state before the hub/detail.

- [ ] **Step 1: Update page/component tests for the new prop contract.** Assert Staff still shows 32 articles, a Manager page uses Manager title/base links, a catalog is not loaded for another audience, detail related links stay on the active base path, the language control has exactly two buttons and no `Languages` icon, ordinary light surfaces include `bg-white`, and outcome classes use green only for success and red only for danger.

- [ ] **Step 2: Run the focused page/component tests and confirm they fail against Staff-only imports and screenshot rendering.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/Articles resources/js/Components/articles --reporter=dot`

Expected: FAIL until the shared props and text-only rendering are implemented.

- [ ] **Step 3: Remove `Languages`, screenshot state/effects/imports, screenshot grid columns, screenshot holders, and lightbox rendering.** Render every step as one full-width numbered text block. Keep `CheckCircle2`/`CircleAlert` and text labels so color is not the only status signal.

- [ ] **Step 4: Make Hub and Detail copy/catalog/category/search/related links audience-aware.** Use `catalog.label/title/intro`, `catalog.categories`, shared utilities, and `basePath`; replace Staff-only IDs/copy with audience-safe IDs while keeping stable Staff IDs as compatibility aliases where tests or accessibility hooks need them. Keep the toggle buttons at least 44px high with visible focus rings.

- [ ] **Step 5: Change ordinary light-mode article surfaces to `bg-white` and keep only success/danger outcome tint classes.** Neutral and warning cards must use `bg-white` plus neutral borders. Check the dark-mode pair on each surface and keep readable text contrast.

- [ ] **Step 6: Implement the page's audience-aware lazy loader and safe states.** If the server sends an unknown audience, show a clear unavailable state and do not fall back to another audience catalog. If a detail slug is absent from the active catalog, show “Article not found” for that audience.

- [ ] **Step 7: Run the focused page/component tests and confirm they pass.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/Pages/ERP/Articles resources/js/Components/articles --reporter=dot`

Expected: PASS.

- [ ] **Step 8: Commit the audience-aware text reader.**

```bash
git add resources/js/Pages/ERP/Articles resources/js/Components/articles
git commit -m "feat: render audience aware text article guides"
```

---

### Task 6: Put Articles last in every applicable sidebar

**Files:**
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/layout/AppSidebar_shopOwner.tsx`
- Modify: `resources/js/layout/CanonicalOwnerSidebar.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Modify: `resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx`

**Interfaces:**
- `erp.*.articles.index` links use the route/base paths in `ARTICLE_AUDIENCE_CONFIG` and are returned only by the matching role/account section.
- Shop Owner article links use `shop-owner.erp.articles.index` and `/shop-owner/erp/articles`; the canonical owner sidebar renders its link after all visible metadata groups, and the legacy owner sidebar renders it after all visible menu sections.

- [ ] **Step 1: Add sidebar tests before changing arrays.** Assert Staff Articles is last after `My Payslips`, each employee role section ends with its own Articles link, a Staff-only viewer cannot see Manager/Finance/CRM/etc. article links, and the owner link is last for company and individual owner layouts.

- [ ] **Step 2: Run the sidebar tests and confirm the ordering/link assertions fail.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx --reporter=dot`

Expected: FAIL on missing role-specific links or wrong order.

- [ ] **Step 3: Add one audience-specific item to the end of each filtered group and update active-path fallbacks.** Move Staff Articles from the middle of `staffItems` to the final Staff position. Do not let an article item enter another group through the current deduplication or broad `has*Access` checks.

- [ ] **Step 4: Add the final Shop Owner link to both sidebar implementations.** Use a book/document vector icon already present in the icon family, a descriptive label, an active path for `/shop-owner/erp/articles`, and a visible focus state. Keep it outside the business-type-specific operational groups so every approved owner variant sees only the Shop Owner catalog link.

- [ ] **Step 5: Run the focused sidebar tests and confirm they pass.**

Run: `node node_modules/vitest/vitest.mjs run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx --reporter=dot`

Expected: PASS.

- [ ] **Step 6: Commit sidebar placement.**

```bash
git add resources/js/layout/AppSidebar_ERP.tsx resources/js/layout/AppSidebar_shopOwner.tsx resources/js/layout/CanonicalOwnerSidebar.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx
git commit -m "feat: place role articles last in sidebars"
```

---

### Task 7: Review, verify, rebase, build, and push

**Files:**
- Modify: `docs/superpowers/plans/2026-09-05-role-based-article-guides.md`
- Modify if needed: `docs/ai-learning-log.md`
- Generated: `public/build/**`

- [ ] **Step 1: Run the sequential simplify review.** Inspect the diff for repeated catalog logic, unnecessary wrappers, dead compatibility exports, client-side cross-audience filtering, and unused route maps. Remove only code created by this feature that is no longer referenced.

Run: `rg -n "screenshotId|screenshots|ArticleScreenshot|ArticleLightbox|Languages|images/articles" resources/js app routes tests public --glob '!public/build/**'`

Expected: no screenshot or language-icon references outside intentionally updated tests/docs; no unrelated file changes.

- [ ] **Step 2: Run standards/spec/risk and frontend checklist reviews sequentially.** Check Laravel authorization and tenant/owner boundaries, React typed props and dynamic imports, keyboard focus, 375px/desktop layout, light/dark contrast, and that color is not the only outcome signal. Apply `ponytail`, `laravel-best-practices`, `vercel-react-best-practices`, `typescript-advanced-types`, `karpathy-guidelines`, and `security-review` findings where relevant.

- [ ] **Step 3: Run the full quality gates.**

```bash
git diff --check
node node_modules/vitest/vitest.mjs run --reporter=dot --silent
php artisan test tests/Feature/Staff/StaffArticlesRouteTest.php
```

Expected: frontend tests pass; the focused Laravel suite passes; any known PHPUnit warnings are documented; `git diff --check` has no output.

- [ ] **Step 4: Rebase onto the latest `origin/solespace-b` before the final build.** Preserve only this feature branch's commits, resolve source conflicts by keeping the approved role-catalog/access behavior, and use the existing generated-build conflict procedure after source conflicts are resolved.

```bash
git fetch origin solespace-b
git rebase origin/solespace-b
```

- [ ] **Step 5: Generate and validate a fresh production build.**

```bash
node node_modules/vite/bin/vite.js build
git diff --check
```

Expected: Vite completes successfully, `public/build/manifest.json` references the current source, and no screenshot image is emitted or referenced.

- [ ] **Step 6: Review exact changed paths and final status.** Verify only the article source/routes/middleware/config/tests/docs and generated `public/build` changed; confirm no `.env`, `vendor`, `node_modules`, unrelated database files, or PR files are included.

- [ ] **Step 7: Push the rebased feature branch without creating a PR.**

```bash
git push --force-with-lease -u origin feature/staff-articles-knowledge-base
```

Expected: the remote feature branch points to the final rebased commit and is ready for the user to open a PR.

- [ ] **Step 8: Record the exact test/build/push evidence in the handoff and update `docs/ai-learning-log.md` only with durable project guidance.** Do not store tokens, secrets, personal data, or temporary debugging notes.

---

## Plan Self-Review

- Spec coverage: catalog isolation, Staff-only boundary, all requested accounts, Shop Owner business/registration variants, text-only complete guides, status colors, language control, last sidebar placement, route/access protection, tests, fresh build, and no PR are covered by Tasks 1–7.
- Placeholder scan: the plan contains no deferred task marker, unresolved choice, or unspecified implementation step.
- Type consistency: the shared `ArticleAudience`, `ArticleCatalog`, `ArticleGuide`, `ArticleViewer`, `ARTICLE_AUDIENCE_CONFIG`, and `loadArticleCatalog` names are used consistently by the catalog, route, page, and sidebar tasks.

Plan complete and saved to `docs/superpowers/plans/2026-09-05-role-based-article-guides.md`. Inline execution is selected because the repository operating model requires one sequential main agent and the user already approved proceeding.
