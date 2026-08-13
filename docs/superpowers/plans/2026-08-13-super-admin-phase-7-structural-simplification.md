# Super Admin Phase 7 Structural Simplification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** DRAFT FOR APPROVAL

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
5. Registration, administrator management, registered shops, users, plans, subscriptions, flags, documents, notifications, and audit each have one runtime owner.
6. Legacy `/api/shop/register`, `/api/shop/register-full`, and `/shop/register-full` writes are removed. `/shop/register` may remain only as a safe GET redirect to the canonical registration form.
7. No current or historical `ShopDocument` row/file is overwritten or deleted. DTI/SEC distinction, legacy DTI/SEC continuity, stable supporting slots, reviewer verification, and renewal promotion remain unchanged.
8. `hr:check-document-expiry` remains HR-only and `shop-documents:send-expiry-reminders` remains shop-compliance-only. Neither command calls the other or shares a generic expiry service.
9. Runtime privileged operations write only through `PrivilegedAudit`. The bounded legacy audit importer remains available as historical reconciliation tooling and is not mistaken for a runtime writer.
10. No schema migration, permission UI, generic repository, base-controller hierarchy, route compatibility package, or new dependency is introduced.

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

### Explicit non-goals

- No route versioning layer or configurable alias registry.
- No generic `DocumentController`, `DocumentExpiryService`, or shared HR/shop expiry abstraction.
- No rewrite of Phase 0-6 services, transaction locks, audit semantics, notification delivery, or UI design.
- No pagination/index/performance expansion; Phase 8 owns measured scale work.
- No deletion of legacy audit rows, import provenance, historical document rows/files, or reconciliation commands.
- No rename of the `super_admin` guard/model or the React `superAdmin` page directory merely for casing/style.

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
- `app/Http/Controllers/Concerns/RespondsToAccountLifecycle.php` — HTTP-only success/error mapping shared by the shop and user controllers; no queries or business rules.
- `tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php` — canonical ownership, legacy alias, mutation uniqueness, dead-handler, audit, and expiry-boundary contract.

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
- `resources/js/ziggy.js` — regenerate from Laravel routes; never hand-edit.
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

- [ ] **Step 1: Capture the pre-change route/controller inventory**

