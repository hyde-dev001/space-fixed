# Super Admin Phase 5 Billing Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task in the existing `super-admin-phase-0-containment` worktree. Apply `superpowers:test-driven-development` before implementation changes, `laravel-best-practices` and `security-review` for every payment/provider/privileged path, the repository UI/design skills for the changed subscription screen, `ponytail` for the minimum coherent solution, and `verification-before-completion` before every completion claim. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate premium billing around the Shop Owner lifecycle and an authoritative payment ledger, replace the withdrawn Super Admin pseudo-billing actions with guarded cancellation, narrow legacy correction, and provider-backed full refunds, and make every external outcome safely reconcilable without rewriting paid history.

**Architecture:** Keep `PremiumCheckoutController`, `PremiumSubscriptionRenewalService`, and `PaymongoWebhookController` as the Shop Owner billing entry points, but make every payable subscription create exactly one durable `ShopOwnerSubscriptionPayment` record before checkout creation. Extract one canonical local cancellation service shared by Shop Owner and Super Admin controllers. Introduce a focused subscription-refund attempt record and PayMongo adapter because money movement crosses the database/provider boundary. Transaction A records and audits an attempt plus the provider idempotency key; the provider call happens outside database locks; Transaction B records and audits the known outcome. A timeout remains `unknown` and must be retried only with the same still-valid provider key or reconciled before any new attempt. Legacy `deactivated` rows and missing payment-ledger rows are repaired only when source evidence is reliable; ambiguous rows remain visible for a narrow, non-financial correction workflow.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, Laravel HTTP client, Inertia 2, React 18, TypeScript 5.7, PHPUnit 11, Vitest 3, pnpm, PayMongo.

**Status:** DRAFT FOR APPROVAL

---

## Design Authority and Scope Guard

Authoritative design:

- `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`
- Phase 5, "Billing Consolidation"
- Sections 10, 13-16, 19-22, and 24 where they define subscription states, external-provider transaction boundaries, audit atomicity, invariants, security, verification, and provider confirmation

Implemented prerequisite:

- Completed Phase 4 tip: `3e945d2d7`
- Worktree/branch: `.worktrees/super-admin-phase-0-containment` / `super-admin-phase-0-containment`

This plan is based on the clean post-Phase-4 worktree. Do not execute it on a branch that still exposes the removed Super Admin upgrade/downgrade/pseudo-refund mutations.

Phase 5 includes:

1. complete payment-ledger coverage for initial checkout, renewal, and upgrade;
2. hardened webhook source-state and payment-ledger finalization;
3. idempotent, evidence-based reconciliation for legacy payments and `deactivated` subscriptions;
4. canonical active-subscription cancellation shared by Shop Owner and Super Admin;
5. one narrow non-financial correction path for unresolved legacy `deactivated` rows;
6. provider-backed full refunds with durable local attempts, safe retry rules, webhook/poll reconciliation, and atomic local audits;
7. truthful Super Admin payment/refund history and intervention controls;
8. failure, concurrency, route, audit, and provider-boundary verification.

Do not add in this phase:

- direct Super Admin plan swaps, manual upgrades/downgrades, arbitrary subscription editors, complimentary credits, charge capture, or a configurable billing rules engine;
- partial refunds, offline/cash refunds, wallet credits, refund approval queues, automatic refund policy, or refunds without a verified PayMongo payment;
- edits to `amount_paid`, historical plan price, provider IDs, paid timestamps, or revenue totals to imitate correction/refund;
- cancellation of a `pending` checkout as though it were an active paid subscription;
- automatic shop suspension, document-compliance behavior, or Phase 6 document work;
- generic payment-provider interfaces or a multi-provider architecture without a second real provider;
- removal of legacy enum values until the deployed data is proven fully reconciled;
- broad pagination/query optimization beyond preventing an immediate billing regression; measured scale work remains Phase 8.

## Confirmed Post-Phase-4 Baseline

