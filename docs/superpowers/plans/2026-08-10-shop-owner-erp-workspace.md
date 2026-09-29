# Shop Owner ERP Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give an approved company shop owner a secure owner-mode ERP workspace that reuses employee operations and React pages through unambiguous owner routes, without impersonating an employee or weakening module, tenant, workflow, or audit controls.

**Architecture:** Keep `config/shop_modules.php` as the only editable capability source. Generate a reviewable route matrix from that catalog and Laravel's loaded routes, resolve one request-scoped `ErpActorContext`, and let paired employee/owner route declarations call the same page controllers, API controllers, services, requests, and policies. Expose owner routes only after their module gate, tenant scope, supporting APIs, domain authorization, actor-persistence strategy, and explicit Spatie activity are complete.

**Tech Stack:** Laravel 12/PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Spatie Permission/Activitylog, PHPUnit, Vitest/Testing Library, pnpm.

**Approved designs:**

- `docs/superpowers/specs/2026-08-10-shop-owner-erp-workspace-design.md`
- `docs/superpowers/specs/2026-08-10-business-scaling-module-access-design.md`

**Implementation skills:** Use `@superpowers:test-driven-development`, `@laravel-best-practices`, and `@security-review` for backend tasks; use `@ui-ux-pro-max`, `@ui-styling`, `@vercel-react-best-practices`, and `@typescript-advanced-types` for the shared shell; finish each task with `@ponytail:ponytail`, `@karpathy-guidelines`, and `@superpowers:verification-before-completion`.

**Repository execution mode:** Use `@superpowers:executing-plans` sequentially by default. The repository permits subagent-driven work only after the user explicitly approves the bounded parallel-review gate.

---

## Delivery contract

- The route matrix is a hard prerequisite. Tasks 1-3 must be reviewed before any operational owner alias is added.
- An owner mutation stays cataloged as owner-denied until its domain policy, tenant query, actor persistence, transaction, audit, idempotency, and external-effect behavior are all implemented and tested.
- Employee route names, URLs, `auth:user`, Spatie role/permission rules, and self-service behavior remain compatible.
- Owner routes use only `auth:shop_owner`; do not add new `auth:user,shop_owner` routes.
- Owner ERP routes are always registered; `SHOP_OWNER_ERP_WORKSPACE_ENABLED` is enforced through fail-closed feature middleware so route-cache topology is stable.
- `ErpActorContext` is request-scoped. Do not bind it as a singleton or cache an authenticated model in process-wide state.
- Owner IDs never populate foreign keys constrained to `users.id`.
- Owner policy, current route exposure, and actor persistence are separate facts; do not use one field as a proxy for another.
- The catalog grants route eligibility only. Existing policies, workflow rules, maker/checker rules, validation, throttles, payment protections, and upload limits remain authoritative.
- Do not hand-edit `public/build`. Run one fresh build at the final gate and review generated changes separately.
- Preserve the existing dirty changes in `resources/js/layout/AppSidebar_shopOwner.tsx`, its test, the two design specs, and `public/build`; reconcile them through focused patches rather than resetting the worktree.

## Capability rollout gates

| Gate | Required evidence | Routes allowed afterward |
| --- | --- | --- |
| A: Catalog complete | Bidirectional coverage passes; generated matrix has no missing classification, method, audience, owner policy/denial, domain reference, risk, pair, API, persistence, or self-service decision | No new operational owner routes |
| B: Actor boundary complete | Dual-session, audience, approved-company, status, business type, module state, and stable-error tests pass | Workspace landing and shared shell only |
| C: Read boundary complete | Owner page smoke tests and cross-shop read tests pass for a matrix wave | Read-only aliases in that wave |
| D: Mutation boundary complete | Persistence, policy, audit, rollback, retry, and external-effect tests pass for a matrix row | That exact owner mutation only |
| E: Release complete | Full focused suites, production flag invariant, build, and diff checks pass | Staging rollout |

### Stable ERP access errors

| Condition | Code/status | Owner browser behavior |
| --- | --- | --- |
| Workspace feature disabled | safe not found / 404 | Existing not-found behavior; no actor or resource resolution |
| Missing owner session | `ERP_AUTH_REQUIRED` / 401 | Shop-owner login redirect |
| Individual or non-approved owner | `OWNER_ERP_ACCOUNT_INELIGIBLE` / 403 | Owner portal with safe explanation |
| Unclassified, wrong-audience, self-service, or owner-denied route | `ERP_ROUTE_NOT_ALLOWED` / 403 | Workspace landing |
| Malformed or unknown module key in the runtime route catalog | `ERP_ROUTE_NOT_ALLOWED` / 403 | Workspace landing with a safe generic explanation |
| Known eligible module with missing required persisted state | `MODULE_STATE_MISSING` / 403 | Workspace landing with safe configuration guidance |
| Disabled persisted module | `MODULE_DISABLED` / 403 | Workspace landing with module guidance |
| Wrong registration/business/module eligibility | `MODULE_INELIGIBLE` / 403 | Workspace landing with reason |
| Domain policy denial | `DOMAIN_ACTION_FORBIDDEN` / 403 | Current page with safe explanation |
| Missing or cross-shop resource | `RESOURCE_NOT_FOUND` / 404 | Existing safe not-found behavior |

---

## File map

### Capability catalog and review artifact

- Modify: `config/shop_modules.php`
- Create: `app/Services/ErpRouteCatalog.php`
- Create: `app/Console/Commands/ErpRouteMatrixCommand.php`
- Modify: `tests/Unit/BusinessScaling/ShopModuleCatalogTest.php`
- Create: `tests/Unit/BusinessScaling/ErpRouteCatalogTest.php`
- Modify: `tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php`
- Create: `tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php`
- Create/generated: `docs/architecture/shop-owner-erp-route-matrix.md`
- Modify: `docs/architecture/shop-module-route-inventory.md`
- Modify: `docs/architecture/shop-module-owner-parity.md`

### Actor, authorization, and routing boundary

