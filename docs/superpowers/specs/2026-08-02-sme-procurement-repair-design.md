# SME Procurement Repair Design

## Goal

Repair the procurement module so repair shops and shoe retailers can safely request, order, receive, and track incoming inventory without turning the platform into an enterprise procurement suite.

## Product boundary

The module is a purchasing and receiving tracker for SMEs. It must support the common case where a shop orders either one item or several items from the same supplier, receives them in one or more deliveries, updates inventory, and submits the resulting expense for Finance review.

This design does not add RFQs, tendering, bid comparison, supplier portals, contracts, catalogs, price agreements, tax engines, budget encumbrance, three-way invoice matching, or advanced supplier scorecards.

Supplier management remains basic master-data recording: name, contact details, address, active status, and notes. Unimplemented supplier performance and purchase-history API actions are removed from the active surface rather than completed.

## Canonical workflow

`PurchaseOrder` becomes the only writable source of truth for procurement orders. The separate legacy `SupplierOrder` workflow remains available only for reading historical records during the cutover period.

```text
Stock Request
  -> Purchase Request
  -> Finance Initial Review
  -> Shop Owner Approval/Acknowledgment
  -> Finance Final Release
  -> Purchase Order with one or more items
  -> One or more Receipts
  -> Accepted quantities posted to Inventory exactly once
  -> Submitted Expense created for Finance review
  -> Purchase Order completed after full receipt verification
```

No legacy `SupplierOrder` record is automatically migrated into `PurchaseOrder`. This avoids guessing how legacy multi-item, status, inventory, and expense records map to the canonical flow.

## Purchase-request approval

The existing three-step approval sequence is retained and tightened. Finance is the final approver because it owns the last budget and cash-availability check; the Shop Owner step records business awareness and consent before funds are released:

```text
draft -> pending_finance -> pending_shop_owner -> pending_finance_final -> approved
                       \-> rejected          \-> rejected              \-> rejected
```

- Procurement or authorized Inventory staff may save a draft or explicitly create-and-submit it to Finance in one action. Create-and-submit is only a UX shortcut; it enters `pending_finance` and skips no approval stage.
- Only Finance may perform the initial review in `pending_finance`, advancing the request to `pending_shop_owner` or rejecting it.
- Only the Shop Owner may approve/acknowledge or reject a request in `pending_shop_owner`. Approval advances to `pending_finance_final`; it does not release the PR for a PO.
- Only Finance may perform the final budget/cash-availability release in `pending_finance_final`, advancing the request to `approved` or rejecting it.
- Finance cannot override a Shop Owner rejection, and an owner-approved PR cannot be edited before Finance final release. Any material supplier, item, quantity, or cost change requires rejection and a corrected PR through the full sequence.
- A requester is a `User` referenced by `requested_by` and cannot perform either Finance action on their own request. The Shop Owner is a separate authenticated principal and is never written into a User foreign key.
- Finance initial review stores its User actor and time in `reviewed_by` and `reviewed_date`. Shop Owner approval stores its ShopOwner actor and time in `approved_by_shop_owner_id` and `shop_owner_approved_at`. Finance final release stores its User actor and time in `approved_by` and `approved_date` without overwriting the initial review fields.
- Rejection stores exactly one actor type: Finance uses `rejected_by_user_id`; Shop Owner uses `rejected_by_shop_owner_id`; both use `rejected_at` and `rejection_reason`. Optional stage remarks are appended with an explicit `Finance Initial`, `Shop Owner`, or `Finance Final` label.
- Invalid transitions are rejected; no role may skip an approval state.

Low-value thresholds, settings, queued jobs, events, and listeners must never approve or advance a PR. The existing auto-approval job and schedule are retired. Every submitted PR, including explicit create-and-submit and low-value requests, must enter `pending_finance` and traverse Finance initial review, Shop Owner approval/acknowledgment, and Finance final release.

## Purchase-order structure

A purchase order has a header and one or more item rows.

### Header

The header owns the shop, supplier, PO number, ordered and expected-delivery dates, payment terms, notes, status, and lifecycle actors/timestamps.

### Items

Each item row links to one approved purchase request and stores the inventory item, product description, requested size/color when applicable, ordered quantity, unit cost, and calculated line total.

Rules:

