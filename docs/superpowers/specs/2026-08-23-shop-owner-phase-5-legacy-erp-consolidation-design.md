# Shop Owner Phase 5 Legacy and ERP Consolidation Design

**Date:** 2026-08-23

**Status:** Revised design accepted for Phase 5 implementation planning; code implementation pending

> **Revision note (2026-08-25):** The owner-access and approval model was reviewed against the panelist requirement that the Shop Owner can use eligible employee/workforce pages, including a read-only Attendance view. Attendance mutations and employee self-service attendance operations remain excluded. This revision also replaces the previous owner-maker submission block with maker-aware stage routing. The tenant, server-authoritative authorization, audit, and fail-closed requirements remain in force.

## Goal

Give the Shop Owner one canonical, tenant-scoped workspace for discovering and using every eligible, enabled business module, including owner-safe employee/workforce pages and a read-only Attendance view. Retire the separate owner-facing ERP Workspace and duplicate owner entry points only after their useful capabilities have canonical destinations and their owner actions have been proven safe.

Phase 5 consolidates presentation and routing and clarifies maker-aware approval routing. It does not grant blanket employee permissions to the Shop Owner, expose attendance mutations or employee self-service operations, replace authoritative domain services, or create new approval authorities.

## Approved outcomes

Phase 5 delivers:

- one canonical Shop Owner shell under the existing `/shop-owner/*` route family;
- direct module access from a grouped sidebar, without a separate ERP module-picker portal;
- a clear separation between page access, operation permission, and approval authority;
- explicit owner-readability classification for every local subpage and data surface; module visibility alone grants no data access;
- owner-safe employee/workforce pages and a read-only Attendance view are reachable through the canonical Workforce module, while attendance mutations remain employee-only;
- one canonical module landing destination labeled **Dashboard**, using existing owner-safe server-derived metrics, and one local page-navigation contract per module without a duplicate legacy dashboard;
- creation and generation actions inside their parent record pages;
- one canonical transaction/detail page where role- and maker-aware actions are rendered; approval pages are not duplicated across modules and Action Center;
- one Action Center for **Needs My Decision**, **Waiting on Others**, and material exceptions, with **Needs Correction** kept as a separate correction surface and the badge counting **Needs My Decision** only;
- one Audit area that includes material owner activity from all canonical owner surfaces;
- Settings access through the existing header/account menu instead of a sidebar item;
- safe consolidation and staged retirement of duplicate routes and entry points;
- persisted maker identity, backend self-approval protection, and maker-aware stage routing for every owner-accessible approval workflow.

The employee ERP experience remains a separate actor experience and is not redesigned by this phase.

## Scope boundaries

### Included

- Shop Owner navigation, module landings, local tabs, canonical links, and route aliases.
- Owner-facing duplicate Purchase Request, approval, audit, dashboard, product, customer-directory, and report entry points.
- Internal links, notifications, tests, and documented repository callers that target retiring owner routes.
- Owner-visible read access to eligible, enabled modules and the agreed owner-safe employee/workforce pages.
- Explicitly characterized owner mutations, including maker identity, approval behavior, tenant authorization, and audit behavior.
- Canonical transaction-detail links from Action Center and role-aware actions on those details.
- Retirement of the owner-facing ERP Workspace entry and, after parity, its presentation-only feature boundary.

### Excluded

- Replacing existing domain workflows or services.
- Granting the owner employee self-service actions or wildcard employee permissions.
- Exposing attendance mutations or employee self-service attendance operations to the Shop Owner.
- Inventing fallback approvers, statuses, approval tables, or generic mutation endpoints.
- Redesigning employee ERP navigation.
- Creating separate Shop Owner approval pages that duplicate canonical transaction details.
- Adding new SME exception capabilities reserved for Phase 6.
- Deleting an unfamiliar route merely because it is absent from the sidebar.
- Modifying or removing existing untracked or generated files that are unrelated to Phase 5.

## Canonical information architecture

The Shop Owner sidebar contains:

```text
Home
Action Center

Operate
  Retail
  Repair
  Customers
  Payments

Oversee
  Finance
  Workforce
  Inventory
  Procurement
  Logistics

Reports & Audit
  Reports
  Audit
```

