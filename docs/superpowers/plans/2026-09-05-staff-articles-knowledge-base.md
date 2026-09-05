# Staff Articles Knowledge Base Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the approved authenticated Staff Articles experience for regular retail Staff accounts: a permission-filtered, bilingual hub and detail view covering all 32 approved topics, with stable deep links, workflow outcomes, screenshot placeholders, and accessible navigation.

**Architecture:** Laravel only owns the authenticated/suspended/forced-password route boundary and renders one Inertia page with an optional article slug. A typed, version-controlled TypeScript catalog owns article copy and source metadata. Pure client helpers filter the catalog using shared auth props before search, categories, related links, or recommendations run. Reusable React article components render the hub, detail state, workflow sections, screenshot fallback, and lightbox inside `AppLayoutERP`.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest, Testing Library, native `<dialog>`/DOM APIs, existing `@heroicons/react`/`lucide-react` icon conventions.

## Global Constraints

- Work only in `C:\programmers\xampp\files\htdocs\solespace-master\.worktrees\staff-articles-knowledge-base` on branch `feature/staff-articles-knowledge-base`; preserve unrelated changes in the main worktree.
- Treat `docs/superpowers/specs/2026-08-26-staff-articles-knowledge-base-design.md` as the approved product contract and derive operational wording from current routes/controllers/services/tests.
- Keep content static and version-controlled. Do not add article database tables, migrations, an editor, uploads, runtime content APIs, or new runtime dependencies.
- Keep the regular Staff catalog separate from cashier, repairer, logistics, manager, HR, Finance, CRM, Inventory, Procurement, Shop Owner, and public/customer content.
- Laravel authorization is authoritative. Frontend filtering is a presentation boundary and must never replace route middleware, tenant/business checks, or existing operational permissions.
- Use existing ERP shell, theme, typography, spacing, icon, Link, and Inertia patterns. Keep layouts mobile-first at 375px, readable through desktop widths, keyboard accessible, dark-mode compatible, and free of nested long-content scrolling.
- Follow test-first sequencing for each new behavior: add a focused failing test, run it to confirm the failure, implement the smallest coherent change, then rerun the focused test before moving on.
- Do not edit `.env`, generated `vendor/`/`node_modules/`, or unrelated dirty files.

---

## Task 1: Add the protected Staff Articles routes

**Files:**

- Add `app/Http/Middleware/EnsureRegularStaffArticleAccess.php`.
- Add `app/Http/Controllers/Erp/StaffArticlesController.php`.
- Update `bootstrap/app.php` with the middleware alias.
- Update `routes/web.php` with `erp.articles.index` and `erp.articles.show`.
- Add `tests/Feature/Staff/StaffArticlesRouteTest.php`.

- [x] Write the feature test first. Build users with `ShopOwner` and `User` factories plus explicit `user`-guard Spatie permissions/roles. Cover:
  - a retail Staff user with one regular Staff permission can open `/erp/articles` and a valid-looking `/erp/articles/{slug}`;
  - `articleSlug` is `null` on the hub and the requested slug is passed on the detail route;
  - unauthenticated requests are rejected by the existing `auth:user` boundary;
  - an authenticated user without a regular Staff permission receives `403`;
  - cashier, repairer, and dedicated logistics Spatie roles remain denied even if their legacy `role` column is `STAFF` and a Staff permission is attached;
  - a Staff user attached to a `repair` shop is denied, while `retail` and `both` are eligible;
  - `force_password_change` redirects to `erp.profile` before the Articles page renders.
