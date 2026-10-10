# Finance Expense Workflow Correctness Implementation Plan

> **For agentic workers:** Execute this plan sequentially in the current worktree. Use TDD for each behavior and do not commit or push until explicitly requested.

**Goal:** Repair manual Finance expense approval visibility, date/time semantics, controlled categories, and server-authoritative filtering without changing the existing workflow states.

**Architecture:** Reuse the existing Expense model, Finance API controller, ExpenseApprovalService, ApprovalService, Owner Action Center adapters, React Finance page, and finance query hook. Fix the shop-owner identity boundary at approval creation, make the existing paginator the frontend source of truth, and expose category options from the same backend vocabulary used for validation.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit/Pest-style feature tests, Inertia/React 18, TypeScript, React Query, Vite, Tailwind.

---

### Task 1: Add failing backend regression tests

**Files:**
- Modify: `tests/Feature/Finance/ExpenseApprovalWorkflowTest.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/ExpenseAttentionAdapterTest.php`
- Create: `tests/Feature/Finance/ExpenseQueryAndCategoryTest.php`

- [x] Add a test where the canonical `ShopOwner` ID has no matching `User` ID, submit a manual expense as the authorized Finance user, and assert the approval references the real shop owner and the expense is not orphaned.
- [x] Add an Action Center regression assertion for company-owner visibility and preserve the existing individual-owner refund-only contract.
- [x] Add tests for manual category options, `Other` requiring `custom_category`, generated categories remaining filterable, future-date rejection, exact category filtering, search/status combination, and paginator metadata.
- [x] Run the focused PHPUnit tests and confirm the new tests fail for the current implementation.

### Task 2: Fix backend approval identity and expense validation

**Files:**
- Modify: `app/Models/Finance/Expense.php`
- Modify: `app/Http/Controllers/Api/Finance/ExpenseController.php`
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `app/Services/ApprovalService.php` only if required by the existing owner identity type boundary
- Modify: `routes/finance-api.php`

- [x] Add the minimal canonical user/system category constants and a tenant-scoped category-options response.
- [x] Validate manual dates as existing business dates that are not in the future; resolve `Other` to a trimmed custom category and reject empty custom values.
- [x] Add the category-options endpoint using the current Finance authorization middleware and preserve historical tenant categories in filter options.
- [x] Resolve the actual `ShopOwner` for manual expense approval creation, keep the existing policy/role selection, and make approval creation fail the transaction instead of silently leaving an orphaned submitted expense.
- [x] Keep procurement/payroll exclusions and existing statuses unchanged.
- [x] Run the focused backend tests and confirm they pass.

### Task 3: Verify the existing owner-type policy

**Files:**
- Modify: `tests/Feature/ShopOwner/ActionCenter/ExpenseAttentionAdapterTest.php` only if the regression assertion needs clearer coverage

- [x] Keep the existing registration-type gate and document it with the focused regression assertion.
- [x] Run the Action Center tests and the approval workflow tests; no production owner-action-center changes are expected.

### Task 4: Add failing frontend regression tests

**Files:**
- Modify: `resources/js/Pages/ERP/Finance/__tests__/Expense.procurement-review.test.tsx`
- Create or modify: `resources/js/hooks/__tests__/useFinanceQueries.test.tsx` if the existing frontend test conventions support hook-level request assertions

- [x] Add a test proving a date-only business date does not render a fabricated `8:00 AM` and that the actual recorded timestamp is used when available.
- [x] Add tests for the category select, `Other` custom input, and server-driven page/filter state.
- [x] Run the focused frontend tests and confirm the new assertions fail before implementation.

### Task 5: Make the Finance page use canonical date, category, and paginator data

**Files:**
- Modify: `resources/js/hooks/useFinanceQueries.ts`
- Modify: `resources/js/Pages/ERP/Finance/Expense.tsx`
- Modify: related frontend test mocks/types only where required

- [x] Preserve paginator metadata in `useExpenses`, pass page/page-size/status/category/search to the existing API, and keep query keys aligned with those parameters.
- [x] Replace local filtering/slicing with server response rows and paginator metadata; reset to page one when a filter changes.
- [x] Fetch backend category options, render a controlled category filter, and use a visible `Other` custom-category field in the Add Expense form.
- [x] Format `Expense.date` as a date-only value and display `created_at` only as the recorded timestamp.
- [x] Run focused frontend tests and the production frontend build.

### Task 6: Final verification and review

**Files:**
- No additional production files expected.

- [x] Review the diff for tenant/authorization regressions, duplicate notifications, stale local filtering, unused imports, and dead branches.
- [x] Run the focused Laravel tests, frontend tests, production build, syntax checks, route check, and `git diff --check`.
- [x] Report exact verification results; do not commit or push until requested.
