# Delivery Batch Dispatcher Workspace Design

**Date:** 2026-07-15  
**Status:** Approved  
**Scope:** Delivery batch dispatcher workflow and batch-list UI/QOL

## Goal

Replace the current text-heavy delivery batch page with a responsive dispatcher workspace that makes it easy to create a draft batch, select and order stops, review the route, offer it to a rider, and monitor active or historical batches.

Coverage-radius behavior, coordinate configuration, Leaflet address selection, and route optimization are intentionally out of scope.

## Approved Direction

Use a focused two-column master-detail workspace on the existing delivery batches route.

- Left: searchable and filterable available-delivery pool.
- Right: new batch builder or selected batch details.
- Below: filterable active batch cards and a collapsed history section.
- Desktop uses two columns; tablet and mobile stack the delivery pool above the workspace.

The implementation will reuse the existing APIs and installed `react-dnd`, `sweetalert2`, and `lucide-react` packages. It will not add a global state library or another UI dependency.

## Page Structure

The page header contains only the title and a primary **New Batch** button. Search and status controls live beside the content they filter; they are not duplicated in the page header.

The main workspace contains:

1. **Available Deliveries panel**
   - Search that filters available deliveries by order/source reference, customer, phone, or address.
   - Delivery date filter.
   - Delivery window filter.
   - Scheduling/status filter.
   - Select individual deliveries or select all eligible matches.
   - Rich delivery rows showing reference, customer, address, schedule, status, and urgency.

2. **Batch Workspace panel**
   - Empty guidance when no batch is selected.
   - Draft builder for a new or existing draft.
   - Read-only detail view for offered, accepted, and in-progress batches.
   - Ordered stop list.
   - Sticky actions on smaller screens.

3. **Batch collection**
   - Active status tabs that filter only the batch collection: All, Draft, Offered, Accepted, In Progress.
   - Collapsible batch cards.
   - Completed and cancelled batches in a collapsed **History** section.

## Draft-First Creation Flow

1. The dispatcher clicks **New Batch**.
2. The dispatcher selects a delivery date and window.
3. The delivery pool displays matching eligible deliveries.
4. Selecting a delivery immediately adds it to the local, unsaved ordered stop list.
5. The dispatcher locally reorders or removes selected stops and may immediately toggle a stop's urgency.
6. **Save Draft** creates the batch without offering it.
7. Once saved, **Review & Offer** becomes available.

Direct create-and-offer is removed from the primary flow. A durable draft is always created first so an offer failure cannot lose the selected stops or their order.

For a new batch, all selection and ordering remain local until **Save Draft**. Saving uses the existing two-request sequence:

1. Partition the selected deliveries into already scheduled stops and stops that still require scheduling.
2. Schedule only the unscheduled subset for the selected date and window.
3. Create the draft with the complete ordered stop ID list.

The UI records which stops were scheduled during the current save attempt. If scheduling succeeds but draft creation fails, it retains the local selection and order, shows an actionable error, and skips the scheduling request for those same stops on retry. After a full page refresh, the server-provided pool is authoritative: those stops return as scheduled for the chosen date/window and can be selected for draft creation without another scheduling request.

Urgency is a leg-level property, not a draft property, so an urgent toggle persists immediately through the existing urgent endpoint even while the new batch selection and order are still unsaved. The badge changes only after a successful response; on failure, the server state remains displayed and an inline error is shown. Draft creation therefore does not need to carry or replay urgency state.

Once a draft exists, supported mutations persist immediately through their existing APIs: reorder, remove, and urgent toggle. Adding new stops to an existing draft is not part of this design because the current batch update API only accepts stops already belonging to that batch. The dispatcher may create a new batch for additional unbatched deliveries.

## Stop Rows and Management

Each stop row shows:

- Stop sequence.
- Order or source reference.
- Customer name and phone.
- Full address.
- Delivery schedule and status.
- Urgent badge.
- Contextual actions.

### Reordering

Draft stops support drag reordering with `react-dnd`. Every draggable row also provides Up and Down controls so keyboard and assistive-technology users can reorder without dragging.

Reordering remains draft-only and persists through the existing batch update API.

### Removing

Removing a draft stop opens a SweetAlert confirmation containing the stop number and customer. Removing the final stop displays a stronger warning that the empty batch will be deleted.

### Urgency

Urgency is a reversible toggle with a visible red **Urgent** badge. It remains available for draft, offered, accepted, and in-progress stops, but not after a stop is delivered or cancelled. The server must enforce the same terminal-status restriction.

## Existing Batch Cards

Every batch card shows:

- Batch number.
- Delivery date and window.
- Rider or **Not assigned**.
- Status badge.
- Stop count and capacity.
- Completion progress.
- Urgent-stop count.

Cards are collapsed by default. The chevron only expands or collapses the inline stop summary. The explicit **Edit batch**, **View offer**, **View route**, **View progress**, or **View summary** primary action loads that batch into the right workspace. Expansion does not change the selected workspace batch.

Actions are status-aware:

| Status | Primary action | Secondary actions |
| --- | --- | --- |
| Draft | Edit batch | Review & Offer, Cancel |
| Offered | View offer | Toggle urgent, Cancel |
| Accepted | View route | Toggle urgent, Cancel |
| In Progress | View progress | Toggle urgent |
| Completed | View summary | None |
| Cancelled | View summary | None |

