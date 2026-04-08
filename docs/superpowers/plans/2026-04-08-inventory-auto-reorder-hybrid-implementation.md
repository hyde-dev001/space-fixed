# Inventory Auto-Reorder Hybrid Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement real-time plus reconciliation auto-reorder creation for repair materials and shoes (size+color aware), with dedupe upsert behavior and upload-time threshold validation.

**Architecture:** Add a focused ReorderAutomationService as the single decision engine for threshold evaluation and stock-request upsert. Wire targeted stock deduction paths to call this service after successful mutations, and add a scheduled reconciliation command as safety net. Extend schema for size-level thresholds and request scope keys so dedupe is identity-safe.

**Tech Stack:** Laravel 11, MySQL, Inertia React TS, PHPUnit feature and unit tests, Laravel scheduler and console command

---

## File Structure

### New files
- Create: `app/Services/ReorderAutomationService.php`
- Create: `app/Console/Commands/ReconcileAutoReorderCommand.php`
- Create: `database/migrations/2026_04_08_000100_add_reorder_fields_to_inventory_sizes_table.php`
- Create: `database/migrations/2026_04_08_000110_add_scope_keys_to_stock_request_approvals_table.php`
- Create: `tests/Unit/Services/ReorderAutomationServiceTest.php`
- Create: `tests/Feature/Inventory/AutoReorderCheckoutFeatureTest.php`
- Create: `tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php`
- Create: `tests/Feature/Inventory/AutoReorderReconcileCommandTest.php`

### Modified files
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Modify: `app/Http/Controllers/Erp/StockMovementController.php`
- Modify: `app/Http/Controllers/Erp/ProductInventoryController.php`
- Modify: `app/Http/Controllers/Erp/UploadInventoryController.php`
- Modify: `app/Models/InventorySize.php`
- Modify: `app/Models/StockRequestApproval.php`
- Modify: `routes/console.php`
- Modify: `resources/js/Pages/ERP/inventory/UploadInventory.tsx`
- Modify: `resources/js/components/variants/ColorVariantManager.tsx`
- Modify: `resources/js/services/inventoryAPI.ts`

---

### Task 1: Add Schema for Size Thresholds and Request Scope Keys

**Files:**
- Create: `database/migrations/2026_04_08_000100_add_reorder_fields_to_inventory_sizes_table.php`
- Create: `database/migrations/2026_04_08_000110_add_scope_keys_to_stock_request_approvals_table.php`
- Modify: `app/Models/InventorySize.php`
- Modify: `app/Models/StockRequestApproval.php`
- Test: `tests/Feature/Inventory/AutoReorderReconcileCommandTest.php`

- [ ] **Step 1: Write failing migration-level feature test for required columns**

```php
<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutoReorderReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_auto_reorder_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('inventory_sizes', 'reorder_level'));
        $this->assertTrue(Schema::hasColumn('inventory_sizes', 'reorder_quantity'));
        $this->assertTrue(Schema::hasColumn('stock_request_approvals', 'inventory_size_id'));
        $this->assertTrue(Schema::hasColumn('stock_request_approvals', 'inventory_color_variant_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails before migrations are added**

Run: `php artisan test tests/Feature/Inventory/AutoReorderReconcileCommandTest.php --filter=required_auto_reorder_columns_exist`
Expected: FAIL with missing column assertion

- [ ] **Step 3: Add the two migrations and update casts/fillable in models**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_sizes', function (Blueprint $table) {
            $table->unsignedInteger('reorder_level')->default(1)->after('quantity');
            $table->unsignedInteger('reorder_quantity')->default(1)->after('reorder_level');
            $table->index(['inventory_item_id', 'inventory_color_variant_id', 'reorder_level'], 'inventory_sizes_reorder_scope_idx');
        });

        DB::statement('UPDATE inventory_sizes s
            INNER JOIN inventory_items i ON i.id = s.inventory_item_id
            SET s.reorder_level = GREATEST(1, COALESCE(i.reorder_level, 1)),
                s.reorder_quantity = GREATEST(1, COALESCE(i.reorder_quantity, 1))');
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            $table->foreignId('inventory_size_id')->nullable()->after('inventory_item_id')->constrained('inventory_sizes')->nullOnDelete();
            $table->foreignId('inventory_color_variant_id')->nullable()->after('inventory_size_id')->constrained('inventory_color_variants')->nullOnDelete();
            $table->index([
                'shop_owner_id',
                'inventory_item_id',
                'inventory_size_id',
                'inventory_color_variant_id',
                'request_source',
                'status',
                'is_auto_generated',
            ], 'stock_req_auto_scope_lookup_idx');
        });
    }
};
```

