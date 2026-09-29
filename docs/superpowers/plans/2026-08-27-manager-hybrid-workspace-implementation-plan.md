# Manager Hybrid Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Implement the approved Manager Hybrid Workspace in the shop-owner-phase-3-action-center worktree, including the exact sidebar/page contract, secure tenant-scoped operational workflows, repair workload assignment, approval flows, accurate metrics, truthful reports, and auditable history.

**Architecture:** Keep the Manager dashboard as an overview and route work to page-specific queues. Add one central Manager capability/tenant authorization service and reuse it from route middleware, controllers, and domain services. Keep order assignment and repair assignment as separate workflows: orders are claim-locked and may be reassigned only when the handler is inactive or otherwise ineligible; repairs are autoassigned by active workload and may be reassigned only after repairer rejection or an approved unavailability exception. Reuse the existing activity_log/audit infrastructure for append-only assignment and decision history instead of introducing a second history store. Consolidate leave and suspension decisions around their existing HR services and authoritative models.

**Tech Stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, PHPUnit/Pest-style feature tests, Vitest, Vite, Spatie Permission, and the existing activity/audit logging stack.

---

## Scope and execution notes

- Source of truth: docs/superpowers/specs/2026-08-27-manager-hybrid-workspace-design.md.
- Execute from C:/xampp/htdocs/solespace-master/.worktrees/shop-owner-phase-3-action-center on branch feat/shop-owner-phase-3-action-center.
- This is a master plan because the sidebar, authorization, API contracts, workflow states, and dashboard signals must ship as one coherent Manager surface. The tasks are separated into bounded workstreams so each increment can be tested and reviewed independently.
- The worktree already contains unrelated staged, modified, and untracked changes. Before every commit, run git status --short and stage only the exact files listed for that task. Never use git add -A, git reset --hard, or a broad restore operation.
- Do not introduce a new branch or multi-location data model. Use the existing authorized shop_owner_id/tenant scope; if an existing branch concept is discovered during implementation, document and preserve it rather than widening this feature.
- Off-shift alone is informational. It must not trigger reassignment unless a separately configured handoff policy explicitly requires work to move before shift end.
- This plan is for a later implementation session. Do not execute the implementation tasks in the plan during the plan-authoring turn.

## Approved route and page contract

The final Manager sidebar must contain exactly these Manager destinations, subject only to capability-based visibility:

    Manager Dashboard

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

Notifications and Profile remain global header features. There is no Manager Action Center, Customer Complaints queue, generic Assign Staff page, Repair Takeover page, or Manager Permission Administration page.

## File map

### Authorization, tenant scope, and seed contract

- Create: app/Services/Manager/ManagerAuthorizationService.php — semantic Manager capabilities, authorized shop resolution, target-scope checks, and mutation decisions.
- Create: app/Http/Middleware/RequireManagerCapability.php — route/page/API capability gate that delegates to the central authorization service.
- Modify: bootstrap/app.php — register the Manager capability middleware alias.
- Modify: app/Http/Middleware/CheckManagerStaffAccess.php — remove broad “any Manager permission” behavior or make it delegate to the exact page capability contract for legacy callers.
- Modify: database/seeders/RolesAndPermissionsSeeder.php — seed page-read and mutation capabilities separately and remove Manager access to permission administration/foreign sidebar destinations as applicable.
- Modify: routes/web.php and routes/hr-api.php — replace broad Manager role-or-permission groups with per-page/per-mutation gates.
- Modify: tests/Feature/Manager/ManagerAuthorizationTest.php and create tests/Feature/Manager/ManagerCapabilityMatrixTest.php — prove capability separation and cross-tenant denial.

### Assignment and operational domain

- Create: app/Services/Manager/ManagerAssignmentEligibilityService.php — one decision for inactive, suspended, terminated/resigned/offboarded, approved-leave, explicit-unavailability, and otherwise ineligible assignees; explicitly exclude ordinary off-shift.
- Create: app/Models/RepairerUnavailability.php — model the existing repairer_unavailability table and cast the JSON date list.
- Modify: app/Services/HR/EmployeeOperationalPolicy.php — expose/reuse the existing employee account and approved-leave rules for continuing assigned work without duplicating status logic.
- Modify: app/Models/User.php, app/Models/Employee.php, and app/Models/Order.php — add the needed assignment relationships/scopes and ensure assignment fields are mass-assignment-safe and auditable.
- Create: app/Services/Manager/ManagerOrderService.php and app/Http/Controllers/Api/ManagerOrderController.php — shop-scoped order monitoring, eligible replacement lookup, and locked reassignment.
- Create: app/Http/Requests/Manager/ReassignOrderRequest.php — validate replacement and mandatory reason.
- Modify: app/Services/Orders/OrderFulfillmentService.php and app/Http/Controllers/Api/StaffOrderController.php — claim/start an order atomically and block a second staff member from processing Staff A’s active order.
- Create: app/Services/Manager/ManagerRepairService.php and app/Http/Controllers/Api/ManagerRepairController.php — all-repairer listing, atomic workload assignment, rejection review, exception reassignment, and final Manager rejection.
- Create: app/Http/Requests/Manager/RepairManagerDecisionRequest.php — validate distinct reassign/override/final-reject decisions and mandatory reasons.
- Create: database/migrations/2026_08_27_000001_add_manager_assignment_states_to_repair_requests_table.php — add explicit reassignment_required and awaiting_assignment repair states if the production database enum requires them.
- Modify: app/Http/Controllers/Api/RepairWorkflowController.php — delegate existing repairer-facing assignment/rejection paths to the shared service and remove ambiguous Manager takeover/approval behavior.
- Modify: routes/web.php — add Manager order/repair APIs and capability-specific routes.
- Modify or replace after legacy classification: tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php and tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php — preserve only an explicitly approved exceptional Owner-stage policy; otherwise remove obsolete default-flow assertions.
- Modify: tests/Feature/Manager/ManagerOrderAssignmentTest.php, tests/Feature/Orders/OrderFulfillmentPolicyTest.php, tests/Feature/Manager/ManagerRepairRejectionTest.php, and tests/Feature/ManualPosJobOrderAssignmentTest.php — cover locks, eligibility, workload assignment, and compatibility.

### Approvals and HR ownership

- Modify: app/Services/HR/LeaveApprovalService.php and app/Services/HR/LeaveService.php — make one authoritative, locked, idempotent leave approval path.
- Modify: app/Http/Controllers/Erp/HR/LeaveController.php and app/Http/Controllers/Api/LeaveController.php — converge lifecycle/field names, enforce self-cancel ownership, and keep legacy routes as a verified compatibility layer only.
- Modify: app/Http/Controllers/Erp/Manager/SuspensionApprovalController.php, app/Http/Controllers/Erp/HR/SuspensionRequestController.php, and app/Http/Controllers/Erp/HR/EmployeeController.php — enforce the HR → Manager → Shop Owner workflow and remove direct status bypasses.
- Modify: tests/Feature/HR/LeaveControllerTest.php, tests/Feature/Manager/ManagerSuspensionApprovalTest.php, and tests/Feature/Manager/ManagerAuthorizationTest.php; create tests/Feature/HR/SuspensionWorkflowAuthorizationTest.php and tests/Feature/Manager/ManagerLeaveApprovalTest.php.

