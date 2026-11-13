# Shop-Owned Logistics Dispatcher-First Scheduling

Date: 2026-08-14
Status: Approved design

## Problem

Creating a Shop-owned logistics shipment currently calls `DeliveryScheduleService::estimate()` while the source record is being marked ready or shipped. If coverage, riders, and capacity are available, the shipment leg is immediately written with a delivery date, window, and `schedule_status = scheduled` before a dispatcher reviews it.

The desired policy is dispatcher-first scheduling for normal Shop-owned logistics. Warranty repair recovery is the explicit exception: the customer chooses the date and window because the customer knows their availability.

## Scope

Change the normal source-shipment paths for:

- retail order delivery;
- repair intake pickup;
- repair return delivery; and
- normal retry or reactivation legs created by those paths.

Keep warranty repair recovery customer-scheduled when it supplies an explicit date and window.

## Design

### Normal Shop-owned shipments

Source shipment creation will not call `DeliveryScheduleService::estimate()` to choose a date or window. New normal Shop-owned legs will be created with:

```text
schedule_status = unscheduled
scheduled_delivery_date = null
delivery_window = null
estimated_at = null
```

Existing address snapshots and repair coverage/payment validation remain unchanged. Where coverage is already calculated, its distance may continue to be retained; the calculation must not inspect rider capacity or select a slot.

The shipment will record an internal dispatcher-attention event so the work is visible as an unscheduled delivery. Existing ERP queries already treat any non-`scheduled` leg as unscheduled.

### Dispatcher scheduling

Reuse the existing dispatcher flow. `POST /api/logistics/legs/schedule` already validates the selected date/window and updates eligible pending or assigned legs to `schedule_status = scheduled`. The existing Batches page already collects unscheduled legs and schedules them before creating a delivery batch.

No migration or new endpoint is required.

### Warranty repair recovery exception

When warranty recovery provides a customer-selected date and window, the recovery path will continue to write those explicit values as a scheduled leg. It must bypass automatic estimation so a rider/capacity-based estimate cannot replace or precede the customer's selection.

If no explicit recovery schedule is provided, the leg follows the normal unscheduled path.

### Scheduling service boundaries

`DeliveryScheduleService::estimate()` remains available for shipping estimates, coverage-related flows, and other explicit planning use cases. Its behavior will not be globally changed; the fix is applied at the source-shipment boundaries that currently auto-schedule.

## Likely implementation areas

- `app/Services/Logistics/SourceShipmentService.php`
- `app/Services/RepairDeliveryService.php`
- `tests/Feature/Logistics/SourceModuleShipmentRequestTest.php`
- `tests/Feature/Repair/RepairLogisticsIntakeTest.php`
- `tests/Feature/Repair/RepairLogisticsReturnTest.php`
- warranty recovery tests covering explicit customer-selected scheduling

Frontend changes are not expected because the ERP dispatcher UI already supports an `unscheduled` collection and the existing scheduling API.

## Verification and acceptance criteria

1. A retail order with valid coordinates, riders, and capacity creates an unscheduled Shop-owned leg with no date or window.
2. A normal repair pickup creates an unscheduled leg while retaining its existing coverage validation.
3. A normal repair return creates an unscheduled leg while retaining its existing coverage validation.
4. Normal source creation does not emit a customer-facing scheduled-estimate event.
5. The dispatcher can schedule the resulting legs through the existing endpoint and batch flow.
6. Warranty repair recovery with an explicit customer date/window remains scheduled with exactly those values.
7. Third-party logistics and unrelated retry/exception behavior are unchanged.
8. Regression tests fail before the production change, then pass after it; focused Laravel tests and `git diff --check` pass.

## Non-goals

- Backfilling or changing already-created scheduled legs.
- Changing shipping quotes, coverage rules, rider capacity calculations, or third-party carrier behavior.
- Introducing a new scheduling abstraction or database state.
