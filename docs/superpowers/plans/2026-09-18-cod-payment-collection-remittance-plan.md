# COD Payment, Collection, Remittance, and Refund Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a complete, tenant-safe COD lifecycle from checkout through rider collection, Finance-confirmed remittance, and asynchronous Xendit COD refunds while preserving PayMongo behavior.

**Architecture:** Add dedicated `cod_collections`, `cod_remittances`, and `cod_remittance_items` records as the operational source of truth. Extend the existing order/refund and Finance invoice-payment boundaries with guarded COD-specific methods, and extract only shared Xendit transport primitives so supplier payout rules remain isolated. Integrate actions into the existing checkout, logistics, Finance, job-order, and refund screens without changing shipment sequencing or PayMongo paths.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, MySQL/SQLite test database, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest/Testing Library, PHPUnit.

---

## 0. Working rules and checkpoints

**Working directory:** `C:\xampp\htdocs\solespace-master\.worktrees\rider-gps-tracking`

**Authoritative design:** `docs/superpowers/specs/2026-09-18-cod-payment-collection-remittance-design.md`

Use the repository's existing patterns and the relevant skills before implementation:

- `@superpowers:executing-plans` for sequential execution and checkpoints.
- `@superpowers:test-driven-development` for each behavior-changing backend/frontend slice.
- `@laravel-best-practices` for Laravel models, validation, transactions, authorization, and queries.
- `@security-review` for payout destinations, webhook authentication, tenant boundaries, and sensitive data.
- `@vercel-react-best-practices` and `@ui-styling` for changed TSX and existing Tailwind conventions.
- `@webapp-testing` for browser-visible manual verification.
- `@ponytail` and `@karpathy-guidelines` for the simplification and assumptions pass.
- `@superpowers:verification-before-completion` before any completion claim.

Before each task:

- [ ] Confirm `git status --short --branch` and preserve unrelated changes.
- [ ] Read changed symbols with CodeGraph first because `.codegraph/` exists.
- [ ] Write the failing test or a focused characterization test before implementation where practical.
- [ ] Run the narrowest relevant check after the task.
- [ ] Commit only the coherent task when its checks pass.

The repository default is one main agent working sequentially. No parallel editing or subagents are used.

## 1. Map exact current contracts and establish tests

**Files:**

- Read: `app/Http/Controllers/UserSide/CheckoutController.php`
- Read: `app/Services/OrderReceiptService.php`
- Read: `app/Services/Orders/OrderFulfillmentService.php`
- Read: `app/Services/Finance/InvoicePaymentService.php`
- Read: `app/Services/OrderRefundService.php`
- Read: `app/Http/Controllers/Api/RefundApprovalController.php`
- Read: `app/Http/Controllers/XenditPayoutWebhookController.php`
- Read: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Read: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Read: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Read: `resources/js/Pages/ERP/Finance/refundApproval.tsx`
- Read: relevant factories in `database/factories/`
- Create: `tests/Feature/Cod/CodSchemaTest.php`
- Create: `tests/Feature/Cod/CodCheckoutTest.php`

- [ ] Query CodeGraph for the checkout, delivery-paid, invoice-payment, refund-execute, and Xendit webhook call paths.
- [ ] Record the exact existing route names, factory prerequisites, auth guards, and order/invoice fields in the implementation notes of the tests.
- [ ] Add a schema smoke test that will fail until the three COD tables and the new refund payout columns exist.
- [ ] Add the first checkout regression test: `payment_method=cod` creates a canonical COD order/collection and makes no PayMongo HTTP request.
- [ ] Run `php artisan test tests/Feature/Cod/CodSchemaTest.php tests/Feature/Cod/CodCheckoutTest.php` and confirm the new tests fail for the expected missing schema/behavior.
- [ ] Commit the tests only: `test: characterize COD checkout and schema contracts`.

## 2. Add COD schema, models, constants, and permissions

**Files:**

