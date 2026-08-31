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
