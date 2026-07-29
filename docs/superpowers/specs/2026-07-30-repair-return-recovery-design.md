# Repair Return Recovery Design

## Goal

When delivery of repaired shoes reaches the maximum delivery attempts, return the item to the shop without refunding the repair or the consumed delivery fee. After shop receipt, show **Returned to shop—awaiting customer arrangement** and let staff choose exactly one recovery path:

1. **Schedule re-delivery** — the customer confirms the destination and pays a new delivery fee before a new shipment can be dispatched.
2. **Set for shop pickup** — the customer collects the repaired shoes from the shop without another delivery fee.

Also hide the return-handoff panel when an intake pickup was cancelled before the shop ever received the shoes.

## Scope

This design covers repair and warranty jobs that use shop-owned logistics for the repaired-item return leg. It does not change retail-order refund behavior, repair-pickup exhaustion behavior, delivery attempt limits, or normal successful repair handoff.

The customer pays every replacement shop-delivery fee, including for warranty jobs. Shop pickup remains free.

## State Model

No new global `repair_requests.status` value is introduced. The database continues to use `ready_for_pickup` after the returned item is physically received by the shop.

The API derives an active return-recovery state only when all of these are true:

- Shipment source is `repair_request`.
- Shipment purpose is `repair_return`.
- The original customer-delivery leg is `cancelled` with resolution `returned`.
- Its `return_to_shop` leg is `delivered`.
- The return receipt proof is approved.
- No newer replacement customer-delivery leg has started.
- The repair is not already released to the customer.

The derived state is exposed as:

```text
code: returned_to_shop_awaiting_arrangement
label: Returned to shop—awaiting customer arrangement
```

This keeps reports and existing repair status tabs compatible while giving the UI an unambiguous operational state.

## Return-to-Shop Completion

When the shop confirms receipt of the failed repaired-item delivery:

- Mark the return-to-shop leg delivered.
- Mark the original customer-delivery leg cancelled with resolution `returned`.
- Cancel the exhausted shipment.
- Set the repair to `ready_for_pickup`.
- Clear `shipped_at`, customer receipt activation, and the old return logistics lock.
- Preserve repair charges and all prior payments.
- Do not create a refund or delivery-fee compensation.
- Preserve the latest return address as a convenience, but require a fresh customer confirmation before re-delivery.
- Notify the customer that the shoes are back at the shop and that the shop will arrange re-delivery or pickup.

The transition is transactionally idempotent. Replaying shop receipt does not create another recovery record or repeat financial changes.

## Staff Actions

The repair job-order return section replaces normal handoff controls with the recovery message and exactly two actions while recovery is active.

### Schedule re-delivery

This action:

- Is available to the same authorized shop staff who manage repair handoff.
- Keeps the repair at `ready_for_pickup`.
- Selects `shop_delivery` as the intended return method.
- Reopens the return address plan for customer confirmation.
- Creates one active re-delivery payment requirement identified by the completed return-to-shop leg.
- Notifies the customer to confirm the address and pay the new delivery fee.
- Does not create a shipment or expose work to the dispatcher yet.

The customer may update the saved return address before payment. Coverage and the delivery quote are recalculated through the existing shop-owned delivery quote logic.

After a matching re-delivery payment is settled:

- Lock the confirmed return plan.
- Append a fresh customer-delivery leg to the existing repair-return shipment history.
- Make that new leg available for dispatcher assignment.
- Clear the active recovery state.

Repeated action requests for the same recovery key return the existing requirement and do not create duplicate charges or shipment legs.

### Set for shop pickup

This action:

- Sets the return method to `walk_in`.
- Clears the old shop-delivery lock, confirmed address markers, and pending re-delivery payment requirement.
- Keeps the repair at `ready_for_pickup`.
- Enables the existing staff-release then customer-confirmation handoff flow.
- Notifies the customer that the repaired shoes are ready for collection.

