# Shop Owner ERP Operational Parity Implementation Plan

> **For the implementation agent:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to execute this plan task-by-task.

**Goal:** Make every existing ERP page that represents a company operation usable by a shop owner inside the scoped ERP shell, while keeping employee self-service pages employee-only and preserving tenant, authorization, persistence, and audit boundaries.

**Architecture:** Keep the ERP Workspace as the module picker. Generate each module's owner sidebar from the route/capability catalog instead of a second hard-coded page list. Every owner page and API uses a scoped /shop-owner/erp/{module} URL and a request-scoped owner actor context. Reuse existing ERP controllers, services, Form Requests, policies, React pages, and domain operations where their behavior is already valid; add owner adapters only where the existing endpoint is employee- or staff-guarded.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, PHPUnit/Pest, Vitest, pnpm.

**Approved references:**

- docs/superpowers/specs/2026-08-10-shop-owner-erp-workspace-design.md
- docs/superpowers/plans/2026-08-10-shop-owner-erp-workspace.md
- docs/architecture/shop-owner-erp-route-matrix.md

## Current baseline and confirmed defect

The scoped ERP shell and module URLs already exist, but the operational catalog is incomplete:

- app/Services/ErpWorkspaceNavigationService.php still exposes a small hard-coded list per module.
- config/shop_modules.php and the generated route matrix contain only selected owner page/API pairs.
- The retail owner page currently renders resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx, whose initial requests target staff product APIs and therefore return User does not have the right permissions for a shop owner.
- Other modules show only the few aliases already added, even when related pages already exist under resources/js/Pages/ERP.
- Employee self-service pages must remain excluded: personal time in/out, personal leave/overtime, personal payslips, employee profile/password, and rider execution/delivery pages.

The plan starts from that baseline. It does not redo the already-merged shell/navigation work.

## Acceptance criteria

- The ERP Workspace remains the only module picker.
- Opening a module creates a scoped module URL and shows only that module's owner-capable pages in the sidebar.
- The module page list is derived from the approved route/capability catalog; no independent hard-coded list can drift from it.
- Every page shown to a shop owner loads with an approved shop_owner session without staff/user permission errors, including initial fetches, polling, exports, downloads, and notifications used by that page.
- Owner actions that change company data use owner-authenticated routes, tenant context, domain authorization, normal persistence, and explicit owner activity/audit records where required.
- Existing employee routes and employee behavior remain unchanged.
- Employee self-service and unsafe staff/rider execution pages are not exposed to owners and do not become reachable through a guessed owner URL.
- The route matrix is regenerated and reviewed so every existing ERP page is explicitly classified as owner-capable, employee-only, or intentionally unavailable.

## File map

Likely files/areas to change:

- Catalog and navigation: config/shop_modules.php, app/Services/ErpRouteCatalog.php, app/Services/ErpWorkspaceNavigationService.php, app/Http/Controllers/ShopOwner/ErpWorkspaceController.php, docs/architecture/shop-owner-erp-route-matrix.md.
- Owner page/API topology: routes/shop-owner-erp.php, routes/shop-owner-erp-api.php, the corresponding existing employee route files, owner controllers/services/Form Requests/policies, and module-specific tests.
- Actor and authorization: existing ErpActorContext middleware/context and owner authorization/persistence/audit services; only extend them when a route needs a verified owner operation.
- Frontend: resources/js/utils/erpCapabilities.ts, resources/js/layout/AppSidebar_ERP.tsx, resources/js/layout/AppLayout_ERP.tsx, and the relevant pages under resources/js/Pages/ERP.
- Tests: tests/Feature, tests/Unit, and resources/js/**/*.test.* or the repository's existing frontend test locations.

Do not edit generated public/build by hand. A fresh build is produced only during verification/release preparation.

## Execution rules for every task

- Start each task with a focused failing test or contract assertion when practical.
- Run the narrowest relevant test immediately after the change.
- Use the existing owner component/service/API before creating a new abstraction.
- Do not add wildcard permissions, synthetic employee users, owner IDs to users.id foreign keys, or broad multi-guard routes.
- Keep owner routes under auth:shop_owner; keep employee routes under auth:user.
- Do not expose a page merely because it can render. It must have an owner policy, tenant-safe reads, and a characterized mutation path or an explicit read-only state.

## Task 1: Establish the operational parity contract

**Files:**

- tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
- tests/Feature/BusinessScaling/OwnerErpApiContractTest.php
- tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php
- tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php
- tests/Unit/BusinessScaling/ErpRouteCatalogTest.php

**Steps:**