- A one-item PO remains a first-class, simple workflow.
- Multiple approved PRs may be grouped only when they belong to the authenticated shop and the same supplier.
- All selected PRs must be approved when the PO is created.
- Ordered quantity, unit cost, inventory item, size, and color are copied unchanged from each approved PR. PO creation does not accept overrides for approved line values; a material change requires a corrected and re-approved PR.
- A PR may belong to only one PO whose status is not `cancelled`. Completed POs continue to consume their PRs; cancelling a PO releases its PRs for a replacement PO. Creation locks the selected PR rows and checks this rule inside the same transaction to prevent concurrent duplicates.
- New PO totals are calculated from item rows on the server; clients cannot submit an authoritative total.
- Each PO item snapshots a quantity multiplier and the exact eligible inventory-size IDs. The multiplier is `1` for a specific item/variant and the number of snapshotted size IDs for an all-size request.
- Existing single-item `PurchaseOrder` records are backfilled into one item row each.
- Existing single-item columns remain temporarily available for compatibility, but canonical reads and writes move to item rows.

## Purchase-order lifecycle

The strict state sequence is:

```text
draft -> sent -> confirmed -> in_transit
                                  | \
                                  |  \-> delivered -> completed
                                  v
                         partially_received
                                  |
                                  \-> delivered -> completed
```

- Each action may move only to its documented next state.
- `partially_received` is set when at least one receipt exists and at least one item still has a remaining accepted quantity.
- A first receipt that fully accepts every item moves directly from `in_transit` to `delivered`; it does not pass through `partially_received`.
- `delivered` is set automatically when every item is fully accepted.
- `completed` is a manual verification action allowed only from `delivered`.
- A PO may be cancelled from `draft`, `sent`, `confirmed`, or `in_transit` only while it has no receipts.
- A PO with any posted, non-voided receipt cannot be cancelled or edited. If every receipt is voided, normal pre-receiving cancellation rules apply again.
- Delivery cannot be recorded through the generic status endpoint. It must pass through the receipt workflow.
- Transition methods return a clear validation failure when the current state is invalid; controllers never report success after a rejected transition.

## Receiving model

Receiving uses a receipt header and receipt item rows.

### Receipt header

The header stores the purchase order, shop, receipt date, receiver, notes, timestamps, and a client-generated idempotency key. The database enforces uniqueness of the key within a PO.

### Receipt items

Each row stores the PO item, received quantity, defective quantity, accepted quantity, and an immutable inventory-effect snapshot containing the exact parent, color-variant, and size-row IDs and quantity deltas changed during posting. Accepted quantity is calculated on the server:

```text
accepted quantity = received quantity - defective quantity
```

Rules:

- A receipt may contain one or more of the PO's remaining items.
- Received and defective quantities are non-negative integers, and defective quantity cannot exceed received quantity.
- The accepted quantity for a line cannot exceed that line's remaining ordered quantity.
- Gross received quantity may exceed ordered quantity only through defective units followed by replacements; cumulative accepted quantity may never exceed ordered quantity.
- Receipt submission locks the PO and affected item rows, rechecks remaining quantities, and writes the receipt in one database transaction.
- Retrying the same idempotency key returns the existing result and does not post inventory or Finance records again.
- Reusing an idempotency key with the same normalized payload returns the existing receipt. Reusing it with different item IDs or quantities returns `409 Conflict` and creates no side effects.
- Receipt contents are immutable after posting. An eligible incorrect receipt may be voided through the controlled correction flow below; it is never edited or deleted. Returns and corrections after financial/PO finalization remain outside this repair scope.

The existing aggregate received and defective fields remain synchronized during the compatibility period, but receipt rows are the authoritative audit trail.

## Receipt correction

Incorrect posted receipts use an audit-safe void operation rather than editing or deletion. A receipt stores `posted` or `voided` status plus nullable `voided_by`, `voided_at`, and `void_reason` fields. The void reason is required.

A receipt may be voided only when:

- It belongs to the authenticated shop and PO.
- It is a normal posted receipt, not a migration-source receipt or an immutable historical PO receipt.
- The actor has `void_purchase_order_receipts` permission.
- The receipt is still posted and the PO is not `completed`.
- Its linked expense is absent, `submitted`, or `rejected`. `approved` and `posted` expenses block voiding; no other expense status is treated as implicitly pending.
- Every parent item, color variant, and size row in the receipt item's inventory-effect snapshot has enough currently available stock to remove its recorded delta.

