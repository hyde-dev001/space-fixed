<?php

namespace Tests\Feature\Procurement;

use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\ReplenishmentRequest;
use App\Models\StockRequestApproval;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReplenishmentAndStockRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;
    protected InventoryItem $inventoryItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->shopOwner = ShopOwner::factory()->create(['user_id' => $this->user->id]);
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
                'data' => [
                    'id',
                    'request_number',
                    'status',
                ]
            ]);

        $this->assertDatabaseHas('replenishment_requests', [
            'product_name' => 'Test Product',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function user_can_accept_replenishment_request()
    {
        $request = ReplenishmentRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$request->id}/accept", [
                'response_notes' => 'Accepted for procurement',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('replenishment_requests', [
            'id' => $request->id,
            'status' => 'accepted',
        ]);
    }

    /** @test */
    public function user_can_reject_replenishment_request()
    {
        $request = ReplenishmentRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$request->id}/reject", [
                'response_notes' => 'Not required at this time',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('replenishment_requests', [
            'id' => $request->id,
            'status' => 'rejected',
        ]);
    }

    /** @test */
    public function user_can_request_additional_details_for_replenishment()
    {
        $request = ReplenishmentRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$request->id}/request-details", [
                'response_notes' => 'Please provide usage forecast',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('replenishment_requests', [
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
            ->postJson("/api/erp/procurement/stock-request-approvals/{$stockRequest->id}/approve", [
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
    public function user_can_approve_stock_request_with_auto_pr_creation()
    {
        $stockRequest = StockRequestApproval::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/stock-request-approvals/{$stockRequest->id}/approve", [
                'approval_notes' => 'Auto-creating PR',
                'auto_create_pr' => true,
            ]);

        $response->assertStatus(200);

        // Verify stock request was approved
        $this->assertDatabaseHas('stock_request_approvals', [
            'id' => $stockRequest->id,
            'status' => 'accepted',
        ]);

        // Verify PR was auto-created
        $this->assertDatabaseHas('purchase_requests', [
            'product_name' => $stockRequest->product_name,
            'quantity' => $stockRequest->quantity_needed,
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
            ->postJson("/api/erp/procurement/stock-request-approvals/{$stockRequest->id}/reject", [
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
            ->getJson('/api/erp/procurement/stock-request-approvals/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_requests',
                'pending_requests',
                'accepted_requests',
            ]);
    }

    /** @test */
    public function user_can_filter_replenishment_requests_by_status()
    {
        ReplenishmentRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'status' => 'pending',
        ]);

        ReplenishmentRequest::factory()->create([
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
        $requestId = $createResponse->json('data.id');

        // Step 2: Accept request
        $acceptResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/replenishment-requests/{$requestId}/accept", [
                'response_notes' => 'Processing procurement',
            ]);

        $acceptResponse->assertStatus(200);

        // Verify final state
        $this->assertDatabaseHas('replenishment_requests', [
            'id' => $requestId,
            'status' => 'accepted',
        ]);
    }
}
