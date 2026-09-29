<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RepairMaterialInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('individualRepairBusinessTypes')]
    public function test_individual_repair_owner_can_list_and_create_materials(string $businessType): void
    {
        config(['shop_modules.enforcement_enabled' => true]);

        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => $businessType,
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'repair_operations',
            'enabled' => true,
        ]);

        $existingMaterial = InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'name' => "Existing {$businessType} Material",
            'category' => 'repair_materials',
            'available_quantity' => 12,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/inventory/items?category=repair_materials&per_page=100')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $existingMaterial->id,
                'name' => $existingMaterial->name,
            ]);

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/inventory/items', [
                'name' => "New {$businessType} Material",
                'category' => 'repair_materials',
                'available_quantity' => 5,
                'unit' => 'pcs',
            ])
            ->assertCreated()
            ->assertJsonPath('item.name', "New {$businessType} Material")
            ->assertJsonPath('item.category', 'repair_materials');

        $this->assertDatabaseHas('inventory_items', [
            'shop_owner_id' => $owner->id,
            'name' => "New {$businessType} Material",
            'category' => 'repair_materials',
        ]);
    }

    public function test_retail_individual_owner_cannot_use_repair_material_inventory_endpoint(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);

        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/inventory/items?category=repair_materials')
            ->assertForbidden();

        $this->actingAs($owner, 'shop_owner')
            ->postJson('/api/shop-owner/inventory/items', [
                'name' => 'Retail Cannot Add Repair Material',
                'category' => 'repair_materials',
                'available_quantity' => 5,
            ])
            ->assertForbidden();
    }

    public function test_owner_repair_material_routes_are_gated_by_repair_operations(): void
    {
        $routes = config('shop_modules.routes', []);

        foreach ([
            'shop_owner.inventory.items.index',
            'shop_owner.inventory.items.store',
            'shop_owner.inventory.items.update',
            'shop_owner.inventory.items.destroy',
            'shop_owner.inventory.items.restore',
        ] as $routeName) {
            $route = $routes[$routeName] ?? null;

            $this->assertIsArray($route, $routeName);
            $this->assertSame(['repair_operations'], $route['module_keys'], $routeName);
            $this->assertSame(['individual'], $route['registration_types'], $routeName);
            $this->assertSame(['repair', 'both'], $route['business_types'], $routeName);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function individualRepairBusinessTypes(): array
    {
        return [
            'repair shop' => ['repair'],
            'combined shop' => ['both'],
        ];
    }
}