- Create: `app/Support/Erp/ErpActorContext.php`
- Create: `app/Support/Erp/ErpAccessResponder.php`
- Create: `app/Http/Middleware/EnsureErpAudience.php`
- Create: `app/Http/Middleware/EnsureOwnerErpWorkspaceEnabled.php`
- Create: `app/Http/Middleware/ResolveErpActorContext.php`
- Modify: `app/Http/Middleware/EnsureShopModuleEnabled.php`
- Modify: `app/Http/Middleware/CheckEmployeeSuspension.php`
- Modify: `app/Http/Middleware/Authenticate.php`
- Modify: `app/Services/ShopModuleAccessService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `bootstrap/app.php`
- Create: `routes/shop-owner-erp.php`
- Create: `routes/shop-owner-erp-api.php`
- Create: `tests/Feature/BusinessScaling/ErpActorContextTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpRolloutConfigurationTest.php`

### Workspace and shared frontend contract

- Create: `app/Http/Controllers/Erp/WorkspaceController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/types/erp.ts`
- Create: `resources/js/utils/erpCapabilities.ts`
- Create: `resources/js/utils/__tests__/erpCapabilities.test.ts`
- Create: `resources/js/Pages/ERP/Workspace.tsx`
- Create: `resources/js/Pages/ERP/__tests__/Workspace.test.tsx`
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Modify: `resources/js/layout/AppHeader_ERP.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/layout/AppSidebar_shopOwner.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Create: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`

### Shared operations, persistence, and audit

- Modify: `routes/web.php`
- Modify: `routes/api.php`
- Modify: `routes/hr-api.php`
- Modify: `routes/finance-api.php`
- Modify: `routes/inventory-api.php`
- Modify: `routes/procurement-api.php`
- Modify: `routes/permission-audit-api.php`
- Modify: `routes/shop-owner-api.php`
- Modify: owner-capable controllers under `app/Http/Controllers/Erp`, `app/Http/Controllers/Api`, `app/Http/Controllers/Logistics`, and `app/Http/Controllers/ShopOwner` as identified by the generated matrix
- Modify: corresponding Form Requests, policies, services, and models named in each matrix row
- Create targeted migrations under `database/migrations` only for matrix rows marked `paired_owner_ref`
- Create: `app/Services/ErpAuditService.php`
- Modify: `app/Http/Controllers/ActivityLogController.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpMutationContractTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpActorPersistenceTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpAuditTest.php`

---

### Task 1: Make the route catalog schema express the approved contract

**Files:**

- Modify: `config/shop_modules.php`
- Modify: `tests/Unit/BusinessScaling/ShopModuleCatalogTest.php`
- Create: `tests/Unit/BusinessScaling/ErpRouteCatalogTest.php`
- Create: `app/Services/ErpRouteCatalog.php`

- [ ] **Step 1: Write failing catalog-schema tests**

Assert that every catalog entry has these keys and valid values:

```php
[
    'methods' => ['GET'],
    'classification' => 'core|module|excluded',
    'audience' => 'user|shop_owner|public|super_admin|system',
    'actor_guard' => 'user|shop_owner|null',
    'module_keys' => [],
    'mode' => 'single|all_of|any_of|null',
    'registration_types' => [],
    'business_types' => [],
    'action' => 'view|create|update|approve|reject|checkout|assign|upload|delete|system',
    'owner_access' => 'allowed|denied',
    'owner_denial_reason' => null,
    'domain_rule' => null,
    'risk_tier' => 'normal|sensitive|financial',
    'paired_route' => null,
    'navigation_group' => null,
    'self_service' => false,
    'supporting_routes' => [],
    'actor_persistence' => 'not_applicable|existing_owner_ref|paired_owner_ref|polymorphic_actor',
];
```

Also assert:

- `module` entries have known module keys and a supported gate mode;
- `core` and `excluded` entries have no module keys;
- `shop_owner` entries use only `shop_owner`, and `user` entries use only `user`;
- a paired route points back to its partner and shares an action classification;
- self-service routes have `audience = user` and no owner pair;
- owner-denied routes have a stable denial reason and `actor_persistence = not_applicable` when no owner write occurs;
- `domain_rule` is `null` when no domain policy or workflow exists beyond catalog/module authorization; otherwise it is a descriptive policy/service/workflow reference rather than executable thresholds, including for policy-governed reads;
- `risk_tier` is review and rollout metadata only and never participates in runtime authorization;
- owner mutations marked allowed have a resolved persistence strategy and a reviewed risk tier.

- [ ] **Step 2: Run the unit tests and confirm RED**

Run:

```powershell
php artisan test tests/Unit/BusinessScaling/ShopModuleCatalogTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php
```

Expected: FAIL because the current entries contain only classification, mode, module keys, ordered guards, and customer capability.

- [ ] **Step 3: Add a minimal catalog accessor**

Implement `ErpRouteCatalog` as a read-only adapter over `config('shop_modules.routes')`. It should normalize route methods, look up an entry by route name and HTTP method, derive the canonical client key from the employee side of a pair, and expose no independent eligibility rules.

It also derives employee authorization text from the loaded route middleware and derives owner exposure as `absent` or `exposed` from the loaded paired route. Do not add hand-maintained `employee_rule` or `owner_route_status` fields.

Use a key format consistently:

```php
public static function capabilityKey(string $method, string $routeName): string
{
    return strtoupper($method).':'.$routeName;
}
```

- [ ] **Step 4: Expand the config entry factory and classify all current entries**

Keep the existing eight module definitions and `ShopModuleAccessService` eligibility source. Replace `actor_guards` with one concrete `actor_guard`; add the schema above. Reclassify operational routes currently in `core`, including CRM, HR, reports, logistics settings, and module-specific manager controls. Mark public/customer, SuperAdmin, owner-portal-only, system, and employee self-service routes explicitly `excluded` with narrow audiences.

Do not add owner pairs yet. For an employee-subject operation, set `owner_access = denied`, use a reason such as `employee_subject_required`, and set `actor_persistence = not_applicable`. Resolve every owner policy decision without pretending that an allowed future policy is already exposed.

- [ ] **Step 5: Run the catalog tests and commit**

Run:

```powershell
php artisan test tests/Unit/BusinessScaling/ShopModuleCatalogTest.php tests/Unit/BusinessScaling/ErpRouteCatalogTest.php
```

Expected: PASS.

Commit: `refactor: formalize ERP route capabilities`

---

### Task 2: Enforce bidirectional route coverage

**Files:**

- Modify: `tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php`
- Modify: `config/shop_modules.php`
- Modify: unnamed operational declarations in `routes/web.php`, `routes/api.php`, `routes/hr-api.php`, `routes/finance-api.php`, `routes/inventory-api.php`, `routes/procurement-api.php`, `routes/permission-audit-api.php`, and `routes/shop-owner-api.php`

- [ ] **Step 1: Write the reverse-coverage test**

Retain catalog-to-route assertions and add route-to-catalog assertions. Treat only exact framework/tooling routes such as `/up` as exemptions. A named application route without a catalog entry fails. An unnamed route fails when its URI or middleware identifies it as internal owner/employee ERP behavior. Broad prefix exemptions are forbidden.

Validate that catalog methods equal the loaded route methods after removing Laravel's implicit `HEAD` from `GET` routes.

- [ ] **Step 2: Run coverage and confirm RED**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php
```

