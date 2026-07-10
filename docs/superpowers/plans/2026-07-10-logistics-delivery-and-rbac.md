# Logistics Delivery and RBAC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add dispatcher-approved proof of delivery and make Logistics authorization consistently permission-driven.

**Architecture:** First inventory every Logistics route/controller/API/file endpoint and map it to one server-side capability service reused by controllers and request authorization. Preserve existing permissions as narrow compatibility aliases and add only granular capabilities used by real pages/actions; use the application's established tenant relationships/scopes for every record query. Extend the existing shipment-leg/proof state machine rather than adding a new delivery subsystem.

**Tech Stack:** Laravel, Spatie Permission, Inertia React, PHPUnit, TypeScript.

---

### Task 1: Inventory the Logistics authorization surface and establish RBAC failures as feature tests

**Files:**
- Modify: `routes/web.php`
- Modify: `routes/api.php` (if Logistics routes are defined there)
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`

- [ ] List every Logistics route, controller action, API endpoint, source-module action, customer tracking endpoint, and proof file URL, then map each to its least-privilege capability and update the actual route file/middleware where the audit finds a mismatch.
- [ ] Write tests for an HR, Manager, Staff, and custom-role user receiving direct and role-derived Logistics permissions from both assignment flows.
- [ ] Run the focused tests and confirm they fail where access is incorrectly coupled to a page permission or role behavior.
- [ ] Add cross-tenant assertions for every newly exercised endpoint.

### Task 2: Centralize Logistics capabilities and tenant authorization

**Files:**
- Create: `app/Services/Logistics/LogisticsAuthorizationService.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Http/Controllers/Api/Logistics/RiderProfileController.php`

- [ ] Write failing tests defining each capability and its accepted permissions.
- [ ] Implement the smallest service that checks the authenticated `user` guard's permissions, including `approve-proof-of-delivery`, and defines exact narrow legacy aliases.
- [ ] Replace duplicated role/dispatcher checks and dashboard fallbacks with capability checks; use established tenant relationships/scopes rather than generic IDs.
- [ ] Run the focused feature tests.

### Task 3: Make User Access Control and HR permission assignment consistent

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/UserAccessControlController.php`
- Modify: `app/Http/Controllers/Erp/HR/EmployeeController.php`
- Modify: `tests/Feature/Logistics/LogisticsEmployeeRoleAccessTest.php`

- [ ] Write failing tests that grant a Logistics permission through each interface and assert `can()` is true after refresh.
- [ ] Add Logistics grouping/allow-list handling to the existing permission partitioning and ensure both paths use the `user` guard and forget Spatie's cached permissions.
- [ ] Run the focused tests.

### Task 4: Add the minimal granular permissions

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `tests/Feature/Logistics/LogisticsSeederTest.php`

- [ ] Write tests for all required Logistics permissions.
- [ ] Seed the granular permissions with the project's existing kebab-case naming and grant the existing dispatcher/rider roles only the capabilities they require.
- [ ] Document and test the compatibility aliases: existing view/status/proof permissions only grant their original narrow capability and never create/delete/cancel/reassign/approve actions.
- [ ] Run the seeder tests.

### Task 5: Add proof approval to the existing shipment-leg state machine

**Files:**
- Create: `database/migrations/*_add_proof_review_fields_to_handoff_proofs_table.php`
- Modify: `app/Models/Logistics/HandoffProof.php`
- Modify: `app/Enums/Logistics/ShipmentLegStatus.php`
- Modify: `app/Services/Logistics/ProofService.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Logistics/ShipmentLegServiceTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`

- [ ] Write failing tests: a rider submits proof, cannot complete delivery, a user with `approve-proof-of-delivery` approves to deliver, rejection returns the leg to in transit and allows a new proof while retaining the old proof/reviewer audit trail, and approval completes only a shipment's final outstanding leg.
- [ ] Add proof review status, reviewer identity/time, and rejection reason; add `awaiting_proof_approval` as the one new leg status.
- [ ] Require approved delivery proof before `markDelivered` and authorize review with the dispatcher approval capability.
- [ ] Run the focused service/API tests.

### Task 6: Build the rider actions and dispatcher proof review UI

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`

- [ ] Write the permissions/page-data assertions first.
- [ ] Render rider-only actions for assigned legs and proof review controls only for `approve-proof-of-delivery`, using existing axios/Inertia patterns.
- [ ] Protect proof preview/download through a controller/temporary authorized endpoint requiring `view-proof-of-delivery`; do not expose public storage paths directly.
- [ ] Gate each sidebar entry and action by its capability; do not use roles.
- [ ] Run focused PHP tests and the frontend type/build check.

### Task 7: Verify the full audit matrix

**Files:**
- Modify: `tests/Feature/Logistics/LogisticsPageAccessTest.php`
- Modify: `tests/Feature/Logistics/LogisticsApiTest.php`

- [ ] Add the requested matrix: shop owner, HR, manager, staff view-only, delivery manager, rider, unauthorized user, direct/role permissions from both flows, permission-cache refresh, direct URLs/API/file requests, and tenant isolation.
- [ ] Run `php artisan test --filter=Logistics`.
- [ ] Run the applicable frontend type/build command from `package.json`.
- [ ] Review every Logistics route and API endpoint against the capability map before committing only the Logistics files.
