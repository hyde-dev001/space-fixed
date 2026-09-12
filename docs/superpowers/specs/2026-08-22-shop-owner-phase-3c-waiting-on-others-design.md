# Shop Owner Phase 3C Waiting on Others Design

**Date:** 2026-08-22

**Status:** Approved and frozen focused design

## 1. Goal

Add `Waiting on Others` as the third responsibility bucket in the existing Shop Owner Action Center so owners can monitor materially important work whose next step belongs to another legitimate actor or team.

Phase 3C does not create a staff task list, a delegation system, or another workflow engine. It projects current authoritative responsibility into the same live-read Action Center introduced in Phase 3A and extended in Phase 3B.

```text
Authoritative domain state
        ↓
Existing domain responsibility policy or projection
        ↓
Dedicated Phase 3C attention adapter
        ↓
Shared OwnerActionCenterService
        ↓
Home summary + full Action Center queue
```

The owner-facing meaning is:

> `Waiting on Others` contains owner-relevant, materially important concerns where the Shop Owner does not currently need to decide and a legitimate actor or team deterministically owns the next step.

## 2. Relationship to Existing Designs

This specification specializes Phase 3C of [`2026-08-15-shop-owner-phase-3-action-center-master-design.md`](./2026-08-15-shop-owner-phase-3-action-center-master-design.md).

It preserves:

- the Phase 3A `Needs My Decision` adapters and behavior;
- the Phase 3B `Urgent Exceptions` adapters and behavior;
- the responsibility-first, mutually exclusive bucket classification;
- the existing `OwnerAttentionItem` in-memory read contract;
- the shared `OwnerActionCenterService`, route, Home summary, full queue, ordering, filtering, pagination, and failure model;
- authoritative Compliance, Refund, and Logistics domain state;
- tenant, capability, source-state, audit, and mutation authorization boundaries;
- the committed always-on thesis deployment defaults, with no `.env` changes required.

Phase 3C does not add the remaining owner approval families. Pricing, Payroll/Salary, Suspensions, Repair Rejections, High-value Repairs, and other verified owner approvals remain part of Phase 4 approval simplification and coverage completion.

The approved later-program direction remains:

```text
Phase 3
→ establish the complete Action Center responsibility model

Phase 4
→ complete owner approval coverage in Needs My Decision
→ apply owner-controlled approval settings where policy permits
→ make Action Center the canonical owner approval inbox
→ remove covered approval-list pages from primary navigation only after parity

Phase 5
→ verify capability coverage
→ finalize canonical module landing/navigation behavior
→ remove the ERP compatibility fallback when no longer required
→ retire the ERP Workspace as a separate owner-facing portal
```

## 3. Scope

### Initial production coverage

```text
Waiting on Others
├─ Compliance renewal review
│  └─ Compliance Review / Super Admin
├─ Failed Order and Repair refund recovery
│  └─ Finance / Payment Recovery
└─ Active Logistics recovery
   └─ Rider / Dispatcher
```

These sources are selected because Phase 3B established or characterized authoritative responsibility foundations for them.

### In scope

- one `waiting_on_others` bucket in the existing Action Center;
- dedicated bucket-specific adapters for the initial sources;
- deterministic role/team responsibility labels;
- source-owned qualification, materiality, urgency, and exit rules;
- responsibility-driven reclassification between 3A, 3B, and 3C;
- separate Home summary and full-queue tab;
- source filters for Compliance, Refunds, and Logistics;
- request-time live reads, manual refresh, partial-source failure isolation, and accurate coverage disclosure;
- the minimum Refund domain evidence required to represent responsibility age accurately.

### Out of scope

