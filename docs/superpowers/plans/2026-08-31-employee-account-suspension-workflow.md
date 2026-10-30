# Employee Account Suspension Workflow Implementation Plan

> **For agentic workers:** Execute this plan task-by-task with focused verification after each task.

**Goal:** Make employee suspension approval-driven and tenant-scoped while keeping employee activation in the company-scoped Employee Directory.

**Architecture:** A suspension request remains pending without changing employee or linked-user access. Manager approval advances the request; final Shop Owner approval atomically changes both records to suspended. Direct HR suspension/status mutations use the approval workflow. Shop Owner and authorized HR directory users retain the company-scoped `Activate Account` action, while platform-admin user-management routes remain separate.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit/Pest feature tests, Inertia/React 18, TypeScript, Vitest.

---

### Task 1: Lock the intended server workflow with regression tests

**Files:**
- Modify: `tests/Feature/HR/EmployeeControllerTest.php`
- Create or modify: `tests/Feature/HR/SuspensionRequestControllerTest.php`
- Modify: `tests/Feature/HR/ManagerSuspensionApprovalTest.php` (if the existing file is named differently)
- Modify: `tests/Feature/ShopOwner/ShopOwnerSuspensionApprovalScopeTest.php`

- [x] Write tests proving a pending request leaves the employee and linked user active, is scoped to the requester’s shop, and cannot target another shop.
- [x] Write tests proving HR direct suspend/status mutations cannot bypass the request workflow while Employee Directory activation remains available and tenant-scoped.
- [x] Write tests proving final owner approval persists `suspension_reason` and synchronizes the linked user atomically.
- [x] Run the focused tests and confirm the new assertions fail for the current implementation.

### Task 2: Make the backend workflow authoritative

**Files:**
- Modify: `app/Http/Controllers/Erp/HR/SuspensionRequestController.php`
- Modify: `app/Http/Controllers/Erp/HR/EmployeeController.php`
- Modify: `app/Http/Controllers/EmployeeController.php`
- Modify: `routes/hr-api.php`
- Modify: `routes/web.php` only if removing the legacy employee activation endpoint is required

- [x] Scope HR request queries and employee lookup through the authenticated user’s `shop_owner_id`.
- [x] Use the `user` guard explicitly for requester identity and notifications.
- [x] Stop changing employee status when a request is created; retain active access until final approval.
- [x] Remove or deny direct HR employee suspend/status transitions that bypass Manager/Owner approval; retain the direct HR directory activation endpoint for suspended employee accounts.
- [x] Keep the owner’s existing direct suspension behavior where it is the approved final-authority path, but persist the correct snake-case reason and synchronize records transactionally.
- [x] Keep the employee-directory activation mutation path on the shop-scoped employee endpoint; preserve unrelated platform-admin user activation routes and never call them from the directory.
- [x] Ensure linked-user lookups are tenant-safe and failures cannot leave employee/user records partially updated.

### Task 3: Remove misleading frontend controls and state changes

**Files:**
- Modify: `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`
- Modify: `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx` only if the legacy fake account action is still rendered or reachable
- Modify: `resources/js/Pages/ShopOwner/TeamManagement/suspendAccount.tsx` only if response/status wording needs alignment

- [x] Add/retain a visible `Activate Account` button, handler, and shop-scoped endpoint call in Employee Directory; never use the platform-admin user endpoint.
- [x] Do not mark a row inactive after submitting a pending request; refresh or show a pending-request state without changing access status.
- [x] Keep the suspension modal wording consistent with the actual approval flow.
- [x] Remove only frontend code made obsolete by the activation change and preserve unrelated employee-management UI.

### Task 4: Verify and review

**Files:**
- Inspect all changed files and relevant routes/tests.

- [x] Run focused Laravel tests.
- [x] Run focused frontend tests, `pnpm run build`, and `git diff --check`.
- [x] Review authorization, tenant scoping, transaction boundaries, and platform-admin route separation.
- [x] Report changed files, fixed root causes, workflow rules, test results, and remaining edge cases.
