# Deployed QA Audit and Remediation Implementation Plan

> **Status: IMPLEMENTATION APPROVED.** The active user-approved goal authorizes sequential A-K implementation with the additional numbered business rules. The user subsequently approved the explicit independent third-party retail tracking exception; no internal dispatch permission follows from it. See implementation progress and final verification for current results. Audit observations below describe the initial audit phase. Execute sequentially with `superpowers:executing-plans`; repository instructions prohibit delegation without an approved parallel-review gate.

**Goal:** Resolve QA #1–#18 while preserving tenant isolation, RBAC, module gates, attendance, verified payments, valid refunds, existing logistics, accounting, and history.

**Architecture:** Repair existing identity, eligibility, snapshot, and serialization boundaries. Reuse the notification hooks, owner actor resolver, module access service, repair planning/payment/refund/warranty services, fee ledger, and report service. Avoid eighteen unrelated patches and avoid replacing working infrastructure.

**Tech stack:** Laravel 12, PHP 8.2, Inertia 2, React 18, TypeScript, TanStack Query, pnpm/Vite, PHPUnit/Vitest.

## Scope, checkout, and evidence limits

- Repository remote verified: `https://github.com/hyde-dev001/space-fixed`.
- Audited checkout: `C:/xampp/htdocs/solespace-master/.worktrees/platform-fee-balance`.
- Branch: `fix/platform-fee-balance`; audited HEAD: `539e204ff3`.
- The parent checkout is on `fix/logistics-hardening-follow-up` and contains unrelated changes. It was not switched or edited.
- Worktree initially contained untracked `docs/superpowers/plans/2026-10-03-shop-owned-logistics-custody-remediation.md` and `storage/framework/cache/`. Preserve both. The custody document is not the specification for this task.
- Source specification: the user attachment `e44eb1a0-a1e5-4fb3-b733-1e867a48d395/Pasted text.txt`, plus the user's clarification that **all symptoms occur on the deployed website**, `https://solespace.shop`.
- All eighteen symptoms are accepted as deployed QA observations. A confirmed symptom does not establish a proposed root cause.
- CodeGraph was consulted before application searches. Subsequent claims use current on-disk code, including symbols the graph did not resolve accurately.
- Public deployment inspection returned HTTP 200. The entry asset is `app-CbJD8eYl.js`; its page map names `myRepairs-DoBi55D0.js`, `Invoice-DPr9_2gb.js`, `Reports-BCCozHbP.js`, and `useNotifications-P3dc7MVF.js`. The repair page filename matches this worktree's committed manifest. This does **not** prove the deployed PHP commit or all server assets match HEAD.
- Direct inspection of the deployed repair asset confirms the existing five-parameter PayMongo cleanup. An authenticated browser trace was unavailable: the browser tool reported no available browser. No production payments, claims, refunds, module changes, or account changes were performed.
- Read-only local MySQL inspection found 202 users, 6 shops, **0 repairs, 0 repair refunds, 0 warranty claims**, and 2 retail fee charges. These are not QA's production fixtures. No production database was inspected.
- No application code, tests, migrations, dependencies, or existing documents are changed by this phase. This new document is the deliverable.

**Acceptance contract:** every QA item has a trace, evidence classification, minimal proposed fix, models/guards/side effects, automated tests, manual QA, and regression risks below. Unresolved deployed causes remain explicit; no inferred database leak or accounting defect is presented as proven.

## Evidence catalog

Paths are relative to the audited worktree. Line numbers refer to HEAD/current files at audit time. The catalog and the workstream entries jointly provide the full frontend → API → authorization → service/model → side-effect trace for each table row.

### Exact source path index

Short filenames used below resolve to these paths; they do not imply that controllers/services all live directly in their parent directories.

| Symbol / filename | Current path |
| --- | --- |
| RepairRequestController | `app/Http/Controllers/Api/RepairRequestController.php` |
| RepairWorkflowController | `app/Http/Controllers/Api/RepairWorkflowController.php` |
| RepairAvailabilityController | `app/Http/Controllers/Api/RepairAvailabilityController.php` |
| RepairWarrantyClaimController | `app/Http/Controllers/Api/RepairWarrantyClaimController.php` |
| RepairerWarrantyClaimController | `app/Http/Controllers/Api/RepairerWarrantyClaimController.php` |
| RepairPosController | `app/Http/Controllers/Api/RepairPosController.php` |
| ManagerController | `app/Http/Controllers/Api/ManagerController.php` |
| PlatformFeeController / InvoiceController | `app/Http/Controllers/Api/Finance/PlatformFeeController.php` / `app/Http/Controllers/Api/Finance/InvoiceController.php` |
| ReadPageController | `app/Http/Controllers/Erp/ReadPageController.php` |
| NotificationController (general / preferences) | `app/Http/Controllers/NotificationController.php` / `app/Http/Controllers/Api/NotificationController.php` |
| ErpNotificationController | `app/Http/Controllers/ErpNotificationController.php` |
| ManagerReportService / ManagerAssignmentEligibilityService | `app/Services/Manager/ManagerReportService.php` / `app/Services/Manager/ManagerAssignmentEligibilityService.php` |
| FinanceShopContext | `app/Support/Finance/FinanceShopContext.php` |
| RecipientResolver | `app/Services/Notifications/RecipientResolver.php` |
| DeliveryScheduleService / ShipmentRequestService | `app/Services/Logistics/DeliveryScheduleService.php` / `app/Services/Logistics/ShipmentRequestService.php` |
| SourceShipmentService / LogisticsActorPolicy | `app/Services/Logistics/SourceShipmentService.php` / `app/Services/Logistics/LogisticsActorPolicy.php` |
| RepairDeliveryService / RepairWarrantyService | `app/Services/RepairDeliveryService.php` / `app/Services/RepairWarrantyService.php` |
| RepairPosRefundService / PaymentSettlementService | `app/Services/RepairPosRefundService.php` / `app/Services/PaymentSettlementService.php` |
| RepairMaterialPlanningService / ShopModuleAccessService | `app/Services/RepairMaterialPlanningService.php` / `app/Services/ShopModuleAccessService.php` |
| PlatformFeeLedgerService / PlatformBalanceService | `app/Services/PlatformFeeLedgerService.php` / `app/Services/PlatformBalanceService.php` |
| ShopOwnerActorUserResolver / NotificationService | `app/Services/ShopOwnerActorUserResolver.php` / `app/Services/NotificationService.php` |
| EmployeeOperationalPolicy | `app/Services/HR/EmployeeOperationalPolicy.php` |
| HandleInertiaRequests / module / attendance middleware | `app/Http/Middleware/HandleInertiaRequests.php` / `app/Http/Middleware/EnsureShopModuleEnabled.php` / `app/Http/Middleware/EnsureEmployeeClockedIn.php` |
| RepairRequestPlatformFeeObserver / AppServiceProvider | `app/Observers/RepairRequestPlatformFeeObserver.php` / `app/Providers/AppServiceProvider.php` |
| QueryProvider / UserDropdown | `resources/js/providers/QueryProvider.tsx` / `resources/js/components/header/UserDropdown.tsx` |
| RepairProcess / myRepairs | `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx` / `resources/js/Pages/UserSide/Repairs/myRepairs.tsx` |
| JobOrdersRepair | `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx` |
| StaffWorkload / Reports | `resources/js/Pages/ERP/Manager/StaffWorkload.tsx` / `resources/js/Pages/ERP/Manager/Reports.tsx` |
| Invoice / Finance / PlatformBalance | `resources/js/Pages/ERP/Finance/Invoice.tsx` / `resources/js/Pages/ERP/Finance/Finance.tsx` / `resources/js/Pages/ERP/Finance/PlatformBalance.tsx` |

Models referenced below live under `app/Models/`; table names are listed in the trace matrix. Route paths and their guards are traced separately below.

