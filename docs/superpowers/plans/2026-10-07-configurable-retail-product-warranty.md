# Configurable Retail Product Warranty — Audit and Implementation Plan

**Status: Approved owner-management removal implemented; fresh revision evidence recorded in the implementation results.**

**Goal:** Issue immutable, downloadable, emailed retail shoe warranties at authoritative fulfillment, and carry warranty context into SoleSpace's existing return/refund assessment without automatic refunds or an exchange workflow.

**Worktree:** `C:/xampp/htdocs/solespace-master/.worktrees/qa-four-follow-up`.
**Branch/base inspected:** `fix/qa-four-follow-up`, `21cbd443629c31f4c3dee156ee105a2865866216`. This is the previously pushed QA commit, including its production bundle. The worktree was clean before this document.
**Source:** The user's October 7 warranty specification. CodeGraph was consulted first, but reported that its index belongs to the main worktree; findings below were verified against this worktree's actual files. No indexing, dependency installation, migrations, code changes, commits, pushes, or application tests were performed for this planning task.

**Execution:** One main agent, sequential work. The user approved this plan with parent/child revisions; proceed without another approval gate. Generic skill suggestions to commit planning documents, dispatch reviewers, or begin implementation do not override this gate or the repository's Git/delegation rules.

## Owner issued-warranty UI removal revision (October 7)

The user approved removal of the Issued Warranties section. Owner retail configuration remains under **Shop Settings / Operations / Retail Product Warranty**, for future eligible purchases. No replacement page or manual void action is permitted. Existing issuance/item rows, relationships, snapshots, certificates, audit history, refund links/quantity consumption, and customer My Orders remain intact. Staff private certificate access retains its existing gates. Owner download URLs must not point to removed endpoints.

Sequential file-level plan:

- [x] Replace owner-history access tests with regressions proving index/detail/certificate/void routes and catalog entries are absent, failed legacy calls cannot mutate coverage, and owner configuration still works.
- [x] Add settings-page regression coverage for configuration without history/search/filter/pagination/detail/PDF/void management, retaining configuration save/error tests and customer panel tests.
- [x] Remove `RetailWarrantyHistory.tsx` and its tests; unmount/import cleanup in `shopSetting.tsx`.
- [x] Remove owner management routes in `routes/shop-owner-api.php` and entries/method overrides in `config/shop_modules.php`. Move the sole surviving staff certificate action from `ShopOwner/RetailWarrantyController.php` into `Api/StaffRetailWarrantyController.php`, update `routes/web.php`, then delete the obsolete owner controller.
- [x] Remove the uncalled `RetailWarrantyService::filterIssuances` and manual `voidWarranty`; keep successful-quantity SQL/reconciliation, historical void fields and audits. Owner projections carry no certificate URL; customer/staff downloads stay authorized.
- [x] Update this plan's current behavior, expected-file list, implementation results/progress and final inventory; mark previous owner-history evidence historical.
- [x] Run focused failing-to-passing backend/frontend checks, warranty/refund regressions, settings/customer/staff browser checks when runnable, fresh `pnpm run build`, syntax/Pint, manifest validation and `git diff --check`; record sequential review and exact results.

## Approved decisions

| Decision | Recommendation | Consequence |
| --- | --- | --- |
| Granularity | ONE order-level issuance/reference/PDF/email with independent item warranties per `order_items` row | Size/color and partial quantities remain traceable; no per-unit serial-number system. |
| Policy boundary | Snapshot at the first authoritative delivered/completed transition | Editing settings never changes an issued warranty or a captured fulfillment policy awaiting payment reconciliation. |
| Cutover | Default disabled; first enablement establishes a permanent `eligible_orders_from` timestamp; order creation and fulfillment must both be on/after it | Pre-enable purchases, including old pending orders, are excluded without explicit migration approval. This resolves the spec's ambiguity between old-order exclusion and completion-date-only eligibility conservatively. |
| PDF | Add one PHP PDF dependency, `barryvdh/laravel-dompdf`, compatible stable version | Current repository has PDF upload/download and browser printing, but no installed PDF renderer. No image certificate, browser PDF service, QR service, or media generation. |
| Shop-owned warranty assessment | A verified post-fulfillment product warranty can enter the existing refund assessment; ordinary delivery complaints retain Report Order/dispatcher routing | The exception is specific to linked, active warranty lines. It does not waive investigation, inspection, payment, return, or approval requirements. |
| POS | Issue for fulfilled POS purchases; registered buyers receive account access; walk-ins get email when a valid address was collected and owner-assisted offline assessment otherwise | Reuse POS return inspection and refund services; do not ask customers to choose inventory inspection disposition. |
| Email delivery | Persist a durable delivery state and avoid automatic re-send after ambiguous provider acceptance | Ordinary definite failures can retry. Strict end-to-end exactly-once email additionally requires provider idempotency or provider reconciliation; existing SMTP/cache uniqueness alone cannot promise it. Unknown acceptance is not automatically resent; this limitation is approved. |

### Alternatives considered

1. **One order certificate with item children — approved.** One issuance/reference/PDF/email, independently tracked items and quantities; successful refunds never rewrite the original document.
2. **Separate item documents/email.** Rejected: one multi-item purchase must not generate multiple attachments/messages.
3. **Extend repair warranty records or use live shop policy versions directly.** Rejected: repair claims create no-charge repair jobs, while retail claims require refund assessment. Checkout-accepted general policies also differ from a warranty policy snapshotted at fulfillment.

## 1. Existing-system audit