### Dashboard, inventory, reports, and audit

- Create: app/Services/Manager/ManagerDashboardService.php — current-state/period metrics, responsibility-aware signals, and one consistent snapshot timestamp.
- Create: app/Services/Manager/ManagerReportService.php — canonical order/order-item queries, staff attribution, synchronous report lifecycle, and truthful review/delivery semantics.
- Modify: app/Http/Controllers/Api/ManagerController.php — delegate dashboard/report reads, fix inventory aggregates, eliminate raw exception responses, and retain compatibility only where consumers are verified.
- Modify: app/Http/Controllers/ActivityLogController.php — enforce audit read capability, tenant scope, pagination, and eager-loaded actor/target context.
- Modify: app/Services/NotificationService.php — update Manager deep links and preserve event/audit context for repair/order/approval actions.
- Modify: tests/Feature/Manager/ManagerDashboardKpisTest.php, tests/Feature/Manager/ManagerReportTest.php, tests/Feature/Manager/ManagerAuditLogsTest.php; create tests/Feature/Manager/ManagerInventoryOverviewTest.php.

### Inertia pages, shell, and frontend contracts

- Modify: resources/js/layout/AppSidebar_ERP.tsx and resources/js/layout/__tests__/AppSidebar_ERP.test.tsx — render the exact Manager sidebar and remove header-owned/foreign destinations.
- Create: resources/js/Pages/ERP/Manager/JobOrders.tsx — paginated monitoring view and exception-only reassignment UI.
- Create: resources/js/Pages/ERP/Manager/RepairJobs.tsx — all-repairer workload view with rejection/unavailability review actions; no takeover/unassign/balance controls.
- Create: resources/js/Pages/ERP/Manager/StaffWorkload.tsx — availability/workload monitoring and drilldown links.
- Create: resources/js/Pages/ERP/Manager/LeaveApprovals.tsx — Manager-scoped leave queue using the authoritative HR API.
- Create: resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx — Manager-stage suspension queue.
- Modify: resources/js/Pages/ERP/Manager/Dashboard.tsx, InventoryOverview.tsx, Reports.tsx, and AuditLogs.tsx — align states, metrics, freshness, and action labels with the spec.
- Modify or remove after consumer verification: resources/js/Pages/ERP/Manager/repairRejectReview.tsx, suspendAccountManager.tsx, and productUpload.tsx — retain only as compatibility wrappers when a verified consumer still needs them.
- Modify: resources/js/hooks/useManagerApi.ts — typed dashboard, queue, workload, and freshness response boundaries.

## Implementation tasks

### Task 1: Establish the exact Manager capability and tenant contract

**Files:**

- Create: app/Services/Manager/ManagerAuthorizationService.php
- Create: app/Http/Middleware/RequireManagerCapability.php
- Modify: bootstrap/app.php
- Modify: app/Http/Middleware/CheckManagerStaffAccess.php
- Modify: database/seeders/RolesAndPermissionsSeeder.php
- Modify: routes/web.php and routes/hr-api.php
- Modify: tests/Feature/Manager/ManagerAuthorizationTest.php
- Create: tests/Feature/Manager/ManagerCapabilityMatrixTest.php

- [ ] **Step 1: Add failing capability-matrix tests.**
  - Create fixtures for two shops and Managers with only dashboard-read, queue-read, and individual mutation capabilities.
  - Assert dashboard read does not authorize repair review, suspension review, leave decisions, order reassignment, permission administration, or direct employee status mutation.
  - Assert queue-read does not authorize the corresponding mutation.
  - Assert Manager targets from another shop resolve to a safe 404/forbidden response without leaking the record.
  - Assert the authorized shop is resolved from the Manager’s tenant relationship and never from a request-provided shop ID.

- [ ] **Step 2: Run the focused tests and confirm the current broad gates fail the new assertions.**
  - Run: php artisan test tests/Feature/Manager/ManagerAuthorizationTest.php tests/Feature/Manager/ManagerCapabilityMatrixTest.php
  - Expected: the new capability-separation and cross-tenant assertions fail against the current broad role_or_permission/userHasManagerAccess behavior.

- [ ] **Step 3: Implement the central decision service and middleware.**
  - Define semantic checks for dashboard read, each queue read, order reassignment, repair review, leave decision, suspension decision, report read/review, audit read, and permission administration.
  - Keep legacy role-column/Spatie role compatibility in one mapping function only; do not let mixed role casing or a generic Manager permission authorize every mutation.
  - Add a tenant-scope helper that requires a non-null authorized shop and applies it before loading or mutating targets.
  - Register the middleware alias and preserve its existing redirect/JSON response style for Inertia versus API requests.

- [ ] **Step 4: Update seeded permissions and route gates.**
  - Add missing page-read and mutation permissions to RolesAndPermissionsSeeder.php.
  - Remove Manager’s permission-administration and foreign module grants from the Manager role assignment; keep global notification/profile permissions only for the header, not sidebar navigation.
  - Replace the single broad Manager page/API middleware group with per-route capability checks. Treat legacy access-repair-reject-review and access-suspend-account as compatibility aliases only where explicitly mapped; never use them as a dashboard-to-write fallback.

- [ ] **Step 5: Re-run authorization tests and inspect direct route access.**
  - Run: php artisan test tests/Feature/Manager/ManagerAuthorizationTest.php tests/Feature/Manager/ManagerCapabilityMatrixTest.php
  - Expected: all capability separation, target scope, and safe-denial assertions pass.
  - Run: php artisan route:list --path=erp/manager and php artisan route:list --path=api/manager
  - Expected: each Manager route shows its specific capability gate; no route relies only on the broad Manager role group.

- [ ] **Step 6: Commit only this coherent authorization increment.**
  - Stage the exact files from this task with git add -- paths after checking git status --short.
  - Commit: git commit -m "fix(manager): separate capabilities and tenant scope"

### Task 2: Centralize reassignment eligibility and explicit repair exception states

**Files:**

- Create: app/Services/Manager/ManagerAssignmentEligibilityService.php
- Create: app/Models/RepairerUnavailability.php
- Modify: app/Services/HR/EmployeeOperationalPolicy.php
- Modify: app/Models/User.php and app/Models/Employee.php
- Create: database/migrations/2026_08_27_000001_add_manager_assignment_states_to_repair_requests_table.php
- Modify: database/factories/RepairRequestFactory.php
- Modify: tests/Unit/Services/HR/EmployeeOperationalPolicyTest.php
- Create: tests/Unit/Services/Manager/ManagerAssignmentEligibilityServiceTest.php

