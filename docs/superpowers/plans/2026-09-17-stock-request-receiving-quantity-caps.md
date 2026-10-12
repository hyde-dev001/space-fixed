# Stock-request quantity and receiving guard plan

## Goal

Keep the existing physical-quantity contract (`quantity per size × eligible sizes`) and prevent supplier receiving inputs from exceeding the order quantity.

## Implementation

1. Add a focused frontend regression test for a four-size, 160-unit order: the UI must show 40 per size and cap each received/defective input at 40. Also cover a single-size input cap.
2. Update `PurchaseOrderReceiptPanel` to clamp numeric input values while editing and expose the matching native `max`/`step` constraints. Reuse the existing per-size limit calculation.
3. Run the focused frontend test, existing procurement receiving tests, diff checks, and a fresh frontend build. Confirm the backend receiving guard remains the final trust-boundary validation.

## Scope constraints

- No new quantity column, migration, or alternate procurement workflow.
- Do not rewrite existing purchase orders; newly created orders use the already-normalized stock-request total.
- Do not add dependencies.