Expected: FAIL with a finite list of unclassified or unnamed routes.

- [ ] **Step 3: Name and classify each reported route**

Add stable route names at their existing declaration sites. Add exact catalog entries; do not rename already-public employee routes. Use `excluded` for true customer/public/SuperAdmin/system routes rather than omitting them.

- [ ] **Step 4: Assert route-declaration invariants available at this stage**

Extend the test so:

- each concrete route has one actor guard at most;
- employee routes retain their current employee middleware;
- no existing owner route gathers employee role/permission, employee business-type, or `shop.isolation` middleware;
- no new owner route uses a multi-guard authentication string.

For every configured employee/owner pair, also assert compatible parameter names, nesting, constraints, and binding semantics. A pair is invalid when the shared action would receive a different route-parameter contract unless a narrow adapter is explicitly cataloged and contract-tested.

Add audience/context/binding-order assertions in Task 4 after those middleware classes exist.

- [ ] **Step 5: Run coverage and commit**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php
```

Expected: PASS with no unclassified internal routes.

Commit: `test: enforce complete ERP route classification`

---

### Task 3: Generate and review the owner capability matrix

**Files:**

- Create: `app/Console/Commands/ErpRouteMatrixCommand.php`
- Create: `tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php`
- Create/generated: `docs/architecture/shop-owner-erp-route-matrix.md`
- Modify: `docs/architecture/shop-module-route-inventory.md`
- Modify: `docs/architecture/shop-module-owner-parity.md`
- Modify: `config/shop_modules.php`

- [ ] **Step 1: Write a failing command test**

Test `php artisan erp:route-matrix --write`. Assert deterministic ordering and these columns:

```text
Method | Employee route | Owner policy | Owner exposure/route | Component/controller |
Supporting APIs | ERP group | Module gate | Business type | Employee rule |
Domain rule | Risk | Actor persistence | Self-service
```

The command must fail non-zero when an owner-capable component has no supporting API list; a catalog row lacks an explicit owner policy, required denial reason, or risk tier; an owner-capable operational row that relies on domain policy or workflow beyond catalog/module authorization lacks a descriptive `domain_rule`; an allowed mutation has no actor-persistence decision; a loaded pair is one-way or parameter-incompatible; or a configured route is not loaded. Excluded, public, SuperAdmin, and system routes—and operations with no additional domain authorization—may use `domain_rule = null`. Owner exposure and the employee middleware rule are derived from Laravel's loaded route collection rather than hand-maintained catalog fields.

- [ ] **Step 2: Run the command test and confirm RED**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php
```

- [ ] **Step 3: Implement deterministic generation**

Read only `ErpRouteCatalog` and Laravel's route collection. Resolve controller/component from the loaded action. Write the generated Markdown only when `--write` is passed; otherwise print it to stdout. Include a generated warning that the file is a review artifact, not a policy source.

- [ ] **Step 4: Review every operational row**

For each page, inspect its initial data and every fetch/axios helper. Populate `supporting_routes`, `owner_access`, `owner_denial_reason`, gate mode, `risk_tier`, and persistence decision in `config/shop_modules.php`. Set a descriptive `domain_rule` only when the operation relies on domain policy or workflow beyond catalog/module authorization; otherwise record `null`. Explicitly mark time-in/out, personal leave/overtime, personal payslips, employee profile/password, and rider-assigned execution as employee self-service with no owner pair.

For an owner mutation, choose exactly one persistence result:

- `existing_owner_ref` when a durable owner reference already exists;
- `paired_owner_ref` when a targeted `*_shop_owner_id` is required;
- `polymorphic_actor` only when an established polymorphic actor field already exists;
- `not_applicable` for reads, exclusions, and owner-denied employee-subject actions.

Keep policy and storage separate: an employee-subject foreign key is an owner denial reason, not an actor-persistence type.

- [ ] **Step 5: Generate the matrix and update stale architecture notes**

Run:

```powershell
php artisan erp:route-matrix --write
```

Replace the hand-maintained parity claims in the two existing architecture docs with short links to the generated matrix and its generation command. `config/shop_modules.php` is the machine policy source, the generated matrix is the route-inventory projection, and other architecture docs contain concepts and links only. Do not maintain the same route policy in three Markdown files.

- [ ] **Step 6: Pass Gate A and commit**

Run:

```powershell
php artisan test tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php
git diff --check
```

Expected: PASS; the generated matrix contains no undecided owner row.

Commit: `docs: generate shop owner ERP capability matrix`

---

### Task 4: Resolve one unambiguous request-scoped ERP actor

**Files:**

- Create: `app/Support/Erp/ErpActorContext.php`
- Create: `app/Support/Erp/ErpAccessResponder.php`
- Create: `app/Http/Middleware/EnsureErpAudience.php`
- Create: `app/Http/Middleware/ResolveErpActorContext.php`
- Modify: `app/Http/Middleware/Authenticate.php`
- Modify: `app/Http/Middleware/EnsureShopModuleEnabled.php`
- Modify: `app/Http/Middleware/CheckEmployeeSuspension.php`
- Modify: `app/Services/ShopModuleAccessService.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Feature/BusinessScaling/ErpActorContextTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php`

- [ ] **Step 1: Write failing actor, audience, and authentication tests**

Cover employee-only, owner-only, anonymous, wrong-guard, and simultaneous `user` plus `shop_owner` sessions. Assert that route family—not session order, request input, referrer, or owner ID—selects the actor. Assert stable JSON fields `code`, `error`, `message`, and array `module_keys`.

For an unauthenticated owner ERP request, assert that JSON receives HTTP 401 with `ERP_AUTH_REQUIRED`, while a browser request redirects to the shop-owner login. Confirm employee and non-ERP authentication behavior remains unchanged.

Include these owner denials:

- approved Individual;
- pending, rejected, or suspended company;
- wrong business type;
- owner-denied catalog row;
- known eligible module with its required persisted state row missing;
- disabled module;
- malformed or unknown module key in the runtime route catalog;
- unknown route.

