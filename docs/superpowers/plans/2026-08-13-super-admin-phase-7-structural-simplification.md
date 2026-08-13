# Super Admin Phase 7 Structural Simplification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Execution discipline:** Implement route-mutating tasks strictly sequentially. Subagents may assist with read-only review, dependency scans, test analysis, or dead-code inspection, but no parallel implementation may edit `routes/web.php`, route names, frontend callers, or `PhaseSevenStructuralBoundaryTest.php`.

**Status:** IMPLEMENTATION COMPLETE - AUTHENTICATED BROWSER VERIFICATION PENDING

**Goal:** Remove duplicate privileged runtime ownership after Phases 0-6, make `/admin` the single canonical mutation surface, split the monolithic controller into focused owners, and retire superseded document and registration paths without changing secured behavior.

**Architecture:** Preserve the existing `super_admin` guard, fixed capability middleware, MFA/recent-reauthentication requirements, workflow services, authoritative `PrivilegedAudit`, immutable `shop_documents` lifecycle, and separate HR/shop expiry commands. Introduce no new domain abstraction: move HTTP orchestration into focused controllers, reuse existing services, migrate every first-party caller to canonical `/admin` route names, retain only explicitly listed read-only redirects, and delete handlers only after route/reference tests prove they are orphaned.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Vitest, PHPUnit, Vite 7, pnpm.

**Design authority:** `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`, especially Sections 6, 16, 18, 22 (Phase 7), 23, and 24.

---

## Implementation Contract

### Acceptance criteria

1. Every privileged mutation has exactly one registered route, one focused controller owner, the existing capability middleware, and recent reauthentication wherever already required.
2. `/admin` is the canonical privileged prefix. No first-party frontend, notification, controller, or test calls a `/superAdmin` mutation.
3. Temporary compatibility consists only of named `GET|HEAD` redirects to canonical pages. Redirects preserve relevant path parameters and query strings; no compatibility closure performs a query or mutation.
4. `SuperAdminController`, `SuperAdminUserManagementController`, and `ShopRegistrationController` are deleted after their live behavior moves to focused owners or is proven obsolete.
5. Every privileged runtime responsibility has exactly one authoritative owner; no semantic operation is duplicated across controllers or route groups. Document creation, renewal submission, registration review, renewal review, and private-byte access remain intentionally separate focused responsibilities.
6. Legacy `/api/shop/register`, `/api/shop/register-full`, and `/shop/register-full` writes are removed. `/shop/register` may remain only as a safe GET redirect to the canonical registration form.
7. No current or historical `ShopDocument` row/file is overwritten or deleted. DTI/SEC distinction, legacy DTI/SEC continuity, stable supporting slots, reviewer verification, and renewal promotion remain unchanged.
8. `hr:check-document-expiry` remains HR-only and `shop-documents:send-expiry-reminders` remains shop-compliance-only. Neither command calls the other or shares a generic expiry service.
9. Runtime privileged operations write only through `PrivilegedAudit`. The bounded legacy audit importer remains available as historical reconciliation tooling and is not mistaken for a runtime writer.
10. No schema migration, permission UI, generic repository, base-controller hierarchy, route compatibility package, or new dependency is introduced.
11. Controller extraction must not change existing `403`, `404`, `409`, `422`, or sanitized `500` behavior solely because of route-model binding. Service-owned resolution remains service-owned where locking, object scope, or conflict semantics require it.
12. Every commit is green. A failing test is run to prove the intended red state, then committed only with the implementation that makes its relevant scope pass.
13. Whenever a task changes route names consumed by first-party frontend code, regenerate `resources/js/ziggy.js` before that task's frontend verification and commit.

### Canonical route contract

| Capability | Canonical method and URI | Canonical route name | Owner |
|---|---|---|---|
| Administrator list | `GET /admin/administrators` | `admin.administrators.index` | `AdministratorManagementController@index` |
| Administrator create page | `GET /admin/administrators/create` | `admin.administrators.create` | `AdministratorManagementController@create` |
| Administrator invitation | `POST /admin/administrators` | `admin.administrators.store` | `AdministratorManagementController@store` |
| Administrator lifecycle/security | Existing verbs under `/admin/administrators/{administrator}/*` | `admin.administrators.*` | `AdministratorManagementController` |
| Registration queue | `GET /admin/registrations` | `admin.registrations.index` | existing focused registration-review controller |
| Registration decisions | `POST /admin/registrations/{shopOwner}/approve|reject` | `admin.registrations.approve|reject` | existing focused registration-review controller |
| Registered shops | `GET /admin/shops` | `admin.shops.index` | `RegisteredShopController@index` |
| Registered-shop detail | `GET /admin/shops/{shopOwner}` | `admin.shops.show` | `RegisteredShopController@show` |
| Shop lifecycle | `suspend`, `reactivate`, `archive`, and `restore` under `/admin/shops/{shopOwner}/*` | `admin.shops.*` | `RegisteredShopController` |
| Users | `GET /admin/users` | `admin.users.index` | `UserInterventionController@index` |
| User lifecycle/private ID | `suspend`, `reactivate`, `archive`, and `restore` under `/admin/users/{user}/*` | `admin.users.*` | user/private-document focused owners |
| Flagged accounts | `GET /admin/flagged-accounts` | `admin.flagged-accounts.index` | existing `FlaggedAccountsController` |
| Flag decisions | `POST /admin/flagged-accounts/{id}/*` | `admin.flagged-accounts.*` | existing `FlaggedAccountsController` |
| Subscription/plan page | `GET /admin/subscriptions` | `admin.subscriptions.index` | `SubscriptionManagementController@index` |
| Plan mutations | Existing verbs under `/admin/plans/{premiumPlan?}` | `admin.plans.*` | `PremiumPlanController` |
| Subscription interventions | Existing canonical cancel/correction/refund routes | existing `admin.subscriptions.*` / payment route names | existing `SubscriptionInterventionController` |
| Document renewals | Existing `/admin/document-renewals*` | existing `admin.document-renewals.*` | existing renewal controller |
| Private documents | Existing scoped private routes | existing names | `PrivateSensitiveDocumentController` |
| Monitoring/notifications/audit/security | Existing canonical `/admin/*` paths | existing names | existing focused owners |

