# Shop Owner Phase 2 Canonical Adaptive Shell Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a controlled-rollout, server-composed canonical Shop Owner shell with stable capability URLs, adaptive individual/company emphasis, existing-page reuse, and a reversible ERP workspace fallback without changing authorization or domain behavior.

**Architecture:** Add a small rollout policy and canonical shell adapter that compose presentation metadata from registration type, `ShopModuleAccessService`, `ErpRouteCatalog`, and `ErpWorkspaceNavigationService`. Share one validated presentation contract through Inertia, register canonical routes independently of the ERP workspace feature gate, and let both existing owner and owner-mode ERP pages select the canonical frame from trusted server metadata. Existing controllers, APIs, middleware, module decisions, and domain routes remain authoritative.

**Tech Stack:** PHP 8.2, Laravel 12, PHPUnit, Inertia 2, React 18, TypeScript 5.7, Vitest, Tailwind CSS 4, pnpm, Playwright/browser smoke tooling.

---

## Source specifications

- `docs/superpowers/specs/2026-08-15-shop-owner-phase-2-canonical-adaptive-shell-foundation-design.md`
- `docs/superpowers/specs/2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md`
- `docs/superpowers/specs/2026-08-15-shop-owner-phase-1-state-responsibility-correctness-design.md`

The focused Phase 2 specification is authoritative. Phase 1 state and responsibility behavior is a prerequisite and must not be altered by this work.

## Required implementation skills

- Use `@superpowers:test-driven-development` for each implementation task.
- Use `@laravel-best-practices` for config, routes, controllers, Inertia sharing, authorization parity, and tests.
- Use `@ui-ux-pro-max`, `@design-system`, and `@ui-styling` for the navigation interaction, responsive shell, and accessibility behavior.
- Use `@vercel-react-best-practices` for changed React/TSX and `@webapp-testing` for browser-visible verification.
- Use `@ponytail` and `@karpathy-guidelines` during the sequential simplification/review gate.
- Use `@security-review` because canonical routes, auth contexts, fallback telemetry, and tenant-scoped capabilities are security-sensitive.
- Use `@superpowers:verification-before-completion` before any completion claim.

## Non-negotiable boundaries

- Do not create a second permission system, generic feature-flag framework, generic navigation engine, or new dependency.
- `SHOP_OWNER_CANONICAL_SHELL_ENABLED` is the global kill switch; it and the allowlist select presentation only.
- `SHOP_OWNER_ERP_WORKSPACE_ENABLED` controls only the existing ERP workspace and its compatibility link; it never gates a canonical capability.
- Use the Shop Owner primary key as the stable shop identity unless the model gains a separate immutable shop identifier before implementation. Never key rollout to email or mutable profile values.
- Compose and validate shell metadata on the server before the Inertia response is committed. React renders one complete selected presentation and does not perform safety fallback.
- Canonical routes remain registered and usable while the canonical-shell flag is off.
- Canonical routes re-run the same module, audience, tenant, capability, validation, and binding checks as their compatibility implementations.
- Do not place `EnsureOwnerErpWorkspaceEnabled` on canonical routes.
- Do not change registration-type or module eligibility to make the navigation look fuller. Ineligible items are omitted.
- Do not implement approval/exception counts, `OwnerAttentionItem`, notification aggregation, or Action Center queries.
- Do not retire or broadly redirect `/shop-owner/*` or `/shop-owner/erp/*` routes.
- Preserve unrelated working-tree changes and stage only files named by the current task.

## Phase 2 shell-destination inventory

This is the implementation inventory, not a promise that every item appears for every owner.

| Destination key | Canonical route name | Canonical URL | Authoritative implementation/decision |
|---|---|---|---|
| `home` | `shop-owner.shell.home` | `/shop-owner/home` | Existing `ShopOwner/Dashboard` page and dashboard API |
| `retail` | `shop-owner.shell.operate.retail` | `/shop-owner/operate/retail` | `WorkspaceController::module`, slug `retail`, module `retail_operations` |
| `repair` | `shop-owner.shell.operate.repair` | `/shop-owner/operate/repair` | `WorkspaceController::module`, slug `repair`, module `repair_operations` |
| `customers` | `shop-owner.shell.operate.customers` | `/shop-owner/operate/customers` | `WorkspaceController::module`, slug `crm`, module `crm` |
| `payments` | `shop-owner.shell.operate.payments` | `/shop-owner/operate/payments` | Thin direct-payment landing; links only to currently authorized Retail/Repair POS routes |
| `finance` | `shop-owner.shell.oversee.finance` | `/shop-owner/oversee/finance` | `WorkspaceController::module`, slug `finance`, module `finance` |
| `workforce` | `shop-owner.shell.oversee.workforce` | `/shop-owner/oversee/workforce` | `WorkspaceController::module`, slug `hr`, module `hr_employees` |
| `inventory` | `shop-owner.shell.oversee.inventory` | `/shop-owner/oversee/inventory` | `WorkspaceController::module`, slug `inventory`, module `inventory` |
| `procurement` | `shop-owner.shell.oversee.procurement` | `/shop-owner/oversee/procurement` | `WorkspaceController::module`, slug `procurement`, module `procurement` |
| `logistics` | `shop-owner.shell.oversee.logistics` | `/shop-owner/oversee/logistics` | `WorkspaceController::module`, slug `logistics`, module `logistics` |
| `reports` | `shop-owner.shell.reports` | `/shop-owner/reports` | Existing `ReadPageController::managerReports` |
| `audit` | `shop-owner.shell.audit` | `/shop-owner/audit` | Existing `ReadPageController::managerAuditLogs` |
| `settings.profile` | `shop-owner.shell.settings.profile` | `/shop-owner/settings/profile` | Existing `ShopSettingsController::index` and `ShopOwner/Settings/shopSetting`, section `profile` |
| `settings.modules-team` | `shop-owner.shell.settings.modules-team` | `/shop-owner/settings/modules-team` | Same settings implementation, section `modules-team` |
| `settings.payments-approvals` | `shop-owner.shell.settings.payments-approvals` | `/shop-owner/settings/payments-approvals` | Same settings implementation, section `payments-approvals` |
| `settings.operations` | `shop-owner.shell.settings.operations` | `/shop-owner/settings/operations` | Same settings implementation, section `operations` |
| `settings.policies-compliance` | `shop-owner.shell.settings.policies-compliance` | `/shop-owner/settings/policies-compliance` | Same settings implementation, section `policies-compliance` |
| `settings.subscription` | `shop-owner.shell.settings.subscription` | `/shop-owner/settings/subscription` | Same settings implementation, section `subscription` |

