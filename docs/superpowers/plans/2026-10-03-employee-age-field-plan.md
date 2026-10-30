# Employee Age Field Implementation Plan

> Execute this plan sequentially. Keep the existing employee directory behavior and UI conventions unchanged outside the requested age field.

## Goal

Make age required for new employees, restrict it to integer values from 0 through 100, persist it, return it from employee APIs, and display it in Employee Details.

## Acceptance criteria

- Add Employee has a required Age field using the existing form styling.
- The UI prevents/submits no negative, decimal, scientific-notation, non-integer, over-100, or blank age values.
- The Laravel endpoint independently rejects the same invalid values.
- Valid ages 0 and 100 persist on both `employees` and the linked `users` row.
- HR and shop-owner payloads expose age; frontend mapping preserves `0`.
- Employee Details shows the fetched age; legacy null values render as `—`.
- Existing suffix/address display and lifecycle action behavior remain unchanged.

## Files

- Add `database/migrations/2026_10_03_000001_add_age_to_employees_table.php`.
- Modify `app/Models/Employee.php`.
- Modify `app/Http/Controllers/Erp/HR/EmployeeController.php`.
- Modify `app/Http/Controllers/ShopOwner/UserAccessControlController.php`.
- Modify `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`.
- Modify `tests/Feature/HR/EmployeeControllerTest.php`.
- Modify `resources/js/Pages/ERP/HR/__tests__/EmployeeDirectory.employeeFormLayout.test.ts` and/or the existing lifecycle contract test only as needed.
- Regenerate tracked `public/build` with the repository's build command.

## Sequence

1. Add/extend regression tests first:
   - valid age is accepted, persisted, returned, and visible in the API response;
   - missing, negative, decimal, non-integer, and greater-than-100 ages return validation errors;
   - frontend source contract includes required age input attributes, payload mapping, API transform, and details rendering.
   Run the narrow tests and record the expected failures.
2. Add the nullable additive employee migration, model fillable field, and integer cast. Keep the column nullable for pre-existing rows while creation validation remains required.
3. Update the HR controller's create/update validation, employee persistence, linked-user synchronization, and payload mapping. Update the shop-owner projection payload so both directory modes can fetch age.
4. Update the React employee type, API transform, add form state/reset, client validation/submission payloads, input layout, and Employee Details display. Preserve `0` with nullish handling.
5. Run focused backend/frontend tests, then the frontend production build. Inspect the generated build and final diff for unrelated changes and whitespace errors.

## Verification commands

```powershell
cmd /c vendor\bin\phpunit.bat tests\Feature\HR\EmployeeControllerTest.php
cmd /c node_modules\.bin\vitest.cmd run resources\js\Pages\ERP\HR\__tests__\EmployeeDirectory.employeeFormLayout.test.ts resources\js\Pages\ERP\HR\__tests__\EmployeeDirectory.lifecycle.test.ts --reporter=verbose
cmd /c pnpm.cmd run build
git diff --check
```

Do not run destructive database commands. Do not commit or push until the requested implementation and fresh build have been verified.