- [ ] **Step 1: Add failing eligibility tests.**
  - Cover inactive, suspended, terminated, resigned/offboarded, approved leave covering the active work date, explicit repairer unavailability, and loss of role/skill eligibility.
  - Assert each qualifying condition returns a stable reason code suitable for reassignment_reason/audit metadata.
  - Assert an employee who is merely off-shift remains eligible and does not produce a reassignment reason.
  - Assert the check is shop-scoped and does not treat a same-email or same-ID record in another shop as eligible.

- [ ] **Step 2: Run the unit tests and verify they fail before implementation.**
  - Run: php artisan test tests/Unit/Services/HR/EmployeeOperationalPolicyTest.php tests/Unit/Services/Manager/ManagerAssignmentEligibilityServiceTest.php
  - Expected: the new reason/eligibility assertions fail because no Manager-level decision service exists.

- [ ] **Step 3: Implement the shared eligibility decision.**
  - Reuse EmployeeOperationalPolicy for canonical employee status and approved-leave checks; bridge the existing User↔Employee email relationship without duplicating HR status rules.
  - Read the existing repairer_unavailability record for repair work only; do not use attendance clock-out or ordinary shift end as an automatic trigger.
  - Return an object equivalent to {eligible, reason_code, reason_label} so order and repair workflows use the same policy and audit language.
  - Add the RepairerUnavailability model with tenant-safe relationships and JSON date casting.

- [ ] **Step 4: Add explicit repair waiting/reassignment states if required by the database.**
  - Extend the production enum with reassignment_required and awaiting_assignment while preserving all existing statuses and SQLite test compatibility.
  - Update the factory with named states for unavailable assignment and no eligible repairer.
  - Do not add a branch column or a shift-end reassignment policy.

- [ ] **Step 5: Re-run policy tests and check migration syntax.**
  - Run: php artisan test tests/Unit/Services/HR/EmployeeOperationalPolicyTest.php tests/Unit/Services/Manager/ManagerAssignmentEligibilityServiceTest.php
  - Expected: all qualifying/non-qualifying cases pass.
  - Run: php artisan migrate --pretend
  - Expected: the new migration is listed without an SQL/schema error. Do not run destructive database resets.

- [ ] **Step 6: Commit the eligibility/state contract.**
  - Stage only the task files.
  - Commit: git commit -m "feat(manager): define assignment eligibility states"

### Task 3: Make order claim/start ownership atomic and add Manager order operations

**Files:**

- Create: app/Services/Manager/ManagerOrderService.php
- Create: app/Http/Controllers/Api/ManagerOrderController.php
- Create: app/Http/Requests/Manager/ReassignOrderRequest.php
- Modify: app/Models/Order.php and app/Models/User.php
- Modify: app/Services/Orders/OrderFulfillmentService.php
- Modify: app/Http/Controllers/Api/StaffOrderController.php
- Modify: routes/web.php
- Create: tests/Feature/Manager/ManagerOrderAssignmentTest.php
- Modify: tests/Feature/Orders/OrderFulfillmentPolicyTest.php

- [ ] **Step 1: Write failing order-lock tests.**
  - Assert a pending order can be claimed by Staff A and records assigned_staff_id, assigned_at, and actor metadata.
  - Assert Staff B cannot start/process the order after Staff A owns it, including when Staff B submits a direct status request.
  - Assert two simultaneous claim/start attempts result in one owner and one safe conflict/idempotent existing result.
  - Assert an order with an active handler is not shown as automatically reassignable merely because the handler is off-shift.

- [ ] **Step 2: Write failing Manager order API tests.**
  - Assert GET /api/manager/orders returns all shop-scoped orders with status, handler, age/overdue state, lock state, assignment state, and next action.
  - Assert filters for status, assignment state, handler, age/overdue, date range, and reassignment-required are server-side and paginated.
  - Assert reassignment is denied while the handler remains eligible, requires an active eligible replacement and a non-empty reason, and preserves the original handler.
  - Assert inactive/suspended/terminated/approved-leave/unavailable handlers can be reassigned once to a valid replacement; cross-tenant replacement IDs are denied.

- [ ] **Step 3: Run the tests and confirm the current implementation fails.**
  - Run: php artisan test tests/Feature/Manager/ManagerOrderAssignmentTest.php tests/Feature/Orders/OrderFulfillmentPolicyTest.php
  - Expected: failures for missing assignment-on-claim, second-handler protection, Manager endpoints, and eligibility-only reassignment.

- [ ] **Step 4: Implement atomic claim/start ownership.**
  - Lock the order row inside the existing OrderFulfillmentService transaction before checking status and assignment.
  - Add a small assignment operation that claims only pending/unassigned work or returns the existing owner when the same actor retries.
  - Require the current staff actor to own the order before processing, shipping, or direct completion; keep Manager monitoring/reassignment separate from physical order processing.
  - Update staff list/action projections so unassigned pending work is claimable while another staff member’s active work is visible only where the existing product contract requires it and is not processable.

- [ ] **Step 5: Implement Manager order list, replacement lookup, and reassignment.**
  - Use ManagerOrderService with tenant scope, server-side filters, eager-loaded assigned staff, and computed age/overdue fields. Use formal SLA data only if a configured policy exists.
  - In one transaction, lock the order, re-read the current handler, call ManagerAssignmentEligibilityService, validate the replacement, update assignment fields, and write an append-only activity/audit event containing previous handler, replacement, actor, reason, trigger, and timestamp.
  - Return stable conflict/validation codes for already-changed assignments; do not serialize exception messages.

- [ ] **Step 6: Add routes and run the focused suite.**
  - Add erp.manager.job-orders and API routes for list/show/eligible replacements/reassign with separate read and mutation capabilities.
  - Run: php artisan test tests/Feature/Manager/ManagerOrderAssignmentTest.php tests/Feature/Orders/OrderFulfillmentPolicyTest.php tests/Feature/Notifications/NewOrderStaffNotificationTest.php
  - Expected: all ownership, reassignment, notification, scope, and conflict tests pass.

- [ ] **Step 7: Commit the order workflow increment.**
  - Stage only the order service/controller/request/model/routes/tests.
  - Commit: git commit -m "feat(manager): lock and reassign job orders safely"

### Task 4: Implement the complete Repair Jobs workflow without takeover or routine balancing

**Files:**

- Create: app/Services/Manager/ManagerRepairService.php
- Create: app/Http/Controllers/Api/ManagerRepairController.php
- Create: app/Http/Requests/Manager/RepairManagerDecisionRequest.php
- Modify: app/Http/Controllers/Api/RepairWorkflowController.php
- Modify: app/Models/RepairRequest.php
- Modify: routes/web.php
- Modify: database/factories/RepairRequestFactory.php
- Modify: tests/Feature/Manager/ManagerRepairRejectionTest.php
- Modify: tests/Feature/ManualPosJobOrderAssignmentTest.php
- Inspect and modify/replace only after classification: tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php and tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php
- Create: tests/Feature/Manager/ManagerRepairWorkspaceTest.php

