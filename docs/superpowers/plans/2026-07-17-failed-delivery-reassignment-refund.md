# Failed Delivery Reassignment and Refund Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make failed deliveries filterable and reassignable below the configured attempt limit, show their history in batches, and route maxed-out online orders through return, inspection, inventory, and Finance refund.

**Architecture:** Keep the failed-attempt transition in `ShipmentLegService`, reuse `OrderRefundService` for reservation and gateway workflow, and reuse `RefundInventoryDispositionService` for idempotent stock movement. Persist stable assignment and batch provenance so retries and UI payloads use database truth; add no new refund subsystem.

**Tech Stack:** Laravel 12, Eloquent/MySQL-compatible migrations, PHPUnit, Inertia React/TypeScript, Vitest/Testing Library, PayMongo integration already in the repository.

---

### Task 1: Persist operation identity, provenance, and sold SKU

**Files:**
- Create: `database/migrations/2026_07_17_000003_harden_failed_delivery_refund_workflow.php`
- Modify: `app/Models/Logistics/DeliveryAttempt.php`
- Modify: `app/Models/Logistics/ShipmentLeg.php`
- Modify: `app/Models/OrderItem.php`
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php`
- Modify: `app/Services/RetailPosPaymentService.php`
- Test: `tests/Feature/Logistics/DeliveryExecutionSchemaTest.php`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`
- Test: `tests/Feature/RetailPosPaymentFlowTest.php`

- [ ] **Step 1: Write failing schema and checkout tests**

Assert that `delivery_attempts` has `attempt_number`, `delivery_assignment_id`, `delivery_batch_id`, and `idempotency_key`; `shipment_legs.return_for_leg_id` is unique; `order_items.product_variant_id` exists; and `(order_refund_id, order_item_id)` rejects duplicates. Add online checkout and POS tests asserting the resolved variant ID is saved on `order_items`. Seed legacy pickup and delivery attempts with tied timestamps and assert the migration backfills deterministic numbers partitioned by `(shipment_leg_id, attempt_type)` and ordered by `attempted_at`, then `id`, so pickup attempts never consume delivery numbering.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Logistics/DeliveryExecutionSchemaTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosPaymentFlowTest.php
```

Expected: FAIL because the new columns/constraints and saved variant ID do not exist.

- [ ] **Step 3: Add the minimal migration and model fields**

Migration behavior:

```php
Schema::table('delivery_attempts', function (Blueprint $table) {
    $table->unsignedSmallInteger('attempt_number')->nullable();
    $table->foreignId('delivery_assignment_id')->nullable()->constrained('delivery_assignments')->nullOnDelete();
    $table->foreignId('delivery_batch_id')->nullable()->constrained('delivery_batches')->nullOnDelete();
    $table->uuid('idempotency_key')->nullable()->unique();
    $table->unique(['shipment_leg_id', 'attempt_type', 'delivery_assignment_id'], 'delivery_attempt_assignment_unique');
});

Schema::table('shipment_legs', fn (Blueprint $table) =>
    $table->unique('return_for_leg_id', 'shipment_leg_return_source_unique')
);

Schema::table('order_items', fn (Blueprint $table) =>
    $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete()
);

Schema::table('order_refund_items', fn (Blueprint $table) =>
    $table->unique(['order_refund_id', 'order_item_id'], 'order_refund_item_unique')
);
```

Backfill attempt numbers deterministically per leg and attempt type using `attempted_at`, then `id`. Backfill `order_items.product_variant_id` only where `(product_id, size, color)` resolves to exactly one variant. Add the fields to `$fillable` and relations. Save `$resolvedVariant?->id` in online checkout and `$variant?->id` in POS order item snapshots.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add database/migrations/2026_07_17_000003_harden_failed_delivery_refund_workflow.php app/Models/Logistics/DeliveryAttempt.php app/Models/Logistics/ShipmentLeg.php app/Models/OrderItem.php app/Http/Controllers/UserSide/CheckoutController.php app/Services/RetailPosPaymentService.php tests/Feature/Logistics/DeliveryExecutionSchemaTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosPaymentFlowTest.php
git commit -m "feat: persist failed delivery workflow identity"
```

### Task 2: Serialize online refund reservations and bootstrap the failed-delivery refund

**Files:**
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Create: `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`

- [ ] **Step 1: Write failing reservation and refund bootstrap tests**

