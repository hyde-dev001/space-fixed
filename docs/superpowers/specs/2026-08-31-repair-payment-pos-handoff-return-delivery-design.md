# Repair Payment, POS, Handoff, and Return-Delivery Workflow Dead Ends

Date: 2026-08-31
Status: Approved design

## Goal

Close the six repair-order workflow dead ends without introducing a second repair
lifecycle or making the browser responsible for payment or handoff authorization.
The repair status, payment state, physical intake, return method, dispatcher
handoff, and shipment state remain separate concerns.

## Scope

This change covers:

- bring-to-shop intake with customer self-pickup;
- derived payment status shown to Repairers;
- separation of the Repairer release/handover action from Dispatcher delivery;
- unpaid third-party return delivery;
- POS repair-order collection eligibility and amount display; and
- stale shop-rider coverage after address or return-method changes.

Unrelated repair lifecycle, payment provider, logistics, and POS flows remain
unchanged.

Terminology: `shop_delivery` means the selected shop provides delivery with its
own rider or delivery arrangement. SoleSpace does not provide that logistics
service. `customer_pickup` means the customer arranges and pays for a
third-party courier.

Actor scope: the changed Repairer/Dispatcher authority applies to company
registration accounts that operate through employee Repairer and Dispatcher
users. Existing individual Shop Owner repair operations remain available,
including the Shop Owner's existing return-handoff action. The Shop Owner-side
projection for `both` business accounts is also not converted into the company
Repairer flow.

## Findings from the current implementation

The existing dead ends have these causes:

1. The POS pages infer the due type and calculate deposit/balance amounts by
   halving a displayed total. They do not receive the current collectible phase
   or amount from the settlement service, so accepted repairs and ready repairs
   can be omitted or charged incorrectly.
2. `RepairWorkflowController::activatePickup` accepts both Shop Owner and
   assigned Repairer callers without distinguishing a pre-dispatch release from
   a post-delivery customer handover. In company accounts, the Repairer UI can
   write `pickup_enabled` for a shop-rider delivery that should first go
   through the Dispatcher. Individual Shop Owner routing remains a compatibility
   path and is not removed.
3. Customer external tracking and pickup confirmation do not consistently
   re-check the authoritative outstanding balance at the mutation boundary.
4. Customer repair presentation duplicates paid/total inference instead of
   consuming a server-calculated outstanding amount. The ready state therefore
   cannot reliably expose the remaining-payment action.
5. `ReturnDeliveryPlanCard` keys its coverage request primarily by address and
   retains accepted fee/coverage state across return-method changes. It can show
   an old `outside_coverage` result after the selected address changes back.
6. Acceptance and customer messaging rely on the legacy delivery field in places
   where the explicit intake method is the canonical choice, leaving walk-in
   requests without a clear initial-payment/drop-off next step.

## Design

### 1. One authoritative repair collection summary

Extend `PaymentSettlementService` with a small public summary operation that
reuses the existing repair payment breakdown, phase resolution, settled checks,
ledger/session records, delivery payment details, and refund/reconciliation
guards. It must return the current server-authoritative collection state,
including:

```text
collectible
due_type                  deposit | full | balance | null
phase                     initial | final | recovery | null
collectible_amount       exact amount collectable in this phase
outstanding_balance      total amount still owed on the repair
service_amount
delivery_amount
total_paid_amount
grand_total
fully_paid
```

The summary must return zero/non-collectible for cancelled, rejected, fully paid,
refunded, or reconciliation-blocked repairs. Recovery-only phases such as
pickup retry or redelivery remain in the existing recovery flow and are not
presented as ordinary POS deposit/balance collection.

`outstanding_balance` is the total amount still owed on the repair.

`collectible_amount` is the exact amount that the current legitimate payment
operation may collect. The two values must not be treated as interchangeable.
POS and checkout use `collectible_amount`; payment-required presentation and
final-release/handoff guards use `outstanding_balance` or `fully_paid`.

Neither value is computed by the frontend or inferred from a status label. The
existing checkout endpoint remains the final authority: it must continue to
lock the repair/payment phase, validate the exact gross amount, enforce tenant
scope, and honor the existing idempotency/phase uniqueness constraints.

No new repair status, payment table, or balance column is required.

### 2. API response contract

Add the summary to the existing response shapes used by:

- customer `myRepairs`;
- Repairer job orders; and
- the `scope=pos_checkout` repair-order list used by both POS pages.

The response may retain existing display totals for compatibility, but the
affected UIs must use the summary for `outstanding_balance`, payment phase,
payment action availability, and handoff gating. POS records must also expose
the repair identifier, customer, service/package, grand total, amount paid,
`collectible_amount`, and collection phase.

