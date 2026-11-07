# Age Validation and Suspension Age Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce a maximum age of 100 in the three reported forms with a clear `Invalid age` SweetAlert on `101`, and display suspension-request age as whole elapsed days.

**Architecture:** Keep the existing form handlers and SweetAlert wrapper. Let the age inputs retain up to three digits so an out-of-range value can reach submit/Next validation, while the existing client and Laravel guards reject it. Cast Carbon's float day difference to an integer at the API presenter boundary so every suspension approval view receives a stable day count.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia, React 18, TypeScript 5.7, Vitest, PHPUnit, SweetAlert2.

## Global Constraints

- Preserve existing form styling, authorization, address handling, and approval workflow.
- The accepted age range for the requested forms is an integer from 0 through 100; `/register` keeps its existing minimum of 18.
- Validate user input server-side as well as in the browser.
- Do not add dependencies, run destructive database commands, commit, or push.

---

### Task 1: Add failing regression coverage

**Files:**
- Modify: `resources/js/Pages/ERP/HR/__tests__/EmployeeDirectory.employeeFormLayout.test.ts`
- Modify: `resources/js/Pages/ShopOwner/TeamManagement/__tests__/UserAccessControl.employeeFormLayout.test.ts`
- Modify: `resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx`
- Modify: `tests/Feature/UserSide/CustomerRegistrationAddressTest.php`
- Modify: `tests/Feature/Manager/ManagerSuspensionApprovalTest.php`

**Interfaces:**
- Tests observe the existing add-employee handlers, registration Next handler, Laravel registration endpoint, and manager suspension API response.
- No production interfaces are added.

- [x] **Step 1: Write the failing frontend contracts and behaviors**

  Assert that the two employee source contracts no longer filter `Number(value) <= 100` during typing, and that the customer registration test expects a SweetAlert with title `Invalid age` and text `Age must be a whole number from 18 to 100.` when `101` is entered and Next is clicked. Assert the registration age input exposes `max="100"`.

- [x] **Step 2: Write failing Laravel regressions**

  Add a registration request test posting the existing fixture payload with `age => 101`, expecting a validation error on `age` and no created user. Add a suspension list/detail assertion using a request created seconds ago and expect JSON `age_days` to be integer `0`, not a fractional value.

- [x] **Step 3: Run focused tests to verify the failures**

  Run:

  ```powershell
  cmd /c node_modules\.bin\vitest.cmd run resources\js\Pages\ERP\HR\__tests__\EmployeeDirectory.employeeFormLayout.test.ts resources\js\Pages\ShopOwner\TeamManagement\__tests__\UserAccessControl.employeeFormLayout.test.ts resources\js\Pages\UserSide\Auth\__tests__\Register.test.tsx --reporter=verbose
  cmd /c vendor\bin\phpunit.bat tests\Feature\UserSide\CustomerRegistrationAddressTest.php tests\Feature\Manager\ManagerSuspensionApprovalTest.php
  ```

  Expected result: the new assertions fail against the current max-120 registration, submit-blocking employee inputs, and Carbon float day output.

### Task 2: Implement the smallest validation and display fixes

**Files:**
- Modify: `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`
- Modify: `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx`
- Modify: `resources/js/Pages/UserSide/Auth/Register.tsx`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Http/Controllers/Erp/Manager/SuspensionApprovalController.php`

**Interfaces:**
- Employee add handlers continue to submit integer ages and show their existing `Invalid Age` SweetAlert.
- `Register.tsx` continues to use `UserModal.fire` through `showValidationModal`, with a dedicated age-invalid alert on Step 2.
- Suspension API `age_days` remains numeric but is an integer number of elapsed days.

- [x] **Step 1: Allow `101` to remain editable while retaining submit guards**

  Keep each employee input's `min`, `max`, and integer keyboard restrictions, but change the input handlers to accept up to three digit characters without checking `Number(value) <= 100`. The existing add handlers already reject values above 100 with `Invalid Age`.

- [x] **Step 2: Enforce `/register` max 100 in both layers**

  Change the Step 2 client error to `Age must be a whole number from 18 to 100.`, add `max="100"` and integer step guidance to the age input, and show `Swal.fire({ icon: 'error', title: 'Invalid age', text: stepErrors.age, ... })` before the generic modal when Step 2 age validation fails. Change `UserController` validation from `max:120` to `max:100` and update its max message.

- [x] **Step 3: Normalize suspension request age**

  Cast `$createdAt->diffInDays(now())` to `(int)` in `SuspensionApprovalController::mapRequest`, preserving `0` for new requests and all existing SLA-minute behavior.

- [x] **Step 4: Run the focused tests and adjust only implementation defects**

  Re-run the commands from Task 1. Expected result: all new and existing focused tests pass.

### Task 3: Verify scope and production build

**Files:**
- Inspect only; no additional files should be changed.

- [x] **Step 1: Run the relevant production checks**

  Run:

  ```powershell
  cmd /c node_modules\.bin\vitest.cmd run resources\js\Pages\ERP\HR\__tests__\EmployeeDirectory.employeeFormLayout.test.ts resources\js\Pages\ShopOwner\TeamManagement\__tests__\UserAccessControl.employeeFormLayout.test.ts resources\js\Pages\UserSide\Auth\__tests__\Register.test.tsx --reporter=verbose
  cmd /c vendor\bin\phpunit.bat tests\Feature\UserSide\CustomerRegistrationAddressTest.php tests\Feature\Manager\ManagerSuspensionApprovalTest.php
  cmd /c pnpm.cmd run build
  ```

  The pinned pnpm command was blocked by Corepack registry-signature verification; the equivalent local Vite build completed successfully with only the existing unresolved auth-pattern asset warning. The focused Vitest and PHPUnit checks passed; the full manager feature file retains 11 pre-existing read-only-mode mutation failures (HTTP 423).

- [x] **Step 2: Inspect final working-tree scope**

  Run `git status --short`, `git diff --stat`, `git diff`, and `git diff --check`. Confirm only the requested source/tests/plan files changed, no debug output or generated build artifacts were unintentionally added, and no unrelated user work was touched.
