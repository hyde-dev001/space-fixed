<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PurchaseOrderService;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Supplier;
use App\Models\ShopOwner;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $service;
    protected ShopOwner $shopOwner;
    protected Supplier $supplier;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurchaseOrderService();
        $this->shopOwner = ShopOwner::factory()->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_create_purchase_order_from_pr()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'approved',
        ]);

        $data = [
            'pr_id' => $pr->id,
            'expected_delivery_date' => now()->addDays(14)->format('Y-m-d'),
            'payment_terms' => 'Net 30',
            'ordered_by' => $this->user->id,
        ];

        $po = $this->service->createPurchaseOrder($data);

        $this->assertInstanceOf(PurchaseOrder::class, $po);
        $this->assertEquals($pr->id, $po->pr_id);
        $this->assertEquals('draft', $po->status);
        $this->assertNotNull($po->po_number);
        $this->assertTrue(str_starts_with($po->po_number, 'PO-'));
    }

    /** @test */
    public function it_generates_unique_po_numbers()
    {
        $pr1 = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'approved',
        ]);

        $pr2 = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'approved',
        ]);

        $po1 = $this->service->createPurchaseOrder([
            'pr_id' => $pr1->id,
            'payment_terms' => 'Net 30',
            'ordered_by' => $this->user->id,
        ]);

        $po2 = $this->service->createPurchaseOrder([
            'pr_id' => $pr2->id,
            'payment_terms' => 'COD',
            'ordered_by' => $this->user->id,
        ]);

        $this->assertNotEquals($po1->po_number, $po2->po_number);
    }

    /** @test */
    public function it_can_update_status()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $result = $this->service->updateStatus($po->id, 'sent', $this->user->id);

        $this->assertEquals('sent', $result->status);
    }

    /** @test */
    public function it_can_send_to_supplier()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $result = $this->service->sendToSupplier($po->id);

        $this->assertEquals('sent', $result->status);
    }

    /** @test */
    public function it_can_mark_as_delivered()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'in_transit',
        ]);

        $result = $this->service->markAsDelivered($po->id, $this->user->id);

        $this->assertEquals('delivered', $result->status);
        $this->assertNotNull($result->actual_delivery_date);
    }

    /** @test */
    public function it_can_cancel_purchase_order()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        $result = $this->service->cancelPurchaseOrder($po->id, $this->user->id, 'Supplier unavailable');

        $this->assertEquals('cancelled', $result->status);
        $this->assertEquals('Supplier unavailable', $result->cancellation_reason);
    }

    /** @test */
    public function it_updates_inventory_on_delivery()
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
            'status' => 'delivered',
        ]);

        $this->service->updateInventoryOnDelivery($po->id);

        $this->assertEquals(150, $inventoryItem->fresh()->quantity);
    }

    /** @test */
    public function it_gets_correct_metrics()
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

        $metrics = $this->service->getMetrics($this->shopOwner->id);

        $this->assertEquals(2, $metrics['total_orders']);
        $this->assertEquals(1, $metrics['active_orders']);
        $this->assertEquals(1, $metrics['completed_orders']);
    }

    /** @test */
    public function it_checks_overdue_pos()
    {
        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'expected_delivery_date' => now()->subDays(5),
            'status' => 'sent',
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'expected_delivery_date' => now()->addDays(5),
            'status' => 'sent',
        ]);

        $overduePOs = $this->service->checkOverduePOs($this->shopOwner->id);

        $this->assertCount(1, $overduePOs);
    }
}
