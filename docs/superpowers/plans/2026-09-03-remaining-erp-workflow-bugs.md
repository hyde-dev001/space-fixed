# Remaining ERP Workflow Bugs

## Contract

- Keep customer return tracking customer-safe; expose only valid external third-party URLs and keep reference details when no link is usable.
- Let same-shop Inventory users with the existing inventory capability approve/reject repair material requests, while preserving procurement authorization and workflow ordering.
- Expose the derived Inventory approval stage on Procurement requests and enforce that stage server-side before procurement processing.
- Ensure newly created shoe-price requests cannot be left without a recognized workflow, and safely repair legitimate pending legacy requests using the current owner-approval setting.
- Compare attendance schedule and clock-in at minute precision in the backend, using the existing shop timezone and without changing historical records.

## Likely file map

- `app/Http/Controllers/UserSide/OrderController.php`
- `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- `resources/js/Pages/UserSide/Orders/__tests__/MyOrders.tracking.test.tsx`
- `app/Policies/StockRequestApprovalPolicy.php`
- `app/Http/Controllers/Erp/StockRequestApprovalController.php`
- `app/Services/StockRequestApprovalService.php`
- `app/Models/StockRequestApproval.php`
- `app/Http/Controllers/Erp/PurchaseRequestController.php`
- `resources/js/types/procurement.ts`
- `resources/js/Pages/ERP/Procurement/StockRequestApproval.tsx`
- focused Procurement feature tests
- `app/Http/Controllers/Api/PriceChangeRequestController.php`
- focused Finance pricing workflow tests
- `app/Models/HR/AttendanceRecord.php`
- `app/Http/Controllers/Erp/HR/AttendanceController.php`
- focused HR attendance test

## Execution and verification

1. Add focused red regressions for each canonical path.
2. Run the smallest Laravel/frontend test commands and address failures.
3. Implement the minimum backend, policy, serializer, and UI changes.
4. Re-run focused tests, then `git diff --check`, relevant frontend tests/build, and the relevant Laravel suites.
5. Review for authorization, tenant scoping, duplicate state, dead code, and unrelated diff before handoff.

## Constraints

- Do not add a new approval state machine or migrate existing history.
- Do not expose privileged ERP paths to customers or weaken role checks.
- Do not fabricate customer, workflow, or attendance data.
- Preserve existing audit, locking, totals, inventory, and payment behavior.
