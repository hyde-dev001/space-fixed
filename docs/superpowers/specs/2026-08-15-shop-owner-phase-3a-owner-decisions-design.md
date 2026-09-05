# Shop Owner Phase 3A Owner Decisions Design

**Date:** 2026-08-15

**Status:** Approved and frozen focused design; ready for Phase 3A implementation planning

## 1. Goal

Introduce the first production Action Center coverage for owner-required decisions from Refunds, Expenses, and Purchase Requests.

Phase 3A proves the shared live-read architecture while preserving each domain's current approval page, validation, authorization, confirmation, reason capture, locking, side effects, notification behavior, and audit trail.

The interaction contract is:

```text
Action Center item
→ open owner-safe authoritative domain detail
→ existing domain workflow handles the decision
→ source state changes
→ next Action Center read no longer includes the item
```

## 2. Relationship to Existing Designs

This focused design implements the first stage of [`2026-08-15-shop-owner-phase-3-action-center-master-design.md`](./2026-08-15-shop-owner-phase-3-action-center-master-design.md).

It preserves:

- the Shop Owner master program contract;
- Phase 1 state and responsibility correctness;
- the completed Phase 2 canonical shell, Home, routes, rollout, adaptive presentation, and ERP compatibility link;
- current Refund, Expense, Approval, and Purchase Request models and mutation services;
- existing approval pages and owner-safe detail routes;
- existing capability, tenancy, source-state, financial, maker/checker, and audit safeguards.

When this design conflicts with a generic Action Center statement in the earlier master program, this focused contract controls Phase 3A.

## 3. Scope

### Initial production sources

```text
Needs My Decision
├── Refunds
├── Expenses
└── Purchase Requests
```

Both Order Refund and Repair/POS Refund records participate under the Refund product label while retaining distinct source types and source-specific eligibility.

### In scope

- a Phase 3 rollout policy nested inside Phase 2 selection;
- application-controlled adapter enablement;
- `OwnerAttentionItem` DTO validation;
- three domain adapter families;
- an in-memory read coordinator;
- shared ordering, filtering, deduplication, counting, and pagination;
- a bounded Home Owner Actions summary;
- `/shop-owner/action-center`;
- discovery-only owner-safe links;
- partial and complete degradation presentation;
- characterization, adapter, coordinator, route, frontend, security, performance, and rollout tests.

### Out of scope

- Phase 3B exceptions;
- Phase 3C waiting-on-staff records;
- Pricing, Payroll, Salary, Suspension, Repair Rejection, or High-value Repair adapters;
- inline decision mutations;
- new approval states or domain services;
- approval-volume simplification, which belongs to Phase 4;
- retirement of approval pages, compatibility URLs, or the owner ERP workspace;
- persisted Action Center records or background synchronization.

## 4. Architecture Boundary

```text
authoritative record
→ domain-specific attention adapter
→ validated OwnerAttentionItem DTO
→ OwnerActionCenterService
→ Home summary / full queue
→ owner-safe domain destination
→ existing mutation service/action
```

The adapter is a projection boundary. The domain workflow remains the execution boundary.

Policies used for inclusion are side-effect free. Projections may expose `owner_action_required`, but mutation endpoints always re-evaluate policy, tenancy, and source state.

## 5. Shared Read Contract

Phase 3A uses the master `OwnerAttentionItem` contract with these fixed values:

```text
primary_bucket = needs_my_decision
owner_action_required = true
waiting_on = shop_owner
```

Required fields are:

```text
attention_key
source_type
source_id
category
primary_bucket
module
title
concise_summary
priority_tier
materiality_tier
comparable_monetary_exposure
urgency_at
actionable_since
waiting_on
owner_action_required
destination_url
```

The DTO is immutable request data. It has no persistence, lifecycle, read/unread state, or independent resolution state.

Identity is `source_type + source_id + category` and is exposed as `attention_key`. Counts use distinct qualifying attention keys.

Phase 3A distinguishes owner-facing coverage from internal adapter execution:

```text
Coverage source
= owner-facing group, filter, and configuration family
= refunds | expenses | purchase_requests

Adapter key
= independently queried and health-reported implementation source
= order_refunds | repair_refunds | expenses | purchase_requests
```

Owner-facing counts aggregate by coverage source after distinct `attention_key` normalization. Adapter-level counts remain operational health data and do not create separate Refund filters.