Run and save concise evidence in the execution notes:

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
rg -n "^\s*public function " app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin/SuperAdminUserManagementController.php app/Http/Controllers/ShopRegistrationController.php
rg -n "AuditLog::create|activity\(\)" app/Http/Controllers/SuperAdminController.php app/Http/Controllers/superAdmin app/Services
```

Record route counts, legacy mutation routes, monolith public-method count, and privileged legacy-writer count. Do not claim performance improvement; this phase measures ownership/complexity only.

- [ ] **Step 2: Write the canonical route-map test**

Assert every route in the canonical contract exists with exact method, URI, action class/method, middleware, and route name. Include capability checks and existing `privileged.recent` requirements for administrator security, archive/restore, and financial interventions.

- [ ] **Step 3: Write mutation-uniqueness tests**

Iterate the route collection by semantic action and assert exactly one mutation route for administrator invitations/lifecycle, registration decisions, shop/user lifecycle, flag decisions, plans, subscription interventions, and document renewals. Assert every `/superAdmin/*` route and every registration compatibility route has only `GET|HEAD` or is absent.

- [ ] **Step 4: Write focused-owner and dead-handler tests**

Assert canonical actions do not reference `SuperAdminController`, `SuperAdminUserManagementController`, or `ShopRegistrationController`. Assert the shop/user `activate` forwarding handlers are absent while the distinct administrator activation transition remains; canonical compatibility belongs at the safe GET route layer, not as a second mutation handler.

- [ ] **Step 5: Write document/audit/expiry separation tests**

Assert:

```text
shop document mutations -> lifecycle/review services only
shop expiry command -> ShopDocumentReminderService only
HR expiry command -> EmployeeDocument only
runtime privileged paths -> PrivilegedAudit, never AuditLog::create
legacy audit import -> retained and read/import-only
```

- [ ] **Step 6: Run the red structural suite**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
```

Expected: new canonical ownership assertions fail against the current monolith and `/superAdmin` mutations; unchanged security assertions continue passing.

- [ ] **Step 7: Commit the failing boundary tests**

```powershell
git add -- tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
git commit -m "test: define phase 7 structural boundaries"
```

---

## Task 2: Extract Administrator Management into One Focused Owner

**Files:**
- Create: `app/Http/Controllers/superAdmin/AdministratorManagementController.php`
- Modify: `app/Services/AdministratorIdentityService.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx`
- Modify: `tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php`

- [ ] **Step 1: Add failing canonical administrator route tests**

Cover index/create/store, setup resend, suspend/deactivate/activate, role update, and MFA reset under `/admin/administrators`. Assert lower Admin receives `403` for management actions, self-mutation protections remain, and final-active-Super-Admin invariants remain unchanged.

- [ ] **Step 2: Move invitation transactions into the existing identity service**

Add narrowly named `invite()` and `resendSetupInvitation()` methods to `AdministratorIdentityService`. Move the existing lock/token/audit/after-commit delivery workflow byte-for-behavior, keeping server-generated password/token material, transaction boundaries, idempotency/state validation, failure sanitization, and final-Super-Admin safeguards. Do not create a second invitation service.

- [ ] **Step 3: Create the focused controller**

Move the administrator list/create pages and thin HTTP orchestration to `AdministratorManagementController`. Inject only `AdministratorIdentityService` and `PrivilegedFailureResponse`; the controller must not issue tokens, write audits, dispatch mail, or open transactions directly.

- [ ] **Step 4: Register canonical routes and migrate UI callers**

Register only the canonical administrator mutations. Temporarily keep old administrator GET paths as query-preserving redirects; do not keep old mutation aliases. Update page submissions, redirects, and tests to canonical names.

- [ ] **Step 5: Verify administrator behavior**

```powershell
php artisan test tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PrivilegedRecentReauthenticationTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
```

Expected: PASS; invitation delivery remains after commit, self/final-Super-Admin protections remain enforced, and only canonical mutations exist.

- [ ] **Step 6: Commit administrator extraction**

```powershell
git add -- app/Http/Controllers/superAdmin/AdministratorManagementController.php app/Services/AdministratorIdentityService.php routes/web.php resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/PrivilegedBootstrapAndInvitationTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
git commit -m "refactor: isolate administrator management"
```

---

## Task 3: Extract Registered-Shop and User Intervention Owners

**Files:**
- Create: `app/Http/Controllers/Concerns/RespondsToAccountLifecycle.php`
- Create: `app/Http/Controllers/superAdmin/RegisteredShopController.php`
- Create: `app/Http/Controllers/superAdmin/UserInterventionController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx`
- Modify: `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php`

- [ ] **Step 1: Characterize both current user-list payloads before choosing one**

Write tests for the actual approved user scope, filters, pagination shape, employee relation, lifecycle fields, and private-ID URL. Use the current active sidebar page as the contract; do not merge incompatible `SuperAdminController::showUserManagement()` and `SuperAdminUserManagementController::index()` payloads by unioning every field.

- [ ] **Step 2: Extract HTTP-only lifecycle response mapping**

Move the existing success/not-found/conflict/validation/unexpected HTTP mapping into `RespondsToAccountLifecycle`. The concern may call `PrivilegedFailureResponse`, but it must not query models, authorize capabilities, mutate state, audit, or know whether the target is a shop or user.

- [ ] **Step 3: Create the user owner**

Move the selected canonical list query and user lifecycle orchestration into `UserInterventionController`. Keep mutations delegated to `AccountLifecycleService`; use route model binding only where it does not bypass the service's canonical locking/not-found behavior.

- [ ] **Step 4: Create the registered-shop owner**

Move registered-shop list/detail and lifecycle orchestration into `RegisteredShopController`. Preserve private document URLs through `admin.shop-documents.show`, lifecycle/archive semantics, and the existing lightweight-list/detail-on-demand boundary. Do not add Phase 8 pagination or query tuning here.

- [ ] **Step 5: Register canonical routes and migrate callers**

Move GET pages to `/admin/users` and `/admin/shops`; keep the existing semantic mutation endpoints beneath those resources. Update all frontend links and tests. Old page/detail GETs become redirects only after direct callers are gone.

- [ ] **Step 6: Verify lifecycle, private access, and UI contracts**

```powershell
php artisan test tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx
```

Expected: PASS; lifecycle state/audit behavior and private document authorization are unchanged.

- [ ] **Step 7: Commit account-owner extraction**

```powershell
git add -- app/Http/Controllers/Concerns/RespondsToAccountLifecycle.php app/Http/Controllers/superAdmin/RegisteredShopController.php app/Http/Controllers/superAdmin/UserInterventionController.php routes/web.php resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseTwoBaselineContractTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
git commit -m "refactor: isolate privileged account owners"
```

---

## Task 4: Extract Subscription Read and Premium-Plan Mutation Owners

**Files:**
- Create: `app/Http/Controllers/superAdmin/SubscriptionManagementController.php`
- Create: `app/Http/Controllers/superAdmin/PremiumPlanController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`
- Modify: `tests/Feature/AdminPremiumPlanManagementTest.php`
- Modify: `tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php`

- [ ] **Step 1: Add failing billing-owner route tests**

Assert `/admin/subscriptions` is read-only page ownership, `/admin/plans*` mutations use `PremiumPlanController`, and provider-backed cancellation/refund/correction remain owned by `SubscriptionInterventionController`. Assert no direct plan swap or pseudo-refund route returns.

- [ ] **Step 2: Move the existing subscription page query intact**

Move `showSubscriptionManagement()` to `SubscriptionManagementController@index` without changing ledger interpretation, provider status, refund presentation, cancellation metadata, or query strategy. Phase 8 owns measured query optimization.

- [ ] **Step 3: Move plan HTTP orchestration intact**

Move create/update/archive/reactivate methods to `PremiumPlanController`, retaining Form Requests, `PremiumPlanManagementService`, `PrivilegedFailureResponse`, capability middleware, audits, and redirects to `admin.subscriptions.index`.

- [ ] **Step 4: Migrate UI and test callers**

Replace old `subscription-management` and `premium-plans` route names/URLs with canonical names. Keep `/admin/subscription-management` as GET redirect only; no old plan mutation route remains.

- [ ] **Step 5: Verify billing containment**

```powershell
php artisan test tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php
```

Expected: PASS; authoritative payment/refund history and provider intervention ownership are unchanged.

- [ ] **Step 6: Commit billing controller extraction**

```powershell
git add -- app/Http/Controllers/superAdmin/SubscriptionManagementController.php app/Http/Controllers/superAdmin/PremiumPlanController.php routes/web.php resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx tests/Feature/AdminPremiumPlanManagementTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
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
- Modify: `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php`
- Modify: `tests/Feature/Reports/ShopAndCustomerReportFlowTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`

- [ ] **Step 1: Register canonical registration routes**

Map queue and decisions to `admin.registrations.index|approve|reject`, preserving `review_registrations`, scoped private access, decision-service transactions, verified document metadata, idempotency, and `409` conflicts. Do not merge registration review and approved-shop renewal review.

- [ ] **Step 2: Register canonical flagged-account routes**

Move GET and POST routes under `/admin/flagged-accounts`, preserving `moderate_reports`, `FlaggedAccountModerationService`, suspension provenance, appeal creation, audit, and notification behavior.

- [ ] **Step 3: Migrate every server and client caller**

Update Inertia requests, sidebar/detail links, review-generated action URLs, tests, and notifications. Search both route names and literal strings; no first-party `/superAdmin/flagged-accounts` or old registration decision URL may remain.

- [ ] **Step 4: Convert old GETs and remove old mutations**

Keep old registration/flagged GET pages as capability-protected redirects. Remove all `/superAdmin` registration and flagged POST routes; submitting to them must return `404|405`, never redirect/replay the mutation.

- [ ] **Step 5: Verify workflows and negative paths**

```powershell
php artisan test tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
```

Expected: PASS; old mutation URLs are absent and canonical decisions preserve all transaction/audit behavior.

- [ ] **Step 6: Commit canonical workflow routes**

```powershell
git add -- routes/web.php app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php app/Http/Controllers/ShopOwner/CustomerReviewController.php app/Http/Controllers/Api/CRM/CRMReviewController.php resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/Reports/ShopAndCustomerReportFlowTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
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
- Test: `tests/Feature/ShopOwner/ShopDocumentRenewalSubmissionTest.php`
- Test: `tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php`

- [ ] **Step 1: Prove the canonical registration flow owns all current writes**

Search for `/api/shop/register`, `/api/shop/register-full`, `/shop/register-full`, `ShopRegistrationController`, `dtiRegistration`, and direct `ShopDocument::create` registration callers. Confirm the React form posts only to `shop-owner.register` and the canonical controller uses `ShopDocumentLifecycleService`.

- [ ] **Step 2: Migrate location-policy tests to the real boundary**

Exercise `ShopOwnerAuthController::register` or the shared `CaviteLocationPolicyService` with the canonical payload. Keep location-denial assertions, but stop keeping obsolete mutation endpoints alive solely for tests.

- [ ] **Step 3: Remove legacy mutation routes and controller**

Delete all three legacy POSTs and `ShopRegistrationController`. Keep `/shop/register` only as a GET redirect to `shop-owner-register`, preserving query string. Assert POST requests to old URIs return `404|405` and create no owner/document/file.

- [ ] **Step 4: Re-run immutable-history guards**

Search and explain every remaining match:

```powershell
rg -n "documents\(\)->delete|ShopDocument::.*delete|deleteDocumentFiles|Business Registration \(DTI/SEC\)|dtiRegistration" app resources/js routes tests
rg -n -- "Storage::.*delete|->delete\(" app/Http/Controllers/ShopOwnerAuthController.php app/Services/ShopDocumentLifecycleService.php app/Http/Controllers/ShopOwner app/Http/Controllers/superAdmin
```

Only failed staged-upload cleanup may delete a non-authoritative file. No historical/current document row or promoted file may be deleted or overwritten.

- [ ] **Step 5: Verify registration and document lifecycle**

```powershell
php artisan test tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php tests/Feature/ShopDocuments/LegacyRegistrationDocumentContractTest.php tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php tests/Feature/ShopOwner/ShopDocumentRenewalSubmissionTest.php tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php
```

Expected: PASS; canonical registration remains operational, legacy mutations are absent, and immutable history/legacy DTI-SEC continuity remain enforced.

- [ ] **Step 6: Commit legacy registration removal**

```powershell
git add -- routes/web.php tests/Feature/LocationPolicy/ShopOwnerRegistrationLocationTest.php tests/Feature/LocationPolicy/ShopOwnerFullRegistrationLocationTest.php tests/Feature/ShopDocuments/LegacyRegistrationDocumentContractTest.php tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php app/Http/Controllers/ShopRegistrationController.php
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

- [ ] **Step 1: Prove the monolith has no route or direct-test callers**

```powershell
rg -n "SuperAdminController|SuperAdminUserManagementController|showShopRegistrations|showFlaggedAccounts|usersList" app routes resources/js tests
```

Expected before deletion: only class files or structural “must be absent” assertions. Migrate any real caller; do not hide it with a forwarding controller.

- [ ] **Step 2: Collapse duplicate `/superAdmin` groups**

Replace both groups with one compact compatibility group containing only the approved GET redirects and target-equivalent capability middleware. Query strings must survive. No controller class from the retired group may be imported solely for compatibility.

- [ ] **Step 3: Migrate active navigation**

Use canonical route names in `AppSidebar.tsx`; keep capability filtering unchanged for Admin versus Super Admin. Update tests to assert canonical hrefs and absence of `/superAdmin` links.

- [ ] **Step 4: Reconfirm and delete the isolated duplicate layout tree**

Run import/reference searches for every file under `resources/js/layout/layout/`. If the only references are within that same isolated directory, delete all eight files together. Do not delete active `resources/js/layout/*` files.

- [ ] **Step 5: Delete retired controllers**

Delete `SuperAdminController` and `SuperAdminUserManagementController`; remove imports. Do not leave subclasses, forwarding methods, or aliases.

- [ ] **Step 6: Regenerate Ziggy route metadata**

```powershell
php artisan ziggy:generate resources/js/ziggy.js
```

Confirm canonical route names exist and removed mutation names/URIs do not. Do not manually edit generated output.

- [ ] **Step 7: Verify route and frontend structure**

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan test tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar.test.tsx
```

Expected: canonical routes resolve to focused owners; `/superAdmin` contains approved GET aliases only; active sidebar contains no legacy route.

- [ ] **Step 8: Commit runtime cleanup**

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

- [ ] **Step 1: Document the final runtime owner inventory**

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

- [ ] **Step 2: Document compatibility retirement criteria**

List every retained GET alias, target, capability, known external/stored-link reason, and Phase 8 removal evidence. Make explicit that aliases cannot accept mutations and are not permanent public API.

- [ ] **Step 3: Verify no ownership regression**

```powershell
php artisan test tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php tests/Feature/SuperAdmin/PrivilegedLegacyWriterCutoverTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
php artisan schedule:list
```

Expected: PASS; schedule output contains the shop reminder schedule once, and no shop-compliance schedule points at the HR command.

- [ ] **Step 4: Commit ownership documentation**

```powershell
git add -- docs/runbooks/super-admin-operations.md tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php
git commit -m "docs: record privileged runtime ownership"
```

---

## Task 9: Required Review Stack and Final Verification

**Files:**
- Modify: `docs/superpowers/plans/2026-08-13-super-admin-phase-7-structural-simplification.md` — execution evidence/status only.
- Create: `docs/ai-learning-log.md` — only for a durable lesson; otherwise do not add the file.

- [ ] **Step 1: Run the sequential required review stack**

Record each result once:

1. **simplify / ponytail:** delete forwarding handlers and duplicate route groups; reject base-controller hierarchies, generic route registries, generic expiry abstractions, and new dependencies;
2. **standards review:** Laravel naming, constructor injection, route model binding where safe, focused controllers, existing Form Requests/services, and repo style;
3. **spec review:** compare every Phase 7 acceptance criterion and canonical owner against the frozen design;
4. **correctness/risk review:** authorization middleware, recent reauth, private object scope, transaction ownership, audit atomicity, immutable documents, legacy DTI/SEC continuity, and redirect method safety;
5. **TypeScript/React review:** typed route payloads, no stale literals/imports, capability-filtered navigation, readable error paths;
6. **code splitting:** `N/A` unless imports/bundle behavior changed beyond deleting dead files;
7. **gauge improvements:** report before/after monolith lines/methods, duplicate route/mutation counts, legacy first-party caller count, and deleted dead-file count; latency/bundle improvement is `not measured` unless measured;
8. **security review:** canonical middleware and object scope must match or strengthen prior routes; old mutation URLs must be absent;
9. **verification-before-completion:** all completion claims require fresh command output.

- [ ] **Step 2: Run reuse and dead-code scans**

```powershell
rg -n "SuperAdminController|SuperAdminUserManagementController|ShopRegistrationController" app routes resources/js tests
rg -n "/superAdmin/|superAdmin\." app routes resources/js tests --glob '!resources/js/ziggy.js'
rg -n "admin\.(admin-management|create-admin|registered-shops|user-management|subscription-management|premium-plans|shop-owner-approve|shop-owner-reject)" app routes resources/js tests --glob '!resources/js/ziggy.js'
rg -n "documents\(\)->delete|ShopDocument::.*delete|Business Registration \(DTI/SEC\)" app routes resources/js tests
rg -n "AuditLog::create|activity\(\)" app/Http/Controllers/superAdmin app/Services
```

Every remaining match must be an approved GET alias, historical test/fixture, non-privileged domain writer, or explicit reconciliation path. Remove stale imports, dead methods, abandoned TODOs created by this phase, and obsolete route names.

- [ ] **Step 3: Run focused backend suites**

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseSevenStructuralBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php tests/Feature/SuperAdmin/PhaseOneRouteSecurityTest.php tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php tests/Feature/SuperAdmin/AdministratorIdentityLifecycleTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/FlaggedAccountWorkflowTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php
```

- [ ] **Step 4: Run focused frontend suites**

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar.test.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/RegisteredShopsLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementBilling.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
```

- [ ] **Step 5: Run route/schedule/generated-file inspection**

```powershell
php artisan route:list --path=admin --except-vendor
php artisan route:list --path=superAdmin --except-vendor
php artisan schedule:list
php artisan ziggy:generate resources/js/ziggy.js
git diff --check
```

Verify one mutation route per semantic action, only approved GET aliases under `/superAdmin`, one shop reminder schedule, separate HR/shop command signatures, and no generated route drift.

- [ ] **Step 6: Run broad quality gates**

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

## Acceptance Checklist

- [ ] `/admin` is the only first-party privileged mutation prefix.
- [ ] Every semantic privileged mutation has exactly one route and focused owner.
- [ ] `/superAdmin` contains only the approved capability-protected GET redirects.
- [ ] Old mutation routes return `404|405` and cannot create state, audit, or notification side effects.
- [ ] `SuperAdminController`, `SuperAdminUserManagementController`, and `ShopRegistrationController` are absent.
- [ ] Administrator, shop, user, plan, subscription, registration, flag, document, notification, and audit ownership matches the frozen design.
- [ ] Fixed capabilities, active/MFA middleware, recent reauthentication, and object scope are unchanged or stronger.
- [ ] No runtime privileged workflow writes legacy `audit_logs` or unnamed activity.
- [ ] Legacy audit import/provenance remains available and historical source rows are preserved.
- [ ] Canonical registration is the sole current shop/document submission path.
- [ ] No destructive shop-document replacement path remains.
- [ ] Legacy DTI/SEC evidence still satisfies approved-shop continuity until classified or renewed.
- [ ] Shop-document and HR-document expiry remain separate commands/services.
- [ ] Expiration never mutates shop status.
- [ ] Active navigation and server-generated action URLs contain no `/superAdmin` mutation links.
- [ ] Duplicate isolated layout files are removed only after a fresh import proof.
- [ ] No new dependency, generic framework, schema migration, or speculative abstraction is introduced.
- [ ] Focused and broad backend/frontend tests, route/schedule inspection, build, browser flows, and diff hygiene are recorded.

## Rollout and Rollback Notes

Deploy route/controller extraction in coherent commits, but release only after all first-party callers use canonical names. Route caches must be cleared/rebuilt through the normal deployment process after `routes/web.php` changes. Monitor `404`, `405`, and redirect telemetry for old paths; distinguish expected retired mutations from unknown external GET bookmarks.

Rollback application commits in reverse order without restoring duplicate mutation routes. If an urgent rollback needs an old GET bookmark, add only a protected GET redirect to the still-authoritative controller. Never restore `ShopRegistrationController`, destructive document replacement, `/superAdmin` POST aliases, legacy audit writers, or a second billing path. Phase 7 has no schema rollback because it creates no migration and deletes no historical data.
