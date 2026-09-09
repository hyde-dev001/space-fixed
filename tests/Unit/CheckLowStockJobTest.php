<?php

namespace Tests\Unit;

use App\Events\LowStockAlert;
use App\Events\OutOfStockAlert;
use App\Jobs\CheckLowStockJob;
use App\Models\InventoryAlert;
use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\PurchaseRequest;
use App\Models\ReplenishmentRequest;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\StockMovement;
use App\Models\StockRequestApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
    public function test_stock_movement_queues_an_automatic_low_stock_check(): void
    {
        Queue::fake();

        $shopOwner = ShopOwner::factory()->create();
        $inventoryItem = InventoryItem::factory()->create(['shop_owner_id' => $shopOwner->id]);
        StockMovement::create([
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -1,
            'quantity_before' => 1,
            'quantity_after' => 0,
        ]);

        Queue::assertPushed(CheckLowStockJob::class, function (CheckLowStockJob $job) use ($shopOwner): bool {
            return $job->shopOwnerId === $shopOwner->id;
        });
    }

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
    public function it_creates_an_automatic_stock_request_for_low_stock_items()
    {
        $shopOwner = ShopOwner::factory()->create();

        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
            'auto_stock_request_enabled' => true,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('stock_request_approvals', [
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $lowStockItem->id,
            'product_name' => $lowStockItem->name,
            'sku_code' => $lowStockItem->sku,
            'quantity_needed' => 50,
            'status' => 'pending',
            'requested_by' => null,
            'is_auto_generated' => true,
        ]);
        $this->assertDatabaseMissing('replenishment_requests', [
            'inventory_item_id' => $lowStockItem->id,
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
            'auto_stock_request_enabled' => true,
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

        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $lowStockItem->id,
            'quantity_needed' => 20,
            'is_auto_generated' => true,
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
            'auto_stock_request_enabled' => true,
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

        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $lowStockItem->id,
            'quantity_needed' => 30,
            'is_auto_generated' => true,
        ]);
    }

    /** @test */
    public function it_does_not_create_duplicate_automatic_stock_requests_on_repeated_checks()
    {
        $shopOwner = ShopOwner::factory()->create();
        User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $lowStockItem = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 0,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
            'auto_stock_request_enabled' => true,
        ]);

        $job = new CheckLowStockJob($shopOwner->id);
        $job->handle();
        $job->handle();

        $this->assertSame(
            1,
            StockRequestApproval::where('inventory_item_id', $lowStockItem->id)->count()
        );
    }

    /** @test */
    public function it_only_alerts_when_automatic_stock_request_is_disabled(): void
    {
        $shopOwner = ShopOwner::factory()->create();

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'reorder_quantity' => 40,
            'auto_stock_request_enabled' => false,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'alert_type' => 'low_stock',
            'is_resolved' => false,
        ]);
        $this->assertDatabaseMissing('stock_request_approvals', [
            'inventory_item_id' => $item->id,
        ]);
        $this->assertDatabaseMissing('replenishment_requests', [
            'inventory_item_id' => $item->id,
        ]);
    }

    /** @test */
    public function existing_open_stock_request_counts_as_incoming_coverage(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'reorder_quantity' => 40,
            'auto_stock_request_enabled' => true,
        ]);

        StockRequestApproval::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'quantity_needed' => 40,
            'status' => 'pending',
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertSame(1, StockRequestApproval::where('inventory_item_id', $item->id)->count());
    }

    /** @test */
    public function backfilled_legacy_requests_are_counted_only_once(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'reorder_quantity' => 40,
            'auto_stock_request_enabled' => true,
        ]);
        $requestNumber = 'SR-2026-LEGACY-001';

        ReplenishmentRequest::create([
            'request_number' => $requestNumber,
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'product_name' => $item->name,
            'sku_code' => $item->sku,
            'quantity_needed' => 25,
            'priority' => 'medium',
            'status' => 'pending',
            'requested_by' => $requester->id,
            'requested_date' => now(),
        ]);
        StockRequestApproval::factory()->create([
            'request_number' => $requestNumber,
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'quantity_needed' => 25,
            'requested_by' => $requester->id,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $item->id,
            'quantity_needed' => 15,
            'is_auto_generated' => true,
        ]);
    }

    public function test_variant_stock_is_checked_independently_and_request_preserves_color_and_size(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 43,
        ]);
        $black = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 23,
        ]);
        $white = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'White',
            'quantity' => 20,
        ]);

        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '9',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $white->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'alert_type' => 'low_stock',
            'is_resolved' => false,
        ]);
        $this->assertSame(1, InventoryAlert::where('inventory_item_id', $item->id)->count());
        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 8',
            'quantity_needed' => 20,
            'is_auto_generated' => true,
        ]);
    }

    public function test_variant_incoming_supply_only_covers_the_matching_color_and_size(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 0,
        ]);
        $black = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 3,
        ]);
        $white = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'White',
            'quantity' => 20,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '9',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $white->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'BLACK',
            'requested_size' => '8',
            'quantity' => 15,
            'status' => 'pending_finance',
            'requested_by' => $requester->id,
        ]);
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 9',
            'quantity' => 30,
            'status' => 'pending_finance',
            'requested_by' => $requester->id,
        ]);
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'white',
            'requested_size' => 'US 8',
            'quantity' => 20,
            'status' => 'pending_finance',
            'requested_by' => $requester->id,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 8',
            'quantity_needed' => 25,
            'is_auto_generated' => true,
        ]);
    }

    public function test_variant_alert_resolves_and_can_alert_again_after_recovery(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 0,
        ]);
        $color = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 3,
        ]);
        $size = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => false,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);

        $job = new CheckLowStockJob($shopOwner->id);
        $job->handle();
        $size->update(['quantity' => 6]);
        $job->handle();
        $size->update(['quantity' => 3]);
        $job->handle();

        $this->assertSame(2, InventoryAlert::where('inventory_item_id', $item->id)->count());
        $this->assertSame(1, InventoryAlert::where('inventory_item_id', $item->id)->where('is_resolved', false)->count());
    }

    public function test_variant_legacy_purchase_order_headers_only_cover_the_matching_color_and_size(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 0,
        ]);
        $black = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 23,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '9',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'BLACK',
            'requested_size' => '8',
            'quantity' => 15,
            'status' => 'sent',
            'ordered_by' => $requester->id,
        ]);
        PurchaseOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 9',
            'quantity' => 30,
            'status' => 'sent',
            'ordered_by' => $requester->id,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 8',
            'quantity_needed' => 25,
            'is_auto_generated' => true,
        ]);
    }
    public function test_low_to_out_transition_resolves_the_previous_condition_only_for_that_target(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shopOwner->id, 'available_quantity' => 0]);
        $color = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 3,
        ]);
        $size = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => false,
            'reorder_level' => 5,
        ]);
        $job = new CheckLowStockJob($shopOwner->id);

        $job->handle();
        $job->handle();
        $size->update(['quantity' => 0]);
        $job->handle();
        $job->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'inventory_size_id' => $size->id,
            'alert_type' => 'low_stock',
            'is_resolved' => true,
        ]);
        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'inventory_size_id' => $size->id,
            'alert_type' => 'out_of_stock',
            'is_resolved' => false,
        ]);
        $this->assertSame(1, InventoryAlert::where('inventory_item_id', $item->id)->where('is_resolved', false)->count());
    }

    public function test_out_to_low_transition_resolves_the_previous_condition_only_for_that_target(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shopOwner->id, 'available_quantity' => 0]);
        $color = InventoryColorVariant::create([
            'inventory_item_id' => $item->id,
            'color_name' => 'Black',
            'quantity' => 0,
        ]);
        $size = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 0,
            'auto_stock_request_enabled' => false,
            'reorder_level' => 5,
        ]);
        $job = new CheckLowStockJob($shopOwner->id);

        $job->handle();
        $job->handle();
        $size->update(['quantity' => 3]);
        $job->handle();
        $job->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'inventory_size_id' => $size->id,
            'alert_type' => 'out_of_stock',
            'is_resolved' => true,
        ]);
        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $color->id,
            'inventory_size_id' => $size->id,
            'alert_type' => 'low_stock',
            'is_resolved' => false,
        ]);
        $this->assertSame(1, InventoryAlert::where('inventory_item_id', $item->id)->where('is_resolved', false)->count());
    }

    public function test_normal_recovery_does_not_resolve_an_unrelated_variant_alert(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shopOwner->id, 'available_quantity' => 0]);
        $black = InventoryColorVariant::create(['inventory_item_id' => $item->id, 'color_name' => 'Black', 'quantity' => 3]);
        $white = InventoryColorVariant::create(['inventory_item_id' => $item->id, 'color_name' => 'White', 'quantity' => 3]);
        $blackSize = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => false,
            'reorder_level' => 5,
        ]);
        $whiteSize = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $white->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => false,
            'reorder_level' => 5,
        ]);
        $job = new CheckLowStockJob($shopOwner->id);

        $job->handle();
        $blackSize->update(['quantity' => 6]);
        $job->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_color_variant_id' => $black->id,
            'inventory_size_id' => $blackSize->id,
            'is_resolved' => true,
        ]);
        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_color_variant_id' => $white->id,
            'inventory_size_id' => $whiteSize->id,
            'is_resolved' => false,
        ]);
    }

    public function test_mixed_color_targets_create_separate_alerts_without_a_parent_alert(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $item = InventoryItem::factory()->create(['shop_owner_id' => $shopOwner->id, 'available_quantity' => 0]);
        $black = InventoryColorVariant::create(['inventory_item_id' => $item->id, 'color_name' => 'Black', 'quantity' => 0]);
        $white = InventoryColorVariant::create(['inventory_item_id' => $item->id, 'color_name' => 'White', 'quantity' => 0]);
        $blackSize = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 0,
            'auto_stock_request_enabled' => false,
            'reorder_level' => 5,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'inventory_size_id' => $blackSize->id,
            'alert_type' => 'out_of_stock',
            'is_resolved' => false,
        ]);
        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $white->id,
            'inventory_size_id' => null,
            'alert_type' => 'out_of_stock',
            'is_resolved' => false,
        ]);
        $this->assertDatabaseMissing('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => null,
            'inventory_size_id' => null,
        ]);
    }

    public function test_variant_automatic_request_subtracts_only_exact_open_incoming_supply(): void
    {
        $shopOwner = ShopOwner::factory()->create();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'available_quantity' => 0,
        ]);
        $black = InventoryColorVariant::create(['inventory_item_id' => $item->id, 'color_name' => 'Black', 'quantity' => 3]);
        $white = InventoryColorVariant::create(['inventory_item_id' => $item->id, 'color_name' => 'White', 'quantity' => 20]);
        $blackEight = InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 3,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '9',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);
        InventorySize::create([
            'inventory_item_id' => $item->id,
            'inventory_color_variant_id' => $white->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 20,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 40,
        ]);

        StockRequestApproval::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'BLACK',
            'requested_size' => 'US 8',
            'quantity_needed' => 5,
            'status' => 'pending',
        ]);
        StockRequestApproval::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 9',
            'quantity_needed' => 50,
            'status' => 'pending',
        ]);
        ReplenishmentRequest::create([
            'request_number' => 'RR-UNSCOPED-001',
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'product_name' => $item->name,
            'sku_code' => $item->sku,
            'quantity_needed' => 100,
            'priority' => 'medium',
            'status' => 'pending',
            'requested_by' => $requester->id,
            'requested_date' => now(),
        ]);
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 8',
            'quantity' => 10,
            'status' => 'pending_finance',
            'requested_by' => $requester->id,
        ]);
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'white',
            'requested_size' => 'US 8',
            'quantity' => 100,
            'status' => 'pending_finance',
            'requested_by' => $requester->id,
        ]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $item->id,
            'status' => 'partially_received',
            'ordered_by' => $requester->id,
        ]);
        $matchingPoItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'BLACK',
            'requested_size' => 'US 8',
            'ordered_quantity' => 12,
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'inventory_item_id' => $item->id,
            'requested_color' => 'BLACK',
            'requested_size' => 'US 9',
            'ordered_quantity' => 100,
        ]);
        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => 'posted',
        ]);
        PurchaseOrderReceiptItem::factory()->create([
            'purchase_order_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $matchingPoItem->id,
            'received_quantity' => 4,
            'accepted_quantity' => 4,
        ]);

        (new CheckLowStockJob($shopOwner->id))->handle();

        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $item->id,
            'requested_color' => 'black',
            'requested_size' => 'US 8',
            'quantity_needed' => 17,
            'is_auto_generated' => true,
        ]);
    }

}