Important inventory consequences:

- The current catalog makes `crm`, `finance`, `hr_employees`, `inventory`, `procurement`, and `logistics` company-only. Phase 2 must not make those modules available to an individual owner merely because the target IA illustrates them “if applicable.”
- The payments landing may appear when at least one authoritative Retail or Repair operational module is accessible. It must not expose a link whose source route is denied.
- Reports and Audit remain distinct canonical destinations even if both reuse manager-backed read pages.
- Nested product, order, repair, refund, customer, employee, purchase-order, shipment, approval, and mutation URLs remain compatibility/domain routes in Phase 2.

## Controlled rollout checkpoints

1. Merge and deploy canonical routes with the global shell flag off.
2. Verify canonical bookmarks, route parity, and complete existing-presentation framing.
3. Enable the global flag with an empty allowlist; behavior must remain unchanged.
4. Add internal/test shop IDs only; verify individual and company contexts plus kill-switch rollback.
5. Expand the allowlist only after per-capability parity and fallback evidence is recorded.
6. Keep the ERP fallback until Phase 5 retirement criteria are met.

## Task 1: Characterize the existing owner and ERP presentation boundaries

**Files:**

- Create: `tests/Feature/ShopOwner/CanonicalShell/ExistingPresentationCharacterizationTest.php`
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Reference: `routes/web.php`
- Reference: `routes/shop-owner-erp.php`
- Reference: `resources/js/layout/AppLayout_shopOwner.tsx`
- Reference: `resources/js/layout/AppLayout_ERP.tsx`

- [ ] **Step 1: Write backend characterization tests**

Lock these current facts before refactoring:

```php
public function test_existing_dashboard_and_erp_workspace_keep_their_current_components(): void
{
    $owner = ShopOwner::factory()->approved()->create([
        'registration_type' => 'company',
        'business_type' => 'both',
    ]);

    $this->actingAs($owner, 'shop_owner')
        ->get(route('shop-owner.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('ShopOwner/Dashboard', false));

    config([
        'shop_modules.owner_erp_workspace_enabled' => true,
        'shop_modules.enforcement_enabled' => true,
    ]);

    $this->actingAs($owner, 'shop_owner')
        ->get(route('shop-owner.erp.workspace'))
        ->assertInertia(fn (Assert $page) => $page->component('ERP/Workspace', false));
}
```

Also record route names, middleware order, and components for one operational module, Reports, Audit, and Settings. Assert the ERP workspace returns 404 when its existing flag is off; that behavior belongs only to the compatibility workspace.

- [ ] **Step 2: Run the backend characterization test**

Run:

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/ExistingPresentationCharacterizationTest.php
```

Expected: PASS against current behavior. If a genuinely defective existing behavior is found, document it in the test and move the intended assertion to the task that fixes it.

- [ ] **Step 3: Extend frontend characterization coverage**

Assert that the current Shop Owner sidebar and ERP sidebar still render their existing primary entries when no `ownerShell` metadata is provided. This protects non-cohort and employee ERP behavior while the new frame is added.

- [ ] **Step 4: Run the focused frontend tests**

Run:

```powershell
pnpm run test:frontend -- resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: PASS.

- [ ] **Step 5: Commit the baseline**

```powershell
git add -- tests/Feature/ShopOwner/CanonicalShell/ExistingPresentationCharacterizationTest.php resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
git commit -m "test: characterize shop owner shell boundaries"
```

## Task 2: Add the presentation-only rollout policy

**Files:**

- Create: `config/owner_shell.php`
- Create: `app/Enums/OwnerShellPresentation.php`
- Create: `app/Enums/OwnerShellSelectionReason.php`
- Create: `app/Support/OwnerShell/OwnerShellSelection.php`
- Create: `app/Services/OwnerShell/OwnerShellRolloutPolicy.php`
- Create: `tests/Unit/Services/OwnerShell/OwnerShellRolloutPolicyTest.php`

- [ ] **Step 1: Write failing rollout-policy tests**

Cover this complete matrix:

```text
flag off                              -> existing / global_disabled
flag on + owner ID absent             -> existing / shop_not_allowlisted
flag on + owner ID allowlisted        -> canonical candidate / shop_allowlisted
flag on + invalid registration type   -> existing / invalid_registration_context
config/allowlist evaluation throws    -> existing / cohort_evaluation_failed
```

Prove that changing `shop_modules.owner_erp_workspace_enabled` does not change the rollout result.

- [ ] **Step 2: Run the unit test to verify RED**