- Create: `database/migrations/2026_09_18_000001_create_cod_collection_and_remittance_tables.php`
- Create: `database/migrations/2026_09_18_000002_add_cod_refund_payout_fields_to_order_refunds.php`
- Create: `app/Models/CodCollection.php`
- Create: `app/Models/CodRemittance.php`
- Create: `app/Models/CodRemittanceItem.php`
- Modify: `app/Models/Order.php`
- Modify: `app/Models/OrderRefund.php`
- Modify: `app/Models/Finance/InvoicePayment.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `tests/Feature/Cod/CodSchemaTest.php`

- [ ] Add `cod_collections` with one row per order, shop/order uniqueness, expected/collected amounts, rider/leg references, collection/settlement timestamps, and status indexes.
- [ ] Add `cod_remittances` with shop/rider ownership, unique reference and idempotency key, expected/submitted/received/variance amounts, status, submit/confirm actors, timestamps, and status indexes.
- [ ] Add `cod_remittance_items` with foreign keys, amount snapshot, unique `(remittance, collection)`, and unique `cod_collection_id` to prevent duplicate remittance membership.
- [ ] Add encrypted `refund_destination`, destination type, `refund_provider`, payout status/reference/idempotency/timestamps/failure fields to `order_refunds`; preserve all existing PayMongo columns/defaults.
- [ ] Use string columns plus model constants rather than database enums for new lifecycle state so SQLite and future status additions remain compatible with current repository migration conventions.
- [ ] Add `Order::codCollection()` and `OrderRefund` payout/destination helpers; add the collection/remittance relationships and masked destination projection.
- [ ] Add `InvoicePayment::SOURCE_COD_REMITTANCE` and keep its append-only update/delete guards intact.
- [ ] Add `access-cod-remittances` to the permission catalog and Finance role assignment. Do not grant it to the Logistics Rider role.
- [ ] Run `php artisan migrate:fresh --env=testing` or the repository's normal refresh path and rerun the schema test.
- [ ] Run `php artisan test tests/Feature/Cod/CodSchemaTest.php` and commit: `feat: add COD collection and remittance records`.

## 3. Normalize COD checkout and remove delivery-as-payment behavior

**Files:**

- Create: `app/Services/OrderInvoiceService.php`
- Modify: `app/Models/Order.php`
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `app/Services/OrderReceiptService.php`
- Modify: `app/Services/Orders/OrderFulfillmentService.php`
- Modify: `app/Services/OrderRefundService.php`
- Modify: `tests/Feature/Cod/CodCheckoutTest.php`
- Modify: `tests/Feature/PaymentLifecycleFeatureTest.php`

- [ ] Add a small canonical payment-method helper that accepts legacy input aliases at the boundary but stores only `paymongo` or `cod` for new orders.
- [ ] Extract the existing order invoice creation logic from `CheckoutController::autoGenerateInvoice` into `OrderInvoiceService::ensureForOrder`, preserving item, VAT, shipping, audit, and invoice status behavior. Keep the controller call site behavior-compatible.
- [ ] During COD order creation, create the pending `CodCollection` inside the existing order transaction and ensure the documentary invoice after the order exists; do not create a PayMongo link/session.
- [ ] Branch `CheckoutController::createOrder` and `payment.tsx` so COD returns an order confirmation response without calling `/retry-payment-session`; leave online checkout and duplicate-order protection unchanged.
- [ ] Ensure PayMongo verification still calls the invoice service for online orders and does not create a COD collection.
- [ ] Remove the COD-specific `markCodPaymentPaid` effects from `OrderReceiptService` and `OrderFulfillmentService`; delivery/receipt confirmation must not update COD payment settlement.
- [ ] Keep the online `PaymentSettlementService::settleOrderPaid` and PayMongo refund paths unchanged except for canonical-method guards.
- [ ] Add assertions for no PayMongo HTTP request, pending collection, canonical `cod`, no `paid_at`, and no generic paid state after checkout/delivery.
- [ ] Run `php artisan test tests/Feature/Cod/CodCheckoutTest.php tests/Feature/PaymentLifecycleFeatureTest.php` and the focused existing receipt/fulfillment tests.
- [ ] Commit: `feat: bypass PayMongo for COD checkout`.

## 4. Implement rider Cash Collected action and COD read model

**Files:**

- Create: `app/Services/CodCollectionService.php`
- Create: `app/Http/Controllers/Api/Logistics/CodCollectionController.php`
- Create: `app/Http/Requests/Logistics/CashCollectedRequest.php`
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/services/logisticsApi.ts`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/RetailOrderSummary.tsx`
- Create: `tests/Feature/Cod/CodCollectionTest.php`
- Modify: `tests/Feature/Logistics/RiderTenantAuthorizationTest.php` if the existing contract is the best location for cross-shop coverage

- [ ] Add a failing Cash Collected feature test for assigned rider, exact server amount, duplicate replay, wrong rider, wrong shop, non-COD, cancelled/refused, and delivery-only payment behavior.
- [ ] Implement `CodCollectionService::cashCollected` with deterministic lock order: shipment leg, order, collection. Reuse `LogisticsActorPolicy` and current-stop/assignment checks instead of recreating them.
- [ ] Require the submitted amount to equal the locked order/collection expected amount; never trust a dispatcher/rider amount as the source of truth.
- [ ] Make repeated equivalent requests return the existing collection; conflicting actor/amount/state returns a safe domain error without another row or ledger write.
- [ ] Record rider/leg snapshots, collection timestamp, activity entry, and a deduplicated Finance/shop notification without sensitive data.
- [ ] Add a rider-owned collection listing endpoint that reports held cash as both unremitted and submitted-unsettled amounts, plus pending/submitted/settled groups.
- [ ] Add the current-stop COD amount and action to `MyDeliveries.tsx`; refresh the existing delivery state after success and do not change stop sequencing.
- [ ] Add COD fields to the existing retail shipment summary used by dispatcher/shipment cards.
- [ ] Add routes under the existing auth/user/shop-isolation/logistics middleware. Keep Finance confirmation out of this controller.
- [ ] Run `php artisan test tests/Feature/Cod/CodCollectionTest.php tests/Feature/Logistics/RiderTenantAuthorizationTest.php` and the existing rider progression tests.
- [ ] Commit: `feat: add rider COD cash collection`.

## 5. Implement remittance submission, Finance confirmation, and final Finance history entry

**Files:**

- Create: `app/Services/CodRemittanceService.php`
- Create: `app/Http/Controllers/Api/Logistics/CodRemittanceController.php`
- Create: `app/Http/Controllers/Api/Finance/CodRemittanceController.php`
- Create: `app/Http/Requests/Logistics/SubmitCodRemittanceRequest.php`
- Create: `app/Http/Requests/Finance/ConfirmCodRemittanceRequest.php`
- Modify: `app/Services/Finance/InvoicePaymentService.php`
- Modify: `app/Models/Finance/Invoice.php`
- Modify: `routes/finance-api.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Erp/ReadPageController.php`
- Modify: `app/Services/NotificationService.php`
- Modify: `app/Enums/NotificationType.php`
- Create: `resources/js/Pages/ERP/Logistics/CodCollections.tsx`
- Create: `resources/js/Pages/ERP/Finance/CodRemittances.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Create: `tests/Feature/Cod/CodRemittanceTest.php`
- Modify: `tests/Feature/Finance/InvoicePaymentTest.php` for the controlled operational source contract