Only eligible, enabled modules are shown. Disabled but eligible modules remain manageable through **Settings -> Modules & Team**. Ineligible modules are neither shown nor reachable by guessing a URL.

Business Settings does not appear in the sidebar. The existing header/account menu remains the canonical entry to Settings.

The Action Center badge preserves the Phase 4 contract: it counts only the current **Needs My Decision** items. **Waiting on Others**, material exceptions, and maker/checker conflicts in **Needs Correction** do not increase this badge. Badge and Needs My Decision list qualification must remain identical.

## Module navigation and page ownership

Clicking a module opens its canonical landing destination, labeled **Dashboard**, directly. Dashboard establishes the module context and gives the owner a useful starting surface. It may reuse existing owner-safe read models and compact summary cards; Phase 5 does not create a second Dashboard page, duplicate dashboard API, chart system, or duplicate aggregate source. Existing owner-safe dashboard content is consolidated into the canonical Dashboard, while employee/staff/product-handler dashboards remain unchanged for their actor experience. Module-specific pages appear as tabs or local navigation inside that module rather than expanding the global sidebar. The URL remains deep-linkable and refresh-safe.

Examples:

- Finance: Dashboard, Invoices, and Expenses. The owner can review both pages, but Create Invoice and Create Expense remain unavailable.
- Workforce: Dashboard, Employees, and read-only Attendance. The owner may create an employee record from Employee Directory, but employee account permissions, self-service, and Attendance mutations remain employee/HR-only. Leave, Overtime, and other approval work appears in Action Center only when the owner is the current authorized actor.
- Inventory: Dashboard, Product Inventory, Stock Movement, and read-only Supplier Order Monitoring. Stock upload and supplier-order receiving/mutation actions remain separately guarded.
- Procurement: Dashboard, Purchase Requests, Purchase Orders, Suppliers.
- Logistics: Dashboard, Shipments, Batches, Riders, module settings where authorized.
- Repair: Dashboard with the owner-safe repair dashboard summary, plus Repair Services. The owner may create repair services through the existing tenant-scoped service endpoint.
- Retail, Customers, and Payments use the same Dashboard-plus-local-pages pattern; POS access remains capability-driven.

The final owner local-page contract is:

| Module | Dashboard content | Local owner pages | Owner actions |
| --- | --- | --- | --- |
| Retail | products and order summary | Products, Orders | existing characterized retail actions |
| Repair | repair workload and service summary | Repair Services | Create Repair Service |
| Workforce | employee and leave summary | Employees, Attendance (read-only) | Create Employee; no attendance mutation |
| Finance | invoice and expense summary | Invoices, Expenses | read-only; no Create Invoice/Create Expense |
| Customers / CRM | customer order and review summary | Customers, Customer Reviews | existing owner-safe reads |
| Inventory | product, low-stock, and supplier-order summary | Product Inventory, Stock Movement, Supplier Order Monitoring (read-only) | no supplier-order receiving mutation |
| Procurement | request and purchase-order summary | Purchase Requests, Purchase Orders, Suppliers | existing characterized permissions |
| Logistics | shipment and batch summary | Shipments, Batches, Riders | existing owner batch policy |

### Canonical module dashboard rendering

The canonical `/shop-owner/*` module route renders the real owner-safe
dashboard for that module as its first page. The existing employee/staff
dashboard is reused only when its controller and read model already resolve the
`shop_owner` actor and tenant; otherwise the implementation provides the
smallest module-specific owner dashboard from an existing scoped read model.
The canonical route must not render a generic placeholder or call an
unclassified dashboard API.

The approved dashboard destinations are:

- Retail: the existing owner Retail Dashboard and its approved tenant-scoped
  dashboard statistics read.
- Repair: the existing owner-safe Repair Dashboard.
- Customers/CRM: the existing owner-safe CRM Dashboard.
- Workforce: the existing owner-safe HR Dashboard.
- Finance: the existing owner-safe Finance Dashboard.
- Inventory: the existing owner-safe Inventory Dashboard.
- Procurement: a small owner-safe Procurement Dashboard using existing scoped
  Purchase Request and Purchase Order summary data.
- Logistics: the existing owner-safe Logistics Dashboard.

