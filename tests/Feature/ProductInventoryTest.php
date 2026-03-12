<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductInventoryTest extends TestCase
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
    public function it_lists_inventory_products()
    {
        InventoryItem::factory()->count(5)->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->getJson('/api/erp/inventory/products');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'sku',
                        'available_quantity',
                        'status',
                    ]
                ],
                'current_page',
                'total',
            ]);
    }

    /** @test */
    public function it_shows_single_product()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->getJson("/api/erp/inventory/products/{$item->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
            ]);
    }

    /** @test */
    public function it_updates_product_quantity()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 10,
        ]);

        $response = $this->putJson("/api/erp/inventory/products/{$item->id}/quantity", [
            'available_quantity' => 20,
            'movement_type' => 'adjustment',
            'notes' => 'Inventory count',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(20, $item->fresh()->available_quantity);
    }

    /** @test */
    public function it_validates_quantity_update()
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        $response = $this->putJson("/api/erp/inventory/products/{$item->id}/quantity", [
            'available_quantity' => -5,
            'movement_type' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['available_quantity', 'movement_type']);
    }

    /** @test */
    public function it_filters_products_by_search()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'name' => 'Nike Air Max',
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'name' => 'Adidas Ultraboost',
        ]);

        $response = $this->getJson('/api/erp/inventory/products?search=Nike');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function it_filters_products_by_category()
    {
        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'shoes',
        ]);

        InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'accessories',
        ]);

        $response = $this->getJson('/api/erp/inventory/products?category=shoes');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
