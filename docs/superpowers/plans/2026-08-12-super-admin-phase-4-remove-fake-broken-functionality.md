# Super Admin Phase 4 Remove Fake and Broken Functionality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task in the existing `super-admin-phase-0-containment` worktree. Apply `superpowers:test-driven-development` before implementation changes, `laravel-best-practices` and `security-review` for privileged routes and data access, the repository UI/design skills for changed interactive surfaces, `ponytail` for the minimum coherent solution, and `verification-before-completion` before every completion claim. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every visible Super Admin control truthful: retain and repair real operational notifications, account lifecycle actions, monitoring, profile/security, plan management, and audit navigation; remove simulated communications, support tickets, customer decisions, deactivation, password reset, and unsafe subscription interventions that have no authoritative workflow.

**Architecture:** Preserve the Phase 0-3 guard, capability, MFA, workflow, audit, and delivery foundations. Do not build replacement announcement, alert, support-ticket, export, or reporting platforms. Replace the mixed fake communications page with one focused administrative notification inbox backed by the existing recipient-scoped `AdminNotificationController`. Keep real user suspension/reactivation/archive/restore controls and remove only actions whose UI invents success or calls nonexistent endpoints. Reuse the canonical `/admin/security`, `/admin/audit`, and `/admin/system-monitoring` paths. Temporarily withdraw all Super Admin subscription cancel/upgrade/downgrade mutations because the current implementations bypass authoritative billing or rewrite paid history; Phase 5 owns the verified replacement. Keep safe legacy GET aliases as redirects where dependency inspection justifies compatibility, while removing duplicate/unsafe mutation endpoints immediately.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, Inertia 2, React 18, TypeScript 5.7, TanStack Query, PHPUnit 11, Vitest 3, pnpm.

**Status:** DRAFT FOR APPROVAL

---

## Design Authority and Scope Guard

Authoritative design:

- `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`
- Phase 4, "Remove Fake and Broken Functionality"
- Sections 8, 12, 16, 17, 21, and 22 where they define fixed capabilities, retained/removed surfaces, route ownership, operational notifications, truthful controls, and verification

Implemented prerequisites:

- Completed Phase 3 tip: `82f667f56`
- Worktree/branch: `.worktrees/super-admin-phase-0-containment` / `super-admin-phase-0-containment`

This plan is based on the clean post-Phase-3 worktree. Do not execute it against a branch lacking active/MFA/capability enforcement, authoritative privileged audit, sanitized failures, and durable workflow delivery.

Phase 4 includes:

1. a structural/runtime inventory that proves which fake surfaces are reachable and which files are only legacy leftovers;
2. replacement of the fake communications page with a real, recipient-scoped administrative notification inbox;
3. removal of fake customer approval/rejection, deactivation, and administrator-triggered password-reset controls;
4. repair of misleading/dead monitoring controls and links;
5. a canonical allowlisted privileged profile page, truthful role labels, and links to real profile/security actions;
6. capability-aware navigation using the fixed server-owned two-role capability map;
7. withdrawal of unsafe subscription cancellation/upgrade/downgrade controls, routes, and controller methods until Phase 5 supplies authoritative billing behavior;
8. focused route, authorization, frontend, and browser regression evidence.

Do not add in this phase:

- announcement composition, broadcast alerts, support tickets, communication preferences, exports, report generation, or configurable notification rules;
- customer registration approval/rejection or administrator-triggered customer password reset without a separately approved domain workflow;
- a new permission package, configurable permission UI, or a frontend-only authorization system;
- provider refunds, canonical subscription cancellation, payment reconciliation, plan switching, financial adjustment, or paid-history correction; Phase 5 owns these;
- business-document expiration/renewal, DTI/SEC normalization, or reminders; Phase 6 owns these;
- wholesale `/superAdmin` route consolidation, monolithic-controller decomposition, or deletion of unrelated duplicate layout/controller trees; Phase 7 owns structural simplification;
- broad pagination, query optimization, indexes, or generalized notification components; Phase 8 owns measured scale work;
- changes to Shop Owner, customer, ERP, HR, finance, POS, or order notification/export behavior merely because shared frontend hooks exist.

## Confirmed Post-Phase-3 Baseline

