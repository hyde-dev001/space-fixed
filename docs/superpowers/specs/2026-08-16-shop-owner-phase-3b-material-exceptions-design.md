# Shop Owner Phase 3B Material Exceptions Design

**Date:** 2026-08-16

**Status:** Approved focused design; ready for implementation planning after written-spec review

## 1. Goal

Add `Urgent Exceptions` to the existing Shop Owner Action Center without creating a second workflow, notification, or exception-resolution system.

Phase 3B projects active material conditions that:

- require Shop Owner awareness;
- do not currently require an owner decision;
- have no legitimate deterministic actor or team responsible for the next step; and
- remain true in an authoritative domain source.

Phase 3B.1 launches with Compliance Document expiry exceptions only. Failed Refunds and Unowned Logistics Failures remain selected but blocked until their owning domains can prove recovery and responsibility state.

## 2. Relationship to Existing Designs

This design specializes the Phase 3B portion of [`2026-08-15-shop-owner-phase-3-action-center-master-design.md`](./2026-08-15-shop-owner-phase-3-action-center-master-design.md).

It preserves:

- Phase 1 domain-state and responsibility correctness;
- the Phase 2 canonical adaptive Shop Owner shell and canonical routes;
- the implemented Phase 3A live-read adapter, DTO, coordinator, Home summary, full queue, rollout, and failure contracts;
- authoritative Compliance, Refund, Logistics, notification, authorization, tenant, and audit behavior.

Phase 3B evolves the shared Action Center contract. It does not add a parallel exception coordinator, a persisted exception model, or a second mutation surface.

The boundary remains:

```text
Authoritative domain state
        ↓
Domain-specific attention adapter
        ↓
Shared OwnerActionCenterService
        ↓
Home summary / full Action Center
```

## 3. Scope and Staged Coverage

### Phase 3B.1 — initial production launch

```text
Urgent Exceptions
└─ Compliance Documents
```

### Selected but blocked

```text
Failed Refunds
└─ blocked on authoritative Refund recovery/resolution lifecycle

Unowned Logistics Failures
└─ blocked on authoritative Logistics responsibility projection
```

### In scope

- evolve the shared Phase 3 DTO and coordinator for explicit bucket and responsibility metadata;
- preserve all existing Phase 3A behavior;
- introduce bucket-specific Home summaries and full-queue tabs;
- centralize Compliance expiry-window policy in the Compliance domain;
- add a request-time Compliance Document exception adapter;
- introduce bucket-level Phase 3B rollout and adapter enablement inside the existing Phase 3 cohort;
- isolate Phase 3B source failures from Phase 3A decisions;
- implement the compact operational-queue visual contract;
- verify classification precedence, date boundaries, tenant isolation, bounded queries, rollback, and accessibility.

### Out of scope

- implementing Failed Refund or Logistics adapters before their prerequisites pass;
- implementing Phase 3C `Waiting on Others` presentation;
- persisting Action Center items or exception acknowledgements;
- Dismiss, Hide, Snooze, Acknowledge, or Mark resolved controls;
- changing notification read behavior;
- inline renewal, approval, rejection, or recovery mutations;
- a generic materiality engine or universal cross-domain threshold service;
- treating ambiguous responsibility or invalid lifecycle data as an Urgent Exception.

## 4. Shared-Contract Evolution

Phase 3B uses Approach 1: evolve the existing `OwnerAttentionItem` and `OwnerActionCenterService` contracts.

Before a Phase 3B adapter is enabled, every Phase 3A adapter must explicitly emit:

```text
primary_bucket = needs_my_decision
waiting_on = shop_owner
owner_action_required = true
coverage_source = its existing owner-facing source family
```

Hidden constructor defaults or coordinator inference for those fields are removed. Characterization tests must prove unchanged Phase 3A inclusion, counts, links, ordering, pagination, and failure behavior.

Phase 3B extends the bounded vocabularies conceptually with:

```text
primary_bucket
├─ needs_my_decision
├─ urgent_exceptions
└─ waiting_on_others        reserved for Phase 3C

waiting_on
├─ shop_owner
├─ none
└─ bounded actor/team key   reserved for Phase 3C

coverage_source
├─ refunds
├─ expenses
├─ purchase_requests
├─ compliance
└─ logistics               disabled until ready
```

