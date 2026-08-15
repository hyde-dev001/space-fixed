# Shop Owner Phase 3 Action Center Master Design

**Date:** 2026-08-15

**Status:** Approved and frozen master design for Phase 3; Phase 3A focused design approved and frozen

## 1. Goal

Make owner-required decisions, material business exceptions, and staff-owned follow-up discoverable from one bounded Shop Owner control surface without creating a second workflow system.

Phase 3 builds on the completed Phase 2 canonical adaptive shell. It introduces one shared live-read Action Center framework and onboards attention sources in three increasingly demanding stages:

```text
Phase 3A — Owner Decisions
Phase 3B — Material Exceptions
Phase 3C — Waiting on Others
```

The stages share one route family, normalized read contract, coordinator, ordering policy, Home summary, full queue, rollout boundary, and failure model. They are not separate Action Centers.

## 2. Relationship to Existing Designs

This design specializes Phase 3 of [`2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md`](./2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md).

It preserves:

- Phase 1 domain-state and responsibility contracts in [`2026-08-15-shop-owner-phase-1-state-responsibility-correctness-design.md`](./2026-08-15-shop-owner-phase-1-state-responsibility-correctness-design.md);
- the completed Phase 2 canonical shell, stable canonical owner routes, adaptive navigation, rollout policy, and ERP compatibility boundary;
- authoritative Refund, Expense, Procurement, Payroll, HR, Repair, Logistics, payment, compliance, and notification workflows;
- server-side capability, tenant, source-state, maker/checker, financial, and audit controls.

Phase 3 changes owner discovery and review presentation. It does not change who may perform a domain action or how the action executes.

## 3. Problem Statement

The platform already has many owner approval and exception pages, but the owner must know which module to inspect before discovering that attention is required. Existing notifications are event-oriented and audit records are evidentiary; neither is a reliable live inventory of current owner attention.

The Action Center must answer:

1. What currently requires my decision?
2. What material condition should I know about?
3. When another actor owns the next step, who is responsible?
4. What is the exposure, urgency, and age?
5. Where is the authoritative workflow?

It must answer those questions without persisting duplicate workflow state or absorbing specialized mutation behavior.

## 4. Program Boundaries

### In scope

- one shared Action Center framework;
- request-time domain adapters;
- one normalized in-memory attention-item contract;
- one in-memory cross-domain read coordinator;
- Home summaries and one full queue;
- stable source identity, duplicate suppression, ordering, filtering, and bounded pagination;
- staged source coverage and accurate coverage disclosure;
- partial-source failure isolation;
- nested controlled rollout inside the Phase 2 canonical-shell cohort;
- tenant, authorization, privacy, accessibility, performance, and observability requirements.

### Out of scope

- an `action_center_items` table or persisted workflow projection;
- a generic workflow, transition, task, or approval engine;
- replacing domain services, state machines, mutation routes, or audit trails;
- generic inline Approve or Reject actions;
- WebSockets, server-sent events, background synchronization, or aggressive polling;
- snapshot synchronization between separate Home and Action Center requests;
- treating notifications or audit records as the Action Center source of truth;
- implementing Phase 3B or Phase 3C before their focused designs are approved.

## 5. Core Architecture

```text
Authoritative domain records
        ↓
Domain-specific attention adapters
        ↓
OwnerActionCenterService / read coordinator
        ↓
same request-time live-read model
     ↙                         ↘
Home summary             Full Action Center
```

The architecture follows five boundaries:

```text
Domain workflow = business truth and execution
Adapter         = domain-specific attention projection
Coordinator     = cross-domain presentation composition
Home            = bounded summary
Action Center   = systematic discovery and review
```

An individual adapter failure is isolated. Complete Phase 3 fallback occurs only when the Phase 3 rollout decision, coordinator, or shared contract composition cannot safely produce a valid presentation.

## 6. Adapter Contract

Each adapter owns only its domain-specific read concerns:

- tenant-scoped qualifying query;
- deterministic entry and exit rules;
- deterministic owner responsibility;
- stable source identity;
- safe title and concise summary inputs;
- normalized priority, materiality, urgency, and aging inputs;
- owner-safe authoritative domain link;
- source availability reporting.

