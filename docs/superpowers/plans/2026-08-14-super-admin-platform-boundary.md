# Super Admin Platform Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Execute inline in the current worktree with test-first checkpoints.

**Goal:** Make Super Admin notifications reflect platform-level review work and make User Management a customer-only surface that does not expose shop employee HR data.

**Architecture:** Reuse the existing `notifications` table and `NotificationType` enum. Registration and appeal workflows will write scoped in-app rows for active Super Admins, while the existing business-upgrade and document-renewal producers remain the source of truth. The Super Admin user read model and page will return/render customer account fields only; shop employee records remain under shop ERP routes.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit feature tests, Inertia 2, React 18, TypeScript, Vite/Vitest.

---

### Task 1: Notification regressions

**Files:**
- Modify: `tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php`
- Modify: `tests/Feature/SuperAdmin/AccountLifecycleWorkflowTest.php` or a focused Super Admin notification test

- [x] Add a failing test that a successful registration creates an unread `SHOP_REGISTRATION_PENDING` notification for an active Super Admin.
- [x] Add a failing test that a submitted suspension appeal creates an in-app notification for eligible Super Admin reviewers without storing the appeal text in the notification payload.
- [x] Run the focused tests and confirm they fail because the producers are missing.

### Task 2: Platform notification producers

**Files:**
- Modify: `app/Http/Controllers/ShopOwnerAuthController.php`
- Modify: `app/Enums/NotificationType.php`
- Modify: `app/Services/SuspensionAppealService.php`

- [x] Write the minimal registration/re-submission notification after the registration transaction commits, linking to `/admin/registrations?status=pending` and including only safe identifiers.
- [x] Add the suspension-appeal notification type and create one unread high-priority row per eligible reviewer.
- [x] Run the focused tests and confirm they pass.

### Task 3: Customer-only Super Admin user management

**Files:**
- Modify: `app/Http/Controllers/superAdmin/UserInterventionController.php`
- Modify: `resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Users/__tests__/SuperAdminUserLifecycle.test.tsx` if contract assertions need updating
- Modify: `tests/Feature/SuperAdmin/PhaseEightScaleBoundaryTest.php`

- [x] Add a failing boundary test proving a shop-linked user is absent and customer payloads contain no employee/HR object.
- [x] Remove employee/shop-owner eager loads and department filtering from the customer read model.
- [x] Remove the department filter, employee column, and HR details from the page; rename visible copy to customer accounts while retaining customer lifecycle controls.
- [x] Verify that shop-owned employee routes remain the only employee-management surface.

### Task 4: Quality gates

- [x] Run focused Laravel tests for registration, notifications, Super Admin user management, and lifecycle authorization.
- [x] Run `git diff --check`.
- [x] Run the frontend test/build commands available in the repository; report any existing dependency/tooling failure without changing dependencies.
- [x] Review the diff for tenant isolation, authorization, sensitive-data exposure, unused fields/imports, and unrelated changes.

### Task 5: Bounded GPS location actions

**Files:**
- Add: `resources/js/utils/geolocation.ts`
- Modify: `resources/js/components/address/CustomerAddressMapPicker.tsx`
- Modify: `resources/js/Pages/UserSide/Auth/Register.tsx`
- Modify: `resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`
- Modify: `resources/js/Pages/UserSide/Products/Products.tsx`
- Modify: `resources/js/components/address/__tests__/CustomerAddressMapPicker.test.tsx`

- [x] Add a regression assertion that GPS requests use a finite timeout and do not reuse stale coordinates.
- [x] Apply the bounded GPS options to registration, shop settings, customer address, and nearby-products location actions.
- [x] Ensure shop settings always exits the loading state when GPS or reverse geocoding fails.
- [x] Run the focused GPS/address frontend test and refresh the public production build.
