# Manager and Shop Owner QA Handoff

**Worktree:** `shop-owner-phase-3-action-center`
**Branch:** `feat/shop-owner-phase-3-action-center`
**Audience:** QA testers, including testers who are new to the project
**Purpose:** Explain the implemented Manager and Shop Owner changes in plain English and provide the expected workflows for manual QA.

This document is based on the current code and route contracts in this worktree. It covers the Manager workspace work and the Shop Owner changes that were added or consolidated alongside it: the canonical owner shell, Approval Center, Operations pages, Oversee pages, module access rules, and company-owner monitoring.

## 1. The most important distinction

There are three different account experiences to test:

| Account | Main responsibility in this change |
| --- | --- |
| **Manager** | Monitor daily work and resolve operational exceptions. |
| **Company Shop Owner** | Monitor company operations, review owner-level approvals, and view business summaries. |
| **Individual Shop Owner** | Continue using the existing owner-operated retail/repair workflows. The new company monitoring pages must not replace these pages. |

Two words are important:

- **Read-only** means the user can view a list, open details, filter, refresh, or inspect history. The page must not provide a mutation button, and the corresponding API must not allow a mutation.
- **Approval** means a record has already been submitted by another workflow and the authorized reviewer makes a decision. Approval is not the same as opening a page.

Manager operational exceptions and Shop Owner approvals are intentionally separate:

```text
Manager:      monitor work -> fix an operational exception -> preserve history
Shop Owner:   review an owner-stage approval -> approve/reject -> preserve history
```

## 2. Manager changes

### 2.1 Manager navigation

The Manager sidebar should contain:

- Log Attendance
- My Payslips
- Manager Dashboard
- Job Orders
- Repair Jobs
- Inventory Overview
- Staff & Workload
- Leave Approvals
- Suspension Approvals
- Reports & Analytics
- Audit Logs

The Manager sidebar must not contain:

- Action Center
- Notifications or Profile as sidebar items
- Customer Complaints queue
- Generic Assign Staff page
- Repair Takeover
- Permission Administration

Notifications and Profile remain in the global header.

### 2.2 Manager Job Orders workflow

```text
New order
  -> Staff A claims or starts the order
  -> The order is locked to Staff A
  -> Staff B is blocked from processing it
  -> Staff A becomes inactive or otherwise unavailable
  -> The order becomes Reassignment Required
  -> Manager reassigns it to an eligible replacement with a reason
```

Manager may reassign only when the current handler is no longer eligible, for example:

- inactive;
- suspended;
- terminated, resigned, or offboarded;
- on approved leave covering the active work;
- explicitly unavailable; or
- no longer eligible because of role, skill, shop, or availability rules.

Merely being off-shift does not automatically qualify for reassignment.

Manager must not routinely reassign healthy work for workload balancing. A client-side disabled button is not enough: the server must also enforce the lock and eligibility rule.

### 2.3 Manager Repair Jobs workflow

New repair requests are assigned automatically to an eligible repairer with the lowest active non-terminal repair workload. A deterministic tie-breaker is used when workloads are equal.

```text
New repair request
  -> Automatic workload-based assignment
  -> Repairer accepts and works on it

If the repairer rejects:
  -> Repairer must give a reason
  -> Pending Manager review
  -> Manager reassigns to another eligible repairer
     OR Manager makes a final shop rejection
```

If the repairer becomes unavailable while the request is active:

```text
Active repair
  -> Reassignment Required
  -> Manager may reassign with a mandatory reason
```

Manager must not take over the repair, normally unassign it, or manually rebalance healthy repair requests.

Manager final rejection:

- closes the request as a terminal rejected state;
- records the Manager's reason and audit event;
- does not automatically forward the request to Shop Owner; and
- does not automatically create a refund.

An explicit exceptional owner-approval policy may still route a repair rejection to Shop Owner. That is not the default path and must be enabled by the record's owner-stage policy.

### 2.4 Other Manager pages