- every ordinary employee or delegated task;
- Procurement, Repair aging/parts, Payroll correction, or other future responsibility sources without an approved focused onboarding contract;
- additional owner approval families;
- Action Center-owned `Remind`, `Reassign`, `Escalate`, `Dismiss`, `Acknowledge`, `Snooze`, or `Resolve` state;
- inline approval or mutation actions;
- personal employee names as primary responsibility labels;
- a persisted Action Center task table;
- a generic responsibility, delegation, workflow, or state-machine engine;
- WebSockets, server-sent events, aggressive polling, or background synchronization.

## 4. Canonical Classification

Classification remains responsibility-first and mutually exclusive:

```text
Does the Shop Owner currently need to decide?
├─ Yes → Needs My Decision
└─ No
   ↓
Does a legitimate actor or team deterministically own the next step?
├─ Yes → Waiting on Others
└─ No
   ↓
Is an active condition materially important enough for owner awareness?
├─ Yes → Urgent Exceptions
└─ No → no Action Center item
```

A concern may produce at most one primary Action Center item at a time. Aging or increasing urgency does not independently change its bucket.

```text
Same legitimate responsible party + increased age/urgency
→ remains Waiting on Others
→ priority may increase according to source-owned policy

Responsibility becomes genuinely unowned + condition remains material
→ Urgent Exceptions

Owner decision becomes required
→ Needs My Decision

Condition resolves or stops being owner-relevant
→ no Action Center item
```

Indeterminate, stale, or contradictory responsibility is a domain/read-health failure. It is not a valid `Waiting on Others` or `Urgent Exceptions` classification.

## 5. Shared Contract Evolution

### OwnerAttentionItem

Phase 3C evolves the existing DTO vocabulary without introducing another DTO or persistence model.

Every Phase 3C item must provide:

```text
primary_bucket = waiting_on_others
owner_action_required = false
waiting_on = legitimate bounded role/team key
```

The initial bounded keys and owner-facing labels are:

```text
super_admin      → Compliance Review
finance          → Finance
payment_recovery → Payment Recovery
rider            → Rider
dispatcher       → Dispatcher
```

The internal key is the stable contract. The owner-facing label may use clearer product language without changing the authoritative responsibility identity.

`waiting_on` is presentation metadata. It is not a new domain status, assignment record, permission, capability, or delegation grant.

### Personal identity

Phase 3C uses role/team labels as the primary responsibility information. It does not expose personal names in the initial queue.

If a future domain can prove a valid active individual assignment and the owner is authorized to view it, that identity may later appear as secondary detail. It must not replace the stable role/team responsibility contract.

### Source and adapter identities

The registry adds bucket-specific definitions:

```text
waiting_on_others
├─ compliance
│  └─ pending_compliance_renewals
├─ refunds
│  ├─ waiting_order_refund_recovery
│  └─ waiting_repair_refund_recovery
└─ logistics
   └─ active_logistics_recovery
```

Stable attention identities remain:

```text
source_type + source_id + category
```

Initial categories are:

```text
compliance_document:<pending-renewal-id>:renewal_review_waiting
order_refund:<refund-id>:refund_recovery_waiting
repair_refund:<refund-id>:refund_recovery_waiting
logistics_failure:<leg-id>:logistics_recovery_waiting
```

The adapter predicate is the primary duplicate-prevention mechanism. The coordinator retains defensive deduplication but does not repair intentionally overlapping adapters.

### Shared coordinator

The existing coordinator continues to own:

- enabled-adapter lookup by bucket and coverage source;
- DTO validation;
- merge, ordering, and defensive deduplication;
- count and bounded candidate composition;
- bucket-specific filtering and pagination;
- Home top-item summaries;
- source health and degradation metadata.

It does not infer responsibility, determine materiality, authorize mutation, or persist workflow state.

## 6. Owner-Relevance and Materiality Boundary

`Waiting on Others` is not a complete inventory of delegated work. A record qualifies only when the owning domain determines that the concern is important enough for owner monitoring.

```text
Routine staff assignment
→ domain workflow only
→ no Action Center item

Material owner-relevant concern with legitimate other-party responsibility
→ Waiting on Others
```

Qualification and ranking remain separate:

```text
Domain policy
→ determines whether the concern qualifies

Adapter
→ maps the qualifying concern into shared priority/materiality/urgency fields

Coordinator
→ orders already-qualified items
```

The Action Center must not invent generic aging or materiality thresholds. Source policy changes are reflected on the next request-time read.

When a concern reclassifies between 3B and 3C without another material domain change, its normalized priority, materiality, exposure, and urgency should remain equivalent. Responsibility changes the bucket; it does not silently reduce the business significance.

## 7. Compliance Renewal Waiting Adapter

### Entry

A Compliance item qualifies when:

```text
current approved, reviewer-verified, dated document
+ authoritative lifecycle policy places it inside the material expiry window
+ exactly one valid pending renewal successor exists
+ the pending successor has deterministic Super Admin/compliance-review responsibility
+ no Shop Owner decision is currently required
→ Waiting on Others
```

Pending submissions outside the authoritative material window remain on the Compliance workflow page and do not enter the Action Center.

### Identity and normalized fields

- `source_type`: `compliance_document`;
- `source_id`: pending renewal successor ID;
- `category`: `renewal_review_waiting`;
- `coverage_source`: `compliance`;
- `waiting_on`: `super_admin`;
- user-facing responsibility: `Compliance Review`;
- `urgency_at`: authoritative expiration date of the current predecessor;
- `actionable_since`: the later of the material-window opening boundary and the pending renewal submission time;
- destination: the owner-safe canonical Policies and Compliance workflow.

Priority and materiality reuse the authoritative Compliance window mapping:

```text
renewal window (currently 8–30 days)
→ normal / medium

urgent window (currently 1–7 days)
→ high / high

expires today or expired
→ critical / critical
```

The adapter consumes the domain classification rather than independently encoding `30 / 7 / 0` thresholds.

### Exit and reclassification

```text
Renewal approved and current document replaced
→ no item

Renewal rejected, withdrawn, or otherwise ceases to be pending
+ predecessor remains materially expiring
+ no legitimate next actor exists
→ eligible for Urgent Exceptions

Future explicit owner decision becomes required
→ eligible for Needs My Decision

Predecessor is corrected outside the material window or becomes non-expiring
→ no item
```

Multiple pending successors, invalid cross-tenant chains, missing reviewer responsibility, contradictory current versions, or malformed lifecycle metadata are adapter/domain-health failures and contribute no misleading item.

## 8. Failed Refund Recovery Waiting Adapters

Phase 3C covers Order Refund and Repair/POS Refund recovery independently while retaining the shared owner-facing `refunds` coverage family.

### Entry

A failed Refund qualifies when:

```text
execution status = failed
+ recovery_status = in_progress
+ recovery_responsible_party = finance or payment_recovery
+ responsibility is current and legitimate
+ no Shop Owner approval/decision is pending
+ recovery remains unresolved and materially owner-relevant
→ Waiting on Others
```

Order and Repair adapters retain separate queries, source identities, health states, and domain policies.

### Responsibility-assignment evidence

The current recovery lifecycle records party and attempt state but does not preserve a dedicated assignment timestamp. Generic `updated_at` is not authoritative because retries and unrelated updates can change it.

Before enabling the Refund waiting adapters, the Refund domain must preserve the assignment boundary through `recovery_assigned_at` or equivalent repository-native audited evidence.

The domain contract is:

- claiming recovery records the responsible party and assignment time atomically;
- changing Finance ↔ Payment Recovery records the new responsibility boundary;
- retries do not rewrite the original/current assignment boundary unless responsibility changes;
- resolution and supersession preserve historical assignment evidence;
- legacy in-progress assigned rows are reported and reconciled before strict adapter enablement;
- rows without safely recoverable assignment evidence remain excluded and health-reported rather than using `updated_at` as a guess.

This evidence belongs to the Refund recovery lifecycle. It is not Action Center state.

### Identity and normalized fields