```php
// app/Models/InventorySize.php
protected $fillable = [
    'inventory_item_id',
    'inventory_color_variant_id',
    'size',
    'size_system',
    'quantity',
    'reorder_level',
    'reorder_quantity',
];

protected $casts = [
    'quantity' => 'integer',
    'reorder_level' => 'integer',
    'reorder_quantity' => 'integer',
];
```

```php
// app/Models/StockRequestApproval.php
protected $fillable = [
    // existing fields...
    'inventory_size_id',
    'inventory_color_variant_id',
    'is_auto_generated',
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Inventory/AutoReorderReconcileCommandTest.php --filter=required_auto_reorder_columns_exist`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_08_000100_add_reorder_fields_to_inventory_sizes_table.php database/migrations/2026_04_08_000110_add_scope_keys_to_stock_request_approvals_table.php app/Models/InventorySize.php app/Models/StockRequestApproval.php tests/Feature/Inventory/AutoReorderReconcileCommandTest.php
git commit -m "feat: add size-level reorder schema and stock request scope keys"
```

---

### Task 2: Implement ReorderAutomationService with Dedupe Upsert

**Files:**
- Create: `app/Services/ReorderAutomationService.php`
- Test: `tests/Unit/Services/ReorderAutomationServiceTest.php`

- [ ] **Step 1: Write failing unit tests for shoes and repair upsert behavior**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\User;
use App\Services\ReorderAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReorderAutomationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_manual_auto_request_for_shoes_size_color_scope(): void
    {
        $shop = ShopOwner::factory()->create();
        $actor = User::factory()->create(['shop_owner_id' => $shop->id]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'category' => 'shoes',
            'name' => 'Runner',
            'sku' => 'RUN-01',
        ]);

        $color = InventoryColorVariant::factory()->create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 1,
        ]);

        $size = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 1,
            'reorder_level' => 2,
            'reorder_quantity' => 6,
        ]);

        app(ReorderAutomationService::class)->evaluateShoesSizeThreshold(
            inventoryItemId: $item->id,
            inventorySizeId: $size->id,
            actorUserId: $actor->id,
            source: 'manual'
        );

        $this->assertDatabaseHas('stock_request_approvals', [
            'shop_owner_id' => $shop->id,
            'inventory_item_id' => $item->id,
            'inventory_size_id' => $size->id,
            'inventory_color_variant_id' => $color->id,
            'request_source' => 'manual',
            'is_auto_generated' => true,
            'quantity_needed' => 5,
        ]);
    }

    public function test_updates_existing_pending_auto_request_instead_of_creating_duplicate(): void
    {
        $shop = ShopOwner::factory()->create();
        $actor = User::factory()->create(['shop_owner_id' => $shop->id]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'category' => 'repair_materials',
            'name' => 'Glue',
            'sku' => 'GLU-01',
            'available_quantity' => 1,
            'reorder_level' => 2,
            'reorder_quantity' => 8,
        ]);

        StockRequestApproval::factory()->create([
            'shop_owner_id' => $shop->id,
            'inventory_item_id' => $item->id,
            'request_source' => 'repair',
            'status' => 'pending',
            'is_auto_generated' => true,
            'quantity_needed' => 3,
        ]);

        app(ReorderAutomationService::class)->evaluateRepairMaterialThreshold($item->id, $actor->id);

        $this->assertSame(1, StockRequestApproval::query()
            ->where('shop_owner_id', $shop->id)
            ->where('inventory_item_id', $item->id)
            ->where('request_source', 'repair')
            ->where('is_auto_generated', true)
            ->count());

        $this->assertDatabaseHas('stock_request_approvals', [
            'shop_owner_id' => $shop->id,
            'inventory_item_id' => $item->id,
            'request_source' => 'repair',
            'status' => 'pending',
            'is_auto_generated' => true,
            'quantity_needed' => 7,
        ]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/ReorderAutomationServiceTest.php`