- **Dashboard:** operational overview, current-state counts, period metrics, responsible person or waiting-on party, next action, freshness timestamp, and links to the correct page.
- **Staff & Workload:** shop-scoped staff availability, active orders, active repairs, overdue work, and reassignment exceptions. It is a monitoring page, not a repair assignment page.
- **Inventory Overview:** server-side inventory totals and product/repair-material visibility based on business type. No stock adjustment or supplier mutation is added to this Manager page.
- **Leave Approvals:** approve/reject within the Manager policy. Approval is terminal by default, rejection requires a reason, and employees can cancel only their own eligible requests.
- **Suspension Approvals:** Manager stage of the HR -> Manager -> Shop Owner suspension workflow. This is separate from repair rejection.
- **Reports & Analytics:** shop- and period-scoped operational reports with correct staff attribution and truthful report statuses.
- **Audit Logs:** read-only, tenant-scoped history of assignments, approvals, repair decisions, reports, and other supported events.

## 3. Shop Owner changes

### 3.1 Canonical Shop Owner shell

The old ERP picker/fallback presentation has been consolidated into a canonical owner shell for the selected owner cohort. The compatibility URL remains safe:

- `/shop-owner/erp/workspace` redirects to `/shop-owner/home`.
- `/shop-owner/erp/{module}` redirects to that module's Dashboard, or to Settings when the module is disabled.

For a **Company Shop Owner**, the high-level sidebar is organized as:

```text
Home
Approval Center
Oversee
  Finance
  Workforce
  Inventory
  Procurement
  Logistics
Operate
  Retail
  Repair
  Customers
Reports & Audit
  Reports
  Audit
```

The canonical header contains the sidebar toggle, search/command field, notification bell, theme control, and Shop Owner profile menu.

The sidebar is responsive: it can collapse on desktop, open as a drawer on smaller screens, preserve expanded groups, and keep the current page active.

### 3.2 Module eligibility and module state

Module visibility is based on both registration type and business type:

| Module | Registration type | Business type |
| --- | --- | --- |
| Retail Operations | Individual or Company | `retail` or `both` |
| Repair Operations | Individual or Company | `repair` or `both` |
| CRM | Individual or Company | `retail`, `repair`, or `both` |
| Workforce/HR | Company | `retail`, `repair`, or `both` |
| Finance | Company | `retail`, `repair`, or `both` |
| Inventory | Company | `retail`, `repair`, or `both` |
| Procurement | Company | `retail`, `repair`, or `both` |
| Logistics | Company | `retail`, `repair`, or `both` |

The persisted module setting can also disable an otherwise eligible module. When this happens:

- the page must not expose the module's data;
- direct URL access must be denied or redirected safely; and
- the user should be directed to Settings -> Modules & Team when appropriate.

Navigation visibility is not the security boundary. Direct URLs and APIs must enforce the same rules.

## 4. Shop Owner Approval Center

The Shop Owner has a unified **Approval Center** at:

`/shop-owner/action-center`

This is different from the removed Manager Action Center. The Shop Owner Approval Center contains only owner-level decisions.

### 4.1 Approval Center screen behavior

The page provides:

- **Pending** view for approvals requiring the current owner decision;
- **History** view for completed owner decisions;
- coverage/source filters;
- pagination;
- Refresh;
- a detail panel/modal opened from an approval row; and
- an Approve or Reject decision footer when the record is still actionable.

Rejected decisions require a reason when the approval type requires one. After a successful decision, the queue refreshes and the record appears in history or leaves the pending queue.

The Approval Center is tenant-scoped. A record belonging to another shop must not appear, load, or mutate.

The former exception and waiting buckets are not normal approval queues in the current contract. Old requests for retired buckets are normalized or redirected to the owner approval view rather than creating an operational queue.

### 4.2 Approval types that may appear

For a Company Shop Owner, the owner-stage approval families include:

- order refunds;
- repair refunds;
- product price changes;
- repair service price changes;
- repair package price changes;
- payslips;
- salary adjustments;
- purchase requests;
- employee suspension requests;
- expenses; and
- repair rejection approvals when an explicit owner-approval policy requires them.

