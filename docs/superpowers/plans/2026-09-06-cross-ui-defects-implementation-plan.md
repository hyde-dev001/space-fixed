# SoleSpace Cross-UI Defects Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Repair the four approved SoleSpace UI defects while changing no backend, API, routing, or business behavior.

**Architecture:** Keep the existing courier tracking flow and page-local card markup, fix presentation at existing component boundaries, and reuse the shared modal for the Super Admin overlay. Tighten only parameterized HR route matching so canonical section URLs remain exact while existing unparameterized descendant matching stays intact.

**Tech Stack:** Laravel 12/Inertia 2 frontend, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, Vite 7, pnpm.

## Global Constraints

- UI-only changes; do not edit controllers, models, migrations, API contracts, route definitions, or business calculations.
- Preserve unrelated working-tree changes, including existing edits in resources/js/Pages/ERP/HR/LeaveApprovals.tsx and resources/js/Pages/ERP/HR/OvertimeApprovals.tsx.
- Use ASCII-safe Saving... for courier loading text.
- Remove only decorative arrow/change percentage chips from metric cards; retain legitimate business percentages.
- Reuse resources/js/components/ui/modal/index.tsx.
- Use pnpm commands and run focused tests after each coherent change group.

---

### Task 1: Add regression tests before production edits

**Files:**
- Modify: resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
- Modify: resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx
- Create: resources/js/components/ui/modal/__tests__/Modal.test.tsx
- Modify: resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
- Create: resources/js/__tests__/metricCardTrendBadges.test.ts

**Interfaces:**
- Consumes: Existing MyRepairs, FlaggedAccounts, Modal, and AppSidebarERP test helpers.
- Produces: Failing regression tests that define the approved UI behavior before implementation.

- [ ] Step 1: Add the courier loading-state regression.

In the existing return courier tracking test, use an unresolved post promise and assert the transient state before resolving it:

~~~
let resolveSave!: (value: { data: { success: boolean; message: string } }) => void;
mocks.post.mockImplementationOnce(() => new Promise((resolve) => {
  resolveSave = resolve;
}));

fireEvent.click(saveButton);

const savingButton = await screen.findByRole("button", { name: "Saving..." });
expect(savingButton).toBeDisabled();
expect(within(tracking).queryByText(/Saving[^.]/)).not.toBeInTheDocument();

resolveSave({ data: { success: true, message: "Tracking details saved." } });
await waitFor(() => expect(screen.getByText("Tracking details saved.")).toBeInTheDocument());
expect(within(tracking).getByRole("button", { name: "Save return tracking" })).toBeEnabled();
~~~

- [ ] Step 2: Add the flagged-account modal regression.

Append a test that opens Review and asserts the shared fixed high-layer wrapper contains the existing heading:

~~~
it("renders report details in the shared high-layer modal", () => {
  render(<FlaggedAccounts />);
  fireEvent.click(screen.getByRole("button", { name: "Review" }));

  const modal = document.querySelector(".modal");
  expect(modal).toHaveClass("fixed", "z-99999");
  expect(modal).toContainElement(
    screen.getByRole("heading", { name: "Review report details" }),
  );
});
~~~

- [ ] Step 3: Add the shared modal scroll-lock regression.

Create resources/js/components/ui/modal/__tests__/Modal.test.tsx. Set window.innerWidth to 1000 and document.documentElement.clientWidth to 980, render an open Modal, assert body overflow is hidden and paddingRight is 20px, unmount, then assert both body styles restore to empty strings.

- [ ] Step 4: Add HR route coverage.

Add an it.each table to AppSidebar_ERP.test.tsx for these locations and intended active item:

- /erp/hr?section=overview -> Dashboard
- /erp/hr?section=employees -> Employees
- /erp/hr?section=attendance -> View Attendance
- /erp/hr?section=leaves -> Leave Requests
- /erp/hr?section=overtime -> Overtime Requests
- /erp/hr?section=payroll-view -> View Slip
- /erp/hr?section=payroll-generate -> Generate Slip
- /erp/hr?section=salary-changes -> Salary Changes
- /erp/my-payslips -> My Payslips
- /erp/hr/articles -> Articles

Set the HR role and all HR permissions used by the existing HR test. After rendering, collect links/buttons with menu-item-active or menu-dropdown-item-active and assert the intended item is active; for Articles assert no parameterized HR item is active.

- [ ] Step 5: Add the global metric-card source contract.

