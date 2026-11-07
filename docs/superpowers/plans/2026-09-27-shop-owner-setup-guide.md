# Shop Owner Setup Guide Implementation Plan

> **For agentic workers:** Use executing-plans sequentially. The repository operating model prohibits parallel editing and commits without explicit request.

**Goal:** Give Shop Owners an account-aware setup checklist and a resumable guide through the real SoleSpace controls.

**Architecture:** A single server-side plan resolves applicability and persisted configuration. A small owner-scoped progress store records welcome and tutorial position only. The shared owner layout renders the drawer and route-aware spotlight; existing pages receive stable tour attributes.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind 4.

## Global constraints

- Preserve the existing owner routes, settings forms, module gates, and monochrome design.
- Completion for configuration tasks comes from authoritative saved state, never tutorial state.
- Required tasks: operating hours, PayMongo key, published policy; workforce and procurement tasks only where modules permit them.
- COD is available only for retail-capable company shops with enabled Shop-owned Logistics and prerequisite staff.
- Repair payment policy is educational because `full_upfront` is fixed by current code.
- Existing Articles has category filters and recommended reads, but no search control.
- Preserve unrelated working-tree edits; do not commit, switch branches, or run destructive migrations.

## Tasks

### 1. Server-side setup plan and regression tests

**Files:** Create `app/Services/ShopOwnerSetupPlan.php`; create `tests/Feature/ShopOwner/SetupGuideTest.php`.

- [ ] Test all six account combinations, module-disabled cases, manually configured completion, and invalidation.
- [ ] Derive tasks from `ShopOwner`, `ShopModuleAccessService`, `CodEligibilityService`, `ShopPolicyVersion`, `ShopPaymentIntegration`, `HR/BranchPayrollSetting`, and `Employee`.
- [ ] Return task key, group, label, requirement, setup status, destination, and counts. Do not expose secrets.
- [ ] Run `php artisan test tests/Feature/ShopOwner/SetupGuideTest.php`.

### 2. Persistent tutorial state and owner-scoped routes

**Files:** Create a focused migration in `database/migrations`, `app/Models/ShopOwnerSetupState.php`, `app/Http/Controllers/ShopOwner/SetupGuideController.php`; modify `routes/shop-owner-shell.php` and `tests/Feature/ShopOwner/SetupGuideTest.php`.

- [ ] Test authentication, cross-owner isolation, allowed task/step values, welcome dismissal, replay, and unchanged business configuration.
- [ ] Persist one owner row with welcome seen and a JSON map of tutorial status/step; validate keys against the server plan.
- [ ] Add a read endpoint and a progress update endpoint under `auth:shop_owner`.
- [ ] Run the focused PHP test.

### 3. Shared guide, sidebar, and route-aware tour

**Files:** Create `resources/js/components/shop-owner/OwnerSetupGuide.tsx`, `SetupGuideNavButton.tsx`, and `setupTours.ts`; modify `resources/js/layout/AppLayout_shopOwner.tsx`, `CanonicalOwnerLayout.tsx`, `CanonicalOwnerSidebar.tsx`, and `AppSidebar_shopOwner.tsx`.

- [ ] Test drawer progress, first-login prompt, actual-click advancement, exit/resume/replay, route transitions, and missing-target fallback.
- [ ] Add Setup Guide directly above Articles in both sidebars.
- [ ] Highlight real controls with a dimmed backdrop, keyboard exit, focus restoration, viewport-aware tooltip, scroll-to-target, and responsive sidebar handling.
- [ ] Run focused frontend tests.

### 4. Stable targets on existing controls

**Files:** Modify `resources/js/Pages/ShopOwner/Settings/shopProfile.tsx`, `shopSetting.tsx`, `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx`, and `resources/js/components/articles/ArticleHub.tsx`.

- [ ] Add semantic `data-tour` values to existing navigation and controls only.
- [ ] Guide configuration tasks to the real edit controls, then refresh server status from saved state; completed replay uses manage wording.
- [ ] Guide Articles through the existing category and recommended sections.
- [ ] Add the optional customer-view preview using the existing public shop route.
- [ ] Run focused frontend tests and `pnpm run build`.

### 5. Final verification

- [ ] Run focused backend and frontend tests, then broaden only for concrete affected areas.
- [ ] Inspect both owner shells at desktop and mobile widths when runnable.
- [ ] Inspect `git status --short`, `git diff --stat`, `git diff`, and `git diff --check`.
- [ ] Report exact passes, gaps, and any browser check that could not run.