- [ ] **Step 1: Add failing Repair Jobs contract tests.**
  - Assert the Manager list includes requests assigned to every eligible repairer in the authorized shop, with repairer workload count, age/overdue, rejection/review state, and next action.
  - Assert a new request is assigned atomically to the active repairer with the lowest count of active non-terminal requests; terminal/rejected/completed requests do not count.
  - Assert deterministic tie-breaking and that concurrent assignments do not produce inconsistent workload selection.
  - Assert repairer rejection enters Manager review and retains the rejection reason/actor in history.
  - Assert Manager can reassign after rejection or a qualifying repairer-unavailability exception, but cannot takeover, unassign, or manually rebalance a healthy active repair.
  - Assert no eligible replacement produces awaiting_assignment/needs_manager_review and remains visible.
  - Assert final Manager rejection is a terminal shop rejection with a mandatory reason and no implied refund or silent reassignment.

- [ ] **Step 2: Run focused repair tests and confirm current behavior is incompatible.**
  - Run: php artisan test tests/Feature/Manager/ManagerRepairRejectionTest.php tests/Feature/Manager/ManagerRepairWorkspaceTest.php tests/Feature/ManualPosJobOrderAssignmentTest.php
  - Expected: failures identify the current Manager-first-approval/Owner-forwarding path, non-atomic workload check, and missing unavailability state.

- [ ] **Step 3: Classify the legacy Repair → Owner tests before changing their assertions.**
  - Inspect tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php and tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php as characterization tests, not unconditional acceptance tests.
  - If a test represents an explicitly approved high-value/business Owner-stage policy that remains in scope, update and rename it to prove that exception is opt-in, tenant-scoped, and separate from ordinary Manager rejection.
  - If a test represents the obsolete default flow in which every Manager rejection is forwarded to Shop Owner, replace/remove that assertion and add the approved Manager-final-rejection assertion instead.
  - Do not require the new implementation to keep the old Owner notification, Owner queue, or two-way approval behavior merely because the legacy test currently passes.

- [ ] **Step 4: Move shared repairer candidate selection into ManagerRepairService.**
  - Preserve the existing active-repair statuses and workload-limit policy, but make count + eligibility + assignment one transaction with locked rows/conditional state checks.
  - Use the existing Repairer role/tenant constraints and the shared eligibility service. Do not inspect shift end unless a future explicit handoff policy is configured.
  - Keep a thin compatibility wrapper in RepairWorkflowController for repairer-facing callers while all assignment decisions use the shared service.

- [ ] **Step 5: Replace ambiguous Manager rejection actions with distinct decisions.**
  - review: lock and re-check repairer_rejected or reassignment_required.
  - reassign/override: require an eligible replacement and mandatory reason, preserve the rejection/unavailability evidence, set the request back to the assigned-repairer state, and notify the replacement.
  - final-reject: require a mandatory reason, set the approved semantic terminal status (Rejected by Manager at API/UI level), release active workload, notify the customer through the permitted CRM path, and create an audit event.
  - Do not forward a routine Manager rejection to an implicit Owner approval stage. Preserve an Owner-stage route only for a separately documented high-value/business decision and ensure it cannot make Manager operational review ambiguous.
  - Remove or deprecate old approve-rejection semantics after searching all frontend, notification, and route consumers; do not leave a button that says approval while performing reassignment or final rejection.

- [ ] **Step 6: Add repair APIs and page route.**
  - Add GET /api/manager/repairs, detail, eligible-repairer, and explicit review/reassign/final-reject routes with read versus mutation capability gates.
  - Keep any legacy /rejected endpoint only as a verified compatibility adapter with the new response contract; it must not bypass the central policy.
  - Add erp.manager.repair-jobs as the canonical Inertia page route. The old separate rejection-review route becomes a redirect/wrapper only if consumer verification finds a live deep link.

- [ ] **Step 7: Run the approved repair, POS, notification, and authorization tests.**
  - Run: php artisan test tests/Feature/Manager/ManagerRepairRejectionTest.php tests/Feature/Manager/ManagerRepairWorkspaceTest.php tests/Feature/ManualPosJobOrderAssignmentTest.php
  - Expected: Manager operational outcomes, no-takeover/no-unassign rules, workload assignment, scope, and conflict behavior pass.
  - Run the updated legacy notification/Owner-stage tests only if Step 3 classified them as an explicitly retained exceptional workflow.
  - Expected: any retained Owner-stage test proves only the explicit exception; no test requires automatic Owner forwarding for ordinary Manager rejection.

- [ ] **Step 8: Commit the Repair Jobs increment.**
  - Stage only repair service/controller/request/model/migration/routes/factory/tests.
  - Commit: git commit -m "feat(manager): add workload-aware repair review"

### Task 5: Build Staff & Workload monitoring and fix staff attribution

**Files:**

- Modify: app/Http/Controllers/Api/ManagerController.php
- Modify: routes/web.php
- Create: resources/js/Pages/ERP/Manager/StaffWorkload.tsx
- Modify: resources/js/hooks/useManagerApi.ts
- Create: tests/Feature/Manager/ManagerStaffWorkloadTest.php
- Modify: tests/Feature/Manager/ManagerReportTest.php

- [ ] **Step 1: Add failing workload and attribution tests.**
  - Create at least two staff members with different assigned order counts, repair counts, overdue work, and statuses.
  - Assert the Manager API returns distinct per-employee values, not the same shop-wide total for every employee.
  - Assert an inactive staff member with active assigned orders is marked as requiring order reassignment; off-shift alone is informational.
  - Assert all results are scoped to the Manager’s shop and period where a period is selected.

- [ ] **Step 2: Run the new tests and confirm the current shop-wide aggregation fails.**
  - Run: php artisan test tests/Feature/Manager/ManagerStaffWorkloadTest.php tests/Feature/Manager/ManagerReportTest.php
  - Expected: failures for canonical assigned_staff_id attribution, per-user counts, and inactive-handler signals.

- [ ] **Step 3: Implement server-authoritative workload endpoints.**
  - Query employees/users through the authorized shop scope and aggregate active orders by assigned_staff_id and active repairs by assigned_repairer_id.
  - Use the canonical assignment identity and total_amount only where monetary totals are needed; do not join every employee to every shop order.
  - Add server-side search, role/status filters, pagination, last-updated timestamp, and drilldown URLs to Job Orders/Repair Jobs.
  - Keep attendance controls out of this page unless connected to a real API; show availability state without making shift end an automatic reassignment trigger.

- [ ] **Step 4: Implement the page with complete data states.**
  - Add StaffWorkload.tsx with staff/role, active orders, active repairs, overdue work, capacity indicator, unavailable state, and a link to affected work.
  - Render loading, empty, stale, forbidden, and error states without sample rows or non-functional attendance buttons.