Create resources/js/__tests__/metricCardTrendBadges.test.ts. Read each file below with node:fs and assert it does not match the exact decorative expression Math.abs(change)}% or Math.abs(change!)}%.

~~~ts
const metricCardFiles = [
  "resources/js/Pages/ERP/CRM/CustomerReviews.tsx",
  "resources/js/Pages/ERP/Finance/Expense.tsx",
  "resources/js/Pages/ERP/Finance/Invoice.tsx",
  "resources/js/Pages/ERP/Finance/payslipApproval.tsx",
  "resources/js/Pages/ERP/Finance/refundApproval.tsx",
  "resources/js/Pages/ERP/Finance/repairPriceApproval.tsx",
  "resources/js/Pages/ERP/Finance/shoePriceApproval.tsx",
  "resources/js/Pages/ERP/HR/AttendanceRecords.tsx",
  "resources/js/Pages/ERP/HR/EmployeeDirectory.tsx",
  "resources/js/Pages/ERP/HR/LeaveApprovals.tsx",
  "resources/js/Pages/ERP/HR/OvertimeApprovals.tsx",
  "resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx",
  "resources/js/Pages/ERP/repairer/PricingAndServices.tsx",
  "resources/js/Pages/ERP/repairer/repairStocksOverview.tsx",
  "resources/js/Pages/ERP/repairer/uploadService.tsx",
  "resources/js/Pages/ERP/repairer/WarrantyQueue.tsx",
  "resources/js/Pages/ERP/STAFF/Customers.tsx",
  "resources/js/Pages/ERP/STAFF/JobOrders.tsx",
  "resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx",
  "resources/js/Pages/ERP/STAFF/shoePricing.tsx",
  "resources/js/Pages/ShopOwner/Customers/customer management/CustomersReviews.tsx",
  "resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx",
  "resources/js/Pages/ShopOwner/Products/product management/InventoryOverview.tsx",
  "resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx",
  "resources/js/Pages/ShopOwner/Repairs/individual/uploadStockMaterial.tsx",
  "resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx",
  "resources/js/Pages/ShopOwner/Repairs/service management/uploadService.tsx",
  "resources/js/Pages/ShopOwner/Repairs/service management/WarrantyQueue.tsx",
  "resources/js/Pages/ShopOwner/Settings/AuditLogs.tsx",
  "resources/js/Pages/ShopOwner/TeamManagement/suspendAccount.tsx",
  "resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx",
  "resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx",
  "resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx",
  "resources/js/Pages/superAdmin/SystemMonitoringDashboard.tsx",
  "resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx",
] as const;

for (const path of metricCardFiles) {
  expect(readFileSync(resolve(path), "utf8"), path)
    .not.toMatch(/Math\.abs\(change!?\)\}%/);
}
~~~

- [ ] Step 6: Run the new tests and confirm RED.

Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/components/ui/modal/__tests__/Modal.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx resources/js/__tests__/metricCardTrendBadges.test.ts
~~~

Expected: failures for the new loading assertion, modal wrapper/scroll-gap assertions, the Articles active-state case, and the metric-card source contract.

### Task 2: Fix courier tracking loading text

**Files:**
- Modify: resources/js/Pages/UserSide/Repairs/myRepairs.tsx near the shared CourierTracking save button
- Test: resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx

**Interfaces:**
- Consumes: Existing saving state, Axios request, onRefresh callback, and error/success state.
- Produces: Clean intake and return loading labels with unchanged request behavior.

- [ ] Step 1: Replace only the malformed label with:

~~~tsx
{saving ? "Saving..." : "Save " + leg + " tracking"}
~~~

Do not change onClick, disabled, request data, onRefresh, success message, error message, or finally cleanup.

- [ ] Step 2: Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.logistics.test.tsx
~~~

Expected: PASS for pending-save disabled state and normal label restoration.

### Task 3: Fix Flagged Accounts modal layering and layout shift

**Files:**
- Modify: resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx
- Modify: resources/js/components/ui/modal/index.tsx
- Test: resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx
- Test: resources/js/components/ui/modal/__tests__/Modal.test.tsx

**Interfaces:**
- Consumes: Existing detailAccount state and shared Modal props.
- Produces: A viewport-fixed high-layer dialog with preserved content/actions and scrollbar compensation.

- [ ] Step 1: Import the shared Modal and replace only the inline fixed overlay.

Use:

~~~tsx
import { Modal } from "../../../components/ui/modal";
~~~