- [ ] Add failing tests for rider-only submission, mixed-rider/shop rejection, duplicate item prevention, Finance same-shop visibility, rider-confirm denial, exact confirmation, variance dispute, duplicate confirmation, and append-only invoice-payment history.
- [ ] Implement `CodRemittanceService::submit` by locking selected collections in sorted ID order, validating the authenticated rider owns all of them and they are `cash_collected`, computing totals server-side, and inserting one remittance plus items with one idempotency key.
- [ ] Implement `CodRemittanceService::confirm` by locking remittance, items, collections, orders, and invoices in deterministic order. Require exact received amount; on mismatch update only dispute fields; on exact match call the dedicated invoice-payment operational method and settle all records atomically.
- [ ] Add `InvoicePaymentService::recordCodOrderPayment` or an equally focused method. It must validate linked order/shop/invoice/remittance, reject manual use of operational invoices, append one `cash`/`cod_remittance` entry, and be idempotent by remittance/order key.
- [ ] Update invoice/order compatibility status only after the append-only entry exists in the same transaction. Never write a Finance row when a remittance is disputed.
- [ ] Add Finance and rider notifications with stable group keys and redacted metadata.
- [ ] Add rider `CodCollections.tsx` with held-cash summary, selectable pending-remittance rows, submitted remittances, settled history, and no settle control.
- [ ] Add Finance `CodRemittances.tsx` with list/detail, order references, expected/submitted/received/variance fields, and exact-confirm/dispute behavior.
- [ ] Add Finance navigation and the `access-cod-remittances` route/page contract without broadening unrelated Finance permissions.
- [ ] Run `php artisan test tests/Feature/Cod/CodRemittanceTest.php tests/Feature/Finance/InvoicePaymentTest.php` and relevant Finance tenant/route tests.
- [ ] Commit: `feat: add COD remittance settlement workflow`.