- [ ] **Step 5: Run tests and commit.**
  - Run: php artisan test tests/Feature/Manager/ManagerStaffWorkloadTest.php tests/Feature/Manager/ManagerReportTest.php
  - Expected: distinct staff metrics and correct reassignment signals pass.
  - Commit: git commit -m "fix(manager): scope staff workload to assigned work"

### Task 6: Consolidate leave approvals and enforce ownership/idempotency

**Files:**

- Modify: app/Services/HR/LeaveApprovalService.php
- Modify: app/Services/HR/LeaveService.php
- Modify: app/Http/Controllers/Erp/HR/LeaveController.php
- Modify: app/Http/Controllers/Api/LeaveController.php
- Modify: routes/hr-api.php and routes/web.php
- Create: resources/js/Pages/ERP/Manager/LeaveApprovals.tsx
- Modify: resources/js/Pages/ERP/HR/LeaveApprovals.tsx
- Modify: tests/Feature/HR/LeaveControllerTest.php
- Create: tests/Feature/Manager/ManagerLeaveApprovalTest.php

- [ ] **Step 1: Add failing lifecycle and ownership tests.**
  - Assert Manager approval is terminal by default, applies the balance effect once, and records actor/reason/history.
  - Assert rejection requires a reason and does not deduct balance.
  - Assert an employee can cancel only their own eligible request; another employee, a Manager, or a broad HR directory permission cannot use self-cancel as an on-behalf mutation unless an explicit capability exists.
  - Assert a repeated approval/rejection returns the existing terminal result or a stable conflict without duplicate balance deduction or notifications.
  - Assert legacy /api/leave and canonical /api/hr/leave-requests agree on field names, status, scope, and lifecycle.

- [ ] **Step 2: Run leave tests and identify duplicate-path failures.**
  - Run: php artisan test tests/Feature/HR/LeaveControllerTest.php tests/Feature/Manager/ManagerLeaveApprovalTest.php
  - Expected: failures for missing row locks/idempotency, self-cancel ownership, and divergent legacy/new API behavior.

- [ ] **Step 3: Make LeaveApprovalService authoritative.**
  - Lock the leave request and relevant balance row(s), re-read the pending state inside the transaction, validate tenant and approval stage, then apply the transition and balance effect once.
  - Return a stable result for an already completed request; do not run notifications or balance effects twice.
  - Use the selected shop and explicit period/age fields for queue output; show formal SLA only when configured.

- [ ] **Step 4: Converge controllers/routes and build the Manager queue.**
  - Make the HR leave API the canonical source, with legacy endpoints delegating or returning a documented compatibility response after consumer verification.
  - Add a Manager page route erp.manager.leave-approvals that consumes the canonical API with Manager read/decision capabilities.
  - Use a shared typed transformer or one naming convention; do not maintain undocumented snake_case/camelCase drift.
  - Include employee, leave type, dates, days, age/overdue, stage, coverage/conflict, next action, history, and stale/error/empty states.

- [ ] **Step 5: Run the full leave-focused suite and commit.**
  - Run: php artisan test tests/Feature/HR/LeaveControllerTest.php tests/Feature/Manager/ManagerLeaveApprovalTest.php tests/Feature/Manager/ManagerAuthorizationTest.php
  - Expected: terminal Manager approval, ownership, scope, and idempotency cases pass.
  - Commit: git commit -m "fix(manager): make leave approvals scoped and idempotent"

### Task 7: Secure the HR → Manager → Shop Owner suspension workflow

**Files:**

- Modify: app/Http/Controllers/Erp/Manager/SuspensionApprovalController.php
- Modify: app/Http/Controllers/Erp/HR/SuspensionRequestController.php
- Modify: app/Http/Controllers/Erp/HR/EmployeeController.php
- Modify: routes/hr-api.php and routes/web.php
- Create: resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx
- Modify: resources/js/Pages/ERP/Manager/suspendAccountManager.tsx
- Modify: tests/Feature/Manager/ManagerSuspensionApprovalTest.php
- Modify: tests/Feature/Manager/ManagerAuthorizationTest.php
- Create: tests/Feature/HR/SuspensionWorkflowAuthorizationTest.php

- [ ] **Step 1: Add failing suspension authorization/race tests.**
  - Assert queue read does not grant Manager-stage mutation, and dashboard/reports/audit/Finance visibility does not grant suspension review.
  - Assert HR can create/read only within the authorized shop and cannot directly mutate an employee’s suspension status through directory/attendance/payslip permissions.
  - Assert a Manager can act only on pending_manager, a second Manager receives the existing-result/conflict response, and Owner-stage review re-checks the terminal state under lock.
  - Assert cross-shop request IDs do not reveal or mutate the target employee.

- [ ] **Step 2: Run suspension tests and confirm current overgrant/race behavior.**
  - Run: php artisan test tests/Feature/Manager/ManagerSuspensionApprovalTest.php tests/Feature/Manager/ManagerAuthorizationTest.php tests/Feature/HR/SuspensionWorkflowAuthorizationTest.php
  - Expected: failures for broad permission access, direct bypasses, and missing conditional transitions.

- [ ] **Step 3: Implement locked, tenant-scoped review.**
  - Route queue reads and decisions through the central capability service.
  - Lock the suspension request row before checking stage, validate target employee shop ownership, conditionally transition Manager stage, and append the actor/reason/history event.
  - Protect, redirect, or remove direct EmployeeController::suspend/activate after checking all consumers; no broad HR directory permission may bypass the workflow.

- [ ] **Step 4: Render the canonical Manager page.**
  - Use SuspensionApprovals.tsx at erp.manager.suspension-approvals.
  - Require a reason for rejection, show requester/evidence/stage/age/previous decisions/next action, and provide confirmation, loading, error, forbidden, and stale states.
  - Retain suspendAccountManager.tsx only as a compatibility wrapper if a live consumer still references it; otherwise remove it after route verification.

- [ ] **Step 5: Run tests and commit.**
  - Run: php artisan test tests/Feature/Manager/ManagerSuspensionApprovalTest.php tests/Feature/Manager/ManagerAuthorizationTest.php tests/Feature/HR/SuspensionWorkflowAuthorizationTest.php
  - Expected: only the authoritative HR → Manager → Owner workflow can change suspension state.
  - Commit: git commit -m "fix(manager): lock and scope suspension approvals"

### Task 8: Correct dashboard, inventory, and freshness semantics

**Files:**

- Create: app/Services/Manager/ManagerDashboardService.php
- Modify: app/Http/Controllers/Api/ManagerController.php
- Modify: resources/js/hooks/useManagerApi.ts
- Modify: resources/js/Pages/ERP/Manager/Dashboard.tsx
- Modify: resources/js/Pages/ERP/Manager/InventoryOverview.tsx
- Modify: routes/web.php
- Modify: tests/Feature/Manager/ManagerDashboardKpisTest.php
- Create: tests/Feature/Manager/ManagerInventoryOverviewTest.php