Assert each condition uses the exact table above. In particular: a known eligible module with no required persisted row returns `MODULE_STATE_MISSING`; a known but ineligible module returns `MODULE_INELIGIBLE`; a known disabled module returns `MODULE_DISABLED`; and an unknown or malformed catalog module key returns `ERP_ROUTE_NOT_ALLOWED`. For JSON, `error` repeats `code` and `module_keys` is always an array.

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/ErpActorContextTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php
```

- [ ] **Step 3: Add the request-scoped value object**

`ErpActorContext` contains the concrete actor, guard, tenant `ShopOwner`, owner-mode flag, route name, HTTP method, action, module keys, gate mode, and evaluated decision. Give it explicit `actor()`, `employeeActor(): ?User`, `ownerActor(): ?ShopOwner`, `tenantOwner(): ShopOwner`, and `isOwnerMode(): bool` accessors; never make `ShopOwner` satisfy a `User` return type. In employee mode, `tenantOwner()` is the employee's tenant owner, not the performer.

Bind the resolved instance during middleware execution with request/container-scoped lifetime. Add a test that two sequential requests in the same process receive different instances and actors.

- [ ] **Step 4: Implement audience-before-auth behavior**

`EnsureErpAudience` should:

1. read the concrete route entry;
2. continue when its required guard is authenticated;
3. return `403 ERP_ROUTE_NOT_ALLOWED` when another internal guard is authenticated but the required guard is absent;
4. otherwise continue so Laravel authentication produces the correct login redirect or 401.

Customize `Authenticate.php` so browser owner-ERP requests use the shop-owner login destination. Add a narrowly scoped `AuthenticationException` renderer in `bootstrap/app.php` so unauthenticated JSON owner-ERP requests return the stable `ERP_AUTH_REQUIRED` contract rather than Laravel's generic response.

Configure the middleware introduced so far in this order: audience selection, authentication, actor resolution, permanent owner eligibility and owner policy, module eligibility/state enforcement, then tenant-scoped binding. Form Request authorization/domain policy and controller/service execution follow binding through Laravel's normal request flow. Route bindings may not run before tenant authority is known. Task 5 prepends the workspace feature boundary when that middleware is created.

`CheckEmployeeSuspension` is currently appended to both global web and API stacks and can run before route middleware. Make it defer owner ERP route names to the ERP actor/capability boundary so suspended owners receive `OWNER_ERP_ACCOUNT_INELIGIBLE` instead of a legacy employee/session response. Preserve its current behavior for employee and non-workspace routes.

- [ ] **Step 5: Refactor module middleware around the context**

Keep the existing `shop.module` alias for compatibility, but make `EnsureShopModuleEnabled` consume `ErpActorContext` and the catalog entry. Reuse `ShopModuleAccessService`; do not duplicate registration/business eligibility rules.

In `bootstrap/app.php`, attach audience middleware to classified internal ERP routes, including employee self-service routes; attach actor/context capability middleware to `core` and `module` ERP routes; attach `shop.module` only to `module` routes. Excluded public/SuperAdmin/system routes receive no ERP actor middleware. Extend route-coverage tests to assert audience-before-auth and actor/capability-before-binding order now that the classes exist.

For owner routes, always enforce owner guard, company registration, approved status, explicit catalog owner allowlisting, tenant consistency, and record/domain authorization. `SHOP_MODULE_ENFORCEMENT_ENABLED=false` may skip only persisted enabled-state enforcement—not permanent security controls.

- [ ] **Step 6: Centralize stable denial responses**

`ErpAccessResponder` maps only ERP authorization conditions to the approved codes and browser destinations. For owner ERP responses, it passes through `MODULE_STATE_MISSING`, `MODULE_INELIGIBLE`, and `MODULE_DISABLED`, but normalizes the module service's internal `UNKNOWN_MODULE` result to `ERP_ROUTE_NOT_ALLOWED`. Do not change `ShopModuleAccessService` or existing non-workspace middleware response contracts. Cross-shop resources remain 404 and validation/domain responses retain their existing contracts. Never expose exception text.

- [ ] **Step 7: Run Gate B backend tests and commit**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/ErpActorContextTest.php tests/Feature/BusinessScaling/OwnerErpAuthorizationTest.php tests/Feature/BusinessScaling/ShopModuleMiddlewareTest.php tests/Feature/BusinessScaling/BusinessScalingActorBoundaryRegressionTest.php
```

Expected: PASS.

Commit: `feat: resolve scoped ERP actors by route family`

---

### Task 5: Add the disabled-by-default workspace boundary

**Files:**

- Modify: `config/shop_modules.php`
- Modify: `.env.example`
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `app/Http/Middleware/EnsureOwnerErpWorkspaceEnabled.php`
- Create: `routes/shop-owner-erp.php`
- Create: `routes/shop-owner-erp-api.php`
- Create: `app/Http/Controllers/Erp/WorkspaceController.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpRolloutConfigurationTest.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`

- [ ] **Step 1: Write failing feature-flag and route tests**

Assert:

- owner ERP route names and route topology are identical whether `SHOP_OWNER_ERP_WORKSPACE_ENABLED` is false or true;
- when false, the feature middleware returns the same safe 404 before actor resolution or resource binding, and owner navigation state is false;
- when true, `shop-owner.erp.workspace` is reachable by an eligible owner and every catalog-approved route introduced later can pass the feature boundary;
- all owner workspace routes use `/shop-owner/erp/*`, `shop-owner.erp.*`, `web`, feature-boundary, `auth:shop_owner`, audience, actor, and capability middleware;
- owner API routes use `/api/shop-owner/erp/*`, unique `shop-owner.erp.api.*` names, session CSRF behavior, and existing relevant throttles;
- a production boot fails closed when workspace is enabled and module enforcement is disabled.

Use fresh application boots or focused subprocesses to compare both flag values and prove that neither the route collection nor a generated route cache changes with the flag. The flag controls request access, not route registration.

- [ ] **Step 2: Run the tests and confirm RED**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpRolloutConfigurationTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
```

- [ ] **Step 3: Add both flags, stable route registration, and the feature boundary**

Add:

```php
'owner_erp_workspace_enabled' => (bool) env('SHOP_OWNER_ERP_WORKSPACE_ENABLED', false),
```

Always register both owner route files so cached and uncached route topology is identical. Place `EnsureOwnerErpWorkspaceEnabled` first in the owner ERP middleware sequence and return the approved safe 404 while the flag is false. Load the API file through the `web` stack because it uses the owner session and CSRF; do not place it behind ambiguous Sanctum or multi-guard authentication.

At this stage, assert the route middleware prefix through tenant-scoped binding: feature boundary, audience, authentication, actor resolution, owner eligibility/policy, module eligibility/state, then binding. Record the remaining request-flow invariant for Tasks 9-11: Form Request/domain authorization, controller/service execution, actor persistence plus explicit owner-operation audit, then after-commit external effects.

Document that changing `SHOP_OWNER_ERP_WORKSPACE_ENABLED` requires refreshing Laravel's configuration cache with `php artisan config:cache`; it must never require rebuilding the route cache.

- [ ] **Step 4: Add the production invariant**

In `AppServiceProvider`, throw a configuration exception only in production when workspace is true and module enforcement is false. Unit/feature tests may override both config values explicitly.

- [ ] **Step 5: Add the landing controller contract**

`WorkspaceController` renders `ERP/Workspace` with enabled and unavailable modules, reasons, navigation groups with at least one allowed page, and server-generated portal/settings URLs. It reads `ErpActorContext` and `ShopModuleAccessService`; it does not query a client-provided shop ID.

- [ ] **Step 6: Run tests and commit**

Commit: `feat: add guarded owner ERP workspace boundary`

---

### Task 6: Publish typed actor and capability props

**Files:**

- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/types/erp.ts`
- Create: `resources/js/utils/erpCapabilities.ts`
- Create: `resources/js/utils/__tests__/erpCapabilities.test.ts`
- Modify: `tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`

