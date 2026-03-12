<?php

namespace Tests\Feature\Procurement;

use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->shopOwner = ShopOwner::factory()->create(['user_id' => $this->user->id]);
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
    }

    /** @test */
    public function user_can_create_purchase_request()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'product_name' => 'Office Supplies',
                'supplier_id' => $this->supplier->id,
                'quantity' => 100,
                'unit_cost' => 50.00,
                'priority' => 'medium',
                'justification' => 'Restock for Q2 operations',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'pr_number',
                    'product_name',
                    'total_cost',
                    'status',
                ]
            ]);

        $this->assertDatabaseHas('purchase_requests', [
            'product_name' => 'Office Supplies',
            'quantity' => 100,
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function user_can_submit_pr_to_finance()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-requests/{$pr->id}/submit-to-finance");

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'status' => 'pending_finance',
        ]);
    }

    /** @test */
    public function finance_user_can_approve_pr()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-requests/{$pr->id}/approve", [
                'approval_notes' => 'Budget approved',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'status' => 'approved',
        ]);
    }

    /** @test */
    public function finance_user_can_reject_pr()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-requests/{$pr->id}/reject", [
                'rejection_reason' => 'Exceeds budget allocation',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'status' => 'rejected',
            'rejection_reason' => 'Exceeds budget allocation',
        ]);
    }

    /** @test */
    public function user_can_get_pr_metrics()
    {
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/erp/procurement/purchase-requests/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_requests',
                'pending_finance',
                'approved',
            ]);
    }

    /** @test */
    public function user_can_filter_purchase_requests()
    {
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'approved',
        ]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/erp/procurement/purchase-requests?status=approved');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function user_can_update_draft_pr()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
            'quantity' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/erp/procurement/purchase-requests/{$pr->id}", [
                'product_name' => 'Updated Product',
                'supplier_id' => $this->supplier->id,
                'quantity' => 150,
                'unit_cost' => 60.00,
                'priority' => 'high',
                'justification' => 'Updated justification',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'quantity' => 150,
        ]);
    }

    /** @test */
    public function user_can_delete_draft_pr()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/erp/procurement/purchase-requests/{$pr->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('purchase_requests', [
            'id' => $pr->id,
        ]);
    }

    /** @test */
    public function complete_pr_workflow_from_creation_to_approval()
    {
        // Step 1: Create PR
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/erp/procurement/purchase-requests', [
                'product_name' => 'Test Product',
                'supplier_id' => $this->supplier->id,
                'quantity' => 50,
                'unit_cost' => 100.00,
                'priority' => 'high',
                'justification' => 'Urgent requirement',
            ]);

        $createResponse->assertStatus(201);
        $prId = $createResponse->json('data.id');

        // Step 2: Submit to Finance
        $submitResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-requests/{$prId}/submit-to-finance");

        $submitResponse->assertStatus(200);

        // Step 3: Approve
        $approveResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-requests/{$prId}/approve", [
                'approval_notes' => 'Approved for urgent requirement',
            ]);

        $approveResponse->assertStatus(200);

        // Verify final state
        $this->assertDatabaseHas('purchase_requests', [
            'id' => $prId,
            'status' => 'approved',
        ]);
    }
}