Order Refund:

```text
source_type = order_refund
category = refund_recovery_waiting
adapter_key = waiting_order_refund_recovery
```

Repair Refund:

```text
source_type = repair_refund
category = refund_recovery_waiting
adapter_key = waiting_repair_refund_recovery
```

Shared presentation:

- `coverage_source`: `refunds`;
- `waiting_on`: `finance` or `payment_recovery`;
- user-facing responsibility: `Finance` or `Payment Recovery`;
- monetary exposure: authoritative Refund amount when comparable in shop currency;
- `actionable_since`: authoritative current responsibility-assignment boundary;
- `urgency_at`, priority, and materiality: source-owned Refund recovery policy;
- destination: existing owner-safe Order or Repair Refund workflow.

### Exit and reclassification

```text
Recovery resolves or is superseded
→ no item

Responsibility changes Finance ↔ Payment Recovery
→ remains Waiting on Others
→ responsibility label and assignment boundary update

Responsibility becomes none
+ failure remains material and unresolved
→ Urgent Exceptions

Shop Owner approval/decision becomes pending
→ Needs My Decision

Responsibility is stale, invalid, contradictory, or indeterminate
→ adapter/domain-health failure
```

The original `failed_at` and `failure_reason` remain immutable historical execution evidence. Action Center classification never resolves or acknowledges the recovery.

## 9. Active Logistics Recovery Waiting Adapter

### Entry

A Logistics concern qualifies when the existing responsibility projection is healthy and reports:

```text
material_exception_active = true
+ owner_action_required = false
+ deterministic_responsible_party = rider or dispatcher
+ recovery_path_active = true
+ recovery_path_exhausted = false
→ Waiting on Others
```

The adapter reuses `LogisticsResponsibilityProjection`; it does not duplicate rider validity, dispatcher responsibility, batch, attempt, return, or exhaustion rules.

### Current legitimate responsibility

Responsibility must refer to a current valid assignment or recovery path. These do not qualify:

- stale or inactive rider assignments;
- completed or invalid batches;
- superseded recovery legs;
- invalid linked rider identities;
- historical overdue events without current qualifying state;
- contradictory active assignments.

### Identity and normalized fields

- `source_type`: `logistics_failure`;
- `source_id`: authoritative shipment-leg ID;
- `category`: `logistics_recovery_waiting`;
- `adapter_key`: `active_logistics_recovery`;
- `coverage_source`: `logistics`;
- `waiting_on`: `rider` or `dispatcher`;
- user-facing responsibility: `Rider` or `Dispatcher`;
- `actionable_since`: the later authoritative boundary at which both the material concern and current responsibility became active;
- `urgency_at`, priority, and materiality: Logistics-owned failure/recovery policy;
- destination: owner-safe Logistics shipment/recovery workflow.

The existing assignment `assigned_at`, leg `failed_at`, and domain recovery state provide the initial time inputs. The adapter must not use a stale assignment merely because its timestamp exists.

### Exit and reclassification

```text
Owner confirmation or decision becomes required
→ Needs My Decision

No responsible party remains
+ recovery is exhausted
+ material condition remains active
→ Urgent Exceptions

Leg is reassigned to another legitimate Rider/Dispatcher
→ remains Waiting on Others
→ responsibility metadata updates

Delivered, returned, cancelled, or otherwise terminally resolved
→ no item

Projection becomes unhealthy or indeterminate
→ adapter/domain-health failure
```

## 10. Home and Full-Queue Interaction

### Home

`/shop-owner/home` displays three independent summaries from the same coordinator:

```text
Needs My Decision
→ bucket count + top 3 items + View all

Urgent Exceptions
→ bucket count + top 3 items + View all

Waiting on Others
→ bucket count + top 3 items + View all
```

To prevent crowding:

- Home renders at most three rows per bucket;
- buckets never merge into a generic attention list;
- a healthy empty Waiting bucket uses a compact one-line state rather than empty cards;
- full review, filtering, and pagination stay on the dedicated page.

