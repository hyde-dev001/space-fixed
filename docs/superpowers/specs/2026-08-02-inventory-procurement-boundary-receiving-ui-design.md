# Inventory–Procurement Boundary and Receiving UI Design

## Goal

Keep Inventory and Procurement as separate SME modules while allowing Inventory staff to receive goods from approved supplier purchase orders.

## Module Boundary

Inventory Manager keeps access to:

- Inventory dashboard and stock-management pages
- Stock Requests
- Supplier Orders, including viewing incoming POs and posting receipts

Inventory Manager must not see or directly open:

- Purchase Requests
- Stock Request Approval
- Purchase Orders
- Suppliers Management

The Procurement sidebar section is shown only to Procurement users or users with an explicit Procurement page permission. The Inventory-only `access-supplier-order-monitoring` permission must not qualify as Procurement module access.

Direct Procurement web routes must enforce their matching Procurement page permissions. Hiding sidebar items is not considered authorization.

## Receiving UI

When a PO item is receivable across multiple shoe sizes, every quantity input must have a visible size label. Each size is displayed as one aligned row:

`US 3 | Received now [input] | Defective [input]`

The same pattern repeats for every eligible size. Single-size and non-sized items keep one received input and one defective input.

Existing receipt behavior remains unchanged:

- Defective quantity cannot exceed received quantity.
- Accepted quantity is received minus defective.
- Only accepted units enter usable stock.
- The receipt continues to create a pending Finance expense.

## Permissions

Inventory retains the minimum shared Procurement API permissions required to load a PO and post a receipt. Purchase-request creation and submission permissions are removed from the Inventory Manager role.

Procurement Manager permissions and workflow remain unchanged.

## Verification

Automated checks will prove that:

1. Multi-size receiving inputs expose visible `US 3`, `US 5`, and similar labels.
2. Inventory-only users do not receive Procurement navigation.
3. Inventory-only users are denied when directly opening each Procurement web page.
4. Inventory users can still view Supplier Orders and post valid receipts.
5. Procurement users retain access to their existing pages.

No new module, dependency, database table, or enterprise procurement feature is introduced.