- [ ] **Step 1: Add failing dashboard/inventory assertions.**
  - Assert current-state counts and selected-period metrics are separate and shop-scoped.
  - Assert each dashboard signal includes age, severity, status, responsible staff/role or waiting-on party, next page, and snapshot timestamp.
  - Assert dashboard refresh updates KPI and drilldown/signal data from one consistent snapshot or clearly labels stale sections.
  - Assert trend percentages use a defined comparison period and are not hard-coded.
  - Assert inventory metrics are server aggregates independent of the current page, and category/search filters apply before pagination.
  - Assert no full complaint queue, duplicate approval queue, dead sample attendance controls, or implied Manager takeover appears on the dashboard.

- [ ] **Step 2: Run focused tests and confirm current derived/ambiguous behavior fails.**
  - Run: php artisan test tests/Feature/Manager/ManagerDashboardKpisTest.php tests/Feature/Manager/ManagerInventoryOverviewTest.php
  - Expected: failures expose stale/duplicated dashboard sections, page-derived inventory totals, hard-coded/undefined trend semantics, or raw error responses.

- [ ] **Step 3: Implement server-authoritative dashboard and inventory reads.**
  - Move dashboard query/serialization into ManagerDashboardService or a clearly bounded equivalent while preserving existing API compatibility where consumers are verified.
  - Use a shop-scoped SQL inventory source that combines active `inventory_items` with active retail `products` not already represented by an active inventory row; apply business-type visibility, aggregates, search/category/status filters, and pagination in SQL before serialization.
  - Replace per-activity user lookups with eager loading/bounded queries and return generic client errors while logging structured context.
  - Make approval widgets compact summaries with real links, or remove their unused definitions; do not leave dead components.

- [ ] **Step 4: Update the dashboard UI and hook types.**
  - Remove Action Center language and complaint queue behavior.
  - Add clear current-state/period labels, responsible/waiting-on labels, last-updated state, refresh/retry behavior, and empty/loading/forbidden/stale/error states.
  - Ensure all dashboard sections use the same response snapshot and that useManagerApi.ts has typed fields for signals, metrics, and timestamps.

- [ ] **Step 5: Verify dashboard and inventory behavior and commit.**
  - Run: php artisan test tests/Feature/Manager/ManagerDashboardKpisTest.php tests/Feature/Manager/ManagerInventoryOverviewTest.php
  - Expected: aggregate, scope, trend, freshness, and error-contract tests pass.
  - Commit: git commit -m "fix(manager): make dashboard metrics authoritative"

### Task 9: Align reports with canonical schema and truthful lifecycle/actions

**Files:**

- Create: app/Services/Manager/ManagerReportService.php
- Modify: app/Http/Controllers/Api/ManagerController.php
- Modify: resources/js/Pages/ERP/Manager/Reports.tsx
- Modify: routes/web.php
- Modify: tests/Feature/Manager/ManagerReportTest.php

- [ ] **Step 1: Add failing report schema/lifecycle tests.**
  - Create populated orders with different assigned_staff_id, customer_name, total_amount, and order_items; assert report output uses those canonical fields rather than legacy customer, product, or total columns.
  - Assert staff performance differs by assigned employee and is scoped to the selected shop and period.
  - Assert synchronous generation returns ready/generated or failed directly; do not expose queued/generating unless the implementation is actually asynchronous.
  - Assert repeated generation/review requests are idempotent and do not overwrite audit history.
  - Assert the UI action is Mark as reviewed unless a real outbox/email/notification delivery workflow exists; never claim to send when it only updates a database row.
  - Assert raw SQL/filesystem/exception details are absent from JSON errors.

- [ ] **Step 2: Run report tests and confirm current legacy-query/send behavior fails.**
  - Run: php artisan test tests/Feature/Manager/ManagerReportTest.php
  - Expected: failures for canonical schema, staff attribution, truthful action label, and generic error behavior.

- [ ] **Step 3: Implement the report service and compatibility boundary.**
  - Move report definitions/query builders into ManagerReportService; use order_items for product detail and total_amount for totals.
  - Keep the current synchronous file-generation path for V1 unless the repository already has a reliable queue/outbox contract; expose only the statuses actually implemented.
  - Rename the mutation route/action to review semantics, or add a real notification/outbox delivery path with retry state if product approval requires sending. Preserve a legacy route only after consumer verification and make it delegate to the truthful operation.
  - Log detailed failures server-side and return stable error codes/messages.

- [ ] **Step 4: Update Reports.tsx and run the focused suite.**
  - Show report scope/period, generated/failed state, download, review/delivery status, retry where supported, and last updated time.
  - Run: php artisan test tests/Feature/Manager/ManagerReportTest.php tests/Feature/Manager/ManagerDashboardKpisTest.php
  - Expected: canonical query, lifecycle, idempotency, and UI-facing response contract tests pass.

- [ ] **Step 5: Commit the report increment.**
  - Commit: git commit -m "fix(manager): align reports with canonical order data"

### Task 10: Make Audit Logs complete, searchable, and capability-safe

**Files:**

- Modify: app/Http/Controllers/ActivityLogController.php
- Modify: app/Models/Order.php, app/Models/RepairRequest.php, and relevant workflow services
- Modify: app/Services/NotificationService.php
- Modify: resources/js/Pages/ERP/Manager/AuditLogs.tsx
- Modify: routes/web.php
- Modify: tests/Feature/Manager/ManagerAuditLogsTest.php

- [ ] **Step 1: Add failing audit assertions.**
  - Assert a user with the explicit audit-read capability can access the route/controller/service consistently, even without a legacy role string.
  - Assert logs are tenant-scoped, paginated, filterable, read-only, and include actor, target, timestamp, previous state, new state, reason, and correlation/reference ID when available.
  - Assert order claim/start/reassignment, repair autoassignment/rejection/reassignment/final rejection, leave decisions, suspension decisions, and report review/delivery attempts create append-only events.
  - Assert activity/target context is eager-loaded or bounded, not N+1 per row.

- [ ] **Step 2: Run the audit tests and verify the existing legacy-role guard/history gaps fail.**
  - Run: php artisan test tests/Feature/Manager/ManagerAuditLogsTest.php
  - Expected: failures identify inconsistent permission handling, missing assignment reason fields, or unbounded actor lookups.

- [ ] **Step 3: Implement the audit contract.**
  - Use the central capability service for read access and tenant scope.
  - Add structured properties to existing activity/audit events rather than editing historical rows or creating a duplicate history table.
  - Update notification action URLs to canonical Manager destinations; notification read/deep-link behavior remains a header concern, not a sidebar item.
  - Ensure failed notification/report delivery attempts are represented truthfully without making them appear successful.

