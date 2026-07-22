# Customer Refund Return Workflow Design

## Goal

Prevent Staff from arranging a customer-requested return pickup before the merchant-side review and Finance authorization are complete, while keeping the actual payout behind physical return inspection.

## Approved workflow

1. Customer submits a refund request and evidence.
2. Staff reviews eligibility and approves or rejects the request.
3. Finance reviews the Staff-approved request and authorizes or rejects the refund. This step does not release money.
4. Staff arranges the return pickup only after both approvals.
5. The rider returns the item to the shop.
6. Staff receives and physically inspects the item, recording each line as resellable or damaged.
7. Finance releases the refund only after the return is marked received.

The failed-delivery refund workflow remains separate because it is system-created and already requires the parcel to return before Finance approval.

## State mapping

The existing `OrderRefund` fields remain the source of truth; no migration is needed.

- `shop_owner_status` represents the merchant-side Staff decision for normal company-account customer refunds. Existing column names remain for compatibility.
- `finance_status` represents Finance authorization.
- `return_status` tracks `awaiting_approval` → `pending_customer_shipment` → `pending_staff_pickup` → `in_transit` → `received`.
- `status = succeeded` and `refund_executed_at` indicate that Finance released the money.

For a normal customer-requested refund, Staff approval changes the merchant-side status from `pending` to `approved`. Finance may authorize only after that status is approved. Dual approval moves the return to `pending_customer_shipment`, making pickup arrangement available. Finance payout remains invalid until `return_status = received`.

## Backend enforcement

The backend is authoritative at every transition:

- Staff approval/rejection must verify the refund belongs to the Staff user's shop and is still pending.
- Finance approval must reject a normal customer refund that lacks Staff approval.
- `arrangeStaffReturnPickup` must require both merchant-side and Finance approval plus the expected pre-pickup return state.
- `executeApprovedRefund` must continue requiring a received and inspected return.
- Invalid transitions return HTTP 422 with a clear message and create no shipment, assignment, notification, or refund side effect.

The guard belongs in the shared refund service so Staff, Shop Owner, API, and future callers cannot bypass it.

## Staff and Finance UI

- Staff Job Orders shows `Approve refund` / `Reject refund` while merchant-side review is pending.
- `Arrange return pickup` is hidden or disabled until both approvals are complete and the return is ready for arrangement.
- The status label distinguishes `Awaiting Staff Review`, `Awaiting Finance Authorization`, `Ready for Pickup Arrangement`, `Return In Transit`, `Awaiting Inspection`, and `Ready for Finance Payout`.
- Finance sees only Staff-approved requests as actionable. Its approval copy says that it authorizes the refund but does not release funds yet.
- The existing Finance payout action becomes available only after Staff confirms receipt and inspection.

## Verification

Automated coverage will prove:

- Finance cannot authorize before Staff approval.
- Staff cannot arrange pickup while either approval is missing.
- A blocked request creates no return shipment.
- Dual approval enables exactly one pickup arrangement and remains idempotent.
- Finance cannot execute payout before receipt/inspection.
- Finance can execute payout after receipt/inspection.
- Existing failed-delivery refund behavior remains green.

The full Logistics suite and the focused refund workflow suite must pass before push.