- [x] Run `php artisan test --filter=StaffArticlesRouteTest` and confirm the new tests fail because the routes/guard do not exist yet.
- [x] Implement `EnsureRegularStaffArticleAccess` with a small explicit regular-permission allowlist (`access-staff-dashboard`, `access-staff-job-orders`, `access-product-management`, `access-product-upload-staff`, `access-shoe-pricing`, `access-staff-time-in`, `access-staff-leave`, `access-color-variant-manager`, `access-staff-customers`) and explicit specialized-role exclusions. Normalize both the legacy role and Spatie role names, and normalize the related shop business type to `retail`, `both`, or deny.
- [x] Preserve existing Inertia/web denial behavior and return generic forbidden responses without exposing role or tenant details. Do not broaden `manager.staff` because its repairer compatibility behavior is intentionally wider than this catalog.
- [x] Register the alias as `staff.articles` in `bootstrap/app.php`.
- [x] Add a thin controller with `index()` and `show(string $slug)` methods. Both methods redirect a forced-password-change user to `erp.profile`; otherwise they render `ERP/Articles/Index` with `articleSlug` (`null` or the requested string). Do not resolve article content in PHP.
- [x] Add `GET /erp/articles` and `GET /erp/articles/{slug}` under `auth:user`, `check.suspension`, and `staff.articles`, with a lowercase slug constraint. Keep the optional slug out of the hub route so route names and active-state matching remain unambiguous.
- [x] Rerun the focused feature test and inspect the Inertia component/props assertions.

**Checkpoint:** `php artisan test --filter=StaffArticlesRouteTest` passes and an ineligible account cannot reach the catalog route. (Fresh evidence: 6 tests, 41 assertions; PHPUnit emitted existing warning output about deprecated doc-comment metadata and the absent frontend build manifest.)

## Task 2: Define and populate the typed bilingual Staff catalog

**Files:**

- Add `resources/js/data/staffArticleAccess.ts`.
- Add `resources/js/data/staffArticles.ts`.
- Add `resources/js/utils/staffArticles.ts`.
- Add `resources/js/data/__tests__/staffArticles.test.ts`.

- [x] Write pure-function/catalog tests first. Assert exactly 32 unique stable slugs; every article has category/order/access/source metadata; both language branches have the same structural IDs for prerequisites, steps, outcomes, errors, workflows, and related links; every screenshot slot has a stable path, alt text in both languages, and a positive aspect ratio; every related slug exists; and no article is categorized as a later role catalog.
- [x] Add tests for access filtering: regular permission intersection plus `retail`/`both` business type; no results for excluded role context; an inaccessible article is omitted from hub/search/related inputs but can still be identified by its slug for the detail unavailable state.
- [x] Add tests for search and categories: English title/summary, Tagalog copy, status terms such as `Under Review`, rejection/error terms, case/whitespace normalization, category counts after access filtering, empty categories removed, and no-results reset data.
- [x] Run the focused Vitest file and confirm it fails before the catalog/helpers exist.
- [x] Define compact exported interfaces for `ArticleLanguage`, category, access metadata, localized copy, step/outcome/error/workflow/screenshot/related structures, and `StaffArticle`. Keep structural IDs language-neutral so parity is testable without comparing translated text.
- [x] Export the regular Staff access permissions and excluded-role identifiers from `staffArticleAccess.ts` for reuse by the sidebar and filtering helpers. Represent shared auth input with a typed `StaffArticleViewer` (`permissions`, `roles`, `legacyRole`, `businessType`).
- [x] Populate all 32 approved articles in `staffArticles.ts`, grouped and ordered as: getting started/account (1–4), attendance/leave/overtime/payslips (5–9), retail orders/refunds/returns (10–19), product management (20–27), and pricing/customers/inventory visibility (28–32). Each article must contain English and Tagalog title, common question, summary, keywords, reading time, prerequisites, numbered steps, next-owner/outcome branches, recovery/errors, related labels/slugs, screenshot slots, and source-coverage references to the current route/page/permission/test surfaces.
- [x] Encode the approved workflow maps for normal orders, refund/return, shoe pricing, and attendance/overtime. State status, next owner, customer-visible result, rejection destination, and correction/cancellation/resubmission path where applicable. Use exact UI status/button labels in quotes when the current screen uses English labels.
- [x] Build path strings as `/images/articles/staff/{category}/{slug}/{slot-filename}.webp` from the catalog metadata so the placeholder and later copied files stay code-independent. Do not commit customer data or fake screenshots.
- [x] Implement pure helpers for viewer normalization, `isStaffArticleAccessible`, `getAccessibleStaffArticles`, `searchStaffArticles`, `getStaffArticleCategories`, `getStaffArticleBySlug`, and related-link resolution. Search only receives an already filtered list and searches localized copy plus keywords/workflow/status/error text.
- [x] Rerun the focused Vitest tests and keep the catalog validation failures actionable if future articles are added.