The owner is approving the next stage of an existing workflow. Examples of the result are:

| Approval | Expected result after approval |
| --- | --- |
| Order or repair refund | Moves to the next authoritative refund stage. It does not by itself bypass refund/payment safeguards. |
| Product or repair price change | Publishes or advances the price through the pricing workflow. |
| Payslip | Moves the payslip to the payroll/disbursement workflow. Disbursement remains separate. |
| Salary adjustment | Advances the HR salary workflow. A proposer must not approve their own change. |
| Purchase request | Advances the procurement workflow. It does not directly create a purchase order. |
| Expense | Advances the expense approval workflow. Settlement remains separate. |
| Suspension request | Performs the Shop Owner stage of the HR suspension lifecycle. |
| Repair rejection | Handles only the explicitly configured owner stage; it is not the default Manager rejection flow. |

For an **Individual Shop Owner**, the canonical owner approval coverage is limited to refunds. Individual owners must not receive company HR, Finance, Procurement, suspension, or repairer-review approval coverage through the new company workflow.

### 4.3 Approval settings

Settings -> Payments & Approvals exposes seven binary approval controls:

- Refund Approval
- Price Approval
- Payslip Approval
- Salary Adjustment Approval
- Purchase Request Approval
- Expense Approval
- Repair Rejection Approval

These settings control future owner-stage routing. They do not give the owner operational permissions, and they must not change an already snapshotted in-flight workflow unexpectedly.

Updating the settings must affect only the authenticated owner's shop and preserve unrelated settings data.

### 4.4 Legacy approval links

The old approval page URLs remain compatibility redirects to the Approval Center. Examples include:

- expense approvals;
- repair pricing;
- shoe pricing;
- purchase request review;
- refund approvals;
- payslip approvals; and
- salary adjustment approvals.

The redirect may include a typed record selection so the correct detail panel opens. A GET redirect must never approve or reject a record.

## 5. Shop Owner Operations pages

The Shop Owner Operations pages are module pages. The exact child tabs are generated from the route catalog, so hidden legacy pages should not be treated as required sidebar items.

### 5.1 Retail Operations

Company owners normally see:

- Retail Dashboard
- Products
- Job Orders
- Vouchers and Discounts

The Company Owner Job Orders page is a monitoring page. It uses the Manager-style design and provides:

- order list;
- status, assignment, handler, date, and overdue filters;
- pagination;
- last-updated information;
- order status and current handler;
- processing-lock information; and
- a View Details modal.

It must not provide:

- Reassign Order;
- Process Order;
- Mark as Shipped;
- takeover;
- unassign; or
- routine assignment controls.

Products, vouchers, and other retail pages retain their existing owner-specific domain behavior. This change does not turn those pages into Manager pages.

### 5.2 Repair Operations

Company owners normally see:

- Repair Dashboard
- Repair Jobs
- Services Management

The Company Owner Repair Jobs page uses the same monitoring layout as the Manager Repair Jobs page. It provides:

- all repair requests for the owner's shop;
- current status;
- assigned repairer;
- each repairer's active workload;
- assignment/review state;
- age/overdue information;
- filters and pagination; and
- a View Details modal.

It must not provide:

- Repair Review;
- Reassign Repairer;
- Final Reject;
- takeover;
- unassign; or
- manual workload balancing.

Services Management keeps the existing service-management workflow. It is separate from the read-only Repair Jobs monitoring page.

### 5.3 Customers/CRM Operations

The owner-safe CRM module provides customer-facing operational views such as:

- CRM Dashboard;
- Customers;
- Customer Reviews; and
- supported customer directory/read pages.

Customer complaints remain a CRM responsibility. The Shop Owner monitoring pages must not be confused with the Manager operational queues.

### 5.4 Individual Shop Owner behavior

Individual owners must continue to use their existing operational components:

- `/shop-owner/erp/retail/orders` -> existing individual order page;
- `/shop-owner/erp/repair/job-orders` -> existing individual repair job page; and
- individual POS and repair execution workflows remain available according to the existing business-type rules.

