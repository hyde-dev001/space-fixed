<?php

namespace Tests\Feature\Repairer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairMaterialTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_template_and_plan_tables_exist_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('repair_material_template_items'));
        $this->assertTrue(Schema::hasTable('repair_material_plan_items'));
        $this->assertTrue(Schema::hasColumn('stock_request_approvals', 'approval_stage'));

        $this->assertTrue(Schema::hasColumns('repair_material_template_items', [
            'inventory_item_id', 'template_type', 'template_id', 'default_quantity', 'is_critical', 'tolerance_percent',
        ]));

        $this->assertTrue(Schema::hasColumns('repair_material_plan_items', [
            'repair_request_id', 'inventory_item_id', 'planned_quantity', 'actual_quantity', 'is_critical', 'tolerance_percent', 'variance_status',
        ]));
    }

    public function test_repair_package_can_store_inventory_linked_material_template_items(): void
    {
        $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);

        $package = \App\Models\RepairPackage::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Basic Restore',
            'package_price' => 1000,
            'status' => 'active',
            'created_by' => $repairer->id,
        ]);

        $material = \App\Models\InventoryItem::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Shoe Glue',
            'sku' => 'RM-GLUE-01',
            'category' => 'repair_materials',
            'available_quantity' => 20,
        ]);

        $package->materialTemplateItems()->create([
            'shop_owner_id' => $shop->id,
            'inventory_item_id' => $material->id,
            'template_type' => 'repair_package',
            'template_id' => $package->id,
            'default_quantity' => 1,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'created_by' => $repairer->id,
        ]);

        $this->assertCount(1, $package->fresh()->materialTemplateItems);
    }

    public function test_package_template_rejects_non_existing_inventory_item_ids(): void
    {
        $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);

        $serviceA = \App\Models\RepairService::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Service A',
            'category' => 'General',
            'price' => 500,
            'duration' => '30 min',
            'status' => 'Active',
        ]);

        $serviceB = \App\Models\RepairService::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Service B',
            'category' => 'General',
            'price' => 700,
            'duration' => '45 min',
            'status' => 'Active',
        ]);

        $response = $this->actingAs($repairer, 'user')->postJson('/api/repair-packages', [
            'name' => 'Deep Restore',
            'package_price' => 1200,
            'status' => 'active',
            'service_ids' => [$serviceA->id, $serviceB->id],
            'material_templates' => [
                ['inventory_item_id' => 999999, 'default_quantity' => 1, 'is_critical' => true, 'tolerance_percent' => 20],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_package_create_accepts_inventory_linked_material_template_payload(): void
    {
        $shop = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = \App\Models\User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);

        $serviceA = \App\Models\RepairService::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Service C',
            'category' => 'General',
            'price' => 500,
            'duration' => '30 min',
            'status' => 'Active',
        ]);

        $serviceB = \App\Models\RepairService::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Service D',
            'category' => 'General',
            'price' => 700,
            'duration' => '45 min',
            'status' => 'Active',
        ]);

        $material = \App\Models\InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Shoe Glue',
            'sku' => 'RM-GLUE-02',
            'category' => 'repair_materials',
            'available_quantity' => 10,
        ]);

        $response = $this->actingAs($repairer, 'user')->postJson('/api/repair-packages', [
            'name' => 'Template Check Package',
            'package_price' => 900,
            'status' => 'active',
            'service_ids' => [$serviceA->id, $serviceB->id],
            'material_templates' => [
                ['inventory_item_id' => $material->id, 'default_quantity' => 1, 'is_critical' => true, 'tolerance_percent' => 20],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }
}