- Phase 4 removed `admin.subscriptions.cancel`, `admin.subscriptions.upgrade`, and `admin.subscriptions.downgrade`, their controller methods, and all mutation controls. Plan management and read-only subscription inspection remain.
- The fixed role map already gives only `super_admin` the `intervene_subscriptions` capability. High-risk routes already use `privileged.recent`, which represents password plus fresh MFA reauthentication.
- Shop Owner initial checkout and scheduled renewal create pending subscriptions and PayMongo checkout sessions but do not create `ShopOwnerSubscriptionPayment` rows.
- Charged and zero-charge upgrades do create payment rows. This makes ledger coverage inconsistent by payment type.
- The paid webhook updates subscription state first and only updates a payment row if it can find one. When none exists it silently leaves the payment ledger incomplete.
- The paid webhook currently accepts both `pending` and `failed` subscriptions. A deliberately abandoned/failed checkout could therefore become active from a late provider event unless source-state semantics are tightened.
- Shop Owner cancellation currently accepts `pending` and `active`. Active cancellation preserves entitlement through `ends_at`, but pending checkout abandonment is not a valid `pending -> cancelled` transition.
- `toggleAutoRenewal()` may reactivate a cancelled-but-still-entitled subscription; this must remain a Shop Owner billing decision and must not bypass unresolved replacement/refund state.
- `ShopOwnerSubscriptionPayment` contains due/paid amounts and provider session/payment IDs but has no refund-attempt relation, no deterministic ledger identity, and no refund status.
- `showSubscriptionManagement()` derives revenue from mutable subscription rows and plan-price fallback, guesses cancellation metadata from arbitrary activity descriptions, and loads all subscriptions. It does not present authoritative payment/refund history.
- The database still permits the contradictory `deactivated` subscription value. Reliable historical rows can map to `cancelled` or `expired`; ambiguous rows cannot be guessed.
- The existing `PaymongoRefundService` is shared by order and repair workflows. Its public contract and default reason do not cleanly match the current subscription-refund contract, so Phase 5 must not casually change it and regress those workflows.
- Official PayMongo documentation, checked 2026-08-12, supports full/partial refunds for eligible paid payments, publishes refund webhook events, permits listing refunds by payment ID, and documents `Idempotency-Key` for resource-creation requests. PayMongo retains an idempotency result for 24 hours. Phase 5 therefore uses the same local-attempt UUID as the provider key, plus provider-reference/list reconciliation before any retry after that window.

## Frozen Phase 5 Contracts

### Authoritative lifecycle

```text
pending -> active | failed
active -> cancelled
active | cancelled -> expired
```

- `deactivated` is legacy-only. No current path may write it.
- A pending checkout may fail or be explicitly abandoned as `failed`; it is never cancelled as a paid subscription.
- A paid active subscription cancelled without refund keeps entitlement until its existing/derived `ends_at`.
- A successfully fully refunded subscription ends entitlement when provider success is confirmed; it is not treated as an ordinary end-of-term cancellation.
- Super Admin never changes `premium_plan_id`, `plan_code`, slot limits, or upgrade/downgrade fields directly.

### Payment-ledger authority

Every provider checkout has one deterministic local payment row before the provider request:

```text
subscription created pending
payment row created pending
        -> provider checkout created
        -> provider webhook/reconciliation
        -> payment paid | failed
        -> subscription active | failed
```

- `ShopOwnerSubscriptionPayment` is authoritative for collected premium revenue.
- `ShopOwnerSubscription::paid_amount` remains a compatibility snapshot and is never the source for a refund or revenue report.
- One subscription version has at most one checkout payment row. A deterministic nullable `ledger_key` supplies database-enforced idempotency for new and safely reconciled rows.
- Zero-charge upgrades remain explicit paid/settled ledger events with amount `0.00`; they do not contribute revenue.
- Provider webhook replay returns the current terminal result and creates no duplicate payment, subscription transition, notification, or audit.

### Canonical cancellation

```text
active paid subscription
        -> lock subscription and relevant replacement rows
        -> reject unresolved pending replacement/renewal checkout
        -> status = cancelled
        -> auto_renew = false
        -> preserve original/derived ends_at
        -> preserve payment and paid_amount history
        -> audit/activity in the same local transaction
```

- Shop Owner and Super Admin call the same domain service; they do not call each other's controllers.
- Super Admin requires `intervene_subscriptions`, recent reauthentication, a fixed cancellation reason, and optional bounded notes.
- An exact repeated cancellation returns current state without a second audit/notification. A conflicting source state returns `409`.
- If a provider subscription object is discovered during implementation, stop and amend this plan before adding provider cancellation. Current inspected code uses one-time checkout sessions, so local auto-renewal cancellation is sufficient.

### Legacy non-financial correction

The correction endpoint is available only for an unresolved legacy `deactivated` row. It may set exactly one of:

```text
cancelled + verified effective ends_at
expired   + verified effective ends_at at/before now
```

It may also record a required correction reason and bounded notes. It may not edit owner, plan, start date, provider/session/payment references, due/paid amount, payment status, or revenue. Reliably reconcilable rows are handled by the command, not by an operator form.

### Provider-backed full refund

V1 supports only a full refund of the original amount of one eligible PayMongo subscription payment. If PayMongo reports any prior partial or untracked refund, the application blocks intervention and requires reconciliation instead of calculating a remainder.

Preconditions:

- actor is a recently reauthenticated Super Admin with `intervene_subscriptions`;
- payment is `paid`, has a PayMongo payment ID, positive `amount_paid`, matching currency, and no succeeded, processing, pending, or unknown full-refund attempt;
- provider retrieval confirms a paid/refundable payment, no prior refund exists, and the local amount/currency/reference matches trusted provider data;
- no unresolved replacement/renewal checkout can later grant contradictory entitlement.