- `NotificationCommunicationTools.tsx` is entirely client-local for announcements, alerts, support tickets, and communication settings. It uses local state and success dialogs without a persisted backend operation.
- Both `/superAdmin/notification-communication-tools` route declarations render that fake page through `NotificationCommunicationToolsController`; `/admin/notifications` also renders it directly.
- The real `/api/admin/notifications` API already lists, counts, marks read, marks all read, and deletes notifications scoped by `super_admin_id`. `AppHeader` already uses that API and links "View All" to `/admin/notifications`.
- `SuperAdminUserManagement.tsx` calls nonexistent `/superAdmin/users/{id}/approve|reject` endpoints, simulates deactivation with local state/timeouts, and displays a password-reset success message without sending or persisting anything.
- Real user suspension, reactivation, archive, restore, details, and private-valid-ID paths already exist and are protected by Phase 1-3 middleware/workflows.
- `SystemMonitoringDashboard.tsx` contains three inert "See all" buttons. Recent activity has a real `/admin/audit` destination; system health and operational counts do not have detail pages.
- Monitoring data is server-derived, but labels such as "All Systems Operational", "Performance Metrics", and "Live" overstate what the current database, queue-configuration, failed-job, and account-count checks prove.
- `SuperAdminAuthController::showProfile()` and `Profile.tsx` exist but have no canonical privileged route. The controller passes a full model, the page can read the wrong guard shape, and both profile/dropdown hard-code the Super Admin role even for the retained `admin` role.
- `SuperAdminAuthController::updatePassword()` is unrouted legacy code displaced by `/admin/security/password`; retaining it creates a second unaudited credential path if accidentally re-exposed.
- `AppSidebar.tsx` reads the wrong `auth.superAdmin` key, computes but does not apply role filtering, exposes Super-Admin-only plan/admin links to regular Admin, uses legacy GET route names, omits audit history, and exposes the fake communications surface.
- The server shares privileged identity and role through `auth.super_admin`, but not the fixed capability list. Backend middleware remains authoritative regardless of navigation visibility.
- `/admin/subscriptions/{id}/upgrade` and `/downgrade` directly rewrite plan fields without authoritative billing. They have no current frontend caller.
- `/admin/subscriptions/{id}/cancel` is visible as "Deactivate" but rewrites `paid_amount` to zero and records/refers to a refund without provider money movement. It is not safe to leave callable while Phase 5 is pending.
- Premium-plan create/update/archive/reactivate and subscription history display are real Phase 3-covered operations and remain in Phase 4.
- Phase 3 already removed the simulated data-report/export controller/page and redirects safe GET aliases to `/admin/audit`; Phase 4 must preserve that cutover and verify no fake export control reappears.
- A second `resources/js/layout/layout/AppSidebar.tsx` and duplicated broad route sections exist. Runtime `AppLayout` uses `resources/js/layout/AppSidebar.tsx`; unrelated structural deletion remains Phase 7 work.

## Frozen Phase 4 Contracts

### Truthful-control rule

```text
visible mutation control
        -> registered protected endpoint
        -> authoritative persisted result
        -> response derived from that result
```

If no approved authoritative workflow exists, remove the control. Do not retain it disabled with "coming soon", simulate it locally, or replace it with a success-only stub.

### Operational notifications boundary

The retained administrative notification inbox may:

- list only the authenticated administrator's operational notifications;
- filter unread notifications using the existing API contract;
- mark one or all notifications read;
- dismiss one notification without changing privileged audit history;
- follow an allowlisted stored action URL through an ordinary Inertia link;
- paginate using the server result.

It may not compose announcements, broadcast alerts, manage preferences, export notifications, create support tickets, bulk-archive, or present notification dismissal as deletion of audit evidence.

The API serializes an explicit notification allowlist only: ID, type, title, message, safe local action URL, read state/time, and creation time. It does not return the full Eloquent model or arbitrary `data` payload. An action URL is linkable only when it is an application-relative path beginning with one `/`; schemes, hosts, protocol-relative values, control characters, and malformed values serialize as `null`.

### User-intervention boundary

Retain only workflows that already have authoritative protected endpoints:

```text
view details/private valid ID
suspend
reactivate
archive
restore
```

Historical `pending`, `approved`, `rejected`, or `deactivated` values may remain visible and filterable as legacy/read-only data. Their presence does not authorize new customer approval, rejection, deactivation, or password-reset actions.

### Subscription containment boundary

During Phase 4 the page retains:

```text
premium-plan create/update/archive/reactivate
read-only subscription/payment/history inspection
```

It exposes no Super Admin subscription intervention mutation:

```text
cancel/deactivate  -> unavailable until Phase 5 canonical cancellation
upgrade            -> removed; Shop Owner billing remains authoritative
downgrade          -> removed; Shop Owner billing remains authoritative
refund             -> unavailable unless Phase 5 verifies provider support
```

Remove all three mutation routes and controller methods now. Do not leave legacy mutation aliases. Phase 5 may introduce a canonical endpoint only together with its transaction, provider/payment, audit, idempotency, and reconciliation contracts.

