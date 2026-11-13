# Shop Owner ERP Module-Scoped Navigation Implementation Plan

> **For implementation:** execute these tasks sequentially with test-driven development. Do not begin production-code work until this plan is approved.

**Goal:** Make the Shop Owner ERP Workspace a real module boundary. The Workspace remains the picker; selecting a module opens a stable module URL inside the ERP shell, and the sidebar shows only that module's owner-capable pages.

**Approved design:** [Shop Owner ERP Module-Scoped Navigation Design](../specs/2026-08-10-shop-owner-erp-module-scoped-navigation-design.md)

**Implementation branch:** `feature/business-scaling-module-access`

**Repository constraints:** preserve the unrelated dirty documentation files in the current worktree; use the existing `ShopModuleAccessService`, `ErpActorContext`, route catalog, controllers, policies, and UI patterns; do not edit generated `public/build` files by hand; keep employee ERP behavior unchanged.

## Acceptance criteria

- The normal Shop Owner sidebar keeps the core pages and ERP Workspace, but does not render Employee Modules or the standalone Logistics accordion.
- The no-active-module Workspace picker may show the core pages, but never shows Employee Modules or standalone Logistics.
- Workspace cards link to readable URLs such as `/shop-owner/erp/retail` and `/shop-owner/erp/logistics`.
- A selected module renders a landing page inside `AppLayoutERP`.
- A selected-module sidebar contains ERP Workspace plus only pages whose server-classified module matches the active module. It contains no core pages, approval groups, other modules, employee self-service pages, or portal-only fallbacks.
- Refreshing or directly opening a valid module page preserves the active module scope.
- Unknown, disabled, ineligible, malformed, and cross-tenant module access fails closed through the existing ERP authorization boundary.
- Retail and every other exposed module link to owner ERP routes and do not switch back to the normal Shop Owner shell.
- At least one existing owner-capable operation/page flow is exercised for every exposed module using its existing guard, tenant context, validation, policy, workflow, audit, and persistence behavior.
- Employee ERP navigation and permissions remain unchanged.

## Server contract and navigation shape

Use one server-owned module catalog/resolver, extracted from the existing `WorkspaceController::MODULE_ENTRY_ROUTES` mapping rather than adding separate client authorization logic. It must provide:

```ts
type OwnerErpModule = {
  key: string;
  slug: string;
  label: string;
  description: string;
};

type OwnerErpPage = {
  label: string;
  routeName: string;
  url: string;
};
```

The shared Inertia state should distinguish these two owner states:

- picker: `activeModule: null`, core-only owner navigation plus ERP Workspace;
- module scope: `activeModule` populated, ERP Workspace plus the selected module's `pages` only.

The active module is derived from the authenticated request and route catalog. A client-supplied slug or shop identifier is never an authorization input.

## Implementation tasks

### 1. Lock the route and module contract with backend tests

Files:

- Modify `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`.
- Modify or create the focused Workspace/module route tests in `tests/Feature/BusinessScaling/`.
- Modify `app/Http/Controllers/Erp/WorkspaceController.php` only after tests are red.
- Modify `routes/shop-owner-erp.php` only after the expected route contract is captured.

Steps:

- [ ] Add failing tests asserting that each accessible Workspace card returns a readable module URL, not the current single-page portal or old dashboard target.
- [ ] Add failing tests for `/shop-owner/erp/{module}` returning `ERP/ModuleLanding` with `activeModule`, canonical page links, and the back-to-Workspace URL.
- [ ] Add failing tests that invalid slugs, disabled modules, ineligible modules, and an attempted `shop_owner_id` override are rejected or safely denied according to the existing ERP responder contract.
- [ ] Register the static `/workspace` route before the module route and constrain the dynamic segment to known readable slugs.
- [ ] Resolve the slug to the existing module key and call `ShopModuleAccessService` for eligibility/accessibility. Keep `ErpActorContext::tenantOwner()` as the tenant authority.
- [ ] Move conflicting module dashboard roots behind module-specific page paths where necessary (for example, make `/erp/crm` and `/erp/logistics` the landing pages and use `/erp/crm/dashboard` and `/erp/logistics/dashboard` for dashboards). Preserve existing named portal URLs; retain ERP aliases or redirects only where they do not conflict with the approved landing URL.
- [ ] Return a single server-owned module payload and use it for Workspace cards, the landing page, and the sidebar. Do not duplicate module access decisions in React.
- [ ] Run the focused backend tests and confirm they pass before continuing.

Verification:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php
```

### 2. Preserve owner operation boundaries while creating module page routes

Files:

- Modify `routes/shop-owner-erp.php` and related owner ERP route declarations.
- Reuse or narrowly wrap existing controllers/components under `app/Http/Controllers/Erp`, `app/Http/Controllers/Api`, `app/Http/Controllers/Logistics`, and `app/Http/Controllers/ShopOwner`.
- Modify `app/Http/Middleware/HandleInertiaRequests.php` or add the smallest focused resolver needed to share active-module state on existing owner ERP page requests.
- Update the relevant route-catalog entries in `config/shop_modules.php` and `app/Services/ErpRouteCatalog.php` only when the new owner ERP route names/paths require it.
- Extend `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`; add a separate operation contract test only if the existing file becomes unclear.

Steps:

- [ ] Write failing tests that direct visits to representative pages receive the correct `activeModule` and module navigation, not the global owner sidebar.
- [ ] Write failing operation-flow tests from the existing route matrix. Choose at least one already owner-capable, tenant-scoped operation/page per exposed module; assert the `shop_owner` guard, tenant owner, existing policy/workflow result, and persisted/action result where the route is mutating.
- [ ] For Retail, replace the current `shop-owner.products` Workspace target with an owner ERP counterpart or actor-aware wrapper so Product Management remains inside `AppLayoutERP`.
- [ ] For CRM, Finance, HR, Inventory, Procurement, Repair, and Logistics, expose only routes already classified as owner-allowed. Do not expose employee self-service routes or invent mutations solely to populate the sidebar.
- [ ] Attach the module key to existing owner ERP page requests through the server route catalog/resolver so direct links and refreshes remain scoped.
- [ ] Keep all existing Form Requests, policies, maker/checker controls, payment controls, upload limits, audit writes, and tenant queries intact. Client module filtering must not replace route authorization.
- [ ] Verify cross-shop identifiers cannot change the resolved tenant and that an unauthorized page/action remains denied even when its URL is guessed.
- [ ] Run the focused backend contract and operation tests.

Verification:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php tests/Feature/BusinessScaling/OwnerErpApiContractTest.php
```