- [ ] **Step 1: Write failing backend prop tests**

For employee and owner ERP routes, assert `auth.erpActor` includes type, ID, name, and owner mode; root props include `ownerMode`, module states/enforcement, derived capabilities, and owner-aware URLs. Assert shop owners receive no wildcard permission and dual sessions expose the route-selected ERP actor.

- [ ] **Step 2: Write failing TypeScript utility tests**

Define:

```ts
export interface ErpCapability {
  allowed: boolean;
  method: string;
  routeName: string;
  url: string | null;
  reason: string | null;
}
```

Test `erpCapabilityKey`, `canUseErpCapability`, and `erpUrl`. The URL resolver must fail closed for a missing/denied capability; it must not construct an employee URL from a prefix.

Treat `allowed` as route-level permission to attempt the operation. It does not authorize a specific record, workflow transition, amount, or tenant resource; server domain and record policies remain authoritative on every request.

- [ ] **Step 3: Run tests and confirm RED**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/InertiaModuleStateShareTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
pnpm run test:frontend -- resources/js/utils/__tests__/erpCapabilities.test.ts
```

- [ ] **Step 4: Share only route-relevant capabilities**

Build capability URLs on the server from paired catalog routes. Use the employee capability key as the canonical client lookup key; in owner mode its value carries the owner route name and owner URL. This is derived from `paired_route`, not a second `owner_actions` policy list.

Replace the shop-owner `permissions: ['*']` fallback with `[]`. Preserve SuperAdmin behavior and employee Spatie permissions.

- [ ] **Step 5: Run tests and commit**

Commit: `feat: share typed ERP actor capabilities`

---

### Task 7: Build the owner-mode shell and single portal entry

**Files:**

- Create: `resources/js/Pages/ERP/Workspace.tsx`
- Create: `resources/js/Pages/ERP/__tests__/Workspace.test.tsx`
- Modify: `resources/js/layout/AppLayout_ERP.tsx`
- Modify: `resources/js/layout/AppHeader_ERP.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/layout/AppSidebar_shopOwner.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_ERP.test.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Create: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`

- [ ] **Step 1: Write failing owner-shell tests**

Assert exactly one **ERP Workspace** link in the owner portal. Assert the ERP header shows owner identity, an Owner mode badge, owner notification/profile/logout URLs, and **Back to Shop Owner Portal**. Assert the landing page shows accessible and unavailable modules with reasons plus **Manage modules**.

Assert the ERP sidebar uses capability URLs in owner mode, retains permission filtering for employees, highlights the active owner URL, and exposes no time-in, personal payslip, employee profile/password, personal leave/overtime, or rider-execution links.

- [ ] **Step 2: Run frontend tests and confirm RED**

Run:

```powershell
pnpm run test:frontend -- resources/js/Pages/ERP/__tests__/Workspace.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx
```

- [ ] **Step 3: Reconcile the existing owner-sidebar work**

Remove the newly duplicated Retail Operations and Repair Operations menus and the older per-module owner entries from the Employee Modules section. Add one flag- and capability-controlled ERP Workspace entry. Preserve unrelated owner portal navigation and current individual-account restrictions.

- [ ] **Step 4: Make the ERP shell actor-aware**

Use `auth.erpActor` rather than `auth.user` for identity. In owner mode, use existing shop-owner dropdown/notification conventions and server URLs; in employee mode preserve current `UserDropdown` and notification selection. Add the portal return action and Owner mode label without forking the layout.

- [ ] **Step 5: Filter navigation from server capabilities**

Add a capability key to existing ERP navigation definitions. Keep the current employee role/permission filters; use capability allow/URL data as the owner-mode branch. Do not rewrite the 2,000-line sidebar into a new navigation framework during this task.

- [ ] **Step 6: Verify accessibility and commit**

Cover keyboard activation, visible focus, mobile open/close, active state, and disabled-module explanations. Run the focused suite again.

Commit: `feat: add owner mode to the shared ERP shell`

---

### Task 8: Extract shared page actions and add read-only owner aliases by matrix wave

**Files:**

- Modify: `routes/web.php`
- Modify: `routes/shop-owner-erp.php`
- Modify: existing page controllers under `app/Http/Controllers/Erp`, `app/Http/Controllers/Api/CRM`, and `app/Http/Controllers/Logistics`
- Create only when a current route is a closure with substantial initial-data logic:
  - `app/Http/Controllers/Erp/FinancePageController.php`
  - `app/Http/Controllers/Erp/HrPageController.php`
  - `app/Http/Controllers/Erp/InventoryPageController.php`
  - `app/Http/Controllers/Erp/ManagerPageController.php`
  - `app/Http/Controllers/Erp/ProcurementPageController.php`
  - `app/Http/Controllers/Erp/RepairPageController.php`
  - `app/Http/Controllers/Erp/StaffPageController.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php`

- [ ] **Step 1: Generate failing page smoke cases from the catalog**

For every owner-capable `GET` page row, authenticate an approved company owner with the correct business type and modules, request the owner alias, and assert the exact real Inertia component from the matrix. Add denial datasets for Individual/status/business/module and direct owner requests to self-service pages.

- [ ] **Step 2: Run the first matrix wave and confirm RED**

Start with core workspace/company dashboard/audit plus read-only CRM and logistics pages. Run:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php
```

- [ ] **Step 3: Extract only closure logic that must be shared**

Move substantial employee page closures into focused controller methods that accept `ErpActorContext`. Both employee and owner declarations call the same method and render the same component/payload. Leave trivial redirects and unrelated routes alone.

Replace direct `Auth::guard('user')`, `Auth::user()`, and default request-user reads only inside owner-capable shared actions. Preserve direct employee reads in excluded self-service actions.

- [ ] **Step 4: Add owner aliases without employee middleware**

Add the owner declaration with its own unique name/URI and only owner ERP middleware. Do not copy employee Spatie permissions, `check.user.business.type`, `manager.staff`, or `shop.isolation` onto the owner alias.

- [ ] **Step 5: Prove tenant-safe reads**