### Fixed-capability navigation

Backend middleware remains the security boundary. The UI consumes a server-derived list of the same fixed capabilities only to avoid offering impossible actions:

```text
Admin
  -> monitoring, registrations, users/shops, reports/flags,
     appeal queue, scoped audit, own profile/security, notifications

Super Admin
  -> all Admin navigation plus administrator management,
     plan management, appeal decisions, platform-security surfaces
```

Do not duplicate a separate editable role matrix in TypeScript. Expose the model's deterministic capability list and fail closed when it is absent.

### Compatibility rule

- `/admin/notifications`, `/admin/profile`, `/admin/security`, `/admin/audit`, and `/admin/system-monitoring` are canonical.
- The old communications GET may redirect to `/admin/notifications` only as temporary safe compatibility; it never renders the fake page.
- Existing safe data-report GET aliases continue redirecting to `/admin/audit`.
- The current paginated `/superAdmin/super-admin-user-management` GET remains temporarily because `/admin/user-management` is not behavior-equivalent: it hydrates an unbounded and broader collection. Phase 4 does not trade truthful controls for a scope/pagination regression; Phase 7/8 will migrate ownership after parity is established.
- No POST/PUT/PATCH/DELETE compatibility route is retained for removed user or subscription mutations.
- Delete a controller/page only after runtime navigation, header links, redirects, tests, notification links, and route actions are checked.

---

## Task 1: Freeze the Reachable-Surface and Route Boundary

**Files:**

- Create: `tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`
- Test: `resources/js/layout/__tests__/AppSidebar.test.tsx`

- [ ] **Step 1: Write failing backend structural tests**

Assert that the completed Phase 4 route table must have:

- canonical protected GET routes for notifications, profile, audit, security, and system monitoring;
- a safe legacy communications GET redirect and no route action rendering `NotificationCommunicationTools`;
- no route action pointing to fake user approval/rejection/deactivation/password-reset handlers;
- no `admin.subscriptions.cancel`, `admin.subscriptions.upgrade`, or `admin.subscriptions.downgrade` route;
- no fake data-report/export mutation or page action;
- correlation, privileged authentication, active-state, and MFA middleware on every canonical/compatibility privileged route.

Update the Phase 3 boundary test that intentionally asserted subscription routes still existed. Its new contract must state that Phase 4 withdrew the uncertified endpoints and that Phase 5 owns any replacement.

Run:

```bash
php artisan test tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php
```

Expected: FAIL because fake communications routes and unsafe subscription mutations are still registered and canonical profile is absent.

- [ ] **Step 2: Add a failing runtime-navigation test**

Extend the actual `resources/js/layout/AppSidebar.tsx` test, not the unused duplicate layout tree. Prove that:

- neither role sees "Notification & Communication Tools";
- both roles receive canonical monitoring/audit/user/shop links;
- regular Admin does not see administrator or plan management;
- Super Admin does see administrator and plan management;
- missing capability data fails closed for restricted items.

Run:

```bash
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar.test.tsx
```

Expected: FAIL because the current sidebar ignores its role calculation and exposes the fake page.

- [ ] **Step 3: Record dependency evidence before deletion**

Use CodeGraph first, then focused searches and route inspection to confirm:

```bash
codegraph explore "NotificationCommunicationTools AppSidebar AppHeader SuperAdminUserManagement subscription mutation callers"
php artisan route:list --path=admin
php artisan route:list --path=superAdmin
rg -n "NotificationCommunicationTools|notification-communication-tools|/admin/notifications|subscriptions\.(cancel|upgrade|downgrade)|/admin/subscriptions/" app routes resources tests --glob '!public/build/**'
```

Classify each reference as runtime caller, safe compatibility redirect, test, generated Ziggy data, or Phase 7 structural debt. Do not delete generated artifacts manually in this task.

- [ ] **Step 4: Commit boundary tests with the first implementation task that makes them pass**

Do not commit deliberately failing tests alone. Keep this task's tests red until Tasks 2-5 satisfy the frozen boundary.

## Task 2: Replace Simulated Communications with a Real Admin Notification Inbox

**Files:**

- Create: `resources/js/Pages/superAdmin/Notifications/AdminNotifications.tsx`
- Create: `resources/js/Pages/superAdmin/Notifications/__tests__/AdminNotifications.test.tsx`
- Create: `tests/Feature/SuperAdmin/AdminNotificationInboxTest.php`
- Modify: `app/Http/Controllers/Api/AdminNotificationController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/layout/AppHeader.tsx` only if tests expose a link/API contract mismatch
- Delete: `app/Http/Controllers/superAdmin/NotificationCommunicationToolsController.php`
- Delete: `resources/js/Pages/superAdmin/Communications/NotificationCommunicationTools.tsx`
- Modify: `tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php`

