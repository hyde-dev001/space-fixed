# Shop Owner Canonical Adaptive Shell Master Design

**Date:** 2026-08-14

**Status:** Approved master design; ready for focused phase specifications

## 1. Goal

Give every Shop Owner one coherent control surface for operating and overseeing an SME while preserving the mature Retail, Repair, Finance, HR, CRM, Inventory, Procurement, Logistics, payment, refund, warranty, payroll, and compliance workflows already present in the system.

The program progressively replaces the competing legacy Shop Owner portal and Shop Owner ERP workspace with one canonical adaptive owner shell. It corrects inconsistent state contracts, centralizes owner attention, reduces routine approval burden, clarifies delegation, and retires duplicate entry points only after capability coverage is proven.

The program simplifies the Shop Owner experience. It does not rewrite the platform's established domain workflows.

## 2. Problem Statement

The Shop Owner surface is feature-complete enough for an SME, but its responsibilities and discovery paths are fragmented:

- `/shop-owner/*` and `/shop-owner/erp/*` provide overlapping owner experiences;
- approvals are distributed across many specialized and sometimes duplicate pages;
- operational and oversight capabilities compete for prominence;
- several ERP pages look operational even when owner mode is read-only;
- order, employee, and Purchase Order state definitions are not consistently consumed;
- the owner often has enough information somewhere in the system but no single place showing what needs attention now;
- mature exception handling exists, but the responsible next actor is not always obvious to the owner.

The desired result is the smallest coherent owner capability set that allows an individual owner to operate directly and a company owner to oversee, approve, configure, and delegate without introducing separate owner systems.

## 3. Core Product Principle

> **One owner shell, adaptive emphasis by operating context.**
> The Shop Owner experience uses a single canonical navigation and interaction model. Individual and microbusiness owners are presented primarily with direct operational capabilities, while company owners are presented primarily with oversight, exception handling, approvals, delegation, and configuration. Capability remains policy-driven; the adaptive shell changes default visibility and prioritization rather than creating separate owner systems.

Adaptive presentation determines default prominence, grouping, ordering, density, and discoverability only. It must not independently create, remove, or redefine domain permissions.

Hidden or de-emphasized navigation does not imply disabled capability. An authorized owner may use a canonical deep link or explicitly open a delegated operational tool even when that tool is not prominent in the default shell.

During migration, canonical shell destinations may resolve to existing legacy or ERP-backed pages. Canonicalization of navigation does not require immediate canonicalization of underlying controllers, components, APIs, or domain services.

For the current thesis deployment, the canonical shell is enabled by committed application defaults rather than environment-controlled cohort rollout. This deployment choice changes presentation availability only; it does not change the authorization, tenant, module, source-state, or domain-service boundaries defined by this design.

## 4. Design Principles

1. Domain state machines remain authoritative.
2. The owner shell presents deterministic projections, not a second source of truth.
3. Authorization remains server-enforced and capability-driven.
4. Presentation adaptation is not a second permission model.
5. Home is the universal owner control surface.
6. The Action Center is the canonical discovery path for owner-required decisions and material exceptions.
7. Specialized domain pages remain the detail and execution surfaces.
8. Routine low-risk work may proceed without owner approval when the Shop Owner's selected policy and fixed domain safeguards permit it.
9. Thresholds never bypass mandatory validation, maker/checker, legal, compliance, payment, tenancy, or source-state rules.
10. Compatibility routes must delegate to authoritative behavior rather than duplicate it.
11. Retirement is based on capability coverage, not page count.
12. Deletion and consolidation are preferred to new workflow machinery.

## 5. Scope

The master program covers:

- one adaptive Shop Owner shell;
- one canonical navigation model;
- a universal Home surface;
- an owner Action Center;
- deterministic owner-facing state projections;
- order, employee, and Purchase Order state consistency;
- fixed approval-policy simplification;
- owner/Finance/staff responsibility boundaries;
- progressive legacy and Shop Owner ERP consolidation;
- controlled status correction where justified;
- employee offboarding;
- repair aging, no-show, and abandonment handling;
- rejected Purchase Request revision or resubmission;
- compatibility, observability, and verification requirements.