Provider transaction boundary:

```text
Transaction A
  lock payment/subscription
  revalidate
  create unique refund attempt
  write initiation audit
commit

PayMongo request outside lock

Transaction B
  lock attempt/payment/subscription
  finalize known outcome
  write outcome audit
commit
```

- `succeeded`: preserve the payment row's original `paid` status and amount as collected-history evidence, record the succeeded refund separately, cancel the subscription, end entitlement at provider-confirmed success time, disable auto-renewal, and audit atomically.
- `processing`: retain entitlement and await webhook/reconciliation.
- `failed`: preserve payment and subscription state; record a sanitized failure code and audit the failure.
- `unknown`: retain entitlement; record uncertainty; forbid another POST until reconciliation proves the first request's outcome.
- Provider responses, secrets, exception messages, and raw payloads are never stored in audit metadata or returned to the browser.
- Send the local attempt UUID as PayMongo's `Idempotency-Key` and in provider notes. A connection timeout may be retried only with the same parameters and same key while its documented 24-hour window remains valid. Before any retry after that window—or before creating a different attempt—retrieve/list refunds for the payment and reconcile the existing attempt. A new attempt is permitted only after a known terminal failure.

### Reconciliation

Two commands keep concerns explicit:

- `premium-billing:reconcile-legacy`: dry-run by default; with `--apply`, safely creates missing ledger rows and maps reliable `deactivated` states in bounded chunks. It reports duplicates/ambiguity and never guesses provider IDs, amount, DTI/SEC data, or entitlement dates.
- `premium-billing:reconcile-provider`: bounded reconciliation for pending/unknown payment and refund records. It retrieves by provider object ID when known and lists refunds by payment ID when a timed-out create returned no refund ID, then applies the same finalization services used by webhooks.

Webhooks remain the real-time source. Commands repair missed/uncertain events and are safe to rerun. Do not schedule provider polling until credentials, production scheduler topology, and expected provider rate limits are verified; document the operator command now and let Phase 8 decide measured scheduling.

### Audit and failure contract

Every committed local billing transition and mandatory audit entry commit atomically. External workflows separately audit initiation and final outcome. Use normalized events such as:

```text
subscription_cancelled
legacy_subscription_corrected
subscription_refund_initiated
subscription_refund_succeeded
subscription_refund_failed
subscription_refund_unknown
subscription_refund_reconciled
premium_payment_reconciled
```

Audit metadata is allowlisted: actor/role, target IDs, previous/resulting state, reason, amount/currency, safe provider object ID, local attempt ID, route, IP, and correlation ID. It excludes raw provider payloads, request bodies, credentials, card/payment-method details, and exception text.

---

## Task 1: Freeze the Billing Boundary with Failing Tests

**Files:**

- Modify: `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php`
- Create: `tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php`
- Create: `tests/Feature/PremiumBillingLedgerTest.php`
- Test: `tests/Feature/PremiumFeatureTest.php`

- [ ] **Step 1: Replace the temporary Phase 4 route assertion with the Phase 5 route contract**

Assert that Phase 5 restores only:

- `POST /admin/subscriptions/{subscription}/cancel` as `admin.subscriptions.cancel`;
- `PATCH /admin/subscriptions/{subscription}/legacy-correction` as `admin.subscriptions.legacy-correction`;
- `POST /admin/subscription-payments/{payment}/refunds` as `admin.subscription-payments.refunds.store`.

All three require authentication, active status, MFA, `intervene_subscriptions`, recent reauthentication, CSRF/web middleware, and correlation ID. The refund endpoint also uses a dedicated actor-plus-IP rate limiter. Regular Admin receives `403`; unauthenticated/setup/suspended/inactive/not-MFA/recent-reauth-expired actors are denied. No upgrade/downgrade/direct-plan-swap route returns.

- [ ] **Step 2: Write failing authoritative-ledger tests**

Cover initial checkout, renewal, charged upgrade, and zero-charge upgrade. Assert one payment row per subscription checkout, deterministic identity, expected payment type/amount/currency, session linking, and paid/failed webhook finalization. Assert revenue cannot be derived from subscription `paid_amount` or current plan price.

- [ ] **Step 3: Write failing lifecycle and late-webhook tests**

Assert `pending -> cancelled` is rejected, abandoned pending checkout becomes `failed` through its own path, failed/abandoned subscriptions are not reactivated by a late webhook without an authoritative pending payment state, duplicate webhooks are inert, and active cancellation preserves the original paid record and end date.

- [ ] **Step 4: Run the red tests and record why each fails**

Run:

```powershell
php artisan test tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php tests/Feature/PremiumBillingLedgerTest.php tests/Feature/PremiumFeatureTest.php
```

Expected: failures prove missing routes/services, incomplete initial/renewal ledger rows, permissive webhook state, and non-authoritative revenue.