### Full Action Center

```text
[ Needs My Decision  N ]
[ Urgent Exceptions  N ]
[ Waiting on Others  N ]
```

`Needs My Decision` remains the default healthy tab, even when its count is zero. The page does not automatically switch based on counts.

The Waiting bucket uses source filters only:

```text
[ All ] [ Compliance ] [ Refunds ] [ Logistics ]
```

Responsible role/team remains row metadata. Phase 3C does not add Finance, Payment Recovery, Rider, Dispatcher, or Compliance Review filters. Such filters may be considered later only if measured queue volume justifies them.

Conceptual URLs are:

```text
/shop-owner/action-center?bucket=waiting_on_others&page=2
/shop-owner/action-center?bucket=waiting_on_others&source=refunds&page=2
```

Each bucket owns its source filter, count, ordering, page, and pagination. Changing buckets resets to page 1. Refresh preserves valid state; an invalid refreshed page normalizes to the nearest valid page according to the existing deterministic server rule.

## 11. Operational-Queue Visual Contract

Phase 3C carries forward the approved compact operational-queue language.

Every Waiting row follows this hierarchy:

```text
source badge
dominant concern title
exposure, deadline, and/or aging context
Waiting on: role/team
textual priority/materiality indicator
explicit Open workflow link
```

Example:

```text
[Refund]

Order refund recovery
₱12,500 · Waiting for 2 days

Waiting on: Finance                         High priority
                                             Open workflow →
```

Rules:

- rows use a light shared container and dividers rather than oversized floating cards;
- bucket tabs dominate source filters;
- selected controls use existing SoleSpace blue;
- priority is textual and not communicated by color alone;
- `Open workflow` is a semantic anchor or Inertia link;
- Refresh is a utility action;
- pagination is conventional; infinite scroll is not used;
- mobile metadata stacks vertically without horizontal page scrolling;
- touch targets remain at least 44 pixels;
- keyboard order follows visual order and focus remains visible;
- dynamic counts and source-health states use appropriate live-region semantics;
- dark/light themes, 200% zoom, and reduced-motion preferences remain supported.

The Action Center contains no inline Approve, Reject, Remind, Reassign, Escalate, Dismiss, Acknowledge, Snooze, or Resolve controls.

## 12. Freshness and Concurrency

Phase 3C remains request-time live-read:

```text
Open Home or Action Center
→ query enabled adapters
→ compose current projection

Responsible party completes, reassigns, or changes the workflow
→ navigate or manually refresh
→ adapters read current authoritative state
→ item updates, changes buckets, or disappears
```

There is no optimistic local removal. An item may become stale between render and navigation; the authoritative destination rechecks current tenant, responsibility, source state, and authorization.

Viewing or opening a Waiting item does not change its lifecycle. Notification read/archive state does not affect inclusion.

## 13. Rollout and Configuration

The current thesis deployment uses committed always-on application defaults. Phase 3C extends the existing bounded `owner_action_center.php` configuration rather than introducing environment flags or a generic rollout platform.

Conceptually:

```text
buckets
└─ waiting_on_others
   ├─ enabled = true
   └─ coverage
      ├─ compliance = true after readiness
      ├─ refunds = true after readiness
      └─ logistics = true after readiness
```

No `.env` edit or shop allowlist membership is required locally or in the deployed thesis environment.

Configuration controls presentation participation only. It does not alter domain authorization, module eligibility, responsibility, or workflow behavior.

Disabling the Waiting bucket removes only Phase 3C surfaces. Phase 3A, Phase 3B, canonical routes, and domain behavior remain unchanged.

## 14. Failure Isolation

```text
One Phase 3C adapter fails
→ healthy Waiting sources still render
→ count is marked partial
→ failed source is identified as temporarily unavailable

All enabled Phase 3C adapters fail
→ Waiting on Others is unavailable
→ not a healthy zero count

Phase 3C bucket disabled
→ Waiting on Others is absent
→ Phase 3A and Phase 3B remain operational

Shared Action Center framework failure
→ existing common Phase 3 degradation behavior
```