| Concern | Current source and observed behavior | Design implication |
| --- | --- | --- |
| Retail purchase data | `Order`, `OrderItem`, `Product`, `ProductVariant`; `order_items` stores name, price, quantity, size/color, image and variant ID | Use purchased-item facts, not subsequently edited live products. |
| Fulfillment | `Orders/OrderFulfillmentService`, `Orders/OrderTransitionPolicy`, `OrderReceiptService`, `Logistics/ShipmentLegService` | Several authoritative fulfillment writers exist; a UI-only trigger or one controller hook misses supported paths. |
| POS purchase | `RetailPosPaymentService::checkout()` creates a delivered/paid order before creating its items, payment lines, receipt and invoice | A created-order observer is too early. Issue at the successful end of the checkout transaction. |
| Customer surface | `UserSide/OrderController`, `UserSide/Orders/MyOrders.tsx` | Extend existing order payloads and details; omit warranty content when none exists. |
| Owner surface | `ShopOwner/ShopSettingsController`, `ShopOwner/Settings/shopSetting.tsx`, canonical settings routes and two owner order components | Add Retail Product Warranty under Operations; maintain both owner shells/registration types. |
| Existing warranty | `RepairWarrantyService`, `RepairWarrantyClaim`, repair snapshot migrations and Repair Warranty settings | Keep repair configuration/claims untouched; the current `warranty_enabled` field is repair-specific in practice. |
| Refunds | `OrderRefundService`, `RetailPosRefundService`, `RefundLineCalculatorService`, online/POS refund item tables | Preserve reservations, amounts, quantities, inspection and approvals; add a link/context rather than another claim state machine. |
| Mail | Laravel Mailables, `NotificationEmail`, `NotificationService`, `PrivilegedMailDispatcher`, `SendPrivilegedWorkflowMail` | Reuse Laravel mail/queue conventions, not privileged recipient rules or preference-controlled generic mail for the contractual certificate. |
| Documents | Private `local` disk, protected receipt/document download controllers; POS `window.print()` | Reuse protected storage/download conventions; printing is not a canonical server-generated PDF attachment. |
| Auditing | Tenant `AuditLog` and Spatie activity records | Use existing audit storage and actor metadata; no unrelated event ledger. |

Repository searches found no retail warranty settings, issued retail warranties, or warranty certificate implementation. Composer's `spatie/pdf-to-image` mention is an optional suggestion for thumbnails, not an installed PDF generation capability.

## 2. Current retail order lifecycle

`OrderStatus` includes pending, processing, shipped, delivered, completed, cancelled and refund. Delivered/completed are terminal fulfillment states; cancelled/refund are final outcomes too. Payment and receipt state are separate.

- Online processing/shipping and direct pickup completion go through `OrderFulfillmentService`, with transaction locks, tenant/handler checks and transition policy enforcement.
- `OrderReceiptService::confirm()` moves eligible third-party shipped orders to delivered and records `customer_received_at`.
- For shop-owned logistics, early receipt confirmation can occur while the leg is `awaiting_proof_approval` or `proof_correction_required`; it deliberately does **not** declare official delivery. `ShipmentLegService::completeRetailOrder()`/`markOrderDeliveredIfShipped()` supplies the authoritative delivery transition.
- Retail POS immediately fulfills a paid purchase, but item/payment/receipt persistence follows order creation within the transaction.
- Terminal-outcome correction must not reset warranty dates, policy or references.
- The active `Order` model/schema does not provide a reliable common retail delivered/completed timestamp. The legacy `Api/CustomerOrderController` contains a query-builder `delivered_at` write, but the audited active routes use the UserSide receipt controller. Do not hook inactive legacy code or assume that timestamp is present.

## 3. Current refund/return lifecycle

Online customer requests go through `POST /orders/request-refund` and `UserSide/OrderController::requestRefund()`:

- Scope the order to the authenticated customer; require delivered/completed.
- Ordinary shop-owned requests are routed to Report Order/dispatcher investigation.
- The ordinary refund deadline is anchored to the order's cancellation/refund window, normally derived from creation and shop settings, not warranty fulfillment dates.
- Validate reason, selected items/quantities, payment facts/COD destination, and existing evidence requirements (five images plus one video).
- `OrderRefundService::reserveOrderRefund()` locks the order, reserves captured funds, detects duplicates, persists line snapshots, and captures approval requirements.
- Staff assessment/return instructions, configured owner approval and Finance stages remain authoritative. Return receipt includes item disposition/inventory processing. Gateway or COD payout follows existing execution/recovery services.

POS uses `RetailPosRefundService` with `PosTransaction`, `PosRefund`, `PosRefundItem`, and payment/refund lines. Selected refund lines require inspection disposition before progression. Finance/shop authorization remains in the existing endpoints. Customer warranty UI must not fabricate this inspection decision.

`RefundLineCalculatorService` subtracts committed quantities, including open/approved/processing requests. That is a **reservation** measure, not evidence that a refund has succeeded. Warranty consumption must distinguish temporarily reserved quantities from permanently refunded quantities.

## 4. Current email architecture

`config/mail.php` defaults to `log` and declares SMTP and provider transports. `config/queue.php` defaults to the database queue; its database connection does not globally defer jobs until commit. Runtime production mail/queue settings were not read from `.env` and are not assumed.

`PrivilegedMailDispatcher` explicitly dispatches after commit. `SendPrivilegedWorkflowMail` demonstrates encrypted, unique queued jobs, three attempts and backoff, but its uniqueness is cache-based/time-limited and it has no durable warranty/provider delivery receipt. Its event enum and privileged-recipient capability rules should not be expanded into a generic warranty subsystem.

`NotificationService` respects browser/email preferences and sends a generic notification Mailable. Mandatory warranty PDF delivery should use a dedicated transactional Mailable/job; existing refund notifications and principal isolation remain unchanged. An additional in-app notification is not required for the initial scope because My Orders already supplies access.

## 5. Current PDF/file generation capabilities

No Dompdf, mPDF, TCPDF, Snappy, jsPDF or PDFMake renderer was found in the dependency manifests/current generation code. Existing code accepts PDFs as uploaded receipts/evidence and streams private documents. Browser `window.print()` does not provide a durable canonical attachment or reliable offline formatting.

