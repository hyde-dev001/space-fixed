# Single-delivery scheduling and failed-attempt gating

## Problem

The ERP Shipments page supports assigning one delivery directly to a rider but has no scheduling controls. This allows a delivery to become assigned while its customer-facing estimated delivery date remains "Not scheduled yet". The rider view also exposes the failed-delivery form while a leg is only assigned or picked up, before delivery has actually started.

## Design

Reuse the existing delivery scheduling endpoint and `BatchDispatchService::schedule`; do not add a second scheduler or database fields.

For each non-batch leg on the ERP Shipments page that is not scheduled, show native delivery-date and morning/afternoon controls. A new unassigned leg uses one `Schedule & assign rider` action: schedule first, then assign the selected rider. This ordering prevents creation of another assigned-but-unscheduled delivery. An already-assigned leg shows `Save schedule` so existing affected orders can be repaired. Successful scheduling reloads the shipment data, which already supplies the saved date and window to customer order tracking.

The scheduling service will accept an unscheduled leg in either `pending` or `assigned` status, while retaining tenant, non-batch, operating-day, blackout-date, and duplicate-schedule checks. Past dates remain rejected by the existing request validation.

The rider page will show the failed-delivery form only for `in_transit` and `delivery_attempted` legs. The report-issue controller will enforce the same rule before accepting a photo or creating an attempt. Lower-level batch execution behavior remains unchanged because current in-progress batch tests use it directly.

## Error handling

Existing API validation messages are shown through the current toast and inline error UI. If scheduling succeeds but rider assignment fails, the delivery remains safely scheduled and the dispatcher can retry assignment without re-entering the date. Invalid or stale status changes return HTTP 422 and create no failed attempt.

## Tests

- Frontend: an unscheduled single leg renders date/window controls; scheduling happens before assignment; an assigned unscheduled leg can save a schedule.
- Frontend: failed-attempt controls are absent for assigned and picked-up legs and present for in-transit legs.
- Backend: scheduling accepts an assigned unscheduled leg but still rejects ineligible legs.
- Backend: the rider report endpoint rejects assigned and picked-up legs and accepts an in-transit leg.

## Non-goals

No batch workflow redesign, arbitrary time picker, automatic route planning, schema change, or customer-page redesign.
