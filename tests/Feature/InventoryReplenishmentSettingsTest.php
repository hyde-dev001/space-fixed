<?php

namespace Tests\Feature;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryReplenishmentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwner;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.defaults.guard' => 'user']);
        $this->shopOwner = ShopOwner::factory()->create(['business_type' => 'both']);
        $this->user = User::factory()->for($this->shopOwner)->create();

        Permission::findOrCreate('access-upload-inventory', 'user');
        Permission::findOrCreate('inventory.edit', 'user');
        $this->user->givePermissionTo(['access-upload-inventory', 'inventory.edit']);
    }

    /** @test */
    public function an_authorized_inventory_user_can_update_explicit_size_settings(): void
    {
        $item = InventoryItem::factory()->create(['shop_owner_id' => $this->shopOwner->id, 'category' => 'shoes']);
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
        ]);

        $response = $this->actingAs($this->user, 'user')
            ->putJson("/api/erp/inventory/items/{$item->id}/replenishment-settings", [
                'targets' => [[
                    'type' => 'size',
                    'id' => $size->id,
                    'auto_stock_request_enabled' => true,
                    'reorder_level' => 5,
                    'reorder_quantity' => 20,
                ]],
            ]);

        $response->assertOk()
            ->assertJsonPath('item.color_variants.0.sizes.0.auto_stock_request_enabled', true)
            ->assertJsonPath('item.color_variants.0.sizes.0.reorder_level', 5)
            ->assertJsonPath('item.color_variants.0.sizes.0.reorder_quantity', 20);

        $this->assertDatabaseHas('inventory_sizes', [
            'id' => $size->id,
            'auto_stock_request_enabled' => true,
            'reorder_level' => 5,
            'reorder_quantity' => 20,
        ]);
    }

    /** @test */
    public function a_user_without_inventory_edit_cannot_update_replenishment_settings(): void
    {
        $this->user->revokePermissionTo('inventory.edit');
        $item = InventoryItem::factory()->create(['shop_owner_id' => $this->shopOwner->id]);

        $this->actingAs($this->user, 'user')
            ->putJson("/api/erp/inventory/items/{$item->id}/replenishment-settings", [
                'targets' => [[
                    'type' => 'item',
                    'id' => $item->id,
                    'auto_stock_request_enabled' => true,
                    'reorder_level' => 5,
                    'reorder_quantity' => 20,
                ]],
            ])
            ->assertForbidden();
    }

    /** @test */
    public function a_target_from_another_shop_is_rejected_without_partial_updates(): void
    {
        $item = InventoryItem::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $foreignShop = ShopOwner::factory()->create();
        $foreignItem = InventoryItem::factory()->create(['shop_owner_id' => $foreignShop->id]);
        $originalLevel = (int) $item->reorder_level;

        $this->actingAs($this->user, 'user')
            ->putJson("/api/erp/inventory/items/{$item->id}/replenishment-settings", [
                'targets' => [
                    [
                        'type' => 'item',
                        'id' => $item->id,
                        'auto_stock_request_enabled' => true,
                        'reorder_level' => 3,
                        'reorder_quantity' => 20,
                    ],
                    [
                        'type' => 'item',
                        'id' => $foreignItem->id,
                        'auto_stock_request_enabled' => true,
                        'reorder_level' => 1,
                        'reorder_quantity' => 10,
                    ],
                ],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'reorder_level' => $originalLevel,
        ]);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $foreignItem->id,
            'reorder_level' => $foreignItem->reorder_level,
        ]);
    }
}
