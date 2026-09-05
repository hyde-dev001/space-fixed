<?php

namespace Tests\Feature\Procurement;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderReceiptService;
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
            'ordered_quantity' => 2,
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

    private function runConcurrently(callable $callback): void
    {
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::disconnect();
                    $callback();
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