Every initial-data query uses `$context->tenantOwner()->id`. Cross-shop IDs return the same safe 404 as missing IDs. For route-bound records, ensure context middleware precedes binding and use scoped binding or an explicit tenant query. Assert each employee/owner pair has compatible parameter names, nesting, constraints, and binding behavior before pointing both declarations at the shared action.

- [ ] **Step 6: Repeat in bounded waves**

Run and commit each wave separately:

1. core + CRM + logistics reads;
2. HR + finance + manager reads;
3. inventory + procurement reads;
4. retail + repair operational reads.

Suggested commits: `feat: expose owner ERP core CRM logistics reads`, `feat: expose owner ERP HR finance manager reads`, `feat: expose owner ERP inventory procurement reads`, and `feat: expose owner ERP retail repair reads`.

- [ ] **Step 7: Pass Gate C for every row**

Regenerate the matrix and confirm that only tested read aliases are exposed as owner routes. Owner policy remains the explicit catalog decision and may be `allowed` before exposure only when the route has not yet passed Gate C.

---

### Task 9: Make read APIs actor-aware and prepare mutation actions without changing payloads

**Files:**

- Modify: `routes/shop-owner-erp-api.php`
- Modify: `routes/api.php`
- Modify: `routes/hr-api.php`
- Modify: `routes/finance-api.php`
- Modify: `routes/inventory-api.php`
- Modify: `routes/procurement-api.php`
- Modify: `routes/permission-audit-api.php`
- Modify: `routes/shop-owner-api.php`
- Modify: only matrix-listed controller/service/Form Request/policy files
- Modify: `tests/Feature/BusinessScaling/OwnerErpMutationContractTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php`

- [ ] **Step 1: Add generated read-API contract cases and mutation characterization tests**

For every supporting read API, assert its method, owner name/URI, payload shape, stable authorization errors, and cross-shop result. For materially different mutation patterns, add characterization tests around the canonical employee action/service, Form Request, and domain policy, but do not register the owner mutation alias yet. Characterize existing validation rules, error bags/statuses, normalized payload, and employee authorization before refactoring.

- [ ] **Step 2: Run one module wave and confirm RED**

Use the same four waves as Task 8. Do not add all aliases in one patch.

- [ ] **Step 3: Reuse canonical controller actions**

Add owner-namespaced declarations for read APIs pointing to the same action. Refactor canonical mutation actions/services so they can accept the real context later, but leave their owner aliases absent until Tasks 10-11 pass and Task 13 activates the exact matrix row. Keep request validation and response payloads unchanged. Where an existing `shop_owner`-only API already has the correct payload and tenant behavior, pair and reuse it instead of creating another action.

Do not reuse an existing `auth:user,shop_owner` endpoint for workspace traffic. Add an unambiguous owner alias first, then let both declarations call the canonical action.

Audit every owner-capable Form Request for `$this->user()`, `Auth::guard('user')`, employee-only policy calls, route-model assumptions, and custom validators. Make `authorize()` and any actor-sensitive validation consume `ErpActorContext` or an actor-aware domain policy while preserving `rules()`, normalized input, messages, and response shape. Do not globally change Laravel's default request user resolver.

Reject or normalize any client-supplied `shop_id`, owner ID, tenant ID, or equivalent against `$context->tenantOwner()->id`; never use it as the authority for queries or writes.

- [ ] **Step 4: Preserve domain policies**

For approvals, payment, pricing, refunds, payroll, procurement, suspension, upload, and assignment actions, test workflow state, thresholds, maker/checker rules, self-approval, file limits, rate limits, and idempotency. A route capability's `allowed` value must not translate to blanket record authorization.

- [ ] **Step 5: Inventory external effects**

For each mutation row, record whether it sends provider requests, writes files, sends mail, or dispatches jobs. Move those effects to `DB::afterCommit`, an existing queued job/outbox, or add explicit retry/compensation behavior before marking the row owner-allowed.

- [ ] **Step 6: Run and commit each read-API wave**

Run the generated read contract plus the module's existing feature tests after each wave. Keep all owner mutation contract tests out of the passing set until the relevant persistence and audit work is complete; never skip them after their owner route is registered. Use the same four concrete wave names from Task 8 in the commit subject.

---

### Task 10: Add durable owner performer fields only where required

**Files:**

- Create: targeted reversible migrations under `database/migrations` for rows marked `paired_owner_ref`
- Modify: corresponding models, policies, services, controllers, factories, and resources named by those matrix rows
- Create: `tests/Feature/BusinessScaling/OwnerErpActorPersistenceTest.php`
- Modify: existing domain workflow tests for each affected table

- [ ] **Step 1: Write failing persistence tests per table family**

For each proposed owner mutation, assert the exact employee and owner attribution columns. Include a foreign-key collision fixture where a `shop_owners.id` equals an unrelated `users.id`; assert the owner ID is never stored in the user column. For new writes that require performer attribution, test both invalid states: both employee and owner references set, and both references null.

- [ ] **Step 2: Lock employee-subject exclusions**

Keep actions such as employee purchase-request creation (`purchase_requests.requested_by`), employee purchase-order creation where `ordered_by` is semantically the employee orderer, suspension request creation, personal leave/overtime, personal payroll, repairer assignment, and rider execution owner-denied unless the matrix proves the field is only a performer and the approved design explicitly changes it.

- [ ] **Step 3: Reuse existing owner references first**

Examples already present include `approved_by_shop_owner_id`, `rejected_by_shop_owner_id`, owner approval fields on refunds, and polymorphic logistics requester/creator fields. Use them rather than adding a new generic actor abstraction.

- [ ] **Step 4: Add paired columns for true performers**

When the matrix marks a nullable `created_by`, `updated_by`, `approved_by`, or equivalent as a performer, add a nullable constrained `*_shop_owner_id`, update model fillable/relations/resources, and enforce exactly one actor reference for new writes in the canonical domain service/model boundary. Do not add a repository-wide polymorphic actor migration.

Use one migration per bounded domain family so rollback and deployment risk remain reviewable, for example:

```text
2026_08_10_000001_add_shop_owner_performers_to_finance_records.php
2026_08_10_000002_add_shop_owner_performers_to_inventory_records.php
2026_08_10_000003_add_shop_owner_performers_to_retail_repair_records.php
```

Create only the migrations required by reviewed matrix rows; omit empty families.

Before adding a database `CHECK`, audit that table's legacy/system/null rows and verify the exact constraint works on both the test SQLite version and deployed MariaDB version. Add the constraint only for a table where those facts make it safe; otherwise retain nullable compatibility and enforce the invariant for new writes in application code with focused tests.