| Ref | Exact source and function evidence |
| --- | --- |
| N1 | `resources/js/layout/AppHeader.tsx:146` renders `components/header/NotificationCenter.tsx`, with `/api/notifications` for non-admin users. `NotificationCenter.tsx:36–39` calls `useUnreadCount`, `useNotifications`, and mutations. The named `components/header/NotificationDropdown.tsx` is a static legacy component, not this header's notification implementation. `components/common/NotificationDropdown.tsx:24` also uses the shared hooks. |
| N2 | `resources/js/hooks/useNotifications.ts:88,139,170,198,342` keys lists, counts, recent, stats, and preferences by endpoint/filter only. No authenticated principal/shop appears. `QueryProvider.tsx:5–15` creates one module-level QueryClient, with 5-minute freshness and 10-minute cache retention. `resources/js/app.jsx:52` mounts it around the app. `UserDropdown.tsx:79` performs Inertia logout; no identity reset exists in the provider. Polls run every 10/15 seconds. Query functions do not forward the Query abort signal. |
| N3 | `routes/api.php:427–444`: `/api/notifications`, `web`, `auth:user` → `NotificationController::index` (`:28`), querying `user_id` (`:32`). `AppHeader` uses this path, **not** the ERP scoped controller. `routes/hr-api.php:373–385` provides the ERP notification group → `ErpNotificationController::buildScopedNotificationQuery` (`:350`), `user_id` plus own/null `shop_id`. Customer general endpoints remain user-scoped; their absence of shop filtering is not itself cross-user leakage. |
| N4 | `app/Services/NotificationService.php:106–116` writes addressed `user_id` and recipient/shop context. `Notifications/RecipientResolver.php::resolveDelegatedUsers` uses shop and direct permissions, not a broadcast to every user. Its delegated-event mapping is coarse and needs event-specific tests, but it is not evidence that QA's recipients were stored incorrectly. Existing notification rows must be preserved. |
| W1 | `ManagerController::getStaffWorkload`, `app/Http/Controllers/Api/ManagerController.php:347–356`: same-shop users selected when legacy role is STAFF/REPAIRER **or** a Spatie Staff/Repairer role exists. No employee record is required; no owner-role exclusion exists. Filters `:486` and effective status `:628` handle inactive accounts separately. |
| W2 | `ShopOwnerActorUserResolver::ensure`, `app/Services/ShopOwnerActorUserResolver.php:69–87`, defaults an existing empty role or new owner proxy to `STAFF`, then assigns Shop Owner. `resolve` (`:14`) already recognizes owner-role/email linkage. Payslip approval service/controller call `ensure`. `User::isEmployeeAccount` (`app/Models/User.php:138`) considers any non-null `shop_owner_id` an employee. `User::employee` (`:176`) links by email, not user ID. ShopOwner and User IDs are separate namespaces. |
| L1 | `resources/js/Pages/UserSide/Repairs/RepairProcess.tsx:440,465` requests `/api/repair/shops/{id}/delivery-quote`; `:482–483` offers shop-owned transport based on company registration, saved address, and quote availability. `routes/web.php:1537` → `RepairAvailabilityController::deliveryQuote` (`:58–73`) validates the customer's address then calls quote. `RepairRequestController::store` (`:193–211`) and `changeDeliveryMethod` (`:1668`, quote in its plan builder) trust `RepairDeliveryService::quote` for shop pickup/delivery. |
| L2 | `RepairDeliveryService::quote`, `app/Services/RepairDeliveryService.php:62–82`: company check → `DeliveryScheduleService::coverage` → shipping estimate. `DeliveryScheduleService.php:108` reads LogisticsSetting, pins, and radius; it does not call `ShopModuleAccessService`. Coverage is distinct from module access. |
| L3 | `RepairWarrantyService::ensureWarrantyLogisticsAllowed` (`:835–855`) only prohibits individual-shop riders. `warrantyDeliveryLeg` (`:858–903`) accepts any saved original-address candidate, checks ownership and quote, but does not preserve original walk-in-only methods. `myRepairs.tsx:5908–5974` renders third-party options and company riders, disabling non-walk-in options only when an address is missing. |
| L4 | `Logistics/SourceShipmentService::ensureRetailOrderShipment` (`:22`), `ensureRefundReturnShipment` (`:128`), `ensureRepairInboundShipment` (`:209`), `ensureRepairReturnShipment` (`:330`) reach `ShipmentRequestService::requestShipment` (`:20`). The latter validates payload/method/tenant and locks the shop, but has no module gate. Existing shipment lookup/replay already exists, e.g. `SourceShipmentService.php:226–229,347–349`. |
| L5 | `ShopModuleAccessService::decide/canAccess` (`:18,:121`) is the existing module authority. `EnsureShopModuleEnabled.php:32` respects the enforcement setting. Customer warranty routes are classified customer-facing in `config/shop_modules.php:946–947`; blanket logistics-route gating would also block legitimate walk-in/third-party repair use. `LogisticsActorPolicy.php:390–391` unconditionally denies leg actions when Logistics is unavailable; continuation needs explicit, narrow preservation/coverage rather than assuming it already works for every action. |
| M1 | `RepairRequestController::store` builds included-service price/name snapshots (`:312–318`), stores them (`:475–476`), attaches services (`:513`), but creates no material snapshot. `RepairWorkflowController::getRepairMaterialUsage` (`:3238`) and readiness handlers (`:541,:555`) call `ensureRepairMaterialPlanItems`. |
| M2 | `RepairWorkflowController::ensureRepairMaterialPlanItems` (`:4112–4144`) recalculates plans and overwrites existing planned quantities from live templates. `buildTemplateMaterialPlan` (`:4147–4263`) loads package/service templates, uses package services only as an empty-plan fallback (`:4167`), reloads live services from saved IDs (`:4200`), and aggregates by inventory ID (`:4247`). `repair_material_plan_items` already has a unique repair/material key (`database/migrations/2026_04_04_000002_create_repair_material_plan_items_table.php:23`). |
| M3 | `RepairMaterialPlanningService::validateStartReadiness/validateCompletionReadiness` validates stock/variance only; it is not the current template resolver. `RepairWorkflowController::logRepairMaterialUsage` (`:3346`) is the actual consumption flow. Planning itself does not decrement stock. UI: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx:3666`, “Planned From Templates”. |
| P1 | `RepairRequestController::updatePaymentLink` generates success/failure URLs with `paymongo_success/failed`, `pending_repair_id`, `return_ts`, `return_sig` (`:2711–2720`). `routes/web.php:238–252` renders `/my-repairs` without a canonical server callback redirect. |
| P2 | `myRepairs.tsx:2477–2512` mount effect reads sessionStorage, prioritizes session pending ID over query ID, writes a handled marker, then runs `history.replaceState({}, '', cleanUrl)` before server verification. It deletes all five parameters. `:2561` POSTs verification; `:2640–2641` runs the effect only on mount. Same code is present in deployed `myRepairs-DoBi55D0.js`. Browser history state/Inertia state are not explicitly preserved together. Runtime interference is a hypothesis, not confirmed. |
| P3 | `RepairRequestController::verifyPayment` (`:3173`) authenticates/scopes a customer or validates a signed public return (`:3177–3188`); checks canonical settlement (`:3209`), retrieves provider payment, calls `PaymentSettlementService::settleRepairPaid` (`:3348`). Return signatures have HMAC/timestamp bounds (`:3391–3420`). URL flags are not settlement authority. |
| R1 | `RepairWorkflowController::acceptRepair` sends walk-in to `pending`, other intake to `repairer_accepted` in both owner/repairer branches (`:674–676,:800–802`). `RepairRequestController::updateServices` (`:2278–2306`) permits only `repairer_accepted` with a conversation and no recorded payment. `myRepairs.tsx:5059–5071` mirrors the status/chat/payment lock. No direct POS-origin edit lock explains this; the intake-dependent status transition does. |
| R2 | `RepairRequestController::requestRefundFromMyRepair` checks customer ownership, active warranty, review, evidence, paid transaction, tenant, and refundable balance (`:1190–1509`), but has no completed-workflow eligibility guard. `RepairPosRefundService::requestRefund` (`:213–287`) checks repair source/amount/active refund, not workflow completion. UI Refund is currently under `order.status === 'picked_up'` (`myRepairs.tsx:5214`), so QA's unfinished **visible button** cannot be explained solely by this current render condition. |
| R3 | `RepairWarrantyService::validateEligibility` (`:129–217`) checks original job, owner/customer, `picked_up`/`received`, issued/unexpired warranty, and prior claims, but never refund rows/payment refund status. Customer claim creation further requires `picked_up` (`:231`). Creation (`:666`) has no shared repair lock with refund initiation. Approval does lock/revalidate the original (`:305,:309`), but that validator also lacks refund checks. |
| R4 | `RepairPosRefundService::markRefundSucceeded` (`:1408–1422`) updates totals and payment status to refunded/partially_refunded, leaving workflow status unchanged. `myRepairs::getStatusText` (`:3935`) shows “Refunded” from the refund row, while the job can remain `picked_up`. `myRepairs.tsx:5205` renders warranty from backend `warranty.can_claim`. Its refund-aware block-reason helper (`:2200`) is not included in that button condition or `canFileWarrantyClaim` (`:2175–2180`). |
| R5 | `myRepairs.tsx:2699–2724` sets repair rows before separately fetching refund rows and latest warranty claims. Existing local conflict checks can therefore lag. Refund-after-warranty rejection exists (`RepairRequestController.php:1231–1239`); warranty-after-refund rejection does not. `received` is also an intake status, and must not become generic proof of completed service merely because it appears in a legacy warranty validator. |
| F1 | `AppServiceProvider.php:60` registers `RepairRequestPlatformFeeObserver`. Its `created` calls `finalizeRepair`; `updated` watches `status`, `payment_status`, `total_paid_amount` (`app/Observers/RepairRequestPlatformFeeObserver.php:14–22`). |
| F2 | `PlatformFeeLedgerService::isEligibleRepair` (`:167–181`) accepts only workflow `completed` or **hyphenated** `ready-for-pickup`, requires marketplace/non-warranty, positive final_total/total, paid/completed payment status, and raw total_paid_amount ≥ base. Current workflow writes **underscored** `ready_for_pickup` (`RepairWorkflowController.php:2054,2108`), and final receipt writes `picked_up` (`RepairRequestController.php:2235`). |
| F3 | `PlatformFeeLedgerService::originForRepair` (`:210`) uses explicit origin, then manual_pos/REP-POS prefix; it does not use walk-in intake. Customer store sets marketplace (`RepairRequestController.php:467`). `finalize` (`:62`) checks positive base/shop, unique source, effective_from, decimal fee/VAT; creates a snapshot charge, applies available credits, invalidates approvals, and evaluates thresholds. Existing source uniqueness is already in the fee migration. |
| F4 | `PaymentSettlementService::resolveRepairTotalPaidAmount` (`:835–860`) can derive customer-facing paid totals from POS ledger/status even when raw repair totals are low. Fee eligibility uses raw totals. This is a second identifiable disagreement, requiring affected production records to establish whether it caused QA #14. Observer does not watch final_total/origin/billing changes. |
| F5 | `PlatformFeeController::payload` (`:255–312`) scopes charges/payment requests/reliability by resolved shop and limits **detail** charges to 100. `PlatformBalanceService::summary` (`:19–98`) independently sums all same-shop, marketplace, non-void charges; adds adjustments; subtracts paid allocations and applied credits. Totals are not sums of the displayed page. `FinanceShopContext::id` trusts ERP context or the appropriate guard, not request shop input. |
| F6 | `PlatformBalance.tsx:402,478` displays source type plus global primary key. `PlatformFeeController.php:273–287` omits metadata containing the existing order_number/request_id snapshots (`PlatformFeeLedgerService.php:35,55`). This explains references without proving a tenant leak. |
| F7 | Additional identity risk: `FinanceShopContext.php::id` fallback uses `$actor->getKey()` when a **User** carries Shop Owner role. Owner proxies carry `shop_owner_id`, and users.id is not shops.id. Normal ownerIndex uses the dedicated owner guard; no deployed cross-shop example was available. Test this fallback explicitly before asserting all identity variants are tenant-safe. |
| I1 | `ReadPageController::financeInvoices/renderOwnerFinanceSection` (`:114,:621–625`) loads shared ERP Finance in owner mode. `Invoice.tsx:271–272` only gates invoice creation. Table `:1176–1228` and modal `:1450–1468` still render send, payment, archive, restore based on status, without owner-mode guards. Finance mutations use `/api/finance/invoices`, `auth:user`, `shop.isolation`, `permission:access-finance-invoices` (`routes/finance-api.php:163–175`). Owner read routes are separate (`routes/shop-owner-api.php:98`). |
| T1 | Owner listing route exists (`routes/shop-owner-erp-api.php:31–32`); `ManagerController::getReports` (`:128–133`) uses owner ERP context. No owner download route exists there. `downloadReport` (`:259–267`) always calls `managerReportActor`, which requires user guard/capability (`:108–123`). `Reports.tsx:324` returns immediately in owner mode; owner row actions are hidden (`:520`). |
| T2 | `ManagerReportService::reportForDownload` (`:317–330`) already scopes `shop_owner_id`, reconstructs missing files from stored report_data, and records downloaded_at. `buildSalesData` (`:419–432`) stores structured order_items. `buildCsvContent` (`:629–636`) uses raw field keys and `json_encode` for non-scalars; `:620` also JSON-encodes nested summary values. Reports use `generated`/`reviewed`, with legacy `sent` presented as reviewed (`:356–359`); there is no report `completed` enum. Stored report snapshots are otherwise useful and should remain structured. |

## Required audit table

“Confirmed” below means a source defect or public-asset fact is established. “Unresolved” means the deployed occurrence needs runtime/source-record attribution, not that QA is invalid. Affected-file references expand to exact paths in the catalog and implementation entries.

| QA # | Observed symptom | Expected behavior | Actual code path | Confirmed root cause / evidence limit | Affected files | Data/model impact | Severity | Shared workstream | Regression risk |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Repair temporarily sees Finance/Staff notifications | Only current recipient | N1 → N2 → N3/N4 | Identity-free persistent frontend cache confirmed; wrong production recipient storage unproven | N1–N4 | notifications/user identities; no row rewrite | P0 | A | Cross-account requests, unread counts, owner/admin namespaces |
| 2 | Staff/CRM temporarily see Rider/HR notifications | Role/context appropriate addressed recipients | Same as #1 | Same cache boundary; event recipient correctness needs exact event/recipient comparison | N1–N4 | notifications/preferences | P0 | A | Legitimate multi-role recipients must remain visible |
| 3 | Owner appears in Staff & Workload | Real workload employees only | W2 → W1 | Owner proxy seeded STAFF, role-only workload query; local named QA owner not present | W1/W2 | users, roles/pivots, employees, shop_owners | P1 | B | Existing actor foreign keys and inactive workload history |
| 4 | New repair can select riders with Logistics OFF | Hide/reject new shop-owned transport | L1 → L2 → L4 | Quote/creation boundary lacks module check | L1/L2/L4/L5 | module states, delivery plans/fees, shipments/legs | P1 | C | Existing paid/started shipment continuation |
| 5 | Planned materials differ from configured package/services | Stable booking plan, aggregate quantities | M1 → M2 → M3 | Lazy live resolution and overwrite; package service fallback can omit included-service contributions | M1–M3 | templates/plans/service snapshots; no planning consumption | P1 | D | Historic actual usage, variance gates, empty snapshots |
| 6 | PayMongo callback parameters remain | Consume verified return then canonical URL | P1 → P2 → P3 | Cleanup exists in source AND deployed asset; precise lifecycle failure unresolved | P1–P3 | payment sessions; browser history/session markers | P2 | E | Lost pending verification, retry/idempotency, login return |
| 7 | Marketplace walk-in cannot modify | Transport-independent pre-lock editing | R1 | Walk-in acceptance selects pending, edit guards only accept repairer_accepted | R1/M1 | repair status/services/pricing/conversations | P1 | F/D | Paid/POS/received/in-progress repairs must stay locked |
| 8 | Unfinished marketplace walk-in can request refund | Completed-service refund eligibility; preserve exceptional compensations | R2/F3 | Backend missing workflow guard confirmed; current visible UI requires picked_up, so exact deployed UI/status cause unresolved | R2/R5/F3 | repairs, POS payment/refund records/legs | P1 | F | Preserve rejection/cancellation/delivery compensation refunds |
| 9 | Active refund plus warranty | Mutually exclusive active resolutions | R2 → R3/R5 | Warranty never queries refunds; reciprocal refund check is outside a shared repair lock | R2–R5 | refunds, claims, warranty jobs | P0 | F | Concurrent requests, approved rework, completed warranty rules |
| 10 | Refunded repair still offers warranty | Final full refund blocks claim | R4 → R3 | Refund changes payment state, not picked_up; warranty validator ignores payment/refund state; render trusts it | R3–R5 | repair payment state/refund totals/warranty | P0 | F | Partial and logistics-only refunds must be distinguished |
| 11 | Walk-in-only warranty offers transport | Original both-walk-in constraints preserved | L3 | No original-method restriction; reusable address fallback is mistaken for eligibility | L3 | original/claim/rework transport snapshots | P1 | C/F | Mixed-leg originals and legacy null methods |
| 12 | Warranty riders offered with Logistics OFF | Original constraints + module + address + coverage | L3 → L2 → L4 | Registration/address/coverage checks omit module state | L2–L5 | claims/rework fees/shipments | P1 | C | Toggle between claim submission and approval |
| 13 | Platform references look global | Own-shop data with canonical references | F5/F6 | Raw global ID display confirmed; normal scoped queries show no cross-shop proof; fallback identity risk F7 remains | F5–F7 | charge metadata; tenant context | P3 display; P1 fallback risk | H/B | Batch source lookups must remain scoped; never redesign IDs |
| 14 | Repair fee absent | Marketplace retail/repair fees; POS excluded | F1 → F2/F3/F4 → F5 | Status spelling/late-terminal eligibility mismatch confirmed by probe; QA's exact failing condition unresolved without records | F1–F5 | repairs, fee charges, credits, thresholds | P1; P0 if source-supported misstatement confirmed | G | Overcharging deposits, delivery/VAT basis, history/retries |
| 15 | Expected two ₱300 fees, outstanding ₱300 | Valid unpaid uncredited pair totals ₱600 | F3 → F5 | No SUM defect established; QA charge pair unavailable; missing source/credits/payment must be checked first | F2–F5 | charges/adjustments/allocations/credits | P1 pending source proof | G/H | Legitimate reductions must not be erased |
| 16 | Owner sees non-working invoice controls | Owner invoice reads only | I1 | ownerMode gate covers creation, not remaining mutation controls | I1 | invoices/payments; no new owner grants | P2 | I | Finance staff actions and read/export affordances |
| 17 | Owner cannot download completed shop reports | Same-shop completed downloads only | T1 → T2 | Missing owner route/controller branch + explicit frontend no-op | T1/T2 | manager_reports/private CSV files/download timestamp | P2 | J | No generation/review grant or cross-shop file access |
| 18 | Sales CSV order_items is raw JSON | Readable items and headers | T2 | buildCsvContent JSON-encodes arrays, exports raw field keys | T2 | structured report_data and generated CSV | P2 | K | Quoting, UTF-8, old report schemas, formula injection |

## Direct answers to QA's eighteen questions

1. **Wrong stored recipient or stale cache?** The shared cache key defect is confirmed. Both active header and common dropdown consume it. Backend recipient queries remain user-scoped. Wrong production recipient rows cannot be confirmed without the exact notification IDs/API response. Do not rewrite recipients based on this symptom alone.
2. **Why does refresh fix it?** A full reload constructs a fresh in-memory QueryClient; polling replaces stale cache with the current session's scoped response. No persistent notification-cache store was found. This explains transient correction; it does not prove every observed refresh was a full reload.
3. **Why Owner in workload?** The canonical owner proxy resolver deliberately seeds STAFF and adds Shop Owner role. The workload OR accepts STAFF without excluding owner identity or requiring an employee. `isEmployeeAccount()` is too broad to solve workload eligibility by itself. Never compare users.id to shop_owners.id.
4. **Which repair/warranty paths bypass Logistics?** Booking quote/store, customer delivery-plan changes using the same quote, warranty submission/approval quotes, and new shipment creation via SourceShipmentService/ShipmentRequestService. Retail/refund-return source creation shares the same missing final boundary. Customer-facing routes must remain usable for walk-in/third-party. Replacement creation must be inventoried through the shared shipment boundary rather than assumed safe.
5. **Where are templates resolved?** In private RepairWorkflowController methods `buildTemplateMaterialPlan`/`ensureRepairMaterialPlanItems`, when material usage/readiness is requested. Included service snapshots are names/prices/IDs, not material quantities. Plans already aggregate duplicate inventory IDs but are overwritten from current templates; adding another SUM alone would not fix the snapshot defect.
6. **Why does cleanup fail?** Precise deployed runtime cause is unresolved. It executes on mount, after sessionStorage reads/writes, before verification; it resets history state to `{}` and does not update Inertia's page URL. A blocked storage operation, mount/history timing, or later history restoration are testable possibilities. All five parameters are deleted in the served asset. Do not claim missing cleanup, skipped branch, or stale deployment as proven.
7. **Why walk-in affects editability?** Intake controls the accepted workflow status, and edit guards key off one status. It is an indirect transport lock, not evidence of explicit POS classification. Expand legitimate accepted/unpaid eligibility consistently; keep canonical payment/progress locks.
8. **Why unfinished refund eligibility?** Customer refund API lacks a service-completion guard and accepts a paid source. It does not explicitly grant a refund because intake is walk-in. Current frontend only renders Refund for picked_up. Deployed repair JSON/workflow transitions and the exact button path must resolve the visual discrepancy; forged unfinished API requests are a separately confirmed gap.
9. **Why coexistence?** Warranty validates prior warranties only. Refund validates active warranties, but before transaction/locking, and lower-level refund creation has no common cross-workflow lock. Warranty-after-refund and concurrent initiation are insufficiently guarded.
10. **Why warranty after refund?** Payment state becomes refunded while workflow remains picked_up; warranty's eligibility does not inspect that payment state or refund rows. The frontend block-reason function knows about refunds but does not control the rendered warranty action. Fix server eligibility first and serialize that result.
11. **How preserve original transport?** Treat original explicit intake/return methods and their saved plan as transport evidence. Both walk_in means warranty both walk_in. Use legacy delivery_method fallback only when explicit methods are absent; do not derive restrictions from manual POS/marketplace origin. Current/saved address availability must never broaden these constraints.
12. **Why warranty riders with OFF?** The form's gating is individual-vs-company/address only; the service's gating is individual shop plus coverage only. Neither includes current persisted Logistics access.
13. **Actual fee leak or IDs?** Global ID display is established. No cross-shop record exposure was demonstrated on production. Dedicated owner and normal Finance paths are scoped. Separately, F7 identifies a risky owner-proxy fallback using the wrong ID namespace; it needs a discriminating boundary test and deployed route evidence, not a blanket safe/leak assertion.
14. **Exact failing repair fee condition?** A read-only invocation of the actual private eligibility method, with marketplace, completed payment, base=paid=6000, yielded: completed=true, ready-for-pickup=true, ready_for_pickup=false, picked_up=false, received=false. Thus the canonical ready alias/late receipt states fail the status gate. This may be masked if a fully paid completed transition already charged successfully; it does not establish which condition failed for QA's repair. Inspect raw paid totals, origin, base, effective_from, existing charge, and actual observer-triggering transition before repairing historical records.
15. **Are two charge rows present?** The reported ₱300 pair was not inspected. Local DB has two unrelated shop-3 retail charges of ₱1,250, source IDs 2 and 3, not Orders 8/9 or repair sources. Its summary is zero after existing payment/credit effects. This demonstrates why row amounts alone do not imply outstanding; it is not a reproduction of QA #15.
16. **Why Owner controls?** Only canCreateInvoice includes !ownerMode. Send/payment/archive/restore controls reuse Finance rendering and status conditions. Owner guard requests cannot be made operational by granting Finance capabilities.
17. **Why list but no download?** Listing has an owner context branch and approved route; download has neither, and the component explicitly returns for ownerMode. Reuse reportForDownload's tenant scope through a dedicated owner download route.
18. **Where JSON serialization?** `ManagerReportService::buildCsvContent`, line 634, encodes order_items arrays created by buildSalesData, line 427. Use export presentation formatting, preserve report_data as structured snapshots, and handle persisted historical files explicitly.

## Reproduction, route, guard, and side-effect matrix

These are the paths to reproduce each deployed symptom during authorized QA, not claims that production mutations were performed. Together with the audit table, source catalog, and each linked A–K workstream's fix/tests/manual QA, this supplies the fourteen requested fields for every issue.

All web/API employee mutations also pass the global suspension/security/attendance middleware in `bootstrap/app.php:143–159`. Operational ERP routes receive audience, actor context, authentication and module middleware from the route catalog loop (`:62–123`) where classified. `EnsureEmployeeClockedIn` enforces mutation attendance; HTTP 423 is not permission to disable it. Customer ownership, source scope, and workflow eligibility still require controller/service checks independently of these middleware.

| QA | Reproduction / frontend | API and controller / guards | Models/tables and side effects | Fix/test/manual workstream |
| --- | --- | --- | --- | --- |
| 1 | Load Finance notifications, switch to Repair in same SPA/browser, inspect first paint and reload | AppHeader → GET `/api/notifications` and `/unread-count`; `routes/api.php:427–444`, web + auth:user → general NotificationController; ERP alternative `routes/hr-api.php:373–385` → ErpNotificationController | User, Notification (`users`, `notifications`), addressed creation through NotificationService/RecipientResolver; stale display/counts, mark-read targeting | A: identity/in-flight/cache tests; Finance→Repair manual sequence |
| 2 | Repeat HR→CRM and Rider→unrelated Staff; compare payload recipient with cached row | Same routes/guards as #1; preferences use Api NotificationController; polling reuses keys | Same rows plus preferences; legitimate explicit multi-role recipients retained, no broadcast rewrite | A: event-recipient matrix and HR/CRM/Rider/Staff manual sequence |
| 3 | Open Manager Staff & Workload with owner proxy seeded by ensure | GET `/api/manager/staff-workload`, `routes/web.php:2955–2970`, web/auth:user/check.suspension + manager.capability:staff-workload-read → ManagerController::getStaffWorkload | User/ShopOwner/Employee and role pivots (`users`, `shop_owners`, `employees`); query counts only, owner actor creation reused in payslip approvals | B: owner proxy versus active/inactive employee fixtures; workload/payslip manual check |
| 4 | Disable Logistics, book repair with a valid covered address and select rider; repeat delivery-plan change | GET quote `routes/web.php:1537` auth:user → RepairAvailabilityController; POST `/api/repair-requests` (`:1391–1394`) auth:user → store; PATCH customer `{id}/delivery-method` (`:1465`) → changeDeliveryMethod; address/customer checks and source tenant locks | RepairRequest/UserAddress/ShopOwner, module state, delivery plans/payment sessions, LogisticsShipment/legs; quote, fees, shipment creation and downstream rider notifications | C: OFF quote/store/change/backend forgery; ON/OFF and continuation manual QA |
| 5 | Configure package + services using same material; book then inspect Planned From Templates; change template and reopen | POST booking → store; GET `/api/repairer/repairs/{id}/materials`, `routes/web.php:1539–1579`, auth:user/check.user.business.type + permission:access-repair-stocks → getRepairMaterialUsage; owner equivalents in shop-owner-api | RepairPackage/RepairService/RepairRequest, template pivots, RepairMaterialPlanItem/RepairMaterialUsage, inventory; reads initialize/overwrite plan, usage alone consumes stock | D: snapshot/aggregation/stock invariants; historical/new job manual comparison |
| 6 | Return from sandbox PayMongo checkout to myRepairs with all five parameters; refresh/back/forward | `/my-repairs`, `routes/web.php:238–252`; POST customer `{id}/verify-payment` auth:user/throttle or signed `/api/customer/repairs/{id}/verify-payment-return` (`:1403–1404`), throttle → verifyPayment; ownership or HMAC/time + provider verification | RepairRequest/RepairPaymentSession and verified payment/transaction records; settlement, observer fee creation, invoices/notifications; history/session markers | E: runtime trace before patch, signed/provider/idempotency tests, canonical-return manual QA |
| 7 | Marketplace walk-in acceptance, open conversation while unpaid, attempt Modify | POST `/api/repairer/repairs/{id}/accept` → acceptRepair; PATCH `/api/customer/repairs/{id}/services` (`routes/web.php:1415`) auth:user → updateServices; assigned/shop/customer/chat/payment checks | RepairRequest/service pivots/conversation and pricing snapshots; pending status blocks edit; edits reprice and notify, must refresh materials deliberately | F + D: actual acceptance→edit regression, paid/progress locks; unpaid walk-in manual edit |
| 8 | Inspect unfinished paid marketplace repair's deployed status/action; attempt customer refund with proof | POST `/api/customer/repairs/{id}/refunds` (`routes/web.php:1446–1448`) auth:user → requestRefundFromMyRepair; ownership/warranty/review/evidence/source/balance checks → RepairPosRefundService | RepairRequest, PosTransaction/PosRefund and split legs (`repair_requests`, `pos_transactions`, `pos_refunds`); source backfill where valid, approval queue/notifications; UI discrepancy remains unresolved | F: unfinished API rejection and exact rendered action fixture; preserve exception refunds |
| 9 | Request refund, then warranty; reverse order and submit concurrently | Customer refund route above + POST `{id}/warranty-claims` (`routes/web.php:1451–1452`) auth:user → RepairWarrantyClaimController::store → createCustomerClaim; claim ownership/evidence validation; repairer/owner approval routes `:1478–1490`, business-type/tenant/actor checks | PosRefund/RepairWarrantyClaim, original and rework RepairRequest (`repair_warranty_claims`); competing resolutions, evidence, notifications, approval-created job/logistics | F: common-lock bidirectional/concurrent exclusion, manual two-tab sequence |
| 10 | Execute full refund on handed-over job, reopen Warranty action | Same claim route and validator; refund execution `/api/finance/repair-refunds/{refund}/execute` (`routes/web.php:1492–1497`) auth:user + permission:access-refund-approval → workflow controller/service | Refund success updates repair payment totals/status; workflow picked_up survives; fee refund-credit side effects and claim eligibility must agree | F: full/partial/delivery-only cases; full refund→claim API/UI denial |
| 11 | Original intake walk_in + return walk_in, reopen warranty transport with another saved address | Claim store/approve paths above → warrantyDeliveryPlan/warrantyDeliveryLeg; customer address ownership; no original-method restriction currently | Original transport fields/snapshots, claim preferred methods, approved rework delivery plan/legs | C + F: original both-walk-in fixture; manual walk-in/mixed-leg comparison |
| 12 | Logistics OFF, covered original address, choose warranty rider; toggle before approval | Same claim/approval routes → ensureWarrantyLogisticsAllowed → quote → SourceShipmentService/ShipmentRequestService; current company/address/coverage check lacks module decision | Module access, claim/rework plans, fees/payment sessions, shipments/legs/rider notifications | C: submit/approve OFF and toggle tests; forged rider submission manual QA |
| 13 | Compare Finance Shop A/B and dedicated owners; inspect raw Order #ID reference | GET `/api/finance/platform-balance`, `routes/finance-api.php:66–68`, web/auth:user/permission:access-finance-dashboard/shop.isolation → index; owner GET `/api/shop-owner/finance/platform-balance`, shop-owner-api `:44,:81–82`, auth:shop_owner/shop.isolation → ownerIndex; FinanceShopContext/ERP context | PlatformFeeCharge/credits/payments/requests and source Order/RepairRequest; shop-scoped payload/summary, raw key presentation; separate owner-proxy namespace risk | H: discriminating tenant/source/reference tests; A/B/owner manual comparison |
| 14 | Complete and fully pay marketplace repair, inspect fee before/after ready and receipt | Existing repair payment/workflow routes → persisted RepairRequest → registered observer → finalizeRepair; Finance read route as #13; provider/source eligibility rather than URL flag | RepairRequest/PlatformFeeCharge/config snapshot, refunds/credits; unique source, VAT/fee basis, approval invalidation and thresholds | G: real status/payment transitions and retries; no historical replay without evidence |
| 15 | Identify reported two sources, inspect charge rows then payments/credits and displayed outstanding | Same Finance/owner read routes and context → PlatformFeeController::payload → independent PlatformBalanceService::summary, not detail pagination | Charges/adjustments/payment allocations/applied credits; local sample is unrelated; controller reads may reconcile, so diagnostic probes use read-only service/DB paths | G + H: two real ₱300 rows with/without reductions; production pair comparison |
| 16 | Owner Finance Invoice table/modal for draft/sent/paid/archived rows; compare Finance | Owner GET `/api/shop-owner/finance/invoices` (`shop-owner-api.php:98`) owner guard/isolation + erp.audience/erp.actor; mutations `/api/finance/invoices` (`finance-api.php:163–175`) auth:user/isolation/permission:access-finance-invoices → InvoiceController | Invoice/payment/history; unauthorized owner controls render despite server boundary; no new invoice mutation or permission grant | I: all row/modal capabilities, direct mutation denial; owner read/Finance operation manual QA |
| 17 | Manager generates report; Owner lists it then attempts download | Manager `/api/manager/reports/{id}/download`, `web.php:2998–2999`, auth:user + manager.capability:reports-read → downloadReport; owner listing `/api/shop-owner/erp/manager/reports`, catalog-supplied owner audience/actor/module → getReports; owner download missing | ManagerReport (`manager_reports`), private CSV storage, report_data, downloaded_at; missing files reconstructed from snapshot | J: same/wrong-shop owner GET and no generation/review grant; generated/reviewed/legacy sent eligibility |
| 18 | Generate sales report with one/multiple products, download CSV; repeat existing file | Manager generation/download routes `web.php:2988–2999`, reports-generate/read capability, attendance for mutation → ManagerController → ManagerReportService::storeFile/buildCsvContent | ManagerReport/report_data and Order/order-items snapshots; JSON serialization only at export, existing file served unchanged | K: readable/header/CSV/formula/old artifact tests; current/historical spreadsheet manual inspection |

No additional event is required for the read-only workload, owner invoice visibility, or CSV presentation fixes. Existing mutation notification/observer/payment hooks remain in place; any after-commit adjustment is limited to the affected resolution transaction.

## Shared state and authorization contract

Use actual repository statuses rather than inventing a replacement enum:

| Action | Required domain eligibility | Independent transport/authorization restrictions |
| --- | --- | --- |
| Edit services | Accepted negotiation stage, conversation where required, no payment/usage/progress/pricing lock | Marketplace/POS origin explicitly distinguished; walk-in itself never a generic lock; same customer/shop |
| Customer service refund | Completed service/receipt and refundable recorded payment; no active competing warranty | Existing review rule; original payment verification/split limits; distinct cancellation/rejection/delivery-compensation paths retain their legitimate exceptions |
| Warranty | Original issued, unexpired warranty after legitimate handover; no active refund, no final full refund, no cancelled/void/ineligible terminal state, existing claim guard | Same customer/shop, original transport constraints, current module/address/coverage; approved-once semantics retained |
| New shop-owned movement | Current accessible Logistics module and eligible company/workflow | Tenant, actor capabilities, address/pin/coverage, source readiness, price/payment lock |
| Existing-started continuation | Existing persisted movement satisfying the approved continuation rule | No new movement disguised as replay; tenant/RBAC/proof/attendance/source rules still apply |

`received` means physical intake in the current repair workflow. It is not interchangeable with final customer receipt. `picked_up` is final customer receipt. `ready_for_pickup` and legacy `ready-for-pickup` must be interpreted consistently. Refund status belongs to refund/payment models and may overlay an unchanged repair workflow status.

## Implementation workstreams A–K

Each task below is **proposed**. Tests may be created/updated only after approval. Execute one scope at a time: write discriminating regression → observe failure → minimal change → rerun narrow suite → sequential review. No automatic commit, push, deploy, or production financial reconciliation is authorized by this plan.

### A. Notification identity/cache isolation — QA #1–2

**Files/methods:** `resources/js/hooks/useNotifications.ts` (all query/mutation/preference hooks), `resources/js/providers/QueryProvider.tsx`, `resources/js/layout/AppHeader.tsx`, `resources/js/components/header/NotificationCenter.tsx`, common dropdown/bell consumers, and notification page consumers. Reuse `HandleInertiaRequests` auth/erpActor props; add no second notification store.

**Layer:** primarily frontend; backend only if recipient/endpoint evidence establishes a defect. **Migration:** none.

- [ ] Add identity-transition regression using one persistent QueryClient, Finance → logout → Repair, including a deferred old request completing after the switch. Assert every rendered frame and unread badge, not just final state.
- [ ] Define one notification identity tuple: actual guard/principal ID, tenant ID, recipient context. Unknown/unauthenticated principal disables reads and renders empty. Same numeric IDs in user/owner/admin guards cannot share state.
- [ ] Scope lists/recent/unread/stats/preferences and mutation invalidation/optimistic updates to the tuple. Forward AbortSignal. Never use previous principal's data as placeholder.
- [ ] Cancel/remove departing notification and preference entries at auth changes; synchronously prevent rendering old identity data before any cleanup effect. Reset local dropdown/filter/action state. Make provider lifetime identity-aware where necessary; do not indiscriminately clear unrelated public caches.
- [ ] Route the ERP header through its canonical user-and-shop notification endpoint. Keep customer/owner/admin guards and endpoints separate; preserve response-shape normalization.
- [ ] Compare deployed wrong IDs with authenticated API response, DB user_id/shop_id, event payload, and recipient resolver. Only correct proven event fan-out errors; preserve all historical addressed notification records.

**Automated:** extend `components/header/__tests__/NotificationCenter.test.tsx`, `components/common/__tests__/NotificationDropdown.test.tsx`; create `hooks/__tests__/useNotifications.identity.test.tsx`. Extend `tests/Feature/Notifications/ErpNotificationIndexScopeTest.php`, `NotificationRecipientMatrixTest.php`, `NotificationRouteContractTest.php`. Finance→Repair, HR→CRM, Rider→unrelated Staff, same-role different users/shops, same IDs across guards, unread/preferences, old in-flight request, SPA navigation, full reload, absent auth, and explicit legitimate recipients.

**Manual:** in one browser, load Finance notifications then sign out/sign in Repair; repeat HR/CRM and Rider/Staff; inspect first paint, 15-second poll, counts, mark-read, pages, and refresh. Keep DB/API evidence private; record only IDs/roles and redacted event types in project notes.

**Compatibility/rollback:** no DB notification rewrite. Rollback reopens exposure, so identity protection must be atomic across hooks/provider/consumers. Existing mutation authorization remains server enforced. Benefit criterion: zero wrong-recipient frames, independent of refresh.

### B. Owner-versus-employee workload classification — QA #3

**Files/methods:** `app/Services/ShopOwnerActorUserResolver.php::ensure/resolve`, `app/Http/Controllers/Api/ManagerController.php::getStaffWorkload`, `app/Models/User.php` only if a narrowly reusable owner/employee classification is necessary; reuse `ManagerAssignmentEligibilityService` and `HR/EmployeeOperationalPolicy`. Review `User::employee` linkage with same-shop constraint. Frontend `StaffWorkload.tsx` consumes corrected eligible rows, no hiding workaround.

**Layer:** backend; frontend regression only. **Migration:** none planned; use existing owner role/linkage rather than a new parallel identity table.

- [ ] Reproduce a new proxy from ensure(), with legacy STAFF + Shop Owner role, no employee; verify exclusion while genuine Staff/Repairer is included.
- [ ] New owner proxies receive canonical owner identity rather than STAFF fallback. Exclude recognized owner proxies from workload even when legacy STAFF/Repairer remains. Require the documented real-employee identity/tenant relationship for workload, preserving existing explicit inactive/terminated filters and history views.
- [ ] Check existing accounts by owner role/link/email fallback and legitimate Employee relationship. Propose a dry-run normalization list; do not bulk alter roles/emails/customer linkage or delete users. Preserve foreign keys, actor history, notifications, payslip approvals, and suspension semantics.
- [ ] Do not globally change isEmployeeAccount without inventorying attendance/TOTP/auth callers; workload is narrower than login classification. Do not exclude user.id == shop_owner_id.

**Automated:** extend `tests/Feature/Manager/ManagerStaffWorkloadTest.php`; add `tests/Feature/ShopOwner/ShopOwnerActorUserClassificationTest.php`. Cover legacy-column/Spatie combinations, owner with historical work, active employees, inactive/terminated/offboarded filters, customers/admins, cross-shop email linkage, owner actor resolution in payslip flow.

**Manual:** named QA owner disappears from manager workload; existing employees/counts/filters still work; owner can still perform already authorized governance actions. **Risk/rollback:** identity corrections can affect RBAC and attendance; prefer bounded query/proxy creation changes and audited existing-record normalization. Do not delete history to make counts match.

### C. Central Logistics eligibility — QA #4, #11, #12

**Files/methods:** `ShopModuleAccessService::decide/canAccess` (reuse as module authority); `RepairDeliveryService::quote/paymentDetails` and new-plan mutation callers; `RepairWarrantyService::ensureWarrantyLogisticsAllowed/warrantyDeliveryLeg/approveClaim`; `Logistics/ShipmentRequestService::requestShipment`; `Logistics/SourceShipmentService::ensure*`; `Logistics/LogisticsActorPolicy` continuation checks; `RepairRequestController::store/changeDeliveryMethod`, `RepairWorkflowController::changeDeliveryMethod`, and `RepairAvailabilityController::deliveryQuote`; `RepairProcess.tsx`, `myRepairs.tsx` warranty/return forms. Audit retail/refund/replacement callers reaching this boundary. Changes to `config/shop_modules.php` only for newly added routes, not broad customer gating.

**Layer:** both. **Migration:** none required for both-walk-in enforcement; original explicit methods and saved snapshots already exist.

- [ ] Add module-OFF tests at quote, booking, plan update, warranty submit/approve, and final creation boundary, with enabled coverage/address and forged payloads.
- [ ] Use the existing module service, respecting configured enforcement policy, company/workflow eligibility and coverage. Centralize the small operation-aware logistics decision once, preferably within existing services. Keep geographical coverage reusable; do not equate it with permission.
- [ ] Revalidate **inside the shop/source transaction**, before creating a new internal movement. Cover retail, repair intake/return, warranty intake/return, refund return, and replacement; identify internal versus independently supported third-party legs explicitly. Do not disable third-party because it shares a creation API.
- [ ] Preserve idempotent lookup of existing movements and the approved started-shipment continuation rule. Add action-specific continuation handling to LogisticsActorPolicy where OFF currently denies everything. No global bypass; no arbitrary new legs/attempts under a continuation exception.
- [ ] Warranty allowed methods = original constraints ∩ current shop eligibility ∩ address/pin/coverage ∩ workflow rules. Both original methods walk_in yields only walk_in in both warranty legs, even if other saved addresses exist. Check again at approval so a module toggle cannot create a new rider job from an older claim.
- [ ] Serialize server availability/reasons into existing quote/repair/warranty payloads. Hide unavailable new rider choices, correct a stale selected option, and keep backend validation authoritative.

**Automated:** extend `tests/Feature/Repair/RepairDeliveryQuoteTest.php`, `tests/Feature/Repair/RepairLogisticsIntakeTest.php`, `tests/Feature/Repair/RepairLogisticsReturnTest.php`, `tests/Feature/Repair/Warranty/RepairWarrantyClaimFlowTest.php`, `tests/Feature/Repair/Warranty/RepairWarrantyLogisticsRecoveryTest.php`; `tests/Feature/BusinessScaling/ShopModuleToggleTest.php`; frontend `repairProcessLogistics.test.tsx`, `myRepairs.logistics.test.tsx`. Add parameterized source-creation coverage for all seven workflow categories and module toggle/race/replay. Attendance fixtures must clock in real mutation actors.

**Manual:** ON/OFF with identical in-coverage pinned address; submit forged rider request; warranty both-walk-in versus mixed original; toggle after claim submission before approval; continue the same already-started shipment under the approved rule; verify pending/new attempts remain denied.

**Compatibility/rollback:** preserve completed/shipped legs, payment snapshots and third-party tracking. Do not silently downgrade paid rider plans to walk-in. Existing not-yet-started paid plans need explicit cancellation/reconciliation, not silent fee loss. Confirm the precise “started” boundary from the accepted business rule before implementation; source currently uses both shipment and leg states and does not encode a complete exception here.

### D. Repair material template snapshot/planning — QA #5 (and service-edit consistency)

**Files/methods:** expand existing `RepairMaterialPlanningService` with snapshot/plan operations; move current private template/aggregation logic out of `RepairWorkflowController::buildTemplateMaterialPlan/ensureRepairMaterialPlanItems` without introducing a competing planner. Modify `RepairRequestController::store/updateServices`, `RepairPosController::createManualRepairRequestFromPos` (`:145`, creation `:271`), `RepairWarrantyService::approveClaim` rework creation (`:370`), `RepairRequest` casts/fillable, and existing usage/readiness callers.

**Layer:** backend, frontend payload/type only if snapshot provenance is shown. **Migration:** one proposed additive nullable JSON `material_plan_snapshot` on `repair_requests` (version/source IDs/quantities/flags, including an explicit empty items array). No migration is created in this phase. Existing unique plan key is retained.

- [ ] Failing regression: service A Glue×1 + service B Glue×2 → one Glue×3 plan, package direct material + included-service/add-on material, including partial/missing relationships. Readiness/usage GET must not change intended plans after template edits.
- [ ] Snapshot templates transactionally when a job is created after selected services are known. Treat package-level and unique included/add-on services as additive sources, deduplicating the same service contribution reached through both pivot and package expansion. Aggregate inventory ID once; preserve critical/tolerance policy.
- [ ] Store snapshot before exposing the job; instantiate plan rows with actual quantity zero. Planning touches no inventory quantity/stock movement. Log Usage remains the only consumption path.
- [ ] Approved pre-lock service/package modifications update the snapshot and planned rows deliberately, in the same transaction as repricing. Remove only now-orphaned zero-usage plan rows; reject edits that would erase actual usage/history. Do not resnapshot on read/start/completion.
- [ ] For existing jobs, preserve material plan/actual usage. Mark legacy provenance; a present empty snapshot means intentionally no templates. Missing historical booking quantities cannot be reconstructed with certainty from current template IDs. Do not label live-derived legacy initialization as original booking evidence or rewrite completed jobs.

**Automated:** extend `tests/Feature/Repairer/RepairMaterialTemplateApiTest.php`, `RepairMaterialPlanningGateTest.php`, `RepairMaterialCompletionVarianceTest.php`, `tests/Feature/ShopOwner/RepairMaterialUsageApiTest.php`, `tests/Feature/RepairPackage/RepairPackageApiTest.php`, `Customer/RepairServiceModificationTest.php`; add `tests/Feature/Repair/RepairMaterialSnapshotTest.php`. Cover template edit/deletion, archived service/package, package removal, empty snapshot, concurrent plan reads, aggregation, critical/tolerance, plan creation no stock changes, usage stock decrement and reversal.

**Manual:** book configured glue/sand paper package; inspect planned quantities; edit templates later; historical job remains stable; new job uses new quantities; Log Usage alone reduces inventory. **Risk/rollback:** nullable additive rollout, populate only new/explicitly edited jobs. Keep snapshot column/data if rolling application back; older live-refresh code could overwrite plan rows, so do not revert that behavior over snapshot-backed jobs. One durable lesson may be logged after implementation; no one-off audit observations added to the existing learning log now.

### E. PayMongo return URL canonicalization — QA #6

**Files/methods:** first investigate `myRepairs.tsx::checkPaymentReturn`, deployed same asset, Inertia page/history lifecycle, `RepairRequestController::updatePaymentLink/verifyPayment`, and `/my-repairs` route. If canonical server callback is needed, add a narrowly scoped GET return handler reusing the existing signed return/provider verification and settlement logic. Do not add a second settlement service.

**Layer:** frontend lifecycle; backend only for a verified canonical redirect design. **Migration:** none.

- [ ] Before deciding implementation, reproduce the signed return in an authenticated deployed/staging browser with URL/history/page state and sessionStorage behavior recorded. Test same-page SPA arrival, full navigation, remount, back/forward, storage rejection, pendingSessionId ≠ queryId, and provider pending→verified.
- [ ] Prefer query's signed resource identity over unrelated stale session state. Keep pending verification distinct from “already handled”; a marker written before verification must not permanently suppress retry after failure/refresh.
- [ ] Use Inertia-compatible history replacement/navigation preserving required history/page state. Ensure canonicalization on arrival/update, not solely first mount, and preserve unrelated query parameters/hash intentionally.
- [ ] If the verified lifecycle still needs a server callback, validate customer/signed return, verify through the existing provider/settlement path, store only minimal pending continuation context for provider delays, then issue a clean canonical redirect. Never mark paid based on query flags. Failed/pending/unauthenticated cases must retain a safe verification/retry route rather than losing the callback.
- [ ] Do not ship a duplicate cleanup snippet just because the symptom names URL parameters. Selection of the exact change depends on the discriminating runtime trace above.

**Automated:** add `resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.payment-return.test.tsx`; extend `tests/Feature/Repair/RepairLogisticsPaymentTest.php` and payment verification coverage for provider fakes, invalid/expired/tampered signatures, cross-customer ID, idempotent settlement, pending propagation, remount and clean refresh. Add feature coverage for the callback route only if selected.

**Manual:** real approved QA payment flow in staging/sandbox: verified receipt → canonical `/my-repairs`; clean refresh/back/forward does not duplicate payment or alert; aborted/pending return can retry. **Risk/rollback:** preserve server verification and existing signatures; changing callback URLs must not strand already-issued checkout sessions. Runtime root cause is unresolved at plan time.

### F. Repair refund/warranty eligibility and mutual exclusion — QA #7–10

**Files/methods:** `RepairRequestController::updateServices/requestRefundFromMyRepair/myRepairs/show`, `RepairWorkflowController::acceptRepair`, `RepairWarrantyService::validateEligibility/createCustomerClaim/createPosWalkInClaim/approveClaim`, `RepairPosRefundService::requestRefund/createRefundWithSplitLegs/markRefundSucceeded`, `RepairRequest` eligibility projections, `myRepairs.tsx` action rendering/submission. Reuse existing refund and warranty services; a small common repair-resolution eligibility helper is justified only to prevent divergent checks.

**Layer:** both. **Migration:** none initially; lock the existing original repair row rather than add a new status table.

- [ ] Add real walk-in acceptance→unpaid MODIFY regression rather than synthesizing repairer_accepted fixtures. Permit legitimate negotiation stages consistently, including accepted walk-in pending with required conversation, no recorded payment, no actual usage/progress/finalization lock. Preserve paid/POS/in-progress restrictions.
- [ ] Add completion eligibility for **customer service-refund workflow**. Verify paid source, origin, workflow/receipt, existing review rules and remaining service refundable amount. Keep cancellation/rejection and delivery-reconciliation compensation as distinct legitimate exceptions. Do not gate every refund path globally on picked_up.
- [ ] Add refund-aware warranty validation: active requested/approved/processing refund, final full service refund/payment refunded, cancelled/void/ineligible terminal state. Retain issued-window, original-only, customer ownership and approved-once guards. Examine service-versus-delivery component breakdown before treating a logistics-only refund as loss of service warranty.
- [ ] Refund initiation and warranty initiation/approval acquire the **same original repair row lock**, in a consistent order, then requery fresh conflicting workflows inside the transaction. Validate source tenant/customer under that lock. A prior frontend/controller read is not concurrency enforcement. Notifications are sent only for committed successful changes using existing dispatch conventions.
- [ ] Recheck the original repair at warranty approval/execution and refund endorsement/final execution where competing state could have changed. Preserve idempotent legitimate executions. No retroactive deletion of approved claims/refunds; existing conflicts need an auditable remediation list.
- [ ] Serialize authoritative can_modify/can_refund/can_claim with reason codes, and use them for UI rendering and submit guards. Eliminate the current rendered warranty/backend versus local block-reason mismatch. Separate asynchronously fetched claim/refund detail display from authoritative eligibility. Fix origin/transport terminology and never derive POS from walk-in.

**Automated:** extend `tests/Feature/Customer/RepairServiceModificationTest.php`, `RepairOnlineRefundWorkflowTest.php`, `RepairRefundSourceBoundaryTest.php`, `RepairMixedRefundSplitSettlementTest.php`, `tests/Feature/Repair/Warranty/RepairWarrantyEligibilityTest.php`, `RepairWarrantyClaimFlowTest.php`, and frontend `myRepairs.service-modification.test.ts`, `myRepairs.refund-workflow.test.tsx`. Add concurrent refund↔warranty test using a database capable of row-lock semantics; SQLite alone does not prove contention. Cover requested/approved/processing/succeeded/rejected/failed refunds; pending/approved/rejected/completed rework; intake received versus final receipt; full/partial/logistics-only refunds; valid completed warranty; exception refunds; cross-shop/customer; transaction origin independent of transport; old record aliases.

**Manual:** unpaid marketplace walk-in negotiation remains editable; unfinished service refund hidden/API rejected; completed valid refund works; refund first→warranty denied; warranty first→conflicting refund denied; simultaneous two-tab requests admit one workflow; after full refund no warranty; no-charge rework cannot recurse; cancellation/rejection/logistics compensation still works.

**Compatibility/rollback:** retain existing workflows/statuses/payment legs and completed transactions. Partial-refund warranty semantics require confirmation from current business rules; the task explicitly forbids final refunded warranty but does not define all partial cases. No generic cancellation/refund rewrite. State-consistency guards should deploy with projections/UI together; rollback must not reopen duplicate resolution.

### G. Existing repair Platform Fee finalization repair — QA #14

**Files/methods:** `PlatformFeeLedgerService::isEligibleRepair/finalizeRepair/originForRepair`, `RepairRequestPlatformFeeObserver::updated`, relevant completed/ready/receipt transition and `PaymentSettlementService` callers only if tests expose missing persisted state. Reuse `PlatformFeeSettingsResolver`, fee source uniqueness, `PlatformFeeRefundService`, payments/threshold hooks.

**Layer:** backend. **Migration:** none; existing fee architecture/schema already supports repairs.

- [ ] Capture redacted deployed failing repair fields: origin_channel, pricing mode/request prefix, billing mode, workflow status/history, raw paid/payment state, totals, POS/gateway session evidence, effective_from/settings, and existing same-source charge. Determine the exact failed guard; do not call provider settlement in a diagnostic read.
- [ ] Add completion/ready aliases and legitimate terminal receipt coverage to the existing eligibility definition, preserving genuinely unfinished/unpaid/deposit/non-marketplace/no-charge exclusions. Fee basis remains repair service amount excluding delivery; preserve fee VAT rounding/settings snapshots.
- [ ] Determine whether verified payment normalization must persist repaired raw totals or finalization should use the existing canonical collection authority. Never infer actual money solely from a displayed success/status. Demonstrate complete service payment with existing verified records and compare against the correct service base.
- [ ] Watch eligibility-relevant changes omitted by the observer (e.g. final_total/origin/billing) when needed by real write paths, or invoke the existing finalizer at a canonical transition. Avoid redundant triggers that conceal non-persisted state. Keep unique source protection and transactional side effects.
- [ ] Prepare a **dry-run** missing-charge report after fixing current flow. Exclude POS/no-charge/pre-effective/fully refunded/ineligible records; respect historical fee configuration. Existing completed transactions cannot silently acquire new fees under today's rates. Any actual production financial backfill is a separate reviewed operation.

**Automated:** extend `tests/Feature/PlatformFee/PlatformFeeLedgerTest.php` with real ready→final-payment→picked_up transitions, underscored/hyphenated aliases, already-terminal late payment, observer final_total changes, verified PayMongo/POS ledger combinations, marketplace walk-in, manual POS exclusion, no-charge warranty, deposit insufficiency, fee base/VAT/effective date, duplicate retries. Run `PlatformFeeRefundTest.php`, `PlatformFeeMoneyMovementTest.php`, `PlatformFeeThresholdTest.php` to protect credits/payment approvals/threshold side effects.

**Manual:** complete marketplace retail and repair in QA, inspect distinct charges and correct service base; true POS produces none; refresh/retry doesn't duplicate; refund credit path remains intact. **Risk/rollback:** acceptance of terminal aliases can expose old eligible missing fees; do not trigger indiscriminate historical replay. The exact QA guard is still pending deployed record evidence.

### H. Platform references, tenant boundaries, and aggregate verification — QA #13, #15

**Files/methods:** `PlatformFeeController::payload`, `PlatformBalance.tsx` charge/credit types and source display, `PlatformBalanceService::creditMovements` reference serialization if needed, `FinanceShopContext::id` fallback, existing ledger metadata. **Layer:** both. **Migration:** none.

- [ ] For QA's pair, inspect each source's qualification and exact charge row first; then compare shop/origin/status, adjustments, payment allocations, applied credits, and summary. A paid/applied-credit charge cannot be counted again as outstanding.
- [ ] Write two actual ₱300 charge fixture → ₱600 outstanding with no reductions; then deliberate payment/credit/void reductions. Include mixed order/repair and >100 detail rows to prove totals independent of the limited list.
- [ ] Test Finance A/B, dedicated owner A/B, user owner proxy with users.id deliberately different from shop_owners.id, and authorized admin boundary. Correct a demonstrated wrong-namespace fallback with model/guard/tenant linkage, retaining strict authorization. Never trust requested shop_id or grant owner mutation permissions.
- [ ] Expose existing metadata order_number/request_id as source_reference. For legacy missing metadata, use tenant-scoped batch source lookup; fallback visibly to stable source type/ID only when necessary. Apply the same reference treatment to credit movements without changing IDs or financial snapshots.
- [ ] Change aggregation only if two valid outstanding rows with no legitimate reductions reproduce a calculation error. Current independent decimal summation is retained otherwise.

**Automated:** extend `tests/Feature/PlatformFee/PlatformBalanceApiTest.php`, `PlatformFeeAdminBoundaryTest.php`, frontend `PlatformBalance.test.tsx`; add discriminating owner-proxy Finance context test. Verify totals, details, credits, payment requests, source lookups and manipulated shop IDs.

**Manual:** Shop A/B cross-check same browser fresh identity; canonical order/repair references; QA pair matches raw charge and money movement records; detail truncation does not alter totals. **Compatibility/rollback:** output field is additive; metadata/source lookups must be scoped and batched. No fee row/ID redesign. No confirmed SUM bug or production tenant leak at audit time.

### I. Owner Finance read-only UI — QA #16

**Files/methods:** `resources/js/Pages/ERP/Finance/Invoice.tsx` capability derivation, row/modal controls and handler guards; `Finance.tsx` section integration if needed. Backend `InvoiceController`/Finance routes reviewed and tested, not widened.

**Layer:** frontend fix + backend authorization regression coverage. **Migration:** none.

- [ ] Add owner render tests for draft/sent/paid/archived invoices, row and modal.
- [ ] Derive mutation affordance from !ownerMode and existing explicit Finance capabilities; apply it to send, record/reverse payment, archive/delete/restore/edit and any retained mutation callbacks. Keep view/details and existing authorized local read exports. Reuse capability helper; no new role matrix service.
- [ ] Ensure rejected owner mutation/API requests remain rejected. Remove no Finance permissions from real Finance staff to hide a UI problem.

**Automated:** extend `resources/js/Pages/ERP/Finance/__tests__/Invoice.owner-actions.test.tsx`, `Invoice.payments.test.tsx`, `tests/Feature/Finance/InvoicePaymentTest.php` and owner read-boundary tests. Assert no owner mutation call can be reached through direct handler affordances; Finance actions still work when clocked in.

**Manual:** owner view/details works, mutation controls absent in table/modal, Finance equivalent still operational, direct owner mutation denied. **Risk/rollback:** test every duplicate modal/row affordance; preserve invoice tenant scoping/history. No intentionally approved owner invoice mutation is introduced.

### J. Owner completed-report download authorization — QA #17

**Files/methods:** `routes/shop-owner-erp-api.php` new GET `/manager/reports/{id}/download`; `config/shop_modules.php` approved owner reports-read route entry; `ManagerController::downloadReport` owner context branch or dedicated owner download method; `ManagerReportService::reportForDownload`; `Reports.tsx::handleDownloadReport` and completed row actions; `HandleInertiaRequests::erpUrls` reports endpoint/download URL if a separate URL is needed.

**Layer:** both. **Migration:** none.

- [ ] Add owner authenticated same-shop completed CSV download test, owner wrong-shop 404/deny, anonymous denied, manager unaffected, and owner generate/review still denied.
- [ ] Resolve tenant from the dedicated owner ERP context; pass it to existing scoped reportForDownload. Interpret QA's “completed report” as current `generated`/`reviewed` (including legacy `sent` mapping), with a valid stored snapshot; reject `failed` or unfinished artifacts. Do not add a new `completed` status. Retain private storage, never accept a client-provided filesystem path, and preserve safe reconstruction from stored snapshot and downloaded_at semantics.
- [ ] Add only the scoped GET route to owner module/audience/actor authorization; keep Manager mutation routes/capabilities unchanged. Existing owner invoice rules do not grant report generation.
- [ ] Use server-approved download URL/base path, remove owner-only no-op for completed downloads, and show download in owner rows while generation/review stay hidden and protected.

**Automated:** extend `tests/Feature/Manager/ManagerReportTest.php` with proper clock-in fixture for Manager mutations, add `tests/Feature/ShopOwner/OwnerManagerReportDownloadTest.php`, extend `resources/js/Pages/ERP/Manager/__tests__/Reports.contract.test.ts` with runtime UI coverage; route/module coverage for the new name. Include incomplete report, missing file reconstruction, hostile report ID and cross-shop path isolation.

**Manual:** Manager generates (clocked in); Owner downloads own completed file, not another shop's; Owner cannot generate/review; existing reports remain listed. **Risk/rollback:** route/config/UI must ship together. GET download records only allowed read-side telemetry/reconstruction, not report content changes. Do not expose storage publicly.

### K. Human-readable report serialization — QA #18

**Files/methods:** `ManagerReportService::buildCsvContent/storeFile/buildSalesData/reportForDownload` export presentation only; readable assignee resolution reuses existing User relationships/tenant-scoped batching. **Layer:** backend. **Migration:** none required.

- [ ] Keep report_data structured. Add a report-type-aware CSV presentation map with stable readable headers: Order Number, Customer, Assigned Staff, Status, Total Amount, Order Items, Created At. Resolve assignee name where known; preserve safe historical fallback. Avoid leaking internal IDs unnecessarily.
- [ ] Format each item as `Nike Shoes × 1 — ₱6,000.00`; join multiple items with `; `. Use fputcsv for delimiters, embedded commas/quotes/newlines, Unicode, null/empty items and compatible old snapshot shapes. Other report types keep their supported fields; no raw JSON fallback for user-facing nested values.
- [ ] Protect spreadsheet formula-leading user text without corrupting numeric monetary cells. Test malicious product/customer text, not only the happy example.
- [ ] Handle **existing persisted CSV files**, not just new reports. They are served unchanged by reportForDownload when present. Rebuild an export from stored report_data with the new presenter during authorized download/versioned export handling, or offer an explicit export regeneration operation. Preserve the original stored artifact/metadata rather than silently destroying historical files; choose a sibling formatted export path if both need retention. Do not rerun current sales queries for historical reports.

**Automated:** extend `tests/Feature/Manager/ManagerReportTest.php` with scalar/header/item serialization assertions and a pre-existing raw-JSON file downloaded through the new path. Add focused presenter unit test if formatting is extracted. Cover multiple/empty items, currency decimals, UTF-8, delimiters/quotes/newlines, old shapes, formula injection, stock/damage/performance regressions, original snapshot unchanged.

**Manual:** open downloaded current and historical sales CSV in spreadsheet software: readable items/headers, one row per order, correct money/quantity, no raw JSON; other report exports still readable. **Risk/rollback:** presentation changes must not mutate report_data/source transactions or historical calculations. Retain originals; rollback can serve old artifacts without data loss.

## Implementation order and verification gates

1. A and F first: current identity exposure and competing resolution workflows.
2. B; C with explicit continuation boundary agreed and tested.
3. D and legitimate service edit eligibility (F #7) together where snapshot replacement depends on editing.
4. E after the runtime trace determines the minimal lifecycle change.
5. G, then H source-row/balance/reference verification. No accounting backfill before source evidence.
6. I, J, K; report download and export presentation must support existing files.

Run one task at a time. Tests below are proposed implementation commands, not claims of successful remediation:

```powershell
php vendor/bin/phpunit tests/Feature/Notifications/ErpNotificationIndexScopeTest.php
php vendor/bin/phpunit tests/Feature/Manager/ManagerStaffWorkloadTest.php
php vendor/bin/phpunit tests/Feature/Repair/RepairDeliveryQuoteTest.php
php vendor/bin/phpunit tests/Feature/Repair/Warranty/RepairWarrantyEligibilityTest.php
php vendor/bin/phpunit tests/Feature/Repairer/RepairMaterialPlanningGateTest.php
php vendor/bin/phpunit tests/Feature/Customer/RepairServiceModificationTest.php
php vendor/bin/phpunit tests/Feature/RepairOnlineRefundWorkflowTest.php
php vendor/bin/phpunit tests/Feature/PlatformFee/PlatformFeeLedgerTest.php
php vendor/bin/phpunit tests/Feature/PlatformFee/PlatformBalanceApiTest.php
php vendor/bin/phpunit tests/Feature/Finance/InvoicePaymentTest.php
php vendor/bin/phpunit tests/Feature/Manager/ManagerReportTest.php
pnpm run test:frontend -- resources/js/components/header/__tests__/NotificationCenter.test.tsx
pnpm run test:frontend -- resources/js/Pages/UserSide/Repairs/__tests__/myRepairs.refund-workflow.test.tsx
pnpm run test:frontend -- resources/js/Pages/ERP/Finance/__tests__/Invoice.owner-actions.test.tsx
pnpm run test:frontend
pnpm run build
composer test
git diff --check
```

New test files listed in tasks are also run directly after creation. Expected remediation outcome: each targeted regression fails for the intended reason before changes and passes afterward; wider suites remain at least at the documented baseline, with existing fixture failures resolved in the authorized test scope. Never disable attendance/module/RBAC middleware to manufacture a pass. Fix missing test attendance setup in implementation, not this audit. Browser verification requires an available authenticated QA/staging browser; production payment/refund mutations are not part of this read-only phase.

## Audit verification performed

| Exact command/check | Result |
| --- | --- |
| `git branch --show-current`, `git worktree list`, `git remote get-url origin`, `git status --short` | Requested branch/worktree/remote verified; unrelated parent changes and initial untracked work preserved. |
| `codegraph explore "useNotifications NotificationDropdown ManagerController staffWorkload PlatformFeeLedgerService finalizeRepair PlatformBalanceService ManagerReportController"` | Graph consulted; source trace followed with current files where graph coverage was incomplete. |
| Broader PHPUnit filter command below | 46 tests, 184 assertions, 17 failures, 249 PHPUnit deprecations. 15 Manager report failures and one manual-POS warranty test returned HTTP 423; one legacy staff-performance assertion returned 0 instead of 2. No test fixes made. |
| Narrower PHPUnit filter command below | **19 tests, 127 assertions, 0 failures**, 249 PHPUnit deprecations. This is existing coverage, not new regression coverage for every QA item. |
| Read-only PHP bootstrap/DB query via PowerShell here-string piped to PHP stdin | Counts and two retail source rows inspected; no repair fixtures locally; no DB mutations. |
| Read-only ReflectionMethod invocation of `PlatformFeeLedgerService::isEligibleRepair` via PHP stdin | Positive completed/hyphenated-ready fixtures, negative underscored-ready/picked_up/received fixtures with all other payment/origin/base values equal. No fee creation or provider request. |
| `PlatformBalanceService::summary(3)` via read-only PHP stdin | Existing local payment/credit effects produce zero outstanding; source rows are unrelated to QA ₱300 pair. No provider reconciliation or controller GET side effects invoked. |
| `Invoke-WebRequest https://solespace.shop/` and public app/repair asset inspection | HTTP 200; deployed repair page filename matches local committed manifest; deployed cleanup removes all five parameters. No login or business mutation. Browser tool had no available browser. |
| Frontend test/build/type/lint | Not run in audit. pnpm was not discoverable on PATH; dependencies are present. No dependency installation attempted. Repository has no committed TS compiler config/frontend lint script; no passing type/lint claim. Build would also rewrite tracked generated assets, unnecessary for a document-only phase. |
| `git diff --check`; final `git status --short` | No tracked changes or diff-hygiene errors. Status contains only the two untracked plans and pre-existing cache directory. |
| `git diff --no-index --check -- /dev/null docs/superpowers/plans/2026-10-04-deployed-qa-audit-and-remediation-plan.md` | No whitespace diagnostics; exit 1 reflects the new-file comparison. Separate UTF-8/source-path/structure checks found all 47 indexed source paths present, 18 audit rows, 11 workstreams, no conflict markers or replacement characters. |

