# Shop Owner Phase 5 Legacy and ERP Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the separate owner ERP Workspace and duplicate owner entry points with one canonical, tenant-safe owner shell while preserving classified read access, authoritative workflows, maker/checker separation, centralized attention, and complete owner-operation audit evidence.

**Architecture:** Extend the existing route/capability catalog, canonical shell metadata, ERP actor context, Action Center adapters, and domain services; do not create parallel authorization, approval, navigation, or audit systems. Implementation proceeds through evidence gates: caller and capability characterization first, canonical UI second, maker/checker hardening before owner write exposure, and route/Workspace retirement last.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Spatie Laravel Activitylog, PHPUnit/Pest, Vitest, pnpm.

**Approved specification:** `docs/superpowers/specs/2026-08-23-shop-owner-phase-5-legacy-erp-consolidation-design.md`

**Authority reference:** `docs/architecture/shop-owner-phase-4-approval-matrix.md`

**Execution constraints:** Use `@laravel-best-practices`, `@vercel-react-best-practices`, `@ui-ux-pro-max`, `@ui-styling`, `@security-review`, `@karpathy-guidelines`, and `@superpowers:verification-before-completion` where applicable. Preserve the existing untracked `public/build/assets/*` and `storage/framework/cache/` files. Never add them to a commit. Use `pnpm`, not npm.

Before every commit, run `git status --short`, stage only the exact files named by the current task or completed evidence matrix, then run `git diff --cached --name-status` and `git diff --cached --check`. Stop if any unrelated file is staged.

## Task 1 evidence execution boundary

Task 1 is frozen in the completed [capability retirement matrix](../../architecture/shop-owner-phase-5-capability-retirement-matrix.md), [maker/checker matrix](../../architecture/shop-owner-phase-5-maker-checker-matrix.md), and [owner-operation audit matrix](../../architecture/shop-owner-phase-5-owner-operation-audit-matrix.md). This note constrains execution only; it does not change the approved goal or acceptance criteria.

- Every `STOP_FOCUSED_DESIGN` row is a hard fail-closed boundary. Tasks 2–6 may classify or consolidate only surfaces proven owner-readable by the evidence and must not expose a STOP row.
- Tasks 7–11 must not add maker fields, owner submission/correction actions, or audit instrumentation for STOP families until focused design/characterization updates the evidence.
- `N/A_NO_OWNER_INITIATION` retains its existing meaning: no owner initiation is inferred, added, or substituted with a redirect.
- Current stop categories are unsupported owner-readable data surfaces, including the Retail Dashboard's unnamed `GET /api/shop-owner/dashboard/stats` initial fetch; salary owner self-proposer Action Center exposure; the owner audit-export guard mismatch; uncharacterized correction transitions; and missing dedicated denied-maker audit implementation. These are evidence gaps, not authorization to invent fixes.

---

## File structure and responsibility map

### Evidence and durable contracts

- Create `docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md` for the exact material operation, implementation file, transaction boundary, safe audit properties, and focused test on every canonical owner surface.

- Create `docs/architecture/shop-owner-phase-5-capability-retirement-matrix.md` — repository caller inventory, owner readability classification, canonical destination, compatibility action, and retirement verdict.
- Create `docs/architecture/shop-owner-phase-5-maker-checker-matrix.md` — authoritative maker fields, owner initiation evidence, ON/OFF routing, correction transition, and stop verdict for every approval family.
- Modify `docs/architecture/shop-owner-erp-route-matrix.md` only through `php artisan erp:route-matrix --write` — generated route/capability evidence.
- Create `docs/shop-owner-phase-5-rollout-guide.md` — developer-only migration, browser QA, rollback, and retirement checklist.

### Canonical routing and navigation

- Modify `config/shop_modules.php` — remain the sole machine-readable route/capability/navigation catalog.
- Modify `app/Services/ErpRouteCatalog.php` — expose validated owner-readable and canonical-navigation metadata without inferring access.
- Modify `app/Services/ErpWorkspaceNavigationService.php` — derive Overview and local tabs only from classified owner-safe catalog entries.
- Modify `app/Services/OwnerShell/CanonicalOwnerShellService.php` — canonical grouped sidebar destinations and compatibility retirement.
- Modify `app/Http/Controllers/Erp/WorkspaceController.php` — serve canonical module Overview metadata without requiring a new dashboard.
- Modify `routes/shop-owner-shell.php` — canonical owner routes.
- Modify `routes/shop-owner-erp.php` and `routes/shop-owner-erp-api.php` — compatibility GETs and later Workspace retirement.
- Modify `resources/js/layout/CanonicalOwnerSidebar.tsx` — global owner groups only; no Business Settings or ERP Workspace fallback after retirement.
- Modify `resources/js/layout/AppLayout_ERP.tsx` — render owner local module navigation from server metadata.
- Create `resources/js/components/owner-shell/OwnerModuleTabs.tsx` — accessible module-local tabs shared by Overview and local pages.
- Modify `resources/js/Pages/ERP/ModuleLanding.tsx` — canonical Overview landing; reuse available content and do not manufacture dashboards.

### Parent-page actions

- Modify `resources/js/Pages/ERP/Finance/Invoice.tsx` — own Create Invoice.
- Modify `resources/js/Pages/ERP/Finance/createInvoice.tsx` only for return/breadcrumb consistency.
- Modify `resources/js/Pages/ERP/HR/viewSlip.tsx` — own Generate Slip.
- Modify `resources/js/Pages/ERP/HR/generateSlip.tsx` only for return/breadcrumb consistency.
- Modify `resources/js/Pages/ERP/inventory/ProductInventory.tsx` — own Upload Stocks.
- Modify `resources/js/Pages/ERP/inventory/UploadInventory.tsx` only for return/breadcrumb consistency.

### Attention and maker/checker safety

- Modify `app/Support/OwnerActionCenter/OwnerAttentionQuery.php`, `app/Services/OwnerActionCenter/OwnerActionCenterService.php`, and the applicable adapters — Waiting on Others remains visible; characterized conflicts feed a distinct Needs Correction surface.
- Modify `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php` and `resources/js/Pages/ShopOwner/ActionCenter.tsx` — distinct correction presentation and badge parity.
- Modify `resources/js/types/ownerActionCenter.ts` — typed correction contract.
- Create `app/Support/Approvals/AuthoritativeMaker.php` — validate exactly one staff/owner maker without polymorphic persistence.
- Create migrations and update only the models/services marked `IMPLEMENT` by the maker/checker matrix.