Owner-facing coverage keys remain domain families within the selected bucket. Failed Refunds therefore reuse the `refunds` coverage key under `urgent_exceptions`, while their independently enabled and health-reported adapter key is `failed_refunds`. The future Logistics adapter uses `coverage_source = logistics` and `adapter_key = unowned_logistics_failures`. The initial Compliance adapter uses `coverage_source = compliance` and `adapter_key = compliance_documents`.

Phase 3B also normalizes the highest shared priority label from the Phase 3A implementation's legacy `urgent` token to the approved `critical` token. This is one coordinated PHP/TypeScript/test migration: existing Phase 3A highest-priority items retain the same relative ordering and behavior, but adapters emit `critical` and the shared contract does not support `urgent` and `critical` as ambiguous synonyms. The shared materiality vocabulary similarly adds `critical` above `high`.

The exact PHP and TypeScript enum/type names follow existing conventions, but DTO construction must reject invalid combinations:

```text
needs_my_decision
→ owner_action_required = true
→ waiting_on = shop_owner

urgent_exceptions
→ owner_action_required = false
→ waiting_on = none

waiting_on_others
→ owner_action_required = false
→ waiting_on = legitimate actor/team key
```

The coordinator may compose, validate, order, filter, count, and paginate explicit metadata. It must not infer a bucket from source type, title, category, priority, age, or amount.

## 5. Classification Precedence

Classification is mutually exclusive and responsibility-first:

```text
Does the Shop Owner currently need to decide?
├─ Yes → Needs My Decision
└─ No
   ↓
Does a deterministic legitimate actor/team own the next step?
├─ Yes → Waiting on Others
└─ No
   ↓
Is an active material condition important enough for owner awareness?
├─ Yes → Urgent Exceptions
└─ No → no Action Center item
```

A single authoritative concern may produce at most one primary Action Center item. Owner decision takes precedence; otherwise legitimate other-party responsibility takes precedence; only then may a qualifying unowned material condition become an Urgent Exception.

This rule prevents:

- one Refund appearing in both Phase 3A and Phase 3B;
- one Compliance renewal appearing in both Phase 3B and Phase 3C;
- stale assignments forcing a condition into `Waiting on Others`;
- missing or contradictory responsibility data being mislabeled as an exception.

Indeterminate responsibility is a domain/read-health failure, not an Urgent Exception.

## 6. Source-Owned Materiality

Qualification and ranking are separate decisions:

```text
Owning domain policy
→ determines whether the condition qualifies

Adapter
→ maps the qualifying result into normalized attention fields

Coordinator
→ orders already-qualified items
```

The Action Center coordinator must never contain source rules such as:

```text
document expires within 7 days
refund failed three times
delivery is overdue by 48 hours
```

Those rules belong to the owning domain. The shared Action Center contract defines only the bounded comparison vocabulary:

```text
priority_tier
├─ critical
├─ high
├─ normal
└─ low

materiality_tier
├─ critical
├─ high
├─ medium
├─ low
└─ none
```

An adapter may map an authoritative source result into these tiers only after the domain says the record qualifies. Old age, large monetary exposure, or an early deadline cannot independently promote a non-qualifying record.

## 7. Compliance Domain Policy

### Existing authoritative sources

The current Compliance lifecycle is grounded in:

- `ShopDocument` immutable version rows;
- `ShopDocument::currentApproved()` and `datedReminderCandidates()`;
- `ShopDocumentValidityService` for reviewer-verified current-document validity;
- `ShopDocumentReminderService` for the existing 30/7/0 reminder thresholds;
- `config('app.shop_timezone', 'Asia/Manila')` for business-date boundaries;
- pending renewal rows linked through `predecessor_document_id`;
- the canonical owner destination `/shop-owner/settings/policies-compliance`.

The current implementation has a broad `expiring_soon` validity state at 30 days and separately stores 30/7/0 reminder thresholds in `ShopDocumentReminderService`. Phase 3B.1 must canonicalize those existing rules in the Compliance domain before the adapter is enabled.

### Canonical expiry-window result

The existing domain policy must expose one side-effect-free expiry-window classification equivalent to:

```text
outside_window
renewal_window
urgent_window
expires_today
expired
non_expiring
metadata_unverified
```

The existing authoritative boundaries are:

```text
31+ days remaining
→ outside_window

8–30 days remaining
→ renewal_window

1–7 days remaining
→ urgent_window

0 days remaining
→ expires_today

negative days remaining
→ expired
```

The implementation should evolve `ShopDocumentValidityService` or a comparably small Compliance-domain policy using existing conventions. Its existing broad validity output may remain compatible for current settings consumers while a more precise side-effect-free expiry-window result serves Phase 3B.

`ShopDocumentReminderService` and the Compliance adapter must consume the same policy/boundaries so `30 / 7 / 0` are not duplicated in Action Center configuration or adapter code. Sharing boundaries does not merge their behaviors:

```text
Reminder delivery
→ exact milestone dates only: 30, 7, and 0

Action Center qualification
→ continuous active windows: 8–30, 1–7, today, and expired
```

Phase 3B must not cause daily reminder delivery or otherwise change the reminder cadence.

The policy is side-effect free, uses the configured business timezone, works with fixed test dates, and does not persist validity state.

## 8. Compliance Document Adapter

### Inclusion

One Compliance item qualifies only when all conditions are true:

```text
document belongs to the authenticated Shop Owner tenant
+ document is current
+ document is approved
+ reviewer identity and review timestamp are present
+ expiration_mode = dated
+ expires_on is valid
+ authoritative expiry policy is within renewal_window, urgent_window,
  expires_today, or expired
+ no explicit owner decision currently takes precedence
+ no legitimate pending renewal reviewer owns the next step
```

The adapter must use a bounded, tenant-scoped query suitable for request-time counts and candidate retrieval. It must not load all document history and filter it in PHP.

### Projection

The normalized projection is:

```text
source_type = compliance_document
coverage_source = compliance
category = document_expiry
primary_bucket = urgent_exceptions
module = compliance
waiting_on = none
owner_action_required = false
comparable_monetary_exposure = null
urgency_at = authoritative expires_on date
actionable_since = business date when the document entered the 30-day window
destination_url = /shop-owner/settings/policies-compliance
```

`actionable_since` is serialized as the ISO-8601 start of that local business date in the authoritative shop timezone so it remains compatible with the shared timestamp contract.

The authoritative entry date is the later of the 30-day window opening and the document becoming current/reviewer-verified. A document approved after it has already entered the window must not claim that it was actionable before that authoritative current approval existed.

Stable identity is:

```text
compliance_document + current ShopDocument ID + document_expiry
```

The title and summary may expose the safe document type/slot label, expiration date, and current responsibility statement. They must not expose private storage paths, checksums, evidence contents, rejection evidence, or unrelated owner data.

### Priority and materiality mapping

```text
renewal_window
→ priority_tier = normal
→ materiality_tier = medium

urgent_window
→ priority_tier = high
→ materiality_tier = high

expires_today / expired
→ priority_tier = critical
→ materiality_tier = critical
```

The adapter maps authoritative domain results; it does not calculate the window independently.

### Exit and reclassification

The item disappears on the next live read when:

- the current document is replaced or superseded;
- a renewal is approved and promoted to current;
- the document becomes authoritatively non-expiring;
- corrected expiry metadata moves it outside the material window;
- the document no longer satisfies reviewer-verified current status; or
- another authoritative terminal resolution removes the predicate.

When a legitimate pending renewal successor becomes the Super Admin/reviewer’s responsibility, the concern stops qualifying for Phase 3B. It becomes eligible for later Phase 3C projection only after that focused design is implemented.

If a future domain state explicitly requires an owner decision, `Needs My Decision` takes precedence.

### Invalid lifecycle data

The following are domain/read-health failures and contribute no exception item:

- missing or malformed expiry metadata;
- multiple conflicting current versions for one logical slot;
- unverified current metadata;
- broken or contradictory predecessor/successor relationships;
- a pending successor whose legitimate responsibility cannot be determined;
- an unreconciled legacy row that cannot satisfy the current approved/versioned contract.

These conditions must be observable without exposing private document data.

## 9. Blocked Source Prerequisites

### Failed Refunds

Failed Refunds remain disabled and hidden until the Refund domain can prove:

- authoritative unresolved recovery state distinct from historical execution failure;
- active legitimate Finance/payment recovery ownership;
- explicit owner-decision precedence where applicable;
- controlled and idempotent recovery resolution;
- retry/replacement linkage that preserves original failure evidence;
- exhaustive entry, reclassification, and exit behavior.

