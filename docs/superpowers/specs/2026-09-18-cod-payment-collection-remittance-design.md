# COD Payment, Collection, Remittance, and Refund Design

**Date:** 2026-09-18

**Status:** Final authoritative input for implementation planning

**Scope:** Retail COD checkout, delivery collection, rider remittance, Finance confirmation, and COD customer refunds in the `fix/procurement-supplier-payment-workflow` worktree

## 1. Summary

SoleSpace already has a working PayMongo online-payment path, a logistics delivery lifecycle, an append-only Finance invoice-payment history, an approval-based retail refund workflow, and shared Xendit integration settings used by supplier payouts. The missing COD path currently writes only a payment-method string, sends checkout into PayMongo, and marks COD paid when delivery is confirmed.

This design adds a dedicated COD operational ledger without replacing those existing systems:

```text
Customer selects COD
  -> COD order and pending collection record
  -> existing shipment / batch / rider stop flow
  -> rider records Cash Collected
  -> rider submits a remittance batch
  -> Finance confirms the physical cash received
  -> COD collection and remittance become settled
  -> one append-only Finance payment entry is recorded
```

COD refunds remain rows in `order_refunds`. The refund stores an encrypted customer destination and uses a separate COD/Xendit payout state. The original order payment method remains `cod`; it is never changed to GCash, bank transfer, or Xendit. Xendit payout completion is webhook-driven and idempotent.

PayMongo behavior remains unchanged for online checkout and online refunds.

## 2. Goals

- Make `cod` a canonical checkout payment method that bypasses PayMongo.
- Keep fulfillment status, payment method, COD collection status, remittance status, and refund/payout status separate.
- Record one authoritative COD collection per retail order.
- Allow only the assigned rider to collect cash for the assigned COD shipment leg.
- Let riders submit their own collections but never settle their own remittance.
- Let Finance confirm physical cash receipt only within the rider's shop.
- Record the final COD receipt in the existing append-only Finance money-history layer without treating rider collection as settled Finance cash.
- Reuse the existing `OrderRefund` workflow for COD refunds.
- Require and protect a GCash or bank destination for COD refunds.
- Reuse shared Xendit transport/configuration while keeping supplier-payout business rules separate.
- Make critical actions transaction-safe, row-locked, and idempotent.
- Preserve existing logistics routing, batch ordering, current-stop enforcement, GPS tracking, and customer tracking.
- Add regression coverage for both PayMongo and the complete COD lifecycle.

## 3. Non-Goals

- Replacing PayMongo, changing PayMongo webhooks, or routing online orders through Xendit.
- Rewriting shipment, batch, GPS, stop-order, proof-of-delivery, or failed-delivery business rules.
- Creating a second retail refund model or a second generic Finance accounting system.
- Allowing Finance to settle a remittance with an unexplained variance. A mismatch is recorded as `disputed` and remains blocked for safe follow-up.
- Supporting partial rider remittance, manual collection amount overrides, or automatic cash variance resolution in this change.
- Adding general-ledger, bank-reconciliation, or treasury features.
- Logging or displaying full payout destination details outside the authenticated customer/authorized payout execution boundary.

## 4. Existing Architecture Reused

| Concern | Existing owner | COD integration |
|---|---|---|
| Customer checkout | `UserSide\\CheckoutController`, `payment.tsx` | Normalize `cod`; skip retry-payment-session and PayMongo redirect |
| Retail order | `Order` | Keep only canonical payment metadata and existing invoice reference |
| Payment settlement | `PaymentSettlementService` | Retain online settlement; do not use it to mark COD at delivery |
| Delivery authorization | `ShipmentController`, `LogisticsActorPolicy`, `ShipmentLegService` | Gate Cash Collected with existing custody/current-stop rules |
| Rider workspace | `MyDeliveries.tsx`, `ErpLogisticsController` | Add COD amount/status and collection action |
| Staff/job orders | `StaffOrderController`, shop-owner order controller, job-order pages | Serialize and display COD operational state |
| Finance money history | `finance_invoice_payments`, `InvoicePaymentService` | Add a controlled operational COD-recording path after remittance settlement |
| Refund workflow | `OrderRefund`, `OrderRefundService`, `RefundApprovalController`, `refundApproval.tsx` | Branch only where original order payment method is COD |
| Xendit configuration | `ShopPaymentIntegration` | Reuse the existing shop Xendit credentials/callback token |
| Xendit supplier transport | `XenditPayoutService` | Extract only common HTTP/auth/sanitization primitives; retain supplier rules separately |
| Audit | Spatie activity log and existing Finance audit conventions | Add redacted COD collection, remittance, approval, payout, and webhook events |
| Notifications | `NotificationService`, logistics notification infrastructure | Add deduplicated rider/Finance/customer notices using existing notification storage |

