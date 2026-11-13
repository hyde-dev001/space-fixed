# SME Procurement Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a tenant-safe SME purchasing and receiving workflow with Finance-initial/Shop-Owner/Finance-final PR approval, one-or-many-item POs, partial receipts, exact-once inventory/Finance posting, and audit-safe receipt voiding.

**Architecture:** Keep `PurchaseOrder` as the canonical header, move line data into `PurchaseOrderItem`, and make immutable `PurchaseOrderReceipt`/`PurchaseOrderReceiptItem` records the only path that updates inventory and creates procurement expenses. Controllers stay thin: policies and scoped requests protect boundaries, `PurchaseOrderService` owns creation/transitions, and `PurchaseOrderReceiptService` owns receipt and void transactions. Legacy `SupplierOrder` endpoints remain read-only.

**Tech Stack:** Laravel 12, PHP 8.x, Eloquent, MySQL/SQLite-compatible migrations, Spatie Permission, PHPUnit, React 18, TypeScript, Inertia, Vitest, SweetAlert2.

**Approved design:** `docs/superpowers/specs/2026-08-02-sme-procurement-repair-design.md`

---

## File responsibility map

### New backend files

- `database/migrations/2026_08_02_000001_harden_purchase_request_approval.php` — retain the Finance-final PR state and add correctly typed Shop Owner and rejection audit columns.
- `database/migrations/2026_08_02_000002_create_purchase_order_receiving_tables.php` — create PO items, receipts, receipt items, and add `partially_received` support.
- `database/migrations/2026_08_02_000003_link_procurement_receipts.php` — add the canonical receipt link to Finance expenses and receipt/reversal links to stock movements without changing the legacy SupplierOrder FK.
- `app/Console/Commands/BackfillPurchaseOrderItems.php` — idempotently convert current single-item POs and stored receipt aggregates into migration-source rows without replaying side effects.
- `app/Models/PurchaseOrderItem.php` — immutable approved-PR line snapshot and cumulative quantity helpers.
- `app/Models/PurchaseOrderReceipt.php` — receipt header, source/status, idempotency, expense, receiver, and void audit.
- `app/Models/PurchaseOrderReceiptItem.php` — received/defective/accepted quantities plus exact inventory-effect snapshot.
- `database/factories/PurchaseOrderItemFactory.php`
- `database/factories/PurchaseOrderReceiptFactory.php`
- `database/factories/PurchaseOrderReceiptItemFactory.php`
- `app/Http/Requests/StorePurchaseOrderReceiptRequest.php` — tenant-aware per-line receipt validation.
- `app/Http/Requests/VoidPurchaseOrderReceiptRequest.php` — required void reason.
- `app/Http/Controllers/Erp/PurchaseOrderReceiptController.php` — list, post, and void receipt HTTP adapter.
- `app/Services/PurchaseOrderReceiptService.php` — locked atomic receipt/void, inventory effects, expense posting, and PO recalculation.
- `app/Policies/SupplierPolicy.php` — basic supplier CRUD authorization and tenant boundary.
- `tests/Feature/Procurement/ProcurementAuthorizationTest.php`
- `tests/Feature/Procurement/PurchaseOrderItemsTest.php`
- `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`
- `tests/Feature/Procurement/PurchaseOrderBackfillTest.php`
- `tests/Feature/Procurement/ProcurementApiContractTest.php`
- `tests/Feature/Procurement/ProcurementConcurrencyTest.php`
- `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx` — receipt history, receive form, and authorized void action.
- `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`
- `resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx`

### Existing backend files to modify

- `database/seeders/RolesAndPermissionsSeeder.php` — seed and assign action permissions.
- `app/Models/PurchaseRequest.php` and `app/Services/PurchaseRequestService.php` — explicit Finance initial review, Shop Owner acknowledgment/approval, and Finance final release.
- `app/Http/Controllers/Erp/PurchaseRequestController.php` and `app/Http/Controllers/ShopOwner/PurchaseRequestController.php` — call the correct transition and actor audit path.
- `app/Policies/PurchaseRequestPolicy.php`, `app/Policies/PurchaseOrderPolicy.php`, and `app/Policies/StockRequestApprovalPolicy.php` — granular permissions, tenant checks, and self-approval prevention.
- `app/Http/Controllers/Erp/StockRequestApprovalController.php` — restore policy checks and shop-scope all IDs.
- `app/Http/Controllers/Erp/SupplierController.php` — authorize basic CRUD only.
- `app/Http/Requests/StorePurchaseRequestRequest.php` and `app/Http/Requests/StorePurchaseOrderRequest.php` — shop-scoped existence and grouped-PR validation.
- `app/Models/PurchaseOrder.php`, `app/Services/PurchaseOrderService.php`, and `app/Http/Controllers/Erp/PurchaseOrderController.php` — item relationships, strict transitions, grouped creation, metrics, and removal of direct delivery side effects.
- `app/Models/StockMovement.php` and `app/Models/Finance/Expense.php` — canonical receipt relationships.
- `app/Services/ExpenseApprovalService.php` — cancel an attached pending workflow during an eligible receipt void.
- `routes/procurement-api.php` and `routes/inventory-api.php` — canonical receipt routes and read-only legacy writes.
- `app/Http/Controllers/Erp/SupplierOrderController.php` and `app/Http/Controllers/Erp/SupplierOrderMonitoringController.php` — retain only historical reads/metrics.
- `app/Providers/EventServiceProvider.php`, `routes/console.php`, `app/Jobs/AutoApproveLowValuePRsJob.php`, `app/Jobs/GenerateProcurementReportJob.php`, `app/Jobs/CheckOverduePurchaseOrdersJob.php`, and `app/Listeners/NotifyOverduePOs.php` — disconnect bypass/duplicate side effects and prevent unscoped scheduled notifications.
- `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`, `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`, `tests/Feature/SupplierOrderTest.php`, `tests/Unit/Models/PurchaseOrderTest.php`, and `tests/Unit/Services/PurchaseOrderServiceTest.php` — replace legacy happy-path assumptions.