Wrap the existing report header, detail sections, Close button, and decision action blocks with:

~~~tsx
<Modal
  isOpen={detailAccount !== null}
  onClose={() => setDetailAccount(null)}
  showCloseButton={false}
  size="2xl"
  className="m-4 max-h-[90vh] overflow-y-auto p-6 shadow-xl"
>
  {detailAccount && (
    <>
      {/* existing report detail content remains unchanged */}
    </>
  )}
</Modal>
~~~

Do not duplicate a backdrop or change decision handlers.

- [ ] Step 2: Preserve the browser scrollbar gap in Modal.

Replace the current unconditional body-style effect with:

~~~tsx
useEffect(() => {
  if (!isOpen) return;

  const previousOverflow = document.body.style.overflow;
  const previousPaddingRight = document.body.style.paddingRight;
  const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;

  document.body.style.overflow = "hidden";
  if (scrollbarWidth > 0) {
    document.body.style.paddingRight = scrollbarWidth + "px";
  }

  return () => {
    document.body.style.overflow = previousOverflow;
    document.body.style.paddingRight = previousPaddingRight;
  };
}, [isOpen]);
~~~

- [ ] Step 3: Run:

~~~powershell
pnpm exec vitest run resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx resources/js/components/ui/modal/__tests__/Modal.test.tsx
~~~

Expected: PASS with the shared .modal z-99999 layer and body styles restored after close.

### Task 4: Correct HR parameterized route matching

**Files:**
- Modify: resources/js/layout/AppSidebar_ERP.tsx in isActive
- Test: resources/js/layout/__tests__/AppSidebar_ERP.test.tsx

**Interfaces:**
- Consumes: Existing url, allRoutePaths, route(), params.section, and extraPaths.
- Produces: Exact matching for section-based HR routes and unchanged descendant matching for routes without query parameters.

- [ ] Step 1: Guard mapped parameterized routes before descendant matching.

In the allRoutePaths branch, keep exact canonical handling and add:

~~~tsx
if (baseUrl === mappedPath) {
  if (params?.section) {
    return queryString.includes("section=" + params.section);
  }
  return true;
}

if (params?.section) {
  return false;
}

const isDeepNestedPath = mappedPath.split("/").filter(Boolean).length >= 2;
if (isDeepNestedPath && baseUrl.startsWith(mappedPath + "/")) return true;
~~~

- [ ] Step 2: Add the same guard after exact route() matching.

After the existing exact routeUrlBase handling, add:

~~~tsx
if (params?.section) {
  return false;
}

const isDeepNestedPath = routeUrlBase.split("/").filter(Boolean).length >= 2;
if (isDeepNestedPath && baseUrl.startsWith(routeUrlBase + "/")) return true;
~~~

Keep extraPaths behavior unchanged.

- [ ] Step 3: Run:

~~~powershell
pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
~~~

Expected: PASS for all HR route cases; only the intended item is active and true child routes still expand their parent.

### Task 5: Remove decorative badges from ERP metric cards

**Files:**
- Modify: resources/js/Pages/ERP/CRM/CustomerReviews.tsx
- Modify: resources/js/Pages/ERP/Finance/Expense.tsx
- Modify: resources/js/Pages/ERP/Finance/Invoice.tsx
- Modify: resources/js/Pages/ERP/Finance/payslipApproval.tsx
- Modify: resources/js/Pages/ERP/Finance/refundApproval.tsx
- Modify: resources/js/Pages/ERP/Finance/repairPriceApproval.tsx
- Modify: resources/js/Pages/ERP/Finance/shoePriceApproval.tsx
- Modify: resources/js/Pages/ERP/HR/AttendanceRecords.tsx
- Modify: resources/js/Pages/ERP/HR/EmployeeDirectory.tsx
- Modify: resources/js/Pages/ERP/HR/LeaveApprovals.tsx
- Modify: resources/js/Pages/ERP/HR/OvertimeApprovals.tsx
- Modify: resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx
- Modify: resources/js/Pages/ERP/repairer/PricingAndServices.tsx
- Modify: resources/js/Pages/ERP/repairer/repairStocksOverview.tsx
- Modify: resources/js/Pages/ERP/repairer/uploadService.tsx
- Modify: resources/js/Pages/ERP/repairer/WarrantyQueue.tsx
- Modify: resources/js/Pages/ERP/STAFF/Customers.tsx
- Modify: resources/js/Pages/ERP/STAFF/JobOrders.tsx
- Modify: resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx
- Modify: resources/js/Pages/ERP/STAFF/shoePricing.tsx
- Test: resources/js/__tests__/metricCardTrendBadges.test.ts

