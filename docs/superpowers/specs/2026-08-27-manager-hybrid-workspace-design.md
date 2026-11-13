# Manager Hybrid Workspace — Design Specification

**Status:** Ready to freeze — final clarifications applied
**Date:** 2026-08-27
**Design direction:** Approved Hybrid Manager Workspace (direction C), with the Action Center removed
**Scope:** Manager information architecture, operational workflows, authorization boundaries, and remediation requirements

## 1. Purpose and decision summary

The Manager module is the shop's operational control surface. It should help a Manager monitor work, resolve operational exceptions, review approvals, and inspect traceable history without duplicating CRM, HR administration, Finance, or Shop Owner responsibilities.

The approved design decisions are:

- The landing page is **Manager Dashboard**, not an Action Center.
- The sidebar contains operational, review, and employee self-service pages.
- Notifications and Profile remain in the global header. They must not appear as sidebar navigation items.
- **Job Orders** behaves like a manager-level job-order view. The Manager monitors all orders and only reassigns an order when the current handler is inactive or otherwise unable to continue.
- **Job Orders** is available only to retail-capable shops (`retail` and `both`). Repair-only shops must not see or access the page or its Manager order APIs.
- **Repair Jobs** shows all repair requests across repairers. Initial assignment is automatic and workload-based. There is no normal takeover, unassign, or manual balancing action. Exception reassignment is allowed when the assigned repairer becomes unavailable.
- A repair request becomes a Manager decision after the repairer rejects it or an approved exception makes the current repairer unavailable. The Manager may reassign/override it to another eligible repairer or make a final shop rejection.
- Customer complaints remain owned by CRM. The Manager may see an operational escalation or a deep link, but does not receive a separate complaint queue.
- The Manager has inventory visibility through **Inventory Overview**, but inventory entry, adjustment, supplier, and stock-request operations remain outside this design.
- The Manager does not administer permissions or perform full HR employee administration.

This specification incorporates the findings from the read-only Manager Module Review. It defines the desired product contract and the fixes required before production sign-off. It intentionally does **not** contain the implementation plan; the plan will be written only after this document is reviewed and approved.

## 2. Prototype reference

The approved standalone visual prototype is available at:

- Browser: `http://localhost:64670`
- Source: `.superpowers/brainstorm/manager-sidebar-20260827-215830/manager-workspace-v2.html`

The prototype uses mock data. It demonstrates the shell, page hierarchy, page-level states, and navigation intent; it is not evidence that the production routes or APIs already implement the behavior described here.

## 3. Goals

The Manager workspace must:

1. Make the Manager's operational scope understandable at a glance.
2. Separate current-state monitoring from pages where a decision or exception action is allowed.
3. Protect assignment ownership and prevent two staff members from processing the same order.
4. Make repair workload and repairer rejection handling visible without implying that the Manager performs repairs.
5. Keep approval actions tenant-scoped, capability-scoped, concurrency-safe, and auditable.
6. Display metrics that represent the Manager's authorized shop and the selected reporting period accurately.
7. Give every actionable signal a clear next page, responsible staff member/role or waiting-on party, age, and status.
8. Preserve a clear boundary between Manager operations, CRM complaints, HR administration, Finance, and Shop Owner decisions.

## 4. Non-goals and ownership boundaries

The following are explicitly outside the Manager workspace's primary responsibility:

- Customer complaint intake, investigation, response, and complaint resolution. These belong to CRM.
- Full employee master-data administration, payroll, attendance administration, and permission administration.
- Direct employee suspension or activation that bypasses the approved HR → Manager → Shop Owner lifecycle.
- Inventory stock entry, stock adjustment, supplier ordering, material-request processing, and stock-request approval.
- Final financial/refund/large-discount decisions unless a separate authorized Finance or Shop Owner policy grants that capability.
- Manager physically performing a repair. A Manager review or override must never be represented as a repairer takeover.
- Notification and Profile pages in the Manager sidebar. These are global header features.
- A separate Manager Action Center. Actionable signals are summarized on Dashboard and routed to the relevant operational page.
- Unverified removal of legacy endpoints, duplicate fields, or shared components. Cleanup requires consumer and route verification during implementation.

## 5. Information architecture and shell

### 5.1 Approved sidebar

