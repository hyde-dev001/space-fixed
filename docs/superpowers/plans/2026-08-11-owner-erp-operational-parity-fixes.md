# Shop Owner ERP Operational Parity Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task with review checkpoints.

**Goal:** Make the Shop Owner ERP shell expose complete, owner-operable module pages with catalog-driven nested navigation, while fixing the Attendance runtime crash and Finance audit-log failure.

**Architecture:** Keep `/shop-owner/erp/workspace` as the module picker and keep each module under a scoped `/shop-owner/erp/{module}` URL. Extend the existing owner page catalog with optional group metadata, have the owner sidebar render direct pages and collapsible groups from that catalog, and reuse existing ERP pages/domain services through owner-scoped routes and APIs. Employee ERP navigation remains separate and unchanged.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, PHPUnit/Pest, Vitest, pnpm.

**Approved references:**

- `docs/superpowers/specs/2026-08-11-owner-erp-operational-parity-fixes-design.md`
- `docs/superpowers/specs/2026-08-10-shop-owner-erp-workspace-design.md`
- `docs/architecture/shop-owner-erp-route-matrix.md`

Relevant working rules: `@superpowers:test-driven-development`, `@superpowers:systematic-debugging`, `@laravel-best-practices`, `@vercel-react-best-practices`, `@karpathy-guidelines`, `@ponytail`, and `@security-review` for owner authorization/API changes.

## File map

- Catalog and server navigation: `config/shop_modules.php`, `app/Services/ErpWorkspaceNavigationService.php`, `app/Services/ErpRouteCatalog.php`, `app/Http/Controllers/ShopOwner/ErpWorkspaceController.php`.
- Owner shell: `resources/js/layout/AppSidebar_shopOwner.tsx`, `resources/js/layout/AppSidebar_ERP.tsx`, `resources/js/layout/AppLayout_ERP.tsx`, and `resources/js/types/shopModules.ts` when the page/group contract needs a type update.
- Owner page/API topology: `routes/shop-owner-erp.php`, `routes/shop-owner-erp-api.php`, `app/Http/Controllers/Erp/ReadPageController.php`, existing owner controllers/services/Form Requests, and `config/shop_modules.php` capability pairs.
- Known frontend defects/pages: `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`, `resources/js/Pages/ERP/HR/HR.tsx`, payroll pages under `resources/js/Pages/ERP/HR`, Finance pages under `resources/js/Pages/ERP/Finance`, and the existing Retail page components.
- Regression tests: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`, `OwnerErpApiContractTest.php`, `OwnerErpAuthorizationTest.php`, `OwnerErpTenantIsolationTest.php`, `tests/Unit/BusinessScaling/ErpRouteCatalogTest.php`, `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`, `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`, and focused page tests under the existing frontend test convention.
- Documentation/generated evidence: `docs/architecture/shop-owner-erp-route-matrix.md`, fresh `public/build` only from the build command.

## Execution rules

- Do not expose a page until its initial load and every page-owned request are owner-safe, tenant-scoped, and covered by a route/API contract.
- Do not grant employee permissions to a Shop Owner and do not impersonate an employee.
- Keep employee routes under `auth:user`; keep owner routes under `auth:shop_owner` and the existing ERP actor boundary.
- Do not add duplicate legacy aliases as separate module pages.
- Start each behavior change with a failing test where practical, run the narrowest check immediately, and commit coherent changes only.
- Preserve unrelated worktree changes and never edit `public/build` by hand.

### Task 1: Establish failing parity and bug contracts

**Files:**

- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpApiContractTest.php`
- Modify: `tests/Unit/BusinessScaling/ErpRouteCatalogTest.php`
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx` only for preserved employee-group assertions
- Create: `resources/js/Pages/ERP/HR/__tests__/AttendanceRecords.test.tsx`

- [ ] **Step 1: Characterize current contracts.** Record the current owner module page arrays, current `OwnerErpPage` shape, the three verified employee ERP groups (Attendance Monitoring, Payroll, Finance Approvals), and the core Shop Owner `Approval Pages` group.
- [ ] **Step 2: Add failing feature assertions.** Require the owner catalog to include the approved Retail Dashboard, Finance direct pages, Finance approval children, HR payroll pages, and existing Inventory/Procurement/Logistics owner-operational pages that are already backed by ERP routes.
- [ ] **Step 3: Add failing group assertions.** Assert that the owner module payload carries group metadata and that Finance/HR groups are rendered only in their selected module; assert direct pages remain direct.
- [ ] **Step 4: Add the audit namespace regression.** Assert both HR and Finance audit API routes resolve to the canonical case-correct controller and return the owner JSON contract.
- [ ] **Step 5: Add the Attendance regression.** Render/import the Attendance page with the existing Inertia test setup and assert it mounts; the baseline should fail with the missing `usePage` reference until the import is fixed.
- [ ] **Step 6: Run the focused baseline.**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpApiContractTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: the new assertions fail for missing pages/groups and the Attendance test reproduces the current defect; existing unrelated assertions remain green.

- [ ] **Step 7: Commit the contract tests.**

```powershell
git add -- tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpApiContractTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/Pages/ERP/HR/__tests__
git commit -m "test: define owner ERP parity contracts"
```

### Task 2: Make the owner catalog and sidebar group-aware

**Files:**

- Modify: `config/shop_modules.php`
- Modify: `app/Services/ErpWorkspaceNavigationService.php`
- Modify: `app/Services/ErpRouteCatalog.php`
- Modify: `app/Http/Controllers/ShopOwner/ErpWorkspaceController.php`
- Modify: `resources/js/layout/AppSidebar_shopOwner.tsx`
- Modify: `resources/js/types/shopModules.ts` if required by the returned payload type
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Modify: `tests/Unit/BusinessScaling/ErpRouteCatalogTest.php`

- [ ] **Step 1: Extend the canonical page metadata.** Add optional `groupKey`, `groupLabel`, `groupOrder`, and `pageOrder` (or the repository's equivalent naming) to the existing owner page catalog. Keep route name, URL, supporting capability/API pair, owner audience, and module key in the same source of truth.
- [ ] **Step 2: Populate approved groups.** Mark HR Attendance Monitoring and Payroll pages, Finance Approvals pages, and any other group that is verified from an existing ERP sidebar. Keep the portal `Approval Pages` group outside the active module payload.
- [ ] **Step 3: Return stable sorted data.** Have `ErpWorkspaceNavigationService` filter enabled/owner-capable pages, sort groups and pages deterministically, and return the group metadata needed by Inertia. A module with no group remains a flat list.
- [ ] **Step 4: Render grouped owner navigation.** Update `AppSidebar_shopOwner.tsx` so it renders direct pages plus collapsible groups from `activeModule.pages`, automatically opens the group containing the active URL, supports manual collapse/reopen, and keeps deep links/active styling correct.
- [ ] **Step 5: Preserve employee behavior.** Keep the existing employee `AppSidebar_ERP.tsx` groups and core portal `Approval Pages` behavior unchanged; add tests proving owner module groups do not leak employee-only links.
- [ ] **Step 6: Run the narrow checks.**

```powershell
php artisan test tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: catalog and sidebar group assertions pass; existing sidebar tests remain green.

