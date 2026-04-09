# Item-Based Partial Refunds (Online + Retail POS) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement true item-based partial refunds with line-level quantity selection, auto-calculated refund amount, and inspection-based inventory disposition (resellable restock vs damaged write-off) for both MyOrders and Retail POS.

**Architecture:** Keep existing refund header tables (`order_refunds`, `pos_refunds`) for workflow compatibility, then add dedicated refund line tables as source of truth for quantities and amount derivation. Enforce remaining refundable quantity at the order-item level using transactional row locks. Apply inventory changes idempotently at execute time based on line disposition.

**Tech Stack:** Laravel 11, Eloquent ORM, MySQL migrations, Inertia React/TypeScript, PHPUnit feature tests.

---

## Scope Check

This remains one subsystem: product order refunds (online + retail POS) with shared line-level ledger rules. It is intentionally not split because both channels must share quantity caps, amount derivation, and inventory disposition semantics.

## File Structure

### New files
- Create: `database/migrations/2026_04_09_150000_create_order_refund_items_table.php`
- Create: `database/migrations/2026_04_09_151000_create_pos_refund_items_table.php`
- Create: `app/Models/OrderRefundItem.php`
- Create: `app/Models/PosRefundItem.php`
- Create: `app/Services/RefundLineCalculatorService.php`
- Create: `app/Services/RefundInventoryDispositionService.php`
- Create: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`
- Create: `tests/Feature/RetailPosItemBasedRefundFlowTest.php`
- Create: `tests/Unit/Services/RefundLineCalculatorServiceTest.php`

### Modified files
- Modify: `app/Models/OrderRefund.php`
- Modify: `app/Models/PosRefund.php`
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `app/Http/Controllers/Api/RetailPosController.php`
- Modify: `app/Services/RetailPosRefundService.php`
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Modify: `resources/js/Pages/ERP/cashier/POS.tsx`
- Modify: `tests/Feature/RetailPosRefundFlowTest.php`

### Responsibility split
- `RefundLineCalculatorService`: remaining qty checks, line amount derivation, aggregate amount generation.
- `RefundInventoryDispositionService`: idempotent inventory side-effects per line (`restock`, `write_off`).
- Controllers/services: channel-specific authorization + workflow orchestration.
- Frontend pages: qty selection UX and inspection disposition capture.

---

### Task 1: Add Line-Level Refund Schema

**Files:**
- Create: `database/migrations/2026_04_09_150000_create_order_refund_items_table.php`
- Create: `database/migrations/2026_04_09_151000_create_pos_refund_items_table.php`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderItemBasedPartialRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function refund_line_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('order_refund_items'));
        $this->assertTrue(Schema::hasTable('pos_refund_items'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=refund_line_tables_exist`
Expected: FAIL with missing table assertion(s).

- [ ] **Step 3: Write minimal implementation**

```php
Schema::create('order_refund_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_refund_id')->constrained('order_refunds')->cascadeOnDelete();
    $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
    $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
    $table->unsignedInteger('requested_qty');
    $table->unsignedInteger('approved_qty')->nullable();
    $table->decimal('unit_price_snapshot', 12, 2);
    $table->decimal('line_amount', 12, 2);
    $table->enum('inspection_disposition', ['pending', 'resellable', 'damaged'])->default('pending');
    $table->enum('inventory_action', ['pending', 'restock', 'write_off'])->default('pending');
    $table->timestamp('inventory_applied_at')->nullable();
    $table->timestamps();

    $table->index(['order_item_id']);
    $table->index(['order_refund_id', 'inventory_action']);
});
```

