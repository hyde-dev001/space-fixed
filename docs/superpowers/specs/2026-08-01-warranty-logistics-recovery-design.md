# Warranty Logistics Recovery Design

**Date:** 2026-08-01
**Branch:** `fix/warranty-logistics-recovery`
**Base:** `origin/solespace-b`

## Problem

Warranty repair logistics becomes a dead end in two cases:

1. A terminal failed warranty pickup cancels the linked warranty repair, leaves its intake plan locked, and exposes no customer recovery action. The customer cannot pay for another shop-rider pickup or restart the same warranty job.
2. After a terminal failed return delivery, the customer does not understand when re-delivery becomes available. Recovery must not open while the repaired shoes are still with the rider; it should open only after the return-to-shop handoff is completed and approved.

The current code already supports customer-owned return recovery and manual warranty replanning. The fix should extend those paths instead of creating a second warranty claim or a dispatcher-only workflow.

## Decisions

- Reuse the same approved warranty repair request. Do not create another warranty claim.
- Preserve every failed attempt and shipment leg for audit.
- Warranty covers the repair service only. Every customer-requested shop-rider leg is paid by the customer: the first pickup, the first return delivery, and every later retry.
- Charge each shop-rider leg when it becomes due instead of collecting both directions upfront. Pickup is paid before intake dispatch; return delivery is paid before return dispatch.
- The automatic return-to-shop custody leg after an exhausted delivery is non-billable. It safely returns the already-paid failed shipment and is not a new customer-requested delivery.
- Walk-in intake, customer-arranged intake, and shop pickup after a failed return remain free of a shop shipping fee.
- The customer owns the recovery choice. Dispatcher and repair staff cannot choose on the customer's behalf.
- Return-delivery recovery becomes actionable only after the item is physically returned to the shop and the return receipt proof is approved.

## Root Cause

`ShipmentLegService::recordFailedAttempt()` marks a terminal repair pickup leg and shipment as cancelled, then changes the linked repair request to `cancelled`. For warranty jobs it correctly skips the refund, but it does not create a payable pickup-recovery state or clear the route to a fresh logistics plan.

The existing `SponsoredIntakeReplanCard` and `changeDeliveryMethod()` path can replan manually cancelled warranty logistics, but terminal pickup exhaustion still has a locked plan and a repair status that `tryCreateIntakeShipment()` will not accept.

`PaymentSettlementService::repairPaymentBreakdown()` currently forces the delivery amount to zero for warranty jobs outside the redelivery phase. Warranty approval therefore treats the first shop pickup and first return delivery as shop-sponsored. That contradicts the approved rule that warranty covers the repair service but not shipping.

The existing return-recovery path is custody-safe: it becomes actionable only after the return-to-shop leg is delivered and its receipt proof is approved. The customer UI needs to expose the in-transit waiting state and then reuse the existing recovery actions for warranty repairs.

## Customer Experience

### Standard warranty shipping

After warranty approval, the customer sees **Warranty repair: Free** and a separate shipping line when shop-owned logistics is selected.

- **First shop pickup:** show the accepted pickup quote and require payment before the dispatcher can assign a rider.
- **First return delivery:** when the repair is ready and the return address is confirmed, show the accepted return quote and require payment before dispatch.
- **Walk-in, customer-arranged courier, or shop pickup:** no shop shipping fee.

The two shop-rider directions are paid separately. A paid pickup never pre-pays the later return delivery.

### Exhausted warranty pickup

The warranty job remains in the Cancelled tab internally, but its card displays **Pickup failed—action required** instead of presenting it as permanently closed.

The customer can choose:

- **Schedule another shop pickup** — select a saved address, date, and time window; see the current coverage quote; pay only the new shipping fee.
- **Bring to shop** — reopen the same warranty job without a shop shipping fee.
- **Use my own courier** — reopen the same warranty job without a shop shipping fee.

For shop pickup, the UI progresses through **Choose pickup plan → Awaiting shipping payment → Pickup ready for rider assignment**. No rider assignment is created before payment succeeds.