## 6. Refund Adapter Family

Refunds use two distinct source types:

```text
order_refund
repair_refund
```

Both use:

```text
category = refund_approval
module = finance
primary_bucket = needs_my_decision
```

They must not be collapsed into one source identity because Order Refund and Repair/POS Refund records have different models, eligibility rules, and authoritative destinations.

### Order Refund inclusion

The adapter follows the same owner eligibility contract as the current Shop Owner Refund approval workflow. A record qualifies only when all current authoritative conditions are satisfied, including the following currently identified rules or their authoritative equivalents:

- the Refund belongs to an order belonging to the current shop through the established tenant relationship;
- the Refund uses the owner-approval request flow;
- the owner decision status is pending;
- the Refund remains in an active requested or pending-approval state;
- company and individual Finance-stage requirements match the existing workflow;
- `ShopOwnerApprovalPolicyService::requiresOwnerApprovalForRefund` or its authoritative equivalent says owner approval is required.

The adapter must reuse or extract a side-effect-free eligibility boundary rather than reproduce a looser status-only query.

### Repair Refund inclusion

The Repair/POS Refund adapter uses the authoritative repair-refund tenant relationship and requires the following currently identified rules or their authoritative equivalents:

- repair module/source classification;
- active requested state;
- pending owner responsibility;
- Finance-stage and threshold rules equivalent to the current `RepairPosRefundService` decision path;
- no terminal, cancelled, superseded, paid-out, or otherwise non-actionable source state.

The linked rider, order, or repair presentation must not broaden eligibility. The service-level owner decision rule controls.

### Refund exit

An item exists if and only if all current inclusion and owner-responsibility predicates remain true. It exits as soon as any required predicate becomes false. Known examples include approval, rejection, cancellation, supersession, changed responsibility, terminal failure handling, and source invalidation; characterization tests must enumerate the transitions supported by the current domain.

Refund fulfillment and payout remain separate domain concerns. Phase 3A does not infer a Refund decision from Order fulfillment status.

### Refund destination

The item links to the existing authorized owner Refund detail or approval page. Order and Repair Refund links may differ. Opening the destination rechecks current policy, tenancy, and source state.

## 7. Expense Adapter

An Expense qualifies only when the current authoritative owner-approval conditions are satisfied. The following fields and relationships are currently identified and must be verified during characterization, using their authoritative equivalents where the implementation differs:

- `Expense.shop_id` matches the current shop identity through the existing tenant relationship;
- the Expense is a manual owner-reviewable expense rather than a procurement-receipt-generated expense;
- the Expense remains submitted and actionable;
- a linked pending Approval exists for the Shop Owner stage;
- the current authoritative approval policy says the Shop Owner may decide it now.

Procurement receipt expenses remain excluded when the current workflow routes them through Finance rather than owner approval.

Any extracted `canApprove`, eligibility scope, or equivalent policy must be:

- side-effect free;
- bulk-query safe;
- suitable for bounded collection retrieval;
- equivalent to the existing mutation authorization contract;
- free from per-row relationship or policy N+1 behavior.

### Expense exit

The item exists if and only if all current Expense inclusion and owner-responsibility predicates remain true. It exits as soon as any required predicate becomes false. Known examples include an Approval being approved, rejected, cancelled, superseded, no longer pending, assigned to another role, or the Expense leaving an actionable submitted state; characterization tests must enumerate the transitions supported by the current domain.

### Expense destination

The item links to the existing authorized Shop Owner Expense approval/detail workflow. Phase 3A does not duplicate confirmation, approval reason, rejection reason, accounting validation, or audit behavior.

## 8. Purchase Request Adapter

A Purchase Request qualifies only when the authoritative owner-decision predicates remain true. The following field and state names are currently identified and must be verified during characterization, using their authoritative equivalents where the implementation differs:

- its authoritative `shop_owner_id` tenant relationship matches the current shop;
- its state is `pending_shop_owner`;
- the current authoritative responsibility and policy still require this owner to decide;
- the request has not been cancelled, superseded, rejected, approved into the Finance stage, or otherwise invalidated.

`pending_shop_owner` alone is insufficient if another authoritative condition makes the request non-actionable.

### Purchase Request exit