- [ ] **Step 1: Write failing inbox authorization and ownership tests**

Cover:

- `/admin/notifications` requires privileged authentication, active state, and completed MFA;
- the index API returns only rows whose `super_admin_id` matches the authenticated administrator;
- unread filtering and deterministic newest-first pagination work;
- `per_page` is integer-normalized, defaults to 20, and is capped at 100;
- an administrator can mark/dismiss only their own notification;
- another administrator's numeric notification ID returns `404`, not leaked data;
- mark-all affects only the current administrator;
- list responses contain only the explicit safe field allowlist, strip arbitrary model `data`, and null unsafe/external/protocol-relative action URLs;
- dismissing an operational notification does not delete or alter `activity_log`.

Run:

```bash
php artisan test tests/Feature/SuperAdmin/AdminNotificationInboxTest.php tests/Feature/BusinessScaling/BusinessScalingNotificationTest.php
```

Expected: FAIL on the missing real page and any unbounded/contract gaps; existing recipient scoping should already pass.

- [ ] **Step 2: Write failing focused page tests**

Prove the page:

- reads the existing `/api/admin/notifications` payload shape;
- renders loading, empty, error, unread, and paginated states;
- marks one/all as read and dismisses through real API mutations;
- uses server-confirmed query invalidation rather than local success fabrication;
- follows a notification action URL when present;
- renders only a server-approved application-relative action URL and leaves an unsafe/null URL non-linkable;
- has no announcement, alert, support-ticket, communication-setting, export, archive, or preference control.

Run:

```bash
pnpm exec vitest run resources/js/Pages/superAdmin/Notifications/__tests__/AdminNotifications.test.tsx
```

Expected: FAIL because the focused page does not exist.

- [ ] **Step 3: Implement the minimum operational inbox**

Reuse the existing notification hooks where their endpoint contract matches. Add only narrow page-local adaptation for the admin API's `notifications` plus nested `pagination` payload; do not generalize the large customer/ERP `NotificationList`, whose export/archive/preferences controls are unsupported for administrators. Normalize and serialize notification rows in the existing controller through one small private method or API Resource only if the repository already uses that pattern nearby; do not create a service for field selection.

Use `AppLayout`, accessible buttons, explicit pending/error states, and the existing mark/read/delete API. Label deletion as dismissal so the UI does not imply authoritative audit removal.

- [ ] **Step 4: Cut routes and callers over**

- Render the new page at canonical `/admin/notifications`.
- Change every duplicate legacy `/superAdmin/notification-communication-tools` declaration to a safe redirect to the canonical inbox.
- Remove the old controller import/action and fake page/controller only after the dependency scan is clean.
- Preserve `AppHeader`'s real notification API and canonical View All link.
- Do not add a sidebar communications item; the header notification center remains the direct inbox entry.

- [ ] **Step 5: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/AdminNotificationInboxTest.php tests/Feature/BusinessScaling/BusinessScalingNotificationTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Notifications/__tests__/AdminNotifications.test.tsx
git add app/Http/Controllers/Api/AdminNotificationController.php app/Http/Controllers/superAdmin/NotificationCommunicationToolsController.php routes/web.php resources/js/Pages/superAdmin/Communications/NotificationCommunicationTools.tsx resources/js/Pages/superAdmin/Notifications/AdminNotifications.tsx resources/js/Pages/superAdmin/Notifications/__tests__/AdminNotifications.test.tsx resources/js/layout/AppHeader.tsx tests/Feature/SuperAdmin/AdminNotificationInboxTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
git commit -m "feat: replace simulated admin communications with notifications"
```

Do not stage `AppHeader.tsx` if no contract fix was needed.

## Task 3: Remove Fake Customer Actions and Preserve Real Lifecycle Controls

**Files:**

- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx`
- Modify: `tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php`

- [ ] **Step 1: Extend frontend tests before editing**

Use fixtures for active, suspended, archived, pending, rejected, and legacy deactivated users. Assert:

- active users offer real suspend/archive/details controls as authorized by current behavior;
- suspended users offer real reactivate controls;
- archived users offer real restore/read-only detail behavior;
- pending/rejected/legacy-deactivated users remain visible as read-only records;
- no row or details modal offers Approve, Reject, Deactivate, or Reset Password;
- no test-observed request targets `/superAdmin/users/{id}/approve|reject` or an invented deactivation/reset endpoint;
- real lifecycle mutations still wait for the server result and preserve existing error handling.