### Exhausted warranty return delivery

While the repaired shoes are returning, the customer sees **Returning to shop—rescheduling unlocks after shop receipt** with no action buttons.

After the shop confirms receipt, the existing **Returned to shop—awaiting customer arrangement** card provides:

- **Schedule re-delivery** — select date and time window, confirm the address, and pay only the new shipping fee.
- **Set for shop pickup** — free, with the shop location displayed.

## State and Data Flow

### Standard warranty logistics payment

1. Warranty eligibility is defined exactly as `is_warranty_job = true` or `billing_mode = warranty_no_charge`.
2. Warranty approval keeps service, add-on, and VAT amounts for covered work at zero. It does not mark an unpaid shop-owned logistics leg as dispatchable or write its logistics lock.
3. If intake uses shop pickup, approval leaves payment pending and enables the initial payment action. The existing initial repair payment phase charges only `intake_delivery_fee`. Successful payment changes the repair to the paid-initial state, locks the accepted intake snapshot, and allows creation of the first pickup shipment.
4. If intake is walk-in or customer-arranged, the zero initial phase settles without an external checkout. The repair proceeds normally and remains eligible for a later return-delivery payment when applicable.
5. When the repair becomes ready and return uses shop delivery, payment is enabled again and the existing final repair payment phase charges only `return_delivery_fee`. Successful payment marks the warranty job fully paid for its selected legs, locks the accepted return snapshot, and allows creation of the first return shipment.
6. If return is shop pickup or customer-arranged, no shop delivery payment is collected.
7. Previously paid pickup shipping is never counted toward return shipping, and neither shipping payment changes the warranty service amount from zero.

### Pickup recovery

1. The final failed pickup attempt cancels the active leg and shipment and records the existing attempt evidence.
2. For a warranty repair—defined exactly as `is_warranty_job = true` or `billing_mode = warranty_no_charge`—the system adds a `pickup_recovery` entry to the existing `logistics_payment_reconciliation.entries` JSON. No migration or new table is required.
3. Initial recovery state is `awaiting_arrangement`. The repair stays `cancelled` so staff cannot process it prematurely.
4. A customer-owned pickup-recovery endpoint always validates ownership and method. Shop pickup additionally requires a saved address, current coverage quote, future date, and time window. Customer-arranged delivery requires a saved address snapshot but no shop coverage or shop schedule. Walk-in requires neither an address nor a schedule.
5. Shop pickup stores the accepted quote, leaves the intake logistics lock empty, and moves recovery to `awaiting_payment`. Walk-in or customer-arranged delivery resolves recovery immediately, restores the repair to `repairer_accepted`, and writes a replan lock newer than the cancelled shipment so the old shop-pickup plan cannot replay. Walk-in clears the intake and pickup address snapshots, fee, and quote. Customer-arranged delivery stores its address snapshot with a zero shop fee and no shop quote. Neither free method creates a new shop-owned shipment leg.
6. Payment uses a `pickup_retry` repair payment phase. Its service amount is always zero, including warranty jobs; its delivery amount is the accepted pickup quote.
7. Successful settlement marks recovery paid, restores the repair to `repairer_accepted`, writes an intake logistics lock newer than the cancelled shipment, and reuses `tryCreateIntakeShipment()` to append exactly one new leg to the same shipment.
8. Attempts restart for the new leg because attempts are leg-scoped. Previous legs and attempts remain unchanged.

### Return recovery

1. The final failed outbound delivery creates the existing return-to-shop leg.
2. The return-to-shop custody leg requires no additional customer payment. Until it is delivered and its receive proof is approved, the customer receives a non-actionable `returning_to_shop` presentation state.
3. Shop receipt activates the existing `awaiting_arrangement` return recovery.
4. Re-delivery continues through the existing `redelivery` payment phase. Warranty service remains zero while the new delivery fee is charged.
5. Payment reopens the same return shipment with one new outbound leg. Shop pickup follows the existing free handoff flow.

## API and Service Boundaries

