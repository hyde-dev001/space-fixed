<?php

namespace Tests\Feature\Procurement;

use Tests\TestCase;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\PurchaseRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\InventoryItem;
use App\Events\PurchaseOrderSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;

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
        config(['auth.defaults.guard' => 'user']);
        $this->shopOwner = ShopOwner::factory()->create();
        $this->user = User::factory()->for($this->shopOwner)->create();
        foreach (['procurement.view', 'procurement.create_purchase_orders', 'procurement.manage_purchase_orders', 'procurement.receive_purchase_orders', 'procurement.complete_purchase_orders', 'procurement.cancel_purchase_orders', 'view-inventory'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
        $this->user->givePermissionTo(['procurement.view', 'procurement.create_purchase_orders', 'procurement.manage_purchase_orders', 'procurement.receive_purchase_orders', 'procurement.complete_purchase_orders', 'procurement.cancel_purchase_orders', 'view-inventory']);
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
                'purchase_request_ids' => [$this->pr->id],
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
		$this->assertSame(['message', 'data'], array_keys($response->json()));

        $this->assertDatabaseHas('purchase_orders', [
            'pr_id' => $this->pr->id,
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function user_can_send_po_to_supplier()
    {
        Event::fake([PurchaseOrderSent::class]);

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
        Event::assertDispatched(PurchaseOrderSent::class, function (PurchaseOrderSent $event) use ($po): bool {
            return $event->purchaseOrder->is($po);
        });
    }

    /** @test */
    public function user_can_update_status_to_sent_and_dispatch_the_supplier_event(): void
    {
        Event::fake([PurchaseOrderSent::class]);

        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/update-status", [
                'status' => 'sent',
            ])
            ->assertOk();

        Event::assertDispatched(PurchaseOrderSent::class, function (PurchaseOrderSent $event) use ($po): bool {
            return $event->purchaseOrder->is($po);
        });
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
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/update-status", [
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
    public function direct_delivery_route_is_removed()
    {
        $inventoryItem = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 100,
        ]);

        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 50,
            'status' => 'in_transit',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/mark-delivered", [
                'actual_delivery_date' => now()->format('Y-m-d'),
                'received_quantity' => 50,
                'defective_quantity' => 0,
            ]);

        $response->assertNotFound();
        $this->assertSame('in_transit', $po->fresh()->status);
        $this->assertEquals(100, $inventoryItem->fresh()->available_quantity);
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
    public function user_cannot_cancel_an_in_transit_po(): void
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'in_transit',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/cancel", [
                'cancellation_reason' => 'Supplier cannot fulfill order',
            ])
            ->assertForbidden();

        $this->assertSame('in_transit', $po->fresh()->status);
    }

    /** @test */
    public function posted_receipt_keeps_an_otherwise_cancellable_po_uncancelled(): void
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);
        PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'shop_owner_id' => $this->shopOwner->id,
            'received_by' => $this->user->id,
            'status' => 'posted',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/cancel", [
                'cancellation_reason' => 'Supplier cannot fulfill order',
            ])
            ->assertUnprocessable();

        $this->assertSame('sent', $po->fresh()->status);
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

        foreach (['partially_received', 'delivered', 'cancelled'] as $status) {
            PurchaseOrder::factory()->create([
                'shop_owner_id' => $this->shopOwner->id,
                'supplier_id' => $this->supplier->id,
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/erp/procurement/purchase-orders/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_purchase_orders',
                'active_orders',
                'awaiting_closure_orders',
                'completed_orders',
                'cancelled_orders',
            ])
            ->assertJsonPath('active_orders', 2)
            ->assertJsonPath('awaiting_closure_orders', 1)
            ->assertJsonPath('completed_orders', 1)
            ->assertJsonPath('cancelled_orders', 1);
    }

    public function test_only_explicit_closure_moves_a_delivered_po_to_completed(): void
    {
        $this->user->givePermissionTo('procurement.complete_purchase_orders');
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'delivered',
        ]);
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'ordered_quantity' => 1,
        ]);
        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'shop_owner_id' => $this->shopOwner->id,
            'received_by' => $this->user->id,
            'status' => 'posted',
        ]);
        PurchaseOrderReceiptItem::factory()->create([
            'purchase_order_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $item->id,
            'received_quantity' => 1,
            'accepted_quantity' => 1,
        ]);

        $this->assertSame('delivered', $po->fresh()->status);

        $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$po->id}/update-status", [
                'status' => 'completed',
            ])
            ->assertOk();

        $this->assertSame('completed', $po->fresh()->status);

        $inTransit = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'in_transit',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$inTransit->id}/update-status", [
                'status' => 'completed',
            ])
            ->assertForbidden();

        $this->assertSame('in_transit', $inTransit->fresh()->status);
    }

    /** @test */
    public function complete_manual_po_workflow_stops_at_in_transit()
    {
        $inventoryItem = InventoryItem::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'available_quantity' => 100,
        ]);

        // Step 1: Create PO
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/erp/procurement/purchase-orders', [
                'purchase_request_ids' => [$this->pr->id],
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
            ->postJson("/api/erp/procurement/purchase-orders/{$poId}/update-status", [
                'status' => 'confirmed',
            ]);
        $confirmResponse->assertStatus(200);

        // Step 4: Update to In Transit
        $transitResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$poId}/update-status", [
                'status' => 'in_transit',
            ]);
        $transitResponse->assertStatus(200);

        // Receipt posting, not a generic transition, owns delivery.
        $deliverResponse = $this->actingAs($this->user)
            ->postJson("/api/erp/procurement/purchase-orders/{$poId}/mark-delivered", [
                'actual_delivery_date' => now()->format('Y-m-d'),
                'received_quantity' => 50,
                'defective_quantity' => 0,
            ]);
        $deliverResponse->assertNotFound();

        // Verify final state
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'in_transit',
        ]);

        // Verify inventory updated
        $this->assertEquals(100, $inventoryItem->fresh()->available_quantity);
    }
}