## 6. Non-Goals

The program does not:

- rewrite mature refund, failed-delivery, return-to-shop, purchasing receipt, payroll-correction, failed-payment, warranty, or document-renewal workflows;
- replace employee ERP experiences;
- create a configurable workflow or approval engine;
- create configurable organization hierarchies;
- introduce enterprise business intelligence or forecasting;
- make company owners unable to operate directly;
- force individual owners through a management-heavy ERP experience;
- make navigation visibility authoritative for access;
- immediately delete legacy or ERP routes;
- create a persisted `DELEGATE` permission without a separately approved requirement;
- merge Audit into operational oversight.

## Relationship to Existing Designs

This master design changes the long-term Shop Owner experience, not the authority of established domain and security contracts.

- [`2026-08-10-shop-owner-erp-workspace-design.md`](./2026-08-10-shop-owner-erp-workspace-design.md) remains authoritative for ERP actor selection, `ErpActorContext`, route capability classification, tenant isolation, owner-versus-employee audience handling, actor persistence, and domain authorization until a focused phase specification intentionally revises a named contract.
- [`2026-08-10-shop-owner-erp-module-scoped-navigation-design.md`](./2026-08-10-shop-owner-erp-module-scoped-navigation-design.md) remains the transitional navigation authority while the separate owner ERP workspace exists. The canonical shell must reuse its module eligibility and route-catalog decisions rather than create a second catalog.
- [`2026-08-10-business-scaling-module-access-design.md`](./2026-08-10-business-scaling-module-access-design.md) remains authoritative for registration type, business type, module eligibility, enabled state, and business-scaling transitions.
- [`2026-08-11-owner-erp-operational-parity-fixes-design.md`](./2026-08-11-owner-erp-operational-parity-fixes-design.md) and later focused operational designs remain authoritative for the corrected behaviors they introduced.
- Finance, Refund, Logistics, Procurement, Payroll, Warranty, payment-recovery, and compliance designs remain authoritative for their domain invariants.

This master design supersedes the earlier designs only in the final user-facing topology after the relevant phases are implemented: one canonical Shop Owner shell replaces the separate legacy and owner ERP portal experiences. It does not supersede employee ERP navigation or the shared domain safeguards used by owner ERP routes.

Every focused phase specification must list the existing design contracts it preserves, modifies, or supersedes. Until that focused phase is implemented and verified, the existing approved design remains operationally authoritative.

## 7. Canonical Owner Information Architecture

```text
Shop Owner
├── Home
│   ├── Business Summary
│   ├── Required Actions
│   └── Exceptions
├── Operate
│   ├── Retail
│   ├── Repair
│   ├── Customers
│   └── Transactional Payments
├── Oversee
│   ├── Finance
│   ├── Workforce
│   ├── Inventory
│   ├── Procurement
│   └── Logistics
├── Reports & Audit
└── Business Settings
    ├── Profile
    ├── Modules and Team
    ├── Payments and Approvals
    ├── Operations
    ├── Policies and Compliance
    └── Subscription
```

`Operate` and `Oversee` are information-architecture groups, not semantic or authorization boundaries. A company owner may operate directly when authorized, and an individual owner may use oversight views.

Transactional payment handling belongs under `Operate`. Payment-gateway configuration, refund policy, approval thresholds, and other owner-controlled financial rules belong in the existing Shop Settings approval/payment area, reached from the owner account/settings menu rather than a primary sidebar group. Finance workflows may consume those settings but Finance actors do not gain permission to change them unless a separate capability explicitly permits it.

`Reports & Audit` remains separate because audit evidence is not an operational work queue.

## 8. Adaptive Presentation

### Individual or microbusiness owner

Home emphasizes:

- today's orders and repairs;
- customers requiring attention;
- transactional payments;
- immediate stock alerts;
- pickup and delivery execution;
- operational exceptions.

