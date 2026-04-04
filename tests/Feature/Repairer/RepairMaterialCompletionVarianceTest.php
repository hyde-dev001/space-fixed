<?php

namespace Tests\Feature\Repairer;

use App\Models\InventoryItem;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMaterialCompletionVarianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_blocks_when_variance_exceeds_tolerance_without_note(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        /** @var User $repairer */

        $repair = RepairRequest::create([
            'request_id' => 'REP-VAR-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Variance Customer',
            'email' => 'repair-variance@example.com',
            'phone' => '09171234567',
            'shop_owner_id' => $shop->id,
            'assigned_repairer_id' => $repairer->id,
            'total' => 500,
            'status' => 'in_progress',
        ]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Thread',
            'sku' => 'RM-TH-1',
            'category' => 'repair_materials',
            'available_quantity' => 100,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 1,
            'actual_quantity' => 2,
            'is_critical' => false,
            'tolerance_percent' => 20,
            'variance_status' => 'within_tolerance',
            'variance_note' => null,
        ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-complete");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.readiness_state', 'variance_review_needed');
    }

    public function test_completion_allows_whole_number_round_up_for_fractional_plans(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        /** @var User $repairer */

        $repair = RepairRequest::create([
            'request_id' => 'REP-VAR-FRAC-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Fractional Plan Customer',
            'email' => 'repair-variance-fractional@example.com',
            'phone' => '09171234567',
            'shop_owner_id' => $shop->id,
            'assigned_repairer_id' => $repairer->id,
            'total' => 500,
            'status' => 'in_progress',
        ]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Industrial Glue',
            'sku' => 'RM-GLUE-1',
            'category' => 'repair_materials',
            'available_quantity' => 100,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 1.2,
            'actual_quantity' => 2,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'variance_status' => 'within_tolerance',
            'variance_note' => null,
        ]);

        $response = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/materials/validate-complete");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.readiness_state', 'ready');
    }
}
