<?php

namespace Tests\Feature\Procurement;

use App\Jobs\CheckLowStockJob;
use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\StockRequestApproval;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\StockRequestApprovalService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    public function test_mysql_serializes_duplicate_receipt_and_void_requests(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL row-lock verification is intentionally skipped on SQLite.');
        }
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL concurrency verification requires the pcntl extension.');
        }

        $owner = ShopOwner::factory()->create();
        $user = User::factory()->for($owner)->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $owner->id]);
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'available_quantity' => 0,
        ]);
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'ordered_by' => $user->id,
            'status' => 'in_transit',
        ]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'inventory_item_id' => $inventory->id,
            'ordered_quantity' => 1,
        ]);
        $payload = [
            'idempotency_key' => 'concurrent-receipt',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => 1,
                'defective_quantity' => 0,
            ]],
        ];

        $this->runConcurrently(function () use ($po, $user, $payload): void {
            app(PurchaseOrderReceiptService::class)->post(
                PurchaseOrder::findOrFail($po->id),
                User::findOrFail($user->id),
                $payload
            );
        });

        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertSame(1, StockMovement::whereNotNull('purchase_order_receipt_item_id')->count());
        $this->assertSame(1, $inventory->fresh()->available_quantity);

        $receipt = PurchaseOrderReceipt::sole();
        $this->runConcurrently(function () use ($po, $receipt, $user): void {
            app(PurchaseOrderReceiptService::class)->void(
                PurchaseOrder::findOrFail($po->id),
                PurchaseOrderReceipt::findOrFail($receipt->id),
                User::findOrFail($user->id),
                'Concurrent receipt correction.'
            );
        });

        $this->assertSame(1, StockMovement::whereNotNull('reversal_of_stock_movement_id')->count());
        $this->assertSame(0, $inventory->fresh()->available_quantity);
    }

    public function test_mysql_serializes_duplicate_automatic_stock_requests_for_one_variant(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL row-lock verification is intentionally skipped on SQLite.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL concurrency verification requires the pcntl extension.');
        }

        $owner = ShopOwner::factory()->create();
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'available_quantity' => 0,
        ]);
        $color = InventoryColorVariant::create([
            'inventory_item_id' => $inventory->id,
            'color_name' => 'Black',
            'quantity' => 3,
        ]);
        InventorySize::create([
            'inventory_item_id' => $inventory->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);

        $this->runConcurrently(function () use ($owner): void {
            (new CheckLowStockJob($owner->id))->handle();
        });

        $this->assertSame(1, StockRequestApproval::query()
            ->where('inventory_item_id', $inventory->id)
            ->where('requested_color', 'black')
            ->where('requested_size', 'US 8')
            ->count());
    }

    public function test_mysql_serializes_competing_stock_request_decisions(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL row-lock verification is intentionally skipped on SQLite.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL concurrency verification requires the pcntl extension.');
        }

        $owner = ShopOwner::factory()->create();
        $users = User::factory()->for($owner)->count(2)->create();
        $request = StockRequestApproval::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => 'pending',
        ]);
        $children = [];
        foreach ($users as $user) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::disconnect();
                    app(StockRequestApprovalService::class)->approveStockRequest($request->id, $user->id);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        sort($statuses);

        $this->assertSame([0, 1], $statuses);
        $this->assertSame('accepted', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->approved_by);
    }

    public function test_mysql_serializes_purchase_request_numbers_per_shop(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL row-lock verification is intentionally skipped on SQLite.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL concurrency verification requires the pcntl extension.');
        }

        $owner = ShopOwner::factory()->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $owner->id]);
        $users = User::factory()->for($owner)->count(2)->create();

        $this->runConcurrently(function (int $index) use ($owner, $supplier, $users): void {
            $user = $users[$index];
            app(PurchaseRequestService::class)->createPurchaseRequest([
                'shop_owner_id' => $owner->id,
                'supplier_id' => $supplier->id,
                'product_name' => 'Concurrent Product',
                'quantity' => 1,
                'unit_cost' => '100.00',
                'priority' => 'medium',
                'justification' => 'Concurrent numbering test',
                'requested_by' => $user->id,
            ]);
        });

        $numbers = \App\Models\PurchaseRequest::query()->pluck('pr_number');
        $this->assertCount(2, $numbers);
        $this->assertSame(2, $numbers->unique()->count());
    }

    public function test_mysql_serializes_purchase_order_numbers_per_shop(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL row-lock verification is intentionally skipped on SQLite.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL concurrency verification requires the pcntl extension.');
        }

        $owner = ShopOwner::factory()->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $owner->id]);
        $users = User::factory()->for($owner)->count(2)->create();
        $requests = PurchaseRequest::factory()->count(2)->create([
            'shop_owner_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'status' => 'approved',
        ]);

        $this->runConcurrently(function (int $index) use ($owner, $users, $requests): void {
            $request = $requests[$index];
            $user = $users[$index];
            app(PurchaseOrderService::class)->createPurchaseOrder([
                'purchase_request_ids' => [$request->id],
                'shop_owner_id' => $owner->id,
                'payment_terms' => 'Net 30',
                'ordered_by' => $user->id,
            ]);
        });

        $numbers = PurchaseOrder::query()->pluck('po_number');
        $this->assertCount(2, $numbers);
        $this->assertSame(2, $numbers->unique()->count());
    }

    private function runConcurrently(callable $callback): void
    {
        $acceptsIndex = (new \ReflectionFunction($callback))->getNumberOfParameters() > 0;
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::disconnect();
                    $acceptsIndex ? $callback($i) : $callback();
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }
}