The void operation locks the PO, receipt, receipt items, affected inventory rows, stock movements, expense, and approval workflow before rechecking eligibility. In one transaction it:

1. Marks the receipt voided with actor, timestamp, and reason.
2. Creates one negative reversing parent stock movement for every original receipt-linked parent movement and applies the exact negative deltas from the receipt item's inventory-effect snapshot to its parent, color, and size rows.
3. Marks a linked submitted expense `rejected` with a system audit reason identifying the voided receipt. If its approval workflow is `pending`, the workflow becomes `cancelled` while preserving completed level history; a previously rejected expense/workflow remains rejected.
4. Recalculates PO aggregate received/defective quantities and status using only posted, non-voided receipts.
5. Recomputes the PO delivery actor and delivery timestamp/date from the remaining receipt that completes all ordered quantities, or clears all delivery audit fields when full delivery no longer exists.

After recalculation, a PO with no active receipts returns to `in_transit`, one with remaining accepted quantities is `partially_received`, and one whose items remain fully accepted is `delivered`. Once all receipts are voided, the PO may be cancelled under the normal lifecycle rule. The user records the corrected quantities through a new receipt.

A repeated void request returns the already-voided receipt and never creates a second reversal. Any failed eligibility check or side effect rolls back the whole operation. If stock is insufficient, the user must resolve the stock discrepancy through an authorized inventory adjustment before retrying.

Shop Owners receive the void permission by default. A Procurement Manager receives it only through an explicit assignment; ordinary receiving permission does not imply void authority.

## Inventory posting

Each receipt item creates one receipt-linked parent stock movement whose quantity is the total parent delta. Defective quantity never increases available stock. The receipt item's inventory-effect snapshot records every subordinate balance changed by that movement:

- Specific size without color: parent `+accepted`; selected size `+accepted`.
- Specific size with color: parent `+accepted`; selected color `+accepted`; selected size `+accepted`.
- All sizes without color: every snapshotted size `+accepted`; parent `+(accepted x multiplier)`.
- All sizes with color: every snapshotted size for that color `+accepted`; selected color and parent each `+(accepted x multiplier)`.

Posting and voiding lock and validate every row in that snapshot. A void requires parent, color, and size balances to each cover their own recorded delta; it then subtracts those exact deltas. This snapshot, rather than current variant configuration, is the source of truth for reversal.

The receipt, stock movements, inventory increments, expense, and PO status update run in the same database transaction. If any operation fails, all operations roll back. A unique link between the stock movement and receipt item prevents a retry from increasing stock twice. A reversing movement stores a unique link to its original movement so a receipt can be reversed only once.

Size and color updates reuse the existing inventory variant behavior. The posted quantity must affect only the selected item or variant. For an all-size item, ordered and received quantities mean quantity per eligible size: inventory adds accepted quantity only to the size IDs snapshotted on the PO item, while parent inventory increases by `accepted quantity x quantity multiplier`. Later additions or removals in the supplier/inventory size list never silently change the PO. If a snapshotted target no longer exists, receiving fails with a configuration error for correction instead of posting to a substitute size.

## Finance posting

Every successfully posted receipt with a positive accepted value creates exactly one Finance expense in `submitted` status. Its optional multi-stage approval workflow begins in `pending` status.

- Amount is the sum of `accepted quantity x PO-item unit cost x quantity multiplier` for that receipt. The multiplier is `1` for non-all-size items.
- The receiving user is recorded as the creator, not the approver.
- Finance may review, edit, approve, or reject the expense through the existing Finance workflow.
- The expense links directly to the procurement receipt through a new nullable, unique relationship.
- The existing Finance relationship to legacy `SupplierOrder` remains untouched for historical compatibility.
- A zero-value receipt does not create an expense.

This is not invoice matching. The expense is an operational draft for Finance review.

## Authorization and tenant isolation

Every query and every foreign-key-like request input is scoped to the authenticated shop. A valid ID from another shop behaves as not found.

Minimum action permissions:

- Create and submit PR
- Finance initial/final-review PR
- Shop Owner approve/acknowledge or reject PR
- Create and manage PO
- Send/progress PO
- Receive PO
- Complete PO
- Cancel PO
- Void PO receipt
- View procurement records

The explicit `receive_purchase_orders` permission may be assigned to Procurement and authorized Inventory roles. Shop Owner access follows ownership and admin authority. Broad dashboard access alone never authorizes approval, rejection, receiving, cancellation, or status changes.

Default responsibility assignments are:

- Procurement Manager: view procurement records; create and submit PRs; create and edit draft POs; record supplier confirmation and transit; send, cancel, receive, and complete POs when the matching action permission is assigned.
- Authorized Inventory staff: view relevant records; create and submit stock/PR requests; receive POs only when `receive_purchase_orders` is assigned. They cannot perform Finance review or commercial PO lifecycle actions by default.
- Finance: view and review `pending_finance` and `pending_finance_final` PRs plus submitted expenses. Finance cannot receive or progress POs by default.
- Shop Owner: view shop procurement records, approve/acknowledge or reject `pending_shop_owner` PRs, and exercise shop-level PO cancellation/completion authority.

Supplier-facing milestones are recorded by an authorized internal user; this scope does not create a supplier login or supplier-side confirmation action.

Policies and shop-scoped request validation enforce the same rules. Controller comments or route visibility are not treated as authorization.

Scheduled procurement reports and overdue notifications must query one shop at a time and notify only users belonging to that shop. If an existing job cannot establish a shop context, it is disabled until invoked with one.

## API behavior

The current procurement API namespace remains canonical.

- PO creation accepts one or more approved purchase-request IDs, not client-authored order-line totals.
- PO detail responses expose header data, item rows, cumulative receipt totals, remaining quantities, and receipt history.
- A dedicated receipt endpoint accepts the idempotency key and per-item received/defective quantities.
- A dedicated receipt-void endpoint accepts a required reason and performs the controlled correction transaction.
- The generic PO status endpoint is retained only for strict non-receiving transitions.
- PR, PO, Supplier, and Receipt mutations use a consistent `{ message, data }` response envelope, and the frontend API adapters unwrap it consistently.
- Invalid business transitions and quantities return `422`.
- Unauthorized actions return `403`.
- Records outside the authenticated shop return `404`.
- A duplicate idempotency submission with the same normalized payload returns the previously created receipt without creating side effects. Reuse with a different payload returns `409 Conflict`.
- Ineligible void requests return `422`; already-voided receipts return the existing void result without side effects.

Legacy `SupplierOrder` create, update, receive, and status-write endpoints return `410 Gone` with a pointer to the canonical Purchase Order workflow. Historical list and detail endpoints remain readable and clearly marked legacy.

Unused supplier performance/history routes are removed from active routing because basic supplier recording is the approved scope.

## User interface

The existing Purchase Orders page becomes the single purchasing and receiving workspace.

- Creating a PO starts with one approved PR and optionally allows adding other approved PRs from the same supplier.
- The default one-item case does not require an extra wizard or advanced form.
- PO details show item rows, ordered/accepted/defective/remaining quantities, and receipt history.
- The receiving form lists only items with remaining quantity and requires received and defective quantities per submitted line.
- A final confirmation explains that receipt contents are immutable after posting and summarizes the quantities before submission; eligible receipts may later be voided through the controlled correction action.
- Eligible receipts show a **Void receipt** action only to authorized users. Its destructive confirmation summarizes the inventory and pending-expense impact and requires a reason.
- Voided receipts remain visible with the void actor, time, reason, and reversing movements.
- The UI shows only valid next lifecycle actions, while the backend remains authoritative.
- Errors are displayed without clearing entered receipt data.
- Legacy Supplier Orders are labeled read-only and removed from normal create/edit/receive navigation.
- Supplier screens remain basic CRUD.

No new dashboard, vendor portal, quotation page, contract page, or analytics suite is added.

## Existing automation

Unreachable or divergent automation is not expanded during this repair.

- Auto-generated PO behavior is disabled because grouping PRs is now an explicit user decision.
- Sending a PO records the state transition; automatic supplier email delivery is not required by this scope.
- Events or listeners that duplicate inventory or Finance side effects are removed or left disconnected. Receipt posting is the only owner of those side effects.
- Notification settings that have no working in-scope behavior are removed from the active UI rather than presented as functional.