Exact PHPUnit commands run, in order:

```powershell
php vendor/bin/phpunit --filter 'PlatformFeeLedgerTest|PlatformBalanceApiTest|ErpNotificationIndexScopeTest|RepairWarrantyEligibilityTest|ManagerStaffWorkloadTest|ManagerReportTest|RepairServiceModificationTest'
php vendor/bin/phpunit --filter 'PlatformFeeLedgerTest|PlatformBalanceApiTest|ErpNotificationIndexScopeTest|ManagerStaffWorkloadTest|RepairServiceModificationTest'
```

### Read-only eligibility probe (reproducible)

```php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ledger = app(App\Services\PlatformFeeLedgerService::class);
$eligible = new ReflectionMethod($ledger, 'isEligibleRepair');
foreach (['completed', 'ready-for-pickup', 'ready_for_pickup', 'picked_up', 'received'] as $status) {
    $repair = new App\Models\RepairRequest([
        'origin_channel' => 'marketplace', 'status' => $status,
        'payment_status' => 'completed', 'final_total' => 6000,
        'total_paid_amount' => 6000,
    ]);
    echo json_encode(['status' => $status, 'eligible' => $eligible->invoke($ledger, $repair)]).PHP_EOL;
}
```

This probes the current guard without saving any model. It does not prove that a particular deployed transaction took that path.

