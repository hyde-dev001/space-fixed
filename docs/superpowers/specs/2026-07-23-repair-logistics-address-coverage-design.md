# Repair Logistics, Address, and Coverage Integration

**Date:** 2026-07-23
**Status:** Approved specification

## Goal

Integrate repair intake and repaired-item return into the existing Logistics system while preserving walk-in and customer-arranged third-party courier choices. Shop-owned repair logistics must use pinned customer addresses, coverage checks, distance-based fees, scheduling, dispatcher assignment, rider proof review, and customer tracking.

## Scope

This design covers normal paid repairs and warranty/rework repairs. It reuses the existing Logistics shipment, leg, batch, rider availability, proof, notification, saved-address, Leaflet, coverage, scheduling, and shipping-estimate behavior.

It does not create a separate repair dispatcher or repair-only delivery tables.

## Delivery Choices

### Intake: customer to shop

- `walk_in`: customer personally brings the shoes to the shop.
- `customer_delivery`: customer arranges and pays a third-party courier to deliver the shoes to the shop.
- `shop_pickup`: the shop's Logistics rider collects the shoes from the customer's pinned address.

### Return: shop to customer

- `walk_in`: customer personally collects the repaired shoes at the shop.
- `customer_pickup`: customer arranges and pays a third-party courier to collect the repaired shoes.
- `shop_delivery`: the shop's Logistics rider delivers the repaired shoes to the customer's pinned address.

Third-party choices remain available when the address is outside shop-owned coverage. They do not create Dispatcher shipments and contribute no system delivery fee. The customer owns external carrier and tracking entry; Staff may view it but does not manage or replace it.

## Address Experience

The repair UI reuses the existing saved-address selector and Leaflet pin editor used by retail checkout.

- Intake and return may use different addresses.
- Return defaults to `Same as intake address`.
- Selecting a saved address copies an independent snapshot into the existing `intake_address` or `return_address` JSON: saved address ID, formatted address fields, latitude, longitude, delivery instructions, and a server-generated version fingerprint. Later edits to the saved-address record do not silently mutate a repair request.
- Customers may add or edit a saved address without leaving the repair flow.
- `Same as intake address` is a live link only while it remains selected and both legs are unlocked. An intake edit then refreshes the return snapshot and invalidates its previous quote and confirmation. Turning it off creates an independent return snapshot. After intake locks, return edits never modify intake.
- Shop-owned intake and return details lock when their matching payment settles successfully. A zero-amount gate is settled and locked by the same server-side domain transition without calling a payment processor.
- A locked shop-owned leg may unlock only through the explicit pre-pickup cancellation-and-compensation transaction described below.
- Non-shop-owned intake details lock when Staff records physical receipt. Non-shop-owned return details lock when Staff records release or third-party handoff.

All repair shops remain visible. After the customer selects or pins an address, the shop list shows `Within coverage`, `Outside coverage`, or `Pin required`. Coverage affects only shop-owned choices.

## Coverage and Fee Rules

Coverage uses the existing `DeliveryScheduleService::coverage` contract and the shop's Logistics Settings radius. It is evaluated independently for intake and return addresses.

Shop-owned delivery fees reuse the existing retail distance formula. The charged quote is stored separately as:

- `intake_delivery_fee`
- `return_delivery_fee`

The repair request also stores `return_address_confirmed_at`, `return_address_confirmed_version`, `intake_logistics_locked_at`, and `return_logistics_locked_at`. Confirmation is valid only when its saved version matches the current return address/method fingerprint.

Only shop-owned legs have a system fee. Walk-in and customer-arranged third-party legs store zero.

The backend is authoritative. At payment-session creation it validates ownership, coordinates, coverage, fee, method, and address version, then records that exact quote and snapshot version in payment metadata. Editing an unlocked leg invalidates its pending payment session and quote. On successful settlement, the callback compares the metadata with the current repair snapshot; a match locks the leg, while a mismatch enters payment reconciliation and creates no shipment. Missing coordinates, missing shop pin, outside coverage, or unavailable Logistics fail closed.

## Payment Allocation

- Initial amount due = required repair deposit/full service payment + intake shop-owned fee.
- Final amount due = remaining repair service balance + return shop-owned fee.
- Each payment record stores service and delivery-fee components separately so retries, reconciliation, credits, and refunds cannot double-apply either component.
- The return fee uses the latest customer-confirmed return address and method.
- Warranty/rework service cost may be zero, but selected shop-owned delivery fees remain payable.
- A paid and locked leg retains its accepted fee; later Logistics Settings changes do not retroactively reprice it. Current coverage and Logistics availability are nevertheless revalidated immediately before shipment creation and cannot be bypassed by an earlier quote.