Adapters are side-effect free. They do not mutate source records, mark attention items resolved, send notifications, or write an Action Center lifecycle.

Every adapter must satisfy this readiness gate:

```text
deterministic entry
+ deterministic responsibility
+ stable source identity
+ defined priority/materiality inputs
+ bounded tenant-scoped query
+ owner-safe destination
+ destination authorization recheck
+ predicate-based exit contract
+ defined cancellation/supersession behavior
+ deterministic duplicate suppression
= adapter ready
```

## 7. OwnerAttentionItem Contract

`OwnerAttentionItem` is an immutable, validated, in-memory read DTO produced per request. It is not an Eloquent model, database record, workflow state, or independently resolvable entity.

The shared contract contains only presentation-safe normalized data:

```text
OwnerAttentionItem
- attention_key
- source_type
- source_id
- category
- primary_bucket
- module
- title
- concise_summary
- priority_tier
- materiality_tier
- comparable_monetary_exposure
- urgency_at
- actionable_since
- waiting_on
- owner_action_required
- destination_url
```

Stable identity is:

```text
source_type + source_id + category
```

This value is exposed as `attention_key`. Each adapter must emit unique attention keys and distinct counts. The coordinator performs defensive duplicate suppression but does not compensate for an adapter that intentionally conflates different source records.

Phase 3 uses two different source concepts:

```text
Coverage source
= owner-facing group, filter, and configuration family
= refunds | expenses | purchase_requests

Adapter key
= independently queried and health-reported implementation source
= order_refunds | repair_refunds | expenses | purchase_requests
```

Owner-facing counts aggregate by coverage source after distinct `attention_key` normalization. Adapter-level counts and health remain operational metadata and do not create separate owner-facing filters.

Owner-safe authoritative domain links are acceptable when Phase 2 did not establish a canonical nested record route. Phase 3 does not become a broad deep-route migration project.

## 8. Coordinator Responsibilities

The coordinator owns:

- the enabled-adapter registry;
- DTO validation;
- adapter failure isolation;
- cross-source merge and defensive deduplication;
- bounded source and bucket filters;
- common deterministic ordering;
- bounded cross-source pagination;
- Home counts and top-item summaries;
- supported, unavailable, and not-yet-supported source disclosure;
- partial and complete degradation metadata.

It does not authorize mutations, infer domain transitions, or persist attention state.

## 9. Primary Buckets and Staging

An item belongs to exactly one primary attention bucket at a time:

1. `Needs My Decision` when owner action is required;
2. otherwise `Urgent Exceptions` when a material condition requires owner awareness;
3. otherwise `Waiting on Others` when another deterministic actor owns the next step.

### Phase 3A — Owner Decisions

Initial production coverage:

- Refunds;
- Expenses;
- Purchase Requests.

Subsequent Phase 3A adapters are independently onboarded after their readiness gates pass:

- Pricing;
- Payroll and Salary;
- Suspensions;
- Repair Rejections;
- High-value Repairs.

### Phase 3B — Material Exceptions

Phase 3B introduces exceptions that may not require an owner decision but have sufficient urgency, customer impact, financial exposure, compliance impact, or operational age to require owner awareness.

Its focused design must define materiality and exit rules per source. Phase 3A does not pre-empt those decisions.

### Phase 3C — Waiting on Others

Phase 3C introduces records where another actor owns the next step. An adapter may participate only when `waiting_on`, next responsibility, entry, and exit can be derived deterministically from authoritative state.

`waiting_on` is presentation metadata, not another domain status or persisted delegation permission.

## 10. Ordering Contract

Cross-source ordering uses normalized inputs in this sequence:

```text
priority tier
→ materiality tier
→ comparable monetary exposure
→ urgency_at ascending, null last
→ actionable_since oldest first
→ source_type
→ source_id
```

Raw amounts in different currencies are never compared. Monetary exposure participates only when expressed in the authoritative shop currency. Otherwise the adapter supplies a domain-defined `materiality_tier`. Phase 3 performs no foreign-exchange conversion.