### Existing frontend files to modify

- `resources/js/services/purchaseOrderApi.ts` — canonical response unwrapping, item/receipt types, receive and void methods.
- `resources/js/services/purchaseRequestApi.ts` — canonical mutation response unwrapping.
- `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx` — grouped PR selection, item rows, strict next actions, and receipt panel.
- `resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx` — retain and clearly label the Finance-final status.
- `resources/js/Pages/ERP/Finance/PurchaseRequestApproval.tsx` — Finance handles initial review in `pending_finance` and final release in `pending_finance_final`.
- `resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx` — owner approval advances to `pending_finance_final`.
- `resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx` — historical read-only banner and removal of mutations.
- `resources/js/services/supplierApi.ts` — remove unsupported performance/history methods and unwrap CRUD responses.

---

## Phase 1 — Security and approval foundation

### Task 1: Add granular procurement permissions and tenant regression tests

**Files:**
- Create: `tests/Feature/Procurement/ProcurementAuthorizationTest.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `routes/procurement-api.php`
- Modify: `app/Policies/PurchaseRequestPolicy.php`
- Modify: `app/Policies/PurchaseOrderPolicy.php`
- Modify: `app/Policies/StockRequestApprovalPolicy.php`
- Create: `app/Policies/SupplierPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `app/Http/Requests/StorePurchaseRequestRequest.php`
- Modify: `app/Http/Requests/StorePurchaseOrderRequest.php`

- [ ] **Step 1: Write failing authorization tests**

Cover cross-shop `show`, foreign supplier/inventory/PR IDs, broad-dashboard permission not authorizing mutations, requester self-review, Inventory receiving permission, and void permission separation. Use explicit permissions rather than role-name assertions.

```php
$finance->givePermissionTo('procurement.review_purchase_requests');
$procurement->givePermissionTo('procurement.create_purchase_orders');
$inventory->givePermissionTo('procurement.receive_purchase_orders');

$this->actingAs($procurement)
    ->getJson("/api/erp/procurement/purchase-orders/{$otherShopPo->id}")
    ->assertNotFound();
```

- [ ] **Step 2: Run the focused test and confirm RED**

Run: `php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php --compact`

Expected: failures showing broad permissions still approve/mutate and cross-shop IDs are reachable or return `403` instead of tenant-hiding `404`.

- [ ] **Step 3: Seed the minimal action permissions**

Add these exact `user`-guard permissions and assign only the approved defaults:

```php
procurement.view
procurement.create_purchase_requests
procurement.submit_purchase_requests
procurement.review_purchase_requests
procurement.create_purchase_orders
procurement.manage_purchase_orders
procurement.receive_purchase_orders
procurement.complete_purchase_orders
procurement.cancel_purchase_orders
procurement.void_purchase_order_receipts
procurement.manage_suppliers
procurement.review_stock_requests
```

`Finance` receives review only; `Procurement Manager` receives view/create/submit/manage/receive/complete/cancel but not void; `Inventory Manager` receives view/create/submit/receive; Shop Owner authority remains on its own guard.

- [ ] **Step 4: Make policies action-specific and lookups tenant-hiding**

Keep legacy page permissions only for `viewAny`. Mutation methods must check the matching action permission plus exact `shop_owner_id`; Finance review must also reject `requested_by === $user->id`. Register `SupplierPolicy`. In PR/PO controllers and Form Requests, resolve records through `where('shop_owner_id', auth()->user()->shop_owner_id)->findOrFail(...)` and shop-scoped `Rule::exists` before policy authorization, so foreign-shop IDs return `404` rather than leaking existence through `403`.

- [ ] **Step 5: Run focused authorization and seeder tests**

Run: `php artisan test tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/Logistics/LogisticsSeederTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Policies app/Providers/AuthServiceProvider.php app/Http/Controllers/Erp/PurchaseRequestController.php app/Http/Controllers/Erp/PurchaseOrderController.php app/Http/Requests/StorePurchaseRequestRequest.php app/Http/Requests/StorePurchaseOrderRequest.php database/seeders/RolesAndPermissionsSeeder.php routes/procurement-api.php tests/Feature/Procurement/ProcurementAuthorizationTest.php
git commit -m "fix: enforce procurement action permissions"
```

### Task 2: Tighten the Finance initial → Shop Owner → Finance final PR workflow

