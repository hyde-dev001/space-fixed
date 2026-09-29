<?php

namespace Tests\Feature\Manager;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagerInventoryOverviewTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $this->manager = User::factory()->for($this->shop)->create(['role' => 'Manager']);
        Role::findOrCreate('Manager', 'user');
        $this->manager->assignRole('Manager');
    }

    public function test_inventory_metrics_are_shop_aggregates_independent_of_filtered_page(): void
    {
        InventoryItem::factory()->for($this->shop)->create([
            'name' => 'Shoe One',
            'category' => 'shoes',
            'available_quantity' => 12,
            'reorder_level' => 5,
        ]);
        InventoryItem::factory()->for($this->shop)->create([
            'name' => 'Shoe Two',
            'category' => 'shoes',
            'available_quantity' => 2,
            'reorder_level' => 5,
        ]);
        InventoryItem::factory()->for($this->shop)->create([
            'name' => 'Repair Glue',
            'category' => 'repair_materials',
            'available_quantity' => 0,
            'reorder_level' => 5,
        ]);
        InventoryItem::factory()->for($this->shop)->create([
            'name' => 'Inactive Shoe',
            'category' => 'shoes',
            'available_quantity' => 99,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/inventory-overview?category=shoes&per_page=5');

        $response->assertOk();
        $data = $response->json();

        $this->assertSame(14, $data['metrics']['total_quantity']);
        $this->assertSame(1, $data['metrics']['low_stock_count']);
        $this->assertSame(1, $data['metrics']['out_of_stock_count']);
        $this->assertCount(2, $data['items']['data']);
        $this->assertSame('shoes', $data['items']['data'][0]['category']);
        $this->assertSame('shoes', $data['items']['data'][1]['category']);
        $this->assertArrayHasKey('last_updated_at', $data);
        $this->assertArrayHasKey('snapshot', $data);
    }

    public function test_inventory_overview_includes_active_products_not_backed_by_inventory_items(): void
    {
        Product::create([
            'shop_owner_id' => $this->shop->id,
            'name' => 'Nike Air Max 270',
            'slug' => 'nike-air-max-270-manager-test',
            'price' => 6499,
            'category' => 'shoes',
            'stock_quantity' => 12,
            'is_active' => true,
            'sku' => 'NIKE-AM270-MANAGER-TEST',
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/inventory-overview?category=shoes&per_page=5');

        $response->assertOk();
        $data = $response->json();

        $this->assertSame(1, $data['items']['total']);
        $this->assertSame('Nike Air Max 270', $data['items']['data'][0]['name']);
        $this->assertSame('shoes', $data['items']['data'][0]['category']);
        $this->assertSame(12, $data['items']['data'][0]['quantity']);
        $this->assertSame('product', $data['items']['data'][0]['source_type']);
        $this->assertSame('In Stock', $data['items']['data'][0]['status']);
        $this->assertSame(12, $data['metrics']['total_quantity']);
        $this->assertSame(['shoes'], $data['categories']);
    }

    public function test_retail_manager_inventory_excludes_repair_materials(): void
    {
        $this->shop->update(['business_type' => 'retail']);

        Product::create([
            'shop_owner_id' => $this->shop->id,
            'name' => 'Retail Shoe',
            'slug' => 'retail-shoe-manager-test',
            'price' => 4999,
            'category' => 'shoes',
            'stock_quantity' => 8,
            'is_active' => true,
            'sku' => 'RETAIL-SHOE-MANAGER-TEST',
        ]);
        InventoryItem::factory()->for($this->shop)->create([
            'name' => 'Repair Glue',
            'category' => 'repair_materials',
            'available_quantity' => 20,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/inventory-overview?per_page=5');

        $response->assertOk();
        $data = $response->json();

        $this->assertSame(1, $data['items']['total']);
        $this->assertSame('Retail Shoe', $data['items']['data'][0]['name']);
        $this->assertSame(['shoes'], $data['categories']);
    }

    public function test_inventory_filters_are_applied_before_pagination(): void
    {
        InventoryItem::factory(6)->for($this->shop)->create([
            'category' => 'shoes',
            'is_active' => true,
        ]);
        InventoryItem::factory(2)->for($this->shop)->create([
            'category' => 'repair_materials',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/inventory-overview?category=repair_materials&per_page=5');

        $response->assertOk();
        $data = $response->json();

        $this->assertSame(2, $data['items']['total']);
        $this->assertSame(1, $data['items']['last_page']);
        $this->assertCount(2, $data['items']['data']);
        $this->assertTrue(collect($data['items']['data'])->every(
            fn (array $item): bool => $item['category'] === 'repair_materials'
        ));
    }

    public function test_inventory_errors_do_not_expose_internal_exception_details(): void
    {
        $response = $this->actingAs($this->manager, 'user')
            ->getJson('/api/manager/inventory-overview?per_page=not-a-number');

        $response->assertStatus(422);
        $response->assertJsonMissingPath('exception');
        $response->assertJsonMissingPath('trace');
    }
}
