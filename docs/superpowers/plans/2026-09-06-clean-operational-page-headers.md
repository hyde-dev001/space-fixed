# Clean Operational Page Headers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox ( - [ ] ) syntax for tracking.

**Goal:** Remove redundant visible page headers from non-dashboard Manager, Staff, Finance, HR, CRM, Cashier, Repairer, Inventory, Procurement, Logistics/Dispatcher, and Shop Owner screens while preserving dashboard content and existing behavior, and polish the affected navigation/table states.

**Architecture:** Keep the change page-local and visual. Preserve semantic page identity with visually hidden top-level headings where a page needs one, remove visible subtitles/context pills, and keep existing actions in the same shell. Update the shared customer drawer only for the Account icon and Logout states; update the Staff Job Orders table only for action/status presentation.

**Tech Stack:** Laravel/Inertia React 18, TypeScript, Tailwind CSS 4, Testing Library, Vitest, Vite.

## Global Constraints

- Do not change routes, data requests, permissions, handlers, table behavior, dashboard content, modal workflows, or browser Head titles.
- Remove only visible top-level title/subtitle blocks from non-dashboard operational pages.
- Keep dashboard headers and cards unchanged.
- Preserve internal section headings, modal/dialog headings, loading states, and error/access-denied headings.
- Keep adjacent icon-only action buttons horizontal and no-wrap; allow table horizontal scrolling on narrow screens.
- Keep Receipt Confirmed and Customer Dispute readable as one-line status pills.
- Use existing components, icons, Tailwind conventions, and local test patterns; add no dependencies.

---

### Task 1: Add regression contracts before implementation

**Files:**
- Create: resources/js/__tests__/operationalPageHeaders.contract.test.ts
- Modify: resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
- Modify: resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts

**Interfaces:**
- Consume the current source files with readFileSync(resolve(...), 'utf8'), matching the existing source-contract test style.
- Produce failing checks for the approved operational-header boundary, Account icon/logout states, horizontal table actions, and readable receipt-status pills.

- [ ] **Step 1: Write the failing source contracts**

Use source fixtures for representative operational pages and explicit dashboard exclusions. The operational fixture list must include Staff Product Management, Staff Job Orders, Manager Inventory Overview, Staff/Manager inventory variants, one page from each remaining ERP role, and the Shop Owner product/order/repair/settings variants. Assert that operational title blocks use the visual-cleanup contract (sr-only heading/no visible subtitle) while dashboard title source remains unchanged.

Add Navigation assertions for a user/account SVG in the Account trigger and hover:bg-red-50, focus-visible:ring-2, and focus-visible:ring-red-500 on Logout. Add Job Orders assertions for flex-nowrap, the widened status/action columns, whitespace-nowrap on secondary receipt badges, and preserved aria-label="View order details".

- [ ] **Step 2: Run only the new/affected contracts and verify they fail**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/__tests__/operationalPageHeaders.contract.test.ts resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
~~~

Expected: the existing tests pass and the new assertions fail because the implementation has not changed yet.

---

### Task 2: Fix shared customer navigation and Staff Job Orders states

**Files:**
- Modify: resources/js/Pages/UserSide/Shared/Navigation.tsx
- Modify: resources/js/Pages/ERP/STAFF/JobOrders.tsx
- Test: resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
- Test: resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts

**Interfaces:**
- Preserve the existing handleLogout, Account accordion state, order action callbacks, and order-status conditions.
- Preserve the existing table horizontal overflow wrapper and add no new component boundary.

- [ ] **Step 1: Restore the Account icon and Logout affordance**

In the authenticated Account trigger, add the same 20px user silhouette style already used by the drawer's Sign in item before the Account label, with aria-hidden="true". Keep the plus/close control and accordion animation unchanged. Add rounded padding, transition, red-tinted hover background, and visible red focus ring to the Logout button without changing its click handler.

- [ ] **Step 2: Make Job Orders actions horizontal and receipt badges readable**

Keep the existing handleViewOrder, handleShipOrder, return actions, and status conditions. Increase the status/action column hints enough for their content, change the action container to flex-nowrap, and keep action buttons at their existing touch size. Add whitespace-nowrap to Customer Dispute, Receipt Confirmed, and Receipt Pending badges so the pills remain intact.

- [ ] **Step 3: Run the focused tests**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/Pages/ERP/STAFF/__tests__/JobOrders.shippingCoverage.test.ts
~~~

Expected: all affected tests pass.

---

### Task 3: Clean Manager, Staff, CRM, and Cashier operational headers