Dashboard is the canonical first tab. It is not repeated as a local page, and
legacy dashboard GET routes remain compatibility destinations until the route
retirement evidence passes. The canonical dashboard may link to local pages,
but it may not add denied create actions or expose employee/staff-only controls.

The implementation must complete a repository-backed page inventory before finalizing labels or removing duplicates. Existing useful pages are mapped to one canonical destination; they are not removed merely because the example list omits them. An agreed owner-safe page must end with a working canonical destination or a documented, reviewed exclusion; a blank or placeholder module page is not an acceptable final substitute.

### Task 3 execution note

Task 1/2 evidence resolves owner-safe Finance reads: `finance.invoices` and
`finance.expenses` are local tabs with GET page/API contracts, while their
creation and mutation routes remain denied. Workforce includes Employees and
read-only Attendance. Repair includes Repair Services and its characterized
owner-create path; Logistics includes Batches under the existing batch policy;
Inventory includes read-only Supplier Order Monitoring. Dashboard rows are
hidden from owner local tabs and their owner-safe summary content is rendered
inside the canonical Dashboard. The named retail dashboard stats API is
explicitly owner-readable and tenant-scoped; its direct owner request is
covered by `OwnerErpAuthorizationTest`. Local-tab tests must assert these final
destinations and the absence of duplicate Dashboard, approval, audit, and
denied create-only tabs.

### Parent-page actions

Record-creation actions live inside the page that owns the resulting record and
only appear when the capability matrix marks the owner operation `ALLOWED`:

- **Create Employee** belongs inside Employee Directory.
- **Create Repair Service** belongs inside Repair Services.
- **Create Invoice** and **Create Expense** are intentionally denied for the Shop Owner, so their buttons and routes remain absent from the owner experience.
- **Generate Slip** and **Upload Stocks** remain staff-only.
- Equivalent create, upload, generate, export, or archive actions remain with their record list or detail page.

An action does not receive a separate global sidebar entry unless it is a durable destination with an independent browsing purpose.

## Access versus action boundary

Module visibility does not imply that every subpage or data surface is owner-readable, and it is not mutation authorization.

These are separate contracts:

| Layer | Meaning | Shop Owner rule |
| --- | --- | --- |
| Page access | Whether the owner may open a tenant-scoped page or record projection | Allow an owner-safe Attendance projection; attendance mutations remain denied. |
| Operation permission | Whether the owner may create, edit, submit, export, or otherwise mutate from that page | Grant only the exact characterized operation; page access alone grants no mutation. |
| Approval authority | Whether the owner is the current authorized decision-maker for the persisted workflow stage | Resolve from role, maker identity, workflow state, tenant, and policy; never from page visibility alone. |

The Shop Owner may open the canonical landing for every eligible, enabled module in their shop. Each local subpage, record type, report, export, sensitive field set, and aggregate data surface must be classified as owner-readable, conditionally readable, or unavailable using the existing server-authoritative capability and tenant rules. An unclassified surface is not exposed merely because its parent module is visible, but an in-scope owner-safe page may not remain unclassified or represented by an empty placeholder at Phase 5 completion.

Existing owner-authorized actions remain available. A new owner write action is exposed only after all of the following are characterized and tested:

1. the authoritative domain service or controller;
2. the exact owner capability and tenant boundary;
3. the persisted actor and maker fields;
4. the workflow states and downstream authority;
5. approval-toggle behavior, when applicable;
6. audit behavior and safe payload fields;
7. direct API, retry, and invalid-transition behavior.

Staff-only operational actions remain unavailable until an explicit owner contract is approved. Employee self-service pages and rider execution pages remain employee-only.

The approved owner operation boundaries for the canonical pages are explicit:

- Employee Directory: create an employee record is allowed; account creation,
  permission assignment, invitations, and employee self-service remain
  separately guarded.
- Repair Services: create a service is allowed through the existing
  server-authoritative, shop-scoped service endpoint.
- Finance Invoices and Expenses: owner read access is allowed; Create Invoice,
  Create Expense, and their mutations are denied.
- Inventory Supplier Order Monitoring: owner read access is allowed; receiving,
  voiding, and stock-changing operations are denied.
- Logistics Batches: the existing `manage-logistics-batches` policy remains the
  authority for the owner; the page is not a duplicate Dashboard tab.

