# Employee Termination and Rehire Workflow Plan

## Contract

- Termination is a permanent close of the current employment period, not a
  direct account toggle.
- The approval chain remains HR request -> Manager review -> Shop Owner final
  approval for company employees.
- Final termination sets the employee status to `terminated` and synchronizes
  the linked user account to `inactive` in one transaction.
- A terminated employee cannot use the ordinary Activate action. Rehire is an
  explicit workflow that requires a new start date and reviewed employment
  attributes before the old account is restored.
- Previous employment periods remain queryable and are never overwritten.
- Suspension remains the temporary restriction workflow and is not merged with
  termination or rehire state.

## File-level work

1. Add lifecycle request/status models, migrations, factories, authorization,
   and employment-period history.
2. Add HR submission, Manager review, Shop Owner final approval, and guarded
   rehire finalization endpoints, reusing the existing scoped transaction,
   audit, and notification conventions.
3. Reject direct `terminated` mutations and expose separate Terminate/Rehire
   actions in the employee directory.
4. Add Manager approval UI/API hooks and add termination/rehire records to the
   existing Shop Owner Action Center.
5. Add focused feature and frontend regression tests.

## Verification

- Focused Laravel termination/rehire and existing employee workflow tests.
- Focused frontend approval/directory tests.
- `git diff --check` and the repository frontend build.

