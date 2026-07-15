# Batch Rejection Visibility and Rider Capacity Design

## Scope

Fix two gaps in the delivery batch workflow without changing logistics coverage settings:

1. A rider-rejected offer returns to `draft`, but the dispatcher cannot see the rejection reason and receives no notification.
2. Rider capacity checks compare only the new batch with the rider limit instead of adding the rider's other batches for the same delivery date.

## Rejection behavior

- Keep a rejected batch in `draft` so the dispatcher can edit and re-offer it.
- Preserve `rejection_reason` and `rejected_at` after rejection.
- Show a red **Rejected by rider** banner with the reason and rejection time on the batch card and batch workspace.
- Send one high-priority **Batch Offer Rejected** notification to users with the Logistics Dispatcher role for the same shop. The notification links to `/erp/logistics/batches` and includes the batch ID and rejection reason.
- Clear `rejection_reason` and `rejected_at` only when the batch is successfully offered again.

## Cumulative daily capacity

- For the selected rider and the candidate batch delivery date, total `assigned_stop_count` from the rider's other batches in `offered`, `accepted`, `in_progress`, and `completed` states.
- Do not count `draft` or `cancelled` batches. The candidate batch is excluded from its existing workload calculation.
- Projected workload is `existing same-day stops + candidate batch stops`.
- The offer modal displays existing, added, projected, and capacity values.
- When projected workload exceeds the rider's `daily_capacity`, the dispatcher must enter an override reason before offering.
- The API accepts an optional `capacity_override_reason`, recalculates workload inside the offer transaction, and rejects over-capacity offers without a reason. UI calculations are advisory; the server is authoritative.
- Store the accepted override reason in the batch's existing `dispatcher_override_reason` field for auditability.

## Data and notification flow

The existing rejection endpoint remains unchanged. `BatchDispatchService` continues to own state transitions and records a `batch_rejected` delivery event containing the batch ID, rider identity, and reason. `LogisticsNotificationService` maps that event to a dispatcher notification.

The offer endpoint adds `capacity_override_reason`. `BatchDispatchService` locks the rider and batch, calculates the same-day workload, validates the override when necessary, creates assignments, clears stale rejection data, and records the existing batch-offered event.

## Error handling

- Missing rejection reason remains a validation error.
- Over-capacity offer without an override returns a validation error attached to `capacity_override_reason`.
- Stale or unauthorized actions retain the existing validation and authorization behavior.
- The offer modal remains open and shows the server error when an offer fails.

## Verification

- Backend regression test: rejection persists reason/time, creates one dispatcher notification with batch/reason data, and re-offer clears stale rejection details.
- Backend regression test: cumulative same-day active/completed stops are counted, draft/cancelled stops are ignored, missing override is rejected, and a supplied override is stored.
- Frontend regression tests: rejected draft banner renders; the offer modal shows projected workload and requires an override reason when capacity is exceeded.
- Run all logistics backend tests, logistics frontend tests, and the production build.
