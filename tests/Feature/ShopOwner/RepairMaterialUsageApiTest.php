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
    public function shop_owner_can_log_zero_quantity_material_usage_with_note_without_deducting_stock(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $repair = RepairRequest::create([
            'request_id' => 'REP-SHOP-MAT-ZERO-0001',
            'customer_name' => 'Zero Qty Customer',
            'email' => 'shopowner-zero-qty@example.test',
            'phone' => '09170000123',
            'shoe_type' => 'Sneakers',
            'description' => 'Zero quantity material usage test',
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
            'name' => 'Carry-over Material',
            'sku' => 'MAT-CARRY-OWNER',
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
                'quantity_used' => 0,
                'notes' => 'Used remaining material from previous repair.',
            ]
        );

        $logResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity_used', 0);

        $this->assertSame(10, (int) $material->fresh()->available_quantity);

        $this->assertDatabaseHas('repair_material_usages', [
            'repair_request_id' => $repair->id,
            'inventory_item_id' => $material->id,
            'quantity_used' => 0,
            'notes' => 'Used remaining material from previous repair.',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $material->id,
            'movement_type' => 'repair_usage',
            'quantity_change' => 0,
            'reference_type' => 'repair_request',
            'reference_id' => $repair->id,
            'notes' => 'Used remaining material from previous repair.',
        ]);
    }

    #[Test]
    public function shop_owner_mark_ready_is_blocked_when_material_variance_requires_review(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $repair = RepairRequest::create([
            'request_id' => 'REP-SHOP-MAT-VAR-0001',
            'customer_name' => 'Variance Customer',
            'email' => 'shopowner-variance@example.test',
            'phone' => '09170000444',
            'shoe_type' => 'Boots',
            'description' => 'Variance gate test',
            'shop_owner_id' => $shopOwner->id,
            'assigned_repairer_id' => null,
            'status' => 'in_progress',
            'images' => [],
            'total' => 900,
            'final_total' => 900,
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
            'name' => 'Variance Material',
            'sku' => 'MAT-VAR-OWNER',
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'reorder_level' => 3,
            'is_active' => true,
            'price' => 120,
            'cost_price' => 70,
        ]);

        $repair->materialPlanItems()->create([
            'inventory_item_id' => $material->id,
            'planned_quantity' => 5,
            'actual_quantity' => 0,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'variance_status' => 'within_tolerance',
            'variance_note' => null,
        ]);

        $this->actingAs($shopOwner, 'shop_owner')->postJson(
            "/api/shop-owner/repairs/{$repair->id}/materials",
            [
                'inventory_item_id' => $material->id,
                'quantity_used' => 1,
                'notes' => 'Initial usage.',
            ]
        )->assertStatus(201);

        $markReadyResponse = $this->actingAs($shopOwner, 'shop_owner')->postJson(
            "/api/shop-owner/repairs/{$repair->id}/mark-ready",
            [
                'pickup_instructions' => 'Pickup after 5PM.',
            ]
        );

        $markReadyResponse->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_variance_review', true)
            ->assertJsonPath('data.readiness_state', 'variance_review_needed');

        $repair->refresh();
        $this->assertSame('in_progress', $repair->status);
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

    #[Test]
    public function shop_owner_cannot_mark_ready_without_material_logs_when_templates_exist(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);

        $service = \App\Models\RepairService::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Template-Backed Service',
            'category' => 'Cleaning',
            'price' => 750,
            'duration' => '40 min',
            'status' => 'Active',
        ]);

        $package = \App\Models\RepairPackage::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Template Package',
            'description' => 'Package used for mark-ready gate test',
            'package_price' => 750,
            'status' => 'active',
        ]);
        $package->services()->sync([$service->id]);

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Template Gate Material',
            'sku' => 'MAT-TPL-GATE-01',
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'reorder_level' => 2,
            'is_active' => true,
        ]);

        $package->materialTemplateItems()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $material->id,
            'template_type' => 'repair_package',
            'template_id' => $package->id,
            'default_quantity' => 1,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'created_by' => null,
        ]);

        $repair = RepairRequest::create([
            'request_id' => 'REP-SHOP-MAT-GATE-0001',
            'customer_name' => 'Template Gate Customer',
            'email' => 'template-gate@example.test',
            'phone' => '09170000999',
            'shoe_type' => 'Sneakers',
            'description' => 'Should block ready transition without material logs',
            'shop_owner_id' => $shopOwner->id,
            'repair_package_id' => $package->id,
            'assigned_repairer_id' => null,
            'status' => 'in_progress',
            'images' => [],
            'total' => 750,
            'final_total' => 750,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
            'delivery_method' => 'walk_in',
        ]);

        $repair->services()->sync([$service->id]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/mark-ready", [
                'pickup_instructions' => 'Try ready without material logs',
                'no_materials_used_confirmed' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_material_logging', true);

        $this->assertSame('in_progress', (string) $repair->fresh()->status);
    }
}
