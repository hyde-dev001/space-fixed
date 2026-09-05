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
        $this->user = User::factory()->for($this->shopOwner)->create();
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

        $this->assertEquals('Pending Finance', $pr->status_label);
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
    public function it_advances_finance_approval_to_shop_owner()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
            'requires_owner_approval' => true,
        ]);

        $pr->reviewByFinance($this->user, 'Approved for budget compliance');

        $this->assertEquals('pending_shop_owner', $pr->fresh()->status);
        $this->assertEquals($this->user->id, $pr->fresh()->reviewed_by);
        $this->assertNotNull($pr->fresh()->reviewed_date);
    }

    /** @test */
    public function it_skips_only_the_owner_stage_for_an_explicitly_disabled_snapshot()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
            'requires_owner_approval' => false,
        ]);

        $this->assertTrue($pr->reviewByFinance($this->user, 'Budget approved'));

        $fresh = $pr->fresh();
        $this->assertSame('pending_finance_final', $fresh->status);
        $this->assertSame($this->user->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_date);
    }

    /** @test */
    public function an_explicitly_disabled_snapshot_cannot_use_a_shop_owner_stage()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_shop_owner',
            'requires_owner_approval' => false,
        ]);

        $this->assertFalse($pr->approveByShopOwner($this->shopOwner, 'Should be blocked'));
        $this->assertFalse($pr->rejectByShopOwner($this->shopOwner, 'Should be blocked'));
        $this->assertSame('pending_shop_owner', $pr->fresh()->status);
    }

    /** @test */
    public function shop_owner_approval_advances_to_finance_final_and_uses_the_owner_audit_column()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_shop_owner',
        ]);

        $this->assertTrue($pr->approveByShopOwner($this->shopOwner, 'Approved by owner'));

        $fresh = $pr->fresh();
        $this->assertSame('pending_finance_final', $fresh->status);
        $this->assertSame($this->shopOwner->id, $fresh->approved_by_shop_owner_id);
        $this->assertNull($fresh->approved_by);
        $this->assertNotNull($fresh->shop_owner_approved_at);
        $this->assertNull($fresh->approved_date);
    }

    /** @test */
    public function finance_final_release_preserves_initial_and_owner_audits()
    {
        $finalFinance = User::factory()->for($this->shopOwner)->create();
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance_final',
            'reviewed_by' => $this->user->id,
            'reviewed_date' => now()->subHour(),
            'approved_by_shop_owner_id' => $this->shopOwner->id,
            'shop_owner_approved_at' => now()->subMinutes(30),
        ]);

        $this->assertTrue($pr->releaseByFinance($finalFinance, 'Funds available'));

        $fresh = $pr->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->user->id, $fresh->reviewed_by);
        $this->assertSame($this->shopOwner->id, $fresh->approved_by_shop_owner_id);
        $this->assertSame($finalFinance->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_date);
    }

    /** @test */
    public function finance_rejection_uses_the_user_audit_column()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $pr->rejectByFinance($this->user, 'Exceeds budget allocation');

        $this->assertEquals('rejected', $pr->fresh()->status);
        $this->assertEquals($this->user->id, $pr->fresh()->rejected_by_user_id);
        $this->assertNull($pr->fresh()->rejected_by_shop_owner_id);
        $this->assertEquals('Exceeds budget allocation', $pr->fresh()->rejection_reason);
    }

    /** @test */
    public function it_refuses_purchase_order_creation_before_final_approval()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance_final',
        ]);

        $purchaseOrder = $pr->convertToPurchaseOrder([
            'ordered_by' => $this->user->id,
        ]);

        $this->assertNull($purchaseOrder);
        $this->assertDatabaseCount('purchase_orders', 0);
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