Expected: FAIL with class or method not found

- [ ] **Step 3: Implement minimal service with shared upsert identity logic**

```php
<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\StockRequestApproval;
use Illuminate\Support\Facades\DB;

class ReorderAutomationService
{
    public function evaluateRepairMaterialThreshold(int $inventoryItemId, ?int $actorUserId = null): void
    {
        $item = InventoryItem::query()->find($inventoryItemId);
        if (!$item || $item->category !== 'repair_materials') {
            return;
        }

        $current = (int) $item->available_quantity;
        $reorderLevel = max(1, (int) $item->reorder_level);
        $reorderQuantity = max(1, (int) $item->reorder_quantity);

        if ($current > $reorderLevel) {
            return;
        }

        $needed = max(1, $reorderQuantity - $current);
        $this->upsertAutoRequest(
            shopOwnerId: (int) $item->shop_owner_id,
            inventoryItemId: (int) $item->id,
            inventorySizeId: null,
            inventoryColorVariantId: null,
            requestSource: 'repair',
            productName: (string) $item->name,
            skuCode: (string) ($item->sku ?? ''),
            requestedSize: null,
            requestedColor: null,
            quantityNeeded: $needed,
            actorUserId: $actorUserId
        );
    }

    public function evaluateShoesSizeThreshold(int $inventoryItemId, int $inventorySizeId, ?int $actorUserId = null, string $source = 'manual'): void
    {
        $size = InventorySize::query()->where('inventory_item_id', $inventoryItemId)->find($inventorySizeId);
        if (!$size) {
            return;
        }

        $item = InventoryItem::query()->find($inventoryItemId);
        if (!$item || $item->category !== 'shoes') {
            return;
        }

        $current = (int) $size->quantity;
        $reorderLevel = max(1, (int) $size->reorder_level);
        $reorderQuantity = max(1, (int) $size->reorder_quantity);

        if ($current > $reorderLevel) {
            return;
        }

        $requestedColor = optional($size->colorVariant)->color_name;
        $requestedSize = trim((string) $size->size_system . ' ' . (string) $size->size);
        $needed = max(1, $reorderQuantity - $current);

        $this->upsertAutoRequest(
            shopOwnerId: (int) $item->shop_owner_id,
            inventoryItemId: (int) $item->id,
            inventorySizeId: (int) $size->id,
            inventoryColorVariantId: $size->inventory_color_variant_id ? (int) $size->inventory_color_variant_id : null,
            requestSource: $source,
            productName: (string) $item->name,
            skuCode: (string) ($item->sku ?? ''),
            requestedSize: $requestedSize,
            requestedColor: $requestedColor,
            quantityNeeded: $needed,
            actorUserId: $actorUserId
        );
    }

    private function upsertAutoRequest(
        int $shopOwnerId,
        int $inventoryItemId,
        ?int $inventorySizeId,
        ?int $inventoryColorVariantId,
        string $requestSource,
        string $productName,
        string $skuCode,
        ?string $requestedSize,
        ?string $requestedColor,
        int $quantityNeeded,
        ?int $actorUserId
    ): void {
        DB::transaction(function () use ($shopOwnerId, $inventoryItemId, $inventorySizeId, $inventoryColorVariantId, $requestSource, $productName, $skuCode, $requestedSize, $requestedColor, $quantityNeeded, $actorUserId) {
            $open = StockRequestApproval::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('inventory_item_id', $inventoryItemId)
                ->where('inventory_size_id', $inventorySizeId)
                ->where('inventory_color_variant_id', $inventoryColorVariantId)
                ->where('request_source', $requestSource)
                ->where('is_auto_generated', true)
                ->whereIn('status', ['pending', 'needs_details'])
                ->lockForUpdate()
                ->first();

            if ($open) {
                if ((int) $open->quantity_needed < $quantityNeeded) {
                    $open->quantity_needed = $quantityNeeded;
                    $open->save();
                }
                return;
            }

            StockRequestApproval::create([
                'request_number' => 'SR-' . now()->format('Y') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
                'shop_owner_id' => $shopOwnerId,
                'inventory_item_id' => $inventoryItemId,
                'inventory_size_id' => $inventorySizeId,
                'inventory_color_variant_id' => $inventoryColorVariantId,
                'product_name' => $productName,
                'sku_code' => $skuCode,
                'quantity_needed' => $quantityNeeded,
                'requested_size' => $requestedSize,
                'requested_color' => $requestedColor,
                'priority' => 'medium',
                'request_source' => $requestSource,
                'status' => 'pending',
                'is_auto_generated' => true,
                'requested_by' => $actorUserId,
                'requested_date' => now(),
                'notes' => '[AUTO-REORDER] Hybrid real-time trigger.',
            ]);
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/ReorderAutomationServiceTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReorderAutomationService.php tests/Unit/Services/ReorderAutomationServiceTest.php
git commit -m "feat: add reorder automation service with dedupe upsert"
```