Each adapter returns one deterministic `urgency_at` input. Due dates, expiry dates, escalation deadlines, and equivalent domain signals are normalized by the adapter rather than interpreted differently by Home and the full queue.

## 11. Bounded Cross-Source Pagination

For requested page `P` and per-page size `N`, each enabled adapter returns at most `P × N` already-qualified, source-filtered, and source-ordered candidates, subject to a fixed maximum candidate ceiling. The coordinator merges, validates, deduplicates, globally orders, and slices the requested page.

Rules:

- membership filters are applied inside adapters before candidate limits;
- page and per-page inputs are bounded and validated;
- requests beyond maximum supported depth are rejected or normalized by one documented server rule;
- refreshed requests that exceed the new last page normalize to the nearest valid page, normally the last available page;
- Home uses bounded count/top-item operations and does not materialize the full queue merely to display 3–5 records.

The algorithm must be tested with globally interleaved sources, deterministic ties, and a source dominating multiple leading pages.

## 12. Surfaces and Routes

### Home

`/shop-owner/home` retains the existing Business Summary and adds a bounded Owner Actions section:

- `Needs My Decision` with a separate count badge;
- top 3–5 items using the shared ordering contract;
- `View all` to `/shop-owner/action-center`;
- secondary supported-source disclosure;
- partial or unavailable state when applicable.

### Full queue

`/shop-owner/action-center` is the single canonical full-queue destination:

- active supported bucket navigation;
- validated source filters;
- deterministic ordering;
- accessible pagination;
- manual refresh;
- coverage and source-health disclosure;
- owner-safe domain links.

Home and the Action Center use identical inclusion, identity, counting, filtering, and ordering contracts. Under the same fixed source state they produce consistent results. Runtime values need not remain identical across separate requests when authoritative state changes between requests.

Unsupported buckets and sources are not rendered as zero-value functionality. They may be named only in secondary coverage disclosure as not yet included.

## 13. Interaction and Visual Contract

Phase 3 reuses the completed canonical shell and existing Shop Owner dashboard design system:

- canonical adaptive sidebar and header;
- existing blue/neutral semantic colors;
- existing rounded cards, borders, spacing, typography, dark/light themes, and Lucide icon language;
- existing responsive layout conventions.

The Action Center must not look like a separate generic administration product.

Every attention row follows one hierarchy:

```text
decision title
source badge + amount/exposure
priority indicator + aging/due context
owner-safe Open workflow link
```

Priority is never communicated by color alone. Workflow navigation uses native anchor or Inertia `Link` semantics. The whole row may be linked only when it contains no competing interactive controls.

Home discovers, the Action Center reviews, and domain pages execute. Initial cards contain no generic Approve or Reject buttons.

## 14. Freshness and Concurrency

Phase 3 uses request-time live reads:

```text
open Home or Action Center
→ query enabled adapters
→ compose current projection

complete domain decision
→ navigate or manually refresh
→ query authoritative state again
→ resolved item no longer qualifies
```

There is no optimistic removal. An item may become stale between render and navigation; the destination rechecks current source state and authorization. Immediate push synchronization is not part of the product contract.

## 15. Rollout Model

Phase 3 rollout is independently controlled inside the successfully selected Phase 2 canonical-shell cohort:

```text
Phase 2 canonical shell selected?
└─ Phase 3 global flag enabled?
   └─ same stable shop_id allowlisted?
      └─ at least one launch adapter enabled?
         └─ Phase 3 presentation
```

Phase 2 and Phase 3 use exactly the same stable shop identity contract. Email, owner account ID, browser identity, and mutable profile fields are not rollout identities.

Rollout reasons are separate from adapter health:

```text
rollout_reason
- canonical_shell_not_selected
- action_center_global_disabled
- shop_not_allowlisted
- shop_allowlisted

adapter_status[]
- enabled
- disabled
- healthy
- failed

degradation_status
- none
- partial
- no_enabled_adapters
- all_adapters_unavailable
- action_center_composition_failed
```

