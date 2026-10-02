# Subscription Payment Reporting and Upgrade Workflow

## Audit result

The authoritative charge record is `ShopOwnerSubscriptionPayment`. Admin gross, net, and per-subscription amounts intentionally include only ledger rows with `status = paid`; refunds reduce net only after they succeed. The calculation is not the cause when a PayMongo-confirmed payment remains locally pending.

The verified defects are:

- Both `PaymongoWebhookController::handleCheckoutSessionPaid` and the `/premium/success` route select `payments[0]`, despite Checkout sessions containing one entry per payment attempt. A prior failed attempt can therefore be used instead of the successful attempt.
- Webhook and success-return code duplicate settlement and subscription activation. The success return does server-side session retrieval, but only runs when the customer returns; there is no provider-backed recovery for ledger rows whose webhook was missed.
- `confirmUpgrade` creates a new pending child subscription and ledger row on every call. Neither the server nor the current owner page prevents another upgrade while one is pending. Separate successful checkouts could consequently activate multiple replacement records.
- `LegacyPremiumBillingReconciler` only repairs legacy subscriptions without a ledger row; it does not verify or settle current pending ledger records from PayMongo.

No PayMongo Dashboard event-delivery history or production payment records were available. Therefore the exact reason for any individual live transaction remaining pending (delivery/signature/configuration versus the payment-attempt selection bug) is not confirmed from production evidence.

## Approved implementation

### Ownership

There is no suitable shared subscription-charge settlement service today. Keep `PremiumProrationService` authoritative for proration, but add one focused `PremiumSubscriptionPaymentService` for verified, idempotent ledger settlement and its subscription lifecycle transition. Do not overload the order/repair `PaymentSettlementService`, renewal/refund services, or the legacy-only reconciler.

### Files and changes

1. Add `app/Services/PremiumSubscriptionPaymentService.php` to validate a locally bound ledger/subscription/session, currency, and expected amount; lock records in a transaction; settle the ledger once; and apply the pending subscription/old-subscription transition once. Preserve paid-only reporting and current proration/date rules.
2. Update `app/Http/Controllers/PaymongoWebhookController.php` and the `/premium/success` handler in `routes/web.php` to select the successful PayMongo payment attempt and delegate to that service. Keep webhook signature verification and server-side PayMongo retrieval; do not accept client success state.
3. Update `app/Http/Controllers/ShopOwner/PremiumCheckoutController.php` to serialize upgrade confirmation on the active source subscription and reuse or reject an existing pending upgrade instead of creating another checkout/child/ledger row. Keep the old plan entitled until a verified charge succeeds.
4. Add a dry-run-first provider reconciliation command for eligible pending PayMongo ledger rows. It must retrieve the stored session from PayMongo, verify exact local references/status/currency/amount, and use the same settlement service; never promote an arbitrary pending row. Leave legacy reconciliation behavior unchanged.
5. Change `SubscriptionManagementController` or its React page only if regression tests show a remaining serialization/display defect after settlement is canonical. Do not alter revenue semantics to count pending payments.
6. Add regression coverage to `PremiumBillingLedgerTest`, webhook signature/payment tests, subscription reporting tests, and existing owner-page tests only where UI behavior actually changes.

## Acceptance and verification

- New paid subscription: verified payment → one paid ledger entry → active subscription → admin amount/gross/net reflect the charge once.
- Paid upgrade: one pending checkout per source subscription → verified payment → one active target subscription → old subscription cancelled → target entitlements/dates correct.
- Failed/expired checkout leaves the prior entitlement intact; duplicate webhook delivery is idempotent; a failed earlier payment attempt does not mask the successful attempt.
- Pending and unverified payments never count as collected; successful refunds reduce net once; recovery is dry-run by default and only applies provider-verified payments.
- Test duplicate upgrade submissions, payment-attempt ordering, successful and failed upgrade finalization, date/entitlement transitions, report serialization, refunds, and safe recovery.
- Run targeted Laravel tests, relevant frontend tests, `pnpm run build`, and `git diff --check`.

## Baseline

`php artisan test --compact tests/Feature/PremiumBillingLedgerTest.php tests/Feature/PaymongoWebhookSignatureTest.php tests/Feature/SuperAdmin/SubscriptionManagementScaleTest.php` — **24 passed, 203 assertions at the pre-implementation audit baseline**. Phase 3 adds regression coverage for paid upgrades, repeated pending upgrades, and multiple attempts in one Checkout Session.

## Operator recovery

Pending PayMongo premium ledgers can be checked safely without mutation:

```powershell
php artisan premium-payments:reconcile-pending
```

Apply only after reviewing the dry-run output; optionally scope the run to a shop owner:

```powershell
php artisan premium-payments:reconcile-pending --apply --shop-owner=123
```

The command only inspects pending PayMongo rows with a stored checkout session and an eligible pending/active subscription. It leaves unpaid, mismatched, ambiguous, or provider-error rows unchanged.
