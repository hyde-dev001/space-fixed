# Procurement Practical Gaps Repair Design

## Goal

Correct the seven production workflow defects found after the SME procurement rollout while keeping the module limited to practical purchasing, receiving, inventory posting, and Finance expense review.

This design amends `docs/superpowers/specs/2026-08-02-sme-procurement-repair-design.md` where the production behavior proved too broad or ambiguous.

## Confirmed workflow

```text
Inventory Stock Request
  -> Procurement Purchase Request
  -> Finance Initial Review
  -> Shop Owner Approval/Acknowledgment
  -> Finance Final Release
  -> Procurement creates and progresses Purchase Order to In Transit
  -> Inventory records actual quantities and defects
  -> Accepted stock posts exactly once
  -> Submitted procurement expense goes to Finance only
  -> Procurement may close the fully received PO
```

The Shop Owner does not approve the procurement expense again. Their PR approval is the business authorization to purchase. The receipt expense is an actual-cost record for Finance reconciliation. PO completion means the goods were fully received; it does not mean the expense has been settled and is not blocked by Finance expense approval.

## Tenant-safe modal drafts

Purchase Request and Stock Request browser drafts are scoped by authenticated shop and user. A global draft key is never restored.

- Draft keys include `shop_owner_id` and user ID.
- The old global keys are removed and ignored.
- A restored PR draft is valid only when its stock-request ID is still present in the current shop's approved, unused stock requests.
- If the source request is missing, the draft is discarded instead of showing product data from another shop.
- Server authorization and shop scoping remain authoritative; local storage is only a convenience.

## Stock Request as the quantity authority

The server, not the restored form or browser payload, copies these fields from the selected approved Stock Request:

- inventory item
- product name
- requested size and color
- total quantity needed
- priority

The client supplies only the selected stock request, supplier, unit cost, justification, optional notes, and submit intent. A stale or edited browser payload cannot change the source product or quantity.

## One quantity meaning

`quantity` means total physical units everywhere: Stock Request, PR, PO, receipt, expense calculation, and stock movement.

For example, a Stock Request for 200 shoes across four sizes remains 200 in its PR and PO. It is not displayed or stored as 50 units with a hidden multiplier of four.

- PR total cost is `total quantity x unit cost`.
- PO item ordered quantity is copied unchanged from the approved PR.
- New PO items retain eligible size IDs for receiving but use a quantity multiplier of `1`.
- Receipt accepted quantity is `sum(received by size) - sum(defective by size)`.
- Expense value is `accepted total x unit cost`.
- Parent/color/size inventory deltas sum to the same accepted total.

For an all-size shoe request, Inventory enters received and defective quantities per snapshotted size. The server validates distinct eligible size IDs, calculates aggregate quantities, rejects over-acceptance, and records the exact per-size effects for reversal. Specific-size and non-sized items keep the simple one-line received/defective input.

## Existing multiplied records

An idempotent data migration normalizes the earlier all-size representation without replaying business side effects:

- PR quantity becomes `total_cost / unit_cost` when that derived total is larger than the stored all-size quantity.
- Each PO item with `quantity_multiplier > 1` has ordered quantity and existing receipt quantities converted to physical totals, then its multiplier becomes `1`.
- PO header quantity, received quantity, and defective quantity are recalculated from normalized item/receipt rows.
- Existing inventory balances, stock movements, expenses, and inventory-effect snapshots are not created, deleted, or replayed.
- Re-running the migration has no effect because normalized rows have multiplier `1`.

This preserves the correct stock movement already observed in production while making the PR, PO, receipt history, and remaining quantity use the same physical-unit meaning.

## Inventory-owned receiving

`/erp/inventory/supplier-order-monitoring` becomes the receiving workspace.

- Remove the button that sends Inventory users to `/erp/procurement/purchase-orders` and causes a `403`.
- Remove the read-only message that says receiving happens in Procurement.
- Each PO row has a View action.
- `in_transit` and `partially_received` POs open an Inventory receiving form with received and defective inputs.
- `delivered` and `completed` POs show details and receipt history without another receiving action.
- Inventory can post receipts only with the existing receiving permission and same-shop access.
- Procurement's PO page shows receipt totals and history but no receive/defective controls.
- Procurement continues to own commercial actions: create PO, send, confirm, mark in transit, cancel when eligible, and complete after full receipt.