## Migration and cutover

The cutover is additive and reversible at the schema level:

1. Add PO item, receipt, and receipt-item tables; receipt void-audit fields; the receipt link on Finance expenses; and original/reversing receipt-item links on stock movements.
2. Backfill one PO item for every existing `PurchaseOrder` and verify counts and totals. For an all-size item, snapshot the currently eligible inventory-size IDs and set the multiplier to their count. A non-terminal PO whose derived multiplier conflicts with its stored total is reported and must be resolved before receipt cutover; a terminal historical PO preserves its stored total and remains immutable.
3. For an existing non-terminal PO with stored received quantities, create migration-source receipt rows from those aggregates without posting inventory or expenses again, then mark it `partially_received` when appropriate. Migration-source receipts cannot be voided because they do not own reconstructable side effects.
4. Treat existing `delivered`, `completed`, and `cancelled` POs as immutable historical POs. Backfill migration-source receipt rows when aggregates exist, preserve their terminal status even if historical quantities are incomplete, never replay inventory or Finance side effects, and never expose receipt-void actions for them.
5. Deploy canonical reads from PO item rows while retaining compatibility fields. Migration-source receipts are display/audit records, not proof that side effects should be replayed.
6. Deploy multi-item PO creation and receipt posting.
7. Switch monitoring and metrics to canonical POs.
8. Disable legacy `SupplierOrder` mutations and remove their action buttons.

Legacy tables and historical rows are not deleted in this project. Removal requires a separate usage audit and cleanup plan.

## Error handling and observability

- Transactions roll back on any failure in PO creation or receipt posting.
- Concurrency conflicts and stale quantities return a clear retryable validation error.
- Unexpected failures are logged with shop, PO, receipt submission key, and actor identifiers, without exposing stack traces or database messages to clients.
- Duplicate submission retries with the same normalized payload are treated as successful idempotent retrievals, not errors. Different-payload reuse returns `409 Conflict`.
- Receipt voiding rolls back completely if stock, Finance workflow closure, PO recalculation, or any reversing movement fails.
- No controller returns raw exception messages in production responses.

## Required tests

The repair is complete only when automated tests cover:

- Cross-shop viewing, creation references, approvals, transitions, and receiving.
- Requester self-approval prevention and Finance-initial/Shop-Owner/Finance-final approval boundaries.
- One-item and same-shop/same-supplier multi-item PO creation.
- Rejection of mixed-shop, mixed-supplier, unapproved, and already-active PR selections.
- Valid lifecycle transitions and rejection of every skipped or reversed transition.
- Partial, complete, defective, replacement, over-acceptance, retry, and concurrent receiving.
- Exact-once inventory changes and stock-movement creation per receipt item.
- Submitted, never auto-approved, expense creation with the correct accepted value.
- Receipt-void authorization, cross-shop blocking, required reason, insufficient-stock rejection, and approved/posted-expense blocking.
- Migration/historical receipt exclusion; concurrent-void serialization; and all-size reversal of parent, color, and size balances.
- Exact-once reversing movements, submitted-expense rejection, pending-approval cancellation, preserved approval history, repeated-void idempotency, PO quantity/status/delivery-audit recalculation, and full void rollback.
- Full transaction rollback when inventory or Finance posting fails.
- Backfill compatibility for existing single-item POs.
- Read-only legacy endpoints and canonical monitoring/metrics.
- Consistent API response handling in the Purchase Orders frontend.

Focused procurement tests and relevant Inventory and Finance integration tests must pass before cutover. A manual browser check covers one one-item PO, one multi-item PO with two partial receipts, and one eligible receipt void with inventory and pending-expense reversal.

## Deferred capabilities

The following remain intentionally out of scope until actual SME usage proves the need:

- Returns, close-short workflows, and correction after expense approval/PO completion
- RFQ and supplier quotation comparison
- Supplier accreditation, contracts, and performance scoring
- Catalogs, tiered pricing, tax/freight allocation, and multi-currency settlement
- Budget reservation and advanced approval matrices
- Supplier invoice capture and three-way matching
- Supplier portal and automated PO email delivery
- Migration or deletion of historical legacy `SupplierOrder` data
