<?php

namespace Tests\Feature\Repairer;

use App\Models\InventoryItem;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepairerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('access-pricing-services', 'user');
        Permission::findOrCreate('access-upload-service', 'user');
        Permission::findOrCreate('access-repair-stocks', 'user');
        Role::findOrCreate('Repairer', 'user');
    }

    private function createRepairShop(array $overrides = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create(array_merge([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ], $overrides));
    }

    private function createRepairer(ShopOwner $shopOwner, array $permissions = []): User
    {
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
            'role' => 'STAFF',
        ]);

        $repairer->assignRole('Repairer');

        if (!empty($permissions)) {
            $repairer->givePermissionTo($permissions);
        }

        return $repairer;
    }

    private function createCustomer(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
        ], $overrides));
    }

    private function createService(ShopOwner $shopOwner, array $overrides = []): RepairService
    {
        return RepairService::create(array_merge([
            'name' => 'Deep Clean',
            'category' => 'Care',
            'price' => 500,
            'duration' => '45 min',
            'description' => 'Basic repair service',
            'status' => 'Active',
            'shop_owner_id' => $shopOwner->id,
        ], $overrides));
    }

    private function createAssignedRepairRequest(
        ShopOwner $shopOwner,
        User $customer,
        User $repairer,
        array $serviceIds,
        array $overrides = []
    ): RepairRequest {
        $repairRequest = RepairRequest::create(array_merge([
            'request_id' => 'REP-T-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => 'Repair Workflow Customer',
            'email' => $customer->email,
            'phone' => '09171234567',
            'shoe_type' => 'Sneakers',
            'brand' => 'Nike',
            'description' => 'Needs repair processing',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'images' => ['repair-requests/test.jpg'],
            'total' => 750,
            'final_total' => 750,
            'status' => 'assigned_to_repairer',
            'delivery_method' => 'walk_in',
            'payment_enabled' => false,
            'payment_status' => 'unpaid',
        ], $overrides));

        $repairRequest->services()->attach($serviceIds);

        return $repairRequest;
    }

    public function test_repairer_can_create_independent_service_and_package(): void
    {
        $shopOwner = $this->createRepairShop();
        $repairer = $this->createRepairer($shopOwner, ['access-pricing-services']);
        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Package Template Material',
            'sku' => 'MAT-PKG-001',
            'available_quantity' => 20,
            'is_active' => true,
        ]);

        $firstServiceResponse = $this->actingAs($repairer, 'user')->postJson('/api/repair-services', [
            'name' => 'Heel Replacement',
            'category' => 'Structural',
            'price' => 850,
            'duration' => '2 hours',
            'description' => 'Replace worn heel block',
            'status' => 'Active',
        ]);

        $firstServiceResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Heel Replacement');

        $secondServiceResponse = $this->actingAs($repairer, 'user')->postJson('/api/repair-services', [
            'name' => 'Midsole Reglue',
            'category' => 'Structural',
            'price' => 650,
            'duration' => '90 min',
            'description' => 'Secure detached midsole',
            'status' => 'Active',
        ]);

        $secondServiceResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Midsole Reglue');

        $firstServiceId = $firstServiceResponse->json('data.id');
        $secondServiceId = $secondServiceResponse->json('data.id');

        $this->assertDatabaseHas('repair_services', [
            'id' => $firstServiceId,
            'shop_owner_id' => $shopOwner->id,
            'created_by' => $repairer->id,
        ]);
        $this->assertDatabaseHas('repair_services', [
            'id' => $secondServiceId,
            'shop_owner_id' => $shopOwner->id,
            'created_by' => $repairer->id,
        ]);

        $packageResponse = $this->actingAs($repairer, 'user')->postJson('/api/repair-packages', [
            'name' => 'Structural Restore Pack',
            'description' => 'Bundle for common sole and heel repairs',
            'package_price' => 1300,
            'status' => 'active',
            'service_ids' => [$firstServiceId, $secondServiceId],
            'material_templates' => [
                [
                    'inventory_item_id' => $material->id,
                    'default_quantity' => 1,
                    'is_critical' => true,
                    'tolerance_percent' => 20,
                ],
            ],
        ]);

        $packageResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Structural Restore Pack')
            ->assertJsonCount(2, 'data.services');

        $packageId = $packageResponse->json('data.id');

        $this->assertDatabaseHas('repair_packages', [
            'id' => $packageId,
            'shop_owner_id' => $shopOwner->id,
            'created_by' => $repairer->id,
        ]);
        $this->assertDatabaseHas('repair_package_service', [
            'repair_package_id' => $packageId,
            'repair_service_id' => $firstServiceId,
        ]);
        $this->assertDatabaseHas('repair_package_service', [
            'repair_package_id' => $packageId,
            'repair_service_id' => $secondServiceId,
        ]);
    }

    public function test_repairer_can_process_an_assigned_service_request_through_completion(): void
    {
        $shopOwner = $this->createRepairShop();
        $customer = $this->createCustomer([
            'email' => 'repair-workflow@example.com',
        ]);
        $repairer = $this->createRepairer($shopOwner);
        $service = $this->createService($shopOwner, [
            'name' => 'Sole Reglue',
            'price' => 750,
        ]);

        $repairRequest = $this->createAssignedRepairRequest(
            $shopOwner,
            $customer,
            $repairer,
            [$service->id],
            [
                'total' => 750,
                'final_total' => 750,
                'status' => 'assigned_to_repairer',
                'delivery_method' => 'walk_in',
                'payment_status' => 'paid',
            ]
        );

        $acceptResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/accept"
        );

        $acceptResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $repairRequest->refresh();
        $this->assertSame('pending', $repairRequest->status);
        $this->assertNotNull($repairRequest->conversation_id);
        $this->assertDatabaseHas('conversations', [
            'id' => $repairRequest->conversation_id,
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'assigned_to_id' => $repairer->id,
            'assigned_to_type' => 'repairer',
        ]);
        $this->assertDatabaseCount('conversation_messages', 1);

        $receivedResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/mark-received"
        );

        $receivedResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $repairRequest->refresh();
        $this->assertSame('received', $repairRequest->status);
        $this->assertNotNull($repairRequest->received_at);

        $startResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/start-work"
        );

        $startResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $repairRequest->refresh();
        $this->assertSame('in_progress', $repairRequest->status);
        $this->assertNotNull($repairRequest->started_at);

        $completedResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/mark-completed",
            [
                'completion_notes' => 'Reglue and edge cleanup finished.',
                'no_materials_used_confirmed' => true,
            ]
        );

        $completedResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $repairRequest->refresh();
        $this->assertSame('completed', $repairRequest->status);
        $this->assertNotNull($repairRequest->completed_at);

        $readyResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/mark-ready",
            ['pickup_instructions' => 'Ready at front desk after 3PM.']
        );

        $readyResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $repairRequest->refresh();
        $this->assertSame('ready_for_pickup', $repairRequest->status);
        $this->assertSame('Ready at front desk after 3PM.', $repairRequest->pickup_instructions);
    }

    public function test_repairer_can_log_material_usage_restore_stock_and_request_more_inventory(): void
    {
        $shopOwner = $this->createRepairShop();
        $customer = $this->createCustomer([
            'email' => 'repair-stocks@example.com',
        ]);
        $repairer = $this->createRepairer($shopOwner, ['access-repair-stocks']);
        $service = $this->createService($shopOwner, [
            'name' => 'Glue Reinforcement',
            'price' => 600,
        ]);
        $repairRequest = $this->createAssignedRepairRequest(
            $shopOwner,
            $customer,
            $repairer,
            [$service->id],
            [
                'status' => 'in_progress',
                'total' => 600,
                'final_total' => 600,
            ]
        );

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Industrial Adhesive',
            'sku' => 'MAT-ADH-001',
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'reorder_level' => 3,
            'unit' => 'pcs',
            'is_active' => true,
            'price' => 120,
            'cost_price' => 80,
        ]);

        $overviewResponse = $this->actingAs($repairer, 'user')->getJson('/api/repairer/materials');

        $overviewResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('metrics.total_items', 10)
            ->assertJsonPath('metrics.low_stock_count', 0)
            ->assertJsonPath('metrics.out_of_stock_count', 0);

        $logUsageResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/materials",
            [
                'inventory_item_id' => $material->id,
                'quantity_used' => 3,
                'notes' => 'Used adhesive for midsole bond.',
            ]
        );

        $logUsageResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity_used', 3);

        $usageId = $logUsageResponse->json('data.id');

        $material->refresh();
        $this->assertSame(7, $material->available_quantity);
        $this->assertDatabaseHas('repair_material_usages', [
            'id' => $usageId,
            'repair_request_id' => $repairRequest->id,
            'inventory_item_id' => $material->id,
            'quantity_used' => 3,
            'used_by' => $repairer->id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $material->id,
            'movement_type' => 'repair_usage',
            'quantity_change' => -3,
            'reference_type' => 'repair_request',
            'reference_id' => $repairRequest->id,
            'performed_by' => $repairer->id,
        ]);

        $usageHistoryResponse = $this->actingAs($repairer, 'user')->getJson(
            "/api/repairer/repairs/{$repairRequest->id}/materials"
        );

        $usageHistoryResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.usages');

        $removeUsageResponse = $this->actingAs($repairer, 'user')->deleteJson(
            "/api/repairer/repairs/{$repairRequest->id}/materials/{$usageId}"
        );

        $removeUsageResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $material->refresh();
        $this->assertSame(10, $material->available_quantity);
        $this->assertDatabaseMissing('repair_material_usages', [
            'id' => $usageId,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $material->id,
            'movement_type' => 'return',
            'quantity_change' => 3,
            'reference_type' => 'repair_request',
            'reference_id' => $repairRequest->id,
            'performed_by' => $repairer->id,
        ]);

        $materialRequestResponse = $this->actingAs($repairer, 'user')->postJson('/api/repairer/material-requests', [
            'inventory_item_id' => $material->id,
            'quantity_needed' => 5,
            'priority' => 'high',
            'notes' => 'Need extra adhesive for incoming repair queue.',
            'repair_request_id' => $repairRequest->id,
        ]);

        $materialRequestResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_source', 'repair')
            ->assertJsonPath('data.repair_request_id', $repairRequest->id);

        $requestId = $materialRequestResponse->json('data.id');

        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $requestId,
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $material->id,
            'repair_request_id' => $repairRequest->id,
            'request_source' => 'repair',
            'status' => 'pending',
            'requested_by' => $repairer->id,
        ]);

        $requestsResponse = $this->actingAs($repairer, 'user')->getJson(
            "/api/repairer/material-requests?repair_request_id={$repairRequest->id}"
        );

        $requestsResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_repairer_cannot_log_fractional_material_usage_quantity(): void
    {
        $shopOwner = $this->createRepairShop();
        $customer = $this->createCustomer([
            'email' => 'repair-fractional@example.com',
        ]);
        $repairer = $this->createRepairer($shopOwner, ['access-repair-stocks']);
        $service = $this->createService($shopOwner, [
            'name' => 'Glue Touch-Up',
            'price' => 400,
        ]);

        $repairRequest = $this->createAssignedRepairRequest(
            $shopOwner,
            $customer,
            $repairer,
            [$service->id],
            [
                'status' => 'in_progress',
                'total' => 400,
                'final_total' => 400,
            ]
        );

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Precision Adhesive',
            'sku' => 'MAT-ADH-002',
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'reorder_level' => 5,
            'unit' => 'pcs',
            'is_active' => true,
            'price' => 100,
            'cost_price' => 60,
        ]);

        $response = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/materials",
            [
                'inventory_item_id' => $material->id,
                'quantity_used' => 1.5,
                'notes' => 'Should fail due to fractional quantity.',
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity_used']);

        $material->refresh();
        $this->assertSame(20, $material->available_quantity);
    }

    public function test_repairer_cannot_mark_completed_without_usage_when_template_exists(): void
    {
        $shopOwner = $this->createRepairShop();
        $customer = $this->createCustomer([
            'email' => 'repair-template-block@example.com',
        ]);
        $repairer = $this->createRepairer($shopOwner, ['access-repair-stocks']);
        $service = $this->createService($shopOwner, [
            'name' => 'Template-Based Restore',
            'price' => 650,
        ]);

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Template Adhesive',
            'sku' => 'MAT-TPL-001',
            'available_quantity' => 15,
            'reserved_quantity' => 0,
            'reorder_level' => 3,
            'unit' => 'pcs',
            'is_active' => true,
            'price' => 80,
            'cost_price' => 40,
        ]);

        $service->materialTemplateItems()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $material->id,
            'template_type' => 'repair_service',
            'template_id' => $service->id,
            'default_quantity' => 1,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'created_by' => $repairer->id,
        ]);

        $repairRequest = $this->createAssignedRepairRequest(
            $shopOwner,
            $customer,
            $repairer,
            [$service->id],
            [
                'status' => 'in_progress',
                'total' => 650,
                'final_total' => 650,
            ]
        );

        $response = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/mark-completed",
            [
                'completion_notes' => 'Attempting completion without material logs.',
                'no_materials_used_confirmed' => true,
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_material_logging', true);

        $repairRequest->refresh();
        $this->assertSame('in_progress', $repairRequest->status);
    }

    public function test_template_material_plan_is_generated_and_updated_from_usage_logs(): void
    {
        $shopOwner = $this->createRepairShop();
        $customer = $this->createCustomer([
            'email' => 'repair-template-plan@example.com',
        ]);
        $repairer = $this->createRepairer($shopOwner, ['access-repair-stocks']);
        $service = $this->createService($shopOwner, [
            'name' => 'Template Plan Service',
            'price' => 700,
        ]);

        $material = InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'category' => 'repair_materials',
            'name' => 'Template Material',
            'sku' => 'MAT-TPL-PLAN-001',
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'reorder_level' => 5,
            'unit' => 'pcs',
            'is_active' => true,
            'price' => 95,
            'cost_price' => 50,
        ]);

        $service->materialTemplateItems()->create([
            'shop_owner_id' => $shopOwner->id,
            'inventory_item_id' => $material->id,
            'template_type' => 'repair_service',
            'template_id' => $service->id,
            'default_quantity' => 2,
            'is_critical' => true,
            'tolerance_percent' => 20,
            'created_by' => $repairer->id,
        ]);

        $repairRequest = $this->createAssignedRepairRequest(
            $shopOwner,
            $customer,
            $repairer,
            [$service->id],
            [
                'status' => 'in_progress',
                'total' => 700,
                'final_total' => 700,
            ]
        );

        $usageResponse = $this->actingAs($repairer, 'user')->getJson(
            "/api/repairer/repairs/{$repairRequest->id}/materials"
        );

        $usageResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan_items.0.inventory_item_id', $material->id)
            ->assertJsonPath('data.plan_items.0.planned_quantity', 2.0)
            ->assertJsonPath('data.plan_items.0.actual_quantity', 0.0);

        $logResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/materials",
            [
                'inventory_item_id' => $material->id,
                'quantity_used' => 2,
                'notes' => 'Applied full planned quantity.',
            ]
        );

        $logResponse->assertStatus(201)
            ->assertJsonPath('success', true);

        $planItem = \App\Models\RepairMaterialPlanItem::query()
            ->where('repair_request_id', $repairRequest->id)
            ->where('inventory_item_id', $material->id)
            ->first();

        $this->assertNotNull($planItem);
        $this->assertSame(2.0, (float) $planItem->planned_quantity);
        $this->assertSame(2.0, (float) $planItem->actual_quantity);
    }

    public function test_repairer_can_ship_pickup_repairs_and_customer_can_confirm_delivery(): void
    {
        $shopOwner = $this->createRepairShop();
        $customer = $this->createCustomer([
            'email' => 'repair-shipping@example.com',
        ]);
        $repairer = $this->createRepairer($shopOwner);
        $service = $this->createService($shopOwner, [
            'name' => 'Full Restore',
            'price' => 1200,
        ]);

        $repairRequest = $this->createAssignedRepairRequest(
            $shopOwner,
            $customer,
            $repairer,
            [$service->id],
            [
                'status' => 'ready_for_pickup',
                'delivery_method' => 'pickup',
                'total' => 1200,
                'final_total' => 1200,
                'payment_policy' => 'full_upfront',
                'payment_status' => 'completed',
            ]
        );

        $shipResponse = $this->actingAs($repairer, 'user')->postJson(
            "/api/repairer/repairs/{$repairRequest->id}/ship",
            [
                'tracking_number' => 'SHIP-12345',
                'carrier_company' => 'Lalamove',
                'carrier_name' => 'Rider One',
                'carrier_phone' => '09171234567',
                'tracking_link' => 'https://tracker.example.com/SHIP-12345',
            ]
        );

        $shipResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $repairRequest->refresh();
        $this->assertSame('shipped', $repairRequest->status);
        $this->assertNotNull($repairRequest->shipped_at);
        $this->assertTrue((bool) $repairRequest->pickup_enabled);
        $this->assertSame('SHIP-12345', $repairRequest->tracking_number);
        $this->assertSame('Lalamove', $repairRequest->carrier_company);

        $confirmResponse = $this->actingAs($customer, 'user')->postJson(
            "/api/customer/repairs/{$repairRequest->id}/confirm-pickup"
        );

        $confirmResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $repairRequest->refresh();
        $this->assertSame('picked_up', $repairRequest->status);
        $this->assertNotNull($repairRequest->picked_up_at);
    }
}