```text
PERSONAL
├── Log Attendance
└── My Payslips

MANAGER
└── Manager Dashboard

OPERATIONS
├── Job Orders
├── Repair Jobs
└── Inventory Overview

PEOPLE & APPROVALS
├── Staff & Workload
├── Leave Approvals
└── Suspension Approvals

REVIEW
├── Reports & Analytics
└── Audit Logs
```

The sidebar must not include:

- Notifications
- Profile
- Customer Complaints
- A generic Assign Staff page
- Repair Takeover
- Permission Administration
- A misleading Product Upload page when the page is read-only

`Log Attendance` reuses the existing employee time-in page. `My Payslips` reuses
the existing employee self-service payslip page and must remain visible to a
Manager even when the Finance module is disabled.

Business-type availability is enforced in both navigation and server routes. `Job Orders` requires a retail-capable shop (`retail` or `both`), while `Repair Jobs` requires a repair-capable shop (`repair` or `both`).

Reports must be a visible Manager navigation item. Suspension Approvals must be owned by the Manager/HR approval area rather than being hidden by Finance-module visibility rules.

### 5.2 Header

The global header owns:

- Notification bell and unread count
- Profile/avatar menu
- Page title/breadcrumb context
- Responsive navigation trigger when the sidebar is collapsed

Notifications should deep-link to the relevant page and record read state. A notification may point to an escalated operational issue, but that does not turn CRM complaints into a Manager queue.

### 5.3 Navigation behavior

- Active navigation must remain visible for the current page, including deep links from notifications.
- Capability-based visibility may hide pages, but hiding a menu item must never be treated as the security boundary.
- Direct URL access must be protected by server-side authorization.
- Empty, loading, stale, forbidden, and error states must be represented on every data page.

## 6. Page-level design contracts

### 6.1 Manager Dashboard

**Purpose:** Provide a reliable operational overview and route the Manager to the correct page.

**Primary content:**

- Pending/open Job Orders
- Active Repair Jobs
- Pending approvals, split by leave, suspension, and repair-rejection review where applicable
- Staff coverage and unavailable staff with active work
- Low-stock summary from server-provided shop-wide inventory metrics
- Operational signals with age, severity, responsible staff member/role or waiting-on party, and a link to the relevant page
- Last-updated timestamp for the visible snapshot

**Behavior:**

- Dashboard is an overview, not a second action queue.
- Business-type visibility is server-authoritative and mirrored by the UI: retail-only shops show order/revenue metrics and no repair metrics or repair-review signals; repair-only shops show repair metrics and no order/revenue metrics; `both` shops show both domains.
- Approval widgets are either intentionally rendered as compact summaries or removed; unused widgets must not remain as dead code.
- Every signal links to Job Orders, Repair Jobs, Staff & Workload, Leave Approvals, Suspension Approvals, or Inventory Overview.
- Period metrics and current-state counts must be labeled separately.
- Refresh behavior must update the visible dashboard snapshot consistently or clearly show which sections are stale. Refreshing only KPI cards while leaving drilldown data stale is not acceptable.
- Trend percentages must be calculated from defined comparison periods; hard-coded percentages are not acceptable in production.

**Must not include:**

- A full customer complaint list
- A full approval queue duplicating the approval pages
- Attendance controls that are only sample handlers or non-functional UI
- An implied Manager takeover action

### 6.2 Job Orders

**Purpose:** Monitor the complete shop-scoped order workload and resolve assignment exceptions.

**Business-type availability:** Retail-capable shops only (`retail` or `both`). Repair-only shops must be denied at the page and API route even when the Manager has the order capability.

**List fields:**

- Order number
- Created/received time
- Customer/order context allowed by the Manager's scope
- Current order status
- Current assigned staff member
- Age and overdue state; show a formal SLA only when a configured SLA policy exists
- Lock/processing state
- Next action or exception reason

**Filters and views:**

- Status
- Assignment state
- Current handler
- Age/overdue
- Date range
- Reassignment required
- Server-side pagination, filtering, and sorting

**Allowed Manager action:**

- **Reassign Order** only when the assigned handler meets the reassignment-eligibility definition in Section 7.1.1.
- Reassignment requires an active eligible replacement and a mandatory reason.
- The original assignment, actor, reason, timestamp, and new handler must remain in the history.

**Not allowed:**

- Routine manual assignment for balancing
- A generic Assign Staff workflow for normal pending work
- A takeover button that hides the responsible staff member
- A second staff member processing an order already claimed/started by Staff A

**Order ownership rule:**

