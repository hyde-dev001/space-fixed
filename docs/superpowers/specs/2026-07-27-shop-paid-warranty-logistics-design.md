# Shop-Paid Warranty Logistics

**Date:** 2026-07-27
**Status:** Approved design

## Goal

Make both shop-owned intake pickup and return delivery free to the customer for approved warranty rework jobs. The shop shoulders these costs while the existing coverage, scheduling, dispatcher, rider, proof, and handoff flows remain authoritative.

This supersedes the earlier repair-logistics rule that made warranty delivery fees customer-payable.

## Rules

- A warranty rework is identified by `is_warranty_job = true` or `billing_mode = warranty_no_charge`.
- Shop-owned warranty legs still require a valid pinned address and must pass the normal coverage check.
- The accepted distance-based quote remains stored in `intake_delivery_fee`, `return_delivery_fee`, and the matching logistics quote. These values represent shop-sponsored operational cost, not customer revenue or customer amount due.
- Warranty jobs keep `total`, `final_total`, and `total_paid_amount` at zero.
- Warranty approval creates the linked job with customer payment disabled and completed.
- Both warranty logistics plans are locked at approval so no payment event is required to lock them.
- The shared repair payment breakdown must return zero customer delivery amount for a warranty job, preventing direct API or POS collection.
- Third-party delivery remains customer-arranged and creates no system delivery fee or dispatcher shipment.
- Normal paid repair behavior is unchanged.

## Flow

1. Customer files a warranty claim and selects intake and return methods.
2. Repairer approves the claim.
3. The linked warranty job stores its addresses, coverage quotes, and shop-sponsored fees.
4. The customer sees no `Pay Now`; the repair card states that warranty service and shop-owned shipping are covered by the shop.
5. Once the repair is accepted, the existing intake readiness check creates the shop-pickup shipment without waiting for payment.
6. When repair work is ready for return and the existing address/handoff gates are satisfied, the existing return readiness check creates the shop-delivery shipment without waiting for payment.

## Implementation Boundaries

- Reuse the existing warranty marker, delivery quote fields, logistics locks, and shipment readiness services.
- Do not add tables, columns, payment records, refunds, credits, or a separate warranty delivery workflow.
- Do not record sponsored warranty delivery fees as customer payments or delivery revenue.
- Keep backend payment protection authoritative; hiding the button alone is insufficient.

## UI

- Hide customer payment actions for warranty rework jobs.
- Replace payment-oriented warranty copy with `Warranty service and shop-owned shipping are covered by the shop.`
- Keep the selected intake/return methods and tracking visible.

## Verification

- Approval with shop-owned intake and return stores both positive accepted quotes.
- The linked warranty job has payment disabled, completed payment state, zero paid amount, and locked intake/return logistics.
- The customer API exposes no payable amount and the UI renders no `Pay Now`.
- Direct POS or online payment creation cannot charge a warranty delivery fee.
- Repairer acceptance can create exactly one intake shipment without customer payment.
- Return readiness can create exactly one return shipment without customer payment.
- Coverage failures still block shop-owned warranty methods while walk-in and third-party remain available.
- Existing paid repair payment, reconciliation, shipment, and delivery-revenue tests remain green.

