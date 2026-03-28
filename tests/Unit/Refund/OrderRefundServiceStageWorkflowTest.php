<?php

declare(strict_types=1);

namespace Tests\Unit\Refund;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Services\OrderRefundService;
use App\Services\PaymentSettlementService;
use App\Services\PaymongoRefundService;
use App\Services\ShopOwnerApprovalPolicyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrderRefundServiceStageWorkflowTest extends TestCase
{
    private bool $requiresOwnerApproval = true;

    /** @var MockObject&PaymongoRefundService */
    private MockObject $paymongoRefundService;

    /** @var MockObject&PaymentSettlementService */
    private MockObject $paymentSettlementService;

    /** @var MockObject&ShopOwnerApprovalPolicyService */
    private MockObject $shopOwnerApprovalPolicyService;

    private OrderRefundService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymongoRefundService = $this->createMock(PaymongoRefundService::class);
        $this->paymentSettlementService = $this->createMock(PaymentSettlementService::class);
        $this->shopOwnerApprovalPolicyService = $this->createMock(ShopOwnerApprovalPolicyService::class);
        $this->shopOwnerApprovalPolicyService
            ->method('requiresOwnerApprovalForRefund')
            ->willReturnCallback(fn () => $this->requiresOwnerApproval);

        $this->service = new OrderRefundService(
            paymongoRefundService: $this->paymongoRefundService,
            paymentSettlementService: $this->paymentSettlementService,
            shopOwnerApprovalPolicyService: $this->shopOwnerApprovalPolicyService,
        );
    }

    #[Test]
    public function shop_owner_approval_requires_finance_initial_approval_first(): void
    {
        $refund = $this->makeRefund();

        $result = $this->service->approveRequestedRefund($refund, stage: 'shop_owner', processedBy: 10);

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('pending', $refund->shop_owner_status);
        $this->assertSame('pending', $refund->finance_status);
        $this->assertSame('awaiting_approval', $refund->return_status);
    }

    #[Test]
    public function finance_can_finalize_immediately_when_owner_approval_is_not_required(): void
    {
        $this->requiresOwnerApproval = false;

        $refund = $this->makeRefund();

        $result = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 10);

        $this->assertSame('approved', $result['result']);
        $this->assertSame('approved', $refund->shop_owner_status);
        $this->assertSame('approved', $refund->finance_status);
        $this->assertSame('pending_customer_shipment', $refund->return_status);
    }

    #[Test]
    public function staged_approval_moves_refund_to_pending_customer_shipment(): void
    {
        $refund = $this->makeRefund();

        $financeInitial = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 12);
        $this->assertSame('approved_initial', $refund->finance_status);
        $this->assertSame('pending', $refund->shop_owner_status);

        $shopOwnerApproval = $this->service->approveRequestedRefund($refund, stage: 'shop_owner', processedBy: 11);
        $financeFinal = $this->service->approveRequestedRefund($refund, stage: 'finance', processedBy: 12);

        $this->assertSame('approved', $shopOwnerApproval['result']);
        $this->assertSame('approved', $financeInitial['result']);
        $this->assertSame('approved', $financeFinal['result']);
        $this->assertSame('approved', $refund->shop_owner_status);
        $this->assertSame('approved', $refund->finance_status);
        $this->assertSame('pending_customer_shipment', $refund->return_status);
        $this->assertSame('pending_approval', $refund->status);
    }

    #[Test]
    public function customer_return_shipment_requires_completed_approvals(): void
    {
        $refund = $this->makeRefund();

        $result = $this->service->markCustomerReturnShipped($refund, [
            'tracking_number' => 'TRK-123',
            'carrier' => 'LBC',
        ]);

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('awaiting_approval', $refund->return_status);
    }

    #[Test]
    public function customer_return_shipment_transitions_to_in_transit_with_tracking_details(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->markCustomerReturnShipped($refund, [
            'tracking_number' => 'TRK-789',
            'carrier' => 'J&T',
            'tracking_link' => 'https://track.example/TRK-789',
            'note' => 'Parcel dropped off at branch',
        ]);

        $this->assertSame('in_transit', $result['result']);
        $this->assertSame('in_transit', $refund->return_status);
        $this->assertSame('TRK-789', $refund->customer_return_tracking_number);
        $this->assertSame('J&T', $refund->customer_return_carrier);
        $this->assertSame('https://track.example/TRK-789', $refund->customer_return_tracking_link);
    }

    #[Test]
    public function staff_can_confirm_return_received_after_shipment(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
            'status' => 'pending_approval',
        ]);

        $result = $this->service->confirmReturnReceived($refund, staffId: 77, notes: 'Box inspected and complete');

        $this->assertSame('received', $result['result']);
        $this->assertSame('received', $refund->return_status);
        $this->assertSame(77, $refund->return_confirmed_by_staff_id);
        $this->assertNotNull($refund->return_confirmed_at);
    }

    #[Test]
    public function execute_refund_attempts_payout_when_return_is_in_transit(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
            'status' => 'pending_approval',
        ]);

        $this->paymongoRefundService
            ->expects($this->once())
            ->method('createRefund')
            ->willReturn([
                'success' => false,
                'message' => 'Gateway temporarily unavailable',
            ]);

        $result = $this->service->executeApprovedRefund($refund, processedBy: 90);

        $this->assertSame('failed', $result['result']);
        $this->assertSame('failed', $refund->status);
        $this->assertStringContainsString('gateway', strtolower((string) $result['message']));
    }

    #[Test]
    public function execute_refund_marks_request_as_refunded_when_gateway_succeeds(): void
    {
        $refund = $this->makeRefund([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'status' => 'pending_approval',
            'amount' => 2500.00,
            'reason_code' => 'defective_item',
            'reason_note' => 'Customer sent proof photos',
        ]);

        $this->paymongoRefundService
            ->expects($this->once())
            ->method('createRefund')
            ->willReturn([
                'success' => true,
                'status' => 'succeeded',
                'refund_id' => 're_test_123',
            ]);

        $this->paymentSettlementService
            ->expects($this->once())
            ->method('settleOrderRefunded');

        $result = $this->service->executeApprovedRefund($refund, processedBy: 91, executionNote: 'Release approved');

        $this->assertSame('refunded', $result['result']);
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('re_test_123', $refund->paymongo_refund_id);
        $this->assertNotNull($refund->refund_executed_at);
        $this->assertNotNull($refund->refunded_at);
    }

    #[Test]
    public function shop_owner_rejection_sets_rejected_state_and_reason(): void
    {
        $refund = $this->makeRefund([
            'status' => 'pending_approval',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);

        $result = $this->service->rejectRequestedRefund(
            refund: $refund,
            rejectionReason: 'Item condition does not qualify for refund.',
            stage: 'shop_owner',
            processedBy: 321,
        );

        $this->assertSame('rejected', $result['result']);
        $this->assertSame('rejected', $refund->status);
        $this->assertSame('rejected', $refund->shop_owner_status);
        $this->assertSame('Item condition does not qualify for refund.', $refund->rejection_reason);
    }

    private function makeRefund(array $overrides = [], ?string $registrationType = null): InMemoryOrderRefund
    {
        $refund = new InMemoryOrderRefund(array_merge([
            'id' => 5001,
            'order_id' => 3001,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
            'return_status' => 'awaiting_approval',
            'amount' => 2500.00,
            'currency' => 'PHP',
            'reason_code' => 'quality_issue',
        ], $overrides));

        $order = new Order([
            'id' => 3001,
            'customer_id' => 101,
            'shop_owner_id' => 202,
            'payment_method' => 'paymongo',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_test_123',
            'total_amount' => 2500.00,
            'shipping_fee' => 0,
        ]);

        $shopOwner = new ShopOwner([
            'id' => 202,
            'registration_type' => $registrationType,
            'paymongo_secret_key' => 'sk_test_abc',
        ]);

        $order->setRelation('shopOwner', $shopOwner);
        $refund->setRelation('order', $order);

        return $refund;
    }
}

final class InMemoryOrderRefund extends OrderRefund
{
    public function loadMissing($relations)
    {
        return $this;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->fill($attributes);

        return true;
    }

    public function fresh($with = [])
    {
        return $this;
    }
}
