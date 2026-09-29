# Employee profile and Philippine address fields implementation plan

> **Execution:** implement sequentially in this session; do not commit or push.

## Objective

Add optional employee suffix plus structured Philippine address fields to both Shop Owner User Access Control and HR employee creation, persist them compatibly, and expose them to downstream employee pages.

## Acceptance criteria

- Both add-employee modals show suffix, address, province, city/municipality, and postal code.
- Province contains the complete existing dataset; city/municipality options depend on province and clear when province changes.
- Postal code is restricted to four digits at the UI and validated server-side.
- Address details persist for owner and HR-created employees and linked users.
- Owner and HR employee payloads expose `suffix`, `province`, `city_municipality`, and `postal_code` without removing old fields.
- Existing employee creation, authorization, role assignment, and legacy records continue to work.

## Files and steps

### 1. Establish regression coverage before implementation

Update `tests/Feature/HR/EmployeeControllerTest.php` and `tests/Feature/ShopOwner/UserAccessControlEmployeeRoleCreationTest.php` with creation requests containing suffix and structured address data, then assert employee legacy columns, new suffix, linked-user fields, and response aliases. Add `resources/js/components/employee/__tests__/PhilippineAddressFields.test.tsx` to verify rendering, dependent city options, clearing on province change, and four-digit postal input filtering. Run only those tests first and confirm the new assertions fail before production changes.

### 2. Add additive persistence

Create `database/migrations/2026_09_26_000001_add_suffix_to_employees_table.php` and `database/migrations/2026_09_26_000002_add_structured_address_to_users_table.php` with nullable columns only. Update `app/Models/Employee.php` and `app/Models/User.php` fillable arrays for the new fields. Do not alter existing migrations or generated/vendor files.

### 3. Add the shared address UI

Create `resources/js/components/employee/PhilippineAddressFields.tsx`. Reuse `MonochromeSelect` and `PHILIPPINE_LOCATIONS` helpers, derive city options during render, disable city until province is selected, reset city on province change, and filter postal input to four digits. Keep labels and styling aligned with existing modals and make controls keyboard/label accessible.

### 4. Wire both React employee forms

Update `resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx` and `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx` to add suffix and structured address state, render the shared fields in add modals, include fields in owner/HR request payloads, map response aliases into page employee types, and reset the added state after successful creation. Preserve existing lifecycle/re-hire modal behavior.

### 5. Persist and expose backend fields

Update `app/Http/Controllers/ShopOwner/UserAccessControlController.php` and `app/Http/Controllers/ERP/HR/EmployeeController.php` to validate suffix/address fields, map province/city/postal values to existing employee columns, save linked-user profile fields, and include stable response aliases in index/show/create/update payloads. Keep existing authorization, role validation, uniqueness checks, redirects, and legacy request fields intact.

### 6. Verify and review

Run the focused frontend test, focused Laravel tests, `pnpm run test:frontend`, `pnpm run build`, and `git diff --check`. Inspect `git status --short`, `git diff --stat`, and the complete diff. Review for tenant/security boundaries, unused imports, unnecessary abstractions, stale state, duplicate submissions, and accidental changes outside this feature. If browser runtime is available, use the webapp-testing Playwright flow to inspect the rendered dependent dropdowns; report if login/server setup prevents it.
