<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairOnlineRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function online_repair_refund_defaults_to_repairer_review_stage(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-ONLINE-RFD-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 1,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = PosRefund::create([
            'refund_no' => 'RFD-TDD-ONLINE-RFD-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 1,
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ])->fresh();

        $this->assertArrayHasKey('repairer_status', $refund->getAttributes());
    }

    #[Test]
    public function repairer_can_endorse_refund_to_finance_with_assessment_note(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-ONLINE-RFD-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 2,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = PosRefund::create([
            'refund_no' => 'RFD-TDD-ONLINE-RFD-002',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 2,
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $service = app(\App\Services\RepairOnlineRefundWorkflowService::class);
        $service->repairerApprove(
            refund: $refund,
            actorId: 101,
            assessmentNote: 'Stitching detached after release; valid service defect.',
            approvedAmount: 500.0,
        );

        $this->assertSame('approved', $refund->fresh()->repairer_status);
        $this->assertSame('requested', $refund->fresh()->status);
        $this->assertSame('pending', $refund->fresh()->finance_status);
    }
}