## Task 2: Make Checkout Payments Complete and Authoritative

**Files:**

- Create via Artisan: `database/migrations/<timestamp>_add_ledger_key_to_shop_owner_subscription_payments_table.php`
- Modify: `app/Models/ShopOwnerSubscription.php`
- Modify: `app/Models/ShopOwnerSubscriptionPayment.php`
- Modify: `app/Http/Controllers/ShopOwner/PremiumCheckoutController.php`
- Modify: `app/Services/PremiumSubscriptionRenewalService.php`
- Modify: `app/Http/Controllers/PaymongoWebhookController.php`
- Modify: `tests/Feature/PremiumBillingLedgerTest.php`
- Modify: `tests/Feature/PremiumFeatureTest.php`

- [ ] **Step 1: Add the minimum ledger identity**

Generate an additive migration for nullable unique `ledger_key` plus only the indexes required by new reconciliation queries. Do not alter deployed migrations. Use a deterministic key based on the durable subscription/version and payment type, not provider response ordering. Leave legacy rows null until reconciliation proves identity.

- [ ] **Step 2: Add model relationships and explicit casts/fillable fields**

Expose subscription `payment`/`payments` following actual cardinality, payment `subscription`, and later refund relations. Keep the new key server-generated; never accept it from request input.

- [ ] **Step 3: Create the payment row in the same local transaction as every pending subscription**

For initial checkout and renewal, create the pending subscription and its pending payment row together before calling PayMongo. Pass `payment_record_id` and the safe ledger key in checkout metadata. Preserve the charged/zero-charge upgrade behavior but route it through the same row-building rules to prevent drift.

The PayMongo checkout request remains outside a database lock. If checkout creation fails, atomically mark the pending payment and subscription `failed`; return a generic error/correlation ID and do not expose the provider body.

- [ ] **Step 4: Harden webhook lookup and source-state validation**

Resolve a payment by trusted `payment_record_id`, then verify its subscription, owner, amount/currency, session, and pending state. Fall back to session/reference only for legacy compatibility and report ambiguity instead of selecting an arbitrary row. Under one lock order—payment, subscription, source subscription—finalize both records and related audit/activity atomically.

Do not activate a subscription merely because its row is `failed`. A paid provider event for a failed/abandoned checkout becomes a reconciliation-required anomaly unless its payment row proves that the provider charge was already pending and not deliberately invalidated.

- [ ] **Step 5: Verify focused ledger behavior**

Run:

```powershell
php artisan test tests/Feature/PremiumBillingLedgerTest.php tests/Feature/PremiumFeatureTest.php
```

## Task 3: Add Idempotent Legacy Billing Reconciliation

**Files:**

- Create: `app/Console/Commands/ReconcileLegacyPremiumBilling.php`
- Create: `app/Services/LegacyPremiumBillingReconciler.php`
- Create: `tests/Feature/Console/ReconcileLegacyPremiumBillingTest.php`
- Modify: `app/Models/ShopOwnerSubscription.php`
- Modify: `docs/runbooks/super-admin-operations.md`

- [ ] **Step 1: Write failing dry-run, apply, and rerun tests**

Fixture groups must include:

- active/cancelled/expired subscriptions with reliable session/payment IDs and no ledger row;
- duplicate/conflicting provider references;
- `deactivated` with a reliable future entitlement deadline;
- `deactivated` with a reliable past deadline;
- `deactivated` with a reliable stored end date and independent lifecycle evidence;
- `deactivated` with no reliable deadline or payment evidence.

Dry-run changes nothing. Apply creates/maps only reliable records. A second apply changes nothing. Ambiguous rows remain intact and are counted/reported without sensitive data.

- [ ] **Step 2: Implement bounded reconciliation**

Use `chunkById()`, explicit ordering, short per-record/group transactions, and locks before mutation. Prefer provider payment/session IDs and stored checkout metadata over plan-price inference. If historical amount or status cannot be proven, report it; do not manufacture a paid ledger entry.

Map reliable legacy states as:

```text
effective ends_at > now  -> cancelled
effective ends_at <= now -> expired
unknown deadline         -> unchanged deactivated / operator correction required
```

Never modify `amount_paid`, plan, provider IDs, start date, or owner during state mapping. Do not remove `deactivated` from the database enum while unresolved rows can remain.

- [ ] **Step 3: Document pre-deploy and post-deploy command order**

Document backup/read-only inventory, dry-run output capture, reviewed `--apply`, rerun proof, ambiguity handoff, and rollback/forward-fix behavior. Schema migration deploys before application writes `ledger_key`; legacy reconciliation runs after compatible code is deployed.

- [ ] **Step 4: Verify reconciliation**

Run:

```powershell
php artisan test tests/Feature/Console/ReconcileLegacyPremiumBillingTest.php
```

## Task 4: Implement One Canonical Cancellation Workflow