A terminal `failed` value or old failure notification is insufficient.

### Unowned Logistics Failures

Logistics remains disabled and hidden until a side-effect-free, bulk-safe domain projection can determine:

```text
owner_action_required
deterministic_responsible_party
recovery_path_active
recovery_path_exhausted
material_exception_active
```

Old rider assignments, completed batches, invalid dispatcher assignments, and historical overdue events do not establish current responsibility. Retry exhaustion and materiality must come from authoritative Logistics policy.

### Blocked-source presentation

Blocked sources:

- do not appear as filters;
- do not contribute zero counts;
- do not appear as placeholders;
- are not reported as temporarily unavailable or failed;
- remain visible only in engineering readiness documentation.

Refunds and Logistics are onboarded independently when ready. Neither waits for the other.

## 10. Home and Full-Queue Interaction

### Home

`/shop-owner/home` displays independent bounded summaries from the same coordinator:

```text
Needs My Decision
├─ bucket-specific count
└─ top 3–5 decisions

Urgent Exceptions
├─ bucket-specific count
└─ top 3–5 exceptions
```

The summaries never merge into one generic attention list. Each `View all` link selects the corresponding bucket on `/shop-owner/action-center`.

### Full Action Center

```text
/shop-owner/action-center

[ Needs My Decision  N ] [ Urgent Exceptions  N ]
```

The selected bucket owns:

- its supported-source filters;
- its count and availability state;
- its deterministic ordering;
- its bounded page and per-page state;
- its pagination controls.

Conceptual URLs are:

```text
/shop-owner/action-center?bucket=needs_my_decision&page=2
/shop-owner/action-center?bucket=urgent_exceptions&source=compliance&page=2
```

Changing buckets resets the selected bucket to page 1 or another documented valid starting page. Decision and exception items are never combined into one globally paginated queue.

`Needs My Decision` remains the default healthy bucket even when it is empty and exceptions contain items. Automatic fallback is permitted only when the default bucket is unsupported or unavailable, not merely empty.

When Compliance is the only active exception source, the redundant Compliance-only source filter may be omitted. Unsupported filters are never rendered.

## 11. Operational-Queue Visual Contract

The Action Center uses one compact operational-queue language across Phase 3A, Phase 3B, and later Phase 3C.

The full-page hierarchy is:

```text
compact page header + last-refreshed status + utility Refresh
bucket tabs with count badges
selected bucket title and concise purpose
bucket-specific source filters
one light queue container
structured rows separated by dividers
conventional pagination
secondary coverage disclosure
```

Rules:

- use rows rather than oversized tiles or an ERP-style table;
- source badge appears first and title is dominant;
- exposure, urgency, and age are secondary scanning metadata;
- textual priority accompanies semantic color;
- bucket tabs dominate source filters;
- selected state uses the existing SoleSpace blue;
- Refresh is a utility action, not the primary CTA;
- `Open workflow` remains an explicit semantic link;
- no inline Approve, Reject, Dismiss, Hide, Snooze, or Resolve controls;
- partial-source failures use a calm inline notice above healthy rows;
- pagination is conventional; infinite scroll is not used;
- mobile metadata stacks vertically with no horizontal page scrolling;
- interactive controls retain at least 44-pixel touch targets;
- focus order follows visual order and visible focus is preserved;
- dynamic counts and source-health changes use appropriate live-region semantics;
- dark/light themes and reduced-motion preferences remain supported.

The page avoids decorative gradients, glass effects, excessive shadows, decorative metrics, and unsupported-source zero states.

## 12. No Independent Exception Lifecycle

Urgent Exceptions have no Action Center-owned dismiss, acknowledge, hide, snooze, or resolve state.

```text
Domain predicate remains true
→ exception remains visible

Domain responsibility changes
→ item changes buckets on the next read

Domain resolves the condition
→ item disappears on the next read
```

Opening a workflow, viewing details, navigating back, or refreshing does not change visibility.

Notification acknowledgement is separate. Marking a related notification read or archived must not change Action Center inclusion because notifications represent event awareness while the Action Center represents current authoritative state.

If an exception proves noisy, the owning domain’s qualification or escalation policy must be corrected. Phase 3B does not add per-owner suppression state.

