# Company Repair Retail: Third-Party Payment and Handoff Fixes

**Status:** Approved for implementation on 2026-09-01

## Goal

Repair the company-repair employee flow so guest and registered POS payments, customer-arranged courier tracking, and physical return handoff remain distinct but complete in the correct order.

## Problem

Three related failures occur in the repair flow:

1. A fully paid customer-pickup/third-party return can appear locked before the assigned employee can record the physical handoff.
2. POS checkout for guest or walk-in repairs can send `customer_id=0`, causing the invalid-user validation error and losing the repair's authoritative customer snapshot.
3. The customer-facing courier note tells the customer to pay the remaining balance first, but the return tracking form stays locked after payment because the payment lock is treated as a physical handoff lock.

## Design decisions

- Keep the existing `PaymentSettlementService`, `RepairDeliveryService`, POS transaction ledger, payment sessions, and idempotency behavior.
- Do not add a second repair state machine or schema fields.
- Treat these as separate facts:
  - payment completion / collectible balance;
  - delivery-plan lock after payment;
  - customer-provided external courier tracking;
  - physical handoff activation (`pickup_enabled`);
  - customer receipt confirmation.
- A `customer_pickup` return requires carrier and tracking number before an employee can activate the physical handoff. The assigned repairer remains the actor for company repairs; shop-owned delivery remains dispatcher-controlled.
- A paid `customer_pickup` plan may still accept or update customer tracking until physical handoff. After `pickup_enabled`, tracking is immutable.
- Initial payment for `customer_delivery` is blocked server-side until intake carrier and tracking number exist. Walk-in, shop pickup, shop-owned delivery, warranty, and recovery paths retain their existing rules.
- POS repair checkout derives the registered-vs-guest identity from the locked `RepairRequest`, validates any supplied ID against that repair, and stores the guest snapshot for guest repairs.
- Customer UI copy reflects the sequence: pay the remaining balance, enter courier tracking, then wait for shop handoff.

## Error handling and security

All payment and handoff gates remain server-enforced. Tenant and actor scope checks are unchanged. Invalid customer IDs, mismatched registered customers, missing courier tracking, wrong return methods, and premature handoff attempts return validation errors without mutating payment, shipment, or handoff state.

## Verification

Add regression coverage for:

- paid company customer-pickup handoff with and without tracking;
- return tracking after the balance payment lock and immutability after physical handoff;
- blocked initial customer-arranged intake payment without tracking and allowed payment after tracking;
- guest/walk-in POS repair settlement and account-backed POS repair settlement;
- unchanged walk-in, shop pickup, shop delivery, and warranty/recovery behavior;
- customer-facing tracking/payment guidance and the guest POS payload normalization.

## Approved follow-up: manual no-account repair review and release

The manual POS no-account repair remains immediately payable by the Cashier. It
must not require a Repairer confirmation before payment and must not create a
fake customer account.

After checkout, the company repair is assigned to a Repairer while remaining in
`new_request`. This keeps the Repairer's Accept and Reject decisions available
before the work begins. Accepting follows the existing repair path; rejecting
records the existing rejection state and reason.

For a fully paid no-account repair whose return method is `walk_in`, the
Repairer's valid `Release to customer` action records the physical handoff and
transitions the repair directly to `picked_up`. No customer confirmation is
required because there is no customer account that can perform that step.

Registered-customer walk-in returns, customer-arranged courier returns, and
shop-owned delivery returns keep their existing confirmation, tracking, and
dispatcher/repairer ownership rules. A stale customer-confirmation flag must
not block the no-account walk-in recovery path.

The change is limited to the manual POS status/assignment path, Repairer
visibility and decision guards, and the shared method-aware return handoff
guard. Existing payment, inventory, receipt, and shipment records remain
unchanged.

## Manual refund after final rejection

If a no-account manual POS repair was paid and the Repairer rejects it, the
Manager's final rejection does not automatically refund the payment and does
not create a Finance approval item. The Manager rejection sends a required
action notification to the shop Cashier.

The Cashier opens POS receipt history and records the manual POS refund after
verifying the receipt. The cashier-only action creates the succeeded refund
ledger record, updates the repair and POS payment status, and is idempotent on
repeat submission. No fake customer account or customer confirmation is
required.