The new company components are not allowed to replace these pages for an Individual Shop Owner. Company-only labels such as `Job Orders` and `Repair Jobs` must not unexpectedly change the individual page contract.

## 6. Shop Owner Oversee pages

Oversee pages are intended for company-level visibility and controlled read/management projections. A page appearing in the sidebar does not automatically grant every underlying domain mutation.

### 6.1 Finance

Expected owner-safe surfaces include:

- Finance Dashboard;
- Invoices; and
- Expenses.

QA should confirm that unsupported creation, payroll, accounting, or settlement operations are not exposed by the canonical owner UI. Direct unsupported requests must fail closed.

Owner approvals for expenses, refunds, prices, and payslips are completed through the Approval Center, not through duplicate approval pages.

### 6.2 Workforce

Expected surfaces include:

- Employee Directory;
- read-only Attendance; and
- supported employee overview data.

The owner employee-directory workflow may create an employee when that capability is explicitly available. The following must remain protected or denied unless a separate capability exists:

- attendance mutations;
- employee self-service actions on behalf of another employee;
- payroll generation;
- broad account permission administration; and
- direct suspension/activation bypasses.

### 6.3 Inventory

Expected read/monitoring surfaces include:

- Inventory Dashboard/Overview;
- Product Inventory;
- Stock Movement; and
- Supplier Order Monitoring where enabled.

Products/shoes and repair materials must be shown according to the shop's business type. Upload, stock adjustment, material-request approval, and supplier mutation flows must not be inferred from a read-only page.

### 6.4 Procurement

Expected surfaces include:

- Purchase Requests;
- Purchase Orders; and
- Suppliers.

Purchase Request Approval is routed through the Approval Center when the owner stage is required. It should not create a second competing approval queue.

### 6.5 Logistics

Expected monitoring surfaces include:

- Logistics Dashboard;
- Shipments;
- Riders; and
- Batches.

These views must be shop-scoped. Protected delivery evidence or unsupported logistics mutations must not become available merely because the owner can see a logistics dashboard.

### 6.6 Reports and Audit

Company owners have canonical:

- Reports at `/shop-owner/reports`; and
- Audit at `/shop-owner/audit`.

Reports are owner-safe, shop-scoped projections. Audit is read-only and allowlisted. Audit export remains denied in the current contract.

## 7. Business-type QA matrix

Use separate test accounts or fixtures for each row below.

| Account | Should see | Should not see |
| --- | --- | --- |
| Company + `retail` | Retail operations, retail Job Orders, retail/products inventory data, company Oversee modules when enabled | Repair Jobs, repair metrics, repair materials as the active operational inventory category |
| Company + `repair` | Repair operations, Repair Jobs, repair services/materials, company Oversee modules when enabled | Retail Job Orders and retail-only metrics |
| Company + `both` | Retail and Repair operations plus both inventory categories | Nothing from another shop; disabled modules remain unavailable |
| Individual + `retail` | Existing individual retail workflow | Company monitoring pages and repair workflow |
| Individual + `repair` | Existing individual repair workflow | Company monitoring pages and retail workflow |
| Individual + `both` | Existing individual retail/repair workflows and allowed refund approvals | Company-only workforce, finance, procurement, inventory, logistics, and manager-style owner mutations |

Important: `both` means both retail and repair data are applicable. It must not mean the owner can access records belonging to another shop.

## 8. Beginner-friendly QA test cases

### SO-01 — Open the canonical Company Owner shell

1. Log in as an approved Company Shop Owner.
2. Open `/shop-owner/home`.
3. Check the sidebar groups and the header.
4. Open one item under Operate and one item under Oversee.

Expected:

- The canonical grouped sidebar loads without a missing-page error.
- Approval Center, Operations, Oversee, Reports, and Audit appear only when eligible.
- Notification bell and Profile are in the header, not duplicate sidebar items.
- The active page remains highlighted after navigation.
- Collapsing/opening the sidebar works on desktop and mobile widths.

### SO-02 — Verify business type and module gating

