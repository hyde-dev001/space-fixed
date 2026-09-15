# Logistics Batches Table and Modal Design

## Goal

Improve the ERP Logistics Batches page so active batches are easier to scan, batch stop details no longer expand inline, and history is accessible through a modal without changing existing batch operations.

## Approved interaction model

- The `Active batches` section uses a responsive table for quick comparison.
- Each active-batch row keeps its existing primary operational action:
  - `Edit batch` continues opening the existing batch workspace for drafts.
  - `View offer`, `View route`, and `View progress` continue opening the existing batch workspace.
- A separate row details action opens a batch-details modal containing the batch summary and stop rows. This replaces the current inline expand/dropdown interaction.
- The existing secondary actions (`Review & Offer`, `Cancel batch`, and `Restore to draft`) remain available through the current action affordance and keep their existing behavior.
- `History (count)` becomes a button placed beside the `All` active-status filter. It opens a history modal containing the completed/cancelled batches.
- History rows use the same batch-details modal for stop details and retain restore behavior for cancelled batches.
- The delivery-proof viewer close icon is black, while its existing keyboard focus, Escape handling, and focus restoration remain intact.

## Visual and accessibility behavior

- Use the existing `Modal` component and Tailwind conventions; do not add dependencies.
- Active batches are represented as table rows on desktop and a readable stacked layout on narrow screens, with no horizontal page overflow.
- Table headers identify Batch, Status, Schedule, Rider, Stops, and Actions.
- Action buttons have accessible names, visible focus states, and at least 44px interactive targets.
- Batch-details and history modals have labelled dialogs, a close button, Escape/backdrop close behavior, and focus restoration to the triggering button.
- The details modal keeps stop information readable and preserves existing urgent-stop behavior.
- The modal content is scrollable when the stop list exceeds the viewport.

## Data and behavior boundaries

- No backend routes, controllers, database queries, or API contracts change.
- Existing batch mutation handlers remain the source of truth for edit, offer, cancel, restore, reorder, and urgent-stop actions.
- The table and modal consume the existing `DeliveryBatch` and `TrackingShipmentLeg` data.
- The current `BatchCard` inline expansion is removed or bypassed only for this page flow; no unrelated logistics pages are changed.

## Testing

- Add regression coverage for the active-batch table and its primary actions.
- Verify the batch details action opens a labelled modal and no longer renders expanded stops inline.
- Verify history is a button beside the `All` filter, opens a labelled modal, and displays history batches.
- Verify history batch details use the modal and cancelled-batch restore still calls the existing handler.
- Verify keyboard close and focus restoration for the new modals.
- Run the focused Batches tests, the full frontend suite, and a fresh production build.
