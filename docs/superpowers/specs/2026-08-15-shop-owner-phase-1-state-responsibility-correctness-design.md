# Shop Owner Phase 1 — State and Responsibility Correctness Design

**Date:** 2026-08-15

**Status:** Approved design; implementation plan pending review

**Program:** Shop Owner Canonical Adaptive Shell

**Phase:** 1 of 6

## 1. Purpose

Correct state definitions, responsibility boundaries, authorization behavior, and sign-in routing before the canonical owner shell or Action Center consumes them.

Phase 1 preserves mature domain workflows. It removes duplicated interpretations of those workflows so that controllers, services, projections, metrics, and user interfaces make the same decisions from the same authoritative records.

The implementation principle is:

> **Canonicalize rules, not infrastructure.** Introduce small domain-specific policies and projections for Order state, Employee state, Purchase Order state, Logistics responsibility, and unified sign-in context. Existing domain services remain authoritative for mutations. Callers migrate incrementally to canonical rules, and legacy data is reconciled before stricter validation or constraints are applied.

The core boundary is:

> **Canonical policies are the shared decision boundary; mutation services remain the shared execution boundary.**

## 2. Relationship to Existing Designs

This specification implements Phase 1 of [`2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md`](./2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md).

It preserves the following existing contracts except where this specification explicitly narrows or corrects them:

- [`2026-08-10-shop-owner-erp-workspace-design.md`](./2026-08-10-shop-owner-erp-workspace-design.md) for ERP actor context, tenant isolation, and route capability classification;
- [`2026-08-10-shop-owner-erp-module-scoped-navigation-design.md`](./2026-08-10-shop-owner-erp-module-scoped-navigation-design.md) for transitional module eligibility and route catalog behavior;
- [`2026-08-10-business-scaling-module-access-design.md`](./2026-08-10-business-scaling-module-access-design.md) for registration type, business type, module eligibility, and enabled state;
- [`2026-08-11-owner-erp-operational-parity-fixes-design.md`](./2026-08-11-owner-erp-operational-parity-fixes-design.md) for corrected owner ERP behavior;
- [`2026-08-11-finance-integrity-design.md`](./2026-08-11-finance-integrity-design.md) for Finance integrity and approval boundaries;
- [`2026-08-02-inventory-procurement-boundary-receiving-ui-design.md`](./2026-08-02-inventory-procurement-boundary-receiving-ui-design.md), [`2026-08-02-sme-procurement-repair-design.md`](./2026-08-02-sme-procurement-repair-design.md), and [`2026-08-02-procurement-practical-gaps-design.md`](./2026-08-02-procurement-practical-gaps-design.md) for purchasing, receipt, inventory, and expense behavior;
- approved Logistics designs for shipment state, assignment, proof, return, audit, and dispatcher behavior.

This focused specification supersedes the master document's earlier implication that an Order normally progresses from `delivered` to `completed`. For retail Orders, `delivered` and `completed` are alternate terminal fulfillment outcomes. The Purchase Order domain retains a meaningful `delivered -> completed` administrative closure sequence.

## 3. Scope

### In scope

- Order fulfillment transition rules, alternate terminal outcomes, Refund separation, owner projection, labels, and controlled corrections;
- Employee account-state normalization, derived Leave and probation concepts, linked-user access, assignment eligibility, and Payroll boundaries;
- Purchase Order receiving, delivery, administrative closure, cancellation, metrics, and correction boundaries;
- Logistics owner dispatch, review, custody, assignment, capability, tenant, and proof responsibility rules;
- one shared sign-in presentation with explicit Customer/Staff and Shop Owner contexts;
- reconciliation discovery, idempotent normalization, caller migration, observability, and focused verification.

### Out of scope

- primary Shop Owner navigation changes;
- the canonical adaptive shell or Action Center implementation;
- approval-volume simplification;
- a generic workflow engine or universal transition service;
- a duplicate persisted owner-state or attention model;
- merging the `user` and `shop_owner` guards;
- replacing mature Refund, Logistics, Procurement, Payroll, inventory, or payment services;
- treating `individual / owner-operated` as a new database business type or permission category.

## 4. Architecture