### Canonical audit

- Create `app/Services/OwnerOperationAudit.php` — one safe Spatie activity-log writer for material owner operations and denied sensitive attempts.
- Modify `app/Http/Controllers/ActivityLogController.php` — tenant-safe display/filter support for the canonical owner-operation properties.
- Instrument only characterized owner mutations in their existing transaction boundaries.

---

### Task 1: Freeze repository caller, capability, and maker/checker evidence

**Files:**
- Create: `docs/architecture/shop-owner-phase-5-capability-retirement-matrix.md`
- Create: `docs/architecture/shop-owner-phase-5-maker-checker-matrix.md`
- Create: `docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md`
- Reference: `docs/architecture/shop-owner-phase-4-approval-matrix.md`
- Reference: `docs/architecture/shop-owner-erp-route-matrix.md`

- [ ] **Step 1: Record the clean planning baseline without touching generated files**

Run:

```powershell
git status --short
git log -5 --oneline
```

Expected: the two Phase 5 design commits are present; existing untracked build/cache files may remain and are explicitly excluded.

- [ ] **Step 2: Inventory repository callers before route edits**

Run CodeGraph first, then supplement string-built callers:

```powershell
codegraph explore "shop-owner.erp.workspace shop-owner.erp.module legacyRedirect create-invoice payroll-generate upload-stocks audit-logs approval routes notifications"
rg -n "shop-owner\.erp|erp/workspace|create-invoice|payroll-generate|upload-stocks|approval|audit-logs" app resources/js routes tests docs --glob '!resources/js/ziggy.js'
php artisan route:list --path=shop-owner --json
```

Expected: every route has its route name, HTTP method, caller type, actor audience, and canonical candidate recorded.

- [ ] **Step 3: Write the capability-retirement and data-surface matrix**

Create one row per route plus one row per distinct data surface exposed by that route:

```markdown
| Existing route/page | Surface kind | Record/report/export/aggregate | Sensitive field set | Method | Audience | Module | Owner-readable classification | Capability/policy | Canonical destination | Known callers | Compatibility action | Implementation file | Focused test | Retirement evidence | Verdict |
```

`Surface kind` covers page, API, record type, report, export/download, aggregate/metric, and sensitive field set. Allowed readability values are `OWNER_READABLE`, `CONDITIONAL`, and `OWNER_DENIED`. Module visibility is never evidence for a row. A page cannot be marked readable while one of its initial fetches or required field sets remains unclassified.

- [ ] **Step 4: Write the maker/checker matrix for all seven Phase 4 families**

Use these columns:

```markdown
| Family | Owner initiation path and creation boundary | Staff maker field | ShopOwner maker field | Maker set at creation | Different submitter supported | ON behavior | Proven OFF authority | Existing correction transition | Authorized correction actor | Correction state guard | Correction audit | Correction notifications | Correction downstream effects | Verdict |
```

Allowed verdicts are `IMPLEMENT`, `N/A_NO_OWNER_INITIATION`, and `STOP_FOCUSED_DESIGN`. Cover Refund, Price, Payslip, Salary Adjustment, Purchase Request, Expense, and Repair Reject.

- [ ] **Step 5: Enforce the stop checkpoint**

Do not proceed with a family if its OFF authority or correction transition is not already authoritative. Do not add a maker column to an `N/A_NO_OWNER_INITIATION` family. If any row is `STOP_FOCUSED_DESIGN`, update the focused design and this plan before implementation.

- [ ] **Step 6: Write the complete owner-operation audit matrix**

Inventory every material operation reachable from Home, Action Center, Operate, Oversee, Reports, Settings, Overview, local pages, record details, protected exports/access, and denied sensitive requests:

```markdown
| Surface | Route/action | Material operation | Actor/capability | Domain implementation file | Transaction boundary | Audit event/result | Safe properties | Denied-attempt behavior | Focused test | Verdict |
```

Routine page loads and tab changes receive `N/A_ROUTINE_VIEW`. Every material row must name an exact implementation file and focused test before audit instrumentation begins.

- [ ] **Step 7: Commit the evidence checkpoint**

```powershell
git add -- docs/architecture/shop-owner-phase-5-capability-retirement-matrix.md docs/architecture/shop-owner-phase-5-maker-checker-matrix.md docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md
git commit -m "docs: characterize phase 5 owner capabilities"
```

### Task 2: Make owner readability explicit in the existing route catalog

**Files:**
- Modify: `config/shop_modules.php`
- Modify: `app/Services/ErpRouteCatalog.php`
- Modify: `app/Services/ErpWorkspaceNavigationService.php`
- Test: `tests/Unit/BusinessScaling/ErpRouteCatalogTest.php`
- Test: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Test: `tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php`

- [ ] **Step 1: Write failing catalog tests**

Add assertions that every owner-visible local page and each required API, record type, report, export, aggregate, and sensitive field set has an explicit matrix-backed classification:

```php
$entry = app(ErpRouteCatalog::class)->entry('shop-owner.erp.finance.invoices');

$this->assertSame('shop_owner', $entry['actor_guard']);
$this->assertSame('allowed', $entry['owner_access']);
$this->assertSame('finance', $entry['navigation_group']);
$this->assertSame('Invoices', $entry['navigation_label']);
```

Also assert an owner-denied or unclassified route never appears in `ErpWorkspaceNavigationService::forKey(...)[pages]`, and a page contract fails when any required initial fetch or data surface is missing, denied, or unclassified.

- [ ] **Step 2: Run the focused tests and verify failure**

```powershell
php artisan test tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
```

Expected: FAIL on at least one unclassified, duplicate, or incorrectly visible page identified by Task 1.

- [ ] **Step 3: Classify every local page and API pair in `config/shop_modules.php`**

Reuse existing `owner_access`, `actor_guard`, `classification`, `navigation_group`, `navigation_visible`, and paired-route fields. Do not add a second read-access registry. Cross-check non-route field-set and aggregate decisions against the checked-in capability matrix from Task 1. Set `navigation_visible=false` for create-only pages, duplicate dashboards, module audit pages, and approval queues that have canonical parent destinations.

- [ ] **Step 4: Fail closed in catalog-derived navigation**

In `pagesForKey()`, retain only entries satisfying all owner contracts:

```php
if (($entry['classification'] ?? null) !== 'module'
    || ($entry['audience'] ?? null) !== 'shop_owner'
    || ($entry['actor_guard'] ?? null) !== 'shop_owner'
    || ($entry['owner_access'] ?? null) !== 'allowed'
    || ($entry['navigation_group'] ?? null) !== $moduleKey
    || ($entry['navigation_visible'] ?? false) !== true) {
    continue;
}
```

- [ ] **Step 5: Run tests and regenerate the route matrix**

```powershell
php artisan test tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php
php artisan erp:route-matrix --write
```

Expected: PASS; generated matrix shows no owner-visible unclassified surface.

- [ ] **Step 6: Commit**

```powershell
git add -- config/shop_modules.php app/Services/ErpRouteCatalog.php app/Services/ErpWorkspaceNavigationService.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php docs/architecture/shop-owner-erp-route-matrix.md
git commit -m "feat: classify canonical owner module surfaces"
```

### Task 3: Make Overview the canonical landing and local tabs the module navigator

**Execution note:** Finance Overview may be a canonical landing only when its current read contract is proven. Task 1/2 evidence marks `finance.invoices` as `STOP_FOCUSED_DESIGN` and `finance.expenses` as conditional/unproven, so neither is a Task 3 local tab and no Finance read API is added. Use already-proven CRM Overview, Customers, and Customer Reviews (or Logistics Overview and Shipments) for the local-tab contract tests.

**Files:**
- Modify: `app/Http/Controllers/Erp/WorkspaceController.php`
- Modify: `app/Services/ErpWorkspaceNavigationService.php`
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Create: `resources/js/components/owner-shell/OwnerModuleTabs.tsx`
- Modify: `resources/js/Pages/ERP/ModuleLanding.tsx`
- Test: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php`
- Test: `resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx`
- Create: `resources/js/components/owner-shell/__tests__/OwnerModuleTabs.test.tsx`

- [ ] **Step 1: Write failing route and component tests**

Prove that `/shop-owner/operate/customers` returns `activeModule`, an explicit Overview item, and the proven CRM local pages; prove the tabs render Overview, Customers, and Customer Reviews without rendering Finance STOP/conditional, approval, audit, or create-only links. Assert the Finance landing keeps its unproven local pages absent.

- [ ] **Step 2: Run tests to verify failure**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php
pnpm exec vitest run resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx resources/js/components/owner-shell/__tests__/OwnerModuleTabs.test.tsx
```

- [ ] **Step 3: Add an explicit Overview entry to module payloads**

Return one server-derived item before local pages:

```php
'overview' => [
    'label' => 'Overview',
    'url' => route($canonicalModuleRoute),
],
```

Do not query new metrics. `ModuleLanding.tsx` may render the existing module description and available-page summary when no reusable domain summary exists.

- [ ] **Step 4: Implement accessible local tabs**

`OwnerModuleTabs` receives only server-derived links, uses `aria-current="page"`, supports horizontal overflow on small screens, and never decides authorization client-side.

- [ ] **Step 5: Render tabs only in owner module context**

Employee ERP layout behavior remains unchanged. Simultaneous owner and employee sessions must use `erp.actor_context`, not whichever guard resolves first.

- [ ] **Step 6: Run tests and commit**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php tests/Feature/BusinessScaling/ErpActorContextTest.php
pnpm exec vitest run resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx resources/js/components/owner-shell/__tests__/OwnerModuleTabs.test.tsx
git add -- app/Http/Controllers/Erp/WorkspaceController.php app/Services/ErpWorkspaceNavigationService.php resources/js/layout/AppLayout_ERP.tsx resources/js/components/owner-shell/OwnerModuleTabs.tsx resources/js/components/owner-shell/__tests__/OwnerModuleTabs.test.tsx resources/js/Pages/ERP/ModuleLanding.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php
git commit -m "feat: add canonical owner module overviews"
```

### Task 4: Keep the global sidebar canonical and Settings in the header

**Files:**
- Modify: `app/Services/OwnerShell/CanonicalOwnerShellService.php`
- Modify: `resources/js/layout/CanonicalOwnerSidebar.tsx`
- Modify: `resources/js/layout/CanonicalOwnerHeader.tsx`
- Test: `tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php`
- Test: `resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx`
- Test: `resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`

- [ ] **Step 1: Write failing shell tests**

Assert grouped Home, Action Center, Operate, Oversee, Reports & Audit; assert no Business Settings item; assert the header/account menu links to `shop-owner.shell.settings.profile`; assert modules do not expand into local pages in the global sidebar.

- [ ] **Step 2: Run tests to verify failure**

```powershell
php artisan test tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php
pnpm exec vitest run resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx
```

- [ ] **Step 3: Remove duplicate sidebar branches but retain compatibility metadata**

Do not remove the ERP fallback link yet; final retirement occurs only in Task 13. Keep unavailable modules linked to Settings -> Modules & Team and deny ineligible modules.

- [ ] **Step 4: Run tests and commit**

```powershell
php artisan test tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php
pnpm exec vitest run resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx
git add -- app/Services/OwnerShell/CanonicalOwnerShellService.php resources/js/layout/CanonicalOwnerSidebar.tsx resources/js/layout/CanonicalOwnerHeader.tsx tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx
git commit -m "refactor: simplify canonical owner navigation"
```

### Task 5: Nest creation actions in their parent pages

**Files:**
- Modify: `config/shop_modules.php`
- Modify: `resources/js/Pages/ERP/Finance/Invoice.tsx`
- Modify: `resources/js/Pages/ERP/Finance/createInvoice.tsx`
- Modify: `resources/js/Pages/ERP/HR/viewSlip.tsx`
- Modify: `resources/js/Pages/ERP/HR/generateSlip.tsx`
- Modify: `resources/js/Pages/ERP/inventory/ProductInventory.tsx`
- Modify: `resources/js/Pages/ERP/inventory/UploadInventory.tsx`
- Create: `resources/js/Pages/ERP/Finance/__tests__/Invoice.owner-actions.test.tsx`
- Create: `resources/js/Pages/ERP/HR/__tests__/viewSlip.owner-actions.test.tsx`
- Create: `resources/js/Pages/ERP/inventory/__tests__/ProductInventory.owner-actions.test.tsx`
- Modify/Test: every additional create, upload, generate, export, or archive route and owning page classified by the completed capability-retirement matrix.

The three file pairs above are mandatory examples, not the complete scope. The completed matrix is authoritative for equivalent actions.

- [ ] **Step 1: Extract the complete parent-action checklist from the capability matrix**

For every create, upload, generate, export, or archive route, record its owning list/detail page, exact implementation files, exact focused test, and whether it is an embedded action or a justified durable destination. No route may be hidden before this row is complete.

- [ ] **Step 2: Write failing UI tests for every embedded-action row**

Assert Create Invoice exists inside Invoices, Generate Slip inside Payroll/Payslips, Upload Stocks inside Product Inventory, and every equivalent matrix row appears on its owning page. Assert embedded actions are absent from module tabs and the global sidebar. For a justified durable destination, assert it remains navigable and document the reason in the matrix.

- [ ] **Step 3: Run focused tests and verify failure**

```powershell
pnpm exec vitest run resources/js/Pages/ERP/Finance/__tests__/Invoice.owner-actions.test.tsx resources/js/Pages/ERP/HR/__tests__/viewSlip.owner-actions.test.tsx resources/js/Pages/ERP/inventory/__tests__/ProductInventory.owner-actions.test.tsx
```

- [ ] **Step 4: Add parent-page action buttons using existing routes**

Buttons remain capability-driven, keyboard accessible, and placed in the page header. Keep create-page routes as compatible GET destinations; do not delete them in this task.

- [ ] **Step 5: Hide only fully migrated embedded-action routes from catalog navigation**

Set `navigation_visible=false` only after the owning-page action test passes. Retain owner access only where Task 1 classified it. Do not hide a durable destination or an action whose owning page has not been implemented and tested.

- [ ] **Step 6: Run tests and commit in exact matrix batches**

```powershell
pnpm exec vitest run resources/js/Pages/ERP/Finance/__tests__/Invoice.owner-actions.test.tsx resources/js/Pages/ERP/HR/__tests__/viewSlip.owner-actions.test.tsx resources/js/Pages/ERP/inventory/__tests__/ProductInventory.owner-actions.test.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx
git add -- config/shop_modules.php resources/js/Pages/ERP/Finance/Invoice.tsx resources/js/Pages/ERP/Finance/createInvoice.tsx resources/js/Pages/ERP/HR/viewSlip.tsx resources/js/Pages/ERP/HR/generateSlip.tsx resources/js/Pages/ERP/inventory/ProductInventory.tsx resources/js/Pages/ERP/inventory/UploadInventory.tsx resources/js/Pages/ERP/Finance/__tests__/Invoice.owner-actions.test.tsx resources/js/Pages/ERP/HR/__tests__/viewSlip.owner-actions.test.tsx resources/js/Pages/ERP/inventory/__tests__/ProductInventory.owner-actions.test.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx
git commit -m "refactor: nest owner module creation actions"
```

Repeat the safe staging/commit sequence using the exact implementation and test files for each additional matrix row; never use a broad directory add.

### Task 6: Preserve Action Center buckets and badge semantics

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php`
- Modify: `app/Http/Controllers/ShopOwner/OwnerActionCenterSummaryController.php`
- Modify: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Modify: `resources/js/types/ownerActionCenter.ts`
- Modify: `resources/js/layout/CanonicalOwnerSidebar.tsx`
- Test: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`
- Test: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php`
- Test: `resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx` or the existing Action Center test file.

