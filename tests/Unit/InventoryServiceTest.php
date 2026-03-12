<?php

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryService $service;
    protected ShopOwner $shopOwner;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = app(InventoryService::class);
        $this->shopOwner = ShopOwner::factory()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
    }

    /** @test */
    public function it_gets_dashboard_metrics()
    {
        // Create various inventory items
        InventoryItem::factory()->count(5)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 50,
            'cost_price' => 100,
        ]);

        InventoryItem::factory()->count(2)->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
            'cost_price' => 50,
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 0,
            'cost_price' => 75,
        ]);

        $metrics = $this->service->getDashboardMetrics($this->shopOwner->id);

        $this->assertEquals(8, $metrics['total_items']);
        $this->assertEquals(2, $metrics['low_stock_count']);
        $this->assertEquals(1, $metrics['out_of_stock_count']);
        $this->assertArrayHasKey('total_value', $metrics);
    }

    /** @test */
    public function it_gets_stock_levels_chart()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'name' => 'Product A',
            'available_quantity' => 100,
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'name' => 'Product B',
            'available_quantity' => 50,
        ]);

        $chartData = $this->service->getStockLevelsChart($this->shopOwner->id);

        $this->assertArrayHasKey('categories', $chartData);
        $this->assertArrayHasKey('series', $chartData);
        $this->assertCount(2, $chartData['categories']);
    }

    /** @test */
    public function it_adjusts_stock_with_movement()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 10,
        ]);

        $this->service->adjustStock(
            $item->id,
            20,
            'adjustment',
            'Inventory count',
            $this->user->id
        );

        $this->assertEquals(20, $item->fresh()->available_quantity);
        
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => 'adjustment',
            'quantity_after' => 20,
        ]);
    }

    /** @test */
    public function it_transfers_stock_between_items()
    {
        $itemFrom = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 20,
        ]);

        $itemTo = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 10,
        ]);

        $this->service->transferStock(
            $itemFrom->id,
            $itemTo->id,
            5,
            $this->user->id
        );

        $this->assertEquals(15, $itemFrom->fresh()->available_quantity);
        $this->assertEquals(15, $itemTo->fresh()->available_quantity);
        
        // Check both movements were created
        $this->assertEquals(2, StockMovement::where('movement_type', 'transfer')->count());
    }

    /** @test */
    public function it_calculates_stock_value()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 10,
            'cost_price' => 100,
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 5,
            'cost_price' => 200,
        ]);

        $totalValue = $this->service->calculateStockValue($this->shopOwner->id);

        $this->assertEquals(2000, $totalValue);
    }

    /** @test */
    public function it_checks_and_creates_alerts()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
        ]);

        $this->service->checkAndCreateAlerts($item->id);

        $this->assertDatabaseHas('inventory_alerts', [
            'inventory_item_id' => $item->id,
            'alert_type' => 'low_stock',
            'is_resolved' => false,
        ]);
    }

    /** @test */
    public function it_gets_low_stock_items()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 20,
            'reorder_level' => 10,
        ]);

        $lowStockItems = $this->service->getLowStockItems($this->shopOwner->id);

        $this->assertCount(1, $lowStockItems);
    }

    /** @test */
    public function it_gets_items_needing_reorder()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 3,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
        ]);

        $reorderItems = $this->service->getItemsNeedingReorder($this->shopOwner->id);

        $this->assertCount(1, $reorderItems);
        $this->assertEquals(50, $reorderItems->first()->reorder_quantity);
    }
}