Cover:

```php
public function test_max_attempt_refund_reserves_remaining_capture_once(): void
{
    // Paid PayMongo order with shipping, no active refund.
    // Expect one request_approval refund with owner bypass, Finance pending,
    // pending_staff_pickup, full remaining capture, and one line per order item.
}

public function test_active_refund_blocks_a_competing_failed_delivery_reservation(): void
{
    // Existing requested/pending_approval/processing refund reserves money.
    // Expect no second refund and an actionable collision result.
}
```

Also test that repeated recovery of the same idempotency key reconciles missing lines without duplicates.
Test succeeded-refund subtraction, every active reservation status, and `succeeded + reserved <= captured amount`. Add competing reservation requests using separate database connections/barrier synchronization where supported; always assert the database constraint/locked recovery path converges to one reservation.
Add dedicated RED cases proving `autoRefundOnCancellation()` is blocked by an active reservation, subtracts succeeded refunds, and never makes total succeeded plus reserved exceed the capture.

- [ ] **Step 2: Run tests and verify RED**

```powershell
php artisan test tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php
```

Expected: FAIL because there is no shared reservation transaction or failed-delivery bootstrap.

- [ ] **Step 3: Add one shared order-level reservation transaction**

In `OrderRefundService`, add the smallest shared method used by customer refund creation and failed-delivery creation:

```php
return DB::transaction(function () use ($order, $payload, $lines) {
    $order = Order::query()->lockForUpdate()->findOrFail($order->id);
    $reserved = OrderRefund::where('order_id', $order->id)
        ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
        ->lockForUpdate()->sum('amount');
    // Reject collisions/over-reservation, create or recover by stable idempotency key,
    // then upsert and reconcile the complete unique item-line set.
});
```

Failed-delivery values are fixed: `reason_code = delivery_attempts_exhausted`, stable idempotency key, owner approved with null actor plus audit note, Finance pending, shop-owned staff return, and remaining captured amount including shipping. Inventory lines use `line_amount = 0` and exact sold quantities/SKUs.

Move the existing `OrderController` request creation and `OrderRefundService::autoRefundOnCancellation()` into this shared locked path without changing their customer-facing validation or compatibility fallback.

- [ ] **Step 4: Run tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Services/OrderRefundService.php app/Http/Controllers/UserSide/OrderController.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php
git commit -m "feat: reserve failed delivery refunds safely"
```

### Task 3: Make failed attempts idempotent and preserve rider custody

**Files:**
- Modify: `app/Http/Controllers/Api/Logistics/ShipmentController.php`
- Modify: `app/Services/Logistics/ShipmentLegService.php`
- Modify: `app/Services/Logistics/AssignmentService.php`
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `tests/Feature/Logistics/DeliveryExecutionTest.php`
- Test: `tests/Feature/Logistics/LogisticsApiTest.php`
- Test: `tests/Feature/Logistics/LogisticsConcurrencyTest.php`

- [ ] **Step 1: Write failing attempt transition tests**

Test four behaviors separately:

1. First failure records assignment/batch/attempt number, closes the old assignment with `status = cancelled` and non-null `cancelled_at`, schedules retry, and permits a new assignment.
2. Reposting the same `delivery_assignment_id` returns the existing attempt without incrementing or changing state.
3. The exact configured maximum creates one accepted return assignment for the custody rider before closing the delivery assignment.
4. The maximum starts one failed-delivery refund; a non-retail leg retains manual `needs_resolution` behavior.
5. An unpaid, COD, or otherwise non-PayMongo retail order never creates an `OrderRefund`; it remains in manual resolution.

Add permission and cross-tenant cases: only the assigned rider can report the attempt, only an owning-shop dispatcher can reassign, and neither role can create a refund for another tenant. For generic `/attempts`, explicitly reject unauthorized staff, unassigned riders, cross-tenant actors, and `shop_owner`-guard actors that lack the explicit Logistics permission; do not preserve the current guard bypass. Assert `AssignmentService` rejects `needs_resolution`, `delivered`, and `cancelled` even when called outside the UI.

Add duplicate-key/race coverage for simultaneous attempt posts, singleton return-leg creation, and refund bootstrap. Exercise the unique-constraint catch path and assert callers receive the already-created attempt/return/refund instead of a 500.

- [ ] **Step 2: Run tests and verify RED**

```powershell
php artisan test tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php
```

Expected: FAIL on stale assignments, missing operation identity, and missing return/refund transition.

- [ ] **Step 3: Implement the locked shared transition**

Require `delivery_assignment_id` on rider issue reports. Require a stable UUID `idempotency_key` on the generic `/attempts` endpoint and recover an existing row before transition validation. In `recordFailedAttempt()`:

```php
$existing = DeliveryAttempt::where('shipment_leg_id', $leg->id)
    ->where('attempt_type', 'delivery')
    ->where('delivery_assignment_id', $assignmentId)
    ->first();
