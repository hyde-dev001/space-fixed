# Customer Repair Return Recovery

## Goal

When repaired shoes are returned to the shop after the final failed delivery attempt, the customer—not the rider—chooses the next fulfillment method.

## Customer flow

The My Repairs card shows `Returned to shop—awaiting customer arrangement` with exactly two choices:

1. `Schedule re-delivery`
   - Choose a future delivery date and morning/afternoon window.
   - Confirm the existing return address and delivery quote.
   - Pay only the new delivery fee through the existing payment flow.
   - After payment, create the new outbound leg using the selected schedule.
2. `Pick up at shop`
   - Free; no new delivery fee.
   - Show the shop name/address and wait for the recorded handoff.

The endpoint is customer-authenticated and ownership-scoped. Existing staff/shop recovery endpoints remain available only as an assisted fallback for arrangements made through chat or phone.

## Data and behavior

Reuse the existing `return_recovery` reconciliation entry. Store the selected date/window in that JSON entry; no migration is needed. Reuse the current redelivery payment session and shipment-reopening flow.

Validation requires a future date and a valid `morning` or `afternoon` window for customer-scheduled re-delivery. Shop pickup clears delivery scheduling and remains free.