1. Inspect the current route catalog, module definitions, and all ERP page entry points.
2. Add failing assertions that the owner module response contains the complete catalog-derived page set for each enabled module, not only the current one-page aliases.
3. Add failing assertions that a self-service page is absent from the owner catalog and that an unapproved owner URL is denied.
4. Add a failing contract for the retail page's first data request: it must use the owner product API and must not call /api/products/*.
5. Run the focused tests and record the baseline failures before implementation.

**Verification:**

~~~~powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpApiContractTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php
~~~~

## Task 2: Make the owner page catalog data-driven

**Files:**

- config/shop_modules.php
- app/Services/ErpRouteCatalog.php
- app/Services/ErpWorkspaceNavigationService.php
- app/Http/Controllers/ShopOwner/ErpWorkspaceController.php
- tests/Unit/BusinessScaling/ErpRouteCatalogTest.php
- tests/Feature/BusinessScaling/OwnerErpPageContractTest.php

**Steps:**

1. Extend the existing route/capability metadata with the minimum fields needed to render a module page (module, navigation_label, navigation_order, audience, owner page/API exposure, and action/capability); do not create a parallel navigation registry.
2. Filter owner navigation to catalog entries that are explicitly owner-capable, enabled for the company, scoped to the selected module, and safe for the owner audience.
3. Sort from catalog metadata and return the same canonical scoped URL used by the owner route.
4. Remove the stale module page arrays from ErpWorkspaceNavigationService.php after the catalog output is covered by tests.
5. Ensure the module picker still lists the module even when a module has no owner-capable page, but make the resulting state explicit rather than rendering a broken link.
6. Regenerate the route matrix and classify every ERP route as owner-capable, employee-only, or unavailable with a reason.

**Verification:**

~~~~powershell
php artisan test tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
php artisan erp:route-matrix --write
~~~~

## Task 3: Repair the retail and repair operational wave

**Files:**

- routes/shop-owner-erp.php
- routes/shop-owner-erp-api.php
- resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx
- resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx
- resources/js/utils/erpCapabilities.ts
- Existing retail/repair controllers, Form Requests, policies, services, and tests

**Steps:**