For `scope=pos_checkout`, the server response should include only repair orders
with a legitimate ordinary `collectible_amount`, or include an explicit
server-provided `collectible` flag that the picker uses. In both cases, the
checkout mutation independently revalidates the repair and amount.

Eligibility combines shop/tenant scope, repair lifecycle, payment phase, exact
outstanding amount, cancellation/rejection/refund state, and existing
authorization. It must include:

- accepted repair with an outstanding initial deposit/full payment; and
- ready-for-return/pickup repair with an outstanding final balance.

It must exclude fully paid, cancelled, rejected, non-collectible, and other-shop
records.

### 3. Bring-to-shop and customer self-pickup flow

Use the explicit `intake_delivery_method` for acceptance branching and customer
messaging, while preserving legacy fields for compatibility.

After acceptance, a walk-in/bring-to-shop repair with an outstanding initial
amount:

1. appears in the POS repair picker;
2. is payable only for the exact outstanding initial amount;
3. records payment against the existing repair request; and
4. remains physically unreceived until the existing receipt/proof rules pass.

Customer UI shows:

```text
Repair request accepted. Bring your shoes to the shop and complete the required
payment so the shop can receive the item.
```

When the initial amount is settled, the message changes to a physical-drop-off
instruction. Payment success never marks the repair as physically received.

### 4. Derived Repairer payment indicator

Repairer job-order list and relevant detail views consume the API summary. When
the canonical repair status is ready for return/pickup and
`outstanding_balance > 0`, show a secondary indicator such as:

```text
Ready for Pickup
Waiting for Payment
```

This is presentation only. It does not add or persist `waiting_for_payment`.
When the balance reaches zero, the indicator disappears on the next response and
the existing handoff/dispatch state is shown.

### 5. Repairer release and Dispatcher handoff authority

The return flow has two different shop-side actions: releasing the repaired
item into the correct next step, and recording that the customer or courier
actually received it. They must not share an ambiguous authorization rule.

The authority matrix is:

| Return method | Release/handover authority | Next step |
| --- | --- | --- |
| Walk-in/direct shop release | Assigned Repairer records the actual customer handover | Customer confirms receipt |
| Customer-arranged courier | Assigned Repairer records the actual courier handover | Customer confirms receipt |
| Shop rider delivery | Assigned Repairer releases the ready repair for dispatch; Dispatcher controls delivery | Dispatcher approves delivery proof, then customer confirms receipt |

For company accounts, the Shop Owner is not the operational actor for the
repair return. The company Repairer/Dispatcher route is changed while the
existing individual Shop Owner repair-management and return-handoff operations
remain unchanged. The Shop Owner-side projection for `both` accounts remains
read-only as before.

For a company account using `shop_delivery`, `markReadyForPickup` is the
Repairer's pre-dispatch release. It keeps the canonical `ready_for_pickup`
status and must not set `pickup_enabled`. Once the full payment and exact
return plan are valid, the existing return-shipment service places the
`repair_return` leg in the Dispatcher queue. The Dispatcher then schedules,
assigns, and dispatches the shop's own rider through the existing logistics
services. Individual Shop Owner accounts keep their existing handoff route.

The Dispatcher proof-approval transaction remains the post-delivery authority.
After an approved `repair_return` delivery proof, it invokes the shared handoff
mutation after checking the canonical full-payment summary. That mutation sets
the existing `pickup_enabled`/lock fields, preserves current notification and
audit conventions, and does not create a new repair status. A company Repairer cannot
use the customer-handover action for a `shop_delivery` repair before or after
Dispatcher delivery; the Repairer's release is the separate `markReadyForPickup`
step.

For company `walk_in` and `customer_pickup` (customer-arranged courier), the
assigned Repairer may record the actual handover only after the repair is ready
and fully paid. This sets the existing customer-receive readiness fields;
payment alone never does so. The customer then confirms receipt. These methods
do not create a shop-rider shipment or enter the Dispatcher delivery queue.
Individual Shop Owner accounts retain their current direct handoff behavior.

The following invariants remain enforced server-side:

- repair acceptance does not imply physical receipt;
- payment does not imply physical receipt;
- ready does not imply fully paid;
- fully paid does not imply handed off;
- Dispatcher delivery approval does not imply repair completion; and
- tracking submission does not imply payment confirmation.

All state-changing operations use the existing transaction/row-lock patterns.

Required outstanding balance greater than zero means no physical release, no
customer pickup confirmation, no courier handoff, and no shop-rider dispatch,
except where an existing canonical no-charge, warranty, or recovery rule
explicitly permits the operation.

### 6. Third-party return payment and tracking gate

