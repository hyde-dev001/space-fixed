# Procurement Supplier Payment Workflow Design

## Status

Approved source of truth as of 2026-09-12. This document incorporates the
accepted revisions and is a design specification only. Production
implementation is not part of this change.

## Objective

Complete the existing SoleSpace flow from an Inventory stock request through
manual Procurement purchasing, canonical Inventory receiving, Finance release,
PayMongo supplier payment, settlement, and supplier defect resolution.

The implementation must extend existing code instead of creating parallel
receiving, approval, settlement, notification, or supplier-order systems.

## Canonical Workflow

1. Inventory creates a stock request and Inventory approves it.
2. Procurement manually creates a Purchase Request from the approved stock
   request.
3. The existing Finance, optional Shop Owner, and final Finance approvals move
   the PR to `approved`.
4. Procurement manually creates a Purchase Order from one or more compatible
   approved PRs.
5. Procurement advances the PO through `draft`, `sent`, `confirmed`, and
   `in_transit`.
6. Inventory posts physical receipt quantities through
   `PurchaseOrderReceiptService`.
7. The existing receipt service posts accepted inventory and stock movements,
   excludes defective quantities, and creates one submitted Finance expense per
   receipt using accepted quantity multiplied by PO unit cost.
8. Receipt posting remains the sole authority that moves the PO to
   `partially_received` or `delivered` under the existing rules. Payment and
   adjustment records must not redefine or directly change `delivered`.
9. Finance reviews and releases the procurement expense from `submitted` to
   `posted`.
10. Finance pays the full outstanding supplier payable through PayMongo using
    the supplier's saved payment profile and the shop's encrypted PayMongo
    credential.
11. A confirmed PayMongo success is recorded through the existing
    `ExpenseSettlementService`; provider acceptance or pending status is not a
    settlement.
12. Procurement may explicitly complete a delivered PO only when its required
    payables are settled and it has no unresolved supplier adjustment.

An approved PR never creates a PO automatically. The dormant
`CreatePurchaseOrderFromPR` listener remains inactive and event discovery
remains disabled.

## Existing Architecture to Preserve

- `StockRequestApprovalService` remains the stock-request and Inventory
  approval boundary.
- `PurchaseRequestService` retains the current Finance and Shop Owner approval
  transitions.
- `PurchaseOrderService` remains the only manual PR-to-PO creation service.
- `PurchaseOrderReceiptService` remains the only receiving and inventory-entry
  service. Its partial receipt, size/color allocation, stock movement,
  idempotency, expense creation, void, and reversal behavior is preserved.
- `ExpenseApprovalService` continues to create procurement expenses and will
  own the new procurement-only release transition.
- `ExpenseSettlementService` remains the only supplier-payable accounting
  ledger writer.
- `NotificationService`, `NotificationType`, existing recipient resolution,
  and notification preferences remain the notification architecture.
- Spatie Media Library and the existing `media` table store evidence and proof.
- Legacy `SupplierOrder` receives no new functionality.

## Explicit Reuse and Addition Decisions

The repository audit found no equivalent for the three proposed business
records below. Existing inbound customer payments, repair payments,
subscriptions, and refunds have different ownership and accounting meaning and
must not be repurposed for outbound supplier obligations.

| Proposed addition | Existing equivalent checked | Why it is necessary |
| --- | --- | --- |
| `supplier_payment_profiles` and `SupplierPaymentProfile` | Existing `Supplier` fields and payment/customer transaction models do not store a reusable supplier payout destination. | Stores one encrypted, verified, shop-owned destination without exposing or duplicating it per expense. |
| `supplier_payment_attempts` and `SupplierPaymentAttempt` | `finance_expense_settlements` is the accounting ledger; existing PayMongo transaction records track inbound customer, repair, or subscription money. | Tracks the outbound provider lifecycle, retries, destination snapshot, and idempotency before settlement exists. |
| `supplier_adjustments` and `SupplierAdjustment` | No current procurement defect, replacement, or supplier-refund case model exists. Spatie `media` stores files but not the business case. | Provides one auditable case for both receiving defects and post-payment issues, replacements, and refunds. |

Only three columns are added to existing tables:

- `purchase_order_receipt_items.replacement_for_adjustment_id`: no current
  receipt-item field links a canonical replacement receipt to its adjustment;
- `finance_expense_settlements.supplier_adjustment_id`: no current settlement
  field links an incoming supplier refund confirmation to its case; and
- `finance_expense_settlements.notes`: no equivalent settlement field stores
  Finance's refund-confirmation note.

No Supplier, Purchase Order, Finance Expense, attachment, notification, or
refund table is duplicated.

Three focused services are necessary:

- `SupplierPaymentService` coordinates outbound payout eligibility, locking,
  attempts, and the existing settlement service; no current service owns that
  lifecycle.
- `PaymongoSupplierPayoutGateway` is the concrete outbound HTTP boundary. The
  existing PayMongo services are specific to inbound payments or refunds and
  cannot safely represent a supplier payout, but their authentication, timeout,
  idempotency, and safe-error conventions are reused.
- `SupplierAdjustmentService` owns the single adjustment lifecycle. No current
  service coordinates procurement defect, replacement, and supplier-refund
  transitions.

No interface, factory, generic workflow engine, or speculative abstraction is
introduced.

## Payment Terms

The only supported terms are:

- `COD`
- `Net 7`
- `Net 15`
- `Net 30`
- `Net 45`
- `Net 60`

The allowlist is defined once on the backend and reused by Supplier and PO
validation. The due-date offsets are 0, 7, 15, 30, 45, and 60 calendar days
respectively. COD is due on the receipt date.

PO creation snapshots the applicable term into the existing
`purchase_orders.payment_terms` column. The precedence is an explicitly
selected valid PO term, the supplier default, a valid existing Procurement
setting, and finally `Net 30`. Changing the supplier later does not alter an
existing PO.

Payment timing is derived from `due_date` and the shop's calendar date:

- `Overdue`: due date is before today.
- `Due Today`: due date is today.
- `Due Soon`: due date is tomorrow through three calendar days from today,
  inclusive.
- `Not Due`: due date is more than three calendar days away.

Finance may release or pay a valid Net-term expense before its due date.

## Finance Review and Release

Add a procurement-specific action:

`POST /api/finance/expenses/{expense}/review-release`

The action uses the existing Finance approval permission group and performs a
locked transaction. It requires:

- a procurement receipt expense;
- expense status `submitted`;
- a posted, nonvoid receipt;
- the expense, receipt, PO, supplier, and authenticated actor to share one shop;
- the expense amount to equal the receipt's accepted quantity multiplied by
  the snapshotted PO item costs.

Success changes only the expense workflow status from `submitted` to `posted`.
Existing `approved_by`, `approved_at`, and `approval_notes` fields record the
Finance reviewer and release note. The normal expense approval workflow remains
unchanged and continues to reject procurement expenses.

The public manual settlement endpoint rejects procurement receipt expenses.
Only a confirmed supplier-payment attempt may invoke the existing settlement
service for these expenses.

## Frontend Changes

Extend the current pages and API helpers; do not add parallel dashboards:

- `Finance/Expense.tsx` shows Supplier, PO and receipt numbers, ordered,
  received, accepted, and defective quantities, unit cost, payable amount,
  payment terms, receipt and due dates, expense status, payment status, and
  payment timing. Submitted procurement expenses show `Review & Release`.
  Posted eligible expenses show `READY FOR PAYMENT` and `Pay Supplier`.
- The payment confirmation shows Supplier, PO, amount, bank, masked account,
  and PayMongo method. It never permits destination editing.
- `Procurement/SuppliersManagement.tsx` exposes existing payment-term, lead-
  time, products-supplied, city, and country fields and manages the one payment
  profile. Finance sees only masked profile details plus verify/disable actions.
- `Procurement/components/PurchaseOrderReceiptPanel.tsx` adds per-line defect
  category, notes, image evidence, and optional replacement-adjustment linkage
  while continuing to submit through the current receipt API.
- The existing Procurement purchase-order area gets one adjustment list/detail
  surface for review, replacement, refund, proof, and resolution actions.
  Post-receipt/post-payment reporting is launched from the relevant posted
  receipt item and enters that same adjustment surface.