The domain flow is:

```text
authoritative domain record
        |
        v
small domain-specific policy
        |
        v
existing mutation service or action
        |
        v
deterministic owner projection
        |
        v
current UI and future owner shell
```

Policies answer narrow questions:

- whether a transition is valid from current source state;
- whether an Employee is operational, login-eligible, assignment-eligible, or routinely payroll-eligible;
- whether a Purchase Order is active receiving, awaiting closure, closed, or cancellable;
- whether an actor may perform a specific Logistics action;
- which trusted authentication context receives a sign-in submission.

### 4.1 Policy rules

1. Policies are side-effect free by default.
2. Policies do not quietly mutate records, dispatch events, or perform external calls.
3. Policies are domain-specific and small.
4. No generic `WorkflowStateService`, universal transition engine, or speculative shared status abstraction is introduced.
5. Existing services remain responsible for transactions, locks, writes, inventory effects, payments, events, notifications, and audit evidence.
6. Controllers and UI components do not reimplement policy decisions.

Illustrative boundaries, with exact class names to follow repository conventions during planning:

```text
Orders       -> Order transition policy + owner projection
Employees    -> Employee operational policy + owner projection
Procurement  -> Purchase Order state policy
Logistics    -> Logistics owner capability policy
Authentication -> shared sign-in presentation + separate trusted handlers
```

### 4.2 Projection rules

1. Projections are deterministic functions of authoritative records and policies.
2. Projections may expose values such as `can_*`, `owner_action_required`, `waiting_on`, or `business_closed` for presentation.
3. Projections never authorize a mutation.
4. Every mutation endpoint reloads or locks current source state and re-evaluates authorization and policy.
5. A projection must not conceal a condition that changes whether an actor may act.

## 5. Order Contract

### 5.1 Canonical fulfillment states

```text
pending | processing | shipped | delivered | completed | cancelled
```

The canonical paths are:

```text
Delivery fulfillment
pending -> processing -> shipped -> delivered

Pickup or direct fulfillment
pending -> completed
processing -> completed
```

`pending -> completed` is allowed only for a domain-defined direct or pickup workflow in which no processing step is required. It is not a generic shortcut around normal processing.

### 5.2 Alternate terminal outcomes

- `delivered` means a delivery fulfillment and custody event has been confirmed.
- `completed` means a pickup or other direct fulfillment path has been explicitly closed.
- `delivered` and `completed` are distinct valid terminal outcomes.
- Neither normally transitions into the other.
- Any correction between terminal outcomes is exceptional and audited.
- A future post-delivery closure step is introduced only if a business event exists beyond renaming the status.

The central semantic rule is:

> **Fulfillment state describes how an Order ended; Refund state describes what happened financially afterward.**

### 5.3 Commercial closure projection

The owner-facing projection includes a canonical commercial-closure concept:

```text
business_closed = true
when fulfillment_status in {delivered, completed}
and no authoritative Refund, Return, Payment,
or other domain condition explicitly keeps the commercial case open
```

The exact set of blocking conditions must be finalized from existing authoritative implementations during Phase 1. It is defined by domain policy, not inferred independently by dashboards or UI components.

Refund workflows may reference either successful terminal outcome where currently supported. Refund, Return, and payout details remain separate records and projections. The legacy `refund` Order status is inventoried and reconciled gradually; it must not be removed until historical rows and every caller have a safe mapping.

### 5.4 Mutations and corrections

- Named domain actions replace unrestricted status selection.
- Customer or Logistics delivery confirmation performs `shipped -> delivered` after required source-state and custody checks.
- Direct or pickup completion performs only a permitted `pending|processing -> completed` transition.
- Terminal outcomes cannot be changed through an ordinary status update.
- Controlled correction requires an authorized actor, reason, locking, source-state revalidation, and audit of previous and resulting state.
- Payment, Refund, Return, inventory, delivery, proof, and custody history cannot be erased.
- Financial or inventory errors use authoritative reversal or compensation.
- Duplicate submissions and stale transitions fail without partial side effects.

### 5.5 Presentation consistency