## Sequential review record and completion boundary

| Required gate | Audit/plan result |
| --- | --- |
| simplify / ponytail | Pass for plan: reuse canonical services; bounded shared eligibility only where duplication/correctness demands it; no new dependency, fee subsystem, or wholesale state enum. |
| Standards → Spec → correctness review | Performed sequentially. Findings are the table above; unresolved deployed attribution is explicit. All QA IDs map to A–K, regression/manual coverage and guards. No parallel reviewer invoked. |
| clean-code-typescript | Source risk identified: identity-free keys, duplicated UI eligibility. Proposed typed identity/capability boundaries. Implementation cleanliness is N/A until TS changes exist. |
| karpathy-guidelines | Pass for plan: actual checkout verified; current code over old plans; assumption/evidence split; bounded changes; observable acceptance tests. |
| code-splitting | N/A: no frontend bundle change proposed; no artificial lazy splits. |
| gauge-improvements | Existing baseline recorded; post-fix correctness/performance improvements **not measured**. No improvement claim based on a proposed patch. |
| security-review + Laravel | Findings recorded: cache principal isolation, lock-time resolution exclusion, missing Logistics creation gate, Finance ID-namespace fallback risk, report owner boundary, CSV formula handling. Preserve tenant/RBAC/attendance/provider verification. |
| verification-before-completion | Fresh baseline/guard/deployment evidence and document/status hygiene recorded. No claim that defects were fixed or all tests passed. |
| Reuse audit | Existing notification provider/hooks, owner resolver, module service, material planner, payment/refund/warranty services, fee ledger/config/refund service, report service all reused. |
| Dead-code scan | No source deletions. Legacy NotificationDropdown is not removed merely because AppHeader uses NotificationCenter; runtime references must be checked if cleanup is ever requested. |
| Writing / vault learning | This document is the requested durable artifact. Existing plans/learning log preserved; no speculative or personal-data learning entry. |