Do not rename already focused Phase 0-6 routes merely for aesthetic consistency. The table changes only routes currently owned by the monolith, duplicated under `/superAdmin`, or explicitly named as canonical in the frozen design.

The administrator `activate` transition is real and remains canonical. By contrast, the current shop/user `activate` handlers only forward to `reactivate`; remove those two duplicate handlers/routes and migrate callers to `admin.shops.reactivate` and `admin.users.reactivate`.

### Temporary safe GET compatibility

After all first-party callers move, keep one compact compatibility group for these read-only aliases only:

```text
/admin/admin                            -> /admin/administrators
/admin/create-admin                     -> /admin/administrators/create
/admin/shop-owner-registration-view     -> /admin/registrations
/admin/registered-shops                 -> /admin/shops
/admin/shops/{id}/details               -> /admin/shops/{id}
/admin/user-management                  -> /admin/users
/admin/subscription-management          -> /admin/subscriptions
/admin/data-reports                     -> /admin/audit

/superAdmin/super-admin-user-management -> /admin/users
/superAdmin/shop-owner-registration-view -> /admin/registrations
/superAdmin/flagged-accounts             -> /admin/flagged-accounts
/superAdmin/system-monitoring-dashboard  -> /admin/system-monitoring
/superAdmin/notification-communication-tools -> /admin/notifications
/superAdmin/data-report-access           -> /admin/audit

/shop/register                          -> /shop-owner-register
```

Each alias must use the same authentication/capability boundary as its target when privileged. It must be `GET|HEAD` only, preserve the query string, and redirect without loading domain data. Phase 8 owns evidence-based removal of these final aliases.

Compatibility actions are behavior-only redirects: they must not resolve models, call a domain controller/service, transform payloads, or make business decisions. Before declaring an alias unused, inspect both source references and persisted `notifications.action_url` values. Phase 7 records persisted-link counts/categories but does not rewrite historical notification URLs.

### Explicit non-goals

- No route versioning layer or configurable alias registry.
- No generic `DocumentController`, `DocumentExpiryService`, or shared HR/shop expiry abstraction.
- No rewrite of Phase 0-6 services, transaction locks, audit semantics, notification delivery, or UI design.
- No pagination/index/performance expansion; Phase 8 owns measured scale work.
- No deletion of legacy audit rows, import provenance, historical document rows/files, or reconciliation commands.
- No rename of the `super_admin` guard/model or the React `superAdmin` page directory merely for casing/style.

### Execution checkpoints

```text
Phase 7A — ownership extraction
Tasks 1-5, sequential and green after each commit
        ↓
Ownership checkpoint
one canonical mutation + security/response parity + workflow tests green
        ↓
Phase 7B — retirement
Tasks 6-9, sequential deletion, evidence, and final verification
```

These are diagnostic checkpoints within one roadmap phase, not separate product phases.

---

## Current Baseline to Preserve

- Branch baseline: Phase 6 implementation commit `a8f003d20` on a clean worktree at plan creation.
- `SuperAdminController` is over 1,000 lines and owns administrators, registered shops, users, subscriptions, plans, and lifecycle response mapping.
- Two `/superAdmin` groups exist in `routes/web.php`; the earlier group still owns flagged-account mutations while the later group repeats read routes.
- Canonical Phase 6 renewal routes already live under `/admin/document-renewals` and the owner renewal route is unique.
- `ShopRegistrationController` exposes three legacy POST endpoints that now only reject the obsolete document contract; there are no first-party frontend callers.
- The canonical registration form submits through `route('shop-owner.register')` to `ShopOwnerAuthController`.
- Runtime privileged writers use `PrivilegedAudit`; `ImportLegacyPrivilegedAudit` and `LegacyPrivilegedAuditMapper` are reconciliation readers/importers and must remain.
- `CheckDocumentExpiry` owns `EmployeeDocument` state under `hr:check-document-expiry`. `SendShopDocumentExpiryReminders` owns shop compliance under `shop-documents:send-expiry-reminders`; only the latter is scheduled in `routes/console.php`.
- `resources/js/layout/layout/` is an isolated duplicate layout tree with no imports from the active application graph. Reconfirm immediately before deletion.

---

## File Ownership Map

### Create

- `app/Http/Controllers/superAdmin/AdministratorManagementController.php` — administrator pages, invitation orchestration, lifecycle/security responses.
- `app/Http/Controllers/superAdmin/RegisteredShopController.php` — registered-shop list/detail and shop lifecycle endpoints.
- `app/Http/Controllers/superAdmin/UserInterventionController.php` — canonical user list and user lifecycle endpoints.
- `app/Http/Controllers/superAdmin/SubscriptionManagementController.php` — read-only subscription/plan management payload.
- `app/Http/Controllers/superAdmin/PremiumPlanController.php` — premium-plan mutations through `PremiumPlanManagementService`.
- `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php` — canonical ownership, legacy alias, mutation uniqueness, dead-handler, audit, and expiry-boundary contract.

### Create only if the measured extraction justifies it

- `app/Http/Controllers/Concerns/RespondsToAccountLifecycle.php` — shared HTTP-only response mapping, but only if the two extracted controllers would otherwise contain substantial identical mapping. Delete/omit it during ponytail review if it merely saves a small catch block. It may contain no queries, authorization, mutations, audits, or shop/user business knowledge.

### Modify