- [ ] **Step 7: Commit the catalog/sidebar change.**

```powershell
git add -- config/shop_modules.php app/Services/ErpWorkspaceNavigationService.php app/Services/ErpRouteCatalog.php app/Http/Controllers/ShopOwner/ErpWorkspaceController.php resources/js/layout/AppSidebar_shopOwner.tsx resources/js/types/shopModules.ts resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx tests/Unit/BusinessScaling/ErpRouteCatalogTest.php
git commit -m "feat: render grouped owner ERP navigation"
```

### Task 3: Fix Attendance and audit-log production failures

**Files:**

- Modify: `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`
- Modify: `routes/shop-owner-erp-api.php`
- Verify/modify only if needed: `app/Http/Controllers/Erp/HR/AuditLogController.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpApiContractTest.php`
- Modify: focused Attendance frontend test from Task 1

- [ ] **Step 1: Add the missing Inertia import.** Import `usePage` from `@inertiajs/react` in `AttendanceRecords.tsx`, preserving the existing props and component exports.
- [ ] **Step 2: Correct namespace casing.** Use `App\\Http\\Controllers\\Erp\\HR\\AuditLogController` consistently for both HR and Finance route imports so Linux/Hostinger autoloading resolves the class.
- [ ] **Step 3: Verify owner context.** Confirm the audit controller still derives the tenant from `erp.actor_context`, keeps owner mode permission behavior, and does not fall back to a client-supplied shop ID.
- [ ] **Step 4: Run the focused regressions.**

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpApiContractTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php
pnpm exec vitest run resources/js/Pages/ERP/HR/__tests__/AttendanceRecords.test.tsx
```

Expected: both audit endpoints return 200/contract JSON, cross-shop authorization remains denied, and Attendance mounts without a `usePage` runtime error.

- [ ] **Step 5: Commit the production fixes.**

```powershell
git add -- resources/js/Pages/ERP/HR/AttendanceRecords.tsx routes/shop-owner-erp-api.php app/Http/Controllers/Erp/HR/AuditLogController.php tests/Feature/BusinessScaling/OwnerErpApiContractTest.php resources/js/Pages/ERP/HR/__tests__/AttendanceRecords.test.tsx
git commit -m "fix: restore owner ERP attendance and audit access"
```

### Task 4: Expand Retail and HR page routes using existing ERP pages

**Files:**

- Modify: `config/shop_modules.php`
- Modify: `routes/shop-owner-erp.php`
- Modify: `app/Http/Controllers/Erp/ReadPageController.php`
- Modify: `resources/js/Pages/ERP/HR/HR.tsx`
- Modify: `resources/js/Pages/ERP/HR/generateSlip.tsx`, `viewSlip.tsx`, and `SalaryChanges.tsx` only where owner-scoped capability URLs/props are required
- Modify: existing Retail page mapping files and/or `resources/js/Pages/ShopOwner/Ecommerce.tsx`
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`