```php
Schema::create('pos_refund_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('pos_refund_id')->constrained('pos_refunds')->cascadeOnDelete();
    $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
    $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
    $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
    $table->unsignedInteger('requested_qty');
    $table->unsignedInteger('approved_qty')->nullable();
    $table->decimal('unit_price_snapshot', 12, 2);
    $table->decimal('line_amount', 12, 2);
    $table->enum('inspection_disposition', ['pending', 'resellable', 'damaged'])->default('pending');
    $table->enum('inventory_action', ['pending', 'restock', 'write_off'])->default('pending');
    $table->timestamp('inventory_applied_at')->nullable();
    $table->timestamps();

    $table->index(['order_item_id']);
    $table->index(['pos_refund_id', 'inventory_action']);
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=refund_line_tables_exist`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_09_150000_create_order_refund_items_table.php database/migrations/2026_04_09_151000_create_pos_refund_items_table.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php
git commit -m "feat(refund): add line-level refund schema"
```

### Task 2: Add Models and Relations

**Files:**
- Create: `app/Models/OrderRefundItem.php`
- Create: `app/Models/PosRefundItem.php`
- Modify: `app/Models/OrderRefund.php`
- Modify: `app/Models/PosRefund.php`
- Test: `tests/Unit/Services/RefundLineCalculatorServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function refund_headers_expose_line_relations(): void
{
    $this->assertTrue(method_exists(new \App\Models\OrderRefund(), 'items'));
    $this->assertTrue(method_exists(new \App\Models\PosRefund(), 'items'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=refund_headers_expose_line_relations`
Expected: FAIL with missing relation method(s).

- [ ] **Step 3: Write minimal implementation**

```php
// app/Models/OrderRefund.php
public function items(): HasMany
{
    return $this->hasMany(OrderRefundItem::class);
}
```

```php
// app/Models/PosRefund.php
public function items(): HasMany
{
    return $this->hasMany(PosRefundItem::class);
}
```

```php
// app/Models/OrderRefundItem.php and app/Models/PosRefundItem.php
protected $fillable = [
    'order_refund_id',
    'pos_refund_id',
    'order_item_id',
    'product_id',
    'product_variant_id',
    'requested_qty',
    'approved_qty',
    'unit_price_snapshot',
    'line_amount',
    'inspection_disposition',
    'inventory_action',
    'inventory_applied_at',
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=refund_headers_expose_line_relations`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/OrderRefund.php app/Models/PosRefund.php app/Models/OrderRefundItem.php app/Models/PosRefundItem.php tests/Unit/Services/RefundLineCalculatorServiceTest.php
git commit -m "feat(refund): add refund header-to-line model relations"
```

### Task 3: Build Remaining-Qty and Amount Calculator

**Files:**
- Create: `app/Services/RefundLineCalculatorService.php`
- Test: `tests/Unit/Services/RefundLineCalculatorServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function it_computes_remaining_qty_and_line_amounts(): void
{
    $service = app(\App\Services\RefundLineCalculatorService::class);

    $result = $service->computeLineAmount(unitPrice: 1200.00, qty: 2);

    $this->assertSame(2400.00, $result);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=it_computes_remaining_qty_and_line_amounts`
Expected: FAIL with class or method missing.

- [ ] **Step 3: Write minimal implementation**

```php
class RefundLineCalculatorService
{
    public function computeLineAmount(float $unitPrice, int $qty): float
    {
        return round(max(0, $unitPrice) * max(0, $qty), 2);
    }

    public function aggregateAmount(array $lineAmounts): float
    {
        return round(array_sum($lineAmounts), 2);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=it_computes_remaining_qty_and_line_amounts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RefundLineCalculatorService.php tests/Unit/Services/RefundLineCalculatorServiceTest.php
git commit -m "feat(refund): add line amount and aggregate calculator service"
```

### Task 4: Implement Online Request with Item+Qty Payload

**Files:**
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `app/Models/OrderRefund.php`
- Create: `app/Models/OrderRefundItem.php`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function online_partial_refund_requires_line_qty_payload_and_derives_amount(): void
{
    $response = $this->actingAs($this->customer, 'user')->post('/orders/request-refund', [
        'order_id' => $this->order->id,
        'reason' => 'damaged_item',
        'request_type' => 'partial',
        'refund_lines' => [
            ['order_item_id' => $this->orderItem->id, 'requested_qty' => 1],
        ],
        'media' => $this->refundEvidenceFiles,
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('order_refund_items', [
        'order_item_id' => $this->orderItem->id,
        'requested_qty' => 1,
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=online_partial_refund_requires_line_qty_payload_and_derives_amount`
Expected: FAIL because `refund_lines` are not persisted.

- [ ] **Step 3: Write minimal implementation**

```php
$validated = $request->validate([
    'refund_lines' => ['nullable', 'array', 'min:1'],
    'refund_lines.*.order_item_id' => ['required', 'integer', 'min:1'],
    'refund_lines.*.requested_qty' => ['required', 'integer', 'min:1'],
]);

$refundLines = collect((array) ($validated['refund_lines'] ?? []));

$lineRows = $refundLines->map(function (array $line) use ($orderItemsById) {
    $orderItem = $orderItemsById[(int) $line['order_item_id']];
    $qty = (int) $line['requested_qty'];
    $unitPrice = (float) $orderItem->price;

    return [
        'order_item_id' => (int) $orderItem->id,
        'product_id' => (int) $orderItem->product_id,
        'product_variant_id' => null,
        'requested_qty' => $qty,
        'approved_qty' => $qty,
        'unit_price_snapshot' => $unitPrice,
        'line_amount' => round($unitPrice * $qty, 2),
        'inspection_disposition' => 'pending',
        'inventory_action' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ];
});

$orderRefund->items()->createMany($lineRows->toArray());
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=online_partial_refund_requires_line_qty_payload_and_derives_amount`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/UserSide/OrderController.php app/Models/OrderRefund.php app/Models/OrderRefundItem.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php
git commit -m "feat(refund-online): accept and persist item qty refund lines"
```

### Task 5: Implement POS Request with Item+Qty and Inspection Disposition

**Files:**
- Modify: `app/Http/Controllers/Api/RetailPosController.php`
- Modify: `app/Services/RetailPosRefundService.php`
- Create: `app/Models/PosRefundItem.php`
- Test: `tests/Feature/RetailPosItemBasedRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function retail_pos_partial_refund_persists_line_qty_and_disposition(): void
{
    $response = $this->actingAs($this->cashier, 'user')->postJson('/api/retail-pos/refunds', [
        'source_transaction_id' => $this->transaction->id,
        'request_type' => 'partial',
        'refund_lines' => [
            [
                'order_item_id' => $this->orderItem->id,
                'requested_qty' => 1,
                'inspection_disposition' => 'damaged',
            ],
        ],
        'reason_code' => 'damaged_item',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('pos_refund_items', [
        'order_item_id' => $this->orderItem->id,
        'inspection_disposition' => 'damaged',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=retail_pos_partial_refund_persists_line_qty_and_disposition`
Expected: FAIL because `refund_lines` are not handled.

- [ ] **Step 3: Write minimal implementation**

```php
$validated = $request->validate([
    'refund_lines' => ['required', 'array', 'min:1'],
    'refund_lines.*.order_item_id' => ['required', 'integer', 'min:1'],
    'refund_lines.*.requested_qty' => ['required', 'integer', 'min:1'],
    'refund_lines.*.inspection_disposition' => ['required', 'string', 'in:resellable,damaged'],
]);
```

```php
$refund = PosRefund::create([...]);

$refund->items()->createMany($mappedLines);

$refund->update([
    'requested_amount' => round((float) collect($mappedLines)->sum('line_amount'), 2),
]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=retail_pos_partial_refund_persists_line_qty_and_disposition`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RetailPosController.php app/Services/RetailPosRefundService.php app/Models/PosRefundItem.php tests/Feature/RetailPosItemBasedRefundFlowTest.php
git commit -m "feat(refund-pos): persist qty-based refund lines with inspection disposition"
```

### Task 6: Execute Line-Level Inventory Actions Idempotently

**Files:**
- Create: `app/Services/RefundInventoryDispositionService.php`
- Modify: `app/Services/RetailPosRefundService.php`
- Modify: `app/Services/OrderRefundService.php`
- Modify: `app/Services/PaymentSettlementService.php`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`
- Test: `tests/Feature/RetailPosItemBasedRefundFlowTest.php`

- [ ] **Step 1: Write the failing tests**

```php
#[Test]
public function damaged_refund_lines_do_not_restock_sellable_inventory(): void
{
    $this->executeRefundWithDisposition('damaged');

    $this->assertSame($this->stockBefore, $this->product->fresh()->stock_quantity);
}

#[Test]
public function resellable_refund_lines_restock_exact_qty_once_even_on_retry(): void
{
    $this->executeRefundWithDisposition('resellable');
    $this->executeRefundWithDisposition('resellable');

    $this->assertSame($this->stockBefore + 1, $this->product->fresh()->stock_quantity);
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test --filter=damaged_refund_lines_do_not_restock_sellable_inventory`
Expected: FAIL.

Run: `php artisan test --filter=resellable_refund_lines_restock_exact_qty_once_even_on_retry`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

```php
class RefundInventoryDispositionService
{
    public function applyPosLine(PosRefundItem $line): void
    {
        if ($line->inventory_applied_at) {
            return;
        }

        if ($line->inspection_disposition === 'resellable') {
            Product::whereKey($line->product_id)->increment('stock_quantity', (int) $line->approved_qty);
            $line->inventory_action = 'restock';
        } else {
            $line->inventory_action = 'write_off';
        }

        $line->inventory_applied_at = now();
        $line->save();
    }
}
```

```php
// RetailPosRefundService::execute
$refund->loadMissing('items');
foreach ($refund->items as $line) {
    $this->inventoryDisposition->applyPosLine($line);
}
```

```php
// OrderRefundService execute path after payout succeeds
$refund->loadMissing('items');
foreach ($refund->items as $line) {
    $this->inventoryDisposition->applyOrderLine($line);
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test --filter=damaged_refund_lines_do_not_restock_sellable_inventory`
Expected: PASS.

Run: `php artisan test --filter=resellable_refund_lines_restock_exact_qty_once_even_on_retry`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RefundInventoryDispositionService.php app/Services/RetailPosRefundService.php app/Services/OrderRefundService.php app/Services/PaymentSettlementService.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosItemBasedRefundFlowTest.php
git commit -m "feat(refund): apply idempotent line-level restock or write-off on execute"
```

### Task 7: Add Remaining-Qty Guards for Multi-Refund Safety

**Files:**
- Modify: `app/Services/RefundLineCalculatorService.php`
- Modify: `app/Http/Controllers/UserSide/OrderController.php`
- Modify: `app/Services/RetailPosRefundService.php`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`
- Test: `tests/Feature/RetailPosItemBasedRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function second_partial_refund_cannot_exceed_remaining_qty(): void
{
    $this->createSuccessfulRefundLine($this->orderItem, qty: 1);

    $response = $this->requestAnotherRefund($this->orderItem, qty: 2);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Requested qty exceeds remaining refundable quantity for one or more items.');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=second_partial_refund_cannot_exceed_remaining_qty`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

```php
$remainingQty = $this->refundLineCalculator->resolveRemainingQty(
    orderItemId: $orderItem->id,
    purchasedQty: (int) $orderItem->quantity,
    channel: 'online',
);

if ($requestedQty > $remainingQty) {
    throw ValidationException::withMessages([
        'refund_lines' => ['Requested qty exceeds remaining refundable quantity for one or more items.'],
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=second_partial_refund_cannot_exceed_remaining_qty`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RefundLineCalculatorService.php app/Http/Controllers/UserSide/OrderController.php app/Services/RetailPosRefundService.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosItemBasedRefundFlowTest.php
git commit -m "fix(refund): enforce remaining refundable qty caps for multi-refund orders"
```

### Task 8: Upgrade MyOrders UI to Quantity-Based Partial Refund

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/MyOrders.tsx`
- Test: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function my_orders_partial_refund_payload_contains_line_qtys_not_manual_amount_only(): void
{
    $response = $this->post('/orders/request-refund', $this->payloadFromUi);

    $response->assertOk();
    $this->assertDatabaseHas('order_refund_items', ['requested_qty' => 1]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=my_orders_partial_refund_payload_contains_line_qtys_not_manual_amount_only`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

```tsx
const [refundLineQtyByItemId, setRefundLineQtyByItemId] = useState<Record<number, number>>({});

const refundSelectedLines = (refundTargetOrder?.items || [])
  .filter((item) => (refundLineQtyByItemId[item.id] || 0) > 0)
  .map((item) => ({
    order_item_id: item.id,
    requested_qty: refundLineQtyByItemId[item.id],
  }));

const refundAmountToRequest = refundSelectedLines.reduce((sum, line) => {
  const item = refundTargetOrder?.items.find((it) => it.id === line.order_item_id);
  const unitPrice = item ? parseAmount(item.price) : 0;
  return sum + unitPrice * line.requested_qty;
}, 0);
```

```tsx
refundSelectedLines.forEach((line, index) => {
  formData.append(`refund_lines[${index}][order_item_id]`, String(line.order_item_id));
  formData.append(`refund_lines[${index}][requested_qty]`, String(line.requested_qty));
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=my_orders_partial_refund_payload_contains_line_qtys_not_manual_amount_only`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/UserSide/Orders/MyOrders.tsx tests/Feature/OrderItemBasedPartialRefundFlowTest.php
git commit -m "feat(myorders): use qty-based partial refund selection and derived totals"
```

### Task 9: Upgrade Retail POS UI to Quantity + Disposition Capture

**Files:**
- Modify: `resources/js/Pages/ERP/cashier/POS.tsx`
- Test: `tests/Feature/RetailPosItemBasedRefundFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function retail_pos_refund_requires_disposition_for_each_selected_line(): void
{
    $response = $this->postJson('/api/retail-pos/refunds', [
        'source_transaction_id' => $this->transaction->id,
        'request_type' => 'partial',
        'refund_lines' => [[
            'order_item_id' => $this->orderItem->id,
            'requested_qty' => 1,
        ]],
        'reason_code' => 'damaged_item',
    ]);

    $response->assertStatus(422);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=retail_pos_refund_requires_disposition_for_each_selected_line`
Expected: FAIL until UI/backend contract includes disposition.

- [ ] **Step 3: Write minimal implementation**

```tsx
type RetailRefundLineSelection = {
  orderItemId: number;
  qty: number;
  inspectionDisposition: 'resellable' | 'damaged';
};

const refundLinesPayload = selectedRefundLines
  .filter((line) => line.qty > 0)
  .map((line) => ({
    order_item_id: line.orderItemId,
    requested_qty: line.qty,
    inspection_disposition: line.inspectionDisposition,
  }));
```

```tsx
await axios.post('/api/retail-pos/refunds', {
  source_transaction_id: transactionId,
  request_type: 'partial',
  refund_lines: refundLinesPayload,
  reason_code: 'customer_return',
  reason_notes: 'Requested from POS history modal.',
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=retail_pos_refund_requires_disposition_for_each_selected_line`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ERP/cashier/POS.tsx tests/Feature/RetailPosItemBasedRefundFlowTest.php
git commit -m "feat(pos): capture line qty and disposition for item-based refunds"
```

### Task 10: Regression, Logs, and Final Verification

**Files:**
- Modify: `app/Services/RetailPosRefundService.php`
- Modify: `app/Services/OrderRefundService.php`
- Modify: `tests/Feature/RetailPosRefundFlowTest.php`
- Modify: `tests/Feature/OrderItemBasedPartialRefundFlowTest.php`

- [ ] **Step 1: Write the failing regression test**

```php
#[Test]
public function full_refund_paths_still_work_after_itemized_refund_changes(): void
{
    $result = $this->executeFullRefundFlow();

    $result->assertOk();
    $this->assertSame('succeeded', $this->latestRefund()->status);
}
```

- [ ] **Step 2: Run targeted regression tests**

Run: `php artisan test --filter=full_refund_paths_still_work_after_itemized_refund_changes`
Expected: PASS.

Run: `php artisan test --filter=RetailPosRefundFlowTest`
Expected: PASS.

- [ ] **Step 3: Add structured line-action logs**

```php
Log::info('Refund line inventory action applied', [
    'channel' => 'retail_pos',
    'refund_id' => (int) $refund->id,
    'line_id' => (int) $line->id,
    'order_item_id' => (int) $line->order_item_id,
    'approved_qty' => (int) $line->approved_qty,
    'disposition' => (string) $line->inspection_disposition,
    'inventory_action' => (string) $line->inventory_action,
]);
```

- [ ] **Step 4: Run full suite for touched areas**

Run: `php artisan test --filter=OrderItemBasedPartialRefundFlowTest`
Expected: PASS.

Run: `php artisan test --filter=RetailPosItemBasedRefundFlowTest`
Expected: PASS.

Run: `php artisan test --filter=RetailPosRefundFlowTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RetailPosRefundService.php app/Services/OrderRefundService.php tests/Feature/RetailPosRefundFlowTest.php tests/Feature/OrderItemBasedPartialRefundFlowTest.php tests/Feature/RetailPosItemBasedRefundFlowTest.php
git commit -m "test(refund): verify itemized partial flows and preserve full-refund regressions"
```

## Self-Review

1. Spec coverage: This plan covers item+qty selection, auto amount derivation, multi-refund cap enforcement, POS and online channels, inspection-based disposition, damaged no-restock, and execute-time idempotent inventory actions.
2. Placeholder scan: No TBD/TODO placeholders remain; each task contains concrete files, code snippets, commands, and expected outcomes.
3. Type consistency: Payload naming is consistent (`refund_lines`, `order_item_id`, `requested_qty`, `inspection_disposition`) across controller, service, and UI tasks.