## 6. Expose COD state in Job Orders, shipment cards, and customer order payloads

**Files:**

- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `app/Http/Controllers/ShopOwner/OrderController.php`
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Modify: `resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/RetailOrderSummary.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/types/logistics.ts`
- Create or extend: `tests/Feature/Cod/CodProjectionTest.php`

- [ ] Add a projection test asserting the same order exposes payment method, COD due, collection status, rider collection, and remittance status to staff, shop owner, dispatcher, and customer only within the correct shop/customer scope.
- [ ] Eager-load the dedicated relationships in the existing shop-scoped queries to avoid per-card N+1 queries.
- [ ] Add explicit labels and state chips without replacing order fulfillment status or showing `Paid` for `cash_collected`/`submitted` COD.
- [ ] Keep shipment/batch/stop identifiers and tracking payloads unchanged apart from additive COD metadata.
- [ ] Run the projection test and existing staff/logistics/customer payload tests.
- [ ] Commit: `feat: expose COD state across operational views`.

## 7. Add COD refund destination and bounded refund request/approval behavior

**Files:**

- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Http/Controllers/Api/RefundApprovalController.php`
- Modify: `app/Models/OrderRefund.php`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`
- Create: `tests/Feature/Cod/CodRefundTest.php`
- Modify: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php` or add regression assertions to preserve online behavior

- [ ] Add failing tests for GCash/bank validation, encrypted-at-rest destination values, masked serializers, no-sensitive-log behavior, no-collection refusal, pre-settlement approval, exact settled-COD execution ceiling, concurrent partial refund collision, and PayMongo branch preservation.
- [ ] Extend customer refund validation: COD requires destination type and the type-specific fields; reject malformed bank/channel/account values server-side; never accept provider or original payment method from the client.
- [ ] Add COD-specific captured/collected amount calculation under the existing refund reservation lock. Permit review staging only after cash collection; final execution must recompute `settled COD - active/succeeded refunds` in cents.
- [ ] Keep existing owner/Finance approval, return inspection, partial-line calculations, and online PayMongo eligibility logic intact for non-COD orders.
- [ ] Extend `transformRefund` with original payment method, collection amount/status, remittance status, destination type/masked account, refund provider, payout status, and a server-derived `canExecutePayout`/blocking reason.
- [ ] Update the customer refund modal to show the destination fields only for COD and clear sensitive state when the modal/order changes.
- [ ] Update the Finance refund approval modal to distinguish original COD payment from Xendit refund destination and disable execution before remittance settlement.
- [ ] Run `php artisan test tests/Feature/Cod/CodRefundTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php`.
- [ ] Commit: `feat: add bounded COD refund destinations`.

## 8. Refactor shared Xendit transport and implement asynchronous COD payout/webhook handling

**Files:**

- Create: `app/Services/Finance/XenditClient.php`
- Create: `app/Services/Finance/CodRefundPayoutService.php`
- Modify: `app/Services/Finance/XenditPayoutService.php`
- Modify: `app/Http/Controllers/XenditPayoutWebhookController.php`
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Models/ShopPaymentIntegration.php`
- Create: `tests/Feature/Cod/CodXenditPayoutTest.php`
- Modify: `tests/Feature/Finance/XenditSupplierPayoutTest.php` only for refactor regression assertions

- [ ] Add failing tests for COD payout local idempotency, provider idempotency header, processing response, no duplicate payout on retries/concurrency, missing Xendit configuration, destination payload masking, authenticated webhook, amount/reference mismatch, and duplicate/out-of-order terminal events.
- [ ] Extract only HTTP client, credentials, endpoint, payout-channel, provider-status, and redaction helpers into `XenditClient`; keep supplier payload and settlement code behaviorally unchanged.
- [ ] Implement `CodRefundPayoutService` using encrypted `OrderRefund` destination data and the existing shop Xendit integration. It must never instantiate `SupplierPaymentAttempt` or write supplier expense settlements.
- [ ] In the locked execute path, enforce approvals/return gates/settled-COD ceiling, set local payout to processing with deterministic refund-based idempotency, and call Xendit once outside the local transaction where the existing supplier pattern requires it.
- [ ] Treat unknown provider/network outcomes as processing and reconcile via webhook; do not create a second local payout attempt.
- [ ] Route the existing Xendit webhook first to a COD refund when provider/reference identifiers match, authenticate the same shop callback token, then preserve supplier webhook handling.
- [ ] On success mark the payout/refund completed and notify the customer; on failed/rejected/reversed update the payout state safely without changing the original order method to Xendit or invoking PayMongo settlement.
- [ ] Run `php artisan test tests/Feature/Cod/CodXenditPayoutTest.php tests/Feature/Finance/XenditSupplierPayoutTest.php` and the refund workflow tests.
- [ ] Commit: `feat: add asynchronous Xendit COD refunds`.