**Checkpoint:** The catalog contains all 32 approved topics, passes bilingual/reference validation, and search never indexes an excluded article. (Fresh evidence: focused Vitest passed 6 tests.)

## Task 3: Build the reusable article UI and page states

**Files:**

- Add `resources/js/components/articles/ArticleScreenshot.tsx`.
- Add `resources/js/components/articles/ArticleLightbox.tsx`.
- Add `resources/js/components/articles/ArticleHub.tsx`.
- Add `resources/js/components/articles/ArticleDetail.tsx`.
- Add `resources/js/Pages/ERP/Articles/Index.tsx`.
- Add `resources/js/components/articles/__tests__/ArticleScreenshot.test.tsx`.
- Add `resources/js/components/articles/__tests__/ArticleLightbox.test.tsx`.
- Add `resources/js/Pages/ERP/Articles/__tests__/Index.test.tsx`.

- [x] Write component/page tests first for: hub heading/search/category controls/recommendations/results; Tagalog search; no-results suggestions and reset; valid detail route; invalid slug not-found state; inaccessible slug unavailable state with hub link; language toggle and `localStorage` persistence; screenshot missing/error placeholder with exact path; successful image display; lightbox open/close/Escape/focus return; accessible labels/focus states; and preservation of query/filter state in the browser URL without a network search request.
- [x] Run the focused Vitest tests and confirm failure before adding UI components.
- [x] Implement `Index.tsx` with typed Inertia props (`articleSlug: string | null`), `AppLayoutERP`, `Head`, and shared auth/business props from `usePage`. Resolve the viewer and catalog client-side; render hub or detail based on the route slug.
- [x] Implement a language preference hook inside the Articles feature using a namespaced local-storage key, SSR-safe reads, English fallback, and structural parity assumptions. Keep language changes on the same article and do not mix missing translations silently.
- [x] Implement hub controls as semantic labeled input/buttons: high-contrast header, pill language switch, search, accessible category chips with counts, recommended/common-question cards, result cards with category/reading time, and a clear no-results/reset state. Search the in-memory accessible catalog only; sync `q`/`category` with `history.replaceState` so browser navigation has useful state without introducing a request waterfall.
- [x] Implement detail sequence: breadcrumb/back link, title/question, audience/access note, prerequisites, workflow map, numbered steps, screenshot slots, what happens next, approval/rejection/cancellation/return/recovery branches, common errors, and permission-filtered related links. Use readable prose widths, sequential headings, status labels plus text/icons, and no horizontal overflow.
- [x] Implement `ArticleScreenshot` with reserved aspect-ratio space, lazy loading for non-critical images, bilingual alt text, an `onError` fallback that exposes the exact configured path, and a keyboard/pointer-usable button. Implement `ArticleLightbox` with native dialog semantics, an accessible close button, Escape handling, focus containment/return, and body-scroll cleanup without a package.
- [x] Match the supplied monochrome ERP vocabulary: pure black/white/soft gray surfaces, hairline borders, restrained shadows (none for article panels), rounded pills for controls, visible dark-mode focus, 44px targets, and reduced-motion-safe transitions. Use existing icon imports rather than emoji or new icon dependencies.
- [x] Rerun the focused component/page tests, then review the rendered markup for heading hierarchy and accessible names.

**Checkpoint:** Hub/detail/error states, bilingual behavior, screenshots, lightbox, and keyboard interactions are covered by focused frontend tests. (Fresh evidence: focused Articles/page/sidebar suite passed 5 files, 53 tests.)

## Task 4: Add Articles to the Staff sidebar

**Files:**

- Update `resources/js/layout/AppSidebar_ERP.tsx`.
- Extend `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx` with focused Articles cases, or add a narrowly scoped sibling test if the existing mock harness cannot isolate the new item.

