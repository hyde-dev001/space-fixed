# Rider Batch Bulk Status Design

## Goal

Let a rider select multiple eligible stops inside one in-progress delivery batch and move them to picked up or in transit with one confirmed action.

## Scope and rules

- Selection is isolated per batch; there is no cross-batch select-all.
- Checkboxes appear only for stops whose status is `assigned` or `picked_up` in an `in_progress` batch.
- Each batch header shows Select all, selected count, Mark Picked Up, Mark In Transit, and Clear controls.
- Mark Picked Up processes only selected `assigned` stops.
- Mark In Transit processes only selected `picked_up` stops.
- Mixed-status selection is allowed. Each action skips ineligible selected stops and reports the skipped count.
- Multiple selected stops may be in transit simultaneously.
- Normal outbound retail legs have `requires_pickup_proof = false`, so pickup does not require a newly uploaded proof. A leg explicitly configured with `requires_pickup_proof = true` keeps the existing proof requirement and may fail independently. Delivery proof requirements remain unchanged.

## Implementation approach

Reuse the existing per-stop logistics endpoints instead of adding a bulk backend endpoint:

- `POST /api/logistics/legs/{leg}/picked-up` for pickup.
- `POST /api/logistics/legs/{leg}/out-for-delivery` for in transit.

The UI sends one request per eligible selected stop with `Promise.allSettled`. This preserves the existing rider assignment, tenant, transition, timestamp, event, and notification checks. Add a minimal guard to `ShipmentLegService::markPickedUp`: when a leg belongs to a batch, that batch must be `in_progress`. The existing out-for-delivery transition already enforces this state. Batches are small enough that the extra requests are acceptable, while avoiding duplicate bulk-transition logic.

## UI behavior

- Bulk controls live in the corresponding batch header.
- Action labels include the eligible count, such as `Mark Picked Up (2)`.
- Buttons are disabled while the batch action is processing to prevent duplicate requests.
- Swal asks for confirmation once before sending requests and identifies eligible and skipped counts.
- The result Swal reports successful, skipped, and failed counts.
- Selection clears after processing and Inertia reloads the batch data.
- Existing single-stop controls and delivery-proof workflow remain available.

## Failure handling

- A failure for one stop does not roll back successful stops.
- Failed or stale stops remain unchanged because the existing endpoints validate each transition.
- The result summary reports failures without exposing internal exception details.
- If no selected stop is eligible for an action, the UI does not send requests and explains why.

## Verification

Frontend tests will verify:

- checkboxes and controls appear only for eligible stops in in-progress batches;
- Select all affects only one batch;
- mixed selection sends requests only for the action's eligible status;
- a single Swal confirmation precedes requests;
- partial failures produce the correct result counts;
- buttons disable during processing; and
- selection clears and data reloads after completion.

Existing backend logistics tests remain the regression coverage for authorization, state transitions, timestamps, events, and notifications. Add regression coverage proving a proof-free assigned retail leg in an in-progress batch can be picked up and a batched leg outside `in_progress` cannot be picked up.