**Files:**
- Modify: resources/js/Pages/ERP/Manager/AuditLogs.tsx
- Modify: resources/js/Pages/ERP/Manager/EmploymentLifecycleApprovals.tsx
- Modify: resources/js/Pages/ERP/Manager/InventoryOverview.tsx
- Modify: resources/js/Pages/ERP/Manager/JobOrders.tsx
- Modify: resources/js/Pages/ERP/Manager/LeaveApprovals.tsx
- Modify: resources/js/Pages/ERP/Manager/RepairJobs.tsx
- Modify: resources/js/Pages/ERP/Manager/Reports.tsx
- Modify: resources/js/Pages/ERP/Manager/StaffWorkload.tsx
- Modify: resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx
- Modify: resources/js/Pages/ERP/STAFF/Customers.tsx
- Modify: resources/js/Pages/ERP/STAFF/JobOrders.tsx
- Modify: resources/js/Pages/ERP/STAFF/leave.tsx
- Modify: resources/js/Pages/ERP/STAFF/MyPayslips.tsx
- Modify: resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx
- Modify: resources/js/Pages/ERP/STAFF/shoePricing.tsx
- Modify: resources/js/Pages/ERP/STAFF/TimeIn.tsx
- Modify: resources/js/Pages/ERP/CRM/CustomerReviews.tsx
- Modify: resources/js/Pages/ERP/CRM/Customers.tsx
- Modify: resources/js/Pages/ERP/CRM/customerSupport.tsx
- Modify: resources/js/Pages/ERP/cashier/POS.tsx

**Interfaces:**
- Leave Staff Dashboard and any dashboard-only content untouched.
- Preserve all page actions, filters, metrics, API calls, modals, and internal headings.

- [ ] **Step 1: Convert visible top-level headers to the visual-cleanup pattern**

For a page header with actions, retain the action container and replace the title/subtitle block with a visually hidden page heading:

~~~tsx
<div className="flex items-center justify-between">
  <h1 className="sr-only">Page title</h1>
  <div className="ml-auto flex items-center gap-3">{/* existing actions */}</div>
</div>
~~~

For a header with no actions, remove the visible wrapper and retain only <h1 className="sr-only">Page title</h1> immediately before the content. Remove only the redundant subtitle. In Manager/InventoryOverview.tsx, remove the top Products/Products + Repair Materials pill while keeping Refresh and update metadata. Do not change dashboard files.

- [ ] **Step 2: Run affected role tests and source contracts**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/__tests__/operationalPageHeaders.contract.test.ts resources/js/Pages/ERP/STAFF/__tests__/Dashboard.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
~~~

Expected: all tests pass and dashboard header assertions remain green.

---

### Task 4: Clean Finance, HR, Inventory, Procurement, and Logistics headers

**Files:**
- Modify: resources/js/Pages/ERP/Finance/createInvoice.tsx
- Modify: resources/js/Pages/ERP/Finance/Expense.tsx
- Modify: resources/js/Pages/ERP/Finance/Invoice.tsx (visible invoice-list header only; preserve print/modal headings)
- Modify: resources/js/Pages/ERP/Finance/payslipApproval.tsx
- Modify: resources/js/Pages/ERP/Finance/PurchaseRequestApproval.tsx
- Modify: resources/js/Pages/ERP/Finance/refundApproval.tsx
- Modify: resources/js/Pages/ERP/Finance/repairPriceApproval.tsx
- Modify: resources/js/Pages/ERP/Finance/shoePriceApproval.tsx
- Modify: resources/js/Pages/ERP/HR/AttendanceRecords.tsx
- Modify: resources/js/Pages/ERP/HR/EmployeeDirectory.tsx
- Modify: resources/js/Pages/ERP/HR/generateSlip.tsx
- Modify: resources/js/Pages/ERP/HR/LeaveApprovals.tsx
- Modify: resources/js/Pages/ERP/HR/OvertimeApprovals.tsx
- Modify: resources/js/Pages/ERP/HR/SalaryChanges.tsx
- Modify: resources/js/Pages/ERP/HR/viewSlip.tsx
- Modify: resources/js/Pages/ERP/inventory/ProductInventory.tsx
- Modify: resources/js/Pages/ERP/inventory/RequestApproval.tsx
- Modify: resources/js/Pages/ERP/inventory/StockMovement.tsx
- Modify: resources/js/Pages/ERP/inventory/StockRequest.tsx
- Modify: resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx
- Modify: resources/js/Pages/ERP/inventory/UploadInventory.tsx
- Modify: resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx
- Modify: resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx
- Modify: resources/js/Pages/ERP/Procurement/StockRequestApproval.tsx
- Modify: resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx
- Modify: resources/js/Pages/ERP/Logistics/Batches.tsx
- Modify: resources/js/Pages/ERP/Logistics/MyDeliveries.tsx
- Modify: resources/js/Pages/ERP/Logistics/Riders.tsx
- Modify: resources/js/Pages/ERP/Logistics/Settings.tsx
- Modify: resources/js/Pages/ERP/Logistics/Shipments.tsx

**Interfaces:**
- Leave resources/js/Pages/ERP/Procurement/Dashboard.tsx unchanged.
- Preserve modal/detail headings and all existing filtering, approval, delivery, and upload behavior.

- [ ] **Step 1: Apply the same semantic-hidden heading pattern**

Remove only the visible page title/subtitle rows identified by the existing top-level h1 occurrences. Keep functional header controls aligned with ml-auto or the existing responsive flex layout. Remove top-only context pills such as Inventory Tracking when they are decorative duplicates.

