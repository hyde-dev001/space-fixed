<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    #[Test]
    public function finance_cannot_approve_before_repairer_endorsement(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $financeActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-ONLINE-RFD-003',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 3,
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
            'refund_no' => 'RFD-TDD-ONLINE-RFD-003',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 3,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(\App\Services\RepairPosRefundService::class)->approve(
            refund: $refund,
            actorId: (int) $financeActor->id,
            approvedAmount: 500,
            approvalNote: 'Finance approval',
            stage: 'finance'
        );
    }

    #[Test]
    public function finance_can_approve_pos_refund_without_repairer_endorsement(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $financeActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-WALKIN-RFD-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 31,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk In Customer',
            'walk_in_phone' => '09170000011',
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
            'refund_no' => 'RFD-TDD-WALKIN-RFD-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 31,
            'workflow_source' => 'pos',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'walk_in_refund',
            'status' => 'requested',
            'finance_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $updated = app(\App\Services\RepairPosRefundService::class)->approve(
            refund: $refund,
            actorId: (int) $financeActor->id,
            approvedAmount: 500,
            approvalNote: 'POS walk-in finance approval',
            stage: 'finance'
        );

        $this->assertSame('approved', (string) $updated->finance_status);
        $this->assertSame('approved', (string) $updated->status);
    }

    #[Test]
    public function full_online_refund_flow_repairer_finance_owner_optional_then_finance_execute(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $repairerActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $financeActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $ownerActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-ONLINE-RFD-004',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 4,
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
            'refund_no' => 'RFD-TDD-ONLINE-RFD-004',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 4,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $onlineWorkflow = app(\App\Services\RepairOnlineRefundWorkflowService::class);
        $refundService = app(\App\Services\RepairPosRefundService::class);

        $refund = $onlineWorkflow->repairerApprove(
            refund: $refund,
            actorId: (int) $repairerActor->id,
            assessmentNote: 'Valid defect found during repairer assessment.',
            approvedAmount: 500.0,
        );

        $refund = $refundService->approve(
            refund: $refund,
            actorId: (int) $financeActor->id,
            approvedAmount: 500.0,
            approvalNote: 'Finance reviewed and endorsed.',
            stage: 'finance',
        );

        if ((string) $refund->finance_status === 'approved_initial') {
            $refund = $refundService->approve(
                refund: $refund,
                actorId: (int) $ownerActor->id,
                approvedAmount: 500.0,
                approvalNote: 'Owner approved after finance initial review.',
                stage: 'shop_owner',
            );
        }

        $refund = $refundService->execute(
            refund: $refund,
            actorId: (int) $financeActor->id,
            executionMode: 'manual',
            executionNote: 'Executed manually after staged approvals.',
        );

        $this->assertSame('approved', (string) $refund->repairer_status);
        $this->assertSame('approved', (string) $refund->finance_status);
        $this->assertContains((string) $refund->shop_owner_status, ['approved', 'skipped']);
        $this->assertSame('succeeded', (string) $refund->status);
    }
}