- [ ] **Step 1: Write failing parity tests**

Prove Waiting on Others appears when enabled, and summary endpoint/sidebar badge always uses only `needs_my_decision`:

```php
$response->assertJsonPath('pending_count', $needsMyDecisionTotal);
$this->assertNotSame($allBucketTotal, $needsMyDecisionTotal);
```

- [ ] **Step 2: Run tests to verify the current gap**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php
pnpm exec vitest run resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx
```

- [ ] **Step 3: Update wording and preserve existing bucket authority**

Needs My Decision, Urgent Exceptions, and Waiting on Others remain backed by existing adapters. Do not count Waiting on Others or exceptions in the sidebar badge.

- [ ] **Step 4: Run tests and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php
pnpm exec vitest run resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx
git add -- app/Http/Controllers/ShopOwner/OwnerActionCenterController.php app/Http/Controllers/ShopOwner/OwnerActionCenterSummaryController.php resources/js/Pages/ShopOwner/ActionCenter.tsx resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/types/ownerActionCenter.ts resources/js/layout/CanonicalOwnerSidebar.tsx tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php
git commit -m "fix: preserve owner action center bucket semantics"
```

### Task 7: Add the non-polymorphic authoritative-maker foundation

**Files:**
- Create: `app/Support/Approvals/AuthoritativeMaker.php`
- Create: `tests/Unit/Support/Approvals/AuthoritativeMakerTest.php`
- Create and modify only the exact family files below when Task 1 marks that family `IMPLEMENT`; leave `N/A_NO_OWNER_INITIATION` rows unchanged.

| Family | Exact creation boundary | Model and migration | Exact focused tests |
| --- | --- | --- | --- |
| Refund | `app/Services/OrderRefundService.php` (`reserveOrderRefund`) | `app/Models/OrderRefund.php`; `database/migrations/2026_08_23_000001_add_shop_owner_maker_to_order_refunds_table.php` | `tests/Feature/OrderRefundApprovalWorkflowTest.php`; `tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php` |
| Price | `app/Http/Controllers/Api/PriceChangeRequestController.php` (`store`) | `app/Models/PriceChangeRequest.php`; `database/migrations/2026_08_23_000002_add_shop_owner_maker_to_price_change_requests_table.php` | `tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php` |
| Payslip | `app/Http/Controllers/Erp/HR/PayrollController.php` (`store`); `app/Services/HR/PayrollService.php` (`generatePayroll`) | `app/Models/HR/Payroll.php`; `database/migrations/2026_08_23_000003_add_shop_owner_maker_to_payrolls_table.php` | `tests/Feature/HR/PayrollControllerTest.php`; `tests/Feature/Finance/PayslipApprovalWorkflowTest.php`; `tests/Unit/Services/PayrollServiceTest.php` |
| Salary Adjustment | `app/Http/Controllers/Erp/HR/SalaryChangeController.php` (`store`) | `app/Models/HR/SalaryChange.php`; `database/migrations/2026_08_23_000004_add_shop_owner_maker_to_salary_changes_table.php` | `tests/Feature/ShopOwner/ApprovalAuthorityCharacterizationTest.php`; `tests/Feature/HR/SalaryChangeOwnerApprovalTest.php` |
| Purchase Request | `app/Http/Controllers/Erp/PurchaseRequestController.php` (`store`, `submitToFinance`); `app/Services/PurchaseRequestService.php` (`createPurchaseRequest`, `submitToFinance`); `app/Http/Requests/StorePurchaseRequestRequest.php` | `app/Models/PurchaseRequest.php`; `database/migrations/2026_08_23_000005_add_shop_owner_maker_to_purchase_requests_table.php` | `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`; `tests/Unit/Services/PurchaseRequestServiceTest.php`; `tests/Unit/Models/PurchaseRequestTest.php` |
| Expense | `app/Http/Controllers/Api/Finance/ExpenseController.php` (`store`) | `app/Models/Finance/Expense.php`; `database/migrations/2026_08_23_000006_add_shop_owner_maker_to_finance_expenses_table.php` | `tests/Feature/Finance/ExpenseApprovalWorkflowTest.php`; `tests/Feature/Finance/ExpenseSettlementTest.php` |
| Repair Reject | `app/Http/Controllers/Api/RepairWorkflowController.php` (`rejectRepair`) | `app/Models/RepairRequest.php`; `database/migrations/2026_08_23_000007_add_shop_owner_rejection_maker_to_repair_requests_table.php` | `tests/Feature/ShopOwner/ApprovalAuthorityCharacterizationTest.php`; `tests/Feature/Manager/ManagerRepairRejectionTest.php`; `tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php` |