1. New work may appear as pending/unassigned.
2. When Staff A claims or starts the order, the system records the assignment and locks processing ownership.
3. Staff B must be blocked from claiming or processing that order while Staff A remains active.
4. If Staff A becomes inactive, the order moves to a visible reassignment-required state.
5. The Manager selects an active, eligible replacement and records the reason.
6. The replacement becomes the new handler while the full handoff history is preserved.

The lock must be enforced by a server-side conditional transition or transaction. A client-side disabled button is not sufficient.

### 6.3 Repair Jobs

**Purpose:** Give the Manager a complete operational view of repair requests and a controlled path for repairer rejection decisions.

**List fields:**

- Repair request number
- Customer/item context permitted by the shop scope
- Current repair status
- Assigned repairer
- Repairer workload count
- Age and overdue state; show a formal SLA only when a configured SLA policy exists
- Rejection/review state
- Next action

**Views and filters:**

- All repair requests across repairers
- Group by repairer
- Status
- Rejection pending Manager review
- Overdue/aging
- Repairer
- Date range
- Server-side pagination and filtering

**Initial assignment rule:**

- The system assigns a new request to an active, eligible repairer with the lowest active repair workload.
- For this design, workload means the count of active non-terminal repair requests. Terminal, rejected, and completed requests do not count.
- Ties must use a deterministic fair tie-breaker, such as stable rotation or the oldest last-assignment timestamp.
- The assignment and workload check must be atomic so concurrent requests or Managers cannot create an inconsistent assignment.

**Repairer rejection rule:**

1. A repairer rejects the request and must provide a reason.
2. The request enters Manager review; the repairer rejection is not silently converted into a customer rejection.
3. The Manager may override/reassign the request to another active, eligible repairer. The original rejection remains in history.
4. The Manager may make a final shop rejection. This closes the request as `Rejected by Manager` (or the final status name approved during implementation), releases the active workload, records the reason, and triggers the permitted CRM/customer notification path.
5. Any refund or financial remedy remains a separate Finance/Shop Owner decision.

**Repairer-unavailability exception:**

- If the assigned repairer meets the reassignment-eligibility definition in Section 7.1.1 before the request reaches a terminal state, the request enters `Reassignment Required`.
- The Manager may reassign it to another active, eligible repairer with a mandatory reason. This is an operational continuity exception, not routine workload balancing.
- The original repairer, reason, actor, timestamp, and replacement remain in the repair history.
- If no eligible replacement exists, the request enters `Awaiting Assignment` or `Needs Manager Review` and remains visible.

**Not allowed:**

- Manager takeover that implies the Manager will perform the repair
- Normal unassigning
- Routine manual reassignment for workload balancing
- Silent reassignment after a repairer rejection

If no eligible repairer is available, the request must use an explicit `Awaiting Assignment` or `Needs Manager Review` state. It must not disappear into an untracked unassigned pool.

### 6.4 Inventory Overview

**Purpose:** Show the Manager whether inventory health can affect operations.

**Primary content:**

- Shop-wide inventory totals from the server
- Low-stock and out-of-stock counts
- Category and search filters
- Paginated inventory list
- Last updated timestamp
- Links to the supported inventory workflow when the Manager has read access

**Constraints:**

- KPI totals must come from server aggregates, not from the current page of a paginated response.
- Category filtering must be applied server-side before pagination, or the API must explicitly return a complete dataset for client filtering.
- A page named or linked as Product Upload must not claim to support upload/create behavior if it is only a read-only table. It should be renamed, implemented, or redirected after route/consumer review.
- Stock entry, adjustment, supplier, and material-request mutations are outside this Manager design.

### 6.5 Staff & Workload

**Purpose:** Monitor staff availability and identify work that may need an operational exception.

**Primary content:**

- Staff name and role
- Active/on-shift/unavailable state; off-shift alone is informational and is not an automatic reassignment trigger
- Active Job Orders
- Active Repair Jobs
- Overdue work
- Capacity/workload indicator
- Inactive staff with assigned orders requiring reassignment
- Last activity/updated timestamp where supported

**Behavior:**

