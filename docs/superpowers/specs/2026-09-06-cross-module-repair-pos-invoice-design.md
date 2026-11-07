# Cross-Module Repair, POS, Inventory, Finance, Notification, and Invoice Fixes

## Scope

The rebased `feat/rider-gps-tracking` branch fixes six existing workflow defects without changing the refund/return architecture, adding a new ledger, or changing database primary keys. Existing repair, POS, inventory, Finance summary, notification, and invoice paths remain the sources of truth.

## Decisions

1. **Repair notifications**

   Remove the repair-creation broadcast to generic Staff. Existing active Repairer assignment notifications remain the repair workflow notification, and shop-owner notifications remain subject to the existing shop registration rules. No Staff permission is broadened and no frontend-only filtering is added.

2. **Delivery numbering**

   The existing `shipments.shipment_number` and shop-scoped allocation/unique constraint are authoritative for human-facing delivery numbers. Existing database IDs and shipment-leg relationships stay unchanged. Rider UI labels will resolve the nested shipment number instead of displaying a shipment-leg primary key.

3. **Repair POS checkout**

   Manual repair creation and repair POS settlement will execute inside one database transaction, with an idempotency lookup protected by the existing shop scope/lock pattern. For cash, `cash_received` is the tendered amount, while payment lines and `paid_amount` remain the exact collectible amount; change is derived and stored in transaction metadata/receipt data. Non-cash methods retain exact-amount and provider-reference validation.

4. **Retail POS inventory**

   Retail POS will use the same linked-inventory deduction behavior as online checkout: lock the canonical inventory/variant rows, decrement the exact option quantity, write a `stock_out` movement referencing the POS order, and keep product/variant fallback behavior for products without linked inventory. The shared deduction logic will be extracted only as far as needed to prevent two stock rules.

5. **Retail POS Finance**

   The existing `FinanceSummaryService` POS reader remains the Finance source. POS-backed orders will be excluded from the generic order reader so a successful POS sale is counted once, through its `pos_transactions` row. No global journal table or synthetic Finance ledger is introduced.

6. **Invoice due dates**

   The selected payment condition is sent by the UI and stored in existing invoice `meta`. The API derives a date-only due date from the issue date: Net 7, Net 15, Net 30, or same-day Due on receipt. A submitted due date cannot override a selected condition. Legacy callers that omit a condition retain the current manual due-date behavior for compatibility.

## Transaction and authorization invariants

- A failed POS checkout leaves no repair/order, payment, receipt, stock mutation, Finance-visible POS transaction, or post-commit notification.
- A successful retail POS sale commits order, payment, receipt, inventory movement, and POS transaction together.
- Repair notifications are addressed to the repair workflow; retail Staff does not receive an unrelated repair request.
- Shop scoping and existing role/permission checks remain intact.
- Existing retail merchandise return/inspection behavior is untouched.

## Verification

Focused Laravel regressions will cover notification recipients, repair POS rollback/idempotency/cash change, linked inventory and rollback, Finance summary inclusion without double count, and invoice date derivation/tampering. Frontend regressions will cover delivery-number presentation and read-only derived invoice dates. The rebased frontend build and the repository's Laravel/frontend suites will be run before completion.