**Files:**
- Create: `database/migrations/2026_08_02_000001_harden_purchase_request_approval.php`
- Modify: `app/Models/PurchaseRequest.php`
- Modify: `app/Services/PurchaseRequestService.php`
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Modify: `app/Http/Controllers/ShopOwner/PurchaseRequestController.php`
- Modify: `app/Http/Requests/StorePurchaseRequestRequest.php`
- Modify: `app/Http/Controllers/Erp/ProcurementSettingsController.php`
- Modify: `app/Jobs/AutoApproveLowValuePRsJob.php`
- Modify: `routes/console.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`
- Modify: `tests/Unit/Models/PurchaseRequestTest.php`
- Modify: `tests/Unit/Services/PurchaseRequestServiceTest.php`

- [ ] **Step 1: Replace happy-path tests with actor-specific failing tests**

Test `draft -> pending_finance -> pending_shop_owner -> pending_finance_final -> approved`, Finance rejection at either Finance stage, Shop Owner rejection, requester self-review denial, owner IDs stored only in owner FK columns, Finance initial audit preservation after final release, final Finance actor stored in the user FK, explicit create-and-submit entering only `pending_finance`, create-only users being forbidden from create-and-submit, atomic rollback if submission fails, existing `pending_finance_final` rows remaining in that state with new owner-audit fields null when history cannot be inferred, and low-value/settings/job/page-payload paths never skipping an approval stage.

```php
$this->actingAs($finance)->postJson(".../{$pr->id}/approve")
    ->assertOk();
$this->assertSame('pending_shop_owner', $pr->fresh()->status);

$this->actingAs($owner, 'shop_owner')->postJson(".../{$pr->id}/approve")
    ->assertOk();
$this->assertSame('pending_finance_final', $pr->fresh()->status);

$this->actingAs($finance)->postJson(".../{$pr->id}/approve")
    ->assertOk();
$this->assertSame('approved', $pr->fresh()->status);
```

- [ ] **Step 2: Run PR tests and confirm RED**

Run: `php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Unit/Services/PurchaseRequestServiceTest.php --compact`

Expected: failures for Finance incorrectly finalizing at its initial stage, actor ambiguity, and approval bypasses.

- [ ] **Step 3: Add correct audit columns and migrate old state**

Add nullable `approved_by_shop_owner_id`, `shop_owner_approved_at`, `rejected_by_user_id`, `rejected_by_shop_owner_id`, and `rejected_at`. Keep `approved_by`/`approved_date` for the final Finance user and retain `pending_finance_final` in the status enum. Do not guess historical Shop Owner actors: existing rows retain their status and current audit values, while the new owner-specific fields remain null unless a future action records them.

- [ ] **Step 4: Replace overloaded approval logic with explicit transitions**

Implement `reviewByFinance(User $actor)`, `approveByShopOwner(ShopOwner $actor)`, `releaseByFinance(User $actor)`, `rejectByFinance(User $actor)`, and `rejectByShopOwner(ShopOwner $actor)`. Each method checks its exact source status and records the correct actor type. Finance review accepts only `pending_finance`; Finance release accepts only `pending_finance_final`; Shop Owner approval accepts only `pending_shop_owner`.

- [ ] **Step 5: Remove every approval bypass**

Make `PurchaseRequestController::store()` call the shared service. The service creates `draft`; when the request explicitly includes `submit_to_finance=true`, require both `procurement.create_purchase_requests` and `procurement.submit_purchase_requests`, then call the normal submit transition inside the same creation transaction so failure rolls back the new PR and success ends only in `pending_finance`. Delete `shouldAutoApprove()` and conditional `requiresOwnerApproval` behavior, make `AutoApproveLowValuePRsJob` a retired/no-op path, remove its schedule, and stop exposing auto-approval settings as active behavior. Remove threshold-derived `requires_owner_approval` and immediate-finalization assumptions from Procurement/Finance page payloads in `routes/web.php`. No threshold, role, event, job, or page payload may skip Finance initial, Shop Owner, or Finance final.

- [ ] **Step 6: Scope request references to the authenticated shop**

Use `Rule::exists(...)->where('shop_owner_id', $shopId)` for supplier and inventory IDs and verify stock-request markers resolve only inside that shop.

- [ ] **Step 7: Run PR and authorization tests**

