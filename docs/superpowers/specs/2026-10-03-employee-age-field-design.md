# Employee Age Field Design

## Outcome

Add a required age field to the HR Add Employee flow. New employee submissions
must contain a whole-number age from 0 through 100, and the saved age must be
returned by the employee API and shown in Employee Details.

## Scope

- Add an additive `age` column to `employees`.
- Add `age` to the `Employee` model's fillable fields and integer casts.
- Validate age server-side on employee creation and when age is supplied during updates.
- Persist age on the employee and linked user records.
- Include age in HR and shop-owner employee payloads.
- Add a required number input to the existing Add Employee modal.
- Map age from API responses and render it in Employee Details.
- Add regression coverage for valid persistence/fetching, invalid ranges/types, and the form contract.
- Rebuild `public/build` after implementation.

## Constraints and decisions

- The database column is nullable to preserve existing employee rows created before this feature. New employee creation remains required at the request-validation and UI layers.
- Validation is `required|integer|min:0|max:100` on create. Update validation is conditional (`sometimes|required|integer|min:0|max:100`) so unrelated edits do not force legacy records to acquire an age.
- The input uses the existing form styling with `type="number"`, `min="0"`, `max="100"`, and `step="1"`; the server remains the trust boundary.
- Blank or missing legacy ages display as an em dash in read-only details.
- No new dependency, table column, employee-list column, or UI redesign is needed.

## Data flow

```text
Add Employee input
  -> React form state and client field validation
  -> POST /api/hr/employees
  -> EmployeeController validation and persistence
  -> employees.age + users.age
  -> employee API payload
  -> transformEmployeeFromApi
  -> Employee Details: Age
```

## Alternatives considered

1. Derive age from date of birth: not applicable because the requested form collects age directly and no birth-date workflow was requested.
2. Validate only in React: rejected because direct API callers could bypass the range and integer rules.

## Acceptance criteria

- The Add Employee modal visibly marks Age as required.
- Negative values, decimals, scientific notation, non-integers, values over 100, and missing age are rejected before an employee is created.
- Ages 0 and 100 are accepted and stored as integers.
- A newly created employee's API response and Employee Details modal show the saved age.
- Existing suffix/address/details behavior and terminated-row actions remain unchanged.
- Relevant frontend tests, Laravel tests, production build, and diff checks pass.