All Order labels, badges, filters, metrics, and frontend types consume the canonical set. The current missing `shipped` label and badge mapping is corrected. Dashboard definitions that need commercially closed Orders use the canonical projection rather than locally grouping statuses.

## 6. Employee Contract

### 6.1 Canonical account state

```text
active | inactive | suspended | terminated
```

- `active`: employed and operational.
- `inactive`: still employed and retained in company records but temporarily non-operational.
- `suspended`: temporarily restricted through a disciplinary or risk workflow.
- `terminated`: permanently offboarded.
- `on_leave`: derived from approved Leave covering the relevant date; not account state.
- `probation`: employment attribute of an otherwise active Employee; not account state.

The central semantic rule is:

> **Employee account state controls operational and access eligibility; Leave, probation, and compensation remain separate domain concerns.**

### 6.2 Linked-user access

```text
active       -> normal access subject to role and capabilities
inactive     -> authentication denied
suspended    -> authentication denied
terminated   -> authentication denied permanently
on_leave     -> no account-state change
probation    -> no account-state change by itself
```

Linked-user state synchronization handles every canonical Employee state deterministically. Authentication also rechecks current Employee policy so stale linked-user state cannot grant access.

### 6.3 Inactive operational effects

An inactive Employee:

- remains employed and retained in company records;
- cannot authenticate;
- cannot receive new operational assignments;
- is excluded from newly generated routine Payroll periods;
- retains historical records and existing business history;
- may be reactivated through an authorized action.

Inactive status does not silently delete or reassign existing work. Outstanding assignments follow their authoritative domain handoff or reassignment workflow.

Inactive status does not erase compensation already owed. Final, corrective, retroactive, or otherwise owed compensation remains payable when Payroll policy requires it.

Suspension does not automatically determine all compensation eligibility. Payroll remains authoritative for legally or operationally payable amounts.

### 6.4 Legacy reconciliation

Legacy spellings and pseudo-states such as `on_leave`, `on-leave`, and `probation` are inventoried across Employee rows, linked users, filters, requests, analytics, Payroll callers, and frontend types.

Reconciliation preserves Leave dates and probation/employment history while mapping account state to a canonical value. No value is normalized automatically when its intended meaning cannot be proven.

## 7. Purchase Order Contract

### 7.1 Canonical progression

```text
draft
  -> sent
  -> confirmed
  -> in_transit
  -> partially_received
  -> delivered
  -> completed
```

`partially_received` may repeat as receipts are posted. Full receiving transitions the Purchase Order to `delivered` through the authoritative receipt workflow.

### 7.2 State meanings and metrics

- `partially_received` remains active receiving work.
- `delivered` means receiving is complete and inventory and receipt effects have been posted.
- `delivered` does not count as active receiving work.
- `delivered` appears in an `Awaiting Closure` bucket.
- `completed` means the Purchase Order is administratively closed.
- `completed` is the normal administrative terminal state.

Canonical dashboard categories are:

```text
Active Receiving = sent | confirmed | in_transit | partially_received
Awaiting Closure = delivered
Completed        = completed
Cancelled        = cancelled
```

Drafts may remain a separate creation metric where useful; they are not active receiving.

### 7.3 Closure and correction

- `delivered -> completed` requires an explicit domain action or deterministic domain policy.
- The UI never infers completion merely because all quantities were received.
- Receipt correction and void behavior remains available only while authoritative rules permit it before closure.
- Once completed, receipt reversal or void behavior is no longer available through the normal receiving flow.
- Reopening a completed Purchase Order is not a generic status edit.
- Any justified correction uses a controlled audited reopen or compensating workflow with inventory, Expense, and receipt safeguards.

### 7.4 Cancellation

Normal cancellation is allowed only from the current domain-defined states:

```text
draft | sent | confirmed -> cancelled
```

`in_transit`, `partially_received`, `delivered`, and `completed` cannot use generic cancellation. Supplier failure, receiving correction, return, or financial issues after that boundary use their authoritative exception or compensating workflows.

Any future expansion of cancellable states requires an explicit domain rule that defines side effects, compensation, and audit behavior.

## 8. Logistics Responsibility Contract

### 8.1 Owner authority

Shop Owners may, where individually authorized:

- monitor shop-scoped Logistics activity;
- configure shop-level Logistics settings;
- assign and schedule riders;
- resolve owner-level delivery exceptions;
- approve or reject delivery proof;
- confirm physical receipt of returned parcels.

Each action remains separately capability or policy checked. Owner authentication does not imply every Logistics mutation.

### 8.2 Rider custody authority

Physical custody progression belongs to an actively assigned rider. Rider actions include:

- pickup confirmation;
- in-transit progression;
- delivery-attempt reporting;
- proof submission;
- other custody assertions tied to a shipment leg.

A Shop Owner may perform rider actions only while operating through both:

1. a valid linked rider identity; and
2. an active assignment for the relevant shipment or delivery leg.

A linked rider profile alone is insufficient.

### 8.3 Proof separation

Proof submission and proof approval are separate responsibilities:

```text
actively assigned rider -> submits evidence
authorized reviewer     -> approves or rejects evidence
```

The system must not collapse submission and approval merely because the actor is a Shop Owner. Existing maker/checker, proof integrity, and audit safeguards remain authoritative.

### 8.4 Authorization invariant

> Authentication through the `shop_owner` guard alone never grants unrestricted Logistics mutation authority.

Every mutation revalidates:

- shop tenancy;
- specific action capability or policy;
- source state;
- active assignment and rider identity where custody is asserted;
- maker/checker or proof-review separation where applicable.

Company-owner presentation emphasizes monitoring, review, and exceptions. Individual or owner-operated presentation may emphasize authorized dispatch controls. This is adaptive presentation, not a new stored business type or permission model.

## 9. Unified Sign-In Contract

### 9.1 Presentation

Customer, Staff, and Shop Owner authentication share one sign-in page and component with an explicit account-context selector:

```text
Sign in

[ Customer / Staff ] [ Shop Owner ]

Email
Password
Sign in
```

The selected trusted context determines the submission target:

```text
Customer / Staff -> POST /user/login
Shop Owner       -> POST /shop-owner/login
```

The selector is presentation and routing context. It does not authorize the actor.

### 9.2 Separate authentication contexts

- The `user` and `shop_owner` guards remain separate.
- Their handlers retain separate approval and status checks, two-factor flows, session regeneration, and post-login destinations.
- The system never tries the same credentials against multiple guards.
- The system does not infer account type from an email lookup.
- No cross-guard fallback, retry, or account suggestion occurs.

### 9.3 Failure privacy

Choosing the wrong context returns the same generic authentication failure as invalid credentials. A response must not disclose that the email belongs to another guard.

Account-specific status information may be returned only after the selected handler has sufficiently verified the claimant, such as after valid credentials. Merely finding an email is insufficient.

Authentication failures must not expose cross-guard or cross-tenant existence through message content. Rate limiting and existing two-factor protections remain in force.

## 10. Reconciliation and Migration

The migration order is:

```text
observe
  -> report-only reconciliation
  -> characterization tests
  -> canonical policies
  -> data reconciliation
  -> caller migration
  -> projection and UI alignment
  -> authorization/validation enforcement
  -> optional database constraints
```

### 10.1 Discovery and report-only pass

Before mutating data or tightening validation, inventory:

- persisted values and their counts;
- affected shops and records;
- controller, request, model, service, job, listener, notification, reporting, and UI callers;
- route and capability behavior;
- unknown or ambiguous legacy values;
- current valid side effects and negative paths.

Characterization tests lock current valid behavior before refactoring so intended corrections can be distinguished from regressions.

### 10.2 Reconciliation requirements

Reconciliation is idempotent and measurable. Every run reports:

```text
domain
shop scope
examined count
normalized count
unchanged count
unresolved count
run/correlation identifier
```

Re-running a completed normalization does not alter already canonical records.

Every unresolved row receives a tracked disposition:

- manual correction;
- accepted legacy exception;
- deferred migration with owner and reason;
- blocked rollout.

Report-only reconciliation precedes mutation. Constraint tightening never acts as the first discovery mechanism for incompatible rows.

### 10.3 Coexistence

Old and new callers may coexist temporarily, but they must produce equivalent:

- tenant decisions;
- transition decisions;
- validation outcomes;
- side effects;
- events and notifications;
- audit evidence.

Compatibility means equivalent authoritative behavior, not merely that both URLs return a response.

### 10.4 Enforcement and constraints

1. Migrate callers to canonical policies and shared execution services.
2. Prove route and UI coverage.
3. Tighten server authorization and source-state validation.
4. Verify reconciliation reports zero incompatible rows or explicitly accepted exceptions.
5. Add database constraints, if justified, in a later independent deployment step.

Do not tighten constraints in the same deployment step that first discovers legacy incompatibilities.

## 11. Logistics Authorization Migration

Logistics tightening is capability-led, not denial-led.

Before removing any broad owner-guard bypass, create an endpoint matrix containing:

```text
route and action
source state
tenant rule
owner capability/policy
employee capability
rider identity requirement
active assignment requirement
maker/checker rule
intended UI context
```

The intended explicit capability and UI control must exist before a previously legitimate owner operation becomes subject to the narrower check.

UI/server parity is an acceptance criterion:

- if the UI exposes an action, the server must authorize that intended actor context;
- if the server intentionally permits an owner action, that capability must be discoverable somewhere in the intended context;
- hidden navigation may de-emphasize an action but must not silently redefine authorization.

## 12. Deployment and Reversibility

- Policy and presentation changes should be independently reversible where practical.
- Enforcement is staged separately from discovery and normalization.
- Completed correct data normalization does not require rollback merely because presentation or policy deployment is reverted.
- Incorrect normalization uses a reviewed repair operation with evidence; it is not reversed blindly.
- Each bounded reconciliation batch is transactional where practical.
- A failed batch rolls back without affecting previously successful batches.
- Deployment gates can stop at unresolved data, caller mismatch, authorization mismatch, or UI/server parity failure without requiring the entire program to roll back.

## 13. Failure Handling

### Reconciliation

- Unknown or ambiguous values are not guessed.
- Unresolved rows remain measurable and receive an explicit disposition.
- Partial batch failure is logged with domain, shop scope, record context, and correlation identifier.

### Mutations

- Invalid and stale transitions fail without partial side effects.
- Mutation services lock or reload source state when concurrency affects correctness.
- Duplicate submissions follow existing idempotency safeguards.
- Unsafe historical changes use reversals or compensation.

### Projections

- Projection failure cannot mutate authoritative records.
- A projection cannot claim an action is available unless the underlying policy currently allows it.
- Mutation endpoints still re-evaluate policy even when the projection is fresh.

### Authentication and authorization

- Wrong sign-in context and invalid credentials return generic authentication failure.
- Authorization failures do not disclose cross-shop or cross-guard existence.
- Post-enforcement authorization-denial spikes are measurable by domain and shop scope.

## 14. Observability

At minimum, record or derive:

- reconciliation runs and counts by domain and shop;
- unresolved-row counts and dispositions;
- invalid transition counts by domain and source/target state;
- authorization denials by domain, action, actor context, and shop scope without logging secrets;
- Logistics denials by missing capability, rider identity, assignment, tenant, or source-state category;
- sign-in failures by selected context without cross-guard account disclosure;
- projection-versus-mutation mismatches discovered by tests or runtime instrumentation.

Alerting thresholds may be introduced during planning. A denial spike after enforcement blocks further rollout until explained.

## 15. Verification Strategy

### 15.1 Orders

- every valid and invalid transition edge;
- conditional `pending -> completed` direct/pickup flow;
- ordinary delivery `processing -> shipped -> delivered`;
- no normal `delivered <-> completed` transition;
- Refund eligibility from supported terminal outcomes;
- `business_closed` with and without blocking Refund, Return, Payment, or other domain conditions;
- controlled corrections, audit, locking, and immutable evidence;
- `shipped` labels, badges, filters, and frontend type coverage;
- legacy `refund` status reconciliation and compatibility.

### 15.2 Employees

- canonical and legacy value mapping;
- linked-user access for active, inactive, suspended, and terminated;
- Leave and probation do not change account state;
- inactive blocks new assignments without changing existing assignment history;
- inactive exclusion from routine future Payroll;
- final, corrective, retroactive, and owed compensation paths;
- suspension does not independently erase compensation;
- reactivation and permanent termination behavior.