## 9. Frontend integration and UX regression checks

**Files:**

- Modify: `resources/js/Pages/UserSide/Orders/payment.tsx`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/CodCollections.tsx`
- Modify: `resources/js/Pages/ERP/Finance/CodRemittances.tsx`
- Modify: `resources/js/Pages/ERP/Finance/refundApproval.tsx`
- Modify: `resources/js/layout/AppSidebar_ERP.tsx`
- Modify: `resources/js/services/logisticsApi.ts`
- Modify: `resources/js/types/logistics.ts` and local component tests adjacent to each page

- [ ] Add/extend Vitest tests for COD checkout bypass, rider action/refresh, rider remittance submission, Finance exact-confirm/variance block, and Finance COD refund disabled state.
- [ ] Keep direct imports and page bundles consistent with current ERP structure; do not add a broad state-management dependency or split small components without evidence.
- [ ] Verify loading, duplicate-click, API error, forbidden, empty, submitted, disputed, and settled states with the repository's monochrome UI conventions and accessible labels.
- [ ] Run `pnpm run test:frontend -- <focused test paths>` or the repository's supported Vitest filter form; record the exact command actually accepted.
- [ ] Run `pnpm run build` after the UI slices compile together.
- [ ] Commit: `feat: integrate COD workflows into customer rider and finance UI`.

## 10. Sequential review and cleanup

**Files:** changed files from Tasks 2-9; `docs/ai-learning-log.md` only if a durable reusable lesson is found.

- [ ] Run a Standards review against repository conventions: tenant scoping, route middleware, model relations, service boundaries, existing audit/notification patterns, and no duplicate systems.
- [ ] Run a Spec review against every frozen requirement and acceptance criterion in the design document.
- [ ] Run the security review for authorization, encrypted destinations, webhook authentication, provider idempotency, logs, and cross-shop access.
- [ ] Run the TypeScript review using `@typescript-advanced-types` only where complex narrowing is actually needed and `@vercel-react-best-practices` for render/data-fetch behavior.
- [ ] Run a Ponytail simplification pass: remove duplicate serializers, speculative abstractions, unused imports, and unneeded dependencies. Keep the shared Xendit client only if it is genuinely reused by supplier and COD payout paths.
- [ ] Run a dead-code scan for the removed delivery-as-payment path and stale COD aliases.
- [ ] Run `git diff --check`.
- [ ] Run the relevant PHPUnit feature set, frontend focused tests, and `pnpm run build`.
- [ ] Run `composer test` if the full suite is practical; if not, record the exact limitation and all narrower results.
- [ ] Commit review/cleanup fixes separately if needed: `refactor: simplify COD workflow integration`.

## 11. Manual QA and completion evidence

- [ ] Customer: create PayMongo order and confirm existing redirect/verification; create COD order and confirm no PayMongo call; request COD refund with GCash and bank destinations; verify masking and status messages.
- [ ] Staff/Job Order: inspect COD pending collection, cash collected, pending remittance, and settled labels; verify no generic Paid label before Finance settlement.
- [ ] Dispatcher: inspect shipment/batch/stop COD amount; verify amount is read-only and stop order/GPS behavior is unchanged.
- [ ] Rider: collect only assigned COD, retry action, inspect held cash, submit own remittance, verify no settle control and no cross-shop data.
- [ ] Finance: inspect submitted remittance, exact-confirm settlement, variance dispute, ledger entry, notifications, refund approval, execution disabled before settlement, and Xendit webhook completion.
- [ ] Verify supplier Xendit payout tests and manual supplier path remain green after client extraction.
- [ ] Record exact migration, PHPUnit, frontend, build, browser/manual, and diff-check commands/results in the final response.
- [ ] Update the implementation summary and durable learning log only where required by the repository workflow.
- [ ] Confirm `git status --short --branch`, list every commit/change, and do not claim unverified paths are complete.
