# Shop Owner Phase 3 Action Center Master Design

**Date:** 2026-08-15

**Status:** Approved and frozen master design for Phase 3; Phase 3A and Phase 3B focused designs approved and frozen

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

For the current thesis deployment, the Phase 2 shell and Phase 3 Action Center use committed always-on application defaults rather than environment-controlled shop allowlists. The rollout architecture and explicit policy overrides remain documented for testing and defensive failure behavior, but no `.env` edit is required for local or deployed thesis environments. This does not broaden domain authorization or tenant access.

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
- coverage_source
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

Bucket and responsibility metadata is explicit and validated:

```text
needs_my_decision
├─ owner_action_required = true
└─ waiting_on = shop_owner

urgent_exceptions
├─ owner_action_required = false
└─ waiting_on = none

waiting_on_others
├─ owner_action_required = false
└─ waiting_on = legitimate actor/team key
```

Invalid combinations fail DTO construction. The coordinator never infers or corrects classification from source type, category, title, or other presentation data.

Stable identity is:

```text
source_type + source_id + category
```

This value is exposed as `attention_key`. Each adapter must emit unique attention keys and distinct counts. The coordinator performs defensive duplicate suppression but does not compensate for an adapter that intentionally conflates different source records.

Phase 3 uses two different source concepts:

```text
Coverage source
= owner-facing group, filter, and configuration family
= refunds | expenses | purchase_requests | compliance | logistics

Adapter key
= independently queried and health-reported implementation source
= order_refunds | repair_refunds | expenses | purchase_requests
  | compliance_documents | failed_refunds | unowned_logistics_failures
```

Coverage sources are bucket-scoped. For example, `refunds` can participate in `needs_my_decision` through approval adapters and later in `urgent_exceptions` through the independently enabled `failed_refunds` adapter without creating two owner-facing Refund source families.

Owner-facing counts aggregate by coverage source after distinct `attention_key` normalization. Adapter-level counts and health remain operational metadata and do not create separate owner-facing filters.

`coverage_source` is supplied explicitly by the adapter/DTO so the coordinator does not maintain source-type-to-product-family switches. `adapter_key`, `source_type`, `coverage_source`, and `category` remain distinct bounded identities.

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

Phase 3B evolves this shared DTO and coordinator rather than introducing a parallel exception model. Before any Phase 3B adapter is enabled, every Phase 3A adapter must explicitly provide its existing `needs_my_decision`, `shop_owner`, `owner_action_required = true`, and coverage metadata. Hidden Phase 3A constructor defaults are removed, and characterization must prove that existing Phase 3A inclusion, ordering, counts, links, and behavior remain unchanged.

## 9. Primary Buckets and Staging

An item belongs to exactly one primary attention bucket at a time. Classification follows this canonical responsibility-first order:

```text
Does the Shop Owner currently need to decide?
├─ Yes → Needs My Decision
└─ No
   ↓
Is there a deterministic named actor or team responsible for the next step?
├─ Yes → Waiting on Others
└─ No
   ↓
Is the active condition materially important enough to require owner awareness?
├─ Yes → Urgent Exceptions
└─ No → not an Action Center item
```

A single authoritative condition may produce at most one primary Action Center item for the same attention concern. Owner decision takes precedence; otherwise deterministic next-party responsibility maps to `Waiting on Others`; only owner-awareness conditions without a deterministic next actor map to `Urgent Exceptions`.

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

Phase 3B introduces active material conditions that require owner awareness, do not currently require an owner decision, and have no legitimate deterministic named actor or team responsible for the next step. Materiality may arise from urgency, customer impact, financial exposure, compliance impact, or operational age.

`Urgent Exceptions` is not a fallback for missing or broken responsibility data. An adapter must prove that the material condition is active, owner awareness is justified, and no legitimate deterministic next actor exists. If responsibility should exist but cannot be derived because the domain workflow is ambiguous, that ambiguity is a domain/design gap and does not independently qualify the record as an Action Center exception.

Selected Phase 3B coverage is:

```text
Urgent Exceptions
├─ Compliance Documents
├─ Failed Refunds
└─ Unowned Logistics Failures
```

Each participating adapter must prove both source-specific materiality and the legitimate absence of deterministic responsibility. An exception adapter is not ready unless it can distinguish `no legitimate actor owns the next step` from `the system cannot determine responsibility`.

Declared Phase 3B coverage is readiness-driven, not roadmap-driven. A selected source does not become visible merely because it is planned for the phase. The single Phase 3B implementation plan uses readiness-gated stages:

```text
Stage 1
└─ Compliance Documents

Stage 2
└─ Failed Refunds
   enabled independently after the Refund recovery lifecycle passes readiness

Stage 3
└─ Unowned Logistics Failures
   enabled independently after the Logistics responsibility projection passes readiness
```

Blocked sources do not appear as filters, zero counts, placeholders, degraded adapters, or temporarily unavailable sources. Their selected-but-blocked status remains in engineering documentation and readiness evidence. When only Compliance participates in `Urgent Exceptions`, the UI may omit the redundant source filter entirely.

The first Phase 3B rollout stage is complete when the Compliance adapter is production-ready and safely enabled. The single Phase 3B implementation plan is complete only when Compliance Documents, Failed Refunds, and Unowned Logistics Failures have each independently passed readiness and been enabled. Production coverage may therefore expand one adapter at a time without waiting for another blocked source.

Phase 3B must preserve these responsibility distinctions:

```text
Refund waiting for owner approval
→ Needs My Decision

Refund recovery owned by Finance or another deterministic actor
→ Waiting on Others

Materially failed Refund recovery with no legitimate recovery owner
→ Urgent Exceptions
```

Compliance expiry thresholds use authoritative document reminder or escalation windows. Failed Refunds and Logistics failures use their own authoritative failure, recovery, and escalation rules. Phase 3B does not invent one universal materiality threshold across domains.

Phase 3B materiality is source-owned:

```text
Domain policy
├─ qualification threshold
├─ escalation condition
├─ retry or failure exhaustion
└─ relevant deadline or age rules
        ↓
Adapter
├─ inclusion predicate
├─ priority_tier
├─ materiality_tier
├─ urgency_at
└─ actionable_since
        ↓
OwnerActionCenterService
└─ deterministic cross-source ordering
```

Qualification and ranking are separate. A domain first determines whether an active condition is sufficiently material to qualify. Only then may its adapter map the authoritative severity into the shared bounded priority and materiality vocabulary. The coordinator never promotes a non-qualifying record merely because its date is old or its amount is large.

The Action Center configuration must not duplicate document expiry windows, Refund recovery limits, Logistics retry limits, or other source-owned thresholds. If an authoritative materiality boundary does not exist, the owning domain must define and test it before the adapter can pass readiness. Because Phase 3B remains request-time live-read, an authoritative domain-policy change is reflected on the next request without Action Center migration or synchronization.

The Compliance Document adapter uses the authoritative compliance lifecycle classification in the configured shop/business timezone:

```text
outside authoritative material window
→ no Action Center item

renewal window (currently 8–30 days)
→ Urgent Exceptions
→ normal priority / medium materiality

urgent window (currently 1–7 days)
→ Urgent Exceptions
→ high priority / high materiality

expires today or expired
→ Urgent Exceptions
→ critical priority / critical materiality
```

The adapter consumes domain policy results rather than independently encoding the current `30 / 7 / 0` boundaries. `urgency_at` is the authoritative expiry date and `actionable_since` is when the document entered the authoritative material window. A pending renewal with deterministic reviewer responsibility belongs to `Waiting on Others`; an explicit owner-decision state takes precedence as `Needs My Decision`; only a qualifying current document without either responsibility belongs to `Urgent Exceptions`. The owner-safe destination is `/shop-owner/settings/policies-compliance`.

Replacement, supersession, conversion to non-expiring metadata, corrected expiry outside the material window, or establishment of deterministic responsibility removes the exception on the next read. Missing expiry metadata, conflicting current versions, unverified metadata, invalid renewal chains, and equivalent lifecycle ambiguity are adapter/domain-health failures and never exception items.

Failed Refunds are selected Phase 3B coverage but remain blocked until the Refund domain supplies an authoritative recovery lifecycle. A terminal `failed` execution alone cannot prove that a failure remains unresolved, identify current recovery responsibility, or explain how it was eventually resolved.

The minimum domain capability is:

```text
Refund execution fails
→ historical failure remains preserved
→ recovery unresolved
   ├─ active legitimate Finance/payment recovery owner
   │  → Waiting on Others
   ├─ explicit owner decision required
   │  → Needs My Decision
   ├─ no legitimate recovery owner
   │  → Urgent Exceptions
   └─ successful retry/replacement or controlled manual recovery
      → recovery resolved
      → no Action Center item
```

Action Center classification derives from Refund recovery state; it never creates or owns that state. Acknowledgment, dismissal, hiding, or marking a card reviewed cannot substitute for domain resolution. An unresolved failure remains unresolved regardless of age, although age may increase its normalized urgency.

The Refund domain must preserve original failure evidence while recording enough audited information to prove active legitimate recovery assignment, controlled resolution actor/time/outcome/reason, and retry or replacement linkage. Exact persistence names are deferred to the single Phase 3B implementation plan. Assignment must be active and legitimate; a stale actor identifier does not establish deterministic responsibility.