The item exists if and only if all current Purchase Request inclusion and owner-responsibility predicates remain true. Approval normally moves the request to its Finance stage. Rejection, cancellation, supersession, or another authoritative responsibility change also removes it from Phase 3A. Characterization tests must enumerate the transitions supported by the current domain.

### Purchase Request destination

The item links to the existing authorized owner Purchase Request approval/detail workflow. Phase 3A does not change Procurement transitions, evidence, Finance review, supplier handling, or audit.

## 9. Stale Items and Concurrent Decisions

Staleness is expected in a live-read system:

```text
Action Center renders item
→ another authorized actor or process changes source state
→ owner opens destination
→ domain workflow shows current state and rejects stale action if necessary
→ next Action Center read removes or changes the item
```

The Action Center does not lock source records, optimistically resolve cards, or claim that render-time availability guarantees mutation authority.

## 10. Coordinator and Registry

The application-controlled coverage configuration initially contains:

```text
refunds
expenses
purchase_requests
```

The `refunds` coverage flag enables two distinct internal adapters:

```text
order_refunds
repair_refunds
```

They retain separate source types, tenant queries, eligibility rules, failure isolation, timing, counts, and health reporting. Failure of one Refund adapter does not require the healthy Refund adapter to contribute zero data. The UI may group both under the `Refunds` product filter while degradation disclosure identifies the unavailable Refund source precisely enough to avoid implying complete coverage.

The coordinator:

- invokes enabled adapters with current shop context and validated filters;
- validates every returned DTO;
- records healthy and failed source keys;
- defensively deduplicates stable attention keys;
- globally orders candidates;
- calculates distinct counts;
- slices the requested page;
- returns source coverage and degradation metadata;
- isolates an individual adapter read/composition failure.

The coordinator does not query domain tables directly.

## 11. Filtering and Pagination

Phase 3A supports one bucket and these bounded source filters:

```text
all
refunds
expenses
purchase_requests
```

Unsupported values receive stable validation behavior. Filters are applied by participating adapters before candidate limits.

For page `P` and size `N`, each participating adapter returns no more than `P × N` ordered candidates up to the configured maximum. The coordinator merges and slices globally.

Verification must include:

- globally interleaved Refund, Expense, and Purchase Request priority across pages;
- deterministic boundaries and tie-breakers;
- one source dominating the first pages;
- duplicates within or across adapter results;
- source filtering before candidate limits;
- page normalization after live state reduces the final page;
- rejection or normalization beyond maximum supported depth.

## 12. Ordering and Exposure

The shared order is:

```text
priority_tier
→ materiality_tier
→ comparable_monetary_exposure
→ urgency_at ascending, null last
→ actionable_since oldest first
→ source_type
→ source_id
```

Adapters map their domain signals into the shared inputs. They do not pass ambiguous collections of dates for the coordinator to reinterpret.

Amounts participate only when expressed in the shop's authoritative currency. Phase 3A performs no currency conversion. Non-comparable records use materiality tiers without raw cross-currency amount ordering.

## 13. Home Design

`/shop-owner/home` retains the existing Shop Owner dashboard and Business Summary. The Phase 2 placeholder area becomes a bounded Owner Actions section when Phase 3 is selected.

Home shows:

- `Needs My Decision` label;
- separate count badge;
- top 3–5 shared-order items;
- source badge, exposure, textual priority, and age/due context;
- native `Open workflow` links;
- `View all` to `/shop-owner/action-center`;
- visually secondary coverage disclosure.

Home uses the coordinator's summary operation. It does not introduce dashboard-specific adapter queries or an alternate ordering algorithm.

## 14. Full Action Center Design

`/shop-owner/action-center` shows:

- page title and staged-coverage language;
- `Needs My Decision` as the only Phase 3A bucket;
- count from currently available enabled sources;
- Refund, Expense, and Purchase Request filters only when those sources are enabled and supported;
- compact mixed-domain decision rows;
- manual Refresh through a normal Inertia visit/reload;
- accessible pagination;
- supported, temporarily unavailable, and not-yet-supported disclosure.

Refresh preserves valid filter and page state. If the current page becomes invalid, the server normalizes to the nearest valid page using the documented last-page rule.

## 15. Card and Link Semantics

Every item follows:

```text
decision title
source badge + amount/exposure
textual priority + aging/due context
Open workflow
```

Priority is not color-only. `Open workflow` is a real anchor or Inertia `Link`. A linked full row is permitted only when no nested competing controls exist.

