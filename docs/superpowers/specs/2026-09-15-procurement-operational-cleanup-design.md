# Procurement Operational Cleanup Design

## Goal

Make the existing Inventory → Procurement → Finance → Shop Owner procurement lifecycle correct, role-clear, and operationally understandable without adding another procurement domain or weakening current transaction, idempotency, tenant, evidence, payment, or settlement controls.

## Design

- Keep the current Stock Request, Purchase Request, Purchase Order, Purchase Order Receipt, Supplier Adjustment, Expense, Supplier Payment Attempt, and Expense Settlement records.
- Treat the one posted Final Receipt as the authoritative commercial receiving outcome. Supporting replacement receipts are operational evidence and do not require expenses.
- Derive finalization and completion readiness on the backend. React displays the returned quantities, blockers, current owner, and next action instead of reconstructing the state machine.
- New Purchase Requests use one Finance review. When Owner approval is enabled, Finance recommends and Shop Owner makes the final decision. Existing `pending_finance_final` rows remain processable for deployment compatibility.
- Keep Inventory as the only physical receiver/finalizer, Procurement as supplier-resolution owner, Finance as expense/payment maker, and Shop Owner as payment checker.
- Extend the existing Supplier Adjustment presentation and endpoint with human labels, ownership, next action, server-side filters, exact-record links, and private evidence previews.
- Expose post-payment issue eligibility from the backend only after the Final Receipt expense is fully settled. Inventory selects the exact eligible receipt item on historical POs; unpaid and partially paid receipts never show the action and remain rejected by the endpoint.

## Compatibility

- No schema migration is planned.
- Historical receipts and Purchase Requests remain intact.
- Existing payment maker-checker, append-only settlements, one-expense constraint, tenant isolation, private media, and idempotency remain authoritative.
- Rejected-PR revision, a full cross-entity timeline, a new dedicated adjustment permission, and legacy code deletion are deferred unless they prove necessary for core correctness.
