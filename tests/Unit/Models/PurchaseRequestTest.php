<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Supplier;
use App\Models\ShopOwner;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup test data
        $this->shopOwner = ShopOwner::factory()->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_calculate_total_cost()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'quantity' => 10,
            'unit_cost' => 50.00,
        ]);

        $this->assertEquals(500.00, $pr->total_cost);
    }

    /** @test */
    public function it_has_correct_priority_label()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'priority' => 'high',
        ]);

        $this->assertEquals('High', $pr->priority_label);
    }

    /** @test */
    public function it_has_correct_status_label()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $this->assertEquals('Pending Finance Approval', $pr->status_label);
    }

    /** @test */
    public function it_can_calculate_days_pending()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'requested_date' => now()->subDays(5),
            'status' => 'pending_finance',
        ]);

        $this->assertEquals(5, $pr->days_pending);
    }

    /** @test */
    public function it_can_submit_to_finance()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $result = $pr->submitToFinance();

        $this->assertTrue($result);
        $this->assertEquals('pending_finance', $pr->fresh()->status);
    }

    /** @test */
    public function it_can_be_approved()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $pr->approve($this->user->id, 'Approved for budget compliance');

        $this->assertEquals('approved', $pr->fresh()->status);
        $this->assertEquals($this->user->id, $pr->fresh()->approved_by);
        $this->assertNotNull($pr->fresh()->approved_date);
    }

    /** @test */
    public function it_can_be_rejected()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $pr->reject($this->user->id, 'Exceeds budget allocation');

        $this->assertEquals('rejected', $pr->fresh()->status);
        $this->assertEquals($this->user->id, $pr->fresh()->reviewed_by);
        $this->assertEquals('Exceeds budget allocation', $pr->fresh()->rejection_reason);
    }

    /** @test */
    public function it_can_check_if_can_be_approved()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $this->assertTrue($pr->canBeApproved());

        $pr->update(['status' => 'approved']);
        $this->assertFalse($pr->canBeApproved());
    }

    /** @test */
    public function it_belongs_to_shop_owner()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->assertInstanceOf(ShopOwner::class, $pr->shopOwner);
        $this->assertEquals($this->shopOwner->id, $pr->shopOwner->id);
    }

    /** @test */
    public function it_belongs_to_supplier()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->assertInstanceOf(Supplier::class, $pr->supplier);
        $this->assertEquals($this->supplier->id, $pr->supplier->id);
    }

    /** @test */
    public function draft_scope_works_correctly()
    {
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'approved',
        ]);

        $this->assertEquals(1, PurchaseRequest::draft()->count());
    }

    /** @test */
    public function pending_finance_scope_works_correctly()
    {
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $this->assertEquals(1, PurchaseRequest::pendingFinance()->count());
    }

    /** @test */
    public function approved_scope_works_correctly()
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

        $this->assertEquals(1, PurchaseRequest::approved()->count());
    }
}