## 13. Rollout, Failure, Security, and Observability

### Rollout

Phase 3B is nested inside the existing successful Phase 3A/Phase 2 cohort:

```text
Phase 2 canonical shell selected
+ Phase 3 Action Center selected
+ Urgent Exceptions bucket enabled
+ Compliance adapter enabled
→ Phase 3B.1 presentation
```

Bucket enablement and adapter enablement use bounded application-controlled configuration. They do not become owner settings, capabilities, or domain authorization.

The configuration should extend the existing narrow `owner_action_center.php` contract rather than create a generic flag platform. Conceptually:

```text
existing Phase 3A coverage
├─ refunds
├─ expenses
└─ purchase_requests

urgent_exceptions
├─ enabled
└─ coverage
   ├─ compliance = true at Phase 3B.1 launch
   ├─ refunds = false until Failed Refund readiness
   └─ logistics = false until Logistics readiness
```

The adapter registry resolves by explicit bucket plus coverage source so a `refunds` decision adapter cannot accidentally participate in `urgent_exceptions`, and the future `failed_refunds` adapter cannot participate in `needs_my_decision`.

Disabling Phase 3B removes the `Urgent Exceptions` surfaces and returns the owner to the unchanged Phase 3A experience. It does not disable Phase 3 as a whole, remove canonical routes, change domain behavior, or require data rollback.

### Failure isolation

```text
Compliance adapter healthy
→ normal Urgent Exceptions bucket

Compliance adapter runtime failure
→ Compliance temporarily unavailable
→ exception count is unavailable, not zero
→ Needs My Decision remains healthy

Failed Refunds / Logistics blocked
→ unsupported and hidden
→ not failed

Phase 3B disabled
→ Urgent Exceptions surface absent
→ Phase 3A unchanged

Shared Phase 3 framework failure
→ existing Phase 3 common degradation contract
```

If Compliance is the only enabled exception adapter and it fails, the exception bucket reports unavailable rather than healthy-empty. A security-sensitive failure contributes no data and is never converted into an apparently successful result.

### Security

The Compliance adapter must:

- derive tenant scope from the authenticated Shop Owner context;
- query only the owner’s current document lifecycle rows;
- validate bucket, source, page, and per-page inputs;
- produce only a local owner-safe destination;
- expose no private paths, checksums, evidence contents, or unrelated documents;
- remain side-effect free;
- avoid using Action Center visibility as mutation authority.

The canonical destination re-evaluates its normal authentication, tenant, and workflow rules.

### Observability

Bounded operational telemetry may include:

- stable `shop_id`;
- selected bucket and validated source key;
- enabled, healthy, failed, and blocked adapter keys as separate concepts;
- adapter duration and distinct result count;
- degradation status;
- bounded pagination values;
- correlation identifier;
- a bounded domain-health reason category for invalid lifecycle data.

Logs must not contain document titles tied to personal evidence, storage paths, checksums, document contents, owner identity details, or other sensitive evidence.

## 14. Verification and Completion Gates

### Shared-contract characterization

Before enabling Phase 3B:

- all Phase 3A adapters explicitly emit bucket, responsibility, action-required, and coverage metadata;
- Phase 3A inclusion, counts, links, ordering, filters, pagination, and failure behavior remain unchanged;
- invalid DTO bucket/responsibility combinations fail validation;
- the same authoritative concern cannot appear in multiple primary buckets.

### Classification tests

Classification must be tested as one mutually exclusive decision tree:

```text
owner decision required
→ Needs My Decision only

no owner decision + legitimate other-party responsibility
→ Waiting on Others only

no owner decision + no legitimate next actor + material active condition
→ Urgent Exceptions only

none of the above
→ no Action Center item
```

### Compliance boundary tests

Using the authoritative business timezone and fixed local dates:

```text
31+ days remaining → excluded
30 days            → normal / medium
8 days             → normal / medium
7 days             → high / high
1 day              → high / high
expires today      → critical / critical
already expired    → critical / critical
```

Tests must also prove:

- non-expiring documents are excluded;
- non-current, unapproved, or reviewer-unverified documents are excluded and/or health-reported appropriately;
- pending renewal responsibility removes the concern from Phase 3B;
- renewal approval/current replacement removes the item;
- contradictory lifecycle data is a domain-health failure, not an exception;
- `actionable_since` and `urgency_at` follow business-date semantics;
- the policy and adapter never persist derived validity or Action Center state;
- reminder delivery and Action Center classification consume the same domain boundaries.

### Lifecycle and presentation tests

Verify the absence of:

```text
Dismiss
Hide
Acknowledge
Snooze
Mark resolved
```

Opening the Compliance workflow, returning, or refreshing must leave the item visible while the source predicate remains true. Notification read/archive state must not affect inclusion.

Frontend and browser checks cover:

- separate Home summaries;
- bucket tabs and count badges;
- healthy empty default decision bucket remains selected;
- bucket-specific filters and independent pagination;
- no mixed global decision/exception page;
- page reset on bucket change;
- compact row hierarchy and semantic workflow links;
- calm partial/unavailable notices;
- keyboard navigation, focus, contrast, live announcements, dark/light themes, 200% zoom, and responsive layouts without horizontal overflow.

### Failure and rollback tests

Verify:

- Compliance runtime failure leaves Phase 3A operational;
- Compliance failure is unavailable, not zero;
- blocked Refund and Logistics adapters are hidden, not failed;
- disabling the Urgent Exceptions bucket returns exactly to Phase 3A presentation;
- disabling the Compliance adapter introduces no domain or data rollback;
- shared framework failure follows the existing Phase 3 common fallback and redirect-loop protections.

### Performance and query evidence

Verify:

- tenant filtering occurs in SQL;
- entry predicates and pending-successor checks are bounded and index-aware;
- counts and top-item reads do not load full histories;
- candidate queries respect shared page/per-page ceilings;
- no N+1 document, owner, predecessor, successor, or reviewer queries occur;
- Home and full queue use identical contracts under fixed source state.

### Completion definitions

Phase 3B.1 initial production launch is complete when the Compliance Document domain policy and adapter pass readiness, security, performance, classification, timezone, presentation, observability, and rollback gates and can be enabled without changing Phase 3A behavior.

Full declared Phase 3B initial coverage is complete only after Failed Refunds and Unowned Logistics Failures independently satisfy their domain prerequisites, focused adapter contracts, and readiness gates. Runtime failure isolation after enablement is not a substitute for pre-launch readiness.

## 15. Implementation Sequence

The implementation plan should preserve this order:

```text
1. Characterize current Phase 3A and Compliance behavior.
2. Make Phase 3A DTO metadata explicit without behavioral change.
3. Centralize Compliance 30/7/0 expiry-window policy in the domain.
4. Add Compliance policy boundary and reminder-parity tests.
5. Evolve the shared coordinator for bucket-specific reads.
6. Add the Compliance exception adapter behind disabled configuration.
7. Add separate Home summaries and full-page bucket navigation.
8. Apply the compact operational-queue presentation.
9. Add rollout, failure-isolation, security, accessibility, and query evidence.
10. Enable Phase 3B.1 only after the Compliance readiness gate passes.
```

Failed Refund and Logistics domain prerequisites receive separate focused designs/plans before their adapters are implemented.

## 16. Acceptance Criteria

Phase 3B.1 is accepted when:

1. `Urgent Exceptions` uses the existing shared Action Center framework.
2. Phase 3A remains behaviorally unchanged.
3. Classification is explicit, deterministic, and mutually exclusive.
4. Compliance materiality comes from one authoritative domain policy shared with reminder behavior.
5. Compliance boundaries use the configured business timezone.
6. Only current, approved, reviewer-verified, dated, materially expiring documents without owner or reviewer responsibility appear.
7. Invalid lifecycle data is health-reported and never mislabeled as an exception.
8. Home and the full Action Center expose separate decision and exception summaries/queues.
9. Bucket filters, ordering, counts, and pagination are independent.
10. The Action Center uses the approved compact operational-queue visual language.
11. No Action Center-owned exception acknowledgement or resolution lifecycle exists.
12. Compliance failure does not disable or corrupt Phase 3A.
13. Blocked Refund and Logistics sources remain hidden and are not reported as failed.
14. Disabling Phase 3B returns safely to the Phase 3A experience without data rollback.
15. Tenant, authorization, privacy, accessibility, performance, and observability gates pass with recorded evidence.
