# Shop Owner ERP Operational Parity Fixes Design

**Date:** 2026-08-11

**Status:** Proposed for implementation

**Related plan:** `docs/superpowers/plans/2026-08-11-owner-erp-operational-parity-fixes.md`

## Goal

Make the scoped Shop Owner ERP workspace expose the complete set of existing, company-operational pages for each enabled module, while preserving the ERP workspace as the module picker and keeping employee self-service pages out of the owner shell.

This follow-up also fixes the production failures found after deployment: the HR attendance page crash and the Finance audit-log 500 response.

## Approved UX

- `/shop-owner/erp/workspace` remains the only module picker.
- Opening a module navigates to `/shop-owner/erp/{module}` or a scoped module page and replaces the portal navigation with that module's pages only.
- The owner portal keeps only its core pages; the old portal Logistics and Employee Modules sections remain removed.
- Module navigation is generated from the canonical module page catalog.
- The catalog supports an optional group key, group label, group order, and page order. Any module can use grouped navigation; the sidebar must not contain HR-specific grouping logic.
- Related pages are grouped as collapsible sidebar sections when a module has more than one page group. The active page automatically expands its group, and the user may collapse or reopen groups without changing the current URL.
- The existing ERP sidebar has three verified nested groups that the owner catalog must account for:
  - HR Attendance Monitoring
  - HR Payroll
  - Finance Approvals
- The existing Shop Owner portal also has a core `Approval Pages` group. It is separate from the ERP module shell and remains available as a core portal group; it must not be silently duplicated as unrelated module links.
- HR uses:
  - Attendance Monitoring: View Attendance, Leave Requests, Overtime Requests.
  - Payroll: View Slip, Generate Slip, Salary Changes.
- Dashboard, Employees, and Audit Logs remain direct HR entries.

## Module page scope

### Retail Operations

Expose the existing E-commerce page as the canonical `Retail Dashboard`, followed by the existing owner-capable pages:

- Retail Dashboard
- Products
- Orders
- Point of Sale
- Vouchers and Discounts

Legacy aliases are not separate module pages.

### Repair Operations

Keep the existing repair dashboard and its owner-capable operational pages in the scoped Repair module. Do not expose employee-only repair or rider execution pages.

### HR and Employees

Expose and make owner-operable through owner-scoped routes and APIs:

- Dashboard
- Employees
- Attendance Monitoring: View Attendance, Leave Requests, Overtime Requests
- Payroll: View Slip, Generate Slip, Salary Changes
- Audit Logs

`Log Attendance` and personal payslips remain employee self-service and are not added to the owner navigation.

### Finance

The Finance module must expose the existing company-operational Finance pages instead of only the current approval aliases:

- Dashboard
- Invoices
- Expenses
- Approvals (collapsible group)
  - Expense Approvals
  - Repair Pricing Approval
  - Shoe Pricing Approval
  - Purchase Request Review
  - Refund Approval
  - Payslip Approvals
  - Salary Adjustments
- Audit Logs

Create Invoice remains part of the Invoices workflow and may be shown as a nested Invoices child only when its owner-scoped route and actions are fully supported. `My Payslips` remains employee self-service and is not added to the owner Finance navigation.

### Repair Operations, CRM, Inventory, Procurement, and Logistics

Expose the existing company-operational pages already present under the ERP folders, using the same scoped catalog and owner authorization rules. Each of these modules may use the same collapsible groups—for example, approval, stock, purchasing, shipment, or customer-operation groups—when that matches the existing module's page hierarchy. Missing pages must be added only when their existing read/action behavior can be safely executed with a Shop Owner actor and tenant scope.

## Required fixes

1. Import Inertia's `usePage` in `resources/js/Pages/ERP/HR/AttendanceRecords.tsx` so the Attendance page can mount.
2. Correct the Finance audit route/controller namespace casing so it resolves on case-sensitive production filesystems and returns the same owner-scoped audit contract as HR.
3. Add the missing Retail Dashboard catalog/route mapping.
4. Add missing HR payroll page routes/catalog entries and owner-safe data/action adapters where existing employee endpoints cannot accept a Shop Owner actor.
5. Add catalog-driven grouped sidebar navigation for HR, Finance, and every other module that has nested operational pages, with active-state and deep-link support.
6. Expand the Finance page catalog and scoped routes for Dashboard, Invoices, Expenses, the Approvals group, and Audit Logs, reusing existing ERP Finance components and owner-safe APIs.
7. Audit the full enabled-module catalog for page/API mismatches, missing route pairs, employee-only URLs, and runtime import errors.

## Authorization and data boundaries

- Owner pages use `auth:shop_owner` and the request-scoped ERP owner actor context.
- Owner reads and mutations derive tenant identity server-side; client-supplied shop IDs are not authorization inputs.
- Employee routes and self-service behavior remain under `auth:user` and their existing permission policies.
- A page is not listed in the owner catalog until its initial load, refresh, filters, exports, and mutations (where applicable) are owner-safe or explicitly read-only.
- Audit-log access remains tenant-scoped and owner-attributed.

## Acceptance criteria

- The Retail module opens the canonical Retail Dashboard and all four existing retail pages without duplicate legacy links.
- The HR module shows the grouped Attendance Monitoring and Payroll sections and all nested pages open from both the sidebar and their direct scoped URLs.
- The Finance module shows Dashboard, Invoices, Expenses, Audit Logs, and a grouped Approvals section containing the existing approval pages that are owner-operable.
- Grouped navigation works consistently for every module that declares page groups; it is not special-cased to HR. The initial verified groups are HR Attendance Monitoring, HR Payroll, and Finance Approvals. Inventory, Procurement, Logistics, CRM, and Repair are currently flat in the existing ERP sidebar; they may gain groups only where the module page catalog defines a real page hierarchy.
- Clicking Attendance no longer throws `ReferenceError: usePage is not defined`.
- HR and Finance Audit Logs load successfully for a valid Shop Owner; no production-only namespace casing failure remains.
- Every page shown in an owner module sidebar can load with the owner session and does not call an employee-only endpoint without an owner-safe adapter.
- Core portal pages remain available outside the module shell, and removed portal Logistics/Employee sections do not reappear.
- Existing employee navigation, routes, authorization, and tenant isolation remain unchanged.
- Feature/frontend regression tests cover catalog completeness, nested navigation, direct URLs, audit responses, and the Attendance import regression.
- A fresh frontend build is produced for deployment verification.

## Likely implementation areas

- `config/shop_modules.php`
- `routes/shop-owner-erp.php`
- `routes/shop-owner-erp-api.php`
- `app/Http/Controllers/Erp/ReadPageController.php`
- `app/Http/Controllers/Erp/HR/AuditLogController.php`
- `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`
- `resources/js/Pages/ERP/HR/HR.tsx` and related payroll pages
- `resources/js/layout/AppSidebar_ERP.tsx` and shared ERP catalog/capability helpers
- Existing owner-safe ERP controllers, services, Form Requests, and tests
- `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- `tests/Feature/BusinessScaling/OwnerErpApiContractTest.php`
- Focused frontend tests under `resources/js`

## Out of scope

- Rebuilding ERP business operations from scratch.
- Exposing employee personal actions to the Shop Owner.
- Creating duplicate legacy route entries for the same operation.
- Changing the existing employee sidebar or employee permissions.
- Disabling server-side module or tenant authorization.
