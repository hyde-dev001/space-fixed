<?php

namespace Tests\Feature\Procurement;

use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\InventoryItem;
use App\Models\InventoryColorVariant;
use App\Models\InventorySize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

class ReplenishmentAndStockRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;
    protected InventoryItem $inventoryItem;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        $this->shopOwner = ShopOwner::factory()->create();
        $this->user = User::factory()->for($this->shopOwner)->create();
        Permission::findOrCreate('procurement.create_purchase_requests', 'user');
        Permission::findOrCreate('procurement.review_stock_requests', 'user');
        Permission::findOrCreate('view-inventory', 'user');
        $this->user->givePermissionTo([
            'procurement.create_purchase_requests',
            'procurement.review_stock_requests',
            'view-inventory',
        ]);
        $this->inventoryItem = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
        ]);
    }

    /** @test */
    public function user_can_create_replenishment_request()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/erp/procurement/replenishment-requests', [
                'inventory_item_id' => $this->inventoryItem->id,
                'product_name' => 'Test Product',
                'sku_code' => 'SKU-001',
                'quantity_needed' => 100,
                'priority' => 'high',
                'notes' => 'Stock running low',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'stock_request' => [
                    'id',
                    'request_number',
                    'status',
                ]
            ]);

        $this->assertDatabaseHas('stock_request_approvals', [
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);
    }

    public function test_inventory_all_sizes_request_stores_quantity_per_configured_size(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'shoes',
        ]);
        $variant = InventoryColorVariant::create([
            'inventory_item_id' => $inventory->id,
            'color_name' => 'Black',
            'quantity' => 0,
        ]);
        foreach (['3', '5', '7', '9'] as $index => $size) {
            InventorySize::create([
                'inventory_item_id' => $inventory->id,
                'inventory_color_variant_id' => $variant->id,
                'size' => $size,
                'size_system' => 'US',
                'quantity' => $index === 3 ? 0 : 10,
            ]);
        }

        $response = $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/stock-requests', [
                'inventory_item_id' => $inventory->id,
                'quantity_needed' => 50,
                'quantity_basis' => 'per_size',
                'priority' => 'high',
                'requested_size' => '',
                'requested_color' => 'black',
                'notes' => 'Restock every configured size.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('stock_request.quantity_needed', 200);
        $this->assertArrayNotHasKey('quantity_basis', $response->json('stock_request'));

        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $response->json('stock_request.id'),
            'quantity_needed' => 200,
            'requested_size' => null,
            'requested_color' => 'black',
        ]);
    }

    public function test_inventory_all_sizes_compatibility_tokens_are_stored_as_total_with_canonical_size(): void
    {
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'shoes',
        ]);

        foreach (['all', 'all_size', 'all_sizes', 'any'] as $token) {
            $payload = [
                'inventory_item_id' => $inventory->id,
                'quantity_needed' => 50,
                'priority' => 'medium',
                'requested_size' => $token,
                'notes' => "Legacy token {$token}.",
            ];
            if ($token !== 'all') {
                $payload['quantity_basis'] = 'total';
            }

            $response = $this->actingAs($this->user, 'user')
                ->postJson('/api/erp/inventory/stock-requests', $payload)
                ->assertCreated();

            $response->assertJsonPath('stock_request.quantity_needed', 50)
                ->assertJsonPath('stock_request.requested_size', null);
        }
    }

    public function test_per_size_quantity_is_rejected_by_procurement_aliases(): void
    {
        $response = $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/procurement/replenishment-requests', [
                'inventory_item_id' => $this->inventoryItem->id,
                'quantity_needed' => 50,
                'quantity_basis' => 'per_size',
                'priority' => 'medium',
                'requested_size' => '',
                'notes' => 'The legacy route must remain total-unit based.',
            ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('stock_request_approvals', [
            'inventory_item_id' => $this->inventoryItem->id,
        ]);
    }

    public function test_per_size_quantity_is_rejected_by_repair_material_route(): void
    {
        $this->shopOwner->update(['business_type' => 'both']);
        $repairItem = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'repair_materials',
        ]);

        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/request-material-approvals', [
                'inventory_item_id' => $repairItem->id,
                'quantity_needed' => 50,
                'quantity_basis' => 'per_size',
                'priority' => 'medium',
                'request_source' => 'repair',
                'notes' => 'Repair requests remain total-unit based.',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('stock_request_approvals', [
            'inventory_item_id' => $repairItem->id,
        ]);
    }

    public function test_per_size_quantity_rejects_invalid_inventory_combinations(): void
    {
        $shoe = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'shoes',
        ]);
        $variant = InventoryColorVariant::create([
            'inventory_item_id' => $shoe->id,
            'color_name' => 'Black',
            'quantity' => 0,
        ]);
        InventorySize::create([
            'inventory_item_id' => $shoe->id,
            'inventory_color_variant_id' => $variant->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 0,
        ]);

        $base = [
            'inventory_item_id' => $shoe->id,
            'quantity_needed' => 50,
            'quantity_basis' => 'per_size',
            'priority' => 'medium',
            'notes' => 'Invalid combination.',
        ];

        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/stock-requests', array_merge($base, [
                'requested_size' => 'US 8',
                'requested_color' => 'Black',
            ]))
            ->assertUnprocessable();

        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/stock-requests', array_merge($base, [
                'requested_size' => '',
            ]))
            ->assertUnprocessable();

        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/stock-requests', array_merge($base, [
                'requested_size' => '',
                'requested_color' => 'Red',
            ]))
            ->assertUnprocessable();

        $nonShoe = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'accessories',
        ]);
        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/stock-requests', array_merge($base, [
                'inventory_item_id' => $nonShoe->id,
                'requested_size' => '',
            ]))
            ->assertUnprocessable();

        $emptyShoe = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'category' => 'shoes',
        ]);
        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/stock-requests', array_merge($base, [
                'inventory_item_id' => $emptyShoe->id,
                'requested_size' => '',
            ]))
            ->assertUnprocessable();

        $this->assertDatabaseMissing('stock_request_approvals', [
            'inventory_item_id' => $shoe->id,
            'quantity_needed' => 200,
        ]);
    }

    public function test_unknown_quantity_basis_is_rejected(): void
    {
        $this->actingAs($this->user, 'user')
            ->postJson('/api/erp/inventory/stock-requests', [
                'inventory_item_id' => $this->inventoryItem->id,
                'quantity_needed' => 50,
                'quantity_basis' => 'multiplier',
                'priority' => 'medium',
                'notes' => 'Invalid marker.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_basis');
    }

    /** @test */
    public function user_can_accept_replenishment_request()
    {
        $request = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$request->id}/accept", [
                'response_notes' => 'Accepted for procurement',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $request->id,
            'status' => 'accepted',
        ]);
    }

    /** @test */
    public function user_can_reject_replenishment_request()
    {
        $request = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$request->id}/reject", [
                'rejection_reason' => 'Not required at this time',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $request->id,
            'status' => 'rejected',
        ]);
    }

    /** @test */
    public function user_can_request_additional_details_for_replenishment()
    {
        $request = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$request->id}/request-details", [
                'response_notes' => 'Please provide usage forecast',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $request->id,
            'status' => 'needs_details',
        ]);
    }

    /** @test */
    public function user_can_approve_stock_request()
    {
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/stock-requests/{$stockRequest->id}/approve", [
                'approval_notes' => 'Approved for procurement',
                'auto_create_pr' => false,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $stockRequest->id,
            'status' => 'accepted',
        ]);
    }

    /** @test */
    public function user_can_reject_stock_request()
    {
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/stock-requests/{$stockRequest->id}/reject", [
                'rejection_reason' => 'Budget constraints',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $stockRequest->id,
            'status' => 'rejected',
            'rejection_reason' => 'Budget constraints',
        ]);
    }

    /** @test */
    public function user_can_get_stock_request_metrics()
    {
        StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/erp/procurement/stock-requests/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_stock_requests',
                'pending_requests',
                'accepted_requests',
            ]);
    }

    /** @test */
    public function user_can_filter_replenishment_requests_by_status()
    {
        StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/erp/procurement/replenishment-requests?status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function complete_replenishment_workflow()
    {
        // Step 1: Create replenishment request
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/erp/procurement/replenishment-requests', [
                'inventory_item_id' => $this->inventoryItem->id,
                'product_name' => 'Workflow Test Product',
                'sku_code' => 'WF-001',
                'quantity_needed' => 200,
                'priority' => 'high',
                'notes' => 'Critical stock level',
            ]);

        $createResponse->assertStatus(201);
        $requestId = $createResponse->json('stock_request.id');

        // Step 2: Accept request
        $acceptResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$requestId}/accept", [
                'response_notes' => 'Processing procurement',
            ]);

        $acceptResponse->assertStatus(200);

        // Verify final state
        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $requestId,
            'status' => 'accepted',
        ]);
    }
}
