<?php

namespace Tests\Feature\Repairer;

use App\Models\InventoryItem;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMaterialPlanningGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_work_is_blocked_when_required_material_is_unavailable(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);

        $repair = RepairRequest::create([
            'request_id' => 'REP-PLAN-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Test Customer',
            'email' => 'repair-planning@example.com',
            'phone' => '09171234567',
            'shop_owner_id' => $shop->id,
            'assigned_repairer_id' => $repairer->id,
            'total' => 500,
            'status' => 'pending',
        ]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Industrial Glue',
            'sku' => 'RM-IG-1',
            'category' => 'repair_materials',
            'available_quantity' => 0,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 1,
            'actual_quantity' => 0,
            'is_critical' => false,
            'tolerance_percent' => 20,
        ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-start");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.readiness_state', 'blocked');
    }

    public function test_start_work_is_blocked_when_any_material_is_short(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);

        $repair = RepairRequest::create([
            'request_id' => 'REP-RISK-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Risk Customer',
            'email' => 'repair-risk@example.com',
            'phone' => '09171234567',
            'shop_owner_id' => $shop->id,
            'assigned_repairer_id' => $repairer->id,
            'total' => 500,
            'status' => 'pending',
        ]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Laces',
            'sku' => 'RM-LACE-1',
            'category' => 'repair_materials',
            'available_quantity' => 0,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 2,
            'actual_quantity' => 0,
            'is_critical' => false,
            'tolerance_percent' => 20,
        ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-start");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.readiness_state', 'blocked');
    }
}