- [ ] **Step 4: Update the audit page and run tests.**
  - Add filters for event type, actor, target/reference, status, date range, and search; display readable reason/old/new values and last-updated/error/empty states.
  - Run: php artisan test tests/Feature/Manager/ManagerAuditLogsTest.php tests/Feature/Manager/ManagerOrderAssignmentTest.php tests/Feature/Manager/ManagerRepairWorkspaceTest.php
  - Expected: audit entries and deep links match every implemented operational transition.

- [ ] **Step 5: Commit the audit increment.**
  - Commit: git commit -m "feat(manager): complete operational audit history"

### Task 11: Integrate the canonical Manager shell and verify page-level UI contracts

**Files:**

- Modify: routes/web.php
- Modify: app/Http/Controllers/Erp/ReadPageController.php
- Modify: resources/js/layout/AppSidebar_ERP.tsx
- Modify: resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
- Create: resources/js/Pages/ERP/Manager/JobOrders.tsx
- Create: resources/js/Pages/ERP/Manager/RepairJobs.tsx
- Integrate/finalize only: resources/js/Pages/ERP/Manager/StaffWorkload.tsx, LeaveApprovals.tsx, and SuspensionApprovals.tsx created by Tasks 5–7.
- Review/finalize presentation only: resources/js/Pages/ERP/Manager/Dashboard.tsx, InventoryOverview.tsx, Reports.tsx, and AuditLogs.tsx; their workflow/data ownership remains with Tasks 8–10.
- Reuse: resources/js/hooks/useManagerApi.ts; its API typing/data ownership remains with Tasks 5 and 8.

- [ ] **Step 1: Add failing shell and route-contract tests.**
  - Assert Manager navigation contains Dashboard, Job Orders, Repair Jobs, Inventory Overview, Staff & Workload, Leave Approvals, Suspension Approvals, Reports & Analytics, and Audit Logs in the approved groups/order.
  - Assert Notifications and Profile are absent from sidebar items because they belong to the header.
  - Assert Action Center, Customer Complaints, Assist Center/DSS, generic Assign Staff, Repair Takeover, Permission Administration, and misleading Product Upload are absent.
  - Assert hidden capabilities remove only the relevant page and do not become the security boundary.
  - Assert canonical page route names exist and active state remains correct for deep links.

- [ ] **Step 2: Run the frontend tests and confirm the current sidebar fails the exact contract.**
  - Run: pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
  - Expected: failures for missing operational pages, Finance-owned suspension visibility, DSS/Assist Center, and absent Reports/Leave/Staff routes.

- [ ] **Step 3: Wire the canonical route/page map and sidebar.**
  - Add canonical route names: erp.manager.dashboard, erp.manager.job-orders, erp.manager.repair-jobs, erp.manager.inventory-overview, erp.manager.staff-workload, erp.manager.leave-approvals, erp.manager.suspension-approvals, erp.manager.reports, and erp.manager.audit-logs.
  - Use capability-driven visibility for each item and keep server-side authorization on every page/API route.
  - Remove moduleKey: finance from Manager suspension navigation and remove the Assist Center/DSS entry from the Manager list.
  - Keep the existing separate Inventory module navigation for users with explicit inventory capabilities, but do not make Manager’s operational sidebar imply stock mutations.

- [ ] **Step 4: Add the two new Manager pages and integrate the three existing page implementations.**
  - JobOrders.tsx: server pagination/filtering, order detail, handler/lock/age/next action, and a confirmation dialog for exception-only reassignment with mandatory reason.
  - RepairJobs.tsx: all repairers/workload, rejection and unavailability states, reassign/final-reject decisions, and no takeover/unassign/routine balancing controls.
  - Integrate StaffWorkload.tsx, LeaveApprovals.tsx, and SuspensionApprovals.tsx delivered by Tasks 5–7 without recreating their API/workflow logic.

- [ ] **Step 5: Apply the shell-only visual and responsive pass.**
  - Make all nine Manager pages use the current ERP shell, spacing/tokens, status presentation, focus treatment, responsive table/card behavior, and shared loading/empty/forbidden/stale/error patterns.
  - Keep Dashboard, Inventory, Reports, Audit Logs, Staff, Leave, and Suspension business logic in their owning tasks; only make cross-page presentation or route-integration changes here.
  - Do not add a second shell, duplicate fetch layer, duplicate page implementation, or new Manager Action Center.

- [ ] **Step 6: Run frontend contract tests and build.**
  - Run: pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
  - Expected: exact sidebar/page visibility tests pass.
  - Run: pnpm run test:frontend
  - Expected: all existing and new frontend tests pass.
  - Run: pnpm run build
  - Expected: Vite production build completes without unresolved Manager page imports/routes.

- [ ] **Step 7: Commit the Manager shell/UI integration increment.**
  - Stage only the listed frontend/page/route files.
  - Commit: git commit -m "feat(manager): add canonical operational workspace"

### Task 12: Resolve legacy Manager routes and remove misleading/dead surfaces

**Files:**

- Modify: routes/web.php
- Modify: routes/hr-api.php
- Modify: app/Http/Controllers/Erp/ReadPageController.php
- Modify: resources/js/Pages/ERP/Manager/repairRejectReview.tsx
- Modify: resources/js/Pages/ERP/Manager/suspendAccountManager.tsx
- Modify: resources/js/Pages/ERP/Manager/productUpload.tsx
- Modify: resources/js/layout/AppSidebar_ERP.tsx
- Modify: resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
- Modify: route/contract tests that reference legacy Manager destinations, including tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php where applicable.

- [ ] **Step 1: Inventory every legacy consumer before changing routes.**
  - Run targeted searches for erp.manager.user-management, erp.manager.dss-insights, erp.manager.products, erp.manager.repair-rejection-review, erp.manager.suspend-approval, /api/manager/products, /api/manager/dss-insights, and the old report send endpoint across app, resources, routes, and tests.
  - Record each live consumer in the implementation PR/commit notes before deleting or redirecting anything.

- [ ] **Step 2: Add failing route-cleanup assertions.**
  - Assert no Manager sidebar item points to removed/misleading pages.
  - Assert the canonical pages remain reachable with their exact capabilities.
  - Assert a verified legacy deep link either redirects to the canonical page or returns the repository-approved not-found/deprecation response; it must not render a missing component or silently grant new authority.

- [ ] **Step 3: Apply the smallest compatibility cleanup.**
  - Remove or redirect the missing User Management page to the verified HR/Shop Owner access-control destination; Manager does not receive permission administration.
  - Remove Manager DSS/Assist Center navigation; keep a separate explicitly authorized DSS route only if an existing consumer requires it.
  - Rename/redirect the read-only Product Upload route to Inventory Overview or the verified inventory page; never expose an upload/create label for a read-only table.
  - Replace old repair rejection/suspension page components with canonical wrappers only when needed by verified consumers; otherwise delete the orphaned page and imports.