The migration filenames are reserved so every possible `IMPLEMENT` row has an exact path. Do not create a reserved migration for an `N/A_NO_OWNER_INITIATION` or `STOP_FOCUSED_DESIGN` row. Controllers that validate inline remain inline; Purchase Request is the only listed family whose existing creation boundary includes a Form Request.

- [ ] **Step 1: Write the failing value-object tests**

```php
it('requires exactly one authoritative maker', function () {
    expect(fn () => AuthoritativeMaker::from(null, null))->toThrow(ValidationException::class);
    expect(fn () => AuthoritativeMaker::from(10, 20))->toThrow(ValidationException::class);
    expect(AuthoritativeMaker::from(10, null)->isStaff())->toBeTrue();
    expect(AuthoritativeMaker::from(null, 20)->isOwner())->toBeTrue();
});
```

- [ ] **Step 2: Run the test and verify failure**

```powershell
php artisan test tests/Unit/Support/Approvals/AuthoritativeMakerTest.php
```

- [ ] **Step 3: Implement the minimal immutable identity helper**

The helper accepts `?int $staffMakerId` and `?int $shopOwnerMakerId`, validates XOR, and exposes `isOwner()`, `staffId()`, and `shopOwnerId()`. It does not store a type discriminator and does not infer identity from audit logs.

- [ ] **Step 4: Write failing per-model and creation-boundary tests before schema changes**

For every `IMPLEMENT` row, add focused tests at the exact creation controller/service named by the maker/checker matrix. Prove maker persistence at initial creation, a nullable ShopOwner maker relationship, XOR at submission, immutability after edits, separate submitter attribution when supported, explicit handling of legacy drafts, and transaction rollback. Do not rely only on the shared helper test.

- [ ] **Step 5: Run the per-model tests and verify failure**

Run the exact focused model and service tests listed by each `IMPLEMENT` matrix row. Expected: FAIL because owner-maker columns/relationships and creation-boundary persistence do not yet exist.

- [ ] **Step 6: Add separate owner-maker foreign keys and model relationships only for `IMPLEMENT` rows**

Follow each domain's vocabulary, for example `requested_by_shop_owner_id`, `created_by_shop_owner_id`, or `proposed_by_shop_owner_id`. Add separate submitter fields only where the matrix proves another actor may submit the maker's draft. Reconcile legacy drafts before adding submitted-state constraints; do not guess maker identity.

- [ ] **Step 7: Implement creation-boundary persistence and immutable model behavior**

Derive the initial maker from explicit `ErpActorContext` or the route's single authoritative guard. Never accept maker IDs from request payloads. Add model relationships and submission validation needed to make the failing tests pass.

- [ ] **Step 8: Run migration/model tests and commit**

```powershell
php artisan test tests/Unit/Support/Approvals/AuthoritativeMakerTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
git add -- app/Support/Approvals/AuthoritativeMaker.php tests/Unit/Support/Approvals/AuthoritativeMakerTest.php
git commit -m "feat: validate authoritative approval makers"
```

Then stage each `IMPLEMENT` row as a separate family commit using its exact command below; skip the entire command for `N/A_NO_OWNER_INITIATION` and stop for `STOP_FOCUSED_DESIGN`. Do not stage whole directories.

```powershell
# Refund, only when IMPLEMENT:
git add -- database/migrations/2026_08_23_000001_add_shop_owner_maker_to_order_refunds_table.php app/Models/OrderRefund.php app/Services/OrderRefundService.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php
git commit -m "feat: persist refund maker identity"

# Price, only when IMPLEMENT:
git add -- database/migrations/2026_08_23_000002_add_shop_owner_maker_to_price_change_requests_table.php app/Models/PriceChangeRequest.php app/Http/Controllers/Api/PriceChangeRequestController.php tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php
git commit -m "feat: persist price change maker identity"

# Payslip, only when IMPLEMENT:
git add -- database/migrations/2026_08_23_000003_add_shop_owner_maker_to_payrolls_table.php app/Models/HR/Payroll.php app/Http/Controllers/Erp/HR/PayrollController.php app/Services/HR/PayrollService.php tests/Feature/HR/PayrollControllerTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Unit/Services/PayrollServiceTest.php
git commit -m "feat: persist payslip maker identity"

# Salary Adjustment, only when IMPLEMENT:
git add -- database/migrations/2026_08_23_000004_add_shop_owner_maker_to_salary_changes_table.php app/Models/HR/SalaryChange.php app/Http/Controllers/Erp/HR/SalaryChangeController.php tests/Feature/ShopOwner/ApprovalAuthorityCharacterizationTest.php tests/Feature/HR/SalaryChangeOwnerApprovalTest.php
git commit -m "feat: persist salary adjustment maker identity"

# Purchase Request, only when IMPLEMENT:
git add -- database/migrations/2026_08_23_000005_add_shop_owner_maker_to_purchase_requests_table.php app/Models/PurchaseRequest.php app/Http/Controllers/Erp/PurchaseRequestController.php app/Services/PurchaseRequestService.php app/Http/Requests/StorePurchaseRequestRequest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Unit/Services/PurchaseRequestServiceTest.php tests/Unit/Models/PurchaseRequestTest.php
git commit -m "feat: persist purchase request maker identity"

# Expense, only when IMPLEMENT:
git add -- database/migrations/2026_08_23_000006_add_shop_owner_maker_to_finance_expenses_table.php app/Models/Finance/Expense.php app/Http/Controllers/Api/Finance/ExpenseController.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/ExpenseSettlementTest.php
git commit -m "feat: persist expense maker identity"

# Repair Reject, only when IMPLEMENT:
git add -- database/migrations/2026_08_23_000007_add_shop_owner_rejection_maker_to_repair_requests_table.php app/Models/RepairRequest.php app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/ShopOwner/ApprovalAuthorityCharacterizationTest.php tests/Feature/Manager/ManagerRepairRejectionTest.php tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php
git commit -m "feat: persist repair rejection maker identity"
```