- `routes/web.php` — canonical route map, one read-only compatibility group, legacy registration-write removal.
- `app/Services/AdministratorIdentityService.php` — absorb the already-transactional invitation creation/resend workflows now stranded in the monolith.
- `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php` — route-model-bound canonical parameters/redirect names only; preserve decision service ownership.
- `app/Http/Controllers/superAdmin/FlaggedAccountsController.php` — no business rewrite; only canonical response/link assumptions if present.
- `app/Http/Controllers/PrivateSensitiveDocumentController.php` — only route-name references required by canonical shop/user pages; preserve private streaming and mandatory audit behavior.
- `app/Http/Controllers/ShopOwner/CustomerReviewController.php` — canonical flagged-account notification/action URL.
- `app/Http/Controllers/Api/CRM/CRMReviewController.php` — canonical flagged-account notification/action URL.
- `resources/js/layout/AppSidebar.tsx` — canonical route names and URLs.
- `resources/js/layout/__tests__/AppSidebar.test.tsx` — canonical navigation expectations for both roles.
- `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx` — canonical flagged-account link and lifecycle endpoints.
- `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx` — canonical decision endpoints.
- `resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx` — canonical endpoint assertions.
- `resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx` — canonical user route assertions.
- `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx` — canonical shop detail/lifecycle endpoints if hard-coded.
- `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx` — canonical plan/subscription endpoint names or URLs.
- `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx` — canonical administrator endpoints.
- `resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx` — canonical administrator store/index routes.
- `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx` — canonical registration decision endpoints.
- `resources/js/ziggy.js` — regenerate after each frontend-consumed route-name change and once at final verification; never hand-edit.
- `tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php`
- `tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php`
- `tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php`
- `tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php`
- `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php`
- `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- `tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php`
- `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`
- `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`
- `tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php`
- `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- `tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php`
- `tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php`
- `tests/Feature/Reports/ShopAndCustomerReportFlowTest.php`
- `tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php` — migrate location policy assertions to the canonical registration endpoint/service boundary rather than preserving retired POSTs.
- `tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php` — same canonical migration.
- `tests/Feature/ShopDocuments/LegacyRegistrationDocumentContractTest.php` — invert from “registered but rejects” to “legacy mutations absent; canonical contract remains.”
- `tests/Feature/AdminPremiumPlanManagementTest.php`
- `docs/runbooks/super-admin-operations.md` — final route/controller/scheduled-command ownership and safe alias inventory.
- `docs/ai-learning-log.md` — create only if execution reveals a durable repository lesson.

### Delete after reference checks pass

- `app/Http/Controllers/SuperAdminController.php`
- `app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php`
- `app/Http/Controllers/ShopRegistrationController.php`
- `resources/js/layout/layout/AppHeader.tsx`
- `resources/js/layout/layout/AppHeader_shopOwner.tsx`
- `resources/js/layout/layout/AppLayout.tsx`
- `resources/js/layout/layout/AppLayout_shopOwner.tsx`
- `resources/js/layout/layout/AppSidebar.tsx`
- `resources/js/layout/layout/AppSidebar_shopOwner.tsx`
- `resources/js/layout/layout/Backdrop.tsx`
- `resources/js/layout/layout/SidebarWidget.tsx`

Do not delete a listed file if a fresh import/route/runtime inspection finds an external caller. Record the reference, migrate it in the owning task, then repeat the proof before deletion.

---

## Task 1: Freeze Route and Ownership Boundaries with Failing Tests

**Files:**
- Create: `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php`

- [x] **Step 1: Capture the pre-change route/controller inventory**

Run and save concise evidence in the execution notes:

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
rg -n "^\s*public function " app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php app/Http/Controllers/ShopRegistrationController.php
rg -n "AuditLog::create|activity\(\)" app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin app/Services
```

Record route counts, legacy mutation routes, monolith public-method count, and privileged legacy-writer count. Do not claim performance improvement; this phase measures ownership/complexity only.

- [x] **Step 2: Add passing baseline characterization helpers/tests**

Create reusable route assertions and characterize the current middleware, response statuses, audit side effects, document immutability, and distinct command signatures without asserting future owners yet. Prefer route/action/middleware and behavioral outcomes over parsing implementation text.

- [x] **Step 3: Add only the first failing administrator-owner slice**

Assert the canonical administrator routes from Task 2, their exact methods/URIs/actions/middleware, and mutation uniqueness. Include existing `privileged.recent`, lower-Admin denial, self-management, and final-Super-Admin behavior. Do not add future Task 3-7 ownership assertions yet; expand this same test file immediately before each owning implementation.

- [x] **Step 4: Add compatibility redirect parity tests**

For the aliases introduced by each task, test target-equivalent behavior:

```text
allowed Admin/Super Admin -> redirect
insufficient capability -> 403
unauthenticated -> canonical authentication behavior
suspended/inactive/setup/not-MFA -> same denial boundary as target
?page=3&status=suspended -> Location preserves both query values
/admin/shops/123/details -> /admin/shops/123
```

Also assert each compatibility action is a redirect closure/action rather than a domain controller, and has only `GET|HEAD`. These tests verify behavior and route metadata; they must not inspect closure source text.

- [x] **Step 5: Keep source-pattern checks as execution evidence, not brittle PHPUnit contracts**

Critical functional tests must prove authorization, state, audit records, notifications, files, and error semantics. Use the final `rg` scans to detect bypasses such as direct legacy audit writes or retired controllers. Do not make PHPUnit assert internal service names or assume how `PrivilegedAudit` persists its own ledger.

- [x] **Step 6: Run the first red administrator slice**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
```

Expected: baseline characterizations pass and only the new Task 2 administrator ownership assertions fail for the expected old route/controller names.

- [x] **Step 7: Keep the expected-red changes local and continue directly to Task 2**

Do not commit the failing tests alone. Task 2 must make this slice green, rerun it, and commit the tests with the administrator implementation. For Tasks 3-7, repeat the same red-then-green pattern one ownership slice at a time so every commit remains bisectable and green.

---

## Task 2: Extract Administrator Management into One Focused Owner

**Files:**
- Create: `app/Http/Controllers/superAdmin/AdministratorManagementController.php`
- Modify: `app/Services/AdministratorIdentityService.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx`
- Modify: `resources/js/ziggy.js`
- Modify: `tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php`

- [x] **Step 1: Add failing canonical administrator route tests**

Cover index/create/store, setup resend, suspend/deactivate/activate, role update, and MFA reset under `/admin/administrators`. Assert lower Admin receives `403` for management actions, self-mutation protections remain, and final-active-Super-Admin invariants remain unchanged.