- [ ] **Step 1: Add the failing route/page assertions.** Require `shop-owner.erp.retail.dashboard` plus the existing four Retail pages, and HR Dashboard, Employees, Attendance Monitoring children, Payroll children, and Audit Logs.
- [ ] **Step 2: Add the canonical Retail Dashboard route.** Render the existing E-commerce dashboard inside the ERP shell with `erpMode=true`; do not create duplicate legacy page entries.
- [ ] **Step 3: Add HR scoped page routes.** Map payroll sections to stable scoped URLs while keeping the existing `HR.tsx` section rendering reusable. Add initial owner-safe props where an existing page otherwise assumes a user actor.
- [ ] **Step 4: Adapt HR page requests.** Replace employee-only endpoint assumptions with the canonical `erpCapabilities` owner URLs or add bounded owner adapters. Keep `Log Attendance` and `My Payslips` excluded.
- [ ] **Step 5: Verify first-load and action paths.** Characterize attendance, leave, overtime, payroll read/generate/view/salary-change flows and ensure every listed page either operates as owner or is removed from the owner catalog with a clear denial reason.
- [ ] **Step 6: Run focused page contracts.**

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx
```

Expected: Retail and HR route lists match the approved catalog, direct URLs render inside the owner shell, and employee-only URLs remain denied.

- [ ] **Step 7: Commit the Retail/HR wave.**

```powershell
git add -- config/shop_modules.php routes/shop-owner-erp.php app/Http/Controllers/Erp/ReadPageController.php resources/js/Pages/ERP/HR resources/js/Pages/ShopOwner/Ecommerce.tsx tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
git commit -m "feat: expand owner retail and HR ERP pages"
```

### Task 5: Expand Finance with the existing page hierarchy

**Files:**

- Modify: `config/shop_modules.php`
- Modify: `routes/shop-owner-erp.php`
- Modify: `routes/shop-owner-erp-api.php` and/or existing owner finance API routes
- Modify: `app/Http/Controllers/Erp/ReadPageController.php`
- Modify: `resources/js/Pages/ERP/Finance/Dashboard.tsx`
- Modify: `resources/js/Pages/ERP/Finance/Invoice.tsx`
- Modify: `resources/js/Pages/ERP/Finance/createInvoice.tsx` only if it becomes an owner-scoped child action
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx`
- Modify: `resources/js/Pages/ERP/Finance/Finance.tsx` and existing approval components as needed
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpApiContractTest.php`

- [ ] **Step 1: Add failing Finance catalog assertions.** Require Dashboard, Invoices, Expenses, Audit Logs, and an Approvals group containing Expense, Repair Pricing, Shoe Pricing, Purchase Request, Refund, Payslip, and Salary Adjustment approval pages when each route/action is owner-safe.
- [ ] **Step 2: Map Finance page routes.** Add scoped owner page routes that reuse existing ERP Finance components or existing owner approval components; keep `My Payslips` employee-only.
- [ ] **Step 3: Map initial data and capability URLs.** Ensure Finance Dashboard, Invoice, and Expense components use owner-scoped initial props/capability URLs instead of `/api/finance/*` endpoints requiring `auth:user`.
- [ ] **Step 4: Preserve domain authorization.** Route approval, invoice, expense, refund, payslip, and salary actions through existing owner-safe controllers/services/Form Requests. Add owner performer/audit handling only where the existing operation needs it; do not bypass thresholds or maker/checker rules.
- [ ] **Step 5: Handle partial support safely.** If an existing Finance page cannot be made owner-safe in this wave, keep it out of the catalog and return an explicit unavailable state rather than showing a broken link.
- [ ] **Step 6: Run Finance tests.**

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpApiContractTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx
```

Expected: all listed Finance pages load with an owner session, mutation endpoints remain tenant-safe, and Finance audit logs no longer return 500.

- [ ] **Step 7: Commit the Finance wave.**

```powershell
git add -- config/shop_modules.php routes/shop-owner-erp.php routes/shop-owner-erp-api.php app/Http/Controllers/Erp/ReadPageController.php resources/js/Pages/ERP/Finance tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpApiContractTest.php
git commit -m "feat: expand owner Finance ERP pages"
```

### Task 6: Complete Inventory, Procurement, Logistics, CRM, and Repair catalog parity

**Files:**

- Modify: `config/shop_modules.php`
- Modify: `routes/shop-owner-erp.php`, `routes/shop-owner-erp-api.php`
- Modify: corresponding existing ERP page components under `resources/js/Pages/ERP/inventory`, `Procurement`, `Logistics`, `CRM`, and repair pages
- Modify: owner controllers/services/Form Requests only for bounded owner adapters
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`, API/authorization/tenant tests

- [ ] **Step 1: Build the parity matrix from the existing ERP sidebar and page files.** Compare current employee entries, current owner catalog entries, route pairs, and actual page-owned requests. Mark each as owner-capable, employee-only, or unavailable with a reason.
- [ ] **Step 2: Add missing owner-safe page entries.** Cover the existing company-operational pages for Inventory (dashboard, product inventory, movement, requests/approvals, supplier orders), Procurement (requests, approvals, purchase orders, suppliers), Logistics (dashboard, shipments, riders and supported dispatch pages), CRM (dashboard, customers, support, reviews), and Repair (dashboard and supported operations).
- [ ] **Step 3: Add only real groups.** Keep these modules flat when the existing page hierarchy is flat; declare a group only when it represents a verified operational family.
- [ ] **Step 4: Adapt all page requests.** Audit polling, refresh, filters, downloads, uploads, and mutations for employee-only URLs and route them through owner capabilities or remove the page from the owner catalog.
- [ ] **Step 5: Verify tenant and action boundaries.** Add cross-shop read/mutation denials and owner audit assertions for each newly exposed mutation; keep rider personal delivery execution and employee self-service unavailable.
- [ ] **Step 6: Run module-focused tests.**

```powershell
php artisan test --filter="OwnerErp|ShopOwner.*(Inventory|Procurement|Logistics|CRM|Repair)"
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: the generated owner catalog has no page without a route/API pair and no employee-only page is exposed.

- [ ] **Step 7: Commit the remaining module wave.**

```powershell
git add -- config/shop_modules.php routes/shop-owner-erp.php routes/shop-owner-erp-api.php app/Http/Controllers resources/js/Pages/ERP tests/Feature/BusinessScaling
git commit -m "feat: complete owner ERP module parity"
```

### Task 7: Regenerate matrix, run full verification, and review

**Files:**

- Modify generated/maintained route matrix through the repository command: `docs/architecture/shop-owner-erp-route-matrix.md`
- Modify `docs/ai-learning-log.md` only if a durable project lesson is discovered
- Review all changed files for dead code and stale employee URLs

- [ ] **Step 1: Regenerate and inspect the route matrix.**

```powershell
php artisan erp:route-matrix --write
```

Expected: every owner navigation page has a paired scoped route, capability/API contract, and explicit owner policy.

- [ ] **Step 2: Run diff hygiene and focused tests.**

```powershell
git diff --check
php artisan test tests/Feature/BusinessScaling
pnpm run test:frontend
```

Expected: all relevant tests pass; any pre-existing unrelated failures are recorded separately.

- [ ] **Step 3: Run the production frontend build.**

```powershell
pnpm run build
```

Expected: Vite completes successfully and writes a fresh `public/build`.

- [ ] **Step 4: Perform sequential review gates.** Review Standards, approved-spec compliance, owner authorization/tenant risk, TypeScript/React boundaries, code-splitting impact, simplification, and dead-code/reuse. Use `@superpowers:verification-before-completion` before claiming success.
- [ ] **Step 5: Verify worktree scope.** Confirm no unexpected deletions or unrelated edits, inspect `git diff --stat origin/solespace-b...HEAD`, and record exact command results.
- [ ] **Step 6: Review generated assets and commit final docs/matrix changes.** If `public/build` is tracked in this branch, include the fresh build output after reviewing its diff; never hand-edit generated files.

```powershell
git add -- docs/architecture/shop-owner-erp-route-matrix.md docs/ai-learning-log.md public/build
git commit -m "docs: refresh owner ERP route matrix"
```

Do not push as part of this plan unless the user separately requests it.

## Completion evidence

Before handoff, report:

- exact commits and changed module waves;
- exact test/build commands and pass/fail results;
- confirmation that Attendance mounts without `usePage` error;
- confirmation that Finance and HR audit logs return successfully;
- confirmation that grouped HR/Finance/sidebar navigation opens and deep-links correctly;
- fresh `public/build` status;
- any remaining page intentionally excluded because its owner API/action is not safe yet.
