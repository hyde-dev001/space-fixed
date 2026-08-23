# Shop Owner Phase 5 Legacy and ERP Consolidation Design

**Date:** 2026-08-23

**Status:** Approved design and written-spec review; pending final user review

## Goal

Give the Shop Owner one canonical, tenant-scoped workspace for discovering and using every eligible, enabled business module. Retire the separate owner-facing ERP Workspace and duplicate owner entry points only after their useful capabilities have canonical destinations and their owner actions have been proven safe.

Phase 5 consolidates presentation and routing. It does not replace authoritative domain services, grant blanket employee permissions to the Shop Owner, or create new approval authorities.

## Approved outcomes

Phase 5 delivers:

- one canonical Shop Owner shell under the existing `/shop-owner/*` route family;
- direct module access from a grouped sidebar, without a separate ERP module-picker portal;
- explicit owner-readability classification for every local subpage and data surface; module visibility alone grants no data access;
- one canonical module landing destination labeled **Overview** and one local page-navigation contract per module, without requiring a new dashboard;
- creation and generation actions inside their parent record pages;
- one Action Center for **Needs My Decision**, **Waiting on Others**, and material exceptions, with **Needs Correction** kept as a separate correction surface and the badge counting **Needs My Decision** only;
- one Audit area that includes material owner activity from all canonical owner surfaces;
- Settings access through the existing header/account menu instead of a sidebar item;
- safe consolidation and staged retirement of duplicate routes and entry points;
- persisted maker identity and backend self-approval protection for every owner-accessible approval workflow.

The employee ERP experience remains a separate actor experience and is not redesigned by this phase.

## Scope boundaries

### Included

- Shop Owner navigation, module landings, local tabs, canonical links, and route aliases.
- Owner-facing duplicate Purchase Request, approval, audit, dashboard, product, customer-directory, and report entry points.
- Internal links, notifications, tests, and documented repository callers that target retiring owner routes.
- Owner-visible read access to eligible, enabled modules.
- Explicitly characterized owner mutations, including maker identity, approval behavior, tenant authorization, and audit behavior.
- Retirement of the owner-facing ERP Workspace entry and, after parity, its presentation-only feature boundary.

### Excluded

- Replacing existing domain workflows or services.
- Granting the owner employee self-service actions or wildcard employee permissions.
- Inventing fallback approvers, statuses, approval tables, or generic mutation endpoints.
- Redesigning employee ERP navigation.
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

Clicking a module opens its canonical landing destination, labeled **Overview**, directly. Overview establishes the module context and gives the owner a useful starting surface. It may reuse an existing summary, list, or landing page; Phase 5 does not require creating a new dashboard, metric cards, charts, or duplicate aggregate queries. Module-specific pages appear as tabs or local navigation inside that module rather than expanding the global sidebar. The URL remains deep-linkable and refresh-safe.

Examples:

- Finance: Overview, Invoices, Expenses.
- Workforce: Overview, Employees, Attendance, Leave, Overtime, Payroll, Salary Adjustments.
- Inventory: Overview, Product Inventory, Stock Movement, Stock Requests, Material Requests, Supplier Orders.
- Procurement: Overview, Purchase Requests, Purchase Orders, Suppliers.
- Logistics: Overview, Shipments, Batches, Riders, module settings where authorized.
- Retail, Repair, Customers, and Payments use the same Overview-plus-local-pages pattern.

The implementation must complete a repository-backed page inventory before finalizing labels or removing duplicates. Existing useful pages are mapped to one canonical destination; they are not removed merely because the example list omits them.

### Parent-page actions

Record-creation actions live inside the page that owns the resulting record:

- **Create Invoice** belongs inside Invoices.
- **Generate Slip** belongs inside Payroll or Payslips.
- **Upload Stocks** belongs inside Product Inventory.
- Equivalent create, upload, generate, export, or archive actions remain with their record list or detail page.

An action does not receive a separate global sidebar entry unless it is a durable destination with an independent browsing purpose.

## Access versus action boundary

Module visibility does not imply that every subpage or data surface is owner-readable, and it is not mutation authorization.