There are no inline Approve or Reject buttons in Phase 3A.

## 16. Availability and Count Semantics

Counts represent qualifying distinct items from currently available enabled coverage sources after normalization by `attention_key`. Adapter-level counts remain operational health metadata and do not create separate owner-facing totals or filters.

### Healthy sources, no items

The UI may state that no decisions from currently supported sources require action.

### Partial adapter failure

```text
Needs My Decision  7
7 actions from currently available sources
Expenses temporarily unavailable
```

The count is marked partial and cannot look globally complete.

### All enabled adapters fail

The UI states that the Action Center is currently unavailable. It never reports zero decisions.

### No adapters enabled

This is a configuration state, not an empty queue. It degrades to the Phase 2 Home placeholders and records `no_enabled_adapters`.

### Common composition failure

- Home renders the Phase 2 placeholders inside the canonical shell;
- `/shop-owner/action-center` redirects to `/shop-owner/home`;
- no automatic redirect returns the owner to the failed route.

## 17. Rollout Configuration

Phase 3A uses a narrow application configuration such as:

```text
owner_action_center.php

enabled
allowlisted_shop_ids
adapters
  refunds
  expenses
  purchase_requests
```

Eligibility requires:

1. successful Phase 2 canonical-shell selection;
2. Phase 3 global flag enabled;
3. the same stable `shop_id` present in the Phase 3 allowlist;
4. valid adapter configuration.

Rollout membership and adapter enablement are presentation concerns. They never grant a capability or make an underlying domain page accessible.

Before initial production enablement, all three declared coverage families must pass the readiness gate, including independent readiness for both the Order Refund and Repair Refund adapters. Runtime failure isolation handles unexpected post-launch degradation; it is not a substitute for launch readiness.

## 18. Route Contract

`/shop-owner/action-center` is registered independently of rollout flags.

- selected cohort with valid composition: render the full queue;
- owner outside the Phase 3 cohort: redirect safely to `/shop-owner/home`;
- common composition failure: redirect safely to `/shop-owner/home`;
- domain record links: use existing owner-safe authoritative routes, canonical where one already exists.

Existing approval URLs remain compatibility and execution entry points. Phase 3A does not retire or redirect them.

## 19. Security Contract

Every adapter query is tenant-scoped through its authoritative relationship. Names referenced in this design describe the currently identified implementation and remain provisional until Phase 3A characterization locks the exact model, column, relationship, policy, and route details. Generic `shop_id` wording must not replace source-specific tenant semantics.

The Action Center:

- never probes other tenants;
- never accepts an arbitrary destination URL from a source record;
- never treats card presence as authorization;
- never suppresses an authorization or tenant defect into a successful empty response;
- never logs sensitive business-record content;
- validates source filters, page, and per-page bounds.

Every destination repeats normal authorization, source-state, and mutation checks.

## 20. Observability

Use separate telemetry dimensions:

```text
rollout_reason
adapter_status[]
degradation_status
```

Bounded operational data includes only:

- stable `shop_id`;
- enabled, healthy, and failed adapter keys;
- adapter duration and result count;
- validated source filter;
- bounded page and per-page values;
- correlation identifier.

Do not log titles, customer names, source references, amounts, Refund facts, Expense reasons, Purchase Request descriptions, or approval reasons.

## 21. Performance Contract

Each adapter performs a bounded attention-specific query. It must not load a full domain collection and filter it in PHP.

Implementation must establish before/after evidence for:

- per-adapter query count;
- total Home and full-queue query count;
- adapter duration;
- absence of per-item relationship or policy N+1 queries;
- bounded candidate retrieval;
- response behavior when one source dominates.

New indexes require measured query evidence. Phase 3A does not add speculative infrastructure.

## 22. Accessibility and Responsive Behavior

Implementation must preserve the canonical shell and provide:

- semantic headings and landmarks;
- native links and buttons;
- visible keyboard focus;
- logical focus order after route changes;
- accessible Refresh and pagination names/states;
- non-color priority text;
- sufficient light/dark contrast;
- an appropriate announcement for refreshed results and partial availability;
- no horizontal page overflow at representative mobile widths;
- no hidden content behind sticky shell elements;
- reduced-motion-compatible transitions.

## 23. Implementation Sequence

