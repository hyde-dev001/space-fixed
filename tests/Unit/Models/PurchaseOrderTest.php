<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Supplier;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopOwner = ShopOwner::factory()->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_has_correct_status_label()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'in_transit',
        ]);

        $this->assertEquals('In Transit', $po->status_label);
    }

    /** @test */
    public function it_can_detect_overdue_orders()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'expected_delivery_date' => now()->subDays(5),
            'status' => 'in_transit',
        ]);

        $this->assertTrue($po->is_overdue);
    }

    /** @test */
    public function it_calculates_days_until_delivery()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'expected_delivery_date' => now()->addDays(10),
            'status' => 'sent',
        ]);

        $this->assertEquals(10, $po->days_until_delivery);
    }

    /** @test */
    public function it_can_be_sent_to_supplier()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $po->sendToSupplier();

        $this->assertEquals('sent', $po->fresh()->status);
    }

    /** @test */
    public function it_can_be_marked_as_confirmed()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        $po->markAsConfirmed($this->user->id);

        $this->assertEquals('confirmed', $po->fresh()->status);
        $this->assertEquals($this->user->id, $po->fresh()->confirmed_by);
        $this->assertNotNull($po->fresh()->confirmed_date);
    }

    /** @test */
    public function it_can_be_marked_as_delivered()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'in_transit',
        ]);

        $po->markAsDelivered($this->user->id);

        $this->assertEquals('delivered', $po->fresh()->status);
        $this->assertEquals($this->user->id, $po->fresh()->delivered_by);
        $this->assertNotNull($po->fresh()->delivered_date);
        $this->assertNotNull($po->fresh()->actual_delivery_date);
    }

    /** @test */
    public function it_can_be_cancelled()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        $po->cancel($this->user->id, 'Supplier cannot fulfill order');

        $this->assertEquals('cancelled', $po->fresh()->status);
        $this->assertEquals('Supplier cannot fulfill order', $po->fresh()->cancellation_reason);
    }

    /** @test */
    public function it_can_get_next_status()
    {
        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        $this->assertEquals('confirmed', $po->getNextStatus());
    }

    /** @test */
    public function active_scope_includes_correct_statuses()
    {
        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'sent',
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'confirmed',
        ]);

        PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'completed',
        ]);

        $this->assertEquals(2, PurchaseOrder::active()->count());
    }

    /** @test */
    public function overdue_scope_works_correctly()
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

        $this->assertEquals(1, PurchaseOrder::overdue()->count());
    }

    /** @test */
    public function it_belongs_to_purchase_request()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
        ]);

        $po = PurchaseOrder::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'pr_id' => $pr->id,
        ]);

        $this->assertInstanceOf(PurchaseRequest::class, $po->purchaseRequest);
        $this->assertEquals($pr->id, $po->purchaseRequest->id);
    }
}