**Files:**

- Create: `app/Services/PremiumSubscriptionCancellationService.php`
- Create: `app/Http/Controllers/superAdmin/SubscriptionInterventionController.php`
- Create: `app/Http/Requests/Privileged/CancelPremiumSubscriptionRequest.php`
- Modify: `app/Http/Controllers/ShopOwner/PremiumCheckoutController.php`
- Modify: `routes/web.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Create: `tests/Feature/PremiumSubscriptionCancellationTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php`

- [ ] **Step 1: Write failing state, authorization, idempotency, and rollback tests**

Cover Shop Owner scope, Super Admin capability/recent reauth, active cancellation, exact replay, pending/failed/expired conflicts, unresolved replacement/renewal conflict, cancellation-audit failure rollback, and preservation of payment history/entitlement dates.

- [ ] **Step 2: Extract the local domain operation**

The service owns transaction boundaries and locks. It re-fetches the target under lock, validates source state and unresolved children, calculates `ends_at` only from reliable existing start plus plan-duration data when missing, sets `cancelled` and `auto_renew=false`, and records the appropriate owner activity or normalized privileged audit before commit.

Controllers perform authentication/request orchestration only. Do not make the Super Admin controller call a Shop Owner endpoint or duplicate cancellation rules.

- [ ] **Step 3: Correct pending-checkout abandonment semantics**

Change Shop Owner cancellation so only active subscriptions use cancellation. If the current UI needs an abandon action for an unpaid checkout, make it an explicit pending-checkout failure operation with source-state checks; otherwise return a truthful conflict and keep PayMongo's existing success/cancel redirect behavior. Update webhook tests so a late event cannot bypass that decision.

- [ ] **Step 4: Register only the canonical privileged cancellation route**

Use implicit model binding and the existing protected `/admin` group. Apply `privileged.capability:intervene_subscriptions` and `privileged.recent`. Add bounded allowlisted validation for reason/notes and sanitized `409`/`500` responses using the existing privileged failure conventions.

- [ ] **Step 5: Verify cancellation**

Run:

```powershell
php artisan test tests/Feature/PremiumSubscriptionCancellationTest.php tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php
```

## Task 5: Add Narrow Legacy Non-Financial Correction

**Files:**

- Create: `app/Http/Requests/Privileged/CorrectLegacyPremiumSubscriptionRequest.php`
- Modify: `app/Http/Controllers/superAdmin/SubscriptionInterventionController.php`
- Create: `app/Services/LegacyPremiumSubscriptionCorrectionService.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/SuperAdmin/LegacyPremiumSubscriptionCorrectionTest.php`
- Modify: `tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php`

- [ ] **Step 1: Write failing allowlist tests**

Assert only unresolved `deactivated` targets are accepted; allowed target states/date combinations are validated; regular Admin and stale reauthentication are denied; extra fields such as `paid_amount`, `premium_plan_id`, provider IDs, owner, and start date are ignored/rejected and never mutate.

- [ ] **Step 2: Implement transactionally under lock**

The service locks the subscription, confirms the row remains unresolved legacy data, records previous/resulting state and evidence/reason, updates only status/effective end/cancellation metadata, and writes the mandatory audit in the same transaction. Exact replay returns current state; conflicting reclassification returns `409`.

- [ ] **Step 3: Keep correction visibly exceptional**

Do not create a generic edit endpoint or expose correction for ordinary active/cancelled/expired records. The response must identify whether the item was corrected or already matched, without exposing internal/provider errors.

- [ ] **Step 4: Verify correction**

Run:

```powershell
php artisan test tests/Feature/SuperAdmin/LegacyPremiumSubscriptionCorrectionTest.php tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php
```

## Task 6: Implement Durable Provider-Backed Full Refunds

**Files:**

- Create via Artisan: `database/migrations/<timestamp>_create_shop_owner_subscription_refunds_table.php`
- Create: `app/Models/ShopOwnerSubscriptionRefund.php`
- Modify: `app/Models/ShopOwnerSubscriptionPayment.php`
- Modify: `app/Models/ShopOwnerSubscription.php`
- Create: `app/Http/Requests/Privileged/RefundPremiumSubscriptionPaymentRequest.php`
- Create: `app/Services/PaymongoSubscriptionRefundGateway.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `app/Services/PremiumSubscriptionRefundService.php`
- Modify: `app/Http/Controllers/superAdmin/SubscriptionInterventionController.php`
- Modify: `app/Http/Controllers/PaymongoWebhookController.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/SuperAdmin/PremiumSubscriptionRefundTest.php`
- Create: `tests/Feature/SuperAdmin/PremiumSubscriptionRefundConcurrencyTest.php`
- Create: `tests/Feature/PaymongoSubscriptionRefundWebhookTest.php`

- [ ] **Step 1: Create the minimal refund-attempt schema**