Run:

```powershell
php artisan test tests/Unit/Services/OwnerShell/OwnerShellRolloutPolicyTest.php
```

Expected: FAIL because the config, enums, DTO, and policy do not exist.

- [ ] **Step 3: Add bounded configuration**

Use one focused config file:

```php
<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('SHOP_OWNER_CANONICAL_SHELL_ENABLED', false),
    'allowlisted_shop_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('SHOP_OWNER_CANONICAL_SHELL_SHOP_IDS', '')),
    ), static fn (int $id): bool => $id > 0)),
];
```

Do not add a database flag, percentage rollout, self-opt-in, generic feature service, or `.env` edits.

- [ ] **Step 4: Implement stable enums and immutable selection**

```php
enum OwnerShellSelectionReason: string
{
    case GlobalDisabled = 'global_disabled';
    case ShopNotAllowlisted = 'shop_not_allowlisted';
    case ShopAllowlisted = 'shop_allowlisted';
    case InvalidRegistrationContext = 'invalid_registration_context';
    case CohortEvaluationFailed = 'cohort_evaluation_failed';
    case ShellCompositionFailed = 'shell_composition_failed';
}
```

`OwnerShellSelection` contains only `presentation`, `reason`, and `context`. Context is `individual|company|null`; do not create an owner-selectable mode.

- [ ] **Step 5: Implement the fail-safe policy**

Keep it deterministic and side-effect free. Catch only configuration/evaluation failures at this boundary, call `report($exception)`, and return the complete existing selection. Do not catch authorization denials.

- [ ] **Step 6: Re-run the policy tests**

Run:

```powershell
php artisan test tests/Unit/Services/OwnerShell/OwnerShellRolloutPolicyTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit the rollout boundary**

```powershell
git add -- config/owner_shell.php app/Enums/OwnerShellPresentation.php app/Enums/OwnerShellSelectionReason.php app/Support/OwnerShell/OwnerShellSelection.php app/Services/OwnerShell/OwnerShellRolloutPolicy.php tests/Unit/Services/OwnerShell/OwnerShellRolloutPolicyTest.php
git commit -m "feat: add canonical owner shell rollout policy"
```

## Task 3: Define and validate the canonical shell metadata contract

**Files:**

- Create: `app/Support/OwnerShell/OwnerShellMetadata.php`
- Create: `app/Support/OwnerShell/OwnerShellGroup.php`
- Create: `app/Support/OwnerShell/OwnerShellItem.php`
- Create: `resources/js/types/ownerShell.ts`
- Create: `tests/Unit/Support/OwnerShell/OwnerShellMetadataTest.php`

- [ ] **Step 1: Write failing contract tests**

Test construction and serialization rules:

- existing presentation has `context: null`, no canonical groups, and no ERP fallback;
- canonical presentation requires `individual|company` context;
- keys, reasons, URLs, and active-match patterns are bounded strings;
- empty groups are rejected/removed server-side;
- unavailable items require a stable reason and canonical management destination;
- malformed canonical metadata cannot be serialized as canonical.

- [ ] **Step 2: Run the contract test to verify RED**

```powershell
php artisan test tests/Unit/Support/OwnerShell/OwnerShellMetadataTest.php
```

Expected: FAIL because the metadata values do not exist.

- [ ] **Step 3: Implement small immutable values**

The serialized shape must be exactly:

```php
[
    'presentation' => 'canonical|existing',
    'selection_reason' => 'stable_enum_value',
    'context' => 'individual|company|null',
    'groups' => [[
        'key' => 'operate',
        'label' => 'Operate',
        'order' => 10,
        'default_expanded' => true,
        'items' => [[
            'key' => 'retail',
            'label' => 'Retail',
            'canonical_url' => '/shop-owner/operate/retail',
            'available' => true,
            'unavailable_reason' => null,
            'management_url' => null,
            'active_matching' => ['/shop-owner/operate/retail', '/shop-owner/erp/retail*'],
        ]],
    ]],
    'compatibility' => [
        'show_erp_fallback' => false,
        'erp_workspace_url' => null,
        'fallback_url' => null,
    ],
];
```

Use ordinary final PHP classes with constructor validation and `toArray()`. Do not add a schema library.

- [ ] **Step 4: Add matching TypeScript types**

Use string unions for the bounded contract and no `any`:

```ts
export type OwnerShellPresentation = "canonical" | "existing";
export type OwnerShellContext = "individual" | "company" | null;

export interface OwnerShellMetadata {
  presentation: OwnerShellPresentation;
  selection_reason: OwnerShellSelectionReason;
  context: OwnerShellContext;
  groups: OwnerShellGroup[];
  compatibility: OwnerShellCompatibility;
}
```

- [ ] **Step 5: Re-run the contract test**

```powershell
php artisan test tests/Unit/Support/OwnerShell/OwnerShellMetadataTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the shared contract**

```powershell
git add -- app/Support/OwnerShell/OwnerShellMetadata.php app/Support/OwnerShell/OwnerShellGroup.php app/Support/OwnerShell/OwnerShellItem.php resources/js/types/ownerShell.ts tests/Unit/Support/OwnerShell/OwnerShellMetadataTest.php
git commit -m "feat: define canonical owner shell metadata"
```

## Task 4: Compose adaptive navigation from authoritative sources

**Files:**

- Create: `app/Services/OwnerShell/CanonicalOwnerShellService.php`
- Create: `tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php`
- Reference: `app/Services/ShopModuleAccessService.php`
- Reference: `app/Services/ErpRouteCatalog.php`
- Reference: `app/Services/ErpWorkspaceNavigationService.php`
- Reference: `config/shop_modules.php`