Run:

```bash
pnpm exec vitest run resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx
```

Expected: FAIL because fake controls and handlers remain.

- [ ] **Step 2: Delete only fake behavior**

Remove:

- `handleApproval`, its confirmation/result dialogs, and both row/modal approval controls;
- local-only deactivation state, focus trap, reason modal, timeout, state mutation, and success dialog;
- local-only reset-password handler, modal/copy, and success dialog;
- imports/types used only by those paths.

Keep legacy statuses in display/filter types where records may still exist. Do not mutate or relabel historical database values and do not invent a migration in this phase.

- [ ] **Step 3: Reverify retained actions**

Run the existing frontend lifecycle test plus the backend account workflow suites so removing fake controls cannot accidentally remove real suspension/archive endpoints:

```bash
pnpm exec vitest run resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx
php artisan test tests/Feature/SuperAdmin/UserLifecycleWorkflowTest.php tests/Feature/SuperAdmin/AccountArchiveWorkflowTest.php
```

If those exact backend filenames differ, select the existing Phase 2 user lifecycle/archive tests by `rg --files tests/Feature/SuperAdmin | rg "User|Archive|Lifecycle"`; do not create duplicate backend coverage merely to satisfy a command name.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
git commit -m "fix: remove simulated customer management actions"
```

## Task 4: Repair Profile, Security Entry Points, Monitoring, and Capability-Aware Navigation

**Files:**

- Modify: `app/Models/SuperAdmin.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `app/Http/Controllers/SuperAdminAuthController.php`
- Modify: `app/Http/Controllers/superAdmin/SystemMonitoringDashboardController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/superAdmin/Settings/Profile.tsx`
- Modify: `resources/js/Pages/superAdmin/SystemMonitoringDashboard.tsx`
- Modify: `resources/js/components/header/SuperAdminDropdown.tsx`
- Modify: `resources/js/layout/AppSidebar.tsx`
- Create: `resources/js/Pages/superAdmin/__tests__/SystemMonitoringDashboard.test.tsx`
- Create: `resources/js/components/header/__tests__/SuperAdminDropdown.test.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar.test.tsx`
- Modify: `tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php`
- Create: `tests/Feature/SuperAdmin/PrivilegedProfileTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php`

- [ ] **Step 1: Write failing profile and capability-sharing tests**

Assert:

- `/admin/profile` renders only allowlisted identity fields for the authenticated privileged actor;
- Admin and Super Admin receive truthful role values/labels;
- no password hash, MFA material, recovery code, setup/reset token, bootstrap marker, session data, or full serialized model is present;
- the shared `auth.super_admin.capabilities` list comes from the model's fixed map and differs correctly by role;
- `/admin/security` remains the only password/MFA/recovery management destination;
- no route points to `SuperAdminAuthController::updatePassword`.

Run:

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedProfileTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
```

Expected: FAIL because canonical profile and shared capabilities are absent.

- [ ] **Step 2: Expose the fixed capability list safely**

Add a typed read-only model method that returns the current role's existing `CAPABILITIES_BY_ROLE` list. Reuse it in `hasCapability()` and the Inertia share rather than copying the matrix into middleware or TypeScript. Unknown roles return an empty list.

This data controls visibility only; every route keeps backend capability middleware.

- [ ] **Step 3: Canonicalize the profile/security entry**

- Add protected `admin.profile` GET using the existing profile controller method.
- Return a small explicit `admin` array: ID, first/last/display name, email, and role only.
- Delete the unrouted legacy `updatePassword()` method and orphaned `Hash` import after route inspection proves no caller.
- Make `Profile.tsx` consume only the explicit privileged prop, render "Admin" or "Super Administrator" truthfully, and link password/MFA/recovery management to `/admin/security`.
- Add Profile and Security links to `SuperAdminDropdown`, remove fake identity fallbacks, and keep logout server-driven. A logout error must not pretend logout succeeded.

- [ ] **Step 4: Write and satisfy monitoring truthfulness tests**

Frontend tests must prove:

- Recent Activity has one real link to `/admin/audit`;
- System Health and operational-count sections have no inert "See all" controls;
- "Performance Metrics" becomes an accurate operational-count label;
- account counts are described as current snapshots, not live performance telemetry;
- the banner says only what the database check proves and failed jobs remain a separate warning signal.

Backend tests retain authoritative activity scope and verify the health payload remains server-derived. Do not add synthetic CPU, memory, queue-worker, uptime, or provider health values.

Run:

```bash
php artisan test tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/__tests__/SystemMonitoringDashboard.test.tsx
```

- [ ] **Step 5: Migrate active navigation to canonical truthful routes**

Update the runtime `AppSidebar.tsx` to:

- use `auth.super_admin` and its server-derived capabilities;
- use `admin.system-monitoring`, `admin.shop-owner-registration-view`, and `admin.audit` where the canonical routes are behavior-equivalent;
- retain the current paginated `superAdmin.super-admin-user-management` GET until the later canonical route preserves its standalone-customer scope, filters, and pagination;
- hide administrator management and subscription/plan management unless the required fixed capability is present;
- add Audit History for both roles;
- remove the fake communications item;
- retain real report, flag, appeal, shop, user, registration, and upgrade-request links;
- fail closed rather than falling back to `#` for a privileged action.

