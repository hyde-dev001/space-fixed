<?php

namespace Tests\Feature\Procurement;

use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\StockRequestApproval;
use App\Models\InventoryItem;
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
        $this->user->givePermissionTo([
            'procurement.create_purchase_requests',
            'procurement.review_stock_requests',
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