- [x] **Step 2: Move invitation transactions into the existing identity service**

Add narrowly named `invite()` and `resendSetupInvitation()` methods to `AdministratorIdentityService`. Move the existing lock/token/audit/after-commit delivery workflow byte-for-behavior, keeping server-generated password/token material, transaction boundaries, idempotency/state validation, failure sanitization, and final-Super-Admin safeguards. Do not create a second invitation service.

- [x] **Step 3: Create the focused controller**

Move the administrator list/create pages and thin HTTP orchestration to `AdministratorManagementController`. Inject only `AdministratorIdentityService` and `PrivilegedFailureResponse`; the controller must not issue tokens, write audits, dispatch mail, or open transactions directly.

- [x] **Step 4: Register canonical routes and migrate UI callers**

Register only the canonical administrator mutations. Temporarily keep old administrator GET paths as query-preserving redirects; do not keep old mutation aliases. Update page submissions, redirects, and tests to canonical names.

- [x] **Step 5: Regenerate frontend route metadata**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
```

Confirm `admin.administrators.*` is present and removed mutation names are absent before running frontend or committing.

- [x] **Step 6: Verify administrator behavior**

```powershell
php artisan test tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
```

Expected: PASS; invitation delivery remains after commit, self/final-Super-Admin protections remain enforced, and only canonical mutations exist.

- [x] **Step 7: Commit administrator extraction with its now-green boundary tests**

```powershell
git add -- app/Http/Controllers/superAdmin/AdministratorManagementController.php app/Services/AdministratorIdentityService.php routes/web.php resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx resources/js/ziggy.js tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
git commit -m "refactor: isolate administrator management"
```

---

## Task 3: Extract Registered-Shop and User Intervention Owners

**Files:**
- Create if justified: `app/Http/Controllers/Concerns/RespondsToAccountLifecycle.php`
- Create: `app/Http/Controllers/superAdmin/RegisteredShopController.php`
- Create: `app/Http/Controllers/superAdmin/UserInterventionController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx`
- Modify: `resources/js/ziggy.js`
- Modify: `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php`

- [x] **Step 1: Add the failing shop/user owner slice and characterize both user payloads**

Expand `PhaseSevenStructuralBoundaryTest` with only the Task 3 canonical shop/user routes, mutation uniqueness, redirect parity, and owner assertions; run them to confirm the expected old-owner failures. Also write behavioral tests for the actual approved user scope, filters, pagination shape, employee relation, lifecycle fields, private-ID URL, and existing error statuses. Use the current active sidebar page as the contract; do not merge incompatible `SuperAdminController::showUserManagement()` and `SuperAdminUserManagementController::index()` payloads by unioning every field.

- [x] **Step 2: Measure lifecycle response duplication before extracting a concern**

Compare the final shop/user controller mappings after outlining both. Create `RespondsToAccountLifecycle` only if it removes substantial identical success/not-found/conflict/validation/unexpected HTTP code. If it saves only a small catch block, keep the mapping local and omit the concern. If created, it may call `PrivilegedFailureResponse` but must not query models, authorize capabilities, mutate state, audit, or know whether the target is a shop or user.

- [x] **Step 3: Create the user owner**

Move the selected canonical list query and user lifecycle orchestration into `UserInterventionController`. Keep mutations delegated to `AccountLifecycleService`; use route model binding only where it does not bypass the service's canonical locking/not-found behavior.

- [x] **Step 4: Create the registered-shop owner**

Move registered-shop list/detail and lifecycle orchestration into `RegisteredShopController`. Preserve private document URLs through `admin.shop-documents.show`, lifecycle/archive semantics, and the existing lightweight-list/detail-on-demand boundary. Do not add Phase 8 pagination or query tuning here.

For both controllers, preserve existing `403`, `404`, `409`, `422`, and sanitized `500` outcomes. Keep target-ID resolution inside `AccountLifecycleService` wherever eager route-model binding would bypass its lock, scope, not-found, or conflict handling.

- [x] **Step 5: Register canonical routes and migrate callers**

Move GET pages to `/admin/users` and `/admin/shops`; keep the existing semantic mutation endpoints beneath those resources. Update all frontend links and tests. Old page/detail GETs become redirects only after direct callers are gone.

- [x] **Step 6: Regenerate frontend route metadata**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
```

Confirm canonical shop/user names exist and retired `activate` mutation aliases are absent.

- [x] **Step 7: Verify lifecycle, private access, and UI contracts**

```powershell
php artisan test tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx
```

Expected: PASS; lifecycle state/audit behavior and private document authorization are unchanged.

- [x] **Step 8: Run the ponytail decision and commit account-owner extraction**

Delete/omit `RespondsToAccountLifecycle` if the completed controllers do not demonstrate meaningful identical HTTP-only code. Then stage the required files; conditionally stage the concern only when it exists:

```powershell
git add -- app/Http/Controllers/superAdmin/RegisteredShopController.php app/Http/Controllers/superAdmin/UserInterventionController.php routes/web.php resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx resources/js/ziggy.js tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
if (Test-Path 'app/Http/Controllers/Concerns/RespondsToAccountLifecycle.php') { git add -- app/Http/Controllers/Concerns/RespondsToAccountLifecycle.php }
git commit -m "refactor: isolate privileged account owners"
```

---

## Task 4: Extract Subscription Read and Premium-Plan Mutation Owners

**Files:**
- Create: `app/Http/Controllers/superAdmin/SubscriptionManagementController.php`
- Create: `app/Http/Controllers/superAdmin/PremiumPlanController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`
- Modify: `resources/js/ziggy.js`
- Modify: `tests/Feature/AdminPremiumPlanManagementTest.php`
- Modify: `tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php`

- [x] **Step 1: Add failing billing-owner route tests**

Assert `/admin/subscriptions` is read-only page ownership, `/admin/plans*` mutations use `PremiumPlanController`, and provider-backed cancellation/refund/correction remain owned by `SubscriptionInterventionController`. Assert no direct plan swap or pseudo-refund route returns.

- [x] **Step 2: Move the existing subscription page query intact**