- Existing `procurementApi`, `supplierApi`, shared procurement types, controls,
  validation presentation, loading states, and error conventions are reused.

Actions are hidden when ineligible for usability, but every backend endpoint
independently enforces status, role, and shop ownership.

## Supplier Management and Payment Profile

The Supplier UI exposes the already-existing fields for payment terms, lead
time, products supplied, city, and country. No duplicate Supplier columns are
added.

Add `supplier_payment_profiles` with a `Supplier hasOne
SupplierPaymentProfile` relationship. The table contains:

- `shop_owner_id` and unique `supplier_id`;
- destination type, bank name, and provider-required bank code;
- account name and encrypted account number;
- `unverified`, `verified`, or `disabled` status;
- verifier and verification timestamp;
- timestamps.

No equivalent supplier destination structure exists. The account number uses
Laravel's encrypted cast, is hidden from normal serialization, and is exposed
only through a masked representation. Banking passwords, OTPs, supplier secret
keys, and online-banking credentials are never stored.

Procurement manages the destination. Finance may view the masked destination
and verify or disable the profile but cannot edit it in the Pay Supplier
confirmation. A change to bank or account details resets the profile to
`unverified`. Changes apply only to future payment attempts.

## PayMongo Supplier Payment

Supplier payouts use the existing encrypted
`ShopOwner.paymongo_secret_key` belonging to the expense's shop. There is no
fallback to the global platform credential. If the shop credential is missing
or invalid, payment is unavailable and no attempt is sent. A global credential
may be considered only if later inspection of the activated PayMongo account
proves that its money-movement product requires a platform-owned account; that
would require an explicit design amendment.

Add `supplier_payment_attempts` because existing customer, repair, and
subscription payment records describe inbound money and cannot represent an
outbound supplier payout. Each attempt stores:

- shop, expense, supplier, and payment-profile foreign keys;
- amount and currency;
- provider and unique internal reference;
- nullable unique provider transfer/reference ID;
- shop-scoped unique idempotency key;
- encrypted destination snapshot;
- `initiating`, `processing`, `succeeded`, or `failed` status;
- sanitized failure information and lifecycle timestamps;
- initiating user;
- nullable link to the resulting Finance settlement.

`SupplierPaymentService` owns locking, eligibility, destination snapshots,
active-attempt protection, provider result handling, and settlement invocation.
`PaymongoSupplierPayoutGateway` is a small concrete HTTP boundary following the
existing PayMongo gateway's Basic authentication, timeout, idempotency-header,
and safe-error patterns. No interface or factory is introduced.

Payment initiation requires a posted expense, positive outstanding balance,
posted nonvoid receipt, verified same-shop profile, and no initiating or
processing attempt. The attempt is committed before the provider call. The
provider call occurs outside the database transaction.

Provider acceptance or pending response changes the attempt to `processing`.
A timeout or unknown result remains `processing` to prevent a blind duplicate
payout. A terminal failure changes it to `failed`, creates no settlement, and
allows Finance to retry with a new idempotency key.

The existing PayMongo webhook route and signature verification are extended
for the account's confirmed supplier-payout events. On terminal success, the
service locks and validates the attempt, amount, currency, provider reference,
and destination binding, then calls `ExpenseSettlementService::record` with a
stable source reference. Duplicate or out-of-order events cannot create a
second settlement. A failure received after success is inert and audit-logged.

## Defect and Supplier Adjustment Model

Add one `supplier_adjustments` table and `SupplierAdjustment` model. No second
defect or refund table is introduced. The record contains:

- shop and originating purchase-order receipt-item foreign keys;
- shop-scoped unique idempotency key;
- `issue_stage`: `receiving_defect` or `post_payment_issue`;
- reported quantity and unit-cost snapshot;
- reason category and immutable Inventory notes;
- adjustment status and optional `replacement` or `refund` resolution;
- Procurement notes;
- expected refund amount and supplier-reported refund details when applicable;
- reporter, reviewer, resolver, and relevant timestamps.

Supplier, PO, receipt, and expense are resolved through the originating receipt
item, avoiding duplicate foreign keys and stale copies. Defective value is
derived from reported quantity and the immutable unit-cost snapshot.

