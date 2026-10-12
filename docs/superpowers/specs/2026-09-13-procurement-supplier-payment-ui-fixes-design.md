# Procurement Supplier Management, Payment Review, and Finance Refresh Fixes

## Status

Approach 1 (surgical reuse of the existing workflow) was approved on
2026-09-13. This written specification is awaiting the user's file review.
It is a targeted bug-fix design; production implementation is not part of
this document change.

## Objective

Fix the current Procurement Supplier Management, Inventory notification,
Finance procurement-expense, Shop Owner supplier-payment review, and supplier
receipt-email regressions without creating parallel supplier, purchase-order,
expense, settlement, notification, approval, proof-storage, or payment
systems.

The existing canonical workflow remains:

```text
Stock Request
-> Purchase Request
-> Approvals
-> Purchase Order
-> Sent / Confirmed
-> In Transit
-> Receiving
-> Inventory increase
-> Procurement Finance Expense
-> Finance Review & Release
-> Shop Owner verification when required
-> Manual supplier payment / settlement
-> Paid
```

Automated PayMongo supplier disbursement remains externally blocked. This
change must not add, fake, or modify PayMongo supplier payout requests,
transfer IDs, webhooks, or dashboard state. Existing customer PayMongo
payment/refund behavior remains untouched.

## Audited root causes

1. `SuppliersManagement.tsx` contains two source JSX groups for City,
   Country, Payment Terms, Lead Time, and Products Supplied in the Add modal.
   This is duplicated rendering, not a styling problem.
2. Add Supplier submits only supplier fields. The existing payment-profile
   endpoint is reached only from Edit Supplier, so a profile cannot be created
   atomically with a new supplier.
3. The existing payment-profile schema is generic bank-shaped storage. Manual
   payments already support both bank transfer and e-wallet methods, but the
   profile UI exposes only `bank_account` and the request accepts arbitrary
   destination values. There are no separate wallet-provider columns.
4. The backend already encrypts account numbers and returns masked data, but
   the form needs a controlled empty input for saved profiles, explicit
   replacement behavior, and a local show/hide control for newly entered data.
5. `PurchaseOrderService::updateStatus()` calls the existing in-transit
   notification after commit, but `NotificationService` targets only the
   `Inventory Manager` role, sends an incomplete payload, and does not provide
   a PO-specific idempotency group key.
6. The Add Supplier, Finance Expense, and Supplier Payment modals lack the
   existing max-height/flex/scrollable-body/sticky-header-and-footer pattern.
7. Finance refetches the expense query after payment actions, but
   `activeExpense` remains the previous object, leaving the open modal stale.
8. Shop Owner and Finance proof endpoints use forced downloads, while the
   payment dialog opens a new browser tab for a filename link. The proof is
   therefore not an inline approval-review interaction.
9. The existing supplier confirmation mailable is sent after settlement, but
   it lacks shop/business name, receipt number, and explicit payment status.
   The payment dialog displays a generic uppercased email status and must rely
   on the backend's actual dispatch result rather than a hardcoded success
   label.

## Chosen approach

Use the smallest safe changes in the existing files and services:

- keep the existing Supplier, SupplierPaymentProfile,
  SupplierPaymentAttempt, ExpenseSettlementService, NotificationService,
  SupplierPaymentService, mailable, private Spatie media collection, routes,
  and React Query/Inertia patterns;
- add no new business tables, payment ledgers, mail systems, proof systems,
  approval workflows, or provider abstractions;
- add one backward-compatible migration making `bank_code` nullable for
  e-wallet profiles;
- preserve all existing authorization and same-shop query boundaries;
- implement each behavior with a failing regression test before production
  code, then commit only green states if a later commit is explicitly
  requested.

## Supplier management and payment profiles

### Canonical form

The Add and Edit Supplier modals will use one canonical supplier-information
field block per modal. The Add modal will contain exactly one instance of:

- Supplier Name;
- City;
- Country;
- Payment Terms;
- Lead Time;
- Products Supplied;
- Contact Person;
- Email;
- Phone; and
- Notes.

The optional Payment Profile block will be available in both Add and Edit.
The implementation may use small local render helpers within the existing
page to prevent the duplicated JSX from returning; it will not create a new
form framework.

### Atomic creation

`POST /api/erp/procurement/suppliers` will accept an optional nested
`payment_profile` payload. Supplier creation and profile creation will run in
one database transaction. If the profile is omitted, no profile row is
created. If profile validation or persistence fails, neither the supplier nor
the profile is left partially created.

The existing Edit flow continues to update supplier details and uses the
existing payment-profile upsert endpoint. Blank account-number input on an
existing profile means “keep the encrypted saved account”; it is never sent
back as the masked display string.