The existing `finance_invoice_payments` table is invoice-specific, but retail orders already have a `finance_invoices` relationship and the existing invoice generator supports COD invoices. It is therefore usable as the final operational payment history when guarded by a dedicated order-COD method. The generic Finance manual-payment path must continue to reject operational invoices; only the COD settlement service may write the controlled source value `cod_remittance` after locking and validating the linked order, invoice, remittance, and shop.

## 5. State Ownership and Invariants

### 5.1 Separate state dimensions

```text
Order fulfillment:     pending -> processing -> shipped -> delivered / cancelled
Payment method:         paymongo | cod                         (immutable after checkout)
COD collection:         pending -> cash_collected -> settled
Remittance:             submitted -> settled | disputed
Refund:                 requested -> pending_approval -> processing -> succeeded / failed / rejected
COD payout:             not_started -> processing -> succeeded / failed / rejected / reversed
```

`delivered` does not imply `cash_collected`. `cash_collected` does not imply `remittance settled`. A rider-collected but unremitted COD order must not be shown as a generic paid/settled order.

The existing `orders.payment_status` remains a compatibility summary for online consumers. For COD it remains open/pending until Finance confirms remittance. COD-specific UI reads the dedicated collection/remittance records and must not infer settlement solely from `payment_status`.

### 5.2 Collection invariants

- Exactly one `cod_collections` row exists per COD order.
- `expected_amount` is copied from the authoritative order total at creation and is not rider-editable.
- `collected_amount` must equal `expected_amount` in this change.
- A collection can move to `cash_collected` only for an assigned rider, assigned shipment leg, COD order, eligible delivery state, and non-cancelled/non-refused shipment.
- The collection action is safe to retry. Once `cash_collected`, the existing row is returned for an equivalent request; a conflicting amount or actor is rejected.
- Rider collection does not update `orders.payment_status`, invoice paid state, or Finance ledger state.

### 5.3 Remittance invariants

- A remittance contains only collections from one shop and one rider.
- The server computes `expected_amount` and `submitted_amount` from locked collection rows; the rider cannot alter either amount.
- A collection can be linked to at most one remittance row. A unique `cod_remittance_items.cod_collection_id` constraint prevents duplicate active or historical remittance links.
- Submission leaves collections in `cash_collected` and creates a `submitted` remittance.
- "Cash currently held" includes both unremitted `cash_collected` collections and collections inside a submitted remittance until Finance confirms receipt; submission is not a handoff or settlement.
- Finance must enter the physical amount received. An exact match is required to settle. A mismatch records `variance_amount`, sets the remittance to `disputed`, and performs no collection settlement or Finance ledger write.
- Only Finance can transition `submitted` to `settled` or `disputed`.
- A disputed remittance is safely blocked for this release; it is not silently resubmitted or overwritten.

### 5.4 Refund invariants

- `Order.payment_method` remains `cod` for the entire order lifetime.
- A COD refund can exist only when a collection is at least `cash_collected`; a refusal before cash collection creates no payout.
- A request or approval may be staged after cash collection so Finance can review it before handoff, but the final refundable ceiling is always the settled COD amount less active and successful refund amounts, calculated in cents inside the payout lock. No payout may execute against cash that is only collected or submitted.
- Review and approval may occur while remittance is submitted, but payout execution is blocked until the linked remittance is settled.
- One `OrderRefund` has at most one COD payout attempt. A deterministic refund-based idempotency key protects local and provider retries.
- Only authenticated Xendit webhook events can mark a COD payout succeeded, failed, rejected, or reversed.
- Duplicate webhook events are no-ops after the terminal state has been reached and cannot create a second receipt, payout, notification, or audit event.