Do not consolidate or delete the unrelated duplicate sidebar tree in this phase. Record it for Phase 7 after confirming `AppLayout` does not import it.

- [ ] **Step 6: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/PrivilegedProfileTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php tests/Feature/SuperAdmin/PhaseZeroAuthorizationTest.php
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar.test.tsx resources/js/components/header/__tests__/SuperAdminDropdown.test.tsx resources/js/Pages/superAdmin/__tests__/SystemMonitoringDashboard.test.tsx resources/js/Pages/superAdmin/Settings/__tests__/Security.test.tsx
git add app/Models/SuperAdmin.php app/Http/Middleware/HandleInertiaRequests.php app/Http/Controllers/SuperAdminAuthController.php app/Http/Controllers/superAdmin/SystemMonitoringDashboardController.php routes/web.php resources/js/Pages/superAdmin/Settings/Profile.tsx resources/js/Pages/superAdmin/SystemMonitoringDashboard.tsx resources/js/components/header/SuperAdminDropdown.tsx resources/js/components/header/__tests__/SuperAdminDropdown.test.tsx resources/js/layout/AppSidebar.tsx resources/js/layout/__tests__/AppSidebar.test.tsx resources/js/Pages/superAdmin/__tests__/SystemMonitoringDashboard.test.tsx tests/Feature/SuperAdmin/PrivilegedProfileTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
git commit -m "fix: connect privileged profile monitoring and navigation"
```

## Task 5: Withdraw Unsafe Subscription Intervention Until Phase 5

**Files:**

- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`
- Create: `resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx`
- Create: `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php`

- [ ] **Step 1: Write failing route and state-preservation tests**

Assert:

- all three old route names and URIs are absent;
- POST attempts to the old cancel/upgrade/downgrade URIs return `404`/`405` and cannot mutate subscription, payment, plan, entitlement, or activity rows;
- premium-plan management routes remain protected and operational;
- subscription-management GET remains read-only for subscription rows and restricted to `manage_plans`;
- Shop Owner authoritative billing routes are unchanged.

Run:

```bash
php artisan test tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php
```

Expected: FAIL because all three unsafe endpoints still exist.

- [ ] **Step 2: Write failing frontend containment tests**

Prove that the page still supports real plan create/edit/archive/reactivate and read-only subscription/history inspection, but has no Cancel, Deactivate, Upgrade, Downgrade, Refund, or adjustment mutation control for a subscription.

Historical labels such as an upgrade payment type or cancelled/deactivated record remain visible as evidence; the test must distinguish display from a mutation control.

Run:

```bash
pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx
```

Expected: FAIL because the Deactivate control still posts to the pseudo-refund endpoint.

- [ ] **Step 3: Remove the uncertified mutation surface**

- Delete cancel/upgrade/downgrade routes with no legacy mutation aliases.
- Delete the three controller methods and only imports made orphaned by their removal.
- Remove cancellation submission state, dialog, button, and mutation copy from the page.
- Keep payment/subscription history fields, filters, and plan-management workflows unchanged.
- Do not repair paid history, reinterpret legacy `deactivated`, or build the Phase 5 service here.

- [ ] **Step 4: Run focused tests and commit**

```bash
php artisan test tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx
git add routes/web.php app/Http/Controllers/SuperAdminController.php resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php
git commit -m "fix: withdraw unsafe subscription interventions"
```

## Task 6: Verify Dependency Removal and Phase Boundaries

**Files:**

- Modify only files required by verified findings
- Create: `docs/runbooks/super-admin-phase-4-surface-inventory.md` only if the final route/surface inventory is not adequately captured by tests and this plan
- Modify: `docs/ai-learning-log.md` only for a genuinely durable project lesson

- [ ] **Step 1: Run the final fake-surface scan**

