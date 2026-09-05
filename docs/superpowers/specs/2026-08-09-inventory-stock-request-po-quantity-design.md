# Inventory Stock Request, PO Cancellation, and All-Size Quantity Design

## Goal

Fix three SME procurement workflow gaps without expanding the module into an enterprise procurement system:

1. Inventory staff can submit Stock Requests from Inventory.
2. Purchase Orders cannot be cancelled once they are In Transit.
3. A new All Sizes request treats the entered quantity as quantity per eligible size.

## Scope and boundaries

Inventory remains responsible for Stock Requests and Supplier Orders/receiving. Procurement remains responsible for Purchase Requests, Purchase Orders, approvals, and suppliers. Inventory must not receive the Procurement purchase-request creation permission as a workaround.

Existing unreceived POs are not rewritten. Their stored quantities remain historical records. The new per-size interpretation applies to new All Sizes Stock Requests only.

## Stock Request authorization

The canonical Inventory Stock Request route authorizes the existing Inventory capability (`view-inventory`) through a route-specific policy ability. Procurement and repair/deprecated aliases continue to use the legacy Procurement creator capability. The Inventory Manager role keeps `view-inventory`, shared Procurement API view/receive permissions, and the existing Inventory Stock Request route; it is not granted `access-stock-request-approval` or any Procurement page permission, and does not regain `procurement.create_purchase_requests` or `procurement.submit_purchase_requests`.

The existing route middleware and shop-isolation checks remain in force. A regression test must prove an Inventory Manager can POST an Inventory Stock Request while still lacking Procurement Purchase Request creation access.

## PO cancellation state rule

The canonical Purchase Order cancellation rule becomes:

```text
cancellable: draft, sent, confirmed
not cancellable: in_transit, partially_received, delivered, completed, cancelled
```

`PurchaseOrder::isCancellableState()` is the single status predicate used by the policy, domain method, and UI; it checks only the whitelist. The domain method then keeps the existing posted-receipt guard. A direct API cancellation for an invalid state is rejected with the existing authorization response (HTTP 403); an otherwise cancellable order with a posted receipt reaches the domain guard and keeps its existing validation response (HTTP 422). Existing cancellation behavior for Draft, Sent, and Confirmed remains unchanged.

## All Sizes quantity contract

For a new shoe Stock Request with All Sizes selected, the form labels the input `Quantity per size` and previews the physical total:

```text
eligible sizes × quantity per size = total quantity
4 × 50 = 200 total units
```

The canonical Inventory endpoint is `POST /api/erp/inventory/stock-requests`. It accepts an optional `quantity_basis` field with only these values: `total` or `per_size`; an omitted field means `total`, and unknown values are rejected. Only this Inventory manual replenishment flow may use `per_size`; the Procurement stock-request/replenishment aliases and repair-material flow remain total-unit callers. The marker is consumed for calculation and is not persisted.

The frontend sends `requested_size` as an empty value for All Sizes. The server recognizes the existing compatibility values `all`, `all_size`, `all_sizes`, and `any`, but canonicalizes every All Sizes value to the existing null/blank representation before persistence so downstream PR/PO size snapshotting always sees the established All Sizes contract. `per_size` is accepted only on the named Inventory manual Stock Request route for a shoe item with an All Sizes request and a positive quantity; an explicit size, repair source, non-shoe item, missing/invalid color selection, or use on a Procurement/repair alias is rejected with HTTP 422. When an item has color variants, the requested color is required and must case-insensitively match one configured color variant. Eligible sizes are the unique configured size rows in that selected color variant; when the item has no color variants, the existing item size rows are used. Current on-hand quantity does not exclude a configured size, because the request is for replenishment.

The server resolves the eligible size count, multiplies the entered per-size value once, and stores the resulting physical total in the existing `quantity_needed` field. No new database column is required. Requests without `quantity_basis` retain the existing total-unit contract.

Downstream PR, PO, cost, receipt, expense, stock movement, and inventory logic continues to operate on physical totals. Therefore a new four-size request at 50 per size produces:

- Stock Request, PR, and PO quantity: 200
- PO cost: `200 × unit cost`
- Receipt allocation: 50 accepted units for each eligible size in this example; per-size receipt values may vary, provided their aggregate accepted quantity does not exceed the PO remaining quantity
- Parent/color inventory and stock movement: +200

The receipt endpoint computes accepted quantity as `received - defective`, validates that aggregate accepted quantity against the PO remaining quantity, and does not require equal per-size allocation or restore the retired size multiplier behavior.

Specific-size and non-shoe requests remain total-unit requests: entering 50 stores and orders 50.

## Error handling

- Inventory Stock Request authorization failures must return the existing API error contract for unauthorized users.
- All Sizes requests with no eligible size rows or invalid quantity basis/quantity/color combinations are rejected before persistence.
- Cancellation rejects invalid PO states consistently through both UI and API.
- Existing records are not silently recalculated.

## Verification

Add or update focused tests to prove:

1. Inventory can create a Stock Request without Procurement PR-create permission, while Procurement page separation remains intact.
2. In Transit cancellation is rejected by the shared model/policy/API predicate and the UI does not offer it; Draft/Sent/Confirmed cancellation remains allowed.
3. The Stock Request UI shows the four-size `Quantity per size` preview and submits `quantity_basis: per_size`; the server stores 200 for an entered 50.
4. Four eligible sizes with per-size quantity 50 flow through PR/PO creation with quantity 200.
5. Supplier receiving accepts 50 per size, records aggregate 200, and updates stock/movement/expense totals from 200; unequal valid allocations and defects use aggregate accepted quantity.
6. Specific-size, non-shoe, repair, omitted-marker legacy callers, and existing unreceived PO fixtures retain their current semantics and stored values.

No new module, dependency, table, or enterprise procurement workflow is introduced.