Failed Refund adapter readiness requires authoritative unresolved state, deterministic recovery ownership, controlled and idempotent resolution, retry/replacement traceability, tested 3A/3B/3C classification, and exhaustive exit behavior.

Unowned Logistics Failures are selected Phase 3B coverage but remain blocked until Logistics supplies an authoritative, side-effect-free, bulk-safe responsibility projection. Existing overdue events are historical notification evidence and cannot independently establish a current exception.

The projection must provide the domain equivalents of:

```text
owner_action_required
deterministic_responsible_party
recovery_path_active
recovery_path_exhausted
material_exception_active
```

Classification is:

```text
owner_action_required
→ Needs My Decision

active legitimate responsible rider, dispatcher, or recovery team
→ Waiting on Others

no owner action
+ no deterministic responsible party
+ authoritative recovery path exhausted
+ current material exception active
→ Urgent Exceptions

stale, contradictory, or indeterminate responsibility
→ adapter/domain-health failure
```

Responsibility must reflect current legitimate ownership rather than an old assignment record. Exhaustion and materiality derive from authoritative Logistics retry, return, resolution, and escalation policy; adapters never invent attempt limits or age thresholds. Reassignment, retry, return completion, cancellation, delivery, owner-action escalation, or another terminal resolution reclassifies or removes the item on the next read.

Logistics adapter readiness requires deterministic current responsibility, recovery-path state, exhaustion, materiality, and owner-action precedence; detectable ambiguous states; and tested reassignment, retry, return, cancellation, delivery, and terminal exits.

Inventory and procurement aging, repair aging or parts delays, and payroll rejection remain deferred until Phase 3C responsibility mapping or a source-specific legitimate unowned escalation state is proven.

Its focused design must define materiality and exit rules per source. Phase 3A does not pre-empt those decisions.

### Phase 3C — Waiting on Others

Phase 3C introduces records where another actor owns the next step. An adapter may participate only when `waiting_on`, next responsibility, entry, and exit can be derived deterministically from authoritative state.

`waiting_on` is presentation metadata, not another domain status or persisted delegation permission.

The implemented Phase 3C launch coverage is bounded to:

- pending compliance renewal review owned by `super_admin`;
- failed order and repair refund recovery owned by `finance` or `payment_recovery`;
- active logistics recovery owned by `rider` or `dispatcher`.

Waiting is independently filtered by `compliance`, `refunds`, and `logistics`. Its Home summary and full queue reuse the shared Phase 3 coordinator and ordering contract, while owner decisions and unowned material exceptions remain in their existing buckets. Remaining approval families and final ERP/navigation retirement are later phase work.

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

`/shop-owner/home` retains the existing Business Summary and adds separate bounded summaries for each active supported attention bucket:

- `Needs My Decision` has its own count and top 3–5 decisions;
- `Urgent Exceptions` has its own count and top 3–5 exceptions once Phase 3B is enabled;
- `Waiting on Others` has its own count and top three responsibility items once Phase 3C is enabled;
- each summary uses the shared inclusion and bucket-specific ordering contract;
- each summary links to `/shop-owner/action-center` with its bucket selected;
- secondary supported-source disclosure and partial or unavailable states remain bucket-aware.

Home never merges decisions and exceptions into one generic attention list.

### Full queue

`/shop-owner/action-center` is the single canonical full-queue destination:

- bucket tabs show active supported buckets and their bucket-specific counts;
- `Needs My Decision` is the default healthy bucket, even when its current count is zero;
- automatic fallback to another bucket is permitted only when the default bucket is unsupported or unavailable, not merely empty;
- the selected bucket determines its eligible sources, validated source filters, deterministic ordering, count, and pagination;
- changing buckets resets pagination to the selected bucket's deterministic valid starting state, normally page 1;
- decisions and exceptions are never combined into one paginated queue;
- accessible pagination is independent per bucket;
- manual refresh;
- coverage and source-health disclosure;
- owner-safe domain links.

Conceptual bounded URLs are:

```text
/shop-owner/action-center?bucket=needs_my_decision&page=2
/shop-owner/action-center?bucket=urgent_exceptions&source=compliance&page=2
/shop-owner/action-center?bucket=waiting_on_others&source=refunds&page=2
```

Source filters appear only when the source participates in the selected bucket's enabled supported coverage. For the initial staged coverage:

```text
Needs My Decision
├─ Refunds
├─ Expenses
└─ Purchase Requests

Urgent Exceptions
├─ Compliance Documents
├─ Failed Refunds       once adapter-ready
└─ Logistics Failures  once adapter-ready

Waiting on Others
├─ Compliance
├─ Refunds
└─ Logistics
```