```bash
rg -n "NotificationCommunicationTools|notification-communication-tools|send announcement|support ticket|Reset Password|handleApproval|handleDeactivate|/superAdmin/users/.*/(approve|reject)|subscriptions\.(cancel|upgrade|downgrade)|/admin/subscriptions/.*/(cancel|upgrade|downgrade)|refunded successfully|paid_amount.*0|See all|See All" app routes resources/js tests --glob '!public/build/**'
```

Expected remaining references are limited to:

- safe legacy GET redirect tests/route names for the old communications URL;
- historical/read-only subscription terminology;
- explicit negative assertions or Phase 5 debt documentation;
- unrelated non-Super-Admin domains.

Investigate every hit before classifying it. Do not delete another role's real report, export, support, password, approval, refund, or notification workflow.

- [ ] **Step 2: Inspect route ownership and generated-route impact**

```bash
php artisan route:list --path=admin
php artisan route:list --path=superAdmin
php artisan route:list --path=api/admin/notifications
rg -n "NotificationCommunicationToolsController|SuperAdminAuthController::updatePassword|cancelSubscription|upgradeSubscription|downgradeSubscription" app routes tests --glob '!public/build/**'
```

If generated Ziggy data is committed by this repository's normal build/generation workflow, regenerate it through that established command; do not hand-edit `resources/js/ziggy.js`. If route generation is not part of Phase 4's normal workflow, record the stale generated artifact for the existing build process rather than inventing a command.

- [ ] **Step 3: Preserve later-phase boundaries explicitly**

Confirm the final diff does not:

- add provider/payment behavior or rewrite legacy paid history;
- split the monolithic controller beyond removing the three displaced methods;
- delete unrelated duplicate route/layout structures;
- add a generic notification/communication abstraction;
- alter customer, Shop Owner, ERP, HR, finance, POS, order, or document routes.

- [ ] **Step 4: Commit only verified cleanup/documentation**

```bash
git add <only Phase 4 files changed by verified findings>
git commit -m "chore: remove retired privileged surface dependencies"
```

If no files changed, do not create an empty commit or unnecessary runbook.

## Task 7: Required Sequential Reviews and Final Verification

**Files:**

- Review all Phase 4 changes since `82f667f56`
- Modify only files required to resolve verified findings

- [ ] **Step 1: Run the required review stack sequentially**

Record one result for each:

1. **simplify / ponytail:** remove local fake state, unnecessary adapters, duplicate capability maps, speculative notification abstractions, and dead modal state. Reuse existing API/hooks/layouts where their contracts fit.
2. **Standards review:** Laravel route/controller conventions, allowlisted serialization, deterministic query order, React accessibility, focused responsibilities, and repository naming/import patterns.
3. **Spec review:** compare every Phase 4 acceptance criterion and frozen contract in this plan and the design authority.
4. **clean-code-typescript:** check explicit page/auth/API types, safe narrowing, no new `any`, focused components, real async error states, and no success state before server confirmation.
5. **karpathy-guidelines:** keep deletion surgical, surface assumptions, leave unrelated duplicate structures for Phase 7, and avoid building replacement platforms.
6. **code-splitting:** N/A unless the new inbox adds a measured heavy dependency; do not split a small page speculatively.
7. **gauge-improvements:** record before/after counts for fake controls/pages/routes, dead buttons, unsafe subscription mutation routes, and canonical working destinations. Bundle/latency is `not measured` unless captured.
8. **security-review:** inspect notification recipient scoping/IDOR, active/MFA middleware, capability sharing, profile serialization, stored action URLs, CSRF, removed credential path, and subscription endpoint absence.
9. **reuse/dead-code review:** confirm existing notification API, hooks, capability model, security page, audit page, monitoring controller, layouts, and lifecycle workflows are reused; scan for orphan imports/state/controllers/routes/tests.

- [ ] **Step 2: Run narrow backend/frontend suites**

```bash
php artisan test tests/Feature/SuperAdmin/PhaseFourSurfaceBoundaryTest.php tests/Feature/SuperAdmin/AdminNotificationInboxTest.php tests/Feature/SuperAdmin/PrivilegedProfileTest.php tests/Feature/SuperAdmin/SystemMonitoringDashboardTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Notifications/__tests__/AdminNotifications.test.tsx resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx resources/js/Pages/superAdmin/__tests__/SystemMonitoringDashboard.test.tsx resources/js/components/header/__tests__/SuperAdminDropdown.test.tsx resources/js/layout/__tests__/AppSidebar.test.tsx
```

- [ ] **Step 3: Run the Super Admin regression suites**

```bash
php artisan test tests/Feature/SuperAdmin
pnpm exec vitest run resources/js/Pages/superAdmin resources/js/layout/__tests__/AppSidebar.test.tsx resources/js/components/header/__tests__/SuperAdminDropdown.test.tsx
```