### Supported destination types

The profile allowlist will be exactly:

- `bank_account`;
- `e_wallet`.

This reflects the existing manual bank-transfer and manual e-wallet payment
methods. No PayMongo payout destination is implied.

The existing generic columns will be reused:

| Destination | Existing storage | UI label |
| --- | --- | --- |
| `bank_account` | `bank_name` | Bank Name |
| `bank_account` | `bank_code` | Bank / Institution Code |
| `e_wallet` | `bank_name` | Wallet Provider |
| `e_wallet` | `bank_code` | Optional Institution Code |
| both | `account_name` | Account Name |
| both | encrypted `account_number` | Account Number or Mobile / Account Number |

`bank_code` becomes nullable through the additive migration. No provider list
or unsupported bank/wallet rail is invented. Validation is conditional on the
selected destination type, and the model/service snapshot continues to use
the same masked destination shape.

### Account-number handling and verification

- New profile input starts as an empty controlled value.
- Existing profile input also starts empty and uses a placeholder explaining
  that a blank value preserves the saved account.
- A local show/hide button changes only the current input's HTML type.
- `autoComplete` is disabled for the financial field where the browser could
  incorrectly prefill a saved value.
- API responses and Finance projections expose only the masked suffix and
  safe profile fields.
- Any destination change resets status to `unverified`, clears verifier and
  verification timestamp, and requires Finance to verify again.

## Purchase Order in-transit notification

`PurchaseOrderService::updateStatus()` remains the only transition boundary.
After a successful committed `confirmed -> in_transit` transition, the
existing notification type and service are reused.

The recipient query will target active same-shop users who hold the existing
`inventory.view` permission. The notification will include:

```text
purchase_order_id
po_number
supplier_id
supplier_name
expected_delivery
status
shop_id
```

A PO-specific group key such as
`purchase-order:{purchase_order_id}:in-transit` will prevent duplicate
notifications on retries. No status transition, role system, or notification
table is added.

## Responsive modals and Finance refresh

The existing Add Expense modal structure will be reused for:

- Add Supplier;
- Edit Supplier;
- Finance Expense detail/procurement modal; and
- Supplier Payment dialog.

Each will have a viewport-relative maximum height, a flex-column shell, a
non-scrolling header, an independently scrolling body, and a non-scrolling
footer. Supplier fields use two columns at desktop widths and one column on
narrow viewports. Important Finance actions, including Review & Release,
payment-profile controls, Settlement, and Close, remain reachable without
browser zoom.

After payment, profile, or refund actions, the Finance page will refetch the
existing expense query and reconcile the refreshed record into
`activeExpense`. The expense table, open modal, payment status, settlement
amount, and summary values will therefore update without a hard browser
reload. No effect will trigger an unbounded refetch loop.

Finance's payment-profile section will show safe information including
destination type, institution/provider, account name, optional code, masked
account, and verification status. The account remains masked by default. When
Finance is performing the manual supplier transfer, an explicit local
Show/Hide action may request the current full account number or wallet
identifier from a no-store, audited Finance-only endpoint; it is never included
in normal projections, notifications, or logs. Shop Owner remains masked and
has no payment-profile verify/disable controls.

## Shop Owner payment-proof review

The existing authenticated proof routes remain the access boundary. They will
return the private stored file with its stored MIME type and an inline content
disposition instead of forcing a download. The query remains scoped through
the authorized payment attempt and shop owner.

The Shop Owner review dialog will:

- show image proofs (`jpg`, `jpeg`, `png`, `webp`) as contained thumbnails;
- keep thumbnails bounded with `object-contain` and a fixed maximum height;
- show a safe empty/unavailable state for missing proof or missing files;
- use the existing secure inline endpoint for supported PDFs rather than
  moving files to public storage;
- open a nested bounded lightbox for larger image/PDF viewing;
- preserve the parent review modal and any typed rejection reason;
- close through the existing close-button/modal conventions and Escape where
  supported; and
- leave confirmation, rejection validation, settlement, authorization, and
  payment state transitions unchanged.

No raw storage path or public URL will be serialized.

## Supplier payment confirmation email

`ExpenseSettlementService` remains the only accounting settlement writer.
`SupplierPaymentService::confirm()` will preserve this order:

```text
lock and verify payment
-> record settlement
-> mark attempt succeeded
-> commit financial transaction
-> set email status ready_to_send
-> Finance explicitly clicks Send Payment Receipt
-> dispatch existing supplier confirmation mail
-> persist actual email status
```