Recommend `barryvdh/laravel-dompdf` with a Blade template and local assets. Its current primary dependency manifest supports PHP 8.1+ and Laravel 12, and its documented remote access default is disabled. Select the stable compatible release at implementation time and commit the lockfile. [Official dependency manifest](https://github.com/barryvdh/laravel-dompdf/blob/master/composer.json), [official usage/configuration](https://github.com/barryvdh/laravel-dompdf).

Use bundled DejaVu Sans for Unicode names/terms, and render representative certificates to images during implementation QA. [Dompdf font documentation](https://github.com/dompdf/dompdf/blob/master/README.md).

## 6. Relevant database tables/models

| Existing tables | Use |
| --- | --- |
| `shop_owners`, `users` | Tenant and buyer identity; account state and shop type. |
| `orders`, `order_items`, `products`, `product_variants` | Order lifecycle and purchased item/variant facts. |
| `shop_policy_versions` | Existing general policy publication/checkout acceptance; preserve it, do not overwrite with warranty edits. |
| `order_refunds`, `order_refund_items` | Online assessment, approvals, returns, item reservations and successful refunds. |
| `pos_transactions`, `pos_payment_lines`, `pos_receipts`, `pos_refunds`, `pos_refund_items`, `pos_refund_lines` | POS identity, payment facts, receipt and item refunds. |
| `cod_collections`, related remittance data | Collected customer cash versus later remittance/Finance processing. |
| Shipment/leg/proof tables, `delivery_disputes` | Authoritative shop-owned delivery and existing investigation workflow. |
| `notifications`, preferences, `audit_logs`, `activity_log`, `jobs`, `failed_jobs` | Existing side effects, audit and delivery recovery. |
| Repair warranty fields/`repair_warranty_claims` | Regression-only; not the retail schema. |

Relevant schema includes the orders/order-items creation migrations, online/POS refund-item migrations, customer receipt fields, COD refund fields, repair snapshot migrations and the current model casts. Do not backfill or repurpose repair rows for retail.

## 7. Relevant controllers/services/jobs/events/listeners

Authoritative writers: `OrderFulfillmentService`, `OrderReceiptService`, `ShipmentLegService`, `RetailPosPaymentService`; late payment facts: `PaymentSettlementService` and COD collection processing. Current platform-fee observers are registered in `AppServiceProvider`; leave their accounting responsibility intact.

Customer/owner/staff reads: `UserSide/OrderController`, `ShopOwner/OrderController`, `Api/StaffOrderController`. Configuration: `ShopSettingsController`. Refund processing: `OrderRefundService`, `RetailPosRefundService`, `RefundLineCalculatorService`, `RefundInventoryDispositionService`, `OrderRefundRecoveryService`, `Finance/CodRefundPayoutService`, `Api/RefundApprovalController`, `Api/RetailPosController`.

Use direct calls to one retail warranty service at the supported fulfillment boundaries. Do not scatter email/PDF logic into controllers or add a generic created-order observer. Reconciliation must also cover late gateway/COD completion and successful refunds without depending exclusively on model observers, since query-builder writes do not emit them.

## 8. Approved warranty architecture

Three models: `ShopRetailWarrantySetting`, `RetailWarrantyIssuance` and `RetailWarranty`. One domain service, one PDF service, one after-commit delivery job/Mailable, scoped controllers and one bounded reconciliation command.

Order → ONE issuance → many item warranties → each purchased OrderItem. Refund items optionally link to the item warranty. The issuance owns the opaque main reference, customer/shop contact snapshots, fulfillment/issuance dates, business timezone, canonical PDF metadata and durable email state. Children own policy/product snapshots, original quantity, start/expiry, effective coverage and audited void metadata.

Capture all policy/shop/buyer/item/timezone facts atomically at the first authoritative fulfillment, including disabled/pre-cutover decisions. Delayed payment cannot reconstruct older coverage from current settings or edited profiles. Issue parent/children in one transaction only after legitimate payment facts. Use existing retail catalog rows without a brittle free-form category whitelist. No separate claim state machine, observer-on-create, exchange or replacement workflow.

## 9. Approved database changes

Four additive migrations; no historical backfill:

1. `create_shop_retail_warranty_settings_table`: unique shop FK; enabled default false; title/duration/unit/description/terms/exclusions/instructions; immutable first-enable `eligible_orders_from`; timestamps.
2. `create_retail_warranty_issuances_table`: unique order FK and opaque warranty_number; shop/customer FKs; frozen shop/customer snapshots; fulfilled_at/issued_at/business_timezone; certificate_path/hash/generated_at/status_at_generation; email_delivery_state/token/attempted_at/sent_at/provider_reference/safe failure code; timestamps.
3. `create_retail_warranties_table`: issuance/shop/order/item/customer FKs; unique order_item_id; policy/item snapshots; original covered quantity; stored start/expiry; active/expired/voided and void reason/actor/time; timestamps. No child PDF/mail fields.
4. `add_retail_warranty_lifecycle_context`: nullable trusted fulfillment timestamp/capture JSON on orders; optional warranty FK on existing online/POS refund-item tables; request_basis default ordinary on refund parents.

Use explicit dates/status/quantity and JSON immutable facts, following local casts. Restrict removal of issued parent/item/shop history; nullable buyer FK retains minimal legitimate name snapshot. Index tenant/issuance date, expiry, customer/order, email state and refund links. Settings edits never change old snapshots. GET does not reconcile/write historical state.

## 10. Warranty state model

| State | Meaning | Allowed next states |
| --- | --- | --- |
| Active | Issued coverage has not expired or been voided; some purchased quantity remains usable | Expired, voided |
| Expired | Current time is later than the snapshotted expiry | Voided if later audit/refund reconciliation requires it; never renew automatically |
| Voided | All covered product quantity was successfully refunded, or a retained historical record was already voided | Terminal; preserve snapshots and reference |

Show remaining covered quantity and separately reserved-for-assessment quantity. A partial successful refund reduces usable quantity without rewriting the original certificate or voiding another item. A rejected/cancelled/failed refund releases reservations and does not consume coverage.

Expiry math: convert the authoritative start to the captured business timezone, add configured calendar days/weeks or no-overflow months/years, then store UTC instants. Expiration is an exact documented local time, not an implicit extra end-of-day extension. Test Jan 31/month end, leap-day/year, timezone boundaries and the exact expiry instant. Display date/time/timezone when needed to avoid misleading date-only boundaries.

Persist expired states through reconciliation; read projection treats an elapsed expiry as expired immediately without mutating a row simply because a GET occurred. Original snapshot dates/duration/terms are immutable.

## 11. Warranty issuance trigger

| Source | Trusted boundary/time | Handling |
| --- | --- | --- |
| Third-party receipt | `OrderReceiptService::confirm()` when it actually changes shipped to delivered | Capture fulfilled time/policy and issue eligible items in the same transaction. |
| Shop-owned delivery | `ShipmentLegService::markOrderDeliveredIfShipped()` after official leg/shipment completion | Use approved delivery facts; early customer receipt alone does not issue. |
| Direct pickup | `OrderFulfillmentService` completed transition with existing direct-fulfillment evidence | Capture first successful completion, retaining handler/tenant/transition checks. |
| Retail POS | End of `RetailPosPaymentService::checkout()` after items, tender lines, receipt and invoice exist | Capture/issue before transaction commit; replay returns existing warranties. |
| Delayed payment facts | Existing payment settlement/COD collection completion plus reconciliation | Use the already captured fulfillment policy/time; do not use later settings or issue for an unfulfilled order. |

Eligibility: retail/both shop, allowed current module/account rules, post-cutover purchase and fulfillment, enabled captured policy, real purchased items, authoritative delivered/completed state, eligible paid/completed or actually collected COD facts, and no fully refunded/failed/cancelled purchase. COD remittance still pending is not the same as the customer not paying.

Rows created before the cutover, manually terminal legacy records with no capture, failed deliveries, pending/processing/shipped orders, or terminal correction repeats do not generate warranties. Reconciliation discovers captured issuance work; it does not invent delivery dates or backfill historical orders.

## 12. ONE email per order issuance

Commit capture/ONE issuance/children → dispatch job with issuance ID after commit → generate/reuse ONE combined PDF → atomically claim durable email state → send ONE Mailable/attachment → store accepted state and audit.

Email includes shop, order, main reference, fulfilled/start/expiry dates, duration, concise terms/exclusions/instructions and all covered products/variants/quantities. Registered POS uses the linked buyer's legitimate name/email; walk-ins use only validated contact from the sale, never account email matching. No recipient means skipped email with certificate still available to authorized shop actors.

States: pending, sending, sent, failed, unknown, skipped. Database claims and terminal guards are authoritative; queue uniqueness is supplementary. Retry definite pre-send/PDF/storage failures with bounded backoff. Any exception after possible transport acceptance or stale sending claim is unknown and must not blindly resend. Strict provider-level exactly-once requires provider support; document this limitation. Jobs carry IDs, safe failure codes omit private transport/contact contents. Reconciliation recovers lost after-commit enqueue without creating another issuance or normal email.

## 13. ONE combined PDF per issuance

Render an escaped Blade template from issuance/child snapshots with local SoleSpace assets/DejaVu Unicode font only; remote resources, arbitrary HTML, PHP and JavaScript disabled. Store ONE canonical PDF at server-computed private `retail-warranties/{shop}/{opaque-order-reference}.pdf`; persist metadata on issuance, verify storage writes and use a concurrency guard. Preserve first-generation dates/status/hash/bytes through downloads, retries, expiry, voids and partial refunds.

Include brand/shop contacts, Product Warranty Certificate title, main reference, order/customer or walk-in identity, order/fulfillment dates, duration/start/expiry/timezone, generation-time status, ALL covered item names/variants/size/color/original quantities, original description/terms/exclusions/instructions and transaction association. Handle long terms/multiple pages/Unicode.

Offline notice: the document records original issued coverage; current status, remaining quantities, refunds and voiding must be checked in SoleSpace when available. Authorized download may ensure a missing PDF but cannot issue coverage. PDF/storage/mail failure never destroys the issuance or children.

## 14. Customer UI changes

My Orders details show Product Warranty only when issuance exists: main reference, issued date, effective Active/Partially Used/No Remaining Coverage/Expired/Voided summary and ONE Download Warranty PDF action. Beneath it show each item/variant, covered/remaining/reserved quantity, dates, status and original policy. GET is a read projection, not a historical mutation. Eager load limited client-safe projections; never expose paths, mail/provider/audit internals or unrelated PII.

For online purchases reuse the existing refund modal with Warranty Assessment context, covered line/quantity selection and existing evidence/payment destination. Preserve ordinary refund and delivery-dispute rules. POS directs customer to shop-assisted assessment; customers do not choose inspection dispositions. Follow existing monochrome cards/buttons/modal/accessibility/responsive patterns.

## 15. Shop Owner UI changes

Add Retail Product Warranty under canonical `/shop-owner/settings/operations`, with existing settings-page section navigation. It is independent from the current Repair Warranty setting and shown only for retail/both shops of either registration type.

Configuration: enabled toggle, bounded positive integer duration, native/existing unit select (days/weeks/months/years), title, description, terms, exclusions and instructions using plain multiline fields. Default disabled. Suggested validation ceiling: ten calendar years expressed consistently in each unit (3,650 days, 520 weeks, 120 months, 10 years); these are input safety limits, not a mandatory warranty duration or policy. Require a title and terms when enabling; configure free text without preselected mandatory exclusions.

Use a dedicated `ShopSettingsController::updateRetailWarranty()` action and `PUT /shop-owner/settings/retail-warranty` endpoint with the retail-only FormRequest. Preserve the existing general/repair settings update validator and save behavior. The existing settings index supplies the new configuration projection; the retail card saves independently with existing validation feedback.

Owner warranty management contains only this configuration. No Issued Warranties section, search/status filter/pagination/detail cards, owner certificate download, manual Void Coverage control or replacement page is exposed. Existing order/POS refund assessment context is retained without an owner certificate link; historical rows and system refund reconciliation remain intact.

Staff see read-only warranty context in their already authorized order detail; a download endpoint uses the same `access-staff-job-orders` or `access-unified-pos` permission, tenant, module and attendance/account rules. Employees cannot edit policy or void coverage merely because they can read orders.

## 16. Authorization/security changes

- Customer reads/downloads require `auth:user`, customer audience/account-state checks and `warranty.customer_id === actor.id`; scope lookup before returning existence or a file. Registered POS buyer access uses the linked purchase ID, never an email match.
- Owner configuration requires `auth:shop_owner`, retail/both eligibility, the enabled retail module and server tenant scope. Dedicated owner issued-warranty read/download/void endpoints are removed; only the owner setting endpoint changes future policy.
- Staff reads use ERP actor context, `access-staff-job-orders`, live tenant scope and the catalogued retail module. No inferred shop from a colliding user ID.
- Classify/name every new internal route in `config/shop_modules.php`, preserving existing gates and owner/employee audience rules. Customer historical downloads do not require an employee module permission; suspension/login enforcement remains intact.
- Downloads resolve only the stored server-generated PDF path, check the expected private namespace, and stream as PDF attachment; no arbitrary path, remote URL or unscoped fallback.
- No owner manual void mutation is exposed. Retain historical void metadata and automatic successful-refund reconciliation/auditing. No new Super Admin powers or weakened Finance authorization.
- Confirm that hard archive, missing buyer, suspended accounts and disabled modules do not trigger unauthorized delivery/read/claim paths. Keep the historical record even when account access is unavailable.

## 17. Refund integration

Do not create a retail warranty claim workflow/table with a second approval state machine. Add optional server-validated `request_basis = warranty` and warranty IDs on selected existing refund lines.

For online requests, keep the current customer/order scope, fulfilled status, evidence, payment/destination, balance, quantity reservations, return inspection and approval chain. Only a validated active warranty for **every selected line and quantity** allows the warranty assessment path beyond the ordinary deadline. Forging the request basis/IDs is not a bypass. A mixed selection containing an uncovered/expired/voided/exhausted item is rejected, not silently included in a full-order refund.

For shop-owned orders, delivery disputes retain the existing Report Order path and evidence/dispatcher restrictions. A post-fulfillment product warranty assessment may enter the existing refund reservation and return logistics, provided no conflicting active delivery dispute/refund exists. Preserve company staff inspection requirements: the current `isThirdPartyCustomerRefund()` helper is specifically restricted to third-party/company orders, so do **not** misclassify a shop-owned warranty as third-party. Introduce a narrow warranty assessment predicate where staff assessment is required, retaining ordinary classification behavior.

For POS, owner/staff assess through the existing retail POS refund endpoints/services and supply legitimate inspection disposition. Link warranty context to those refund lines; approval/execution still follows existing authorization. Customer self-service POS inspection is excluded from the initial implementation.

Validate warranty eligibility and reservations under the same order/refund lock boundary, then capture the evidence/context/remaining quantities in the refund. A request timely at submission may be inspected after warranty expiry; use the immutable submitted-at snapshot rather than silently rejecting a valid pending assessment solely because staff processing took time. Rejection still permits a new assessment only while coverage/reservations allow it.

After authoritative **successful** product refund settlement (gateway completion, COD payout success, or POS success), reconcile linked affected item quantities. Pending/approved/processing only reserves quantities; failed/rejected/cancelled does not void. Full product refund with legacy no-line data voids all covered items only when success and amount/context prove full product resolution. Shipping/delivery-only compensation must not consume shoe coverage. Never use `payment_status = refunded` alone to invalidate unrelated items in a partial refund.

There is an existing `replacement_required` label in delivery-dispute resolution. It does not justify adding replacement/exchange functionality to this feature. Leave unrelated legacy behavior untouched and expose only existing refund/return assessment in warranty UI.

## 18. Idempotency strategy

| Operation | Boundary |
| --- | --- |
| Fulfillment capture | Order row lock; write capture only once, including disabled/pre-cutover decision; never re-read settings on a retry. |
| Issuance | Order/appropriate existing aggregate lock plus unique issuance `order_id`, main opaque reference and child `order_item_id` constraints; do not count existing rows to build a sequence. |
| Configuration race | Serialize the existing settings row and use a consistent locking order with current fulfillment roots; missing-row insertion relies on unique shop key/retry. Do not introduce a shop→order/order→shop deadlock. |
| Certificate | Server-computed stable path, keyed concurrency guard and persisted path/hash/generation facts; reuse existing bytes; repair incomplete generation without changing the warranty. |
| Email | Issuance-level durable claim token/state and terminal sent/skipped/unknown guards; no automatic retry of unknown acceptance; provider idempotency where strict guarantee is required. |
| Refund quantity | Existing reservation/balance checks plus warranty-line scope under root locks. |
| Refund/void reconciliation | Recalculate cumulative successful consumed quantity; transition once with one audit event, not increment per webhook. |

Use existing Laravel transaction, queue, cache-lock and unique-index features. Reconciliation scans bounded batches with eager loading; it does not run an unbounded full-table scan per request. Test concurrency with MySQL/MariaDB, since sequential SQLite tests cannot prove row-lock scheduling.

## 19. Audit logging strategy

Use tenant `AuditLog` for warranty settings changed, policy captured/issued, certificate generated, email accepted/definitely failed/unknown, automatic quantity-exhaustion voiding, and linked refund assessment/consumption. Historical administrative void audits remain preserved. Record actor type and ID separately, shop, target warranty/order, event timestamp, reason and relevant before/after values. Background actors are explicitly system/queue, not forged user IDs.

Do not log raw contact data, full private PDF contents or transport secrets. Settings before/after content is permission-restricted existing audit metadata. Identical retries do not produce duplicate issued/generated/sent/voided events. A successful refund's ordinary accounting/platform-fee audit remains unchanged; warranty audit is additional context, not a duplicate financial action.

## 20. Backward compatibility/migration strategy

Deploy additive schema first, then code, then restart queue workers. All shops start disabled; migrations do not create warranties or derive historical delivery dates from `updated_at`. First enablement sets the per-shop cutover once. Later settings edits do not move it or update old snapshots. Captured disabled/pre-cutover decisions prevent later retries from retroactive issuance.

Use the stricter interpretation of the spec: exclude purchases created before first enablement even if fulfilled afterwards. If the user chooses completion-date-only cutover instead, change that explicit acceptance criterion before coding. No retroactive batch promise without separate approval.

Existing general policies, checkout-accepted policy version, repair warranties, ordinary return windows, platform fees/balances, owner/employee classification and notification isolation remain regression-protected. No destructive database command or `.env` change is part of implementation.

Production rollout requires a real mail transport, running queue/scheduler, private storage capacity and agreed unknown-delivery recovery policy. `log`/`array` mailers are test/local behavior, not proof of customer delivery.

## 21. Edge cases/conflicts to handle explicitly

| Case | Planned treatment |
| --- | --- |
| Early shop-owned receipt / failed delivery / cancelled purchase | No warranty before official fulfillment. |
| Delivered before payment reconciliation | Keep fulfillment time and captured policy; issue only after eligible payment facts arrive, without consulting new settings. |
| Two same-variant rows or quantity > 1 | Distinct item IDs; one item coverage record per row under the shared order certificate, with quantity and independent consumption. |
| Product deletion/name/size/price or shop contact edit | Original item/shop snapshots remain usable offline. |
| Registered POS order has walk-in defaults | Resolve the linked buyer at capture; don't mail an arbitrary walk-in address instead. |
| Walk-in with no email/account | Authorized staff private PDF and existing physical-store assessment; no owner issuance browser or public unprotected certificate link. |
| Month end, leap year, shop timezone change | No-overflow calendar math and captured timezone; no recalculation from current settings. |
| Current refund window shorter than warranty | Verified warranty-specific assessment exception; ordinary deadline unchanged. |
| Shop-owned/company assessment | Preserve required staff inspection without reusing the third-party classification incorrectly. |
| Partial successful refund versus open reservation | Consume only final successful quantity; open request temporarily blocks that quantity. |
| Expiry during staff evaluation | Honor a timely captured submission; still validate terms, return, approvals and remaining funds. |
| Lost enqueue/PDF/storage/mail failure | Preserve records, show preparing/retry state, reconcile pending work; no duplicate issuance. |
| Provider accepted mail but worker crashed | Unknown state; no blind retry. Provider idempotency/reconciliation is needed for strict once-only acceptance. |
| Terminal correction/admin retry/duplicate webhook | First capture/reference/date is immutable; no automatic second certificate/email. |
| Legacy successful refunds without item attribution | Fail closed for ambiguous remaining coverage; require authoritative full-product evidence or assessment, not guessed item allocation. |
| Disabled retail module / suspended account | Existing access/action gates remain; issued data is preserved. |

## 22. Automated test plan and sequential implementation tasks

Use the repo's existing PHPUnit/Vitest framework, factories, clock controls, Mail/Queue/Storage fakes and real service calls. Fake external delivery only; verify actual warranty/PDF storage and audit state. Each stage starts with a failing test, confirms the intended failure, implements minimum changes, then reruns focused and adjacent suites.

| Task | Files/area | Tests and acceptance |
| --- | --- | --- |
| A — schema/settings | Four migrations; three models; settings request/controller/UI | Defaults disabled, enable/disable, days/weeks/months/years including 5 days/1 year, free terms, bounds, first-enable cutover, owner-only and all four shop classifications; repair settings unchanged. |
| B — capture/issue | RetailWarrantyService, parent/children and four fulfillment boundaries; settlement hook | Pending/processing/shipped/cancelled/failed no issue; official delivery/direct/POS issue per row; early receipt no issue; config race/late payment uses original capture; rollback emits no mail; settings edits preserve snapshots. |
| C — PDF/delivery | Issuance PDF service, combined Blade template, order Mailable/job and reconciliation | Actual PDF bytes/metadata, long terms/Unicode/pages, private path, offline required facts, ONE combined stored certificate and ONE normal email per order, duplicate/retried jobs, definite failure recovery, crash/unknown state, no-email walk-in and no active delivery before fulfillment. |
| D — scoped reads/UI | Customer/staff controllers/routes, projections, configuration and customer/staff panels | Customer A/B isolation; shop A/B and colliding IDs; staff permission/tenant/module/account gates; immutable terms; no clutter when absent; customer/staff download/loading/expired/voided states; owner configuration in all supported classifications; removed owner management endpoints stay absent. |
| E — refund context | Existing online/POS services/controller/line models; assessment predicates/projections | Warranty never auto-approves; outside ordinary window with valid covered lines; forged/expired/cross-order/uncovered quantities rejected; shop-owned active-dispute exclusion; existing owner/staff/Finance stages; POS inspection kept. |
| F — final consumption/void | Existing success callbacks and reconciliation | Full/partial product refunds, multi-item and qty 2→1, no consume on failure/reservation, shipping-only compensation unchanged, webhook replay idempotency and automatic consumption/audits; no manual void or replacement endpoints. |
| G — complete review | Relevant regression suites, browser and PDF rendering, build/diff | Sequential Standards/Spec/security/simplification/TS/reuse/dead-code checks; measured red-to-green evidence; no unrun check labelled PASS. |

Planned new feature suites: `tests/Feature/RetailWarranty/{Settings,Issuance,Snapshot,CertificateDelivery,Access,RefundIntegration}Test.php`, plus frontend component/settings/order integration tests listed in section 24. Include factory support rather than a new test framework.

Focused commands after corresponding implementation:

```powershell
php artisan test tests/Feature/RetailWarranty/SettingsTest.php
php artisan test tests/Feature/RetailWarranty/IssuanceTest.php tests/Feature/RetailWarranty/SnapshotTest.php
php artisan test tests/Feature/RetailWarranty/CertificateDeliveryTest.php tests/Feature/RetailWarranty/AccessTest.php
php artisan test tests/Feature/RetailWarranty/RefundIntegrationTest.php
pnpm exec vitest run resources/js/Pages/ShopOwner/Settings/components/__tests__/RetailWarrantySettings.test.tsx resources/js/components/orders/__tests__/RetailWarrantyPanel.test.tsx
```

Related regressions: `Orders/OrderFulfillmentPolicyTest`, `CustomerDeliveryReceiptTest`, `CustomerDeliveryDisputeEvidenceTest`, `RetailPosPaymentFlowTest`, `RetailPosRefundFlowTest`, `RetailPosItemBasedRefundFlowTest`, `OrderItemBasedPartialRefundFlowTest`, `OrderRefundApprovalWorkflowTest`, `OrderRefundReturnInspectionTest`, `OrderRefundRecoveryLifecycleTest`, `Cod/CodRefundTest`, repair warranty suites, PlatformFee suites, Notifications suites, module/owner-role boundary suites and the four-QA regression files from this branch.

Final commands: `composer test`, `pnpm run test:frontend`, `pnpm run build`, `git diff --check`. Run a real concurrent MySQL test for duplicate capture/issuance, settings races, email claims and simultaneous refund reservations. If deployment artifacts are requested, generate `public/build` only on the final approved revision. No type-check/lint PASS claim without actual configured tooling. The current prior QA report documents an unrelated source-scan timeout; verify rather than suppressing failures.

## 23. Manual QA plan

- [ ] For individual-retail, individual-both, company-retail and company-both: enable 5-day coverage with custom terms; save, reload, and confirm repair-only shops cannot configure it.
- [ ] Buy two different products/variants with one line quantity 2; verify nothing is issued while pending/processing/shipped. Confirm official third-party receipt, shop-owned proof approval, direct pickup and POS independently, including online payment/COD.
- [ ] Confirm early shop-owned receipt does not issue. Failed delivery and cancellation do not issue. Duplicate confirmation/webhook/job creates no second record/mail.
- [ ] Change the policy to one year/new text after one purchase fulfills; original reference/dates/terms remain unchanged; future fulfilled purchases use the new policy. Test a policy edit between fulfillment and delayed payment/job processing.
- [ ] Open the email and save the PDF, disconnect from SoleSpace, and check complete readable shop/customer/item/reference/terms information on phone and printed pages. Verify long clauses, Unicode names and multi-page layout.
- [ ] Exercise customer/staff downloads, cross-customer/shop forged URLs, arbitrary file paths, suspended accounts and module gates. Verify owner management URLs are absent, Operations contains only retail configuration, and no replacement page exists. Historical snapshots cannot be edited.
- [ ] Claim valid covered quantity beyond the ordinary refund deadline; follow the existing inspection/return/owner/Finance flow. Active warranty alone does not approve or execute payment.
- [ ] Refund one of two units, then another product: remaining quantities remain distinct. Failed/rejected refund releases reservation; full successful product refund prevents reuse; shipping-only compensation does not void shoe coverage.
- [ ] Verify a timely claim remains reviewable after expiry while a newly submitted expired claim is rejected. Verify preserved historical void records and system successful-refund audit history without manual void controls.
- [ ] Break PDF/storage/queue/mail deliberately in QA, recover definite failures, and simulate provider acceptance followed by worker crash. Unknown delivery must not auto-resend; original warranty/account access remains.
- [ ] Verify old orders remain uncovered after enablement/reconciliation. Inspect audit events for actor/tenant correctness and absence of duplicate side effects or sensitive transport details.

## 24. Exact expected files

### Create

| Path | Responsibility |
| --- | --- |
| `database/migrations/2026_10_07_075506_create_shop_retail_warranty_settings_table.php` | Dedicated enabled policy and cutover. |
| `database/migrations/2026_10_07_075507_create_retail_warranty_issuances_table.php` | One order reference/document/email aggregate. |
| `database/migrations/2026_10_07_075508_create_retail_warranties_table.php` | Independent immutable item coverage. |
| `database/migrations/2026_10_07_075509_add_retail_warranty_lifecycle_context.php` | Trusted fulfillment capture and existing refund links/basis. |
| `app/Models/ShopRetailWarrantySetting.php` | Typed settings and shop relationship. |
| `app/Models/RetailWarrantyIssuance.php` | Order reference, private PDF/mail state and item relationships. |
| `app/Models/RetailWarranty.php` | Warranty casts/relationships and immutable fields. |
| `app/Services/RetailWarrantyService.php` | Capture, issuance, projection, validation and automatic reconciliation/audit; no owner search/status-filter/manual-void helpers. |
| `app/Services/RetailWarrantyCertificateService.php` | Safe stable private PDF rendering. |
| `app/Jobs/DeliverRetailWarranty.php` | After-commit, durable claim/retry/sent/unknown semantics. |
| `app/Mail/RetailWarrantyMail.php` | Transactional certificate email/attachment. |
| `app/Http/Controllers/UserSide/RetailWarrantyController.php` | Customer-scoped detail/download. |
| `app/Http/Controllers/Api/StaffRetailWarrantyController.php` | Staff private download with existing employee/permission/tenant/module gates. Obsolete owner management controller removed. |
| `app/Http/Requests/ShopOwner/UpdateRetailWarrantySettingsRequest.php` | Owner/business-type validation for the dedicated retail action in the existing settings controller. |
| `app/Console/Commands/ReconcileRetailWarranties.php` | Bounded expiry/refund/issuance/delivery reconciliation; no historical backfill. |
| `resources/views/warranties/retail-certificate.blade.php` | Offline PDF layout with escaped snapshots/local branding. |
| `resources/views/emails/retail-warranty.blade.php` | Email explanation and original reference/coverage. |
| `resources/js/types/retailWarranty.ts` | Shared limited client projection. |
| `resources/js/components/orders/RetailWarrantyPanel.tsx` | Customer/staff read-only item coverage and downloads. |
| `resources/js/Pages/ShopOwner/Settings/components/RetailWarrantySettings.tsx` | Owner future-purchase configuration only; no issued-warranty management. |
| `database/factories/ShopRetailWarrantySettingFactory.php`, `database/factories/RetailWarrantyIssuanceFactory.php`, `database/factories/RetailWarrantyFactory.php` | Feature fixtures following current conventions. |
| `tests/Feature/RetailWarranty/SettingsTest.php` | Settings/owner/type/cutover checks. |
| `tests/Feature/RetailWarranty/IssuanceTest.php` | Supported fulfillment paths and replay. |
| `tests/Feature/RetailWarranty/SnapshotTest.php` | Immutable policy/quantity/temporal boundary checks. |
| `tests/Feature/RetailWarranty/CertificateDeliveryTest.php` | Actual PDF/private file/mail/retry/unknown tests. |
| `tests/Feature/RetailWarranty/AccessTest.php` | Customer/staff/security boundaries and regression proving owner issued-management endpoints are absent. |
| `tests/Feature/RetailWarranty/RefundIntegrationTest.php` | Existing assessment and final successful consumption. |
| `resources/js/components/orders/__tests__/RetailWarrantyPanel.test.tsx` | Projection/download/claim/absent-state UI checks. |
| `resources/js/Pages/ShopOwner/Settings/components/__tests__/RetailWarrantySettings.test.tsx` | Configuration save/error feedback only. |
| `resources/js/Pages/ShopOwner/Settings/__tests__/shopSetting.retail-warranty.test.tsx` | Actual settings-page configuration-only regressions for individual/company retail/both and repair-only exclusion. |

### Modify

| Path | Expected narrow change |
| --- | --- |
| `composer.json`, `composer.lock` | One approved PHP PDF renderer and its resolved dependencies; no frontend dependency. |
| `app/Models/Order.php`, `app/Models/OrderItem.php`, `app/Models/ShopOwner.php` | Trusted capture casts and warranty relationships, without client-writable historical fields. |
| `app/Models/OrderRefund.php`, `app/Models/OrderRefundItem.php`, `app/Models/PosRefund.php`, `app/Models/PosRefundItem.php` | Server-owned assessment basis and warranty links. |
| `app/Http/Controllers/ShopOwner/ShopSettingsController.php` | Read retail configuration and add a dedicated atomic retail-save action; preserve existing general/repair update validation. |
| `app/Services/Orders/OrderFulfillmentService.php` | Capture/issue on authoritative terminal fulfillment only. |
| `app/Services/OrderReceiptService.php` | Third-party actual delivery hook; early shop-owned receipt unchanged. |
| `app/Services/Logistics/ShipmentLegService.php` | Official delivery hook, preserving current proof/movement locks. |
| `app/Services/RetailPosPaymentService.php` | Issue after complete checkout persistence; registered buyer capture. |
| `app/Services/PaymentSettlementService.php` | Complete previously captured eligible issuance after payment; successful full-refund context reconciliation where authoritative. |
| `app/Services/OrderRefundService.php` | Linked warranty validation/reservation, required assessment predicate, and successful refund reconciliation. |
| `app/Services/RetailPosRefundService.php` | Link/validate warranty context and successful item refund consumption. |
| Existing `Finance/CodRefundPayoutService` callbacks via `PaymentSettlementService` | Reuse the shared successful-settlement hook; no duplicated COD payout implementation. |
| `app/Http/Controllers/UserSide/OrderController.php` | Eager-loaded warranty projection and bounded warranty assessment extension in existing refund endpoint. |
| `app/Http/Controllers/ShopOwner/OrderController.php`, `app/Http/Controllers/Api/StaffOrderController.php` | Authorized item coverage/assessment projections. |
| `app/Http/Controllers/Api/RetailPosController.php` | Validate optional warranty context through existing POS assessment routes. |
| `routes/web.php` | Dedicated owner retail-settings update, named customer downloads and existing staff-scoped download route. |
| `routes/shop-owner-api.php` | Remove owner issued-warranty index/detail/certificate/manual-void endpoints. |
| `config/shop_modules.php` | Name/classify new internal routes and preserve `retail_operations`/actor contracts. |
| `routes/console.php` | Scheduled bounded reconciliation. |
| `resources/js/Pages/UserSide/Orders/MyOrders.tsx` | Item warranty panel and existing refund modal context. |
| `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx` | Mount retail settings under Operations; repair settings unchanged. |
| `resources/js/Pages/ShopOwner/Operations/JobOrders.tsx`, `resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx`, `resources/js/Pages/ERP/STAFF/JobOrders.tsx` | Read-only warranty/assessment context in supported order shells. |
| `resources/js/Pages/ERP/cashier/POS.tsx`, `resources/js/Pages/ShopOwner/Repairs/service management/POS.tsx` | Retail-mode inspection/refund warranty context only; repair modes unchanged. |
| `app/Services/Orders/OrderRefundOwnerProjection.php` | Preserve owner assessment/history classification when adding warranty metadata. |
| Existing affected test files named in section 22 | Extend fixtures/cases only where required; preserve legitimate gates rather than disabling middleware. |
| `docs/ai-learning-log.md` and this plan | Record approved decisions, actual evidence and durable lessons after implementation. |
| `public/build/manifest.json`, generated `public/build/assets/*` | Only at final verified implementation delivery if fresh deployment output is requested. Not changed during planning. |

Conditional only: a dedicated provider-idempotent mail adapter/config would require an agreed transport capability; do not add an adapter hierarchy or change `.env` speculatively. No changes are planned to repair warranty services, privileged mail capabilities, replacement routes, general policy publication, or unrelated notifications.

## Plan review record at approval (before implementation)

- **Scope/spec:** All 24 requested planning areas covered; no application implementation performed.
- **Standards/reuse:** Existing order items, transactions, refund assessment, queues, private storage, audit and owner UI reused; retail remains separate from repair warranty behavior.
- **Risk:** Identified cutover ambiguity, missing shared fulfillment time, POS creation timing/buyer defaults, shorter refund deadlines, shop-owned routing/company inspection, quantity reservations versus consumption, and ambiguous email acceptance.
- **Simplification:** One policy per shop, ONE order issuance/certificate/email with independent item coverage, no separate claim state machine, no image/QR/exchange feature, no mandatory sample exclusions, no new frontend package.
- **Required stack for this planning change:** Simplification, sequential Standards/Spec/risk review, surgical-scope review and security design review recorded above. TypeScript/code-splitting/runtime dead-code checks are N/A because no application source changed. Runtime improvement measurements are not measured. Implementation review gates remain scheduled in task G.
- **Verification:** Current worktree audit and read-only searches performed. Implementation tests/migrations/build are planned, not reported as run. Document whitespace and worktree status checked before handing off; only this new plan is changed.

**Execution authorization:** The planning gate is satisfied. Proceed sequentially through A schema/models, B settings, C fulfillment/issuance, D combined PDF, E order email, F customer/downloads, G owner/staff views, H refund assessment, I successful quantity consumption/reconciliation; focused red/green checks before advancing.

**Required acceptance test/manual QA:** One-year warranty; ONE purchase with A qty2/B qty1/C qty1. Before official fulfillment: zero issuance/children/mail. After: ONE issuance, THREE children, ONE combined PDF and ONE email. Changing settings to five days preserves old one-year snapshots/PDF; future eligible fulfillment uses five days. Existing assessment reserves one A without automatic refund; successful settlement leaves A qty1 and B/C unchanged. Original PDF/reference/email count stay unchanged; requesting two A is rejected. Include Unicode/multi-page PDF, known failure/unknown mail acceptance, private customer/tenant/staff downloads and all logistics/payment/shop types.

**Delivery evidence:** Record actual commands/results in the implementation progress/results document; do not label unrun checks PASS. Fresh production build after final source revision; no destructive database operations or unrelated refactors.

## Implemented file-level adjustments and evidence

The approved aggregate architecture is implemented. The actual additive migration timestamps are listed above. A shared successful-settlement hook covers existing COD payout callbacks; no duplicate COD payout implementation was added. The real canonical company-owner monitoring controller (`ShopOwnerOperationsMonitoringController`) and the optional shared manager-order TypeScript field provide owner coverage without changing manager payload permissions. The owner configuration remains under Operations. The former `RetailWarrantyHistory` component/test and owner issued-management controller/routes/catalog entries are removed; no replacement page is introduced. Existing refund context remains without owner PDF links.

Additional bounded tests cover the required acceptance scenario, real outer commit/rollback, successful quantity consumption, official proof/pickup/COD, late-payment scheduler recovery, immutable replay and batched My Orders queries. Warranty relation queries were reduced from six to two for three orders. No separate claim state machine, new frontend dependency, exchange, renewal or historical policy editing was introduced.

The [implementation results](2026-10-07-retail-product-warranty-results.md) contain the requested 13-part report, sequential review record, exact commands and full-suite limitations. The [file inventory](2026-10-07-retail-warranty-file-inventory.md) lists all created/modified source and fresh build output. The review record above is the historical planning gate; implementation evidence supersedes its not-yet-run statements. Warranty publication is authorized on `fix/qa-four-follow-up`, rebased onto the current merge target before the final build.

The owner-management removal revision passed 68 backend tests (527 assertions) and 126 frontend tests (27 files); browser config save/removal and unchanged customer PDF verified at desktop/mobile with zero JavaScript errors; fresh production build passed. Detailed exact commands and historical full-suite limitations are retained in the results document.