- [ ] **Step 5: Preserve compatibility**

Existing employee, legacy, imported, and system records and relations remain valid. Owner writes set the owner column and leave employee performer columns null only when schema and consumers allow it. APIs expose actor display without interpreting a null employee ID as unknown.

- [ ] **Step 6: Run migration and persistence tests**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpActorPersistenceTest.php
```

Then run each affected domain suite. Expected: PASS with real owner attribution and no synthetic users.

Commit: `feat: persist shop owner ERP performers`

---

### Task 11: Record exactly one explicit owner operation activity

**Files:**

- Create: `app/Services/ErpAuditService.php`
- Modify: every owner-capable mutation action/service from Tasks 9-10
- Modify: `app/Http/Controllers/ActivityLogController.php`
- Create: `tests/Feature/BusinessScaling/OwnerErpAuditTest.php`
- Modify: `tests/Feature/Manager/ManagerAuditLogsTest.php`

- [ ] **Step 1: Write failing audit tests**

Assert exactly one explicit `owner_operation` activity for a committed create/update/approve/reject/checkout/assign/upload/delete example; zero owner-operation activities for validation failure, policy denial, rollback, or idempotent no-op retry. Assert owner causer, tenant, module keys, concrete route/method/action, operation/correlation IDs, subject, safe changes, bounded related IDs/counts, IP, and bounded user agent.

Assert only duplicate automatic model-event logging for the same owner operation is suppressed inside the callback and is restored after an exception. Unrelated domain, compliance, payment, inventory, or security audit events emitted by the canonical action must remain intact.

- [ ] **Step 2: Run audit tests and confirm RED**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpAuditTest.php tests/Feature/Manager/ManagerAuditLogsTest.php
```

- [ ] **Step 3: Add a focused audit service**

`ErpAuditService` should provide a callback-scoped, model-selective automatic-log suppression helper using the installed Spatie `withoutLogs` behavior and a separate explicit `recordOwnerOperation` method. Never globally disable activity logging or use an unguarded manual disable/enable pair. The explicit record occurs after the mutation inside the same `DB::transaction` and connection.

Allowlist safe properties. Never log raw requests, secrets, tokens, private document paths/bytes, payment secrets, or unrelated personal data. Generate operation IDs server-side; trust incoming correlation IDs only through an explicit trusted-infrastructure condition.

- [ ] **Step 4: Integrate one mutation at a time**

For each owner mutation:

1. begin/reuse the domain transaction;
2. run the mutation with callback-scoped suppression only for automatic records proven to duplicate the explicit owner operation;
3. skip activity when no state changed;
4. write exactly one explicit owner-operation activity while retaining unrelated domain audit events;
5. schedule external effects after commit.

Do not alter employee audit behavior.

- [ ] **Step 5: Extend tenant-safe audit filtering**

Add actor, module, route, action, and correlation filters to the existing activity API while retaining tenant scope and safe serialization.

- [ ] **Step 6: Run tests and commit**

Commit: `feat: audit owner ERP operations atomically`

---

### Task 12: Replace hard-coded employee URLs in shared components

**Files:**

- Modify: `resources/js/utils/erpCapabilities.ts`
- Modify: every owner-capable page and API helper listed in `docs/architecture/shop-owner-erp-route-matrix.md`
- Modify: corresponding existing page/component tests
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/layout/AppHeader_ERP.tsx`

- [ ] **Step 1: Add failing page-network tests per matrix wave**

Render the real shared component once as an employee and once as an owner. Assert initial reads, polling and refetch timers, React Query/SWR refreshes, WebSocket/subscription handlers, exports, downloads, print/preview flows, notifications, and mutation calls use the URL supplied for that actor. For mutations not yet activated, assert owner controls and every associated background call remain absent while employee behavior stays unchanged. Task 13 adds the owner-enabled mutation cases after the route is safe.

- [ ] **Step 2: Run the first frontend wave and confirm RED**

Run the wave's existing and newly added tests with the matching exact directories:

```powershell
pnpm run test:frontend -- resources/js/Pages/ERP/CRM resources/js/Pages/ERP/Logistics
pnpm run test:frontend -- resources/js/Pages/ERP/HR resources/js/Pages/ERP/Finance resources/js/Pages/ERP/Manager
pnpm run test:frontend -- resources/js/Pages/ERP/inventory resources/js/Pages/ERP/Procurement
pnpm run test:frontend -- resources/js/Pages/ERP/cashier resources/js/Pages/ERP/STAFF resources/js/Pages/ERP/repairer
```

- [ ] **Step 3: Resolve URLs from capabilities**

Inventory all URL construction in each matrix-listed component before editing: direct fetch/axios calls, helpers, polling intervals, retry/refetch callbacks, WebSocket channels, export/download links, print windows, preview URLs, and notification actions. Replace hard-coded employee endpoints only in owner-capable shared components. Pass the canonical method/employee route key to `erpUrl`; use the server-provided concrete URL. Preserve payloads and normal employee behavior.

- [ ] **Step 4: Remove owner-inapplicable behavior**

Hide employee self-service buttons/tabs/links and do not start their effects in owner mode. Add accessible authorization, validation, timeout, retry, loading, and success states for owner mutations.

- [ ] **Step 5: Run and commit each frontend wave**

Run affected Vitest files after each matrix wave. Use commit subjects `feat: make owner ERP CRM logistics UI actor-aware`, `feat: make owner ERP HR finance manager UI actor-aware`, `feat: make owner ERP inventory procurement UI actor-aware`, and `feat: make owner ERP retail repair UI actor-aware`.

---

### Task 13: Pass the mutation gate and regenerate the final matrix

**Files:**

- Modify: `config/shop_modules.php`
- Modify: matrix-listed owner route declarations, canonical actions/services, Form Requests, policies, and shared components
- Regenerate: `docs/architecture/shop-owner-erp-route-matrix.md`
- Modify: `tests/Feature/BusinessScaling/OwnerErpMutationContractTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Modify: affected domain and frontend contract tests

- [ ] **Step 1: Rank completed mutation candidates**

Group only technically complete candidates by catalog `risk_tier`: `normal`, then `sensitive`, then `financial`. Within a tier, keep the four domain waves bounded and do not combine unrelated high-impact workflows. A candidate is complete only when its supporting APIs, domain authorization tests, tenant checks, Form Request audit, persistence, explicit owner-operation audit, external-effect handling, and prepared frontend URL behavior pass.

- [ ] **Step 2: Activate one bounded wave at a time**

For each reviewed candidate, retain or change the explicit `owner_access` policy in `config/shop_modules.php`, add the paired owner route, and add the owner-enabled component/capability test in the same patch. Route exposure is derived from the loaded paired route; do not maintain a second status field. Leave every incomplete row explicitly owner-denied with a stable reason, and never infer access from a navigation group or `Shop Owner` label in legacy middleware.

