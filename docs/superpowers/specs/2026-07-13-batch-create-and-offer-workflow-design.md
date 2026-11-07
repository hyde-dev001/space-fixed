# Batch Create and Offer Workflow Design

## Goal

Make batch dispatch a single, clear dispatcher action while preserving the existing scheduling, capacity, blackout-date, and rider-availability rules.

## Chosen approach

Combine the existing frontend actions without adding a new backend endpoint. The page will call the current APIs in sequence: schedule selected deliveries, create the draft batch, then offer it when a rider was selected. If no rider is selected, the workflow stops at a draft batch that can be assigned later.

This keeps the change small and reuses the backend rules that already protect each transition.

## Dispatcher experience

Replace the separate **Unscheduled deliveries** and **Dispatch pool** controls with one **Create delivery batch** form:

- Select one or more unscheduled deliveries, including a Select all control.
- Choose a delivery date and Morning or Afternoon window.
- Optionally choose an active, available rider.
- Show the selected stop count and the selected rider's capacity.
- Label the primary action **Create & offer batch** when a rider is selected, or **Create draft batch** when no rider is selected.
- Disable the action while required fields are missing or while the request is running.
- Show readable dates and clear empty states.

Existing scheduled, unbatched deliveries remain usable in the same form. After a date and window are chosen, only scheduled deliveries that match both values are selectable. Changing either value clears any scheduled selections that no longer match. Select all covers unscheduled deliveries plus matching scheduled deliveries only.

## Data flow

For the selected deliveries:

1. Partition the selection into unscheduled and already scheduled deliveries.
2. Call the existing schedule API for the unscheduled subset only.
3. Call the existing create-batch API with the full selection, date, and window.
4. If a rider was selected, call the existing offer-batch API using the created batch ID.
5. Reload the page after the final successful step.

If the selection contains no unscheduled deliveries, skip step 2. If no rider was selected, stop after batch creation and leave the batch in `draft` status.

## Failure handling

Keep the completed backend transition instead of attempting a client-side rollback. After scheduling succeeds, the client records those IDs as scheduled for the current attempt. If batch creation fails, the date, window, rider, and selection remain in place; retry skips scheduling and starts at batch creation. A browser refresh is also safe because those deliveries return as scheduled pool items.

If batch creation succeeds but offering fails, reload the batch list and display: **Draft batch created, but the rider offer failed. Assign a rider from the draft batch below.** The new draft's existing assignment controls provide the recovery action.

Validation errors should display the server-provided message when available. Generic stale-data failures retain a refresh-and-retry message. Buttons remain disabled during execution to prevent duplicate submissions.

## Existing batches

Draft batches keep their current rider assignment controls so a dispatcher can offer a draft later. Offered, accepted, in-progress, completed, and cancelled batches are read-only for assignment.

## Testing

Frontend tests will verify:

- selected unscheduled deliveries run schedule, create, and offer in order;
- no rider creates a draft and does not call the offer API;
- mixed selections schedule only the unscheduled subset and create from the full selection;
- scheduled deliveries with a different date or window are not selectable;
- the action is disabled while incomplete or submitting;
- Select all affects only eligible deliveries and the selected count/capacity indicator is correct;
- readable dates and empty states are displayed;
- server validation errors and generic errors are understandable;
- retry after schedule-success/create-failure does not schedule twice;
- a failed offer reloads and leaves a recoverable draft-state message.

Existing backend tests continue to cover scheduling eligibility, batch creation, capacity, and rider availability.

## Out of scope

- A new combined backend endpoint or cross-request transaction.
- Automatic rider selection.
- Reassigning a batch after it has been offered or started.
- Route optimization changes.