1. Add a regression test for owner product listing, product create/update/archive, variant/stock operations, and image upload using the owner tenant.
2. Reuse or adapt the existing owner product page and owner endpoints so the ERP-scoped retail page uses /api/shop-owner/products/* capability URLs and the owner-safe payloads.
3. Verify every background request, action, upload, archive, and refresh in the retail page is owner-aware; remove employee permission assumptions rather than granting the owner a staff permission.
4. Map the existing owner-capable repair dashboard, job/order, warranty, service, pricing, stock/material, and support operations to scoped routes/API aliases using their existing domain services.
5. Keep rider delivery execution and other employee-personal actions out of the owner repair/logistics catalog.
6. Add authorization, tenant-isolation, persistence, and audit assertions for each owner mutation before exposing it in the sidebar.

**Verification:**

~~~~powershell
php artisan test --filter="ShopOwner.*(Product|Repair|Erp)"
pnpm exec vitest run --passWithNoTests resources/js/Pages/ERP
~~~~

## Task 4: Expand the HR, finance, and manager wave

**Files:**

- routes/shop-owner-erp.php
- routes/shop-owner-erp-api.php
- resources/js/Pages/ERP/HR/**
- resources/js/Pages/ERP/Finance/**
- resources/js/Pages/ERP/Manager/**
- Corresponding controllers/services/Form Requests/policies/tests

**Steps:**

1. Characterize each existing page's read and mutation requests and classify it as company operation, employee self-service, or unsafe/unavailable.
2. Add owner aliases for company operations such as employee directory, attendance monitoring, leave/overtime approvals, payroll administration/approval, invoices, expenses, reports, and manager review pages where the existing domain operation supports an owner actor.
3. Adapt frontend URLs through erpCapabilities.ts; do not make pages infer guard identity from the current browser path.
4. Reuse existing validation and domain authorization. Add owner performer/audit fields only where persistence or external effects require them.
5. Exclude personal attendance, personal leave/overtime, personal payslips, and employee account/profile operations from owner capabilities.
6. Add focused owner and employee regression tests for reads, approvals, exports, and denied self-service access.

**Verification:**

~~~~powershell
php artisan test --filter="ShopOwner.*(HR|Finance|Manager|Erp)"
pnpm exec vitest run --passWithNoTests resources/js/Pages/ERP
~~~~

## Task 5: Expand the inventory and procurement wave

**Files:**

- routes/shop-owner-erp.php
- routes/shop-owner-erp-api.php
- resources/js/Pages/ERP/inventory/**
- resources/js/Pages/ERP/Procurement/**
- Corresponding controllers/services/Form Requests/policies/tests

**Steps:**

1. Add contract tests for dashboard, product/material inventory, stock movement, stock requests, approvals, supplier orders, purchase requests/orders, and supplier management.
2. Expose owner-safe reads already supported by the current owner API aliases and add missing scoped aliases for the remaining company-operational pages.
3. Route owner mutations through tenant-safe domain services with explicit owner authorization and audit records; preserve cross-module gates such as business type and module enabled checks.
4. Update all page actions, filters, polling, downloads, and refreshes to use capability URLs.
5. Keep employee-only stock/request workflows unavailable where the existing operation represents an employee assignment rather than company administration.

**Verification:**

~~~~powershell
php artisan test --filter="ShopOwner.*(Inventory|Procurement|Erp)"
pnpm exec vitest run --passWithNoTests resources/js/Pages/ERP
~~~~

## Task 6: Expand the CRM, logistics, and cashier wave

**Files:**

- routes/shop-owner-erp.php
- routes/shop-owner-erp-api.php
- resources/js/Pages/ERP/CRM/**
- resources/js/Pages/ERP/Logistics/**
- resources/js/Pages/ERP/cashier/**
- Corresponding controllers/services/Form Requests/policies/tests

**Steps:**

1. Add owner contract tests for CRM customers/support/reviews, logistics dashboard/shipments/batches/riders/settings, and cashier/POS operations that are valid for a shop owner.
2. Extend the existing CRM/logistics aliases and add only the pages whose data and actions are owner-operational and tenant-safe.
3. Keep rider personal execution/delivery pages employee-only; expose owner shipment/dispatch management separately where supported.
4. Treat POS, refunds, pricing, and other financial/external-effect operations as high-risk: require explicit domain authorization, atomic persistence, audit, and verified failure handling before catalog exposure.
5. Migrate all page-side URLs to erpCapabilities.ts and verify polling/refresh/error handling with owner responses.

**Verification:**

~~~~powershell
php artisan test --filter="ShopOwner.*(CRM|Logistics|Cashier|Erp)"
pnpm exec vitest run --passWithNoTests resources/js/Pages/ERP
~~~~

## Task 7: Complete shell, capability, and sidebar regression coverage

**Files:**

- resources/js/layout/AppLayout_ERP.tsx
- resources/js/layout/AppSidebar_ERP.tsx
- resources/js/utils/erpCapabilities.ts
- Existing layout/sidebar tests

**Steps:**

1. Confirm owner mode renders only ERP Workspace plus the selected module's catalog-derived pages; the old shop-owner logistics/employee sections remain absent from the portal shell.
2. Confirm employee mode remains unchanged and still renders its existing role-specific navigation.
3. Confirm direct navigation, browser refresh, back/forward, disabled-module access, and unknown-module access resolve through the same server capability rules.
4. Remove only stale owner navigation branches created by the new catalog path; preserve unrelated role-specific employee navigation.
5. Add a browser-level smoke flow: portal → ERP Workspace → module → page → back to workspace → another module.

**Verification:**

~~~~powershell
pnpm run test:frontend
pnpm exec playwright test --list
~~~~

If the configured browser test target is available, run the focused owner flow with the repository's existing Playwright/webapp-testing command and save screenshots/logs as test evidence.

## Task 8: Final matrix, security, simplification, and release checks

**Files:**

- All changed files
- docs/architecture/shop-owner-erp-route-matrix.md
- docs/ai-learning-log.md only if a durable project lesson is discovered

**Steps:**

1. Regenerate the route matrix and verify there are no owner navigation entries without a paired route/API, capability, policy, and test.
2. Perform the sequential standards, spec, authorization/tenant-risk, TypeScript/React, code-splitting, simplification, and Karpathy reviews.
3. Run the dead-code/reuse audit for stale hard-coded page arrays, employee URLs left in owner pages, unused imports, unreachable branches, and abandoned TODOs.
4. Run the complete quality gates and capture exact results.
5. Build fresh frontend assets; do not hand-edit public/build.
6. Prepare a concise change summary and deployment notes, including route/config cache refresh requirements if applicable.

**Verification:**

~~~~powershell
git diff --check
php artisan test
pnpm run test:frontend
pnpm run build
~~~~

## Delivery checkpoints

- Commit each coherent module wave separately so a failing high-risk wave can be reverted without losing catalog or shell work.
- Do not push or create a pull request as part of implementation unless separately requested.
- Before claiming completion, report the exact tests/build commands run and whether each passed, failed, or was unavailable.