Disabled or not-yet-supported sources are hidden. They are not reported as runtime failures.

Security-sensitive failures contribute no data and are never converted into apparently successful results. Ambiguous responsibility is health-reported rather than misclassified.

## 15. Security and Privacy

Every Phase 3C adapter must:

- derive shop scope from the authenticated Shop Owner context;
- enforce tenant predicates in SQL;
- query only current authoritative records for that shop;
- use bounded and validated bucket, source, page, and per-page inputs;
- produce only validated local `/shop-owner/...` destinations;
- remain side-effect free;
- avoid using visibility as mutation authority;
- avoid exposing personal names as primary responsibility labels.

The destination re-evaluates its normal authentication, tenant, capability, source-state, locking, validation, audit, and mutation rules.

Operational logs must not contain customer, rider, employee, or reviewer names; refund reasons; document contents or storage paths; payment information; credentials; or sensitive workflow descriptions.

## 16. Observability

Bounded telemetry may include:

- stable `shop_id`;
- selected bucket and validated coverage source;
- enabled, healthy, and failed adapter keys;
- degradation status;
- adapter duration and distinct result count;
- bounded page/per-page values;
- correlation identifier;
- bounded domain-health reason category.

Role/team responsibility may be emitted only as a bounded key where operationally necessary. Personal identity and business-sensitive row contents remain excluded.

## 17. Verification Strategy

### Shared contract characterization

Before enabling Phase 3C:

- Phase 3A and Phase 3B inclusion, counts, links, ordering, filters, pagination, and failures remain unchanged;
- valid `waiting_on_others` role/team keys pass DTO validation;
- owner-action or `waiting_on = none` combinations fail for the Waiting bucket;
- the same concern cannot appear simultaneously in multiple buckets;
- source and adapter identities remain bucket-scoped and deterministic.

### Classification tests

```text
owner decision required
→ Needs My Decision only

no owner decision + legitimate other-party responsibility
→ Waiting on Others only

no owner decision + no legitimate party + material active condition
→ Urgent Exceptions only

resolved or non-material concern
→ no item
```

Tests must prove that aging alone changes ranking but not responsibility classification.

### Compliance verification

Using the authoritative business timezone and fixed local dates:

- 31+ days remaining is excluded;
- 30–8 days maps to normal/medium when a pending reviewer-owned renewal exists;
- 7–1 days maps to high/high;
- expires today or expired maps to critical/critical;
- pending renewal outside the material window is excluded;
- approval removes the Waiting item;
- rejection/withdrawal removes the Waiting item and permits 3B only when its predicate qualifies;
- contradictory lifecycle or responsibility data is a health failure;
- `urgency_at` and `actionable_since` use the authoritative timezone and lifecycle boundaries.

### Refund verification

- Order and Repair sources pass independent tests;
- Finance and Payment Recovery responsibility labels are distinct;
- assignment and responsibility changes record authoritative time evidence;
- retries do not falsify waiting age;
- legacy in-progress assigned rows are reportable and safely reconciled;
- owner decision precedence removes the concern from 3C;
- responsibility removal permits 3B only when its predicate qualifies;
- resolution and supersession remove the item;
- stale, invalid, or indeterminate responsibility is a health failure;
- original failure evidence remains preserved.

### Logistics verification

- valid active Rider responsibility qualifies;
- valid active Dispatcher responsibility qualifies;
- reassignment updates responsibility and its current boundary;
- owner confirmation/decision takes precedence;
- exhausted unowned recovery permits 3B only when its predicate qualifies;
- delivered, returned, cancelled, and resolved states exit;
- inactive riders, stale assignments, invalid batches, and contradictory states are health failures or normal exclusions according to domain policy;
- historical events alone never qualify.

