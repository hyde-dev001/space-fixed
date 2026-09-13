<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryItemWriteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected ShopOwner $shopOwner;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopOwner = ShopOwner::factory()->create([
            'business_type' => 'repair',
        ]);
        $this->user = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);

        Permission::findOrCreate('access-upload-inventory', 'user');
        Permission::findOrCreate('inventory.create', 'user');
        Permission::findOrCreate('inventory.edit', 'user');

        $this->user->givePermissionTo('access-upload-inventory');
        $this->actingAs($this->user, 'user');
    }

    /** @test */
    public function item_create_and_update_require_the_inventory_policy_permissions(): void
    {
        $payload = [
            'name' => 'Repair Glue',
            'category' => 'repair_materials',
            'available_quantity' => 5,
            'reorder_level' => 2,
            'reorder_quantity' => 10,
            'auto_stock_request_enabled' => true,
            'unit' => 'pcs',
        ];

        $this->postJson('/api/erp/inventory/items', $payload)
            ->assertForbidden();

        $this->user->givePermissionTo('inventory.create');

        $created = $this->postJson('/api/erp/inventory/items', $payload)
            ->assertCreated()
            ->assertJsonPath('item.auto_stock_request_enabled', true);

        $itemId = (int) $created->json('item.id');

        $this->putJson('/api/erp/inventory/items/' . $itemId, [
            'name' => 'Repair Glue',
            'category' => 'repair_materials',
            'auto_stock_request_enabled' => false,
        ])->assertForbidden();

        $this->user->givePermissionTo('inventory.edit');

        $this->putJson('/api/erp/inventory/items/' . $itemId, [
            'name' => 'Repair Glue',
            'category' => 'repair_materials',
            'auto_stock_request_enabled' => false,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_items', [
            'id' => $itemId,
            'auto_stock_request_enabled' => false,
        ]);
    }

    /** @test */
    public function inventory_access_permission_can_archive_and_restore_without_deleting_history(): void
    {
        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'repair_materials',
            'is_active' => true,
        ]);
        $movement = StockMovement::factory()->create([
            'inventory_item_id' => $item->id,
        ]);

        $this->deleteJson('/api/erp/inventory/items/' . $item->id)
            ->assertOk()
            ->assertJsonPath('message', 'Inventory item archived successfully');

        $this->assertSoftDeleted('inventory_items', ['id' => $item->id]);
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id, 'inventory_item_id' => $item->id]);
        $this->getJson('/api/erp/inventory/items')
            ->assertOk()
            ->assertJsonMissing(['id' => $item->id]);
        $this->getJson('/api/erp/inventory/items?archived=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $item->id]);

        $this->postJson('/api/erp/inventory/items/' . $item->id . '/restore')
            ->assertOk();

        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'deleted_at' => null]);
    }
}