- [ ] **Step 4: Run route/frontend contract checks and commit.**
  - Run: php artisan test tests/Feature/BusinessScaling/ShopModuleRouteCoverageTest.php tests/Feature/Manager/ManagerAuthorizationTest.php
  - Run: pnpm exec vitest run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
  - Expected: no broken route consumers, no misleading navigation, and no deleted page referenced by the bundle.
  - Commit: git commit -m "chore(manager): remove obsolete workspace surfaces"

### Task 13: Complete integrated verification and review gates

**Files:**

- Review all files changed by Tasks 1–12.
- Modify tests/docs only if verification exposes a real contract gap.

- [ ] **Step 1: Run the focused backend Manager/HR suite.**
  - Run:
    php artisan test tests/Feature/Manager/ManagerAuthorizationTest.php tests/Feature/Manager/ManagerCapabilityMatrixTest.php tests/Feature/Manager/ManagerOrderAssignmentTest.php tests/Feature/Manager/ManagerRepairRejectionTest.php tests/Feature/Manager/ManagerRepairWorkspaceTest.php tests/Feature/Manager/ManagerStaffWorkloadTest.php tests/Feature/Manager/ManagerLeaveApprovalTest.php tests/Feature/Manager/ManagerSuspensionApprovalTest.php tests/Feature/Manager/ManagerDashboardKpisTest.php tests/Feature/Manager/ManagerInventoryOverviewTest.php tests/Feature/Manager/ManagerReportTest.php tests/Feature/Manager/ManagerAuditLogsTest.php tests/Feature/HR/LeaveControllerTest.php tests/Feature/HR/SuspensionWorkflowAuthorizationTest.php tests/Feature/Orders/OrderFulfillmentPolicyTest.php tests/Feature/ManualPosJobOrderAssignmentTest.php
  - Expected: all focused Manager, HR, order-lock, repair assignment, report, audit, and compatibility tests pass.

- [ ] **Step 2: Run frontend quality gates.**
  - Run: pnpm run test:frontend
  - Run: pnpm run build
  - Expected: all Vitest tests and the Vite build pass. Do not report a TypeScript check or lint pass because the repository has no committed TypeScript compiler configuration or frontend lint script.

- [ ] **Step 3: Perform browser verification against the running app.**
  - Use the repository’s Playwright/webapp-testing workflow with a Manager fixture for the target shop.
  - Verify sidebar order, header-only notifications/profile, dashboard freshness, Job Orders lock/reassign flow, Repair Jobs rejection/unavailability flow, Staff & Workload drilldowns, Leave/Suspension confirmation/reason flows, Inventory aggregate/filter behavior, Reports truthful action, and Audit Logs traceability.
  - Verify narrow screens provide a usable table/card alternative, focus is visible, confirmation dialogs identify target/result/effect, and color is not the only status signal.
  - Verify direct URLs and cross-shop IDs are denied even when a sidebar item is hidden.

- [ ] **Step 4: Run standards, spec, security, reuse, and simplification reviews sequentially.**
  - Simplification: remove duplicate Manager controller checks, dead widgets, stale page wrappers, and unnecessary new abstractions.
  - Standards: compare PHP/React/route/test changes with nearby repository conventions.
  - Spec: walk the approved design acceptance criteria line by line, including inactive/unavailable semantics and no new branch model.
  - Security: re-check authorization, tenant scope, input validation, uploads/report paths, raw exception handling, and concurrent mutations.
  - Reuse/dead-code: confirm existing HR leave service, order fulfillment transaction patterns, audit/logging, notification, ERP shell, and design-system components are reused; scan changed areas for stale imports and unreachable routes.

- [ ] **Step 5: Run hygiene checks and record evidence.**
  - Run: git diff --check
  - Run: git status --short
  - Expected: no whitespace errors; only intended task files are in the implementation commits, and unrelated worktree changes remain untouched.
  - Record exact test/build/browser commands and results in the implementation handoff. Do not claim completion without fresh verification output.

- [ ] **Step 6: Update durable documentation only when behavior changed.**
- Update the Manager design/spec or a concise change note only if implementation decisions diverge from the approved contract.
- Add only durable, non-sensitive lessons to docs/ai-learning-log.md; never include credentials, tokens, or personal data.

## Execution record

- Tasks 1–12 were implemented and verified incrementally in the `shop-owner-phase-3-action-center` worktree.
- Task 13 integrated verification completed after resolving migration compatibility, repair autoassignment idempotency, soft-deleted-assignee dashboard visibility, stale analytics references, and suspension error-message exposure.
- Focused backend suite: 152 tests passed, 753 assertions.
- Full frontend suite: 138 files passed, 739 tests passed.
- Production build: Vite transformed 3,724 modules successfully.
- Browser smoke: all nine Manager pages, compatibility redirects, header-only notification/profile boundary, and 390px responsive layout passed without 4xx/5xx responses.
- Route catalog, PHP syntax, migration status, and `git diff --check` verification passed. TypeScript and lint checks were not reported because the repository has no committed TypeScript configuration or frontend lint script.
- Frontend commands used `npm.cmd` because `pnpm` was unavailable in the environment.
- Follow-up contract refinement: Job Orders is now restricted at the page, API, and sidebar to retail-capable shops (`retail` and `both`), with regression coverage for repair-only denial and retail/both access.
- Approved follow-up refinement: Manager Dashboard and Staff & Workload now consume server-provided business capabilities, hide non-applicable repair/order metrics, and preserve the existing response shape for compatibility. Manager navigation restores the existing Attendance and My Payslips destinations, with payslips available even when the Finance module is disabled.
- Follow-up verification: the affected backend tests pass (24 tests, 133 assertions), the focused sidebar/capability tests pass (29 tests), the full frontend suite passes (139 files, 746 tests), and the production build transforms 3,725 modules successfully.
- The full Manager feature/unit run has one unrelated legacy failure in `ManagerPaginationTest`: its suspension-list default expects 10 rows while the current endpoint returns 25; all other 146 tests in that run passed.
- No commit, merge, push, or cleanup operation was performed. The worktree's unrelated dirty changes were preserved.

## Definition of done

- Manager navigation matches the approved nine-page structure; Notifications/Profile remain header-owned.
- Dashboard is an overview with actionable signals and freshness, not a duplicate Action Center or CRM complaint queue.
- Job Orders are claim-locked server-side; only inactive/unavailable handlers can be reassigned, with mandatory reason and complete history.
- Repair Jobs show all repairer workloads, autoassign by active request count, permit only rejection/unavailability exception reassignment, and never imply Manager physical repair takeover.
- Leave and suspension decisions are tenant-scoped, capability-separated, reasoned, locked, and idempotent.
- Inventory/report/dashboard metrics are server-authoritative and period/scoped correctly; reports do not claim delivery that did not happen.
- Audit Logs expose actor, target, previous/new state, reason, timestamp, and reference for every Manager operational decision.
- Legacy routes/pages have an explicit keep, implement, redirect, or remove decision backed by consumer verification.
- Focused backend tests, frontend tests, production build, browser verification, git diff --check, and the sequential review gates have fresh recorded evidence.