- [ ] **Step 3: Run contracts and affected domain tests after each wave**

Run:

```powershell
php artisan test tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/BusinessScaling/OwnerErpMutationContractTest.php tests/Feature/BusinessScaling/OwnerErpActorPersistenceTest.php tests/Feature/BusinessScaling/OwnerErpAuditTest.php tests/Feature/BusinessScaling/OwnerErpTenantIsolationTest.php
```

Also run the affected domain suite and exact frontend component tests. Stop the wave on any policy, persistence, audit, tenant, payload, or background-request regression; do not continue into the next risk tier.

- [ ] **Step 4: Regenerate and diff the matrix after each wave**

Run:

```powershell
php artisan erp:route-matrix --write
git diff -- docs/architecture/shop-owner-erp-route-matrix.md
```

Review each new `Owner policy = allowed` and derived owner-exposure row against Gate D evidence, including its risk tier and route-parameter compatibility.

- [ ] **Step 5: Run catalog consistency and commit the wave**

Run:

```powershell
php artisan test tests/Unit/BusinessScaling/ErpRouteCatalogTest.php tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php tests/Feature/BusinessScaling/ErpRouteMatrixCommandTest.php
```

Use a scoped commit subject naming the risk/domain wave. After the last approved wave, commit the final generated inventory as `feat: finalize owner ERP capability allowlist`.

---

### Task 14: Complete sequential review, verification, and rollout evidence

**Files:**

- Modify only if behavior changed: `docs/superpowers/specs/2026-08-10-shop-owner-erp-workspace-design.md`
- Modify only for durable lessons: `docs/ai-learning-log.md`
- Regenerate only through Vite: `public/build/**`

- [ ] **Step 1: Run the required simplification review**

Apply `@ponytail:ponytail` and `@karpathy-guidelines` sequentially. Remove duplicated policy logic, speculative actor abstractions, unnecessary data wrappers, and only dead code created by this implementation. Confirm the implementation reuses `ShopModuleAccessService`, existing controllers/services/requests/policies, existing dropdowns, and current module state types where appropriate.

- [ ] **Step 2: Run Standards, Spec, and risk reviews sequentially**

Check repository conventions, then both approved designs, then security/authorization/data-integrity risks. Explicitly inspect:

- always-registered route topology, feature-middleware order, and cached/uncached behavior;
- dual sessions, owner login redirect, and JSON `ERP_AUTH_REQUIRED` behavior;
- owner/employee foreign-key separation;
- actor-sensitive Form Requests and client-supplied tenant IDs;
- tenant-scoped binding and nested resources;
- maker/checker and approval thresholds;
- payment/upload/idempotency/external-effect behavior;
- no wildcard owner permissions;
- no self-service UI, polling/refetch, WebSocket, export/download, print/preview, or notification calls in owner mode;
- no new multi-guard workspace routes.

- [ ] **Step 3: Run narrow backend suites**

Run:

```powershell
php artisan test tests/Unit/BusinessScaling
php artisan test tests/Feature/BusinessScaling
```

Then run the existing domain suites touched by each matrix wave. Expected: PASS; record any pre-existing warning separately.

- [ ] **Step 4: Run the frontend suite**

Run:

```powershell
pnpm run test:frontend
```

Expected: PASS.

- [ ] **Step 5: Build once and inspect generated output**

Run:

```powershell
pnpm run build
```

Expected: Vite exits 0. Do not claim lint or standalone TypeScript checking because the repository has no committed scripts for them. Review `public/build/manifest.json` and generated hash churn separately from source changes.

- [ ] **Step 6: Run final Laravel and diff gates**

Run:

```powershell
composer test
git diff --check
git status --short
```

Expected: tests pass, no whitespace errors, and only intended source/docs plus reviewed build artifacts remain.

- [ ] **Step 7: Perform staging rollout in the specified order**

1. deploy schema and code with workspace false;
2. run `php artisan config:cache` and verify owner ERP routes are registered but return the safe feature-boundary 404;
3. verify module-state backfill, catalog policy, generated exposure matrix, and cached/uncached route-topology equivalence;
4. enable module enforcement in staging and run `php artisan config:cache`;
5. enable workspace in staging and run `php artisan config:cache` without rebuilding the route cache;
6. verify approved `both`, retail-only, repair-only, approved Individual denial, pending/rejected/suspended denial, unauthenticated browser/JSON behavior, dual sessions, cross-shop IDs, disabled modules, background requests, and an employee;
7. enable module enforcement in production and run `php artisan config:cache`;
8. after every intended normal, sensitive, and financial mutation wave has its recorded Gate D evidence, enable workspace in production and run `php artisan config:cache`.

Rollback disables only `SHOP_OWNER_ERP_WORKSPACE_ENABLED` and refreshes configuration with `php artisan config:cache`. Routes remain registered behind the safe feature boundary, so rollback does not rebuild route caches. It must not disable employee module enforcement, delete data, revert audit history, or require synthetic-user cleanup.

- [ ] **Step 8: Record evidence and commit**

Document exact commands/results in the delivery summary. Add to `docs/ai-learning-log.md` only if the implementation produced a durable, reusable repository lesson.

Commit: `chore: verify shop owner ERP workspace rollout`

---

## Definition of done

- Gate A-E evidence is recorded and fresh.
- Every loaded internal application route is classified both ways.
- Every catalog row has explicit owner policy/domain/risk metadata; owner exposure and employee middleware rules are derived from loaded routes.
- Every allowed owner page and supporting API has a unique owner route and uses the shared implementation.
- Every owner route is always registered, and the disabled workspace fails closed through feature middleware under cached and uncached configuration.
- Every owner read and write derives tenant authority only from `ErpActorContext::tenantOwner()`; client tenant IDs are never authoritative.
- Every owner-capable Form Request preserves its validation/payload contract and authorizes through the resolved actor/domain policy.
- Capability `allowed` means route-level ability to attempt; record and workflow authorization remains server-side.
- Every owner mutation has valid domain authorization, durable actor persistence, exactly one atomic explicit owner-operation activity without suppressing unrelated domain audit events, and defined retry/external-effect behavior.
- Employee self-service remains employee-only in routes, components, and background calls.
- The owner portal has one ERP Workspace entry; the shared shell is owner-aware and accessible.
- Both rollout flags fail safe, with production module enforcement enabled before workspace exposure and configuration cache refreshed after flag changes without route-cache rebuilds.
- Focused and broad tests, the Vite build, and `git diff --check` pass with recorded output.