The existing immutable `supplier_email_to` snapshot remains the recipient
source. The current supplier record is still checked through the same-shop
procurement links before confirmation; frontend-supplied recipient addresses
are not trusted. Shop Owner confirmation records the settlement but does not
send mail; Finance reviews the final receipt and explicitly sends it.

The existing mailable and Blade view will include:

- supplier name;
- shop/business name;
- PO number;
- receipt number (`RCV-{receipt_id}` under the current convention);
- transferred amount;
- payment method;
- external reference;
- payment date;
- payment status (`Verified / Paid`); and
- masked destination.

The existing email-delivery fields remain separate from payment state:

```text
ready_to_send -> queued/dispatched | failed
failed -> queued/dispatched | failed
```

`queued` or `dispatched` is written only for the delivery state the configured
Laravel mail infrastructure can prove; it must not imply inbox delivery. A
transport exception records `failed` without rolling back the settlement. A
missing snapshot address is reported as unavailable/not sent. The existing
send/resend endpoint is reused and cannot create a second settlement.

Repeated confirmation is idempotent: it cannot create another settlement or
duplicate a successful email dispatch. Email delivery is attempted only after
the financial transaction has committed. The application will not claim that
an email reached an inbox when the environment has only a local log transport;
actual external delivery still depends on the configured mail transport.

## Authorization and tenant boundaries

- Supplier creation/edit/profile changes remain behind existing Procurement
  Supplier policy/capability checks.
- Finance profile verification and supplier-payment actions retain existing
  Finance shop context and permissions.
- Shop Owner proof, confirmation, and rejection remain behind the existing
  `shop_owner` guard and same-shop attempt query.
- Inventory notifications are limited to same-shop users with `inventory.view`.
- Supplier, PO, receipt, expense, profile, attempt, media, and mail recipient
  resolution are rechecked through canonical same-shop relationships.
- Account numbers, raw proof paths, mail credentials, and PayMongo secrets are
  never exposed in API responses, notifications, UI, or logs.

## Files expected to change

### Backend

- `database/migrations/2026_09_13_000001_make_supplier_payment_profile_bank_code_nullable.php`
- `app/Http/Controllers/Erp/SupplierController.php`
- `app/Http/Requests/StoreSupplierPaymentProfileRequest.php`
- `app/Models/SupplierPaymentProfile.php` if destination constants/serialization need to be centralized
- `app/Services/PurchaseOrderService.php`
- `app/Services/NotificationService.php`
- `app/Services/Finance/SupplierPaymentService.php`
- `app/Mail/SupplierPaymentConfirmationMail.php`
- `resources/views/emails/supplier-payment-confirmation.blade.php`
- `app/Http/Controllers/ShopOwner/SupplierPaymentController.php`
- `app/Http/Controllers/Api/Finance/ProcurementExpenseController.php` only if its proof response needs the same inline behavior
- `app/Http/Controllers/Api/Finance/ExpenseController.php` only if the safe projection needs a missing field

### Frontend

- `resources/js/types/procurement.ts`
- `resources/js/services/supplierApi.ts`
- `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- `resources/js/Pages/ERP/Finance/Expense.tsx`
- `resources/js/Pages/ERP/Finance/components/ProcurementExpensePanel.tsx`
- `resources/js/Pages/ERP/Finance/components/SupplierPaymentDialog.tsx`

### Tests

- existing supplier-management/profile feature and frontend tests;
- existing notification critical-flow tests;
- existing supplier manual-payment/mail/proof feature tests;
- existing Finance procurement-review/settlement/payment-dialog frontend tests;
- new focused assertions only where an existing test file cannot express the
  regression without unrelated setup.

No generated build output, environment file, vendor directory, or node_modules
file will be edited.

## TDD implementation order

1. Add failing Supplier form/profile/destination/masking tests; implement the
   atomic optional profile create and responsive form.
2. Add failing in-transit recipient/payload/dedupe tests; implement the
   permission-based notification change.
3. Add failing Finance modal refresh/projection tests; implement active-record
   reconciliation and responsive shells.
4. Add failing inline-proof/lightbox/access tests; implement secure inline
   responses and preview state.
5. Add failing mail content/dispatch/failure/idempotency tests; implement the
   existing mailable update and post-commit delivery status handling.
6. Run focused backend/frontend tests after each slice, then the relevant full
   suites, frontend build, and `git diff --check`.

## Explicitly excluded

- automated PayMongo supplier disbursement;
- fake PayMongo transfer IDs or webhooks;
- supplier portal/login;
- new supplier/payment/proof/settlement/notification tables;
- new approval or role systems;
- automatic supplier payment;
- changing the canonical procurement workflow;
- changing customer PayMongo payment/refund behavior;
- changing retail payment behavior; and
- unrelated repair, payroll, logistics, inventory, or authentication flows.