- [ ] **Step 1: Write failing composition tests**

Use factories and real module decisions to cover:

- individual Retail, Repair, and `both` owners: `Operate` first and expanded;
- company Retail, Repair, and `both` owners: `Oversee` first and expanded;
- company owners retain accessible direct operations;
- inaccessible modules are omitted;
- eligible disabled owner-manageable modules may be unavailable with `shop-owner.shell.settings.modules-team` as management destination;
- inaccessible items with no useful owner action are omitted;
- empty groups are absent;
- Reports and Audit are separate items;
- settings is one group with six canonical section links;
- payments appears only when at least one Retail/Repair operational path is accessible;
- canonical destination eligibility does not change when the ERP workspace flag changes;
- fallback visibility requires both canonical selection and existing ERP workspace eligibility.

- [ ] **Step 2: Add a query-bound assertion**

Count `shop_owner_modules` queries while composing the full shell. Expect one module-state load, not one query per item. Also assert no queries touch approval, refund, notification, repair, order, payroll, or exception tables.

- [ ] **Step 3: Run the service test to verify RED**

```powershell
php artisan test tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php
```

Expected: FAIL because the service does not exist.

- [ ] **Step 4: Implement the static destination mapping inside the focused service**

Keep the mapping private and Phase-2-specific:

```php
private const MODULE_DESTINATIONS = [
    'retail' => ['group' => 'operate', 'module' => 'retail_operations', 'route' => 'shop-owner.shell.operate.retail'],
    'repair' => ['group' => 'operate', 'module' => 'repair_operations', 'route' => 'shop-owner.shell.operate.repair'],
    'customers' => ['group' => 'operate', 'module' => 'crm', 'route' => 'shop-owner.shell.operate.customers'],
    'finance' => ['group' => 'oversee', 'module' => 'finance', 'route' => 'shop-owner.shell.oversee.finance'],
    'workforce' => ['group' => 'oversee', 'module' => 'hr_employees', 'route' => 'shop-owner.shell.oversee.workforce'],
    'inventory' => ['group' => 'oversee', 'module' => 'inventory', 'route' => 'shop-owner.shell.oversee.inventory'],
    'procurement' => ['group' => 'oversee', 'module' => 'procurement', 'route' => 'shop-owner.shell.oversee.procurement'],
    'logistics' => ['group' => 'oversee', 'module' => 'logistics', 'route' => 'shop-owner.shell.oversee.logistics'],
];
```

Read module states once with `statesFor($owner)`. Reference `ErpRouteCatalog`/`ErpWorkspaceNavigationService` to validate that each mapped module and compatibility active-match source exists; do not copy their page lists.

- [ ] **Step 5: Implement complete-presentation failure handling**

`forOwner(ShopOwner $owner): OwnerShellMetadata` must:

1. ask `OwnerShellRolloutPolicy` for selection;
2. return existing metadata immediately unless canonical is selected;
3. compose and construct valid canonical metadata;
4. on composition/contract failure, `report($exception)`, log stable `shop_id` and `shell_composition_failed`, and return complete existing metadata.

Do not return a partial group list.

- [ ] **Step 6: Re-run service tests**

```powershell
php artisan test tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php
```

Expected: PASS, including the bounded query assertion.

- [ ] **Step 7: Commit the adapter**

```powershell
git add -- app/Services/OwnerShell/CanonicalOwnerShellService.php tests/Unit/Services/OwnerShell/CanonicalOwnerShellServiceTest.php
git commit -m "feat: compose adaptive owner shell navigation"
```

## Task 5: Share one server-selected presentation through Inertia

**Files:**

- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php`
- Create: `tests/Feature/ShopOwner/CanonicalShell/OwnerShellInertiaShareTest.php`

- [ ] **Step 1: Write failing Inertia-share tests**

Assert:

```text
guest/customer/employee/super-admin -> no ownerShell canonical metadata
shop owner, flag off                -> ownerShell.presentation = existing
allowlisted valid shop owner        -> ownerShell.presentation = canonical
invalid registration context        -> complete existing metadata
mocked composition exception        -> complete existing metadata
```

Prove the response never contains canonical groups with `presentation: existing`.

- [ ] **Step 2: Run the focused tests to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/OwnerShellInertiaShareTest.php tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php
```

Expected: FAIL because `ownerShell` is not shared.

- [ ] **Step 3: Add the shared prop at the existing server boundary**

Resolve only for an authenticated `ShopOwner`. Reuse one resolved value for the request rather than calling the service from multiple props:

```php
$ownerShell = $shopOwner instanceof ShopOwner
    ? app(CanonicalOwnerShellService::class)->forOwner($shopOwner)->toArray()
    : null;

return array_merge(parent::share($request), [
    // existing props...
    'ownerShell' => $ownerShell,
]);
```

Do not make this a client-computed or optional malformed canonical payload. Keep existing `auth.shopModules`, `erpCapabilities`, and `erpUrls` contracts unchanged.

- [ ] **Step 4: Add bounded presentation-selection telemetry**

Log only when the session has no prior selection or when presentation/reason changes. Store the last stable pair in session; log stable `shop_id`, presentation, reason, and existing correlation/session identifier. Never log email, credentials, or arbitrary metadata.

- [ ] **Step 5: Re-run focused tests**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/OwnerShellInertiaShareTest.php tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the server presentation boundary**

```powershell
git add -- app/Http/Middleware/HandleInertiaRequests.php tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php tests/Feature/ShopOwner/CanonicalShell/OwnerShellInertiaShareTest.php
git commit -m "feat: share selected owner shell presentation"
```