### Coordinator and UI verification

- Home renders three independent summaries with at most three rows each;
- full Action Center renders three bucket tabs;
- `Needs My Decision` remains the healthy default when empty;
- Waiting uses only Compliance, Refunds, and Logistics source filters;
- each bucket owns independent counts, ordering, filters, and pagination;
- Home and full queue use identical contracts under fixed source state;
- healthy empty, partial, all-failed, disabled, and unsupported states are distinct;
- workflow links retain semantic navigation and destination authorization;
- no Action Center mutation controls exist;
- keyboard, focus, contrast, live announcements, dark/light themes, 200% zoom, responsive layout, and no-horizontal-overflow behavior pass.

### Performance and security verification

- tenant predicates execute in SQL;
- counts and candidates are bounded;
- adapters do not load full domain histories;
- no N+1 reviewer, Refund, assignment, rider, batch, leg, attempt, or return queries occur;
- source filters are applied before candidate limits;
- destinations are local and owner-safe;
- telemetry remains privacy-safe.

## 18. Implementation Sequence

The single Phase 3C implementation plan must preserve these gates:

```text
Gate A — Shared contract evolution
├─ add bounded Waiting role/team keys
├─ register bucket-specific coverage
├─ characterize Phase 3A/3B behavior
└─ add third-bucket Home/full-page support

Gate B — Compliance waiting adapter
├─ query material pending renewals
├─ project Compliance Review responsibility
└─ pass lifecycle, timezone, failure, and query gates

Gate C — Refund responsibility-age prerequisite and adapters
├─ preserve authoritative assignment time/evidence
├─ reconcile legacy assigned rows
├─ add Order Refund waiting adapter
├─ add Repair Refund waiting adapter
└─ pass responsibility, reclassification, and exit gates

Gate D — Logistics waiting adapter
├─ reuse LogisticsResponsibilityProjection
├─ project Rider/Dispatcher responsibility
└─ pass assignment, recovery, reclassification, and exit gates

Gate E — Integrated completion verification
├─ cross-source ordering and duplicate prevention
├─ Home/full-page consistency
├─ source filters and independent pagination
├─ partial/all-source failure behavior
├─ tenant, privacy, accessibility, and performance checks
└─ rollout and rollback evidence
```

No later adapter is enabled before its own readiness gate passes. All gates remain in one Phase 3C specification and one implementation plan.

## 19. Acceptance Criteria

Phase 3C is complete when:

1. The existing Action Center exposes `Waiting on Others` as its third responsibility bucket.
2. Phase 3A and Phase 3B remain behaviorally unchanged.
3. Classification remains responsibility-first, deterministic, and mutually exclusive.
4. Only owner-relevant, materially important work appears; routine staff tasks remain excluded.
5. Compliance renewal review projects deterministic Compliance Review responsibility inside the authoritative material window.
6. Order and Repair Refund recovery project distinct Finance and Payment Recovery responsibility using authoritative assignment-time evidence.
7. Logistics projects valid active Rider and Dispatcher responsibility through the existing domain projection.
8. Responsibility changes reclassify items without duplicate primary concerns.
9. Resolution removes items only through authoritative domain state.
10. Home shows three independent bounded summaries and the full page shows three bucket tabs.
11. Waiting source filters are limited to Compliance, Refunds, and Logistics.
12. The compact SoleSpace operational-queue visual contract is preserved.
13. No Action Center-owned mutation, acknowledgement, suppression, or assignment lifecycle exists.
14. Partial and complete Phase 3C failures do not disable Phase 3A or Phase 3B.
15. Healthy zero, partial, unavailable, disabled, and unsupported states are represented accurately.
16. Tenant, authorization, privacy, observability, accessibility, and bounded-query gates pass with recorded evidence.
17. Phase 3C requires no `.env` rollout changes in the thesis deployment.
18. One focused Phase 3C design and one implementation plan contain all accepted work.