Run: `php artisan test tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Unit/Services/PurchaseRequestServiceTest.php --compact`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_02_000001_harden_purchase_request_approval.php app/Models/PurchaseRequest.php app/Services/PurchaseRequestService.php app/Http/Controllers/Erp/PurchaseRequestController.php app/Http/Controllers/ShopOwner/PurchaseRequestController.php app/Http/Controllers/Erp/ProcurementSettingsController.php app/Http/Requests/StorePurchaseRequestRequest.php app/Jobs/AutoApproveLowValuePRsJob.php routes/console.php routes/web.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Unit/Services/PurchaseRequestServiceTest.php
git commit -m "fix: tighten purchase request approvals"
```

### Task 3: Restore stock-request and supplier tenant boundaries

**Files:**
- Modify: `app/Http/Controllers/Erp/StockRequestApprovalController.php`
- Modify: `app/Http/Controllers/Erp/SupplierController.php`
- Modify: `routes/web.php`
- Modify: `routes/procurement-api.php`
- Modify: `tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php`
- Modify: `tests/Unit/SupplierTest.php`

- [ ] **Step 1: Add failing cross-shop and action-permission tests**

Cover stock request show/approve/reject/request-details/store references and supplier show/update/archive/restore.

- [ ] **Step 2: Run tests and confirm RED**

Run: `php artisan test tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Unit/SupplierTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php --compact`

- [ ] **Step 3: Restore every policy call and shop-scope every lookup**

Replace global `findOrFail()` with `where('shop_owner_id', $shopId)->findOrFail()`, validate referenced inventory/repair rows within the shop, call the existing stock-request policy, and authorize Supplier CRUD through `SupplierPolicy`.

- [ ] **Step 4: Remove unsupported supplier routes**

Delete active routes for `purchase-history`, `performance`, and `rating`; do not implement the unused Supplier Performance service.

- [ ] **Step 5: Run focused tests and commit**

Run: `php artisan test tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Unit/SupplierTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php --compact`

```bash
git add app/Http/Controllers/Erp/StockRequestApprovalController.php app/Http/Controllers/Erp/SupplierController.php routes/procurement-api.php tests/Feature/Procurement/ReplenishmentAndStockRequestTest.php tests/Unit/SupplierTest.php
git commit -m "fix: isolate procurement records by shop"
```

---

## Phase 2 — Canonical purchase orders

### Task 4: Add PO item and receipt schema, models, factories, and backfill

**Files:**
- Create: `database/migrations/2026_08_02_000002_create_purchase_order_receiving_tables.php`
- Create: `database/migrations/2026_08_02_000003_link_procurement_receipts.php`
- Create: `app/Models/PurchaseOrderItem.php`
- Create: `app/Models/PurchaseOrderReceipt.php`
- Create: `app/Models/PurchaseOrderReceiptItem.php`
- Create: `database/factories/PurchaseOrderItemFactory.php`
- Create: `database/factories/PurchaseOrderReceiptFactory.php`
- Create: `database/factories/PurchaseOrderReceiptItemFactory.php`
- Create: `app/Console/Commands/BackfillPurchaseOrderItems.php`
- Modify: `app/Models/PurchaseOrder.php`
- Modify: `app/Models/PurchaseRequest.php`
- Modify: `app/Models/StockMovement.php`
- Modify: `app/Models/Finance/Expense.php`
- Create: `tests/Feature/Procurement/PurchaseOrderBackfillTest.php`

- [ ] **Step 1: Write failing relationship/backfill tests**

Test one item row per legacy PO, all-size target snapshot, migration-source receipts for stored aggregates, terminal historical immutability, command idempotency, and no inventory/expense replay.

- [ ] **Step 2: Run the backfill test and confirm RED**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderBackfillTest.php --compact`

- [ ] **Step 3: Create the additive schema**

Use these minimum constraints:

```php
$table->unique(['purchase_order_id', 'idempotency_key']);
$table->unique(['purchase_order_receipt_id', 'purchase_order_item_id']);
$table->unique('procurement_receipt_id'); // finance_expenses, nullable
$table->unique('purchase_order_receipt_item_id'); // original parent stock movement, nullable
$table->unique('reversal_of_stock_movement_id'); // stock_movements, nullable
```

`purchase_order_items` stores approved PR snapshots, `quantity_multiplier`, and `eligible_size_ids` JSON. Receipt items store integers plus `inventory_effects` JSON. Add `partially_received` to the PO enum on MySQL while SQLite accepts the string column behavior.

- [ ] **Step 4: Add relationships and computed helpers**

`PurchaseOrder::items()`, `receipts()`, `activeReceipts()`; `PurchaseOrderItem::acceptedQuantity()` and `remainingQuantity()` sum only posted receipts; `PurchaseOrderReceipt::expense()` and void audit relationships; StockMovement original/reversal relationships.

- [ ] **Step 5: Implement and test an idempotent dry-run-capable backfill command**

Declare `protected $signature = 'procurement:backfill-purchase-orders {--dry-run}'`. Run in chunks; use `firstOrCreate` keys; mark generated receipts `source=migration`; never call inventory or Finance services. With `--dry-run`, calculate/report counts, totals, and unresolved rows without any inserts/updates. Test that dry-run leaves row counts unchanged, live mode backfills once, and a second live run is idempotent. Return non-zero for unresolved non-terminal all-size rows.

- [ ] **Step 6: Run migration/backfill tests**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderBackfillTest.php --compact`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_02_000002_create_purchase_order_receiving_tables.php database/migrations/2026_08_02_000003_link_procurement_receipts.php app/Models/PurchaseOrder.php app/Models/PurchaseRequest.php app/Models/PurchaseOrderItem.php app/Models/PurchaseOrderReceipt.php app/Models/PurchaseOrderReceiptItem.php app/Models/StockMovement.php app/Models/Finance/Expense.php database/factories/PurchaseOrderItemFactory.php database/factories/PurchaseOrderReceiptFactory.php database/factories/PurchaseOrderReceiptItemFactory.php app/Console/Commands/BackfillPurchaseOrderItems.php tests/Feature/Procurement/PurchaseOrderBackfillTest.php
git commit -m "feat: add canonical purchase order records"
```

