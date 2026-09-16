# Supplier Payment Success Feedback Design

## Goal

Show action-specific success feedback after supplier-payment proof submission, Shop Owner confirmation, and receipt dispatch without changing the payment workflow or backend.

## Design

Keep feedback inside `SupplierPaymentDialog`, immediately after each successful API response and state refresh. Reuse the existing `workflowFeedback` helper. Failed responses continue using the existing inline error state.

Messages:

- Proof submitted: payment is waiting for Shop Owner verification.
- Payment confirmed: Finance can send the supplier receipt.
- Receipt sent: the payment receipt was dispatched.

## Verification

Add focused component assertions for all three success paths, run the dialog test file, production build, and `git diff --check`.
