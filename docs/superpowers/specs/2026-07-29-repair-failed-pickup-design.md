# Repair Failed Pickup Design

## Problem

A rider collecting a repair item from a customer can currently confirm the
pickup, but cannot close the stop correctly when the customer is unavailable,
the item is not ready, or another pickup problem occurs. The existing failed
attempt workflow is designed for drop-off delivery. Reusing it unchanged would
count the pickup as a failed delivery and could incorrectly trigger
return-to-shop or retail refund behavior even though the item never entered
rider custody.

The failed pickup must therefore be recorded separately, remove only the
affected stop from the rider's active work, and wait for a dispatcher decision.

## Approved Behavior

- Failed Pickup is available only for a `repair_pickup` shipment after the
  rider records arrival and before pickup is confirmed.
- A reason and photo are required for every failed pickup.
- Notes are optional except for `other`, where a short note is required.
- The active assignment ends and the failed stop leaves its batch.
- Other stops in the batch remain active so the rider can continue.
- Before the configured maximum pickup attempts is reached, the leg becomes
  `needs_resolution`; it is not immediately reassigned.
- Before the maximum is reached, the dispatcher chooses either Reschedule
  Pickup or Cancel Pickup.
- Reschedule Pickup returns the leg to the assignment pool for the next
  operating day only while an attempt remains.
- The final allowed failed pickup is terminal: cancel the leg, shipment, and
  repair request, then create one full refund request for the repair's remaining
  paid amount.
- A terminal no-charge warranty pickup is cancelled without creating a refund.
- Cancel Pickup uses the existing repair cancellation and compensation rules.
- The customer sees a safe reason label, date/time, and photo proof. Internal
  notes are hidden.
- Pickup attempts remain separate from delivery attempts but use the configured
  `max_delivery_attempts` value as their own retry limit.
- A failed pickup never increments delivery-attempt counters or creates a
  return-to-shop leg.

## Reasons

Use pickup-specific reason codes and rider-friendly labels:

| Code | Rider and customer label |
| --- | --- |
| `customer_unavailable` | Customer unavailable / not home |
| `customer_requested_reschedule` | Customer requested reschedule |
| `customer_refused_pickup` | Customer refused pickup |
| `item_not_ready` | Item not ready or unavailable |
| `wrong_address_or_pin` | Wrong address or map pin |
| `unsafe_or_inaccessible_location` | Unsafe or inaccessible location |
| `vehicle_or_rider_problem` | Vehicle or rider problem |
| `other` | Other |

The server owns this allowlist. All reasons require an image of at most 10 MB.
`other` also requires notes. Internal notes are never serialized into
customer-facing payloads.

## Domain Design

Reuse the existing `DeliveryAttempt` model and proof storage:

- `attempt_type = pickup`
- `status = failed`
- `attempt_number` equals prior failed pickup attempts on the leg plus the
  current failed attempt
- `reason_code`, `notes`, `file_path`, assignment, batch, actor, and timestamp
  use the existing fields

No database migration or new failed-pickup model is required.

The pickup-specific failed-attempt branch must:

1. Lock the leg, active assignment, and batch in one transaction.
2. Recheck that the shipment purpose is `repair_pickup`.
3. Recheck that the leg is `assigned` or `pickup_scheduled`.
4. Recheck that a pickup-arrival event exists.
5. Reject the action when pickup has already been confirmed.
6. Store the `pickup` attempt with the submitted idempotency key.
7. Set the new attempt number to prior failed `pickup` attempts plus one. The
   attempt is terminal when that number is greater than or equal to the shop's
   configured maximum.
8. Before the maximum, set the leg to `needs_resolution`, set `failed_at`, and
   mark the resolution as a failed pickup.
9. At the maximum, cancel the leg and shipment, cancel the repair request, and
   create one full refund request for the remaining paid repair amount through
   the existing repair refund workflow. No-charge warranty pickups skip refund
   creation.
10. Cancel the active assignment and detach the leg from its batch.
11. Keep the shipment active only while it awaits dispatcher action; terminal
    pickups remain cancelled.
12. Record customer-visible and internal delivery events.

If the batch still has stops, its existing progress continues. If no stops
remain, use the existing all-stops-removed batch closing rule with a failed
pickup reason.

The generic failed-delivery branch remains unchanged. Pickup attempts do not
increment its counters, create a return leg, or use the retail order refund
service. They use the same configured maximum solely as a pickup retry limit
and use the existing repair refund service for paid repairs.

## Rider Experience

After a repair pickup arrival is recorded, show:

1. Primary button: **Confirm pickup**
2. Secondary outlined button: **Failed pickup**

Failed Pickup opens a compact inline panel or bottom sheet containing:

- reason selector;
- required image input using the device camera when available;
- notes, marked required only for Other;
- **Submit failed pickup** button.

Submitting opens the existing confirmation dialog. While the request is
pending, all mutation buttons are disabled. The idempotency key remains stable
for retries of the same submission. On success, the page reloads and advances
the rider to the next stop.

When offline, submission is disabled and the form remains populated in the
current page session with the message **Retry after reconnect**. Offline queue
storage is not added in this phase.

## Dispatcher Experience

Add a **Failed Pickups** or **Needs Action** filter to the dispatcher shipment
workspace. A failed pickup card displays:

- repair request and shipment identifiers;
- customer name and pickup address;
- rider;
- reason;
- attempt date/time;
- arrival verification result;
- photo proof;
- Reschedule Pickup and Cancel Pickup actions while pickup attempts remain.

**Reschedule Pickup** requires confirmation and a dispatcher note, then:

- changes the leg from `needs_resolution` to `pending`;
- schedules the next valid operating day;
- records a customer-visible reschedule event;
- leaves the leg unassigned for normal dispatcher assignment.

Reschedule Pickup is unavailable when the pickup-attempt limit has been
reached. The server rejects stale retry or assignment requests even if an old
dispatcher page still shows an action.

After the final attempt, the card shows **Pickup cancelled · Refund requested**
for paid repairs or **Pickup cancelled** for no-charge warranty work. The
existing repair refund approval and payout workflow handles the actual money
movement; the final pickup action does not call a payment gateway directly.

Existing schedule controls can adjust the date or window afterward. No new
date-picker workflow is introduced.

**Cancel Pickup** requires a cancellation reason and uses the existing repair
delivery cancellation/compensation service. Its pre-custody guard must accept a
non-terminal failed pickup in `needs_resolution`, while post-pickup
cancellation protections remain unchanged.

## Customer Experience

The repair tracking timeline shows **Pickup attempt unsuccessful** with the
safe reason and timestamp. **View pickup proof** opens the original photo
through the existing authorized tracking proof endpoint.

The endpoint must verify that the signed-in customer owns the repair shipment.
It must not expose the storage path, actor identifiers, raw internal notes,
resolution notes, or dispatcher-only metadata.

After dispatcher action, the timeline adds either:

- **Pickup rescheduled**, including the new schedule; or
- **Pickup cancelled**, using the existing customer-safe compensation and
  cancellation messaging.

After the final allowed failure, the timeline states that pickup was cancelled
and, for paid repairs, that a full refund was requested. Existing repair refund
status messaging communicates approval and payout progress.

## API and Validation

Extend the existing multipart failed-attempt request rather than creating a
parallel persistence module. The request identifies `attempt_type = pickup`
and includes:

- active `delivery_assignment_id`;
- stable UUID `idempotency_key`;
- approved `reason_code`;
- required `proof_file`;
- conditional `notes`.

Authorization requires the rider who owns the active assignment. Cross-rider
and cross-shop requests return `403`. Missing evidence, invalid reasons,
missing arrival, stale state, or already-confirmed pickup return `422` with
plain-language field errors.

Photo cleanup follows the existing failed-delivery behavior: delete a newly
stored file when the transaction fails or an idempotent replay returns an
attempt that references a different file.

## Status and Event Labels

Use rider-friendly UI labels without changing the existing enum:

- `needs_resolution` plus pickup attempt → **Failed pickup · Needs action**
- rescheduled `pending` plus latest pickup attempt → **Pickup rescheduled**
- cancelled repair pickup → **Pickup cancelled**

Do not rely on color alone. Each state includes text and an icon. Customer
events use neutral language and never claim the customer caused the failure
when the reason was operational or safety-related.

## Error Handling

- A duplicate request returns the original attempt without another event,
  assignment cancellation, batch mutation, repair cancellation, or refund.
- A stale page receives a validation message and reloads current delivery data.
- An upload or network failure keeps the rider form visible for retry.
- A dispatcher race between reschedule and cancel resolves through row locking;
  the losing request returns the current state without partial mutation.
- A retry or assignment request after the configured maximum is rejected by the
  backend even when submitted from a stale page.
- A non-terminal failed pickup cannot be marked picked up until it is
  rescheduled and assigned again. A terminal failed pickup cannot be reopened.

## Accessibility

- Buttons and inputs have at least 44-by-44-pixel touch targets.
- Reason, image, and note requirements are included in visible labels and
  programmatic error text.
- Statuses use text and icons in addition to color.
- The failed-pickup panel receives focus when opened, validation errors are
  announced, and the result message uses an `aria-live` region.
- Camera upload also permits choosing an existing image for device
  compatibility.

## Testing

Backend coverage:

- successful standalone repair failed pickup;
- failed stop removal while another batch stop remains active;
- rejection before arrival, after pickup, for non-repair pickup purposes, and
  for another rider or shop;
- required reason, image, and Other notes validation;
- idempotent duplicate submission;
- pickup attempt counts remain separate from delivery attempts;
- a non-final pickup remains reschedulable;
- the final pickup cancels the leg, shipment, and repair request;
- the final paid pickup creates one full repair refund request for the remaining
  paid amount;
- duplicate final submissions do not duplicate refunds;
- a final no-charge warranty pickup creates no refund;
- stale retry and rider assignment requests are rejected after the limit;
- no return leg or retail failed-delivery refund is created;
- dispatcher reschedule and cancellation/compensation;
- authorized customer proof access and sanitized tracking payload.

Frontend coverage:

- Failed Pickup appears only at the approved repair pickup stage;
- required form fields and confirmation;
- duplicate button taps are blocked;
- offline retry state preserves the current form;
- successful batch failure advances to the next stop;
- dispatcher card and both resolution actions;
- customer timeline label and proof link.

Run focused tests first, then the complete Logistics feature suite, relevant
frontend test files, and the production build.

## Deployment

No migration is required. Deploy source and a fresh `public/build`, clear
application and CDN caches, then smoke-test one standalone repair pickup and
one repair pickup inside a batch.
