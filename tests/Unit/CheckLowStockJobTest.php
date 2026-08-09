<?php

namespace Tests\Unit;

use App\Events\LowStockAlert;
use App\Events\OutOfStockAlert;
use App\Jobs\CheckLowStockJob;
use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\PurchaseRequest;
use App\Models\ReplenishmentRequest;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CheckLowStockJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([LowStockAlert::class, OutOfStockAlert::class]);
    }

    /** @test */
    public function it_creates_alerts_for_low_stock_items()
    {
        $shopOwner = ShopOwner::factory()->create();
        
        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
        ]);

        $normalStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 20,
            'reorder_level' => 10,
        ]);

        $job = new CheckLowStockJob($shopOwner->id);
        $job->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $lowStockItem->id,
            'alert_type' => 'low_stock',
            'is_resolved' => false,
        ]);

        $this->assertDatabaseMissing('inventory_alerts', [
            'inventory_item_id' => $normalStockItem->id,
        ]);
    }

    /** @test */
    public function it_creates_alerts_for_out_of_stock_items()
    {
        $shopOwner = ShopOwner::factory()->create();
        
        $outOfStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 0,
        ]);

        $job = new CheckLowStockJob($shopOwner->id);
        $job->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $outOfStockItem->id,
            'alert_type' => 'out_of_stock',
            'is_resolved' => false,
        ]);
    }

    /** @test */
    public function it_does_not_create_duplicate_alerts()
    {
        $shopOwner = ShopOwner::factory()->create();
        
        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
        ]);

        InventoryAlert::create([
            'inventory_item_id' => $lowStockItem->id,
            'alert_type' => 'low_stock',
            'is_resolved' => false,
        ]);

        $job = new CheckLowStockJob($shopOwner->id);
        $job->handle();

        $this->assertEquals(1, InventoryAlert::where('inventory_item_id', $lowStockItem->id)->count());
    }

    /** @test */
    public function it_creates_a_replenishment_request_for_low_stock_items()
    {
        $shopOwner = ShopOwner::factory()->create();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('replenishment_requests', [
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $lowStockItem->id,
            'product_name' => $lowStockItem->name,
            'sku_code' => $lowStockItem->sku,
            'quantity_needed' => 50,
            'status' => 'pending',
            'requested_by' => $requester->id,
        ]);
    }

    /** @test */
    public function it_subtracts_open_purchase_requests_and_purchase_orders_from_replenishment_quantity()
    {
        $shopOwner = ShopOwner::factory()->create();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
        ]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $lowStockItem->id,
            'quantity' => 20,
            'status' => 'pending_finance',
            'requested_by' => $requester->id,
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $lowStockItem->id,
            'quantity' => 15,
            'received_quantity' => 5,
            'defective_quantity' => 0,
            'status' => 'sent',
            'ordered_by' => $requester->id,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('replenishment_requests', [
            'inventory_item_id' => $lowStockItem->id,
            'quantity_needed' => 20,
        ]);
    }

    /** @test */
    public function it_uses_the_remaining_quantity_of_a_receiving_aware_purchase_order()
    {
        $shopOwner = ShopOwner::factory()->create();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
        ]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $lowStockItem->id,
            'quantity' => 30,
            'status' => 'partially_received',
            'ordered_by' => $requester->id,
        ]);
        $purchaseOrderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'inventory_item_id' => $lowStockItem->id,
            'ordered_quantity' => 30,
            'unit_cost' => 100,
            'line_total' => 3000,
        ]);
        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'shop_owner_id' => $shopOwner->id,
        ]);
        PurchaseOrderReceiptItem::factory()->create([
            'purchase_order_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'received_quantity' => 10,
            'accepted_quantity' => 10,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('replenishment_requests', [
            'inventory_item_id' => $lowStockItem->id,
            'quantity_needed' => 30,
        ]);
    }

    /** @test */
    public function it_does_not_create_duplicate_replenishment_requests_on_repeated_checks()
    {
        $shopOwner = ShopOwner::factory()->create();
        User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 0,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
        ]);

        $job = new CheckLowStockJob($shopOwner->id);
        $job->handle();
        $job->handle();

        $this->assertSame(
            1,
            ReplenishmentRequest::where('inventory_item_id', $lowStockItem->id)->count()
        );
    }
}
