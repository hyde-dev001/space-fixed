<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PurchaseRequestService;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Supplier;
use App\Models\ShopOwner;
use App\Models\ProcurementSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PurchaseRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseRequestService $service;
    protected ShopOwner $shopOwner;
    protected Supplier $supplier;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurchaseRequestService();
        $this->shopOwner = ShopOwner::factory()->create();
        $this->supplier = Supplier::factory()->create(['shop_owner_id' => $this->shopOwner->id]);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_create_purchase_request()
    {
        $data = [
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'product_name' => 'Office Supplies',
            'quantity' => 100,
            'unit_cost' => 50.00,
            'priority' => 'medium',
            'justification' => 'Restock for Q2 operations',
            'requested_by' => $this->user->id,
        ];

        $pr = $this->service->createPurchaseRequest($data);

        $this->assertInstanceOf(PurchaseRequest::class, $pr);
        $this->assertEquals('Office Supplies', $pr->product_name);
        $this->assertEquals(5000.00, $pr->total_cost);
        $this->assertEquals('draft', $pr->status);
        $this->assertNotNull($pr->pr_number);
        $this->assertTrue(str_starts_with($pr->pr_number, 'PR-'));
    }

    /** @test */
    public function it_generates_unique_pr_numbers()
    {
        $pr1 = $this->service->createPurchaseRequest([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'product_name' => 'Product 1',
            'quantity' => 10,
            'unit_cost' => 100,
            'priority' => 'medium',
            'justification' => 'Test',
            'requested_by' => $this->user->id,
        ]);

        $pr2 = $this->service->createPurchaseRequest([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'product_name' => 'Product 2',
            'quantity' => 20,
            'unit_cost' => 50,
            'priority' => 'high',
            'justification' => 'Test',
            'requested_by' => $this->user->id,
        ]);

        $this->assertNotEquals($pr1->pr_number, $pr2->pr_number);
    }

    /** @test */
    public function it_can_submit_to_finance()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $result = $this->service->submitToFinance($pr->id);

        $this->assertInstanceOf(PurchaseRequest::class, $result);
        $this->assertEquals('pending_finance', $result->status);
    }

    /** @test */
    public function it_can_approve_purchase_request()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $result = $this->service->approvePurchaseRequest($pr->id, $this->user->id, 'Budget approved');

        $this->assertEquals('approved', $result->status);
        $this->assertEquals($this->user->id, $result->approved_by);
        $this->assertEquals('Budget approved', $result->notes);
    }

    /** @test */
    public function it_can_reject_purchase_request()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $result = $this->service->rejectPurchaseRequest($pr->id, $this->user->id, 'Exceeds budget');

        $this->assertEquals('rejected', $result->status);
        $this->assertEquals('Exceeds budget', $result->rejection_reason);
    }

    /** @test */
    public function it_auto_approves_low_value_requests()
    {
        ProcurementSettings::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'auto_approval_threshold' => 1000.00,
        ]);

        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'quantity' => 10,
            'unit_cost' => 50.00, // Total: 500.00 (below threshold)
            'status' => 'pending_finance',
        ]);

        $autoApproved = $this->service->autoApproveLowValueRequests($this->shopOwner->id);

        $this->assertEquals(1, $autoApproved);
        $this->assertEquals('approved', $pr->fresh()->status);
    }

    /** @test */
    public function it_gets_correct_metrics()
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

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'rejected',
        ]);

        $metrics = $this->service->getMetrics($this->shopOwner->id);

        $this->assertEquals(3, $metrics['total_requests']);
        $this->assertEquals(1, $metrics['pending_finance']);
        $this->assertEquals(1, $metrics['approved']);
        $this->assertEquals(1, $metrics['rejected']);
    }

    /** @test */
    public function it_gets_approved_prs_for_po_creation()
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

        $approvedPRs = $this->service->getApprovedPRs($this->shopOwner->id);

        $this->assertCount(1, $approvedPRs);
        $this->assertEquals('approved', $approvedPRs[0]->status);
    }

    /** @test */
    public function it_gets_urgent_requests()
    {
        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'priority' => 'high',
            'status' => 'pending_finance',
        ]);

        PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'priority' => 'medium',
            'status' => 'pending_finance',
        ]);

        $urgentRequests = $this->service->getUrgentRequests($this->shopOwner->id);

        $this->assertCount(1, $urgentRequests);
        $this->assertEquals('high', $urgentRequests[0]->priority);
    }
}