## Unresolved evidence and product decisions

1. Obtain deployed notification ID plus first wrong DOM/API payload and current principal; distinguish stale cache from event addressing. Preserve recipient rows.
2. Obtain QA's owner proxy/Employee identity and production release PHP commit; local shops have no resolved owner-linked User, so the named account cannot be inspected locally.
3. QA #6 needs an available browser to capture mount/history/storage state and subsequent navigation. Served cleanup is proven; exact runtime failure is not.
4. QA #8 needs deployed unfinished repair JSON/status history and precise action path. Current UI is picked_up-gated; backend completion guard is still absent.
5. QA #14–15 need the affected repair/order IDs, redacted verified payment/source ledger fields, fee setting effective dates and exact charges/credits/payment allocations. No SUM or historical fee backfill decision before this evidence.
6. Define started-shipment continuation at the persisted shipment/leg/custody boundary using the approved rule; allow continuation only for its covered actions, not new warranty/return/replacement work.
7. Confirm partial-service-refund warranty treatment if current policy does not settle it. Final fully refunded claims are unequivocally prohibited. Preserve legitimate delivery-only compensation.
8. Historical material snapshots cannot be restored from current templates with certainty. Preserve existing plans; any legacy initialization must carry explicit provenance and must not rewrite completed history.

No further broad product discovery is needed for the approved owner read-only/report-download/CSV/marketplace-fee rules. The remaining items are bounded evidence or compatibility decisions. Stop here and wait for **`approve implementation`**.
