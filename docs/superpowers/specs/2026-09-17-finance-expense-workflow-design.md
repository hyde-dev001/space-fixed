# Finance Expense Workflow Correctness Design

**Status:** Approved 2026-09-17

## Goal

Make manual Finance expenses follow the existing approval workflow reliably, preserve the user-entered business date, use the real recorded time, and provide controlled tenant-safe category filtering.

## Root causes found

- Finance submission resolves the shop owner with `User::find($shopId)`, even though `$shopId` is the canonical `ShopOwner` identity. That can skip approval creation when the IDs do not match.
- Owner Action Center expense coverage is intentionally restricted to company owners; existing characterization tests define individual owners as refund-only. This is preserved rather than treated as a Finance bug.
- The API stores a business `DATE`, but the Finance page parses it as a UTC datetime and displays the fabricated midnight-converted time.
- The API already paginates and filters expenses, but the Finance page discards paginator metadata and applies a second local filter/slice to only the first response page.
- Manual category input has no canonical list or `Other`/custom validation.

## Design

1. Resolve and persist approvals against the actual `ShopOwner` identity while preserving existing approval roles, statuses, policies, tenant scopes, and notification transitions. Approval creation remains part of the expense transaction so a submitted expense cannot be left without its required approval record.
2. Keep the existing owner-type policy: company owners may receive Finance expense approvals, while individual owners remain refund-only. Same-shop pending expense approvals for eligible company owners remain governed by the existing query rules.
3. Treat `Expense.date` as a business date and `Expense.created_at` as the recorded timestamp. The API contract remains backward-compatible; the Finance page formats each field according to its meaning instead of converting the date-only value into a time.
4. Expose the canonical manual category list from the backend. Manual creation accepts only those categories or `Other` with a non-empty custom category; generated `Procurement` and `Payroll` records remain valid historical/filter values but are not manual choices.
5. Pass status, category, search, page, and page size to the existing paginated endpoint. The Finance page renders the returned page directly and uses backend-provided category options, preserving tenant isolation and historical categories.

## Error handling and security

- Keep same-shop scoping on every expense query and approval action.
- Reject future manual business dates and invalid categories with normal validation responses.
- Do not add statuses, parallel approval models, or a historical data migration.
- Keep notifications on the existing post-transition path; do not introduce duplicate notifications.

## Verification

- Laravel feature tests cover owner identity resolution, individual/company Action Center coverage, tenant isolation, date/category validation, and server filters/pagination.
- Frontend tests cover business-date rendering, category selection/`Other`, and query-driven pagination/filter state.
- Run the focused Laravel tests, focused frontend tests, `pnpm run build`, and `git diff --check`.