When a repair is ready for a customer-arranged/third-party return and the
summary reports an outstanding balance, the customer page shows `Pay Remaining
Balance` using the existing retry-payment-session flow and the exact amount
returned by Laravel.

Until the balance is zero:

- actionable return tracking submission is hidden or disabled;
- `updateExternalTracking` rejects the request server-side with
  `Complete the remaining payment before arranging the return courier.`;
- no courier handoff/dispatch transition can be initiated; and
- the page explains why return arrangement is blocked.

After successful full payment, the existing customer page refreshes its repair
data and unlocks tracking without requiring an unrelated repair status change.
Existing warranty/no-charge and recovery exceptions continue to use their
canonical payment rules.

### 7. Coverage state and return-plan recalculation

The customer return planner treats coverage as belonging to the current return
method plus the complete identity of the selected address:

```text
return method + selected address identity + coordinates/address revision
```

Changing the saved address, pinned address, same-as-intake choice, return
method, or any coverage input clears the prior coverage result, error, quote,
and accepted fee before requesting/reusing a result for the new selection. The
frontend may represent this as a key such as
`shop_delivery:address_42:14.1234:120.5678`. Responses are ignored if they no
longer match the current method/address/revision key, so a delayed response for
an earlier selection cannot overwrite the current state.

Shop-rider coverage and fee requests run only for `shop_delivery`. Customer
pickup/walk-in methods do not display Shop-rider validation errors. The backend
continues to revalidate coverage, address snapshots, method, and accepted fee
when the plan is saved or a shipment/payment is created.

The state sequence below must work without a page reload:

```text
covered address A -> uncovered address B -> covered address A
```

The final state must show coverage and the current fee for A.

## Likely implementation areas

Backend:

- `app/Services/PaymentSettlementService.php`
- `app/Services/RepairDeliveryService.php`
- `app/Http/Controllers/Api/RepairWorkflowController.php`
- `app/Http/Controllers/Api/RepairRequestController.php`
- `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- repairer/shop-owner route files containing `activate-pickup`

Frontend:

- `resources/js/Pages/ERP/cashier/POS.tsx`
- `resources/js/Pages/ERP/repairer/POS.tsx`
- `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- `resources/js/Pages/UserSide/Repairs/myRepairs.tsx`

Tests will reuse the existing repair payment, POS, intake, return-handoff,
Repairer workflow, warranty, coverage, and frontend suites rather than create a
parallel test harness.

## Test plan

### Laravel feature coverage

- walk-in accepted repair is POS-visible and collects only the initial amount;
- physical receipt remains blocked until the item/proof is actually received;
- ready repair with a remaining balance is POS-visible and collects only that
  balance;
- payment summary distinguishes a total outstanding balance from a smaller
  currently collectible phase amount;
- fully paid, cancelled, rejected, and different-shop repairs are excluded;
- duplicate checkout/retry remains idempotent;
- customer third-party tracking and handoff mutations reject unpaid repairs and
  unlock after full settlement;
- the assigned company Repairer can record walk-in/customer-courier handover;
- individual Shop Owner accounts retain their existing repair-return handoff
  route, while company Shop Owner access does not replace the company Repairer
  flow;
- dispatcher proof approval activates the existing customer receive state for
  shop-rider repair returns, while unpaid or unapproved returns remain blocked;
- existing dispatcher, shipment, refund, warranty, intake, and private-storage
  behavior remains green.

### React coverage

- POS displays server-provided total, paid, remaining, and phase and does not
  halve totals locally;
- Repairer UI has no customer-receive activation control and shows the derived
  waiting-for-payment indicator;
- customer walk-in acceptance provides the next-step message;
- customer pickup/third-party ready repairs expose remaining payment when due;
- tracking is blocked in the unpaid state and available after refresh/full pay;
- coverage passes covered A -> uncovered B -> covered A;
- a delayed response for address A cannot overwrite a newer selection B;
- changing return method clears irrelevant Shop-rider errors and stale fees.

## Non-goals

- no new persisted repair status;
- no replacement payment or logistics subsystem;
- no browser-only authorization;
- no broad schema change or data rewrite;
- no redesign of unrelated repair, POS, payment, or logistics screens; and
- no change to existing customer-arranged courier configuration beyond the
  unpaid-balance gate.

## Verification

Run focused Laravel and frontend tests first, then the repository quality gates:

```text
php artisan test <focused repair/POS/logistics tests>
pnpm run test:frontend -- <focused frontend tests>
pnpm run build
git diff --check
composer test
```

The final report will identify each root cause, changed files, eligibility and
payment rules, tests added/updated, and remaining edge cases. Type-checking and
linting will not be reported unless the repository provides and runs those
tools.