Changing the return method or address before final payment invalidates confirmation and recalculates the return fee. Once payment locks a shop-owned leg, neither its method nor address can change while shipment creation is retrying. The only exception is a successful pre-pickup cancellation-and-compensation transaction, which unlocks the leg for a new selection.

## Shipment Creation

Continue using `RepairRequest` as the shipment source and the existing purposes:

- `repair_pickup`: one inbound leg from customer to shop.
- `repair_return`: one outbound leg from shop to customer.

Both use the existing idempotent source-shipment lookup. Shipment snapshots copy the locked repair snapshot and include its version, pinned coordinates, formatted address, phone, delivery instructions, schedule, distance, accepted fee, current coverage result, and estimate timestamp.

### Automatic intake trigger

Create the `repair_pickup` shipment only when all conditions hold:

1. Intake method is `shop_pickup`.
2. The repair request has been accepted.
3. The required initial repair amount and intake fee are paid.
4. The locked intake snapshot still passes current coverage and Logistics availability validation.

The same transactional, idempotent readiness check runs after acceptance and payment settlement. Concurrent or out-of-order events may retry, but the source/purpose uniqueness rule creates exactly one shipment.

### Automatic return trigger

Create the `repair_return` shipment only when all conditions hold:

1. Return method is `shop_delivery`.
2. Repair status is ready for return.
3. The final repair balance and return fee are paid.
4. The customer confirmed the latest return address.
5. The locked return snapshot still passes current coverage and Logistics availability validation.

No manual `Send to Logistics` step is required.

The same transactional, idempotent return-readiness check runs after every relevant gate event: ready-for-return transition, final payment settlement, and latest-address confirmation. Concurrent or out-of-order events may retry, but the source/purpose uniqueness rule creates exactly one shipment.

## Custody and Completion

Rider and Dispatcher proof does not replace physical/customer confirmation.

### Intake

1. Rider completes the inbound leg and submits proof.
2. Dispatcher approves proof.
3. An authorized Staff/repairer belonging to the same shop confirms physical receipt while the request is in the expected handoff state.
4. Only Staff/repairer confirmation changes the repair to `received` and permits repair work to start.

### Return

1. Rider completes the outbound leg and submits proof.
2. Dispatcher approves proof and the return is shown as delivered.
3. The owning customer confirms receipt while the request is in the expected delivered state.
4. Only customer confirmation completes the repair.

Proof approval alone never advances either physical-custody transition. Actor ownership, shop membership, and current state are checked server-side; invalid or replayed confirmations leave state unchanged.

### Non-shop-owned handoffs

- `walk_in` intake: authorized same-shop Staff/repairer records physical receipt; no Dispatcher proof is required.
- `customer_delivery` intake: the customer owns external tracking until arrival; Staff may view it, and authorized same-shop Staff/repairer records physical receipt without Dispatcher proof.
- `walk_in` return: authorized same-shop Staff records release, then the owning customer confirms receipt.
- `customer_pickup` return: the customer owns external tracking until shop handoff; authorized same-shop Staff confirms handoff and locks the details, then the owning customer confirms receipt.

Existing off-schedule/on-leave rider checks, assignment rules, batching, proof rejection, retry, and notification rules apply without repair-specific duplicates.

## UI Changes

### Repair shop selection

- Saved/pinned address selector.
- Coverage badge per shop without hiding outside-coverage shops.
- Shop-owned availability explanation.

### Repair booking

- Separate `Send shoes to shop` and `Return repaired shoes` cards.
- Walk-in, shop-owned, and customer-arranged third-party choices.
- Saved-address and Leaflet pin controls for shop-owned legs.
- `Same as intake address` enabled by default.
- Coverage, distance, estimated schedule, and fee feedback.
- Third-party copy states that payment is made directly to the courier.
- Summary distinguishes the intake fee charged initially from the return fee charged with the final balance.

### My Repairs

- Separate intake and return tracking timelines.
- Actionable payment, address, coverage, and delivery status messages.
- At ready-for-return, the customer reviews the latest return address and selects `Confirm address & delivery` before final payment/dispatch.

### Staff/repairer repair jobs

- Shop-owned legs display shared Logistics status and tracking; no manual rider fields.
- Third-party carrier/tracking remains customer-owned and read-only to Staff; Staff records only the physical receipt or handoff.
- Physical intake receipt remains an explicit Staff/repairer action after approved rider proof.

### Dispatcher