- Update `RepairWarrantyService` approval initialization so covered service remains zero while unpaid intake and return delivery quotes remain payable in their respective phases. It must not pre-lock or dispatch an unpaid shop-owned leg.
- Keep final-attempt recording in `ShipmentLegService`; it should create the pickup-recovery marker for warranty jobs at the same transaction boundary as cancellation.
- Add pickup-recovery resolution to `RepairDeliveryService`, following its existing return-recovery ownership and idempotency rules.
- Add one customer-only route under `/api/customer/repairs/{id}` for pickup recovery. Cross-customer access returns 404; staff routes are not added.
- Keep warranty service amounts at zero in `PaymentSettlementService`, but stop zeroing valid shop-owned delivery quotes. Reuse the existing initial and final phases for the first pickup and first return, then add only the `pickup_retry` phase for exhausted pickup recovery.
- Reuse `changeDeliveryMethod()`, coverage quotes, address snapshots, shipment reopening, notifications, and the current return-recovery endpoint wherever possible.

## Safety and Idempotency

- Only the repair-owning customer may choose a recovery plan.
- A shop-rider plan requires a current saved-address snapshot and an available coverage quote. Customer-arranged delivery requires only its address snapshot; walk-in requires neither.
- A stale quote, changed address version, or changed fee invalidates the pending payment session.
- Replayed recovery requests and payment callbacks return the existing state and never create duplicate charges, legs, or notifications.
- Dispatcher assignment remains unavailable until the recovery is paid or a free intake method is confirmed.
- The first warranty pickup and first warranty return shipment are also unavailable to dispatch until their own shipping payment is settled.
- Return re-delivery actions remain unavailable until return-to-shop custody is confirmed.
- A paid recovery cannot be replaced by a free option without an explicit refund workflow.

## UI Changes

- Reuse the current My Repairs cards and form controls.
- Extend `SponsoredIntakeReplanCard` into the pickup-recovery card rather than adding a separate page.
- Show **Warranty repair: Free** separately from **Pickup shipping fee**, **Return shipping fee**, or **New shipping fee**. Do not mix shipping with covered service value or previously paid legs.
- Add a disabled explanatory return state while the item is returning to the shop.
- Refresh the repair list after each successful plan or payment transition.
- Keep status communication textual and not color-only.

## Tests

Add focused regression coverage for:

- first warranty shop pickup charges only its shipping fee before dispatch;
- first warranty return delivery charges only its shipping fee before dispatch;
- automatic return-to-shop custody after a failed delivery creates no additional charge;
- warranty approval does not lock or dispatch an unpaid shop-owned leg;
- pickup payment does not pre-pay or reduce the later return fee;
- walk-in, customer-arranged intake, and shop pickup require no shop shipping payment;
- terminal warranty pickup creates customer recovery without a refund;
- customer schedules a covered pickup and sees only the new shipping fee;
- payment reopens the same repair and shipment with one new pickup leg;
- old attempts remain and the new leg starts a fresh attempt count;
- walk-in and customer-arranged intake reopen without a shop fee;
- duplicate plan submissions and payment callbacks are idempotent;
- another customer and staff cannot resolve pickup recovery;
- out-of-coverage or stale-address pickup is rejected;
- failed warranty return shows a non-actionable returning state;
- return recovery activates only after approved shop receipt;
- warranty re-delivery charges only the new shipping fee;
- free shop pickup remains available after confirmed return.

Relevant existing warranty replan, payment, logistics, and return-recovery tests must remain green.

## Acceptance Criteria

- A customer can recover the same warranty job after exhausted pickup attempts.
- Shop-rider retry is not assignable until its new shipping fee is paid.
- The same warranty claim, repair request, and shipment are reused without losing history.
- Failed return delivery cannot be rescheduled while the item is still outside shop custody.
- Once shop receipt is approved, the customer can pay for re-delivery or choose free shop pickup.
- Warranty repair service charges remain zero from approval through completion.
- Every customer-requested shop-rider warranty leg is paid separately before it can be dispatched; automatic return-to-shop custody is non-billable.