Owner routes reuse existing domain services and validation where the contracts are actor-safe. An owner adapter may translate authentication and route context, but it may not duplicate business rules or write an owner ID into a staff-only foreign key.

Canonical owner routes, middleware, controllers, and Form Requests resolve the `shop_owner` actor and tenant explicitly. They must not fall through to the default employee guard, including when owner and employee sessions exist simultaneously. Route parameters and client-provided shop identifiers never override the server-resolved owner tenant.

## Centralized approvals

Action Center is the sole primary discovery page for Shop Owner approvals. It includes **Needs My Decision** for actions currently requiring the owner and **Waiting on Others** for submitted or ongoing work whose current responsibility belongs to another existing actor. Finance, Workforce, Procurement, Repair, and other modules may show record status and a contextual link to the canonical Action Center detail, but they do not recreate separate owner approval queues.

The Action Center is an attention aggregator, not a second workflow engine. Selecting a task opens the same canonical transaction/detail page used by the owning module. The detail page renders the actions allowed for the current actor, maker, workflow state, and policy. Stage names describe responsibilities such as `financial_review` and `owner_authorization`; they do not require separate pages named after each role.

Finance Review and Owner Authorization are distinct responsibilities. Finance validates budget, category, amount, and supporting documents. The Shop Owner makes the business authorization decision only when the persisted workflow says the owner is the current eligible authority.

**Needs Correction** remains a distinct correction surface rather than an approval queue or a Waiting on Others bucket. It contains records whose persisted state or maker/checker conflict prevents a valid owner decision and exposes only a separately characterized corrective action.

The seven Phase 4 approval families remain authoritative:

1. Refund;
2. Price;
3. Payslip;
4. Salary Adjustment;
5. Purchase Request;
6. Expense;
7. Repair Reject.

Their existing ON and OFF authority paths remain governed by `docs/architecture/shop-owner-phase-4-approval-matrix.md`. The revised owner-maker route must be characterized per family before code changes; it may reuse a proven independent downstream authority but may not reinterpret an owner-stage approval as completed.

## Maker/checker invariants

Every owner-accessible approval workflow must persist an authoritative maker identity.

Where an existing maker reference is staff-only, the affected record receives a separate nullable `ShopOwner` maker reference rather than a polymorphic actor identity. For example, a staff `requested_by` field may be paired with `requested_by_shop_owner_id`. Naming follows the domain's existing field vocabulary.

Every newly created record in an owner-accessible approval workflow has exactly one authoritative maker from creation. Existing drafts with missing or ambiguous maker data must be reconciled before they can be submitted. For every record entering the approval workflow:

- exactly one staff or Shop Owner maker reference is authoritative;
- both-set and both-null states are invalid;
- approval and independent-review checks use the persisted maker identity, never UI state, route guard assumptions, current session inference, or audit-log inference; a user cannot approve or review an independent-control stage for a record they created;
- maker identity is assigned at creation and is immutable;
- editing, assigning, or submitting another actor's draft does not transfer maker identity;
- when a workflow permits an actor other than the maker to submit a draft, it persists that actor as a separate `submitted_by` identity; submitter attribution can never overwrite or transfer the maker;
- copying or recreating a withdrawn record creates a new record and therefore a new maker identity.

Database constraints are used where the database and migration path can safely express the invariant. Domain validation remains mandatory because authorization cannot depend on client behavior or UI visibility.

### Maker-aware stage resolution

The owner-approval policy is evaluated and snapshotted at submission, but the system must resolve the route from the persisted maker and the required independent responsibilities. A Shop Owner-created record must never be routed back to a Shop Owner approval stage for the same Shop Owner.

| Persisted maker | Required responsibility/policy | Required result |
| --- | --- | --- |
| Employee | Finance review plus owner authorization | Finance review, then the existing owner authorization stage. |
| Finance employee | Owner authorization | Continue to the existing owner authorization stage when the owner is not the maker. |
| Shop Owner | Independent Finance review required | Finance review, then the existing proven non-owner final authority; omit the owner stage that would return to the maker. |
| Shop Owner | Low-risk direct/automated policy | Allow only when an explicit policy and existing audited authority are characterized; never create a self-approval action. |
| Shop Owner | High-risk or independent-approver policy | Route to the existing designated independent authority; if none exists, keep the record out of the approval queue and fail closed with a clear state. |