Direct Retail and Repair tools are prominent. Oversight and reporting remain available without dominating the workflow.

### Company owner

Home emphasizes:

- `Needs My Decision`;
- urgent business exceptions;
- items waiting on staff, Finance, HR, Procurement, or Logistics;
- cash and financial exposure;
- workforce and payroll exposure;
- compliance deadlines;
- delegated-operation health.

Operational tools remain accessible when capability permits them, but they do not dominate the default navigation.

### Shared shell rules

- Both contexts use the same shell, route catalog, capability decisions, tenant isolation, notifications, and audit rules.
- Owner context is derived from registration type, business type, enabled modules, and server-provided capabilities.
- The client never grants capability based on inferred context.
- The server may provide presentation metadata, but route and domain authorization remain authoritative.

## 9. Universal Home Contract

Every owner Home contains the same concepts:

```text
Home
├── Business Summary
├── Required Actions
└── Exceptions
```

Only weighting, ordering, density, and default filter differ by owner context.

The Business Summary prioritizes concise SME signals:

- sales, cash, and expense position;
- order and repair throughput;
- customer-blocking work;
- stock and purchasing exposure;
- workforce and payroll exposure;
- failed payments, refunds, and deliveries;
- compliance deadlines.

Module dashboards remain available for drill-down. The owner should not need to visit each dashboard to discover an urgent decision.

## 10. Action Center Purpose

The Action Center is the canonical owner entry point for pending decisions and material business exceptions. Specialized approval and operational pages remain the authoritative detail and execution surfaces, but the owner should not need to search those pages individually to discover that action is required.

The Action Center answers:

1. What requires the owner's decision?
2. What material exception should the owner know about?
3. Who owns the next action?
4. What is the business exposure?
5. How long has the item been waiting?
6. Where is the authoritative detail or execution surface?

It is a prioritized control surface, not a giant inbox and not a generic workflow engine.

## 11. Action Center Read Model

The first implementation introduces no universal workflow table. A bounded owner-attention query composes qualifying records from authoritative domains into a normalized read model.

```text
OwnerAttentionItem
- source_type
- source_id
- stable source identity
- shop_owner_id
- module
- category
- primary_bucket
- title
- concise business summary
- priority
- monetary or operational exposure
- created_at
- due_at
- age
- waiting_on
- owner_action_required
- exception reason
- canonical detail URL
- allowed action capabilities
```

The source record remains authoritative. The Action Center does not persist a duplicate approval state. An item changes or disappears when the underlying domain state no longer qualifies.

Owner-facing projected states must be deterministic mappings from authoritative records and must not conceal a condition that changes whether the owner may act.

## 12. Action Center Presentation

The Action Center has three layers:

### Summary

- Needs My Decision
- Urgent Exceptions
- Waiting on Others

### Bounded priority queue

- show only the first 5–10 owner-relevant items per section;
- order by urgency, business exposure, due date, age, and deterministic ID tie-breaker;
- provide `View all` for deeper review.

### Filtered views

- Needs My Decision
- Exceptions
- Waiting on Others
- Finance
- HR
- Procurement
- Logistics
- Retail
- Repair

`Needs My Decision` is the default company-owner view.

An item has exactly one primary attention bucket at a time:

1. if owner action is required, use `Needs My Decision`;
2. otherwise, if the condition is materially urgent, use `Urgent Exceptions`;
3. otherwise, if another role owns the next action, use `Waiting on Others`.

This rule prevents duplicate cards from appearing across the primary sections.

## 13. Initial Action Center Sources

### Required Actions

- high-value or exceptional Refund approvals;
- material Expense approvals;
- material Product or Repair pricing approvals;
- material or exceptional Purchase Request approvals;
- payroll-batch approval and exceptional payslips;
- material Salary Adjustments;
- severe or disputed Employee Suspensions;
- disputed Repair Rejections;
- High-Value Repair approvals.

Routine transactions are excluded when owner-controlled fixed policy allows them to proceed without owner approval.

### Exceptions

