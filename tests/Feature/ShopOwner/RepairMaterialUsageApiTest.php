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

    #[Test]
    public function manual_pos_checkout_with_package_and_services_generates_template_plan_items(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
            'repair_payment_policy' => 'deposit_50',
        ]);

        $service = \App\Models\RepairService::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Deep Sole Clean',
            'category' => 'Cleaning',
            'price' => 900,
            'duration' => '45 min',
            'status' => 'Active',
        ]);

        $package = \App\Models\RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Premium Deep Clean Package',
            'description' => 'Package with template-linked materials',
            'package_price' => 900,
            'status' => 'active',
        ]);
        $package->services()->sync([$service->id]);

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Premium Cleaning Solution',
            'sku' => 'MAT-PREM-CLEAN-01',
            'available_quantity' => 12,
            'reserved_quantity' => 0,
            'reorder_level' => 2,
            'is_active' => true,
        ]);

        $package->materialTemplateItems()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $material->id,
            'template_type' => 'repair_package',
            'template_id' => $package->id,
            'default_quantity' => 2,
            'is_critical' => true,
            'tolerance_percent' => 15,
            'created_by' => null,
        ]);

        $checkoutResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Template Plan Walk-in',
            'walk_in_phone' => '09171112222',
            'idempotency_key' => 'manual-pos-template-plan-001',
            'manual_repair_subtotal' => 900,
            'manual_service_summary' => 'Premium Deep Clean Package',
            'manual_payment_policy' => 'deposit_50',
            'manual_repair_package_id' => $package->id,
            'manual_service_ids' => [$service->id],
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 450],
            ],
        ]);

        $checkoutResponse->assertOk()->assertJsonPath('success', true);

        $transactionId = (int) $checkoutResponse->json('transaction_id');
        $transaction = \App\Models\PosTransaction::query()->findOrFail($transactionId);
        $repair = RepairRequest::query()->findOrFail((int) $transaction->module_reference_id);

        $this->assertSame((int) $package->id, (int) $repair->repair_package_id);
        $this->assertSame([$service->id], $repair->services()->pluck('repair_services.id')->all());

        $materialsResponse = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson("/api/shop-owner/repairs/{$repair->id}/materials");

        $materialsResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.plan_items')
            ->assertJsonPath('data.plan_items.0.inventory_item_id', (int) $material->id)
            ->assertJsonPath('data.plan_items.0.planned_quantity', 2);
    }
}