1. Test a Company `retail` owner.
2. Test a Company `repair` owner.
3. Test a Company `both` owner.
4. Disable one eligible module in Settings -> Modules & Team.
5. Try both the sidebar link and the direct URL for the disabled module.

Expected:

- Retail shows retail operations but not Repair Jobs.
- Repair shows repair operations but not retail Job Orders.
- Both shows both.
- A disabled module is not allowed to reveal its data.
- The direct URL denies or redirects safely to Settings.

### SO-03 — Verify Company Owner Job Orders is read-only

1. Log in as a Company `retail` or `both` owner.
2. Open `/shop-owner/erp/retail/orders`.
3. Apply filters and pagination.
4. Open an order using View Details.
5. Inspect the row and detail modal.

Expected:

- The page title is `Job Orders` for a Company Owner.
- The page uses the Manager-style monitoring layout.
- Order status, handler, age, lock state, and next action are visible where data exists.
- The detail modal opens without navigating to the Staff processing page.
- No Process Order, Mark as Shipped, Reassign Order, Takeover, or Unassign control appears.
- A direct POST to a Manager mutation endpoint is denied for the Shop Owner.

### SO-04 — Verify Company Owner Repair Jobs is read-only

1. Log in as a Company `repair` or `both` owner.
2. Open `/shop-owner/erp/repair/job-orders`.
3. Filter by repairer, status, review pending, or overdue.
4. Open a repair request using View Details.

Expected:

- The page title is `Repair Jobs` for a Company Owner.
- Every visible repair request belongs to the authenticated shop.
- The assigned repairer and active workload are visible.
- The detail modal opens.
- No Repair Review, Reassign Repairer, Final Reject, Takeover, Unassign, or balancing control appears.
- Direct mutation attempts are denied.

### SO-05 — Verify Individual Owner pages did not change

1. Log in as an Individual Owner.
2. Open the retail order page if the owner is retail-capable.
3. Open the repair job page if the owner is repair-capable.
4. Compare the component behavior with the existing individual workflow.

Expected:

- The existing individual components load.
- Individual order/repair execution behavior is not replaced by the company read-only monitoring components.
- Individual labels remain the existing owner-facing labels where the route contract specifies them.
- Company-only monitoring APIs return a safe forbidden response for the Individual Owner.

### SO-06 — Verify Shop Owner Approval Center pending decisions

1. Create or seed one pending owner-stage approval for a Company Owner.
2. Open `/shop-owner/action-center`.
3. Confirm the record appears in Pending.
4. Open the record details.
5. Approve or reject using the correct action.
6. Open History.

Expected:

- The record appears only when the current owner is the authorized owner-stage reviewer.
- The detail panel identifies the approval type and record.
- Reject requires the required reason.
- After the decision, the pending queue refreshes.
- The completed decision is visible in History with status, reviewer, time, and comments where supported.
- Repeating the same request does not repeat the business side effect.

### SO-07 — Verify Shop Owner approval families

Use one fixture at a time. Confirm that the correct approval type has a detail renderer and the correct decision result:

- order refund;
- repair refund;
- product price change;
- repair service/package price change;
- payslip;
- salary adjustment;
- purchase request;
- expense;
- suspension request; and
- explicitly configured repair rejection.

Expected:

- The owner sees only records from the owner's shop.
- Salary self-proposals do not appear as actionable approvals for the same owner.
- A normal Manager final repair rejection does not automatically appear in the Shop Owner queue.
- An explicitly configured repair owner-stage record may appear and follows the authoritative repair workflow.
- A suspension approval remains part of the HR -> Manager -> Shop Owner lifecycle.

### SO-08 — Verify approval toggles and legacy redirects

1. Open Settings -> Payments & Approvals.
2. Confirm the seven binary controls are present.
3. Change one control and save.
4. Confirm another owner's settings were not changed.
5. Open a legacy approval URL such as `/shop-owner/erp/finance/refund-approvals`.

Expected:

- Only the authenticated owner's settings change.
- Invalid non-boolean values are rejected.
- Legacy approval pages redirect to the Approval Center.
- The redirect does not approve/reject anything by itself.
- Existing in-flight workflow policy remains consistent with its stored/snapshotted requirement.