Move `showSubscriptionManagement()` to `SubscriptionManagementController@index` without changing ledger interpretation, provider status, refund presentation, cancellation metadata, or query strategy. Phase 8 owns measured query optimization.

- [x] **Step 3: Move plan HTTP orchestration intact**

Move create/update/archive/reactivate methods to `PremiumPlanController`, retaining Form Requests, `PremiumPlanManagementService`, `PrivilegedFailureResponse`, capability middleware, audits, and redirects to `admin.subscriptions.index`.

- [x] **Step 4: Migrate UI and test callers**

Replace old `subscription-management` and `premium-plans` route names/URLs with canonical names. Keep `/admin/subscription-management` as GET redirect only; no old plan mutation route remains.

- [x] **Step 5: Regenerate frontend route metadata**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
```

Confirm `admin.subscriptions.index` and `admin.plans.*` exist and retired plan mutation names are absent.

- [x] **Step 6: Verify billing containment**

```powershell
php artisan test tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php
```

Expected: PASS; authoritative payment/refund history and provider intervention ownership are unchanged.

- [x] **Step 7: Commit billing controller extraction**

```powershell
git add -- app/Http/Controllers/superAdmin/SubscriptionManagementController.php app/Http/Controllers/superAdmin/PremiumPlanController.php routes/web.php resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx resources/js/ziggy.js tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
git commit -m "refactor: isolate privileged billing controllers"
```

---

## Task 5: Canonicalize Registration and Flagged-Account Routes

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`
- Modify: `app/Http/Controllers/ShopOwner/CustomerReviewController.php`
- Modify: `app/Http/Controllers/Api/CRM/CRMReviewController.php`
- Modify: `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx`
- Modify: `resources/js/ziggy.js`
- Modify: `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php`
- Modify: `tests/Feature/Reports/ShopAndCustomerReportFlowTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`

- [x] **Step 1: Add the failing registration/flag ownership slice**

Expand `PhaseSevenStructuralBoundaryTest` and the workflow tests with only the Task 5 canonical route/action/middleware, redirect parity, mutation uniqueness, and old-mutation absence assertions. Run the focused tests and confirm failures identify the current `/superAdmin` ownership rather than unrelated workflow behavior.

- [x] **Step 2: Register canonical registration routes**

Map queue and decisions to `admin.registrations.index|approve|reject`, preserving `review_registrations`, scoped private access, decision-service transactions, verified document metadata, idempotency, and `409` conflicts. Do not merge registration review and approved-shop renewal review.

- [x] **Step 3: Register canonical flagged-account routes**

Move GET and POST routes under `/admin/flagged-accounts`, preserving `moderate_reports`, `FlaggedAccountModerationService`, suspension provenance, appeal creation, audit, and notification behavior.

- [x] **Step 4: Migrate every server and client caller**

Update Inertia requests, sidebar/detail links, review-generated action URLs, tests, and notifications. Search both route names and literal strings; no first-party `/superAdmin/flagged-accounts` or old registration decision URL may remain.

- [x] **Step 5: Convert old GETs and remove old mutations**

Keep old registration/flagged GET pages as capability-protected redirects. Remove all `/superAdmin` registration and flagged POST routes; submitting to them must return `404|405`, never redirect/replay the mutation.

- [x] **Step 6: Regenerate frontend route metadata**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
```

Confirm canonical registration/flag names are present and `/superAdmin` mutation names are absent.

- [x] **Step 7: Verify workflows and negative paths**

```powershell
php artisan test tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
```

Expected: PASS; old mutation URLs are absent and canonical decisions preserve all transaction/audit behavior.

- [x] **Step 8: Commit canonical workflow routes**

```powershell
git add -- routes/web.php app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php app/Http/Controllers/ShopOwner/CustomerReviewController.php app/Http/Controllers/Api/CRM/CRMReviewController.php resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/ziggy.js tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
git commit -m "refactor: canonicalize privileged review routes"
```

---

## Task 6: Remove Superseded Registration Mutations and Preserve Immutable Documents

**Files:**
- Delete: `app/Http/Controllers/ShopRegistrationController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php`
- Modify: `tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php`
- Modify: `tests/Feature/ShopDocuments/LegacyRegistrationDocumentContractTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php`
- Modify: `resources/js/ziggy.js`
- Modify: `resources/js/Pages/UserSide/Shared/Navigation.tsx`
- Test: `tests/Feature/ShopOwner/ShopDocumentRenewalSubmissionTest.php`
- Test: `tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php`

- [x] **Step 1: Prove the canonical registration flow owns all current writes**

Search for `/api/shop/register`, `/api/shop/register-full`, `/shop/register-full`, `ShopRegistrationController`, `dtiRegistration`, and direct `ShopDocument::create` registration callers. Confirm the React form posts only to `shop-owner.register` and the canonical controller uses `ShopDocumentLifecycleService`.

- [x] **Step 2: Migrate location-policy tests to the real boundary**

Exercise `ShopOwnerAuthController::register` or the shared `CaviteLocationPolicyService` with the canonical payload. Keep location-denial assertions, but stop keeping obsolete mutation endpoints alive solely for tests.

- [x] **Step 3: Remove legacy mutation routes and controller**

Delete all three legacy POSTs and `ShopRegistrationController`. Keep `/shop/register` only as a GET redirect to `shop-owner-register`, preserving query string. Assert POST requests to old URIs return `404|405` and create no owner/document/file.

- [x] **Step 4: Re-run immutable-history guards**

Search and explain every remaining match:

```powershell
rg -n "documents\(\)->delete|ShopDocument::.*delete|deleteDocumentFiles|Business Registration \(DTI/SEC\)|dtiRegistration" app resources/js routes tests
rg -n -- "Storage::.*delete|->delete\(" app/Http/Controllers/ShopOwnerAuthController.php app/Services/ShopDocumentLifecycleService.php app/Http/Controllers/ShopOwner app/Http/Controllers/superAdmin
```

Only failed staged-upload cleanup may delete a non-authoritative file. No historical/current document row or promoted file may be deleted or overwritten.

- [x] **Step 5: Regenerate route metadata after removing legacy names**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
```

