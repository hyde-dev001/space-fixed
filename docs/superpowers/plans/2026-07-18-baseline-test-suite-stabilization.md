# Baseline Test Suite Stabilization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the five confirmed baseline unit failures while preserving current notification and overdue-job behavior and making inventory deductions consistent.

**Architecture:** Keep production changes limited to the inventory trust boundary. Isolate low-stock alert tests from queued notification listeners, align overdue-job tests with the current shop-scoped event contract, and reject invalid stock deductions before mutation.

**Tech Stack:** Laravel 11, PHPUnit, Eloquent, Spatie Permission events/listeners

---

### Task 1: Isolate low-stock alert creation tests

**Files:**
- Modify: `tests/Unit/CheckLowStockJobTest.php:12-79`

- [ ] **Step 1: Confirm the existing RED failure**

Run: `php artisan test tests/Unit/CheckLowStockJobTest.php`

Expected: two failures with `PermissionDoesNotExist` for `inventory.view` because synchronous listeners run during alert-creation tests.

- [ ] **Step 2: Fake only the notification-triggering events**

Add imports and a setup method:

```php
use App\Events\LowStockAlert;
use App\Events\OutOfStockAlert;
use Illuminate\Support\Facades\Event;

protected function setUp(): void
{
    parent::setUp();
    Event::fake([LowStockAlert::class, OutOfStockAlert::class]);
}
```

Do not seed permissions or change production listeners; recipient permissions are outside this test's responsibility.

- [ ] **Step 3: Verify GREEN**

Run: `php artisan test tests/Unit/CheckLowStockJobTest.php`

Expected: 3 tests pass.

- [ ] **Step 4: Commit**

```powershell
git add tests/Unit/CheckLowStockJobTest.php
git commit -m "test: isolate low stock alert checks"
```

### Task 2: Align overdue-order tests with the scoped event contract

**Files:**
- Modify: `tests/Unit/CheckOverdueOrdersJobTest.php:13-63`

- [ ] **Step 1: Confirm the existing RED failures**

Run: `php artisan test tests/Unit/CheckOverdueOrdersJobTest.php`

Expected: constructor argument failure and invalid `pending` enum fixture.

- [ ] **Step 2: Update the overdue event test**

Import `SupplierOrderOverdue` and `Event`, fake the event, explicitly set `shop_owner_id`, and invoke the scoped job:

```php
Event::fake([SupplierOrderOverdue::class]);

$overdueOrder = SupplierOrder::factory()->create([
    'shop_owner_id' => $shopOwner->id,
    'supplier_id' => $supplier->id,
    'created_by' => $user->id,
    'status' => 'confirmed',
    'expected_delivery_date' => now()->subDays(3),
]);

$onTimeOrder = SupplierOrder::factory()->create([
    'shop_owner_id' => $shopOwner->id,
    'supplier_id' => $supplier->id,
    'created_by' => $user->id,
    'status' => 'confirmed',
    'expected_delivery_date' => now()->addDays(3),
]);

(new CheckOverdueOrdersJob($shopOwner->id))->handle();

Event::assertDispatched(
    SupplierOrderOverdue::class,
    fn (SupplierOrderOverdue $event) => $event->supplierOrder->is($overdueOrder),
);
Event::assertDispatchedTimes(SupplierOrderOverdue::class, 1);
$this->assertSame('confirmed', $onTimeOrder->fresh()->status);
```

- [ ] **Step 3: Update the excluded-status test**

Call `Event::fake([SupplierOrderOverdue::class])`, use valid status `draft`, explicitly set `shop_owner_id`, invoke `new CheckOverdueOrdersJob($shopOwner->id)`, and assert `SupplierOrderOverdue` was not dispatched.

- [ ] **Step 4: Verify GREEN**

Run: `php artisan test tests/Unit/CheckOverdueOrdersJobTest.php`

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```powershell
git add tests/Unit/CheckOverdueOrdersJobTest.php
git commit -m "test: align overdue order job contract"
```

### Task 3: Reject invalid stock deductions

**Files:**
- Modify: `tests/Unit/InventoryItemTest.php:95-108`
- Modify: `app/Models/InventoryItem.php:224-242`

- [ ] **Step 1: Strengthen the failing over-deduction test**

Replace the broad expected exception with an explicit contract and unchanged-state assertions:

```php
try {
    $item->decrementStock(10, 'stock_out', 'Sold', $this->user->id);
    $this->fail('Expected over-deduction to be rejected.');
} catch (\InvalidArgumentException) {
    $this->assertSame(5, $item->fresh()->available_quantity);
    $this->assertSame(0, $item->stockMovements()->count());
}
```

- [ ] **Step 2: Add zero and negative quantity tests**

Add one test that loops through `[0, -1]`, creates a fresh item for each value, catches each exception independently, and asserts unchanged state:

```php
public function test_it_rejects_non_positive_stock_deductions(): void
{
    foreach ([0, -1] as $quantity) {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 5,
        ]);

        try {
            $item->decrementStock($quantity, 'stock_out', 'Invalid deduction', $this->user->id);
            $this->fail("Expected quantity {$quantity} to be rejected.");
        } catch (\InvalidArgumentException) {
            $this->assertSame(5, $item->fresh()->available_quantity);
            $this->assertSame(0, $item->stockMovements()->count());
        }
    }
}
```

- [ ] **Step 3: Verify RED**

Run: `php artisan test tests/Unit/InventoryItemTest.php --filter="cannot_decrement_below_zero|rejects_non_positive"`

Expected: failures because the model currently clamps over-deductions and accepts non-positive values.

- [ ] **Step 4: Add the minimum validation**

At the start of `InventoryItem::decrementStock()`:

```php
if ($quantity <= 0) {
    throw new \InvalidArgumentException('Stock deduction quantity must be greater than zero.');
}

if ($quantity > $this->available_quantity) {
    throw new \InvalidArgumentException('Stock deduction exceeds available quantity.');
}
```

Keep the existing valid-deduction and movement logic unchanged.

- [ ] **Step 5: Verify GREEN**

Run: `php artisan test tests/Unit/InventoryItemTest.php`

Expected: all inventory-item tests pass.

- [ ] **Step 6: Commit**

```powershell
git add app/Models/InventoryItem.php tests/Unit/InventoryItemTest.php
git commit -m "fix: reject invalid inventory deductions"
```

### Task 4: Verify the stabilized baseline

**Files:**
- No expected code changes

- [ ] **Step 1: Run the three repaired files together**

Run: `php artisan test tests/Unit/CheckLowStockJobTest.php tests/Unit/CheckOverdueOrdersJobTest.php tests/Unit/InventoryItemTest.php`

Expected: 18 tests pass with no failures.

- [ ] **Step 2: Run the full PHP suite**

Run: `php artisan test`

Expected: the five confirmed failures are gone. If another deterministic failure appears, stop and apply systematic debugging before changing code.

- [ ] **Step 3: Review the diff**

Run `git diff --check` and inspect the four modified files. Expected: no unrelated production or generated-asset changes.