The existing canonical Purchase Order and Receipt services remain the only write path. No second Inventory receipt implementation or SupplierOrder write flow is introduced.

## Recipient-safe notification destinations

Every procurement notification uses a real page the recipient can access and includes a query parameter identifying the record to open:

- Inventory stock-request requester: `/erp/inventory/stock-request?stock_request=<id>`
- Procurement stock-request reviewer: `/erp/procurement/stock-request-approval?stock_request=<id>`
- Finance PR reviewer: `/finance/purchase-request-approval?purchase_request=<id>`
- Shop Owner PR reviewer: `/shop-owner/purchase-request-approval?purchase_request=<id>`
- Procurement PR requester: `/erp/procurement/purchase-request?purchase_request=<id>`

The target page opens the matching same-shop record when present. Invalid or foreign IDs never bypass normal API/shop authorization; the page simply does not open a foreign record.

## Finance-only procurement expense review

A posted receipt with positive accepted value creates one `submitted` procurement expense linked to that receipt.

- No multi-level expense approval record is created for a procurement receipt.
- Finance receives the notification and uses the existing single-step approve/reject endpoint.
- Shop Owner expense lists, detail actions, and direct approval endpoints exclude receipt-linked procurement expenses.
- General manually entered expenses retain their existing approval workflow; this change is procurement-specific.
- Voiding an eligible receipt continues to reject its still-submitted expense and reverse only the recorded inventory effects.

## Authorization

- Inventory Manager retains `procurement.receive_purchase_orders` and inventory-page access.
- Procurement Manager no longer receives purchase-order receiving permission by default.
- Receipt policy requires the receiving permission, same-shop ownership, and Inventory module access.
- UI visibility is not authorization; policy and shop-scoped queries remain authoritative.

## Error handling

- Restored draft validation happens before showing the restore prompt.
- Invalid notification IDs do not cause cross-shop requests.
- Quantity, size allocation, and remaining-quantity validation return `422` and post no receipt, inventory movement, or expense.
- Receipt posting remains atomic and idempotent.
- User-entered receipt values remain in the form after a recoverable validation error.

## Required regression coverage

Automated tests cover:

1. Draft keys are scoped by shop and user, and stale/foreign stock-request drafts are discarded.
2. Stock-request notifications route Inventory requesters to the Inventory page without `403`.
3. PR notifications route Finance and Shop Owner to existing pages and open the intended request.
4. The server copies Stock Request quantity and identity fields instead of trusting the PR form.
5. Total quantity remains identical across Stock Request, PR, PO, receipt aggregates, expense, and stock movement.
6. All-size receipts accept exact per-size quantities and defects without hidden multiplication.
7. Inventory can receive from Supplier Order Monitoring; Procurement cannot receive from its PO page.
8. Completed POs are view-only and cannot be received again.
9. Receipt-linked procurement expenses are Finance-only and require no second Shop Owner approval.
10. Existing multiplied records normalize once without changing stock or creating duplicate movements/expenses.

Manual verification covers the seven reported scenarios with two shops and Inventory, Procurement, Finance, and Shop Owner accounts.

## Follow-up: consumed requests and all-size clarity

An accepted Stock Request becomes unavailable for PR creation as soon as any Purchase Request links to it. The initial Inertia payload and the modal refresh API both exclude linked Stock Requests; the existing unique `stock_request_id` validation remains the final duplicate-write guard. Rejected, approved, and completed PRs all keep their source Stock Request consumed; a new need starts with a new Stock Request.

All-size quantities remain physical totals, not per-size multipliers. Inventory Stock Request details, Procurement Stock Request review, Procurement PR details, Finance PR details, and Shop Owner PR details show:

- `Requested Size: All Sizes`
- the included size labels for the requested color when available
- `Total Quantity Across All Sizes: <quantity> units`

Specific-size and non-shoe records keep the shorter `Quantity` or `Quantity Needed` wording. Exact distribution among included sizes is recorded during Inventory receiving.

## Explicitly deferred

This repair does not add RFQs, bid comparison, contracts, budgets, supplier portals, invoice matching, advanced accounting posting, or supplier analytics. Basic supplier recording and the approved SME purchasing flow remain unchanged.