Changing a setting affects only future submissions. A submitted record retains its snapshotted route.

For every toggle-OFF path, Phase 5 may route only to the already-authoritative downstream stage proven for that workflow. The owner-maker route may reuse that same proven independent authority when the maker would otherwise receive the owner stage, but this is a maker-aware routing decision, not an implicit toggle-OFF decision and not an owner-stage approval. If characterization finds no valid existing independent stage, implementation for that family stops and the focused design is updated. Phase 5 must not invent a fallback approver, treat a skipped owner stage as an owner approval, or bypass a mandatory domain safeguard.

Owner-made records are not placed into a circular owner-approval queue. The server either routes them through the characterized independent path or returns a clear validation/state response when no such path exists, such as:

> This request requires an independent review path. The system cannot send it back to the Shop Owner who created it.

The UI may explain the resolved route, but the backend is authoritative. Automated/direct completion is allowed only when an explicit low-risk policy and audited authority have been characterized; it must not be implemented as a hidden owner approval.

### Existing conflicted records

If a legacy, imported, or previously malformed record reaches an owner-pending state with the same owner as maker:

- Approve and Reject are unavailable;
- the record appears under a separate **Needs Correction** section;
- it is excluded from the actionable approval-count badge;
- Withdraw or Cancel is the only permitted owner resolution when repository characterization proves an existing owner-authorized transition with safe downstream behavior;
- a proven withdrawal or cancellation requires a reason and is audited;
- after a proven withdrawal or cancellation, an authorized staff member may create a new record if the business request is still needed.

Before enabling this resolution for a family, characterize the existing transition, authorized actor, state guard, audit behavior, notification behavior, and downstream effects. Withdrawal is not approval and must not trigger the downstream effect of the original workflow. If no valid existing withdrawal or cancellation transition exists, implementation for that family stops and updates the focused design; Phase 5 does not invent a withdrawal, reassignment, or fallback-approver path. This correction surface is for already-conflicted legacy records, not the normal route for newly created Shop Owner records.

## Canonical Audit

The canonical Audit area includes material actions performed by the Shop Owner from all canonical owner surfaces, including Home, Action Center, Operate, Oversee, Reports, Settings, module Dashboard pages, local module pages, and canonical record details.

Record:

- successful material mutations;
- owner approvals, rejections, withdrawals, and cancellations;
- separate maker, financial-reviewer, and authorizer identities where the workflow supports those responsibilities;
- blocked self-approval or invalid-maker attempts;
- denied sensitive actions;
- protected exports and access to sensitive records where the existing audit policy requires it.

Do not record routine page loads or tab changes.

Each entry includes owner identity, shop, module, action, target type and identifier, safe before/after properties where appropriate, result, timestamp, and correlation or operation identifier. Audit records are tenant-scoped and read-only to the owner. Secrets, credentials, raw sensitive payloads, and unnecessary personal data are excluded.

A committed material owner database mutation and its authoritative operation audit use the same database connection and transaction boundary. A rolled-back operation must not leave a false success audit record. Non-transactional external effects run after commit through the existing durable job/outbox convention where available; otherwise their failure is recorded separately and may use the domain's existing compensation behavior, but it cannot rewrite the committed business action as a false success or failure.

## Route consolidation and retirement

### Canonical-first migration

Before retiring any route or page:

1. inventory repository callers, route names, notification links, tests, browser entry points, and generated route metadata;
2. identify the authoritative destination and actor audience;
3. migrate internal links and notifications;
4. migrate focused tests to the canonical route while retaining compatibility tests;
5. verify tenant, capability, query/filter, record-ID, and safe-return context parity;
6. remove the duplicate only after no valid caller remains.

CodeGraph is used first for repository caller analysis when the index is available. Text search, route listing, test discovery, and browser QA supplement it, especially for string-built URLs and frontend requests.

### Compatibility behavior

- Safe duplicate GET routes may temporarily redirect to the canonical owner destination.
- Redirects preserve supported record IDs, filters, and validated return context.
- Redirects cannot cross actor audiences, weaken authorization, or form loops.
- Mutation routes do not redirect across HTTP methods.
- Duplicate mutation routes remain until all known callers are migrated and equivalent behavior is verified.
- Temporary aliases delegate to the same authoritative action and do not duplicate business logic.
- Typo aliases are retired after repository callers and tests no longer depend on them.