### SO-09 — Verify Oversee pages

1. From the Company Owner shell, open Finance, Workforce, Inventory, Procurement, and Logistics.
2. For each module, open its Dashboard and visible child pages.
3. Check that data belongs to the current shop.
4. Try a known unsupported direct mutation URL/API.

Expected:

- Each eligible module opens its own dashboard and visible pages.
- Finance shows owner-safe invoice/expense views without unsupported create/settlement actions.
- Workforce shows Employees and read-only Attendance; payroll generation and broad permission administration are not exposed.
- Inventory shows product/shoe data for retail-capable shops and repair materials for repair-capable shops.
- Procurement shows purchasing records; owner approval is in Approval Center.
- Logistics shows shop-scoped shipments, riders, and batches.
- Unsupported direct mutation requests fail closed.

### SO-10 — Verify tenant isolation

1. Create two shops with similar records.
2. Log in as the owner of Shop A.
3. Search, filter, and open records from Shop A.
4. Try to request a known Shop B record ID through the browser/API.

Expected:

- Shop B records are not listed.
- A cross-shop ID returns a safe not-found/forbidden response.
- The response does not reveal whether the other-shop record exists.
- No approval, operation, report, audit, or dashboard query leaks another shop's totals.

### SO-11 — Verify loading, empty, error, stale, and responsive states

1. Test a shop with no records.
2. Simulate a slow or failed API response.
3. Use Refresh after a stale response.
4. Test at a desktop width and approximately 390px width.

Expected:

- Empty state explains that there are no matching records.
- Loading state identifies the page/section being loaded.
- Errors use a human-readable message and a retry/refresh path.
- Stale data shows a last-updated timestamp or stale warning.
- Tables have a usable narrow-screen/card alternative.
- Buttons and dialogs remain keyboard accessible and do not rely on color alone.

### M-01 — Verify Manager route and capability boundaries

1. Log in as Manager.
2. Open every canonical Manager sidebar page.
3. Open Dashboard, Reports, and Audit.
4. Try to open a page using a direct URL without the relevant capability.

Expected:

- All canonical Manager pages load without a missing component.
- Dashboard is an overview, not a duplicate Action Center.
- Reports and Audit are visible in the correct sections.
- A read capability does not automatically grant a mutation capability.
- Dashboard, Reports, Audit, and employee-directory access do not grant suspension or repair decisions.

### M-02 — Verify order locking and reassignment

1. Create a pending order.
2. Have Staff A claim/start it.
3. Attempt to process it as Staff B.
4. Confirm Staff B is blocked.
5. Make Staff A inactive or otherwise unavailable.
6. Reopen Manager Job Orders and filter for reassignment required.
7. Reassign to an eligible replacement with a reason.

Expected:

- Staff A becomes the recorded handler.
- Staff B cannot process the locked order.
- A healthy active handler cannot be reassigned just for balancing.
- The replacement must be active, eligible, and from the same shop.
- The full original assignment and reassignment history is preserved.

### M-03 — Verify repair workload and rejection flow

1. Create repair requests with at least two eligible repairers.
2. Confirm a new request is assigned to the repairer with the lower active workload.
3. Have the repairer reject one request with a reason.
4. Open Manager Repair Jobs and review the rejection.
5. Reassign it, or use Manager final rejection.
6. Separately make an assigned repairer unavailable and test exception reassignment.

Expected:

- Terminal, completed, and rejected requests do not count as active workload.
- Rejection preserves the repairer's reason and history.
- Reassignment requires an eligible replacement and reason.
- Final rejection closes the request and does not silently forward it to Shop Owner.
- Repairer unavailability creates an explicit reassignment state.
- No Manager takeover or normal unassign is available.

### M-04 — Verify Manager business-type metrics

1. Log in as a retail-only Manager shop.
2. Check Dashboard, Staff & Workload, Inventory Overview, Job Orders, and Repair Jobs.
3. Repeat with repair-only and both-capability shops.