The only allowed defect categories are:

- `manufacturing_defect`
- `damaged`
- `wrong_item`
- `incorrect_size_or_variant`
- `other`

Notes are always captured for an issue and are explicitly required when the
category is `other`.

The lifecycle is:

`reported` → `under_review` → `awaiting_supplier` →
`resolution_in_progress` → `resolved`

A refund uses `awaiting_verification` after supplier proof is attached and may
be `partially_refunded` while confirmed money remains below the expected
amount. Only replacement and refund resolutions are valid.

`SupplierAdjustmentService` centralizes immutable reporting, permitted
transitions, tenant locks, replacement totals, refund confirmation, and
resolution. Activity logging records actors, timestamps, previous/new states,
references, and notes without logging full bank details.

## Receiving-Time Defect Reporting

The existing receipt request is extended per line. When
`defective_quantity > 0`, Inventory must provide an allowed category, notes,
and at least one image. The authenticated user and server time supply
`reported_by` and `reported_at`.

The receipt, defect adjustment, accepted inventory, stock movement, and expense
are treated as one operation. If required evidence cannot be stored, the
receipt does not commit. Original quantity, category, notes, and evidence are
immutable after posting. Procurement actions and additional proof are appended
without overwriting Inventory's report.

Defective quantities remain excluded from the initial expense.

## Post-Receipt and Post-Payment Issue Reporting

Add an explicit issue-reporting action for a previously posted receipt item
whose units were accepted and later found defective. This action creates a
`SupplierAdjustment` with `issue_stage = post_payment_issue`; it does not create
another receipt, modify the original receipt, rewrite accepted quantities,
rewrite the original stock movement, or alter the original payment.

The action requires:

- a posted, nonvoid same-shop receipt and PO;
- an accepted quantity greater than zero;
- a posted expense with confirmed paid amount for the affected receipt;
- reported quantity not exceeding the paid accepted quantity after accounting
  for other open post-payment adjustments;
- an allowed category, notes, and at least one image;
- Inventory or another explicitly authorized operations actor.

The report enters the same adjustment lifecycle and is handled by Procurement.
An inventory write-off is not invented by this workflow; any physical stock
correction must use an existing authorized Inventory adjustment mechanism and
must not rewrite the receipt.

## Evidence

`SupplierAdjustment` uses the existing Spatie Media Library. Private media
collections distinguish original defect evidence, supplier refund proof, and
Finance refund-confirmation proof. Media custom properties may link a Finance
proof to its settlement entry, avoiding a new attachment table.

Dedicated endpoints validate MIME type, file size, upload authorization,
same-shop ownership, and download authorization. Original Inventory evidence
cannot be deleted or replaced after the report is committed. Storage failures
must clean up staged files and roll back the related database operation.

## Replacement

Add nullable
`purchase_order_receipt_items.replacement_for_adjustment_id`. Item-level
linkage permits one canonical receipt to contain replacements for different
adjustments and avoids a second receiving workflow.

Inventory receives replacements through `PurchaseOrderReceiptService`. The
service validates the same shop, PO item, replacement resolution, active case,
and remaining replacement quantity. Receipt idempotency continues to prevent
duplicate inventory, movement, expense, and payable records.

For a `receiving_defect`, accepted replacement quantity uses the existing
payable rule and may create its own expense because the original defective
quantity was never payable. For a `post_payment_issue`, accepted replacement
quantity enters Inventory but is not automatically payable again because the
original item was already paid. Any additional supplier charge is outside this
workflow.

The adjustment resolves only when accepted linked replacement quantity fully
satisfies the reported quantity. A defective replacement requires new evidence
and leaves the case unresolved.

## Supplier Refund

Refund is available only against a valid confirmed supplier payment. An
initial receiving defect excluded from the payable cannot request a refund.

Procurement records the expected refund and supplier communication. Procurement
or Finance uploads the supplier's external proof, reference, reported amount,
and transfer date. Supplier proof moves the case to awaiting verification but
does not confirm money received.

Finance confirmation captures actual amount received, bank/reference number,
received date, proof, notes, actor, and timestamp. The existing
`finance_expense_settlements` table gains:

- nullable `supplier_adjustment_id` to link partial confirmations; and
- nullable `notes` for Finance confirmation notes.

`ExpenseSettlementService` gains an append-only `supplier_refund` entry method.
Refund entries preserve the original payment, do not reduce its paid status,
and are returned separately as `refunded_amount`. Finance summaries treat them
as incoming cash against expense outflow, and integrity checks recognize the
new entry type.

Each confirmation uses row locking, a shop-scoped idempotency key, and a unique
source reference. Confirmed totals below expected produce
`partially_refunded`; the adjustment resolves only when confirmed totals reach
the expected amount. Confirmations cannot exceed the expected or refundable
paid amount. An authorized expected-amount change is separately activity-
logged and never rewrites confirmed entries.

## Receipt Voiding

Existing Inventory reversal behavior is preserved.

- A submitted expense may be voided and rejected as it is today.
- A posted but unpaid expense may be voided only when it has no successful
  settlement and no initiating or processing payment attempt. Failed attempts
  remain in history.
- A processing or paid supplier payment blocks receipt voiding. Resolution must
  use the supplier adjustment/refund path.
- No attempt, settlement, refund, adjustment, evidence, or historical
  transaction is deleted or rewritten.

## PO Delivery and Completion

`PurchaseOrderReceiptService` remains the sole owner of `partially_received`
and `delivered`. The design does not redefine `delivered`, make payment a
delivery condition, or allow Procurement to set it manually.

The existing explicit `delivered` → `completed` action gains only these
additional guards:

- all required receipt expenses are posted and fully settled;
- no initiating or processing supplier payment exists; and
- no unresolved supplier adjustment exists.

Completion remains manual and does not change receipt-driven delivery.

## Status Separation

- Fulfillment stays on `purchase_orders.status`.
- Expense workflow remains `submitted` or `posted` for procurement expenses.
- Payment is derived as `unpaid`, `processing`, `paid`, or `failed` from the
  settlement balance and attempt history.
- Quality is derived as clear or has defects from adjustments.
- Adjustment status remains on `supplier_adjustments`.

No duplicate payment, quality, or adjustment columns are added to Purchase
Order or Expense.

## Authorization and Tenant Isolation

- Inventory owns receiving, original defect reporting, evidence, and
  replacement receiving.
- Procurement owns suppliers, manual PR/PO work, supplier communication,
  resolution selection, replacement requests, and refund requests.
- Finance owns Review and Release, payment-profile verification, supplier
  payment, and refund confirmation.
- Suppliers remain external records with no login or portal.

Every new service validates shop ownership again after row locking. Frontend
IDs are never trusted as ownership proof. Evidence routes resolve both the
adjustment and media through the authenticated shop. Provider calls occur only
after every linked record and the shop credential have been verified.

Supplier archival is blocked while unresolved adjustments, unpaid released
expenses, or active payment attempts exist. Historical relations remain
readable for archived suppliers.

## Sorting Security

Purchase Order and Purchase Request list endpoints replace frontend-controlled
`orderBy` inputs with explicit column allowlists and `asc`/`desc` direction
normalization. New adjustment and attempt lists use the same rule. Finance
Expense already uses Spatie QueryBuilder allowlists.

## Notifications

The existing notification service handles:

- receipt/payable creation to Finance;
- Finance release to Procurement;
- PO in transit to Inventory;
- defect or post-payment issue to Procurement;
- replacement request to Inventory and Procurement;
- supplier payment success to Procurement;
- supplier payment failure to Finance;
- supplier refund proof to Finance; and
- confirmed refund to Procurement.

Only minimal new notification enum values are added when current generic types
would be misleading. No new notification table or delivery framework is added.

## Error and Concurrency Handling

- Same idempotency key with different receipt, payment, or refund data returns
  conflict.
- Double clicks, client retries, and concurrent Finance actions cannot create a
  second active payout.
- Missing, disabled, cross-shop, or unverified destinations block payment.
- A payment keeps its encrypted destination snapshot when the supplier profile
  changes.
- Unknown provider outcomes stay processing until verified; they are never
  retried blindly.