Home and the Action Center use identical inclusion, identity, counting, filtering, and ordering contracts. Under the same fixed source state they produce consistent results. Runtime values need not remain identical across separate requests when authoritative state changes between requests.

Unsupported buckets and sources are not rendered as zero-value functionality. They may be named only in secondary coverage disclosure as not yet included.

## 13. Interaction and Visual Contract

Phase 3 reuses the completed canonical shell and existing Shop Owner dashboard design system:

- canonical adaptive sidebar and header;
- existing blue/neutral semantic colors;
- existing rounded cards, borders, spacing, typography, dark/light themes, and Lucide icon language;
- existing responsive layout conventions.

The Action Center must not look like a separate generic administration product.

The standard Phase 3 visual language is a compact operational queue. It is optimized for mixed-domain scanning at low or moderate SME volumes without becoming either an oversized card gallery or a dense ERP table.

The full-page hierarchy is:

```text
compact page header + last-refreshed status + utility Refresh
bucket tabs with count badges
selected bucket title and concise purpose
bucket-specific source filters
one light queue container
structured attention rows separated by dividers
conventional pagination
secondary coverage disclosure
```

Bucket tabs are the dominant page control. Source filters are visually secondary and appear only for participating sources in the selected bucket. Selected controls use the existing SoleSpace blue; priority colors are restrained semantic accents rather than the page's dominant color.

Every attention row follows one consistent hierarchy:

```text
source badge
dominant title
amount/exposure + urgency/age context
textual priority or materiality indicator
owner-safe Open workflow link
```

Rows use dividers and restrained hover/focus treatment rather than individual oversized floating cards or repeated shadows. Priority is never communicated by color alone. Workflow navigation uses native anchor or Inertia `Link` semantics. `Open workflow` remains an explicit control; the whole row may be linked only when it is one semantic link with no competing interactive controls.

Refresh is utility-level UI rather than a primary call to action. Pagination remains conventional and exposes a stable queue position; infinite scroll is not used. A healthy empty `Needs My Decision` bucket remains selected and usable even when `Urgent Exceptions` contains items.

Partial-source failures use a calm inline notice above the queue, identify affected sources, and mark counts as partial. They do not replace the queue with an oversized alarming panel when healthy sources can still render.

On small screens, bucket controls remain fully usable, metadata stacks vertically, links and buttons retain at least 44-pixel touch targets, and the page introduces no horizontal scrolling or compressed desktop table. Keyboard order follows visual order, focus indicators remain visible, dynamic count/status changes use appropriate live-region semantics, and reduced-motion preferences are respected.

Phase 3 deliberately avoids decorative gradients, glass effects, excessive shadows, decorative metrics, duplicated mutation controls, and unsupported-source zero states. This compact operational-queue contract carries forward to Phase 3C so all attention buckets feel like one coherent product.

Home discovers, the Action Center reviews, and domain pages execute. Initial cards contain no generic Approve or Reject buttons.

Urgent Exceptions have no Action Center-owned dismiss, acknowledge, hide, snooze, or resolve state. An exception remains visible while its authoritative domain predicate remains true and disappears or changes buckets only when authoritative domain state changes. Opening a workflow, viewing details, returning to the Action Center, or marking a related notification as read does not affect inclusion.

Notification acknowledgement and Action Center inclusion are separate concerns: notifications represent event awareness, while the Action Center projects current authoritative state. If an exception becomes too noisy, the owning domain's qualification or escalation policy must be corrected rather than adding a per-owner Action Center suppression mechanism.

The Action Center has no independent exception-resolution lifecycle. Only authoritative domain transitions, current responsibility changes, or authoritative resolution may remove or reclassify an Urgent Exception.

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

The Phase 3A implementation plan covers [`2026-08-15-shop-owner-phase-3a-owner-decisions-design.md`](./2026-08-15-shop-owner-phase-3a-owner-decisions-design.md). One Phase 3B implementation plan proceeds from [`2026-08-16-shop-owner-phase-3b-material-exceptions-design.md`](./2026-08-16-shop-owner-phase-3b-material-exceptions-design.md) and contains readiness-gated stages for Compliance Documents, Failed Refunds, and Unowned Logistics Failures. Phase 3C proceeds from [`2026-08-22-shop-owner-phase-3c-waiting-on-others-design.md`](./2026-08-22-shop-owner-phase-3c-waiting-on-others-design.md) and its implementation plan; the initial Compliance, Order Refund, Repair Refund, and Logistics responsibility adapters are now readiness-tested in the bounded scope documented by the rollout guide.