### Development deprecation window

This thesis project does not require a production telemetry window, a minimum number of days, or a full production release cycle. Retirement evidence consists of:

- repository caller analysis;
- route and notification-link inventory;
- focused automated tests;
- full relevant test suites where practical;
- developer browser QA of canonical and compatibility paths;
- explicit confirmation that no documented external integration depends on the route.

An unknown caller is a reason to keep a compatibility route, not a reason to guess.

## ERP Workspace retirement

The separate Shop Owner ERP Workspace is retired as a user-facing module picker. Its useful capabilities remain in the canonical Shop Owner shell.

Retirement requires capability coverage, not visual page-count parity. For every owner capability currently reachable through the Workspace, the implementation records one of:

- canonical destination with equivalent behavior;
- canonical destination with an explicitly approved revised behavior;
- employee-only or unsafe for owners, with a documented exclusion reason;
- obsolete duplicate with evidence that no capability is lost.

The Workspace entry may first redirect through a safe GET compatibility route. Its presentation feature boundary is removed only after the capability matrix, tests, and developer browser QA prove that the canonical shell no longer depends on it.

Earlier designs that require the ERP Workspace to remain the permanent owner module picker are superseded by this Phase 5 design. Their tenant, server-authoritative capability, actor-persistence, domain-service reuse, and audit requirements remain in force.

## Task 1 evidence execution boundary

Task 1 remains the repository evidence baseline in the completed [capability retirement matrix](../../architecture/shop-owner-phase-5-capability-retirement-matrix.md), [maker/checker matrix](../../architecture/shop-owner-phase-5-maker-checker-matrix.md), and [owner-operation audit matrix](../../architecture/shop-owner-phase-5-owner-operation-audit-matrix.md). The 2026-08-25 revision supersedes the old owner-maker blocking rule; the affected maker-aware routes and Workforce page classifications must be re-characterized before implementation. This is an execution boundary only; it does not authorize inventing authority.

- Every `STOP_FOCUSED_DESIGN` row is a hard fail-closed boundary. Tasks 2–6 may classify or consolidate only surfaces proven owner-readable by the evidence and must not expose a STOP row.
- Tasks 7–11 must not add maker fields, owner submission/correction actions, or audit instrumentation for STOP families until focused design/characterization updates the evidence.
- `N/A_NO_OWNER_INITIATION` retains its existing meaning: no owner initiation is inferred, added, or substituted with a redirect.
- The agreed owner-safe employee pages must be characterized and implemented; they may not remain hidden behind an empty placeholder merely because the first inventory was incomplete.
- Current stop categories are unsupported owner-readable data surfaces, including salary owner self-proposer Action Center exposure; the owner audit-export guard mismatch; uncharacterized correction transitions; missing dedicated denied-maker audit implementation; and any owner-made workflow without a proven independent route. The retail dashboard stats read is explicitly named, owner-readable, tenant-scoped, and covered by API authorization tests. These remaining stop rows are evidence gaps, not authorization to invent fixes.

## Failure handling

- Unknown, malformed, disabled, or ineligible modules use the existing safe denial behavior.
- A module page never presents a missing or failed source as an empty successful result.
- An uncharacterized owner mutation remains unavailable with a clear explanation.
- Maker identity validation fails closed before submission.
- Approval-policy evaluation failure does not create a self-approval, circular owner route, or fallback route.
- The Shop Owner's Attendance view returns only its characterized read projection; attendance mutations and employee self-service requests are denied.
- A newly created Shop Owner transaction never enters a queue requiring that same owner to approve it; if no independent route exists, submission remains safely unresolved with a clear state.
- A compatibility redirect never sends an owner to an employee-only login or action.
- A retirement checkpoint stops when caller evidence or capability parity is incomplete.

## Verification and acceptance

### Navigation and capability coverage

