<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ProcurementSettings;
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

    #[Test]
    public function shop_owner_approval_defaults_to_finance_approved_amount_when_partial(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $financeActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $ownerActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        ProcurementSettings::query()->updateOrCreate(
            ['shop_owner_id' => $shopOwner->id],
            [
                'settings_json' => [
                    'approval_pages' => [
                        'refund_approval' => ['enabled' => true, 'limit' => null],
                        'price_approval' => ['enabled' => false, 'limit' => null],
                        'purchase_request_approval' => ['enabled' => false, 'limit' => null],
                        'repair_reject_approval' => ['enabled' => false, 'limit' => null],
                    ],
                ],
            ],
        );

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-WALKIN-RFD-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 41,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk In Customer',
            'walk_in_phone' => '09170000022',
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
            'refund_no' => 'RFD-TDD-WALKIN-RFD-002',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 41,
            'workflow_source' => 'pos',
            'request_type' => 'partial',
            'requested_amount' => 500,
            'reason_code' => 'walk_in_refund',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $refundService = app(\App\Services\RepairPosRefundService::class);

        $refund = $refundService->approve(
            refund: $refund,
            actorId: (int) $financeActor->id,
            approvedAmount: 300.0,
            approvalNote: 'Finance approved partial amount.',
            stage: 'finance',
        );

        $this->assertSame('approved_initial', (string) $refund->finance_status);
        $this->assertSame('pending', (string) $refund->shop_owner_status);
        $this->assertSame(300.0, (float) $refund->approved_amount);

        $refund = $refundService->approve(
            refund: $refund,
            actorId: (int) $ownerActor->id,
            approvedAmount: null,
            approvalNote: 'Owner confirmed finance-approved amount.',
            stage: 'shop_owner',
        );

        $this->assertSame('approved', (string) $refund->finance_status);
        $this->assertSame('approved', (string) $refund->shop_owner_status);
        $this->assertSame(300.0, (float) $refund->approved_amount);
    }

    #[Test]
    public function shop_owner_repair_refund_list_includes_media_urls_from_object_evidence_snapshot(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-ONLINE-RFD-005',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 51,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 650,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 650,
            'paid_amount' => 650,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosRefund::create([
            'refund_no' => 'RFD-TDD-ONLINE-RFD-005',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 51,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 650,
            'reason_code' => 'service_defect',
            'reason_notes' => 'Evidence attached by customer.',
            'status' => 'requested',
            'finance_status' => 'approved_initial',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'approved',
            'evidence_snapshot' => [
                ['type' => 'photo', 'url' => 'https://example.com/evidence-photo.jpg'],
                ['type' => 'video', 'url' => 'https://example.com/evidence-video.mp4'],
            ],
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-refunds');

        $response->assertOk();
        $response->assertJsonPath('data.0.media.0', 'https://example.com/evidence-photo.jpg');
        $response->assertJsonPath('data.0.media.1', 'https://example.com/evidence-video.mp4');
    }

    #[Test]
    public function individual_shop_owner_can_approve_repair_refund_without_finance_initial_approval(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        $customer = User::factory()->create();
        $ownerActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-IND-RFD-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 61,
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
            'refund_no' => 'RFD-TDD-IND-RFD-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 61,
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

        $updated = app(\App\Services\RepairPosRefundService::class)->approve(
            refund: $refund,
            actorId: (int) $ownerActor->id,
            approvedAmount: null,
            approvalNote: 'Individual owner approved directly.',
            stage: 'shop_owner',
        );

        $this->assertSame('approved', (string) $updated->status);
        $this->assertSame('approved', (string) $updated->finance_status);
        $this->assertSame('approved', (string) $updated->shop_owner_status);
    }

    #[Test]
    public function individual_shop_owner_can_reject_repair_refund_without_finance_initial_approval(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'individual',
        ]);
        $customer = User::factory()->create();
        $ownerActor = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-TDD-IND-RFD-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 62,
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
            'refund_no' => 'RFD-TDD-IND-RFD-002',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 62,
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

        $updated = app(\App\Services\RepairPosRefundService::class)->reject(
            refund: $refund,
            actorId: (int) $ownerActor->id,
            rejectionReason: 'Individual owner rejected directly.',
            stage: 'shop_owner',
        );

        $this->assertSame('rejected', (string) $updated->status);
        $this->assertSame('rejected', (string) $updated->finance_status);
        $this->assertSame('rejected', (string) $updated->shop_owner_status);
    }
}