---

### Task 3: Wire Real-Time Trigger into Checkout and Repair Usage

**Files:**
- Modify: `app/Http/Controllers/UserSide/CheckoutController.php`
- Modify: `app/Http/Controllers/Api/RepairWorkflowController.php`
- Test: `tests/Feature/Inventory/AutoReorderCheckoutFeatureTest.php`

- [ ] **Step 1: Write failing feature test for checkout-triggered shoes request**

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoReorderCheckoutFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_creates_or_updates_auto_reorder_for_low_shoes_size(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'both']);
        $customer = User::factory()->create();

        $product = Product::factory()->create(['shop_owner_id' => $shop->id, 'stock_quantity' => 3]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'US 8', 'color' => 'Black', 'quantity' => 3]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'product_id' => $product->id,
            'category' => 'shoes',
            'available_quantity' => 3,
        ]);

        $color = InventoryColorVariant::factory()->create(['inventory_item_id' => $item->id, 'color_name' => 'Black', 'quantity' => 3]);

        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'reorder_level' => 2,
            'reorder_quantity' => 7,
        ]);

        $this->actingAs($customer, 'user')->postJson('/api/checkout/create-order', [
            'items' => [[
                'pid' => $product->id,
                'qty' => 2,
                'name' => 'Runner',
                'price' => 500,
                'size' => '8',
                'color' => 'Black',
                'options' => ['color' => 'Black'],
            ]],
            'total_amount' => 1000,
            'shipping_fee' => 0,
            'customer_name' => 'Test',
            'customer_email' => $customer->email,
            'customer_phone' => '09170000000',
            'shipping_address' => 'Test',
            'payment_method' => 'paymongo',
        ])->assertOk();

        $this->assertDatabaseHas('stock_request_approvals', [
            'shop_owner_id' => $shop->id,
            'inventory_item_id' => $item->id,
            'request_source' => 'manual',
            'is_auto_generated' => true,
            'requested_size' => 'US 8',
            'requested_color' => 'Black',
            'quantity_needed' => 6,
        ]);

        $this->assertSame(1, StockRequestApproval::query()->where('shop_owner_id', $shop->id)->where('inventory_item_id', $item->id)->where('is_auto_generated', true)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Inventory/AutoReorderCheckoutFeatureTest.php`
Expected: FAIL with missing auto request assertion

- [ ] **Step 3: Wire ReorderAutomationService in checkout deduction and repair usage path**

```php
// app/Http/Controllers/UserSide/CheckoutController.php
use App\Services\ReorderAutomationService;

app(ReorderAutomationService::class)->evaluateShoesSizeThreshold(
    inventoryItemId: (int) $inventoryItem->id,
    inventorySizeId: (int) $sizeRow->id,
    actorUserId: (int) $performedBy,
    source: 'manual'
);
```

```php
// app/Http/Controllers/Api/RepairWorkflowController.php
use App\Services\ReorderAutomationService;

app(ReorderAutomationService::class)->evaluateRepairMaterialThreshold(
    inventoryItemId: (int) $inventoryItem->id,
    actorUserId: $actorUserId > 0 ? (int) $actorUserId : null
);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Inventory/AutoReorderCheckoutFeatureTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/UserSide/CheckoutController.php app/Http/Controllers/Api/RepairWorkflowController.php tests/Feature/Inventory/AutoReorderCheckoutFeatureTest.php
git commit -m "feat: wire real-time auto-reorder in checkout and repair usage"
```

---

### Task 4: Wire Real-Time Trigger for Manual Stock-Out and Negative Adjustments

**Files:**
- Modify: `app/Http/Controllers/Erp/StockMovementController.php`
- Modify: `app/Http/Controllers/Erp/ProductInventoryController.php`
- Test: `tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php`

- [ ] **Step 1: Write failing feature test for manual stock-out trigger**

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AutoReorderManualStockOutFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_stock_out_creates_auto_reorder_for_repair_material(): void
    {
        Permission::findOrCreate('view-inventory', 'user');

        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo('view-inventory');

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'category' => 'repair_materials',
            'available_quantity' => 3,
            'reorder_level' => 2,
            'reorder_quantity' => 9,
        ]);

        $this->actingAs($user, 'user')->postJson('/api/erp/inventory/movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -2,
            'notes' => 'Manual test stock out',
        ])->assertStatus(201);

        $this->assertDatabaseHas('stock_request_approvals', [
            'shop_owner_id' => $shop->id,
            'inventory_item_id' => $item->id,
            'request_source' => 'repair',
            'is_auto_generated' => true,
            'quantity_needed' => 8,
        ]);

        $this->assertSame(1, StockRequestApproval::query()->where('shop_owner_id', $shop->id)->where('inventory_item_id', $item->id)->where('is_auto_generated', true)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php`
Expected: FAIL with missing auto request assertion

- [ ] **Step 3: Integrate service in manual mutation controllers**

```php
// app/Http/Controllers/Erp/StockMovementController.php
if ((int) $validated['quantity_change'] < 0) {
    app(\App\Services\ReorderAutomationService::class)
        ->evaluateRepairMaterialThreshold((int) $item->id, (int) $request->user()->id);
}
```

```php
// app/Http/Controllers/Erp/ProductInventoryController.php
if ($quantityChange < 0) {
    app(\App\Services\ReorderAutomationService::class)
        ->evaluateRepairMaterialThreshold((int) $item->id, (int) $request->user()->id);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Erp/StockMovementController.php app/Http/Controllers/Erp/ProductInventoryController.php tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php
git commit -m "feat: trigger auto-reorder on manual stock reduction paths"
```

---

### Task 5: Add Reconciliation Command and Scheduler Hook

**Files:**
- Create: `app/Console/Commands/ReconcileAutoReorderCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Inventory/AutoReorderReconcileCommandTest.php`

- [ ] **Step 1: Extend failing test for reconciliation behavior**

```php
public function test_reconcile_command_creates_missing_auto_request_for_low_scope(): void
{
    $shop = \App\Models\ShopOwner::factory()->create();
    $item = \App\Models\InventoryItem::factory()->create([
        'shop_owner_id' => $shop->id,
        'category' => 'repair_materials',
        'available_quantity' => 1,
        'reorder_level' => 2,
        'reorder_quantity' => 7,
    ]);

    $this->artisan('inventory:reconcile-auto-reorder')
        ->assertExitCode(0);

    $this->assertDatabaseHas('stock_request_approvals', [
        'shop_owner_id' => $shop->id,
        'inventory_item_id' => $item->id,
        'request_source' => 'repair',
        'is_auto_generated' => true,
        'quantity_needed' => 6,
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Inventory/AutoReorderReconcileCommandTest.php --filter=reconcile_command_creates_missing_auto_request_for_low_scope`
Expected: FAIL with command not defined

- [ ] **Step 3: Implement command and schedule it every 10 minutes**

```php
<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Services\ReorderAutomationService;
use Illuminate\Console\Command;

class ReconcileAutoReorderCommand extends Command
{
    protected $signature = 'inventory:reconcile-auto-reorder {--shop-owner-id=}';
    protected $description = 'Reconcile low stock scopes and ensure auto-reorder requests exist';

    public function handle(): int
    {
        $service = app(ReorderAutomationService::class);

        InventoryItem::query()
            ->when($this->option('shop-owner-id'), fn ($q, $id) => $q->where('shop_owner_id', (int) $id))
            ->where('category', 'repair_materials')
            ->whereRaw('available_quantity <= reorder_level')
            ->chunkById(200, function ($rows) use ($service) {
                foreach ($rows as $item) {
                    $service->evaluateRepairMaterialThreshold((int) $item->id, null);
                }
            });

        InventorySize::query()
            ->whereHas('inventoryItem', function ($q) {
                $q->where('category', 'shoes')
                  ->when($this->option('shop-owner-id'), fn ($sq, $id) => $sq->where('shop_owner_id', (int) $id));
            })
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->chunkById(300, function ($rows) use ($service) {
                foreach ($rows as $size) {
                    $service->evaluateShoesSizeThreshold((int) $size->inventory_item_id, (int) $size->id, null, 'manual');
                }
            });

        $this->info('Auto-reorder reconciliation completed.');
        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
Schedule::command('inventory:reconcile-auto-reorder')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Inventory/AutoReorderReconcileCommandTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ReconcileAutoReorderCommand.php routes/console.php tests/Feature/Inventory/AutoReorderReconcileCommandTest.php
git commit -m "feat: add auto-reorder reconciliation command and schedule"
```

---

### Task 6: Enforce Upload Validation and Frontend Inputs for Reorder Fields

**Files:**
- Modify: `app/Http/Controllers/Erp/UploadInventoryController.php`
- Modify: `resources/js/Pages/ERP/inventory/UploadInventory.tsx`
- Modify: `resources/js/components/variants/ColorVariantManager.tsx`
- Modify: `resources/js/services/inventoryAPI.ts`
- Test: `tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php`

- [ ] **Step 1: Write failing API validation assertion for missing reorder fields on shoes sizes**

```php
public function test_upload_inventory_requires_reorder_fields_for_shoes_sizes(): void
{
    $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'both']);
    $user = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id]);

    $this->actingAs($user, 'user')->postJson('/api/erp/inventory/items', [
        'name' => 'Validation Shoe',
        'category' => 'shoes',
        'available_quantity' => 5,
        'unit' => 'pairs',
        'reorder_level' => 2,
        'reorder_quantity' => 8,
        'color_variants' => [[
            'color_name' => 'Black',
            'quantity' => 5,
            'sizes' => [[
                'size' => '8',
                'size_system' => 'US',
                'quantity' => 5,
            ]],
        ]],
    ])->assertStatus(422)
      ->assertJsonValidationErrors([
          'color_variants.0.sizes.0.reorder_level',
          'color_variants.0.sizes.0.reorder_quantity',
      ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php --filter=requires_reorder_fields_for_shoes_sizes`
Expected: FAIL with missing validation rule assertion

- [ ] **Step 3: Add validation and client form bindings for reorder fields**

```php
// app/Http/Controllers/Erp/UploadInventoryController.php
'color_variants.*.sizes.*.reorder_level' => 'required_if:category,shoes|integer|min:1',
'color_variants.*.sizes.*.reorder_quantity' => 'required_if:category,shoes|integer|min:1|gte:color_variants.*.sizes.*.reorder_level',
```

```tsx
// resources/js/components/variants/ColorVariantManager.tsx
<input
  type="number"
  min={1}
  value={sizeVariant.reorder_level ?? ''}
  onChange={(e) => updateSizeField(colorVariant.id, sizeVariant.id, 'reorder_level', Number(e.target.value || 1))}
/>
<input
  type="number"
  min={1}
  value={sizeVariant.reorder_quantity ?? ''}
  onChange={(e) => updateSizeField(colorVariant.id, sizeVariant.id, 'reorder_quantity', Number(e.target.value || 1))}
/>
```

```tsx
// resources/js/Pages/ERP/inventory/UploadInventory.tsx
const [formData, setFormData] = useState({
  name: '',
  brand: '',
  category: (canUploadShoes ? 'shoes' : 'repair_materials') as StockCategory,
  quantity: '',
  unit: 'pcs',
  notes: '',
  reorderLevel: '',
  reorderQuantity: '',
});
```

```ts
// resources/js/services/inventoryAPI.ts
export interface CreateInventorySizeData {
  size: string;
  size_system?: 'US' | 'UK' | 'EU' | 'AU' | 'CN';
  quantity: number;
  reorder_level: number;
  reorder_quantity: number;
}
```

- [ ] **Step 4: Run targeted tests and one frontend type check**

Run: `php artisan test tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php --filter=requires_reorder_fields_for_shoes_sizes`
Expected: PASS

Run: `npm run typecheck`
Expected: PASS with no TS errors from inventory form types

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Erp/UploadInventoryController.php resources/js/Pages/ERP/inventory/UploadInventory.tsx resources/js/components/variants/ColorVariantManager.tsx resources/js/services/inventoryAPI.ts tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php
git commit -m "feat: require and submit reorder fields for upload inventory scopes"
```

---

### Task 7: Final Verification and Documentation Updates

**Files:**
- Modify: `docs/plans/2026-04-08-inventory-auto-reorder-hybrid-design.md`
- Modify: `docs/P4-ROLLOUT-CHECKLIST.md`

- [ ] **Step 1: Add rollout checkpoints for hybrid auto-reorder release**

```md
## Inventory Auto-Reorder Hybrid Rollout
- [ ] Migrations applied in staging
- [ ] Real-time hooks verified for checkout and repair usage
- [ ] Reconciliation command enabled
- [ ] Duplicate pending request audit report clean
- [ ] Procurement queue receives shoes size+color auto requests
```

- [ ] **Step 2: Run full relevant test set**

Run: `php artisan test tests/Unit/Services/ReorderAutomationServiceTest.php tests/Feature/Inventory/AutoReorderCheckoutFeatureTest.php tests/Feature/Inventory/AutoReorderManualStockOutFeatureTest.php tests/Feature/Inventory/AutoReorderReconcileCommandTest.php`
Expected: PASS

- [ ] **Step 3: Run command dry verification in local environment**

Run: `php artisan inventory:reconcile-auto-reorder --shop-owner-id=1`
Expected: Exit code 0 and message "Auto-reorder reconciliation completed."

- [ ] **Step 4: Commit final docs updates**

```bash
git add docs/plans/2026-04-08-inventory-auto-reorder-hybrid-design.md docs/P4-ROLLOUT-CHECKLIST.md
git commit -m "docs: add rollout checkpoints for hybrid auto-reorder"
```

- [ ] **Step 5: Prepare PR summary**

```md
## Summary
- Added size-level reorder schema and scoped stock-request identity keys
- Implemented real-time reorder automation service with dedupe upsert
- Wired checkout, repair usage, and manual stock reduction paths
- Added reconciliation command scheduled every 10 minutes
- Added tests for service logic, checkout trigger, manual trigger, and reconciliation safety net
```

---

## Self-Review

### 1. Spec coverage
- Hybrid architecture: covered by Tasks 2, 3, 5.
- Real-time targeted integration: covered by Tasks 3 and 4.
- Reconciliation safety net: covered by Task 5.
- Shoes size+color thresholds: covered by Tasks 1, 2, 3, 6.
- Upsert duplicate protection: covered by Task 2 and feature assertions in Tasks 3 and 4.
- Upload required reorder fields: covered by Task 6.

No uncovered requirements identified.

### 2. Placeholder scan
- No TODO or TBD markers remain.
- Every code-changing step includes concrete code blocks.
- Every verification step has exact command and expected outcome.

### 3. Type consistency
- Service method names are consistent across tests and integration steps.
- Scope key names align with migration and model fields.
- Request source values align with approved routing manual for shoes and repair for materials.