- failed or retryable Refunds;
- failed or expired Payments;
- failed deliveries awaiting retry or return resolution;
- returned shipments awaiting receipt;
- rejected Payroll awaiting HR correction;
- overdue or blocked Repairs;
- repairs awaiting parts beyond the configured attention period;
- customer pickup or no-show aging;
- low-stock exposure requiring purchasing action;
- defective or partial supplier receipts requiring follow-up;
- expiring or rejected compliance documents;
- module or access configuration problems that block normal operation.

Informational exceptions may appear with `owner_action_required = false` and a clear `waiting_on` role.

## 14. Action Center Interaction

The initial Action Center deep-links to canonical domain detail pages. Inline mutations are added only in focused phase designs where:

- the existing domain endpoint already supports the action;
- the summary provides enough decision context;
- confirmation and reason capture remain complete;
- validation and authorization remain in the domain workflow;
- audit attribution remains authoritative.

The Action Center never sends a generic `approve` command.

Relationship to other surfaces:

- **Action Center:** current business attention derived from live state;
- **Notifications:** event delivery and awareness that may be read or archived;
- **Audit:** immutable evidence of decisions and changes.

Reading or archiving a notification does not resolve an Action Center item. Resolving the source workflow does.

## 15. Owner-Facing State Projection

Every owner-facing workflow projection provides only the decision-relevant contract:

```text
OwnerWorkflowState
- phase
- display_label
- terminal
- waiting_on
- owner_action_required
- next_action
- age
- due_at
- exception_reason
```

`waiting_on` represents responsibility. It is not another domain status.

Domain-specific services or query boundaries produce these mappings. The design does not add a universal persisted state machine.

## 16. Order State Contract

Fulfillment and Refund concerns are separated:

```text
Fulfillment
pending -> processing -> shipped -> delivered -> completed
    └──────────────────────────────> cancelled

Refund
none -> requested -> approval/return/payout stages -> partial/full refund
```

Rules:

- `delivered` is a fulfillment and custody fact.
- `completed` is a business closure state.
- Any automatic `delivered -> completed` transition is defined by order-domain policy, not inferred by the UI.
- Refund activity is derived from Refund records rather than used as an alternative fulfillment state.
- Owner and staff controls use named actions rather than an unrestricted status dropdown.
- Controlled corrections require authorization, reason, locking, source-state revalidation, and audit.
- Corrections cannot erase payment, refund, inventory, proof, delivery, or custody history.
- Financial and inventory errors use domain reversals or compensation.
- Existing `refund` Order status may be mapped temporarily during migration until callers and historical rows are reconciled.

## 17. Employee State Contract

Canonical Employee account state is:

```text
active | inactive | suspended | terminated
```

- `active`: currently employed and operational.
- `inactive`: still employed or retained in records but temporarily non-operational; it is not a substitute for termination.
- `suspended`: temporarily restricted through a disciplinary or risk workflow.
- `terminated`: permanently offboarded.
- `on_leave`: derived from approved Leave covering the relevant date.
- `probation`: an employment attribute or lifecycle field, not an account-access state.

Employee filters, linked-user access, payroll eligibility, invitations, analytics, and reporting consume the same canonical rules. Legacy values and spellings are reconciled before strict constraints are enforced.

## 18. Purchase Order State Contract

Active Purchase Order states are:

```text
sent | confirmed | in_transit | partially_received
```

- Partial and defective receipts remain supported.
- `delivered` means ordered quantities were physically received.
- `completed` means receipt and linked financial consequences are finalized.
- Dashboard counts, filters, low-stock logic, and monitoring use canonical model scopes.

## 19. Refund and Repair Projection

Refund internals retain separate owner, Finance, return, and payout stages. The owner projection exposes:

- case state;
- return state;
- payout state;
- waiting role;
- next action;
- material failure reason.

Repair internals retain their detailed workflow. The owner projection groups them into:

- intake;
- approval or confirmation;
- in repair;
- blocked or awaiting parts;
- ready for customer;
- delivery or pickup;
- closed;
- exception.

