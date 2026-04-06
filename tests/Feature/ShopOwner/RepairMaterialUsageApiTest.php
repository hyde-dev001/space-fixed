<?php

namespace Tests\Feature\ShopOwner;

use App\Models\InventoryItem;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairMaterialUsageApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_owner_can_log_get_and_remove_repair_material_usage(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $repair = RepairRequest::create([
            'request_id' => 'REP-SHOP-MAT-0001',
            'customer_name' => 'Material Test Customer',
            'email' => 'shopowner-material@example.test',
            'phone' => '09170000111',
            'shoe_type' => 'Sneakers',
            'description' => 'Shop owner material usage test',
            'shop_owner_id' => $shopOwner->id,
            'assigned_repairer_id' => null,
            'status' => 'in_progress',
            'images' => [],
            'total' => 700,
            'final_total' => 700,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
            'delivery_method' => 'walk_in',
        ]);

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Adhesive A',
            'sku' => 'MAT-ADH-A',
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'reorder_level' => 2,
            'is_active' => true,
            'price' => 100,
            'cost_price' => 60,
        ]);

        $logResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson(
            "/api/shop-owner/repairs/{$repair->id}/materials",
            [
                'inventory_item_id' => $material->id,
                'quantity_used' => 3,
                'notes' => 'Used for sole re-glue',
            ]
        );

        $logResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity_used', 3);

        $usageId = (int) $logResponse->json('data.id');

        $this->assertDatabaseHas('repair_material_usages', [
            'id' => $usageId,
            'repair_request_id' => $repair->id,
            'inventory_item_id' => $material->id,
            'quantity_used' => 3,
        ]);

        $this->assertSame(7, (int) $material->fresh()->available_quantity);

        $getResponse = $this->actingAs($shopOwner, 'shop_owner')->getJson(
            "/api/shop-owner/repairs/{$repair->id}/materials"
        );

        $getResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.usages');

        $deleteResponse = $this->actingAs($shopOwner, 'shop_owner')->deleteJson(
            "/api/shop-owner/repairs/{$repair->id}/materials/{$usageId}"
        );

        $deleteResponse->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseMissing('repair_material_usages', [
            'id' => $usageId,
        ]);

        $this->assertSame(10, (int) $material->fresh()->available_quantity);
    }

    #[Test]
    public function shop_owner_cannot_access_material_usage_of_another_shop(): void
    {
        $ownerA = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $ownerB = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);

        $repairOfB = RepairRequest::create([
            'request_id' => 'REP-SHOP-MAT-0002',
            'customer_name' => 'Other Shop Customer',
            'email' => 'other-shop@example.test',
            'phone' => '09170000222',
            'shoe_type' => 'Boots',
            'description' => 'Cross-shop access test',
            'shop_owner_id' => $ownerB->id,
            'assigned_repairer_id' => null,
            'status' => 'in_progress',
            'images' => [],
            'total' => 500,
            'final_total' => 500,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
            'delivery_method' => 'walk_in',
        ]);

        $this->actingAs($ownerA, 'shop_owner')
            ->getJson("/api/shop-owner/repairs/{$repairOfB->id}/materials")
            ->assertStatus(404);
    }
}