## 6. Data Model

### 6.1 `cod_collections`

Add a dedicated table with:

- `id`
- `shop_owner_id` FK
- `order_id` FK, unique
- nullable `shipment_id` FK and `shipment_leg_id` FK, populated when the assigned delivery leg is known
- nullable `rider_profile_id` FK and `rider_user_id` FK snapshot for the collecting rider
- `expected_amount` decimal(18,2)
- nullable `collected_amount` decimal(18,2)
- `status` string constrained by the service to `pending`, `cash_collected`, `settled`
- nullable `collection_reference` unique per shop/order
- nullable `collection_idempotency_key`
- nullable `collected_at`, `collected_by_user_id`
- nullable `settled_at`, `settled_by_user_id`
- timestamps

Indexes cover `(shop_owner_id, status)`, `(rider_profile_id, status)`, and the order uniqueness. The row is the authoritative collection fact; order and shipment records only expose a read model of it.

The checkout transaction creates the pending row. A lazy `ensureForOrder` path is permitted for legacy COD orders encountered by the delivery/refund workflow, but it must still lock the order and never create a second row.

### 6.2 `cod_remittances`

Add a dedicated table with:

- `id`
- `shop_owner_id` FK
- `rider_profile_id` FK and `rider_user_id` FK
- `reference` unique per shop
- `expected_amount`, `submitted_amount`, nullable `received_amount`, nullable `variance_amount` decimal(18,2)
- `status`: `submitted`, `settled`, `disputed`
- `idempotency_key` unique per shop
- `submitted_at`, `submitted_by_user_id`
- nullable `confirmed_at`, `confirmed_by_user_id`
- nullable `dispute_reason`
- timestamps

### 6.3 `cod_remittance_items`

Add a relational join table with:

- `id`
- `cod_remittance_id` FK
- `cod_collection_id` FK
- `expected_amount` decimal(18,2) snapshot
- timestamps

Use unique indexes on `(cod_remittance_id, cod_collection_id)` and `cod_collection_id`. This makes duplicate remittance membership impossible at the database layer.

### 6.4 `order_refunds` COD payout fields

Extend, do not replace, `order_refunds` with:

- `refund_destination_type`: `gcash` or `bank`
- `refund_destination`: encrypted array containing only the validated payout destination snapshot
- `refund_provider`: nullable provider identifier, `xendit` for COD refunds
- `payout_status`: `not_started`, `processing`, `succeeded`, `failed`, `rejected`, `reversed`
- `payout_idempotency_key` unique when present
- nullable provider payout/reference IDs
- payout initiated/succeeded/failed/reversed timestamps
- payout failure code/message using safe, provider-redacted text

For GCash, the encrypted snapshot contains account name and number. For bank, it contains channel/bank, account holder name, and account number. Normal serializers expose only destination type, provider, and masked account details. Activity metadata contains no full destination, provider payload, API key, callback token, or raw webhook body.

Existing PayMongo columns and `payment_gateway` behavior remain intact for online refunds. COD refund serialization derives the original method from the order and exposes `refund_provider` separately so Finance cannot mistake a COD payout for a PayMongo refund.

### 6.5 Existing Finance money history

Add `cod_remittance` to the controlled `InvoicePayment` source values. The final settlement path uses a dedicated method that:

1. locks the remittance, its items, collections, orders, and linked invoices;
2. verifies the remittance is submitted and the received amount exactly matches;
3. verifies each invoice belongs to the same shop and has the expected total;
4. appends exactly one `finance_invoice_payments` row per COD order with source `cod_remittance`, payment method `cash`, a remittance/order reference, and a deterministic idempotency key;
5. marks the invoice paid and order compatibility payment status paid;
6. marks each collection and the remittance settled in the same transaction.

