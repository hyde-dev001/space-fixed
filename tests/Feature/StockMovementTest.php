<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->shopOwner = ShopOwner::factory()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_lists_stock_movements()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        StockMovement::factory()->count(5)->create([
            'inventory_item_id' => $item->id,
            'performed_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/erp/inventory/movements');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'movement_type',
                        'quantity_change',
                        'performed_at',
                    ]
                ],
            ]);
    }

    /** @test */
    public function it_creates_stock_movement()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 10,
        ]);

        $response = $this->postJson('/api/erp/inventory/movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_in',
            'quantity_change' => 5,
            'notes' => 'Restocked',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(15, $item->fresh()->available_quantity);
    }

    /** @test */
    public function it_gets_movement_metrics()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        StockMovement::factory()->create([
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_in',
            'quantity_change' => 50,
        ]);

        StockMovement::factory()->create([
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -10,
        ]);

        $response = $this->getJson('/api/erp/inventory/movements/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_movements',
                'stock_in_total',
                'stock_out_total',
            ]);
    }

    /** @test */
    public function it_filters_movements_by_type()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        StockMovement::factory()->create([
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_in',
        ]);

        StockMovement::factory()->create([
            'inventory_item_id' => $item->id,
            'movement_type' => 'stock_out',
        ]);

        $response = $this->getJson('/api/erp/inventory/movements?movement_type=stock_in');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