For each executed family command, insert `git diff --cached --name-status` and `git diff --cached --check` before its commit as required by the global safe-commit rule. A listed creation boundary that characterization proves is not owner-accessible is evidence for `N/A_NO_OWNER_INITIATION`, not permission to expose it.

### Task 8: Enforce owner-made ON/OFF submission behavior per characterized family

**Files:**
- Modify as applicable: `app/Services/PurchaseRequestService.php`
- Modify as applicable: `app/Services/ExpenseApprovalService.php`
- Modify as applicable: `app/Services/PriceChangeApprovalService.php`
- Modify as applicable: `app/Services/PayslipApprovalService.php`
- Modify as applicable: `app/Services/HR/SalaryChangeApprovalService.php`
- Modify as applicable: `app/Services/OrderRefundService.php`
- Modify as applicable: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify applicable owner/ERP controllers and Form Requests identified in Task 1.
- Test existing family workflow test files listed in the Phase 4 matrix.

- [ ] **Step 1: Add failing tests for every `IMPLEMENT` family**

Each applicable family proves:

```php
// staff maker + ON: existing owner stage
// staff maker + OFF: exact proven non-owner downstream stage
// owner maker + ON: 422 before workflow entry
// owner maker + OFF: exact proven Phase 4 downstream state
// both maker references or neither maker: rejected at real submission boundary
// edit/reassignment: maker remains immutable
// direct endpoint call: same guard
// different submitter: maker unchanged and submitter separately persisted
// simultaneous sessions: shop_owner actor wins on canonical owner route
// cross-tenant and wrong-owner actor: denied without mutation
// repeated submission/retry: no duplicate transition, downstream effect, notification, or audit
// stale or invalid workflow state: rejected without mutation or success audit
```

- [ ] **Step 2: Run each family test before changing its service**

```powershell
php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php
php artisan test tests/Feature/Finance/ExpenseApprovalWorkflowTest.php
php artisan test tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php
php artisan test tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php
```

Expected: only applicable owner-maker cases fail.

- [ ] **Step 3: Guard submission inside locked domain transactions**

Evaluate and snapshot the approval toggle at submission. If maker is the same ShopOwner and snapshot is ON, throw a validation error before changing status. If OFF, call only the downstream transition named in the Phase 4 matrix. Never mark the owner stage approved for compatibility.

- [ ] **Step 4: Protect maker immutability at every write boundary**

Form Requests must not accept maker IDs from clients. Controllers derive the maker from explicit `ErpActorContext`; services reject attempts to update maker fields after creation.

- [ ] **Step 5: Run family and security tests**

```powershell
php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php tests/Feature/BusinessScaling/ErpActorContextTest.php
```

- [ ] **Step 6: Stage and commit one family or tightly coupled family group at a time**

Use the exact service, controller/Form Request, migration/model, and focused tests named by that family matrix row. Review the staged diff before each commit:

```powershell
git status --short
git add -- <exact-family-files-from-maker-checker-matrix>
git diff --cached --name-status
git diff --cached --check
git commit -m "fix: prevent owner purchase request self-approval"
git commit -m "fix: preserve maker checks in finance approvals"
git commit -m "fix: harden remaining owner approval makers"
```

### Task 9: Add the distinct Needs Correction surface without inventing transitions

**Files:**
- Modify: `app/Support/OwnerActionCenter/OwnerAttentionQuery.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify applicable adapters under `app/Services/OwnerActionCenter/Adapters/`
- Modify: `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php`
- Modify: `resources/js/types/ownerActionCenter.ts`
- Modify: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Test: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`
- Test applicable adapter tests under `tests/Feature/ShopOwner/ActionCenter/`.

- [ ] **Step 1: Write failing conflict-projection tests**

Create an owner-pending record with the same persisted owner maker and prove it is absent from Needs My Decision, excluded from the summary count, and present only on Needs Correction.

- [ ] **Step 2: Write failing correction-action tests per family**

Expose Withdraw/Cancel only for rows whose Task 1 matrix names an existing transition. Assert Approve and Reject are absent and direct calls are denied.

- [ ] **Step 3: Run tests to verify failure**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter
```

- [ ] **Step 4: Add a distinct typed correction surface**

Reuse the adapter aggregation and stable source identity, but keep correction qualification separate from normal decision qualification. Do not add correction totals to `OwnerActionCenterSummaryController`.

- [ ] **Step 5: Delegate only to proven domain correction methods**

If a family lacks an authoritative transition, stop that family and update the focused design. Do not add a generic withdrawal endpoint.

- [ ] **Step 6: Run tests and commit**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter
pnpm exec vitest run resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx
git add -- app/Support/OwnerActionCenter/OwnerAttentionQuery.php app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php app/Services/OwnerActionCenter/Adapters/OrderRefundAttentionAdapter.php app/Services/OwnerActionCenter/Adapters/RepairRefundAttentionAdapter.php app/Services/OwnerActionCenter/Adapters/PriceApprovalAttentionAdapter.php app/Services/OwnerActionCenter/Adapters/PayslipAttentionAdapter.php app/Services/OwnerActionCenter/Adapters/SalaryChangeAttentionAdapter.php app/Services/OwnerActionCenter/Adapters/PurchaseRequestAttentionAdapter.php app/Services/OwnerActionCenter/Adapters/ExpenseAttentionAdapter.php app/Services/OwnerActionCenter/Adapters/RepairRejectAttentionAdapter.php app/Http/Controllers/ShopOwner/OwnerActionCenterController.php resources/js/types/ownerActionCenter.ts resources/js/Pages/ShopOwner/ActionCenter.tsx resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Feature/ShopOwner/ActionCenter/OrderRefundAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/RepairRefundAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/PriceApprovalAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/PayslipAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/SalaryChangeAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/PurchaseRequestAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/ExpenseAttentionAdapterTest.php tests/Feature/ShopOwner/ActionCenter/RepairRejectAttentionAdapterTest.php tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php
git commit -m "feat: separate owner approval corrections"
```