- Duplicate and out-of-order webhooks are inert after a terminal result.
- Provider amount, currency, destination, or reference mismatch creates a
  reconciliation condition rather than a settlement.
- Money sent to PayMongo is converted using integer centavos rather than
  floating-point arithmetic.
- Full bank account numbers and provider secrets are excluded from logs,
  notifications, activity metadata, and frontend responses.

## Required Tests

Tests must cover:

- approved PR does not auto-create a PO and manual PO creation still works;
- Procurement status transitions and receipt-owned delivery;
- COD and every supported Net due date;
- immutable PO term snapshot after supplier edits;
- correct receipt details, accepted/defective quantities, and payable amount;
- Review and Release success, misuse, void, replay, and tenant isolation;
- submitted settlement rejection and posted settlement eligibility;
- manual settlement bypass rejection for procurement expenses;
- profile encryption, masking, verification, and cross-shop denial;
- initiating, processing, success, failure, timeout, retry, concurrency, and
  duplicate-webhook payment behavior;
- receiving-time defect validation and immutable evidence;
- post-receipt/post-payment issue eligibility, quantity limits, evidence, and
  preservation of original receipt, stock movement, payment, and settlement;
- replacement inventory, payable distinction by issue stage, linkage,
  idempotency, and resolution;
- supplier proof, Finance proof, partial/full refund, duplicate confirmation,
  over-refund protection, and preserved original payment;
- unpaid receipt void and processing/paid receipt void protection;
- completion payment and adjustment guards;
- sort allowlists; and
- cross-shop denial for every supplier, profile, PO, receipt, expense,
  adjustment, attempt, settlement, refund confirmation, and media path.

## Scope Priority

### MUST HAVE FOR FINAL DEFENSE

Everything in the finalized canonical workflow is mandatory: manual PR-to-PO,
receipt-owned delivery, accepted-quantity payable creation, payment terms and
snapshotting, Finance Review and Release, supplier payment profiles,
shop-specific PayMongo payout attempts, confirmed settlement, receiving-time
and post-payment adjustments, evidence, replacement receiving, supplier refund
verification, PO completion guards, notifications, authorization, tenant
isolation, audit history, sorting allowlists, and the required tests.

The provider contract identified below is a blocker to the live PayMongo
request/webhook portion; it is not permission to substitute a global key or to
fake provider success.

### NICE TO HAVE

None. Additional dashboards, analytics, provider abstractions, automated
reconciliation jobs, supplier self-service, or alternate workflows are outside
this approved scope and should not be added for final defense.

## Implementation Order

1. Add regression tests for current specification conflicts.
2. Add the three new tables and the three necessary existing-table columns.
3. Restrict and snapshot payment terms, expose existing Supplier fields, and
   harden sorting.
4. Add Finance Review and Release.
5. Add supplier payment-profile management and masking.
6. Add payment attempts, shop-specific PayMongo gateway, Pay Supplier action,
   and existing-webhook integration.
7. Add receiving-time and post-payment issue reporting with private evidence.
8. Add replacement linkage through canonical receipt items.
9. Add supplier refund confirmation through `ExpenseSettlementService`.
10. Add receipt-void and PO-completion guards, notifications, and final tenant
    isolation tests.
11. Run focused tests after each scope, then the full Laravel test suite,
    frontend tests, frontend build, and `git diff --check`.

## Explicitly Excluded

- automatic PO creation;
- Pay Before Shipment, prepayment, or early supplier funding;
- supplier credit, balance, carry-forward, or credit application;
- supplier login or portal;
- supplier-managed shipment statuses;
- a second receiving flow;
- a second Finance approval workflow;
- a second settlement or refund ledger;
- direct Procurement delivery marking;
- global settlement of submitted expenses;
- legacy `SupplierOrder` integration;
- frontend PayMongo secrets; and
- rewriting historical payment or receipt records.

## Provider Blocker

Before implementing the gateway request and webhook mapping, the project must
confirm the exact outbound PayMongo product enabled for each shop account, its
endpoint and payload, supported destination bank code, terminal statuses,
retrieval endpoint, test-mode behavior, and webhook event names. Until that
contract is confirmed, provider-specific code must not guess an API shape.
