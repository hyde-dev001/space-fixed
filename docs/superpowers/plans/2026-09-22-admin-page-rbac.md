# Admin Page-Based RBAC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add secure, explicit page access for regular SoleSpace admins while preserving the existing privileged security architecture.

**Architecture:** A central PHP page catalog owns stable keys, labels, assignability, and route semantics. A transactional page-access service and reusable middleware enforce access on the server; existing capability middleware remains responsible for normal page actions. Inertia receives only safe page keys for navigation and dashboard/notification presentation.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL, Inertia 2, React 18, TypeScript 5.7, Vitest.

---

### Task 1: Establish page catalog and persistence

**Files:**
- Create: `app/Enums/AdminPage.php`
- Create: `app/Models/AdminPagePermission.php`
- Modify: `app/Models/SuperAdmin.php`
- Create: `database/migrations/2026_09_22_000001_create_admin_page_permissions_table.php`
- Create: `database/migrations/2026_09_22_000002_backfill_admin_page_permissions.php`
- Test: `tests/Feature/SuperAdmin/AdminPagePermissionSchemaTest.php`

- [ ] Write schema and backfill tests first.
- [ ] Run the focused tests and confirm failure because the table/catalog is absent.
- [ ] Add the enum/catalog and model relation with a unique database constraint.
- [ ] Add the migration and backfill all delegable keys for existing `admin` accounts only.
- [ ] Run the focused tests.

### Task 2: Add centralized authorization and management service

**Files:**
- Create: `app/Services/AdminPageAccessService.php`
- Create: `app/Http/Middleware/EnsurePrivilegedPageAccess.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Support/PrivilegedFailureResponse.php`
- Create: `app/Http/Requests/Privileged/UpdateAdminPageAccessRequest.php`
- Modify: `app/Services/AdministratorIdentityService.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Services/PrivilegedAuditVisibility.php`
- Test: `tests/Feature/SuperAdmin/AdminPageAuthorizationTest.php`

- [ ] Write failing tests for Super Admin bypass, regular allow/deny, forged keys, self-management, immediate revocation, and inactive/suspended/MFA states.
- [ ] Run the focused test file and confirm expected failures.
- [ ] Implement catalog-backed authorization and standardized 403 responses.
- [ ] Implement atomic assignment and role-transition row handling without touching credential/security state.
- [ ] Add the audit event with old/new/added/removed keys.
- [ ] Run the focused test file and existing identity lifecycle tests.

### Task 3: Protect all admin route families

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/superAdmin/AdministratorManagementController.php`
- Test: `tests/Feature/SuperAdmin/AdminPageRouteBoundaryTest.php`

- [ ] Add route-boundary tests for representative GET, POST/PATCH, download/media, platform-fee, subscription, and notification endpoints.
- [ ] Apply `privileged.page` to all mapped route families while preserving current capability/recent middleware.
- [ ] Keep Administrator Management and Maintenance Super Admin-only.
- [ ] Run the route-boundary tests and `php artisan route:list --path=admin`.

### Task 4: Add page-access provisioning and editing UI

**Files:**
- Modify: `app/Http/Requests/Privileged/InviteAdministratorRequest.php`
- Modify: `app/Http/Controllers/superAdmin/AdministratorManagementController.php`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/CreateAdmin.tsx`
- Modify: `resources/js/Pages/superAdmin/AdminTeam/AdminManagement.tsx`
- Test: `resources/js/Pages/superAdmin/AdminTeam/__tests__/AdminManagement.test.tsx`

- [ ] Add frontend tests for selectable assignable pages and hidden Super-Admin-only pages.
- [ ] Add validated page-access data to invitation and update endpoints.
- [ ] Add an accessible checkbox section for regular Admins and an edit-access modal from Admin Management.
- [ ] Preserve the existing invitation/setup/MFA flow and existing SweetAlert confirmations.
- [ ] Run focused frontend tests.

### Task 5: Make shared navigation, Dashboard, and notifications permission-aware

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `app/Http/Controllers/superAdmin/SystemMonitoringDashboardController.php`
- Modify: `resources/js/layout/AppSidebar.tsx`
- Modify: `resources/js/Pages/superAdmin/SystemMonitoringDashboard.tsx`
- Modify: `app/Http/Controllers/Api/AdminNotificationController.php`
- Modify: `resources/js/components/header/NotificationCenter.tsx` only if the API contract requires it
- Test: `tests/Feature/SuperAdmin/AdminPagePresentationSecurityTest.php`
- Test: `resources/js/layout/__tests__/AppSidebar.test.tsx`

- [ ] Write failing tests for safe shared props, hidden empty groups, dashboard filtering, and restricted notification links.
- [ ] Share only role and page keys; do not share privileged secrets or security fields.
- [ ] Make Dashboard always available but omit restricted metrics and links server-side.
- [ ] Filter admin notification action URLs/content by the same page catalog.
- [ ] Update sidebar rendering to use page access, not broad capabilities.
- [ ] Run focused backend/frontend tests.

### Task 6: Full verification and review

**Files:**
- Modify only if verification finds defects.
- Update: `docs/ai-learning-log.md` only for a durable project lesson.

- [ ] Run `git diff --check`.
- [ ] Run the focused PHP tests, existing Super Admin tests, and frontend tests.
- [ ] Run `pnpm run build` if pnpm is available; otherwise use the repository’s local Vite command and record the limitation.
- [ ] Review changed PHP for validation, authorization, mass-assignment, transaction, audit, and secret-exposure issues.
- [ ] Review changed TS/TSX for typed boundaries, accessibility, stale permission state, and unnecessary rerenders.
- [ ] Confirm unrelated rider files remain unchanged by this work.