Confirm the canonical `shop-owner.register` remains and retired registration mutation names/URIs are absent.

- [x] **Step 6: Verify registration and document lifecycle**

```powershell
php artisan test tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php tests/Feature/ShopDocuments/LegacyRegistrationDocumentContractTest.php tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php tests/Feature/ShopOwner/ShopDocumentRenewalSubmissionTest.php tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php
```

Expected: PASS; canonical registration remains operational, legacy mutations are absent, and immutable history/legacy DTI-SEC continuity remain enforced.

- [x] **Step 7: Commit legacy registration removal**

```powershell
git add -- routes/web.php resources/js/ziggy.js tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php tests/Feature/ShopDocuments/LegacyRegistrationDocumentContractTest.php tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php app/Http/Controllers/ShopRegistrationController.php
git commit -m "refactor: remove superseded registration mutations"
```

---

## Task 7: Collapse Compatibility Routes, Navigation, and Dead Runtime Files

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/js/layout/AppSidebar.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar.test.tsx`
- Modify: `resources/js/ziggy.js`
- Delete: `app/Http/Controllers/SuperAdminController.php`
- Delete: `app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php`
- Delete: `resources/js/layout/layout/AppHeader.tsx`
- Delete: `resources/js/layout/layout/AppHeader_shopOwner.tsx`
- Delete: `resources/js/layout/layout/AppLayout.tsx`
- Delete: `resources/js/layout/layout/AppLayout_shopOwner.tsx`
- Delete: `resources/js/layout/layout/AppSidebar.tsx`
- Delete: `resources/js/layout/layout/AppSidebar_shopOwner.tsx`
- Delete: `resources/js/layout/layout/Backdrop.tsx`
- Delete: `resources/js/layout/layout/SidebarWidget.tsx`
- Modify: `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php`

- [x] **Step 1: Add the failing retirement and redirect-parity slice**

Expand `PhaseSevenStructuralBoundaryTest` with the final approved GET alias set, target-equivalent middleware/status/query/path behavior, retired-controller absence, `/superAdmin` mutation absence, and canonical navigation expectations. Confirm only the current duplicate groups/retired owners fail.

- [x] **Step 2: Prove the monolith has no route or direct-test callers**

```powershell
rg -n "SuperAdminController|SuperAdminUserManagementController|showShopRegistrations|showFlaggedAccounts|usersList" app routes resources/js tests
```

Expected before deletion: only class files or structural “must be absent” assertions. Migrate any real caller; do not hide it with a forwarding controller.

- [x] **Step 3: Inventory persisted compatibility links without rewriting them**

Against a safe database snapshot/read-only production connection, group `notifications.action_url` values containing `/superAdmin/` or any listed old `/admin/*` alias. Record URL category and count. Use the equivalent of this read-only query for the deployed database:

```sql
SELECT action_url, COUNT(*) AS total
FROM notifications
WHERE action_url LIKE '%/superAdmin/%'
   OR action_url IN (
       '/admin/admin',
       '/admin/create-admin',
       '/admin/shop-owner-registration-view',
       '/admin/registered-shops',
       '/admin/user-management',
       '/admin/subscription-management',
       '/admin/data-reports'
   )
   OR action_url LIKE '/admin/shops/%/details%'
GROUP BY action_url
ORDER BY total DESC, action_url ASC;
```

Do not update historical rows in Phase 7. A non-zero count is a reason to retain the corresponding safe GET redirect and evidence for Phase 8 retirement.

- [x] **Step 4: Collapse duplicate `/superAdmin` groups**

Replace both groups with one compact compatibility group containing only the approved GET redirects and target-equivalent capability middleware. Query strings must survive. No controller class from the retired group may be imported solely for compatibility.

- [x] **Step 5: Migrate active navigation**

Use canonical route names in `AppSidebar.tsx`; keep capability filtering unchanged for Admin versus Super Admin. Update tests to assert canonical hrefs and absence of `/superAdmin` links.

- [x] **Step 6: Reconfirm and delete the isolated duplicate layout tree**

Run import/reference searches for every file under `resources/js/layout/layout/`. If the only references are within that same isolated directory, delete all eight files together. Do not delete active `resources/js/layout/*` files.

- [x] **Step 7: Delete retired controllers**

Delete `SuperAdminController` and `SuperAdminUserManagementController`; remove imports. Do not leave subclasses, forwarding methods, or aliases.

- [x] **Step 8: Regenerate Ziggy route metadata**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
```

Confirm canonical route names exist and removed mutation names/URIs do not. Do not manually edit generated output.

- [x] **Step 9: Verify route and frontend structure**

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan test tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar.test.tsx
```

Expected: canonical routes resolve to focused owners; `/superAdmin` contains approved GET aliases only; active sidebar contains no legacy route.

- [x] **Step 10: Commit runtime cleanup**

```powershell
git add -- routes/web.php resources/js/layout/AppSidebar.tsx resources/js/layout/__tests__/AppSidebar.test.tsx resources/js/ziggy.js app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php resources/js/layout/layout tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
git commit -m "refactor: remove duplicate privileged runtime ownership"
```

---

## Task 8: Record Document, Audit, Notification, and Schedule Ownership

**Files:**
- Modify: `docs/runbooks/super-admin-operations.md`
- Modify: `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php`
- Test: `tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php`
- Test: `tests/Feature/SuperAdmin/PrivilegedLegacyWriterCutoverTest.php`
- Test: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`

- [x] **Step 1: Document the final runtime owner inventory**

Record:

```text
Owner registration       -> ShopOwnerAuthController + ShopDocumentLifecycleService
Owner renewal submission -> ShopOwnerDocumentRenewalController + lifecycle service
Registration review      -> ShopOwnerRegistrationViewController + decision service
Renewal review           -> ShopDocumentRenewalController + lifecycle service
Private document access  -> PrivateSensitiveDocumentController
Shop expiry detection    -> SendShopDocumentExpiryReminders + reminder service
HR expiry processing     -> CheckDocumentExpiry (EmployeeDocument only)
Notification persistence -> existing Notification model/infrastructure
Privileged audit writes  -> PrivilegedAudit
Legacy audit import      -> ImportLegacyPrivilegedAudit (bounded reconciliation only)
```

- [x] **Step 2: Document compatibility retirement criteria**

List every retained GET alias, target, capability, source-reference count, persisted `notifications.action_url` count/category, known external/bookmark reason, and Phase 8 removal evidence. Include relative and absolute persisted URL forms and query-string variants. Make explicit that aliases cannot accept mutations, cannot query/call domain code, are not permanent public API, and historical notification rows were not rewritten.

- [x] **Step 3: Verify no ownership regression**

```powershell
php artisan test tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php tests/Feature/SuperAdmin/PrivilegedLegacyWriterCutoverTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
php artisan schedule:list
```

Expected: PASS; schedule output contains the shop reminder schedule once, and no shop-compliance schedule points at the HR command.

- [x] **Step 4: Commit ownership documentation**

```powershell
git add -- docs/runbooks/super-admin-operations.md tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
git commit -m "docs: record privileged runtime ownership"
```

---

## Task 9: Required Review Stack and Final Verification

**Files:**
- Modify: `docs/superpowers/plans/2026-08-13-super-admin-phase-7-structural-simplification.md` — execution evidence/status only.
- Create: `docs/ai-learning-log.md` — only for a durable lesson; otherwise do not add the file.

- [x] **Step 1: Run the sequential required review stack**

Record each result once:

1. **simplify / ponytail:** delete forwarding handlers and duplicate route groups; reject base-controller hierarchies, generic route registries, generic expiry abstractions, and new dependencies;
2. **standards review:** Laravel naming, constructor injection, route model binding where safe, focused controllers, existing Form Requests/services, and repo style;
3. **spec review:** compare every Phase 7 acceptance criterion and canonical owner against the frozen design;
4. **correctness/risk review:** authorization middleware, recent reauth, private object scope, transaction ownership, audit atomicity, immutable documents, legacy DTI/SEC continuity, and redirect method safety;
5. **TypeScript/React review:** typed route payloads, no stale literals/imports, capability-filtered navigation, readable error paths;
6. **code splitting:** `N/A` unless imports/bundle behavior changed beyond deleting dead files;
7. **gauge improvements:** report before/after monolith lines/methods, duplicate route/mutation counts, legacy first-party caller count, and deleted dead-file count; latency/bundle improvement is `not measured` unless measured;
8. **security review:** canonical middleware and object scope must match or strengthen prior routes; old mutation URLs must be absent;
9. **verification-before-completion:** all completion claims require fresh command output; confirm every implementation commit was green and route-mutating tasks ran sequentially.

- [x] **Step 2: Run reuse and dead-code scans**

```powershell
rg -n "SuperAdminController|SuperAdminUserManagementController|ShopRegistrationController" app routes resources/js tests
rg -n "/superAdmin/|superAdmin\." app routes resources/js tests --glob '!resources/js/ziggy.js'
rg -n "admin\.(admin-management|create-admin|registered-shops|user-management|subscription-management|premium-plans|shop-owner-approve|shop-owner-reject)" app routes resources/js tests --glob '!resources/js/ziggy.js'
rg -n "documents\(\)->delete|ShopDocument::.*delete|Business Registration \(DTI/SEC\)" app routes resources/js tests
rg -n "AuditLog::create|activity\(\)" app/Http/Controllers/superAdmin app/Services
```

Every remaining match must be an approved GET alias, historical test/fixture, non-privileged domain writer, or explicit reconciliation path. Remove stale imports, dead methods, abandoned TODOs created by this phase, and obsolete route names.

- [x] **Step 3: Run focused backend suites**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php
```

- [x] **Step 4: Run focused frontend suites**

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar.test.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementBilling.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
```

- [x] **Step 5: Run route/schedule/generated-file inspection**

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan schedule:list
php artisan ziggy:generate resources/js/ziggy.js
git diff --check
```

Verify one mutation route per semantic action, only approved GET aliases under `/superAdmin`, one shop reminder schedule, separate HR/shop command signatures, and no generated route drift.

- [x] **Step 6: Run broad quality gates**

```powershell
composer test
pnpm run test:frontend
pnpm run build
git diff --check
```

The repository has no committed TypeScript compiler configuration or frontend lint script; do not report type-checking or linting as passing unless tooling is actually added and run. If an environment prerequisite blocks a command, record exact output and retain narrower passing evidence without converting a blocked check into a pass.

- [ ] **Step 7: Browser-verify both privileged roles**

Verify desktop/mobile and browser console/network behavior:

```text
Admin -> monitoring, registrations, users, shops, flags, own security
Admin -> denied administrator management, plans, subscription intervention, full audit
Super Admin -> all authorized canonical pages/actions
old GET bookmark -> capability-protected redirect preserving filters
old mutation URL -> 404/405 and no state/audit/notification change
registration decision -> verified immutable documents remain authoritative
shop renewal -> unchanged queue/private access/promotion behavior
```

- [ ] **Step 8: Record execution evidence and hand off Phase 8**

Set this plan to `EXECUTED` only after all applicable checks are recorded. Include before/after route counts, deleted monolith/dead-layout line counts, remaining GET aliases with retirement reasons, tests/build results, and any measured Phase 8 scale candidates. Do not expand Phase 7 to fix unrelated performance findings.

---

## Execution Evidence (2026-08-13)

Implementation and automated verification were completed on branch `super-admin-phase-0-containment` in the cumulative Phase 0-6 worktree. The plan remains short of `EXECUTED` until the authenticated Admin and Super Admin browser flows are run in a browser-capable environment.

Review results:

1. **Simplify / ponytail:** Passed. Duplicate route groups, forwarding handlers, the monolithic privileged controllers, and the isolated duplicate layout tree were removed. The only retained shared lifecycle concern is HTTP-only response mapping for materially identical shop/user outcomes. No generic domain abstraction or dependency was added.
2. **Standards:** Passed. PHP syntax, focused Laravel suites, route ownership, middleware, generated route metadata, and diff hygiene passed.
3. **Spec:** Passed after fixing audit compatibility redirects to preserve query strings. The frozen canonical owner, mutation, immutable-document, audit, expiry, and compatibility boundaries are covered by tests and route inspection.
4. **Correctness/risk:** Passed in focused workflow, audit, failure-injection, document-access, and full backend tests. Staged-upload cleanup is the only remaining document-file deletion path; historical/current document rows and promoted files are not deleted or overwritten.
5. **TypeScript/React:** Passed through focused and full Vitest suites plus the production build. No repository TypeScript or frontend lint script exists, so neither was reported as passed.
6. **Code splitting:** N/A. No heavy dependency or bundle behavior was introduced; dead layout files were deleted.
7. **Gauge:** The Phase 6 baseline monolith was 983 lines and 32 public methods. The final runtime has one `/superAdmin` compatibility group instead of two, six `/superAdmin` GET aliases, zero retired-controller runtime references, zero legacy mutation runtime references, and eight duplicate layout files deleted. Latency and bundle-size improvements were not measured.
8. **Security:** Passed. Canonical routes retain privileged authentication, active-state, MFA, recent-reauthentication, capability, private-object, audit, and subscription-containment checks. Old privileged mutation URLs are absent.
9. **Verification:** All route-mutating tasks were executed sequentially and committed only after their relevant tests passed. Fresh command evidence is listed below.

Verification results:

- Focused backend Phase 7 suite: exit 0, 4,067 assertions, 105 warnings.
- Audit/security/failure suite: exit 0, 334 assertions, 27 warnings.
- Full direct backend suite (`php artisan config:clear --ansi` followed by `php artisan test`): exit 0, 48,246 assertions, 1,851 warnings, 3 skipped. The `composer test` wrapper exceeded Composer's internal 300-second process limit; the underlying test command completed successfully in about 424 seconds.
- Focused frontend suite: 6 files and 23 tests passed.
- Full frontend suite: 93 files and 528 tests passed.
- Production build: passed; Vite transformed 3,692 modules.
- Route inspection: focused `/admin` owners are present; `/superAdmin` contains exactly six GET|HEAD compatibility redirects and no mutations.
- Schedule inspection: one shop-document reminder schedule is registered; the HR expiry command is not scheduled there.
- Ziggy regeneration and `git diff --check`: passed with no generated route drift.
- Local notification compatibility inventory: zero matching relative, absolute, or query-string legacy action URLs. Historical/external production counts remain unknown and are intentionally not rewritten.

Browser verification status:

- HTTP smoke verification passed against a temporary worktree server: `/admin/login` returned 200; an unauthenticated legacy privileged GET redirected to `/admin/login`; `/shop/register?source=legacy` preserved its query string while redirecting to `/shop-owner-register`; all three retired registration POSTs returned 404.
- Authenticated Admin/Super Admin desktop/mobile flows, browser console/network checks, and persisted-state reload checks were not runnable here because Python and a usable Playwright runner are unavailable. These remain the only open Phase 7 verification item; do not treat the HTTP smoke check as a substitute for role-flow verification.

The remaining handoff is to run Task 9 Step 7 in a browser-capable environment, record its results, then mark this plan `EXECUTED` and hand off measured scale candidates to Phase 8. No Phase 8 performance work was added to this phase.

---

## Acceptance Checklist

- [x] `/admin` is the only first-party privileged mutation prefix.
- [x] Every semantic privileged mutation has exactly one route and focused owner.
- [x] `/superAdmin` contains only the approved capability-protected GET redirects.
- [x] Compatibility redirects preserve authentication, status/MFA, capability, path parameters, and query strings while calling no model/controller/service.
- [x] Old mutation routes return `404|405` and cannot create state, audit, or notification side effects.
- [x] `SuperAdminController`, `SuperAdminUserManagementController`, and `ShopRegistrationController` are absent.
- [x] Administrator, shop, user, plan, subscription, registration, flag, document, notification, and audit ownership matches the frozen design.
- [x] Fixed capabilities, active/MFA middleware, recent reauthentication, and object scope are unchanged or stronger.
- [x] No runtime privileged workflow writes legacy `audit_logs` or unnamed activity.
- [x] Legacy audit import/provenance remains available and historical source rows are preserved.
- [x] Canonical registration is the sole current shop/document submission path.
- [x] No destructive shop-document replacement path remains.
- [x] Legacy DTI/SEC evidence still satisfies approved-shop continuity until classified or renewed.
- [x] Shop-document and HR-document expiry remain separate commands/services.
- [x] Expiration never mutates shop status.
- [x] Active navigation and server-generated action URLs contain no `/superAdmin` mutation links.
- [x] Persisted legacy `notifications.action_url` values are inventoried but not rewritten; retained alias reasons are recorded for Phase 8.
- [x] Duplicate isolated layout files are removed only after a fresh import proof.
- [x] `RespondsToAccountLifecycle` exists only if the final diff proves substantial identical HTTP-only mapping; otherwise it is omitted.
- [x] No new dependency, generic framework, schema migration, or speculative abstraction is introduced.
- [x] Every route-mutating task executed sequentially, regenerated Ziggy when needed, and committed only after its relevant tests were green.
- [ ] Focused and broad backend/frontend tests, route/schedule inspection, build, browser flows, and diff hygiene are recorded.

## Rollout and Rollback Notes

Deploy route/controller extraction in coherent commits, but release only after all first-party callers use canonical names. Route caches must be cleared/rebuilt through the normal deployment process after `routes/web.php` changes. Monitor `404`, `405`, and redirect telemetry for old paths; distinguish expected retired mutations from unknown external GET bookmarks.

Rollback application commits in reverse order without restoring duplicate mutation routes. If an urgent rollback needs an old GET bookmark, add only a protected GET redirect to the still-authoritative controller. Never restore `ShopRegistrationController`, destructive document replacement, `/superAdmin` POST aliases, legacy audit writers, or a second billing path. Phase 7 has no schema rollback because it creates no migration and deletes no historical data.