## Task 6: Register stable canonical routes without the ERP workspace gate

**Files:**

- Create: `routes/shop-owner-shell.php`
- Modify: `bootstrap/app.php`
- Modify: `config/shop_modules.php`
- Modify: `app/Http/Controllers/Erp/WorkspaceController.php`
- Create: `app/Http/Controllers/ShopOwner/CanonicalOwnerPaymentsController.php`
- Create: `resources/js/Pages/ShopOwner/Payments/CanonicalPaymentsLanding.tsx`
- Create: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php`
- Create: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php`

- [ ] **Step 1: Write the failing operational-route inventory test**

Define the exact route-name/URI map for Operate, Oversee, Reports, and Audit from the inventory above. Home and Settings are added and asserted in Tasks 7 and 8, then the complete inventory is rechecked in Task 11. Assert:

- each canonical route exists exactly once;
- no canonical URI contains `/erp` or `/legacy`;
- canonical routes exist with both shell and ERP workspace flags off;
- no canonical route contains `EnsureOwnerErpWorkspaceEnabled`;
- auth, ERP audience/actor, module middleware, and bindings match the reused implementation where applicable;
- unrelated detail/action routes are not renamed or duplicated.

- [ ] **Step 2: Write failing authorization-parity tests**

For each module alias, compare canonical and compatibility outcomes for:

```text
eligible + enabled owner        -> both 200 and same Inertia component
ineligible owner                -> same stable denial status/code
disabled module                -> same stable denial status/code
unauthenticated request         -> same owner-auth behavior
simultaneous owner/user session -> owner route selects shop_owner actor
```

Also prove canonical module routes work when `owner_erp_workspace_enabled` is false while `/shop-owner/erp/*` remains safely unavailable.