- This is a monitoring and drilldown page, not a manual repair assignment page.
- Business-type visibility is server-authoritative and mirrored by the UI: retail-only shops show order workload only, repair-only shops show repair workload only, and `both` shops show both. Non-applicable counts, exception links, and workload columns must not be shown.
- A Manager can open the affected Job Orders page to reassign an order whose handler is inactive.
- A Manager cannot use this page to rebalance repair requests arbitrarily.
- A shift ending does not automatically make an order or repair eligible for reassignment unless an explicit handoff policy requires work to be transferred before shift end.
- Attendance controls and sample attendance records must be removed or connected to a real workflow; non-functional controls must not remain in the production UI.
- Performance numbers must be based on the canonical assignment identity and scoped to the selected shop and period.

### 6.6 Leave Approvals

**Purpose:** Provide the Manager's approval-stage view for shop-scoped leave requests.

**Queue fields:**

- Employee
- Leave type
- Date range and number of days
- Request age/overdue; show a formal SLA only when a configured SLA policy exists
- Current status and approval stage
- Coverage/conflict indicator where available
- Next action

**Actions:**

- Approve
- Reject with a mandatory reason
- View request history

**Rules:**

- Employee self-service cancellation is allowed only for the authenticated employee's own eligible request, unless a separate explicit staff-on-behalf capability exists.
- Manager approval is terminal by default: it moves the request to its approved state and applies the balance effect once. A later Owner/HR stage must not be assumed unless a separate policy explicitly requires it.
- Approval locks the request and relevant balance rows, re-checks the pending state inside the transaction, and is idempotent.
- The legacy leave API and newer HR leave API must converge on one authoritative model, field naming convention, lifecycle, and authorization policy before the workflow is considered complete.
- Client/API field names must match the authoritative schema, or a deliberate request/response transformer must be documented and tested. Missing scopes and undocumented lifecycle helpers are not acceptable.

### 6.7 Suspension Approvals

**Purpose:** Handle the Manager stage of the approved HR → Manager → Shop Owner suspension workflow.

**Queue fields:**

- Employee and shop scope
- Requester
- Reason/evidence summary
- Current approval stage
- Request age/overdue; show a formal SLA only when a configured SLA policy exists
- Previous decisions
- Next action

**Actions:**

- Approve Manager stage
- Reject with a mandatory reason
- View the request and employee event history

**Rules:**

- Reading the queue and mutating a decision are separate capabilities.
- Reports, audit, or dashboard access must never grant suspension review.
- HR cannot directly mutate employee suspension status through a broad directory, attendance, or payslip permission.
- Suspension requests and target employees must be tenant-scoped to the acting user's authorized shop.
- Direct employee suspend/activate endpoints must be removed, protected behind the authoritative workflow, or redirected after consumer verification.
- The request row must be locked and conditionally transitioned so two Managers cannot both complete the same `pending_manager` decision.
- Owner-stage review must also re-check the terminal state under lock.

### 6.8 Reports & Analytics

**Purpose:** Provide accurate operational reporting for orders, repairs, inventory health, and staff workload.

**Reports:**

- Order volume, status, throughput, and value
- Repair volume, outcomes, aging, rejection, and reassignment
- Inventory health
- Staff workload and performance

Customer complaint analytics remain CRM-owned unless explicitly published as a read-only cross-module metric.

**Rules:**

- Reports must use the canonical order schema: assigned staff identity, `customer_name` where customer display data is needed, order-item data for product detail, and `total_amount` for totals. Legacy column names must not be assumed without a verified compatibility layer.
- Staff performance must join orders through the canonical assigned staff field. Every employee must not receive the same shop-wide totals.
- Reports must be shop-scoped and period-scoped.
- Report lifecycle must match the execution model. Synchronous generation may resolve directly to ready or failed; asynchronous generation must expose queued/generating/ready/failed states and a retry path.
- A button labeled **Send report** must deliver through a real notification/email/outbox workflow. If delivery is not implemented, the action must be renamed **Mark as reviewed** rather than implying delivery.
- Repeated generation or delivery requests must be idempotent and must not overwrite audit history incorrectly.
- Raw SQL, filesystem, or internal exception messages must not be returned to clients; detailed context belongs in structured server logs.

### 6.9 Audit Logs

**Purpose:** Provide a read-only, searchable record of operational and approval decisions.

**Events to expose:**

- Order claim/start and processing lock
- Order reassignment, original handler, replacement, actor, and reason
- Repair autoassignment and reassignment/override
- Repairer rejection and Manager final rejection
- Leave submission, cancellation, approval, and rejection
- Suspension request and each approval-stage decision
- Report generation, review, and delivery attempts
- Relevant permission changes performed by the authorized administrator

