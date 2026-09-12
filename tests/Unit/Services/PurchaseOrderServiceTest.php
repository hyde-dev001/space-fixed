<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PurchaseOrderService;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Supplier;
use App\Models\ShopOwner;
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
        $this->user = User::factory()->for($this->shopOwner)->create();
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
            'purchase_request_ids' => [$pr->id],
            'shop_owner_id' => $this->shopOwner->id,
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
            'purchase_request_ids' => [$pr1->id],
            'shop_owner_id' => $this->shopOwner->id,
            'payment_terms' => 'Net 30',
            'ordered_by' => $this->user->id,
        ]);

        $po2 = $this->service->createPurchaseOrder([
            'purchase_request_ids' => [$pr2->id],
            'shop_owner_id' => $this->shopOwner->id,
            'payment_terms' => 'COD',
            'ordered_by' => $this->user->id,
        ]);

        $this->assertNotEquals($po1->po_number, $po2->po_number);
    }

    public function test_po_number_sequence_handles_more_than_three_digits(): void
    {
        PurchaseOrder::factory()->create([
            'po_number' => 'PO-' . date('Y') . '-1009',
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->assertSame('PO-' . date('Y') . '-1010', $this->service->generatePONumber($this->shopOwner->id));
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

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'partially_received',
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'delivered',
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'cancelled',
        ]);

        $metrics = $this->service->getMetrics($this->shopOwner->id);

        $this->assertEquals(5, $metrics['total_purchase_orders']);
        $this->assertEquals(2, $metrics['active_orders']);
        $this->assertEquals(1, $metrics['awaiting_closure_orders']);
        $this->assertEquals(1, $metrics['completed_orders']);
        $this->assertEquals(1, $metrics['cancelled_orders']);
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
