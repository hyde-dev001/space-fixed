# Shop Owner Phase 3B Material Exceptions Rollout Guide

## Release boundary

This release adds the `Urgent Exceptions` bucket to the existing Phase 3 Action Center. Compliance Documents, Failed Order and Repair Refunds, and Unowned Logistics Failures are enabled by default in the thesis build after the completed readiness gates.

The rollout is presentation-only. It does not change Shop Owner authorization, Compliance workflows, document state, or the existing Phase 3A decision queue.

Current source status:

| Source | Status | User-facing behavior |
| --- | --- | --- |
| Compliance Documents | Releasable after Gate B verification | Appears under `Urgent Exceptions` |
| Failed Order and Repair Refunds | Gate D implementation and Gate G readiness verification complete | Appears when an authoritative failed-recovery predicate qualifies |
| Unowned Logistics Failures | Gate E/F implementation and Gate G readiness verification complete | Appears when an authoritative unowned-failure predicate qualifies |

The source adapters remain independently failure-isolated. A runtime adapter failure is presented as unavailable source coverage; it is never represented as zero work.

## Eligibility hierarchy

A Shop Owner receives Phase 3B when the canonical shell and Action Center are selected:

```text
Phase 2 canonical shell selected
  -> Phase 3 Action Center selected
     -> Urgent Exceptions bucket enabled
        -> enabled, readiness-approved adapters
```

The thesis build does not require Phase 2 or Phase 3 shop allowlists. Existing tenant, module, source-state, and authorization checks remain authoritative.

## Configuration

The thesis build uses the committed defaults in `config/owner_action_center.php`:

```text
owner_action_center.enabled = true
owner_action_center.allowlisted_shop_ids = []
owner_action_center.buckets.urgent_exceptions.enabled = true
owner_action_center.buckets.urgent_exceptions.coverage.compliance = true
owner_action_center.buckets.urgent_exceptions.coverage.refunds = true
owner_action_center.buckets.urgent_exceptions.coverage.logistics = true
```

The Action Center rollout and source environment variables are no longer read. No `.env` change is required for local, thesis, or deployed environments. After a config code change, clear and rebuild Laravel's configuration cache using the normal deployment procedure.

## Expected behavior

- Home shows `Needs My Decision` and `Urgent Exceptions` as separate summaries.
- `/shop-owner/action-center` defaults to `Needs My Decision`; the owner selects `Urgent Exceptions` explicitly.
- Source filters are bucket-scoped: Compliance, Refunds, and Logistics appear only when their corresponding source is enabled and healthy for that bucket.
- Compliance exceptions link to the authoritative Policies & Compliance workflow; Logistics exceptions link to the authorized Shop Owner Logistics shipments workflow.
- Opening or viewing an exception does not dismiss, acknowledge, snooze, hide, or resolve it.
- An exception disappears or changes classification only after authoritative domain or responsibility state changes.
- Phase 3A decisions retain their existing inclusion, ordering, filtering, and pagination.

## Health and observability

Monitor the existing bounded structured events:

- `owner_action_center.adapter_read`
- `owner_action_center.adapter_failed`
- `owner_action_center.read`
- `owner_action_center.route_failed`

Useful fields include stable `shop_id`, bucket, adapter key, coverage source, duration, result count, degradation status, bounded pagination/filter values, and correlation ID. These events must not include filenames, paths, checksums, document contents, customer data, workflow reasons, titles, or monetary details.

Interpret health states carefully:

| State | Meaning |
| --- | --- |
| Compliance adapter healthy with zero items | No current qualifying Compliance exceptions |
| Compliance adapter failed | Urgent Exceptions unavailable; do not present this as zero work |
| Failed Refund/Logistics flags off | Sources intentionally unsupported and hidden, not failed |
| Failed Refund/Logistics adapter failed | Only that source is unavailable; healthy exception sources remain visible |
| Urgent Exceptions bucket off | Phase 3A continues without the Phase 3B surface |

A Compliance adapter failure is isolated from the Phase 3A decision bucket. A common Action Center composition failure follows the established Phase 3 degradation behavior.

## Gate G verification evidence

The complete declared Phase 3B implementation has passed the focused readiness gates for all three source families:

- Action Center and owner-attention contract suites: **157 tests / 821 assertions passed**.
- Logistics readiness and regression suite: **341 passed / 1 skipped / 1,813 assertions**. The skipped test requires the optional GD extension and is unrelated to the Phase 3B projection contract.
- Frontend Action Center and Home tests: **12 tests passed**.
- Full frontend Vitest suite: **passed** with exit code 0.
- Vite production build: **passed**, transforming 3,708 modules.
- `git diff --check`: **passed**.

The full repository Composer suite was attempted with extended timeout and memory settings but could not complete because the existing route-loading path exhausted PHP memory at `routes/web.php:1335`. This is an environment/repository-wide verification limitation, not a failure in the focused Phase 3B suites.

The completed readiness results authorize the always-on thesis defaults; they do not change domain authorization.

## Verification after enablement

Confirm:

- cross-shop documents never appear;
- no private file metadata is serialized or logged;
- 30/7/0 policy boundaries follow the configured shop timezone;
- a pending renewal with deterministic reviewer responsibility is excluded from `Urgent Exceptions`;
- contradictory lifecycle data produces a degraded source state, not a misleading exception;
- failed Order and Repair refunds with active Finance/payment-recovery ownership are excluded;
- reassigned, retried, returned, cancelled, or resolved Logistics failures disappear or reclassify on the next read;
- decision and exception tabs retain independent filters and pagination;
- refresh preserves valid bucket/filter/page state and normalizes an invalid page;
- keyboard focus, semantic links, mobile layout, dark mode, 200% zoom, and contrast remain usable;
- no Dismiss, Hide, Acknowledge, Snooze, Resolve, Approve, or Reject control appears in the Action Center.

## Rollback

For a Phase 3B-specific regression, deploy the previous verified revision or make a reviewed code/config change, then refresh Laravel configuration. Confirm `Urgent Exceptions` degrades without affecting Phase 3A `Needs My Decision`. Automated tests retain explicit configuration overrides for source-specific failure and rollback behavior.

Rollback changes presentation/read participation only. It does not require a database rollback and must not alter document state or domain authorization.

## Completion distinction

Gate B is the first-stage Phase 3B release checkpoint: Compliance Documents can be enabled safely without affecting Phase 3A.

Gate D covers both Order and Repair failed-refund recovery projections. Gate E defines the Logistics responsibility projection, and Gate F covers the unowned Logistics adapter.

Full declared Phase 3B implementation and readiness verification is complete after all three sources independently pass the final coverage verification gate:

- Failed Order and Repair Refund recovery lifecycles and adapters;
- Unowned Logistics responsibility projection and adapter.

The thesis implementation enables the three readiness-approved source families by default. Phase 3C `Waiting on Others` remains outside this release.