**Rules:**

- Logs are tenant-scoped, paginated, filterable, and read-only.
- Read permission must be honored consistently by route middleware, controller, and service policy; a user with the explicit audit capability must not be rejected merely because the controller checks only a legacy role.
- Historical events are append-only. Corrections create a new event rather than editing the old event.
- The log must show actor, target, timestamp, previous state, new state, reason, and correlation/reference ID where available.

## 7. Cross-cutting workflow and data rules

### 7.1 Tenant and shop scope

Every Manager page, queue, metric, approval, report, and audit query must be scoped to the Manager's authorized shop/tenant. The current design does not require introducing a branch or multi-location model. If an existing branch concept is already used by the application, the implementation plan must document and preserve that existing scope explicitly rather than creating a new branch requirement for this work. Target lookup must validate tenant ownership before showing or mutating a record. IDs from another shop must produce a safe not-found/forbidden response without leaking existence.

### 7.1.1 Inactive and unavailable definition

The following conditions qualify an assigned order or repair request for Manager reassignment:

- Inactive
- Suspended
- Terminated, resigned, or offboarded
- Approved leave that covers the period in which the active work must be handled
- Explicitly unavailable
- Otherwise no longer eligible for that work according to the applicable role, skill, or availability policy

Being merely off-shift does **not** automatically qualify for reassignment. A shift ending may trigger reassignment only when an explicit operational policy says that active work must be handed off before the end of the shift. The same rule applies to Job Orders and Repair Jobs.

### 7.2 Capability separation

The implementation must distinguish at least these semantic capabilities, even if the final permission names differ:

| Capability type | Examples | Must not imply |
|---|---|---|
| Dashboard read | View Manager dashboard and KPIs | Repair decisions, suspension decisions, or permission changes |
| Queue read | View orders, repairs, leave, suspension, reports, or audit | Mutation of that queue |
| Operational mutation | Reassign inactive-handler order; review repair rejection | General employee suspension or permission administration |
| Approval mutation | Approve/reject leave or suspension; finalize repair review | Access to unrelated approval domains |
| Permission administration | Manage direct permissions/roles | General HR, dashboard, report, or audit access |

Use one centralized exact policy/service decision for each mutation entry point. Route groups may gate page reads, but controllers and services must call the same authoritative mutation policy rather than maintaining divergent ad hoc checks. A single Manager permission must not expose unrelated capabilities.

The authority source for legacy `users.role` and Spatie roles/permissions must be consolidated or explicitly mapped. Mixed role casing and inconsistent guard assumptions must not decide authorization accidentally.

### 7.3 Concurrency and idempotency

The following operations require row locks or equivalent conditional transitions and an idempotent response:

- Order claim/start and reassignment
- Leave approval, balance deduction, and eligible leave creation checks
- Manager and Owner suspension review
- Repair rejection review, finalization, and override/reassignment
- Repairer workload check plus assignment
- Report generation and delivery/review status transitions

The state must be re-read inside the transaction. A second request against an already completed transition must return the existing result or a safe conflict; it must not repeat side effects.

### 7.4 Error handling

- Clients receive stable, human-readable error codes/messages appropriate to the action.
- Internal exception details are logged with context but are not serialized into JSON responses.
- Authorization failures do not disclose cross-tenant records.
- Failed file generation, notification delivery, and state transitions have visible retry/error states.

## 8. Role ownership matrix

| Area | Manager | Staff | Repairer | CRM | Shop Owner / Finance |
|---|---|---|---|---|---|
| Job Orders | Monitor; reassign inactive handlers | Claim/process assigned work | N/A unless separately assigned | See customer context as needed | Oversight/escalation |
| Repair Jobs | Monitor all; reassign after rejection or unavailability; final-reject when authorized | N/A | Work assigned repairs; reject with reason | Customer communication/escalation | Final business/financial oversight |
| Customer complaints | Operational escalation only | N/A | Evidence/input only | Own queue and resolution | Visibility/escalation |
| Inventory | Read operational overview | Use authorized stock workflows | Material context as needed | N/A | Own inventory decisions |
| Staff & Workload | Monitor and drill down | Own workload | Own repair workload | N/A | Oversight |
| Leave | Approve/reject within policy | Submit and cancel own eligible request | Submit and cancel own eligible request | N/A | Policy/oversight |
| Suspension | Review Manager stage | Request/subject as policy allows | Request/subject as policy allows | N/A | Owner-stage decision |
| Reports | Operational reports | Limited self/work reports if granted | Limited self/work reports if granted | Complaint reports | Executive/financial reports |
| Audit Logs | Read authorized operational history | Read only if explicitly granted | Read only if explicitly granted | Read CRM scope | Read broader authorized scope |
| Permissions | No administration by default | No | No | No | Dedicated Shop Owner/permission administrator |