Expected:

- Retail-only Manager views show order/retail data and hide repair operational data.
- Repair-only Manager views show repair data and hide retail order data.
- Both shows both.
- Staff workload columns match the applicable business type.
- Inventory totals are server-authoritative and do not change incorrectly because of pagination.

### M-05 — Verify Manager approvals, reports, and audit

1. Submit a leave request and test Manager approve/reject.
2. Submit a suspension request and test the Manager stage.
3. Generate a report and check its lifecycle.
4. Open Audit Logs after an order, repair, leave, or suspension event.

Expected:

- Leave rejection requires a reason and approval is not duplicated.
- Suspension follows HR -> Manager -> Shop Owner.
- Reports use correct shop, date range, and assigned-staff attribution.
- Audit records show actor, target, time, state change, reason, and reference where available.

## 9. Useful routes for QA

### Manager

- `/erp/manager/dashboard`
- `/erp/manager/job-orders`
- `/erp/manager/repair-jobs`
- `/erp/manager/inventory-overview`
- `/erp/manager/staff-workload`
- `/erp/manager/leave-approvals`
- `/erp/manager/suspension-approvals`
- `/erp/manager/reports`
- `/erp/manager/audit-logs`

### Company Shop Owner

- `/shop-owner/home`
- `/shop-owner/action-center`
- `/shop-owner/operate/retail`
- `/shop-owner/operate/repair`
- `/shop-owner/operate/customers`
- `/shop-owner/oversee/finance`
- `/shop-owner/oversee/workforce`
- `/shop-owner/oversee/inventory`
- `/shop-owner/oversee/procurement`
- `/shop-owner/oversee/logistics`
- `/shop-owner/reports`
- `/shop-owner/audit`
- `/shop-owner/erp/retail/orders`
- `/shop-owner/erp/repair/job-orders`

### Important read-only monitoring APIs

- `GET /api/shop-owner/erp/operations/orders`
- `GET /api/shop-owner/erp/operations/orders/{id}`
- `GET /api/shop-owner/erp/operations/repairs`
- `GET /api/shop-owner/erp/operations/repairs/{id}`

These four monitoring endpoints are GET-only projections for Company Shop Owners. They are not Manager mutation endpoints.

## 10. Known verification notes

The implementation was verified with focused backend tests, frontend tests, a production Vite build, route checks, and browser navigation smoke tests. The Company Owner Job Orders and Repair Jobs navigation smoke test completed without page errors after rebuilding the frontend assets.

If QA sees this error:

```text
Page not found: ./Pages/ShopOwner/Operations/JobOrders.tsx
```

the browser is probably using a stale `public/build` bundle. Rebuild the frontend assets and perform a hard refresh before logging a source-code defect.

Current repository limitations:

- The repository has no committed TypeScript compiler configuration or frontend lint script, so no TypeScript/lint pass is claimed.
- The broad Manager test run has one known legacy expectation mismatch: `ManagerPaginationTest` expects 10 suspension rows while the current endpoint returns 25. This should be tracked separately from the Manager/Shop Owner workflow behavior.
- The worktree contains unrelated existing changes. QA should validate the deployed/build output for this feature scope and should not assume that every dirty file belongs to this handoff.

## 11. Defect report template

When reporting a failure, include:

- account type: Manager, Company Owner, or Individual Owner;
- registration type: Individual or Company;
- business type: Retail, Repair, or Both;
- shop/tenant used;
- exact URL;
- steps to reproduce;
- expected result from this document;
- actual result;
- screenshot or console/API response, if available; and
- whether the issue remains after a hard refresh and fresh asset build.

## 12. Technical references

- [Manager design specification](../superpowers/specs/2026-08-27-manager-hybrid-workspace-design.md)
- [Manager implementation plan](../superpowers/plans/2026-08-27-manager-hybrid-workspace-implementation-plan.md)
- [Shop Owner ERP route matrix](../architecture/shop-owner-erp-route-matrix.md)
- [Shop Owner Phase 5 rollout guide](../shop-owner-phase-5-rollout-guide.md)