The Shop Owner may open the canonical landing for every eligible, enabled module in their shop. Each local subpage, record type, report, export, sensitive field set, and aggregate data surface must be classified as owner-readable, conditionally readable, or unavailable using the existing server-authoritative capability and tenant rules. An unclassified surface is not exposed merely because its parent module is visible.

Existing owner-authorized actions remain available. A new owner write action is exposed only after all of the following are characterized and tested:

1. the authoritative domain service or controller;
2. the exact owner capability and tenant boundary;
3. the persisted actor and maker fields;
4. the workflow states and downstream authority;
5. approval-toggle behavior, when applicable;
6. audit behavior and safe payload fields;
7. direct API, retry, and invalid-transition behavior.

Staff-only operational actions remain unavailable until an explicit owner contract is approved. Employee self-service pages and rider execution pages remain employee-only.

Owner routes reuse existing domain services and validation where the contracts are actor-safe. An owner adapter may translate authentication and route context, but it may not duplicate business rules or write an owner ID into a staff-only foreign key.

Canonical owner routes, middleware, controllers, and Form Requests resolve the `shop_owner` actor and tenant explicitly. They must not fall through to the default employee guard, including when owner and employee sessions exist simultaneously. Route parameters and client-provided shop identifiers never override the server-resolved owner tenant.

## Centralized approvals

Action Center is the sole primary discovery page for Shop Owner approvals. It includes **Needs My Decision** for actions currently requiring the owner and **Waiting on Others** for submitted or ongoing work whose current responsibility belongs to another existing actor. Finance, Workforce, Procurement, Repair, and other modules may show record status and a contextual link to the canonical Action Center detail, but they do not recreate separate owner approval queues.

**Needs Correction** remains a distinct correction surface rather than an approval queue or a Waiting on Others bucket. It contains records whose persisted state or maker/checker conflict prevents a valid owner decision and exposes only a separately characterized corrective action.

The seven Phase 4 approval families remain authoritative:

1. Refund;
2. Price;
3. Payslip;
4. Salary Adjustment;
5. Purchase Request;
6. Expense;
7. Repair Reject.

Their existing ON and OFF authority paths remain governed by `docs/architecture/shop-owner-phase-4-approval-matrix.md`.

## Maker/checker invariants

Every owner-accessible approval workflow must persist an authoritative maker identity.

Where an existing maker reference is staff-only, the affected record receives a separate nullable `ShopOwner` maker reference rather than a polymorphic actor identity. For example, a staff `requested_by` field may be paired with `requested_by_shop_owner_id`. Naming follows the domain's existing field vocabulary.

Every newly created record in an owner-accessible approval workflow has exactly one authoritative maker from creation. Existing drafts with missing or ambiguous maker data must be reconciled before they can be submitted. For every record entering the approval workflow:

- exactly one staff or Shop Owner maker reference is authoritative;
- both-set and both-null states are invalid;
- self-approval checks use the persisted maker identity, never UI state, route guard assumptions, current session inference, or audit-log inference;
- maker identity is assigned at creation and is immutable;
- editing, assigning, or submitting another actor's draft does not transfer maker identity;
- when a workflow permits an actor other than the maker to submit a draft, it persists that actor as a separate `submitted_by` identity; submitter attribution can never overwrite or transfer the maker;
- copying or recreating a withdrawn record creates a new record and therefore a new maker identity.

Database constraints are used where the database and migration path can safely express the invariant. Domain validation remains mandatory because authorization cannot depend on client behavior or UI visibility.

### Owner-made submission decision

The owner-approval setting is evaluated and snapshotted at submission.

| Persisted maker | Snapshotted owner stage | Result |
| --- | --- | --- |
| Staff | ON | Continue through the existing owner-required workflow. |
| Shop Owner | ON | Block submission before the record enters the approval workflow. |
| Staff | OFF | Continue through the proven existing non-owner authority path. |
| Shop Owner | OFF | Continue only through the same proven existing non-owner authority path. |

Changing a setting affects only future submissions. A submitted record retains its snapshotted route.

For every toggle-OFF path, Phase 5 may route only to the already-authoritative downstream stage proven for that workflow. If characterization finds no valid existing non-owner stage, implementation for that family stops and the focused design is updated. Phase 5 must not invent a fallback approver, treat an OFF toggle as approval, or bypass a mandatory domain safeguard.