Detailed states remain visible in authoritative records and audit. Simplification must not hide a condition affecting whether the owner may act.

## 20. Approval Policy

The system uses fixed workflow policies, not a configurable approval builder.

Phase 4 evolves the existing Shop Settings approval section and `ShopOwnerApprovalPolicyService`. It does not introduce another settings page, a second configuration store, or a generic workflow engine.

For each supported workflow, owner-controlled policy determines:

1. the owner's approval-participation mode;
2. the amount, risk, dispute, or exception threshold requiring owner approval;
3. whether Finance or another domain validation is required;
4. which conditions always require owner attention;
5. the role responsible for delegated review or execution after approval;
6. the owner's notification preference, independently from approval responsibility.

The conceptual participation modes are:

```text
always
→ every otherwise-valid qualifying request requires an owner decision

policy_based
→ an owner decision is required only when an authoritative threshold,
  risk, dispute, or exception policy requires it

delegated
→ routine approvals route to an authorized staff role where domain policy permits
→ mandatory owner cases still route to the owner
```

These names define product semantics; exact stored values may follow repository conventions. `Require my approval` and `Notify me` are independent concerns. Disabling a notification must not remove an approval responsibility, and requesting a notification must not grant decision authority.

Existing `enabled` and `limit` settings must be compatibility-mapped to the expanded policy without silently changing a shop's current approval behavior. Reconciliation and verification precede stricter enforcement.

```text
Transaction created
        |
fixed domain policy
   /            \
routine          material or exceptional
   |                   |
proceed          Owner Action Center
                       |
                approve or reject
                       |
             Finance or Operations executes
```

Thresholds determine whether owner approval is required. They never bypass mandatory domain validation, maker/checker rules, legal or compliance requirements, payment safeguards, tenant isolation, or source-state prerequisites.

The default is that an actor does not create and approve the same record. A fixed domain policy may permit direct owner operation where a legitimate owner-operated SME workflow requires it.

Owner-controlled policies remain in the existing Shop Settings approval section, reachable from the owner account/settings area. They do not require a primary sidebar group. Finance may consume those policies but cannot modify them without an explicit separate capability.

The Action Center is the canonical discovery surface for owner-required decisions. Authoritative domain approval/detail pages remain the execution, validation, reason-capture, and audit surfaces. Removing a consolidated `Approval Pages` sidebar group does not transfer mutation logic or approval history into the Action Center.

## 21. Owner-Controlled Approval Participation Targets

The targets below describe recommended `policy_based` defaults, not mandatory reductions in owner involvement. A shop may select `always` where the workflow supports it. `delegated` is available only where authoritative domain policy, staff capability, maker/checker requirements, and mandatory safeguards permit delegation.

| Workflow | Target Owner Role |
|---|---|
| Refund | Approve only high-value, disputed, policy-breaking, or exceptional cases |
| Price change | Approve changes breaching fixed percentage or margin rules |
| Expense | Approve above-limit, unbudgeted, or exceptional expenses |
| Repair pricing/service | Approve new or materially risky services; avoid routine Finance-to-Owner-to-Finance cycles |
| Purchase Request | Approve high-value, unbudgeted, capital, or new-supplier exposure |
| Payslip | Approve payroll-batch totals and exceptions rather than every routine payslip |
| Salary Adjustment | Approve permanent or material changes outside delegated bands |
| Suspension | Approve severe, disputed, or high-risk cases while preserving emergency owner suspension |
| Repair Rejection | Approve disputed, warranty, high-value, or customer-risk cases |
| High-Value Repair | Retain threshold-based owner approval |

Finance, owner, and operation responsibilities are separated:

```text
Finance: validate evidence, accounting, budget, margin, and payment state
Owner: accept material business risk or policy exception
Operations: execute the approved action
```

A workflow does not require both Finance and owner approval twice unless the facts or financial exposure materially change between stages.

Each approval-family phase records baseline approval volume and the expected impact of each supported participation mode. Verification confirms that the owner's selected settings are honored, mandatory cases still route correctly, all required checks execute, and no shop is silently migrated to fewer approvals.