### 3. Implement the two owner sidebar states without changing employees

Files:

- Modify `resources/js/layout/AppSidebar_ERP.tsx`.
- Modify `resources/js/layout/AppSidebar_shopOwner.tsx`.
- Reuse the existing `NavItem` metadata and module keys; add only the minimum owner-ERP route/page metadata needed for links that cannot reuse an existing owner route.
- Modify `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`.
- Modify `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`.

Steps:

- [ ] Add failing component tests for the picker state: core pages and ERP Workspace may render; Employee Modules and standalone Logistics must not render.
- [ ] Add failing component tests for Retail, Finance, CRM, Inventory, Procurement, Repair, HR, and Logistics scopes: each renders ERP Workspace and only matching module pages; each hides core, approval, other-module, and employee self-service items.
- [ ] Add a failing test that the selected module's active link remains active after direct URL refresh and nested page navigation.
- [ ] Change owner mode in `AppSidebar_ERP` to consume the server-provided active module/navigation state. It must no longer delegate unconditionally to the full portal sidebar.
- [ ] Keep the owner picker state core-only, and remove Employee Modules and standalone Logistics from normal portal rendering for all Shop Owners, including Individual accounts.
- [ ] Filter nested items by the active module key and render only server-approved owner ERP URLs. Hide unclassified items rather than falling back to portal URLs.
- [ ] Keep the employee branch, permission checks, submenu behavior, responsive collapse behavior, active-state matching, and accessibility semantics unchanged.
- [ ] Run the focused Vitest files and confirm the old test that expected enabled Employee Modules is replaced by the new removal contract.

Verification:

```powershell
pnpm run test:frontend -- resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx
```

### 4. Add the module landing page and update the Workspace UI

Files:

- Create `resources/js/Pages/ERP/ModuleLanding.tsx`.
- Create `resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx`.
- Modify `resources/js/Pages/ERP/Workspace.tsx`.
- Modify `resources/js/Pages/ERP/__tests__/Workspace.test.tsx`.
- Modify shared ERP types only if the repository has an existing type location for Inertia page props.

Steps:

- [ ] Add failing tests for the landing title, description, page links, active module information, and return-to-Workspace link.
- [ ] Add failing Workspace tests for readable module URLs and disabled/unavailable module behavior.
- [ ] Implement the landing page inside `AppLayoutERP`; keep it lightweight and reuse the existing card, link, typography, dark-mode, and focus-state patterns.
- [ ] Render page links from server-provided URLs. Do not reconstruct route URLs or authorization state in the client.
- [ ] Keep the Workspace as the module picker and avoid adding module-specific domain logic to it.
- [ ] Run the focused frontend tests and the full frontend suite after the page contract stabilizes.

Verification:

```powershell
pnpm run test:frontend -- resources/js/Pages/ERP/Workspace.test.tsx resources/js/Pages/ERP/__tests__/ModuleLanding.test.tsx
```

### 5. Verify the end-to-end owner flow and review the diff

Steps:

- [ ] Run the full relevant BusinessScaling backend suite, including route coverage and existing actor-boundary tests.
- [ ] Run the full frontend suite and a fresh production build.
- [ ] Use browser verification with an approved company Shop Owner:

  ```text
  Shop Owner Dashboard
  → ERP Workspace
  → Retail Operations
  → Retail landing page and Retail-only sidebar
  → complete the selected representative Retail operation
  → return to ERP Workspace
  → Logistics
  → Logistics landing page and Logistics-only sidebar
  → complete the selected representative Logistics operation/page flow
  ```

- [ ] Repeat a direct URL refresh, browser Back navigation, disabled-module attempt, and cross-shop query-string attempt.
- [ ] Confirm the normal Shop Owner sidebar contains neither Employee Modules nor standalone Logistics.
- [ ] Run the required sequential review gates: simplify avoidable complexity, Standards/Spec review, TypeScript/React review, security/authorization review, dead-code/reuse scan, and verification-before-completion.
- [ ] Run the final quality commands and record exact results:

```powershell
git diff --check
php artisan test tests/Feature/BusinessScaling
pnpm run test:frontend
pnpm run build
```

- [ ] Review generated build changes separately and do not hand-edit `public/build`.

## Completion evidence

The implementation is complete only when the approved spec's module-picker, scoped-sidebar, route, authorization, tenant-isolation, owner-operation, employee-regression, and browser-flow criteria all have fresh test or browser evidence. Report any unmeasured performance/bundle baseline as `not measured`; do not claim it passed without running the relevant command.