### Task 5: Create one-or-many-item POs from approved PRs

**Files:**
- Modify: `app/Http/Requests/StorePurchaseOrderRequest.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `app/Models/PurchaseOrder.php`
- Create: `tests/Feature/Procurement/PurchaseOrderItemsTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`
- Modify: `tests/Unit/Services/PurchaseOrderServiceTest.php`

- [ ] **Step 1: Write failing grouped-creation tests**

Cover one PR, multiple same-shop/same-supplier PRs, mixed supplier/shop rejection, unapproved PR rejection, server-copied line fields/totals, cancelled PO releasing a PR, completed/non-cancelled PO blocking reuse, and concurrent duplicate prevention using locked PR rows.

- [ ] **Step 2: Run tests and confirm RED**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderItemsTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Unit/Services/PurchaseOrderServiceTest.php --compact`

- [ ] **Step 3: Change the request contract**

Accept `purchase_request_ids` as a required distinct integer array and header-only editable data. Do not accept quantity, unit cost, total, supplier, inventory item, size, or color from the client.

- [ ] **Step 4: Centralize creation in `PurchaseOrderService`**

Inside one transaction, shop-scope and `lockForUpdate()` all selected PRs, require approved/same supplier, check no non-cancelled PO item exists, create the header, snapshot size IDs/multiplier, create items, and sum line totals. Keep PO-number collision retry.

- [ ] **Step 5: Return one consistent envelope and eager-load canonical relationships**

```php
return response()->json([
    'message' => 'Purchase order created.',
    'data' => $purchaseOrder->load('items.purchaseRequest', 'supplier'),
], 201);
```

- [ ] **Step 6: Run creation tests and commit**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderItemsTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Unit/Services/PurchaseOrderServiceTest.php --compact`

```bash
git add app/Http/Requests/StorePurchaseOrderRequest.php app/Services/PurchaseOrderService.php app/Http/Controllers/Erp/PurchaseOrderController.php app/Models/PurchaseOrder.php tests/Feature/Procurement/PurchaseOrderItemsTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php tests/Unit/Services/PurchaseOrderServiceTest.php
git commit -m "feat: create multi-item purchase orders"
```

### Task 6: Enforce the PO state machine and remove direct delivery

**Files:**
- Modify: `app/Models/PurchaseOrder.php`
- Modify: `app/Services/PurchaseOrderService.php`
- Modify: `app/Http/Requests/UpdatePurchaseOrderStatusRequest.php`
- Modify: `app/Http/Requests/CancelPurchaseOrderRequest.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `routes/procurement-api.php`
- Modify: `tests/Unit/Models/PurchaseOrderTest.php`
- Modify: `tests/Feature/Procurement/PurchaseOrderWorkflowTest.php`

- [ ] **Step 1: Write the transition matrix tests**

Test every valid transition plus draft-to-delivered, sent-to-completed, repeated status, cancellation after posted receipt, and completion before full accepted quantity.

- [ ] **Step 2: Run and confirm current invalid transitions falsely succeed**

Run: `php artisan test tests/Unit/Models/PurchaseOrderTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php --compact`

- [ ] **Step 3: Implement strict model transitions**

Use explicit allowed-current-state checks; throw `ValidationException` on invalid state. Receipt recalculation alone owns `partially_received`/`delivered`. Completion requires `delivered`; cancellation allows draft/sent/confirmed/in_transit only when no posted receipt.

- [ ] **Step 4: Remove delivery from generic status handling**

Delete `delivered` handling and all inventory/expense calls from `PurchaseOrderController::updateStatus()` and remove the old `mark-delivered` route. The controller calls service methods and reports success only after a successful transition.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test tests/Unit/Models/PurchaseOrderTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php --compact`

```bash
git add app/Models/PurchaseOrder.php app/Services/PurchaseOrderService.php app/Http/Requests/UpdatePurchaseOrderStatusRequest.php app/Http/Requests/CancelPurchaseOrderRequest.php app/Http/Controllers/Erp/PurchaseOrderController.php routes/procurement-api.php tests/Unit/Models/PurchaseOrderTest.php tests/Feature/Procurement/PurchaseOrderWorkflowTest.php
git commit -m "fix: enforce purchase order transitions"
```

---

## Phase 3 — Receiving, inventory, Finance, and correction

### Task 7: Post partial receipts exactly once

**Files:**
- Create: `app/Http/Requests/StorePurchaseOrderReceiptRequest.php`
- Create: `app/Http/Controllers/Erp/PurchaseOrderReceiptController.php`
- Create: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `app/Models/Finance/Expense.php`
- Modify: `app/Http/Controllers/Api/Finance/ExpenseController.php`
- Modify: `routes/procurement-api.php`
- Create: `tests/Feature/Procurement/PurchaseOrderReceivingTest.php`
- Create: `tests/Feature/Procurement/ProcurementConcurrencyTest.php`

- [ ] **Step 1: Write failing receiving tests**

Cover one/multi-line partial receipts, first-receipt full delivery, defects and replacement delivery, over-acceptance, foreign PO item, non-transit PO, same-key same-payload retry, different-payload key conflict, transaction rollback, zero accepted value, expense formula/creator/linkage, Finance procurement detail payload, and specific/all-size inventory effects.

- [ ] **Step 2: Run and confirm RED**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderReceivingTest.php --compact`