- [ ] **Step 4: Run broader verification**

```bash
composer test
pnpm run test:frontend
pnpm run build
git diff --check
git status --short
```

Do not report standalone TypeScript type-checking or linting as passed; the repository has no committed scripts for those checks.

- [ ] **Step 5: Browser verification for both retained roles**

With the local app running, verify:

- Admin and Super Admin see truthful names/roles and can open Profile, Security, Monitoring, Notifications, and their authorized Audit scope;
- only Super Admin sees administrator and plan-management navigation;
- the header notification dropdown and `/admin/notifications` show only the current actor's real rows; read/dismiss survives reload;
- the old communications URL redirects and no announcement/alert/ticket/settings UI remains;
- active/suspended/archived users show only real lifecycle controls; pending/rejected/deactivated legacy users are read-only;
- monitoring has one working Audit link and no inert "See all" control or overstated telemetry;
- plan create/edit/archive/reactivate still persists;
- subscription history remains visible but no cancel/deactivate/upgrade/downgrade/refund intervention is callable;
- direct POSTs to withdrawn subscription URLs do not mutate state;
- old data-report URLs still redirect to authoritative audit with no export UI.

- [ ] **Step 6: Commit review fixes and final evidence**

```bash
git add <only Phase 4 files changed by verified findings>
git commit -m "test: verify phase 4 truthful privileged surfaces"
```

If no files changed after review, do not create an empty commit.

---

## Phase 4 Acceptance Checklist

- [ ] No visible Super Admin action reports success without a registered protected endpoint and persisted authoritative result.
- [ ] Fake announcements, alerts, support tickets, communication settings, exports, and report generation are not reachable or present in runtime navigation.
- [ ] `/admin/notifications` is a real recipient-scoped operational inbox; dismissal never changes privileged audit history.
- [ ] The old communications GET is safe compatibility only and never renders the retired page.
- [ ] Customer approval/rejection, local deactivation, and fake administrator password-reset controls are removed.
- [ ] Real suspension/reactivation/archive/restore/details/private-document controls still work and remain backend authorized.
- [ ] Legacy customer statuses remain visible as read-only evidence rather than being silently rewritten.
- [ ] Profile serialization is allowlisted, role labels are truthful for both roles, and own credential/MFA/recovery management uses `/admin/security` only.
- [ ] Navigation consumes the server-owned fixed capability list, fails closed for restricted links, and never replaces backend authorization.
- [ ] Monitoring shows only measured server data, has one real Audit destination, and has no inert detail controls or overstated performance/availability claims.
- [ ] Premium-plan management and read-only subscription/payment history remain available to authorized Super Admins.
- [ ] Old Super Admin cancel/upgrade/downgrade routes, methods, and controls are absent; Phase 5 owns any canonical replacement.
- [ ] No pseudo-refund, paid-history rewrite, direct plan swap, or provider claim remains callable through the Super Admin module.
- [ ] Existing customer, Shop Owner, ERP, HR, finance, POS, order, and document workflows are unchanged.
- [ ] Safe data-report aliases still redirect to authoritative audit and no fake export control returns.
- [ ] Focused backend/frontend tests, full suites, build, route inspection, dependency scan, and browser flows have fresh recorded evidence.

## Rollback and Recovery Notes

- Phase 4 contains route/page/controller removal but no schema migration. Roll back the application commit set if a retained real surface regresses.
- Do not restore simulated UI as a production fallback. If the admin inbox page fails, the header API/dropdown can remain while the page is corrected.
- Withdrawing subscription endpoints intentionally removes unsafe capability. Do not restore pseudo-refund/direct-plan-swap methods to recover availability; proceed to the approved Phase 5 plan or keep intervention unavailable.
- Existing subscription/payment/history rows are not modified by this phase and need no data rollback.
- Operational notification dismissal is not audit deletion. Audit remains recoverable through `/admin/audit` regardless of inbox state.
- Safe legacy GET redirects may remain until Phase 7 verifies stored links and callers have migrated. No removed mutation receives a compatibility alias.

## Execution Order

```text
freeze reachable-surface contract
        -> real admin notification inbox
        -> remove fake customer actions
        -> repair profile/security/monitoring/navigation
        -> withdraw unsafe subscription intervention
        -> dependency and phase-boundary scan
        -> sequential reviews and full verification
```

No task is complete based solely on the absence of a button. Route reachability, persisted behavior, recipient/object scope, backend authorization, reload behavior, negative paths, and direct endpoint access must be verified independently.