### Task 10: Standardize canonical owner-operation audit evidence

**Files:**
- Create: `app/Services/OwnerOperationAudit.php`
- Modify: `app/Http/Controllers/ActivityLogController.php`
- Create: `tests/Unit/Services/OwnerOperationAuditTest.php`
- Create: `tests/Feature/ShopOwner/CanonicalAuditTest.php`

- [ ] **Step 1: Write failing transaction and privacy tests**

Prove a committed owner mutation writes one `activity_log` row with ShopOwner causer, shop, module, action, target, result, correlation ID, and safe before/after values. Prove rollback leaves no success row, denied sensitive attempts are recorded without secrets, and routine page/tab views are not logged.

- [ ] **Step 2: Run tests to verify failure**

```powershell
php artisan test tests/Unit/Services/OwnerOperationAuditTest.php tests/Feature/ShopOwner/CanonicalAuditTest.php
```

- [ ] **Step 3: Implement one safe writer over Spatie Activitylog**

The service accepts an explicit ShopOwner and tenant ID, validates same-shop context, filters properties through an allowlist, and writes on the caller's database connection/transaction. External effects remain after commit or use existing compensation.

- [ ] **Step 4: Make canonical Audit display the new events safely**

Extend `safeFieldsByModel` only for needed business fields. Keep tenant filtering by ShopOwner causer or same-shop User causer; never expose raw properties, credentials, or unrestricted personal data.

- [ ] **Step 5: Run foundation tests and commit**

```powershell
php artisan test tests/Unit/Services/OwnerOperationAuditTest.php tests/Feature/ShopOwner/CanonicalAuditTest.php tests/Feature/Manager/ManagerAuditLogsTest.php
git add -- app/Services/OwnerOperationAudit.php app/Http/Controllers/ActivityLogController.php tests/Unit/Services/OwnerOperationAuditTest.php tests/Feature/ShopOwner/CanonicalAuditTest.php tests/Feature/Manager/ManagerAuditLogsTest.php
git diff --cached --name-status
git diff --cached --check
git commit -m "feat: standardize owner operation audit"
```

### Task 11: Instrument every material canonical owner operation

**Files:**
- Modify: exact domain implementation files listed in `docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md`
- Test: exact focused tests listed in the same matrix
- Modify: `docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md`

- [ ] **Step 1: Split the completed matrix into bounded implementation batches**

Create batches for: Home/Settings; Action Center/corrections; Operate; Oversee; Reports/exports; Overview/local pages/details; denied sensitive actions. Every batch must contain exact implementation files and focused test files before code changes begin.

- [ ] **Step 2: Write failing focused tests for the first batch**

For each material row, prove event name/result, ShopOwner causer, shop, target, safe properties, correlation ID, same-transaction success, rollback absence, and cross-tenant denial. Prove rows marked `N/A_ROUTINE_VIEW` create no operation event.

- [ ] **Step 3: Run the exact tests and verify failure**

Use the focused-test commands recorded in the audit matrix. Do not run a broad suite in place of proving the new failure.

- [ ] **Step 4: Instrument the existing domain transaction**

Call `OwnerOperationAudit` from the existing service/controller transaction named by the matrix. Do not wrap an unrelated outer transaction, duplicate domain mutations, or log success before commit. Denied sensitive attempts use a separate safe failure event.

- [ ] **Step 5: Run the focused tests and update the matrix verdict**

Mark a row `VERIFIED` only after its exact test passes. Repeat Steps 2-5 one batch at a time until all material rows are verified or explicitly blocked.

- [ ] **Step 6: Commit each bounded audit batch safely**

```powershell
git status --short
git add -- <exact-implementation-files-and-tests-from-current-audit-matrix-batch> docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md
git diff --cached --name-status
git diff --cached --check
git commit -m "feat: audit <canonical-owner-surface> operations"
```

### Task 12: Migrate links and add safe GET compatibility redirects

**Files:**
- Modify: `routes/web.php`
- Modify: `routes/shop-owner-erp.php`
- Modify: `routes/shop-owner-shell.php`
- Modify: `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php`
- Modify notification classes/services identified in Task 1.
- Modify frontend callers and tests identified in Task 1.
- Test: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php`
- Test: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php`

- [ ] **Step 1: Write failing redirect/context tests**

For each approved GET alias, assert one hop to the canonical route, preservation of valid record/filter context, rejection of unsafe return URLs, no loop, and no cross-audience redirect.

- [ ] **Step 2: Migrate repository callers first**

Update route helpers, hard-coded URLs, notification action URLs, frontend tests, and docs to canonical destinations. Do not edit generated `resources/js/ziggy.js` manually; regenerate it only with the repository's existing command if required.

- [ ] **Step 3: Convert approved duplicate GETs to thin redirects**

Keep mutation routes unchanged. A GET compatibility route delegates no business logic.

- [ ] **Step 4: Re-run caller analysis**

```powershell
codegraph explore "retiring Phase 5 route names and callers"
rg -n "<each retiring route name or URI>" app resources/js routes tests docs --glob '!resources/js/ziggy.js'
```

Expected: only compatibility declarations and explicit compatibility tests remain.