```text
1. Characterize current Refund, Expense, and Purchase Request owner decisions
2. Add Phase 3 rollout/configuration contracts
3. Add DTO, adapter interface, registry, and coordinator
4. Implement and independently verify all three launch adapters
5. Add coordinator ordering, filtering, pagination, and failure isolation
6. Replace Phase 2 Home placeholders for the Phase 3 cohort
7. Add /shop-owner/action-center
8. Add structured health and rollout observability
9. Run controlled internal and allowlisted rollout verification
```

No domain mutation path is migrated into the Action Center during this sequence.

## 24. Verification Strategy

### Characterization tests

- current valid and invalid owner entry states for every source;
- company versus individual Refund staging where applicable;
- Expense approval-role sequencing and procurement-expense exclusion;
- Purchase Request owner-to-Finance transition;
- current authorized destinations and stale-state handling.

### Adapter tests

- exact tenant scoping;
- deterministic inclusion and exclusion;
- distinct identity and counts;
- predicate-based exit behavior, with known approval, rejection, cancellation, supersession, responsibility-change, and terminal transitions enumerated;
- safe summaries and destinations;
- bounded, bulk-safe queries;
- security-sensitive failure contributes no data.

### Coordinator tests

- DTO validation;
- duplicate suppression;
- normalized ordering and tie-breakers;
- currency and materiality behavior;
- source filters before limits;
- adversarial cross-source pagination;
- partial, all-failed, and no-enabled behavior;
- isolated Order Refund versus Repair Refund failure and health disclosure;
- Home and full queue under the same fixed state;
- valid page normalization after refresh.

### Route and rollout tests

- nested Phase 2/Phase 3 selection;
- identical stable `shop_id` contract;
- route availability independent of flags;
- safe Home redirect and no loop;
- unchanged underlying domain authorization.

### Frontend and browser tests

- existing Business Summary remains intact;
- top 3–5 Home items and `View all`;
- filters, Refresh, pagination, and coverage disclosure;
- normal, empty, partial, all-unavailable, and loading states;
- semantic links, keyboard navigation, focus, contrast, dark/light mode, and responsive layout.

### Quality evidence

- narrow relevant Laravel tests first;
- relevant frontend tests;
- `pnpm run build` when implementation changes frontend behavior;
- browser verification at representative desktop and mobile widths;
- route inspection;
- query-count and duration evidence;
- `git diff --check`.

## 25. Rollback

Disabling the Phase 3 flag or removing a shop from the allowlist restores the complete Phase 2 Home placeholders and canonical shell.

Rollback does not require:

- reverting Phase 1 normalization;
- removing `/shop-owner/action-center`;
- reverting domain data;
- changing authorization;
- removing existing approval pages;
- reversing any Action Center data migration, because none exists.

## 26. Acceptance Criteria

Phase 3A is complete when:

1. Order Refund, Repair Refund, Expense, and Purchase Request adapters independently pass the readiness gate.
2. Allowlisted canonical-shell owners see the bounded Owner Actions Home summary.
3. `/shop-owner/action-center` provides the complete supported prioritized queue.
4. Home and the full queue use identical contracts under fixed source state.
5. Counts and source health are accurate during normal and partial operation.
6. Each item has stable identity and exactly one `Needs My Decision` bucket.
7. Every item opens an owner-safe authoritative domain workflow.
8. Domain destinations recheck authorization and source state.
9. Resolved, rejected, cancelled, superseded, or reassigned records disappear on the next read.
10. Cross-source pagination is bounded and deterministic under adversarial data.
11. The UI accurately distinguishes empty, unsupported, partial, all-unavailable, and no-enabled states.
12. Existing approval pages and mutation behavior remain authoritative and functional.
13. Phase 3 failures preserve the Phase 2 canonical shell and do not alter domain behavior.
14. No Action Center persistence, generic mutation surface, second authorization system, or workflow duplication is introduced.

Completing these criteria completes Phase 3A only. The Phase 3 program remains open until Phase 3B and Phase 3C have approved focused designs and their accepted scope is implemented and verified.

> **Completion invariant:** Phase 3A is complete when Order Refund, Repair Refund, Expense, and Purchase Request adapters have independently passed their readiness gates and allowlisted canonical-shell owners can discover and systematically review their current owner-required decisions through the shared live-read model, while existing domain workflows remain the sole execution surfaces and unsupported or temporarily unavailable domains are represented accurately.