## 9. Manager Module Review remediation requirements

The following findings from the read-only review are part of this design contract. They are grouped as requirements, not as an implementation sequence.

### 9.1 Required before production sign-off

| Review finding | Required design response |
|---|---|
| Suspension approval overgrant | Separate queue read from suspension decision capability. Reports, audit, and dashboard permissions must not authorize suspension mutations. |
| Dashboard permission can mutate repairs | Repair review mutations require the dedicated repair-review capability; dashboard read access is never a write fallback. |
| HR suspension cross-tenant access/direct bypass | Centralize tenant-scoped suspension policies and enforce the HR → Manager → Owner lifecycle. Remove or protect direct status mutation. |
| Leave cancellation ownership gap | A user can cancel only their own eligible leave request unless an explicit staff-on-behalf policy exists. Record the requester. |
| Approval race conditions | Lock rows, re-check status within the transaction, apply conditional transitions, and make repeated decisions idempotent. |
| Permission administration overgrant | Remove permission administration from Manager/ordinary HR scope. Require a dedicated Shop Owner or permission-administrator capability, protected boundaries, and audit events. |
| Incorrect staff-performance aggregation | Use canonical `assigned_staff_id`, correct shop/period scope, and populated multi-employee fixtures to verify distinct totals. |
| Sales-report schema drift | Align report queries with the authoritative order/order-item schema and test with populated rows. |
| Missing Manager user-management page | Decide whether it is a supported Manager feature. Default recommendation: remove or redirect it to the authorized HR/Shop Owner access-control flow because permission administration is not in this workspace. |
| Inventory page-derived totals | Use server-side aggregate metrics and apply filters before pagination. |
| Raw exception exposure | Return stable generic errors and log internal details server-side. |
| Repair reassignment too narrowly limited to rejection | Permit an exception reassignment when the assigned repairer becomes unavailable, while preserving the no-takeover/no-unassign/no-routine-balancing rule. |

### 9.2 Required workflow and UX corrections

- Implement or intentionally remove dashboard approval/activity widgets; do not leave defined-but-unrendered components as a misleading contract.
- Make dashboard refresh and drilldown freshness explicit.
- Remove or implement attendance handlers and sample rows.
- Consolidate legacy and newer leave API behavior, naming, scopes, and status lifecycle.
- Add pagination, filtering, age/overdue, responsible staff or waiting-on party, and next-action fields to repair, suspension, leave, and audit queues. Use formal SLA timers only where a configured policy exists.
- Make repair review assignment atomic and visible in the audit trail.
- Correct the read-only Product/Inventory page naming so it does not imply upload/create behavior.
- Ensure report delivery semantics match the button label and expose retry status.
- Make explicit audit permission work consistently through route, controller, and service layers.
- Add Reports to the Manager sidebar.
- Correct Suspension Approvals' module ownership so Finance visibility cannot hide it.

### 9.3 Deferred improvements after the core contract

These are valuable but are not required to validate the approved page structure:

- Saved Manager queue filters and dashboard presets
- Bulk review with an audit event for each record
- Keyboard shortcuts for common approval actions
- Exportable audit and operational reports
- Employee timelines combining leave, suspension, attendance, and performance
- Configurable approval matrices by business type, value, or risk
- Outbox/event-driven notifications
- Snapshot-based historical analytics
- Multi-branch or multi-location support
- Removal of verified dead UI code after external-consumer confirmation
- Consolidation of duplicated authorization helpers after policy coverage exists
- Reduction of N+1 activity-log lookups and other unbounded queries

## 10. UX, accessibility, and responsive requirements

