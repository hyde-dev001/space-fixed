<?php

namespace Tests\Feature;

use App\Models\InventoryImage;
use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Erp\ShopOwnerInventoryReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-product-inventory', 'user');
        Permission::findOrCreate('inventory.adjust_stock', 'user');
        
        $this->shopOwner = ShopOwner::factory()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->user->givePermissionTo('access-product-inventory');
        $this->user->givePermissionTo('inventory.adjust_stock');
        $this->actingAs($this->user, 'user');
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
    public function test_inventory_image_paths_are_canonical_and_missing_files_are_omitted(): void
    {
        Storage::fake('public');
        $this->shopOwner->update(['business_type' => 'both']);
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'shoes',
            'main_image' => 'public/inventory/1/front.jpg',
        ]);
        $existingPaths = [
            '/storage/inventory/1/front.jpg' => 'inventory/1/front.jpg',
            'storage/inventory/1/side.jpg' => 'inventory/1/side.jpg',
            'public/inventory/1/back.jpg' => 'inventory/1/back.jpg',
        ];
        $sortOrder = 0;
        foreach ($existingPaths as $path => $storagePath) {
            Storage::disk('public')->put($storagePath, 'image');
            InventoryImage::create([
                'inventory_item_id' => $item->id,
                'image_path' => $path,
                'sort_order' => $sortOrder++,
            ]);
        }
        InventoryImage::create([
            'inventory_item_id' => $item->id,
            'image_path' => 'storage/inventory/1/missing.jpg',
            'sort_order' => $sortOrder,
        ]);

        $response = $this->getJson('/api/erp/inventory/products');

        $response->assertOk();
        $this->assertSame('inventory/1/front.jpg', $response->json('data.0.main_image'));
        $this->assertSame([
            'inventory/1/back.jpg',
            'inventory/1/front.jpg',
            'inventory/1/side.jpg',
        ], collect($response->json('data.0.images'))
            ->pluck('image_path')
            ->sort()
            ->values()
            ->all());
        $this->assertCount(3, $response->json('data.0.images'));
        $this->assertTrue(collect($response->json('data.0.images'))
            ->every(fn (array $image): bool => str_ends_with($image['url'], '/storage/' . $image['image_path'])));

        $row = app(ShopOwnerInventoryReadService::class)
            ->rows($this->shopOwner->id)
            ->firstWhere('id', $item->id);
        $this->assertSame('inventory/1/front.jpg', $row['main_image']);
        $this->assertSame([
            'inventory/1/back.jpg',
            'inventory/1/front.jpg',
            'inventory/1/side.jpg',
        ], collect($row['images'])
            ->pluck('image_path')
            ->sort()
            ->values()
            ->all());

        $itemsResponse = $this->getJson('/api/erp/inventory/items');

        $itemsResponse->assertOk();
        $this->assertSame('inventory/1/front.jpg', $itemsResponse->json('data.0.main_image'));
        $this->assertCount(3, $itemsResponse->json('data.0.images'));
    }
}
