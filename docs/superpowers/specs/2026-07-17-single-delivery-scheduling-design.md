# Single-delivery scheduling and failed-attempt gating

## Problem

The ERP Shipments page supports assigning one delivery directly to a rider but has no scheduling controls. This allows a delivery to become assigned while its customer-facing estimated delivery date remains "Not scheduled yet". The rider view also exposes the failed-delivery form while a leg is only assigned or picked up, before delivery has actually started.

## Design

Reuse the existing delivery scheduling endpoint and `BatchDispatchService::schedule`; do not add a second scheduler or database fields.

For each non-batch leg in `pending` or `assigned` status that is not scheduled, show native delivery-date and morning/afternoon controls on the ERP Shipments page. A new unassigned leg uses one `Schedule & assign rider` action: schedule first, then assign the selected rider. This ordering prevents creation of another assigned-but-unscheduled delivery. An already-assigned leg shows `Save schedule` so existing affected orders can be repaired. Successful scheduling reloads the shipment data, which already supplies the saved date and window to customer order tracking.

The scheduling service will accept an unscheduled leg in either `pending` or `assigned` status, while retaining tenant, non-batch, operating-day, blackout-date, and duplicate-schedule checks. Past dates remain rejected by the existing request validation.

The rider page will show the failed-delivery form only for `in_transit` and `delivery_attempted` legs. The report-issue controller will reject other statuses before storing a photo, then the locked service operation will enforce the same status set before creating an attempt. If the locked operation fails after upload, the controller deletes the newly stored photo before returning the error. Lower-level batch execution behavior remains unchanged because current in-progress batch tests explicitly opt into its broader status handling.

## Error handling

Existing API validation messages are shown through the current toast and inline error UI. If scheduling succeeds but rider assignment fails, the page reloads shipment data so the leg becomes scheduled and the retry calls assignment only. Invalid or stale status changes return HTTP 422, create no failed attempt, and leave no newly uploaded photo.

## Tests

- Frontend: an eligible unscheduled single leg renders date/window controls; scheduling happens before assignment; an assigned unscheduled leg can save a schedule; partial success reloads into an assign-only retry state.
- Frontend: failed-attempt controls are absent for assigned and picked-up legs and present for in-transit legs.
- Backend: scheduling accepts an assigned unscheduled leg; rejects cross-tenant, unauthorized, batched, already-scheduled, and invalid-status legs.
- Backend: the rider report endpoint rejects assigned and picked-up legs without creating an attempt or stored photo, rejects cross-tenant riders and riders without an active assignment, and accepts in-transit and delivery-attempted legs.
- Backend: a storage-fake race test changes the leg after the pre-upload check, forces the locked service operation to return 422, and verifies the newly uploaded photo is deleted.

## Non-goals

No batch workflow redesign, arbitrary time picker, automatic route planning, schema change, or customer-page redesign.
