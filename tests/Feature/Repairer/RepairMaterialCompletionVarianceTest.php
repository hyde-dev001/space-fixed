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

    public function test_mark_completed_requires_variance_confirmation_for_repairer_until_override_is_confirmed(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        /** @var User $repairer */

        $repair = RepairRequest::create([
            'request_id' => 'REP-VAR-COMP-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Variance Completion Customer',
            'email' => 'repair-variance-completed@example.com',
            'phone' => '09171234567',
            'shop_owner_id' => $shop->id,
            'assigned_repairer_id' => $repairer->id,
            'total' => 500,
            'status' => 'in_progress',
        ]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Repair Glue',
            'sku' => 'RM-GLUE-COMP-1',
            'category' => 'repair_materials',
            'available_quantity' => 100,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 1,
            'actual_quantity' => 2,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'variance_status' => 'within_tolerance',
            'variance_note' => null,
        ]);

        $blockedResponse = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-completed", [
                'completion_notes' => 'Attempt to complete without variance override.',
                'no_materials_used_confirmed' => true,
            ]);

        $blockedResponse->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_variance_review', true)
            ->assertJsonPath('data.readiness_state', 'variance_review_needed');

        $this->assertSame('in_progress', (string) $repair->fresh()->status);

        $overrideResponse = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-completed", [
                'completion_notes' => 'Confirmed variance and completed repair.',
                'no_materials_used_confirmed' => true,
                'variance_override_confirmed' => true,
            ]);

        $overrideResponse->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', (string) $repair->fresh()->status);
    }

    public function test_mark_ready_requires_variance_confirmation_for_repairer_until_override_is_confirmed(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        /** @var User $repairer */

        $repair = RepairRequest::create([
            'request_id' => 'REP-VAR-READY-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Variance Ready Customer',
            'email' => 'repair-variance-ready@example.com',
            'phone' => '09171234567',
            'shop_owner_id' => $shop->id,
            'assigned_repairer_id' => $repairer->id,
            'total' => 500,
            'status' => 'in_progress',
        ]);

        $item = InventoryItem::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Repair Thread',
            'sku' => 'RM-TH-READY-1',
            'category' => 'repair_materials',
            'available_quantity' => 100,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $item->id,
            'planned_quantity' => 1,
            'actual_quantity' => 2,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'variance_status' => 'within_tolerance',
            'variance_note' => null,
        ]);

        $blockedResponse = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-ready", [
                'pickup_instructions' => 'Attempt to mark ready without variance override.',
                'no_materials_used_confirmed' => true,
            ]);

        $blockedResponse->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_variance_review', true)
            ->assertJsonPath('data.readiness_state', 'variance_review_needed');

        $this->assertSame('in_progress', (string) $repair->fresh()->status);

        $overrideResponse = $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-ready", [
                'pickup_instructions' => 'Confirmed variance and marked ready.',
                'no_materials_used_confirmed' => true,
                'variance_override_confirmed' => true,
            ]);

        $overrideResponse->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('ready_for_pickup', (string) $repair->fresh()->status);
    }
}
