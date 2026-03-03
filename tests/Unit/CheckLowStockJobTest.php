<?php

namespace Tests\Unit;

use App\Jobs\CheckLowStockJob;
use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckLowStockJobTest extends TestCase
{
    use RefreshDatabase;

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
            'status' => 'active',
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
            'status' => 'active',
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
            'status' => 'active',
            'message' => 'Low stock alert',
        ]);

        $job = new CheckLowStockJob($shopOwner->id);
        $job->handle();

        $this->assertEquals(1, InventoryAlert::where('inventory_item_id', $lowStockItem->id)->count());
    }
}