The operational COD records remain authoritative for collection/remittance state. The Finance payment row is the final accounting history, not the collection trigger. If a legacy COD order has no usable invoice, the service uses the shared order-invoice ensure path under the same locks; it never writes an invoice-less fake ledger row.

## 7. Backend Services and Responsibilities

### 7.1 COD collection/remittance service

Create one focused service responsible for:

- canonical COD detection and `ensureForOrder`;
- rider `cashCollected` transition;
- rider collection listing and remittance submission;
- Finance remittance listing/detail/confirmation;
- amount normalization in cents;
- shop/rider/leg authorization decisions that delegate to existing logistics policy helpers;
- database transactions, row locks, idempotency, activity logs, and notifications.

The service must not change shipment sequencing or proof rules. The existing delivery confirmation services must stop calling the current COD `markCodPaymentPaid` behavior. They may still mark delivery status and customer receipt state according to their existing rules.

### 7.2 Order invoice integration

Extract or centralize the existing order-invoice generation logic into the smallest reusable `OrderInvoiceService` boundary needed by online settlement and COD creation/settlement. It must preserve existing invoice fields and item calculations. COD invoices are documentary and remain unpaid/sent until remittance settlement.

### 7.3 Refund service extension

Extend `OrderRefundService` rather than adding another retail refund service:

- COD request path validates collection eligibility and destination details.
- COD reserve path verifies cash collection for initial eligibility and locks the order/collection/refunds. The execute path recomputes the strict settled-COD ceiling under lock before allowing a payout, so a pre-settlement approval cannot bypass the settled-funds rule.
- Existing approval stages and return gates remain in force.
- COD execute path checks final approval, return rules, collection/remittance settlement, and remaining refundable amount before creating the payout attempt.
- PayMongo paths retain their current provider calls and order settlement behavior.
- COD success updates only COD refund/payout state and refund audit/notifications; it does not change the original payment method to Xendit and does not call the PayMongo settlement path.

### 7.4 Shared Xendit provider layer

Refactor only transport concerns from `XenditPayoutService` into a shared client/primitives boundary:

- authenticated HTTP client and endpoint selection;
- credentials verification;
- payout-channel discovery;
- provider request idempotency header;
- safe error/status parsing and redaction.

Keep supplier payout payload validation, settlement, supplier notifications, and `SupplierPaymentAttempt` rules in the supplier service. Add a separate `CodRefundPayoutService` using the shared client and `OrderRefund` fields. It must not create supplier payment attempts or supplier expense settlements.

The COD service uses the shop's existing connected Xendit payout integration and callback token. No new payout backend or alternate credentials store is introduced.

### 7.5 Xendit webhook routing

Keep `/api/webhooks/xendit/payout`. Authenticate the callback token against the shop integration before mutation. Route the event to a COD refund when payout/reference IDs match an `OrderRefund` COD payout; otherwise retain the supplier attempt path. For COD:

- accept only the supported authoritative payout events;
- verify refund ID, shop, provider reference, and amount/currency;
- lock the refund and linked order/collection;
- ignore duplicate terminal events safely;
- mark only the corresponding local payout/refund state;
- emit one redacted audit event and deduplicated notification per effective transition.

## 8. HTTP Routes and Authorization

### Customer

The existing customer order/refund authentication remains the entry point. Extend the existing refund request payload for COD destinations; do not add a parallel refund submission system.

### Rider / Logistics

Use the existing `auth:user`, shop isolation, and logistics permission family:

- `POST /api/logistics/legs/{leg}/cash-collected`
- `GET /api/logistics/cod-collections`
- `POST /api/logistics/cod-remittances`
- `GET /erp/logistics/cod-collections`

The action requires the existing rider operation capability and custody/current-stop assignment checks. A rider may only read their own collections and submit their own remittance. No rider route can confirm or settle a remittance.

### Finance

Add a focused Finance route family protected by `access-cod-remittances` plus existing web/session, user authentication, and shop isolation:

- `GET /api/finance/cod-remittances`
- `GET /api/finance/cod-remittances/{id}`
- `POST /api/finance/cod-remittances/{id}/confirm`
- `GET /finance/cod-remittances`

Confirmation is Finance-only and requires exact amount. Existing `access-refund-approval` continues to protect refund approval/execution. The existing refund execution endpoint branches by original payment method and calls the COD payout service only for COD refunds.

Add `access-cod-remittances` to the permission catalog and Finance role assignments. Do not grant it to riders. Every query and mutation uses the authenticated user's shop context; route model binding must not bypass that scope.

## 9. Frontend Behavior

### 9.1 Customer checkout

In `payment.tsx`:

- offer PayMongo and COD using the existing payment-page style;
- keep PayMongo selected/default behavior for existing online customers;
- send canonical `payment_method: cod` for COD;
- after order creation, skip `/retry-payment-session` for COD;
- show the authoritative amount due and COD explanation;
- navigate to the existing order confirmation/orders experience without a PayMongo URL.

No online checkout branch is removed or generalized away.

### 9.2 Job Order / staff / dispatcher

Extend existing order and shipment serializers and cards with:

- `Payment Method: Cash on Delivery`
- `COD Amount Due`
- `COD Collection Status`
- rider/collection timestamp when available
- `Remittance Status`

Use explicit labels such as `COD - Pending Collection`, `COD - Cash Collected`, `Remittance - Pending`, `COD - Settled`, and `Remittance - Received`. Do not replace the existing order/shipment status chips or stop behavior.

### 9.3 Rider

In the existing My Deliveries current-stop view, show COD amount/status and a `Cash Collected` action only when the backend says it is eligible. The action confirms the exact amount and refreshes the stop state.

Add `ERP/Logistics/CodCollections.tsx` for the rider's own:

- cash currently held;
- pending-remittance collections;
- submitted remittances;
- settled remittance history.

The page supports selecting eligible own collections, displays a server-computed total, and submits one remittance. It has no settle/confirm control.

### 9.4 Finance

Add `ERP/Finance/CodRemittances.tsx` with list/detail views showing rider, reference, included orders, expected/submitted/received/variance amounts, timestamps, and status. Exact confirmation is the only settlement action. A mismatch becomes visibly disputed and cannot be silently settled.

Update `refundApproval.tsx` so COD rows explicitly show original payment COD, collected amount, remittance status, refund destination/provider, and the disabled-execution explanation when remittance is not settled. PayMongo rows retain existing labels and actions.

## 10. Notifications and Audit

Use existing notification storage and deduplication patterns. Add the minimum notification types/helpers needed for:

- Finance notified when a rider submits a remittance;
- rider notified when Finance settles or disputes it;
- customer notified when a COD refund payout begins/completes/fails;
- Finance notified when a COD payout needs attention.

Audit/activity events include:

- `cod_cash_collected`
- `cod_remittance_submitted`
- `cod_remittance_settled`
- `cod_remittance_disputed`
- COD refund approval/rejection
- `cod_refund_payout_initiated`
- `cod_refund_payout_succeeded`
- `cod_refund_payout_failed`
- `cod_refund_payout_reversed`

Properties are limited to shop ID, order/refund/remittance IDs, references, amounts, statuses, actor IDs, and safe reason codes. Full account numbers, GCash numbers, bank details, secrets, callback tokens, and raw provider payloads are excluded.

## 11. Transaction, Locking, and Idempotency Rules

- Cash collection: lock the leg, order, and collection; validate custody and state; update the existing row; rely on the unique order constraint and request-key replay.
- Remittance submission: lock each selected collection in deterministic ID order; validate one shop/rider and no existing remittance item; create remittance and items in one transaction.
- Remittance confirmation: lock remittance, items, collections, orders, and invoices in deterministic order; exact-amount check; write Finance payment entries and all state changes atomically.
- Refund reservation/approval: reuse existing order/refund lock and active-refund collision rules, with a COD collected-amount ceiling.
- COD payout execution: lock refund and linked COD settlement; return existing processing/terminal state on replay; create one deterministic local payout state before making the provider call.
- Provider retries: always send the deterministic refund idempotency key to Xendit. Unknown transport results leave the payout processing for webhook reconciliation rather than creating another attempt.
- Webhooks: authenticate before mutation, verify identifiers and amount, lock the refund, apply only valid state transitions, and make terminal handling idempotent.