## 22. Responsibility Model

Owner-facing capabilities are classified for presentation as one or more of:

```text
OPERATE
APPROVE
MONITOR
CONFIGURE
DELEGATE
```

- Individual owners default toward `OPERATE`.
- Company owners default toward `APPROVE`, `MONITOR`, `CONFIGURE`, and `DELEGATE`.
- A capability may belong to more than one presentation group.
- `DELEGATE` describes the expected operating model; it is not automatically a persisted permission or assignment feature.
- Server-authoritative capabilities decide whether the owner may use an action.
- Operational pages with no owner action or material decision are renamed as overviews or removed from primary navigation.

## 23. Canonical Route and Shell Strategy

`/shop-owner/*` is the long-term owner route family. The employee ERP experience is unaffected.

During migration:

- canonical shell links may target existing `shop-owner.*` or `shop-owner.erp.*` routes;
- existing React components, controllers, APIs, and services are reused;
- the owner ERP workspace remains available until canonical capability coverage is complete;
- the ERP workspace feature flag may control rollout presentation but must not redefine domain authorization;
- compatible legacy GET routes may redirect after canonical destinations exist;
- mutation routes remain until callers are migrated and equivalent behavior is verified.

The separate Shop Owner ERP workspace is retired only as a user-facing portal. Its useful ERP-backed capabilities remain available through the canonical owner shell.

An entry point may be retired only when every owner capability it exposes has a canonical destination with equivalent or intentionally revised behavior. Retirement is based on capability coverage, not page count.

## 24. Compatibility Rules

1. Existing domain services remain authoritative throughout migration.
2. Temporary aliases call the same controller or action; they do not duplicate business logic.
3. Safe legacy GET routes may redirect after canonical destinations exist.
4. Redirects preserve intended context such as record IDs, supported filters, and validated safe return URLs where applicable.
5. Mutation routes remain until frontend callers, notification links, tests, and documented external callers migrate.
6. Old and new routes enforce identical tenant, capability, validation, and state rules.
7. The route catalog remains the machine-readable capability source.
8. Adaptive presentation metadata does not become a second authorization catalog.
9. Hidden navigation never revokes capability.
10. Data reconciliation precedes strict state constraints.
11. Compatibility removal requires caller and runtime-entry-point evidence.
12. Redirects cannot create loops.
13. Every owner-required decision has exactly one canonical discovery path in the shell even when multiple compatibility URLs still resolve to the same authoritative detail page.

## 25. Failure Handling and Observability

### Action Center aggregation

- Aggregation performs no business mutation.
- A failed source is reported as unavailable rather than presented as zero pending items.
- Successfully loaded sources remain usable where a safe partial response is possible.
- Partial-source failures are logged with the affected source, shop context, and correlation identifier.
- User-facing errors do not reveal sensitive internals.
- Source records remain reachable through canonical detail pages.
- Stable source identity prevents duplicates from overlapping queries.
- Pagination and sorting are deterministic.

### Approval policy

- Failure to evaluate a financial or sensitive threshold fails closed to the existing or manual approval path.
- A threshold does not authorize an invalid transition.
- Existing idempotency, transactions, locks, payment safeguards, and compensation remain intact.
- External delivery failures cannot produce a false successful business decision.

### State correction

- Corrections require an authorized actor and reason.
- Source state is locked and revalidated.
- Audit records previous and resulting state.
- Payment, inventory, proof, refund, payroll, and custody history cannot be erased.
- Unsafe corrections use domain reversal or compensation.

### Shell and compatibility

- Unavailable modules show a stable reason and management destination.
- Presentation rollback can restore the prior shell without reversing completed data reconciliation.
- Legacy and canonical routes cannot redirect into each other.
- A compatibility failure cannot silently send an owner to a route for the wrong actor audience.

## 26. Performance and Scale

