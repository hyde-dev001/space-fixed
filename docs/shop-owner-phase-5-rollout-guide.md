# Shop Owner Phase 5 Legacy ERP Consolidation Rollout Guide

## Scope and release boundary

Phase 5 makes the canonical owner shell the only owner-facing navigation
presentation. The legacy ERP picker is retired, while a small GET compatibility
surface remains for existing bookmarks:

- `GET /shop-owner/erp/workspace` redirects once to `shop-owner.shell.home`.
- `GET /shop-owner/erp/{module}` redirects to the catalog-derived module
  Dashboard, or to Modules & Team when that module is disabled.
- The picker page, picker payload API, fallback presentation, and
  `SHOP_OWNER_ERP_WORKSPACE_ENABLED` flag are removed from runtime behavior.
- Legacy approval GET links continue to redirect to the typed Action Center;
  mutation routes remain owned by their existing domain services.

This is a presentation and route-topology consolidation. It does not promote
module visibility into record authorization, add owner correction workflows, or
change the seven-family maker/checker boundary. The capability, maker/checker,
and owner-operation matrices remain the source of truth for those decisions.

## Deployment checklist

1. Deploy the application code, route catalog, canonical shell metadata, and
   frontend assets together. No database migration is required by this phase.
2. Rebuild Laravel configuration and route caches through the normal release
   pipeline. Do not add the retired workspace flag to `.env`.
3. Do not deploy or stage generated `public/build/assets/*` or
   `storage/framework/cache/` files unless the repository's release process
   explicitly tracks them.
4. Confirm the route matrix is generated from the current catalog:

   ```powershell
   php artisan erp:route-matrix --write
   ```

5. Confirm the canonical shell rollout and Action Center behavior remain
   enabled for the intended owner cohort before exposing the release.

## Developer and browser QA

Use an approved, non-production Shop Owner fixture and verify:

- Home opens the canonical grouped sidebar.
- Each eligible module opens Dashboard and only its catalog-derived local tabs.
- Settings → Modules & Team can disable a module; the old module GET lands in
  Settings, while an enabled module lands in its canonical Dashboard.
- Action Center retains Needs My Decision, Waiting on Others, and safe typed
  legacy approval redirects.
- The old workspace GET makes exactly one redirect to canonical Home.
- Dashboard contains the existing owner-safe module metrics; duplicate
  Dashboard tabs are absent. Staff/product-handler sessions retain their
  existing employee dashboards.
- Workforce exposes Employees and read-only Attendance. Owner employee
  creation works from Employee Directory; attendance mutations, employee
  self-service, payroll generation, and account-permission operations remain
  denied or fail closed according to the matrices.
- Repair exposes the owner-safe Repair Dashboard summary and Repair Services;
  owner service creation uses the existing shop-scoped API.
- Finance exposes read-only Invoices and Expenses. Create Invoice and Create
  Expense are absent from the owner UI and directly denied.
- Inventory exposes read-only Supplier Order Monitoring. Logistics exposes
  Batches under the existing owner batch-management policy.
- The canonical owner Audit page and its scoped read API are available; audit
  export remains denied.
- A disabled, ineligible, cross-shop, or unauthenticated direct URL cannot
  reveal module or record data.
- Owner and employee sessions keep their own actor guard and tenant context.

Do not use real payment, payroll, salary, customer, or audit data for this QA.
No authenticated browser smoke test is claimed unless valid test credentials
are available.

## Rollback

Rollback is presentation-only:

1. If the canonical shell is unhealthy, revert to the last verified application
   release through the normal deployment system.
2. Keep the compatibility GET redirect and existing domain mutation boundaries
   stable while traffic is reconciled.
3. Do not restore a generic owner mutation endpoint, bypass maker/checker
   evidence, or reverse completed domain transitions with SQL.
4. Do not add new owner operation reads or broaden the owner audit surface;
   retain the characterized canonical owner audit projection and keep
   uncharacterized legacy audit/export surfaces denied.

There is no production wait or telemetry claim in this phase. The compatibility
GETs are retained to protect bookmarks and old callers while canonical links
are adopted.

## Evidence and unresolved boundaries

The generated route matrix must contain the compatibility GET routes and no
picker API or deleted workspace middleware. The audit matrix records the
verified canonical owner Audit read projection and the material owner
operation added in this phase: module toggle success is written as an
allowlisted `owner_operation` event inside the existing transaction. Routine
page loads remain `N/A_ROUTINE_VIEW`; audit export remains explicitly denied.

The maker/checker matrix remains fail-closed: Refund is
`N/A_NO_OWNER_INITIATION`; Price, Payslip, Salary Adjustment, Purchase Request,
Expense, and Repair Reject are `EXPLICITLY_EXCLUDED` for the uncharacterized
owner-maker/correction expansions. Their proven staff-maker decision paths
remain intact. No owner submission, correction, self-approval bypass, or
synthetic denied-attempt audit was added for those excluded expansions.