- [ ] **Step 5: Run tests and commit**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php tests/Feature/Notifications
```

Stage and commit each caller batch with the exact files listed in the completed capability-retirement matrix; never use a broad directory add. The final route batch is:

```powershell
git add -- routes/web.php routes/shop-owner-erp.php routes/shop-owner-shell.php app/Http/Controllers/ShopOwner/OwnerActionCenterController.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php docs/architecture/shop-owner-phase-5-capability-retirement-matrix.md
git commit -m "refactor: migrate canonical owner links"
```

### Task 13: Retire duplicate mutations and the owner ERP Workspace

**Files:**
- Modify: `routes/shop-owner-erp.php`
- Modify: `routes/shop-owner-erp-api.php`
- Modify: `routes/shop-owner-shell.php`
- Modify: `app/Http/Middleware/EnsureOwnerErpWorkspaceEnabled.php`
- Modify: `app/Services/OwnerShell/CanonicalOwnerShellService.php`
- Modify: `resources/js/layout/CanonicalOwnerSidebar.tsx`
- Delete only after proof: `resources/js/Pages/ERP/Workspace.tsx`
- Delete only after proof: `resources/js/Pages/ERP/__tests__/Workspace.test.tsx`
- Modify: `config/shop_modules.php`
- Test: canonical shell, route matrix, and rollback tests.

- [ ] **Step 1: Confirm every retirement row is PASS and run automated parity checks**

No route is removed while its matrix verdict is compatibility-only, unknown, or blocked. Confirm no documented external integration uses it. Run:

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerCapabilityParityTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
pnpm exec vitest run resources/js/Pages/ERP/__tests__/Workspace.test.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx
```

Expected: PASS before any Workspace file or feature boundary is removed.

- [ ] **Step 2: Perform and record pre-retirement developer browser parity QA**

With the compatibility Workspace still available, compare every enabled module's Workspace capabilities against its canonical Overview, local pages, reads, actions, exports/downloads, and denials. Record each result in the capability-retirement matrix. Do not proceed until every retirement row has automated and browser evidence.

- [ ] **Step 3: Write failing retirement tests**

Assert the canonical sidebar has no ERP Workspace fallback, the old Workspace GET safely redirects to Home or the matching canonical Overview, disabled modules still resolve through Settings, and removed mutation aliases are absent from `route:list`.

- [ ] **Step 4: Remove duplicate mutation aliases only after zero-caller evidence**

Do not redirect POST/PATCH/DELETE methods. Remove the alias registration and catalog entry together; retain the authoritative mutation route.

- [ ] **Step 5: Retire the owner-facing Workspace picker**

Keep a safe GET redirect for `/shop-owner/erp/workspace` during the development deprecation checkpoint. Remove `owner_erp_workspace_enabled`, fallback presentation metadata, picker-only controller payload, and Workspace page only after canonical capability parity tests pass.

- [ ] **Step 6: Verify rollback remains presentation-only**

Rollback may restore a compatibility GET or prior shell presentation; it must not reverse maker fields, audit rows, or completed domain transitions.

- [ ] **Step 7: Run tests, inspect the staged diff, and commit**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerCapabilityParityTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRollbackTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php
pnpm exec vitest run resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx
rg -n "ERP Workspace|shop-owner\.erp\.workspace|/shop-owner/erp/workspace|Pages/ERP/Workspace" app resources/js routes tests config docs --glob '!resources/js/ziggy.js'
git add -- routes/shop-owner-erp.php routes/shop-owner-erp-api.php routes/shop-owner-shell.php app/Http/Middleware/EnsureOwnerErpWorkspaceEnabled.php app/Services/OwnerShell/CanonicalOwnerShellService.php resources/js/layout/CanonicalOwnerSidebar.tsx resources/js/Pages/ERP/Workspace.tsx resources/js/Pages/ERP/__tests__/Workspace.test.tsx config/shop_modules.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerCapabilityParityTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRollbackTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx docs/architecture/shop-owner-phase-5-capability-retirement-matrix.md
git diff --cached --name-status
git diff --cached --check
git commit -m "refactor: retire owner erp workspace"
```

Expected stale-reference search results: only the intentional compatibility GET redirect, its explicit contract tests, and historical/design documentation. Any runtime reference to the deleted page or retired mutation aliases blocks the commit.

### Task 14: Complete developer QA, documentation, and final review gates

**Files:**
- Create: `docs/shop-owner-phase-5-rollout-guide.md`
- Modify: `docs/architecture/shop-owner-phase-5-capability-retirement-matrix.md`
- Modify: `docs/architecture/shop-owner-phase-5-maker-checker-matrix.md`
- Modify: `docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md`
- Modify: `docs/ai-learning-log.md` only if a durable lesson emerged.

- [ ] **Step 1: Run narrow suites for changed domains**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell tests/Feature/ShopOwner/ActionCenter tests/Feature/BusinessScaling/ErpActorContextTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php
```

Expected: PASS, with `N/A_NO_OWNER_INITIATION` families documented rather than receiving synthetic tests or fields.

- [ ] **Step 2: Run frontend and build gates**

```powershell
pnpm run test:frontend
pnpm run build
```

Expected: PASS. Do not stage generated build artifacts unless the repository explicitly tracks the refreshed manifest/assets for this branch.

- [ ] **Step 3: Run broader backend and diff gates**

```powershell
composer test
git diff --check
```

Expected: PASS, or document unrelated pre-existing failures with exact command output.

- [ ] **Step 4: Perform developer browser QA**

Verify with approved company-owner fixtures:

```text
Home -> each eligible module Overview -> local tabs -> parent-page create action
Action Center -> Needs My Decision -> Waiting on Others -> Needs Correction
Header/account menu -> Settings -> Modules & Team
Reports -> Audit -> owner mutation evidence
Old GET link -> one canonical redirect
Owner-created ON request -> blocked
Owner-created OFF request -> proven downstream authority
Simultaneous owner + employee sessions -> canonical owner actor and tenant
Disabled/ineligible/cross-shop direct URL -> denied
```

- [ ] **Step 5: Run the required sequential review stack**

Record: simplify, Standards review, Spec review, TypeScript/React review, code-splitting applicability, measured improvements or `not measured`, security review, reuse audit, dead-code scan, and verification-before-completion evidence.

- [ ] **Step 6: Complete rollout guide and matrices**

Document exact retained redirects, removed aliases, no-production-wait rationale, browser evidence, rollback steps, and unresolved `N/A`/blocked rows. Do not claim telemetry or a release cycle.

- [ ] **Step 7: Commit final evidence**

```powershell
git add -- docs/shop-owner-phase-5-rollout-guide.md docs/architecture/shop-owner-phase-5-capability-retirement-matrix.md docs/architecture/shop-owner-phase-5-maker-checker-matrix.md docs/architecture/shop-owner-phase-5-owner-operation-audit-matrix.md
git status --short docs/ai-learning-log.md
# Only if this task added a durable learning entry:
git add -- docs/ai-learning-log.md
git diff --cached --name-status
git diff --cached --check
git commit -m "docs: complete phase 5 consolidation evidence"
```