Only the primary action is shown as a full button. Secondary actions use a labelled three-dot menu.

Draft stops remain editable. Offered, accepted, and in-progress stop order and membership are read-only. Urgency remains editable until terminal stop status. Completed and cancelled batches are read-only.

## Review and Offer

**Review & Offer** opens a modal containing:

- Rider selector.
- Rider availability.
- Rider capacity.
- Delivery date and window.
- Ordered stop list.
- Stop count.
- Urgent-stop count.
- Capacity warning when relevant.

The dispatcher cannot confirm until a rider is selected. The final action is labelled **Offer Batch to Rider**.

On success:

- The batch becomes offered.
- The workspace becomes read-only except for allowed urgency and cancellation actions.
- A non-blocking success toast appears.
- The batch moves from Draft to Offered.
- The rider receives one **Delivery Batch Offered** notification for the batch.

The existing per-stop **Delivery Assigned** notifications are replaced for the batch-offer path. Individual non-batch assignment behavior remains unchanged.

If the offer fails, the saved draft remains intact and the modal displays the server validation error without losing stop order.

## Feedback and Confirmations

SweetAlert2 is used only for consequential actions:

- Remove stop.
- Remove final stop and delete the empty batch.
- Cancel batch.
- Final offer confirmation.

Successful mutations use non-blocking toasts:

- Draft saved.
- Stop removed.
- Stop order updated.
- Urgent state updated.
- Batch offered.
- Batch cancelled.

Buttons show local loading states and remain disabled while their request is in flight. Errors appear in the relevant panel. Safe retry actions are offered when possible, while stale-data errors prompt an Inertia refresh.

Filtering, selecting deliveries, expanding cards, and local reordering do not trigger alerts.

## Empty, Loading, and Responsive States

The delivery pool provides:

- Skeleton rows during loading or refresh.
- **No deliveries match these filters** with a clear-filters action.
- **All eligible deliveries are already batched** when appropriate.

The workspace provides:

- **Select a batch or create a new one** when idle.
- Guidance when a draft has no stops.
- A no-available-riders warning.
- A visible capacity warning that does not prevent saving a draft.

Desktop uses two columns. Tablet and mobile stack the delivery pool above the workspace, use touch-friendly controls, wrap stop details without horizontal scrolling, and keep the builder action bar visible.

## Component Boundaries

Use a small set of focused components:

- `Batches.tsx`: page orchestration, filters, selected batch, API calls, and Inertia refresh.
- `AvailableDeliveriesPanel`: filters and selectable delivery rows.
- `BatchWorkspace`: draft builder or selected batch details.
- `BatchCard`: active/history batch summary and status-aware actions.
- `BatchStopRow`: shared rich stop display and contextual actions.
- `OfferBatchModal`: rider selection, capacity summary, ordered-stop review, and confirmation.

No global store is required. Server-provided Inertia props remain authoritative. Local state holds only transient filters, draft selection/order, expanded cards, and in-flight action state.

After a successful mutation, the page uses the existing Inertia reload flow while preserving only relevant selection. Server errors are normalized through one existing or shared feedback helper rather than duplicated per component.

## Backend Adjustments

Only backend adjustments required by the approved workflow are included:

- Preserve draft-only reorder and remove validation.
- Reject urgent changes for delivered or cancelled stops.
- Return actionable validation messages for stale actions.
- Create one batch-offered notification for the rider.
- Avoid per-stop assigned notifications during the batch-offer path without changing individual assignment notifications.

No scheduling, radius, coordinate, map, or route-optimization behavior changes are included.

## Accessibility and Safety

- Icon-only controls have accessible names and tooltips.
- Drag reordering always includes Up and Down fallbacks.
- Modal focus is trapped and returns to its trigger.
- Status and urgency communicate through text and icons as well as color.
- Destructive actions require confirmation.
- All action buttons prevent duplicate submission.
- Server authorization, tenant isolation, and validation remain authoritative.
- Touch targets are sized for mobile and tablet use.

## Testing Strategy

Frontend tests cover:

- Searching, filtering, and selecting deliveries.
- Draft-first creation.
- Stop drag reorder and Up/Down fallback.
- Remove confirmation and final-stop warning.
- Urgent toggle and badge.
- Offer review modal and rider requirement.
- Rider capacity and warning display.
- Active status tabs and collapsed history.
- Loading, empty, retry, and API error states.
- Status-specific action visibility.
- Responsive stacking-critical class or structure behavior where practical.

Backend tests cover:

- Draft-only reorder and removal.
- Terminal-status urgent restriction.
- Offer failure preserving the draft and stop order.
- One rider notification per offered batch.
- Individual assignment notifications remaining unchanged.
- Existing permissions and tenant isolation.

Focused tests run first, followed by the relevant logistics feature suite and frontend build/type checks.

## Success Criteria

- A dispatcher can create, review, and offer a batch without guessing which action comes next.
- Stop details and controls are visible and understandable without relying on raw leg IDs.
- Draft changes cannot accidentally affect offered or active routes.
- Destructive actions are confirmed and successful actions receive immediate feedback.
- Active and historical batches are easy to scan and filter.
- The workflow works with mouse, keyboard, touch, and responsive layouts.
- No coverage/settings or coordinate behavior changes as part of this work.