- Home and Action Center queries are tenant-scoped, bounded, paginated, and deterministically sorted.
- Default queues show no more than the configured small summary limit.
- Summary counts and list records use the same qualification definitions.
- Queries reuse existing indexed shop, status, and date fields where sufficient.
- New indexes are added only from measured query evidence.
- Domain details are loaded on demand rather than fully hydrating every Action Center item.
- The first version does not add a synchronization job or universal attention table.

## 27. Program Phases

### Phase 1 — State and Responsibility Correctness

**Objective:** Correct source contracts before aggregating or simplifying them.

Scope:

- Employee state normalization and legacy reconciliation;
- Order fulfillment, delivery, completion, Refund, and correction contracts;
- Purchase Order active-state consistency;
- Logistics owner API/UI responsibility verification;
- owner login GET/POST consistency;
- deterministic Refund and Repair owner projections;
- focused authorization, state, migration, and negative-path tests.

This phase does not change the primary navigation.

### Phase 2 — Canonical Adaptive Shell Foundation

**Objective:** Establish one shell without rewriting domain pages.

Scope:

- canonical owner shell and navigation;
- server-provided presentation metadata;
- context-based emphasis;
- deep-link access to de-emphasized authorized tools;
- separate Reports and Audit area;
- grouped Business Settings;
- existing page reuse through canonical destinations.

### Phase 3 — Home and Action Center

**Objective:** Make all owner-required decisions and material exceptions discoverable from one bounded control surface.

Scope:

- universal Home;
- summary counts;
- Needs My Decision, Urgent Exceptions, and Waiting on Others;
- filtered and paginated views;
- fixed priority rules;
- source availability and observability;
- canonical detail links;
- notification-link migration where appropriate.

### Phase 4 — Owner-Controlled Approval Policy

**Objective:** Allow each Shop Owner to choose approval participation per supported workflow while preserving mandatory domain safeguards and clear execution responsibility.

Approval families proceed in this order unless a focused design proves a dependency change:

1. Purchase Requests and Expenses;
2. Refunds;
3. Product and Repair pricing;
4. Payroll and Salary Adjustments;
5. Suspensions, Repair Rejections, and High-Value Repairs.

For each family, Phase 4 evolves the existing Shop Settings approval controls and the existing approval-policy service rather than creating a parallel configuration surface. Current `enabled` and `limit` values are compatibility-mapped before expanded modes are enforced. Notification preferences remain separate from approval responsibility.

Each family defines supported participation modes, compatibility mapping, authoritative thresholds, mandatory owner cases, Finance/owner/operation responsibility, delegation eligibility, notification behavior, exception routing, baseline approval volume, expected mode impact, and evidence that the selected settings are enforced safely. The Action Center discovers required decisions; existing domain workflows continue to execute them.

### Phase 5 — Legacy and Shop Owner ERP Consolidation

**Objective:** Retire duplicate owner discovery and navigation after capability parity.

Scope:

- canonical sidebar links;
- duplicate Purchase Request, approval, audit, dashboard, product, and report entry-point consolidation;
- compatible GET redirects;
- notification, test, and internal-link migration;
- typo alias retirement;
- duplicate mutation route retirement after caller migration;
- Shop Owner ERP workspace entry retirement after capability coverage;
- workspace feature-boundary retirement only after production no longer depends on it.

### Phase 6 — Remaining SME Exception Capabilities

**Objective:** Add only the operational gaps still justified after simplification.

Scope:

- Employee offboarding;
- Repair aging and awaiting-parts escalation;
- customer no-show and abandoned-Repair resolution;
- rejected Purchase Request revise, clone, or resubmit.

### Post-Program Optional Enhancements

- daily owner digest;
- saved Action Center filters;
- configurable summary period;
- optional explicit owner-operated presentation preference if registration context and actual behavior prove insufficient.

These enhancements do not block the core program.

## 28. Verification Strategy

### State contracts

- valid and invalid Order transitions;
- custody `delivered` versus policy closure `completed`;
- Refund projection without fulfillment corruption;
- controlled correction and immutable evidence;
- Employee state normalization and linked-user access;
- Leave and probation derivation;
- Purchase Order active-state consistency;
- deterministic Repair and Refund projections.