**Interfaces:**
- Consumes: Existing local metric-card props and call sites.
- Produces: ERP cards with unchanged metric values and no decorative arrow/percentage chip.

- [ ] Step 1: Remove only local card badge presentation.

For each file, remove change and changeType from the local metric-card type/destructure, delete the JSX block containing ArrowUpIcon/ArrowDownIcon and Math.abs(change) percentage output, and delete only now-unused arrow helpers/imports and call-site change props. If an arrow or percentage is used elsewhere for business data, retain that separate use.

- [ ] Step 2: Preserve user edits in the HR files.

In LeaveApprovals.tsx and OvertimeApprovals.tsx, change only the metric-card badge block and related unused props; retain existing bg-gray-950/text-white initials changes.

- [ ] Step 3: Run:

~~~powershell
pnpm exec vitest run resources/js/__tests__/metricCardTrendBadges.test.ts resources/js/Pages/ERP/HR/__tests__ resources/js/Pages/ERP/Finance/__tests__
~~~

Expected: PASS, with retained business percentage matches reviewed manually.

### Task 6: Remove decorative badges from Shop Owner and Super Admin cards

**Files:**
- Modify: resources/js/Pages/ShopOwner/Customers/customer management/CustomersReviews.tsx
- Modify: resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx
- Modify: resources/js/Pages/ShopOwner/Products/product management/InventoryOverview.tsx
- Modify: resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/individual/uploadStockMaterial.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/service management/uploadService.tsx
- Modify: resources/js/Pages/ShopOwner/Repairs/service management/WarrantyQueue.tsx
- Modify: resources/js/Pages/ShopOwner/Settings/AuditLogs.tsx
- Modify: resources/js/Pages/ShopOwner/TeamManagement/suspendAccount.tsx
- Modify: resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx
- Modify: resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx
- Modify: resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx
- Modify: resources/js/Pages/superAdmin/SystemMonitoringDashboard.tsx
- Modify: resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx
- Test: resources/js/__tests__/metricCardTrendBadges.test.ts

**Interfaces:**
- Consumes: Existing local metric-card props and call sites.
- Produces: Shop Owner and Super Admin cards with unchanged metric values and no decorative trend chip.

- [ ] Step 1: Apply the same surgical card cleanup as Task 5.

Keep arrows, percentages, and labels outside the card badge when they represent real operational or financial data.

- [ ] Step 2: Run:

~~~powershell
pnpm exec vitest run resources/js/__tests__/metricCardTrendBadges.test.ts
~~~

Expected: PASS for every manifest file.

### Task 7: Run complete verification and review

**Files:**
- Inspect: all changed frontend files and git diff
- Verify: FlaggedAccounts.tsx, myRepairs.tsx, AppSidebar_ERP.tsx, and components/ui/modal/index.tsx

**Interfaces:**
- Consumes: Tasks 1-6.
- Produces: Fresh evidence for UI behavior, no accidental backend changes, no dead imports, and a clean frontend build.

- [ ] Step 1: Run the complete frontend suite.

~~~powershell
pnpm run test:frontend
~~~

Expected: Vitest exits with code 0 and reports zero failed tests.

- [ ] Step 2: Build the frontend.

~~~powershell
pnpm run build
~~~

Expected: Vite exits with code 0.

- [ ] Step 3: Check diff hygiene and UI-only scope.

~~~powershell
git diff --check
git diff --name-only
git diff --stat
~~~

Confirm implementation files are frontend/test files only; pre-existing backend edits remain unchanged and unstaged.

- [ ] Step 4: Run browser verification when the local app is available.

First run:

~~~powershell
python .agents/skills/webapp-testing/scripts/with_server.py --help
~~~

If the existing local setup can start, use Playwright at desktop and 375px viewports to open the Flagged Accounts dialog, verify full-viewport backdrop/clickability, inspect courier Saving... state, and inspect HR active classes. Use screenshots only as verification evidence.

- [ ] Step 5: Perform sequential Standards, Spec, Risk, reuse, and dead-code reviews.

Check existing React/Tailwind conventions, every acceptance criterion, unchanged API/workflow behavior, reuse of existing helpers, and removal of imports/helpers/props made unused by this change.