if ($existing) return $existing;
```

Then lock the exact assignment and leg; validate ownership/status; use persisted failed-attempt count against `max_delivery_attempts`; record originating batch; below max close assignment and return to scheduled `pending`; at max set `return_required`, create/recover the singleton return leg and accepted custody assignment, close the delivery assignment, and call the Task 2 refund bootstrap only for paid retail orders. Preserve batch count/cancellation updates already in the service.

In `AssignmentService`, permit assignment only for retryable leg states and explicitly reject `needs_resolution` so controller, batch, and future callers share one guard.

Pass the active assignment ID from the rider UI form. Do not add a separate client UUID.

- [ ] **Step 4: Run tests and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Api/Logistics/ShipmentController.php app/Services/Logistics/ShipmentLegService.php app/Services/Logistics/AssignmentService.php resources/js/Pages/ERP/Logistics/Shipments.tsx tests/Feature/Logistics/DeliveryExecutionTest.php tests/Feature/Logistics/LogisticsApiTest.php tests/Feature/Logistics/LogisticsConcurrencyTest.php
git commit -m "fix: make failed delivery transitions actionable"
```

### Task 4: Gate receipt, inventory, and Finance payout atomically

**Files:**
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Http/Controllers/Api/StaffOrderController.php`
- Modify: `app/Http/Controllers/Api/RefundApprovalController.php`
- Modify: `resources/js/Pages/ERP/STAFF/JobOrders.tsx`
- Create: `resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-refund.test.tsx`
- Test: `tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php`
- Test: `tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php`

- [ ] **Step 1: Write failing receipt and payout tests**

Assert that receipt fails for an incomplete return leg, missing/duplicate/foreign lines, incomplete dispositions, quantity mismatch, or unresolved variant. Assert that a complete resellable line increments product and variant stock exactly once, a damaged line writes off, and `return_status` changes to `received` only after all actions succeed. Assert Finance approval/execution for `delivery_attempts_exhausted` rejects `in_transit` and accepts `received`.

Add controller tests proving owning-shop Staff Job Orders permission is required, cross-tenant receipt is forbidden, Finance excludes the request before receipt and includes it after receipt, and only the owning-shop Finance actor can approve/execute. Assert the fixed bypass note/status survives approval and the amount policy cannot change Finance to `approved_initial`.

- [ ] **Step 2: Run tests and verify RED**

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php
npx vitest run resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-refund.test.tsx
```

Expected: FAIL because receipt currently accepts partial input and payout permits `in_transit`.

- [ ] **Step 3: Implement the atomic receipt contract**

For the failed-delivery reason only, lock the refund, every refund line, the linked return leg, and its approved receive proof in one transaction. Validate the submitted IDs exactly match all refund lines and each approved quantity. Apply every line with `RefundInventoryDispositionService::applyOrderLine()` without catching exceptions; only then update `return_status = received`.

Make `line_dispositions` required for this flow in `StaffOrderController`, and make Job Orders render one required native select per line. Keep current behavior for unrelated legacy refund flows.

In `approveRequestedRefund()` and `executeApprovedRefund()`, require `received` for `delivery_attempts_exhausted`. Explicitly bypass amount-based Shop Owner policy for this reason so Finance approval becomes final, while retaining the system bypass audit note. Filter Finance's actionable list so these requests appear only after receipt.

Write a frontend test first for the Staff form: every refund line must render a required `Resellable`/`Damaged` select and confirmation stays disabled until all lines are classified.

- [ ] **Step 4: Run tests and verify GREEN**