`no_enabled_adapters` safely returns the Phase 2 Home placeholders. Before initial Phase 3A production enablement, Refunds, Expenses, and Purchase Requests must each pass the readiness gate.

## 16. Failure Handling

### Individual adapter failure

- healthy source results remain usable;
- the failed source contributes no data;
- affected source is marked temporarily unavailable;
- counts and totals are explicitly marked partial;
- a security-sensitive failure is reported and never converted into an apparently successful empty result.

### All enabled adapters fail

The UI presents `Action Center currently unavailable`, not `0 decisions`.

### Common rollout or composition failure

- Home retains the Phase 2 canonical shell and informational placeholders;
- `/shop-owner/action-center` redirects safely to `/shop-owner/home`;
- Home does not automatically redirect back, preventing loops;
- domain behavior and authorization remain unchanged.

The rollout flag selects presentation, never route existence or capability availability.

## 17. Security and Privacy

Every adapter must:

- use the authoritative tenant relationship;
- evaluate owner responsibility with side-effect-free, bulk-safe policy logic;
- avoid unrestricted record enumeration;
- expose only decision-safe summaries;
- produce validated local destinations;
- apply bounded source, page, and per-page inputs.

Action Center availability never implies mutation authority. Domain destinations re-evaluate authorization, tenancy, source state, locking, maker/checker rules, validation, audit, and side effects.

Operational logs contain no titles, customer names, order numbers, amounts, refund details, workflow reasons, credentials, or payment data.

## 18. Observability

Bounded structured telemetry records only:

- stable `shop_id`;
- rollout reason;
- enabled, healthy, and failed adapter keys;
- degradation status;
- adapter duration and result count;
- validated source-filter key;
- bounded page and per-page values;
- correlation identifier.

High-frequency timing and count signals use the application's normal metrics or structured logging mechanism rather than verbose audit-style records.

## 19. Verification Strategy

Shared framework verification covers:

- DTO validation and stable identity;
- tenant isolation;
- source entry predicates and known transition examples;
- owner-responsibility boundaries;
- duplicate suppression;
- ordering, currency, urgency, and tie-breaker behavior;
- adversarial interleaved pagination;
- partial, all-failed, no-enabled, and common-failure behavior;
- Home/full-queue contract consistency under fixed source state;
- stale-item destination rechecks;
- bounded queries and no N+1 behavior;
- rollout identity and flag behavior;
- privacy-safe telemetry;
- semantic links, keyboard behavior, contrast, focus, responsive layout, and source-health announcements.

Each future adapter must independently pass the readiness gate before production enablement.

## 20. Program Acceptance Criteria

The shared Phase 3 program is complete only after Phase 3A, Phase 3B, and Phase 3C have each received an approved focused design and their accepted scope has been implemented and verified. Completing the first implementation plan completes Phase 3A only; it does not complete the full Phase 3 program.

Phase 3 is complete when:

1. One shared Action Center framework supports all three approved stages.
2. Home and `/shop-owner/action-center` consume the same live-read contract.
3. Every surfaced item has stable identity and exactly one primary bucket.
4. Counts reflect currently available enabled sources and never represent failure as zero work.
5. Owners navigate to authoritative domain workflows for execution.
6. Domain state, authorization, and audit remain authoritative.
7. Source onboarding is controlled by explicit readiness evidence.
8. Individual adapter failures are isolated and common failure preserves the Phase 2 shell.
9. Cross-source queries and pagination remain bounded and deterministic.
10. Unsupported and temporarily unavailable sources are represented accurately.
11. No persistence, generic workflow engine, second authorization system, or duplicate mutation surface is introduced.

## 21. Focused Specifications

This document is the shared Phase 3 program contract. Implementation proceeds only from approved focused specifications:

```text
Phase 3 master
├── Phase 3A — Owner Decisions
├── Phase 3B — Material Exceptions
└── Phase 3C — Waiting on Others
```

The first implementation plan covers [`2026-08-15-shop-owner-phase-3a-owner-decisions-design.md`](./2026-08-15-shop-owner-phase-3a-owner-decisions-design.md) only. Phase 3B and Phase 3C receive their own focused designs and plans before source onboarding begins.