All monetary comparisons use integer cents or normalized two-decimal strings. No frontend amount is authoritative.

## 12. Testing Strategy

### Backend feature tests

Add coverage for:

- COD checkout creates a pending collection, invoice/documentary state, and no PayMongo session/request.
- Existing PayMongo checkout and payment confirmation still use PayMongo.
- COD collection rejects unassigned riders, wrong shop, wrong leg, cancelled/refused orders, wrong amount, and duplicate/concurrent submissions.
- Delivery confirmation does not settle COD.
- Rider collection listing is tenant- and rider-scoped.
- Remittance submission computes totals, links relational items, rejects mixed riders/shops, and prevents duplicate active/historical links.
- Finance sees only same-shop submitted remittances; rider cannot confirm.
- Exact Finance confirmation settles remittance/collections/invoices and writes one append-only Finance payment per order.
- Variance becomes disputed without settlement or duplicate ledger entry.
- COD refund destination validation, encryption, masking, and no-sensitive-log behavior.
- Refund request/approval is bounded by collected COD value and concurrent requests cannot over-refund.
- COD execution is blocked before remittance settlement and does not call PayMongo.
- Xendit payout creation is local/provider-idempotent and asynchronous.
- Authenticated Xendit success, failure, rejected, pending, reversed, duplicate, mismatched, and cross-shop webhooks.
- Existing PayMongo refund behavior remains unchanged.

### Frontend tests

Add focused tests for:

- COD checkout skips the PayMongo retry endpoint and shows COD confirmation.
- COD labels/statuses in job orders and shipment summaries.
- Rider collection action and own-remittance submission states.
- Finance remittance detail/confirmation and variance block.
- Finance refund screen identifies COD/Xendit and disables execution before settlement.

### Quality and manual verification

Run the repository's narrow frontend/backend tests first, then the relevant full suites, build, and `git diff --check`. Browser verification must cover Customer, Job Order/Staff, Dispatcher, Rider, and Finance using same-shop and cross-shop actors. No completion claim is made for a path whose test or manual evidence was not run.

## 13. Rollout and Compatibility

- Add only forward migrations; do not rewrite existing migrations.
- Preserve legacy payment-method aliases at input boundaries but store canonical `paymongo` or `cod` for new orders.
- Existing COD rows without a collection are initialized lazily under a locked, idempotent service path. Historical paid COD records are not reinterpreted as new rider cash events without an explicit legacy reconciliation decision.
- Keep current PayMongo columns and webhooks untouched except for safe COD guards.
- Do not remove existing supplier payout tables or supplier webhook behavior.
- Update durable developer guidance only if implementation uncovers a reusable project-wide lesson.

## 14. Acceptance Criteria

The implementation is acceptable only when all of the following are true:

1. COD checkout creates no PayMongo checkout session.
2. PayMongo checkout, confirmation, and refund regression tests still pass.
3. A rider can collect only an assigned COD shipment, once, for the server-authoritative amount.
4. Delivery completion alone does not settle COD.
5. A rider can submit, but never settle, a remittance containing only their own collections.
6. Finance can view and confirm only same-shop submitted remittances.
7. Exact confirmation settles remittance and collections and creates one Finance ledger entry per order.
8. Variances are visible and blocked, not silently posted.
9. Job orders and logistics views distinguish COD pending collection, cash collected, pending remittance, and settled.
10. COD refunds retain original payment method COD and require encrypted/masked GCash or bank destination data.
11. COD refund payout execution is blocked until remittance settlement.
12. COD payouts use Xendit asynchronously with local/provider idempotency and authenticated idempotent webhooks.
13. No cross-shop or role-boundary path succeeds.
14. The final report includes exact changed areas, lifecycle diagrams, tests/results, manual QA, and limitations without overstating coverage.