- Existing Shipments and Batches pages display repair request number, customer, shoe summary, and `Repair Pickup` or `Repair Return` purpose.
- Both pages expose a backend-applied `Module` filter with `All`, `Retail`, and `Repair` choices so paginated queues and counts remain correct.
- Module is derived from existing shipment sources: `order` and `order_refund` are Retail; `repair_request` is Repair. No duplicate module column is stored.
- The existing granular `Purpose` filter remains. Retail limits applicable purposes to Retail Delivery and Refund Return; Repair limits them to Repair Pickup and Repair Return.
- Shops registered as `both` see the selector defaulted to `All`. Retail-only or repair-only shops are scoped to their allowed module and do not see a redundant selector.
- Shipment rows and batch cards/stops show a compact Retail or Repair badge.
- Every delivery batch is module-homogeneous. Retail and Repair legs cannot be combined in one batch.
- The same module rule is enforced server-side for batch suggestions, manual creation, adding or replacing stops, and restoring a cancelled batch. Invalid mixed-module requests fail before assignments, events, or other side effects.

## Failure Handling

- Invalid or foreign saved address: reject the request without changing repair state.
- Missing pin, outside coverage, or unavailable Logistics: do not create a shipment; direct the customer to edit the pin or select walk-in/third-party.
- Unpaid required amount: keep the shipment absent and show the outstanding amount.
- Payment callback whose address/method version differs from the current repair snapshot: atomically apply only the valid service-payment portion, place the stale delivery-fee portion in reconciliation, keep the leg undispatched, and notify the customer and authorized same-shop Finance users. Finance resolves it only by crediting that exact fee to an outstanding repair balance or refunding it to the original payment channel. The leg and replacement payment action remain blocked until compensation succeeds; resolution then unlocks the leg and permits a fresh quote/payment.
- If a current coverage recheck or operational cancellation makes a paid shop-owned leg impossible before pickup starts, cancel any unstarted shipment and compensate the exact delivery fee: first credit it against an outstanding repair balance, otherwise refund it to the original payment channel. Unlock the leg only after compensation succeeds. Temporary infrastructure failure remains retryable and does not trigger compensation.
- Duplicate callbacks or retries: recover the existing shipment.
- Address or method change after payment lock: reject with a clear locked-details message.
- Shipment creation failure: leave the repair retryable and do not record a false dispatch state.

## Verification

Backend coverage must prove:

- Independent intake and return coverage results.
- Shop-owned disabled without a valid in-radius pin while walk-in/third-party remain available.
- Correct initial and final fee allocation.
- Exact reuse of the retail distance-fee formula at boundary distances and rounding points.
- Warranty service can be free while shop-owned fees remain payable.
- Acceptance/payment event ordering produces exactly one intake shipment under concurrent retries.
- Ready/payment/address-confirmation event ordering produces exactly one return shipment under concurrent retries.
- No Dispatcher shipment for third-party legs.
- Forged, foreign, missing-coordinate, and out-of-radius addresses fail server-side with no payment/session or shipment state change.
- Editing an unlocked leg invalidates its pending quote, payment session, and return confirmation.
- Payment metadata mismatch enters reconciliation without dispatch.
- Reconciliation applies the valid service portion exactly once, compensates the stale delivery fee, blocks duplicate payment, and unlocks only after authorized Finance resolution.
- Address/method locks after successful matching payment; the accepted fee survives later rate changes, while current coverage is still required at dispatch.
- Zero-amount gates use the server settlement/lock transition, and non-shop-owned details lock at physical receipt/release or handoff.
- A paid leg that falls outside newly changed coverage creates no shipment and follows compensation before unlock.
- Paid-leg operational cancellation compensates the delivery fee before unlocking.
- Approved rider proof still requires Staff intake confirmation or customer return confirmation.
- Unauthorized, cross-shop, wrong-customer, wrong-state, and replayed confirmations leave repair state unchanged.
- Walk-in and third-party intake/return handoffs complete through their explicit non-Dispatcher paths.
- Existing rider leave, schedule, assignment, batching, and proof-review guards remain enforced.

Frontend coverage must prove:

- Saved-address/Leaflet integration and `Same as intake` default.
- Coverage badges and disabled shop-owned choices.
- Correct fee placement in initial/final payment summaries.
- Return address confirmation and edit lock.
- Separate intake and return timelines.
- Both-business Dispatcher users can switch Shipments and Batches between All, Retail, and Repair, while single-module shops remain scoped without a redundant selector.

Backend coverage must also prove that the derived module filter composes with status, purpose, window, and pagination without cross-module rows or incorrect counts. Batch suggestions, creation, updates, and restore must reject mixed Retail/Repair legs without partial side effects.

Final regression gate:

- Complete Repair feature suite.
- Complete Logistics feature suite.
- Focused repair UI tests.
- Production frontend build.

## Non-goals

- No separate repair Logistics schema or dispatcher.
- No shop-arranged third-party dispatcher jobs.
- No configurable repair-only delivery rate table; repair reuses the retail formula.
- No manual coverage override in the first version.