- Only eligible, enabled modules appear in the sidebar.
- Disabled eligible modules are managed through Settings; Settings is absent from the sidebar.
- Module links open the canonical Dashboard landing directly; Dashboard may reuse existing owner-safe read models and does not require a duplicate legacy dashboard.
- Every local subpage and data surface has an explicit owner-readability classification; module visibility alone never authorizes its data.
- The agreed owner-safe employee/workforce pages, including the read-only Attendance view, have working canonical destinations; attendance mutations are denied by direct request.
- Parent-page actions are not duplicated as sidebar destinations.
- Action Center includes Needs My Decision and Waiting on Others, while Needs Correction remains a separate correction surface; all approval tasks deep-link to the canonical transaction detail.
- The sidebar badge and Needs My Decision list use the same qualification and exclude Waiting on Others, material exceptions, and Needs Correction records.
- Employee ERP navigation remains unchanged.
- Cross-shop, ineligible, disabled, and employee-only direct requests are denied.
- When owner and employee sessions coexist, canonical owner routes and Form Requests resolve the `shop_owner` actor and tenant and never fall through to the employee guard.
- Every retired Workspace capability has a documented canonical destination or exclusion.

### Maker/checker coverage

For every owner-accessible approval workflow, including owner creation paths that predate Phase 5, tests prove:

- staff maker plus ON follows the normal owner approval path;
- owner maker never receives the same record's owner-authorization stage; it uses the characterized independent downstream authority, whether or not the owner policy is otherwise enabled;
- owner maker with no characterized independent authority is held or rejected safely, without self-approval, circular routing, or synthetic fallback;
- policy changes affect only future submissions and a submitted record keeps its resolved route;
- both or neither maker references prevent submission;
- editing, reassignment, or a different submitter cannot change the maker;
- workflows that allow a different submitter persist that submitter separately without changing the maker;
- direct API calls cannot bypass the backend guard;
- a legacy conflict appears under Needs Correction without Approve or Reject; Withdraw/Cancel appears only after its existing authoritative transition is characterized;
- a family with no valid existing withdrawal or cancellation transition stops for focused design instead of receiving a synthetic resolution;
- conflicted records are excluded from the approval badge;
- tenant isolation and wrong-owner denials hold;
- successful and denied material owner actions produce safe audit evidence.

The seven families are Refund, Price, Payslip, Salary Adjustment, Purchase Request, Expense, and Repair Reject. A family with no owner-accessible initiation action is marked not applicable with repository evidence rather than receiving speculative fields or endpoints.

### Audit coverage

- Material owner actions from every canonical owner surface use the canonical tenant-scoped Audit contract.
- Routine page loads and tab changes remain excluded.
- Successful mutations, denied sensitive attempts, maker/checker conflicts, and characterized corrective actions retain their required safe audit evidence.

### Route retirement coverage

- Safe GET redirects preserve expected context and cannot loop.
- Mutation aliases still behave identically during migration.
- Repository callers, notifications, tests, and documented integrations no longer target retired routes.
- Typo aliases and duplicate mutations are removed only after the caller gate passes.
- Canonical routes retain existing authorization, validation, locking, idempotency, and downstream-effect safeguards.

### Quality evidence

Run the narrowest family and route tests after each coherent change, followed by the relevant broader checks. Expected final evidence includes focused Laravel tests, focused frontend tests, `pnpm run test:frontend`, `pnpm run build`, `composer test` where practical, developer browser QA, and `git diff --check`. A check is reported only if it was actually run.

## Completion criteria

Phase 5 is complete only when:

1. the canonical owner shell is the single primary navigation experience;
2. every eligible, enabled module has a canonical Dashboard landing and explicitly classified local pages and data surfaces, without requiring a duplicate legacy dashboard;
3. the agreed owner-safe employee/workforce pages, including the read-only Attendance view, are operational through canonical routes, while attendance mutations remain denied;
4. Action Center is the single owner approval discovery page, includes Waiting on Others, keeps Needs Correction distinct, and counts only Needs My Decision in its badge;
5. canonical Audit includes material owner actions from all canonical owner surfaces;
6. owner module visibility grants neither unclassified read access nor uncharacterized mutations;
7. every exposed approval-creating owner action enforces persisted, immutable maker identity;
8. no owner can approve or reject their own record, and an owner-made record never loops back to that owner stage;
9. every OFF route and every owner-maker route uses a characterized existing independent authority;
10. caller analysis and verification support each retirement;
11. the owner-facing ERP Workspace is retired without removing useful ERP capability.
