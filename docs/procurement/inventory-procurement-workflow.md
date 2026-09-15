# Inventory and Procurement Workflow

This is the current SME workflow. It tracks what the shop intends to buy, what actually arrived, the stock added, and the expense Finance still needs to review. It intentionally does not include RFQs, bidding, contracts, or enterprise approval configuration.

```mermaid
flowchart LR
    A[Stock request] --> B[Purchase request: draft]
    B --> C[Finance initial review]
    C -->|approve| D[Shop Owner awareness / approval]
    D -->|approve| E[Finance final release]
    E -->|approve| F[Approved PR]
    C -->|reject| X[Rejected]
    D -->|reject| X
    E -->|reject| X
    F --> G[One or more same-supplier PRs grouped into a PO]
    G --> H[draft → sent → confirmed → in transit]
    H --> I[Submit one complete receiving result]
    I --> J[Accepted stock movement]
    I -->|defects| K[Supplier Adjustment]
    K --> L[Replacement or short fulfillment]
    L --> I
    I -->|all resolved| M[Post one Final Receipt]
    M --> N[One submitted expense for Finance review]
    N --> O[Completed]
```

## State rules

Purchase Request:

```text
draft → pending_finance → pending_shop_owner → pending_finance_final → approved
                                                    ↘ rejected ↙
```

- Finance performs both the initial budget review and final release.
- The Shop Owner step provides awareness and consent; it is not the final financial approval.
- Creating and submitting immediately is allowed only when the user has both permissions, and still ends at `pending_finance`.
- There is no value threshold or background job that skips an approval stage.

Purchase Order:

```text
draft → sent → confirmed → in_transit → partially_received → delivered → completed
```

- A PO may contain one item. It may also group multiple approved PRs when they belong to the same shop and supplier.
- PO item product, quantity, cost, color, size, and inventory targets are server snapshots from approved PRs; the client cannot rewrite them.
- Manual status actions stop at `in_transit`. Only receipt posting can set `partially_received` or `delivered`.
- Draft, sent, confirmed, and in-transit POs can be cancelled only before any receipt is posted. Cancellation releases their PRs for a replacement PO.

## Receiving and Finance

- Record the complete original supplier delivery once with received and defective quantities per PO item. The pre-final receiving result is not a posted receipt.
- Accepted quantity is `received - defective`. Defective units do not enter usable stock and do not create expense value.
- Partial original deliveries cannot be finalized. Every PO item must be physically accounted for in the original result.
- Defects create an actionable Supplier Adjustment and block Finance. Replacements remain linked to that adjustment and are recorded on the same pre-final receipt; they do not create another PO, normal receipt, or expense.
- Supplier decline can close the unresolved quantity as Short Fulfillment. Required defective returns use only `required`, `released`, `received_by_supplier`, and `waived` states; no courier or tracking subsystem is involved.
- The one Final Receipt is posted only after all adjustments and required returns are resolved. Its payable quantity is initial accepted quantity plus accepted replacements.
- Each submission uses an idempotency key, and the PO/receipt locks prevent duplicate Final Receipts, expenses, or stock effects.
- New Final Receipts use a shop-scoped `RCV-YYYY-####` reference. Pending receiving results and replacements do not consume that sequence; historical receipt IDs remain unchanged.
- Inventory updates use the PO item's frozen parent/color/size targets.
- Only the Final Receipt creates one Finance expense with status `submitted`; it never auto-approves and does not pre-fill final approver fields.

## Corrections

A manual receipt can be voided only while the PO is not completed and its linked expense is absent, `submitted`, or `rejected`. A reason is required. Voiding creates compensating stock movements, reverses the exact parent/color/size deltas, rejects a submitted expense, cancels any pending approval attached to it, and recalculates the PO from the remaining posted receipts. Historical/migration receipts cannot be voided.

## Canonical and legacy screens

- Canonical purchasing and receiving: `/erp/procurement/purchase-orders`
- Canonical APIs: `/api/erp/procurement/purchase-requests` and `/api/erp/procurement/purchase-orders`
- Supplier Order Monitoring is read-only. Its former write endpoints remain as explicit `410 Gone` responses directing callers to canonical Purchase Orders.
- Unsupported supplier performance, rating, purchase-history, auto-PO, and auto-approval features are not part of this SME module.

Historical size-label cleanup remains documented in [requested-size-label-normalization-checklist.md](requested-size-label-normalization-checklist.md).
