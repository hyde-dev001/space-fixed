<?php

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryItemTest extends TestCase
{
    use RefreshDatabase;

    protected ShopOwner $shopOwner;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->shopOwner = ShopOwner::factory()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
    }

    /** @test */
    public function it_can_create_inventory_item()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 50,
        ]);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'available_quantity' => 50,
        ]);
    }

    /** @test */
    public function it_calculates_status_correctly()
    {
        // In Stock
        $item1 = InventoryItem::factory()->create([
            'available_quantity' => 50,
            'reorder_level' => 10,
        ]);
        $this->assertEquals('In Stock', $item1->status);

        // Low Stock
        $item2 = InventoryItem::factory()->create([
            'available_quantity' => 5,
            'reorder_level' => 10,
        ]);
        $this->assertEquals('Low Stock', $item2->status);

        // Out of Stock
        $item3 = InventoryItem::factory()->create([
            'available_quantity' => 0,
            'reorder_level' => 10,
        ]);
        $this->assertEquals('Out of Stock', $item3->status);
    }

    /** @test */
    public function it_calculates_total_stock_value()
    {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 10,
            'cost_price' => 100.00,
        ]);

        $this->assertEquals(1000.00, $item->total_stock_value);
    }

    /** @test */
    public function it_can_increment_stock()
    {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 10,
        ]);

        $item->incrementStock(5, 'stock_in', 'Restocked', $this->user->id);

        $this->assertEquals(15, $item->fresh()->available_quantity);
        
        // Check stock movement was created
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_in',
            'quantity_change' => 5,
            'quantity_before' => 10,
            'quantity_after' => 15,
        ]);
    }

    /** @test */
    public function it_can_decrement_stock()
    {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 10,
        ]);

        $item->decrementStock(3, 'stock_out', 'Sold', $this->user->id);

        $this->assertEquals(7, $item->fresh()->available_quantity);
        
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -3,
            'quantity_before' => 10,
            'quantity_after' => 7,
        ]);
    }

    /** @test */
    public function it_cannot_decrement_below_zero()
    {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 5,
        ]);

        $this->expectException(\Exception::class);
        $item->decrementStock(10, 'stock_out', 'Sold', $this->user->id);
    }

    /** @test */
    public function it_can_adjust_stock()
    {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 10,
        ]);

        $item->adjustStock(20, 'Inventory count adjustment', $this->user->id);

        $this->assertEquals(20, $item->fresh()->available_quantity);
        
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => 'adjustment',
            'quantity_change' => 10,
        ]);
    }

    /** @test */
    public function it_can_reserve_stock()
    {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $item->reserveStock(3);

        $item = $item->fresh();
        $this->assertEquals(7, $item->available_quantity);
        $this->assertEquals(3, $item->reserved_quantity);
    }

    /** @test */
    public function it_can_release_stock()
    {
        $item = InventoryItem::factory()->create([
            'available_quantity' => 7,
            'reserved_quantity' => 3,
        ]);

        $item->releaseStock(2);

        $item = $item->fresh();
        $this->assertEquals(9, $item->available_quantity);
        $this->assertEquals(1, $item->reserved_quantity);
    }

    /** @test */
    public function it_has_low_stock_scope()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 50,
            'reorder_level' => 10,
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 5,
            'reorder_level' => 10,
        ]);

        $lowStockItems = InventoryItem::lowStock()->get();

        $this->assertCount(1, $lowStockItems);
        $this->assertEquals(5, $lowStockItems->first()->available_quantity);
    }

    /** @test */
    public function it_has_out_of_stock_scope()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 10,
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 0,
        ]);

        $outOfStockItems = InventoryItem::outOfStock()->get();

        $this->assertCount(1, $outOfStockItems);
        $this->assertEquals(0, $outOfStockItems->first()->available_quantity);
    }

    /** @test */
    public function it_checks_reorder_level()
    {
        $item1 = InventoryItem::factory()->create([
            'available_quantity' => 5,
            'reorder_level' => 10,
        ]);

        $item2 = InventoryItem::factory()->create([
            'available_quantity' => 15,
            'reorder_level' => 10,
        ]);

        $this->assertTrue($item1->checkReorderLevel());
        $this->assertFalse($item2->checkReorderLevel());
    }
}