### 15.3 Purchase Orders

- valid progression through partial and full receipt;
- repeated partial receipts;
- active receiving includes `partially_received`;
- delivered records appear as Awaiting Closure;
- completion requires explicit action or deterministic policy;
- completed receipts cannot use normal void behavior;
- normal cancellation only from draft, sent, or confirmed;
- late exceptions use domain workflows instead of generic cancellation;
- controlled reopen or compensation safeguards;
- dashboard category counts match canonical definitions.

### 15.4 Logistics

- every mutation in the endpoint capability matrix;
- owner, employee dispatcher, reviewer, assigned rider, unassigned rider, and linked-owner-rider combinations;
- tenant isolation for every action;
- linked rider identity plus active leg assignment for custody actions;
- proof submitter cannot gain approval solely by owner authentication;
- authorized reviewer approval and rejection;
- returned-parcel receipt confirmation;
- stale source-state and duplicate request rejection;
- UI/server parity in company-owner and individual/owner-operated presentation contexts.

### 15.5 Sign-in

- selector submits only to its chosen endpoint;
- Customer/Staff and Shop Owner success paths;
- wrong-context generic failure;
- no cross-guard fallback or probing;
- no account existence disclosure through response content;
- account-specific status only after sufficient claimant verification;
- separate approval/status checks, two-factor flow, session regeneration, remember behavior, and safe redirect;
- rate limiting and session fixation protection.

### 15.6 Reconciliation and coexistence

- report-only pass precedes mutation;
- idempotent reruns;
- exact examined, normalized, unchanged, and unresolved counts;
- unresolved disposition tracking;
- bounded-batch rollback;
- old and canonical callers produce equivalent decisions and side effects;
- authorization-denial metrics identify post-enforcement spikes by domain and shop.

## 16. Acceptance Criteria

Phase 1 is accepted when:

1. Canonical policies are side-effect-free shared decision boundaries.
2. Existing domain services remain shared execution boundaries.
3. No generic workflow engine or duplicate persisted owner state is introduced.
4. Order delivery and direct fulfillment use distinct alternate terminal outcomes.
5. Refund and commercial closure are projected without corrupting fulfillment state.
6. Employee account state is canonical and distinct from Leave, probation, and compensation.
7. Linked-user access and new-assignment eligibility match Employee policy.
8. Inactive Employees remain eligible for compensation owed by Payroll policy.
9. Purchase Order metrics distinguish Active Receiving, Awaiting Closure, Completed, and Cancelled.
10. Purchase Order completion and post-closure correction follow explicit domain actions.
11. Generic Purchase Order cancellation is limited to draft, sent, and confirmed.
12. Every Logistics mutation is tenant-scoped, source-state validated, and individually policy-authorized.
13. Custody actions require linked rider identity and active assignment.
14. Proof submission and approval remain separate responsibilities.
15. The Shop Owner guard alone grants no unrestricted Logistics mutation authority.
16. One shared sign-in presentation routes explicit contexts to separate handlers without cross-guard probing.
17. Wrong-context authentication fails generically without account enumeration.
18. Reconciliation is report-first, idempotent, measurable, and disposition-complete.
19. Old and canonical callers remain behaviorally equivalent during coexistence.
20. UI/server parity is proven for intended capabilities.
21. Constraint tightening occurs only after reconciliation and caller migration evidence.
22. Unresolved-row and authorization-denial spikes are observable by domain and shop scope.
23. Phase 1 does not change primary Shop Owner navigation.

## 17. Implementation Planning Gate

This document is a focused design specification, not an implementation plan.

After user review and approval, a separate TDD implementation plan must identify:

- exact policy, service, request, controller, model, migration, route, component, and test files;
- characterization tests to write before refactoring;
- reconciliation report and mutation commands;
- deployment and rollback checkpoints;
- narrow verification commands after each coherent change;
- sequential Standards, Spec, risk, reuse, and dead-code reviews;
- final evidence required before enforcement or constraint deployment.

Implementation must not begin until that plan is approved.