- [x] Add a failing sidebar test for an eligible regular retail Staff viewer: `Articles` is visible under the Staff section, its href is `/erp/articles`, and `/erp/articles/{slug}` keeps that item active. Add denial cases for cashier-only, repairer, logistics, and non-retail business context.
- [x] Add one Staff nav item using the existing inline SVG/icon conventions. Add `erp.articles.index` to the route/path maps so Ziggy fallback and nested active-state detection work.
- [x] Reuse the shared access constants/helper to gate the item. Keep existing `hasStaffAccess`, attendance placement, deduplication, collapsed behavior, prefetch, scroll restoration, and module filtering unchanged.
- [x] Run the focused sidebar tests and verify no existing section visibility assertions regress.

**Checkpoint:** Articles is discoverable only for the same regular Staff audience enforced by Laravel and remains active on detail pages. (Fresh evidence: AppSidebar_ERP Vitest passed 38 tests.)

## Task 5: Add screenshot handoff notes and perform review/verification

**Files:**

- Add `public/images/articles/staff/README.md` documenting the screenshot directory pattern, redaction rules, preferred formats, capture guidance, and the fact that missing files intentionally render placeholders.
- Update `docs/superpowers/specs/2026-08-26-staff-articles-knowledge-base-design.md` only if implementation decisions materially clarify the approved contract; otherwise leave the approved spec unchanged.
- Update `docs/ai-learning-log.md` only for a durable, reusable repository lesson discovered during implementation; do not record one-off debugging details or user data.

- [x] Run the sequential review stack against the finished diff: simplify/ponytail pass; Standards/spec/risk review; TypeScript/React readability and safe narrowing review using the repository’s advanced-types and Vercel guidance; Karpathy surgical-scope review; bundle/code-splitting check; measured-improvement evidence (or `not measured`); and security review for route authorization, tenant/business filtering, XSS-safe rendering, local storage, and lightbox behavior. Result: pass; measurable before/after baseline not available (`not measured`).
- [x] Perform reuse and dead-code audits: confirm existing ERP layout/Link/theme/icons are reused, no orphan imports/branches/TODOs remain, and no unrelated files changed. Result: pass; the catalog-free access helper keeps the full article catalog out of the always-loaded sidebar path.
- [x] Run `git diff --check`. Result: pass.
- [x] Run the focused Laravel route test and focused Articles/sidebar Vitest tests after the final code changes. Result: pass — route coverage 1 warning/1 assertion; Staff Articles 6 warnings/41 assertions; focused frontend 5 files/53 tests. Warnings are existing manifest/doc-comment warnings.
- [x] Run `composer test` and `pnpm run test:frontend` when dependencies are available; report any pre-existing failures separately and never call an unrun check passing. Result: frontend pass — 204 files/1,160 tests; full Composer suite attempted but timed out at 300 seconds after existing warning/unrelated failure output, so it is not reported as passing.
- [x] Run `pnpm run build` and inspect the generated build output for a successful production bundle. Result: pass — 3,788 modules transformed; generated output was removed/restored from the worktree after inspection.
- [x] Use the webapp-testing skill for browser verification when the local app can run: check 375px, tablet, and desktop widths in light/dark mode, search/category filtering, direct detail/invalid/inaccessible states, placeholder/lightbox keyboard flow, and sidebar active state. Result: attempted with the required wait-for-server flow, but blocked because the isolated worktree has no `.env`/application encryption key; no `.env` was created or edited.
- [x] Recheck `git status --short --branch` and summarize the isolated branch, changed areas, review results, and exact verification commands in the final handoff. Result: source-only changes remain on `feature/staff-articles-knowledge-base`.

**Final acceptance:** An eligible regular retail Staff user can navigate to Articles, find any of the 32 bilingual guides, follow documented workflow outcomes, and use screenshot placeholders/lightbox access; excluded roles/business contexts cannot receive the catalog through normal routes or navigation; relevant tests, build, browser checks (when runnable), and diff hygiene have fresh recorded evidence.