### Adaptive shell

Test at least:

- individual Retail owner;
- individual Repair owner;
- combined individual owner;
- company Retail owner;
- company Repair owner;
- combined company owner;
- disabled and ineligible modules;
- authorized deep links to de-emphasized operations;
- denied capabilities despite manually entered URLs;
- simultaneous owner and employee sessions where relevant.

### Action Center

- inclusion and exclusion for every source;
- exactly one primary bucket per item;
- owner-action-required precedence;
- waiting-on ownership;
- threshold boundary behavior;
- source-state resolution removes items;
- counts match list definitions;
- pagination and deterministic sorting;
- partial-source failure without false zero;
- correlation-aware error logging;
- tenant isolation;
- bounded query count and no N+1 behavior.

### Approval simplification

- below, equal-to, and above-threshold cases;
- mandatory checks below threshold;
- maker/checker and self-approval defaults;
- Finance/owner/operation responsibility;
- duplicate submission and stale-state rejection;
- payment and Refund idempotency;
- Action Center visibility after every transition;
- before/after owner-approval volume;
- material-risk case retention.

### Migration and UI

- one canonical discovery path per owner decision;
- one canonical sidebar destination per capability;
- capability-coverage inventory before entry-point retirement;
- context-preserving legacy redirects;
- no duplicate mutation behavior;
- notification and Audit links;
- browser navigation for both operating contexts;
- keyboard, focus, responsive, empty, loading, partial-error, and unavailable-module behavior;
- no regression to customer, employee, Finance, Logistics, or compliance workflows.

### Quality gates

Each focused phase runs:

- the narrowest relevant Laravel or frontend tests first;
- relevant broader suites;
- `pnpm run build` when frontend behavior changes;
- browser verification for visible workflows;
- `git diff --check`;
- route and capability inspection;
- before/after evidence for query count, duplicate routes/pages, or approval volume where applicable.

## 29. Program Acceptance Criteria

The master program is complete when:

1. One canonical adaptive owner shell serves individual and company owners.
2. Home always provides Summary, Required Actions, and Exceptions.
3. Owners can discover all meaningful pending decisions from the Action Center.
4. Every owner-required decision has exactly one canonical discovery path.
5. Every Action Center item has exactly one primary bucket and clear responsible role.
6. Default queues are bounded and cannot bury owner-required decisions beneath informational work.
7. Domain state remains authoritative and projections are deterministic and decision-complete.
8. Employee, Order, and Purchase Order state contracts are consistent.
9. Routine low-risk work follows the Shop Owner's selected approval policy subject to mandatory domain safeguards.
10. Thresholds never bypass mandatory controls.
11. Finance, owner, and operational responsibilities are explicit.
12. Approval-family changes preserve existing shop behavior during migration and demonstrably honor each owner's selected participation mode while preserving mandatory routing.
13. Authorized operational tools remain reachable when de-emphasized.
14. Legacy or ERP entry points are retired only after capability coverage.
15. The separate Shop Owner ERP portal is retired without removing useful ERP capability.
16. Compatibility redirects preserve relevant safe context.
17. Partial Action Center failures are visible and operationally observable.
18. Mature Refund, Logistics, purchasing receipt, failed-payment, payroll-correction, warranty, and compliance safeguards remain intact.
19. No generic workflow engine, duplicate source of truth, or second permission model is introduced.

## 30. Focused Phase Specifications

This master document defines program direction and invariants. It is not a file-level implementation plan.

Before implementation, each phase receives a focused design specification covering:

- exact current behavior and affected workflows;
- accepted product decisions;
- data and state migration;
- route, controller, service, model, and component boundaries;
- authorization and tenant rules;
- failure and rollback behavior;
- focused acceptance criteria;
- verification and measured evidence;
- compatibility and deployment sequence.

After each focused design is approved, a separate TDD implementation plan identifies exact files, failing tests, narrow commands, migration steps, review gates, and completion evidence.

The first focused specification is **Phase 1 — State and Responsibility Correctness**.