Any pending, unpaid re-delivery checkout session for the same recovery is invalidated. A paid re-delivery session cannot be switched to shop pickup through this action; staff must use the existing payment reconciliation process instead of silently discarding a payment.

## Re-delivery Payment

Re-delivery uses the existing `repair_payment_sessions` table with a distinct `redelivery` phase. No repair service balance is charged again.

The session records:

- `phase = redelivery`
- `service_amount = 0`
- the newly quoted `delivery_amount`
- the confirmed address snapshot version
- `delivery_method = shop_delivery`
- the recovery key in the session quote metadata

The payment endpoint treats an active re-delivery requirement as payable even when the repair's normal payment status is already `completed`. Settlement increases `total_paid_amount` only by the new delivery charge, preserves the completed repair-payment state, locks the new return plan, marks the recovery requirement paid, and creates the next return-delivery leg.

Webhook replay, duplicate checkout creation, stale address versions, stale recovery keys, or prior final-payment sessions cannot settle the new delivery accidentally.

## UI Behavior

### Staff job order

While recovery is active, show:

- Status: **Returned to shop—awaiting customer arrangement**
- Supporting text: the repaired shoes are held safely at the shop and the repair itself is not being refunded.
- **Schedule re-delivery**
- **Set for shop pickup**

Hide proof approval, delivered-handoff, and other normal return actions in this state.

### Customer repair page

Before staff chooses:

- Show that the shoes were returned to the shop.
- Explain that the shop will arrange re-delivery or pickup.
- Do not show a refund state.

After **Schedule re-delivery**:

- Show the reopened return-delivery plan.
- Require fresh address confirmation.
- Show **Pay new delivery fee** with the current quote.
- Do not show rider tracking until payment creates a new shipment leg.

After **Set for shop pickup**:

- Show **Ready for pickup at shop**.
- Show shop details and the existing customer receipt-confirmation flow.

### Premature return-handoff visibility

The return-handoff panel is hidden when intake pickup was cancelled before an approved intake handoff. This includes terminal pickup-attempt exhaustion and manual cancellation before shop receipt.

It remains visible after successful intake and during normal repaired-item return processing.

## Authorization and Validation

- Staff recovery actions must belong to the same shop as the repair.
- Recovery actions reject repairs that are not in the derived recovery state.
- New re-delivery requires a covered, version-matched customer address and a current quote.
- Dispatcher assignment remains impossible until the replacement delivery payment is settled and a new pending leg exists.
- Shop pickup cannot silently override a paid re-delivery requirement.
- Neither recovery action creates a repair refund.

## Notifications

Use existing repair-status notification infrastructure for:

- Return received by shop.
- Customer action required for new delivery address and fee.
- Repair ready for shop pickup.
- Re-delivery payment settled and shipment ready for dispatch.

Notifications link to the existing customer repair detail page.

## Testing

Backend feature tests must prove:

- A failed repaired-item delivery returns to the shop without creating any repair refund.
- Shop receipt produces the derived recovery state and changes the repair to `ready_for_pickup`.
- Schedule re-delivery is idempotent and creates no shipment before payment.
- A `redelivery` payment charges only the new delivery fee and appends exactly one fresh delivery leg.
- Set for shop pickup invalidates an unpaid re-delivery session and enables the existing walk-in handoff.
- A paid re-delivery requirement cannot be switched to shop pickup.
- Unauthorized, cross-shop, stale, and replayed actions are rejected or safely replayed.
- Warranty return recovery follows the same customer-paid re-delivery rule.

Frontend tests must prove:

- Staff sees the recovery label and only the two allowed actions.
- Customer sees address/payment controls only after re-delivery is selected.
- Customer sees pickup instructions after shop pickup is selected.
- Return handoff is hidden for cancelled-before-intake repairs.
- Normal successful intake and normal repair return screens remain unchanged.

## Out of Scope

- Automatically contacting the customer outside existing notifications.
- A shop-paid re-delivery option.
- More than the two approved recovery actions.
- Refunding the consumed repair-return delivery fee.
- Adding a new global repair status or a new reporting category.
