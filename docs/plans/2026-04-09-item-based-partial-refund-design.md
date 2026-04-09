# Item-Based Partial Refund Design

Date: 2026-04-09
Status: Approved
Scope: Retail POS (walk-in) and Online MyOrders

## 1. Problem Statement

Current refund behavior is primarily amount-based. It does not fully enforce line-level variant and quantity controls across multiple refund requests per order, and it does not support inspection-driven inventory outcomes (restock vs write-off) at line level.

This design introduces true item-based partial refunds:

- Select exact order item variant and quantity.
- Auto-calculate refund amount from selected quantities.
- Support multiple refunds on the same order until item quantities are exhausted.
- Apply correct per-line inventory outcomes based on inspection.

## 2. Approved Decisions

1. Approach: Shared line-level refund ledger model for both channels (POS + Online).
2. Retail POS remains walk-in focused, but this feature also includes online MyOrders.
3. Restock timing:
   - POS: apply inventory action at refund execute.
   - Online: apply inventory action only after return is marked received, then execute.
4. Disposition model: inspection-based.
5. Damaged shoes: no restock; record loss/write-off event.
6. Partial refund UX: quantity picker per order line, amount auto-calculated and locked (not manually editable).

## 3. Domain Model

### 3.1 Refund Header (existing)

Keep existing refund headers for compatibility and reporting:

- `order_refunds` (online)
- `pos_refunds` (POS)

Header amount remains as aggregate, but derived from approved/requested line data.

### 3.2 New Refund Line Ledger

Introduce line-level tables (online and POS domains), storing:

- refund header id
- order item id
- product id
- variant identity snapshot (size, color, optional variant id)
- requested qty
- approved qty
- unit price snapshot
- line amount (derived)
- inspection disposition (`resellable`, `damaged`)
- inventory action (`restock`, `write_off`)
- inventory action timestamps/idempotency marker

### 3.3 Remaining Refundable Quantity

For each `order_item_id`:

`remaining_qty = purchased_qty - committed_refund_qty`

Where committed refund quantity includes active/approved/processing/succeeded lines according to workflow policy, and excludes terminally rejected/failed lines.

## 4. Workflow Design

### 4.1 Request

1. Client submits selected lines with `order_item_id` and `requested_qty`.
2. Server validates ownership, bounds, and remaining refundable qty.
3. Server computes total request amount from trusted order item snapshots.
4. Create one refund header plus multiple refund lines.

### 4.2 Approval

1. Keep header-level approval behavior initially.
2. Allow line-level `approved_qty` evolution if partial line approvals are needed later.
3. Header aggregate amount is computed from approved lines.

### 4.3 Inspection and Return Gating

1. Online:
   - Return flow must reach received state before execute.
   - Staff sets per-line disposition.
2. POS walk-in:
   - Inspection captured at counter before execute.
3. Each line gets disposition:
   - `resellable` -> `restock`
   - `damaged` -> `write_off`

### 4.4 Execute

1. Execute runs atomically with idempotent line processing.
2. Per line:
   - `restock`: increment exact variant/item sellable stock by approved qty.
   - `write_off`: no sellable increment; log loss/write-off movement.
3. Header reaches succeeded only after all line actions and payout operations complete successfully.

## 5. API and UI Contract

### 5.1 Request Payload

Both channels accept line arrays containing at minimum:

- `order_item_id`
- `requested_qty`

Client amount fields are treated as display-only; server recomputes totals.

### 5.2 MyOrders UI

In `resources/js/Pages/UserSide/Orders/MyOrders.tsx`:

- Replace checkbox-only item selection with per-line qty selector.
- Show remaining refundable qty per line.
- Auto-calc and lock refund amount.
- Preserve reason/evidence flow.

### 5.3 POS UI

In `resources/js/Pages/ERP/cashier/POS.tsx`:

- Show purchased lines with variant identity (product, size, color).
- Qty selector per line constrained by remaining qty.
- Auto-calc and lock amount.
- Capture per-line inspection disposition before execute.
- Explicitly warn that damaged items are write-off (no restock).

### 5.4 Response Additions

Expose refund line details in history/detail endpoints:

- requested qty
- approved qty
- disposition
- inventory action
- inventory applied timestamp
- remaining refundable qty snapshot (where applicable)

## 6. Compatibility and Rollout

1. Keep existing endpoints and amount fields during transition.
2. Introduce line payload as optional first, then required for partial refund after migration window.
3. Preserve current full-refund flows.
4. Roll out behind channel flags if needed.

## 7. Testing Strategy

### 7.1 Unit Tests

- remaining qty calculations
- auto amount aggregation
- qty bounds validation
- idempotent line application

### 7.2 Feature Tests (Online)

- request partial with per-line qty
- block over-qty against remaining qty
- enforce return received gating
- execute mixed line dispositions (`restock` + `write_off`)

### 7.3 Feature Tests (POS)

- walk-in partial refund by variant+qty
- damaged line triggers no-restock and write-off log
- resellable line restocks exact qty
- multiple refunds on same order converge to zero remaining qty

### 7.4 Regression

- existing full refund paths pass unchanged
- compatibility payloads still accepted during migration period

## 8. Observability and Safety

1. Structured logs per line action:
   - refund id, line id, order item id, qty, disposition, action result
2. Reconciliation utility/report:
   - refunded qty vs inventory actions
3. Retry safety:
   - line-level inventory applied marker prevents double restock/write-off

## 9. Non-Goals (This Phase)

1. No customer-facing damaged bucket dashboard.
2. No manual override of auto-calculated amount.
3. No checkout flow changes; refund pipeline only.

## 10. Success Criteria

1. Partial refunds are strictly line+qty based in both channels.
2. Refund amount is always derived from selected quantities.
3. Damaged lines do not return to sellable inventory.
4. Multiple refunds per order remain accurate without exceeding purchased quantities.
5. Retry or duplicate execution cannot double-apply inventory effects.