Generate a focused table with payment/subscription/actor foreign keys; immutable local UUID/reference; provider refund ID; amount/currency; business reason; provider reason from the documented allowlist; status (`pending`, `processing`, `succeeded`, `failed`, `unknown`); safe failure code; initiated/finalized/reconciled timestamps; and timestamps. Add unique indexes for local reference and nullable provider refund ID plus query indexes for payment/status. Preserve attempts if an administrator later becomes inactive; choose foreign-key delete behavior accordingly.

Do not store raw responses, card/payment-method details, secrets, stack traces, or a mutable copy of the whole request.

- [ ] **Step 2: Write failing eligibility and authorization tests**

Cover non-PayMongo/zero/unpaid/already-refunded payments, mismatched provider amount/currency, unsupported provider response, regular Admin, missing recent reauth, validation, actor-plus-IP throttling, and cross-subscription binding. Define the narrow `privileged-subscription-refund` limiter beside the existing privileged limiters. Assert no provider call occurs when local preconditions fail.

- [ ] **Step 3: Add a focused PayMongo subscription-refund adapter**

Reuse repository configuration and HTTP conventions, with explicit connect/request timeouts, normalized safe results, and `Http::preventStrayRequests()` in tests. Do not alter the shared `PaymongoRefundService` public signature unless every existing order/repair caller and test is deliberately migrated.

The adapter retrieves payment/refund status, lists refunds by payment ID for uncertain creates, and creates one full refund with documented provider reason plus notes containing only the local attempt reference. Send the local UUID as `Idempotency-Key`. It must distinguish known provider rejection from connection timeout/uncertain delivery. Any within-window retry must explicitly reuse the same key and identical parameters; never use a generic automatic POST retry that could outlive or replace the key.

- [ ] **Step 4: Implement the two-transaction service**

Transaction A locks in canonical order, checks eligibility and competing attempts, creates one attempt, and writes `subscription_refund_initiated`. Commit before provider retrieval/POST.

Transaction B locks again and applies only a known response. A succeeded full refund preserves `amount_paid`, records the refund relationship/status, ends entitlement, disables auto-renewal, closes conflicting future lifecycle fields safely, and writes success audit atomically. Processing/failed/unknown outcomes follow the frozen contract.

Exact duplicate submissions return the existing attempt/current state. A different request while pending/processing/unknown returns `409`. A known failed attempt may be followed by a new explicit attempt; an unknown attempt reuses its original key only inside the provider's validity window and must otherwise be reconciled first.

- [ ] **Step 5: Extend webhook handling without weakening existing order/repair refunds**

Route `payment.refunded` and `payment.refund.updated` by trusted provider refund/payment IDs to the subscription refund service when a matching subscription attempt exists; otherwise preserve existing `OrderRefund` behavior. Verify signature before parsing/mutation. Replay is inert. A webhook cannot change a different payment/subscription or invent an attempt.

- [ ] **Step 6: Add targeted concurrency/failure injection**

Cover two simultaneous refund requests producing one provider-attempt row/key, response timeout followed by same-key retry inside 24 hours, timeout followed by list reconciliation after key expiry, webhook racing with synchronous finalization, audit failure rolling back each local transaction, and cancellation racing with refund. Use the database concurrency harness already used in critical workflow tests; skip with an explicit engine reason where SQLite cannot model the lock.

- [ ] **Step 7: Verify refunds**

Run:

```powershell
php artisan test tests/Feature/SuperAdmin/PremiumSubscriptionRefundTest.php tests/Feature/SuperAdmin/PremiumSubscriptionRefundConcurrencyTest.php tests/Feature/PaymongoSubscriptionRefundWebhookTest.php
```

## Task 7: Add Provider Reconciliation and Safe Operational Recovery

**Files:**

- Create: `app/Console/Commands/ReconcilePremiumBillingProvider.php`
- Create: `app/Services/PremiumBillingProviderReconciler.php`
- Modify: `app/Services/PremiumSubscriptionRefundService.php`
- Modify: `app/Http/Controllers/PaymongoWebhookController.php`
- Create: `tests/Feature/Console/ReconcilePremiumBillingProviderTest.php`
- Modify: `docs/runbooks/super-admin-operations.md`

- [ ] **Step 1: Write failing bounded/replay tests**

Cover pending payment, unknown/processing refund, provider success/failure, missing provider object, amount/currency mismatch, command rerun, one bad record not corrupting another, and `--limit`/chunk bounds. Fake every HTTP request and reject stray traffic.

- [ ] **Step 2: Share finalizers, not request paths**

The command, synchronous request, and webhook call the same payment/refund finalization methods. Provider I/O stays outside locks; local finalization uses the canonical lock order and transaction/audit rules. Never make a second refund POST from reconciliation.

- [ ] **Step 3: Add operator-safe output and runbook**

