# Employee Access, Rehire, Receipts, Leave, and Filter UX

## Contract

- Preserve the existing Laravel/Inertia/React architecture and tenant isolation.
- Use one canonical employee account-link service for HR and Shop Owner flows.
- Keep Shop Owner account/access actions separate from HR master-data editing.
- Generate rehire dates at approval/reinstatement, never from request input.
- Use one concurrency-safe receipt ledger per shop, shared by retail and repair after auditing the current Finance relationships.
- Reuse existing React Query refetch and local filter-query patterns.
- Add focused Laravel and Vitest regressions before implementation, then run the narrowest checks after each slice.

## Execution order

1. Add failing backend/frontend contracts for account-link delivery, rehire state/date, receipt scope, owner access boundaries, leave refresh, and automatic filters.
2. Extract the shared account-link issuance and personal-email delivery flow, wire HR and Shop Owner reset routes, and preserve token/audit behavior.
3. Remove client-controlled rehire dates, generate the effective date during approved reinstatement, and expose pending rehire state in the HR directory.
4. Add a shop-scoped receipt sequence and make the existing receipt issuer use it for both POS domains without changing historical rows or internal IDs.
5. Revalidate Manager Leave Approvals through the existing React Query query and refresh after decisions.
6. Convert the six Manager ERP manual-filter forms to automatic structured-filter application with debounced search and page reset.
7. Replace Shop Owner Edit Employee with read-only View Details, keep account/security actions, and reject the legacy HR mutation endpoint server-side.
8. Run focused Laravel/Vitest checks, frontend build, diff hygiene, sequential standards/spec/security/simplification reviews, dead-code/reuse scans, and report any legacy reconciliation needs.

## Likely files

- app/Services/EmployeeAccountLinkService.php
- app/Http/Controllers/InvitationController.php
- app/Http/Controllers/ShopOwner/UserAccessControlController.php
- app/Services/HR/EmployeeLifecycleWorkflowService.php
- app/Http/Controllers/Erp/HR/EmployeeLifecycleRequestController.php
- app/Models/Employee.php
- app/Http/Controllers/Erp/HR/EmployeeController.php
- POS receipt service/model/migration and focused payment tests
- resources/js/Pages/ERP/HR/EmployeeDirectory.tsx
- resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx
- resources/js/Pages/ERP/Manager/LeaveApprovals.tsx
- resources/js/hooks/useManagerApi.ts
- the six affected resources/js/Pages/ERP/Manager/* filter pages
- focused Laravel and Vitest contract tests

## Verification

- php artisan test tests/Feature/HR/EmployeeTerminationAndRehireWorkflowTest.php
- focused account-access and POS receipt feature tests
- pnpm exec vitest run for changed frontend contracts
- pnpm run build
- git diff --check