Owner-made records with an ON owner stage are not automatically approved, silently skipped, reassigned, or placed into the normal approval queue. The server returns a clear validation response such as:

> This request requires independent Shop Owner approval. Because you created this request, you cannot submit and approve it yourself.

The UI may explain or disable the submit action when the conflict is already known, but the backend is authoritative.

### Existing conflicted records

If a legacy, imported, or previously malformed record reaches an owner-pending state with the same owner as maker:

- Approve and Reject are unavailable;
- the record appears under a separate **Needs Correction** section;
- it is excluded from the actionable approval-count badge;
- Withdraw or Cancel is the only permitted owner resolution when repository characterization proves an existing owner-authorized transition with safe downstream behavior;
- a proven withdrawal or cancellation requires a reason and is audited;
- after a proven withdrawal or cancellation, an authorized staff member may create a new record if the business request is still needed.

Before enabling this resolution for a family, characterize the existing transition, authorized actor, state guard, audit behavior, notification behavior, and downstream effects. Withdrawal is not approval and must not trigger the downstream effect of the original workflow. If no valid existing withdrawal or cancellation transition exists, implementation for that family stops and updates the focused design; Phase 5 does not invent a withdrawal, reassignment, or fallback-approver path.

## Canonical Audit

The canonical Audit area includes material actions performed by the Shop Owner from all canonical owner surfaces, including Home, Action Center, Operate, Oversee, Reports, Settings, module Overview pages, local module pages, and canonical record details.

Record:

- successful material mutations;
- owner approvals, rejections, withdrawals, and cancellations;
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

## Failure handling

- Unknown, malformed, disabled, or ineligible modules use the existing safe denial behavior.
- A module page never presents a missing or failed source as an empty successful result.
- An uncharacterized owner mutation remains unavailable with a clear explanation.
- Maker identity validation fails closed before submission.
- Approval-policy evaluation failure does not create a self-approval or fallback route.
- A compatibility redirect never sends an owner to an employee-only login or action.
- A retirement checkpoint stops when caller evidence or capability parity is incomplete.

## Verification and acceptance

### Navigation and capability coverage

- Only eligible, enabled modules appear in the sidebar.
- Disabled eligible modules are managed through Settings; Settings is absent from the sidebar.
- Module links open the canonical Overview landing directly; Overview may reuse an existing useful page and does not require a newly built dashboard.
- Every local subpage and data surface has an explicit owner-readability classification; module visibility alone never authorizes its data.
- Parent-page actions are not duplicated as sidebar destinations.
- Action Center includes Needs My Decision and Waiting on Others, while Needs Correction remains a separate correction surface.
- The sidebar badge and Needs My Decision list use the same qualification and exclude Waiting on Others, material exceptions, and Needs Correction records.
- Employee ERP navigation remains unchanged.
- Cross-shop, ineligible, disabled, and employee-only direct requests are denied.
- When owner and employee sessions coexist, canonical owner routes and Form Requests resolve the `shop_owner` actor and tenant and never fall through to the employee guard.
- Every retired Workspace capability has a documented canonical destination or exclusion.

### Maker/checker coverage

For every owner-accessible approval workflow, including owner creation paths that predate Phase 5, tests prove:

- staff maker plus ON follows the normal owner approval path;
- owner maker plus ON is blocked at submission;
- owner maker plus OFF uses only the proven existing downstream authority;
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
2. every eligible, enabled module has a canonical Overview landing and explicitly classified local pages and data surfaces, without requiring a new dashboard;
3. Action Center is the single owner approval discovery page, includes Waiting on Others, keeps Needs Correction distinct, and counts only Needs My Decision in its badge;
4. canonical Audit includes material owner actions from all canonical owner surfaces;
5. owner module visibility grants neither unclassified read access nor uncharacterized mutations;
6. every exposed approval-creating owner action enforces persisted, immutable maker identity;
7. no owner can approve or reject their own record;
8. every OFF route uses a proven existing authority;
9. caller analysis and verification support each retirement;
10. the owner-facing ERP Workspace is retired without removing useful ERP capability.
