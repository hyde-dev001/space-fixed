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
        $this->user = User::factory()->for($this->shopOwner)->create();
    }

    /** @test */
    public function it_can_create_purchase_request()
    {
        $data = [
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'product_name' => 'Office Supplies',
            'quantity' => 300,
            'unit_cost' => 50.00,
            'priority' => 'medium',
            'justification' => 'Restock for Q2 operations',
            'requested_by' => $this->user->id,
        ];

        $pr = $this->service->createPurchaseRequest($data);

        $this->assertInstanceOf(PurchaseRequest::class, $pr);
        $this->assertEquals('Office Supplies', $pr->product_name);
        $this->assertEquals(15000.00, $pr->total_cost);
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
        $this->assertTrue($result->requires_owner_approval);
    }

    /** @test */
    public function submission_freezes_the_owner_policy_before_finance_review()
    {
        $this->setPurchaseRequestApproval(false);
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'draft',
        ]);

        $submitted = $this->service->submitToFinance($pr->id);
        $this->assertSame(false, $submitted->requires_owner_approval);

        $this->setPurchaseRequestApproval(true);

        $reviewed = $this->service->reviewByFinance($pr->id, $this->user, 'Budget approved');

        $this->assertSame('pending_finance_final', $reviewed->status);
        $this->assertSame($this->user->id, $reviewed->reviewed_by);
    }

    /** @test */
    public function finance_review_advances_to_shop_owner_when_snapshot_requires_it()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
            'requires_owner_approval' => true,
        ]);

        $result = $this->service->reviewByFinance($pr->id, $this->user, 'Budget approved');

        $this->assertEquals('pending_shop_owner', $result->status);
        $this->assertEquals($this->user->id, $result->reviewed_by);
        $this->assertStringContainsString('Finance Initial: Budget approved', $result->notes);
    }

    /** @test */
    public function finance_can_reject_purchase_request()
    {
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_finance',
        ]);

        $result = $this->service->rejectByFinance($pr->id, $this->user, 'Exceeds budget');

        $this->assertEquals('rejected', $result->status);
        $this->assertEquals('Exceeds budget', $result->rejection_reason);
    }

    /** @test */
    public function owner_approval_then_finance_release_preserves_each_stage_actor()
    {
        $finalFinance = User::factory()->for($this->shopOwner)->create();
        $pr = PurchaseRequest::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending_shop_owner',
            'reviewed_by' => $this->user->id,
            'reviewed_date' => now(),
        ]);

        $ownerApproved = $this->service->approveByShopOwner($pr->id, $this->shopOwner, 'Proceed');
        $this->assertSame('pending_finance_final', $ownerApproved->status);

        $approved = $this->service->releaseByFinance($pr->id, $finalFinance, 'Funds released');
        $this->assertSame('approved', $approved->status);
        $this->assertSame($this->user->id, $approved->reviewed_by);
        $this->assertSame($this->shopOwner->id, $approved->approved_by_shop_owner_id);
        $this->assertSame($finalFinance->id, $approved->approved_by);
    }

    /** @test */
    public function low_value_settings_never_bypass_the_two_approvers()
    {
        ProcurementSettings::create([
            'shop_owner_id' => $this->shopOwner->id,
            'auto_pr_approval_threshold' => 1000.00,
            'require_finance_approval' => true,
        ]);

        $pr = $this->service->createPurchaseRequest([
            'shop_owner_id' => $this->shopOwner->id,
            'supplier_id' => $this->supplier->id,
            'product_name' => 'Low-value supplies',
            'quantity' => 10,
            'unit_cost' => 50.00, // Total: 500.00 (below threshold)
            'priority' => 'medium',
            'justification' => 'Routine restock',
            'requested_by' => $this->user->id,
        ]);

        $this->assertEquals('draft', $pr->status);
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

        $this->assertEquals(3, $metrics['total_purchase_requests']);
        $this->assertEquals(1, $metrics['pending_finance']);
        $this->assertEquals(1, $metrics['approved_requests']);
        $this->assertEquals(1, $metrics['rejected_requests']);
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

    private function setPurchaseRequestApproval(bool $enabled): void
    {
        $settings = ProcurementSettings::firstOrNew([
            'shop_owner_id' => $this->shopOwner->id,
        ]);
        $settings->settings_json = [
            'approval_pages' => [
                'purchase_request_approval' => [
                    'enabled' => $enabled,
                    'limit' => null,
                ],
            ],
        ];
        $settings->save();
    }
}