- [ ] **Step 2: Run the affected source contracts and role coverage**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/__tests__/operationalPageHeaders.contract.test.ts resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/HR/__tests__/AttendanceRecords.test.tsx
~~~

Expected: all tests pass.

---

### Task 5: Clean Repairer and Shop Owner operational headers

**Files:**
- Modify: resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
- Modify: resources/js/Pages/ERP/repairer/POS.tsx
- Modify: resources/js/Pages/ERP/repairer/PricingAndServices.tsx
- Modify: resources/js/Pages/ERP/repairer/repairerSupport.tsx
- Modify: resources/js/Pages/ERP/repairer/repairStocksOverview.tsx
- Modify: resources/js/Pages/ERP/repairer/requestMaterials.tsx
- Modify: resources/js/Pages/ERP/repairer/uploadService.tsx
- Modify: resources/js/Pages/ERP/repairer/WarrantyQueue.tsx
- Modify: resources/js/Pages/ShopOwner/ActionCenter.tsx
- Modify: resources/js/Pages/ShopOwner/Customers/customer management/Customers.tsx
- Modify: resources/js/Pages/ShopOwner/Customers/customer management/CustomersReviews.tsx
- Modify: resources/js/Pages/ShopOwner/Customers/customer management/customerSupport.tsx
- Modify: resources/js/Pages/ShopOwner/Customers/customer management/repairSupport.tsx
- Modify: resources/js/Pages/ShopOwner/Logistics/Riders.tsx
- Modify: resources/js/Pages/ShopOwner/Logistics/Shipments.tsx
- Modify: resources/js/Pages/ShopOwner/Operations/JobOrders.tsx
- Modify: resources/js/Pages/ShopOwner/Operations/RepairJobs.tsx
- Modify: resources/js/Pages/ShopOwner/Orders/order management/discount.tsx
- Modify: resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx
- Modify: resources/js/Pages/ShopOwner/Premium/premuimBenefits.tsx
- Modify: resources/js/Pages/ShopOwner/Products/product management/InventoryOverview.tsx
- Modify: resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/historyRejection.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/individual/uploadStockMaterial.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/service management/uploadService.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/service management/WarrantyQueue.tsx
- Modify: resources/js/Pages/ShopOwner/Settings/AuditLogs.tsx
- Modify: resources/js/Pages/ShopOwner/Settings/shopProfile.tsx
- Modify: resources/js/Pages/ShopOwner/Settings/shopSetting.tsx
- Modify: resources/js/Pages/ShopOwner/TeamManagement/suspendAccount.tsx
- Modify: resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx

**Interfaces:**
- Leave resources/js/Pages/ShopOwner/Logistics/Dashboard.tsx and resources/js/Pages/ShopOwner/DssInsights.tsx unchanged as dashboard/insight surfaces.
- Preserve Shop Owner company/individual and retail/repair capability branches, actions, permissions, and modal headings.

- [ ] **Step 1: Apply the approved header cleanup without changing business branches**

Replace only the visible top title/subtitle block in each listed operational screen with a semantic sr-only heading and preserve any existing action group. In product and inventory pages, remove only decorative top labels while keeping filters, Refresh, Add, and Show Active controls.

- [ ] **Step 2: Run Shop Owner and Repairer focused tests**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run resources/js/__tests__/operationalPageHeaders.contract.test.ts resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx resources/js/Pages/ShopOwner/Repairs/service management/__tests__/JobOrdersRepair.logistics.test.tsx resources/js/layout/__tests__/CanonicalOwnerSidebar.test.tsx
~~~

Expected: all tests pass.

---

### Task 6: Audit, simplify, verify, build, and hand off

**Files:**
- Modify: public/build/** (fresh Vite output)
- Review: all files changed by Tasks 2–5
- Review: docs/superpowers/specs/2026-09-06-clean-operational-page-headers-design.md

- [ ] **Step 1: Audit scope and dead code**

Run rg over the listed role directories for <h1, Products + Repair Materials, and Inventory Tracking. Manually confirm remaining matches are dashboards, modals, loading/error states, or internal content. Confirm no removed title wrapper leaves unused imports, empty layout containers, or broken action alignment.

- [ ] **Step 2: Run the full frontend suite**

Run:

~~~powershell
.\node_modules\.bin\vitest.cmd run
~~~

Expected: all frontend test files and tests pass.

- [ ] **Step 3: Run hygiene checks and a fresh build**

Run:

~~~powershell
git diff --check
.\node_modules\.bin\vite.cmd build
~~~

Expected: clean diff check and successful Vite build with regenerated public/build.

- [ ] **Step 4: Review and commit the implementation**

Inspect git diff --stat, git diff --name-status, and git status --short --branch. Stage only the intended page sources, tests, spec/plan docs, and generated public/build; commit with:

~~~powershell
git add resources/js docs/superpowers public/build
git diff --cached --check
git commit -m "fix: clean operational page headers and controls"
~~~

- [ ] **Step 5: Push the feature branch**

Confirm the branch remains based on origin/solespace-b, then push feature/customer-navigation-dropup without force-pushing. Report the commit, test/build results, and PR base.