- [ ] **Step 3: Validate the receipt boundary**

Require UUID `idempotency_key`, receipt date not in the future, at least one distinct PO item, non-negative integer quantities, and `defective <= received`; request validation shop-scopes the PO while the service verifies item membership under lock.

- [ ] **Step 4: Implement the locked receipt transaction**

Lock PO, PO items, inventory parent/color/size rows, and same-key receipt. Hash the normalized payload. Calculate remaining accepted quantity from posted receipts; record exact `inventory_effects`; create exactly one parent stock movement per receipt item through the unique `purchase_order_receipt_item_id`; update aggregates and delivery audit; create at most one submitted Expense linked by `procurement_receipt_id`.

- [ ] **Step 5: Keep Finance pending**

Create no expense when accepted value is zero. Otherwise calculate `sum(accepted_quantity * purchase_order_item.unit_cost * purchase_order_item.quantity_multiplier)`, set `status=submitted`, store the receiving user in `meta.created_by`, never set `approved_by/approved_at`, and use a unique reference derived from receipt number. Do not populate the legacy `purchase_order_id` FK. Add `Expense::procurementReceipt()` and update `ExpenseController::appendProcurementDetails()` to resolve canonical PO/item/receipt details through `procurement_receipt_id`, while preserving its legacy fallback. If an approval workflow is attached by existing Finance behavior, it remains pending.

- [ ] **Step 6: Add the canonical routes**

```php
GET  /purchase-orders/{purchaseOrder}/receipts
POST /purchase-orders/{purchaseOrder}/receipts
```

- [ ] **Step 7: Add a MySQL locking check**

In `ProcurementConcurrencyTest`, use two database connections when `DB::getDriverName() === 'mysql'` to submit the same receipt concurrently and assert one receipt/original-movement set. Mark the test skipped with an explicit message on SQLite rather than pretending SQLite verifies row locks. Void concurrency is added after the void endpoint exists in Task 8.

- [ ] **Step 8: Run receipt, inventory, and Finance tests**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php tests/Feature/StockMovementTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php --compact`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/StorePurchaseOrderReceiptRequest.php app/Http/Controllers/Erp/PurchaseOrderReceiptController.php app/Services/PurchaseOrderReceiptService.php app/Services/ExpenseApprovalService.php app/Models/Finance/Expense.php app/Http/Controllers/Api/Finance/ExpenseController.php routes/procurement-api.php tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php
git commit -m "feat: receive purchase orders atomically"
```

### Task 8: Void eligible receipts with exact compensating records

**Files:**
- Create: `app/Http/Requests/VoidPurchaseOrderReceiptRequest.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderReceiptController.php`
- Modify: `app/Services/PurchaseOrderReceiptService.php`
- Modify: `app/Services/ExpenseApprovalService.php`
- Modify: `routes/procurement-api.php`
- Create: `tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php`
- Modify: `tests/Feature/Procurement/ProcurementConcurrencyTest.php`

- [ ] **Step 1: Write failing void tests**

Cover required reason, explicit permission, cross-shop, migration-source/history block, completed PO block, the exact allowed expense set (`absent`, `submitted`, `rejected`), rejection of every other status including `draft`, `approved`, and `posted`, insufficient parent/color/size stock, exact all-size reversal, submitted expense rejection, pending approval cancellation/history preservation, status/date/actor recomputation, repeated/concurrent void, and rollback.