## Verification record

Record the exact command and result for each release candidate:

```powershell
php artisan test tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php
php artisan test tests/Feature/ShopOwner/CanonicalShell tests/Feature/ShopOwner/ActionCenter tests/Feature/BusinessScaling/ErpActorContextTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/utils/__tests__/pageTheme.test.ts
node_modules/.bin/vite.cmd build
git diff --check
```

The repository currently has no committed TypeScript compiler configuration or
frontend lint script. `pnpm` remains the documented command, but an equivalent
local binary invocation must be recorded when `pnpm` is unavailable.

### Latest verification evidence

- PHP syntax check over all changed PHP files: PASS.
- `php artisan erp:route-matrix --write`: PASS; generated matrix contains the
  two compatibility GET routes and no picker API or retired workspace
  middleware.
- Route/catalog/page/API/authorization contracts: PASS; the core combined
  owner-focused backend run completed with 347 tests and 18,548 assertions.
- Workspace-retirement parity bundle: PASS; 154 tests and 2,663 assertions.
- Approval-family characterization suite: PASS; 60 tests and 391 assertions.
- Canonical audit writer/audit API hardening subset: PASS; 10 tests and 45
  assertions.
- Focused Vitest run: 10 files, 52 tests passed; retirement UI subset: 3
  files, 24 tests passed. Full local Vitest run: 121 files, 664 tests
  passed.
- `node_modules/.bin/vite.cmd build`: PASS; 3,714 modules transformed.
- `composer test`: incomplete; the repository-wide command exceeded Composer's
  300-second process timeout. The stale catalog/session expectations observed
  during that run were corrected and the affected focused suites were rerun
  independently. No full-suite pass is claimed.
- `git diff --check`: PASS, with only Git's CRLF normalization warning for the
  generated route matrix.
- Current page contract run: PASS; `OwnerErpPageContractTest` completed with
  28 tests and 1,204 assertions, including canonical Dashboard summaries and
  the Logistics Batches page.
- Current API contract run: PASS; `OwnerErpApiContractTest` completed with 14
  tests and 106 assertions, including owner employee/service creation, Batches
  GET access, and cross-shop Repair Service denial.
- Fresh requested-scope backend regression: PASS; page/API/authorization/actor
  context contracts completed with 57 tests and 1,428 assertions. Catalog and
  route-matrix contracts completed with 11 tests and 43 assertions.
- Retail dashboard regression: PASS; `GET /api/shop-owner/dashboard/stats`
  now returns the owner-safe metric structure with tenant-scoped data (1 test,
  8 assertions), instead of the previous 403.
- Fresh audit and tenant-isolation regression: PASS; 12 tests and 119
  assertions.
- Current frontend owner-surface run: PASS; 8 files and 33 tests passed for
  canonical tabs/sidebar/layout, module dashboards, owner Employee Directory,
  owner Finance actions, and owner Inventory actions.

## Sequential review record

| Review gate | Result | Evidence |
| --- | --- | --- |
| Simplify / Ponytail | Pass | Removed picker/fallback presentation, duplicate workspace payload/API, and retired flag; reused the existing catalog, shell, actor context, and domain action. |
| Standards review | Pass | Route registration, middleware injection, Inertia metadata, tests, and generated route evidence follow existing project conventions. |
| Spec review | Pass | Compatibility GETs are retained exactly where required; unsupported writes, audit reads, and maker/checker gaps remain fail-closed. |
| TypeScript/React review | Pass for changed surfaces | Focused frontend tests, retirement UI tests, full local Vitest, and build pass. No TypeScript compiler or lint pass is claimed because the repository does not provide those scripts/configuration. |
| Code splitting | N/A / not measured | No new heavy dependency or conditional feature was introduced; the build passed without a measured reason to split the changed shell components. |
| Improvement gauge | Not measured | No before/after production latency, query, render, or bundle baseline was available; the build output is recorded as verification evidence only. |
| Security review | Pass for changed boundaries | Tenant-bound audit writer, owner/employee actor separation, disabled-module denial, compatibility redirects, canonical owner audit read scoping, tenant-scoped Repair Service reads/updates, and denied owner operations are covered by focused tests. Legacy audit export remains denied. |
| Reuse and dead-code audit | Pass | Runtime references to the deleted workspace page, picker API, fallback middleware, and retired flag are absent; only intentional compatibility routes and historical plan/spec references remain. |
| Verification before completion | Pass with documented exception | Focused backend/frontend/build/diff gates pass; the full Composer command timed out, so no repository-wide backend pass is claimed. |