Run both Step 2 commands. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Services/OrderRefundService.php app/Http/Controllers/Api/StaffOrderController.php app/Http/Controllers/Api/RefundApprovalController.php resources/js/Pages/ERP/STAFF/JobOrders.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-refund.test.tsx tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/Logistics/FailedDeliveryRefundWorkflowTest.php
git commit -m "feat: gate refunds on inspected returns"
```

### Task 5: Add failed-attempt filter and payload truth

**Files:**
- Modify: `app/Http/Controllers/Logistics/ErpLogisticsController.php`
- Modify: `resources/js/types/logistics.ts`
- Test: `tests/Feature/Logistics/LogisticsPageAccessTest.php`

- [ ] **Step 1: Write failing page payload tests**

Request `/erp/logistics/shipments?status=failed_attempts` and assert only shipments with failed delivery attempts are returned. Assert each relevant leg includes latest attempt, actual failed count, configured max, refund/resolution state, and assignable state. Assert batch pool/current stop payloads include the same attempt data and originating batch ID.

Assert a maxed-out stop is excluded from the server-provided batch pool; this belongs here, not in a component-only test.

- [ ] **Step 2: Run test and verify RED**

```powershell
php artisan test tests/Feature/Logistics/LogisticsPageAccessTest.php
```

Expected: FAIL because the dispatcher filter and batch eager loads do not exist.

- [ ] **Step 3: Add query filter and eager loads**

Add `failed_attempts` to the dispatcher status allowlist and filter with `whereHas('legs.attempts', ...)`. Reuse one constrained attempts relation/eager-load shape for shipments, pool, unscheduled, and batch legs. Expose `maxDeliveryAttempts` once as an Inertia prop and add typed attempt fields; do not calculate counts in React.

- [ ] **Step 4: Run test and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Logistics/ErpLogisticsController.php resources/js/types/logistics.ts tests/Feature/Logistics/LogisticsPageAccessTest.php
git commit -m "feat: expose failed delivery operations"
```

### Task 6: Add dispatcher failed-attempt UX and reassignment

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [ ] **Step 1: Write failing UI tests**

Assert the dispatcher status select has `Failed attempts`, retryable failures show `Failed attempt - 1/2` plus reason and rider assignment controls, and maxed-out failures show `Subject for refund` with no reassignment control.

- [ ] **Step 2: Run test and verify RED**

```powershell
npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
```

Expected: FAIL because the option/counter/state-specific controls are missing.

- [ ] **Step 3: Render the minimal state-aware UI**

Add the filter option, one shared attempt badge block, and derive reassignment from backend state (`pending`, no active assignment, below max). Reuse the existing rider selector and assign endpoint. Maxed-out legs render the refund/return state only.

- [ ] **Step 4: Run test and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add resources/js/Pages/ERP/Logistics/Shipments.tsx resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx
git commit -m "feat: manage failed deliveries from logistics"
```

### Task 7: Show failed attempts on batch stops

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write failing batch badge tests**

Render a reassigned pool/current batch stop with a prior attempt and assert `Failed attempt - 1/2` and the reason are visible.

- [ ] **Step 2: Run test and verify RED**

```powershell
npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL because `BatchStopRow` does not render attempt data.

- [ ] **Step 3: Add the badge to the existing row**

Render the same backend-derived attempt count/max/reason used by Shipments. Add no new modal or batch state.

- [ ] **Step 4: Run test and verify GREEN**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add resources/js/Pages/ERP/Logistics/components/BatchStopRow.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: identify failed attempts in batches"
```

### Task 8: Run the release gate and update the branch

**Files:**
- Modify only files required by failures caused by this feature.

- [ ] **Step 1: Run backend regression suites**

```powershell
php artisan test tests/Feature/Logistics tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php
```

Expected: all tests pass; Logistics count must not fall below the current `155 tests / 634 assertions` baseline.

- [ ] **Step 2: Run frontend Logistics tests**

```powershell
npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/STAFF/__tests__/JobOrders.return-refund.test.tsx
```

Expected: PASS.

- [ ] **Step 3: Run production build**

```powershell
npm run build
```

Expected: Vite exits `0`. Do not stage generated `public/build` files.

- [ ] **Step 4: Review the exact branch diff**

```powershell
git diff --check
git status --short
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
```

Expected: no unrelated source files or unexpected deletions; existing generated build/cache changes remain unstaged.

- [ ] **Step 5: Commit any verification-only correction, then push**

```powershell
git push origin fix/logistics-delivery-workflow
```

Expected: remote feature branch advances without pushing directly to `solespace-b`.