- [ ] **Step 2: Run and confirm RED**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php --compact`

- [ ] **Step 3: Add the void request and endpoint**

```php
POST /purchase-orders/{purchaseOrder}/receipts/{receipt}/void
{ "reason": "Count entered twice" }
```

- [ ] **Step 4: Implement one locked compensating transaction**

Recheck eligibility under row locks and accept only no expense or an expense in `submitted`/`rejected`. Validate every recorded inventory effect, mark receipt voided, create one negative reversal for each original receipt-item parent movement with unique `reversal_of_stock_movement_id`, reverse each item's exact parent/color/size deltas, reject the linked submitted expense, cancel a pending Approval, and recompute PO aggregates/status/delivery audit from posted receipts.

- [ ] **Step 5: Make retry behavior idempotent**

An already-voided receipt returns its recorded result; unique reversal links and receipt status prevent double reversal under concurrent requests.

- [ ] **Step 6: Add and run the MySQL concurrent-void case**

Extend `ProcurementConcurrencyTest` with two MySQL connections voiding the same receipt and assert one reversal set. Keep the explicit SQLite skip.

- [ ] **Step 7: Run receipt test suite and commit**

Run: `php artisan test tests/Feature/Procurement/PurchaseOrderReceivingTest.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php tests/Feature/StockMovementTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php --compact`

```bash
git add app/Http/Requests/VoidPurchaseOrderReceiptRequest.php app/Http/Controllers/Erp/PurchaseOrderReceiptController.php app/Services/PurchaseOrderReceiptService.php app/Services/ExpenseApprovalService.php routes/procurement-api.php tests/Feature/Procurement/PurchaseOrderReceiptVoidTest.php tests/Feature/Procurement/ProcurementConcurrencyTest.php
git commit -m "feat: void procurement receipts safely"
```

---

## Phase 4 — API/UI cutover and legacy containment

### Task 9: Make legacy Supplier Orders read-only and disable unsafe automation

**Files:**
- Modify: `routes/inventory-api.php`
- Modify: `app/Http/Controllers/Erp/SupplierOrderController.php`
- Modify: `app/Http/Controllers/Erp/SupplierOrderMonitoringController.php`
- Modify: `app/Providers/EventServiceProvider.php`
- Modify: `routes/console.php`
- Modify: `app/Jobs/GenerateProcurementReportJob.php`
- Modify: `app/Jobs/CheckOverduePurchaseOrdersJob.php`
- Modify: `app/Listeners/NotifyOverduePOs.php`
- Modify: `tests/Feature/SupplierOrderTest.php`
- Create: `tests/Feature/Procurement/ProcurementScheduledJobsTest.php`

- [ ] **Step 1: Write failing legacy and scheduled-job tests**

Assert historical list/show still work, all legacy mutations return `410`, canonical metrics exclude SupplierOrder, null-shop reports send nothing, and overdue recipients never receive another shop's POs.

- [ ] **Step 2: Run and confirm RED**

Run: `php artisan test tests/Feature/SupplierOrderTest.php tests/Feature/Procurement/ProcurementScheduledJobsTest.php --compact`

- [ ] **Step 3: Preserve every legacy write URI as an explicit 410 response**

Keep `GET /supplier-orders`, `GET /supplier-orders/{id}`, and historical metrics backed by read-only controller methods. Keep the existing POST/PUT/DELETE/status/receive/generate-PO route URIs, but route every mutation to one shared `410 Gone` JSON response pointing to `/api/erp/procurement/purchase-orders`; remove their unreachable mutation code and duplicated inventory/expense methods from both controllers. Tests must distinguish `410` from `404/405`.

- [ ] **Step 4: Disconnect duplicate event side effects**

Remove auto-PO creation and PO-delivery inventory/expense listeners from active mappings. Receipt posting remains the sole side-effect owner; do not add supplier-email automation.

- [ ] **Step 5: Disable or shop-scope scheduled work**

Remove the null-shop monthly schedule and the auto-approval schedule. `GenerateProcurementReportJob` and `CheckOverduePurchaseOrdersJob` with no explicit shop return without sending; `NotifyOverduePOs` receives the same shop context and resolves only that shop's users and POs.

- [ ] **Step 6: Run tests and commit**

Run: `php artisan test tests/Feature/SupplierOrderTest.php tests/Feature/Procurement/ProcurementScheduledJobsTest.php --compact`

```bash
git add routes/inventory-api.php app/Http/Controllers/Erp/SupplierOrderController.php app/Http/Controllers/Erp/SupplierOrderMonitoringController.php app/Providers/EventServiceProvider.php routes/console.php app/Jobs/GenerateProcurementReportJob.php app/Jobs/CheckOverduePurchaseOrdersJob.php app/Listeners/NotifyOverduePOs.php tests/Feature/SupplierOrderTest.php tests/Feature/Procurement/ProcurementScheduledJobsTest.php
git commit -m "fix: retire legacy supplier order mutations"
```

### Task 10: Update the canonical Purchase Orders UI and API adapters

**Files:**
- Modify: `app/Http/Controllers/Erp/PurchaseRequestController.php`
- Modify: `app/Http/Controllers/ShopOwner/PurchaseRequestController.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderController.php`
- Modify: `app/Http/Controllers/Erp/PurchaseOrderReceiptController.php`
- Modify: `app/Http/Controllers/Erp/SupplierController.php`
- Create: `tests/Feature/Procurement/ProcurementApiContractTest.php`
- Modify: `resources/js/services/purchaseOrderApi.ts`
- Modify: `resources/js/services/purchaseRequestApi.ts`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx`
- Modify: `resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx`
- Modify: `resources/js/Pages/ERP/Finance/PurchaseRequestApproval.tsx`
- Modify: `resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx`
- Create: `resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx`
- Modify: `resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx`
- Modify: `resources/js/services/supplierApi.ts`
- Create: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx`
- Create: `resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx`

- [ ] **Step 1: Write failing backend envelope tests**

For create/update/approve/reject/receive/void Supplier, PR, PO, and Receipt mutations, require exactly `{ message, data }` at the top level and verify `data` contains the canonical resource. Include Shop Owner PR approval responses.

- [ ] **Step 2: Run backend contract tests and confirm RED**

Run: `php artisan test tests/Feature/Procurement/ProcurementApiContractTest.php --compact`

- [ ] **Step 3: Normalize backend mutation envelopes**

Update all in-scope controllers to return `{ message, data }`; pagination endpoints retain Laravel's standard paginator shape. Do not preserve parallel `purchase_request`, `purchase_order`, or `supplier` mutation keys.

- [ ] **Step 4: Write failing frontend contract tests**

Cover `{ message, data }` unwrapping, same-supplier PR grouping, item totals, partial remaining quantities, receive payload/idempotency key, authorized void visibility/reason, invalid transition buttons, server-error retention, legacy read-only screen, Finance actions for `pending_finance` and `pending_finance_final`, and owner approval ending in `pending_finance_final`.

- [ ] **Step 5: Run frontend tests and confirm RED**

Run: `pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx`

- [ ] **Step 6: Update TypeScript contracts and adapters**

Add `PurchaseOrderItem`, `PurchaseOrderReceipt`, `PurchaseOrderReceiptItem`, receipt/void payload types, canonical envelope unwrapping in PO/PR/Supplier adapters, and API methods. Remove unsupported supplier performance/history methods.

- [ ] **Step 7: Implement grouped creation without an advanced wizard**

Keep the first approved PR select, then show optional checkboxes only for approved PRs from the same supplier. Submit `purchase_request_ids` plus header fields.

- [ ] **Step 8: Add receipt history, receiving, and void UI**

Render item ordered/accepted/defective/remaining values. Generate one UUID per submission attempt with `crypto.randomUUID()`, retain entered data on errors, show a final quantity confirmation, and expose void only when API permissions/eligibility allow it.

- [ ] **Step 9: Clarify the Finance-final PR UI state**

Retain `pending_finance_final` in Procurement and Finance status unions, badges, filters, counts, actions, messages, and request parameters. Finance shows initial-review actions for `pending_finance` and final-release actions for `pending_finance_final`; Shop Owner approval refreshes the request as `pending_finance_final`. Remove threshold-derived `requires_owner_approval` values from `routes/web.php` and do not let the frontend infer that Finance initial review can finalize a PR.

- [ ] **Step 10: Make legacy monitoring visibly read-only**

Remove status/receive controls and show a link to the canonical Purchase Orders page.

- [ ] **Step 11: Run backend/frontend contracts and production build**

Run: `php artisan test tests/Feature/Procurement/ProcurementApiContractTest.php --compact`

Run: `pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx`

Run: `pnpm run build`

Expected: both exit 0.

- [ ] **Step 12: Commit**

```bash
git add app/Http/Controllers/Erp/PurchaseRequestController.php app/Http/Controllers/ShopOwner/PurchaseRequestController.php app/Http/Controllers/Erp/PurchaseOrderController.php app/Http/Controllers/Erp/PurchaseOrderReceiptController.php app/Http/Controllers/Erp/SupplierController.php routes/web.php tests/Feature/Procurement/ProcurementApiContractTest.php resources/js/services/purchaseOrderApi.ts resources/js/services/purchaseRequestApi.ts resources/js/services/supplierApi.ts resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx resources/js/Pages/ERP/Finance/PurchaseRequestApproval.tsx resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx resources/js/Pages/ERP/Procurement/components/PurchaseOrderReceiptPanel.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseOrders.test.tsx resources/js/Pages/ERP/Procurement/__tests__/PurchaseRequestApproval.test.tsx resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx
git commit -m "feat: add canonical procurement receiving UI"
```

---

## Phase 5 — Verification and cutover evidence

### Task 11: Verify migration, regression, and end-to-end behavior

**Files:**
- Modify only if verification exposes a scoped defect.
- Update: `docs/procurement/inventory-procurement-workflow.md`

- [ ] **Step 1: Run all focused backend tests**

```bash
php artisan test tests/Feature/Procurement tests/Unit/Services/PurchaseRequestServiceTest.php tests/Unit/Services/PurchaseOrderServiceTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Unit/Models/PurchaseOrderTest.php tests/Feature/SupplierOrderTest.php tests/Feature/StockMovementTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php --compact
```

Expected: 0 failures.

- [ ] **Step 2: Run all frontend tests**

Run: `pnpm run test:frontend`

Expected: 0 failures.

- [ ] **Step 3: Run the production build**

Run: `pnpm run build`

Expected: exit 0.

- [ ] **Step 4: Verify routes**

Run: `php artisan route:list --path=erp/procurement`

Expected: canonical receipt and void routes present; unsupported supplier performance routes and old mark-delivered route absent.

Run: `php artisan route:list --path=erp/inventory/supplier-orders`

Expected: only read routes remain writable in controller terms; legacy mutation routes return the documented 410 response if retained for compatibility.

- [ ] **Step 5: Verify backfill safely**

Run: `php artisan procurement:backfill-purchase-orders --dry-run`

Expected: counts/totals report with no writes and no unresolved non-terminal rows.

- [ ] **Step 6: Perform the manual browser check**

Use the local app to verify one one-item PO, one two-item PO with two partial receipts, and one eligible receipt void. Confirm exact inventory deltas, submitted/rejected expense state, PO status, receipt history, and no duplicate effect after browser retry.

- [ ] **Step 7: Update current-workflow documentation**

Document the Finance-initial/Shop-Owner/Finance-final PR flow, multi-item PO, partial receipt status, pending Finance expense, void boundary, and read-only legacy Supplier Orders.

- [ ] **Step 8: Review final diff and commit verification docs/fixes**

```bash
git diff --check
git status --short
git add docs/procurement/inventory-procurement-workflow.md
git commit -m "docs: update procurement workflow"
```

Do not stage unrelated workspace changes.