- Use clear status text in addition to color: `Pending`, `In Progress`, `Reassignment Required`, `Awaiting Assignment`, `Pending Manager Review`, `Rejected by Manager`, and terminal states.
- Use warning/danger treatment for overdue, inactive-handler, rejected, and failed states, but do not rely on color alone.
- Destructive or terminal actions require confirmation and a mandatory reason where specified.
- Tables must support keyboard navigation, visible focus, readable column labels, and a responsive alternative for narrow screens.
- Loading states must preserve layout and identify the section being loaded.
- Empty states must explain whether there is no work, no permission, or a filter with no results.
- Stale data must show its last-updated time and offer a retry/refresh action.
- Confirmation dialogs must identify the target record, resulting state, and downstream effect.
- Error messages must explain what the Manager can do next without exposing internal details.

## 11. Acceptance criteria for the design

The design is ready to become an implementation plan when the reviewer agrees that:

1. The sidebar contains exactly the approved Manager pages and excludes header-owned Notifications/Profile.
2. Dashboard is an overview with links, not a duplicate Action Center or CRM complaint queue.
3. Job Orders is restricted to retail-capable shops, allows reassignment only for an inactive/unavailable handler, and preserves a lock and history after claim/start.
4. Repair Jobs shows all repairer workloads, autoassigns by active workload, and has no normal takeover/unassign/balancing flow.
5. A repairer rejection or repairer-unavailability exception routes to the correct Manager review/reassignment state; Manager reassign/override and final rejection have distinct outcomes and audit records.
6. Manager final repair rejection does not silently reassign the request and does not imply a refund decision.
7. Leave and suspension queues are tenant-scoped, capability-scoped, reasoned, and concurrency-safe.
8. Dashboard, inventory, staff, and report metrics distinguish current state from period metrics and use server-authoritative aggregates.
9. Reports are visible in navigation and their delivery/review action is truthful.
10. Audit Logs provide traceability for assignments, decisions, reasons, actors, and timestamps.
11. Manager cannot obtain unrelated mutation authority through dashboard, reports, audit, broad HR, or Finance module permissions.
12. Missing/incomplete routes and UI elements have an explicit keep, implement, redirect, or remove decision before implementation begins.

## 12. Final review decisions requested

The latest pre-freeze recommendations are captured here as the current draft decisions. The spec remains reviewable and will not be treated as frozen until the user confirms it.

| Decision | Current draft decision |
|---|---|
| Repair reassignment | Allow after repairer rejection and as an exception when the current repairer becomes unavailable; do not add takeover, unassign, or routine balancing. |
| Leave approval | Manager approval is terminal by default; no implicit second approval stage. |
| SLA display | Use age/overdue by default. Show a formal SLA only when a defined SLA policy exists. |
| Reports lifecycle | Use queued/generating/ready/failed only for asynchronous generation; keep delivery/review status truthful. |
| Authorization | Use one centralized exact policy/service decision per mutation entry point; avoid duplicated divergent checks. |
| Dashboard signal responsibility | Label the responsible staff member/role or the party the work is waiting on, rather than an ambiguous owner field. |
| Workload measure | Keep active repair-request count for V1; consider weighted capacity later. |
| No eligible repairer | Use `Awaiting Assignment` or `Needs Manager Review`, never an invisible unassigned state. |
| Manager user management | Remove/redirect to HR or Shop Owner access control. A distinct Manager page is not part of this design. |
| DSS Insights | Keep outside the primary Manager sidebar unless a separate read-only Manager capability and ownership are approved. |
| Permission Administration | Keep outside the Manager module; require a dedicated Shop Owner/permission-administrator capability. |
| Action Center | Removed; Dashboard plus page-specific queues are the approved pattern. |
| Report send action | Use real delivery/outbox behavior, or label the action `Mark as reviewed`. |
| Exact status labels | Use the semantic states in this document; finalize database/API labels during planning. |
| Inactive/unavailable definition | Qualifies: inactive, suspended, terminated/resigned/offboarded, approved leave covering the active work, explicitly unavailable, or otherwise no longer eligible. Merely off-shift does not qualify unless an explicit handoff policy requires reassignment before shift end. |
| Refund after repair rejection | Keep as a separate Finance/Shop Owner decision. |
| Job Orders business type | Restrict the Manager page and APIs to `retail` and `both`; repair-only shops do not receive Job Orders. |

## 13. Implementation boundary

This turn creates only this design specification for review. No production routes, controllers, policies, migrations, APIs, pages, tests, dependencies, or generated application assets are changed by this document.

After the user approves this spec, the next document will be a separate file-level written implementation plan covering the existing Manager module, authorization remediation, workflow state transitions, UI changes, tests, and browser verification. That plan must not be started until this review is complete.