- [ ] **Step 3: Run route tests to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php
```

Expected: FAIL because the canonical routes are not registered.

- [ ] **Step 4: Load a dedicated canonical route file**

In `bootstrap/app.php`, load `routes/shop-owner-shell.php` under `web` before the route-catalog middleware pass. Do not add `shop-owner.shell.` to the broad ERP-prefix classifier; declare required middleware explicitly in the route file so the canonical surface remains reviewable.

- [ ] **Step 5: Register static module aliases**

Use the existing action with trusted route defaults:

```php
Route::prefix('shop-owner')->name('shop-owner.shell.')->middleware('auth:shop_owner')->group(function (): void {
    Route::middleware(['erp.audience', 'erp.actor', 'shop.module'])->group(function (): void {
        Route::get('/operate/retail', [WorkspaceController::class, 'module'])
            ->defaults('module', 'retail')->name('operate.retail');
        // repeat for repair, crm/customers, finance, hr/workforce,
        // inventory, procurement, and logistics
    });
});
```

If Laravel does not inject a route default into the current required method argument, minimally change `WorkspaceController::module` to read a bounded `module` route value from `Request`. Do not duplicate its access decision or rendering logic.

Register Reports and Audit as direct aliases to `ReadPageController::managerReports` and `ReadPageController::managerAuditLogs`. Give each alias the same owner auth, audience, actor-context, module/capability checks, and error behavior as its compatibility implementation. Do not redirect the browser to `/shop-owner/erp/manager/*`.

- [ ] **Step 6: Add canonical route-catalog entries**

Clone the authoritative shape of the corresponding owner module route entry, with the precise canonical route name and module key. Mark these aliases non-navigation-visible to `ErpWorkspaceNavigationService`; canonical navigation comes from `CanonicalOwnerShellService`. Keep actor guard `shop_owner`, audience `shop_owner`, owner access `allowed`, method `GET`, and the same module classification.

- [ ] **Step 7: Add the focused transactional-payments landing**

`CanonicalOwnerPaymentsController` must call `ShopModuleAccessService::statesFor()` once and expose only authorized direct-operation links:

```php
[
    'retail' => accessible('retail_operations') ? route('shop-owner.point-of-sale') : null,
    'repair' => accessible('repair_operations') ? route('erp.repairer.point-of-sale') : null,
]
```

Resolve the exact existing owner-safe Repair POS route from `ErpRouteCatalog`; never send an owner to an employee-only audience. If no owner-safe direct payment route exists for a module, omit that link and record the capability as not migration-complete rather than weakening middleware. The page is a navigation landing only and performs no payment queries or mutations.

- [ ] **Step 8: Re-run route and parity tests**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit canonical topology**

```powershell
git add -- routes/shop-owner-shell.php bootstrap/app.php config/shop_modules.php app/Http/Controllers/Erp/WorkspaceController.php app/Http/Controllers/ShopOwner/CanonicalOwnerPaymentsController.php resources/js/Pages/ShopOwner/Payments/CanonicalPaymentsLanding.tsx tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php
git commit -m "feat: add canonical shop owner destinations"
```

## Task 7: Reuse the dashboard as canonical Home with Phase 3 placeholders

**Files:**

- Create: `app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php`
- Modify: `routes/web.php`
- Modify: `routes/shop-owner-shell.php`
- Modify: `resources/js/Pages/ShopOwner/Dashboard.tsx`
- Create: `resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx`
- Create: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php`

- [ ] **Step 1: Write failing backend Home tests**

Assert:

- `/shop-owner/dashboard` and `/shop-owner/home` render `ShopOwner/Dashboard`;
- `shop-owner.shell.home` is the only canonical Home route and uses `/shop-owner/home`;
- both preserve current dashboard behavior and API use;
- canonical Home is available with the shell flag off;
- only canonical Home receives `showPhaseThreePlaceholders: true`;
- no approval, exception, notification, refund, repair, payroll, or attention query runs while rendering the page response.

- [ ] **Step 2: Write failing frontend placeholder tests**

Assert that canonical Home renders two visually subordinate informational sections:

```text
Required Actions — Coming in Phase 3
Exceptions — Coming in Phase 3
```

They must have no counts, no disabled-control semantics, and no links to legacy approval pages. Existing dashboard metrics remain rendered.

- [ ] **Step 3: Run focused tests to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
```

Expected: FAIL because the controller, route, and placeholders do not exist.

- [ ] **Step 4: Extract the existing dashboard closure into one controller**

Use the same controller for both routes. Set the placeholder prop from a trusted route default, not by inspecting `window.location` in React:

```php
public function __invoke(Request $request): Response
{
    return Inertia::render('ShopOwner/Dashboard', [
        'shop_owner' => $request->user('shop_owner'),
        'showPhaseThreePlaceholders' => (bool) $request->route('canonical_home', false),
    ]);
}
```

- [ ] **Step 5: Add static informational placeholder markup**

Use existing cards/tokens. Copy must say existing module and approval pages remain the current action surfaces. If a helpful link is included, it may point only to a canonical module destination already supplied by the shell; do not add a legacy approval-page link.

- [ ] **Step 6: Re-run Home tests**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx
```

Expected: PASS.

- [ ] **Step 7: Commit canonical Home**

```powershell
git add -- app/Http/Controllers/ShopOwner/ShopOwnerDashboardController.php routes/web.php routes/shop-owner-shell.php resources/js/Pages/ShopOwner/Dashboard.tsx resources/js/Pages/ShopOwner/__tests__/DashboardCanonicalHome.test.tsx tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerHomeTest.php
git commit -m "feat: frame existing dashboard as canonical home"
```

## Task 8: Add canonical Settings section URLs without duplicating settings behavior

**Files:**

- Modify: `routes/shop-owner-shell.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`
- Create: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerSettingsTest.php`
- Create: `resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx`

- [ ] **Step 1: Write failing route/component tests**

For all six section keys, assert the canonical URL renders the existing settings component with a trusted `initialSection`:

```text
profile
modules-team
payments-approvals
operations
policies-compliance
subscription
```

Invalid section values must not be client-selectable. Existing `/shop-owner/settings` behavior and mutations remain unchanged.

Also assert each section has exactly one canonical route name/URL and that the complete Phase 2 route inventory now contains every destination listed at the start of this plan.

- [ ] **Step 2: Run focused tests to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerSettingsTest.php
pnpm run test:frontend -- resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx
```

Expected: FAIL because canonical settings routes and initial-section behavior do not exist.

- [ ] **Step 3: Register six static aliases to the same controller action**

Set a bounded route default for each alias. Change `ShopSettingsController::index` to accept `Request` and pass only the known route default as `initialSection`; default the compatibility page to `profile` or its current top section.

- [ ] **Step 4: Expose existing settings content through accessible section navigation**

Add stable section IDs and a small section navigation that selects/focuses the trusted initial section. Do not split settings persistence, duplicate forms, or change update endpoints. Use buttons/links with visible focus and correct active semantics.

- [ ] **Step 5: Re-run settings tests**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerSettingsTest.php
pnpm run test:frontend -- resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx
```

Expected: PASS.

- [ ] **Step 6: Commit settings aliases**

```powershell
git add -- routes/shop-owner-shell.php app/Http/Controllers/ShopOwner/ShopSettingsController.php resources/js/Pages/ShopOwner/Settings/shopSetting.tsx tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerSettingsTest.php resources/js/Pages/ShopOwner/Settings/__tests__/CanonicalSettingsSections.test.tsx
git commit -m "feat: add canonical owner settings sections"
```

## Task 9: Render the canonical adaptive shell in both owner page families

**Files:**

- Create: `resources/js/layout/CanonicalOwnerLayout.tsx`
- Create: `resources/js/layout/CanonicalOwnerSidebar.tsx`
- Create: `resources/js/layout/CanonicalOwnerHeader.tsx`
- Create: `resources/js/layout/ownerShellMetadata.ts`
- Modify: `resources/js/layout/AppLayout_shopOwner.tsx`
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Create: `resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx`
- Create: `resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`

- [ ] **Step 1: Write failing adaptive-navigation tests**

With server metadata fixtures, assert:

- individual: `Operate` precedes and defaults expanded before `Oversee`;
- company: `Oversee` precedes and defaults expanded before `Operate`;
- empty groups do not render;
- unavailable items include text reason, not color alone;
- Home, Reports, Audit, and Settings links use canonical URLs;
- compatibility active matches highlight the single canonical item;
- no registration/module/capability computation occurs in the component;
- ERP fallback is secondary and outside primary groups.

- [ ] **Step 2: Write failing frame-selection tests**

```text
owner page + canonical metadata       -> CanonicalOwnerLayout
owner page + existing metadata        -> existing AppSidebar_shopOwner frame
owner-mode ERP page + canonical       -> CanonicalOwnerLayout
owner-mode ERP page + existing        -> existing AppSidebar_ERP frame
employee ERP page                     -> existing AppSidebar_ERP frame
```

This is the no-mixed-shell invariant.

- [ ] **Step 3: Run frontend tests to verify RED**

```powershell
pnpm run test:frontend -- resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: FAIL because the canonical components do not exist.

- [ ] **Step 4: Add one defensive metadata reader**

`ownerShellMetadata.ts` may return canonical metadata only when the server-provided discriminant and required arrays are present. On absent metadata it returns `null`, causing the existing frame. It must not repair malformed groups, infer context, or compute authorization; server validation remains authoritative.

- [ ] **Step 5: Implement the canonical layout and sidebar**

Reuse `SidebarProvider`, `Backdrop`, existing spacing tokens, icon library, dark-mode conventions, and header/profile controls. Render groups/items directly from metadata. Use semantic links/buttons, `aria-expanded`, visible focus, keyboard activation, and text/tooltips for collapsed icon-only states.

- [ ] **Step 6: Bridge both existing layouts**

At the top of each layout, select the canonical frame only when trusted shared metadata says `presentation === "canonical"` and the actor is the Shop Owner/owner-mode actor. Do not select it from `location.pathname`. Keep employee ERP unchanged.

- [ ] **Step 7: Add mobile and reduced-motion behavior tests**

Assert drawer close restores focus to the trigger, backdrop closes the drawer, active destination is announced/styled, and motion classes respect existing reduced-motion conventions.

- [ ] **Step 8: Re-run frontend tests**

```powershell
pnpm run test:frontend -- resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
```

Expected: PASS.

- [ ] **Step 9: Commit the canonical frame**

```powershell
git add -- resources/js/layout/CanonicalOwnerLayout.tsx resources/js/layout/CanonicalOwnerSidebar.tsx resources/js/layout/CanonicalOwnerHeader.tsx resources/js/layout/ownerShellMetadata.ts resources/js/layout/AppLayout_shopOwner.tsx resources/js/layout/AppLayout_ERP.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx resources/js/layout/__tests__/CanonicalOwnerLayout.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
git commit -m "feat: render adaptive canonical owner shell"
```

## Task 10: Add the controlled ERP workspace fallback and fixed telemetry

**Files:**

- Create: `app/Enums/OwnerShellFallbackReason.php`
- Create: `app/Http/Requests/ShopOwner/OpenOwnerErpFallbackRequest.php`
- Create: `app/Http/Controllers/ShopOwner/OwnerErpFallbackController.php`
- Modify: `routes/shop-owner-shell.php`
- Modify: `app/Services/OwnerShell/CanonicalOwnerShellService.php`
- Modify: `resources/js/layout/CanonicalOwnerSidebar.tsx`
- Create: `tests/Feature/ShopOwner/CanonicalShell/OwnerErpFallbackTest.php`

- [ ] **Step 1: Write failing fallback tests**

Cover:

```text
canonical + ERP eligible        -> secondary fallback visible
existing presentation           -> hidden
canonical + ERP flag off        -> hidden
canonical + ERP unauthorized    -> hidden
valid fixed reason/source       -> log and redirect to existing workspace
arbitrary reason/source         -> validation failure
owner outside source shop       -> denied
```

Allowed reasons are exactly `missing_destination`, `missing_action`, `verification`, and `user_preference`. Source capability/page values must come from a server-owned fixed key list, not arbitrary text.

- [ ] **Step 2: Run the fallback test to verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/OwnerErpFallbackTest.php
```

Expected: FAIL because the enum, request, controller, and route do not exist.

- [ ] **Step 3: Implement re-evaluated fallback access**

The fallback endpoint must:

1. authenticate `shop_owner`;
2. validate fixed reason/source keys;
3. re-run rollout and ERP workspace eligibility;
4. log stable `shop_id`, reason, source capability/page, and correlation/session identifier;
5. redirect to `shop-owner.erp.workspace` only after those checks.

Do not treat fallback use as permission evidence. Do not accept an external return URL.

- [ ] **Step 4: Render the link as secondary compatibility UI**

Place “Open existing ERP Workspace” in the sidebar footer/profile/help area. It must not be a group or duplicate ERP navigation.

- [ ] **Step 5: Re-run fallback and sidebar tests**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell/OwnerErpFallbackTest.php
pnpm run test:frontend -- resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx
```

Expected: PASS.

- [ ] **Step 6: Commit fallback telemetry**

```powershell
git add -- app/Enums/OwnerShellFallbackReason.php app/Http/Requests/ShopOwner/OpenOwnerErpFallbackRequest.php app/Http/Controllers/ShopOwner/OwnerErpFallbackController.php routes/shop-owner-shell.php app/Services/OwnerShell/CanonicalOwnerShellService.php resources/js/layout/CanonicalOwnerSidebar.tsx tests/Feature/ShopOwner/CanonicalShell/OwnerErpFallbackTest.php
git commit -m "feat: add measurable owner ERP fallback"
```

## Task 11: Prove rollback, parity, accessibility, and rollout readiness

**Files:**

- Create: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRollbackTest.php`
- Create: `tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerCapabilityParityTest.php`
- Create: `docs/shop-owner-phase-2-rollout-guide.md`
- Modify: `docs/ai-learning-log.md` only if a durable repository-wide lesson is discovered
- Modify: `scripts/browser-smoke.mjs` only if the existing script needs a narrow canonical-shell scenario

- [ ] **Step 1: Write rollback and bookmark tests**

Assert:

- switching the global flag off immediately selects the complete existing presentation;
- every canonical URL remains registered and authorized as before;
- canonical bookmarks render the underlying capability in the existing frame;
- no canonical/compatibility redirect loop exists;
- no module state, authorization, or domain row changes during rollback.

- [ ] **Step 2: Add a per-capability parity data provider**

For every inventory row, record:

```php
[
    'capability' => 'finance',
    'canonical_route' => 'shop-owner.shell.oversee.finance',
    'compatibility_route' => 'shop-owner.erp.module',
    'compatibility_parameters' => ['module' => 'finance'],
    'expected_component' => 'ERP/ModuleLanding',
]
```

Test component, tenant, module denial, audience, and HTTP error parity. Mark a capability incomplete when no owner-safe source action exists or when fallback is still necessary.

- [ ] **Step 3: Run backend regression tests**

```powershell
php artisan test tests/Feature/ShopOwner/CanonicalShell tests/Feature/BusinessScaling/OwnerErpRolloutConfigurationTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php tests/Feature/BusinessScaling/ShopModuleMiddlewareTest.php tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php
```

Expected: PASS.

- [ ] **Step 4: Run the complete frontend test suite**

```powershell
pnpm run test:frontend
```

Expected: PASS.

- [ ] **Step 5: Build production frontend assets**

```powershell
pnpm run build
```

Expected: PASS with no Vite/TypeScript build errors. The repository has no committed standalone type-check or lint script; do not claim either was run.

- [ ] **Step 6: Perform browser verification for both contexts**

With the global flag on and only test shop IDs allowlisted, verify at desktop and mobile widths:

- individual Retail, individual Repair, company Retail, and company `both` contexts;
- ordering/default expansion and empty-group omission;
- canonical URL remains in the address bar;
- old compatibility URLs highlight the canonical item without a second map;
- reused ERP pages render inside the canonical owner frame only for owner mode;
- employee ERP remains unchanged;
- keyboard traversal, focus visibility, mobile focus restoration, backdrop, active state, and reduced motion;
- placeholders are informational and show no counts;
- ERP fallback is secondary and telemetry accepts only fixed values;
- kill-switch rollback returns the full existing frame without breaking canonical bookmarks.

Use `pnpm run test:browser` if the local app/test fixtures support these actors; otherwise record the exact manual/local-browser scenarios and screenshots as QA evidence without claiming automated coverage.

- [ ] **Step 7: Write the rollout guide and per-capability evidence table**

Document:

- flag and allowlist configuration names;
- empty-allowlist deployment order;
- how to add/remove stable shop IDs;
- kill-switch rollback procedure;
- expected selection reasons and safe logs;
- fixed fallback reasons/sources;
- a table for each capability: canonical route, compatibility source, auth parity, behavior parity, browser result, fallback still required, migration-complete yes/no;
- explicit rule that unresolved `missing_action`/`missing_destination` prevents that capability from being marked complete.

- [ ] **Step 8: Run the required sequential review stack**

Record results in this order:

1. simplify with `@ponytail`;
2. Standards review against Laravel/Inertia/repository conventions;
3. Spec review against all 30 Phase 2 acceptance criteria;
4. TS/TSX clean-code and React performance review with `@vercel-react-best-practices`;
5. assumptions/minimum-scope review with `@karpathy-guidelines`;
6. code-splitting review—expected `N/A` unless a genuinely heavy conditional dependency was added;
7. gauge improvements: route/parity tests, query count, metadata payload size, and fallback evidence; report unmeasured items honestly;
8. security review for auth, audience, tenant scope, safe telemetry, and untrusted client inputs;
9. verification-before-completion evidence review.

Perform these reviews sequentially. Do not invoke the parallel `code-review` skill unless the user separately approves the repository’s optional parallel-review gate.

- [ ] **Step 9: Perform reuse and dead-code audits**

Confirm reuse of `ShopModuleAccessService`, `ErpRouteCatalog`, `ErpWorkspaceNavigationService`, existing controllers/pages, `SidebarProvider`, and existing authorization middleware. Scan changed files for unused imports, duplicate route maps, stale flags, unreachable mixed-shell branches, temporary TODOs, and direct new links to `/shop-owner/erp/*` outside the compatibility fallback.

- [ ] **Step 10: Run final quality gates**

```powershell
git diff --check
php artisan test tests/Feature/ShopOwner/CanonicalShell tests/Unit/Services/OwnerShell tests/Unit/Support/OwnerShell
pnpm run test:frontend
pnpm run build
```

Expected: all commands PASS. If the broader repository suite is practical, also run `composer test`; report its exact result separately.

- [ ] **Step 11: Commit rollout evidence and guide**

```powershell
git add -- tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRollbackTest.php tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerCapabilityParityTest.php docs/shop-owner-phase-2-rollout-guide.md scripts/browser-smoke.mjs docs/ai-learning-log.md
git commit -m "docs: add canonical owner shell rollout evidence"
```

Stage `scripts/browser-smoke.mjs` and `docs/ai-learning-log.md` only if this task actually changed them. Never stage unrelated pre-existing edits.

## Final acceptance gate

Do not call Phase 2 complete or expand the allowlist until all of the following are evidenced:

- canonical is selected only by the global flag plus stable shop allowlist;
- rollout/composition failure produces the complete existing presentation;
- canonical route existence and capability eligibility are independent of rollout and ERP workspace flags;
- canonical and compatibility routes have equivalent authorization and domain behavior;
- individual/company emphasis is server-composed and eligibility-aware;
- Home contains only existing dashboard behavior plus static Phase 3 placeholders;
- no attention/approval/exception aggregation or navigation N+1 was introduced;
- both owner page families use one canonical frame without changing employee ERP;
- the ERP fallback is secondary, re-authorized, measurable, and fixed-key only;
- rollback has been exercised without route or domain rollback;
- migration completeness is recorded per capability;
- all applicable review-stack items and exact verification commands/results are recorded.