Output counts and local IDs/status categories only. Include commands for inspecting unknown attempts, running bounded reconciliation, handling provider outage, and escalating an irreconcilable case. Do not print provider payloads, owner PII, or secrets.

- [ ] **Step 4: Verify provider recovery**

Run:

```powershell
php artisan test tests/Feature/Console/ReconcilePremiumBillingProviderTest.php tests/Feature/PaymongoSubscriptionRefundWebhookTest.php
```

## Task 8: Replace Read-Only Containment with a Truthful Billing UI

**Files:**

- Modify: `app/Http/Controllers/SuperAdminController.php`
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`
- Modify: `resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx`
- Create: `resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementBilling.test.tsx`
- Modify: `tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php`
- Modify: `tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php`

- [ ] **Step 1: Write failing server-prop tests**

Assert revenue and payment history come from paid payment-ledger rows, refunds are separate immutable outcomes, subscription `paid_amount`/plan-price mismatches do not inflate revenue, cancellation metadata comes from canonical fields/audit, and unresolved `deactivated` rows are explicitly labelled "Needs correction."

- [ ] **Step 2: Build a bounded, eager-loaded billing view model**

Load the relationships and columns actually rendered; avoid per-row activity queries and arbitrary description matching. Keep current Phase 4 pagination behavior unless a minimum page bound is required to avoid adding a worse unbounded payment/refund collection. Phase 8 owns measured broad pagination and query optimization.

Compute:

- gross collected revenue from paid ledger amounts;
- refunded amount from succeeded refund attempts;
- net collected revenue as an explicit separate value;
- entitlement from subscription status/dates, not refund/revenue labels.

- [ ] **Step 3: Add only eligible controls**

Show:

- **Cancel at period end** for eligible active subscriptions;
- **Correct legacy state** only for unresolved `deactivated` rows;
- **Issue full refund** only for server-declared eligible PayMongo payment rows;
- attempt status and "reconciliation required" for processing/unknown outcomes.

Use accessible confirmation dialogs that state entitlement impact and require a reason. Server responses remain authoritative. Do not show upgrade/downgrade, editable paid amount, partial refund, generic adjustment, or fake success.

- [ ] **Step 4: Test controls and truthful failures**

Frontend tests cover visibility, confirmation, pending state, `403`, `409`, `422`, sanitized `500`, duplicate/current-state responses, and no optimistic mutation before the server response. Regular Admin cannot reach the page; backend authorization remains the boundary.

- [ ] **Step 5: Verify UI and backend payload**

Run:

```powershell
php artisan test tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementContainment.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/SubscriptionManagementBilling.test.tsx
```

## Task 9: Phase Review, Deployment Verification, and Handoff

**Files:**

- Modify: `docs/runbooks/super-admin-operations.md`
- Modify: `docs/superpowers/plans/2026-08-12-super-admin-phase-5-billing-consolidation.md`
- Modify only for a durable new lesson: `docs/ai-learning-log.md`

- [ ] **Step 1: Perform the required sequential review stack**

Record:

1. `ponytail` simplification—remove speculative provider abstractions, duplicate finalizers, generic adjustment fields, and unnecessary DTOs/dependencies;
2. Standards review—Laravel conventions, focused controllers/services, form requests, migration/index quality, eager loading, bounded commands, HTTP timeouts/fakes;
3. Spec review—every frozen Phase 5 contract and no Phase 6-8 scope leakage;
4. TypeScript/React review—focused types, safe narrowing, no `any`, accessible dialogs, no duplicated authorization map, no unjustified code splitting;
5. Karpathy review—surface assumptions, keep each changed line tied to billing correctness, remove only orphans created by Phase 5;
6. security review—authorization, recent reauth, CSRF, input allowlists, provider secrets, sensitive logs/errors, money-movement replay, webhook verification, audit metadata;
7. reuse/dead-code review—reuse current auth/audit/failure/provider configuration patterns and confirm removed Phase 4 containment assertions have correct Phase 5 replacements;
8. improvement evidence—before/after ledger coverage and truthful route/UI behavior; report query/performance improvement as `not measured` unless captured.

- [ ] **Step 2: Run focused tests as one Phase 5 group**

```powershell
php artisan test tests/Feature/PremiumBillingLedgerTest.php tests/Feature/PremiumSubscriptionCancellationTest.php tests/Feature/PaymongoSubscriptionRefundWebhookTest.php tests/Feature/Console/ReconcileLegacyPremiumBillingTest.php tests/Feature/Console/ReconcilePremiumBillingProviderTest.php tests/Feature/SuperAdmin/PhaseFiveBillingBoundaryTest.php tests/Feature/SuperAdmin/LegacyPremiumSubscriptionCorrectionTest.php tests/Feature/SuperAdmin/PremiumSubscriptionRefundTest.php tests/Feature/SuperAdmin/PremiumSubscriptionRefundConcurrencyTest.php tests/Feature/SuperAdmin/SubscriptionInterventionContainmentTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php
```

- [ ] **Step 3: Run broader regression gates**

```powershell
php artisan route:list --path=admin/subscriptions
php artisan route:list --path=admin/subscription-payments
php artisan test tests/Feature/PremiumFeatureTest.php tests/Feature/SuperAdmin/PremiumPlanWorkflowTest.php tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php
pnpm run test:frontend
composer test
pnpm run build
git diff --check
```

If a broad suite cannot run because of an existing environment prerequisite, record the exact command, exit/output, and focused passing evidence. Do not mark the phase complete from the UI or happy path alone.

- [ ] **Step 4: Inspect structural and data invariants**

Confirm:

- one canonical route per mutation and no upgrade/downgrade/direct plan-swap route;
- all privileged billing mutations carry capability plus recent reauth middleware;
- no current write path emits `deactivated`;
- all new checkouts create payment rows before provider calls;
- payment/refund IDs and local references are uniquely constrained where reliable;
- provider calls do not occur inside database transactions/locks;
- no refund path updates historical `amount_paid` to zero;
- no raw provider payload/error/request body appears in browser responses, logs intentionally written by Phase 5, or privileged audit;
- exact duplicate webhook/request/reconciliation paths produce one terminal state and one mandatory audit outcome.

- [ ] **Step 5: Browser-verify the integrated experience**

Using local test data and fake/sandbox provider behavior where available, verify:

```text
Regular Admin -> subscription management denied
Super Admin -> payment history derives from ledger
Super Admin -> ordinary cancellation preserves end-of-term access
Super Admin -> unresolved legacy row offers only narrow correction
Super Admin -> eligible full refund shows provider-backed confirmation
provider failure/unknown -> no fake success; reconciliation required
Shop Owner -> initial/upgrade/renewal/cancel flows remain authoritative
```

Capture console/network errors as test evidence only. Never send a real refund against production credentials during verification.

- [ ] **Step 6: Complete execution evidence and hand off Phase 6**

Update this plan to `EXECUTED` only when tests, reconciliation dry-run, route inspection, security review, and browser evidence are recorded. Include migration/deployment order, any unresolved legacy counts, and exact provider-sandbox limitations. Phase 6 may begin only after Phase 5 has no known paid-history rewrite, duplicate money movement, or unresolved unsafe route.

---

## Acceptance Checklist

- [ ] Shop Owner remains the authoritative billing lifecycle owner; Super Admin cannot swap plans.
- [ ] Initial, renewal, charged-upgrade, and zero-charge-upgrade flows create deterministic payment-ledger rows.
- [ ] Paid revenue is derived from payment records, not subscription snapshots or plan-price fallback.
- [ ] `pending -> cancelled` and new `deactivated` writes are impossible through current paths.
- [ ] Ordinary cancellation preserves paid history and entitlement through `ends_at`.
- [ ] Ambiguous legacy rows are visible and never guessed; reliable reconciliation is dry-run-first and idempotent.
- [ ] Administrative correction is limited to non-financial legacy state/date metadata.
- [ ] Refund means verified provider money movement and never a local `paid_amount = 0` rewrite.
- [ ] Unknown provider outcomes block blind retry and are reconcilable by provider reference.
- [ ] Every local transition and mandatory audit commit atomically; provider initiation and outcome are audited separately.
- [ ] Webhook/request/command replay is idempotent and concurrency tests protect duplicate cancellation/refund/payment finalization.
- [ ] Provider secrets, raw payloads, payment details, and exception text are absent from UI and privileged audit.
- [ ] Visible controls map to registered, protected, persisted operations and handle negative responses truthfully.
- [ ] Focused backend/frontend tests, broad suites, route/data inspection, build, diff hygiene, and browser flows are recorded before completion.

## Deployment and Rollback Notes

Deploy additively:

1. back up and inventory subscription/payment tables;
2. deploy nullable ledger identity and refund-attempt schema;
3. deploy code that writes complete new payment rows and understands old null keys;
4. run focused smoke tests and `premium-billing:reconcile-legacy` dry-run;
5. review ambiguity/duplicate counts, then run bounded `--apply`;
6. enable Super Admin cancellation/correction/refund routes only with provider credentials/webhook verification confirmed;
7. run provider reconciliation in dry/sandbox-safe mode and verify audit/UI output;
8. retain the existing enum and compatibility snapshot columns until all production ambiguity is resolved and a later removal plan exists.

Rollback application code before schema. New nullable columns/tables are backward-compatible with the Phase 4 application, but completed provider refunds and historical attempts must never be deleted or reversed locally to imitate rollback. If a deploy fails after provider success, forward-reconcile the local attempt from PayMongo before re-enabling retries. Never roll back by restoring the Phase 4 pseudo-refund or direct plan-swap code.
