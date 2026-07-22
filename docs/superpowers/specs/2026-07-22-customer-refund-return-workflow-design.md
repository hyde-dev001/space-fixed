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

This sequence applies only to normal, customer-requested refunds for company accounts. Individual-shop approvals and the system-created `delivery_attempts_exhausted` workflow keep their existing rules.

## State mapping

The existing `OrderRefund` fields remain the source of truth; no migration is needed.

- `shop_owner_status` represents the merchant-side Staff decision for normal company-account customer refunds. Existing column names remain for compatibility, and the approving Staff user remains recorded in `shop_owner_approved_by`.
- `finance_status` represents Finance authorization.
- `return_status` tracks `awaiting_approval` → `pending_customer_shipment` → `pending_staff_pickup` → `in_transit` → `received`.
- `refund_executed_at` records when a payout attempt started. Only `status = succeeded` and `refunded_at` indicate that Finance released the money successfully.

For a normal company-account customer refund, Staff approval changes the merchant-side status from `pending` to `approved`. This replaces the existing Finance-initial → merchant → Finance-final branch only for this flow. Finance may authorize only after Staff approval and changes `finance_status` directly from `pending` to `approved`. Dual approval moves the return to `pending_customer_shipment`, making pickup arrangement available.

Individual-shop refunds retain their existing owner approval behavior. Failed-delivery refunds retain their system-approved merchant side and return-before-Finance behavior.

## Backend enforcement

The backend is authoritative at every transition:

- Add Staff-authenticated approve and reject routes under the existing Staff orders/refunds API. Both require the existing `access-staff-job-orders` permission, verify the refund belongs to the Staff user's shop, accept only a pending merchant-side decision, and call the shared refund service with an explicit Staff review stage.
- Finance approval must reject a normal customer refund that lacks Staff approval.
- `arrangeStaffReturnPickup` must require both merchant-side and Finance approval and `return_status = pending_customer_shipment` for both shop-owned and third-party carriers. A duplicate or concurrent arrangement is rejected with HTTP 422; it must not rewrite tracking data or repeat shipment, assignment, or notification effects.
- Return confirmation is atomic: every refund line must receive exactly one valid `resellable` or `damaged` inspection disposition before the refund can become `received`. Missing, partial, invalid, or failed disposition persistence leaves the refund unreceived. Existing `OrderRefundItem` fields are sufficient; no migration is required.
- Change `executeApprovedRefund` to accept only `return_status = received`. The current `in_transit` allowance is removed.
- Invalid transitions return HTTP 422 with a clear message and create no shipment, assignment, notification, or refund side effect.

The guard belongs in the shared refund service so Staff, Shop Owner, API, and future callers cannot bypass it.

## Staff and Finance UI

- Staff Job Orders shows `Approve refund` / `Reject refund` while merchant-side review is pending. Approval copy makes clear this is an eligibility/evidence review, not physical inspection.
- `Arrange return pickup` is hidden or disabled until both approvals are complete and the return is ready for arrangement.
- The status label distinguishes `Awaiting Staff Review`, `Awaiting Finance Authorization`, `Ready for Pickup Arrangement`, `Return In Transit`, and `Ready for Finance Payout`. The return remains `Return In Transit` until Staff submits the physical inspection.
- Finance sees only Staff-approved requests as actionable. Its approval copy says that it authorizes the refund but does not release funds yet.
- The existing Finance payout action becomes available only after Staff confirms receipt and inspection.

## Verification

Automated coverage will prove:

- Finance cannot authorize before Staff approval.
- Cross-shop or unauthorized Staff cannot approve or reject a refund.
- Staff cannot approve or reject a non-pending merchant decision.
- Finance cannot reject a normal company refund before Staff review.
- Staff cannot arrange pickup while either approval is missing.
- A blocked request returns HTTP 422 and creates no return shipment, assignment, or notification.
- Dual approval enables one pickup arrangement; duplicate and concurrent requests are rejected without repeated side effects.
- Missing, partial, or invalid line inspections cannot mark the return received.
- Finance cannot execute payout before receipt/inspection.
- Finance can execute payout after receipt/inspection.
- Existing failed-delivery refund behavior remains green.

The full Logistics suite and the focused refund workflow suite must pass before push.
