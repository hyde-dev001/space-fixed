<?php

namespace Tests\Feature\Procurement;

use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\PurchaseRequest;
use App\Models\PurchaseOrder;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ShopOwner $shopOwner;
    protected Supplier $supplier;
    protected PurchaseRequest $pr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->shopOwner = ShopOwner::factory()->create(['user_id' => $this->user->id]);
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        
        $this->pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'approved',
        ]);
    }

    /** @test */
    public function user_can_create_purchase_order_from_approved_pr()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/erp/procurement/purchase-orders', [
                'pr_id' => $this->pr->id,
                'expected_delivery_date' => now()->addDays(14)->format('Y-m-d'),
                'payment_terms' => 'Net 30',
                'notes' => 'Rush delivery required',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'po_number',
                    'status',
                    'total_cost',
                ]
            ]);

        $this->assertDatabaseHas('purchase_orders', [
            'pr_id' => $this->pr->id,
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function user_can_send_po_to_supplier()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/send-to-supplier");

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'sent',
        ]);
    }

    /** @test */
    public function user_can_update_po_status()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/erp/procurement/purchase-orders/{$po->id}/update-status", [
                'status' => 'confirmed',
                'notes' => 'Supplier confirmed order',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'confirmed',
        ]);
    }

    /** @test */
    public function user_can_mark_po_as_delivered()
    {
        $inventoryItem = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'quantity' => 100,
        ]);

        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 50,
            'status' => 'in_transit',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/mark-as-delivered", [
                'actual_delivery_date' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'delivered',
        ]);

        // Verify inventory was updated
        $this->assertEquals(150, $inventoryItem->fresh()->quantity);
    }

    /** @test */
    public function user_can_cancel_po()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/cancel", [
                'cancellation_reason' => 'Supplier cannot fulfill order',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Supplier cannot fulfill order',
        ]);
    }

    /** @test */
    public function user_can_get_po_metrics()
    {
        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/erp/procurement/purchase-orders/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_orders',
                'active_orders',
                'completed_orders',
            ]);
    }

    /** @test */
    public function complete_po_workflow_from_creation_to_delivery()
    {
        $inventoryItem = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'quantity' => 100,
        ]);

        // Step 1: Create PO
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/erp/procurement/purchase-orders', [
                'pr_id' => $this->pr->id,
                'expected_delivery_date' => now()->addDays(10)->format('Y-m-d'),
                'payment_terms' => 'COD',
            ]);

        $createResponse->assertStatus(201);
        $poId = $createResponse->json('data.id');

        // Update PO with inventory item
        PurchaseOrder::find($poId)->update([
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 50,
        ]);

        // Step 2: Send to Supplier
        $sendResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$poId}/send-to-supplier");
        $sendResponse->assertStatus(200);

        // Step 3: Update to Confirmed
        $confirmResponse = $this->actingAs($this->user)
            ->putJson("/api/erp/procurement/purchase-orders/{$poId}/update-status", [
                'status' => 'confirmed',
            ]);
        $confirmResponse->assertStatus(200);

        // Step 4: Update to In Transit
        $transitResponse = $this->actingAs($this->user)
            ->putJson("/api/erp/procurement/purchase-orders/{$poId}/update-status", [
                'status' => 'in_transit',
            ]);
        $transitResponse->assertStatus(200);

        // Step 5: Mark as Delivered
        $deliverResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$poId}/mark-as-delivered", [
                'actual_delivery_date' => now()->format('Y-m-d'),
            ]);
        $deliverResponse->assertStatus(200);

        // Verify final state
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'delivered',
        ]);

        // Verify inventory updated
        $this->assertEquals(150, $inventoryItem->fresh()->quantity);
    }
}
