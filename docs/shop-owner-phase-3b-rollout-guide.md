# Shop Owner Phase 3B Material Exceptions Rollout Guide

## Release boundary

This release adds the `Urgent Exceptions` bucket to the existing Phase 3 Action Center. Compliance Documents are the first production source; Failed Refunds and Unowned Logistics Failures are implemented as independently controlled sources and remain hidden until the final verification and enablement gates pass.

The rollout is presentation-only. It does not change Shop Owner authorization, Compliance workflows, document state, or the existing Phase 3A decision queue.

Current source status:

| Source | Status | User-facing behavior |
| --- | --- | --- |
| Compliance Documents | Releasable after Gate B verification | Appears under `Urgent Exceptions` |
| Failed Order and Repair Refunds | Gate D implementation and Gate G readiness verification complete | Hidden until enabled |
| Unowned Logistics Failures | Gate E/F implementation and Gate G readiness verification complete | Hidden until enabled |

Blocked sources must not be presented as unavailable. They are not enabled production coverage yet.

## Eligibility hierarchy

A Shop Owner receives Phase 3B only when every parent rollout gate succeeds:

```text
Phase 2 canonical shell selected for the shop
  -> Phase 3 Action Center selected for the same shop_id
     -> Urgent Exceptions bucket enabled
        -> Compliance adapter enabled
```

The Phase 2 and Phase 3 allowlists must use the same stable `shop_id`. Do not use an owner email, account ID, browser identifier, or another mutable value.

## Configuration

Use these application-controlled environment values:

```dotenv
# Existing Phase 3 selection
SHOP_OWNER_ACTION_CENTER_ENABLED=true
SHOP_OWNER_ACTION_CENTER_SHOP_IDS=42

# Phase 3B bucket and source controls
SHOP_OWNER_ACTION_CENTER_URGENT_EXCEPTIONS_ENABLED=true
SHOP_OWNER_ACTION_CENTER_COMPLIANCE_ENABLED=true

# Blocked until their independent readiness gates pass
SHOP_OWNER_ACTION_CENTER_FAILED_REFUNDS_ENABLED=false
SHOP_OWNER_ACTION_CENTER_LOGISTICS_EXCEPTIONS_ENABLED=false
```

The shop must already be eligible for the Phase 2 canonical shell. Preserve the existing Phase 3A source settings for Refund decisions, Expenses, and Purchase Requests.

## Safe enablement order

1. Deploy the verified code with `SHOP_OWNER_ACTION_CENTER_URGENT_EXCEPTIONS_ENABLED=false`.
2. Confirm the existing Phase 3A Action Center remains healthy for the intended shop cohort.
3. Confirm the Phase 3 allowlist contains only approved stable shop IDs.
4. Set `SHOP_OWNER_ACTION_CENTER_COMPLIANCE_ENABLED=true` while keeping the bucket disabled. This does not expose the source by itself.
5. Set `SHOP_OWNER_ACTION_CENTER_URGENT_EXCEPTIONS_ENABLED=true`.
6. Clear or refresh configuration using the deployment platform's normal Laravel procedure.
7. Verify one allowlisted company owner and one allowlisted individual owner where applicable.
8. Expand the Phase 3 shop allowlist only after telemetry and workflow deep links are healthy.

Do not enable Failed Refunds or Logistics merely to test their filters. Their classes are registered and covered by focused tests, but production enablement remains blocked until Gate G confirms the complete three-source contract.

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

All Phase 3B source flags remain disabled. These results authorize controlled rollout review; they do not enable the bucket or change domain authorization.

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

For a Phase 3B-specific regression:

1. Set `SHOP_OWNER_ACTION_CENTER_URGENT_EXCEPTIONS_ENABLED=false`.
2. Refresh Laravel configuration using the normal deployment procedure.
3. Confirm `Urgent Exceptions` disappears from Home and the Action Center.
4. Confirm Phase 3A `Needs My Decision` remains operational.

If only Compliance is unhealthy, `SHOP_OWNER_ACTION_CENTER_COMPLIANCE_ENABLED=false` also removes that source. Because Compliance is the sole first-stage exception source, disabling it should normally be paired with disabling the bucket to return cleanly to Phase 3A.

Rollback changes presentation/read participation only. It does not require a database rollback and must not alter document state or domain authorization.

## Completion distinction

Gate B is the first-stage Phase 3B release checkpoint: Compliance Documents can be enabled safely without affecting Phase 3A.

Gate D covers both Order and Repair failed-refund recovery projections. Gate E defines the Logistics responsibility projection, and Gate F covers the unowned Logistics adapter.

Full declared Phase 3B implementation and readiness verification is complete after all three sources independently pass the final coverage verification gate:

- Failed Order and Repair Refund recovery lifecycles and adapters;
- Unowned Logistics responsibility projection and adapter.

The implementation keeps all three source flags disabled until the controlled rollout is explicitly approved. Phase 3C `Waiting on Others` remains outside this release.
